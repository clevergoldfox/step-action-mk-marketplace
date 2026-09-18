<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Notify\Dispatcher;
use MK\Notify\Notification;
use MK\Stripe\TransferService;
use MK\Support\Money;
use WC_Order;

/**
 * A payment taken back by the card issuer, and who is left holding it.
 *
 * Stripe already told us about disputes -- the webhook froze the payout the
 * moment one opened -- but that was the whole of it. Nothing said what became
 * of the dispute, nothing wrote the loss into the creator's ledger, and
 * TransferService::absorbDispute(), which does exactly that, was reachable
 * from no screen at all.
 *
 * The client's rule (2026-09-19): the disputed sale and Stripe's chargeback
 * fee are the creator's, because it is their sale that caused both. The
 * exception is a chargeback caused by a fault in this system or a plain
 * mistake by the operator, which the platform pays for.
 *
 * Deliberately not automatic. A lost dispute is money that has already gone;
 * pressing the button only decides whose books it comes out of, and that is a
 * finding about a real transaction, not a status change to apply on a
 * webhook. So the operator is told, the payout stays frozen until they act,
 * and the rule is the pre-selected answer rather than the only one.
 */
final class Chargeback
{
    public const META_ID      = '_mk_dispute_id';
    public const META_STATUS  = '_mk_dispute_status';
    public const META_AMOUNT  = '_mk_dispute_amount';
    public const META_FEE     = '_mk_dispute_fee';
    public const META_BEARER  = '_mk_dispute_bearer';
    public const META_SETTLED = '_mk_dispute_settled_at';

    /** Stripe's dispute fee for JPY, used when the event does not carry one. */
    public const DEFAULT_FEE = 1500;

    private const NONCE = 'mk_chargeback';

    public static function register(): void
    {
        add_action('mk_dispute_opened', [self::class, 'onOpened'], 10, 2);
        add_action('mk_dispute_changed', [self::class, 'onChanged'], 10, 2);
        add_action('add_meta_boxes', [self::class, 'addBox'], 10, 2);
        add_action('admin_post_mk_chargeback', [self::class, 'handle']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    // --------------------------------------------------------------- events

    /** @param object $dispute */
    public static function onOpened(WC_Order $order, $dispute): void
    {
        self::record($order, $dispute);
        self::tellOperator($order, $dispute, true);
    }

    /**
     * A dispute that changed. Only a decision is worth anyone's attention.
     *
     * @param object $dispute
     */
    public static function onChanged(WC_Order $order, $dispute): void
    {
        $was = (string) $order->get_meta(self::META_STATUS);

        self::record($order, $dispute);

        $now = (string) ($dispute->status ?? '');

        if ($now === $was || !in_array($now, ['won', 'lost'], true)) {
            return;
        }

        if ($now === 'won') {
            $order->add_order_note(
                'チャージバックは取り消されました（カード会社の判断）。代金は当社に戻ります。'
                . '送金の保留を解除する場合は、取引の状況を確認のうえ操作してください。'
            );
            $order->save();
        } else {
            $order->add_order_note(sprintf(
                'チャージバックが確定しました（%s）。代金はカード会社に引き戻されています。'
                . '負担者を確定し、記録する操作が必要です。',
                Money::format((int) $order->get_meta(self::META_AMOUNT))
            ));
            $order->save();
        }

        self::tellOperator($order, $dispute, false);
    }

    /** @param object $dispute */
    public static function record(WC_Order $order, $dispute): void
    {
        $order->update_meta_data(self::META_ID, (string) ($dispute->id ?? ''));
        $order->update_meta_data(self::META_STATUS, (string) ($dispute->status ?? ''));
        $order->update_meta_data(self::META_AMOUNT, (int) ($dispute->amount ?? $order->get_total()));
        $order->update_meta_data(self::META_FEE, self::feeFrom($dispute));
        $order->save();
    }

    /**
     * Stripe's own fee for this dispute, when the event carries it.
     *
     * The balance transaction is the authority: the fee has changed before and
     * differs by currency, and a hard-coded ¥1,500 that is quietly wrong makes
     * an account that will not reconcile. The constant is the fallback for an
     * event that arrives without one expanded.
     *
     * @param object $dispute
     */
    public static function feeFrom($dispute): int
    {
        $transactions = $dispute->balance_transactions ?? [];

        foreach (is_array($transactions) ? $transactions : [] as $transaction) {
            $fee = (int) ($transaction->fee ?? 0);

            if ($fee !== 0) {
                return abs($fee);
            }
        }

        return self::DEFAULT_FEE;
    }

    public static function statusLabel(string $status): string
    {
        return [
            'warning_needs_response' => '事前警告（カード会社からの照会）',
            'warning_under_review'   => '事前警告（確認中）',
            'warning_closed'         => '事前警告は終了しました',
            'needs_response'         => '異議申立ての対応が必要です（Stripeで証拠の提出が必要）',
            'under_review'           => 'カード会社が審査中です',
            'won'                    => 'チャージバックは取り消されました',
            'lost'                   => 'チャージバックが確定しました',
        ][$status] ?? $status;
    }

    /** @param object $dispute */
    private static function tellOperator(WC_Order $order, $dispute, bool $opened): void
    {
        $status = (string) ($dispute->status ?? '');
        $amount = Money::format((int) ($dispute->amount ?? $order->get_total()));

        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $adminId) {
            Dispatcher::send(new Notification(
                type: $opened ? 'dispute.opened' : 'dispute.changed',
                userId: (int) $adminId,
                subject: $opened ? 'チャージバックが申し立てられました' : 'チャージバックの状況が変わりました',
                body: sprintf(
                    "ご注文 #%d（%s）について、カード会社への異議申立て（チャージバック）が%s。\n"
                    . "現在の状況：%s\n\n"
                    . "この取引の出品者への送金は保留されています。\n"
                    . "証拠の提出は Stripe の管理画面から行ってください。\n"
                    . "チャージバックが確定した場合は、注文画面で負担者を確定し、記録してください。",
                    $order->get_id(),
                    $amount,
                    $opened ? '行われました' : '更新されました',
                    self::statusLabel($status)
                ),
                short: sprintf('注文 #%d のチャージバック：%s', $order->get_id(), self::statusLabel($status)),
                url: self::orderUrl($order->get_id()),
                context: ['order_id' => $order->get_id(), 'dispute_status' => $status],
            ));
        }
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

        if (!$order instanceof WC_Order || (string) $order->get_meta(self::META_ID) === '') {
            return;
        }

        $screens = ['shop_order'];

        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique(array_filter($screens)) as $screen) {
            add_meta_box(
                'mk-chargeback',
                'チャージバック（運営操作）',
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
        $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID ?? 0);

        if (!$order instanceof WC_Order) {
            return;
        }

        $status = (string) $order->get_meta(self::META_STATUS);
        $amount = (int) $order->get_meta(self::META_AMOUNT);
        $fee    = (int) $order->get_meta(self::META_FEE) ?: self::DEFAULT_FEE;

        printf(
            '<p><strong>%s</strong><br>対象金額：%s<br>チャージバック手数料：%s</p>',
            esc_html(self::statusLabel($status)),
            esc_html(Money::format($amount)),
            esc_html(Money::format($fee))
        );

        if ((string) $order->get_meta(self::META_SETTLED) !== '') {
            printf(
                '<p>処理済みです（%s／負担：%s）。</p>',
                esc_html((string) $order->get_meta(self::META_SETTLED)),
                esc_html($order->get_meta(self::META_BEARER) === 'platform' ? '運営負担' : 'クリエイター負担')
            );

            return;
        }

        // Won: nothing was lost, so nothing is charged to anybody. What is
        // left is the hold the dispute put on the payout, which no report
        // resolves because no report was ever opened for it.
        if ($status === 'won') {
            echo '<p>チャージバックは取り消されました。この取引の売上は当社に戻ります。'
                . '送金の保留を解除すると、通常どおり出品者へ送金されます。</p>';

            printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
            wp_nonce_field(self::NONCE);
            echo '<input type="hidden" name="action" value="mk_chargeback">';
            echo '<input type="hidden" name="release" value="1">';
            printf('<input type="hidden" name="order_id" value="%d">', $order->get_id());
            echo '<p><button type="submit" class="button">送金の保留を解除する</button></p>';
            echo '</form>';

            return;
        }

        if ($status !== 'lost') {
            echo '<p>カード会社の判断が確定するまで、この取引の送金は保留されます。'
                . '証拠の提出は Stripe の管理画面から行ってください。</p>';

            return;
        }

        echo '<p><small>チャージバックは返金ではありません。代金はすでにカード会社が引き戻しているため、'
            . 'ここでの操作でお金は動きません。送金済みであれば巻き戻しを行い、'
            . '誰の負担とするかを記録します。</small></p>';

        printf(
            '<form method="post" action="%s">',
            esc_url(admin_url('admin-post.php'))
        );

        wp_nonce_field(self::NONCE);

        printf('<input type="hidden" name="action" value="mk_chargeback">');
        printf('<input type="hidden" name="order_id" value="%d">', $order->get_id());

        printf(
            '<p><label>チャージバック手数料<br>'
            . '<input type="number" name="fee" value="%d" min="0" step="1" class="small-text"> 円</label></p>',
            $fee
        );

        echo '<p><strong>費用の負担</strong></p>';
        echo '<p><label><input type="radio" name="bearer" value="creator" checked> '
            . 'クリエイター負担（原則）</label><br>'
            . '<label><input type="radio" name="bearer" value="platform"> '
            . '運営負担（システム上の不備・運営の過失による場合）</label></p>';

        echo '<p><textarea name="reason" rows="2" class="widefat" placeholder="判断の理由（任意）"></textarea></p>';

        echo '<p><button type="submit" class="button button-primary" '
            . 'onclick="return confirm(\'チャージバックを確定して記録します。よろしいですか？\');">'
            . 'チャージバックを記録する</button></p>';

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

        if (!empty($_POST['release'])) {
            self::release($order);
            wp_safe_redirect(add_query_arg('mk_chargeback_released', '1', self::orderUrl($orderId)));
            exit;
        }

        // Once is enough. A second press would pull the transfer back twice
        // and bill the creator for the same chargeback again.
        if ((string) $order->get_meta(self::META_SETTLED) !== '') {
            wp_safe_redirect(add_query_arg('mk_chargeback_done', '1', self::orderUrl($orderId)));
            exit;
        }

        $fee    = isset($_POST['fee']) ? max(0, (int) $_POST['fee']) : self::DEFAULT_FEE;
        $bearer = sanitize_key(wp_unslash((string) ($_POST['bearer'] ?? 'creator'))) === 'platform'
            ? 'platform'
            : 'creator';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash((string) $_POST['reason'])) : '';

        try {
            (new TransferService())->absorbDispute($order, $fee, $bearer === 'creator');
        } catch (\Throwable $e) {
            error_log(sprintf('[mk-marketplace] chargeback of order %d failed: %s', $orderId, $e->getMessage()));

            wp_safe_redirect(add_query_arg(
                ['mk_chargeback_failed' => 1, 'mk_chargeback_msg' => rawurlencode($e->getMessage())],
                self::orderUrl($orderId)
            ));
            exit;
        }

        $order = wc_get_order($orderId);   // reload: absorbDispute saved it
        $admin = wp_get_current_user();

        $order->update_meta_data(self::META_BEARER, $bearer);
        $order->update_meta_data(self::META_SETTLED, current_time('mysql'));
        $order->add_order_note(sprintf(
            'チャージバックを記録しました（操作者：%s／費用負担：%s）。%s',
            $admin->display_name,
            $bearer === 'platform' ? '運営負担' : 'クリエイター負担',
            $reason !== '' ? '理由：' . $reason : ''
        ));
        $order->save();

        if (!in_array($order->get_status(), ['refunded', 'cancelled'], true)) {
            $order->update_status('refunded', 'チャージバックの確定による取引の終了');
        }

        do_action('mk_chargeback_settled', $orderId, $bearer, get_current_user_id());

        wp_safe_redirect(add_query_arg('mk_chargeback_done', '1', self::orderUrl($orderId)));
        exit;
    }

    /**
     * Let the held payout go again, after a dispute that came to nothing.
     *
     * Only the dispute's own hold is lifted: a report opened by a buyer keeps
     * its own, and lifting that one is the report's business, not this one's.
     */
    private static function release(WC_Order $order): void
    {
        $open = (new \MK\Report\Service())->openFor(\MK\Report\Service::TARGET_ORDER, $order->get_id());

        if (!empty($open)) {
            $order->add_order_note(
                'チャージバックは取り消されましたが、この取引には未対応の申し出があるため、送金の保留は続きます。'
            );
            $order->save();

            return;
        }

        $order->update_meta_data('_mk_has_open_report', 'no');
        $order->add_order_note(sprintf(
            'チャージバックの取り消しにより、送金の保留を解除しました（操作者：%s）。',
            wp_get_current_user()->display_name
        ));
        $order->save();

        // Only where the transfer is still owed. After payout there is
        // nothing to schedule, and a second transfer would pay twice.
        if ((string) $order->get_meta(TransferService::META_TRANSFER_ID) === ''
            && in_array($order->get_status(), [Statuses::RECEIVED, 'completed'], true)) {
            \MK\Schedule\Jobs::scheduleTransfer($order->get_id());
        }
    }

    public static function notices(): void
    {
        if (isset($_GET['mk_chargeback_released'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . '送金の保留を解除しました。</p></div>';
        }

        if (isset($_GET['mk_chargeback_done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . 'チャージバックを記録しました。</p></div>';
        }

        if (isset($_GET['mk_chargeback_failed'])) {
            printf(
                '<div class="notice notice-error"><p><strong>チャージバックの記録に失敗しました。</strong>'
                . 'Stripe の管理画面で状況を確認してください。<br><code>%s</code></p></div>',
                esc_html(rawurldecode((string) ($_GET['mk_chargeback_msg'] ?? '')))
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
