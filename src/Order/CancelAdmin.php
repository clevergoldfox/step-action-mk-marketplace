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
 * The refund itself is deliberately blunt: one action, a confirmation, and a
 * note on the order saying who did it and how much went back. It is the full
 * amount, except where the buyer is found responsible and bears the costs the
 * platform cannot recover. An arbitrary partial refund is a different
 * conversation and belongs in a different control.
 *
 * Who pays for it follows the client's three-way rule (2026-09-17):
 *
 *   ① creator's responsibility   full refund; the creator bears the costs
 *   ② buyer's responsibility     refund less the costs the platform cannot
 *                                recover; the buyer bears them
 *   ③ neither clearly            the operator finds who bears them
 *
 * Where the grounds themselves are on the creator's side -- a missed deadline,
 * a listing that does not match the item, a creator declining a video for
 * their own reasons -- ① is not a choice: the form offers only it, and
 * costBearer() enforces it on submit. Everything else is the operator's
 * finding.
 *
 * An inappropriate request is ② as a rule, and ③ where the operator finds the
 * buyer's responsibility hard to establish (2026-09-18). So a creator who
 * declines a video as an inappropriate request gets ② suggested, not imposed:
 * that is the creator's claim about the buyer, and the operator is the one who
 * weighs it.
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

        $total   = (int) $order->get_total();
        $paidOut = $order->get_meta(TransferService::META_TRANSFER_ID) !== '';
        $service = new ReportService();
        $open    = $service->openFor(ReportService::TARGET_ORDER, $order->get_id());

        if ($order->get_meta(DispatchDeadline::META_REQUESTED) !== '') {
            echo '<p><strong>購入者からキャンセル申請が出ています。</strong></p>';
        }

        foreach ($open as $report) {
            printf(
                '<p>申し出の理由：<strong>%s</strong></p>',
                esc_html(ReportService::reasonLabel((string) $report->reason))
            );
        }

        printf(
            '<p>ご購入代金：<strong>%s</strong><br><small>購入者の責任とする場合のみ、返還されない決済手数料を差し引いて返金します。</small></p>',
            esc_html(Money::format($total))
        );

        if ($paidOut) {
            echo '<p style="color:#b32d2e"><strong>この取引はすでに出品者へ送金済みです。</strong>'
                . '返金と同時に出品者から資金を引き戻します。出品者の残高が不足している場合は、'
                . 'その分が未回収として記録されます。</p>';
        } else {
            echo '<p>売上はまだ出品者へ送金されていないため、送金の引き戻しは発生しません。</p>';
        }

        if ($open !== []) {
            echo '<p>この取引の未対応の申し出も、あわせて解決済みにします。</p>';
        }

        $creatorSide = self::creatorSideReasons($order);

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_cancel_order">';
        printf('<input type="hidden" name="order_id" value="%d">', $order->get_id());
        echo '<p><input type="text" name="reason" style="width:100%" placeholder="理由（注文メモに記録されます）"></p>';

        $confirm = '\'この取引をキャンセルし、購入者へ全額返金します。元に戻せません。よろしいですか？\'';

        if ($creatorSide !== []) {
            // Not offered as a choice. The client's rule is that a refund caused
            // on the creator's side is the creator's cost; costBearer() enforces
            // the same on submit, so this form is not what makes it true.
            printf(
                '<p style="border-top:1px solid #dcdcde;padding-top:8px">'
                . '<strong>返金にかかる費用はクリエイター負担です</strong><br>'
                . '理由：%s<br>'
                . 'クリエイター側の事情による返金のため、決済手数料等はクリエイターの未回収額として計上し、'
                . '次回以降の売上から差し引きます。</p>',
                esc_html(implode('／', $creatorSide))
            );

            printf(
                '<p><button type="submit" name="charge" value="creator" class="button button-primary" style="width:100%%" '
                . 'onclick="return confirm(%s);">キャンセルして全額返金する</button></p>',
                $confirm
            );

            echo '</form>';

            return;
        }

        $recommended = self::recommendedBearer($order);

        echo '<p style="border-top:1px solid #dcdcde;padding-top:8px">'
            . '<strong>返金にかかる費用の負担</strong><br>'
            . '内容を確認のうえ、責任の所在に応じて選んでください。</p>'
            . '<ul style="margin:0 0 8px 1.2em;list-style:disc">'
            . '<li>クリエイター側の都合・原因 → ①</li>'
            . '<li>不適切な依頼 → 原則②（購入者の責任と判断しにくい場合は③）</li>'
            . '</ul>';

        if (\MK\Product\MessageVideo::isMessageVideoOrder($order) && VideoDelivery::isDeclined($order)) {
            $kinds = VideoDelivery::declineKinds();
            $kind  = (string) $order->get_meta(VideoDelivery::META_DECLINE_KIND);

            printf(
                '<p>クリエイターの申告：<strong>%s</strong><br>理由：%s</p>'
                . '<p class="description">辞退を認めず撮影を続けてもらう場合は、返金せずに'
                . '「通報の管理」から「解決（送金を再開）」を選んでください。</p>',
                esc_html($kinds[$kind] ?? $kinds['other']),
                esc_html((string) $order->get_meta(VideoDelivery::META_DECLINE_REASON))
            );
        }

        $primary = static fn (string $bearer): string => $bearer === $recommended ? 'button-primary' : '';

        printf(
            '<p><button type="submit" name="charge" value="creator" class="button %s" style="width:100%%" '
            . 'onclick="return confirm(%s);">①クリエイターの責任<br><small>全額返金／費用はクリエイター負担</small></button></p>',
            $primary('creator'),
            $confirm
        );

        if ($paidOut) {
            echo '<p class="description">②購入者の責任：出品者へ送金済みのため、購入者負担での返金は選べません。</p>';
        } else {
            $fee = self::safeFee($order);

            $detail = $fee === null
                ? '返還されない決済手数料を差し引いて返金'
                : sprintf('%s を返金（決済手数料 %s を差し引き）', Money::format($total - $fee), Money::format($fee));

            printf(
                '<p><button type="submit" name="charge" value="buyer" class="button %s" style="width:100%%" '
                . 'onclick="return confirm(%s);">②購入者の責任<br><small>%s</small></button></p>',
                $primary('buyer'),
                '\'購入者の責任として、返還されない決済手数料を差し引いて返金します。元に戻せません。よろしいですか？\'',
                esc_html($detail)
            );
        }

        printf(
            '<p><button type="submit" name="charge" value="platform" class="button %s" style="width:100%%" '
            . 'onclick="return confirm(%s);">③責任が明確でない<br><small>全額返金／費用は運営負担</small></button></p>',
            $primary('platform'),
            $confirm
        );

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

        // Decided by the grounds whenever they are on the creator's side, and by
        // the operator's finding otherwise. Read before the refund runs:
        // resolving the reports is part of the refund, and must not change who
        // pays for it.
        $bearer   = self::costBearer($order, sanitize_key(wp_unslash((string) ($_POST['charge'] ?? ''))));
        $refunded = (int) $order->get_total();

        try {
            if ($bearer === 'buyer') {
                $refunded = (int) (new TransferService())->refundWithBuyerCost($order)['refunded'];
            } else {
                (new TransferService())->refundAndReverse($order, null, 0, $bearer === 'creator');
            }
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

        $order = wc_get_order($orderId);   // reload: the refund saved it
        $label = self::bearerLabel($bearer);

        $order->update_meta_data(TransferService::META_COST_BEARER, $bearer);
        $order->update_meta_data(TransferService::META_REFUND_AMOUNT, $refunded);

        $order->add_order_note(sprintf(
            '運営が取引をキャンセルし、%sを返金しました（操作者：%s／費用負担：%s）。%s',
            Money::format($refunded),
            $admin->display_name,
            $label,
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
                sprintf(
                    'キャンセル・返金対応済み（費用負担：%s）%s',
                    $label,
                    $reason !== '' ? '：' . $reason : ''
                ),
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

    /**
     * Who bears the costs of refunding this order: 'creator', 'buyer' or
     * 'platform'.
     *
     * Where the grounds are on the creator's side the creator pays, and what
     * was posted is ignored. Otherwise it is the operator's finding, and an
     * unanswered or unrecognised form means the platform: better to absorb a
     * cost than to bill a creator, or short a buyer, for one nobody attributed
     * to them.
     *
     * 'buyer' is returned as posted even after payout, where it cannot be
     * carried out. The refund then fails loudly and moves no money, which is
     * the right outcome for a choice the operator made on out-of-date facts.
     */
    public static function costBearer(WC_Order $order, string $posted): string
    {
        if (self::creatorSideReasons($order) !== []) {
            return 'creator';
        }

        return in_array($posted, ['creator', 'buyer'], true) ? $posted : 'platform';
    }

    /** Japanese for who bore the costs, for notes and report records. */
    public static function bearerLabel(string $bearer): string
    {
        return ['creator' => 'クリエイター', 'buyer' => '購入者', 'platform' => '運営'][$bearer] ?? '運営';
    }

    /**
     * Which outcome the form offers first.
     *
     * A declined message video carries the creator's own account of why. It
     * is a claim, not a finding -- the operator still decides -- but it is the
     * best starting point there is.
     */
    public static function recommendedBearer(WC_Order $order): string
    {
        if (\MK\Product\MessageVideo::isMessageVideoOrder($order) && VideoDelivery::isDeclined($order)) {
            $paidOut = $order->get_meta(TransferService::META_TRANSFER_ID) !== '';

            return match ((string) $order->get_meta(VideoDelivery::META_DECLINE_KIND)) {
                // ② cannot be carried out once the creator has been paid; ③ is
                // what remains for a buyer-side case.
                'buyer_request' => $paidOut ? 'platform' : 'buyer',
                'creator'       => 'creator',
                default         => 'platform',
            };
        }

        return 'platform';
    }

    /** The fee Stripe kept, or null if it cannot be read right now. */
    private static function safeFee(WC_Order $order): ?int
    {
        try {
            return (new TransferService())->stripeFeeFor($order);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Labels of the creator-side grounds on this order.
     *
     * Every report counts, resolved or not: an operator who closed the report
     * before pressing refund has not changed why the refund is happening. A
     * missed dispatch deadline counts on its own, whether or not the buyer
     * asked to cancel.
     *
     * @return string[]
     */
    private static function creatorSideReasons(WC_Order $order): array
    {
        $labels = [];

        foreach ((new ReportService())->allFor(ReportService::TARGET_ORDER, $order->get_id()) as $report) {
            if (ReportService::isSellerFault((string) $report->reason)) {
                $labels[] = ReportService::reasonLabel((string) $report->reason);
            }
        }

        if ((string) $order->get_meta(DispatchDeadline::META_OVERDUE_AT) !== '') {
            $labels[] = DispatchDeadline::verb($order) . '期限の超過';
        }

        // The creator's own word that the decline was their convenience.
        // Unlike a claim about the buyer, there is nothing for the operator to weigh.
        if (\MK\Product\MessageVideo::isMessageVideoOrder($order)
            && VideoDelivery::isDeclined($order)
            && (string) $order->get_meta(VideoDelivery::META_DECLINE_KIND) === 'creator'
        ) {
            $labels[] = 'クリエイターの都合による辞退';
        }

        return array_values(array_unique($labels));
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
