<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * Stops two buyers paying for the same one-of-a-kind item.
 *
 * ---------------------------------------------------------------------------
 * Why a conditional UPDATE rather than read-then-write
 * ---------------------------------------------------------------------------
 * The obvious implementation -- "check whether it is sold, then mark it sold"
 * -- has a window between the check and the write. Two buyers hitting Buy in
 * the same second both read `publish`, both proceed, and both are charged for
 * an item that exists once. Refunding one of them is the good outcome; the
 * bad one is a creator with two orders and one jacket.
 *
 * A single UPDATE with the expected status in the WHERE clause IS the lock.
 * MySQL serialises the row write, so exactly one caller sees one affected row
 * and every other caller sees zero. There is no window.
 *
 * The Bubble build needed a reservation field, an expiry timestamp, a sweeper
 * and a webhook to approximate this. Here it is one statement.
 *
 * ---------------------------------------------------------------------------
 * Listings with a quantity
 * ---------------------------------------------------------------------------
 * Most of this marketplace is one-of-a-kind, and for those the status IS the
 * lock. A seller with three of the same shirt needs something else: taking the
 * listing off sale on the first purchase would strand the other two.
 *
 * The same trick applies one level down. Instead of a conditional UPDATE on
 * the post's status, it is a conditional UPDATE on the stock count -- decrement
 * only if at least one is left -- and again MySQL serialises the row, so of two
 * buyers racing for the last shirt exactly one gets a row back.
 *
 * A held unit belongs to an order rather than to the product, because several
 * buyers can legitimately hold units of the same listing at the same moment
 * and one _mk_reserved_by cannot describe that. The sweeper therefore reclaims
 * units by looking at abandoned orders, not at the product.
 */
final class Reservation
{
    public const META_RESERVED_BY    = '_mk_reserved_by';
    public const META_RESERVED_UNTIL = '_mk_reserved_until';

    /** Set on an order that is holding one unit of a stocked listing. */
    public const META_HOLDS_UNIT = '_mk_holds_stock_unit';

    /** How long a checkout may hold an item before the sweeper reclaims it. */
    private const HOLD_MINUTES = 20;

    /** Whether this listing is counted by quantity rather than sold as one item. */
    public static function tracksStock(int $productId): bool
    {
        return get_post_meta($productId, '_manage_stock', true) === 'yes';
    }

    public static function stockOf(int $productId): int
    {
        return (int) get_post_meta($productId, '_stock', true);
    }

    /**
     * Claim the product, or one unit of it, for this buyer.
     *
     * @return bool true if this caller won the race.
     */
    public function lock(int $productId, int $buyerId): bool
    {
        // An offer to record, not an object. Any number of buyers may order a
        // message video; locking it would sell exactly one.
        if (MessageVideo::isMessageVideo($productId)) {
            return true;
        }

        if (self::tracksStock($productId)) {
            return $this->takeUnit($productId);
        }

        global $wpdb;

        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts}
                    SET post_status = %s
                  WHERE ID = %d
                    AND post_status = %s",
                Statuses::RESERVED,
                $productId,
                'publish'
            )
        );

        if ($claimed !== 1) {
            return false;
        }

        update_post_meta($productId, self::META_RESERVED_BY, $buyerId);
        update_post_meta(
            $productId,
            self::META_RESERVED_UNTIL,
            gmdate('Y-m-d H:i:s', time() + self::HOLD_MINUTES * MINUTE_IN_SECONDS)
        );

        clean_post_cache($productId);

        return true;
    }

    /**
     * Take one unit, if there is one to take.
     *
     * CAST to SIGNED because _stock is stored as a string: without it MySQL
     * compares '10' against 1 as text and a listing with ten in stock can
     * fail the test that a listing with two passes.
     */
    private function takeUnit(int $productId): bool
    {
        global $wpdb;

        $taken = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                    SET meta_value = CAST(meta_value AS SIGNED) - 1
                  WHERE post_id = %d
                    AND meta_key = '_stock'
                    AND CAST(meta_value AS SIGNED) >= 1",
                $productId
            )
        );

        if ($taken !== 1) {
            return false;
        }

        clean_post_cache($productId);

        // The last one: the listing comes off sale exactly as a one-off does.
        if (self::stockOf($productId) <= 0) {
            update_post_meta($productId, '_stock_status', 'outofstock');

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_status = %s WHERE ID = %d AND post_status = %s",
                    Statuses::SOLD,
                    $productId,
                    'publish'
                )
            );

            clean_post_cache($productId);
        }

        return true;
    }

    /** Give a held unit back, and put the listing on sale again if it was the last. */
    private function returnUnit(int $productId): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                    SET meta_value = CAST(meta_value AS SIGNED) + 1
                  WHERE post_id = %d AND meta_key = '_stock'",
                $productId
            )
        );

        update_post_meta($productId, '_stock_status', 'instock');

        // Only from sold. A listing the seller has since unpublished, or that
        // an operator withdrew, must not be put back on sale by a refund.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_status = %s WHERE ID = %d AND post_status = %s",
                'publish',
                $productId,
                Statuses::SOLD
            )
        );

        clean_post_cache($productId);
    }

    /** Payment failed or was abandoned: put it back on sale. */
    public function release(int $productId): void
    {
        if (MessageVideo::isMessageVideo($productId)) {
            return;   // nothing was taken, so nothing goes back
        }

        if (self::tracksStock($productId)) {
            $this->returnUnit($productId);

            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts}
                    SET post_status = %s
                  WHERE ID = %d
                    AND post_status = %s",
                'publish',
                $productId,
                Statuses::RESERVED
            )
        );

        delete_post_meta($productId, self::META_RESERVED_BY);
        delete_post_meta($productId, self::META_RESERVED_UNTIL);

        clean_post_cache($productId);
    }

    /**
     * Payment confirmed by Stripe. The item is gone for good.
     *
     * For a listing with a quantity the unit was already taken at lock time,
     * and the listing came off sale then if it was the last one. All that is
     * left here is to stop treating it as a held unit.
     */
    public function markSold(int $productId): void
    {
        if (MessageVideo::isMessageVideo($productId)) {
            return;   // stays on sale for the next buyer
        }

        if (self::tracksStock($productId)) {
            delete_post_meta($productId, self::META_RESERVED_UNTIL);
            clean_post_cache($productId);

            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_status = %s WHERE ID = %d",
                Statuses::SOLD,
                $productId
            )
        );

        delete_post_meta($productId, self::META_RESERVED_UNTIL);

        clean_post_cache($productId);
    }

    /**
     * Reclaim items held by checkouts that were never completed.
     *
     * Without this a buyer who opens checkout and closes the tab locks the
     * item permanently. The creator sees a listing nobody can buy and has no
     * way to understand why -- a support ticket that is very hard to answer.
     *
     * @return int number of products released
     */
    public function sweepExpired(): int
    {
        global $wpdb;

        $expired = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                   FROM {$wpdb->posts} p
                   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                  WHERE p.post_status = %s
                    AND m.meta_key = %s
                    AND m.meta_value < %s
                  LIMIT 100",
                Statuses::RESERVED,
                self::META_RESERVED_UNTIL,
                gmdate('Y-m-d H:i:s')
            )
        );

        foreach ($expired as $id) {
            $this->release((int) $id);
        }

        return count($expired) + $this->sweepAbandonedUnits();
    }

    /**
     * Give back units held by checkouts that were never paid for.
     *
     * A one-off item is reclaimed by its own status, which is why the query
     * above can find it. A unit of a stocked listing leaves no mark on the
     * product at all -- the only record that it is being held is the unpaid
     * order holding it, so that is what is swept.
     *
     * @return int units returned
     */
    private function sweepAbandonedUnits(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::HOLD_MINUTES * MINUTE_IN_SECONDS);

        $orders = wc_get_orders([
            'status'       => ['pending'],
            'limit'        => 50,
            'date_created' => '<' . $cutoff,
            'meta_query'   => [
                [
                    'key'   => self::META_HOLDS_UNIT,
                    'value' => 'yes',
                ],
            ],
        ]);

        $returned = 0;

        foreach ($orders as $order) {
            $productId = (int) $order->get_meta('_mk_product_id');

            if ($productId > 0) {
                $this->returnUnit($productId);
                $returned++;
            }

            // Cleared first: if cancelling throws, the unit must not be
            // returned a second time on the next sweep.
            $order->update_meta_data(self::META_HOLDS_UNIT, 'no');
            $order->save();

            $order->update_status('cancelled', '購入手続きが完了しなかったため、在庫を戻しました。');
        }

        return $returned;
    }

    public function isReservedBy(int $productId, int $buyerId): bool
    {
        return (int) get_post_meta($productId, self::META_RESERVED_BY, true) === $buyerId;
    }
}
