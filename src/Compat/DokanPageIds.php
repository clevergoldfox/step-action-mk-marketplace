<?php
declare(strict_types=1);

namespace MK\Compat;

use WP_Post;

/**
 * Stop Dokan mistaking a category archive for the seller dashboard.
 *
 * dokan_is_seller_dashboard() compares the dashboard PAGE id against
 * $wp_query->queried_object_id without checking what kind of object was
 * queried. On a product category archive that value is the TERM id -- a
 * completely unrelated number from a different table -- so any term whose id
 * happens to equal the dashboard page id is treated as the dashboard.
 *
 * It happened here: the eight product categories were created as terms 16-23
 * and Dokan's four pages are 18-21, so four category pages (靴・帽子,
 * バッグ, ハンドメイド, コスメ) rendered the React dashboard shell instead of
 * their products -- a blank white page to a logged-out shopper, because the
 * dashboard cannot render for someone with no account.
 *
 * Dokan provides a filter on exactly that value, so this needs no change to
 * Dokan itself. Only the number that came from the query is neutralised:
 * the same filter is applied elsewhere to a specific post id, and that
 * comparison is legitimate.
 */
final class DokanPageIds
{
    public static function register(): void
    {
        add_filter('dokan_get_current_page_id', [self::class, 'ignoreNonPageQueries'], 10, 1);
    }

    /**
     * @param mixed $id the id Dokan is about to compare against a page id
     * @return mixed
     */
    public static function ignoreNonPageQueries($id)
    {
        if (is_admin() || !is_numeric($id)) {
            return $id;
        }

        // A page or post was queried: comparing page ids is meaningful.
        if (get_queried_object() instanceof WP_Post) {
            return $id;
        }

        global $wp_query;

        $queriedId = isset($wp_query->queried_object_id) ? (int) $wp_query->queried_object_id : 0;

        // Only the query's own id is suppressed. Dokan also applies this
        // filter to $post->ID, which is a real page id and must survive.
        return ($queriedId > 0 && (int) $id === $queriedId) ? 0 : $id;
    }
}
