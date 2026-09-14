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

    /** Part of that debt deducted from a later payout, or paid off-platform. */
    public const DEBT_RECOVERED = 'debt_recovered';

    /**
     * A cost of an unwind that the platform chose to carry itself.
     *
     * Not a debt: it never touches the balance. It is here so that a creator's
     * history shows the refund happened and was NOT charged to them, which is
     * the question that comes up when they compare two refunds and only one of
     * them cost them anything.
     */
    public const PLATFORM_ABSORBED = 'platform_absorbed';

    /** An outstanding amount billed to the creator outside the platform. */
    public const INVOICED = 'invoiced';

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
        global $wpdb;

        return max(0, (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT outstanding FROM {$wpdb->prefix}mk_creator_balances WHERE user_id = %d",
                $userId
            )
        ));
    }

    /**
     * Apply a delta to the outstanding balance in a single SQL statement.
     *
     * Read-modify-write in PHP would lose an update if a refund webhook and a
     * scheduled transfer touched the same creator at the same moment — and a
     * lost debt increment is money the platform silently never recovers.
     * GREATEST(0, ...) keeps the balance from going negative if a reversal is
     * somehow applied twice.
     *
     * ---------------------------------------------------------------------
     * Why this is not in wp_usermeta
     * ---------------------------------------------------------------------
     * It was, and the statement below was written against it unchanged. That
     * does not work: ON DUPLICATE KEY UPDATE only fires on a UNIQUE or PRIMARY
     * key, and wp_usermeta has neither on (user_id, meta_key) — both of its
     * indexes are non-unique. Every call therefore INSERTED another row rather
     * than updating, get_user_meta() went on returning the first one, and the
     * balance never moved.
     *
     * The visible symptom was a creator's debt being deducted from a payout,
     * correctly, and then still being outstanding afterwards — so it would
     * have been deducted again from every future payout, indefinitely.
     *
     * mk_creator_balances has user_id as its PRIMARY KEY, which is the
     * constraint this statement always required.
     */
    private function adjustOutstanding(int $userId, int $delta): int
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}mk_creator_balances
                     (user_id, outstanding, updated_at)
                 VALUES (%d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                     outstanding = GREATEST(0, outstanding + %d),
                     updated_at  = VALUES(updated_at)",
                $userId,
                max(0, $delta),
                current_time('mysql', true),
                $delta
            )
        );

        return $this->outstanding($userId);
    }

    /**
     * Record that the creator has been billed for what they owe.
     *
     * Deliberately does NOT reduce the balance. Sending an invoice collects
     * nothing; treating it as recovery would clear the debt from the payout
     * path and the money would never be taken from anywhere. This is a note in
     * the history so the operator can see a bill went out, and when.
     */
    public function invoice(int $userId, int $amount, string $note = ''): int
    {
        return $this->record(
            $userId,
            null,
            self::INVOICED,
            $amount,
            0,
            null,
            $note !== '' ? $note : '未回収額を出品者へ別途請求'
        );
    }

    /**
     * Money the creator actually paid back, outside the platform.
     *
     * The other half of invoice(). Clamped to what is owed: recording a
     * payment larger than the debt would otherwise leave a negative balance
     * that the payout path would read as credit.
     */
    public function recordPayment(int $userId, int $amount, string $note = ''): int
    {
        $amount = min(max(0, $amount), $this->outstanding($userId));

        return $this->record(
            $userId,
            null,
            self::DEBT_RECOVERED,
            $amount,
            -$amount,
            null,
            $note !== '' ? $note : '出品者からの入金により回収'
        );
    }

    /**
     * Everyone who currently owes the platform money.
     *
     * @return array<int, object> {user_id, outstanding, updated_at}, largest first
     */
    public function debtors(int $limit = 100): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, outstanding, updated_at
                   FROM {$wpdb->prefix}mk_creator_balances
                  WHERE outstanding > 0
               ORDER BY outstanding DESC
                  LIMIT %d",
                $limit
            )
        ) ?: [];
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
