<?php
declare(strict_types=1);

namespace MK\Product;

use MK\Support\Money;

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
 * Which hook, and why it took two goes to get right
 * ---------------------------------------------------------------------------
 * PublishGate gates in wp_insert_post_data because whether its creator can be
 * paid is known before the post row is written. A price is not knowable that
 * early: it is post meta, written after the row.
 *
 * save_post_product is not late enough either, and that is not obvious. Dokan
 * saves through WC_Product::save(), which writes the post row -- firing
 * save_post_product -- and only then hands the object to WooCommerce's data
 * store, which writes _price and _regular_price. A gate on save_post_product
 * therefore reads the price from BEFORE this save: empty for a new listing.
 * The client duly listed an item with a price of ¥3,000, watched it go to
 * draft, opened it, and found the price sitting there exactly as they had
 * entered it.
 *
 * woocommerce_new_product and woocommerce_update_product fire at the end of
 * that data store write, when the price on disk is the one just submitted.
 * They are also the single point the vendor form, the REST route, quick-edit
 * and the admin screen all pass through, which is what save_post_product was
 * chosen for in the first place.
 */
final class PriceGate
{
    /** Marks a listing held back because it has no usable price. */
    public const META_HELD = '_mk_held_no_price';

    /** Guards against the recursion our own wp_update_post would cause. */
    private static bool $working = false;

    public static function register(): void
    {
        add_action('woocommerce_new_product', [self::class, 'enforce'], 99, 1);
        add_action('woocommerce_update_product', [self::class, 'enforce'], 99, 1);

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

    /** @param int $postId */
    public static function enforce($postId): void
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
            // Underlined and labelled as an action. Rendered as plain text
            // these read as a list of names, and the client could not tell
            // they were the way to fix the problem the notice describes.
            $items[] = sprintf(
                '<li><a class="mk-held__link" href="%s">%s<span class="mk-held__action">編集する</span></a></li>',
                esc_url(dokan_edit_product_url((int) $productId)),
                esc_html(get_the_title((int) $productId))
            );
        }

        printf(
            '<div class="dokan-alert dokan-alert-warning mk-held">'
            . '<strong>販売価格が未入力のため、公開できなかった商品があります。</strong><br>'
            . '下記の商品名を押すと編集画面が開きます。価格を入力して保存すると、'
            . '通常どおり公開の手続きに進みます。'
            . '<ul class="mk-held__list">%s</ul></div>',
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
