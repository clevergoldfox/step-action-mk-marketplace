<?php
declare(strict_types=1);

namespace MK\Tests;

use InvalidArgumentException;
use MK\Creator\Numbering;
use MK\Fee\Calculator;
use MK\Support\Money;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure-logic tests. No WordPress bootstrap required.
 *
 * These cover the three things that are expensive to get wrong and invisible
 * when they are: the yen conversion, the fee split, and creator numbering.
 */
final class MoneyAndFeeTest extends TestCase
{
    // ---------------------------------------------------------------- money

    /**
     * The single most costly possible bug in this codebase.
     *
     * JPY is zero-decimal. Any `* 100` inherited from a USD example charges
     * one hundred times too much, and test mode will not make that obvious.
     */
    public function testYenIsNeverMultiplied(): void
    {
        self::assertSame(1000, Money::toStripeAmount(1000));
        self::assertSame(10_000, Money::toStripeAmount(10_000));
        self::assertSame(1_000_000, Money::toStripeAmount(1_000_000));
    }

    public function testRateRoundsDownSoTheCreatorNeverLoses(): void
    {
        // 999 * 0.16 = 159.84 -> the fractional yen falls to the creator.
        self::assertSame(159, Money::applyRate(999, 0.16));
        self::assertSame(1600, Money::applyRate(10_000, 0.16));
        self::assertSame(800, Money::applyRate(2000, 0.40));
    }

    public function testPriceBoundsMatchPayPayLimits(): void
    {
        Money::assertValidPrice(50);
        Money::assertValidPrice(1_000_000);

        $this->expectException(InvalidArgumentException::class);
        Money::assertValidPrice(49);
    }

    public function testPriceAbovePayPayCeilingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::assertValidPrice(1_000_001);
    }

    // ------------------------------------------------------------------ fee

    /**
     * The worked example agreed with the client:
     * ¥10,000 product + ¥2,000 options, 16% and 40%.
     */
    public function testAgreedWorkedExample(): void
    {
        $result = (new Calculator(0.16, 0.40))->calculate(10_000, 2000);

        self::assertSame(12_000, $result->total);
        self::assertSame(1600, $result->productFee);
        self::assertSame(800, $result->optionFee);
        self::assertSame(2400, $result->platformFee);
        self::assertSame(9600, $result->creatorAmount);
    }

    /**
     * The halves must always reconstitute the total exactly.
     *
     * Rounding both sides independently would drift by a yen on some inputs,
     * and that drift only surfaces months later as a balance that will not
     * reconcile against Stripe.
     */
    public function testSplitAlwaysSumsToTheTotal(): void
    {
        $calculator = new Calculator(0.16, 0.40);

        foreach ([1, 50, 333, 999, 1001, 12_345, 999_999] as $product) {
            foreach ([0, 1, 777, 5555] as $option) {
                $r = $calculator->calculate($product, $option);

                self::assertSame(
                    $r->total,
                    $r->platformFee + $r->creatorAmount,
                    "drift at product={$product} option={$option}"
                );
            }
        }
    }

    public function testOptionsAreChargedAtTheHigherRate(): void
    {
        $r = (new Calculator(0.16, 0.40))->calculate(0, 1000);

        self::assertSame(400, $r->platformFee);
        self::assertSame(600, $r->creatorAmount);
    }

    public function testSnapshotRatesAreUsedNotCurrentSettings(): void
    {
        // An order taken at 16% must still pay out at 16% after the admin
        // raises the live rate to 20%.
        $atPurchase = Calculator::fromSnapshot(0.16, 0.40);

        self::assertSame(1600, $atPurchase->calculate(10_000, 0)->platformFee);
    }

    // ---------------------------------------------------------- outstanding

    public function testOutstandingIsDeductedFromTheNextTransfer(): void
    {
        $r = (new Calculator(0.16, 0.40))->applyOutstanding(9600, 360);

        self::assertSame(9240, $r['transfer']);
        self::assertSame(360, $r['recovered']);
        self::assertSame(0, $r['remaining']);
    }

    /**
     * A debt larger than the sale must not produce a negative transfer.
     * Stripe would reject it; the remainder simply carries forward.
     */
    public function testTransferNeverGoesNegative(): void
    {
        $r = (new Calculator(0.16, 0.40))->applyOutstanding(1000, 2500);

        self::assertSame(0, $r['transfer']);
        self::assertSame(1000, $r['recovered']);
        self::assertSame(1500, $r['remaining']);
    }

    // ------------------------------------------------------------ numbering

    public function testZeroPaddingAtEveryBoundary(): void
    {
        $n = new Numbering();

        self::assertSame('A00001', $n->format(1));
        self::assertSame('A00009', $n->format(9));
        self::assertSame('A00010', $n->format(10));
        self::assertSame('A00099', $n->format(99));
        self::assertSame('A00100', $n->format(100));
        self::assertSame('A00999', $n->format(999));
        self::assertSame('A01000', $n->format(1000));
        self::assertSame('A09999', $n->format(9999));
        self::assertSame('A10000', $n->format(10_000));
        self::assertSame('A99999', $n->format(99_999));
    }

    public function testCarriesToTheNextLetterAndSkipsIAndO(): void
    {
        $n = new Numbering();

        self::assertSame('B00001', $n->format(100_000));

        // Index 7 is H, so index 8 must be J — I is skipped.
        self::assertSame('J00001', $n->format(8 * 99_999 + 1));

        // Index 12 is N, so index 13 must be P — O is skipped.
        self::assertSame('N00001', $n->format(12 * 99_999 + 1));
        self::assertSame('P00001', $n->format(13 * 99_999 + 1));

        // The last letter, at the top of its block.
        self::assertSame('Z99999', $n->format(24 * 99_999));
    }

    public function testRangeExhaustionFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        (new Numbering())->format(24 * 99_999 + 1);
    }

    public function testSearchRecognisesCreatorNumbers(): void
    {
        self::assertTrue(Numbering::isCreatorNumber('A00001'));
        self::assertTrue(Numbering::isCreatorNumber('Z99999'));

        // Excluded letters must not be mistaken for creator numbers.
        self::assertFalse(Numbering::isCreatorNumber('I00001'));
        self::assertFalse(Numbering::isCreatorNumber('O00001'));

        self::assertFalse(Numbering::isCreatorNumber('A0001'));
        self::assertFalse(Numbering::isCreatorNumber('AA0001'));
        self::assertFalse(Numbering::isCreatorNumber('shirt'));
    }

    public function testSearchInputIsNormalisedBeforeMatching(): void
    {
        self::assertSame('A00001', Numbering::normalise('  a00001 '));
        self::assertTrue(Numbering::isCreatorNumber(Numbering::normalise('a00001')));
    }
}
