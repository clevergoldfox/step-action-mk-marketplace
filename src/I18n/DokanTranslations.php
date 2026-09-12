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
    /**
     * Dokan's dashboard header is a React bundle, not a PHP template, so the
     * .mo above never reaches it: its strings go through wp.i18n in the
     * browser. That is why the header still said "Visit Store" after the rest
     * of the dashboard was Japanese.
     */
    private const SCRIPT_HANDLE = 'dokan-vendor-dashboard';

    private static bool $scriptDone = false;

    public static function register(): void
    {
        // init, not plugins_loaded: WordPress 6.7 emits a notice for text
        // domains loaded before init, and Dokan builds its dashboard strings
        // at render time, well after this runs.
        add_action('init', [self::class, 'load'], 1);

        // Just before scripts print, in the head and again in the footer:
        // Dokan registers the bundle from a shortcode, so whether it exists
        // yet by wp_enqueue_scripts depends on the page.
        add_action('wp_print_scripts', [self::class, 'scriptStrings'], 1);
        add_action('wp_print_footer_scripts', [self::class, 'scriptStrings'], 1);

        // After Dokan's own filter (priority 10), which builds this crumb.
        add_filter('woocommerce_get_breadcrumb', [self::class, 'storeBreadcrumb'], 20, 1);
    }

    /**
     * Every string the header bundle passes to __(), extracted from Dokan's
     * build. Anything missing here falls back to English, as with the .mo.
     *
     * @return array<string,string>
     */
    public static function scriptMessages(): array
    {
        return [
            '%1$s at %2$s'                               => '%1$s %2$s',
            '(%1$s)'                                     => '（%1$s）',
            'Add Filter'                                 => '絞り込みを追加',
            'Are you sure? This action cannot be undone.' => '本当によろしいですか？この操作は元に戻せません。',
            'Commissions'                                => '手数料',
            'Dokan'                                      => get_bloginfo('name'),
            'Net Sales'                                  => '純売上',
            'No data found'                              => 'データがありません',
            'Remove filter'                              => '絞り込みを解除',
            'Select or Upload Media'                     => '画像を選択またはアップロード',
            'Store Image'                                => 'ショップ画像',
            'Total Sales'                                => '売上合計',
            'Use this media'                             => 'この画像を使う',
            'User Profile Image'                         => 'プロフィール画像',
            'Vendor Dashboard'                           => '出品者ダッシュボード',
            'Vendor Dashboard Logo'                      => '出品者ダッシュボードのロゴ',
            'Visit Store'                                => 'ショップを見る',
            'Your Store'                                 => 'あなたのショップ',
        ];
    }

    public static function scriptStrings(): void
    {
        if (self::$scriptDone || !str_starts_with(determine_locale(), 'ja')) {
            return;
        }

        if (!wp_script_is(self::SCRIPT_HANDLE, 'registered')) {
            return;
        }

        self::$scriptDone = true;

        $data = ['' => ['domain' => 'dokan-lite', 'lang' => 'ja']];

        foreach (self::scriptMessages() as $english => $japanese) {
            $data[$english] = [$japanese];
        }

        // 'before' runs after the bundle's dependencies (wp-i18n among them)
        // and before the bundle itself renders anything.
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            sprintf(
                'wp.i18n.setLocaleData(%s, "dokan-lite");',
                wp_json_encode($data, JSON_UNESCAPED_UNICODE)
            ),
            'before'
        );
    }

    /**
     * The store page's breadcrumb, in Japanese and by shop name.
     *
     * Dokan builds it as ucwords() of the store URL base -- literally
     * "Store", untranslatable -- followed by the seller's LOGIN SLUG. So a
     * creator's page read "Store / mk_test_creator" instead of naming their
     * shop. Rewritten here rather than by changing Dokan.
     *
     * @param array<int, array{0:string,1:string}> $crumbs
     * @return array<int, array{0:string,1:string}>
     */
    public static function storeBreadcrumb(array $crumbs): array
    {
        if (!function_exists('dokan_is_store_page') || !dokan_is_store_page()) {
            return $crumbs;
        }

        $listing = (int) (get_option('dokan_pages', [])['store_listing'] ?? 0);

        if (isset($crumbs[1]) && $listing > 0) {
            $crumbs[1] = [get_the_title($listing), (string) get_permalink($listing)];
        }

        if (isset($crumbs[2][0])) {
            $user = get_user_by('slug', (string) $crumbs[2][0]);

            if ($user) {
                $info = function_exists('dokan_get_store_info') ? dokan_get_store_info($user->ID) : [];
                $name = trim((string) ($info['store_name'] ?? ''));

                $crumbs[2][0] = $name !== '' ? $name : $user->display_name;
            }
        }

        return $crumbs;
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
