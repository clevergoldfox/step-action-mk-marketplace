<?php
declare(strict_types=1);

namespace MK\Message;

use WC_Order;

/**
 * The message thread, rendered on both sides of an order.
 *
 * The buyer sees it on their order page; the creator sees the same thread on
 * their Dokan order screen. One renderer serves both, so the two views cannot
 * drift into showing different things — and the access question is asked once,
 * by Service::counterpartyOf().
 */
final class Frontend
{
    private const NONCE = 'mk_send_message';

    public static function register(): void
    {
        // Buyer: after the report form (20), so the page reads shipping,
        // receipt, problem, then conversation.
        add_action('woocommerce_order_details_after_order_table', [self::class, 'renderForBuyer'], 30);

        // Creator: on the Dokan order detail screen.
        add_action('dokan_order_detail_after_order_items', [self::class, 'renderForCreator'], 20);

        add_action('template_redirect', [self::class, 'handleSubmit']);

        add_action('mk_message_sent', [self::class, 'notify'], 10, 4);
    }

    public static function renderForBuyer(WC_Order $order): void
    {
        self::render($order, $order->get_view_order_url());
    }

    public static function renderForCreator(WC_Order $order): void
    {
        self::render($order, dokan_get_navigation_url('orders') . 'details/' . $order->get_id() . '/');
    }

    private static function render(WC_Order $order, string $returnUrl): void
    {
        $userId  = get_current_user_id();
        $service = new Service();

        if ($service->counterpartyOf($order, $userId) === null) {
            return;
        }

        $messages = $service->forOrder($order->get_id());

        // Reading the page is reading the messages.
        $service->markRead($order->get_id(), $userId);

        echo '<section class="mk-messages"><h2>取引メッセージ</h2>';

        if (!$messages) {
            echo '<p>まだメッセージはありません。'
                . '配送や商品についてのご連絡にご利用ください。</p>';
        } else {
            echo '<div class="mk-thread" style="max-height:400px;overflow-y:auto;'
                . 'border:1px solid #ddd;padding:12px;margin-bottom:12px">';

            foreach ($messages as $m) {
                $mine   = (int) $m->sender_id === $userId;
                $author = get_userdata((int) $m->sender_id);

                printf(
                    '<div style="margin-bottom:12px;text-align:%s">'
                    . '<div style="display:inline-block;max-width:80%%;padding:8px 12px;'
                    . 'border-radius:8px;background:%s;text-align:left">'
                    . '<small style="opacity:.7">%s ・ %s</small><br>%s</div></div>',
                    $mine ? 'right' : 'left',
                    $mine ? '#e8f0fe' : '#f4f4f4',
                    esc_html($mine ? 'あなた' : ($author ? $author->display_name : '相手')),
                    esc_html(get_date_from_gmt((string) $m->created_at, 'n月j日 H:i')),
                    nl2br(esc_html((string) $m->body))
                );
            }

            echo '</div>';
        }

        $action = wp_nonce_url(
            add_query_arg('mk_message', $order->get_id(), $returnUrl),
            self::NONCE
        );

        printf('<form method="post" action="%s">', esc_url($action));
        printf(
            '<textarea name="mk_message_body" rows="3" style="width:100%%" maxlength="%d" '
            . 'placeholder="メッセージを入力（最大%d文字）" required></textarea>',
            Service::MAX_LENGTH,
            Service::MAX_LENGTH
        );
        echo '<p><button type="submit" class="button">送信する</button></p>';
        echo '</form>';

        echo '<p><small>お支払いは必ずサイト内で完結してください。'
            . 'メッセージで直接取引を持ちかけられた場合は、通報からお知らせください。</small></p>';

        echo '</section>';
    }

    public static function handleSubmit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_message'])) {
            return;
        }

        check_admin_referer(self::NONCE);

        $orderId = (int) $_GET['mk_message'];
        $order   = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $body = isset($_POST['mk_message_body'])
            ? sanitize_textarea_field(wp_unslash($_POST['mk_message_body']))
            : '';

        try {
            // send() re-checks membership itself; the form being rendered is
            // not evidence that the person posting it belongs here.
            (new Service())->send($orderId, get_current_user_id(), $body);
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 403]);
        }

        wp_safe_redirect(wp_get_referer() ?: $order->get_view_order_url());
        exit;
    }

    /**
     * Tell the recipient something is waiting.
     *
     * Email for now. When LINE is added it listens on the same hook rather
     * than editing this, so a creator who has linked LINE gets it there and
     * everyone else still gets the mail.
     */
    public static function notify(int $messageId, int $orderId, int $senderId, int $receiverId): void
    {
        $receiver = get_userdata($receiverId);
        $sender   = get_userdata($senderId);
        $order    = wc_get_order($orderId);

        if (!$receiver || !$order) {
            return;
        }

        wp_mail(
            $receiver->user_email,
            sprintf('[%s] 取引メッセージが届いています', get_bloginfo('name')),
            sprintf(
                "%s 様\n\n%s さんから、ご注文 #%d についてメッセージが届いています。\n\n"
                . "下記よりご確認ください。\n%s\n\n"
                . "※このメールは送信専用です。返信はサイト内からお願いいたします。\n",
                $receiver->display_name,
                $sender ? $sender->display_name : '取引相手',
                $orderId,
                $order->get_view_order_url()
            )
        );
    }
}
