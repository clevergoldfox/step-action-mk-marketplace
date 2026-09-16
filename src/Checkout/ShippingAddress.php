<?php
declare(strict_types=1);

namespace MK\Checkout;

use MK\Order\Statuses;
use MK\Product\MessageVideo;
use WC_Order;
use WC_Product;

/**
 * The buyer's delivery address: asked for at checkout, shown to the creator,
 * and taken away from the creator once the sale is over.
 *
 * Nothing collected an address before this class existed. Orders were paid
 * with every shipping field empty, so a creator had an order to fulfil and no
 * idea where to send it. The design always assumed the address would reach
 * the creator -- there is no anonymous shipping here -- it just never got
 * built.
 *
 * ---------------------------------------------------------------------------
 * Asked for on the payment page, before the card form
 * ---------------------------------------------------------------------------
 * The order already exists by then, so the address is saved straight onto it.
 * The card form is not rendered at all until an address is saved: the Stripe
 * client secret is only handed to a page that already has one, so there is no
 * route to paying for a parcel with nowhere to send it.
 *
 * Message videos and listings marked as not shipped never ask. Whether an
 * order needs an address is decided once, when the order is built, and stored
 * on it -- a listing edited later does not change what the buyer agreed to.
 *
 * ---------------------------------------------------------------------------
 * What the creator sees, and for how long
 * ---------------------------------------------------------------------------
 * Name, postcode, address and phone number: what a carrier needs, and nothing
 * else. Dokan's own order page would also show the buyer's email address and
 * IP, and would keep showing everything forever, so its address and customer
 * blocks are switched off and this class draws the address itself.
 *
 * The privacy policy promises the creator loses sight of the address 30 days
 * after the sale completes (受取確認). A cancelled or refunded order hides it
 * at once: there is nothing left to post. The operator still sees everything
 * in wp-admin -- the data is hidden from the creator, not deleted.
 */
final class ShippingAddress
{
    public const META_NEEDS  = '_mk_needs_shipping';
    public const META_MASKED = '_mk_shipping_masked';

    private const NONCE = 'mk_shipping_address';

    /** @var string[] problems with the address sent on this request */
    private static array $errors = [];

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'handleSubmit']);

        add_action('dokan_order_detail_after_order_items', [self::class, 'renderForCreator'], 5);
        add_filter('dokan_order_details_show_billing_address', '__return_false');
        add_filter('dokan_order_details_show_shipping_address', '__return_false');
        add_filter('option_dokan_selling', [self::class, 'hideCustomerInfo']);
    }

    // ---------------------------------------------------------------- reads

    /**
     * The address fields: key => [label, required, max length].
     *
     * Keys are WooCommerce's shipping properties, so the address lands where
     * wp-admin, emails and the buyer's order page already look for it.
     *
     * @return array<string, array{0:string, 1:bool, 2:int}>
     */
    public static function fields(): array
    {
        return [
            'last_name'  => ['姓', true, 30],
            'first_name' => ['名', true, 30],
            'postcode'   => ['郵便番号', true, 8],
            'state'      => ['都道府県', true, 4],
            'city'       => ['市区町村', true, 50],
            'address_1'  => ['番地', true, 100],
            'address_2'  => ['建物名・部屋番号', false, 100],
            'phone'      => ['電話番号', true, 13],
        ];
    }

    /** @return array<string, string> JP01 => 北海道, ... */
    public static function prefectures(): array
    {
        $states = function_exists('WC') ? WC()->countries->get_states('JP') : [];

        return is_array($states) ? $states : [];
    }

    public static function productNeedsShipping(WC_Product $product): bool
    {
        return !MessageVideo::isMessageVideo($product->get_id()) && !$product->is_virtual();
    }

    public static function needsShipping(WC_Order $order): bool
    {
        $stored = (string) $order->get_meta(self::META_NEEDS);

        // Orders built before this existed carry no flag. A message video is
        // the only kind that was never shipped.
        return $stored !== '' ? $stored === 'yes' : !MessageVideo::isMessageVideoOrder($order);
    }

    public static function hasAddress(WC_Order $order): bool
    {
        return $order->get_shipping_last_name() !== ''
            && $order->get_shipping_postcode() !== ''
            && $order->get_shipping_state() !== ''
            && $order->get_shipping_city() !== ''
            && $order->get_shipping_address_1() !== ''
            && $order->get_shipping_phone() !== '';
    }

    public static function isMasked(WC_Order $order): bool
    {
        return $order->get_meta(self::META_MASKED) === 'yes';
    }

    /** Should the creator be able to read this order's address right now? */
    public static function visibleToCreator(WC_Order $order): bool
    {
        return !self::isMasked($order)
            && in_array($order->get_status(), [Statuses::PAID, Statuses::SHIPPED, Statuses::RECEIVED, 'completed'], true);
    }

    public static function hasErrors(): bool
    {
        return self::$errors !== [];
    }

    /**
     * The address as the lines a label is written from.
     *
     * @return string[]
     */
    public static function lines(WC_Order $order): array
    {
        $prefectures = self::prefectures();

        return array_values(array_filter([
            '〒' . $order->get_shipping_postcode(),
            ($prefectures[$order->get_shipping_state()] ?? $order->get_shipping_state())
                . $order->get_shipping_city() . $order->get_shipping_address_1(),
            $order->get_shipping_address_2(),
            trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name()) . ' 様',
            'TEL ' . $order->get_shipping_phone(),
        ], static fn (string $line): bool => $line !== ''));
    }

    // ----------------------------------------------------------- validation

    /**
     * Check and tidy an address as typed.
     *
     * Japanese keyboards produce full-width digits and every flavour of dash,
     * and people type postcodes and phone numbers with and without hyphens.
     * All of that is accepted and stored in one shape, so a creator copying
     * the address onto a label never meets 〒１５０ー０００１.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, string>, 1: string[]} clean values, problems
     */
    public static function normalise(array $input): array
    {
        $clean  = [];
        $errors = [];

        foreach (self::fields() as $key => [$label, $required, $max]) {
            $value = trim((string) ($input[$key] ?? ''));
            $value = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'as') : $value;
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            $value = sanitize_text_field($value);

            if ($key === 'postcode') {
                $digits = preg_replace('/\D/', '', $value) ?? '';

                if ($value === '') {
                    $errors[] = '郵便番号を入力してください。';
                } elseif (strlen($digits) !== 7) {
                    $errors[] = '郵便番号は7桁の数字で入力してください。';
                }

                $clean[$key] = strlen($digits) === 7 ? substr($digits, 0, 3) . '-' . substr($digits, 3) : $value;

                continue;
            }

            if ($key === 'phone') {
                $digits = preg_replace('/\D/', '', $value) ?? '';

                if ($value === '') {
                    $errors[] = '電話番号を入力してください。';
                } elseif (!preg_match('/^0\d{9,10}$/', $digits)) {
                    $errors[] = '電話番号は、0から始まる10桁または11桁の数字で入力してください。';
                }

                $clean[$key] = $digits;

                continue;
            }

            if ($key === 'state') {
                if (!isset(self::prefectures()[$value])) {
                    $errors[] = '都道府県を選んでください。';
                }

                $clean[$key] = $value;

                continue;
            }

            if ($required && $value === '') {
                $errors[] = sprintf('%sを入力してください。', $label);
            } elseif (mb_strlen($value) > $max) {
                $errors[] = sprintf('%sは%d文字以内で入力してください。', $label, $max);
            }

            $clean[$key] = $value;
        }

        return [$clean, $errors];
    }

    // ------------------------------------------------------------------ save

    /** @param array<string, string> $clean output of normalise() with no errors */
    public static function saveToOrder(WC_Order $order, array $clean): void
    {
        $order->set_shipping_country('JP');
        $order->set_shipping_last_name($clean['last_name']);
        $order->set_shipping_first_name($clean['first_name']);
        $order->set_shipping_postcode($clean['postcode']);
        $order->set_shipping_state($clean['state']);
        $order->set_shipping_city($clean['city']);
        $order->set_shipping_address_1($clean['address_1']);
        $order->set_shipping_address_2($clean['address_2']);
        $order->set_shipping_phone($clean['phone']);
        $order->save();
    }

    /**
     * Remember the address for next time, as WooCommerce's own account fields.
     *
     * @param array<string, string> $clean
     */
    public static function saveToProfile(int $userId, array $clean): void
    {
        update_user_meta($userId, 'shipping_country', 'JP');

        foreach ($clean as $key => $value) {
            update_user_meta($userId, 'shipping_' . $key, $value);
        }
    }

    /**
     * What the form starts with: the order's own address, else the account's.
     *
     * @return array<string, string>
     */
    public static function prefill(WC_Order $order, int $userId): array
    {
        if (self::hasAddress($order)) {
            return [
                'last_name'  => $order->get_shipping_last_name(),
                'first_name' => $order->get_shipping_first_name(),
                'postcode'   => $order->get_shipping_postcode(),
                'state'      => $order->get_shipping_state(),
                'city'       => $order->get_shipping_city(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'phone'      => $order->get_shipping_phone(),
            ];
        }

        $values = [];

        foreach (array_keys(self::fields()) as $key) {
            $values[$key] = (string) get_user_meta($userId, 'shipping_' . $key, true);
        }

        foreach (['last_name', 'first_name', 'phone'] as $key) {
            if ($values[$key] === '') {
                $values[$key] = (string) get_user_meta($userId, 'billing_' . $key, true);
            }
        }

        return $values;
    }

    public static function handleSubmit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['mk_shipping_submit'])) {
            return;
        }

        $userId = get_current_user_id();
        $order  = wc_get_order(isset($_POST['mk_order']) ? (int) $_POST['mk_order'] : 0);

        if ($userId === 0
            || !$order instanceof WC_Order
            || $order->get_customer_id() !== $userId
            || $order->get_status() !== 'pending'
            || !self::needsShipping($order)
        ) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_mk_shipping_nonce'] ?? '')));

        if (!wp_verify_nonce($nonce, self::NONCE . '_' . $order->get_id())) {
            self::$errors = ['画面の有効期限が切れました。お手数ですが、もう一度入力してください。'];

            return;
        }

        $input = isset($_POST['mk_shipping']) && is_array($_POST['mk_shipping']) ? wp_unslash($_POST['mk_shipping']) : [];

        [$clean, $errors] = self::normalise($input);

        if ($errors !== []) {
            self::$errors = $errors;   // the page renders later in this request, with the form refilled

            return;
        }

        self::saveToOrder($order, $clean);

        if (!empty($_POST['mk_shipping_remember'])) {
            self::saveToProfile($userId, $clean);
        }

        wp_safe_redirect(add_query_arg('mk_order', $order->get_id(), Controller::checkoutUrl()));
        exit;
    }

    // --------------------------------------------------------------- render

    public static function renderForm(WC_Order $order): string
    {
        $userId = get_current_user_id();
        $values = self::$errors !== [] && isset($_POST['mk_shipping']) && is_array($_POST['mk_shipping'])
            ? array_map(static fn ($v): string => sanitize_text_field((string) $v), wp_unslash($_POST['mk_shipping']))
            : self::prefill($order, $userId);

        ob_start();

        echo '<div class="mk-checkout mk-shipping-step">';
        echo '<h2>お届け先の入力</h2>';
        printf(
            '<p class="mk-shipping-step__item">%s</p>',
            esc_html((string) $order->get_meta('_mk_title_snapshot'))
        );
        echo '<p>商品のお届け先を入力してください。入力いただいた内容は、発送のためにクリエイターへ表示されます。</p>';

        if (self::$errors !== []) {
            echo '<div class="mk-error" role="alert"><ul>';

            foreach (self::$errors as $message) {
                printf('<li>%s</li>', esc_html($message));
            }

            echo '</ul></div>';
        }

        printf('<form method="post" action="%s" class="mk-shipping-form">', esc_url(add_query_arg('mk_order', $order->get_id(), Controller::checkoutUrl())));
        wp_nonce_field(self::NONCE . '_' . $order->get_id(), '_mk_shipping_nonce');
        printf('<input type="hidden" name="mk_order" value="%d">', $order->get_id());
        echo '<input type="hidden" name="mk_shipping_submit" value="1">';

        $text = static function (string $key, string $type, string $autocomplete, string $placeholder = '') use ($values): void {
            [$label, $required, $max] = self::fields()[$key];

            printf(
                '<p class="mk-shipping-field mk-shipping-field--%1$s"><label for="mk_shipping_%1$s">%2$s%3$s</label>'
                . '<input type="%4$s" id="mk_shipping_%1$s" name="mk_shipping[%1$s]" value="%5$s" maxlength="%6$d" autocomplete="%7$s" placeholder="%8$s"%9$s></p>',
                esc_attr($key),
                esc_html($label),
                $required ? '<span class="required">*</span>' : '<small>（任意）</small>',
                esc_attr($type),
                esc_attr($values[$key] ?? ''),
                $max + 10,
                esc_attr($autocomplete),
                esc_attr($placeholder),
                $required ? ' required' : ''
            );
        };

        echo '<div class="mk-shipping-row">';
        $text('last_name', 'text', 'shipping family-name', '山田');
        $text('first_name', 'text', 'shipping given-name', '花子');
        echo '</div>';

        $text('postcode', 'text', 'shipping postal-code', '123-4567');

        echo '<p class="mk-shipping-field mk-shipping-field--state"><label for="mk_shipping_state">都道府県<span class="required">*</span></label>'
            . '<select id="mk_shipping_state" name="mk_shipping[state]" autocomplete="shipping address-level1" required>'
            . '<option value="">選択してください</option>';

        foreach (self::prefectures() as $code => $name) {
            printf('<option value="%s"%s>%s</option>', esc_attr($code), selected($values['state'] ?? '', $code, false), esc_html($name));
        }

        echo '</select></p>';

        $text('city', 'text', 'shipping address-level2', '渋谷区');
        $text('address_1', 'text', 'shipping address-line1', '神宮前1-2-3');
        $text('address_2', 'text', 'shipping address-line2', 'トレジャーマンション101');
        $text('phone', 'tel', 'shipping tel', '090-1234-5678');

        echo '<p class="mk-shipping-remember"><label><input type="checkbox" name="mk_shipping_remember" value="1" checked> '
            . 'このお届け先をアカウントに保存し、次回から自動で入力する</label></p>';

        echo '<p><button type="submit" class="button alt">お届け先を確定して、お支払いへ進む</button></p>';
        echo '</form></div>';

        return (string) ob_get_clean();
    }

    /** The saved address on the payment page, with a way back to change it. */
    public static function renderSummary(WC_Order $order): string
    {
        return sprintf(
            '%s<br><a href="%s" class="mk-shipping-change">お届け先を変更する</a>',
            implode('<br>', array_map('esc_html', self::lines($order))),
            esc_url(add_query_arg(['mk_order' => $order->get_id(), 'mk_edit_address' => 1], Controller::checkoutUrl()))
        );
    }

    public static function renderForCreator(WC_Order $order): void
    {
        // Same audience as the shipping form beside it.
        if ((int) $order->get_meta('_mk_creator_id') !== get_current_user_id()
            && !current_user_can('manage_woocommerce')
        ) {
            return;
        }

        if (!self::needsShipping($order)) {
            return;
        }

        echo '<div class="dokan-panel dokan-panel-default mk-ship-to">'
            . '<div class="dokan-panel-heading"><strong>お届け先</strong></div><div class="dokan-panel-body">';

        if (in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
            echo '<p>この取引はキャンセルされたため、お届け先は表示されません。</p>';
        } elseif (self::isMasked($order)) {
            echo '<p>取引完了から30日が経過したため、お届け先は表示されません。</p>';
        } elseif (!self::visibleToCreator($order)) {
            echo '<p>お支払いが完了すると、お届け先が表示されます。</p>';
        } elseif (!self::hasAddress($order)) {
            echo '<p>この取引にはお届け先が登録されていません。お手数ですが運営までお問い合わせください。</p>';
        } else {
            printf('<p class="mk-ship-to__address">%s</p>', implode('<br>', array_map('esc_html', self::lines($order))));
            echo '<p class="description">お届け先は、この商品の発送のためにのみご利用ください。'
                . '取引完了（受取確認）から30日が経過すると表示されなくなります。</p>';
        }

        echo '</div></div>';
    }

    /**
     * Keep Dokan's customer block -- name, email, phone, IP -- off the
     * creator's order page. The address panel above shows what shipping needs.
     *
     * @param mixed $value the dokan_selling option
     * @return mixed
     */
    public static function hideCustomerInfo($value)
    {
        if (is_array($value)) {
            $value['hide_customer_info'] = 'on';
        }

        return $value;
    }
}
