<?php
declare(strict_types=1);

namespace MK\Creator;

/**
 * The クリエイター一覧 page.
 *
 * Dokan's listing shows ten creators and pages after that. The client asked
 * for a page a buyer can scroll through two hundred of (2026-09-26), so the
 * page size goes up and the card gets the creator number the rest of the site
 * identifies people by.
 *
 * The number is printed from here rather than from the theme because it is
 * the plugin that issues it; the theme only decides where it sits.
 */
final class StoreListing
{
    /** Enough that paging is rare, few enough that the page still loads. */
    public const PER_PAGE = 48;

    public static function register(): void
    {
        add_filter('dokan_store_listing_per_page', [self::class, 'perPage']);
        add_action('dokan_seller_listing_after_store_data', [self::class, 'renderNumber'], 10, 2);
    }

    /**
     * @param mixed $defaults
     * @return mixed
     */
    public static function perPage($defaults)
    {
        if (is_array($defaults)) {
            $defaults['per_page'] = self::PER_PAGE;
        }

        return $defaults;
    }

    /**
     * @param mixed $seller
     * @param mixed $storeInfo
     */
    public static function renderNumber($seller = null, $storeInfo = []): void
    {
        $userId = is_object($seller) && isset($seller->ID) ? (int) $seller->ID : (int) $seller;

        if ($userId <= 0) {
            return;
        }

        $number = (string) get_user_meta($userId, Onboarding::USER_META_NUMBER, true);

        if ($number === '') {
            return;
        }

        printf('<p class="mk-listing-number">%s</p>', esc_html($number));
    }
}
