<?php
declare(strict_types=1);

namespace MK\Product;

use MK\Order\Statuses;

/**
 * Only a buyer who has received the item may review it.
 *
 * The terms (第15条) say reviews come from buyers, after delivery. WooCommerce
 * was installed with reviews open to anyone -- no login, no purchase -- which
 * on a public marketplace is an invitation to spam and to fake ratings.
 *
 * WooCommerce already has the machinery: with "verified owners only" on, it
 * hides the form from everyone else and refuses the submission server-side
 * (pre_comment_on_post), both through wc_customer_bought_product(). What it
 * cannot know is what "bought" means here. Its answer is an order in a paid
 * status -- processing or completed -- and this marketplace never uses
 * processing and reaches completed only after the payout. So the question is
 * answered here instead: an order for this product that has reached
 * 受取確認 (or completed after it). A buyer whose parcel is still in transit,
 * or whose order was cancelled, cannot review yet.
 *
 * The setting is forced in code rather than left in the database, so the rule
 * cannot be switched off by someone tidying the WooCommerce settings screen.
 */
final class Reviews
{
    private const WC_REFUSAL = 'Only logged in customers who have purchased this product may leave a review.';

    public static function register(): void
    {
        add_filter('pre_option_woocommerce_review_rating_verification_required', [self::class, 'required']);
        add_filter('woocommerce_pre_customer_bought_product', [self::class, 'bought'], 10, 4);
        add_filter('gettext_woocommerce', [self::class, 'text'], 10, 3);
        add_filter('get_post_status', [self::class, 'soldIsReviewable'], 10, 2);
    }

    /**
     * Let a sold listing take the review of the buyer who bought it.
     *
     * A one-off item is marked mk-sold once bought, and that status is
     * registered non-public so the item drops out of the shop and search.
     * WordPress refuses a comment on any non-public post
     * (wp_handle_comment_submission, 'comment_on_draft') before WooCommerce's
     * verified-owner check ever runs -- so exactly the people allowed to review
     * a one-off item, the ones who received it, could not.
     *
     * Only while wp-comments-post.php is handling a comment on that very
     * product does the status read as publish. The verified-owner check still
     * runs straight after, so nobody else gets in, and nothing about how the
     * listing is queried or shown changes.
     *
     * @param mixed $status
     * @param mixed $post
     * @return mixed
     */
    public static function soldIsReviewable($status, $post = null)
    {
        if ($status !== \MK\Product\Statuses::SOLD
            || !$post instanceof \WP_Post
            || $post->post_type !== 'product'
            || basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'wp-comments-post.php'
            || (int) ($_POST['comment_post_ID'] ?? 0) !== $post->ID
        ) {
            return $status;
        }

        return 'publish';
    }

    public static function required(): string
    {
        return 'yes';
    }

    /**
     * @param mixed      $result
     * @param string     $email
     * @param int|string $userId
     * @param int|string $productId
     */
    public static function bought($result, $email = '', $userId = 0, $productId = 0): bool
    {
        return self::canReview((int) $userId, (int) $productId);
    }

    public static function canReview(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        $orders = wc_get_orders([
            'customer_id' => $userId,
            'status'      => [Statuses::RECEIVED, 'completed'],
            'limit'       => -1,
        ]);

        foreach ($orders as $order) {
            if ((int) $order->get_meta('_mk_product_id') === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $translation
     * @param string $text
     * @param string $domain
     */
    public static function text($translation, $text = '', $domain = ''): string
    {
        return $text === self::WC_REFUSAL
            ? 'この商品を購入し、受取が完了した方のみレビューを投稿できます。'
            : (string) $translation;
    }
}
