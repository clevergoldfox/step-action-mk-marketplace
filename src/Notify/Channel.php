<?php
declare(strict_types=1);

namespace MK\Notify;

/**
 * Somewhere a notification can be delivered.
 *
 * The interface a future LINE channel implements. Adding one is:
 *
 *   1. write a class implementing this
 *   2. add it via the mk_notification_channels filter
 *
 * and nothing that raises a notification changes. That is the whole reason
 * this layer exists rather than calling wp_mail() from the order code.
 *
 * isAvailableFor() is what makes a mixed rollout work. A LINE channel returns
 * false for anyone who has not linked their account, so those people keep
 * getting mail and the linked ones get both — no per-user branching in the
 * dispatcher, and no flag day where everyone must be migrated at once.
 */
interface Channel
{
    /** Stable identifier, used in logs and in per-channel opt-outs. */
    public function name(): string;

    /**
     * Can this channel reach this person at all?
     *
     * Asked per recipient, not per channel, so a channel that only some users
     * have set up can be added without touching anything else.
     */
    public function isAvailableFor(int $userId): bool;

    /** @return bool true if delivery was accepted */
    public function send(Notification $notification): bool;
}
