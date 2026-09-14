<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Product\Reservation;
use MK\Schedule\Jobs;
use MK\Stripe\TransferService;
use WC_Order;

/**
 * The single place where a change of order status has money consequences.
 *
 * Every scheduled job in this system is created or cancelled here. Scattering
 * as_schedule_* calls across controllers and admin screens is how a transfer
 * ends up scheduled twice, or cancelled by one path and left running by
 * another. One hook, one switch, one place to read when a payout misbehaves.
 *
 *   pending   -> paid        schedule the dispatch deadline (+1-7d, promised)
 *   paid      -> shipped     schedule auto-complete   (+7d)
 *   shipped   -> received    schedule transfer        (+7d or +14d)
 *   received  -> completed   schedule address masking (+30d)
 *   any       -> cancelled   cancel everything, release the item
 *   any       -> refunded    cancel everything; recovery is manual
 */
final class Transitions
{
    public static function register(): void
    {
        add_action('woocommerce_order_status_changed', [self::class, 'handle'], 10, 4);
    }

    public static function handle(int $orderId, string $from, string $to, WC_Order $order): void
    {
        // This hook passes the bare status, which is the form the constants
        // are already in. Normalising anyway costs nothing and keeps the
        // match correct if a caller ever passes the wc- spelling through.
        match (Statuses::bare($to)) {
            Statuses::PAID     => self::onPaid($order),
            Statuses::SHIPPED  => self::onShipped($order),
            Statuses::RECEIVED => self::onReceived($order),
            'completed'        => self::onCompleted($order),
            'cancelled'        => self::onCancelled($order),
            'refunded'         => self::onRefunded($order),
            default            => null,
        };
    }

    /**
     * Money captured, so the creator's clock starts.
     *
     * The obligation to post the item begins at payment, not at checkout: an
     * order that never completed payment asks nothing of the creator.
     */
    private static function onPaid(WC_Order $order): void
    {
        DispatchDeadline::start($order);

        $order->add_order_note(sprintf(
            'お支払いを確認しました。出品時の発送目安（%s）に基づき、発送期限を %s に設定しました。',
            \MK\Product\Details::dispatchLabel((string) $order->get_meta(DispatchDeadline::META_DISPATCH)),
            DispatchDeadline::dueLabel($order)
        ));
        $order->save();
    }

    private static function onShipped(WC_Order $order): void
    {
        // Posted, on time or late; either way nothing is waiting on the
        // deadline any more.
        Jobs::cancelDispatchOverdue($order->get_id());

        Jobs::scheduleAutoComplete($order->get_id());

        $days = (int) get_option('mk_auto_complete_days', 7);

        $order->add_order_note(sprintf(
            '発送が登録されました。%d日後に自動的に受取確認となります。',
            $days
        ));
        $order->save();
    }

    private static function onReceived(WC_Order $order): void
    {
        // The buyer may have confirmed early. If the auto-complete job is
        // still queued it would fire later against an order that has already
        // moved on -- harmless because runAutoComplete checks the status, but
        // leaving dead jobs in the queue makes the Scheduled Actions screen
        // useless for spotting real problems.
        Jobs::cancelAutoComplete($order->get_id());

        // The creator must not be able to release their own funds, no matter
        // which code path set the status. Dokan's REST bulk-action endpoint
        // lets a vendor set any status on an order they own, and enumerating
        // upstream routes only protects against the ones that exist today --
        // so the refusal lives here, at the money, rather than only at the
        // door. See Guard for the full reasoning.
        if (!Guard::mayReleaseFunds($order)) {
            $order->update_meta_data(Guard::META_BLOCKED, 'yes');
            $order->add_order_note(
                '⚠️ 出品者自身の操作により受取確認となったため、送金を保留しました。'
                . '運営が内容を確認してください。'
            );
            $order->save();

            error_log(sprintf(
                '[mk-marketplace] order %d reached received via its own creator (user %d); transfer withheld',
                $order->get_id(),
                get_current_user_id()
            ));

            return;
        }

        Jobs::scheduleTransfer($order->get_id());

        $due = (string) $order->get_meta('_mk_transfer_due_at');

        $order->add_order_note(sprintf(
            '受取確認が完了しました。%s頃にクリエイターへ送金します。',
            $due !== '' ? get_date_from_gmt($due, 'Y年n月j日') : '保留期間経過後'
        ));
        $order->save();
    }

    private static function onCompleted(WC_Order $order): void
    {
        // Hide the buyer's address from the creator once the sale is history.
        // A creator who keeps shipping addresses indefinitely is a data
        // breach waiting for an excuse.
        Jobs::scheduleAddressMask($order->get_id());
    }

    private static function onCancelled(WC_Order $order): void
    {
        Jobs::cancelAutoComplete($order->get_id());
        Jobs::cancelTransfer($order->get_id());
        Jobs::cancelDispatchOverdue($order->get_id());

        $productId = (int) $order->get_meta('_mk_product_id');

        // A unit is given back once. The sweeper returns the unit itself and
        // then cancels the order, which lands here -- without this check the
        // same unit would be credited twice and the seller would end up with
        // more stock than they ever had.
        $holdsUnit = $order->get_meta(Reservation::META_HOLDS_UNIT) === 'yes';
        $tracked   = $productId > 0 && Reservation::tracksStock($productId);

        if ($productId > 0 && (!$tracked || $holdsUnit)) {
            (new Reservation())->release($productId);

            if ($tracked) {
                $order->update_meta_data(Reservation::META_HOLDS_UNIT, 'no');
            }
        }

        $order->add_order_note('取引がキャンセルされました。送金予定を取り消し、商品を再出品しました。');
        $order->save();
    }

    private static function onRefunded(WC_Order $order): void
    {
        Jobs::cancelAutoComplete($order->get_id());
        Jobs::cancelTransfer($order->get_id());
        Jobs::cancelDispatchOverdue($order->get_id());

        // Deliberately NOT calling refundAndReverse() here.
        //
        // This hook also fires when an admin records a refund inside
        // WooCommerce, which has already moved the money. Reversing from here
        // would double-handle it. Refunds must be initiated through
        // TransferService::refundAndReverse(), which performs the Stripe
        // refund and the transfer reversal as one operation and then lands
        // back here with the work done.
        if ($order->get_meta(TransferService::META_TRANSFER_ID) !== ''
            && $order->get_meta(TransferService::META_STATUS) !== TransferService::STATUS_REVERSED
        ) {
            $order->add_order_note(
                '⚠️ 送金済みですが、巻き戻しが記録されていません。'
                . '管理画面から返金処理を実行してください。'
            );
            $order->save();
        }
    }
}
