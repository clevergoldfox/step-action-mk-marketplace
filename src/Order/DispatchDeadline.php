<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Product\Details;
use MK\Product\MessageVideo;
use MK\Report\Service as ReportService;
use MK\Schedule\Jobs;
use WC_Order;

/**
 * 発送期限 — the promise the creator made at listing time, enforced.
 *
 * Before this, an unshipped order simply sat at 購入済 forever. Nothing
 * expired, nothing chased it, and the buyer's only options were to wait or to
 * file a general 通報. The money stayed on the platform, so nobody lost
 * anything outright, but the buyer had paid and had no way to get out.
 *
 * The chain the client asked for is three steps and each one is somebody
 * different acting:
 *
 *   deadline passes   both sides are told (this class, via Action Scheduler)
 *   buyer decides     キャンセル申請 — a 通報 of reason not_shipped, which
 *                     freezes the payout exactly as any other report does
 *   operator decides  cancel and refund, or resolve in the creator's favour
 *
 * Note what the middle step is NOT: an automatic cancellation. A parcel in
 * the post is indistinguishable from one never sent, and plenty of creators
 * ship on the last day and register it late. Cancelling on a timer would
 * unwind real deliveries. So the deadline gives the buyer a RIGHT to
 * cancel and tells them they have it; it does not exercise it for them.
 *
 * ---------------------------------------------------------------------------
 * The deadline is snapshotted, not looked up
 * ---------------------------------------------------------------------------
 * The number of days is copied onto the order at purchase. Read live from the
 * listing, a creator who was running late could edit the product to 4〜7日 and
 * move their own deadline after the fact -- and the buyer would be held to a
 * promise they never saw.
 */
final class DispatchDeadline
{
    /** The promise, as it stood when the buyer accepted it. */
    public const META_DISPATCH = '_mk_dispatch';

    public const META_DUE_AT     = '_mk_dispatch_due_at';
    public const META_OVERDUE_AT = '_mk_dispatch_overdue_at';
    public const META_REQUESTED  = '_mk_cancel_requested_at';

    /** Guards the creator's late counter against an Action Scheduler retry. */
    public const META_LATE_COUNTED = '_mk_dispatch_late_counted';

    /** How many late dispatches this creator has accumulated. */
    public const USER_LATE_COUNT = 'mk_late_dispatch_count';

    private const NONCE = 'mk_request_cancel';

    public static function register(): void
    {
        // Between Transitions (10, which schedules the job) and Events (20).
        add_action('woocommerce_order_details_after_order_table', [self::class, 'render'], 15);
        add_action('template_redirect', [self::class, 'handleRequest']);

        // The creator's own view of the same deadline.
        add_action('dokan_order_detail_after_order_items', [self::class, 'renderForCreator'], 5);
    }

    // ------------------------------------------------------------- the clock

    /** Days promised on this order, falling back for pre-feature listings. */
    public static function daysFor(WC_Order $order): int
    {
        $key = (string) $order->get_meta(self::META_DISPATCH);

        // A message video carries its delivery promise under the same key,
        // prefixed so the two vocabularies can never be mistaken for each other.
        if (str_starts_with($key, 'video-')) {
            return MessageVideo::deliveryDays(substr($key, 6));
        }

        return Details::dispatchDays($key);
    }

    /** The promise as the buyer saw it: 「2〜3日で発送」 or 「7日以内に送信」. */
    public static function promiseLabel(WC_Order $order): string
    {
        $key = (string) $order->get_meta(self::META_DISPATCH);

        return str_starts_with($key, 'video-')
            ? MessageVideo::deliveryLabel(substr($key, 6))
            : Details::dispatchLabel($key);
    }

    /** 「送信」 for a message video, 「発送」 for a parcel. */
    public static function verb(WC_Order $order): string
    {
        return MessageVideo::isMessageVideoOrder($order) ? '送信' : '発送';
    }

    /** GMT 'Y-m-d H:i:s', or '' if this order has no deadline. */
    public static function dueAt(WC_Order $order): string
    {
        return (string) $order->get_meta(self::META_DUE_AT);
    }

    public static function isOverdue(WC_Order $order): bool
    {
        // A declined video is with the operator, not late.
        if (VideoDelivery::isDeclined($order)) {
            return false;
        }

        return $order->get_meta(self::META_OVERDUE_AT) !== ''
            || ($order->get_status() === Statuses::PAID
                && self::dueAt($order) !== ''
                && strtotime(self::dueAt($order) . ' UTC') < time());
    }

    /**
     * Start the clock. Called from Transitions when payment lands.
     *
     * Counted from payment rather than from the order being placed: the
     * creator is not asked to ship anything until the money is captured, so
     * the days they promised start when the obligation does.
     */
    public static function start(WC_Order $order): void
    {
        $days = self::daysFor($order);
        $due  = time() + $days * DAY_IN_SECONDS;

        $order->update_meta_data(self::META_DUE_AT, gmdate('Y-m-d H:i:s', $due));
        $order->save();

        Jobs::scheduleDispatchOverdue($order->get_id(), $due);
    }

    /**
     * The deadline passed and the order is still unshipped.
     *
     * Both sides are told, because they need different things: the creator
     * needs to know they are late and that the buyer can now cancel, and the
     * buyer needs to know they have that right at all. Telling only the
     * creator would leave the buyer waiting on a process they cannot see.
     */
    public static function markOverdue(WC_Order $order): void
    {
        if ($order->get_status() !== Statuses::PAID) {
            return;   // shipped, cancelled, or otherwise moved on
        }

        if (VideoDelivery::isDeclined($order)) {
            return;   // with the operator; a decline is not lateness
        }

        if ($order->get_meta(self::META_OVERDUE_AT) !== '') {
            return;   // a retried job; do not re-notify or re-count
        }

        $order->update_meta_data(self::META_OVERDUE_AT, gmdate('Y-m-d H:i:s'));
        $verb = self::verb($order);

        $order->add_order_note(sprintf(
            '%1$s期限（%2$s）を過ぎましたが、%1$sされていません。'
            . '出品者・購入者へ通知し、購入者がキャンセルを申請できる状態にしました。',
            $verb,
            self::dueLabel($order)
        ));
        $order->save();

        self::countLate($order);

        do_action('mk_dispatch_overdue', $order->get_id(), (int) $order->get_meta('_mk_creator_id'));
    }

    /**
     * Keep score, and tell the operator when a creator becomes a pattern.
     *
     * The score is recorded automatically; the consequence is not. 出品制限 is
     * a judgement about whether someone is careless or abusive, and one late
     * parcel because a creator was in hospital should not look the same as
     * three because they never post anything. See Creator\Restriction, where
     * the operator applies it.
     */
    private static function countLate(WC_Order $order): void
    {
        if ($order->get_meta(self::META_LATE_COUNTED) === 'yes') {
            return;
        }

        $creatorId = (int) $order->get_meta('_mk_creator_id');

        if ($creatorId <= 0) {
            return;
        }

        $count = (int) get_user_meta($creatorId, self::USER_LATE_COUNT, true) + 1;

        update_user_meta($creatorId, self::USER_LATE_COUNT, $count);

        $order->update_meta_data(self::META_LATE_COUNTED, 'yes');
        $order->save();

        $threshold = (int) get_option('mk_late_dispatch_threshold', 3);

        if ($count >= $threshold) {
            do_action('mk_creator_repeatedly_late', $creatorId, $count);
        }
    }

    public static function lateCount(int $creatorId): int
    {
        return (int) get_user_meta($creatorId, self::USER_LATE_COUNT, true);
    }

    // ------------------------------------------------------------------ view

    public static function dueLabel(WC_Order $order): string
    {
        $due = self::dueAt($order);

        return $due !== '' ? get_date_from_gmt($due, 'n月j日') : '—';
    }

    /** The buyer's panel on 注文詳細. */
    public static function render(WC_Order $order): void
    {
        if (get_current_user_id() !== $order->get_customer_id()) {
            return;
        }

        if ($order->get_status() !== Statuses::PAID || self::dueAt($order) === '') {
            return;
        }

        if (!self::isOverdue($order)) {
            // A message video has its own panel saying this in the words that
            // fit it; two panels would contradict each other.
            if (MessageVideo::isMessageVideoOrder($order)) {
                return;
            }

            printf(
                '<section class="mk-dispatch"><h2>発送予定</h2>'
                . '<p>出品者は <strong>%s頃まで</strong>に発送する予定です（%s）。'
                . '発送されるとメールでお知らせします。</p></section>',
                esc_html(self::dueLabel($order)),
                esc_html(Details::dispatchLabel((string) $order->get_meta(self::META_DISPATCH)))
            );

            return;
        }

        // Already asked. Say so rather than offering the button again: a
        // second request would be refused by Report\Service anyway, and a
        // button that does nothing reads as the site being broken.
        if ($order->get_meta(self::META_REQUESTED) !== ''
            || $order->get_meta(ReportService::ORDER_FLAG) === 'yes'
        ) {
            echo '<section class="mk-dispatch mk-dispatch--late"><h2>キャンセル申請を受け付けました</h2>'
                . '<p>運営が状況を確認しております。確認が完了するまで、出品者への送金は保留されます。'
                . '結果はご登録のメールアドレスへご連絡いたします。</p></section>';

            return;
        }

        $action = wp_nonce_url(
            add_query_arg('mk_cancel_request', $order->get_id(), $order->get_view_order_url()),
            self::NONCE
        );

        $verb = self::verb($order);

        printf('<section class="mk-dispatch mk-dispatch--late"><h2>%s期限を過ぎています</h2>', esc_html($verb));

        printf(
            '<p>この取引の%1$s期限は <strong>%2$s</strong> でしたが、'
            . 'まだ%1$sされていません。出品者へは通知済みです。</p>',
            esc_html($verb),
            esc_html(self::dueLabel($order))
        );

        echo '<p>お待ちいただくこともできますが、キャンセルをご希望の場合は下記より申請してください。'
            . '運営が状況を確認のうえ、キャンセル・ご返金の対応を行います。'
            . '<strong>申請を行うと、確認が完了するまで出品者への送金は保留されます。</strong></p>';

        printf('<form method="post" action="%s">', esc_url($action));
        echo '<p><label for="mk_cancel_comment">運営への連絡事項（任意）</label><br>'
            . '<textarea name="mk_cancel_comment" id="mk_cancel_comment" rows="3" '
            . 'style="width:100%" maxlength="2000"></textarea></p>';
        echo '<button type="submit" class="button" '
            . 'onclick="return confirm(\'この取引のキャンセルを申請します。よろしいですか？\');">'
            . 'キャンセルを申請する</button>';
        echo '</form></section>';
    }

    /** The same deadline, on the creator's side of the order. */
    public static function renderForCreator(WC_Order $order): void
    {
        if ($order->get_status() !== Statuses::PAID || self::dueAt($order) === '') {
            return;
        }

        if ((int) $order->get_meta('_mk_creator_id') !== get_current_user_id()
            && !current_user_can('manage_woocommerce')
        ) {
            return;
        }

        if (MessageVideo::isMessageVideoOrder($order)) {
            // The request panel already shows the deadline; only lateness
            // needs saying here, in the words that fit a video.
            if (self::isOverdue($order)) {
                printf(
                    '<div class="dokan-alert dokan-alert-danger">'
                    . '<strong>送信期限（%s）を過ぎています。</strong> '
                    . '購入者はこの取引のキャンセルを申請できます。撮影済みの場合は、至急動画を送信してください。</div>',
                    esc_html(self::dueLabel($order))
                );
            }

            return;
        }

        if (self::isOverdue($order)) {
            printf(
                '<div class="dokan-alert dokan-alert-danger">'
                . '<strong>発送期限（%s）を過ぎています。</strong> '
                . 'お約束の発送日を過ぎているため、購入者はこの取引のキャンセルを申請できます。'
                . 'すでに発送済みの場合は、至急「発送登録」を行ってください。</div>',
                esc_html(self::dueLabel($order))
            );

            return;
        }

        printf(
            '<div class="dokan-alert dokan-alert-info">'
            . '出品時にお約束した発送期限は <strong>%s</strong> です。'
            . '期限を過ぎると購入者がキャンセルを申請できるようになります。</div>',
            esc_html(self::dueLabel($order))
        );
    }

    // --------------------------------------------------------------- request

    /**
     * The buyer's cancellation request.
     *
     * Filed as a 通報 rather than as a new kind of record, because the
     * machinery a cancellation needs already exists there: the payout freezes,
     * the auto-complete stops, the operator gets it in one queue with
     * everything else, and closing it either way is already built. A parallel
     * "cancellation request" table would duplicate all of that and would be
     * the one an operator forgets to look at.
     */
    public static function handleRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_cancel_request'])) {
            return;
        }

        check_admin_referer(self::NONCE);

        $order  = wc_get_order((int) $_GET['mk_cancel_request']);
        $userId = get_current_user_id();

        if (!$order instanceof WC_Order) {
            return;
        }

        // The buyer, and only the buyer. Re-checked here rather than trusted
        // from the form: without this, anyone logged in could POST an order id
        // and freeze a stranger's payout.
        if ($userId === 0 || $userId !== $order->get_customer_id()) {
            wp_die('この取引をキャンセル申請する権限がありません。', '', ['response' => 403]);
        }

        // The right only exists once the promise has actually been broken.
        if ($order->get_status() !== Statuses::PAID || !self::isOverdue($order)) {
            wp_safe_redirect($order->get_view_order_url());
            exit;
        }

        $service = new ReportService();

        if ($service->alreadyReported($userId, ReportService::TARGET_ORDER, $order->get_id())) {
            wp_safe_redirect($order->get_view_order_url());
            exit;
        }

        $comment = isset($_POST['mk_cancel_comment'])
            ? sanitize_textarea_field(wp_unslash($_POST['mk_cancel_comment']))
            : '';

        $comment = trim(sprintf(
            "【キャンセル申請】%1\$s期限 %2\$s を過ぎても%1\$sされていません。\n%3\$s",
            self::verb($order),
            self::dueLabel($order),
            $comment
        ));

        try {
            $service->open(
                $userId,
                ReportService::TARGET_ORDER,
                $order->get_id(),
                'not_shipped',
                $comment
            );
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg('mk_cancel_error', '1', $order->get_view_order_url()));
            exit;
        }

        $order->update_meta_data(self::META_REQUESTED, gmdate('Y-m-d H:i:s'));
        $order->add_order_note('購入者から発送遅延によるキャンセル申請がありました。運営の確認をお待ちください。');
        $order->save();

        do_action('mk_cancel_requested', $order->get_id(), $userId);

        wp_safe_redirect(add_query_arg('mk_cancel_requested', '1', $order->get_view_order_url()));
        exit;
    }
}
