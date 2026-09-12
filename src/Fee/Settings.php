<?php
declare(strict_types=1);

namespace MK\Fee;

use RuntimeException;

/**
 * The live commission rates, and the record of every change to them.
 *
 * Changing a rate is a change to what the platform will earn on FUTURE
 * orders and nothing else. Orders already placed keep the rates snapshotted
 * onto them at purchase -- see Calculator -- so a creator who was told they
 * would receive a certain amount still receives it, whatever is changed here
 * afterwards.
 *
 * Every change is written to mk_fee_rate_history. The first time a creator
 * disputes the commission on an old order, "what was the rate on 3 March, and
 * who changed it" is the only question that matters, and an options table
 * cannot answer it.
 */
final class Settings
{
    public const OPTION_PRODUCT = 'mk_fee_rate';
    public const OPTION_OPTION  = 'mk_option_fee_rate';

    /** Above this, a rate is almost certainly a typo (e.g. 40 meant as 0.40). */
    private const MAX_RATE = 0.90;

    public static function productRate(): float
    {
        return (float) get_option(self::OPTION_PRODUCT, 0.14);
    }

    public static function optionRate(): float
    {
        return (float) get_option(self::OPTION_OPTION, 0.40);
    }

    /**
     * Store new rates, as fractions (0.14 = 14%).
     *
     * @param int $changedBy WordPress user id, or 0 for a change made on the
     *                       client's written instruction rather than in the UI.
     */
    public static function update(float $productRate, float $optionRate, int $changedBy): void
    {
        $productRate = self::validate($productRate, '商品の手数料率');
        $optionRate  = self::validate($optionRate, 'オプションの手数料率');

        $oldProduct = self::productRate();
        $oldOption  = self::optionRate();

        if (abs($oldProduct - $productRate) < 0.00001 && abs($oldOption - $optionRate) < 0.00001) {
            return;   // nothing changed; do not write a history row saying so
        }

        update_option(self::OPTION_PRODUCT, $productRate);
        update_option(self::OPTION_OPTION, $optionRate);

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'mk_fee_rate_history',
            [
                'changed_by'       => $changedBy,
                'old_product_rate' => $oldProduct,
                'new_product_rate' => $productRate,
                'old_option_rate'  => $oldOption,
                'new_option_rate'  => $optionRate,
                'changed_at'       => current_time('mysql', true),
            ],
            ['%d', '%f', '%f', '%f', '%f', '%s']
        );
    }

    /** @return array<int, object> newest first */
    public static function history(int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mk_fee_rate_history
                  ORDER BY changed_at DESC, id DESC LIMIT %d",
                $limit
            )
        ) ?: [];
    }

    /**
     * A rate typed as a percentage in the admin form, as a fraction.
     *
     * Accepts "14", "14.5", "１４" (full-width, which a Japanese keyboard
     * produces without the user noticing).
     */
    public static function fromPercent(string $input): float
    {
        $normalised = mb_convert_kana(trim($input), 'n', 'UTF-8');

        if (!is_numeric($normalised)) {
            throw new RuntimeException('手数料率は数字で入力してください。');
        }

        return round(((float) $normalised) / 100, 4);
    }

    private static function validate(float $rate, string $label): float
    {
        if (!is_finite($rate) || $rate < 0) {
            throw new RuntimeException($label . 'が正しくありません。');
        }

        if ($rate > self::MAX_RATE) {
            throw new RuntimeException(sprintf(
                '%sが高すぎます（%d%% 以下で入力してください）。',
                $label,
                (int) (self::MAX_RATE * 100)
            ));
        }

        return round($rate, 4);
    }
}
