<?php
declare(strict_types=1);

namespace MK\Order;

use WC_Order;

/**
 * 受取確認 — the buyer's confirmation, and the release of the creator's money.
 *
 * This is the single most consequential button a buyer presses. Everything
 * before it is reversible by refunding funds the platform still holds;
 * pressing it starts the clock on a transfer that afterwards can only be
 * undone by clawing money back out of the creator's balance.
 *
 * It is therefore the buyer's alone. Order\Guard enforces that a creator
 * cannot cause this transition on their own order however they reach it, and
 * this class refuses to render or accept the action for anyone but the person
 * who paid.
 *
 * The shipping panel is shown here too rather than in a separate screen. A
 * buyer deciding whether to confirm receipt wants the carrier and tracking
 * number in front of them at that moment, not one click away.
 */
final class Receipt
{
    private const NONCE = 'mk_confirm_receipt';

    public static function register(): void
    {
        add_action('woocommerce_order_details_after_order_table', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handleSubmit']);
    }

    public static function render(WC_Order $order): void
    {
        if (get_current_user_id() !== $order->get_customer_id()) {
            return;
        }

        $status = $order->get_status();

        if (!in_array($status, [Statuses::SHIPPED, Statuses::RECEIVED, 'completed'], true)) {
            return;
        }

        self::renderShippingPanel($order);

        if ($status === Statuses::SHIPPED) {
            self::renderConfirmButton($order);
        }
    }

    private static function renderShippingPanel(WC_Order $order): void
    {
        $carrier  = (string) $order->get_meta(Shipping::META_CARRIER_NAME);
        $tracking = (string) $order->get_meta(Shipping::META_TRACKING);
        $shipped  = (string) $order->get_meta(Shipping::META_SHIPPED_AT);

        echo '<section class="mk-shipping-info"><h2>配送情報</h2><table class="woocommerce-table shop_table">';

        printf(
            '<tr><th>配送会社</th><td>%s</td></tr>',
            esc_html($carrier !== '' ? $carrier : '—')
        );

        if ($order->get_meta(Shipping::META_NO_TRACKING) === 'yes') {
            // Promised to the client explicitly. A buyer who is told upfront
            // that this method has no tracking does not open a "where is my
            // order" enquiry three days later, and does not read the absence
            // of tracking as the creator having failed to post it.
            echo '<tr><th>追跡番号</th><td>'
                . '<strong>なし</strong><br>'
                . '<small>追跡番号のない発送方法のため、配送状況は確認できません。'
                . 'お手元に届くまで数日かかる場合がございます。</small>'
                . '</td></tr>';
        } else {
            printf(
                '<tr><th>追跡番号</th><td>%s</td></tr>',
                esc_html($tracking !== '' ? $tracking : '—')
            );
        }

        if ($shipped !== '') {
            printf(
                '<tr><th>発送日時</th><td>%s</td></tr>',
                esc_html(get_date_from_gmt($shipped, 'Y年n月j日 H:i'))
            );
        }

        echo '</table></section>';
    }

    private static function renderConfirmButton(WC_Order $order): void
    {
        $days = (int) get_option('mk_auto_complete_days', 7);

        $action = wp_nonce_url(
            add_query_arg('mk_receive', $order->get_id(), $order->get_view_order_url()),
            self::NONCE
        );

        echo '<section class="mk-receipt"><h2>受取確認</h2>';

        echo '<p>商品がお手元に届きましたら、下のボタンから受取確認を行ってください。'
            . '受取確認をもって取引完了となり、出品者へ売上が支払われます。</p>';

        printf(
            '<p><small>発送から%d日が経過した場合、自動的に受取確認となります。'
            . '商品に問題がある場合は、受取確認を行う前に運営までご連絡ください。</small></p>',
            $days
        );

        printf(
            '<form method="post" action="%s">'
            . '<button type="submit" class="button alt" '
            . 'onclick="return confirm(\'受取確認を行います。よろしいですか？\');">'
            . '受取確認をする</button></form>',
            esc_url($action)
        );

        echo '</section>';
    }

    public static function handleSubmit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_receive'])) {
            return;
        }

        check_admin_referer(self::NONCE);

        $order = wc_get_order((int) $_GET['mk_receive']);

        if (!$order instanceof WC_Order) {
            return;
        }

        // Only the person who paid. Not the creator, not another logged-in
        // customer who guessed an order id.
        if (get_current_user_id() !== $order->get_customer_id()) {
            wp_die('この注文を操作する権限がありません。', '', ['response' => 403]);
        }

        // Only from 発送済, and only once. Without this, a resubmitted form or
        // a double-click on an already-received order would re-enter
        // Transitions::onReceived and queue a second transfer for the same
        // money.
        if ($order->get_status() !== Statuses::SHIPPED) {
            wp_safe_redirect($order->get_view_order_url());
            exit;
        }

        $order->update_status(Statuses::RECEIVED, '購入者が受取確認を行いました。');

        wp_safe_redirect(add_query_arg('mk_received', '1', $order->get_view_order_url()));
        exit;
    }
}
