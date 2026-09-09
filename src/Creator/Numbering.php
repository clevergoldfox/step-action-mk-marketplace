<?php
declare(strict_types=1);

namespace MK\Creator;

use RuntimeException;

/**
 * Allocates creator numbers: one letter followed by five digits, e.g. A00001.
 *
 * I and O are excluded because they are indistinguishable from 1 and 0 when a
 * number is read aloud, written on a form, or matched against a LINE profile
 * field. That leaves 24 letters and 2,399,976 numbers.
 *
 * ---------------------------------------------------------------------------
 * Concurrency
 * ---------------------------------------------------------------------------
 * Two people registering in the same instant must not receive the same number.
 *
 * The Bubble implementation could not express this: its backend workflows have
 * no transactions, so it needed an increment, a five-branch padded write, a
 * scheduled verification workflow, a retry counter and a repair path — roughly
 * eighty moving parts to make a race self-healing rather than impossible.
 *
 * MySQL makes it impossible in one statement. LAST_INSERT_ID(expr) stores expr
 * in the connection's session state while the UPDATE holds a row lock, so the
 * read-modify-write is atomic and the value we read back is provably ours.
 */
final class Numbering
{
    /** 24 letters. I and O deliberately absent. */
    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const PER_LETTER = 99_999;

    private const OPTION_SEQ = 'mk_creator_seq';

    /**
     * Reserve the next number. Safe to call concurrently.
     *
     * @throws RuntimeException if the entire range is exhausted.
     */
    public function allocate(): string
    {
        return $this->format($this->nextSequence());
    }

    /**
     * Atomically increment and return the global counter.
     *
     * The UPDATE and the SELECT must run on the same connection, which is
     * guaranteed here because $wpdb holds a single persistent handle.
     */
    private function nextSequence(): int
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options}
                    SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
                  WHERE option_name = %s",
                self::OPTION_SEQ
            )
        );

        if ($updated === false) {
            throw new RuntimeException('Failed to increment the creator sequence.');
        }

        // The counter row is created on activation. If it is missing the
        // install is broken, and silently seeding it here would hand out
        // numbers that collide with ones already issued.
        if ($updated === 0) {
            throw new RuntimeException(
                'Creator sequence option is missing. Reactivate the plugin.'
            );
        }

        $next = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        // The UPDATE above deliberately bypasses the options API -- only raw
        // SQL can make the read-modify-write atomic -- but that leaves
        // WordPress holding a stale cached copy of the option.
        //
        // Allocation itself never reads the cache, so numbers are unaffected.
        // Everything else is: get_option() would report the pre-increment
        // value, and update_option() would compare against it, decide nothing
        // changed and skip the write entirely. Under a persistent object cache
        // (Redis is available on this host) the stale value outlives the
        // request and every later reader sees it too.
        //
        // The option is autoloaded, so it lives inside the alloptions blob as
        // well as under its own key; both have to go.
        wp_cache_delete(self::OPTION_SEQ, 'options');
        wp_cache_delete('alloptions', 'options');

        return $next;
    }

    /**
     * Turn a global sequence number into a letter-prefixed, zero-padded code.
     *
     * Bubble needed five conditional workflow actions to zero-pad, because it
     * has no string formatting. Here it is one sprintf.
     */
    public function format(int $sequence): string
    {
        if ($sequence < 1) {
            throw new RuntimeException(sprintf('Sequence must be positive, got %d.', $sequence));
        }

        $index  = intdiv($sequence - 1, self::PER_LETTER);
        $within = (($sequence - 1) % self::PER_LETTER) + 1;

        if ($index >= strlen(self::LETTERS)) {
            throw new RuntimeException(
                'Creator number range exhausted. Widen the format before issuing more.'
            );
        }

        return sprintf('%s%05d', self::LETTERS[$index], $within);
    }

    /**
     * Whether a string looks like a creator number.
     *
     * Used by product search to decide between "find this creator's listings"
     * and "search titles". Callers should trim and uppercase first.
     */
    public static function isCreatorNumber(string $candidate): bool
    {
        return (bool) preg_match('/^[' . self::LETTERS . ']\d{5}$/', $candidate);
    }

    public static function normalise(string $input): string
    {
        return strtoupper(trim($input));
    }
}
