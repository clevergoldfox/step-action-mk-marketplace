<?php
declare(strict_types=1);

namespace MK\Account;

use WP_Error;

/**
 * Agreement to the terms, taken at sign-up and recorded.
 *
 * Two things matter here and they are different from each other.
 *
 * The first is that the person can READ what they are agreeing to, and that
 * it is the right document: a buyer and a seller are agreeing to different
 * terms, so the link changes with the role they pick.
 *
 * The second is that the agreement is actually REQUIRED. A checkbox marked
 * `required` is a hint to a browser, not a rule -- it is trivially bypassed,
 * and a registration that skipped it would be indistinguishable afterwards
 * from one that did not. So the check that decides is the server-side one in
 * validate(), and what was agreed to is written onto the account: which
 * document, which role, and when. "Did this user accept the terms, and which
 * version" is the question a dispute turns on, and it cannot be answered
 * later from a checkbox that was only ever in a browser.
 */
final class Terms
{
    public const OPTION_BUYER  = 'mk_terms_buyer_page';
    public const OPTION_SELLER = 'mk_terms_seller_page';

    /** What the account records about the agreement. */
    public const META_AGREED_AT   = 'mk_terms_agreed_at';
    public const META_AGREED_PAGE = 'mk_terms_agreed_page';
    public const META_AGREED_ROLE = 'mk_terms_agreed_role';
    public const META_PAGE_EDITED = 'mk_terms_page_modified';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensurePages']);

        // After Dokan's role radio, which is what the link switches on.
        add_action('woocommerce_register_form', [self::class, 'renderCheckbox'], 30);
        add_action('woocommerce_register_post', [self::class, 'validate'], 10, 3);
        add_action('woocommerce_created_customer', [self::class, 'record'], 10, 1);

        // Becoming a seller later is agreeing to the seller terms, which the
        // buyer never saw at sign-up.
        add_action('dokan_after_seller_migration_fields', [self::class, 'renderMigrationCheckbox']);
        add_action('template_redirect', [self::class, 'guardMigration'], 1);
    }

    /** @return array<string, array{option:string, title:string, slug:string}> */
    public static function documents(): array
    {
        return [
            'customer' => [
                'option' => self::OPTION_BUYER,
                'title'  => '購入者向け利用規約',
                'slug'   => 'terms-buyer',
            ],
            'seller' => [
                'option' => self::OPTION_SELLER,
                'title'  => '出品者向け利用規約',
                'slug'   => 'terms-seller',
            ],
        ];
    }

    public static function pageId(string $role): int
    {
        $documents = self::documents();
        $document  = $documents[$role] ?? $documents['customer'];

        return (int) get_option($document['option']);
    }

    public static function url(string $role): string
    {
        $pageId = self::pageId($role);

        return $pageId > 0 ? (string) get_permalink($pageId) : '';
    }

    public static function label(string $role): string
    {
        return self::documents()[$role]['title'] ?? '利用規約';
    }

    public static function privacyUrl(): string
    {
        return (string) get_privacy_policy_url();
    }

    /**
     * Make sure both documents exist as pages.
     *
     * Placeholders, deliberately marked as such: the client's lawyer is
     * drafting the real text. A page that says it is being prepared is
     * honest; a link that 404s next to a checkbox saying "I agree" is not.
     */
    public static function ensurePages(): void
    {
        foreach (self::documents() as $document) {
            $existing = (int) get_option($document['option']);

            if ($existing > 0) {
                $page = get_post($existing);

                if ($page && $page->post_status === 'publish') {
                    continue;
                }
            }

            $pageId = wp_insert_post([
                'post_title'     => $document['title'],
                'post_name'      => $document['slug'],
                'post_content'   => sprintf(
                    "<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->",
                    esc_html($document['title'] . 'は現在準備中です。内容が確定しましたら、このページに掲載します。')
                ),
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ]);

            if (!is_wp_error($pageId)) {
                update_option($document['option'], (int) $pageId);
            }
        }
    }

    /** The checkbox, with the document that matches the selected role. */
    public static function renderCheckbox(): void
    {
        $buyer   = self::url('customer');
        $seller  = self::url('seller');
        $privacy = self::privacyUrl();
        $checked = !empty($_POST['mk_terms_agree']) ? ' checked' : '';

        echo '<p class="form-row mk-terms">';

        printf(
            '<label class="mk-terms__label"><input type="checkbox" name="mk_terms_agree" value="1" id="mk_terms_agree"%s> '
            . '<span><a href="%s" target="_blank" rel="noopener" class="mk-terms__link" '
            . 'data-buyer-url="%s" data-seller-url="%s" data-buyer-label="%s" data-seller-label="%s">%s</a>',
            $checked,
            esc_url($buyer),
            esc_url($buyer),
            esc_url($seller),
            esc_attr(self::label('customer')),
            esc_attr(self::label('seller')),
            esc_html(self::label('customer'))
        );

        if ($privacy !== '') {
            printf(
                'および<a href="%s" target="_blank" rel="noopener">プライバシーポリシー</a>',
                esc_url($privacy)
            );
        }

        echo 'に同意する <span class="required" aria-hidden="true">*</span></span></label>';
        echo '</p>';

        // The document has to follow the role choice, and the role is chosen
        // on this same screen. Without this the link is simply wrong for
        // anyone registering as a seller.
        ?>
        <script>
        (function () {
            var link = document.querySelector('.mk-terms__link');

            if (!link) {
                return;
            }

            function apply() {
                var seller = document.querySelector('input[name="role"]:checked');
                var isSeller = seller && seller.value === 'seller';

                link.href = isSeller ? link.dataset.sellerUrl : link.dataset.buyerUrl;
                link.textContent = isSeller ? link.dataset.sellerLabel : link.dataset.buyerLabel;
            }

            Array.prototype.forEach.call(document.querySelectorAll('input[name="role"]'), function (radio) {
                radio.addEventListener('change', apply);
            });

            apply();
        })();
        </script>
        <?php
    }

    /**
     * The check that actually decides.
     *
     * @param string   $username
     * @param string   $email
     * @param WP_Error $errors
     */
    public static function validate($username, $email, $errors): void
    {
        if (!$errors instanceof WP_Error) {
            return;
        }

        if (empty($_POST['mk_terms_agree'])) {
            $errors->add(
                'mk_terms_required',
                '<strong>エラー：</strong>ご登録には、利用規約およびプライバシーポリシーへの同意が必要です。'
            );
        }
    }

    /** Write down what was agreed, by whom, and when. */
    public static function record(int $userId): void
    {
        $role = isset($_POST['role']) && $_POST['role'] === 'seller' ? 'seller' : 'customer';

        self::stamp($userId, $role);
    }

    public static function stamp(int $userId, string $role): void
    {
        $pageId = self::pageId($role);

        update_user_meta($userId, self::META_AGREED_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_AGREED_ROLE, $role);
        update_user_meta($userId, self::META_AGREED_PAGE, $pageId);

        // The document's own last-modified date, so an agreement can be tied
        // to the wording that was on the page at the time rather than to
        // whatever it says by the time anyone asks.
        if ($pageId > 0) {
            update_user_meta($userId, self::META_PAGE_EDITED, get_post_modified_time('Y-m-d H:i:s', true, $pageId));
        }
    }

    /** The same checkbox on the page where a buyer becomes a seller. */
    public static function renderMigrationCheckbox(): void
    {
        $seller  = self::url('seller');
        $privacy = self::privacyUrl();

        echo '<p class="form-row mk-terms">';

        printf(
            '<label class="mk-terms__label"><input type="checkbox" name="mk_terms_agree" value="1"> '
            . '<span><a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url($seller),
            esc_html(self::label('seller'))
        );

        if ($privacy !== '') {
            printf('および<a href="%s" target="_blank" rel="noopener">プライバシーポリシー</a>', esc_url($privacy));
        }

        echo 'に同意する <span class="required" aria-hidden="true">*</span></span></label>';
        echo '</p>';
    }

    /**
     * Stop the become-a-seller submission when the box is not ticked.
     *
     * Dokan offers no validation hook on this form, so the submission is
     * defused before Dokan's handler sees it: the field it keys on is
     * removed and the reason is shown. Leaving the check to the browser
     * would mean the agreement is optional in practice.
     */
    public static function guardMigration(): void
    {
        if (empty($_POST['dokan_migration'])) {
            return;
        }

        if (!empty($_POST['mk_terms_agree'])) {
            // Agreed: record it against the account being upgraded.
            $userId = get_current_user_id();

            if ($userId > 0) {
                self::stamp($userId, 'seller');
            }

            return;
        }

        unset($_POST['dokan_migration']);

        if (function_exists('wc_add_notice')) {
            wc_add_notice('出品者になるには、出品者向け利用規約およびプライバシーポリシーへの同意が必要です。', 'error');
        }
    }
}
