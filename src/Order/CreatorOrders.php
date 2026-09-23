<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Checkout\ShippingAddress;
use WC_Order;

/**
 * The creator's 取引・発送 screen, saying what this marketplace means.
 *
 * Dokan's order list is built for Dokan's own money model and its own
 * statuses, and on this site it read wrong in four ways at once, which is
 * what the client saw when the screen did not match the button that opened it
 * (2026-09-23):
 *
 *  - The menu called it 「注文」 while every route to it -- the sold panel,
 *    the LINE menu, the emails -- calls it 取引・発送.
 *  - 受取額 showed the whole order total, because Dokan works it out from its
 *    own commission tables, which this project never writes to. A ¥8,000 sale
 *    promised ¥8,000 where ¥6,880 is due. Same root as DashboardFigures.
 *  - The 状態 column was blank for every live order: Dokan translates order
 *    statuses from a hard-coded list that knows nothing of 購入済, 発送済 or
 *    受取確認.
 *  - The buyer was 「ゲスト」, because Dokan reads the billing name and this
 *    checkout collects a delivery address instead.
 *
 * The buyer's name is answered from the delivery address, and only while the
 * creator is entitled to see it: once ShippingAddress has masked the address
 * -- the receipt confirmed, the parcel long delivered -- the name goes back
 * to 「購入者」 with it. Nothing is written to the order, so there is no second
 * copy of the name to mask later.
 */
final class CreatorOrders
{
    public static function register(): void
    {
        // Before SalesAlert::badge (20) appends the count to this title.
        add_filter('dokan_get_dashboard_nav', [self::class, 'renameMenu'], 10, 1);

        add_filter('dokan_get_order_status_translated', [self::class, 'statusLabel'], 10, 2);
        add_filter('dokan_get_earning_by_order', [self::class, 'earning'], 10, 3);

        add_filter('woocommerce_order_get_billing_first_name', [self::class, 'buyerFirstName'], 10, 2);
        add_filter('woocommerce_order_get_billing_last_name', [self::class, 'buyerLastName'], 10, 2);
    }

    /**
     * @param mixed $nav
     * @return mixed
     */
    public static function renameMenu($nav)
    {
        if (is_array($nav) && isset($nav['orders']['title'])) {
            $nav['orders']['title'] = '取引・発送';
        }

        return $nav;
    }

    /**
     * @param mixed  $label
     * @param string $status
     * @return mixed
     */
    public static function statusLabel($label, $status = '')
    {
        $bare = Statuses::bare((string) $status);
        $ours = Statuses::custom();

        return isset($ours[$bare]) ? $ours[$bare]['label'] : $label;
    }

    /**
     * What this order actually pays the creator.
     *
     * Frozen onto the order at checkout by OrderBuilder, fees and rates as
     * they stood then -- the same figure the 売上状況 panel and the payouts
     * page report. Anything without it is left to Dokan rather than guessed
     * at.
     *
     * @param mixed  $earning
     * @param mixed  $order
     * @param string $context
     * @return mixed
     */
    public static function earning($earning, $order = null, $context = 'seller')
    {
        if ($context !== 'seller') {
            return $earning;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order($order);

        if (!$order instanceof WC_Order) {
            return $earning;
        }

        $ours = $order->get_meta('_mk_creator_amount');

        return $ours === '' || $ours === null ? $earning : (float) $ours;
    }

    /** @param mixed $order */
    public static function buyerFirstName($value, $order = null)
    {
        return self::buyerName($value, $order, 'first');
    }

    /** @param mixed $order */
    public static function buyerLastName($value, $order = null)
    {
        return self::buyerName($value, $order, 'last');
    }

    /**
     * @param mixed  $value
     * @param mixed  $order
     * @param string $part 'first' or 'last'
     * @return mixed
     */
    private static function buyerName($value, $order, string $part)
    {
        // Only the creator's own screens. wp-admin keeps whatever the order
        // really carries, so the operator is never shown a name we invented.
        if (is_admin() || $value !== '' || !$order instanceof WC_Order) {
            return $value;
        }

        if ((int) $order->get_meta('_mk_creator_id') <= 0) {
            return $value;
        }

        if (ShippingAddress::isMasked($order)) {
            return $part === 'last' ? '購入者' : '';
        }

        $name = $part === 'last' ? $order->get_shipping_last_name() : $order->get_shipping_first_name();

        if ($name !== '') {
            return $name;
        }

        return $part === 'last' ? '購入者' : '';
    }
}
