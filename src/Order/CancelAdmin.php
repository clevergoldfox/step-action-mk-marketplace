<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Report\Service as ReportService;
use MK\Stripe\TransferService;
use MK\Support\Money;
use WC_Order;

/**
 * The operator's cancel-and-refund button.
 *
 * TransferService::refundAndReverse() has always been the correct way to
 * unwind a sale -- it pulls the transfer back before refunding, so a failure
 * mid-way never leaves the platform paying both halves -- but nothing on any
 * screen called it. The only unwind available to the operator was
 * WooCommerce's own refund box, which knows nothing about the Connect
 * transfer and would have refunded the buyer while leaving the creator paid.
 *
 * That gap did not matter much while there was no way for a buyer to ask for
 * a cancellation. Now that a missed dispatch deadline produces exactly such a
 * request, the flow the client described ends here, and it needs a button.
 *
 * Everything about the button is deliberately blunt: full amount, one action,
 * a confirmation, and a note on the order saying who did it. A partial refund
 * is a different conversation and belongs in a different control.
 */
final class CancelAdmin
{
    private const NONCE = 'mk_cancel_order';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addBox']);
        add_action('admin_post_mk_cancel_order', [self::class, 'handle']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    public static function addBox(): void
    {
        // Both spellings: HPOS puts orders on their own screen, and the site
        // may still be on post-table storage.
        $screens = ['shop_order'];

        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique(array_filter($screens)) as $screen) {
            add_meta_box(
                'mk-cancel-order',
                'キャンセル・返金（運営操作）',
                [self::class, 'renderBox'],
                $screen,
                'side',
                'low'
            );
        }
    }

    /** @param mixed $post WP_Post on the legacy screen, WC_Order under HPOS */
    public static function renderBox($post): void
    {
        $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID ?? 0);

        if (!$order instanceof WC_Order) {
            return;
        }

        $status = $order->get_status();

        if (in_array($status, ['cancelled', 'refunded'], true)) {
            printf(
                '<p>この取引は %s です。</p>',
                esc_html($status === 'cancelled' ? 'キャンセル済み' : '返金済み')
            );

            return;
        }

        // Nothing has been captured yet, so there is nothing to give back.
        if (!in_array($status, [Statuses::PAID, Statuses::SHIPPED, Statuses::RECEIVED, 'completed'], true)) {
            echo '<p>この取引はまだ決済が完了していないため、返金操作はできません。</p>';

            return;
        }

        $total    = (int) $order->get_total();
        $paidOut  = $order->get_meta(TransferService::META_TRANSFER_ID) !== '';
        $reported = (new ReportService())->openCountFor(ReportService::TARGET_ORDER, $order->get_id());

        if ($order->get_meta(DispatchDeadline::META_REQUESTED) !== '') {
            echo '<p><strong>購入者からキャンセル申請が出ています。</strong></p>';
        }

        printf('<p>購入者へ <strong>%s</strong> を全額返金します。</p>', esc_html(Money::format($total)));

        if ($paidOut) {
            echo '<p style="color:#b32d2e"><strong>この取引はすでに出品者へ送金済みです。</strong>'
                . '返金と同時に出品者から資金を引き戻します。出品者の残高が不足している場合は、'
                . 'その分が未回収として記録されます。</p>';
        } else {
            echo '<p>売上はまだ出品者へ送金されていないため、返金による損失は発生しません。</p>';
        }

        if ($reported > 0) {
            echo '<p>この取引の未対応の通報も、あわせて解決済みにします。</p>';
        }

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_cancel_order">';
        printf('<input type="hidden" name="order_id" value="%d">', $order->get_id());
        echo '<p><input type="text" name="reason" style="width:100%" placeholder="理由（注文メモに記録されます）"></p>';
        echo '<button type="submit" class="button button-primary" style="width:100%" '
            . 'onclick="return confirm(\'この取引をキャンセルし、購入者へ全額返金します。元に戻せません。よろしいですか？\');">'
            . 'キャンセルして全額返金する</button>';
        echo '</form>';
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order   = wc_get_order($orderId);
        $reason  = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if (!$order instanceof WC_Order) {
            wp_safe_redirect(admin_url('admin.php?page=wc-orders'));
            exit;
        }

        $paidOut = $order->get_meta(TransferService::META_TRANSFER_ID) !== '';

        try {
            (new TransferService())->refundAndReverse($order);
        } catch (\Throwable $e) {
            // Deliberately not swallowed into a note nobody reads: the money
            // did not move, and the operator must see that on the screen they
            // pressed the button on.
            error_log(sprintf('[mk-marketplace] manual refund of order %d failed: %s', $orderId, $e->getMessage()));

            wp_safe_redirect(add_query_arg(
                ['mk_cancel_failed' => 1, 'mk_cancel_msg' => rawurlencode($e->getMessage())],
                self::orderUrl($orderId)
            ));
            exit;
        }

        $admin = wp_get_current_user();

        $order = wc_get_order($orderId);   // reload: refundAndReverse saved it

        $order->add_order_note(sprintf(
            '運営が取引をキャンセルし、全額を返金しました（操作者：%s）。%s',
            $admin->display_name,
            $reason !== '' ? '理由：' . $reason : ''
        ));
        $order->save();

        // Close whatever the buyer raised, without releasing a payout: there
        // is nothing left to pay. Leaving the report open would keep the order
        // flagged for a hold that no longer means anything.
        $service = new ReportService();

        foreach ($service->openFor(ReportService::TARGET_ORDER, $orderId) as $report) {
            $service->resolve(
                (int) $report->id,
                get_current_user_id(),
                'キャンセル・返金対応済み' . ($reason !== '' ? '：' . $reason : ''),
                false
            );
        }

        // cancelled when nothing ever left the platform, refunded when a
        // transfer had to be pulled back. See Order\Statuses.
        $order->update_status(
            $paidOut ? 'refunded' : 'cancelled',
            '運営によるキャンセル・返金処理'
        );

        do_action('mk_order_cancelled_by_admin', $orderId, get_current_user_id());

        wp_safe_redirect(add_query_arg('mk_cancelled', '1', self::orderUrl($orderId)));
        exit;
    }

    public static function notices(): void
    {
        if (isset($_GET['mk_cancelled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . '取引をキャンセルし、購入者へ返金しました。</p></div>';
        }

        if (isset($_GET['mk_cancel_failed'])) {
            printf(
                '<div class="notice notice-error"><p><strong>返金に失敗しました。</strong>'
                . 'お金は動いていません。Stripe の管理画面で状況を確認してください。<br><code>%s</code></p></div>',
                esc_html(rawurldecode((string) ($_GET['mk_cancel_msg'] ?? '')))
            );
        }
    }

    private static function orderUrl(int $orderId): string
    {
        return function_exists('wc_get_page_screen_id')
            ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId)
            : admin_url('post.php?post=' . $orderId . '&action=edit');
    }
}
