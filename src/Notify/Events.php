<?php
declare(strict_types=1);

namespace MK\Notify;

use MK\Order\DispatchDeadline;
use MK\Order\Shipping;
use MK\Order\Statuses;
use MK\Support\Money;
use WC_Order;

/**
 * Turns things that happen into notifications.
 *
 * Every transactional event in the marketplace is raised here and nowhere
 * else. WooCommerce sends its own mail for its own statuses and knows nothing
 * about 購入済 / 発送済 / 受取確認, so without this the entire transaction ran
 * silently: a creator was never told they had sold anything, and a buyer was
 * never told their parcel had been posted.
 *
 * Which events exist is a product decision and it is the list the client
 * approved. What is NOT here is equally deliberate — nothing is sent for a
 * status the other party caused and is already looking at.
 */
final class Events
{
    public static function register(): void
    {
        add_action('woocommerce_order_status_changed', [self::class, 'onStatusChanged'], 20, 4);
        add_action('mk_message_sent', [self::class, 'onMessage'], 10, 4);
        add_action('mk_report_opened', [self::class, 'onReport'], 10, 3);
        add_action('mk_dispatch_overdue', [self::class, 'onDispatchOverdue'], 10, 2);
        add_action('mk_creator_repeatedly_late', [self::class, 'onRepeatedlyLate'], 10, 2);
        add_action('mk_creator_charged', [self::class, 'onCreatorCharged'], 10, 3);
        add_action('mk_transfer_sent', [self::class, 'onTransferSent'], 10, 3);
        add_action('transition_post_status', [self::class, 'onListingStatus'], 10, 3);
    }

    /**
     * The approval queue, from both sides.
     *
     * Operator: a listing has arrived and nobody can buy it until they act.
     * Creator: it is live. Without the second, a creator's only way to find
     * out is to keep reloading their product list, and one who lists something
     * and hears nothing reasonably assumes it failed.
     *
     * A listing from a creator who cannot yet be paid never reaches pending or
     * publish at all (PublishGate holds it as a draft), so the operator is not
     * asked to review something unsellable and no "published" message is sent
     * for something that is not actually live.
     */
    public static function onListingStatus(string $new, string $old, \WP_Post $post): void
    {
        if ($post->post_type !== 'product' || $new === $old) {
            return;
        }

        $author = (int) $post->post_author;

        if (!function_exists('dokan_is_user_seller') || !dokan_is_user_seller($author)) {
            return;
        }

        if ($new === 'pending') {
            foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $adminId) {
                Dispatcher::send(new Notification(
                    type: 'listing.pending',
                    userId: (int) $adminId,
                    subject: '承認待ちの商品があります',
                    body: sprintf(
                        "新しい商品が出品され、承認を待っています。\n\n"
                        . "商品名：%s\n出品者：%s\n\n"
                        . "公開するまで、購入者はこの商品を購入できません。",
                        $post->post_title,
                        get_the_author_meta('display_name', $author)
                    ),
                    short: sprintf('承認待ち：%s', $post->post_title),
                    url: admin_url('edit.php?post_type=product&post_status=pending'),
                    context: ['product_id' => $post->ID],
                ));
            }

            return;
        }

        // The second case is an approval that was held for onboarding and has
        // now taken effect (PublishGate::releaseHeld). It goes draft ->
        // publish, and the held marker is still on the post during the
        // transition, which tells it apart from a creator's ordinary draft.
        $releasedApproval = $old === 'draft'
            && get_post_meta($post->ID, \MK\Product\PublishGate::META_HELD, true) === 'yes';

        if ($new === 'publish' && ($old === 'pending' || $releasedApproval)) {
            Dispatcher::send(new Notification(
                type: 'listing.published',
                userId: $author,
                subject: '商品が公開されました',
                body: sprintf(
                    "出品された商品が承認され、公開されました。\n\n"
                    . "商品名：%s\n\n"
                    . "購入者が商品ページを閲覧・購入できるようになりました。",
                    $post->post_title
                ),
                short: sprintf('「%s」が公開されました。', $post->post_title),
                url: (string) get_permalink($post->ID),
                context: ['product_id' => $post->ID],
            ));
        }
    }

    public static function onStatusChanged(int $orderId, string $from, string $to, WC_Order $order): void
    {
        match (Statuses::bare($to)) {
            Statuses::PAID     => self::notifyCreatorOfSale($order),
            Statuses::SHIPPED  => self::notifyBuyerOfShipment($order),
            Statuses::RECEIVED => self::notifyCreatorOfReceipt($order),
            default            => null,
        };
    }

    private static function notifyCreatorOfSale(WC_Order $order): void
    {
        $creatorId = (int) $order->get_meta('_mk_creator_id');
        $title     = (string) $order->get_meta('_mk_title_snapshot');
        $creator   = (int) $order->get_meta('_mk_creator_amount');

        Dispatcher::send(new Notification(
            type: 'order.paid',
            userId: $creatorId,
            subject: '商品が購入されました',
            body: sprintf(
                "「%s」が購入されました。\n\n"
                . "ご注文番号：#%d\n"
                . "お受け取り予定額：%s（手数料差引後）\n\n"
                . "発送の準備が整いましたら、出品者ダッシュボードから発送登録を行ってください。\n"
                . "発送登録を行わないと取引が進みません。",
                $title,
                $order->get_id(),
                Money::format($creator)
            ),
            short: sprintf('「%s」が購入されました。発送登録をお願いします。', $title),
            url: dokan_get_navigation_url('orders'),
            context: ['order_id' => $order->get_id()],
        ));
    }

    private static function notifyBuyerOfShipment(WC_Order $order): void
    {
        $carrier  = (string) $order->get_meta(Shipping::META_CARRIER_NAME);
        $tracking = (string) $order->get_meta(Shipping::META_TRACKING);
        $noTrack  = $order->get_meta(Shipping::META_NO_TRACKING) === 'yes';
        $days     = (int) get_option('mk_auto_complete_days', 7);

        $trackingLine = $noTrack
            ? "追跡番号：なし（追跡番号のない発送方法のため、配送状況は確認できません）"
            : sprintf('追跡番号：%s', $tracking !== '' ? $tracking : '未登録');

        Dispatcher::send(new Notification(
            type: 'order.shipped',
            userId: $order->get_customer_id(),
            subject: '商品が発送されました',
            body: sprintf(
                "ご注文の商品が発送されました。\n\n"
                . "ご注文番号：#%d\n配送会社：%s\n%s\n\n"
                . "商品がお手元に届きましたら、注文履歴から「受取確認」をお願いいたします。\n"
                . "発送から%d日が経過した場合は、自動的に受取確認となります。",
                $order->get_id(),
                $carrier !== '' ? $carrier : '—',
                $trackingLine,
                $days
            ),
            short: sprintf('ご注文商品が発送されました（%s）。', $carrier !== '' ? $carrier : '発送済'),
            url: $order->get_view_order_url(),
            context: ['order_id' => $order->get_id()],
        ));
    }

    private static function notifyCreatorOfReceipt(WC_Order $order): void
    {
        $due = (string) $order->get_meta('_mk_transfer_due_at');

        Dispatcher::send(new Notification(
            type: 'order.received',
            userId: (int) $order->get_meta('_mk_creator_id'),
            subject: '受取確認が完了しました',
            body: sprintf(
                "ご注文 #%d について、購入者による受取確認が完了しました。\n\n"
                . "お支払い予定日：%s頃\n\n"
                . "所定の保留期間を経過後、ご登録の口座へ送金いたします。",
                $order->get_id(),
                $due !== '' ? get_date_from_gmt($due, 'Y年n月j日') : '保留期間経過後'
            ),
            short: sprintf('受取確認が完了しました（注文 #%d）。', $order->get_id()),
            url: dokan_get_navigation_url('orders'),
            context: ['order_id' => $order->get_id()],
        ));
    }

    /**
     * The promised dispatch date passed with nothing posted.
     *
     * Both sides, deliberately. The creator is the only one who can fix it,
     * and the buyer is the only one who can decide whether to keep waiting --
     * and they cannot decide that if nobody tells them the deadline has gone.
     * This is the one place the usual "do not tell the reported party" rule
     * does not apply, because nobody has reported anything yet: the creator is
     * being reminded of their own promise, not accused.
     */
    public static function onDispatchOverdue(int $orderId, int $creatorId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $title = (string) $order->get_meta('_mk_title_snapshot');
        $due   = DispatchDeadline::dueLabel($order);

        Dispatcher::send(new Notification(
            type: 'dispatch.overdue.creator',
            userId: $creatorId,
            subject: '【重要】発送期限を過ぎています',
            body: sprintf(
                "ご注文 #%d（%s）の発送期限（%s）を過ぎていますが、発送登録が確認できません。\n\n"
                . "至急、出品者ダッシュボードから発送登録を行ってください。\n\n"
                . "期限を過ぎているため、購入者はこの取引のキャンセルを申請できる状態です。"
                . "キャンセルとなった場合、この取引の売上はお支払いできません。\n"
                . "発送の遅れが繰り返される場合、出品を制限させていただくことがあります。",
                $orderId,
                $title,
                $due
            ),
            short: sprintf('注文 #%d の発送期限を過ぎています。至急ご対応ください。', $orderId),
            url: dokan_get_navigation_url('orders'),
            context: ['order_id' => $orderId],
        ));

        Dispatcher::send(new Notification(
            type: 'dispatch.overdue.buyer',
            userId: $order->get_customer_id(),
            subject: '発送予定日を過ぎています',
            body: sprintf(
                "ご注文 #%d（%s）について、出品者がお約束した発送期限（%s）を過ぎましたが、"
                . "発送の登録が確認できておりません。\n\n"
                . "出品者へは発送を促す通知をお送りしました。\n\n"
                . "もう少しお待ちいただくこともできますが、キャンセルをご希望の場合は、"
                . "注文詳細ページから「キャンセルを申請する」をお選びください。"
                . "運営が状況を確認のうえ、キャンセル・ご返金の対応を行います。\n\n"
                . "お支払いいただいた代金は、出品者へはまだお渡ししておりません。ご安心ください。",
                $orderId,
                $title,
                $due
            ),
            short: sprintf('注文 #%d が発送期限を過ぎています。キャンセル申請が可能です。', $orderId),
            url: $order->get_view_order_url(),
            context: ['order_id' => $orderId],
        ));
    }

    /**
     * The seller has been charged for a refund they caused.
     *
     * Sent at the moment the charge is recorded, not when it is deducted. The
     * deduction can be weeks away, and a payout that arrives smaller than
     * expected with no prior explanation is the single fastest way to lose a
     * seller's trust -- including the honest ones, who will assume the
     * platform is skimming.
     */
    public static function onCreatorCharged(int $creatorId, int $amount, int $orderId): void
    {
        Dispatcher::send(new Notification(
            type: 'creator.charged',
            userId: $creatorId,
            subject: '返金に伴う費用のご負担について',
            body: sprintf(
                "ご注文 #%d について、購入者へ返金を行いました。\n\n"
                . "この返金は出品内容と実際の商品との相違によるものと判断したため、"
                . "返金に伴う費用 %s を出品者様のご負担とさせていただきます。\n\n"
                . "この金額は次回以降の売上から自動的に差し引かれます。"
                . "売上から差し引けない場合は、別途ご請求させていただくことがあります。\n\n"
                . "内訳は出品者ダッシュボードの「売上・受取設定」からご確認いただけます。"
                . "ご不明な点やご異議がございましたら、運営までご連絡ください。",
                $orderId,
                Money::format($amount)
            ),
            short: sprintf('注文 #%d の返金費用 %s をご負担いただきます。', $orderId, Money::format($amount)),
            url: dokan_get_navigation_url(\MK\Creator\Onboarding::PAGE),
            context: ['order_id' => $orderId],
        ));
    }

    /**
     * A creator who is late again and again.
     *
     * Reported to the operator rather than acted on. Suspending someone is a
     * judgement about intent -- illness, a holiday, or someone taking money
     * for things they never post -- and the counter cannot tell those apart.
     */
    public static function onRepeatedlyLate(int $creatorId, int $count): void
    {
        $creator = get_userdata($creatorId);

        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $adminId) {
            Dispatcher::send(new Notification(
                type: 'creator.late.repeated',
                userId: (int) $adminId,
                subject: '発送遅延が続いている出品者がいます',
                body: sprintf(
                    "出品者「%s」の発送期限超過が %d 件になりました。\n\n"
                    . "内容をご確認のうえ、必要に応じて出品制限をご検討ください。\n"
                    . "出品制限を設定すると、その出品者は新たに商品を公開できなくなります"
                    . "（進行中の取引と発送登録は制限されません）。",
                    $creator ? $creator->display_name : '#' . $creatorId,
                    $count
                ),
                short: sprintf('出品者「%s」の発送遅延が %d 件になりました。',
                    $creator ? $creator->display_name : '#' . $creatorId, $count),
                url: admin_url('admin.php?page=mk-creators'),
                context: ['creator_id' => $creatorId],
            ));
        }
    }

    /** Raised by TransferService once the money has actually gone. */
    public static function onTransferSent(int $orderId, int $creatorId, int $amount): void
    {
        Dispatcher::send(new Notification(
            type: 'payout.sent',
            userId: $creatorId,
            subject: '売上を送金しました',
            body: sprintf(
                "ご注文 #%d の売上 %s を送金いたしました。\n\n"
                . "Stripe からご登録口座への入金は、通常この後数営業日以内に行われます。\n"
                . "入金状況は Stripe のダッシュボードからもご確認いただけます。",
                $orderId,
                Money::format($amount)
            ),
            short: sprintf('売上 %s を送金しました。', Money::format($amount)),
            url: dokan_get_navigation_url('mk-payouts'),
            context: ['order_id' => $orderId],
        ));
    }

    public static function onMessage(int $messageId, int $orderId, int $senderId, int $receiverId): void
    {
        $sender = get_userdata($senderId);
        $order  = wc_get_order($orderId);

        Dispatcher::send(new Notification(
            type: 'message.received',
            userId: $receiverId,
            subject: '取引メッセージが届いています',
            body: sprintf(
                "%s さんから、ご注文 #%d についてメッセージが届いています。\n\n"
                . "内容の確認とご返信は、サイト内からお願いいたします。",
                $sender ? $sender->display_name : '取引相手',
                $orderId
            ),
            short: sprintf('%s さんからメッセージが届いています。',
                $sender ? $sender->display_name : '取引相手'),
            url: $order instanceof WC_Order ? $order->get_view_order_url() : home_url('/'),
            context: ['order_id' => $orderId, 'message_id' => $messageId],
        ));
    }

    /**
     * Tell the operator, not the reported party.
     *
     * A report freezes a creator's payout, and the operator has to act before
     * anything moves again. Telling the creator they have been reported, before
     * anyone has looked at it, invites them to go and argue with the buyer.
     */
    public static function onReport(int $reportId, string $targetType, int $targetId): void
    {
        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $adminId) {
            Dispatcher::send(new Notification(
                type: 'report.opened',
                userId: (int) $adminId,
                subject: '通報が届いています',
                body: sprintf(
                    "新しい通報が届いています（通報ID：%d／対象：%s #%d）。\n\n"
                    . "対象が取引の場合、確認が完了するまで出品者への送金は保留されます。\n"
                    . "管理画面よりご対応ください。",
                    $reportId,
                    $targetType,
                    $targetId
                ),
                short: sprintf('通報 #%d が届いています。送金は保留中です。', $reportId),
                url: admin_url('admin.php?page=mk-reports'),
                context: ['report_id' => $reportId],
            ));
        }
    }
}
