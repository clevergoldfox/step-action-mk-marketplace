<?php
declare(strict_types=1);

namespace MK\Option;

use MK\Fee\Calculator;

/**
 * The creator's option pricing, on their own product edit screen.
 *
 * Shows what they actually take home rather than only what the buyer pays.
 * Options carry a much higher commission than the item itself — 40% against
 * 14% today — and a creator who prices gift wrapping at ¥500 expecting ¥500 will be
 * unpleasantly surprised by ¥300. Telling them at the moment they type the
 * number is the only honest place to do it.
 */
final class ProductPanel
{
    /** Both hooks below fire on the edit screen; the panel is drawn once. */
    private static bool $rendered = false;

    public static function register(): void
    {
        add_action('dokan_product_edit_after_options', [self::class, 'render'], 10, 2);

        // Also on the creation form. Without this a creator had to save the
        // listing, find it again and open it for editing before they could
        // price an option -- so in practice options went unset.
        add_action('dokan_new_product_form', [self::class, 'render'], 10, 2);

        add_action('dokan_product_updated', [self::class, 'save'], 10, 1);
        add_action('dokan_new_product_added', [self::class, 'save'], 10, 1);
    }

    public static function render($post = null, $postId = null): void
    {
        if (self::$rendered) {
            return;
        }

        self::$rendered = true;

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
            . '<strong>「表示する」のチェックを外したオプションは、この商品の購入画面に表示されません。</strong>'
            . '商品ごとに選べます。<br>'
            . 'オプション売上には %s%% の運営手数料がかかります'
            . '（受取額はご入力額の約 %d%% です）。</p>',
            esc_html((string) round($optRate * 100, 1)),
            $takeHome
        );
        echo '</div><div class="dokan-section-content">';

        // Deliberately not a <table>. Dokan's table styling is built for a
        // desktop dashboard, and forcing it into a phone layout meant
        // overriding column widths and header cells until rows overflowed the
        // screen. Our own markup is a list that stacks by itself.
        echo '<ul class="mk-option-rows">';

        foreach ($groups as $group) {
            $gid     = (int) $group->id;
            $row     = $current[$gid] ?? null;
            $price   = $row ? (int) $row->price : 0;
            $offered = $row && (int) $row->is_offered === 1;

            $net = $price > 0
                ? Calculator::fromSettings()->calculate(0, $price)->creatorAmount
                : 0;

            echo '<li class="mk-option-row">';

            printf(
                '<label class="mk-option-row__show"><input type="checkbox" name="mk_option[%d][offered]" value="1"%s>'
                . '<span>表示する</span></label>',
                $gid,
                $offered ? ' checked' : ''
            );

            printf('<p class="mk-option-row__name">%s</p>', esc_html((string) $group->name));

            printf(
                '<p class="mk-option-row__price"><label for="mk-option-price-%1$d">価格（円・税込）</label>'
                . '<input type="number" id="mk-option-price-%1$d" name="mk_option[%1$d][price]" value="%2$d" '
                . 'min="0" step="1" inputmode="numeric" class="dokan-form-control"></p>',
                $gid,
                $price
            );

            printf(
                '<p class="mk-option-row__net">受取額の目安：%s</p>',
                $net > 0 ? esc_html('約 ' . number_format($net) . ' 円') : '—'
            );

            echo '</li>';
        }

        echo '</ul>';

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
