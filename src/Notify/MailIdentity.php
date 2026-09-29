<?php
declare(strict_types=1);

namespace MK\Notify;

/**
 * Who the site's mail says it is from.
 *
 * WordPress signs every mail "WordPress <wordpress@your-domain>" unless told
 * otherwise, and the client saw the result on their phone: a push notification
 * titled **WordPress** carrying a TREASURE BUZZ shipping deadline
 * (2026-09-29). The subject line was ours; the name above it was not.
 *
 * The address moves to this site's own domain as well. Mail claiming to come
 * from another company's domain, sent by this server, is exactly the shape a
 * spam filter is built to distrust -- SPF and DKIM are published for the
 * domain that sends, so From has to match it. Replies are pointed at the
 * operator's real address instead, which is where a person should land
 * anyway.
 */
final class MailIdentity
{
    public const FROM = 'noreply@treasure-buzz.com';

    public static function register(): void
    {
        add_filter('wp_mail_from_name', [self::class, 'fromName'], 99);
        add_filter('wp_mail_from', [self::class, 'fromAddress'], 99);
        add_filter('wp_mail', [self::class, 'addReplyTo'], 99);
    }

    /**
     * @param mixed $name
     */
    public static function fromName($name): string
    {
        $site = get_bloginfo('name');

        return $site !== '' ? $site : (string) $name;
    }

    /**
     * @param mixed $address
     */
    public static function fromAddress($address): string
    {
        $address = (string) $address;

        // Only WordPress's own default is replaced. An address the operator
        // set deliberately -- WooCommerce's, say -- is left alone.
        if ($address === '' || str_starts_with($address, 'wordpress@')) {
            return (string) apply_filters('mk_mail_from', self::FROM);
        }

        return $address;
    }

    /**
     * Somewhere for a reply to go, since From is a no-reply address.
     *
     * @param mixed $args
     * @return mixed
     */
    public static function addReplyTo($args)
    {
        if (!is_array($args)) {
            return $args;
        }

        $headers = $args['headers'] ?? [];
        $headers = is_array($headers) ? $headers : [(string) $headers];

        foreach ($headers as $header) {
            if (stripos((string) $header, 'reply-to:') === 0) {
                return $args;   // the sender chose one already
            }
        }

        $to = get_option('admin_email');

        if (is_email($to)) {
            $headers[]       = 'Reply-To: ' . get_bloginfo('name') . ' <' . $to . '>';
            $args['headers'] = $headers;
        }

        return $args;
    }
}
