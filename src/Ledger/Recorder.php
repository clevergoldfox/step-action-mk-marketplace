<?php
declare(strict_types=1);

namespace MK\Ledger;

/**
 * Append-only record of every credit and debit against a creator.
 *
 * `mk_unrecovered_amount` on the user is the running balance and is what the
 * transfer path reads. This ledger is why that number is what it is.
 *
 * The distinction matters the first time a creator writes in asking why they
 * received ¥9,240 instead of ¥9,600. A single balance field can only answer
 * "you owe ¥360"; the ledger answers "order #118 was refunded on 3 October,
 * Stripe kept its ¥360 processing fee, and it was recovered from this sale".
 * The second answer ends the conversation.
 */
final class Recorder
{
    public const META_OUTSTANDING = 'mk_unrecovered_amount';

    /** Money sent to the creator. */
    public const TRANSFER = 'transfer';

    /** A transfer pulled back after a refund or dispute. */
    public const REVERSAL = 'reversal';

    /** A cost the platform absorbed that the creator now owes. */
    public const DEBT_INCURRED = 'debt_incurred';

    /** Part of that debt deducted from a later payout. */
    public const DEBT_RECOVERED = 'debt_recovered';

    /**
     * Write one entry and update the running balance atomically.
     *
     * $debtDelta is the change to what the creator owes: positive when they
     * incur a debt, negative when it is recovered, zero for informational
     * entries such as a successful transfer.
     */
    public function record(
        int $userId,
        ?int $orderId,
        string $entryType,
        int $amount,
        int $debtDelta = 0,
        ?string $stripeObjectId = null,
        ?string $note = null,
    ): int {
        global $wpdb;

        $balance = $debtDelta === 0
            ? $this->outstanding($userId)
            : $this->adjustOutstanding($userId, $debtDelta);

        $wpdb->insert(
            $wpdb->prefix . 'mk_creator_ledger',
            [
                'user_id'          => $userId,
                'order_id'         => $orderId,
                'entry_type'       => $entryType,
                'amount'           => $amount,
                'balance_after'    => $balance,
                'stripe_object_id' => $stripeObjectId,
                'note'             => $note,
                'created_at'       => current_time('mysql', true),
            ],
            ['%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public function outstanding(int $userId): int
    {
        return max(0, (int) get_user_meta($userId, self::META_OUTSTANDING, true));
    }

    /**
     * Apply a delta to the outstanding balance in a single SQL statement.
     *
     * Read-modify-write through update_user_meta() would lose an update if a
     * refund webhook and a scheduled transfer touched the same creator at the
     * same moment — and a lost debt increment is money the platform silently
     * never recovers. GREATEST(0, ...) keeps the balance from going negative
     * if a reversal is somehow applied twice.
     */
    private function adjustOutstanding(int $userId, int $delta): int
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
                 VALUES (%d, %s, %d)
                 ON DUPLICATE KEY UPDATE
                     meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d)",
                $userId,
                self::META_OUTSTANDING,
                max(0, $delta),
                $delta
            )
        );

        // usermeta is cached per-request; without this the next read in the
        // same request returns the pre-update value.
        wp_cache_delete($userId, 'user_meta');

        return $this->outstanding($userId);
    }

    /**
     * @return array<int, object> newest first
     */
    public function historyFor(int $userId, int $limit = 50): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mk_creator_ledger
                  WHERE user_id = %d
               ORDER BY created_at DESC, id DESC
                  LIMIT %d",
                $userId,
                $limit
            )
        );
    }
}
