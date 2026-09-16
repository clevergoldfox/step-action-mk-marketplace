<?php
declare(strict_types=1);

namespace MK\Checkout;

use MK\Stripe\Client;
use MK\Stripe\PaymentService;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Product;

/**
 * Buying one item.
 *
 * WooCommerce's cart and checkout are bypassed entirely. This is a C2C
 * marketplace where every listing is a single unique item: a cart implies
 * quantities and multi-seller baskets, and each basket line would need its own
 * escrow, its own transfer and its own hold period. It would also fight the
 * reservation lock, which exists precisely so that two people cannot be at the
 * checkout for the same jacket. One item, one order, one payment.
 *
 * ---------------------------------------------------------------------------
 * The browser never decides anything
 * ---------------------------------------------------------------------------
 * The buyer confirms the payment client-side, and is then returned to a page
 * that reports what the ORDER says, not what the redirect says. Nothing here
 * marks an order paid. Only the payment_intent.succeeded webhook does that,
 * because a return URL can be tampered with, replayed, or simply never
 * reached: a buyer who closes the tab the moment Stripe redirects has still
 * paid, and their order must still complete.
 *
 * So the return page can legitimately show "処理中" for a few seconds while
 * the webhook lands. That is honest, and far better than a client-side
 * confirmation that says "完了" for a payment that later fails.
 *
 * Prices are never read from the request. The product price comes from the
 * product and option prices from mk_product_options, because a posted price
 * is a posted discount.
 */
final class Controller
{
    public const PAGE_OPTION = 'mk_checkout_page_id';

    private const NONCE_BUY = 'mk_buy';

    public static function register(): void
    {
        add_shortcode('mk_checkout', [self::class, 'renderCheckout']);

        add_action('template_redirect', [self::class, 'handleBuy']);

        // Not on plugins_loaded, and not from the installer.
        //
        // wp_insert_post() calls get_permalink(), which dereferences the
        // global $wp_rewrite -- and that object does not exist until init.
        // Creating this page any earlier is a fatal error on EVERY request,
        // which takes the whole site down rather than just this feature.
        add_action('init', [self::class, 'ensurePage'], 100);

        // Replace the add-to-cart button rather than sitting beside it. Two
        // routes to buying the same unique item is two ways to reserve it.
        add_action('wp', static function (): void {
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            add_action('woocommerce_single_product_summary', [self::class, 'renderBuyButton'], 30);
        });

        /*
         * Close every other way into WooCommerce's cart.
         *
         * Removing the button from the single product page was not enough,
         * and the gap was found by a client, not by a test. The shop listing
         * kept rendering its own add-to-cart button on every card: one click
         * from /shop/ put the item in WooCommerce's cart, walked to
         * WooCommerce's checkout, and hit "支払い可能な方法がございません" —
         * because no gateway is configured there, and by design never will be.
         * The platform is merchant of record and the money layer is ours.
         *
         * Nothing was broken; the storefront was simply offering a door that
         * opens onto a wall. So all three routes are shut rather than the one
         * that happened to be reported:
         *
         *   the listing button becomes a link to the product
         *   the cart refuses every addition, whatever the source
         *   the cart and checkout pages send visitors back to the shop
         *
         * The second matters most. A bookmarked ?add-to-cart= URL, a block, a
         * theme template or a future plugin can all reach the cart without
         * going near a button we filtered.
         */
        add_filter('woocommerce_loop_add_to_cart_link', [self::class, 'loopButton'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', '__return_false', 99);
        add_action('template_redirect', [self::class, 'blockCartPages']);

        /*
         * And again at the block layer, because the theme is a block theme.
         *
         * Removing woocommerce_template_single_add_to_cart from the
         * woocommerce_single_product_summary hook does nothing to the
         * woocommerce/add-to-cart-form BLOCK: it renders the same template
         * directly, without going through that hook at all. On Twenty
         * Twenty-Five the product page therefore still showed
         * 「お買い物カゴに追加」, and because cart additions are refused it
         * silently bounced the visitor back to the product — a dead button
         * that looks like a broken site.
         *
         * The mini-cart block in the header is the same problem wearing a
         * different hat: a cart icon leading to a cart that redirects away.
         *
         * Classic hooks and block rendering are two separate surfaces, and a
         * block theme uses the second. Filtering one is not filtering the
         * other.
         */
        add_filter('render_block', [self::class, 'suppressCartBlocks'], 10, 2);

        // A second way back to an unpaid order, from the account order list.
        // The product page link only helps someone who still has that tab.
        add_filter('woocommerce_my_account_my_orders_actions', [self::class, 'orderActions'], 10, 2);
    }

    public static function checkoutUrl(): string
    {
        $pageId = (int) get_option(self::PAGE_OPTION);

        return $pageId > 0 ? (string) get_permalink($pageId) : home_url('/');
    }

    /**
     * The page that hosts the payment form.
     *
     * WooCommerce's own checkout is not used, so this page is ours and has to
     * exist for anyone to be able to pay at all.
     *
     * Re-checked on every load rather than created once, because the stored id
     * can outlive the page: someone trashes it while tidying up, and the buy
     * button then points at nothing. The stored id is trusted only while a
     * published page still answers to it. The check is one cached option read
     * plus one cached post lookup, so the common path costs nothing.
     */
    public static function ensurePage(): void
    {
        $existing = (int) get_option(self::PAGE_OPTION);

        if ($existing > 0) {
            $page = get_post($existing);

            if ($page && $page->post_status === 'publish') {
                return;
            }
        }

        $pageId = wp_insert_post([
            'post_title'     => 'お支払い',
            'post_name'      => 'mk-checkout',
            'post_content'   => '[mk_checkout]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);

        if (!is_wp_error($pageId)) {
            update_option(self::PAGE_OPTION, (int) $pageId);
        }
    }

    /**
     * On a listing card, send people to the product instead of the cart.
     *
     * Not a buy button: the item may carry options that have to be chosen,
     * and buying from a grid would skip that. The product page is where the
     * real purchase path lives.
     *
     * @param string $html the add-to-cart markup WooCommerce built
     */
    public static function loopButton(string $html, $product): string
    {
        if (!$product instanceof WC_Product) {
            return $html;
        }

        $sold = in_array(get_post_status($product->get_id()), ['mk-sold', 'mk-reserved'], true);

        return sprintf(
            '<a href="%s" class="button %s">%s</a>',
            esc_url((string) get_permalink($product->get_id())),
            $sold ? 'disabled' : '',
            $sold ? '売切れ' : '詳細を見る'
        );
    }

    /**
     * Offer "pay now" on an unpaid order, and drop WooCommerce's own version.
     *
     * WooCommerce adds a `pay` action pointing at its checkout, which cannot
     * take payment here — the same dead end in a different place.
     *
     * @param array<string,array{url:string,name:string}> $actions
     * @return array<string,array{url:string,name:string}>
     */
    public static function orderActions(array $actions, $order): array
    {
        unset($actions['pay']);

        if (!$order instanceof \WC_Order || $order->get_status() !== 'pending') {
            return $actions;
        }

        $actions['mk_pay'] = [
            'url'  => add_query_arg('mk_order', $order->get_id(), self::checkoutUrl()),
            'name' => 'お支払いに進む',
        ];

        return $actions;
    }

    /**
     * Blocks that lead to a cart this marketplace does not use.
     *
     * @param string              $content rendered block HTML
     * @param array<string,mixed> $block   block, including its blockName
     */
    public static function suppressCartBlocks(string $content, array $block): string
    {
        $name = (string) ($block['blockName'] ?? '');

        if ($name === '') {
            return $content;
        }

        // The buy form on a product page. Renders the add-to-cart template
        // directly, so removing the classic hook does nothing to it.
        if ($name === 'woocommerce/add-to-cart-form' || $name === 'woocommerce/add-to-cart-with-options') {
            return '';
        }

        /*
         * The mini-cart, by prefix.
         *
         * Matching 'woocommerce/mini-cart' exactly removed nothing: what
         * actually renders is mini-cart-contents and a handful of children
         * (title-items-counter, products-table, footer...). The wrapper name
         * is not the name in the output, and checking the page rather than
         * trusting the filter is what showed it.
         */
        if (str_starts_with($name, 'woocommerce/mini-cart')) {
            return '';
        }

        /*
         * woocommerce/product-button is deliberately NOT suppressed.
         *
         * It honours woocommerce_loop_add_to_cart_link, which already turns
         * it into a 詳細を見る link to the product. Blanking the block removed
         * the replacement too and left listing cards with no call to action
         * at all -- a fix that broke the thing it was fixing.
         */
        return $content;
    }

    /**
     * The cart and checkout pages cannot work here, so nobody should land on
     * them. They exist only because WooCommerce creates them on install.
     */
    public static function blockCartPages(): void
    {
        if (!function_exists('is_cart')) {
            return;
        }

        if (is_cart() || is_checkout()) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
    }

    // ------------------------------------------------------------ buy button

    public static function renderBuyButton(): void
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        // Before anything else, and before every early return below.
        //
        // bailToProduct() has always sent the buyer back here with the reason
        // in the URL, and nothing has ever displayed it. Every refusal --
        // sold, gone, creator not payable, no price -- therefore looked
        // identical from the buyer's chair: press 購入手続きへ, the page
        // reloads, nothing happens. The client reported it as a dead button,
        // which is exactly what it was.
        self::renderError();

        $status = get_post_status($product->get_id());

        if ($status !== 'publish') {
            self::renderUnavailable($product, $status);

            return;
        }

        // A price an order cannot be built from, checked before anything is
        // asked of the visitor. PriceGate keeps such a listing out of publish,
        // so reaching this means the price was emptied by a route that did not
        // re-save the post -- and a button that cannot work must not be
        // offered, nor a login walked into on the way to one.
        if (!\MK\Product\PriceGate::isSellable(\MK\Product\PriceGate::priceOf($product->get_id()))) {
            echo '<p class="mk-unavailable"><strong>この商品は現在購入できません</strong><br>'
                . '販売価格が設定されていないため、購入手続きに進めません。'
                . '出品者の方は、商品編集画面から価格をご設定ください。</p>';

            return;
        }

        if (!is_user_logged_in()) {
            printf(
                '<p><a href="%s" class="button">ログインして購入する</a></p>',
                esc_url(wp_login_url(get_permalink($product->get_id())))
            );

            return;
        }

        // A creator buying their own item would be paying themselves through
        // the platform's fee, and would break the payout authorisation check.
        if ((int) get_post_field('post_author', $product->get_id()) === get_current_user_id()) {
            echo '<p class="mk-unavailable">ご自身の商品は購入できません。</p>';

            return;
        }

        $isVideo = \MK\Product\MessageVideo::isMessageVideo($product->get_id());

        // A message video with no type on offer cannot be ordered: the buyer
        // would have nothing to choose and the creator nothing to record.
        if ($isVideo && !\MK\Product\MessageVideo::supportedTypes($product->get_id())) {
            echo '<p class="mk-unavailable"><strong>現在リクエストを受け付けていません</strong><br>'
                . 'このクリエイターが受け付けているメッセージの種類が、現在ありません。</p>';

            return;
        }

        // Options are for goods -- gift wrapping, a card. A video is made to
        // the buyer's request, and the request takes their place.
        $options = $isVideo ? [] : self::optionsFor($product->get_id());

        printf(
            '<form method="post" action="%s" class="mk-buy-form">',
            esc_url(add_query_arg('mk_buy', $product->get_id(), self::checkoutUrl()))
        );

        wp_nonce_field(self::NONCE_BUY);

        if ($isVideo) {
            echo \MK\Product\MessageVideo::renderBuyFields($product->get_id()); // escaped inside
        }

        if ($options) {
            echo '<div class="mk-options"><p><strong>オプション</strong></p>';

            foreach ($options as $option) {
                printf(
                    '<label class="mk-option"><input type="checkbox" name="mk_options[]" value="%d">'
                    . '<span class="mk-option-name">%s</span>'
                    . '<span class="mk-option-price">+%s</span></label>',
                    (int) $option->option_group_id,
                    esc_html($option->name),
                    esc_html(number_format((int) $option->price) . '円')
                );
            }

            echo '</div>';
        }

        echo '<button type="submit" class="single_add_to_cart_button button alt">購入手続きへ</button>';
        echo '</form>';
    }

    /**
     * Whatever went wrong on the last attempt, said out loud.
     *
     * The message is put in the URL by bailToProduct(), so it is attacker
     * controllable and is escaped as text rather than trusted as markup.
     */
    private static function renderError(): void
    {
        if (empty($_GET['mk_error'])) {
            return;
        }

        $message = sanitize_text_field(rawurldecode((string) wp_unslash($_GET['mk_error'])));

        // Sanitising can empty the value entirely -- it does for a markup
        // payload -- and an empty red box is its own kind of broken.
        if ($message === '') {
            return;
        }

        printf('<p class="mk-error" role="alert">%s</p>', esc_html($message));
    }

    /**
     * Why this item cannot be bought right now.
     *
     * The three cases read very differently to the person in front of them,
     * and collapsing them into "購入できません" is what made a working
     * reservation look like a broken site. In particular the buyer who is
     * mid-checkout gets their way back: they are not blocked by the hold,
     * they are the reason for it.
     */
    private static function renderUnavailable(WC_Product $product, string $status): void
    {
        if ($status === \MK\Product\Statuses::SOLD) {
            echo '<p class="mk-unavailable"><strong>売り切れました</strong><br>'
                . 'この商品は他の方が購入されました。</p>';

            return;
        }

        if ($status !== \MK\Product\Statuses::RESERVED) {
            echo '<p class="mk-unavailable">この商品は現在購入できません。</p>';

            return;
        }

        $holder = (int) get_post_meta($product->get_id(), '_mk_reserved_by', true);

        if ($holder > 0 && $holder === get_current_user_id()) {
            $order = self::pendingOrderFor($product->get_id(), $holder);

            echo '<p class="mk-unavailable"><strong>お手続き中の商品です</strong><br>'
                . 'お支払いが完了していません。下記より続きからお手続きいただけます。</p>';

            if ($order > 0) {
                printf(
                    '<p><a href="%s" class="single_add_to_cart_button button alt">お支払いに進む</a></p>',
                    esc_url(add_query_arg('mk_order', $order, self::checkoutUrl()))
                );
            }

            return;
        }

        $until = (string) get_post_meta($product->get_id(), '_mk_reserved_until', true);

        printf(
            '<p class="mk-unavailable"><strong>他の方がお手続き中です</strong><br>'
            . 'この商品は現在、別の方が購入手続き中です。%s'
            . 'お手続きが完了しなかった場合は、再度ご購入いただけるようになります。</p>',
            $until !== ''
                ? esc_html(sprintf('%s頃まで確保されています。', get_date_from_gmt($until, 'H:i')))
                : ''
        );
    }

    /** The buyer's own unpaid order for this product, if there is one. */
    private static function pendingOrderFor(int $productId, int $userId): int
    {
        $orders = wc_get_orders([
            'customer_id' => $userId,
            'status'      => ['pending'],
            'limit'       => 5,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        foreach ($orders as $order) {
            if ((int) $order->get_meta('_mk_product_id') === $productId) {
                return $order->get_id();
            }
        }

        return 0;
    }

    /** @return array<int,object> */
    private static function optionsFor(int $productId): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT po.option_group_id, po.price, og.name
                   FROM {$wpdb->prefix}mk_product_options po
                   JOIN {$wpdb->prefix}mk_option_groups og ON og.id = po.option_group_id
                  WHERE po.product_id = %d AND po.is_offered = 1 AND og.is_active = 1
               ORDER BY og.sort_order ASC",
                $productId
            )
        ) ?: [];
    }

    // ------------------------------------------------------------- buy action

    public static function handleBuy(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_buy'])) {
            return;
        }

        check_admin_referer(self::NONCE_BUY);

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $product = wc_get_product((int) $_GET['mk_buy']);

        if (!$product instanceof WC_Product) {
            self::bailToProduct(0, 'この商品は見つかりませんでした。');
        }

        $isVideo = \MK\Product\MessageVideo::isMessageVideo($product->get_id());

        $options = !$isVideo && isset($_POST['mk_options']) && is_array($_POST['mk_options'])
            ? array_map('intval', wp_unslash($_POST['mk_options']))
            : [];

        try {
            // Validated before any order exists, so a bad request never leaves
            // a stray unpaid order behind. Its message is buyer-facing and is
            // shown on the product page by renderError().
            $request = $isVideo
                ? \MK\Product\MessageVideo::validateRequest($product->get_id(), $_POST)
                : [];

            $order = (new OrderBuilder())->create(
                $product,
                wp_get_current_user(),
                $options,
                $request
            );

            (new PaymentService())->createIntent(
                $order,
                (int) $order->get_meta('_mk_product_amount'),
                (int) $order->get_meta('_mk_option_amount')
            );
        } catch (RuntimeException $e) {
            // OrderBuilder throws these with buyer-facing Japanese messages:
            // the item is gone, or the creator cannot be paid.
            self::bailToProduct($product->get_id(), $e->getMessage());
        } catch (Throwable $e) {
            error_log('[mk-marketplace] checkout failed: ' . $e->getMessage());
            self::bailToProduct($product->get_id(), '購入手続きを開始できませんでした。時間をおいてお試しください。');
        }

        wp_safe_redirect(add_query_arg('mk_order', $order->get_id(), self::checkoutUrl()));
        exit;
    }

    private static function bailToProduct(int $productId, string $message): void
    {
        $url = $productId > 0 ? get_permalink($productId) : home_url('/');

        wp_safe_redirect(add_query_arg('mk_error', rawurlencode($message), $url));
        exit;
    }

    // ---------------------------------------------------------- checkout page

    public static function renderCheckout(): string
    {
        if (!is_user_logged_in()) {
            return '<p>購入手続きにはログインが必要です。</p>';
        }

        $orderId = isset($_GET['mk_order']) ? (int) $_GET['mk_order'] : 0;
        $order   = $orderId > 0 ? wc_get_order($orderId) : null;

        if (!$order instanceof WC_Order) {
            return '<p>購入手続きの情報が見つかりませんでした。</p>';
        }

        if ($order->get_customer_id() !== get_current_user_id()) {
            return '<p>この注文を表示する権限がありません。</p>';
        }

        // Returning from Stripe: report the order, not the redirect.
        if (isset($_GET['payment_intent'])) {
            return self::renderResult($order);
        }

        if ($order->get_status() !== 'pending') {
            return self::renderResult($order);
        }

        return self::renderPaymentForm($order);
    }

    private static function renderPaymentForm(WC_Order $order): string
    {
        // A parcel needs somewhere to go before it is paid for. The card form,
        // and the client secret that goes with it, are not rendered until the
        // order carries an address. See ShippingAddress.
        $needsShipping = ShippingAddress::needsShipping($order);

        if ($needsShipping
            && (!ShippingAddress::hasAddress($order) || isset($_GET['mk_edit_address']) || ShippingAddress::hasErrors())
        ) {
            return ShippingAddress::renderForm($order);
        }

        $intentId = (string) $order->get_meta(PaymentService::META_INTENT_ID);

        if ($intentId === '') {
            return '<p>決済情報を準備できませんでした。もう一度お試しください。</p>';
        }

        try {
            $secret = (string) Client::get()->paymentIntents->retrieve($intentId)->client_secret;
        } catch (Throwable $e) {
            error_log('[mk-marketplace] could not retrieve intent ' . $intentId . ': ' . $e->getMessage());

            return '<p>決済情報を取得できませんでした。時間をおいてお試しください。</p>';
        }

        $returnUrl = add_query_arg('mk_order', $order->get_id(), self::checkoutUrl());

        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        wp_add_inline_script('stripe-js', sprintf(
            'window.mkCheckout = %s;',
            wp_json_encode([
                'pk'        => Client::publishableKey(),
                'secret'    => $secret,
                'returnUrl' => $returnUrl,
            ])
        ), 'after');
        wp_add_inline_script('stripe-js', self::script(), 'after');

        ob_start();
        ?>
        <div class="mk-checkout">
            <h2>お支払い</h2>

            <table class="shop_table">
                <tr>
                    <th>商品</th>
                    <td><?php echo esc_html((string) $order->get_meta('_mk_title_snapshot')); ?></td>
                </tr>
                <tr>
                    <th>販売価格</th>
                    <td><?php echo esc_html(number_format((int) $order->get_meta('_mk_product_amount')) . '円'); ?></td>
                </tr>
                <?php if ($needsShipping) : ?>
                    <tr>
                        <th>送料</th>
                        <td>販売価格に含まれています（追加の送料はかかりません）</td>
                    </tr>
                <?php endif; ?>
                <?php
                $optionLabel  = (string) $order->get_meta('_mk_option_snapshot');
                $optionAmount = (int) $order->get_meta('_mk_option_amount');

                if ($optionLabel !== '') :
                    ?>
                    <tr>
                        <th>オプション</th>
                        <td><?php
                            printf(
                                '%s（+%s円）',
                                esc_html($optionLabel),
                                esc_html(number_format($optionAmount))
                            );
                        ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>お支払い金額</th>
                    <td><strong><?php
                        echo esc_html(number_format((int) $order->get_total()) . '円');
                    ?></strong></td>
                </tr>
                <?php if ($needsShipping) : ?>
                    <tr>
                        <th>お届け先</th>
                        <td><?php echo ShippingAddress::renderSummary($order); // escaped inside ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>お支払い方法</th>
                    <td>クレジットカード等（下記よりお選びください）</td>
                </tr>
            </table>

            <div class="mk-checkout-terms">
                <p>購入者のご都合によるキャンセル・返品・返金は、原則としてお受けしておりません。
                <?php if (!$needsShipping) : ?>
                    デジタルコンテンツ（メッセージ動画を含みます）は、提供後の返金もお受けしておりません。
                <?php endif; ?>
                商品の未着や説明との相違など、クリエイター側に問題がある場合は、取引画面からお申し出ください。</p>
                <p><?php
                    $links = array_filter([
                        \MK\Account\Terms::url('customer') !== ''
                            ? sprintf('<a href="%s" target="_blank" rel="noopener">利用規約</a>', esc_url(\MK\Account\Terms::url('customer')))
                            : '',
                        \MK\Account\Terms::privacyUrl() !== ''
                            ? sprintf('<a href="%s" target="_blank" rel="noopener">プライバシーポリシー</a>', esc_url(\MK\Account\Terms::privacyUrl()))
                            : '',
                    ]);

                    echo implode('・', $links); // built from escaped parts
                    echo $links ? 'をご確認のうえ、' : '';
                ?>「支払う」を押すと、上記の内容で購入が確定します。</p>
            </div>

            <form id="mk-payment-form">
                <div id="mk-payment-element"></div>
                <button id="mk-submit" class="button alt" type="submit">
                    <span id="mk-submit-text">支払う</span>
                </button>
                <div id="mk-payment-message" role="alert" style="display:none"></div>
            </form>

            <p><small>お支払い情報は Stripe が直接処理します。当サイトではカード番号を保持しません。</small></p>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private static function script(): string
    {
        return <<<'JS'
(function () {
    var cfg = window.mkCheckout;
    if (!cfg) { return; }

    var stripe    = Stripe(cfg.pk);
    var elements  = stripe.elements({ clientSecret: cfg.secret });
    var form      = document.getElementById('mk-payment-form');
    var button    = document.getElementById('mk-submit');
    var message   = document.getElementById('mk-payment-message');

    elements.create('payment').mount('#mk-payment-element');

    function show(text) {
        message.textContent = text;
        message.style.display = 'block';
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        // Guard against a double submit creating a second charge attempt.
        button.disabled = true;
        message.style.display = 'none';

        stripe.confirmPayment({
            elements: elements,
            confirmParams: { return_url: cfg.returnUrl }
        }).then(function (result) {
            // Reached only when the payment did NOT redirect, which for our
            // purposes always means it failed. A success redirects away.
            if (result.error) {
                show(result.error.message || '決済に失敗しました。');
                button.disabled = false;
            }
        });
    });
})();
JS;
    }

    private static function renderResult(WC_Order $order): string
    {
        $status = $order->get_status();

        if ($status === 'pending') {
            // The webhook has not arrived yet. Say so plainly and reload
            // rather than claiming an outcome we do not have.
            return '<div class="mk-checkout-result">'
                . '<h2>お支払いを確認しています</h2>'
                . '<p>決済の確認中です。この画面は自動的に更新されます。</p>'
                . '<p><small>数分経っても変わらない場合は、注文履歴からご確認ください。</small></p>'
                . '<meta http-equiv="refresh" content="3">'
                . '</div>';
        }

        if ($status === 'failed') {
            return '<div class="mk-checkout-result">'
                . '<h2>決済に失敗しました</h2>'
                . '<p>お支払いを完了できませんでした。商品は他の方が購入できる状態に戻っています。</p>'
                . '</div>';
        }

        return '<div class="mk-checkout-result">'
            . '<h2>ご購入ありがとうございます</h2>'
            . '<p>お支払いが完了しました。出品者が発送を登録すると、'
            . 'ご登録のメールアドレスへお知らせいたします。</p>'
            . sprintf(
                '<p><a href="%s" class="button">注文の詳細を見る</a></p>',
                esc_url($order->get_view_order_url())
            )
            . '</div>';
    }
}
