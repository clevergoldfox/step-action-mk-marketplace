<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Notify\Dispatcher;
use MK\Notify\Notification;
use MK\Schedule\Jobs;
use MK\Stripe\Client;
use MK\Stripe\PaymentService;
use MK\Stripe\TransferService;
use MK\Support\Money;
use WC_Order;

/**
 * A refund made in the Stripe dashboard, brought back into our books.
 *
 * Refunds belong here, where the transfer is pulled back, the ledger is
 * written and the order moves. The client's operating rule says so
 * (2026-09-19). Stripe's dashboard, though, will always be there, and one
 * click in it refunds a buyer while this site goes on showing a live sale
 * with a payout on its way -- and that payout would then send the creator
 * money for a sale the buyer no longer paid for.
 *
 * So the rule is backed by a safety net rather than trusted: the refunded
 * webhook is compared against the refunds this site made, anything else stops
 * the payout at once, and the operator is told to finish the job.
 *
 * Told, not corrected automatically. Who bears the cost is a finding, and the
 * amounts are already gone either way -- there is nothing that must be done
 * this second, and a wrong ledger entry is harder to undo than a late one.
 */
final class ExternalRefund
{
    public const META_AMOUNT  = '_mk_external_refund_amount';
    public const META_IDS     = '_mk_external_refund_ids';
    public const META_AT      = '_mk_external_refund_at';
    public const META_SETTLED = '_mk_external_refund_settled_at';

    /**
     * How long after our own refund call a strange refund is treated as
     * possibly ours. Stripe delivers in seconds; the window is generous
     * because the cost of waiting is a delay and the cost of being wrong is
     * an order flagged against an operator who did nothing unusual.
     */
    private const OURS_WINDOW = 300;

    private const NONCE = 'mk_external_refund';

    public static function register(): void
    {
        add_action('mk_stripe_charge_refunded', [self::class, 'onCharge']);
        add_action('mk_recheck_external_refund', [self::class, 'recheck'], 10, 2);
        add_action('add_meta_boxes', [self::class, 'addBox'], 10, 2);
        add_action('admin_post_mk_external_refund', [self::class, 'handle']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    /** @param object $charge */
    public static function onCharge($charge): void
    {
        $order = self::orderFor($charge);

        if (!$order instanceof WC_Order) {
            return;
        }

        [$amount, $ids] = self::unknownRefunds($order, $charge);

        if ($amount <= 0) {
            return;
        }

        // Our own refund, still being recorded by the request that made it.
        $started = (int) $order->get_meta(PaymentService::META_REFUND_STARTED);

        if ($started > 0 && (time() - $started) < self::OURS_WINDOW) {
            wp_schedule_single_event(
                time() + self::OURS_WINDOW,
                'mk_recheck_external_refund',
                [$order->get_id(), (string) ($charge->id ?? '')]
            );

            return;
        }

        self::flag($order, $amount, $ids);
    }

    /**
     * Look again, once the request that may have made this refund is over.
     *
     * The charge is fetched rather than remembered: what matters is the state
     * now, and by now our own refund -- if it was ours -- is on the order.
     */
    public static function recheck(int $orderId, string $chargeId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order || $chargeId === '' || !Client::isConfigured()) {
            return;
        }

        try {
            $charge = Client::get()->charges->retrieve($chargeId, []);
        } catch (\Throwable $e) {
            error_log(sprintf('[mk-marketplace] recheck of charge %s failed: %s', $chargeId, $e->getMessage()));

            return;
        }

        [$amount, $ids] = self::unknownRefunds($order, $charge);

        if ($amount > 0) {
            self::flag($order, $amount, $ids);
        }
    }

    /**
     * What Stripe has refunded that this site did not do.
     *
     * @param object $charge
     * @return array{0:int, 1:array<int, string>} amount in yen, and the refund ids
     */
    private static function unknownRefunds(WC_Order $order, $charge): array
    {
        $known = $order->get_meta(PaymentService::META_KNOWN_REFUNDS);
        $known = is_array($known) ? array_map('strval', $known) : [];

        $refunds = $charge->refunds->data ?? null;

        if (!is_array($refunds)) {
            // No list to compare against: fall back on the totals. Less
            // precise, and it cannot name the refund, but it still catches
            // money leaving the account without us.
            $ours = (int) $order->get_meta(TransferService::META_REFUND_AMOUNT);

            return [max(0, (int) ($charge->amount_refunded ?? 0) - $ours), []];
        }

        $amount = 0;
        $ids    = [];

        foreach ($refunds as $refund) {
            $id = (string) ($refund->id ?? '');

            if ($id === '' || in_array($id, $known, true)) {
                continue;
            }

            $amount += (int) ($refund->amount ?? 0);
            $ids[]   = $id;
        }

        return [$amount, $ids];
    }

    /** @param array<int, string> $ids */
    private static function flag(WC_Order $order, int $amount, array $ids): void
    {
        // Already flagged for the same money: the webhook is delivered more
        // than once, and a second note helps nobody.
        if ((int) $order->get_meta(self::META_AMOUNT) === $amount) {
            return;
        }

        $order->update_meta_data(self::META_AMOUNT, $amount);
        $order->update_meta_data(self::META_IDS, $ids);
        $order->update_meta_data(self::META_AT, current_time('mysql'));
        $order->update_meta_data('_mk_has_open_report', 'yes');
        $order->add_order_note(sprintf(
            '⚠️ Stripe の管理画面で返金が行われました（%s）。'
            . 'サイト側の取引状態と売上の記録は、まだ更新されていません。'
            . '出品者への送金は保留しました。注文画面から反映の操作を行ってください。',
            Money::format($amount)
        ));
        $order->save();

        Jobs::cancelTransfer($order->get_id());
        Jobs::cancelAutoComplete($order->get_id());

        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $adminId) {
            Dispatcher::send(new Notification(
                type: 'refund.external',
                userId: (int) $adminId,
                subject: 'Stripe の管理画面で返金が行われました',
                body: sprintf(
                    "ご注文 #%d について、Stripe の管理画面から %s の返金が行われました。\n\n"
                    . "この返金はサイト側の記録に反映されていません。出品者への送金は保留しています。\n"
                    . "注文画面で「サイト側に反映する」操作を行ってください。\n\n"
                    . "返金は原則として TREASURE BUZZ の管理画面から行ってください。",
                    $order->get_id(),
                    Money::format($amount)
                ),
                short: sprintf('注文 #%d が Stripe 側で返金されています。反映が必要です。', $order->get_id()),
                url: self::orderUrl($order->get_id()),
                context: ['order_id' => $order->get_id(), 'amount' => $amount],
            ));
        }

        do_action('mk_external_refund_found', $order->get_id(), $amount);
    }

    /** @param object $charge */
    private static function orderFor($charge): ?WC_Order
    {
        $chargeId = (string) ($charge->id ?? '');

        if ($chargeId === '') {
            return null;
        }

        $orders = wc_get_orders([
            'meta_key'   => PaymentService::META_CHARGE_ID,
            'meta_value' => $chargeId,
            'limit'      => 1,
        ]);

        return empty($orders) ? null : $orders[0];
    }

    // ---------------------------------------------------------------- admin

    /**
     * Added only where there is something to show.
     *
     * A box on every order saying that nothing happened is noise on a
     * screen the operator reads under pressure.
     *
     * @param mixed $post WP_Post on the legacy screen, WC_Order under HPOS
     */
    public static function addBox($screen = '', $post = null): void
    {
        $order = $post instanceof WC_Order ? $post : wc_get_order(is_object($post) ? ($post->ID ?? 0) : 0);

        if (!$order instanceof WC_Order || (int) $order->get_meta(self::META_AMOUNT) <= 0) {
            return;
        }

        $screens = ['shop_order'];

        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique(array_filter($screens)) as $screen) {
            add_meta_box(
                'mk-external-refund',
                'Stripe 側で行われた返金',
                [self::class, 'renderBox'],
                $screen,
                'side',
                'high'
            );
        }
    }

    /** @param mixed $post WP_Post on the legacy screen, WC_Order under HPOS */
    public static function renderBox($post): void
    {
        $order  = $post instanceof WC_Order ? $post : wc_get_order($post->ID ?? 0);
        if (!$order instanceof WC_Order) {
            return;
        }

        $amount = (int) $order->get_meta(self::META_AMOUNT);

        printf(
            '<p><strong>Stripe の管理画面で %s が返金されています。</strong><br>検出日時：%s</p>',
            esc_html(Money::format($amount)),
            esc_html((string) $order->get_meta(self::META_AT))
        );

        if ((string) $order->get_meta(self::META_SETTLED) !== '') {
            printf(
                '<p>反映済みです（%s／費用負担：%s）。</p>',
                esc_html((string) $order->get_meta(self::META_SETTLED)),
                esc_html($order->get_meta(TransferService::META_COST_BEARER) === 'platform' ? '運営負担' : 'クリエイター負担')
            );

            return;
        }

        echo '<p><small>お金はすでに Stripe 側で動いています。ここでの操作は、'
            . '出品者への送金の巻き戻しと、サイト側の取引状態・売上の記録の更新です。'
            . '全額返金として処理します。</small></p>';

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));

        wp_nonce_field(self::NONCE);

        echo '<input type="hidden" name="action" value="mk_external_refund">';
        printf('<input type="hidden" name="order_id" value="%d">', $order->get_id());

        echo '<p><strong>費用の負担</strong></p>';
        echo '<p><label><input type="radio" name="bearer" value="creator" checked> '
            . 'クリエイター負担</label><br>'
            . '<label><input type="radio" name="bearer" value="platform"> '
            . '運営負担</label></p>';

        echo '<p><textarea name="reason" rows="2" class="widefat" placeholder="返金の理由（任意）"></textarea></p>';

        echo '<p><button type="submit" class="button button-primary" '
            . 'onclick="return confirm(\'この返金をサイト側の記録に反映します。よろしいですか？\');">'
            . 'サイト側に反映する</button></p>';

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

        if (!$order instanceof WC_Order) {
            wp_safe_redirect(admin_url('admin.php?page=wc-orders'));
            exit;
        }

        if ((string) $order->get_meta(self::META_SETTLED) !== '') {
            wp_safe_redirect(add_query_arg('mk_external_done', '1', self::orderUrl($orderId)));
            exit;
        }

        $amount = (int) $order->get_meta(self::META_AMOUNT);
        $bearer = sanitize_key(wp_unslash((string) ($_POST['bearer'] ?? 'creator'))) === 'platform'
            ? 'platform'
            : 'creator';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash((string) $_POST['reason'])) : '';
        $paidOut = (string) $order->get_meta(TransferService::META_TRANSFER_ID) !== '';

        try {
            (new TransferService())->reconcileExternalRefund($order, $amount, $bearer === 'creator');
        } catch (\Throwable $e) {
            error_log(sprintf('[mk-marketplace] external refund of order %d failed: %s', $orderId, $e->getMessage()));

            wp_safe_redirect(add_query_arg(
                ['mk_external_failed' => 1, 'mk_external_msg' => rawurlencode($e->getMessage())],
                self::orderUrl($orderId)
            ));
            exit;
        }

        $order = wc_get_order($orderId);   // reload: the reconcile saved it
        $admin = wp_get_current_user();

        $order->update_meta_data(self::META_SETTLED, current_time('mysql'));
        $order->add_order_note(sprintf(
            'Stripe 側の返金をサイトに反映しました（操作者：%s／費用負担：%s）。%s',
            $admin->display_name,
            $bearer === 'platform' ? '運営負担' : 'クリエイター負担',
            $reason !== '' ? '理由：' . $reason : ''
        ));
        $order->save();

        if (!in_array($order->get_status(), ['refunded', 'cancelled'], true)) {
            $order->update_status($paidOut ? 'refunded' : 'cancelled', 'Stripe 側で行われた返金の反映');
        }

        do_action('mk_external_refund_settled', $orderId, $bearer, get_current_user_id());

        wp_safe_redirect(add_query_arg('mk_external_done', '1', self::orderUrl($orderId)));
        exit;
    }

    public static function notices(): void
    {
        if (isset($_GET['mk_external_done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . 'Stripe 側の返金をサイトに反映しました。</p></div>';
        }

        if (isset($_GET['mk_external_failed'])) {
            printf(
                '<div class="notice notice-error"><p><strong>反映に失敗しました。</strong>'
                . 'Stripe の管理画面で状況を確認してください。<br><code>%s</code></p></div>',
                esc_html(rawurldecode((string) ($_GET['mk_external_msg'] ?? '')))
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
