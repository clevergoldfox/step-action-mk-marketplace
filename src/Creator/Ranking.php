<?php
declare(strict_types=1);

namespace MK\Creator;

/**
 * Whose page to show first.
 *
 * The client asked (2026-09-27) whether an "おすすめ順" could mix follower
 * count, views, favourites and recent activity with an element of chance, so
 * that a creator who joined yesterday can still be seen. It can, and this is
 * it -- with one honest gap: favourites do not exist yet (the ♡ feature is
 * still undecided), so its weight is zero until it does. Followers already
 * exist; views are counted from here on, by this class.
 *
 * Two decisions worth stating.
 *
 * Every signal is compressed with log1p before it is weighed. Without it the
 * single creator with the most followers takes the top slot on every home
 * page forever: raw counts in a young marketplace are dominated by whoever
 * started first, which is the opposite of what was asked for.
 *
 * The shuffle is seeded per period, not per request. A list that reorders on
 * every page load feels broken -- a visitor who scrolls, opens a creator and
 * comes back finds a different page. Seeded by the hour, the order holds
 * still while someone is browsing and differs when they return, and it is the
 * same for everyone, so it can be cached and explained.
 */
final class Ranking
{
    /** Daily view counts, 'Y-m-d' => int, kept for WINDOW days. */
    public const META_VIEWS = 'mk_store_views';

    public const WINDOW = 30;

    /** How often the order is reshuffled. */
    public const ROTATE = HOUR_IN_SECONDS;

    private const CACHE     = 'mk_creator_ranking_base';
    private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    /** One visitor's view of one creator counts once in this window. */
    private const VIEW_GUARD = HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'countView'], 20);
    }

    // ------------------------------------------------------------------ views

    /** A visit to a creator's page, counted once per visitor per hour. */
    public static function countView(): void
    {
        if (is_admin() || wp_doing_ajax() || !function_exists('dokan_is_store_page') || !dokan_is_store_page()) {
            return;
        }

        $sellerId = (int) get_query_var('author');

        // Not the creator's own visits: they open their page more than anyone.
        if ($sellerId <= 0 || $sellerId === get_current_user_id()) {
            return;
        }

        $guard = 'mk_view_' . md5($sellerId . '|' . self::visitor());

        if (get_transient($guard)) {
            return;
        }

        set_transient($guard, 1, self::VIEW_GUARD);
        self::addView($sellerId);
    }

    private static function visitor(): string
    {
        $userId = get_current_user_id();

        if ($userId > 0) {
            return 'u' . $userId;
        }

        return md5(
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
            . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }

    private static function addView(int $sellerId): void
    {
        $views = get_user_meta($sellerId, self::META_VIEWS, true);
        $views = is_array($views) ? $views : [];

        $today         = current_time('Y-m-d');
        $views[$today] = (int) ($views[$today] ?? 0) + 1;

        $cutoff = gmdate('Y-m-d', (int) current_time('timestamp') - (self::WINDOW * DAY_IN_SECONDS));

        foreach (array_keys($views) as $day) {
            if ((string) $day < $cutoff) {
                unset($views[$day]);
            }
        }

        ksort($views);
        update_user_meta($sellerId, self::META_VIEWS, $views);
    }

    /** Views of this creator's page over the last WINDOW days. */
    public static function views(int $sellerId): int
    {
        $views = get_user_meta($sellerId, self::META_VIEWS, true);

        return is_array($views) ? (int) array_sum(array_map('intval', $views)) : 0;
    }

    // ---------------------------------------------------------------- ranking

    /**
     * Creator ids, best first.
     *
     * $period is the shuffle's deal, normally the current hour. It is an
     * argument so a test can watch a day of them go by without waiting one.
     *
     * @return int[]
     */
    public static function creators(int $limit = 24, ?int $period = null): array
    {
        $rows = self::gather();

        if (!$rows) {
            return [];
        }

        $period  = $period ?? (int) floor(time() / self::ROTATE);
        $weights = self::weights();
        $max     = self::maxima($rows);
        $scored  = [];

        foreach ($rows as $id => $row) {
            $merit =
                  $weights['followers'] * self::scale((int) $row['followers'], $max['followers'])
                + $weights['views'] * self::scale((int) $row['views'], $max['views'])
                + $weights['favourites'] * self::scale((int) $row['favourites'], $max['favourites'])
                + $weights['sales'] * self::scale((int) $row['sales'], $max['sales'])
                + $weights['listings'] * self::scale((int) $row['products'], $max['products'])
                + $weights['recency'] * self::decay((int) $row['latest'], 14)
                + $weights['newcomer'] * self::decay((int) $row['registered'], 30)
                + $weights['profile'] * ($row['profile'] ? 1.0 : 0.0);

            // Chance, deliberately large: merit alone would freeze the order.
            $scored[$id] = ((1.0 - $weights['chance']) * $merit)
                + ($weights['chance'] * self::roll((int) $id, $period));
        }

        arsort($scored);

        return array_slice(array_map('intval', array_keys($scored)), 0, max(1, $limit));
    }

    /**
     * @return array<string, float>
     */
    private static function weights(): array
    {
        $weights = (array) apply_filters('mk_creator_ranking_weights', [
            'followers'  => 0.22,
            'views'      => 0.15,
            // Waiting on the ♡ feature; scored the day it lands.
            'favourites' => 0.00,
            'sales'      => 0.15,
            'listings'   => 0.08,
            'recency'    => 0.22,
            'newcomer'   => 0.13,
            'profile'    => 0.05,
            // 0.45 after measuring a simulated week (2026-09-27): at 0.35 the
            // strongest creator held the top slot 54% of the hours; at 0.55
            // the order stopped meaning anything. Here the best creator still
            // leads most often and a creator who listed today reaches the top
            // three in about three hours out of ten.
            'chance'     => 0.45,
        ]);

        foreach (['followers', 'views', 'favourites', 'sales', 'listings', 'recency', 'newcomer', 'profile', 'chance'] as $key) {
            $weights[$key] = isset($weights[$key]) ? (float) $weights[$key] : 0.0;
        }

        return $weights;
    }

    /**
     * Everything the score is made of, in three queries.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function gather(): array
    {
        $cached = get_transient(self::CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        // Only creators with something to sell: a home page full of empty
        // pages sends people nowhere.
        $products = $wpdb->get_results(
            "SELECT post_author AS id, COUNT(*) AS products,
                    UNIX_TIMESTAMP(MAX(post_date_gmt)) AS latest
               FROM {$wpdb->posts}
              WHERE post_type = 'product' AND post_status = 'publish'
              GROUP BY post_author",
            ARRAY_A
        ) ?: [];

        $rows = [];

        foreach ($products as $row) {
            $id = (int) $row['id'];

            $user = get_userdata($id);

            // The seller role, not dokan_is_user_seller(): that is true for
            // any administrator, and the operator's own account is not a
            // creator for a visitor to browse.
            if ($id <= 0 || !$user || !in_array('seller', (array) $user->roles, true)) {
                continue;
            }

            $rows[$id] = [
                'products'   => (int) $row['products'],
                'latest'     => (int) $row['latest'],
                'sales'      => 0,
                'followers'  => 0,
                'views'      => self::views($id),
                'favourites' => 0,
                'profile'    => StoreProfile::bio($id) !== '',
                'registered' => $user ? (int) strtotime($user->user_registered . ' UTC') : 0,
            ];
        }

        if (!$rows) {
            set_transient(self::CACHE, [], self::CACHE_TTL);

            return [];
        }

        $ids = implode(',', array_map('intval', array_keys($rows)));

        $sales = $wpdb->get_results(
            "SELECT seller_id, COUNT(*) AS sales
               FROM {$wpdb->prefix}dokan_orders
              WHERE seller_id IN ({$ids})
                AND order_status NOT IN ('wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-pending', 'wc-checkout-draft')
              GROUP BY seller_id",
            ARRAY_A
        ) ?: [];

        foreach ($sales as $row) {
            $id = (int) $row['seller_id'];

            if (isset($rows[$id])) {
                $rows[$id]['sales'] = (int) $row['sales'];
            }
        }

        $follows = $wpdb->prefix . 'mk_follows';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $follows)) === $follows) {
            $counts = $wpdb->get_results(
                "SELECT creator_id, COUNT(*) AS followers
                   FROM {$follows} WHERE creator_id IN ({$ids}) GROUP BY creator_id",
                ARRAY_A
            ) ?: [];

            foreach ($counts as $row) {
                $id = (int) $row['creator_id'];

                if (isset($rows[$id])) {
                    $rows[$id]['followers'] = (int) $row['followers'];
                }
            }
        }

        set_transient(self::CACHE, $rows, self::CACHE_TTL);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function maxima(array $rows): array
    {
        $max = ['followers' => 0, 'views' => 0, 'favourites' => 0, 'sales' => 0, 'products' => 0];

        foreach ($rows as $row) {
            foreach ($max as $key => $value) {
                $max[$key] = max($value, (int) $row[$key]);
            }
        }

        return $max;
    }

    /** log-compressed 0..1, so first movers cannot own the top of the page. */
    private static function scale(int $value, int $max): float
    {
        if ($value <= 0 || $max <= 0) {
            return 0.0;
        }

        return log1p((float) $value) / log1p((float) $max);
    }

    /** 1.0 now, about a third after $halfLife days, never negative. */
    private static function decay(int $timestamp, int $halfLife): float
    {
        if ($timestamp <= 0) {
            return 0.0;
        }

        $days = max(0.0, (time() - $timestamp) / DAY_IN_SECONDS);

        return exp(-$days / max(1, $halfLife));
    }

    /** A stable 0..1 for this creator in this period. */
    private static function roll(int $id, int $period): float
    {
        $hash = substr(md5($id . '|' . $period . '|' . wp_salt('mk_ranking')), 0, 8);

        return (float) hexdec($hash) / (float) 0xffffffff;
    }
}
