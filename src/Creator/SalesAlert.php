<?php
declare(strict_types=1);

namespace MK\Creator;

use MK\Order\DispatchDeadline;
use MK\Order\Statuses;
use MK\Product\MessageVideo;
use WC_Order;

/**
 * "Something sold" -- on the screen, not only in an email.
 *
 * A sale already sends the creator an email (Notify\Events::onStatusChanged).
 * Nothing said so anywhere they could see it: the client bought an item in the
 * live test, went looking for it on the creator's screens, and found no sign
 * of it at all (2026-09-20). An email that arrives while they are looking at
 * the dashboard is not what they check, and a creator who misses it simply
 * never posts the parcel.
 *
 * So the dashboard says it itself: a panel on every page until the order moves
 * on, and a count beside 注文 in the menu. Both are driven by the orders that
 * are actually waiting for the creator to do something -- 購入済 and nothing
 * further -- so they clear themselves the moment the parcel is registered.
 */
final class SalesAlert
{
    public static function register(): void
    {
        add_filter('dokan_get_dashboard_nav', [self::class, 'badge'], 20, 1);
        add_action('dokan_dashboard_content_inside_before', [self::class, 'banner'], 6);
        add_action('woocommerce_account_dashboard', [self::class, 'banner'], 6);
    }

    /**
     * Orders this creator has been paid for and not yet sent.
     *
     * @return array<int, WC_Order>
     */
    public static function waiting(int $userId, int $limit = 20): array
    {
        if ($userId <= 0 || !function_exists('wc_get_orders')) {
            return [];
        }

        $orders = wc_get_orders([
            'limit'      => $limit,
            'status'     => [Statuses::PAID],
            'meta_key'   => '_mk_creator_id',
            'meta_value' => $userId,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);

        return is_array($orders) ? array_filter($orders, static fn ($o): bool => $o instanceof WC_Order) : [];
    }

    /**
     * The count beside 注文 in the dashboard menu.
     *
     * @param array<string, mixed> $nav
     * @return array<string, mixed>
     */
    public static function badge($nav)
    {
        if (!is_array($nav) || !isset($nav['orders']['title'])) {
            return $nav;
        }

        $count = count(self::waiting(get_current_user_id()));

        if ($count > 0) {
            $nav['orders']['title'] .= sprintf(' <span class="mk-nav-badge">%d</span>', $count);
        }

        return $nav;
    }

    public static function banner(): void
    {
        $userId = get_current_user_id();

        if ($userId <= 0 || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return;
        }

        $orders = self::waiting($userId, 5);

        if ($orders === []) {
            return;
        }

        echo '<div class="mk-sold" role="status">';
        printf(
            '<p class="mk-sold__title">商品が購入されました（%d件）</p>',
            count($orders)
        );

        echo '<ul class="mk-sold__list">';

        foreach ($orders as $order) {
            $title = (string) $order->get_meta('_mk_title_snapshot');
            $video = MessageVideo::isMessageVideoOrder($order);
            $due   = (string) $order->get_meta(DispatchDeadline::META_DUE_AT);

            printf(
                '<li><strong>%s</strong><span class="mk-sold__meta">注文 #%d／%s%s</span></li>',
                esc_html($title !== '' ? $title : '商品'),
                $order->get_id(),
                esc_html($video ? '動画の撮影・送信をお願いします' : '発送のご準備をお願いします'),
                $due !== '' ? esc_html(sprintf('／期限 %s', mysql2date('n月j日', $due))) : ''
            );
        }

        echo '</ul>';

        printf(
            '<a class="mk-sold__button" href="%s">取引・発送の画面を開く</a>',
            esc_url(function_exists('dokan_get_navigation_url') ? dokan_get_navigation_url('orders') : home_url('/dashboard/orders/'))
        );

        echo '</div>';
    }
}
