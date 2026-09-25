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
        // A profile page, not a shop front: the client asked for the address,
        // phone, email and opening hours to go (2026-09-27). What stays is
        // the 特定商取引法 block for approved businesses -- that one is a
        // legal disclosure, not a shop detail (see Creator\Business).
        add_filter('option_dokan_appearance', [self::class, 'hideShopDetails']);

        add_filter('dokan_get_dashboard_nav', [self::class, 'renameSettings'], 15, 1);
        add_action('dokan_dashboard_before_widgets', [self::class, 'renderPrompt'], 8);

        add_action('dokan_settings_after_store_name', [self::class, 'renderField'], 10, 2);
        add_action('dokan_store_profile_saved', [self::class, 'save'], 10, 1);
        // Not dokan_store_header_after_store_name: that one fires INSIDE the
        // <h1>, which the theme lays out as a flex row -- the number and the
        // introduction became columns beside the shop name, and the name
        // wrapped one character per line. This hook is the list under it.
        add_action('dokan_store_header_info_fields', [self::class, 'renderHeader'], 5, 1);
    }

    /**
     * @param mixed $options
     * @return mixed
     */
    public static function hideShopDetails($options)
    {
        if (!is_array($options)) {
            return $options;
        }

        $hidden = is_array($options['hide_vendor_info'] ?? null) ? $options['hide_vendor_info'] : [];

        foreach (['address', 'phone', 'email'] as $field) {
            $hidden[$field] = 'on';
        }

        $options['hide_vendor_info'] = $hidden;
        $options['store_open_close'] = 'off';

        return $options;
    }

    /**
     * 設定 alone did not say that this is where a creator introduces
     * themselves (2026-09-27).
     *
     * @param mixed $nav
     * @return mixed
     */
    public static function renameSettings($nav)
    {
        if (!is_array($nav) || !isset($nav['settings'])) {
            return $nav;
        }

        $nav['settings']['title'] = 'プロフィール・設定';

        if (isset($nav['settings']['submenu']['store']['title'])) {
            $nav['settings']['submenu']['store']['title'] = 'プロフィール';
        }

        return $nav;
    }

    /** The way in, on the dashboard, while the profile is still empty. */
    public static function renderPrompt(): void
    {
        $userId = get_current_user_id();

        if ($userId <= 0 || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return;
        }

        if (self::bio($userId) !== '') {
            return;   // they have said their piece
        }

        printf(
            '<div class="dokan-w12 dokan-panel-inner-container"><div class="mk-profile-prompt">'
            . '<p class="mk-profile-prompt__title">プロフィールを設定しましょう</p>'
            . '<p class="mk-profile-prompt__body">アイコン画像と自己紹介を設定すると、'
            . 'あなたのページが購入者に伝わりやすくなります。</p>'
            . '<a class="mk-profile-prompt__button" href="%s">プロフィールを設定する</a>'
            . '</div></div>',
            esc_url(function_exists('dokan_get_navigation_url') ? dokan_get_navigation_url('settings/store') : home_url('/dashboard/settings/store/'))
        );
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
            . '<span class="dokan-form-help">プロフィールページのお名前の下に表示されます（%1$d文字まで）。'
            . 'どんな商品を出品しているか、購入者の方へ一言どうぞ。</span>'
            . '</div></div>',
            self::MAX_BIO,
            esc_textarea(self::bio($userId))
        );

        // The address and telephone fields below this are Dokan's, and a
        // creator filling them in has no way of knowing they are private.
        echo '<div class="dokan-form-group"><div class="dokan-w8 dokan-text-left mk-private-note">'
            . '住所・電話番号・メールアドレスは、プロフィールページには表示されません。'
            . '配送や法令対応のために運営が確認する情報です。'
            . '</div></div>';
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
