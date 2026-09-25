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

        add_action('wp_print_footer_scripts', [self::class, 'ariaLabels'], 30);

        // Dokan writes 'All' into this list as a literal, past gettext.
        add_filter('dokan_vendor_dashboard_order_listing_statuses', [self::class, 'orderStatusAll'], 20, 1);
    }

    /**
     * @param mixed $statuses
     * @return mixed
     */
    public static function orderStatusAll($statuses)
    {
        if (is_array($statuses) && ($statuses['all'] ?? '') === 'All') {
            $statuses['all'] = 'すべて';
        }

        return $statuses;
    }

    /**
     * The few labels Dokan writes straight into its markup, untranslatable:
     * what a screen reader announces for the menu button and the chart
     * period. Invisible on screen, but English is English to the person
     * listening. React draws the charts after the page loads, so the page is
     * watched for a short while rather than fixed once.
     */
    public static function ariaLabels(): void
    {
        if (!str_starts_with(determine_locale(), 'ja')
            || !function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) {
            return;
        }

        $labels = wp_json_encode([
            'Menu'                => 'メニュー',
            'Toggle sidebar menu' => 'サイドメニューの開閉',
            'Chart period'        => 'グラフの期間',
        ], JSON_UNESCAPED_UNICODE);

        echo '<script>(function(){var m=' . $labels . ';'
            . 'function fix(){document.querySelectorAll("[aria-label]").forEach(function(e){'
            . 'var v=e.getAttribute("aria-label");if(m[v]){e.setAttribute("aria-label",m[v]);}});}'
            . 'fix();var o=new MutationObserver(fix);'
            . 'o.observe(document.body,{childList:true,subtree:true});'
            . 'setTimeout(function(){o.disconnect();},20000);})();</script>' . PHP_EOL;
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
            'Store Image'                                => 'プロフィール画像',
            'Total Sales'                                => '売上合計',
            'Use this media'                             => 'この画像を使う',
            'User Profile Image'                         => 'プロフィール画像',
            'Vendor Dashboard'                           => '出品者ダッシュボード',
            'Vendor Dashboard Logo'                      => '出品者ダッシュボードのロゴ',
            'Visit Store'                                => 'プロフィールページを見る',
            'Your Store'                                 => 'あなたのページ',
        ];
    }

    /**
     * The analytics block at the top of the creator dashboard, and the report
     * pages it links to: Overview, Performance, Charts and the rest, which
     * were still English after everything around them was Japanese
     * (2026-09-21). Extracted from the five analytics bundles' __() calls,
     * less the ones no creator can reach -- the report builder's internal
     * filter configuration and a developer warning.
     *
     * @return array<string,string>
     */
    public static function analyticsMessages(): array
    {
        return [
            '%s Report'                                  => '%sのレポート',
            'Add %s section'                             => '「%s」を追加',
            'Add more sections'                          => '表示する項目を追加',
            'Advanced Filters'                           => '詳細な絞り込み',
            'Advanced filters'                           => '詳細な絞り込み',
            'All'                                        => 'すべて',
            'All Revenue'                                => 'すべての売上',
            'All coupons'                                => 'すべてのクーポン',
            'All downloads'                              => 'すべてのダウンロード',
            'All orders'                                 => 'すべての注文',
            'All products'                               => 'すべての商品',
            'All taxes'                                  => 'すべての税',
            'All variations'                             => 'すべてのバリエーション',
            'Amount'                                     => '金額',
            'Average items per order'                    => '1注文あたりの平均点数',
            'Average order value'                        => '平均注文額',
            'Balance:'                                   => '残高：',
            'Bar chart'                                  => '棒グラフ',
            'By day'                                     => '日別',
            'By hour'                                    => '時間別',
            'By month'                                   => '月別',
            'By quarter'                                 => '四半期別',
            'By week'                                    => '週別',
            'By year'                                    => '年別',
            'Charts'                                     => 'グラフ',
            'Check at least two coupon codes below to compare' => '比較するクーポンコードを2つ以上選んでください',
            'Check at least two products below to compare'     => '比較する商品を2つ以上選んでください',
            'Check at least two tax codes below to compare'    => '比較する税コードを2つ以上選んでください',
            'Check at least two variations below to compare'   => '比較するバリエーションを2つ以上選んでください',
            'Choose which analytics to display and the section name' => '表示する項目と見出しを選べます',
            'Choose which charts to display'             => '表示するグラフを選べます',
            'Compare'                                    => '比較する',
            'Compare Coupon Codes'                       => 'クーポンコードを比較',
            'Compare Products'                           => '商品を比較',
            'Compare Tax Codes'                          => '税コードを比較',
            'Compare Variations'                         => 'バリエーションを比較',
            'Comparison'                                 => '比較',
            'Coupon code'                                => 'クーポンコード',
            'Coupons'                                    => 'クーポン',
            'Customer type'                              => '購入者の種類',
            'Dashboard'                                  => 'ダッシュボード',
            'Dashboard Sections'                         => 'ダッシュボードの項目',
            'Discounted orders'                          => '割引のあった注文',
            'Display stats:'                             => '表示する数値：',
            'Downloads'                                  => 'ダウンロード',
            'Full refunds are not deducted from tax or net sales totals' => '全額返金は、税額や純売上の合計からは差し引かれません',
            'Fully refunded'                             => '全額返金',
            'Gross discounted'                           => '割引合計',
            'Gross sales'                                => '総売上',
            'IP Address'                                 => 'IPアドレス',
            'Items sold'                                 => '販売点数',
            'Kindly refresh the page to load data or try again.' => 'ページを再読み込みするか、もう一度お試しください。',
            'Line chart'                                 => '折れ線グラフ',
            'Loading…'                                   => '読み込み中…',
            'Move down'                                  => '下へ移動',
            'Move up'                                    => '上へ移動',
            'Net discount amount'                        => '割引額',
            'Net sales'                                  => '純売上',
            'New'                                        => '新規',
            'No data for the current search'             => '検索に一致するデータはありません',
            'No data for the selected date range'        => '選択した期間のデータはありません',
            'No data recorded for the selected time period.' => '選択した期間に記録されたデータはありません。',
            'None'                                       => 'なし',
            'Not allowed'                                => '許可されていません',
            'Oh no! Something went wrong…'               => '問題が発生しました…',
            'Order #'                                    => '注文番号',
            'Order status'                               => '注文の状態',
            'Order tax'                                  => '注文の税額',
            'Orders'                                     => '注文',
            'Overview'                                   => '概要',
            'Partially refunded'                         => '一部返金',
            'Performance'                                => '販売状況',
            'Previous period:'                           => '前の期間：',
            'Previous year:'                             => '前年：',
            'Product'                                    => '商品',
            'Product attribute'                          => '商品の属性',
            'Product variation'                          => 'バリエーション',
            'Products sold'                              => '販売した商品',
            'Refund'                                     => '返金',
            'Reload'                                     => '再読み込み',
            'Remove IP address filter'                   => 'IPアドレスの絞り込みを解除',
            'Remove block'                               => '項目を削除',
            'Remove coupon filter'                       => 'クーポンの絞り込みを解除',
            'Remove customer filter'                     => '購入者の絞り込みを解除',
            'Remove customer username filter'            => 'ユーザー名の絞り込みを解除',
            'Remove order number filter'                 => '注文番号の絞り込みを解除',
            'Remove order status filter'                 => '注文の状態の絞り込みを解除',
            'Remove product attribute filter'            => '商品の属性の絞り込みを解除',
            'Remove product filter'                      => '商品の絞り込みを解除',
            'Remove product variation filter'            => 'バリエーションの絞り込みを解除',
            'Remove refund filter'                       => '返金の絞り込みを解除',
            'Remove section'                             => '項目を削除',
            'Remove tax rate filter'                     => '税率の絞り込みを解除',
            'Reports'                                    => 'レポート',
            'Returning'                                  => 'リピーター',
            'Returns'                                    => '返品',
            'Search'                                     => '検索',
            'Search IP address'                          => 'IPアドレスを検索',
            'Search coupons'                             => 'クーポンを検索',
            'Search customer username'                   => 'ユーザー名を検索',
            'Search for products to compare'             => '比較する商品を検索',
            'Search for tax codes to compare'            => '比較する税コードを検索',
            'Search for variations to compare'           => '比較するバリエーションを検索',
            'Search order number'                        => '注文番号を検索',
            'Search product attributes'                  => '商品の属性を検索',
            'Search product variations'                  => 'バリエーションを検索',
            'Search products'                            => '商品を検索',
            'Search tax rates'                           => '税率を検索',
            'Section title'                              => '見出し',
            'Select IP address'                          => 'IPアドレスを選択',
            'Select a customer type'                     => '購入者の種類を選択',
            'Select a refund type'                       => '返金の種類を選択',
            'Select an order status'                     => '注文の状態を選択',
            'Select attributes'                          => '属性を選択',
            'Select coupon codes'                        => 'クーポンコードを選択',
            'Select customer username'                   => 'ユーザー名を選択',
            'Select order number'                        => '注文番号を選択',
            'Select product'                             => '商品を選択',
            'Select products'                            => '商品を選択',
            'Select tax rates'                           => '税率を選択',
            'Select variation'                           => 'バリエーションを選択',
            'Shipping'                                   => '送料',
            'Shipping tax'                               => '送料の税額',
            'Show'                                       => '表示',
            'Single Coupon'                              => 'クーポン別',
            'Single coupon'                              => 'クーポン別',
            'Single product'                             => '商品別',
            'Single variation'                           => 'バリエーション別',
            'Sorry, you are not allowed to access this page.' => 'このページを表示する権限がありません。',
            'Store Performance'                          => '販売状況',
            'TAX'                                        => '税',
            'Tax rate'                                   => '税率',
            'Taxes'                                      => '税',
            'There was an error getting your stats. Please try again.' => '集計を取得できませんでした。もう一度お試しください。',
            'Total sales'                                => '売上合計',
            'Total tax'                                  => '税額合計',
            'Try Again'                                  => 'もう一度試す',
            'Type to search for a coupon'                => 'クーポンを入力して検索',
            'Type to search for a product'               => '商品名を入力して検索',
            'Type to search for a variation'             => 'バリエーションを入力して検索',
            'Username'                                   => 'ユーザー名',
            'Variations Sold'                            => '販売したバリエーション',
        ];
    }

    public static function scriptStrings(): void
    {
        if (self::$scriptDone || !str_starts_with(determine_locale(), 'ja')) {
            return;
        }

        // The header bundle on every dashboard page; failing that, one of the
        // analytics bundles, on a report page that loads only those. The
        // locale data is global to the text domain, so whichever carries it,
        // every bundle on the page reads it.
        $handle = null;

        foreach ([self::SCRIPT_HANDLE, 'dokan_analytics_dashboard', 'vendor_analytics_script'] as $candidate) {
            if (wp_script_is($candidate, 'registered')) {
                $handle = $candidate;
                break;
            }
        }

        if ($handle === null) {
            return;
        }

        self::$scriptDone = true;

        $data = ['' => ['domain' => 'dokan-lite', 'lang' => 'ja']];

        foreach (self::scriptMessages() + self::analyticsMessages() as $english => $japanese) {
            $data[$english] = [$japanese];
        }

        // 'before' runs after the bundle's dependencies (wp-i18n among them)
        // and before the bundle itself renders anything.
        wp_add_inline_script(
            $handle,
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
