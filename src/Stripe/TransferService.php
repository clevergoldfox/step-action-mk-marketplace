<?php
declare(strict_types=1);

namespace MK\Stripe;

use MK\Fee\Calculator;
use MK\Ledger\Recorder;
use MK\Support\Money;
use RuntimeException;
use WC_Order;

/**
 * Moves the creator's share out of the platform balance, and pulls it back
 * when a sale unwinds.
 *
 * ---------------------------------------------------------------------------
 * The rule that this class exists to enforce
 * ---------------------------------------------------------------------------
 * Refunding a charge does NOT reverse a transfer. Stripe treats the two as
 * independent operations. Refund without reversing and the creator keeps the
 * money while the platform absorbs the entire refund — the single most common
 * way marketplaces lose money, and it fails silently.
 *
 * Every unwind therefore goes through refundAndReverse(), which does both or
 * neither.
 */
final class TransferService
{
    public const META_TRANSFER_ID = '_mk_stripe_transfer_id';
    public const META_STATUS      = '_mk_transfer_status';
    public const META_REVERSED    = '_mk_reversed_amount';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_SENT     = 'sent';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_SKIPPED  = 'skipped';

    public function __construct(
        private readonly Recorder $ledger = new Recorder(),
    ) {
    }

    /**
     * Pay the creator for a completed order.
     *
     * Safe to call more than once: Action Scheduler retries on failure, and a
     * webhook may arrive twice. A second call after a successful transfer is a
     * no-op rather than a duplicate payment.
     */
    public function execute(WC_Order $order): ?string
    {
        if ($order->get_meta(self::META_TRANSFER_ID) !== '') {
            return null; // already paid
        }

        // A report opened between scheduling and execution must stop the
        // money. Once it has left the platform balance, recovery depends on
        // the creator's balance still holding enough to reverse.
        if ($order->get_meta('_mk_has_open_report') === 'yes') {
            $order->update_meta_data(self::META_STATUS, self::STATUS_SKIPPED);
            $order->add_order_note('通報対応中のため送金を保留しました。');
            $order->save();

            return null;
        }

        $creatorId = (int) $order->get_meta('_mk_creator_id');
        $accountId = (string) get_user_meta($creatorId, AccountService::META_ACCOUNT_ID, true);

        if ($accountId === '') {
            throw new RuntimeException(
                sprintf('Order %d has no connected account to pay.', $order->get_id())
            );
        }

        // Recompute from the rates frozen at purchase, never from the live
        // settings. An admin changing the fee rate must not alter what this
        // sale pays out.
        $breakdown = Calculator::fromSnapshot(
            (float) $order->get_meta('_mk_fee_rate_snapshot'),
            (float) $order->get_meta('_mk_option_fee_rate_snapshot')
        )->calculate(
            (int) $order->get_meta('_mk_product_amount'),
            (int) $order->get_meta('_mk_option_amount')
        );

        $outstanding = $this->ledger->outstanding($creatorId);

        $split = Calculator::fromSnapshot(0.0, 0.0)
            ->applyOutstanding($breakdown->creatorAmount, $outstanding);

        if ($split['transfer'] <= 0) {
            // The whole payout went to clearing debt. Nothing to send, but the
            // recovery still has to be recorded or it would be recovered twice.
            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::DEBT_RECOVERED,
                $split['recovered'],
                -$split['recovered'],
                null,
                '売上全額を未回収額の返済に充当'
            );

            $order->update_meta_data(self::META_STATUS, self::STATUS_SKIPPED);
            $order->save();

            return null;
        }

        $transfer = Client::get()->transfers->create([
            'amount'      => Money::toStripeAmount($split['transfer']),
            'currency'    => 'jpy',
            'destination' => $accountId,

            // Ties the transfer to the originating charge. Stripe then allows
            // it even before the charge has settled, and holds the funds on
            // the creator's side until it does.
            'source_transaction' => (string) $order->get_meta(PaymentService::META_CHARGE_ID),

            'transfer_group' => 'ORDER_' . $order->get_id(),
            'metadata'       => ['order_id' => (string) $order->get_id()],
        ]);

        if ($split['recovered'] > 0) {
            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::DEBT_RECOVERED,
                $split['recovered'],
                -$split['recovered'],
                $transfer->id,
                '過去の返金等による未回収額を控除'
            );
        }

        $this->ledger->record(
            $creatorId,
            $order->get_id(),
            Recorder::TRANSFER,
            $split['transfer'],
            0,
            $transfer->id,
            sprintf('売上送金（手数料 %s 控除後）', Money::format($breakdown->platformFee))
        );

        $order->update_meta_data(self::META_TRANSFER_ID, $transfer->id);
        $order->update_meta_data(self::META_STATUS, self::STATUS_SENT);
        $order->add_order_note(
            sprintf('クリエイターへ %s を送金しました。', Money::format($split['transfer']))
        );
        $order->save();

        return $transfer->id;
    }

    /**
     * Refund the buyer AND recover from the creator, as one operation.
     *
     * $additionalCost carries the ¥1,500 dispute fee when this is a chargeback
     * rather than a voluntary refund. It is charged to the creator, per the
     * terms the client agreed.
     */
    public function refundAndReverse(
        WC_Order $order,
        ?int $refundAmount = null,
        int $additionalCost = 0,
    ): void {
        $payments = new PaymentService();
        $refundId = $payments->refund($order, $refundAmount);

        $this->reverse($order, $refundId, $additionalCost);
    }

    /**
     * Pull a transfer back and charge the shortfall to the creator.
     *
     * Stripe keeps its processing fee on a refund, so even a full reversal
     * leaves the platform short by that amount. That shortfall becomes the
     * creator's outstanding balance and is deducted from their next payout.
     */
    public function reverse(WC_Order $order, ?string $refundId = null, int $additionalCost = 0): void
    {
        $creatorId  = (int) $order->get_meta('_mk_creator_id');
        $transferId = (string) $order->get_meta(self::META_TRANSFER_ID);

        $shortfall = $this->stripeFeeFor($order) + $additionalCost;

        if ($transferId !== '') {
            $reversal = Client::get()->transfers->reverseTransfer($transferId, []);

            $order->update_meta_data(self::META_REVERSED, (int) $reversal->amount);
            $order->update_meta_data(self::META_STATUS, self::STATUS_REVERSED);

            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::REVERSAL,
                (int) $reversal->amount,
                0,
                $reversal->id,
                '返金に伴う送金の巻き戻し'
            );
        }

        if ($shortfall > 0) {
            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::DEBT_INCURRED,
                $shortfall,
                $shortfall,
                $refundId,
                $additionalCost > 0
                    ? 'チャージバック手数料および決済手数料（返金時は返還されないため）'
                    : '決済手数料（返金時は返還されないため）'
            );
        }

        $order->add_order_note(sprintf(
            '返金処理を実行しました。未回収額 %s をクリエイターの次回売上から控除します。',
            Money::format($shortfall)
        ));
        $order->save();
    }

    /**
     * The processing fee Stripe actually kept on this charge.
     *
     * Read from the balance transaction rather than estimated from a rate:
     * the effective rate varies by card brand and by payment method (3.6% for
     * domestic cards, 3.98% for PayPay), and an estimate that is a few yen
     * out becomes an account that will not reconcile.
     */
    private function stripeFeeFor(WC_Order $order): int
    {
        $chargeId = (string) $order->get_meta(PaymentService::META_CHARGE_ID);

        if ($chargeId === '') {
            return 0;
        }

        $charge = Client::get()->charges->retrieve(
            $chargeId,
            ['expand' => ['balance_transaction']]
        );

        return (int) ($charge->balance_transaction->fee ?? 0);
    }
}
