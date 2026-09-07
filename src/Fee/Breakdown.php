<?php
declare(strict_types=1);

namespace MK\Fee;

/**
 * The immutable result of a fee calculation, in whole yen.
 *
 * Every field here is written to order meta at purchase time and never
 * recalculated. See Calculator for why.
 */
final class Breakdown
{
    public function __construct(
        public readonly int $productAmount,
        public readonly int $optionAmount,
        public readonly int $total,
        public readonly float $productRate,
        public readonly float $optionRate,
        public readonly int $productFee,
        public readonly int $optionFee,
        public readonly int $platformFee,
        public readonly int $creatorAmount,
    ) {
    }

    /**
     * Shape written to WooCommerce order meta.
     *
     * Keys mirror the `_mk_*` names in the development direction document.
     *
     * @return array<string, int|float>
     */
    public function toOrderMeta(): array
    {
        return [
            '_mk_product_amount'           => $this->productAmount,
            '_mk_option_amount'            => $this->optionAmount,
            '_mk_fee_rate_snapshot'        => $this->productRate,
            '_mk_option_fee_rate_snapshot' => $this->optionRate,
            '_mk_platform_fee'             => $this->platformFee,
            '_mk_creator_amount'           => $this->creatorAmount,
        ];
    }
}
