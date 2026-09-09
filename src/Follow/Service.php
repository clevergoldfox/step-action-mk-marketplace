<?php
declare(strict_types=1);

namespace MK\Follow;

/**
 * Following a creator.
 *
 * ---------------------------------------------------------------------------
 * Why the follower count is not cached
 * ---------------------------------------------------------------------------
 * The obvious optimisation is a follower_count column kept in step with the
 * rows. It is not taken here. A denormalised counter is a second copy of a
 * fact, and a second copy drifts: a failed write, a row deleted when a user is
 * removed, a double-tap that slips past a guard, and the number is wrong
 * forever with nothing to correct it.
 *
 * This project has already been bitten by exactly that. An outstanding-balance
 * cache in wp_usermeta silently stopped tracking its ledger and a creator's
 * debt was deducted from every payout without ever clearing — because the
 * cached number and the truth had parted company and nothing noticed.
 *
 * COUNT(*) over an indexed creator_id cannot drift. At a marketplace of this
 * size it costs nothing worth measuring, and if it ever does, a cache can be
 * added deliberately with a rebuild path — which is a very different thing
 * from inheriting one that was never verified.
 */
final class Service
{
    /**
     * Follow a creator. Idempotent.
     *
     * The UNIQUE index on (follower_id, creator_id) is what makes it so: a
     * double-tapped button, a resubmitted form and a duplicated request all
     * collapse into the one row that already exists.
     */
    public function follow(int $followerId, int $creatorId): bool
    {
        if (!$this->isValidPair($followerId, $creatorId)) {
            return false;
        }

        global $wpdb;

        // INSERT IGNORE rather than check-then-insert: two taps arriving
        // together would both pass the check and one would fail on the index.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}mk_follows
                     (follower_id, creator_id, created_at)
                 VALUES (%d, %d, %s)",
                $followerId,
                $creatorId,
                current_time('mysql', true)
            )
        );

        return true;
    }

    public function unfollow(int $followerId, int $creatorId): void
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'mk_follows',
            ['follower_id' => $followerId, 'creator_id' => $creatorId],
            ['%d', '%d']
        );
    }

    public function isFollowing(int $followerId, int $creatorId): bool
    {
        if ($followerId <= 0 || $creatorId <= 0) {
            return false;
        }

        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}mk_follows
                  WHERE follower_id = %d AND creator_id = %d",
                $followerId,
                $creatorId
            )
        );
    }

    public function followerCount(int $creatorId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mk_follows WHERE creator_id = %d",
                $creatorId
            )
        );
    }

    /**
     * Creator ids this person follows, newest first.
     *
     * @return int[]
     */
    public function following(int $followerId, int $limit = 200): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT creator_id FROM {$wpdb->prefix}mk_follows
                  WHERE follower_id = %d ORDER BY id DESC LIMIT %d",
                $followerId,
                $limit
            )
        ) ?: []);
    }

    /**
     * Follower ids of a creator.
     *
     * Exists for notification: when a creator lists something new, this is
     * who hears about it.
     *
     * @return int[]
     */
    public function followers(int $creatorId, int $limit = 5000): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT follower_id FROM {$wpdb->prefix}mk_follows
                  WHERE creator_id = %d LIMIT %d",
                $creatorId,
                $limit
            )
        ) ?: []);
    }

    /**
     * A follow needs two different, real people, one of whom sells.
     *
     * Following yourself would inflate your own count and, once new-listing
     * notifications exist, mail you about your own products.
     */
    private function isValidPair(int $followerId, int $creatorId): bool
    {
        if ($followerId <= 0 || $creatorId <= 0 || $followerId === $creatorId) {
            return false;
        }

        if (!get_userdata($followerId) || !get_userdata($creatorId)) {
            return false;
        }

        return !function_exists('dokan_is_user_seller') || dokan_is_user_seller($creatorId);
    }

    /**
     * Drop a departing user's follows, in both directions.
     *
     * Without this, deleting a user leaves rows pointing at nobody: a
     * creator's follower count keeps counting people who no longer exist, and
     * a deleted creator stays in other people's following lists forever.
     */
    public static function purgeUser(int $userId): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->prefix . 'mk_follows', ['follower_id' => $userId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'mk_follows', ['creator_id' => $userId], ['%d']);
    }
}
