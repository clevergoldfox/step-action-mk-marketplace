<?php
declare(strict_types=1);

namespace MK\Order;

use MK\Product\MessageVideo;
use MK\Report\Service as ReportService;
use MK\Schedule\Jobs;
use WC_Order;

/**
 * Handing a message video over, or declining to record it.
 *
 * The digital counterpart of Shipping (the creator's side) and Receipt (the
 * buyer's). A URL takes the place of a carrier and tracking number, and the
 * order then moves to exactly the status a registered parcel does -- so the
 * auto-complete, the payout hold and the escrow release follow on their own.
 *
 * ---------------------------------------------------------------------------
 * The URL is the only secret, and it is only shown to one person
 * ---------------------------------------------------------------------------
 * Creators upload to their own Vimeo accounts as 限定公開, which means anyone
 * holding the URL can watch. Nothing here can stop a buyer from sharing it --
 * that is what the no-redistribution rule in the terms is for -- but the site
 * itself never shows it to anyone but the buyer who paid, the creator who
 * sent it, and the operator.
 *
 * ---------------------------------------------------------------------------
 * Declining is a request, not a refund
 * ---------------------------------------------------------------------------
 * A creator can refuse a request before recording -- a name that is an insult,
 * a message that is not something they will say. Declining files a report,
 * which freezes the order exactly as any other report does, and puts it in
 * front of the operator. The operator refunds from the order screen, choosing
 * who carries the cost; or rejects the decline, and the creator is asked to
 * record after all.
 *
 * The decline does not refund automatically because the client asked for it
 * to be reviewed, and because a creator who could cancel any sale on their
 * own say-so would have an easy way out of every order they no longer felt
 * like fulfilling.
 */
final class VideoDelivery
{
    public const META_URL            = '_mk_video_url';
    public const META_SENT_AT        = '_mk_video_sent_at';
    public const META_DECLINED_AT    = '_mk_video_declined_at';
    public const META_DECLINE_REASON = '_mk_video_decline_reason';

    /** The creator's account of why: a key of declineKinds(). */
    public const META_DECLINE_KIND = '_mk_video_decline_kind';

    /** The report reason a decline is filed under. */
    public const REASON = 'creator_declined';

    private const NONCE_SEND    = 'mk_send_video';
    private const NONCE_DECLINE = 'mk_decline_video';

    private const REASON_MAX = 500;

    public static function register(): void
    {
        add_action('dokan_order_detail_after_order_items', [self::class, 'renderForCreator'], 8);
        add_action('woocommerce_order_details_after_order_table', [self::class, 'renderForBuyer'], 9);

        add_action('template_redirect', [self::class, 'handleSend']);
        add_action('template_redirect', [self::class, 'handleDecline']);

        add_action('mk_report_resolved', [self::class, 'onReportResolved'], 10, 2);
    }

    // -------------------------------------------------------------- the URL

    /**
     * A Vimeo video URL, normalised, or '' if it is not one.
     *
     * Restricted to Vimeo because that is what was agreed, and because an
     * arbitrary URL on an order page is an arbitrary link the platform is
     * vouching for -- a phishing page presented as "your video".
     */
    public static function normaliseUrl(string $url): string
    {
        $url   = trim($url);
        $parts = wp_parse_url($url);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            return '';
        }

        // Every Vimeo video URL carries the numeric video id somewhere in the
        // path; a profile or a showcase does not.
        if (!preg_match('~/\d{5,}~', (string) ($parts['path'] ?? ''))) {
            return '';
        }

        return esc_url_raw($url, ['https']);
    }

    /**
     * What the creator says the decline is about.
     *
     * The client's three-way refund rule turns on whose responsibility the
     * decline is, and the creator is the first person who knows. It is their
     * claim, recorded for the operator -- who still makes the finding and can
     * disagree -- not a decision that moves money by itself.
     *
     * @return array<string, string>
     */
    public static function declineKinds(): array
    {
        return [
            'buyer_request' => '依頼内容が不適切（購入者側の問題）',
            'creator'       => 'クリエイターの都合',
            'other'         => 'その他・判断がつかない',
        ];
    }

    public static function isDeclined(WC_Order $order): bool
    {
        return $order->get_meta(self::META_DECLINED_AT) !== '';
    }

    // ------------------------------------------------------- creator's side

    public static function renderForCreator(WC_Order $order): void
    {
        if (!Delivery::needsDelivery($order)) {
            return;
        }

        $isOwner = (int) $order->get_meta('_mk_creator_id') === get_current_user_id();

        if (!$isOwner && !current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<div class="dokan-panel dokan-panel-default mk-video-delivery">';
        printf('<div class="dokan-panel-heading"><strong>%sのリクエスト</strong></div>',
            esc_html(Delivery::noun($order)));
        echo '<div class="dokan-panel-body">';

        echo self::requestSummary($order); // escaped inside

        $status = $order->get_status();
        $open   = in_array($status, [Statuses::PAID, Statuses::SHIPPED], true);

        if ($open && self::isDeclined($order)) {
            echo '<div class="dokan-alert dokan-alert-info">'
                . '<strong>辞退を受け付けました。</strong><br>'
                . '運営が内容を確認しています。確認が完了するまでお待ちください。</div>';
        } elseif ($open && !Delivery::isDelivered($order)) {
            self::renderSendForm($order);
            self::renderDeclineForm($order);
        } elseif (Delivery::isDelivered($order)) {
            self::renderSent($order);
        }

        echo '</div></div>';
    }

    /**
     * What the buyer asked for, or where to find out.
     *
     * The listings collected a request at checkout. An option bought on an
     * ordinary item has no such form -- the buyer adds メッセージ動画 to a
     * mug -- so rather than print a table of empty rows, the panel points at
     * the conversation attached to the order, which both sides already have.
     */
    private static function requestSummary(WC_Order $order): string
    {
        if (MessageVideo::isMessageVideoOrder($order)) {
            return MessageVideo::renderRequestSummary($order);
        }

        if (MessageVideo::hasRequest($order)) {
            return sprintf(
                '<p class="mk-field-help">%sのオプション付きのご注文です。</p>%s',
                esc_html(Delivery::noun($order)),
                MessageVideo::renderRequestSummary($order, false)
            );
        }

        return sprintf(
            '<p class="mk-field-help">%sのオプション付きのご注文です。'
            . 'ご希望の内容は、この取引のメッセージからご確認ください。</p>',
            esc_html(Delivery::noun($order))
        );
    }

    private static function renderSendForm(WC_Order $order): void
    {
        $action = wp_nonce_url(
            add_query_arg(['mk_send_video' => $order->get_id()], dokan_get_navigation_url('orders')),
            self::NONCE_SEND
        );

        $noun  = Delivery::noun($order);
        $audio = Delivery::kind($order) === 'audio';

        printf('<h4 class="mk-video-delivery__heading">%sを送信する</h4>', esc_html($noun));

        printf(
            '<p class="mk-field-help">%1$sを%2$sに<strong>限定公開</strong>でアップロードし、'
            . 'そのURLを貼り付けて送信してください。送信期限：<strong class="mk-due">%3$s</strong></p>',
            esc_html($noun),
            $audio ? 'ご自身のクラウド（Googleドライブ・Dropbox等）' : 'ご自身のVimeoアカウント',
            esc_html(DispatchDeadline::dueLabel($order))
        );

        printf('<form method="post" action="%s">', esc_url($action));

        printf(
            '<div class="dokan-form-group">'
            . '<label class="dokan-form-label" for="mk_video_url">%s<span class="required">*</span></label>'
            . '<input type="url" name="mk_video_url" id="mk_video_url" class="dokan-form-control" required '
            . 'placeholder="%s" inputmode="url">'
            . '</div>',
            $audio ? '音声ファイルのURL' : 'VimeoのURL',
            $audio ? 'https://drive.google.com/...' : 'https://vimeo.com/123456789/abcdef1234'
        );

        echo '<p class="mk-field-help">送信すると購入者に通知され、購入者が内容を確認して「受取完了」を押すと取引完了となります。'
            . (int) get_option('mk_auto_complete_days', 7) . '日間受取完了がない場合は、自動的に受取完了となります。</p>';

        printf('<button type="submit" class="dokan-btn dokan-btn-theme">%sを送信する</button>',
            esc_html($noun));
        echo '</form>';
    }

    private static function renderDeclineForm(WC_Order $order): void
    {
        $action = wp_nonce_url(
            add_query_arg(['mk_decline_video' => $order->get_id()], dokan_get_navigation_url('orders')),
            self::NONCE_DECLINE
        );

        echo '<details class="mk-video-decline"><summary>このリクエストをお受けできない場合</summary>';

        echo '<p class="mk-field-help">依頼内容に不適切な表現が含まれているなど、撮影をお受けできない場合は、撮影前に辞退できます。'
            . '辞退すると運営が内容を確認し、キャンセル・返金などの対応を決定します。</p>';

        printf('<form method="post" action="%s">', esc_url($action));

        echo '<div class="dokan-form-group"><label class="dokan-form-label" for="mk_decline_kind">'
            . '辞退の理由の種類<span class="required">*</span></label>'
            . '<select name="mk_decline_kind" id="mk_decline_kind" class="dokan-form-control" required>'
            . '<option value="">選択してください</option>';

        foreach (self::declineKinds() as $key => $label) {
            printf('<option value="%s">%s</option>', esc_attr($key), esc_html($label));
        }

        echo '</select></div>';

        printf(
            '<div class="dokan-form-group"><label class="dokan-form-label" for="mk_decline_reason">'
            . '辞退の理由<span class="required">*</span></label>'
            . '<textarea name="mk_decline_reason" id="mk_decline_reason" class="dokan-form-control" rows="3" '
            . 'maxlength="%d" required></textarea></div>',
            self::REASON_MAX
        );

        echo '<button type="submit" class="dokan-btn mk-video-decline__button" '
            . 'onclick="return confirm(\'このリクエストを辞退します。運営が確認するまで取引は保留されます。よろしいですか？\');">'
            . 'このリクエストを辞退する</button>';

        echo '</form></details>';
    }

    private static function renderSent(WC_Order $order): void
    {
        $url  = (string) $order->get_meta(self::META_URL);
        $sent = (string) $order->get_meta(self::META_SENT_AT);

        printf(
            '<p><strong>送信した動画：</strong><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url($url),
            esc_html($url)
        );

        if ($sent !== '') {
            printf('<p><strong>送信日時：</strong>%s</p>', esc_html(get_date_from_gmt($sent, 'Y年n月j日 H:i')));
        }

        echo '<p class="mk-field-help">取引が完了するまで、Vimeo上の動画を削除したり非公開にしたりしないでください。</p>';
    }

    public static function handleSend(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_send_video'])) {
            return;
        }

        check_admin_referer(self::NONCE_SEND);

        $order = wc_get_order((int) $_GET['mk_send_video']);

        if (!$order instanceof WC_Order || !Delivery::needsDelivery($order)) {
            return;
        }

        self::assertOwner($order);

        $back = dokan_get_navigation_url('orders');

        // Only once, never on top of a decline the operator has not ruled on
        // yet, and from either side of the parcel: a recording bought as an
        // option can be sent before the item goes out or after it has.
        if (!in_array($order->get_status(), [Statuses::PAID, Statuses::SHIPPED], true)
            || self::isDeclined($order)
            || Delivery::isDelivered($order)
        ) {
            wp_safe_redirect($back);
            exit;
        }

        $url = self::normaliseUrl(isset($_POST['mk_video_url']) ? (string) wp_unslash($_POST['mk_video_url']) : '');

        if ($url === '') {
            wp_safe_redirect(add_query_arg('mk_video_error', 'url', $back));
            exit;
        }

        $was  = $order->get_status();
        $noun = Delivery::noun($order);

        $order->update_meta_data(self::META_URL, $url);
        $order->update_meta_data(self::META_SENT_AT, gmdate('Y-m-d H:i:s'));
        $order->save();

        if ($was === Statuses::SHIPPED) {
            // The parcel went first. Both halves are done, so the clock that
            // Transitions::onShipped deliberately did not start, starts here.
            Jobs::scheduleAutoComplete($order->get_id());

            $order->add_order_note(sprintf(
                '%sを納品しました。商品は発送済みのため、%d日後に自動的に受取確認となります。',
                $noun,
                (int) get_option('mk_auto_complete_days', 7)
            ));
            $order->save();
        } elseif (Delivery::needsShipping($order)) {
            // Recording first, parcel still to come: the order stays where it
            // is and the dispatch deadline keeps running.
            $order->add_order_note(sprintf(
                '%sを納品しました。商品の発送登録が済むと取引が進みます。',
                $noun
            ));
            $order->save();
        } else {
            // Nothing to post: the recording IS the dispatch, which is how
            // the message-video listings worked before options existed.
            $order->update_status(Statuses::SHIPPED, sprintf('クリエイターが%sを送信しました。', $noun));
        }

        wp_safe_redirect(add_query_arg('mk_video_sent', '1', $back));
        exit;
    }

    public static function handleDecline(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_decline_video'])) {
            return;
        }

        check_admin_referer(self::NONCE_DECLINE);

        $order = wc_get_order((int) $_GET['mk_decline_video']);

        if (!$order instanceof WC_Order || !MessageVideo::isMessageVideoOrder($order)) {
            return;
        }

        self::assertOwner($order);

        $back = dokan_get_navigation_url('orders');

        if ($order->get_status() !== Statuses::PAID || self::isDeclined($order)) {
            wp_safe_redirect($back);
            exit;
        }

        $reason = isset($_POST['mk_decline_reason'])
            ? trim(sanitize_textarea_field(wp_unslash((string) $_POST['mk_decline_reason'])))
            : '';

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            wp_safe_redirect(add_query_arg('mk_video_error', 'reason', $back));
            exit;
        }

        $kind = isset($_POST['mk_decline_kind']) ? sanitize_key(wp_unslash((string) $_POST['mk_decline_kind'])) : '';

        if (!isset(self::declineKinds()[$kind])) {
            wp_safe_redirect(add_query_arg('mk_video_error', 'kind', $back));
            exit;
        }

        self::decline($order, get_current_user_id(), $reason, $kind);

        wp_safe_redirect(add_query_arg('mk_video_declined', '1', $back));
        exit;
    }

    /**
     * Record the decline and hand it to the operator.
     *
     * Public so it can be exercised without a request; the handler above is
     * the only caller in production.
     */
    public static function decline(WC_Order $order, int $creatorId, string $reason, string $kind = 'other'): int
    {
        $kinds = self::declineKinds();
        $kind  = isset($kinds[$kind]) ? $kind : 'other';

        $order->update_meta_data(self::META_DECLINED_AT, gmdate('Y-m-d H:i:s'));
        $order->update_meta_data(self::META_DECLINE_REASON, $reason);
        $order->update_meta_data(self::META_DECLINE_KIND, $kind);
        $order->add_order_note(sprintf(
            'クリエイターがリクエストを辞退しました。運営の確認待ちです。種類：%s／理由：%s',
            $kinds[$kind],
            $reason
        ));
        $order->save();

        // A declined order is not late. Left running, the deadline would tell
        // the buyer to request a cancellation that is already in hand, and
        // count a lateness against a creator who did the right thing.
        Jobs::cancelDispatchOverdue($order->get_id());

        $reportId = (new ReportService())->open(
            $creatorId,
            ReportService::TARGET_ORDER,
            $order->get_id(),
            self::REASON,
            sprintf('【%s】%s', $kinds[$kind], $reason)
        );

        do_action('mk_video_declined', $order->get_id(), $creatorId);

        return $reportId;
    }

    /**
     * The operator closed a decline without refunding: the creator records
     * after all.
     *
     * A refund cancels the order, so an order still at 購入済 after its decline
     * was resolved is one the operator decided should go ahead. The decline is
     * lifted and the clock restarts, so the creator has the full promised time
     * rather than whatever was left before they declined.
     */
    public static function onReportResolved(int $reportId, int $adminId): void
    {
        $report = (new ReportService())->find($reportId);

        if ($report === null || $report->reason !== self::REASON || $report->target_type !== ReportService::TARGET_ORDER) {
            return;
        }

        $order = wc_get_order((int) $report->target_id);

        if (!$order instanceof WC_Order || $order->get_status() !== Statuses::PAID) {
            return;
        }

        $order->delete_meta_data(self::META_DECLINED_AT);
        $order->add_order_note('運営が辞退を認めず、撮影を継続する判断をしました。送信期限を再設定します。');
        $order->save();

        DispatchDeadline::start($order);

        do_action('mk_video_decline_rejected', $order->get_id(), (int) $order->get_meta('_mk_creator_id'));
    }

    private static function assertOwner(WC_Order $order): void
    {
        $isOwner = (int) $order->get_meta('_mk_creator_id') === get_current_user_id();

        if (!$isOwner && !current_user_can('manage_woocommerce')) {
            wp_die('この注文を操作する権限がありません。', '', ['response' => 403]);
        }
    }

    // --------------------------------------------------------- buyer's side

    public static function renderForBuyer(WC_Order $order): void
    {
        if (!Delivery::needsDelivery($order) || get_current_user_id() !== $order->get_customer_id()) {
            return;
        }

        $status = $order->get_status();

        if (!in_array($status, [Statuses::PAID, Statuses::SHIPPED, Statuses::RECEIVED, 'completed'], true)) {
            return;
        }

        $noun  = Delivery::noun($order);
        $audio = Delivery::kind($order) === 'audio';

        printf('<section class="mk-video-panel"><h2>%s</h2>', esc_html($noun));

        if (MessageVideo::isMessageVideoOrder($order)) {
            echo MessageVideo::renderRequestSummary($order); // escaped inside
        } elseif (MessageVideo::hasRequest($order)) {
            echo MessageVideo::renderRequestSummary($order, false); // escaped inside
        } else {
            echo '<p class="mk-video-panel__notice">ご希望の内容は、この取引のメッセージから'
                . 'クリエイターへお伝えください。</p>';
        }

        if (!Delivery::isDelivered($order)) {
            if (self::isDeclined($order)) {
                echo '<p class="mk-video-panel__notice">クリエイターが今回のリクエストをお受けできないとの連絡がありました。'
                    . '運営が内容を確認のうえ、対応を決定いたします。結果はメールでお知らせします。</p>';
            } else {
                printf(
                    '<p class="mk-video-panel__notice">クリエイターが準備中です。'
                    . '<strong class="mk-due">%s頃まで</strong>に送信される予定です。'
                    . '送信されるとメールでお知らせします。</p>',
                    esc_html(DispatchDeadline::dueLabel($order))
                );
            }

            echo '</section>';

            return;
        }

        $url = (string) $order->get_meta(self::META_URL);

        printf(
            '<p><a class="mk-video-panel__watch" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url($url),
            $audio ? '音声を聴く' : '動画を見る'
        );

        printf(
            '<p class="mk-video-panel__rules"><strong>%sのURLの第三者への共有・転載・再配布は禁止されています。</strong>'
            . 'ご自身でご視聴になる目的でのみご利用ください。</p>',
            esc_html($noun)
        );

        if ($status === Statuses::SHIPPED) {
            printf(
                '<p>内容をご確認のうえ、問題がなければ「受取完了」を押してください。'
                . '%d日間受取完了が行われない場合は、自動的に受取完了となります。</p>',
                (int) get_option('mk_auto_complete_days', 7)
            );

            printf(
                '<form method="post" action="%s"><button type="submit" class="button alt" '
                . 'onclick="return confirm(\'受取完了にします。よろしいですか？\');">受取完了</button></form>',
                esc_url(Receipt::confirmUrl($order))
            );
        }

        echo '</section>';
    }
}
