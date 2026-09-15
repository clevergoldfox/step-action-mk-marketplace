<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * Makes Dokan's listing form say what it wants.
 *
 * Dokan's field labels are written for a general-purpose multi-vendor store
 * and read as jargon to someone selling one second-hand jacket: 「簡単な説明」
 * and 「商品説明」 are two boxes with no stated difference, 「タグ」 is a word
 * with no instruction attached, and a permalink box appears with no
 * explanation of what it is for. The client walked the whole flow as a seller
 * and stopped at each of them.
 *
 * None of that is a bug, which is exactly why it is worth fixing: a form that
 * works perfectly and cannot be understood produces listings with empty
 * descriptions and no tags, and the marketplace is only as good as what is in
 * it.
 *
 * ---------------------------------------------------------------------------
 * Where each piece of guidance lives
 * ---------------------------------------------------------------------------
 * Labels are changed in the dokan-lite translation catalogue, because they are
 * translatable strings and that is the mechanism for changing them -- no
 * template is overridden and no markup is rewritten.
 *
 * Help text goes through Dokan's own action hooks where one exists next to the
 * field, and through a small script where one does not. The two descriptions
 * are the case with no hook: they are printed inline by the template between
 * two other fields.
 */
final class FormGuide
{
    public static function register(): void
    {
        // Physical goods only. See forcePhysical().
        add_action('woocommerce_new_product', [self::class, 'forcePhysical'], 5, 2);
        add_action('woocommerce_update_product', [self::class, 'forcePhysical'], 5, 2);

        add_action('dokan_product_edit_after_product_tags', [self::class, 'tagHelp'], 10, 0);
        add_action('dokan_product_edit_after_inventory', [self::class, 'inventoryHelp'], 10, 0);

        // Late, so it lands after the option panel and the condition fields
        // and immediately before Dokan's own submit button.
        add_action('dokan_product_edit_after_options', [self::class, 'submitButtons'], 90, 1);
        add_action('dokan_product_content_inside_area_before', [self::class, 'savedNotice']);

        add_filter('body_class', [self::class, 'bodyClass']);

        add_action('wp_print_footer_scripts', [self::class, 'script'], 20);

        // Coming back from the preview. See backToEdit().
        add_action('woocommerce_single_product_summary', [self::class, 'backToEdit'], 4);
    }

    /**
     * Every listing here is a physical object that gets posted.
     *
     * 「ダウンロード商品」 and 「配送なし」 are WooCommerce's switches for
     * digital goods and services. Both are hidden from the form (see
     * ListingForm), and both are forced off on save rather than merely
     * hidden: a hidden checkbox is still a value a REST call can set, and a
     * listing marked 配送なし skips shipping entirely -- no address, no
     * dispatch deadline, nothing for the buyer to receive. The two features
     * this marketplace is built around would simply not apply to it.
     *
     * @param int        $productId
     * @param mixed      $product
     */
    public static function forcePhysical($productId, $product = null): void
    {
        $productId = (int) $productId;

        if (!PriceGate::applies($productId)) {
            return;   // platform-owned products are not ours to change
        }

        // A message video has no parcel and no stock. Its own rules apply, and
        // applying these on top would put back what those take away.
        if (MessageVideo::isMessageVideo($productId)) {
            MessageVideo::normaliseStock($productId);

            return;
        }

        foreach (['_downloadable', '_virtual'] as $key) {
            if (get_post_meta($productId, $key, true) !== 'no') {
                update_post_meta($productId, $key, 'no');
            }
        }

        self::oneInventoryRule($productId);
    }

    /**
     * ② 在庫管理 and 1点のみ are one choice, not two.
     *
     * WooCommerce is happy with both at once -- "I have three, buy one at a
     * time" is a real thing -- but on a marketplace of one-off items it is two
     * settings that look like they contradict each other, and the client asked
     * for one or the other.
     *
     * Stock management wins when both arrive. A seller who went to the trouble
     * of entering a quantity means it; the per-order limit is the setting they
     * are more likely to have left ticked from a previous save. The browser
     * prevents the combination in the first place, so this is the backstop for
     * the REST route and for listings that predate the rule.
     */
    private static function oneInventoryRule(int $productId): void
    {
        if (get_post_meta($productId, '_manage_stock', true) !== 'yes') {
            return;
        }

        if (get_post_meta($productId, '_sold_individually', true) === 'yes') {
            update_post_meta($productId, '_sold_individually', 'no');
        }
    }

    /** ⑤ What a tag is for, next to the tag field. */
    public static function tagHelp(): void
    {
        echo '<p class="mk-field-help">'
            . 'この商品に当てはまる<strong>特徴やキーワード</strong>を入力してください。'
            . '購入者が探すときの手がかりになります。<br>'
            . '<span class="mk-field-help__eg">例：オーバーサイズ／古着／ストリート／Tシャツ／ユニセックス／一点物</span>'
            . '</p>';
    }

    /**
     * ⑤ One question instead of two settings.
     *
     * 「在庫管理を有効にする」 and 「1回の注文で1点のみ購入可能にする」 are two
     * checkboxes that a seller has to combine correctly, and combining them
     * wrongly produces a listing that behaves in a way they did not intend.
     * There are only two answers worth having, so they are offered as two
     * answers: this is one of a kind, or I have several.
     *
     * The radio is what the seller sees; Dokan's own checkboxes are still what
     * is submitted, driven from it by the script and hidden by the stylesheet.
     * Nothing about Dokan's save path is bypassed, so a future Dokan change
     * cannot leave us writing fields it has stopped reading.
     */
    public static function inventoryHelp(): void
    {
        $tracks = self::editingStocked();

        echo '<div class="mk-stock-choice">';
        echo '<p class="mk-stock-choice__title">この商品の在庫について</p>';

        printf(
            '<label class="mk-stock-choice__option"><input type="radio" name="mk_stock_mode" value="single"%s>'
            . '<span><strong>1点のみの商品</strong>'
            . '<small>手持ちが1点だけの商品です。購入されると自動的に「売り切れ」になります。'
            . '在庫数の入力は必要ありません。</small></span></label>',
            $tracks ? '' : ' checked'
        );

        printf(
            '<label class="mk-stock-choice__option"><input type="radio" name="mk_stock_mode" value="stock"%s>'
            . '<span><strong>在庫が複数ある商品</strong>'
            . '<small>同じ商品を複数お持ちの場合です。購入されるたびに在庫数が1つ減り、'
            . '0になると自動的に「売り切れ」になります。下の「在庫数」にお持ちの数をご入力ください。</small></span></label>',
            $tracks ? ' checked' : ''
        );

        echo '</div>';
    }

    /** Whether the listing being edited is one with a quantity. */
    private static function editingStocked(): bool
    {
        $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

        return $productId > 0 && Reservation::tracksStock($productId);
    }

    /**
     * The stock mode, decided while the page is built.
     *
     * The quantity fields used to be hidden by a class the script added after
     * load: the seller saw them flash on every page load, and saw them for
     * good if any script above ours threw. What mode a listing is in is known
     * here, so it is settled here, and the script only has to keep up when the
     * seller changes their mind.
     *
     * @param array<int,string> $classes
     * @return array<int,string>
     */
    public static function bodyClass(array $classes): array
    {
        if (!function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) {
            return $classes;
        }

        $classes[] = self::editingStocked() ? 'mk-stock-mode-stock' : 'mk-stock-mode-single';

        // Which half of the form shows -- goods or message video -- settled
        // while the page is built, for the same reason as the stock mode.
        $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
        $classes[] = MessageVideo::isMessageVideo($productId) ? 'mk-kind-message-video' : 'mk-kind-physical';

        return $classes;
    }

    /**
     * ⑥ Two buttons, because there are two things a seller might mean.
     *
     * Dokan offers one, labelled 「商品を保存」, which re-saves at whatever
     * status the listing already has. A seller filling in a new listing cannot
     * tell from that whether pressing it puts the item on sale or merely keeps
     * their work, and the difference matters enormously to them.
     *
     * So: one button that only keeps the work, and one that actually submits
     * it. Both are ordinary submits of Dokan's own form carrying Dokan's own
     * field name -- the only thing added is the post_status Dokan already
     * reads, set by the button that was pressed. With scripting unavailable
     * the field stays empty, which is exactly Dokan's existing behaviour of
     * keeping the current status: the worse outcome is a listing that stays
     * a draft, never one that goes on sale unintentionally.
     *
     * @param int $postId
     */
    public static function submitButtons($postId = 0): void
    {
        $postId  = (int) $postId;
        $current = $postId > 0 ? (string) get_post_status($postId) : '';

        // What 出品する means for this seller: straight to publish for a
        // trusted one, the approval queue for everyone else -- and for a
        // listing that is already live, staying live.
        $target = $current === 'publish'
            ? 'publish'
            : PublishGate::unreviewedStatus(get_current_user_id());

        $live = $target === 'publish';

        printf('<input type="hidden" name="post_status" id="mk_post_status" value="">');

        echo '<div class="mk-submit">';

        printf(
            '<button type="submit" name="dokan_update_product" value="Save Product" '
            . 'class="mk-submit__btn mk-submit__btn--save" data-mk-status="draft">'
            . '商品を保存<small>下書きとして保存します。購入者には表示されません。</small></button>'
        );

        printf(
            '<button type="submit" name="dokan_update_product" value="Save Product" '
            . 'class="mk-submit__btn mk-submit__btn--publish" data-mk-status="%s">'
            . '出品する<small>%s</small></button>',
            esc_attr($target),
            esc_html($live
                ? 'この内容で公開します。購入者が購入できる状態になります。'
                : 'この内容で出品します。運営の承認後に公開されます。')
        );

        echo '</div>';
    }

    /**
     * ⑦ Where the listing went, and what happens next.
     *
     * Dokan's own confirmation is 「商品が保存されました」 and nothing more, so
     * a seller who has just pressed 保存 on a listing that went to the approval
     * queue has no way to tell that from one that went live. The status is the
     * answer to both "where is it" and "what do I do now".
     */
    public static function savedNotice(): void
    {
        if (!isset($_GET['message']) || !isset($_GET['product_id'])) {
            return;
        }

        $productId = (int) $_GET['product_id'];
        $product   = get_post($productId);

        if (!$product || (int) $product->post_author !== get_current_user_id()) {
            return;
        }

        // These two have their own notices explaining a specific problem, and
        // saying "saved as a draft" over the top of them just adds noise.
        if (get_post_meta($productId, PriceGate::META_HELD, true) === 'yes'
            || get_post_meta($productId, PublishGate::META_HELD, true) === 'yes'
        ) {
            return;
        }

        [$class, $heading, $body, $link] = self::statusMessage($product->post_status, $productId);

        printf(
            '<div class="dokan-alert %s mk-saved"><strong>%s</strong><br>%s%s</div>',
            esc_attr($class),
            esc_html($heading),
            $body,
            $link
        );
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function statusMessage(string $status, int $productId): array
    {
        $list = sprintf(
            '<a href="%s">商品一覧</a>',
            esc_url(dokan_get_navigation_url('products'))
        );

        return match ($status) {
            'publish' => [
                'dokan-alert-success',
                '保存しました。この商品は「公開中」です。',
                sprintf('購入者が商品ページを閲覧・購入できる状態です。%sからいつでも編集できます。', $list),
                sprintf(
                    '<br><a class="mk-saved__link" href="%s" target="_blank" rel="noopener">'
                    . '公開中の商品ページを見る</a>',
                    esc_url((string) get_permalink($productId))
                ),
            ],
            'pending' => [
                'dokan-alert-info',
                '保存しました。この商品は「審査待ち」です。',
                sprintf(
                    '運営が内容を確認し、承認すると公開されます。承認されるとメールでお知らせします。'
                    . 'それまでは購入者には表示されません。%sでは「審査待ち」と表示されます。',
                    $list
                ),
                '',
            ],
            'draft' => [
                'dokan-alert-warning',
                '保存しました。この商品は「下書き」です。',
                sprintf(
                    'まだ公開されていません。購入者には表示されませんので、'
                    . '出品する準備ができましたら、この画面の「商品を保存」から公開の手続きへお進みください。'
                    . '保存した内容は%sに残っています。',
                    $list
                ),
                '',
            ],
            default => [
                'dokan-alert-info',
                '保存しました。',
                sprintf('保存した内容は%sでご確認いただけます。', $list),
                '',
            ],
        };
    }

    /**
     * ⑨ A way back from the preview.
     *
     * The permalink opens the public product page, and from there the only
     * route back to the half-finished listing is the browser's back button.
     * Shown only to the person who owns the listing, and to the operator.
     */
    public static function backToEdit(): void
    {
        global $product;

        if (!$product instanceof \WC_Product || !is_user_logged_in()) {
            return;
        }

        $productId = $product->get_id();
        $author    = (int) get_post_field('post_author', $productId);
        $userId    = get_current_user_id();

        if ($userId !== $author && !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!function_exists('dokan_edit_product_url')) {
            return;
        }

        printf(
            '<p class="mk-preview-bar">これは購入者に表示される商品ページです。'
            . '<a class="mk-preview-bar__link" href="%s">編集画面に戻る</a></p>',
            esc_url(dokan_edit_product_url($productId))
        );
    }

    /**
     * The guidance that has no hook to hang on, and the one rule that has to
     * be visible as it happens.
     *
     * Printed only on the seller's product screens. Written against ids that
     * Dokan's template sets explicitly, and every step is guarded: a missing
     * element leaves the form exactly as it was rather than throwing and
     * taking the rest of the page's scripts with it.
     */
    public static function script(): void
    {
        if (!function_exists('dokan_is_seller_dashboard') || !dokan_is_seller_dashboard()) {
            return;
        }

        ?>
<script>
(function () {
    function help(text) {
        var p = document.createElement('p');
        p.className = 'mk-field-help';
        p.innerHTML = text;
        return p;
    }

    function after(el, node) {
        if (el && el.parentNode) {
            el.parentNode.insertBefore(node, el.nextSibling);
        }
    }

    // ⑥ Two description boxes, with nothing saying how they differ.
    var shortLabel = document.querySelector('label[for="post_excerpt"]');
    var longLabel = document.querySelector('label[for="post_content"]');

    if (shortLabel) {
        after(shortLabel, help(
            '商品一覧や商品ページの上部に表示される、<strong>短い紹介文</strong>です（1〜2行程度）。'
            + '<br><span class="mk-field-help__eg">例：オーバーサイズTシャツ。'
            + '数回着用しましたが、目立った傷や汚れはありません。</span>'
        ));
    }

    if (longLabel) {
        after(longLabel, help(
            '商品ページ下部に表示される、<strong>詳しい説明</strong>です。'
            + 'サイズ・素材・着用回数・気になる傷や汚れなど、購入前に知りたいことを書いてください。'
            + '<br><span class="mk-field-help__eg">例：サイズL／着丈約72cm／身幅約58cm／'
            + 'コットン100％／160cm 腰くらいのサイズ感<br>数回着用。目立った傷や汚れはありません。'
            + '<br>自宅保管のため、細かな使用感などはご了承ください。</span>'
        ));
    }

    // ⑨ The permalink box is written by WordPress after the page loads, so it
    // is waited for rather than assumed.
    var slugBox = document.getElementById('edit-slug-box');

    if (slugBox && !document.querySelector('.mk-permalink-help')) {
        var note = help(
            '<strong>パーマリンク</strong>は、公開後にこの商品が表示されるページのアドレスです。'
            + 'リンクを押すと、購入者に見えるページをそのまま確認できます。'
            + '<br><span class="mk-field-help__eg">確認したあとは、ページ上部の「編集画面に戻る」から'
            + 'この画面に戻れます。</span>'
        );
        note.className += ' mk-permalink-help';
        after(slugBox, note);
    }

    // ⑤ The 1点のみ / 在庫あり choice drives Dokan's own two checkboxes, which
    // are hidden but still what gets submitted.
    var stock = document.getElementById('_manage_stock');
    var single = document.getElementById('_sold_individually');
    var modes = document.querySelectorAll('input[name="mk_stock_mode"]');

    function applyMode(mode) {
        if (!stock || !single) {
            return;
        }

        var wantStock = mode === 'stock';

        if (stock.checked !== wantStock) {
            stock.checked = wantStock;

            // Dokan shows and hides the quantity fields from this event, so it
            // has to be told rather than just ticked.
            stock.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (single.checked === wantStock) {
            single.checked = !wantStock;
            single.dispatchEvent(new Event('change', { bubbles: true }));
        }

        document.body.classList.toggle('mk-stock-mode-single', !wantStock);
        document.body.classList.toggle('mk-stock-mode-stock', wantStock);
    }

    // The choice belongs above the fields it governs. Dokan prints it after
    // them, because the hook that exists is at the end of the section.
    var choice = document.querySelector('.mk-stock-choice');
    var skuField = document.getElementById('_sku');
    var section = choice ? choice.closest('.dokan-section-content') : null;

    if (choice && skuField && section) {
        var skuGroup = skuField.closest('.dokan-form-group');

        if (skuGroup && skuGroup.parentNode === section) {
            section.insertBefore(choice, skuGroup.nextSibling);
        }
    }

    if (modes.length) {
        Array.prototype.forEach.call(modes, function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    applyMode(radio.value);
                }
            });

            if (radio.checked) {
                applyMode(radio.value);
            }
        });
    }

    // メッセージ動画 / 通常の商品. The choice sits just below the row holding the
    // product image, where the client asked for it, and decides which half of
    // the form shows. Below the row rather than inside the image column: that
    // column is half the width of a phone, and eight message types with their
    // descriptions wrapped every three or four characters in it.
    var kindBlock = document.querySelector('.mk-kind');
    var shortDescription = document.querySelector('.dokan-product-short-description');

    if (kindBlock && shortDescription && shortDescription.parentNode) {
        shortDescription.parentNode.insertBefore(kindBlock, shortDescription);
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[name="mk_kind"]'), function (radio) {
        radio.addEventListener('change', function () {
            if (radio.checked) {
                var video = radio.value === 'message_video';
                document.body.classList.toggle('mk-kind-message-video', video);
                document.body.classList.toggle('mk-kind-physical', !video);
            }
        });
    });

    // At least one message type, or the listing cannot be ordered at all.
    var productForm = document.querySelector('form.dokan-product-edit-form');

    if (productForm) {
        productForm.addEventListener('submit', function (event) {
            var video = document.querySelector('input[name="mk_kind"][value="message_video"]');

            if (video && video.checked && !document.querySelector('input[name="mk_message_types[]"]:checked')) {
                event.preventDefault();
                alert('対応できるメッセージの種類を1つ以上選んでください。');
            }
        });
    }

    // ⑥ Whichever button was pressed says what the save means.
    var statusField = document.getElementById('mk_post_status');

    Array.prototype.forEach.call(document.querySelectorAll('[data-mk-status]'), function (button) {
        button.addEventListener('click', function () {
            if (statusField) {
                statusField.value = button.getAttribute('data-mk-status');
            }
        });
    });
})();
</script>
        <?php
    }
}
