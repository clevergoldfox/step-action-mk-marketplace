<?php
declare(strict_types=1);

namespace MK\I18n;

/**
 * Japanese for the Dokan screens creators use.
 *
 * Dokan Lite has no Japanese language pack at all — installing one fails with
 * "Language 'ja' not available" — so on this ja-locale site the seller
 * dashboard was entirely in English. A client testing the listing flow went
 * looking for 「出品中」 and could not find their products, because the tab
 * said "Live".
 *
 * Loaded into the dokan-lite text domain. Strings it does not cover fall back
 * to Dokan's English, so a gap degrades to what was there before rather than
 * breaking anything. If an official pack ever appears, check which wins
 * before relying on either: precedence between two files for the same domain
 * depends on load order, and that has not been tested here because there is
 * no official file to test against.
 */
final class DokanTranslations
{
    public static function register(): void
    {
        // init, not plugins_loaded: WordPress 6.7 emits a notice for text
        // domains loaded before init, and Dokan builds its dashboard strings
        // at render time, well after this runs.
        add_action('init', [self::class, 'load'], 1);
    }

    public static function load(): void
    {
        $locale = determine_locale();

        if (!str_starts_with($locale, 'ja')) {
            return;
        }

        $mofile = MK_PLUGIN_DIR . 'languages/dokan-lite-ja.mo';

        if (is_readable($mofile)) {
            load_textdomain('dokan-lite', $mofile, $locale);
        }
    }
}
