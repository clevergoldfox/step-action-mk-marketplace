<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * マイページ, told apart for someone who both buys and sells.
 *
 * WooCommerce calls its order list 「注文」, which on a creator's マイページ is
 * their own shopping -- not the sales they were looking for (2026-09-20). The
 * list is renamed to say which it is, and a creator gets a second entry that
 * leads to their listings in the 出品者ダッシュボード.
 *
 * Their purchases stay where they are. A creator is a buyer too, and the
 * orders they have placed have to live somewhere; the fix for "I could not
 * find my sales" is a route to the sales, not the loss of the receipts.
 */
final class SellerMenu
{
    /** A menu entry that is a link elsewhere rather than an endpoint. */
    public const LISTINGS = 'mk-listings';

    public static function register(): void
    {
        add_filter('woocommerce_account_menu_items', [self::class, 'items'], 20, 1);
        add_filter('woocommerce_get_endpoint_url', [self::class, 'endpointUrl'], 10, 4);

        // The way back. マイページ has led to the 出品者ダッシュボード since it
        // existed; the dashboard led nowhere, so a creator who wanted their
        // own purchases had to find the site's own menu again. The client
        // chose this over a 出品者モード／購入者モード switch, which would
        // have been a state to get lost in rather than a way across
        // (2026-09-24).
        add_filter('dokan_get_dashboard_nav', [self::class, 'backToAccount'], 30, 1);
    }

    /**
     * @param mixed $nav
     * @return mixed
     */
    public static function backToAccount($nav)
    {
        if (!is_array($nav)) {
            return $nav;
        }

        $nav['mk-my-account'] = [
            'title' => 'マイページ（購入者画面）',
            'icon'  => '<i class="fas fa-user"></i>',
            'url'   => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/'),
            'pos'   => 200,
        ];

        return $nav;
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public static function items($items)
    {
        if (!is_array($items)) {
            return $items;
        }

        if (isset($items['orders'])) {
            $items['orders'] = '購入履歴';
        }

        if (!self::isSeller()) {
            return $items;
        }

        $reordered = [];

        foreach ($items as $key => $label) {
            $reordered[$key] = $label;

            // Straight after the dashboard, above the buying side: for a
            // creator this is the entry they are looking for.
            if ($key === 'dashboard') {
                $reordered[self::LISTINGS] = '出品履歴';
            }
        }

        return isset($reordered[self::LISTINGS])
            ? $reordered
            : [self::LISTINGS => '出品履歴'] + $reordered;
    }

    /**
     * @param string $url
     * @param string $endpoint
     * @param string $value
     * @param string $permalink
     * @return string
     */
    public static function endpointUrl($url, $endpoint = '', $value = '', $permalink = '')
    {
        if ($endpoint !== self::LISTINGS) {
            return $url;
        }

        return function_exists('dokan_get_navigation_url')
            ? dokan_get_navigation_url('products')
            : home_url('/dashboard/products/');
    }

    private static function isSeller(): bool
    {
        $userId = get_current_user_id();

        return $userId > 0
            && function_exists('dokan_is_user_seller')
            && dokan_is_user_seller($userId);
    }
}
