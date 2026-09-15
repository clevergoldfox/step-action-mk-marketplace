<?php
declare(strict_types=1);

namespace MK\Stripe;

use MK\Fee\Calculator;
use MK\Ledger\Recorder;
use MK\Support\Money;
use Stripe\Exception\InvalidRequestException;
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
    public const META_REFUND_ID   = '_mk_stripe_refund_id';

    /** How much actually went back to the buyer, in yen. */
    public const META_REFUND_AMOUNT = '_mk_refund_amount';

    /** Who bore the unwind's costs: 'creator', 'buyer' or 'platform'. */
    public const META_COST_BEARER = '_mk_refund_cost_bearer';

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

        // Announced only once the money has actually left, and after the
        // order is saved. A creator told they have been paid by a transfer
        // that then failed to record is worse than one told a moment later.
        do_action('mk_transfer_sent', $order->get_id(), $creatorId, $split['transfer']);

        return $transfer->id;
    }

    /**
     * Refund the buyer AND recover from the creator, as one operation.
     *
     * $additionalCost carries the ¥1,500 dispute fee when this is a chargeback
     * rather than a voluntary refund. It is charged to the creator, per the
     * terms the client agreed.
     *
     * $chargeToCreator says who ends up paying for the unwind, and it is the
     * operator's finding of fact, not something this code can work out. The
     * client's policy is that a refund caused by the seller -- not posted,
     * condition or size described wrongly, a different item, a deliberate
     * misdescription -- is at the seller's cost, and everything else is the
     * platform's. Both cases are still written to the ledger; only one of them
     * moves the seller's balance.
     *
     * ---------------------------------------------------------------------
     * Order of operations
     * ---------------------------------------------------------------------
     * The transfer is pulled back BEFORE the buyer is refunded. The other way
     * round was tried and is wrong: any failure after the refund leaves the
     * buyer repaid, the creator still holding their share, and the platform
     * covering both halves of a sale it never made.
     *
     * That is not hypothetical. This method called a Stripe SDK method that
     * does not exist, so every refund threw -- but only after the refund had
     * already left. One test refund cost the platform the full ¥3,000 on a
     * ¥3,000 order.
     *
     * Reversing first inverts the exposure. If the refund then fails, the
     * platform is holding money it owes the buyer and can simply retry; it is
     * never out of pocket, and nothing has been given away.
     */
    public function refundAndReverse(
        WC_Order $order,
        ?int $refundAmount = null,
        int $additionalCost = 0,
        bool $chargeToCreator = true,
    ): void {
        $reversed = $this->pullBackTransfer($order);

        $refundId = (new PaymentService())->refund($order, $refundAmount);

        $this->recordUnwind($order, $reversed, $refundId, $additionalCost, $chargeToCreator);
    }

    /**
     * Refund a buyer whose own conduct ended the sale, less what refunding costs.
     *
     * The client's rule (2026-09-17): when a cancellation is the buyer's
     * responsibility -- a message video request a creator rightly refused as
     * inappropriate, for instance -- the buyer bears the costs the platform
     * actually incurs and cannot get back. Today that is Stripe's processing
     * fee, which Stripe keeps on a refund. It is read from the charge rather
     * than estimated, so what the buyer is told was deducted is exactly what
     * Stripe kept.
     *
     * Only before payout. After a transfer the creator's share has left the
     * platform, and a buyer-responsible unwind would need its own rules for
     * a reversal the creator's balance cannot cover. Nothing needs that today
     * -- a declined video has never been paid out -- so it is refused rather
     * than guessed at.
     *
     * No ledger entry: the creator is neither charged nor credited, and the
     * platform is not out of pocket. The amounts are kept on the order.
     *
     * @return array{refunded:int, fee:int, refund_id:string}
     * @throws RuntimeException if the creator has already been paid, or the
     *                          costs would consume the whole payment
     */
    public function refundWithBuyerCost(WC_Order $order): array
    {
        if ((string) $order->get_meta(self::META_TRANSFER_ID) !== '') {
            throw new RuntimeException('出品者への送金後は、購入者負担での返金はできません。');
        }

        $total  = (int) $order->get_total();
        $fee    = $this->stripeFeeFor($order);
        $amount = $total - $fee;

        if ($amount <= 0) {
            throw new RuntimeException('返金に伴う費用が代金を上回るため、購入者負担での返金はできません。');
        }

        $refundId = (new PaymentService())->refund($order, $amount);

        $order->update_meta_data(self::META_REFUND_ID, $refundId);
        $order->update_meta_data(self::META_REFUND_AMOUNT, $amount);
        $order->update_meta_data(self::META_COST_BEARER, 'buyer');
        $order->add_order_note(sprintf(
            '返金処理を実行しました（購入者負担）。代金 %s から、返還されない決済手数料 %s を差し引いた %s を返金しました。',
            Money::format($total),
            Money::format($fee),
            Money::format($amount)
        ));
        $order->save();

        return ['refunded' => $amount, 'fee' => $fee, 'refund_id' => $refundId];
    }

    /**
     * Absorb a chargeback. Like a refund, but without refunding.
     *
     * A disputed charge cannot be refunded -- Stripe rejects it outright with
     * "has been charged back; cannot issue a refund", because the card network
     * has already taken the money back. refundAndReverse() therefore throws on
     * every dispute, which left chargebacks with no working unwind path at all
     * despite being the exact case this design exists to survive.
     *
     * So the money movement here is only ever inward: pull back the transfer
     * if one went out, and record what the platform is left holding the bill
     * for.
     *
     * $disputeFee is Stripe's ¥1,500, charged to the creator per the terms the
     * client agreed. Note what is NOT charged to them: the platform's own
     * commission on the lost sale. The creator is billed for the money they
     * would have received and for the fee their transaction caused, and the
     * platform absorbs its own margin rather than profiting from a chargeback.
     * That is a policy choice and the operator may want a different one.
     */
    public function absorbDispute(WC_Order $order, int $disputeFee = 1500): void
    {
        $reversed = $this->pullBackTransfer($order);

        $this->recordUnwind($order, $reversed, null, $disputeFee);

        $order->update_meta_data('_mk_has_open_report', 'yes');
        $order->save();
    }

    /**
     * Claw the transfer back, and report how much actually came back.
     *
     * A reversal draws on the connected account's balance, and that balance
     * may not hold the money any more: Stripe funds settle over days, and the
     * creator may have been paid out to their bank already. Refusing to
     * refund the buyer because of that would be the wrong call -- the buyer
     * is owed their money regardless of where the creator's went -- so a
     * balance failure is recorded and recovery continues through the ledger
     * rather than aborting the refund.
     *
     * A failure that is NOT about balance is re-thrown. Those mean the call
     * itself is wrong, and continuing would refund the buyer on top of a
     * transfer that was never pulled back.
     *
     * @return int the amount reversed, in yen; 0 if nothing came back
     */
    private function pullBackTransfer(WC_Order $order): int
    {
        $transferId = (string) $order->get_meta(self::META_TRANSFER_ID);

        if ($transferId === '') {
            return 0; // never paid out; nothing to pull back
        }

        if ($order->get_meta(self::META_STATUS) === self::STATUS_REVERSED) {
            return (int) $order->get_meta(self::META_REVERSED);
        }

        try {
            // createReversal, not reverseTransfer. The latter does not exist
            // in this SDK and the mistake was invisible until a refund was
            // actually attempted against the live API.
            $reversal = Client::get()->transfers->createReversal($transferId, []);
        } catch (InvalidRequestException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'insufficient')) {
                throw $e;
            }

            $order->add_order_note(sprintf(
                '⚠️ 送金の巻き戻しができませんでした（クリエイター残高不足）。'
                . '返金は実行し、回収できなかった分は未回収額として次回売上から控除します。（%s）',
                $e->getMessage()
            ));
            $order->save();

            return 0;
        }

        $order->update_meta_data(self::META_REVERSED, (int) $reversal->amount);
        $order->update_meta_data(self::META_STATUS, self::STATUS_REVERSED);
        $order->save();

        return (int) $reversal->amount;
    }

    /**
     * How much of the creator's share the platform is out by, after a reversal.
     *
     * Public and separate because it is the one line of this class that can be
     * checked without moving money, and because it is the line that was wrong:
     * a creator who was never paid owes nothing back, however little came back
     * from a reversal that never happened.
     */
    public static function unrecoverableShare(WC_Order $order, int $reversedAmount): int
    {
        if ((string) $order->get_meta(self::META_TRANSFER_ID) === '') {
            return 0;
        }

        return max(0, (int) $order->get_meta('_mk_creator_amount') - $reversedAmount);
    }

    /**
     * Write the unwind to the creator's ledger.
     *
     * The shortfall is everything the platform is out by and did not get back:
     *
     *   - Stripe's processing fee, which it keeps on a refund
     *   - the dispute fee, on a chargeback
     *   - any part of the creator's share that could not be reversed
     *
     * That last term is the one that matters most and was previously missing
     * entirely: the old code assumed a reversal always succeeded in full and
     * charged only the processing fee, so a creator whose balance was empty
     * kept their whole share and the platform silently ate it.
     *
     * It is also the term that has to know whether a transfer ever HAPPENED.
     * "Unrecovered" was computed as the creator's share minus whatever came
     * back, which on an order that was never paid out is the creator's entire
     * share -- money they never received, billed to them as a debt against
     * their next sale. Cancelling an undelivered order before dispatch is the
     * most common unwind there is, and it was charging the creator the full
     * value of a sale they were never paid for. Found on the first real
     * cancellation run through the new 発送期限 flow (¥2,639 against a creator
     * who had received nothing).
     */
    private function recordUnwind(
        WC_Order $order,
        int $reversedAmount,
        ?string $refundId,
        int $additionalCost,
        bool $chargeToCreator = true,
    ): void {
        $creatorId = (int) $order->get_meta('_mk_creator_id');

        if ($reversedAmount > 0) {
            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::REVERSAL,
                $reversedAmount,
                0,
                (string) $order->get_meta(self::META_TRANSFER_ID),
                '返金に伴う送金の巻き戻し'
            );
        }

        $paidOut     = (string) $order->get_meta(self::META_TRANSFER_ID) !== '';
        $unrecovered = self::unrecoverableShare($order, $reversedAmount);

        $shortfall = $this->stripeFeeFor($order) + $additionalCost + $unrecovered;

        if ($shortfall > 0 && $chargeToCreator) {
            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::DEBT_INCURRED,
                $shortfall,
                $shortfall,
                $refundId,
                $additionalCost > 0
                    ? 'チャージバック手数料・決済手数料・回収不能額'
                    : '決済手数料および回収不能額（返金時は返還されないため）'
            );

            // The seller has to be told. A deduction that first appears as a
            // smaller payout weeks later, or an invoice out of nowhere, is
            // indistinguishable from the platform helping itself.
            do_action('mk_creator_charged', $creatorId, $shortfall, $order->get_id());
        } elseif ($shortfall > 0) {
            // Written down even though nobody is billed for it. A cost the
            // platform decided to absorb is still a cost, and the creator's
            // history has to show that this refund happened and did NOT count
            // against them -- otherwise the only record of the decision is in
            // the operator's memory.
            $this->ledger->record(
                $creatorId,
                $order->get_id(),
                Recorder::PLATFORM_ABSORBED,
                $shortfall,
                0,
                $refundId,
                '返金に伴う費用（運営負担）'
            );
        }

        // The refund's own id, on the order. The ledger carried it as a
        // reference, but only when there was a shortfall to record -- so a
        // clean refund left nothing on the order to reconcile against Stripe.
        if ($refundId !== null) {
            $order->update_meta_data(self::META_REFUND_ID, $refundId);
        }

        $reversal = $paidOut
            ? sprintf('巻き戻し %s', Money::format($reversedAmount))
            : '送金前のため巻き戻しはありません';

        $order->add_order_note(sprintf(
            '返金処理を実行しました。%s。%s',
            $reversal,
            $shortfall <= 0
                ? '追加の費用は発生していません'
                : ($chargeToCreator
                    ? sprintf('費用 %s は出品者負担とし、次回売上から差し引きます', Money::format($shortfall))
                    : sprintf('費用 %s は運営負担とし、出品者には請求しません', Money::format($shortfall)))
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
    public function stripeFeeFor(WC_Order $order): int
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
