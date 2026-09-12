<?php
declare(strict_types=1);

namespace MK\Fee;

use MK\Support\Money;
use Throwable;

/**
 * Where the operator changes the commission rates.
 *
 * The rates were settings with no screen: correct for launch, wrong for a
 * marketplace that has to respond to its own economics afterwards without
 * calling a developer. The client asked for them to stay changeable after
 * delivery, with past orders untouched -- which is what Calculator's
 * snapshots already guarantee, and what this screen says out loud so nobody
 * has to take it on faith.
 */
final class Admin
{
    private const SLUG  = 'mk-fees';
    private const NONCE = 'mk_save_fee_rates';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_save_fee_rates', [self::class, 'handleSave']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            '手数料設定',
            '手数料設定',
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。');
        }

        $product = Settings::productRate();
        $option  = Settings::optionRate();

        echo '<div class="wrap"><h1>手数料設定</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>手数料率を変更しました。'
                . 'この料率は、変更後に成立した注文から適用されます。</p></div>';
        }

        if (isset($_GET['error'])) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(sanitize_text_field(wp_unslash((string) $_GET['error'])))
            );
        }

        echo '<p>販売代金から運営が受け取る手数料の割合です。商品本体とオプションで'
            . '別々の料率を設定できます。</p>';

        echo '<div class="notice notice-info inline"><p><strong>変更した料率は、変更後の新規注文にのみ適用されます。</strong><br>'
            . 'すでに成立している注文（発送待ち・送金待ちを含む）は、購入された時点の料率を保持し、'
            . '後から変更されることはありません。出品者にお伝えした受取額が変わらないようにするためです。</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_save_fee_rates">';

        echo '<table class="form-table"><tbody>';

        printf(
            '<tr><th scope="row"><label for="mk_product_rate">商品本体の手数料率</label></th>'
            . '<td><input type="text" inputmode="decimal" id="mk_product_rate" name="product_rate" '
            . 'value="%s" class="small-text"> %%'
            . '<p class="description">現在：%s%%（例：10,000円の商品なら、運営 %s円 / 出品者 %s円）</p></td></tr>',
            esc_attr(self::asPercent($product)),
            esc_html(self::asPercent($product)),
            esc_html(number_format(Money::applyRate(10000, $product))),
            esc_html(number_format(10000 - Money::applyRate(10000, $product)))
        );

        printf(
            '<tr><th scope="row"><label for="mk_option_rate">オプションの手数料率</label></th>'
            . '<td><input type="text" inputmode="decimal" id="mk_option_rate" name="option_rate" '
            . 'value="%s" class="small-text"> %%'
            . '<p class="description">現在：%s%%（例：1,000円のオプションなら、運営 %s円 / 出品者 %s円）</p></td></tr>',
            esc_attr(self::asPercent($option)),
            esc_html(self::asPercent($option)),
            esc_html(number_format(Money::applyRate(1000, $option))),
            esc_html(number_format(1000 - Money::applyRate(1000, $option)))
        );

        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">保存する</button></p>';
        echo '</form>';

        self::renderHistory();

        echo '</div>';
    }

    /** The audit trail. Without it, "when did this change" has no answer. */
    private static function renderHistory(): void
    {
        $rows = Settings::history();

        echo '<h2>変更履歴</h2>';

        if (!$rows) {
            echo '<p>まだ変更履歴はありません。</p>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>日時</th><th>商品本体</th><th>オプション</th><th>変更者</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $user = (int) $row->changed_by > 0 ? get_userdata((int) $row->changed_by) : null;

            printf(
                '<tr><td>%s</td><td>%s%% → %s%%</td><td>%s%% → %s%%</td><td>%s</td></tr>',
                esc_html(get_date_from_gmt((string) $row->changed_at, 'Y/m/d H:i')),
                esc_html(self::asPercent((float) $row->old_product_rate)),
                esc_html(self::asPercent((float) $row->new_product_rate)),
                esc_html(self::asPercent((float) $row->old_option_rate)),
                esc_html(self::asPercent((float) $row->new_option_rate)),
                esc_html($user ? $user->display_name : '運営（依頼による設定）')
            );
        }

        echo '</tbody></table>';
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        try {
            Settings::update(
                Settings::fromPercent((string) ($_POST['product_rate'] ?? '')),
                Settings::fromPercent((string) ($_POST['option_rate'] ?? '')),
                get_current_user_id()
            );
        } catch (Throwable $e) {
            wp_safe_redirect(add_query_arg(
                ['page' => self::SLUG, 'error' => rawurlencode($e->getMessage())],
                admin_url('admin.php')
            ));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }

    /** 0.14 -> "14", 0.145 -> "14.5". Trailing zeros help nobody. */
    private static function asPercent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.');
    }
}
