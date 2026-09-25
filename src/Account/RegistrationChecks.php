<?php
declare(strict_types=1);

namespace MK\Account;

use WP_Error;

/**
 * The registration form, made to ask for real details and only what it needs.
 *
 * From the client's first live run-through of creator registration
 * (2026-09-19):
 *
 *  - Say, before anything is typed, that the details must be the member's
 *    own, and turn back the ones that plainly are not (see ProfileChecks).
 *  - ショップのURL is optional. Dokan insists on it, twice: its server check
 *    refuses an empty one, and its script keeps 登録する disabled until a
 *    typed URL has been checked for availability. An empty one is now given
 *    a random address before either sees it.
 *  - Dokan's setup wizard -- "Welcome to the Marketplace!", then an English
 *    store-address form -- is switched off. Nothing it asks is used here:
 *    a business's address is taken by the business application, and payouts
 *    by 売上・受取設定.
 */
final class RegistrationChecks
{
    public static function register(): void
    {
        add_action('woocommerce_register_form_start', [self::class, 'renderNotice'], 5);

        // Before WooCommerce handles the form (wp_loaded, 20) and before
        // Dokan's become-a-creator handler (template_redirect, 10).
        add_action('wp_loaded', [self::class, 'fillShopUrl'], 5);
        add_action('template_redirect', [self::class, 'fillShopUrl'], 0);

        add_filter('woocommerce_registration_errors', [self::class, 'validate'], 15, 3);
        add_action('template_redirect', [self::class, 'guardMigration'], 1);

        add_action('wp_print_footer_scripts', [self::class, 'script'], 30);

        add_filter('option_dokan_selling', [self::class, 'withoutWizard']);
        add_filter('option_dokan_general', [self::class, 'withoutWizard']);
        add_filter('dokan_set_go_to_vendor_dashboard_btn_text', [self::class, 'dashboardButton']);
    }

    public static function renderNotice(): void
    {
        echo '<div class="mk-register-notice" role="note">'
            . '<p class="mk-register-notice__title">登録情報は正確に入力してください</p>'
            . '<p>お名前・電話番号・住所などは、<strong>ご本人の正しい情報</strong>をご入力ください。'
            . '事実と異なる情報で登録された場合、利用規約（第4条・第8条）に基づき、'
            . '登録の取消しや利用停止となる場合があります。</p>'
            . '</div>';
    }

    // -------------------------------------------------------------- shop URL

    /**
     * Give a creator who left ショップのURL empty an address of their own.
     *
     * Random rather than derived: a Japanese shop name has no URL-safe form,
     * and the fallback WordPress would otherwise use is the login name, which
     * WooCommerce makes from the member's email address.
     */
    public static function fillShopUrl(): void
    {
        if (!self::isCreatorSubmission() || trim((string) ($_POST['shopurl'] ?? '')) !== '') {
            return;
        }

        $_POST['shopurl'] = self::freshShopSlug();
    }

    public static function freshShopSlug(): string
    {
        do {
            $slug = 'shop-' . strtolower(wp_generate_password(8, false, false));
        } while (get_user_by('slug', $slug));

        return $slug;
    }

    private static function isCreatorSubmission(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        $registering = isset($_POST['register']) && ($_POST['role'] ?? '') === 'seller';

        return $registering || !empty($_POST['dokan_migration']);
    }

    // ------------------------------------------------------------ validation

    /**
     * @param WP_Error $errors
     * @return WP_Error
     */
    public static function validate($errors, $username = '', $email = '')
    {
        if (!$errors instanceof WP_Error || ($_POST['role'] ?? '') !== 'seller') {
            return $errors;
        }

        foreach (self::problems(wp_unslash($_POST)) as $i => $message) {
            $errors->add('mk_profile_' . $i, $message);
        }

        return $errors;
    }

    /**
     * The same checks on the become-a-creator form, which Dokan handles itself
     * on template_redirect with no validation hook -- defused the way
     * Terms::guardMigration and Business::guardMigration do it.
     */
    public static function guardMigration(): void
    {
        if (empty($_POST['dokan_migration'])) {
            return;
        }

        $problems = self::problems(wp_unslash($_POST));

        if ($problems === []) {
            return;
        }

        unset($_POST['dokan_migration']);

        if (function_exists('wc_add_notice')) {
            foreach ($problems as $message) {
                wc_add_notice($message, 'error');
            }
        }
    }

    /**
     * @param array<string, mixed> $post unslashed
     * @return array<int, string>
     */
    public static function problems(array $post): array
    {
        $problems = [];

        foreach (['lname' => '姓', 'fname' => '名'] as $field => $label) {
            $problem = ProfileChecks::nameProblem((string) ($post[$field] ?? ''), $label);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        $phone = ProfileChecks::phoneProblem((string) ($post['phone'] ?? ''));

        if ($phone !== null) {
            $problems[] = $phone;
        }

        $shopUrl = trim((string) ($post['shopurl'] ?? ''));

        if ($shopUrl !== '' && !preg_match('/^[a-z0-9][a-z0-9\-]{1,39}$/', $shopUrl)) {
            $problems[] = 'ページのURLは、半角の英小文字・数字・ハイフン（-）で、2〜40文字で入力してください。';
        }

        return $problems;
    }

    // ----------------------------------------------------------------- Dokan

    /**
     * @param mixed $options
     * @return mixed
     */
    public static function withoutWizard($options)
    {
        if (is_array($options)) {
            $options['disable_welcome_wizard'] = 'on';
        }

        return $options;
    }

    public static function dashboardButton(): string
    {
        return '出品者ダッシュボードへ';
    }

    /**
     * ショップのURL as the optional field it now is.
     *
     * The markup is Dokan's template, so the label and the required flag are
     * changed here rather than by copying the template into the theme, where
     * it would silently fall behind Dokan's updates. Without scripting the
     * field stays required, which is Dokan's own behaviour -- the worse case
     * is the old form, never a broken one.
     */
    public static function script(): void
    {
        if (!wp_script_is('dokan-vendor-registration', 'done')) {
            return;
        }

        ?>
<script>
(function () {
    var field = document.getElementById('seller-url');

    if (!field) {
        return;
    }

    field.removeAttribute('required');
    field.setAttribute('placeholder', '例：my-shop（空欄でも登録できます）');

    var label = document.querySelector('label[for="seller-url"]');

    if (label) {
        label.innerHTML = 'ページのURL<span class="mk-optional">（任意）</span>';
    }

    var note = document.createElement('small');
    note.className = 'mk-field-help mk-shopurl-help';
    note.textContent = '空欄の場合は自動で作成されます。入力する場合は、半角の英小文字・数字・ハイフン（-）が使えます。';

    var preview = field.parentNode.querySelector('small');
    field.parentNode.insertBefore(note, preview ? preview.nextSibling : field.nextSibling);

    // Dokan keeps 登録する disabled until a URL it has checked is available.
    // An empty URL is never checked, so it is answered here instead.
    var reg = window.Dokan_Vendor_Registration;

    if (reg && typeof reg.ensureShopSlugAvailability === 'function') {
        var original = reg.ensureShopSlugAvailability;

        reg.ensureShopSlugAvailability = function () {
            if (field.value.trim() === '') {
                return;
            }

            return original.apply(this, arguments);
        };
    }

    field.addEventListener('input', function () {
        if (field.value.trim() !== '') {
            return;
        }

        var message = document.getElementById('url-alart-mgs');

        if (message) {
            message.textContent = '';
        }

        var submit = document.querySelector('.woocommerce-form-register__submit');

        if (submit) {
            submit.disabled = false;
        }
    });
})();
</script>
        <?php
    }
}
