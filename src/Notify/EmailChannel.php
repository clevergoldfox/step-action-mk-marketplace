<?php
declare(strict_types=1);

namespace MK\Notify;

/**
 * Delivery by email. The channel everyone has.
 *
 * Chosen as the transactional channel because reaching people is the whole
 * job and every account already has an address — no linking step, no plan
 * upgrade, and nothing that can silently stop working because a third-party
 * subscription lapsed.
 */
final class EmailChannel implements Channel
{
    public function name(): string
    {
        return 'email';
    }

    /**
     * Anyone with a real address.
     *
     * A user deleted between the event and the send is the ordinary case
     * here, not an exotic one: transfers and digests run from scheduled jobs,
     * long after the thing they are reporting.
     */
    public function isAvailableFor(int $userId): bool
    {
        $user = get_userdata($userId);

        return $user !== false && is_email($user->user_email);
    }

    public function send(Notification $notification): bool
    {
        $user = get_userdata($notification->userId);

        if ($user === false) {
            return false;
        }

        $body = sprintf("%s 様\n\n%s\n", $user->display_name, $notification->body);

        if ($notification->url !== '') {
            $body .= sprintf("\n%s\n", $notification->url);
        }

        $body .= "\n--\n"
            . get_bloginfo('name') . "\n"
            . "※このメールは送信専用です。ご返信いただいてもお答えできません。\n";

        return wp_mail(
            $user->user_email,
            sprintf('[%s] %s', get_bloginfo('name'), $notification->subject),
            $body
        );
    }
}
