<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * Trims Dokan's product form to the fields this marketplace uses.
 *
 * Fields a creator does not need are not harmless: every one is a question a
 * first-time seller stops at, and on a phone every one is another screen of
 * scrolling between the photos and the 出品する button.
 */
final class ListingForm
{
    /**
     * Dokan template parts that are not rendered.
     *
     * products/product-brand -- WooCommerce product brands. The client does
     * not use brands (confirmed 2026-09-11). Dokan adds the field to the new
     * product page, the edit page and the quick-add popup through this one
     * template, so suppressing the template removes it everywhere at once,
     * including from any future Dokan screen that reuses it.
     *
     * Saving needs no change: Dokan only writes brands when the form submits
     * some, and with no field it never does.
     */
    private const HIDDEN_PARTS = [
        'products/product-brand',

        /*
         * products/download-virtual -- the ダウンロード商品 and 配送なし
         * checkboxes.
         *
         * WooCommerce's switches for digital goods and for services. Every
         * listing on this marketplace is a physical second-hand object that
         * gets posted, so neither has a meaning here, and a seller who ticks
         * 配送なし produces a listing with no address, no dispatch deadline
         * and nothing to receive -- the two features the whole transaction is
         * built around silently do not apply.
         *
         * Hidden here and forced off on save in Product\FormGuide, because a
         * field that is merely absent from a form is still a value a REST
         * call can set.
         */
        'products/download-virtual',
    ];

    public static function register(): void
    {
        add_filter('dokan_get_template_part', [self::class, 'hideParts'], 10, 3);
    }

    /**
     * @param string|false $template resolved template path
     * @return string|false
     */
    public static function hideParts($template, string $slug, string $name = '')
    {
        // Dokan includes the template only if this is truthy.
        return in_array($slug, self::HIDDEN_PARTS, true) ? false : $template;
    }
}
