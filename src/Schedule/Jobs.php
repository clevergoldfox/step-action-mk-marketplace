<?php
declare(strict_types=1);

namespace MK\Schedule;

use MK\Order\Statuses;
use MK\Stripe\TransferService;
use WC_Order;

/**
 * Scheduled work, on Action Scheduler.
 *
 * Action Scheduler ships with WooCommerce and is a real queue: retries on
 * failure, a persistent record of every pending and failed job, and an admin
 * screen at WooCommerce → Status → Scheduled Actions showing all of it.
 *
 * Raw WP-Cron is not used anywhere. It fires on page requests, so on a
 * pre-launch marketplace with no traffic a scheduled transfer simply never
 * runs — money does not move and nothing errors. Action Scheduler still
 * depends on cron firing, which is why the server cron entry matters.
 */
final class Jobs
{
    public const AUTO_COMPLETE  = 'mk_auto_complete_order';
    public const EXECUTE_TRANSFER = 'mk_execute_transfer';
    public const MASK_ADDRESS   = 'mk_mask_shipping_address';
    public const DISPATCH_OVERDUE = 'mk_dispatch_overdue_check';
    public const DAILY_DIGEST   = 'mk_send_daily_digest';
    public const SWEEP_RESERVATIONS = 'mk_sweep_reservations';

    /** Guards the completed-sales counter against a job retry. */
    public const META_SALE_COUNTED = '_mk_sale_counted';

    private const GROUP = 'mk-marketplace';

    public static function register(): void
    {
        add_action(self::AUTO_COMPLETE, [self::class, 'runAutoComplete'], 10, 1);
        add_action(self::EXECUTE_TRANSFER, [self::class, 'runTransfer'], 10, 1);
        add_action(self::MASK_ADDRESS, [self::class, 'runMaskAddress'], 10, 1);
        add_action(self::DISPATCH_OVERDUE, [self::class, 'runDispatchOverdue'], 10, 1);
        add_action(self::SWEEP_RESERVATIONS, [self::class, 'runSweepReservations']);

        add_action('init', [self::class, 'ensureRecurringScheduled']);
    }

    // ------------------------------------------------------------ scheduling

    /** Buyer has not confirmed receipt; complete the order for them. */
    public static function scheduleAutoComplete(int $orderId): void
    {
        $days = (int) get_option('mk_auto_complete_days', 7);

        as_schedule_single_action(
            time() + $days * DAY_IN_SECONDS,
            self::AUTO_COMPLETE,
            ['order_id' => $orderId],
            self::GROUP
        );
    }

    public static function cancelAutoComplete(int $orderId): void
    {
        as_unschedule_all_actions(self::AUTO_COMPLETE, ['order_id' => $orderId], self::GROUP);
    }

    /**
     * Hold the creator's money, then send it.
     *
     * The hold exists because a card chargeback can arrive up to roughly 120
     * days after the charge, while our own flow would otherwise release funds
     * about a week after shipping. It does not close that window — nothing
     * could — but it covers the period in which most disputes actually surface.
     */
    public static function scheduleTransfer(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $days = self::holdDaysFor($order);
        $due  = time() + $days * DAY_IN_SECONDS;

        $order->update_meta_data('_mk_payout_hold_days', $days);
        $order->update_meta_data('_mk_transfer_due_at', gmdate('Y-m-d H:i:s', $due));
        $order->update_meta_data(TransferService::META_STATUS, TransferService::STATUS_PENDING);
        $order->save();

        as_schedule_single_action(
            $due,
            self::EXECUTE_TRANSFER,
            ['order_id' => $orderId],
            self::GROUP
        );
    }

    public static function cancelTransfer(int $orderId): void
    {
        as_unschedule_all_actions(self::EXECUTE_TRANSFER, ['order_id' => $orderId], self::GROUP);
    }

    /**
     * How long to hold this creator's money.
     *
     * Longer for the cases where a loss is least recoverable: a creator with
     * no track record who could take one payout and never return, and any
     * single order big enough to be worth doing that for. Everyone else gets
     * the standard hold, because a creator with a history is the one you least
     * want to inconvenience.
     *
     * The two risks were one setting until the client chose to price them
     * separately -- 10 days for a new creator, 14 for a large order -- on the
     * grounds that the money at stake, not the newness, is what justifies the
     * longest wait.
     *
     * They are not mutually exclusive, so the longest applicable hold wins
     * rather than the first one matched. A new creator's first sale being a
     * large one is the single riskiest combination in the system, and it must
     * not come out shorter than either rule alone would give it.
     */
    private static function holdDaysFor(WC_Order $order): int
    {
        $threshold = (int) get_option('mk_new_creator_threshold', 3);
        $highValue = (int) get_option('mk_high_value_threshold', 50_000);

        $creatorId = (int) $order->get_meta('_mk_creator_id');
        $sales     = (int) get_user_meta($creatorId, 'mk_completed_sales_count', true);
        $total     = (int) $order->get_meta('_mk_product_amount')
                   + (int) $order->get_meta('_mk_option_amount');

        $days = (int) get_option('mk_payout_hold_days', 7);

        if ($sales < $threshold) {
            $days = max($days, (int) get_option('mk_payout_hold_days_new', 10));
        }

        if ($total > $highValue) {
            $days = max($days, (int) get_option('mk_payout_hold_days_high', 14));
        }

        return $days;
    }

    /**
     * Check, at the promised dispatch date, whether the parcel went out.
     *
     * The timestamp is passed in rather than computed here: the deadline is
     * the one snapshotted on the order at purchase, and recomputing it from
     * options would quietly disagree with the date the buyer was shown.
     */
    public static function scheduleDispatchOverdue(int $orderId, int $dueAt): void
    {
        self::cancelDispatchOverdue($orderId);

        as_schedule_single_action(
            $dueAt,
            self::DISPATCH_OVERDUE,
            ['order_id' => $orderId],
            self::GROUP
        );
    }

    public static function cancelDispatchOverdue(int $orderId): void
    {
        as_unschedule_all_actions(self::DISPATCH_OVERDUE, ['order_id' => $orderId], self::GROUP);
    }

    /** Hide the buyer's address from the creator once the sale is history. */
    public static function scheduleAddressMask(int $orderId): void
    {
        // Scheduled at 受取確認 and again at completed; the first one stands.
        if (as_has_scheduled_action(self::MASK_ADDRESS, ['order_id' => $orderId], self::GROUP)) {
            return;
        }

        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order && $order->get_meta('_mk_shipping_masked') === 'yes') {
            return;
        }

        $days = (int) get_option('mk_shipping_mask_days', 30);

        as_schedule_single_action(
            time() + $days * DAY_IN_SECONDS,
            self::MASK_ADDRESS,
            ['order_id' => $orderId],
            self::GROUP
        );
    }

    public static function ensureRecurringScheduled(): void
    {
        if (!as_has_scheduled_action(self::DAILY_DIGEST, [], self::GROUP)) {
            // 09:00 Japan time. wp_timezone() is used rather than a fixed
            // offset so this stays correct if the site timezone is changed.
            $next = new \DateTimeImmutable('tomorrow 09:00', wp_timezone());

            as_schedule_recurring_action(
                $next->getTimestamp(),
                DAY_IN_SECONDS,
                self::DAILY_DIGEST,
                [],
                self::GROUP
            );
        }

        if (!as_has_scheduled_action(self::SWEEP_RESERVATIONS, [], self::GROUP)) {
            as_schedule_recurring_action(
                time() + 5 * MINUTE_IN_SECONDS,
                15 * MINUTE_IN_SECONDS,
                self::SWEEP_RESERVATIONS,
                [],
                self::GROUP
            );
        }
    }

    // -------------------------------------------------------------- handlers

    public static function runAutoComplete(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        // Only from shipped. A buyer who confirmed early, or a cancellation,
        // has already moved the order on.
        if ($order->get_status() !== Statuses::SHIPPED) {
            return;
        }

        if ($order->get_meta('_mk_has_open_report') === 'yes') {
            $order->add_order_note('通報対応中のため自動完了を見送りました。');
            $order->save();

            return;
        }

        $order->update_status(
            Statuses::RECEIVED,
            sprintf('発送から%d日が経過したため自動的に受取確認としました。',
                (int) get_option('mk_auto_complete_days', 7))
        );
    }

    public static function runTransfer(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        // Throwing here is deliberate: Action Scheduler records the failure
        // and retries, and the job shows as failed in the admin rather than
        // disappearing. A silently swallowed transfer failure is a creator
        // who never gets paid and nobody finding out.
        (new TransferService())->execute($order);

        // Reload: execute() writes its own meta and saved the order.
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        // Withheld pending a report. The order stays at 受取確認 until an
        // operator resolves it, which is the whole point of withholding.
        if ($order->get_meta(TransferService::META_STATUS) === TransferService::STATUS_SKIPPED) {
            return;
        }

        // Counted once, on the order, not by re-deriving it. Action Scheduler
        // retries a job whose later steps failed, and execute() is idempotent
        // -- so without this a retry would re-count the sale and could push a
        // creator over the "established" threshold on a single transaction.
        if ($order->get_meta(self::META_SALE_COUNTED) !== 'yes') {
            $creatorId = (int) $order->get_meta('_mk_creator_id');
            $count     = (int) get_user_meta($creatorId, 'mk_completed_sales_count', true);

            update_user_meta($creatorId, 'mk_completed_sales_count', $count + 1);

            $order->update_meta_data(self::META_SALE_COUNTED, 'yes');
            $order->save();
        }

        /*
         * Close the transaction.
         *
         * The state machine documents "received -> completed: transfer
         * executed", and nothing performed that edge. The order sat at 受取確認
         * forever, which looked merely untidy and was not: onCompleted() is
         * what schedules the buyer's address to be masked, so the privacy
         * measure promised to the client silently never ran, and the creator
         * kept the buyer's address indefinitely.
         *
         * Found by running one real transaction end to end. Every individual
         * piece had passed its own test.
         */
        if ($order->get_status() !== 'completed') {
            $order->update_status('completed', 'クリエイターへの送金が完了しました。');
        }
    }

    /**
     * Reclaim items held by checkouts that were abandoned.
     *
     * Runs every 15 minutes against a 20-minute hold, so a genuine checkout
     * is never interrupted while a closed tab frees the item within roughly
     * half an hour.
     */
    public static function runSweepReservations(): void
    {
        $released = (new \MK\Product\Reservation())->sweepExpired();

        if ($released > 0) {
            error_log(sprintf('[mk-marketplace] released %d expired reservations', $released));
        }
    }

    /**
     * The promised dispatch date has arrived.
     *
     * The decision of whether anything is actually late, and what follows from
     * it, belongs with the deadline itself -- this is only the alarm clock.
     */
    public static function runDispatchOverdue(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        \MK\Order\DispatchDeadline::markOverdue($order);
    }

    public static function runMaskAddress(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $order->update_meta_data('_mk_shipping_masked', 'yes');
        $order->save();
    }
}
