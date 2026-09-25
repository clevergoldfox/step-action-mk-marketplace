<?php
declare(strict_types=1);

namespace MK\Creator;

use WP_Error;

/**
 * 事業者申請 -- corporations and sole proprietors selling on the platform.
 *
 * The client's rules (2026-09-17):
 *
 *   - an individual registers as a creator as before; a corporation or sole
 *     proprietor applies as a business, from the same creator registration
 *     screens
 *   - a business may not put anything on sale until the operator approves the
 *     application, which can take up to a week
 *   - an approved business is marked 「事業者」 where buyers can see it
 *
 * And a follow-up (2026-09-18): a creator who registered before any of this
 * existed can apply later, from the creator dashboard.
 *
 * ---------------------------------------------------------------------------
 * Why a business is gated and an individual is not
 * ---------------------------------------------------------------------------
 * Selling as a business brings obligations an individual clearing out their
 * wardrobe does not have -- a 古物商許可 to resell second-hand goods as a
 * trade, and disclosures under 特定商取引法. The platform cannot check those
 * after the fact without having been told who the business is, so the
 * application comes first and publication waits for it.
 *
 * The creator can still do everything else while waiting: set up the shop,
 * write listings, save drafts. Only publishing is held, in the same place
 * every other publication rule lives (wp_insert_post_data).
 *
 * ---------------------------------------------------------------------------
 * A later application does not stop an existing shop
 * ---------------------------------------------------------------------------
 * An existing creator applying from the dashboard stays an individual until
 * the operator approves: META_KIND changes on approval, not on application.
 * The gate reads META_KIND, so their listings stay on sale during review.
 * Holding them instead would unpublish a working shop, a week at a time, as
 * the price of doing the right thing -- and the gate only fires when a
 * listing is saved, so it would not even do that consistently. A rejected
 * later application leaves them exactly where they were.
 *
 * ---------------------------------------------------------------------------
 * Licence images are never public
 * ---------------------------------------------------------------------------
 * A 古物商許可証 carries a name, an address and a permit number. Files go to a
 * directory OUTSIDE the web root, under random names, and are served only to
 * the operator through BusinessAdmin's handler. Anything inside public_html
 * would depend on the web server honouring a deny rule, and this host puts
 * nginx in front of Apache -- a static file can be answered before .htaccess
 * is ever read.
 */
final class Business
{
    /** Dokan dashboard sub-page slug. */
    public const PAGE = 'mk-business-apply';

    public const META_KIND       = 'mk_seller_kind';
    public const META_STATUS     = 'mk_business_status';
    public const META_DATA       = 'mk_business_application';
    public const META_LICENSE    = 'mk_business_license_file';
    public const META_APPLIED_AT = 'mk_business_applied_at';
    public const META_DECIDED_AT = 'mk_business_decided_at';
    public const META_DECISION   = 'mk_business_decision_note';
    public const META_ROUTE      = 'mk_business_route';

    public const KIND_INDIVIDUAL = 'individual';
    public const KIND_BUSINESS   = 'business';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const ROUTE_REGISTRATION = 'registration';
    public const ROUTE_DASHBOARD    = 'dashboard';

    private const PRIVATE_SUBDIR = 'mk-private/business-licenses';
    private const MAX_BYTES      = 5 * 1024 * 1024;
    private const NONCE_APPLY    = 'mk_business_apply';

    private static bool $rendered = false;

    /** @var string[] problems with a dashboard application, shown on the same request */
    private static array $applyErrors = [];

    public static function register(): void
    {
        // Both creator registration screens: becoming a creator later, and
        // choosing 出品者 at sign-up.
        add_action('dokan_after_seller_migration_fields', [self::class, 'renderFields'], 5);
        add_action('dokan_seller_registration_field_after', [self::class, 'renderFields'], 5);

        // A licence image cannot be uploaded through a form that is not
        // multipart. The sign-up form exposes a hook for its tag; the
        // become-a-creator form does not, so renderFields() handles that one.
        add_action('woocommerce_register_form_tag', [self::class, 'multipartTag']);

        add_filter('woocommerce_registration_errors', [self::class, 'validateRegistration'], 20, 3);
        add_action('template_redirect', [self::class, 'guardMigration'], 1);

        add_action('dokan_new_seller_created', [self::class, 'save'], 20, 1);

        // An existing creator applying later.
        add_filter('dokan_query_var_filter', [self::class, 'addQueryVar']);
        add_filter('dokan_get_dashboard_nav', [self::class, 'addNavItem']);
        add_action('dokan_load_custom_template', [self::class, 'renderPage']);
        add_action('template_redirect', [self::class, 'handleApplication']);

        add_filter('wp_insert_post_data', [self::class, 'gate'], 22, 2);

        add_action('dokan_dashboard_content_inside_before', [self::class, 'notice']);
        add_action('dokan_new_product_before_product_area', [self::class, 'notice']);

        add_action('dokan_store_header_after_store_name', [self::class, 'renderStoreBadge'], 10, 1);
        add_action('dokan_product_seller_tab_start', [self::class, 'renderSellerTabBadge'], 10, 1);
        add_action('dokan_store_profile_frame_after', [self::class, 'renderStoreInfo'], 10, 2);
    }

    // ---------------------------------------------------------------- reads

    public static function kindOf(int $userId): string
    {
        return get_user_meta($userId, self::META_KIND, true) === self::KIND_BUSINESS
            ? self::KIND_BUSINESS
            : self::KIND_INDIVIDUAL;
    }

    public static function isBusiness(int $userId): bool
    {
        return $userId > 0 && self::kindOf($userId) === self::KIND_BUSINESS;
    }

    public static function statusOf(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_STATUS, true);
    }

    /** Has this user ever applied, by either route? */
    public static function hasApplication(int $userId): bool
    {
        return $userId > 0 && isset(self::statusLabels()[self::statusOf($userId)]);
    }

    public static function isApproved(int $userId): bool
    {
        return self::isBusiness($userId) && self::statusOf($userId) === self::STATUS_APPROVED;
    }

    /** An individual may always publish; a business only once approved. */
    public static function canPublish(int $userId): bool
    {
        return !self::isBusiness($userId) || self::isApproved($userId);
    }

    /** May this creator send an application from the dashboard now? */
    public static function canApply(int $userId): bool
    {
        if ($userId === 0 || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return false;
        }

        return !in_array(self::statusOf($userId), [self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }

    /** @return array<string, mixed> */
    public static function applicationOf(int $userId): array
    {
        $data = get_user_meta($userId, self::META_DATA, true);

        return is_array($data) ? $data : [];
    }

    /** Where the application came from, for the operator. */
    public static function routeLabel(int $userId): string
    {
        return get_user_meta($userId, self::META_ROUTE, true) === self::ROUTE_DASHBOARD
            ? '出品者登録後に申請（既存の出品者）'
            : '出品者登録時に申請';
    }

    /** @return array<string, string> */
    public static function businessTypes(): array
    {
        return [
            'corporation'     => '法人',
            'sole_proprietor' => '個人事業主',
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING  => '審査中',
            self::STATUS_APPROVED => '承認済み',
            self::STATUS_REJECTED => '承認されませんでした',
        ];
    }

    /**
     * The application's text fields: key => [label, required, max length].
     *
     * @return array<string, array{0:string, 1:bool, 2:int}>
     */
    public static function fields(): array
    {
        return [
            'business_name'     => ['法人名または屋号', true, 100],
            'representative'    => ['代表者名', true, 50],
            'address'           => ['所在地', true, 200],
            'phone'             => ['電話番号', true, 20],
            'email'             => ['メールアドレス', true, 100],
            'invoice_number'    => ['インボイス登録番号（任意）', false, 14],
            'products'          => ['販売予定の商品', true, 500],
            'kobutsu_number'    => ['古物商許可番号', false, 30],
            'kobutsu_authority' => ['許可を受けた公安委員会', false, 30],
            'other_licenses'    => ['その他の許認可（任意）', false, 300],
        ];
    }

    // --------------------------------------------------------------- render

    public static function multipartTag(): void
    {
        echo ' enctype="multipart/form-data"';
    }

    /** The registration screens: individual or business, then the application. */
    public static function renderFields(): void
    {
        if (self::$rendered) {
            return;
        }

        self::$rendered = true;

        $kind = isset($_POST['mk_seller_kind']) && $_POST['mk_seller_kind'] === self::KIND_BUSINESS
            ? self::KIND_BUSINESS
            : self::KIND_INDIVIDUAL;

        echo '<div class="mk-seller-kind" data-mk-business>';
        echo '<p class="mk-seller-kind__title">出品者の区分<span class="required">*</span></p>';

        printf(
            '<label class="mk-seller-kind__option"><input type="radio" name="mk_seller_kind" value="%s"%s> '
            . '<span><strong>個人</strong><small>個人として出品します。</small></span></label>',
            esc_attr(self::KIND_INDIVIDUAL),
            checked($kind, self::KIND_INDIVIDUAL, false)
        );

        printf(
            '<label class="mk-seller-kind__option"><input type="radio" name="mk_seller_kind" value="%s"%s> '
            . '<span><strong>法人・個人事業主</strong><small>事業として出品する場合は、事業者申請が必要です。</small></span></label>',
            esc_attr(self::KIND_BUSINESS),
            checked($kind, self::KIND_BUSINESS, false)
        );

        echo '<div class="mk-business-fields">';

        echo '<p class="mk-business-notice">事業者申請は、運営の審査後に承認となります。'
            . '<strong>申請から承認まで、最大1週間程度かかる場合があります。</strong>'
            . '承認されるまで商品の公開はできませんが、プロフィールの設定や商品の下書き保存は行えます。<br>'
            . self::publicNotice() . '</p>';

        self::renderApplicationInputs();

        echo '</div></div>';

        self::renderScript();
    }

    /** The application itself, shared by registration and the dashboard. */
    private static function renderApplicationInputs(): void
    {
        $posted = static fn (string $key): string => isset($_POST['mk_business'][$key])
            ? esc_attr(sanitize_text_field(wp_unslash((string) $_POST['mk_business'][$key])))
            : '';

        $type = $posted('business_type');

        echo '<p class="form-row form-row-wide"><label>事業者区分<span class="required">*</span></label>';

        foreach (self::businessTypes() as $key => $label) {
            printf(
                '<label class="mk-business-inline"><input type="radio" name="mk_business[business_type]" value="%s"%s> %s</label>',
                esc_attr($key),
                checked($type, $key, false),
                esc_html($label)
            );
        }

        echo '</p>';

        foreach (self::fields() as $key => [$label, $required, $max]) {
            if (in_array($key, ['kobutsu_number', 'kobutsu_authority'], true)) {
                continue;   // rendered inside the 古物商 block below
            }

            $id    = 'mk_business_' . $key;
            $star  = $required ? '<span class="required">*</span>' : '';
            $input = in_array($key, ['products', 'other_licenses'], true)
                ? sprintf('<textarea name="mk_business[%s]" id="%s" rows="3" maxlength="%d" class="input-text">%s</textarea>',
                    esc_attr($key), esc_attr($id), $max, isset($_POST['mk_business'][$key])
                        ? esc_textarea(sanitize_textarea_field(wp_unslash((string) $_POST['mk_business'][$key])))
                        : '')
                : sprintf('<input type="%s" name="mk_business[%s]" id="%s" maxlength="%d" value="%s" class="input-text">',
                    $key === 'email' ? 'email' : 'text', esc_attr($key), esc_attr($id), $max, $posted($key));

            printf(
                '<p class="form-row form-row-wide"><label for="%s">%s%s</label>%s%s</p>',
                esc_attr($id),
                esc_html($label),
                $star,
                $input,
                $key === 'invoice_number' ? '<small>「T」から始まる14桁（例：T1234567890123）</small>' : ''
            );
        }

        printf(
            '<p class="form-row form-row-wide"><label class="mk-business-inline">'
            . '<input type="checkbox" name="mk_business[needs_kobutsu]" value="1"%s> '
            . '古物商許可が必要な商品（中古品など）を事業として販売する</label></p>',
            !empty($_POST['mk_business']['needs_kobutsu']) ? ' checked' : ''
        );

        echo '<div class="mk-business-kobutsu">';

        foreach (['kobutsu_number', 'kobutsu_authority'] as $key) {
            printf(
                '<p class="form-row form-row-wide"><label for="mk_business_%1$s">%2$s<span class="required">*</span></label>'
                . '<input type="text" name="mk_business[%1$s]" id="mk_business_%1$s" maxlength="%3$d" value="%4$s" class="input-text"></p>',
                esc_attr($key),
                esc_html(self::fields()[$key][0]),
                self::fields()[$key][2],
                $posted($key)
            );
        }

        echo '<p class="form-row form-row-wide"><label for="mk_business_kobutsu_file">古物商許可証の画像<span class="required">*</span></label>'
            . '<input type="file" name="mk_business_kobutsu_file" id="mk_business_kobutsu_file" accept="image/jpeg,image/png,application/pdf">'
            . '<small>JPEG・PNG・PDF、5MBまで。運営のみが確認し、公開されることはありません。</small></p>';

        echo '</div>';
    }

    /**
     * Shows the business half only when chosen (where there is a choice), the
     * 古物商 block only when ticked, and makes the enclosing form able to carry
     * a file.
     */
    private static function renderScript(): void
    {
        ?>
<script>
(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-mk-business]'), function (wrap) {
        var form = wrap.closest('form');
        if (form) { form.enctype = 'multipart/form-data'; }

        var business = wrap.querySelector('.mk-business-fields');
        var kobutsu = wrap.querySelector('.mk-business-kobutsu');
        var needs = wrap.querySelector('input[name="mk_business[needs_kobutsu]"]');
        var kinds = wrap.querySelectorAll('input[name="mk_seller_kind"]');

        function sync() {
            if (kinds.length) {
                var chosen = wrap.querySelector('input[name="mk_seller_kind"]:checked');
                business.hidden = !(chosen && chosen.value === 'business');
            }
            kobutsu.hidden = !(needs && needs.checked);
        }

        Array.prototype.forEach.call(kinds, function (r) { r.addEventListener('change', sync); });
        if (needs) { needs.addEventListener('change', sync); }
        sync();
    });
})();
</script>
        <?php
    }

    // ----------------------------------------------------------- validation

    /**
     * What is wrong with a submission, as buyer-readable messages.
     *
     * Public and free of request globals so the rules can be tested directly.
     *
     * @param array<string, mixed> $post  the whole form, unslashed
     * @param array<string, mixed> $files $_FILES
     * @return string[] empty when the submission is acceptable
     */
    public static function validationErrors(array $post, array $files): array
    {
        $kind = (string) ($post['mk_seller_kind'] ?? '');

        if (!in_array($kind, [self::KIND_INDIVIDUAL, self::KIND_BUSINESS], true)) {
            return ['出品者の区分（個人／法人・個人事業主）を選んでください。'];
        }

        if ($kind === self::KIND_INDIVIDUAL) {
            return [];
        }

        $data   = is_array($post['mk_business'] ?? null) ? $post['mk_business'] : [];
        $errors = [];

        if (!isset(self::businessTypes()[(string) ($data['business_type'] ?? '')])) {
            $errors[] = '事業者区分（法人／個人事業主）を選んでください。';
        }

        foreach (self::fields() as $key => [$label, $required, $max]) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($required && $value === '') {
                $errors[] = sprintf('%sを入力してください。', $label);
            } elseif (mb_strlen($value) > $max) {
                $errors[] = sprintf('%sは%d文字以内で入力してください。', $label, $max);
            }
        }

        // Shown to buyers as the 特定商取引法 details, so held to the same
        // standard as a creator's own name and phone (2026-09-19).
        foreach ([
            \MK\Account\ProfileChecks::nameProblem((string) ($data['representative'] ?? ''), '代表者名'),
            \MK\Account\ProfileChecks::phoneProblem((string) ($data['phone'] ?? '')),
            \MK\Account\ProfileChecks::addressProblem((string) ($data['address'] ?? '')),
        ] as $problem) {
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }

        $email = trim((string) ($data['email'] ?? ''));

        if ($email !== '' && !is_email($email)) {
            $errors[] = 'メールアドレスの形式が正しくありません。';
        }

        $invoice = trim((string) ($data['invoice_number'] ?? ''));

        if ($invoice !== '' && !preg_match('/^T\d{13}$/', $invoice)) {
            $errors[] = 'インボイス登録番号は「T」と13桁の数字で入力してください。';
        }

        if (!empty($data['needs_kobutsu'])) {
            foreach (['kobutsu_number', 'kobutsu_authority'] as $key) {
                if (trim((string) ($data[$key] ?? '')) === '') {
                    $errors[] = sprintf('%sを入力してください。', self::fields()[$key][0]);
                }
            }

            $file = $files['mk_business_kobutsu_file'] ?? null;

            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = '古物商許可証の画像を添付してください。';
            } else {
                $problem = self::fileProblem((string) $file['tmp_name'], (string) $file['name'], (int) $file['size']);

                if ($problem !== null) {
                    $errors[] = $problem;
                }
            }
        }

        return $errors;
    }

    /** Why this file cannot be accepted, or null if it can. */
    public static function fileProblem(string $path, string $name, int $size): ?string
    {
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return '古物商許可証の画像は5MB以内でお送りください。';
        }

        // Checked against the file's real contents, not its name: a renamed
        // script is still a script.
        $check = wp_check_filetype_and_ext($path, $name, [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'pdf'      => 'application/pdf',
        ]);

        if (empty($check['type']) || empty($check['ext'])) {
            return '古物商許可証の画像は、JPEG・PNG・PDFのいずれかでお送りください。';
        }

        return null;
    }

    /**
     * @param WP_Error $errors
     * @return WP_Error
     */
    public static function validateRegistration($errors, $username = '', $email = '')
    {
        if (!$errors instanceof WP_Error || ($_POST['role'] ?? '') !== 'seller') {
            return $errors;
        }

        foreach (self::validationErrors(wp_unslash($_POST), $_FILES) as $i => $message) {
            $errors->add('mk_business_' . $i, $message);
        }

        return $errors;
    }

    /**
     * Stop a become-a-creator submission that is not a complete application.
     *
     * Same technique as Account\Terms::guardMigration, and for the same reason:
     * Dokan's handler offers no validation hook, and runs on template_redirect
     * at the default priority, so the submission is defused just before it.
     */
    public static function guardMigration(): void
    {
        if (empty($_POST['dokan_migration'])) {
            return;
        }

        $errors = self::validationErrors(wp_unslash($_POST), $_FILES);

        if ($errors === []) {
            return;
        }

        unset($_POST['dokan_migration']);

        if (function_exists('wc_add_notice')) {
            foreach ($errors as $message) {
                wc_add_notice($message, 'error');
            }
        }
    }

    // ------------------------------------------------------------------ save

    /** Record the choice made at registration, once Dokan has made the user a creator. */
    public static function save(int $userId): void
    {
        // Only from the two registration forms. Dokan fires this hook for
        // creators made in wp-admin too, where there is no application.
        if (!isset($_POST['mk_seller_kind'])) {
            return;
        }

        $post = wp_unslash($_POST);

        if ($post['mk_seller_kind'] !== self::KIND_BUSINESS) {
            update_user_meta($userId, self::META_KIND, self::KIND_INDIVIDUAL);

            return;
        }

        // A business from the start: held until approved.
        update_user_meta($userId, self::META_KIND, self::KIND_BUSINESS);

        self::recordApplication($userId, $post, $_FILES, true, self::ROUTE_REGISTRATION);
    }

    /**
     * Store an application and put it in front of the operator.
     *
     * Does not touch META_KIND: whether the applicant is held meanwhile is the
     * caller's decision (see the class comment). A new application replaces
     * the previous one, licence file included.
     *
     * @param array<string, mixed> $post  the form, unslashed
     * @param array<string, mixed> $files $_FILES, already validated
     * @param bool                 $uploaded false lets tests supply a file they made
     */
    public static function recordApplication(int $userId, array $post, array $files, bool $uploaded, string $route): void
    {
        $data  = is_array($post['mk_business'] ?? null) ? $post['mk_business'] : [];
        $clean = [
            'business_type' => isset(self::businessTypes()[(string) ($data['business_type'] ?? '')])
                ? (string) $data['business_type']
                : '',
            'needs_kobutsu' => !empty($data['needs_kobutsu']),
        ];

        foreach (self::fields() as $key => [$label, $required, $max]) {
            $value       = in_array($key, ['products', 'other_licenses'], true)
                ? sanitize_textarea_field((string) ($data[$key] ?? ''))
                : sanitize_text_field((string) ($data[$key] ?? ''));
            $clean[$key] = mb_substr(trim($value), 0, $max);
        }

        update_user_meta($userId, self::META_DATA, $clean);
        update_user_meta($userId, self::META_STATUS, self::STATUS_PENDING);
        update_user_meta($userId, self::META_APPLIED_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_ROUTE, $route === self::ROUTE_DASHBOARD ? self::ROUTE_DASHBOARD : self::ROUTE_REGISTRATION);

        $previous = self::licensePath($userId);
        delete_user_meta($userId, self::META_LICENSE);

        $file = $files['mk_business_kobutsu_file'] ?? null;

        if ($clean['needs_kobutsu'] && is_array($file) && (int) ($file['error'] ?? 1) === UPLOAD_ERR_OK) {
            $stored = self::storeFile((string) $file['tmp_name'], (string) $file['name'], $uploaded);

            if ($stored !== '') {
                update_user_meta($userId, self::META_LICENSE, $stored);
            }
        }

        // A permit that is no longer part of any application is not kept.
        if ($previous !== '' && $previous !== self::licensePath($userId)) {
            @unlink($previous);
        }

        do_action('mk_business_applied', $userId);
    }

    // ------------------------------------------------------------- dashboard

    /**
     * @param array<string,string> $vars
     * @return array<string,string>
     */
    public static function addQueryVar(array $vars): array
    {
        $vars[self::PAGE] = self::PAGE;

        return $vars;
    }

    /**
     * @param array<string,mixed> $nav
     * @return array<string,mixed>
     */
    public static function addNavItem(array $nav): array
    {
        $nav[self::PAGE] = [
            'title' => '事業者申請',
            'icon'  => '<i class="fas fa-building"></i>',
            'url'   => dokan_get_navigation_url(self::PAGE),
            'pos'   => 56,
        ];

        return $nav;
    }

    /** Accept an application sent from the dashboard page. */
    public static function handleApplication(): void
    {
        if (empty($_POST['mk_business_apply'])) {
            return;
        }

        $userId = get_current_user_id();

        if ($userId === 0 || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_mk_business_nonce'] ?? '')));

        if (!wp_verify_nonce($nonce, self::NONCE_APPLY)) {
            self::$applyErrors = ['画面の有効期限が切れました。お手数ですが、もう一度送信してください。'];

            return;
        }

        $page = dokan_get_navigation_url(self::PAGE);

        // Already under review or approved: nothing to send. Back to the page,
        // which says so.
        if (!self::canApply($userId)) {
            wp_safe_redirect($page);
            exit;
        }

        $post                   = wp_unslash($_POST);
        $post['mk_seller_kind'] = self::KIND_BUSINESS;

        $errors = self::validationErrors($post, $_FILES);

        if ($errors !== []) {
            self::$applyErrors = $errors;   // the page renders later in this request, with the form refilled

            return;
        }

        self::recordApplication($userId, $post, $_FILES, true, self::ROUTE_DASHBOARD);

        wp_safe_redirect(add_query_arg('mk_applied', '1', $page));
        exit;
    }

    /** @param array<string,mixed> $queryVars */
    public static function renderPage(array $queryVars): void
    {
        if (!isset($queryVars[self::PAGE])) {
            return;
        }

        $userId = get_current_user_id();

        if ($userId === 0 || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            echo '<div class="dokan-error">この画面を表示する権限がありません。</div>';

            return;
        }

        // Same wrapper handling as Onboarding::renderMarkup(), for the same reason.
        echo '<article class="mk-business-page">';
        echo '<header class="dokan-dashboard-header"><h1 class="entry-title">事業者申請</h1></header>';
        echo '<div class="dokan-panel dokan-panel-default"><div class="dokan-panel-body">';

        self::renderPageBody($userId);

        echo '</div></div></article></div>';
    }

    /** What the dashboard page says, by the state of the creator's application. */
    public static function renderPageBody(int $userId): void
    {
        if (isset($_GET['mk_applied'])) {
            echo '<div class="dokan-alert dokan-alert-success">事業者申請を受け付けました。審査結果はメールでお知らせします。</div>';
        }

        $status = self::statusOf($userId);
        $data   = self::applicationOf($userId);

        if (self::isApproved($userId)) {
            printf(
                '<p>%s　<strong>%s</strong> として承認されています。</p>'
                . '<p>プロフィールページのクリエイター名の横と、商品ページの出品者情報に「事業者」と表示されます。</p>'
                . '<p class="description">登録内容の変更が必要な場合は、運営までお問い合わせください。</p>',
                self::badgeHtml(),
                esc_html((string) ($data['business_name'] ?? ''))
            );

            return;
        }

        if ($status === self::STATUS_PENDING) {
            printf(
                '<div class="dokan-alert dokan-alert-info"><strong>事業者申請を審査中です。</strong>（申請日：%s）<br>'
                . '申請から承認まで、最大1週間程度かかる場合があります。承認されましたらメールでお知らせします。<br>%s</div>',
                esc_html(get_date_from_gmt((string) get_user_meta($userId, self::META_APPLIED_AT, true), 'Y年n月j日')),
                self::isBusiness($userId)
                    ? '承認されるまで、商品は公開されません（下書きとして保存されます）。'
                    : '審査中も、これまでどおり出品・販売を続けられます。'
            );

            return;
        }

        if ($status === self::STATUS_REJECTED) {
            $note = trim((string) get_user_meta($userId, self::META_DECISION, true));

            printf(
                '<div class="dokan-alert dokan-alert-danger"><strong>前回の事業者申請は承認されませんでした。</strong><br>%s'
                . '内容を見直して、下のフォームから再度申請できます。</div>',
                $note !== '' ? '運営からの連絡：' . esc_html($note) . '<br>' : ''
            );
        }

        echo '<p>法人・個人事業主として出品される場合は、事業者申請が必要です。'
            . '運営の審査で承認されると、プロフィールページと商品ページに「事業者」と表示されます。</p>';

        if (!self::isBusiness($userId)) {
            echo '<p><strong>すでに出品中の商品は、審査中もこれまでどおり販売を続けられます。</strong></p>';
        }

        if (self::$applyErrors !== []) {
            echo '<div class="dokan-alert dokan-alert-danger"><ul class="mk-business-errors">';

            foreach (self::$applyErrors as $message) {
                printf('<li>%s</li>', esc_html($message));
            }

            echo '</ul></div>';
        }

        echo '<form method="post" enctype="multipart/form-data" class="mk-business-apply-form">';
        wp_nonce_field(self::NONCE_APPLY, '_mk_business_nonce');
        echo '<input type="hidden" name="mk_business_apply" value="1">';

        echo '<div class="mk-business-apply" data-mk-business><div class="mk-business-fields">';
        echo '<p class="mk-business-notice"><strong>申請から承認まで、最大1週間程度かかる場合があります。</strong><br>'
            . esc_html(self::publicNotice()) . '</p>';

        self::renderApplicationInputs();

        echo '</div></div>';

        echo '<p><button type="submit" class="dokan-btn dokan-btn-theme">事業者申請を送信する</button></p>';
        echo '</form>';

        self::renderScript();
    }

    // --------------------------------------------------------------- storage

    public static function privateDir(): string
    {
        return dirname(untrailingslashit(ABSPATH)) . '/' . self::PRIVATE_SUBDIR;
    }

    /**
     * Move a licence file into private storage under a random name.
     *
     * @param bool $uploaded true for a real HTTP upload; false lets tests
     *                       store a file they created themselves
     * @return string the stored file name, or '' on failure
     */
    public static function storeFile(string $source, string $originalName, bool $uploaded): string
    {
        if (self::fileProblem($source, $originalName, (int) @filesize($source)) !== null) {
            return '';
        }

        $dir = self::privateDir();

        if (!wp_mkdir_p($dir)) {
            return '';
        }

        // Belt and braces: the directory is outside the web root, but if it is
        // ever moved inside one, these keep it from being browsable.
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }

        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence.\n");
        }

        $check = wp_check_filetype_and_ext($source, $originalName, [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'pdf'      => 'application/pdf',
        ]);

        $name   = bin2hex(random_bytes(16)) . '.' . $check['ext'];
        $target = $dir . '/' . $name;
        $moved  = $uploaded ? @move_uploaded_file($source, $target) : @copy($source, $target);

        if (!$moved) {
            return '';
        }

        @chmod($target, 0600);

        return $name;
    }

    /** Absolute path of a creator's licence file, or '' if there is none. */
    public static function licensePath(int $userId): string
    {
        $name = basename((string) get_user_meta($userId, self::META_LICENSE, true));

        if ($name === '' || !preg_match('/^[a-f0-9]{32}\.(jpe?g|png|pdf)$/', $name)) {
            return '';
        }

        $path = self::privateDir() . '/' . $name;

        return is_file($path) ? $path : '';
    }

    // -------------------------------------------------------------- decision

    public static function decide(int $userId, bool $approve, string $note, int $adminId): void
    {
        $status = $approve ? self::STATUS_APPROVED : self::STATUS_REJECTED;

        // Approval is what makes someone a business. A creator who applied
        // later becomes one only here; one rejected stays as they were.
        if ($approve) {
            update_user_meta($userId, self::META_KIND, self::KIND_BUSINESS);
        }

        update_user_meta($userId, self::META_STATUS, $status);
        update_user_meta($userId, self::META_DECIDED_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_DECISION, $note);

        do_action('mk_business_decided', $userId, $status, $note, $adminId);
    }

    // ------------------------------------------------------------------ gate

    /**
     * Hold a business's listings until its application is approved.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function gate(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'product') {
            return $data;
        }

        $status = (string) ($data['post_status'] ?? '');

        if ($status !== 'publish' && $status !== 'pending') {
            return $data;
        }

        $author = (int) ($data['post_author'] ?? 0);

        if ($author === 0 || user_can($author, 'manage_woocommerce')) {
            return $data;
        }

        if (!self::canPublish($author)) {
            $data['post_status'] = 'draft';
        }

        return $data;
    }

    public static function notice(): void
    {
        $userId = get_current_user_id();

        if (!self::isBusiness($userId) || self::isApproved($userId)) {
            return;
        }

        if (self::statusOf($userId) === self::STATUS_REJECTED) {
            $note = trim((string) get_user_meta($userId, self::META_DECISION, true));

            printf(
                '<div class="dokan-alert dokan-alert-danger"><strong>事業者申請は承認されませんでした。</strong><br>'
                . '商品を公開することはできません。%s<a href="%s">事業者申請</a>から再度申請できます。</div>',
                $note !== '' ? '運営からの連絡：' . esc_html($note) . '<br>' : '',
                esc_url(dokan_get_navigation_url(self::PAGE))
            );

            return;
        }

        echo '<div class="dokan-alert dokan-alert-info"><strong>事業者申請を審査中です。</strong><br>'
            . '承認されるまで、商品は公開されません（下書きとして保存されます）。'
            . '申請から承認まで、最大1週間程度かかる場合があります。承認されましたらメールでお知らせします。</div>';
    }

    // ------------------------------------------------------- public details

    /**
     * What an approved business shows buyers on its shop page.
     *
     * A business selling to consumers online has to say who it is -- name,
     * representative, address, phone, email -- and the client chose to show
     * it on the shop page (2026-09-19). Only these five come from the
     * application; the licence image, permit number and invoice number stay
     * with the operator.
     *
     * @return array<string, string> label => value, empty unless approved
     */
    public static function publicDetails(int $userId): array
    {
        if (!self::isApproved($userId)) {
            return [];
        }

        $data = self::applicationOf($userId);
        $rows = [
            '事業者名'       => (string) ($data['business_name'] ?? ''),
            '代表者名'       => (string) ($data['representative'] ?? ''),
            '所在地'         => (string) ($data['address'] ?? ''),
            '電話番号'       => (string) ($data['phone'] ?? ''),
            'メールアドレス' => (string) ($data['email'] ?? ''),
        ];

        return array_filter($rows, static fn (string $value): bool => $value !== '');
    }

    /** Told to every applicant before they send anything. */
    public static function publicNotice(): string
    {
        return '承認後、法人名または屋号・代表者名・所在地・電話番号・メールアドレスは、'
            . '特定商取引法に基づく表記としてプロフィールページに表示されます。'
            . '古物商許可証の画像などの審査用資料は公開されません。';
    }

    /**
     * @param mixed $storeUser the vendor's WP_User data object
     * @param mixed $storeInfo
     */
    public static function renderStoreInfo($storeUser, $storeInfo = []): void
    {
        $id   = is_object($storeUser) && isset($storeUser->ID) ? (int) $storeUser->ID : (int) $storeUser;
        $rows = self::publicDetails($id);

        if ($rows === []) {
            return;
        }

        echo '<details class="mk-business-info"><summary>事業者情報（特定商取引法に基づく表記）</summary><dl>';

        foreach ($rows as $label => $value) {
            printf('<dt>%s</dt><dd>%s</dd>', esc_html($label), esc_html($value));
        }

        echo '</dl></details>';
    }

    // ----------------------------------------------------------------- badge

    public static function badgeHtml(): string
    {
        return '<span class="mk-business-badge">事業者</span>';
    }

    /** @param mixed $storeUser Dokan's vendor object */
    public static function renderStoreBadge($storeUser): void
    {
        $id = is_object($storeUser) && method_exists($storeUser, 'get_id') ? (int) $storeUser->get_id() : (int) $storeUser;

        if (self::isApproved($id)) {
            echo self::badgeHtml(); // static markup
        }
    }

    /** @param mixed $author WP_User */
    public static function renderSellerTabBadge($author): void
    {
        $id = $author instanceof \WP_User ? $author->ID : (int) $author;

        if (self::isApproved($id)) {
            echo '<li class="mk-business">' . self::badgeHtml() . '</li>'; // static markup
        }
    }
}
