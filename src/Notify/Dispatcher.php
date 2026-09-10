<?php
declare(strict_types=1);

namespace MK\Notify;

use Throwable;

/**
 * Fans one notification out to every channel that can reach the recipient.
 *
 * ---------------------------------------------------------------------------
 * A notification must never break a transaction
 * ---------------------------------------------------------------------------
 * Every send is wrapped. A mail server refusing connections, or one day a LINE
 * API returning 500, must not throw out of an order status change — the money
 * has already moved, and failing the request afterwards would leave the order
 * mid-flight to save a message nobody would have minded missing.
 *
 * One channel failing also must not stop the others, so each is tried
 * independently rather than in one try block.
 *
 * Failures are logged. Silence here would recreate the webhook problem
 * exactly: an integration that quietly delivers nothing while every screen
 * reports success.
 */
final class Dispatcher
{
    /**
     * The channels in play.
     *
     * Email today. A LINE channel is added by filtering this — the whole
     * point of the arrangement:
     *
     *     add_filter('mk_notification_channels', function (array $channels) {
     *         $channels[] = new \MK\Notify\LineChannel();
     *         return $channels;
     *     });
     *
     * @return Channel[]
     */
    public static function channels(): array
    {
        $channels = apply_filters('mk_notification_channels', [new EmailChannel()]);

        return array_values(array_filter(
            (array) $channels,
            static fn ($c): bool => $c instanceof Channel
        ));
    }

    public static function send(Notification $notification): void
    {
        if ($notification->userId <= 0) {
            return;
        }

        /**
         * Last word on whether this goes out at all.
         *
         * Where a per-user preference screen would hook in, and where a
         * digest would intercept: LINE bills per delivery per recipient, so
         * batching the chatty types into one daily message is the difference
         * between roughly 5,000 and roughly 1,000 messages a month. Returning
         * false here and queueing is all that takes.
         */
        if (!apply_filters('mk_should_notify', true, $notification)) {
            return;
        }

        foreach (self::channels() as $channel) {
            try {
                if (!$channel->isAvailableFor($notification->userId)) {
                    continue;
                }

                $channel->send($notification);
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[mk-marketplace] notification %s to user %d failed on channel %s: %s',
                    $notification->type,
                    $notification->userId,
                    $channel->name(),
                    $e->getMessage()
                ));
            }
        }

        do_action('mk_notification_sent', $notification);
    }
}
