<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * The listing approval queue.
 *
 * The client chose approval-based publishing for launch: a creator's listing
 * waits in 審査待ち until the operator publishes it. That makes the queue the
 * bottleneck between a creator listing something and anyone being able to buy
 * it, so the queue has to be impossible to overlook.
 *
 * Dokan already provides the mechanism — new vendor listings are created as
 * `pending` — but nothing tells the operator that a listing is waiting, and a
 * creator whose first item sits unapproved for three days is a creator who
 * does not list a second.
 */
final class Approval
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'pendingNotice']);
        add_action('admin_notices', [self::class, 'editScreenNotice']);
    }

    /**
     * On the product itself: this one is still waiting.
     *
     * The operator edited three listings, saved them, and reported that the
     * site did not change. It had not: all three were still 審査待ち, so
     * nothing about them was public yet -- and the edit screen says so
     * nowhere, since WordPress calls the state 「レビュー待ち」 in a dropdown
     * most people never look at.
     */
    public static function editScreenNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'product') {
            return;
        }

        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        if ($postId <= 0 || get_post_status($postId) !== 'pending') {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . '<strong>この商品はまだ公開されていません（審査待ち）。</strong><br>'
            . '編集した内容は保存されますが、公開するまで購入者には表示されません。'
            . '公開するには、右上の「公開」ボタンを押してください。</p></div>';
    }

    public static function pendingCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type = 'product' AND post_status = 'pending'"
        );
    }

    /** On every admin screen, because waiting listings are waiting creators. */
    public static function pendingNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $count = self::pendingCount();

        if ($count === 0) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // Already looking at the queue; the notice would only repeat it.
        if ($screen && $screen->id === 'edit-product' && ($_GET['post_status'] ?? '') === 'pending') {
            return;
        }

        printf(
            '<div class="notice notice-info"><p>'
            . '承認待ちの商品が <strong>%d件</strong> あります。'
            . '<a href="%s">確認して公開する</a></p></div>',
            $count,
            esc_url(admin_url('edit.php?post_type=product&post_status=pending'))
        );
    }
}
