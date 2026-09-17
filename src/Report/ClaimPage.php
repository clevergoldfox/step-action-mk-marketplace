<?php
declare(strict_types=1);

namespace MK\Report;

use MK\Order\Statuses;
use RuntimeException;
use WC_Order;

/**
 * 運営への申し出 -- the page a buyer reaches from LINE to raise a problem.
 *
 * The client's terms (第16条・第17条) promise two things this page has to make
 * true: a buyer with a genuine problem can bring it to the operator, and the
 * creator is not paid while the operator is looking. The second already
 * exists -- Service::open() cancels the queued transfer and auto-complete the
 * moment a report is filed -- so this page is the front door onto it: choose
 * the order, say what is wrong, attach photos.
 *
 * It replaces the buyer's half of the form on the order screen, which now
 * links here with that order chosen. One way in means one set of categories,
 * one place photos can be attached, and one thing to explain.
 *
 * Buyers only. A creator's concerns about a buyer go through the order
 * screen, as before.
 */
final class ClaimPage
{
    public const ENDPOINT = 'mk-claim';

    private const NONCE      = 'mk_claim';
    private const MAX_DETAIL = 2000;

    /** @var string[] */
    private static array $errors = [];

    public static function register(): void
    {
        add_action('init', [self::class, 'addEndpoint']);
        add_filter('woocommerce_get_query_vars', [self::class, 'addQueryVar']);
        add_filter('woocommerce_account_menu_items', [self::class, 'addMenuItem']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [self::class, 'title']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handleSubmit']);
    }

    public static function addEndpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_PAGES);

        // An endpoint whose rule was never flushed is a menu item leading to
        // a 404. The stamp is per endpoint, so adding this one flushes once.
        if (get_option('mk_claim_endpoint_flushed') !== 'yes') {
            flush_rewrite_rules(false);
            update_option('mk_claim_endpoint_flushed', 'yes');
        }
    }

    /** @param array<string,string> $vars */
    public static function addQueryVar(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public static function addMenuItem(array $items): array
    {
        $new = [];

        foreach ($items as $key => $label) {
            $new[$key] = $label;

            if ($key === 'orders') {
                $new[self::ENDPOINT] = '運営への申し出';
            }
        }

        if (!isset($new[self::ENDPOINT])) {
            $new = [self::ENDPOINT => '運営への申し出'] + $new;
        }

        return $new;
    }

    public static function title(): string
    {
        return '運営への申し出';
    }

    public static function url(int $orderId = 0): string
    {
        $url = wc_get_account_endpoint_url(self::ENDPOINT);

        return $orderId > 0 ? add_query_arg('order', $orderId, $url) : $url;
    }

    /**
     * The orders a buyer can raise, newest first.
     *
     * From payment onwards: before that nothing has been paid, and a
     * cancelled or refunded order has already been dealt with.
     *
     * @return WC_Order[]
     */
    public static function eligibleOrders(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return wc_get_orders([
            'customer_id' => $userId,
            'status'      => [Statuses::PAID, Statuses::SHIPPED, Statuses::RECEIVED, 'completed'],
            'limit'       => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
    }

    /**
     * What is wrong with a claim, as buyer-readable messages.
     *
     * @param array<string, mixed> $post  unslashed
     * @param array<string, mixed> $files the mk_claim_files entry of $_FILES
     * @return string[]
     */
    public static function problems(int $userId, array $post, array $files): array
    {
        $errors  = [];
        $orderId = (int) ($post['mk_claim_order'] ?? 0);
        $order   = null;

        foreach (self::eligibleOrders($userId) as $candidate) {
            if ($candidate->get_id() === $orderId) {
                $order = $candidate;
                break;
            }
        }

        if ($order === null) {
            $errors[] = '申し出の対象となる注文を選んでください。';
        } elseif ((new Service())->openCountFor(Service::TARGET_ORDER, $orderId) > 0) {
            $errors[] = 'この注文については、すでに申し出を受け付けています。運営の確認をお待ちください。';
        }

        if (!isset(Service::claimCategories()[(string) ($post['mk_claim_category'] ?? '')])) {
            $errors[] = '申し出の内容を選んでください。';
        }

        $detail = trim((string) ($post['mk_claim_detail'] ?? ''));

        if ($detail === '') {
            $errors[] = '詳細を入力してください。';
        } elseif (mb_strlen($detail) > self::MAX_DETAIL) {
            $errors[] = sprintf('詳細は%d文字以内で入力してください。', self::MAX_DETAIL);
        }

        return array_merge($errors, ClaimFiles::problems($files));
    }

    /**
     * File the claim: open the report (which holds the payout) and keep the photos.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @throws RuntimeException with buyer-readable messages
     * @return int the report id
     */
    public static function submit(int $userId, array $post, array $files, bool $uploaded): int
    {
        $problems = self::problems($userId, $post, $files);

        if ($problems !== []) {
            throw new RuntimeException(implode("\n", $problems));
        }

        $orderId  = (int) $post['mk_claim_order'];
        $category = (string) $post['mk_claim_category'];
        $detail   = sanitize_textarea_field(trim((string) $post['mk_claim_detail']));

        $reportId = (new Service())->open($userId, Service::TARGET_ORDER, $orderId, $category, $detail);

        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order) {
            $count = ClaimFiles::store($order, $reportId, $files, $uploaded);

            if ($count > 0) {
                $order = wc_get_order($orderId);
                $order->add_order_note(sprintf('申し出（#%d）に画像が%d枚添付されました。', $reportId, $count));
                $order->save();
            }
        }

        return $reportId;
    }

    public static function handleSubmit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['mk_claim_submit'])) {
            return;
        }

        $userId = get_current_user_id();

        if ($userId === 0) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_mk_claim_nonce'] ?? '')));

        if (!wp_verify_nonce($nonce, self::NONCE)) {
            self::$errors = ['画面の有効期限が切れました。お手数ですが、もう一度送信してください。'];

            return;
        }

        $files = isset($_FILES['mk_claim_files']) && is_array($_FILES['mk_claim_files']) ? $_FILES['mk_claim_files'] : [];

        try {
            self::submit($userId, wp_unslash($_POST), $files, true);
        } catch (RuntimeException $e) {
            self::$errors = explode("\n", $e->getMessage());   // shown on this request, with the form refilled

            return;
        }

        wp_safe_redirect(add_query_arg('mk_claimed', '1', self::url()));
        exit;
    }

    // --------------------------------------------------------------- render

    public static function render(): void
    {
        $userId  = get_current_user_id();
        $service = new Service();
        $posted  = self::$errors !== [];

        if (isset($_GET['mk_claimed'])) {
            echo '<div class="woocommerce-message mk-claim__done" role="status">'
                . '<strong>申し出を受け付けました。</strong><br>'
                . '運営が内容を確認し、ご登録のメールアドレスへご連絡いたします。'
                . '確認が完了するまで、この取引の出品者への売上金の支払いは保留されます。</div>';
        }

        echo '<section class="mk-claim">';

        echo '<p>ご購入いただいた取引について問題がある場合は、こちらから運営へお申し出ください。'
            . '運営が内容を確認のうえ、必要に応じて取引のキャンセル、返品、返金その他の対応を行います。</p>';

        // The policy, before the form rather than after the request. A buyer
        // who reads this and closes the page has been served better than one
        // who fills in a form that was never going to succeed.
        echo '<div class="mk-report__policy"><p><strong>お客様のご都合による返品・返金はお受けしておりません。</strong><br>'
            . '「イメージと違った」「サイズが合わなかった」「間違えて購入した」「気が変わった」「必要なくなった」といった理由では、'
            . 'キャンセル・ご返金はいたしかねます。</p>'
            . '<p>ただし、<strong>商品が届かない場合や、出品内容と実際の商品に明らかな相違がある場合</strong>などは、'
            . '下記よりお申し出ください。運営が内容を確認のうえ、対応を判断いたします。</p>'
            . '<p>お申し出をいただくと、<strong>運営による確認が完了するまで、出品者への売上金の支払いは保留されます。</strong></p></div>';

        if (self::$errors !== []) {
            echo '<div class="woocommerce-error mk-claim__errors" role="alert"><ul>';

            foreach (self::$errors as $message) {
                printf('<li>%s</li>', esc_html($message));
            }

            echo '</ul></div>';
        }

        $orders = self::eligibleOrders($userId);

        if ($orders === []) {
            echo '<p>現在、申し出の対象となるご注文はありません。</p>';
        } else {
            self::renderForm($orders, $service, $posted);
        }

        self::renderHistory($userId);

        echo '</section>';
    }

    /** @param WC_Order[] $orders */
    private static function renderForm(array $orders, Service $service, bool $posted): void
    {
        $chosenOrder    = $posted ? (int) ($_POST['mk_claim_order'] ?? 0) : (int) ($_GET['order'] ?? 0);
        $chosenCategory = $posted ? sanitize_key(wp_unslash((string) ($_POST['mk_claim_category'] ?? ''))) : '';
        $detail         = $posted ? sanitize_textarea_field(wp_unslash((string) ($_POST['mk_claim_detail'] ?? ''))) : '';

        echo '<form method="post" enctype="multipart/form-data" class="mk-claim__form">';
        wp_nonce_field(self::NONCE, '_mk_claim_nonce');
        echo '<input type="hidden" name="mk_claim_submit" value="1">';

        echo '<p class="mk-claim__field"><label for="mk_claim_order">対象の注文<span class="required">*</span></label>'
            . '<select name="mk_claim_order" id="mk_claim_order" required><option value="">選択してください</option>';

        foreach ($orders as $order) {
            $open  = $service->openCountFor(Service::TARGET_ORDER, $order->get_id()) > 0;
            $label = sprintf(
                '#%d　%s（%s・%s）%s',
                $order->get_id(),
                (string) $order->get_meta('_mk_title_snapshot') ?: '商品',
                $order->get_date_created() ? $order->get_date_created()->date_i18n('Y/m/d') : '',
                wc_get_order_status_name($order->get_status()),
                $open ? '　※申し出受付中' : ''
            );

            printf(
                '<option value="%d"%s%s>%s</option>',
                $order->get_id(),
                selected($chosenOrder, $order->get_id(), false),
                $open ? ' disabled' : '',
                esc_html($label)
            );
        }

        echo '</select></p>';

        echo '<fieldset class="mk-claim__field"><legend>申し出の内容<span class="required">*</span></legend>';

        foreach (Service::claimCategories() as $key => $label) {
            printf(
                '<label class="mk-claim__category"><input type="radio" name="mk_claim_category" value="%s"%s required> %s</label>',
                esc_attr($key),
                checked($chosenCategory, $key, false),
                esc_html($label)
            );
        }

        echo '</fieldset>';

        printf(
            '<p class="mk-claim__field"><label for="mk_claim_detail">詳細<span class="required">*</span></label>'
            . '<textarea name="mk_claim_detail" id="mk_claim_detail" rows="6" maxlength="%d" required '
            . 'placeholder="いつ・どのような問題が起きているか、できるだけ具体的にご記入ください。">%s</textarea></p>',
            self::MAX_DETAIL,
            esc_textarea($detail)
        );

        printf(
            '<p class="mk-claim__field"><label for="mk_claim_files">画像の添付<small>（任意）</small></label>'
            . '<input type="file" name="mk_claim_files[]" id="mk_claim_files" accept="image/jpeg,image/png" multiple>'
            . '<small>商品の状態などがわかる画像を%d枚まで添付できます（JPEG・PNG、1枚10MBまで）。運営のみが確認し、公開されることはありません。</small></p>',
            ClaimFiles::MAX_FILES
        );

        echo '<p><button type="submit" class="button alt" onclick="return confirm(\'運営へ申し出ます。よろしいですか？\');">運営に申し出る</button></p>';
        echo '</form>';
    }

    private static function renderHistory(int $userId): void
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mk_reports
              WHERE reporter_id = %d AND target_type = %s
           ORDER BY id DESC LIMIT 20",
            $userId,
            Service::TARGET_ORDER
        )) ?: [];

        if ($rows === []) {
            return;
        }

        echo '<h3 class="mk-claim__history-title">これまでの申し出</h3>'
            . '<table class="shop_table mk-claim__history"><thead><tr><th>申し出日</th><th>注文</th><th>内容</th><th>状況</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            printf(
                '<tr><td>%s</td><td>#%d</td><td>%s</td><td>%s</td></tr>',
                esc_html(get_date_from_gmt((string) $row->created_at, 'Y/m/d')),
                (int) $row->target_id,
                esc_html(Service::reasonLabel((string) $row->reason)),
                $row->status === Service::STATUS_RESOLVED ? '対応済み' : '運営が確認中'
            );
        }

        echo '</tbody></table>';
    }
}
