<?php
declare(strict_types=1);

namespace MK\Account;

use WP_Error;

/**
 * Agreement to the terms, taken at sign-up and recorded.
 *
 * Two things matter here and they are different from each other.
 *
 * The first is that the person can READ what they are agreeing to. The
 * client's final terms are one document for buyers and creators alike
 * (2026-09-17), so there is one page and one link. This used to be two
 * documents with a link that switched on the role radio; the role is still
 * recorded, because "agreed as a buyer, later became a creator" is part of an
 * account's history, but it no longer changes what is shown.
 *
 * The second is that the agreement is actually REQUIRED. A checkbox marked
 * `required` is a hint to a browser, not a rule -- it is trivially bypassed,
 * and a registration that skipped it would be indistinguishable afterwards
 * from one that did not. So the check that decides is the server-side one in
 * validate(), and what was agreed to is written onto the account: which
 * page, which role, and when, and that page's last-modified time. "Did this
 * user accept the terms, and which version" is the question a dispute turns
 * on, and it cannot be answered later from a checkbox that was only ever in a
 * browser.
 *
 * The two retired pages (terms-buyer, terms-seller) are kept as drafts rather
 * than deleted: accounts registered before the change point at them.
 */
final class Terms
{
    public const OPTION_PAGE = 'mk_terms_page';

    /** What the account records about the agreement. */
    public const META_AGREED_AT   = 'mk_terms_agreed_at';
    public const META_AGREED_PAGE = 'mk_terms_agreed_page';
    public const META_AGREED_ROLE = 'mk_terms_agreed_role';
    public const META_PAGE_EDITED = 'mk_terms_page_modified';

    private const TITLE = '利用規約';
    private const SLUG  = 'terms';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensurePages']);

        add_action('woocommerce_register_form', [self::class, 'renderCheckbox'], 30);
        add_action('woocommerce_register_post', [self::class, 'validate'], 10, 3);
        add_action('woocommerce_created_customer', [self::class, 'record'], 10, 1);

        // Becoming a creator later is agreed to again, and recorded as such.
        add_action('dokan_after_seller_migration_fields', [self::class, 'renderMigrationCheckbox']);
        add_action('template_redirect', [self::class, 'guardMigration'], 1);
    }

    /** @return array<string, array{option:string, title:string, slug:string}> */
    public static function documents(): array
    {
        return [
            'terms' => [
                'option' => self::OPTION_PAGE,
                'title'  => self::TITLE,
                'slug'   => self::SLUG,
            ],
        ];
    }

    /** The terms page. The role is accepted for callers that still pass one. */
    public static function pageId(string $role = ''): int
    {
        return (int) get_option(self::OPTION_PAGE);
    }

    public static function url(string $role = ''): string
    {
        $pageId = self::pageId();

        return $pageId > 0 ? (string) get_permalink($pageId) : '';
    }

    public static function label(string $role = ''): string
    {
        return self::TITLE;
    }

    public static function privacyUrl(): string
    {
        return (string) get_privacy_policy_url();
    }

    /**
     * Make sure the terms page exists.
     *
     * A placeholder until the text is in, marked as such: a link that 404s
     * next to a checkbox saying "I agree" is worse than a page that says it
     * is being prepared.
     */
    public static function ensurePages(): void
    {
        $existing = self::pageId();

        if ($existing > 0 && get_post_status($existing) === 'publish') {
            return;
        }

        $page = get_page_by_path(self::SLUG);

        if ($page && $page->post_status === 'publish') {
            update_option(self::OPTION_PAGE, (int) $page->ID);

            return;
        }

        $pageId = wp_insert_post([
            'post_title'     => self::TITLE,
            'post_name'      => self::SLUG,
            'post_content'   => sprintf(
                '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
                esc_html(self::TITLE . 'は現在準備中です。内容が確定しましたら、このページに掲載します。')
            ),
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);

        if (!is_wp_error($pageId)) {
            update_option(self::OPTION_PAGE, (int) $pageId);
        }
    }

    public static function renderCheckbox(): void
    {
        self::renderAgreement(!empty($_POST['mk_terms_agree']));
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
        $pageId = self::pageId();

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

    /** The same checkbox on the page where a buyer becomes a creator. */
    public static function renderMigrationCheckbox(): void
    {
        self::renderAgreement(false);
    }

    /**
     * Stop the become-a-creator submission when the box is not ticked.
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
            wc_add_notice('出品者になるには、利用規約およびプライバシーポリシーへの同意が必要です。', 'error');
        }
    }

    private static function renderAgreement(bool $checked): void
    {
        $terms   = self::url();
        $privacy = self::privacyUrl();

        echo '<p class="form-row mk-terms"><label class="mk-terms__label">';

        printf(
            '<input type="checkbox" name="mk_terms_agree" value="1"%s> <span>',
            $checked ? ' checked' : ''
        );

        if ($terms !== '') {
            printf('<a href="%s" target="_blank" rel="noopener" class="mk-terms__link">%s</a>', esc_url($terms), esc_html(self::TITLE));
        } else {
            echo esc_html(self::TITLE);
        }

        if ($privacy !== '') {
            printf('および<a href="%s" target="_blank" rel="noopener">プライバシーポリシー</a>', esc_url($privacy));
        }

        echo 'に同意する <span class="required" aria-hidden="true">*</span></span></label></p>';
    }
}
