<?php
declare(strict_types=1);

namespace MK\Notify;

/**
 * One thing worth telling one person about.
 *
 * Carries three renderings of the same news, because the channels that will
 * deliver it do not want the same shape:
 *
 *   subject  a mail subject line
 *   body     the full text, for mail
 *   short    one line, for LINE or a push notification
 *
 * Writing all three at the point the event happens — where the order, the
 * amounts and the names are already in hand — is what makes adding a channel
 * later a matter of writing a Channel, not of revisiting every event to work
 * out what its one-line form should say.
 *
 * The client has chosen email-only for now and asked that LINE be easy to add
 * afterwards. `short` is dead weight until that day and is written anyway;
 * it costs a line here and saves reopening every notification later.
 */
final class Notification
{
    /**
     * @param string               $type    stable id, e.g. 'order.shipped'
     * @param int                  $userId  who is being told
     * @param array<string, mixed> $context ids for channels that want them
     */
    public function __construct(
        public readonly string $type,
        public readonly int $userId,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $short,
        public readonly string $url = '',
        public readonly array $context = [],
    ) {
    }
}
