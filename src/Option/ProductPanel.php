<?php
declare(strict_types=1);

namespace MK\Option;

use MK\Fee\Calculator;

/**
 * The creator's option pricing, on their own product edit screen.
 *
 * Shows what they actually take home rather than only what the buyer pays.
 * Options carry a much higher commission than the item itself — 40% against
 * 16% — and a creator who prices gift wrapping at ¥500 expecting ¥500 will be
 * unpleasantly surprised by ¥300. Telling them at the moment they type the
 * number is the only honest place to do it.
 */
final class ProductPanel
{
    public static function register(): void
    {
        add_action('dokan_product_edit_after_options', [self::class, 'render'], 10, 2);
        add_action('dokan_product_updated', [self::class, 'save'], 10, 1);
        add_action('dokan_new_product_added', [self::class, 'save'], 10, 1);
    }

    public static function render($post = null, $postId = null): void
    {
        $productId = (int) ($postId ?: (is_object($post) ? ($post->ID ?? 0) : 0));

        $service = new Service();
        $groups  = $service->activeGroups();

        if (!$groups) {
            return;   // nothing defined yet; do not show an empty box
        }

        $current  = $productId > 0 ? $service->forProduct($productId) : [];
        $optRate  = (float) get_option('mk_option_fee_rate', 0.40);
        $takeHome = (int) round((1 - $optRate) * 100);

        echo '<div class="dokan-form-group dokan-clearfix mk-option-pricing">';
        echo '<div class="dokan-section-heading"><h2>オプション設定</h2>';
        printf(
            '<p class="dokan-section-desc">この商品で提供するオプションと価格を設定します。'
            . 'オプション売上には %s%% の運営手数料がかかります'
            . '（受取額はご入力額の約 %d%% です）。</p>',
            esc_html((string) round($optRate * 100, 1)),
            $takeHome
        );
        echo '</div><div class="dokan-section-content">';

        echo '<table class="dokan-table" style="width:100%">';
        echo '<thead><tr><th style="width:80px">提供する</th><th>オプション</th>'
            . '<th style="width:180px">価格（円・税込）</th>'
            . '<th style="width:140px">受取額の目安</th></tr></thead><tbody>';

        foreach ($groups as $group) {
            $gid     = (int) $group->id;
            $row     = $current[$gid] ?? null;
            $price   = $row ? (int) $row->price : 0;
            $offered = $row && (int) $row->is_offered === 1;

            $net = $price > 0
                ? Calculator::fromSettings()->calculate(0, $price)->creatorAmount
                : 0;

            echo '<tr>';
            printf(
                '<td style="text-align:center"><input type="checkbox" name="mk_option[%d][offered]" '
                . 'value="1"%s></td>',
                $gid,
                $offered ? ' checked' : ''
            );
            printf('<td>%s</td>', esc_html((string) $group->name));
            printf(
                '<td><input type="number" name="mk_option[%d][price]" value="%d" min="0" step="1" '
                . 'class="dokan-form-control" style="width:150px"></td>',
                $gid,
                $price
            );
            printf(
                '<td class="mk-option-net">%s</td>',
                $net > 0 ? esc_html('約 ' . number_format($net) . ' 円') : '—'
            );
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><small>価格を0円のままにすると、チェックを入れても提供されません。'
            . '受取額は保存後に反映されます。</small></p>';
        echo '</div></div>';
    }

    /**
     * Persist the creator's pricing.
     *
     * Ownership is not re-checked here: Dokan fires these hooks only after
     * establishing that the current user may edit this product, and the panel
     * writes nothing outside mk_product_options for that product id. The
     * prices are still read back from the database at checkout rather than
     * taken from the buyer's request, so a tampered form can only misprice
     * the creator's own listing, never someone else's.
     */
    public static function save($productId): void
    {
        $productId = (int) $productId;

        if ($productId <= 0 || !isset($_POST['mk_option']) || !is_array($_POST['mk_option'])) {
            return;
        }

        $selections = [];

        foreach (wp_unslash($_POST['mk_option']) as $groupId => $data) {
            $selections[(int) $groupId] = [
                'offered' => !empty($data['offered']),
                'price'   => isset($data['price']) ? (int) $data['price'] : 0,
            ];
        }

        (new Service())->saveForProduct($productId, $selections);
    }
}
