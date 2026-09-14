<?php
declare(strict_types=1);

namespace MK\Product;

use MK\Support\Money;
use WP_Post;

/**
 * A listing with no price may not go on sale.
 *
 * Reported by the client as "the buy button does nothing". It did something:
 * the purchase was refused, because an order cannot be built for a price of
 * zero, and the buyer was sent back to the listing with an explanation that no
 * page displayed. The dead button was the symptom; a published listing with an
 * empty price was the cause.
 *
 * Both halves are fixed, and both were necessary. Checkout\Controller now
 * shows the refusal, so no future failure is invisible -- but a listing that
 * cannot be bought should never have been on sale in the first place, and a
 * buyer should not be the one who finds out.
 *
 * ---------------------------------------------------------------------------
 * Why this runs on save_post_product rather than wp_insert_post_data
 * ---------------------------------------------------------------------------
 * PublishGate gates in wp_insert_post_data because whether its creator can be
 * paid is known before the row is written. The price is not: WooCommerce and
 * Dokan write _regular_price as post meta AFTER the post row, so at filter
 * time a brand-new product has no price yet and every single new listing would
 * be demoted. save_post_product runs once the meta is in place, and it is
 * still one point that the vendor form, the REST route, quick-edit and the
 * admin screen all pass through.
 */
final class PriceGate
{
    /** Marks a listing held back because it has no usable price. */
    public const META_HELD = '_mk_held_no_price';

    /** Guards against the recursion our own wp_update_post would cause. */
    private static bool $working = false;

    public static function register(): void
    {
        add_action('save_post_product', [self::class, 'enforce'], 99, 3);

        add_action('dokan_dashboard_content_inside_before', [self::class, 'creatorNotice']);
        add_action('dokan_new_product_before_product_area', [self::class, 'creatorNotice']);
        add_action('admin_notices', [self::class, 'adminNotice']);
    }

    /** Whether this price is one an order could actually be built from. */
    public static function isSellable(int $price): bool
    {
        return $price >= Money::MIN_YEN && $price <= Money::MAX_YEN;
    }

    /**
     * The listing's price, read from meta rather than through wc_get_product().
     *
     * wc_get_product() needs WooCommerce's data stores, which are not
     * registered during plugin bootstrap -- so in the installer it returned
     * false for every product and this reported a price of zero. Run from
     * there, a migration built on it withdrew SEVEN priced listings on the
     * client's site before the one genuinely priceless listing it was meant
     * to catch. Restored by hand; the lesson is in this method.
     *
     * _price is what WooCommerce sells at (it mirrors the sale price when one
     * is running); _regular_price is the fallback for a product whose derived
     * price has not been written yet.
     */
    public static function priceOf(int $productId): int
    {
        $price = get_post_meta($productId, '_price', true);

        if ($price === '' || $price === null) {
            $price = get_post_meta($productId, '_regular_price', true);
        }

        return is_numeric($price) ? (int) $price : 0;
    }

    /**
     * Whether this listing is one of ours to gate.
     *
     * Platform-owned products are not creator listings, and one of them is
     * Dokan's internal "Reverse Withdrawal Payment" product -- priced at zero
     * by design, and part of how Dokan bills vendors. Withdrawing it breaks
     * that feature, which the first version of this gate duly did.
     */
    public static function applies(int $productId): bool
    {
        $author = (int) get_post_field('post_author', $productId);

        if ($author === 0 || user_can($author, 'manage_woocommerce')) {
            return false;
        }

        return !function_exists('dokan_is_user_seller') || dokan_is_user_seller($author);
    }

    /**
     * @param int          $postId
     * @param WP_Post|null $post
     * @param bool         $update
     */
    public static function enforce($postId, $post = null, $update = false): void
    {
        if (self::$working || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        $postId = (int) $postId;
        $status = get_post_status($postId);

        if ($status !== 'publish' && $status !== 'pending') {
            return;
        }

        if (!self::applies($postId)) {
            return;
        }

        if (self::isSellable(self::priceOf($postId))) {
            delete_post_meta($postId, self::META_HELD);

            return;
        }

        self::$working = true;

        wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
        update_post_meta($postId, self::META_HELD, 'yes');

        self::$working = false;

        error_log(sprintf(
            '[mk-marketplace] product %d held as draft: price %d is not sellable',
            $postId,
            self::priceOf($postId)
        ));
    }

    private static function heldFor(int $userId): array
    {
        if ($userId === 0) {
            return [];
        }

        return get_posts([
            'post_type'      => 'product',
            'post_status'    => 'draft',
            'author'         => $userId,
            'posts_per_page' => 20,
            'meta_key'       => self::META_HELD,
            'meta_value'     => 'yes',
            'fields'         => 'ids',
        ]);
    }

    /**
     * Tell the creator, on the dashboard they will land on after saving.
     *
     * Named, not counted. "1件の商品が公開されていません" sends someone
     * through their whole product list looking for it.
     */
    public static function creatorNotice(): void
    {
        $held = self::heldFor(get_current_user_id());

        if (!$held) {
            return;
        }

        $items = [];

        foreach ($held as $productId) {
            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url(dokan_edit_product_url((int) $productId)),
                esc_html(get_the_title((int) $productId))
            );
        }

        printf(
            '<div class="dokan-alert dokan-alert-warning">'
            . '<strong>販売価格が未入力のため、公開できなかった商品があります。</strong><br>'
            . '価格を入力して保存すると、通常どおり公開の手続きに進みます。'
            . '<ul style="margin:8px 0 0 18px">%s</ul></div>',
            implode('', $items)
        );
    }

    /** The same thing on the product screen in wp-admin. */
    public static function adminNotice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'product') {
            return;
        }

        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        if ($postId <= 0 || get_post_meta($postId, self::META_HELD, true) !== 'yes') {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>この商品には販売価格が設定されていません。</strong><br>'
            . '価格が未設定の商品は購入できないため、下書きのまま保留しています。'
            . '%d円以上の価格を入力して公開してください。</p></div>',
            Money::MIN_YEN
        );
    }
}
