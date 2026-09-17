<?php
declare(strict_types=1);

namespace MK\Line;

use MK\Creator\Onboarding;

/**
 * Fixed entry points for the LINE official account's rich menu.
 *
 * The rich menu is configured in Lステップ, outside this codebase, and every
 * change to it means re-uploading the menu there. So the menu links to
 * addresses on this site that never change -- /line/sell/, /line/mypage/ --
 * and this class decides where each one actually goes. Moving a page,
 * renaming a Dokan endpoint or adding a guide later is then a change here,
 * not a rich-menu rebuild the client has to do by hand.
 *
 * It also answers "who is tapping". The same 出品する button has to take a
 * logged-in creator to the listing form, a buyer to the page for becoming a
 * seller, and someone not logged in to the login form and then back to where
 * they were going. A static URL in the menu can do none of that.
 *
 * Handled at parse_request rather than with a rewrite rule, so there is no
 * rule to flush and nothing that can silently stop matching after a
 * permalink change.
 */
final class Links
{
    public const PREFIX = 'line';

    /**
     * Alternative prefix for the same destinations.
     *
     * The site's own menu needs the same "send this person to the right
     * place" behaviour, and /line/sell/ in the address bar of someone who
     * never came from LINE reads as a mistake. Same resolver, two doors.
     */
    public const ALT_PREFIX = 'go';

    /** Where to send someone after they log in. Holds a slug, never a URL. */
    private const COOKIE = 'mk_line_after_login';

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'route'], 1);

        // After Dokan's own (priority 1), which sends every seller to the
        // dashboard regardless of where they were going.
        add_filter('woocommerce_login_redirect', [self::class, 'afterLogin'], 100, 1);
    }

    /**
     * Every entry point, with the label to put on the rich-menu button.
     *
     * @return array<string,array{label:string,login:bool}>
     */
    public static function destinations(): array
    {
        return [
            'home'    => ['label' => 'ホーム',                   'login' => false],
            'buyer'   => ['label' => '購入者の方（商品をさがす）', 'login' => false],
            'search'  => ['label' => '商品一覧',                 'login' => false],
            'creator' => ['label' => 'クリエイター番号で探す',     'login' => false],
            'seller'  => ['label' => '出品者の方（出品者ページ）', 'login' => true],
            'sell'    => ['label' => '出品する',                 'login' => true],
            'sales'   => ['label' => '取引・発送（出品者）',       'login' => true],
            'payouts' => ['label' => '売上・受取設定',            'login' => true],
            'mypage'  => ['label' => 'マイページ',               'login' => false],
            'orders'  => ['label' => '購入履歴・取引メッセージ',   'login' => true],
            'claim'   => ['label' => '運営への申し出',             'login' => true],
        ];
    }

    public static function url(string $slug, string $prefix = self::PREFIX): string
    {
        return home_url('/' . $prefix . '/' . $slug . '/');
    }

    /** The menu-facing address for a slug: /go/{slug}/. */
    public static function menuUrl(string $slug): string
    {
        return self::url($slug, self::ALT_PREFIX);
    }

    /**
     * Where this slug goes for this user.
     *
     * Returns null for an unknown slug, and the login page for a slug that
     * needs an account when nobody is logged in.
     */
    public static function resolve(string $slug, int $userId): ?string
    {
        $all = self::destinations();

        if (!isset($all[$slug])) {
            return null;
        }

        $account = wc_get_page_permalink('myaccount');

        if ($all[$slug]['login'] && $userId === 0) {
            return $account;
        }

        $isSeller = $userId > 0 && dokan_is_user_seller($userId);

        // A buyer tapping a seller button: the page that turns their
        // existing account into a seller account, rather than a dashboard
        // that would only refuse them.
        $becomeSeller = wc_get_account_endpoint_url('account-migration');

        return match ($slug) {
            'home', 'buyer' => home_url('/'),
            'search'        => wc_get_page_permalink('shop'),
            'creator'       => home_url('/#creator-search'),
            'seller'        => $isSeller ? dokan_get_navigation_url() : $becomeSeller,
            'sell'          => $isSeller ? dokan_get_navigation_url('new-product') : $becomeSeller,
            'sales'         => $isSeller ? dokan_get_navigation_url('orders') : $becomeSeller,
            'payouts'       => $isSeller ? dokan_get_navigation_url(Onboarding::PAGE) : $becomeSeller,
            'mypage'        => $account,
            'orders'        => wc_get_account_endpoint_url('orders'),
            'claim'         => \MK\Report\ClaimPage::url(),
        };
    }

    public static function route(\WP $wp): void
    {
        $path = trim((string) $wp->request, '/');

        $pattern = '#^(' . self::PREFIX . '|' . self::ALT_PREFIX . ')/([a-z]+)$#';

        if (!preg_match($pattern, $path, $m)) {
            return;
        }

        if (!function_exists('wc_get_page_permalink') || !function_exists('dokan_is_user_seller')) {
            return;
        }

        $slug   = $m[2];
        $userId = get_current_user_id();
        $target = self::resolve($slug, $userId);

        if ($target === null) {
            // A mistyped rich-menu link should still land somewhere useful,
            // not on a 404 inside LINE's browser.
            $target = home_url('/');
        } elseif ($userId === 0 && self::destinations()[$slug]['login']) {
            setcookie(self::COOKIE, $slug, [
                'expires'  => time() + 15 * MINUTE_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // Before launch the storefront is behind WooCommerce's coming-soon
        // page. Carrying the private preview key through lets the client test
        // the rich menu before the site opens.
        if (isset($_GET['woo-share'])) {
            $target = add_query_arg('woo-share', sanitize_text_field(wp_unslash($_GET['woo-share'])), $target);
        }

        nocache_headers();
        wp_safe_redirect($target, 302, 'Treasure Buzz LINE');
        exit;
    }

    /**
     * Return to the rich-menu destination once logged in.
     *
     * Back through /line/{slug}/ rather than straight to a stored URL, so the
     * destination is decided for the account that just logged in -- a buyer
     * who tapped 出品する must not be sent to a seller dashboard -- and so a
     * tampered cookie can only ever name one of the slugs above.
     */
    public static function afterLogin(string $redirect): string
    {
        $slug = isset($_COOKIE[self::COOKIE]) ? sanitize_key((string) $_COOKIE[self::COOKIE]) : '';

        if ($slug === '' || !isset(self::destinations()[$slug])) {
            return $redirect;
        }

        setcookie(self::COOKIE, '', [
            'expires' => time() - HOUR_IN_SECONDS,
            'path'    => COOKIEPATH ?: '/',
            'domain'  => COOKIE_DOMAIN ?: '',
        ]);

        return self::url($slug);
    }
}
