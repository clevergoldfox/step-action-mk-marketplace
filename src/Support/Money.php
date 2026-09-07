<?php
declare(strict_types=1);

namespace MK\Support;

use InvalidArgumentException;

/**
 * JPY money handling.
 *
 * Japanese yen is a ZERO-DECIMAL currency. Stripe expects the amount in yen,
 * not in some hundredth subunit. A `* 100` copied from a USD tutorial produces
 * a charge one hundred times too large, and test mode will not make that
 * obvious. Every amount in this codebase passes through here so that mistake
 * has exactly one place it could live, and that place is covered by tests.
 *
 * @see https://docs.stripe.com/currencies#zero-decimal
 */
final class Money
{
    /** PayPay's floor, and a sane minimum for the marketplace. */
    public const MIN_YEN = 50;

    /** PayPay's ceiling. Product prices are capped here. */
    public const MAX_YEN = 1_000_000;

    /**
     * Convert an internal yen amount to the integer Stripe expects.
     *
     * This is deliberately an identity function. It exists so that the absence
     * of a conversion is explicit and testable rather than implied by silence.
     */
    public static function toStripeAmount(int $yen): int
    {
        self::assertNonNegative($yen);

        return $yen;
    }

    /**
     * Validate a price a creator is trying to set on a listing.
     *
     * @throws InvalidArgumentException when outside the permitted range.
     */
    public static function assertValidPrice(int $yen): void
    {
        if ($yen < self::MIN_YEN || $yen > self::MAX_YEN) {
            throw new InvalidArgumentException(
                sprintf(
                    'Price must be between %d and %d JPY, got %d.',
                    self::MIN_YEN,
                    self::MAX_YEN,
                    $yen
                )
            );
        }
    }

    /**
     * Apply a rate to an amount, rounding DOWN to whole yen.
     *
     * Rounding down on the platform's share means any fractional yen falls to
     * the creator. That is the safe direction: a creator who receives one yen
     * more than expected never files a complaint, and the platform is never
     * short when reconciling against Stripe.
     *
     * The rate is converted to integer basis points before multiplying, so the
     * arithmetic is exact. Doing this in floating point silently loses a yen
     * on some inputs -- floor(12000 * 0.036) is 431, not 432, because the
     * product is 431.99999999999994 in IEEE754. Our own 16% and 40% happen to
     * be safe, but the rate is admin-editable, so "happens to be safe" is not
     * a property worth depending on in code that moves money.
     *
     * Bounds: 1,000,000 yen * 10,000 bp = 10^10, comfortably inside a 64-bit
     * integer.
     */
    public static function applyRate(int $yen, float $rate): int
    {
        self::assertNonNegative($yen);

        if ($rate < 0.0 || $rate > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Rate must be between 0 and 1, got %F.', $rate)
            );
        }

        $basisPoints = (int) round($rate * 10_000);

        return intdiv($yen * $basisPoints, 10_000);
    }

    public static function format(int $yen): string
    {
        return '¥' . number_format($yen);
    }

    private static function assertNonNegative(int $yen): void
    {
        if ($yen < 0) {
            throw new InvalidArgumentException(
                sprintf('Amount cannot be negative, got %d.', $yen)
            );
        }
    }
}
