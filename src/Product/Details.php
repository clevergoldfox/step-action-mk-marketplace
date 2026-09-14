<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * Two things a second-hand marketplace has to state up front: what condition
 * the item is in, and how soon it ships.
 *
 * Both are the creator's claim about their own item, chosen from a fixed
 * list rather than typed. A free-text condition ("美品です") cannot be
 * compared between listings and cannot be held to anything afterwards; a
 * grade with a published definition can. The same applies to dispatch: "すぐ
 * 送ります" is not a promise anyone can act on, while "2〜3日で発送" sets a
 * date -- which is what Order\DispatchDeadline then holds the seller to.
 *
 * The dispatch choice is therefore not decoration. It is the number the
 * deadline, the buyer's cancellation right and the seller's late count are
 * all computed from, so it is snapshotted onto the order at purchase and
 * never read live afterwards.
 */
final class Details
{
    public const META_CONDITION = '_mk_condition';
    public const META_DISPATCH  = '_mk_dispatch';

    /** Used when a listing predates these fields, so every order has a deadline. */
    public const DEFAULT_DISPATCH = '2-3';

    private static bool $rendered = false;

    public static function register(): void
    {
        add_action('dokan_product_edit_after_options', [self::class, 'renderFields'], 20, 2);
        add_action('dokan_new_product_form', [self::class, 'renderFields'], 20, 2);

        add_action('dokan_product_updated', [self::class, 'save'], 10, 1);
        add_action('dokan_new_product_added', [self::class, 'save'], 10, 1);

        // Above the buy button, where the decision is being made.
        add_action('woocommerce_single_product_summary', [self::class, 'renderOnProduct'], 25);
    }

    /**
     * The grades, with the definition each one carries.
     *
     * The wording is the client's and is shown to buyers verbatim: a grade
     * means nothing without the sentence that defines it.
     *
     * @return array<string, array{label:string, description:string}>
     */
    public static function conditions(): array
    {
        return [
            'A' => [
                'label'       => 'A｜非常に良い',
                'description' => '使用感がほとんどなく、目立つ傷・汚れがない状態',
            ],
            'B' => [
                'label'       => 'B｜良好',
                'description' => '多少の使用感はあるが、目立つ傷・汚れが少なく、全体的に良好な状態',
            ],
            'C' => [
                'label'       => 'C｜使用感あり',
                'description' => '傷・汚れ・使用感などがあるが、通常使用には問題ない状態',
            ],
            'D' => [
                'label'       => 'D｜傷・汚れあり',
                'description' => '目立つ傷・汚れ・使用感などあり',
            ],
        ];
    }

    /**
     * Dispatch promises, and the number of days each one commits to.
     *
     * `days` is the LONGEST of the range: the deadline has to be the promise
     * the seller can be held to, not the best case they hoped for.
     *
     * @return array<string, array{label:string, days:int}>
     */
    public static function dispatchOptions(): array
    {
        return [
            '1-2' => ['label' => '1〜2日で発送', 'days' => 2],
            '2-3' => ['label' => '2〜3日で発送', 'days' => 3],
            '4-7' => ['label' => '4〜7日で発送', 'days' => 7],
        ];
    }

    public static function conditionOf(int $productId): string
    {
        $key = (string) get_post_meta($productId, self::META_CONDITION, true);

        return isset(self::conditions()[$key]) ? $key : '';
    }

    public static function dispatchOf(int $productId): string
    {
        $key = (string) get_post_meta($productId, self::META_DISPATCH, true);

        return isset(self::dispatchOptions()[$key]) ? $key : '';
    }

    /** Days to dispatch for a listing, falling back so every order has a deadline. */
    public static function dispatchDays(string $key): int
    {
        $options = self::dispatchOptions();

        return (int) ($options[$key]['days'] ?? $options[self::DEFAULT_DISPATCH]['days']);
    }

    public static function dispatchLabel(string $key): string
    {
        return (string) (self::dispatchOptions()[$key]['label'] ?? '');
    }

    /** @param int|\WP_Post|null $post */
    public static function renderFields($post = null, $postId = null): void
    {
        if (self::$rendered) {
            return;   // both hooks fire on the edit screen
        }

        self::$rendered = true;

        $productId = (int) ($postId ?: (is_object($post) ? ($post->ID ?? 0) : 0));
        $condition = $productId > 0 ? self::conditionOf($productId) : '';

        // Falls back for an existing listing too, not only for a new one.
        // A product saved before this field existed has no value, and with
        // nothing marked selected the browser shows the FIRST option -- so
        // editing an old listing for an unrelated reason would quietly commit
        // its creator to the fastest dispatch promise on the list.
        $dispatch = ($productId > 0 ? self::dispatchOf($productId) : '') ?: self::DEFAULT_DISPATCH;

        echo '<div class="dokan-form-group dokan-clearfix mk-details">';
        echo '<div class="dokan-section-heading"><h2>商品の状態と発送</h2>'
            . '<p class="dokan-section-desc">中古品の場合は状態ランクを選んでください。'
            . '発送までの日数は購入者に表示され、<strong>この期限を過ぎると購入者がキャンセルを申請できます</strong>。</p>'
            . '</div><div class="dokan-section-content">';

        echo '<p class="mk-details__field"><label for="mk_condition">商品の状態</label>';
        echo '<select name="mk_condition" id="mk_condition" class="dokan-form-control">';
        echo '<option value="">未使用・新品（状態ランクを表示しない）</option>';

        foreach (self::conditions() as $key => $grade) {
            printf(
                '<option value="%s"%s>%s ― %s</option>',
                esc_attr($key),
                selected($condition, $key, false),
                esc_html($grade['label']),
                esc_html($grade['description'])
            );
        }

        echo '</select></p>';

        echo '<p class="mk-details__field"><label for="mk_dispatch">発送までの日数</label>';
        echo '<select name="mk_dispatch" id="mk_dispatch" class="dokan-form-control">';

        foreach (self::dispatchOptions() as $key => $option) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($dispatch, $key, false),
                esc_html($option['label'])
            );
        }

        echo '</select>';
        echo '<small>購入代金の支払いが確認できた日から数えます。</small></p>';
        echo '</div></div>';
    }

    /** @param int $productId */
    public static function save($productId): void
    {
        $productId = (int) $productId;

        if ($productId <= 0) {
            return;
        }

        if (isset($_POST['mk_condition'])) {
            $condition = sanitize_text_field(wp_unslash((string) $_POST['mk_condition']));

            if (isset(self::conditions()[$condition])) {
                update_post_meta($productId, self::META_CONDITION, $condition);
            } else {
                delete_post_meta($productId, self::META_CONDITION);
            }
        }

        if (isset($_POST['mk_dispatch'])) {
            $dispatch = sanitize_text_field(wp_unslash((string) $_POST['mk_dispatch']));

            if (isset(self::dispatchOptions()[$dispatch])) {
                update_post_meta($productId, self::META_DISPATCH, $dispatch);
            }
        }
    }

    /** What the buyer sees, next to the price. */
    public static function renderOnProduct(): void
    {
        global $product;

        if (!$product instanceof \WC_Product) {
            return;
        }

        $productId = $product->get_id();
        $condition = self::conditionOf($productId);
        $dispatch  = self::dispatchOf($productId);

        if ($condition === '' && $dispatch === '') {
            return;
        }

        echo '<ul class="mk-details-list">';

        if ($condition !== '') {
            $grade = self::conditions()[$condition];

            printf(
                '<li><span class="mk-details-list__key">商品の状態</span>'
                . '<span class="mk-details-list__value"><strong>%s</strong>'
                . '<small>%s</small></span></li>',
                esc_html($grade['label']),
                esc_html($grade['description'])
            );
        }

        if ($dispatch !== '') {
            printf(
                '<li><span class="mk-details-list__key">発送までの目安</span>'
                . '<span class="mk-details-list__value"><strong>%s</strong>'
                . '<small>ご入金の確認後、出品者が発送します。</small></span></li>',
                esc_html(self::dispatchLabel($dispatch))
            );
        }

        echo '</ul>';
    }
}
