<?php
declare(strict_types=1);

namespace MK\Option;

use MK\Support\Money;
use RuntimeException;

/**
 * Per-creator option pricing.
 *
 * The split of responsibility is the whole point of the design, and it is why
 * an option group carries no price of its own:
 *
 *   the platform decides WHAT may be offered   (mk_option_groups)
 *   the creator decides WHAT IT COSTS          (mk_product_options)
 *
 * A shared price list would be wrong in both directions. Gift wrapping is
 * worth more from someone who hand-letters it than from someone who tapes a
 * bow on, and a marketplace that fixes the price for them either underpays
 * the first or overcharges for the second. Equally, letting creators invent
 * their own option NAMES would leave buyers comparing "ラッピング" against
 * "ギフト包装" against "プレゼント用" and unable to filter on any of them.
 *
 * Options carry their own commission rate (mk_option_fee_rate, 40%), well
 * above the 16% on the item itself. Nothing here needs to know that -- the
 * amount is passed to Fee\Calculator, which applies both rates and snapshots
 * them onto the order at purchase -- but it is why an option's price is worth
 * getting right rather than treating as a rounding error.
 */
final class Service
{
    // --------------------------------------------------------------- groups

    /** @return array<int, object> active groups, in display order */
    public function activeGroups(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mk_option_groups
              WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        ) ?: [];
    }

    /** @return array<int, object> every group, active or not */
    public function allGroups(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mk_option_groups ORDER BY sort_order ASC, id ASC"
        ) ?: [];
    }

    public function createGroup(string $name, int $sortOrder = 0): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('オプション名を入力してください。');
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'mk_option_groups',
            ['name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1],
            ['%s', '%d', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    public function updateGroup(int $id, string $name, int $sortOrder, bool $active): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mk_option_groups',
            ['name' => $name, 'sort_order' => $sortOrder, 'is_active' => $active ? 1 : 0],
            ['id' => $id],
            ['%s', '%d', '%d'],
            ['%d']
        );
    }

    /**
     * Retire a group by deactivating it. Never delete.
     *
     * Orders reference option groups by id for their price snapshot and their
     * human-readable label. Deleting a row would leave completed transactions
     * describing a purchase nobody can identify afterwards, which is exactly
     * the record you need when a buyer queries what they paid for. Deactivated
     * groups disappear from new listings and stay legible on old orders.
     */
    public function deactivateGroup(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mk_option_groups',
            ['is_active' => 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }

    // ------------------------------------------------------- per-product

    /**
     * What this creator offers on this product, keyed by group id.
     *
     * @return array<int, object> {option_group_id, price, is_offered, name}
     */
    public function forProduct(int $productId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.option_group_id, o.price, o.is_offered, g.name
                   FROM {$wpdb->prefix}mk_product_options o
                   JOIN {$wpdb->prefix}mk_option_groups g ON g.id = o.option_group_id
                  WHERE o.product_id = %d",
                $productId
            )
        ) ?: [];

        $byGroup = [];

        foreach ($rows as $row) {
            $byGroup[(int) $row->option_group_id] = $row;
        }

        return $byGroup;
    }

    /**
     * Save one product's option pricing.
     *
     * @param array<int, array{offered: bool, price: int}> $selections keyed by group id
     */
    public function saveForProduct(int $productId, array $selections): void
    {
        global $wpdb;

        $valid = [];

        foreach ($this->activeGroups() as $group) {
            $valid[(int) $group->id] = true;
        }

        foreach ($selections as $groupId => $selection) {
            $groupId = (int) $groupId;

            // Only groups the platform currently offers. A posted id for a
            // retired or invented group is ignored rather than trusted.
            if (!isset($valid[$groupId])) {
                continue;
            }

            $price   = max(0, (int) $selection['price']);
            $offered = !empty($selection['offered']);

            // An option nobody can be charged for is not on offer, whatever
            // the checkbox says: a zero-priced "gift wrapping" would show in
            // the buyer's list and add nothing to the order.
            if ($offered && $price <= 0) {
                $offered = false;
            }

            if ($offered) {
                Money::assertValidPrice($price);
            }

            // REPLACE against the UNIQUE (product_id, option_group_id) index,
            // so re-saving a product updates rather than accumulating rows.
            $wpdb->replace(
                $wpdb->prefix . 'mk_product_options',
                [
                    'product_id'      => $productId,
                    'option_group_id' => $groupId,
                    'price'           => $price,
                    'is_offered'      => $offered ? 1 : 0,
                ],
                ['%d', '%d', '%d', '%d']
            );
        }
    }

    /** Everything a buyer may currently select on this product. */
    public function offeredFor(int $productId): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.option_group_id, o.price, g.name
                   FROM {$wpdb->prefix}mk_product_options o
                   JOIN {$wpdb->prefix}mk_option_groups g ON g.id = o.option_group_id
                  WHERE o.product_id = %d AND o.is_offered = 1 AND g.is_active = 1
               ORDER BY g.sort_order ASC",
                $productId
            )
        ) ?: [];
    }
}
