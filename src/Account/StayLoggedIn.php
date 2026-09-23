<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * Stay logged in, the way a phone app does.
 *
 * WordPress writes the login cookie as a SESSION cookie unless 「ログイン状態
 * を保存する」 is ticked -- it expires when the browser is closed, whatever
 * the token's own two-day life says. On a phone, where the browser is closed
 * or evicted constantly, that meant typing an email and password on almost
 * every visit, which is what the client reported (2026-09-24).
 *
 * So the box is ticked by default (the template does that) and a remembered
 * login lasts long enough to be worth the name. Not forever: the cookie still
 * expires, and every session can still be ended from 退会 or by the operator
 * suspending the account, both of which destroy the tokens themselves rather
 * than relying on the cookie.
 *
 * Anyone who unticks the box is taken at their word and gets WordPress's own
 * behaviour -- a shared or borrowed device is exactly who that box is for.
 */
final class StayLoggedIn
{
    /** How long a remembered login lasts. */
    public const DAYS = 30;

    public static function register(): void
    {
        add_filter('auth_cookie_expiration', [self::class, 'lifetime'], 10, 3);
        add_action('login_footer', [self::class, 'tickWpLoginBox']);
    }

    /**
     * @param mixed $length   seconds WordPress proposes
     * @param mixed $userId
     * @param mixed $remember whether the login is to be remembered
     * @return mixed
     */
    public static function lifetime($length, $userId = 0, $remember = false)
    {
        return $remember ? self::DAYS * DAY_IN_SECONDS : $length;
    }

    /**
     * The same default on wp-login.php.
     *
     * Members use the site's own form, but password resets and admin links
     * land here, and being logged out again two minutes later reads as the
     * site having forgotten them.
     */
    public static function tickWpLoginBox(): void
    {
        echo '<script>(function(){var b=document.getElementById("rememberme");'
            . 'if(b&&!b.checked){b.checked=true;}})();</script>' . PHP_EOL;
    }
}
