<?php
declare(strict_types=1);

namespace MK\Creator;

use WP_User;

/**
 * Who this shop belongs to, at the top of their shop page.
 *
 * The client's reference for the shop page (2026-09-26) leads with three
 * things about the creator: their name, their creator number, and a sentence
 * in their own words. The name was there; the number was printed everywhere
 * except the page people arrive at from it; and there was nowhere for a
 * creator to say anything at all.
 *
 * The number is read, never issued here -- see Onboarding::renderNumberCard
 * for why that matters.
 *
 * The sentence is stored on the creator rather than in Dokan's settings blob:
 * Dokan builds that array from its own whitelist on every save, so a key it
 * does not know would be dropped the next time the creator changed their shop
 * name.
 */
final class StoreProfile
{
    public const META_BIO = 'mk_store_bio';

    /** Long enough for a few lines, short enough to stay a headline. */
    public const MAX_BIO = 200;

    private static bool $headerDone = false;

    public static function register(): void
    {
        add_action('dokan_settings_after_store_name', [self::class, 'renderField'], 10, 2);
        add_action('dokan_store_profile_saved', [self::class, 'save'], 10, 1);
        // Not dokan_store_header_after_store_name: that one fires INSIDE the
        // <h1>, which the theme lays out as a flex row -- the number and the
        // introduction became columns beside the shop name, and the name
        // wrapped one character per line. This hook is the list under it.
        add_action('dokan_store_header_info_fields', [self::class, 'renderHeader'], 5, 1);
    }

    public static function bio(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_BIO, true);
    }

    // --------------------------------------------------------------- settings

    /**
     * @param mixed $currentUser
     * @param mixed $profile
     */
    public static function renderField($currentUser = null, $profile = null): void
    {
        $userId = $currentUser instanceof WP_User ? $currentUser->ID : (int) $currentUser;

        printf(
            '<div class="dokan-form-group">'
            . '<label class="dokan-w3 dokan-control-label" for="mk_store_bio">自己紹介</label>'
            . '<div class="dokan-w5 dokan-text-left">'
            . '<textarea class="dokan-form-control" name="settings[mk_bio]" id="mk_store_bio" rows="3" maxlength="%1$d">%2$s</textarea>'
            . '<span class="dokan-form-help">ショップページのお名前の下に表示されます（%1$d文字まで）。'
            . 'どんな商品を出品しているか、購入者の方へ一言どうぞ。</span>'
            . '</div></div>',
            self::MAX_BIO,
            esc_textarea(self::bio($userId))
        );
    }

    /**
     * Dokan rebuilds its settings array from a whitelist, so this field is
     * saved from the same request rather than left to it.
     *
     * @param mixed $storeId
     */
    public static function save($storeId = 0): void
    {
        $storeId = (int) $storeId;

        if ($storeId <= 0 || !isset($_POST['settings']['mk_bio'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Dokan verified it before firing this.
        $bio = sanitize_textarea_field(wp_unslash((string) $_POST['settings']['mk_bio']));
        $bio = trim((string) preg_replace('/\n{3,}/u', "\n\n", $bio));

        if ($bio === '') {
            delete_user_meta($storeId, self::META_BIO);

            return;
        }

        update_user_meta($storeId, self::META_BIO, mb_substr($bio, 0, self::MAX_BIO));
    }

    // ----------------------------------------------------------------- header

    /**
     * The number and the sentence, under the shop name.
     *
     * Dokan's header template fires this hook twice, once per layout, and
     * only one of them is ever visible -- printing in both would put a second
     * copy in the page for a screen reader to read out.
     *
     * @param mixed $storeUser
     */
    public static function renderHeader($storeUser = null): void
    {
        if (self::$headerDone) {
            return;
        }

        $userId = self::userIdOf($storeUser);

        if ($userId <= 0) {
            return;
        }

        self::$headerDone = true;

        $number = (string) get_user_meta($userId, Onboarding::USER_META_NUMBER, true);
        $bio    = self::bio($userId);

        // <li>, because this hook prints into Dokan's ul.dokan-store-info.
        if ($number !== '') {
            printf(
                '<li class="mk-store-number">クリエイター番号：<span>%s</span></li>',
                esc_html($number)
            );
        }

        if ($bio !== '') {
            printf('<li class="mk-store-bio">%s</li>', nl2br(esc_html($bio)));
        }
    }

    /** @param mixed $storeUser a WP_User, a Dokan vendor, or an id */
    private static function userIdOf($storeUser): int
    {
        if (is_numeric($storeUser)) {
            return (int) $storeUser;
        }

        if (is_object($storeUser)) {
            if (method_exists($storeUser, 'get_id')) {
                return (int) $storeUser->get_id();
            }

            if (isset($storeUser->ID)) {
                return (int) $storeUser->ID;
            }
        }

        return (int) get_query_var('author');
    }
}
