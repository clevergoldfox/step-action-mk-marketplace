<?php
declare(strict_types=1);

namespace MK\Ad;

use WP_Post;
use WP_Query;

/**
 * Banners and pop-ups the operator manages themselves.
 *
 * The client intends to sell advertising space (2026-09-29) and asked for a
 * screen where a banner is an image, a link and a publish button -- no
 * developer between them and a campaign going live.
 *
 * So an ad is a post type. The media library, drafts, scheduling, ordering
 * and the trash all come from WordPress; what is added is the three fields
 * that make an ad an ad: where it goes, who sees it, and where it leads.
 *
 * Audience is a preference, not a fact about the visitor: the site never asks
 * anyone's gender. A visitor taps メンズ or レディース to filter what they are
 * browsing, and the ads follow that choice. An ad marked 全員 shows to
 * everyone whatever they tapped, which is what an advertiser buying reach
 * expects.
 */
final class Service
{
    public const POST_TYPE = 'mk_ad';

    public const META_URL       = '_mk_ad_url';
    public const META_AUDIENCE  = '_mk_ad_audience';
    public const META_PLACEMENT = '_mk_ad_placement';
    public const META_STARTS    = '_mk_ad_starts';
    public const META_ENDS      = '_mk_ad_ends';

    public const PLACEMENT_BANNER    = 'banner';
    public const PLACEMENT_POPUP     = 'popup';
    public const PLACEMENT_DASHBOARD = 'dashboard';

    public const AUDIENCE_ALL   = 'all';
    public const AUDIENCE_MEN   = 'men';
    public const AUDIENCE_WOMEN = 'women';

    /** @return array<string, string> */
    public static function placements(): array
    {
        return [
            self::PLACEMENT_BANNER    => 'ホームのバナー',
            self::PLACEMENT_POPUP     => 'ポップアップ（初回表示）',
            self::PLACEMENT_DASHBOARD => 'クリエイター管理ページ',
        ];
    }

    /** @return array<string, string> */
    public static function audiences(): array
    {
        return [
            self::AUDIENCE_ALL   => '全員に表示',
            self::AUDIENCE_MEN   => 'メンズを選んだ人',
            self::AUDIENCE_WOMEN => 'レディースを選んだ人',
        ];
    }

    public static function audienceLabel(string $audience): string
    {
        return self::audiences()[$audience] ?? self::audiences()[self::AUDIENCE_ALL];
    }

    /**
     * Published ads for one slot and one visitor's choice, in order.
     *
     * @return array<int, array<string, string>> image, url, alt, id
     */
    public static function active(string $placement, string $audience = self::AUDIENCE_ALL): array
    {
        $audience = self::normaliseAudience($audience);

        $query = new WP_Query([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => self::META_PLACEMENT,
                    'value' => $placement,
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'   => self::META_AUDIENCE,
                        'value' => self::AUDIENCE_ALL,
                    ],
                    [
                        'key'   => self::META_AUDIENCE,
                        'value' => $audience,
                    ],
                ],
            ],
        ]);

        $out = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof WP_Post || !self::isRunning($post->ID)) {
                continue;
            }

            $image = get_the_post_thumbnail_url($post->ID, 'large');

            if (!$image) {
                continue;   // an ad without a picture is an empty box
            }

            $out[] = [
                'id'    => (string) $post->ID,
                'image' => (string) $image,
                'url'   => (string) get_post_meta($post->ID, self::META_URL, true),
                'alt'   => $post->post_title,
            ];
        }

        return $out;
    }

    /** Within its dates, if it has any. */
    public static function isRunning(int $adId, ?int $now = null): bool
    {
        $now    = $now ?? (int) current_time('timestamp');
        $starts = (string) get_post_meta($adId, self::META_STARTS, true);
        $ends   = (string) get_post_meta($adId, self::META_ENDS, true);

        if ($starts !== '' && $now < (int) strtotime($starts . ' 00:00:00')) {
            return false;
        }

        // Inclusive: an ad ending on the 31st runs through the 31st.
        if ($ends !== '' && $now > (int) strtotime($ends . ' 23:59:59')) {
            return false;
        }

        return true;
    }

    /**
     * What the visitor is browsing: their tap, remembered for the session.
     *
     * A query argument wins over the cookie so a link can carry the choice;
     * anything unrecognised means "show me everything".
     */
    public static function currentAudience(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a display preference, not an action.
        if (isset($_GET['tb_aud'])) {
            return self::normaliseAudience(sanitize_key(wp_unslash((string) $_GET['tb_aud'])));
        }

        if (isset($_COOKIE['tb_aud'])) {
            return self::normaliseAudience(sanitize_key(wp_unslash((string) $_COOKIE['tb_aud'])));
        }
        // phpcs:enable

        return self::AUDIENCE_ALL;
    }

    public static function normaliseAudience(string $audience): string
    {
        return isset(self::audiences()[$audience]) ? $audience : self::AUDIENCE_ALL;
    }
}
