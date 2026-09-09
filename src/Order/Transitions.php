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
        // WooCommerce strips the wc- prefix in this hook but our constants
        // carry it, so normalise both sides rather than comparing mixed forms.
        $to = self::bare($to);

        match ($to) {
            self::bare(Statuses::SHIPPED)  => self::onShipped($order),
            self::bare(Statuses::RECEIVED) => self::onReceived($order),
            'completed'                    => self::onCompleted($order),
            'cancelled'                    => self::onCancelled($order),
            'refunded'                     => self::onRefunded($order),
            default                        => null,
        };
    }

    private static function onShipped(WC_Order $order): void
    {
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

        $productId = (int) $order->get_meta('_mk_product_id');

        if ($productId > 0) {
            (new Reservation())->release($productId);
        }

        $order->add_order_note('取引がキャンセルされました。送金予定を取り消し、商品を再出品しました。');
        $order->save();
    }

    private static function onRefunded(WC_Order $order): void
    {
        Jobs::cancelAutoComplete($order->get_id());
        Jobs::cancelTransfer($order->get_id());

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

    private static function bare(string $status): string
    {
        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }
}
