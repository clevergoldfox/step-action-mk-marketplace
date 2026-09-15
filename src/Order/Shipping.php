<?php
declare(strict_types=1);

namespace MK\Order;

use WC_Order;

/**
 * 発送登録 — the creator's only write action on an order.
 *
 * This exists because the vendor status dropdown had to go. Dokan builds that
 * dropdown from wc_get_order_statuses(), which now contains our statuses, so
 * leaving it in place let a creator select 受取確認 and release their own
 * payout (see Order\Guard). Removing it left the creator with no way to say
 * "I posted it", which is the one transition that genuinely is theirs.
 *
 * A purpose-built form is the better answer anyway: a raw status dropdown
 * cannot capture the carrier or the tracking number, and the buyer needs both.
 *
 * ---------------------------------------------------------------------------
 * Why the tracking number is optional
 * ---------------------------------------------------------------------------
 * 定形外郵便, スマートレター and ミニレター issue no tracking number at all,
 * and they are the normal way to post small accessories and apparel — the
 * client's main categories. If the field were required, those creators could
 * not register a shipment, so the order would never reach 発送済, the 7-day
 * auto-complete would never start, and they would never be paid. A required
 * field would have quietly broken the payout path for an entire class of
 * seller.
 *
 * So the number is optional, and a creator who has none ticks a box that says
 * so explicitly. That distinction matters to the buyer: "no tracking number
 * exists for this method" is information, whereas a silently empty field is
 * indistinguishable from a creator who simply has not filled it in yet.
 */
final class Shipping
{
    public const META_CARRIER_ID   = '_mk_carrier_id';
    public const META_CARRIER_NAME = '_mk_carrier_name';
    public const META_TRACKING     = '_mk_tracking_number';
    public const META_NO_TRACKING  = '_mk_no_tracking';
    public const META_SHIPPED_AT   = '_mk_shipped_at';

    private const NONCE = 'mk_register_shipment';

    public static function register(): void
    {
        add_action('dokan_order_detail_after_order_items', [self::class, 'renderForm']);
        add_action('template_redirect', [self::class, 'handleSubmit']);
    }

    /** @return array<int, object{id:int, name:string}> */
    public static function carriers(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}mk_carriers
              WHERE is_active = 1
           ORDER BY sort_order ASC, id ASC"
        ) ?: [];
    }

    // ------------------------------------------------------------------ view

    public static function renderForm(WC_Order $order): void
    {
        if (!current_user_can('dokan_view_order')) {
            return;
        }

        // Only the creator who owns this order.
        if ((int) $order->get_meta('_mk_creator_id') !== get_current_user_id()
            && !current_user_can('manage_woocommerce')
        ) {
            return;
        }

        // A message video is handed over by URL, in VideoDelivery.
        if (\MK\Product\MessageVideo::isMessageVideoOrder($order)) {
            return;
        }

        $status = $order->get_status();

        if ($status === Statuses::PAID) {
            self::renderPending($order);

            return;
        }

        if (in_array($status, [Statuses::SHIPPED, Statuses::RECEIVED, 'completed'], true)) {
            self::renderSummary($order);
        }
    }

    private static function renderPending(WC_Order $order): void
    {
        $carriers = self::carriers();

        if (!$carriers) {
            echo '<div class="dokan-alert dokan-alert-danger">'
                . '配送会社が登録されていません。運営までお問い合わせください。'
                . '</div>';

            return;
        }

        $action = wp_nonce_url(
            add_query_arg(
                ['mk_ship' => $order->get_id()],
                dokan_get_navigation_url('orders')
            ),
            self::NONCE
        );

        echo '<div class="dokan-panel dokan-panel-default mk-shipping">';
        echo '<div class="dokan-panel-heading"><strong>発送登録</strong></div>';
        echo '<div class="dokan-panel-body">';

        printf('<form method="post" action="%s">', esc_url($action));

        echo '<div class="dokan-form-group">';
        echo '<label class="dokan-form-label" for="mk_carrier_id">配送会社 <span class="required">*</span></label>';
        echo '<select name="mk_carrier_id" id="mk_carrier_id" class="dokan-form-control" required>';
        echo '<option value="">選択してください</option>';

        foreach ($carriers as $carrier) {
            printf(
                '<option value="%d">%s</option>',
                (int) $carrier->id,
                esc_html($carrier->name)
            );
        }

        echo '</select></div>';

        echo '<div class="dokan-form-group">';
        echo '<label class="dokan-form-label" for="mk_tracking_number">追跡番号（任意）</label>';
        echo '<input type="text" name="mk_tracking_number" id="mk_tracking_number" '
            . 'class="dokan-form-control" maxlength="64" autocomplete="off">';
        echo '</div>';

        echo '<div class="dokan-form-group"><label>'
            . '<input type="checkbox" name="mk_no_tracking" value="1"> '
            . '追跡番号のない発送方法を利用しました（定形外郵便・スマートレター等）'
            . '</label></div>';

        echo '<p class="description">'
            . '発送登録を行うと、購入者に通知され、'
            . (int) get_option('mk_auto_complete_days', 7)
            . '日後に自動的に受取確認となります。</p>';

        echo '<button type="submit" class="dokan-btn dokan-btn-theme">発送を登録する</button>';
        echo '</form>';
        echo '</div></div>';
    }

    private static function renderSummary(WC_Order $order): void
    {
        $carrier  = (string) $order->get_meta(self::META_CARRIER_NAME);
        $tracking = (string) $order->get_meta(self::META_TRACKING);
        $shipped  = (string) $order->get_meta(self::META_SHIPPED_AT);

        echo '<div class="dokan-panel dokan-panel-default mk-shipping">';
        echo '<div class="dokan-panel-heading"><strong>発送情報</strong></div>';
        echo '<div class="dokan-panel-body">';

        printf('<p><strong>配送会社：</strong>%s</p>', esc_html($carrier !== '' ? $carrier : '—'));

        if ($order->get_meta(self::META_NO_TRACKING) === 'yes') {
            echo '<p><strong>追跡番号：</strong>なし（追跡番号のない発送方法）</p>';
        } else {
            printf(
                '<p><strong>追跡番号：</strong>%s</p>',
                esc_html($tracking !== '' ? $tracking : '—')
            );
        }

        if ($shipped !== '') {
            printf(
                '<p><strong>発送日時：</strong>%s</p>',
                esc_html(get_date_from_gmt($shipped, 'Y年n月j日 H:i'))
            );
        }

        echo '</div></div>';
    }

    // ---------------------------------------------------------------- submit

    public static function handleSubmit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_ship'])) {
            return;
        }

        check_admin_referer(self::NONCE);

        $orderId = (int) $_GET['mk_ship'];
        $order   = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        // Ownership, re-checked here rather than trusted from the form. The
        // form is only rendered for the owner, but a form is not a permission.
        $isOwner = (int) $order->get_meta('_mk_creator_id') === get_current_user_id();

        if (!$isOwner && !current_user_can('manage_woocommerce')) {
            wp_die('この注文を操作する権限がありません。', '', ['response' => 403]);
        }

        // Only from 購入済. Re-registering a shipment on an order that has
        // moved on would restart the auto-complete timer and, on a received
        // order, could re-queue a transfer that has already been scheduled.
        // A video order registered as a parcel would reach 発送済 -- and start
        // the clock towards payout -- with nothing having been sent.
        if ($order->get_status() !== Statuses::PAID
            || \MK\Product\MessageVideo::isMessageVideoOrder($order)
        ) {
            wp_safe_redirect(dokan_get_navigation_url('orders'));
            exit;
        }

        $carrierId = isset($_POST['mk_carrier_id']) ? (int) $_POST['mk_carrier_id'] : 0;
        $carrier   = self::carrierById($carrierId);

        if ($carrier === null) {
            wp_safe_redirect(add_query_arg('mk_ship_error', 'carrier', dokan_get_navigation_url('orders')));
            exit;
        }

        $tracking   = isset($_POST['mk_tracking_number'])
            ? sanitize_text_field(wp_unslash($_POST['mk_tracking_number']))
            : '';
        $noTracking = !empty($_POST['mk_no_tracking']);

        // Ticking the box wins over a stray value left in the field: the
        // creator's explicit statement is better evidence than an input they
        // may simply not have cleared.
        if ($noTracking) {
            $tracking = '';
        }

        $order->update_meta_data(self::META_CARRIER_ID, $carrierId);
        $order->update_meta_data(self::META_CARRIER_NAME, $carrier->name);
        $order->update_meta_data(self::META_TRACKING, $tracking);
        $order->update_meta_data(self::META_NO_TRACKING, $noTracking ? 'yes' : 'no');
        $order->update_meta_data(self::META_SHIPPED_AT, gmdate('Y-m-d H:i:s'));
        $order->save();

        // Transitions::onShipped schedules the auto-complete from here.
        $order->update_status(
            Statuses::SHIPPED,
            sprintf(
                '出品者が発送を登録しました。配送会社: %s / 追跡番号: %s',
                $carrier->name,
                $noTracking ? 'なし（追跡不可の発送方法）' : ($tracking !== '' ? $tracking : '未入力')
            )
        );

        wp_safe_redirect(add_query_arg('mk_shipped', '1', dokan_get_navigation_url('orders')));
        exit;
    }

    private static function carrierById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}mk_carriers
                  WHERE id = %d AND is_active = 1",
                $id
            )
        );

        return $row ?: null;
    }
}
