<?php
declare(strict_types=1);

namespace MK\Message;

use RuntimeException;
use WC_Order;

/**
 * The conversation attached to one transaction.
 *
 * Scoped to an order rather than to a pair of users, deliberately. "Where is
 * my parcel" only means anything against a specific purchase, and a creator
 * with a hundred buyers needs the question filed against the right one. It
 * also means the thread inherits the order's access rules for free: the two
 * parties to the order are exactly the two people who may read it.
 *
 * Messages are a support and coordination channel, not a place to agree new
 * terms. Nothing here touches money or order status.
 */
final class Service
{
    public const MAX_LENGTH = 2000;

    /**
     * Post a message and return its id.
     *
     * @throws RuntimeException if the sender is not a party, or the body is empty
     */
    public function send(int $orderId, int $senderId, string $body): int
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            throw new RuntimeException('取引が見つかりません。');
        }

        $receiverId = $this->counterpartyOf($order, $senderId);

        if ($receiverId === null) {
            throw new RuntimeException('この取引のメッセージを送信する権限がありません。');
        }

        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('メッセージが空です。');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            $body = mb_substr($body, 0, self::MAX_LENGTH);
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'mk_messages',
            [
                'order_id'    => $orderId,
                'sender_id'   => $senderId,
                'receiver_id' => $receiverId,
                'body'        => $body,
                'is_read'     => 0,
                'created_at'  => current_time('mysql', true),
            ],
            ['%d', '%d', '%d', '%s', '%d', '%s']
        );

        $id = (int) $wpdb->insert_id;

        // Notification lives on this hook rather than inline, so email today
        // and LINE later are both just listeners.
        do_action('mk_message_sent', $id, $orderId, $senderId, $receiverId);

        return $id;
    }

    /**
     * The other party to this order, or null if $userId is not one of them.
     *
     * The single place that decides who may take part in a thread. Both the
     * form and the submit handler ask this, so they cannot drift apart.
     */
    public function counterpartyOf(WC_Order $order, int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $buyer   = $order->get_customer_id();
        $creator = (int) $order->get_meta('_mk_creator_id');

        if ($userId === $buyer) {
            return $creator > 0 ? $creator : null;
        }

        if ($userId === $creator) {
            return $buyer > 0 ? $buyer : null;
        }

        return null;
    }

    /** @return array<int, object> oldest first, as a conversation reads */
    public function forOrder(int $orderId, int $limit = 200): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mk_messages
                  WHERE order_id = %d ORDER BY id ASC LIMIT %d",
                $orderId,
                $limit
            )
        ) ?: [];
    }

    /** Mark everything addressed to this user in this thread as read. */
    public function markRead(int $orderId, int $userId): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}mk_messages
                    SET is_read = 1
                  WHERE order_id = %d AND receiver_id = %d AND is_read = 0",
                $orderId,
                $userId
            )
        );
    }

    public function unreadCount(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mk_messages
                  WHERE receiver_id = %d AND is_read = 0",
                $userId
            )
        );
    }

    public function unreadCountForOrder(int $orderId, int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mk_messages
                  WHERE order_id = %d AND receiver_id = %d AND is_read = 0",
                $orderId,
                $userId
            )
        );
    }
}
