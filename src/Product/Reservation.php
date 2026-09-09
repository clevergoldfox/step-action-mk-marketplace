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
 */
final class Reservation
{
    public const META_RESERVED_BY    = '_mk_reserved_by';
    public const META_RESERVED_UNTIL = '_mk_reserved_until';

    /** How long a checkout may hold an item before the sweeper reclaims it. */
    private const HOLD_MINUTES = 20;

    /**
     * Claim the product for this buyer.
     *
     * @return bool true if this caller won the race.
     */
    public function lock(int $productId, int $buyerId): bool
    {
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

    /** Payment failed or was abandoned: put it back on sale. */
    public function release(int $productId): void
    {
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

    /** Payment confirmed by Stripe. The item is gone for good. */
    public function markSold(int $productId): void
    {
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

        return count($expired);
    }

    public function isReservedBy(int $productId, int $buyerId): bool
    {
        return (int) get_post_meta($productId, self::META_RESERVED_BY, true) === $buyerId;
    }
}
