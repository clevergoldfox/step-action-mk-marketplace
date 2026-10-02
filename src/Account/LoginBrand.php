<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * The login screen belongs to this marketplace, not to WordPress.
 *
 * A creator signing in met a WordPress mark above the form and a link that
 * took them to wordpress.org -- off the site they were trying to enter
 * (client, 2026-10-02). The logo, the link and the tooltip are all this
 * site's now.
 *
 * The admin bar's own W goes too. It is the same mark in the same corner,
 * and a creator looking at their management page has no use for a menu about
 * the software underneath it.
 */
final class LoginBrand
{
    public static function register(): void
    {
        add_action('login_enqueue_scripts', [self::class, 'logo']);
        add_filter('login_headerurl', [self::class, 'url']);
        add_filter('login_headertext', [self::class, 'text']);
        add_action('wp_before_admin_bar_render', [self::class, 'dropWordPressMenu']);
    }

    /**
     * The site's own lockup, in place of the W.
     *
     * The header logo is used rather than the full one: it is the version
     * without the tagline baked in, which is what fits a 320px-wide form.
     */
    public static function logo(): void
    {
        $id  = (int) get_theme_mod('custom_logo', (int) get_option('site_logo'));
        $src = $id > 0 ? wp_get_attachment_image_url($id, 'medium') : '';

        if ($src === '') {
            return;   // nothing to put there; leave WordPress's own
        }

        // On its own the lockup is white, and the login screen is pale grey:
        // it disappeared into the background. It gets the same black band it
        // has everywhere else on the site.
        printf(
            '<style id="mk-login-brand">
                body.login div#login h1 a {
                    background-image: url(%s);
                    background-color: #111;
                    background-size: 190px auto;
                    background-position: center;
                    background-repeat: no-repeat;
                    width: 100%%;
                    height: 72px;
                    border-radius: 12px;
                    margin-bottom: 20px;
                    text-indent: -9999px;
                }
                body.login { background: #f6f6f6; }
            </style>',
            esc_url($src)
        );
    }

    /**
     * @param mixed $url
     */
    public static function url($url): string
    {
        return home_url('/');
    }

    /**
     * @param mixed $text
     */
    public static function text($text): string
    {
        $name = get_bloginfo('name');

        return $name !== '' ? $name : (string) $text;
    }

    public static function dropWordPressMenu(): void
    {
        global $wp_admin_bar;

        if ($wp_admin_bar instanceof \WP_Admin_Bar) {
            $wp_admin_bar->remove_node('wp-logo');
        }
    }
}
