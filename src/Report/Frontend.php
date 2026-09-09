<?php
declare(strict_types=1);

namespace MK\Report;

use WC_Order;

/**
 * The 通報 form on a transaction.
 *
 * Shown to both sides of an order. A buyer reports an item that never
 * arrived or was not as described; a creator reports a buyer who is abusive
 * or who confirmed receipt and then demanded money back off-platform.
 *
 * Placed on the order screen rather than behind a support link because the
 * report has to be attached to a specific transaction to do anything useful:
 * it is the order id that stops the payout.
 */
final class Frontend
{
    private const NONCE = 'mk_report_order';

    public static function register(): void
    {
        // After Receipt's panel (priority 10), so the order of the page reads
        // shipping, then receipt confirmation, then "something is wrong".
        add_action('woocommerce_order_details_after_order_table', [self::class, 'render'], 20);
        add_action('template_redirect', [self::class, 'handleSubmit']);
    }

    public static function render(WC_Order $order): void
    {
        $userId = get_current_user_id();

        if ($userId === 0 || !self::isParty($order, $userId)) {
            return;
        }

        // Nothing to report before money has changed hands, and nothing to
        // stop once the payout is long done.
        if (in_array($order->get_status(), ['pending', 'failed', 'cancelled'], true)) {
            return;
        }

        $service = new Service();

        if ($service->openCountFor(Service::TARGET_ORDER, $order->get_id()) > 0) {
            echo '<section class="mk-report"><h2>通報について</h2>'
                . '<p>この取引について通報を受け付けております。'
                . '運営が内容を確認するまで、出品者への送金は保留されます。'
                . '確認が完了次第、ご登録のメールアドレスへご連絡いたします。</p>'
                . '</section>';

            return;
        }

        $action = wp_nonce_url(
            add_query_arg('mk_report', $order->get_id(), $order->get_view_order_url()),
            self::NONCE
        );

        echo '<section class="mk-report"><h2>この取引に問題がありますか？</h2>';
        echo '<p>商品が届かない、説明と異なるなどの問題がございましたら、'
            . '下記より運営へご連絡ください。<strong>通報を行うと、'
            . '運営が確認するまで出品者への送金は保留されます。</strong></p>';

        printf('<form method="post" action="%s">', esc_url($action));

        echo '<p><label for="mk_report_reason">理由</label><br>';
        echo '<select name="mk_report_reason" id="mk_report_reason" required>';
        echo '<option value="">選択してください</option>';

        foreach (Service::reasons() as $key => $label) {
            printf('<option value="%s">%s</option>', esc_attr($key), esc_html($label));
        }

        echo '</select></p>';

        echo '<p><label for="mk_report_comment">詳細（任意）</label><br>'
            . '<textarea name="mk_report_comment" id="mk_report_comment" rows="4" '
            . 'style="width:100%" maxlength="2000"></textarea></p>';

        echo '<button type="submit" class="button" '
            . 'onclick="return confirm(\'運営へ通報します。よろしいですか？\');">'
            . '通報する</button>';
        echo '</form></section>';
    }

    public static function handleSubmit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_report'])) {
            return;
        }

        check_admin_referer(self::NONCE);

        $order  = wc_get_order((int) $_GET['mk_report']);
        $userId = get_current_user_id();

        if (!$order instanceof WC_Order) {
            return;
        }

        // Re-checked on submit. The form is only rendered for the two parties
        // to the order, but a form is not a permission: without this, anyone
        // logged in could POST an order id and freeze a stranger's payout.
        if ($userId === 0 || !self::isParty($order, $userId)) {
            wp_die('この取引を通報する権限がありません。', '', ['response' => 403]);
        }

        $service = new Service();

        if ($service->alreadyReported($userId, Service::TARGET_ORDER, $order->get_id())) {
            wp_safe_redirect($order->get_view_order_url());
            exit;
        }

        $reason = isset($_POST['mk_report_reason'])
            ? sanitize_key(wp_unslash($_POST['mk_report_reason']))
            : '';

        $comment = isset($_POST['mk_report_comment'])
            ? sanitize_textarea_field(wp_unslash($_POST['mk_report_comment']))
            : '';

        try {
            $service->open($userId, Service::TARGET_ORDER, $order->get_id(), $reason, $comment);
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg('mk_report_error', '1', $order->get_view_order_url()));
            exit;
        }

        wp_safe_redirect(add_query_arg('mk_reported', '1', $order->get_view_order_url()));
        exit;
    }

    /** The buyer or the creator of this order, and nobody else. */
    private static function isParty(WC_Order $order, int $userId): bool
    {
        return $userId === $order->get_customer_id()
            || $userId === (int) $order->get_meta('_mk_creator_id');
    }
}
