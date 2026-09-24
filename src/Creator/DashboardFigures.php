<?php
declare(strict_types=1);

namespace MK\Creator;

/**
 * Only true money figures on the creator dashboard.
 *
 * Dokan's analytics block adds four cards to WooCommerce's sales summary --
 * Total Earning, Marketplace Commission, Marketplace Discount, Store Discount
 * -- read from Dokan's commission tables, which this project never writes to:
 * the platform fee is taken through Stripe (see the plugin header). So a
 * creator who sold a ¥3,000 item was told they had earned ¥3,000 with a ¥0
 * commission, when ¥2,580 is what reaches them. In English that went unread;
 * translated into Japanese for the client (2026-09-21) it would have become a
 * plain misstatement, so the cards go instead.
 *
 * The "Balance" beside the heading is Dokan's withdrawal balance -- the
 * withdrawal system is switched off (see Onboarding::addNavItem) and it can
 * only ever say ¥0 -- and is hidden in the theme by its class.
 *
 * What stays is what WooCommerce counts from the orders themselves: sales,
 * order and item counts, the charts. The creator's actual takings are the
 * 売上状況 panel, now at the top of the page (Onboarding).
 *
 * The operator keeps Dokan's cards in wp-admin, wrong figures and all, as a
 * sign that nothing there should be relied on either.
 */
final class DashboardFigures
{
    /** Dokan's schema properties and the indicator slugs that point at them. */
    private const DOKAN_FIGURES = [
        'total_vendor_earning',
        'total_admin_discount',
        'total_vendor_discount',
        'total_admin_commission',
    ];

    public static function register(): void
    {
        // After Dokan's own filters (priority 10), which add these.
        add_filter('woocommerce_rest_report_revenue_stats_schema', [self::class, 'schema'], 20, 1);
        add_filter('woocommerce_rest_report_sort_performance_indicators', [self::class, 'indicators'], 20, 1);

        // The 受取額 column in the creator's own product list, which Dokan
        // works out from its commission tables the same way: a ¥2,000 listing
        // promised ¥2,000 where ¥1,720 is what a sale would pay (2026-09-25).
        add_filter('dokan_get_earning_by_product', [self::class, 'productEarning'], 20, 3);
    }

    /**
     * What a sale of this listing would pay its creator, at today's rates.
     *
     * An estimate, and only an estimate: the rates that count are the ones
     * frozen onto an order at checkout (Fee\Breakdown). This is the same
     * arithmetic the listing form shows under the price field, so the two
     * screens agree.
     *
     * @param mixed $earning
     * @param mixed $product
     * @param string $context
     * @return mixed
     */
    public static function productEarning($earning, $product = null, $context = 'seller')
    {
        if ($context !== 'seller') {
            return $earning;
        }

        $product = $product instanceof \WC_Product ? $product : wc_get_product($product);

        if (!$product instanceof \WC_Product) {
            return $earning;
        }

        $price = (int) round((float) $product->get_price());

        if ($price <= 0) {
            return $earning;
        }

        return (float) (new \MK\Fee\Calculator(
            (float) \MK\Fee\Settings::productRate(),
            (float) \MK\Fee\Settings::optionRate()
        ))->calculate($price, 0)->creatorAmount;
    }

    /**
     * @param mixed $schema
     * @return mixed
     */
    public static function schema($schema)
    {
        if (!is_array($schema) || !self::forCreator() || !isset($schema['totals']['properties'])) {
            return $schema;
        }

        foreach (self::DOKAN_FIGURES as $property) {
            unset($schema['totals']['properties'][$property]);
        }

        return $schema;
    }

    /**
     * @param mixed $indicators
     * @return mixed
     */
    public static function indicators($indicators)
    {
        if (!is_array($indicators) || !self::forCreator()) {
            return $indicators;
        }

        $drop = ['revenue/total_seller_earning'];

        foreach (self::DOKAN_FIGURES as $property) {
            $drop[] = 'revenue/' . $property;
        }

        return array_values(array_filter(
            $indicators,
            static fn ($slug): bool => !in_array($slug, $drop, true)
        ));
    }

    private static function forCreator(): bool
    {
        return is_user_logged_in() && !current_user_can('manage_woocommerce');
    }
}
