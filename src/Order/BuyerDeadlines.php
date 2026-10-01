<?php
declare(strict_types=1);

namespace MK\Order;

use WC_Order;

/**
 * The dates on the buyer's 購入履歴, in red.
 *
 * Both deadlines in a transaction belong to the buyer as much as to the
 * creator -- when the parcel is promised by, and when the order will confirm
 * itself if they say nothing -- and until now neither appeared on the list
 * they actually look at (client, 2026-10-01). They were a line inside the
 * order's own page, two taps away.
 *
 * Rendered into the status column rather than a new one: a phone shows the
 * orders table at four columns already, and a fifth would wrap every row.
 * WooCommerce hands the whole column over to whoever hooks it, so the status
 * itself is printed here too.
 */
final class BuyerDeadlines
{
    public static function register(): void
    {
        add_action('woocommerce_my_account_my_orders_column_order-status', [self::class, 'column']);
    }

    /**
     * @param mixed $order
     */
    public static function column($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        printf('<span class="mk-order-status">%s</span>', esc_html(wc_get_order_status_name($order->get_status())));

        $line = self::line($order);

        if ($line !== '') {
            printf('<span class="mk-due mk-due--row">%s</span>', esc_html($line));
        }
    }

    /**
     * What this order is waiting on, if it is waiting on a date.
     */
    public static function line(WC_Order $order): string
    {
        $status = $order->get_status();

        if ($status === Statuses::PAID) {
            $due = DispatchDeadline::dueLabel($order);

            if ($due === '—') {
                return '';
            }

            return DispatchDeadline::isOverdue($order)
                ? sprintf('%s期限（%s）を過ぎています', DispatchDeadline::verb($order), $due)
                : sprintf('%s期限：%s', DispatchDeadline::verb($order), $due);
        }

        if ($status === Statuses::SHIPPED) {
            $auto = self::autoCompleteLabel($order);

            return $auto !== '' ? sprintf('%s に自動で受取確認となります', $auto) : '';
        }

        return '';
    }

    /**
     * When an unconfirmed order confirms itself.
     *
     * Computed from the shipping date rather than read from the queue: the
     * scheduled job knows the time but not in a form a template can ask for,
     * and the two cannot disagree because both are that date plus the same
     * setting.
     */
    private static function autoCompleteLabel(WC_Order $order): string
    {
        $shipped = (string) $order->get_meta(Shipping::META_SHIPPED_AT);

        if ($shipped === '') {
            return '';
        }

        $days = (int) get_option('mk_auto_complete_days', 7);
        $at   = strtotime($shipped . ' UTC');

        if ($at === false) {
            return '';
        }

        return (string) wp_date('n月j日', $at + $days * DAY_IN_SECONDS);
    }
}
