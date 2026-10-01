<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Product\MessageVideo;
use WC_Order;

/**
 * Orders that are not finished when the parcel arrives.
 *
 * メッセージ動画 and メッセージ音声 used to be listings of their own. From
 * 2026-10-01 they are paid options on an ordinary item, at the client's
 * request: someone buys a mug and adds a recorded message to it. What that
 * changes is the end of the transaction. A parcel can be in the buyer's hands
 * while the recording is still unmade, so 受取確認 -- and with it the payout --
 * has to wait for both.
 *
 * Two things can put an order in this state:
 *
 *   an option whose group carries a delivery_kind   (the new way)
 *   a message-video listing bought before the change (the old way)
 *
 * The second is not migrated away. Those orders exist, some are open, and
 * rewriting history to make a report tidier is how a marketplace loses track
 * of what it owes.
 */
final class Delivery
{
    /** Which kind of recording an order is waiting on; '' if none. */
    public const META_KIND = '_mk_delivery_kind';

    /** The option groups bought, so the order can say why it waits. */
    public const META_OPTION_IDS = '_mk_option_ids';

    public static function needsDelivery(WC_Order $order): bool
    {
        return self::kind($order) !== '';
    }

    public static function kind(WC_Order $order): string
    {
        $kind = (string) $order->get_meta(self::META_KIND);

        if ($kind !== '') {
            return $kind;
        }

        // A listing from before options carried this.
        return MessageVideo::isMessageVideoOrder($order) ? 'video' : '';
    }

    public static function isDelivered(WC_Order $order): bool
    {
        return (string) $order->get_meta(VideoDelivery::META_SENT_AT) !== '';
    }

    /** Waiting on the creator to send something. */
    public static function isPending(WC_Order $order): bool
    {
        return self::needsDelivery($order) && !self::isDelivered($order);
    }

    /**
     * What to call it, in a sentence a buyer reads.
     */
    public static function noun(WC_Order $order): string
    {
        return match (self::kind($order)) {
            'audio' => 'メッセージ音声',
            'video' => 'メッセージ動画',
            default => '',
        };
    }

    /**
     * Whether this order still needs a parcel as well.
     *
     * An option is bought on top of an item, so most of these orders ship
     * too; a message-video listing from the old world does not.
     */
    public static function needsShipping(WC_Order $order): bool
    {
        return !MessageVideo::isMessageVideoOrder($order);
    }
}
