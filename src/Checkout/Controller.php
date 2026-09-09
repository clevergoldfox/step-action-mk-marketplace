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

    // ------------------------------------------------------------ buy button

    public static function renderBuyButton(): void
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        $status = get_post_status($product->get_id());

        if ($status !== 'publish') {
            echo '<p class="mk-unavailable">この商品は現在購入できません。</p>';

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

        $options = self::optionsFor($product->get_id());

        printf(
            '<form method="post" action="%s" class="mk-buy-form">',
            esc_url(add_query_arg('mk_buy', $product->get_id(), self::checkoutUrl()))
        );

        wp_nonce_field(self::NONCE_BUY);

        if ($options) {
            echo '<div class="mk-options"><p><strong>オプション</strong></p>';

            foreach ($options as $option) {
                printf(
                    '<label style="display:block"><input type="checkbox" name="mk_options[]" value="%d"> %s（+%s）</label>',
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

        $options = isset($_POST['mk_options']) && is_array($_POST['mk_options'])
            ? array_map('intval', wp_unslash($_POST['mk_options']))
            : [];

        try {
            $order = (new OrderBuilder())->create(
                $product,
                wp_get_current_user(),
                $options
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
            </table>

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
