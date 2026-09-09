<?php
declare(strict_types=1);

namespace MK\Report;

use MK\Schedule\Jobs;
use MK\Stripe\TransferService;
use WC_Order;

/**
 * 通報 — a buyer or creator raising a problem with a transaction.
 *
 * This completes a mechanism that already existed at the money layer with no
 * way to trigger it. TransferService::execute() refuses to pay out an order
 * carrying _mk_has_open_report, and Jobs::runAutoComplete() refuses to
 * advance one — but until now only a Stripe chargeback could set that flag.
 * A buyer whose item never arrived had no way to stop the payout, and the
 * creator would be paid on schedule while the complaint sat unheard.
 *
 * ---------------------------------------------------------------------------
 * Opening a report stops the clock
 * ---------------------------------------------------------------------------
 * The queued transfer and the auto-complete are both cancelled the moment a
 * report is opened. This is the cheapest possible instant to intervene: the
 * money is still on the platform's balance, so resolving in the buyer's
 * favour costs a refund and nothing else. Once it has been transferred,
 * recovery depends on the creator's balance still holding enough to reverse,
 * and after payout to their bank it may not.
 *
 * Resolution re-queues the transfer rather than paying immediately, so the
 * agreed hold period still applies to whatever remains of it.
 */
final class Service
{
    public const TARGET_ORDER   = 'order';
    public const TARGET_PRODUCT = 'product';
    public const TARGET_USER    = 'user';

    public const STATUS_OPEN        = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';

    /** Order meta mirroring "this order has at least one unresolved report". */
    public const ORDER_FLAG = '_mk_has_open_report';

    /**
     * The reasons a report can be filed under, as agreed with the client.
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return [
            'not_arrived'  => '商品が届かない',
            'not_shipped'  => '発送されない',
            'not_as_described' => '説明と異なる',
            'damaged'      => '商品が破損していた',
            'nuisance'     => '迷惑行為',
            'other'        => 'その他',
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return self::reasons()[$reason] ?? $reason;
    }

    // ------------------------------------------------------------------ open

    /**
     * File a report. Returns the new report id.
     *
     * @throws \RuntimeException if the reason is not one of ours
     */
    public function open(
        int $reporterId,
        string $targetType,
        int $targetId,
        string $reason,
        string $comment = '',
    ): int {
        if (!isset(self::reasons()[$reason])) {
            throw new \RuntimeException('不正な通報理由です。');
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'mk_reports',
            [
                'reporter_id' => $reporterId,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'reason'      => $reason,
                'comment'     => $comment !== '' ? $comment : null,
                'status'      => self::STATUS_OPEN,
                'created_at'  => current_time('mysql', true),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        $reportId = (int) $wpdb->insert_id;

        if ($targetType === self::TARGET_ORDER) {
            $this->holdOrder($targetId, $reason);
        }

        do_action('mk_report_opened', $reportId, $targetType, $targetId);

        return $reportId;
    }

    /**
     * Freeze the money on a reported order.
     *
     * Deliberately does not change the order status. The transaction is still
     * whatever it was — shipped, received — and overwriting that would lose
     * the information needed to resume it. The hold lives in meta and in the
     * absence of the scheduled jobs.
     */
    private function holdOrder(int $orderId, string $reason): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $order->update_meta_data(self::ORDER_FLAG, 'yes');
        $order->add_order_note(sprintf(
            '通報が行われました（%s）。送金と自動完了を保留します。',
            self::reasonLabel($reason)
        ));
        $order->save();

        Jobs::cancelTransfer($orderId);
        Jobs::cancelAutoComplete($orderId);
    }

    // --------------------------------------------------------------- resolve

    /**
     * Close a report.
     *
     * $releasePayout says what happens to the money: true resumes the payout
     * on the normal schedule, false leaves it frozen because the operator
     * intends to refund. Refunding is deliberately NOT done here — it moves
     * real money and belongs behind its own explicit action, not behind a
     * status change on a support ticket.
     */
    public function resolve(int $reportId, int $adminId, string $note = '', bool $releasePayout = true): void
    {
        global $wpdb;

        $report = $this->find($reportId);

        if ($report === null || $report->status === self::STATUS_RESOLVED) {
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'mk_reports',
            [
                'status'      => self::STATUS_RESOLVED,
                'admin_note'  => $note !== '' ? $note : null,
                'resolved_at' => current_time('mysql', true),
            ],
            ['id' => $reportId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($report->target_type !== self::TARGET_ORDER) {
            do_action('mk_report_resolved', $reportId, $adminId);

            return;
        }

        // One order can carry several reports. The hold lifts only when the
        // last of them is closed, otherwise resolving one complaint would
        // release money that another is still disputing.
        if ($this->openCountFor(self::TARGET_ORDER, (int) $report->target_id) > 0) {
            do_action('mk_report_resolved', $reportId, $adminId);

            return;
        }

        $this->releaseOrder((int) $report->target_id, $releasePayout);

        do_action('mk_report_resolved', $reportId, $adminId);
    }

    private function releaseOrder(int $orderId, bool $releasePayout): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $order->update_meta_data(self::ORDER_FLAG, 'no');

        if (!$releasePayout) {
            $order->add_order_note('通報を解決済みとしましたが、送金は保留したままです。返金対応を行ってください。');
            $order->save();

            return;
        }

        $order->add_order_note('通報が解決されました。送金予定を再設定します。');
        $order->save();

        // Only an order that had reached 受取確認 and has not already been
        // paid is waiting on a transfer. Anything else resumes on its own.
        $paid = $order->get_meta(TransferService::META_TRANSFER_ID) !== '';

        if (!$paid && $order->get_status() === \MK\Order\Statuses::RECEIVED) {
            Jobs::scheduleTransfer($orderId);
        }
    }

    // ----------------------------------------------------------------- reads

    public function find(int $reportId): ?object
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}mk_reports WHERE id = %d", $reportId)
        );
    }

    public function openCountFor(string $targetType, int $targetId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mk_reports
                  WHERE target_type = %s AND target_id = %d AND status <> %s",
                $targetType,
                $targetId,
                self::STATUS_RESOLVED
            )
        );
    }

    /**
     * Has this person already reported this thing and not had it resolved?
     *
     * Stops one upset buyer filing the same complaint ten times and burying
     * everyone else's in the operator's queue.
     */
    public function alreadyReported(int $reporterId, string $targetType, int $targetId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mk_reports
                  WHERE reporter_id = %d AND target_type = %s
                    AND target_id = %d AND status <> %s",
                $reporterId,
                $targetType,
                $targetId,
                self::STATUS_RESOLVED
            )
        );
    }

    /** @return array<int, object> newest first */
    public function listByStatus(string $status = self::STATUS_OPEN, int $limit = 100): array
    {
        global $wpdb;

        if ($status === 'all') {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}mk_reports ORDER BY id DESC LIMIT %d",
                    $limit
                )
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mk_reports
                  WHERE status = %s ORDER BY id DESC LIMIT %d",
                $status,
                $limit
            )
        ) ?: [];
    }
}
