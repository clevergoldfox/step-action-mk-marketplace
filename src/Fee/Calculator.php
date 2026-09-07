<?php
declare(strict_types=1);

namespace MK\Fee;

use MK\Support\Money;

/**
 * Splits an order between the platform and the creator.
 *
 * Two rates apply, and they are different:
 *
 *   product portion  ->  16.0%   (mk_fee_rate)
 *   option portion   ->  40.0%   (mk_option_fee_rate)
 *
 * Because the rates differ, the order MUST carry the product and option
 * amounts separately. A single `total` cannot be decomposed afterwards, and
 * the first creator to query a payout will ask exactly that question.
 *
 * Both rates are snapshotted onto the order at purchase. An admin changing a
 * rate must never alter the payout of an order already in flight — a creator
 * who saw "you will receive ¥8,400" at listing time has to receive ¥8,400.
 */
final class Calculator
{
    public function __construct(
        private readonly float $productRate,
        private readonly float $optionRate,
    ) {
    }

    /**
     * Build from the live admin settings.
     *
     * Only ever call this at the moment an order is created. Everywhere else,
     * reconstruct from the order's snapshot with {@see fromSnapshot()}.
     */
    public static function fromSettings(): self
    {
        return new self(
            (float) get_option('mk_fee_rate', 0.16),
            (float) get_option('mk_option_fee_rate', 0.40),
        );
    }

    /**
     * Rebuild the calculator that produced an existing order.
     *
     * Used by the transfer, refund and reversal paths so they compute against
     * the rates in force at purchase, not the rates in force today.
     */
    public static function fromSnapshot(float $productRate, float $optionRate): self
    {
        return new self($productRate, $optionRate);
    }

    public function calculate(int $productAmount, int $optionAmount): Breakdown
    {
        $productFee = Money::applyRate($productAmount, $this->productRate);
        $optionFee  = Money::applyRate($optionAmount, $this->optionRate);

        $total       = $productAmount + $optionAmount;
        $platformFee = $productFee + $optionFee;

        // Derive the creator's share by subtraction rather than by applying
        // (1 - rate). Subtraction guarantees the two halves sum to the total
        // exactly; independent rounding of both halves does not, and the
        // resulting one-yen drift would surface as an unreconcilable balance
        // against Stripe months later.
        $creatorAmount = $total - $platformFee;

        return new Breakdown(
            productAmount: $productAmount,
            optionAmount: $optionAmount,
            total: $total,
            productRate: $this->productRate,
            optionRate: $this->optionRate,
            productFee: $productFee,
            optionFee: $optionFee,
            platformFee: $platformFee,
            creatorAmount: $creatorAmount,
        );
    }

    /**
     * The amount to actually transfer, after deducting anything the creator
     * still owes the platform from a previous refund or chargeback.
     *
     * Stripe cannot reverse more than the original transfer, so shortfalls
     * (its own processing fee, the ¥1,500 dispute fee) are carried on the
     * creator's account and recovered here.
     *
     * Never returns a negative transfer. Any remainder stays outstanding and
     * is recovered from the sale after that.
     *
     * @return array{transfer:int, recovered:int, remaining:int}
     */
    public function applyOutstanding(int $creatorAmount, int $outstanding): array
    {
        $recovered = min($creatorAmount, max(0, $outstanding));

        return [
            'transfer'  => $creatorAmount - $recovered,
            'recovered' => $recovered,
            'remaining' => max(0, $outstanding) - $recovered,
        ];
    }
}
