<?php
declare(strict_types=1);

namespace MK\Product;

use WC_Product;

/**
 * Link previews: what LINE, X, Facebook and the like show when a URL is pasted.
 *
 * The share buttons (ShareLink) are only half of sharing. Without Open Graph
 * tags a pasted link is a bare URL; with them it is a card with the item's
 * photo, name and price, which is what makes anyone tap it (2026-09-20).
 *
 * A listing gets its own card. Every other page gets a plain one -- the site
 * name, the page title, the site icon -- so a shop or the home page shared
 * from the same buttons does not come out blank.
 *
 * Crawlers follow the /item/{id}/ redirect, so the short link previews the
 * same as the full one. Before launch they see the coming-soon page instead,
 * which carries none of this; previews start working when the site opens.
 *
 * Stays out of the way of an SEO plugin if one is ever installed: two sets of
 * og: tags make every platform pick one at random.
 */
final class OpenGraph
{
    private const MAX_DESCRIPTION = 110;

    /** Attachment meta caching the JPEG copy made for previews. */
    public const META_JPEG = '_mk_og_jpeg';

    public static function register(): void
    {
        add_action('wp_head', [self::class, 'render'], 5);
    }

    public static function render(): void
    {
        if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION')) {
            return;
        }

        $tags = is_singular('product') ? self::forProduct((int) get_queried_object_id()) : self::forPage();

        foreach ($tags as $property => $content) {
            if ($content === '') {
                continue;
            }

            $attr = str_starts_with($property, 'twitter:') ? 'name' : 'property';

            printf('<meta %s="%s" content="%s">' . "\n", $attr, esc_attr($property), esc_attr($content));
        }
    }

    /** @return array<string, string> */
    public static function forProduct(int $productId): array
    {
        $product = wc_get_product($productId);

        if (!$product instanceof WC_Product) {
            return self::forPage();
        }

        $price = (int) $product->get_price();
        $store = self::storeName((int) get_post_field('post_author', $productId));

        $description = self::summarise((string) $product->get_short_description())
            ?: self::summarise((string) $product->get_description());

        if ($description === '') {
            $description = trim(implode('｜', array_filter([
                $price > 0 ? '¥' . number_format($price) : '',
                $store,
            ])));
        }

        [$image, $width, $height] = self::productImage($product);

        return [
            'og:site_name'           => get_bloginfo('name'),
            'og:type'                => 'product',
            'og:title'               => $product->get_name(),
            'og:description'         => $description,
            'og:url'                 => (string) get_permalink($productId),
            'og:image'               => $image,
            'og:image:width'         => $width > 0 ? (string) $width : '',
            'og:image:height'        => $height > 0 ? (string) $height : '',
            'og:image:alt'           => $image !== '' ? $product->get_name() : '',
            'og:locale'              => 'ja_JP',
            'product:price:amount'   => $price > 0 ? (string) $price : '',
            'product:price:currency' => $price > 0 ? 'JPY' : '',
            'twitter:card'           => $image !== '' ? 'summary_large_image' : 'summary',
            'twitter:title'          => $product->get_name(),
            'twitter:description'    => $description,
            'twitter:image'          => $image,
        ];
    }

    /** @return array<string, string> */
    public static function forPage(): array
    {
        $icon  = (string) get_site_icon_url(512);
        $title = html_entity_decode(wp_get_document_title(), ENT_QUOTES, 'UTF-8');
        $url   = is_front_page() ? home_url('/') : (is_singular() ? (string) get_permalink() : '');

        return [
            'og:site_name'        => get_bloginfo('name'),
            'og:type'             => 'website',
            'og:title'            => $title,
            'og:description'      => get_bloginfo('description'),
            'og:url'              => $url,
            'og:image'            => $icon,
            'og:locale'           => 'ja_JP',
            'twitter:card'        => 'summary',
            'twitter:title'       => $title,
            'twitter:description' => get_bloginfo('description'),
            'twitter:image'       => $icon,
        ];
    }

    /** @return array{0:string, 1:int, 2:int} url, width, height; the site icon when the listing has no photo */
    private static function productImage(WC_Product $product): array
    {
        $ids = array_filter(array_merge([(int) $product->get_image_id()], array_map('intval', $product->get_gallery_image_ids())));

        foreach ($ids as $id) {
            $jpeg = self::jpegCopy($id);

            if ($jpeg !== null) {
                return $jpeg;
            }

            $src = wp_get_attachment_image_src($id, 'large');

            if (is_array($src) && !empty($src[0])) {
                return [(string) $src[0], (int) $src[1], (int) $src[2]];
            }
        }

        $icon = (string) get_site_icon_url(512);

        return [$icon, $icon !== '' ? 512 : 0, $icon !== '' ? 512 : 0];
    }

    /**
     * A JPEG stand-in for a WebP or HEIC photo.
     *
     * Phones upload WebP more and more, and not every app that draws a link
     * card can read it -- LINE in particular has never documented support. A
     * JPEG always works, so one is made the first time the listing is crawled
     * and reused after that. JPEG and PNG photos are left as they are.
     *
     * @return array{0:string, 1:int, 2:int}|null
     */
    public static function jpegCopy(int $attachmentId): ?array
    {
        $mime = (string) get_post_mime_type($attachmentId);

        if (!in_array($mime, ['image/webp', 'image/heic', 'image/heif', 'image/avif'], true)) {
            return null;
        }

        $uploads = wp_get_upload_dir();
        $cached  = get_post_meta($attachmentId, self::META_JPEG, true);

        if (is_array($cached) && !empty($cached['file']) && is_readable($uploads['basedir'] . '/' . $cached['file'])) {
            return [$uploads['baseurl'] . '/' . $cached['file'], (int) $cached['width'], (int) $cached['height']];
        }

        $source = (string) get_attached_file($attachmentId);

        if ($source === '' || !is_readable($source)) {
            return null;
        }

        $editor = wp_get_image_editor($source);

        if (is_wp_error($editor)) {
            return null;
        }

        $editor->resize(1200, 1200, false);
        $editor->set_quality(85);

        $target = preg_replace('/\.[^.\/]+$/', '', $source) . '-og.jpg';
        $saved  = $editor->save($target, 'image/jpeg');

        if (is_wp_error($saved) || empty($saved['path'])) {
            return null;
        }

        $relative = ltrim(str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path((string) $saved['path'])), '/');

        update_post_meta($attachmentId, self::META_JPEG, [
            'file'   => $relative,
            'width'  => (int) $saved['width'],
            'height' => (int) $saved['height'],
        ]);

        return [$uploads['baseurl'] . '/' . $relative, (int) $saved['width'], (int) $saved['height']];
    }

    private static function storeName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $info = function_exists('dokan_get_store_info') ? dokan_get_store_info($userId) : [];

        return is_array($info) && !empty($info['store_name']) ? (string) $info['store_name'] : '';
    }

    private static function summarise(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($html))));

        return mb_strlen($text) > self::MAX_DESCRIPTION ? mb_substr($text, 0, self::MAX_DESCRIPTION) . '…' : $text;
    }
}
