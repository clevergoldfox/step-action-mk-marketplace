<?php
declare(strict_types=1);

namespace MK\Order;

use WC_Order;

/**
 * Tells Dokan which creator an order belongs to.
 *
 * Dokan decides whether a vendor may open an order by looking for one row in
 * its own dokan_orders table. That row is normally written by WooCommerce's
 * cart checkout -- which this marketplace deliberately bypasses (see
 * Checkout\Controller). So no order built here was ever recorded as its
 * creator's, and Dokan's order screen answered every creator with
 * 「この操作を行う権限がありません」.
 *
 * That screen is where the creator's actions live: 発送登録 for a parcel, and
 * 送信 / 辞退 for a message video. None of them could be reached, for any
 * order, since the checkout was written. Every piece had passed its own test;
 * it took driving a real order through the real creator page to find it.
 *
 * ---------------------------------------------------------------------------
 * Why this does not call dokan_sync_insert_order()
 * ---------------------------------------------------------------------------
 * Dokan's own function writes the row and ALSO books the sale into Dokan's
 * vendor balance ledger, using Dokan's commission rules. This marketplace pays
 * creators through Stripe on its own schedule with its own fees; a second,
 * Dokan-calculated withdrawable balance would show creators money that does
 * not exist and would never match what Stripe sends. So only the row that
 * grants access is written, carrying our own figures.
 *
 * Dokan keeps the row's status in step by itself afterwards
 * (Order\Hooks::on_order_status_change updates it on every status change,
 * custom statuses included), so nothing else here needs to.
 */
final class DokanSync
{
    public static function register(): void
    {
        // Early, so the row exists before anything that renders or checks it.
        add_action('woocommerce_order_status_changed', [self::class, 'onStatusChanged'], 5, 4);

        // And again last: Dokan writes this row too, on the same hook, with
        // its own commission model -- which on this site means the creator's
        // share is the whole order total. Written at 5 it was overwritten a
        // moment later, and the creator's 取引・発送 screen promised them
        // ¥8,000 of an ¥8,000 sale (2026-09-23).
        add_action('woocommerce_order_status_changed', [self::class, 'syncFigures'], 99, 4);

        // Not from the installer: see runPendingBackfill().
        add_action('wp_loaded', [self::class, 'runPendingBackfill']);
    }

    /**
     * Register existing orders, once, when WooCommerce can actually list them.
     *
     * The installer runs on plugins_loaded, before WooCommerce's order data
     * store exists. Calling wc_get_orders() from there was a fatal error on
     * every request -- the whole site, front end included -- so the installer
     * only raises a flag, and the work happens here on wp_loaded.
     *
     * The flag is cleared before the work starts. If the backfill fails part
     * way it is not retried on every page load; it can be re-run by setting
     * the flag again, and ensure() skips rows that already exist.
     */
    public static function runPendingBackfill(): void
    {
        if (get_option('mk_dokan_backfill_pending') !== 'yes') {
            return;
        }

        delete_option('mk_dokan_backfill_pending');

        $written = self::backfill();

        if ($written > 0) {
            error_log(sprintf('[mk-marketplace] registered %d existing orders with Dokan', $written));
        }
    }

    /**
     * Record this order as its creator's, if it is not already.
     *
     * @return bool true if a row now exists
     */
    public static function ensure(WC_Order $order): bool
    {
        $creatorId = (int) $order->get_meta('_mk_creator_id');

        if ($creatorId <= 0) {
            return false;   // not a marketplace order
        }

        // Two records, because Dokan reads two different places. The order
        // detail screen checks the dokan_orders row below; the order LIST --
        // the REST collection the creator's dashboard renders -- filters on
        // this meta instead. With only the row, a creator could open an order
        // from a direct link and never find it in their own list, which is
        // how it shipped the first time.
        if ((int) $order->get_meta('_dokan_vendor_id') !== $creatorId) {
            $order->update_meta_data('_dokan_vendor_id', $creatorId);
            $order->save_meta_data();
        }

        global $wpdb;

        $table = $wpdb->prefix . 'dokan_orders';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT seller_id FROM {$table} WHERE order_id = %d LIMIT 1",
            $order->get_id()
        ));

        if ($existing !== null) {
            return true;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'order_id'     => $order->get_id(),
                'seller_id'    => $creatorId,
                'order_total'  => (float) $order->get_total(),
                'net_amount'   => (float) (int) $order->get_meta('_mk_creator_amount'),
                'order_status' => 'wc-' . $order->get_status(),
            ],
            ['%d', '%d', '%f', '%f', '%s']
        );

        return $inserted === 1;
    }

    /**
     * Keep the creator's figure in the row current.
     *
     * The creator's share is only known once the payment intent has been
     * priced, which is after the order and its row were created. Dokan shows
     * this figure in the creator's order list, so a row left at zero would
     * tell every creator they earn nothing.
     */
    public static function onStatusChanged(int $orderId, string $from, string $to, WC_Order $order): void
    {
        if (!self::ensure($order)) {
            return;
        }

        $amount = (int) $order->get_meta('_mk_creator_amount');

        if ($amount <= 0) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'dokan_orders',
            ['net_amount' => (float) $amount],
            ['order_id' => $orderId],
            ['%f'],
            ['%d']
        );
    }

    /**
     * Put our figures back into Dokan's row, whoever wrote it last.
     *
     * The row feeds Dokan's own reads -- the order list's 受取額 column and
     * the order screen's 「この注文の受取額」 -- through a lookup that passes
     * no filter, so this is the only place the number can be corrected.
     *
     * @param mixed $order
     */
    public static function syncFigures($orderId, $from = '', $to = '', $order = null): void
    {
        $orderId = (int) $orderId;
        $order   = $order instanceof WC_Order ? $order : wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        self::writeFigures($order);
    }

    /** @return bool true if the row now holds our figures */
    public static function writeFigures(WC_Order $order): bool
    {
        $creatorId = (int) $order->get_meta('_mk_creator_id');
        $amount    = (int) $order->get_meta('_mk_creator_amount');

        if ($creatorId <= 0 || $amount <= 0) {
            return false;   // not ours, or not priced yet
        }

        global $wpdb;

        $table = $wpdb->prefix . 'dokan_orders';
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT seller_id, order_total, net_amount FROM {$table} WHERE order_id = %d LIMIT 1",
            $order->get_id()
        ));

        if ($row === null) {
            return false;   // ensure() writes it; nothing to correct yet
        }

        $total = (float) $order->get_total();

        if ((int) $row->seller_id === $creatorId
            && abs((float) $row->net_amount - (float) $amount) < 0.01
            && abs((float) $row->order_total - $total) < 0.01) {
            return true;
        }

        $wpdb->update(
            $table,
            ['seller_id' => $creatorId, 'order_total' => $total, 'net_amount' => (float) $amount],
            ['order_id' => $order->get_id()],
            ['%d', '%f', '%f'],
            ['%d']
        );

        // Dokan remembers what it read from this table.
        if (class_exists('\WeDevs\Dokan\Cache')) {
            foreach (['seller', 'admin'] as $context) {
                \WeDevs\Dokan\Cache::delete(sprintf('get_earning_from_order_table_%d_%s', $order->get_id(), $context));
                \WeDevs\Dokan\Cache::delete(sprintf('get_earning_from_order_table_%d_%s_raw', $order->get_id(), $context));
            }
        }

        return true;
    }

    /**
     * Record every existing marketplace order.
     *
     * @return int rows written
     */
    public static function backfill(): int
    {
        $written = 0;
        $page    = 1;

        do {
            $orders = wc_get_orders([
                'limit'      => 200,
                'paged'      => $page++,
                'status'     => array_keys(wc_get_order_statuses()),
                'meta_key'   => '_mk_creator_id',
                'meta_compare' => 'EXISTS',
            ]);

            foreach ($orders as $order) {
                global $wpdb;

                $hadRow = (bool) $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d",
                    $order->get_id()
                ));

                $hadMeta = (int) $order->get_meta('_dokan_vendor_id') > 0;

                // Always run: an order registered before the meta was added has
                // its row but not its list entry, and needs the second fixed.
                if (self::ensure($order) && (!$hadRow || !$hadMeta)) {
                    $written++;
                }

                // And put our figures back over whatever Dokan last wrote.
                self::writeFigures($order);
            }
        } while (count($orders) === 200);

        return $written;
    }
}
