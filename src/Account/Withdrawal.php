<?php
declare(strict_types=1);

namespace MK\Account;

use MK\Order\Statuses;
use MK\Report\Service as ReportService;
use WP_Error;
use WP_User;

/**
 * 退会 -- leaving the service, on the terms' conditions (第9条).
 *
 * ---------------------------------------------------------------------------
 * Not while anything is unfinished
 * ---------------------------------------------------------------------------
 * The terms refuse withdrawal while a transaction, a shipment, a refund or any
 * other procedure is open. blockers() is that rule, checked from the account's
 * actual orders, reports and ledger, and it is checked again when the button
 * is pressed -- the page may have been open for a day.
 *
 * ---------------------------------------------------------------------------
 * Withdrawal closes the account; it does not erase it
 * ---------------------------------------------------------------------------
 * The terms keep obligations from before withdrawal alive and allow the
 * operator to keep what the law, disputes and fraud prevention need (第9条3項・
 * 6項). So nothing is deleted. The account stops working: it cannot log in or
 * reset its password, every session is ended, and a creator's listings are
 * taken down. Orders, messages, reports and ledger rows stay exactly where
 * they are, still pointing at the same user id.
 *
 * The email address is the one thing moved. Left in place it would make the
 * person unable to sign up again with their own address, forever, for having
 * left voluntarily. The original is kept in user meta, which is where the
 * operator looks for it.
 */
final class Withdrawal
{
    public const ENDPOINT = 'mk-withdraw';

    public const META_AT       = 'mk_withdrawn_at';
    public const META_EMAIL    = 'mk_withdrawn_email';
    public const META_LISTINGS = 'mk_withdrawn_listings';

    private const NONCE = 'mk_withdraw';

    /** @var string[] */
    private static array $errors = [];

    public static function register(): void
    {
        add_action('init', [self::class, 'addEndpoint']);
        add_filter('woocommerce_get_query_vars', [self::class, 'addQueryVar']);
        add_filter('woocommerce_account_menu_items', [self::class, 'addMenuItem'], 20);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [self::class, 'title']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handleSubmit']);

        // A withdrawn account stays closed.
        add_filter('wp_authenticate_user', [self::class, 'refuseLogin'], 99, 1);
        add_filter('allow_password_reset', [self::class, 'refuseReset'], 99, 2);

        add_action('woocommerce_before_customer_login_form', [self::class, 'farewell']);

        add_filter('manage_users_columns', [self::class, 'userColumn']);
        add_filter('manage_users_custom_column', [self::class, 'userColumnValue'], 10, 3);
    }

    public static function addEndpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_PAGES);

        if (get_option('mk_withdraw_endpoint_flushed') !== 'yes') {
            flush_rewrite_rules(false);
            update_option('mk_withdraw_endpoint_flushed', 'yes');
        }
    }

    /** @param array<string,string> $vars */
    public static function addQueryVar(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    /**
     * Just before logout, the last thing in the menu.
     *
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public static function addMenuItem(array $items): array
    {
        if (current_user_can('manage_woocommerce')) {
            return $items;
        }

        $new = [];

        foreach ($items as $key => $label) {
            if ($key === 'customer-logout') {
                $new[self::ENDPOINT] = '退会';
            }

            $new[$key] = $label;
        }

        $new[self::ENDPOINT] ??= '退会';

        return $new;
    }

    public static function title(): string
    {
        return '退会';
    }

    public static function isWithdrawn(int $userId): bool
    {
        return $userId > 0 && (string) get_user_meta($userId, self::META_AT, true) !== '';
    }

    // ------------------------------------------------------------ blockers

    /**
     * Why this account cannot leave yet: one line per kind of unfinished
     * business, empty when it can.
     *
     * @return string[]
     */
    public static function blockers(int $userId): array
    {
        $reasons = [];

        $idsLabel = static fn (array $ids): string => implode('、', array_map(static fn ($id): string => '#' . (int) $id, array_slice($ids, 0, 5)))
            . (count($ids) > 5 ? ' ほか' : '');

        // ---- as a buyer
        $buying = self::orderIds(['customer_id' => $userId], [Statuses::PAID, Statuses::SHIPPED]);

        if ($buying !== []) {
            $reasons[] = sprintf('お届け・受取が完了していないご注文があります（%s）', $idsLabel($buying));
        }

        if (self::recentPendingIds(['customer_id' => $userId]) !== []) {
            $reasons[] = 'お支払い手続き中のご注文があります';
        }

        // ---- as a creator
        $creatorQuery = ['meta_query' => [['key' => '_mk_creator_id', 'value' => (string) $userId]]];

        $toShip = self::orderIds($creatorQuery, [Statuses::PAID]);

        if ($toShip !== []) {
            $reasons[] = sprintf('発送（提供）が完了していない注文があります（%s）', $idsLabel($toShip));
        }

        $awaitingReceipt = self::orderIds($creatorQuery, [Statuses::SHIPPED]);

        if ($awaitingReceipt !== []) {
            $reasons[] = sprintf('購入者の受取確認が完了していない注文があります（%s）', $idsLabel($awaitingReceipt));
        }

        $awaitingPayout = self::orderIds($creatorQuery, [Statuses::RECEIVED]);

        if ($awaitingPayout !== []) {
            $reasons[] = sprintf('売上金の支払い手続きが完了していない注文があります（%s）', $idsLabel($awaitingPayout));
        }

        if (self::recentPendingIds($creatorQuery) !== []) {
            $reasons[] = '購入手続き中の出品商品があります';
        }

        if ((new \MK\Ledger\Recorder())->outstanding($userId) > 0) {
            $reasons[] = '未回収額（返金等によりお支払いいただく金額）が残っています';
        }

        // ---- anything the operator is still looking at, on either side
        if (self::hasOpenReports($userId)) {
            $reasons[] = '運営が確認中の申し出・返金等の手続きがあります';
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $query
     * @param string[] $statuses
     * @return int[]
     */
    private static function orderIds(array $query, array $statuses): array
    {
        return array_map('intval', wc_get_orders($query + [
            'status' => $statuses,
            'limit'  => -1,
            'return' => 'ids',
        ]));
    }

    /**
     * Unpaid orders from the last hour: a payment may be completing right now.
     * Older ones are abandoned checkouts, swept on their own, and must not
     * block anybody forever.
     *
     * @param array<string, mixed> $query
     * @return int[]
     */
    private static function recentPendingIds(array $query): array
    {
        return array_map('intval', wc_get_orders($query + [
            'status'       => ['pending'],
            'date_created' => '>' . (time() - HOUR_IN_SECONDS),
            'limit'        => -1,
            'return'       => 'ids',
        ]));
    }

    private static function hasOpenReports(int $userId): bool
    {
        global $wpdb;

        $filed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mk_reports WHERE reporter_id = %d AND status <> %s",
            $userId,
            ReportService::STATUS_RESOLVED
        ));

        if ($filed > 0) {
            return true;
        }

        // Reports and chargebacks against their orders, either side.
        $flag = ['key' => ReportService::ORDER_FLAG, 'value' => 'yes'];

        $asBuyer   = wc_get_orders(['customer_id' => $userId, 'meta_query' => [$flag], 'limit' => 1, 'return' => 'ids']);
        $asCreator = wc_get_orders(['meta_query' => [['key' => '_mk_creator_id', 'value' => (string) $userId], $flag], 'limit' => 1, 'return' => 'ids']);

        return $asBuyer !== [] || $asCreator !== [];
    }

    // ------------------------------------------------------------ withdraw

    /** Close the account. Callers check blockers() first. */
    public static function withdraw(int $userId): void
    {
        $user = get_userdata($userId);

        if (!$user instanceof WP_User || self::isWithdrawn($userId)) {
            return;
        }

        update_user_meta($userId, self::META_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_EMAIL, $user->user_email);

        // Kept so a member under a measure cannot sign up again with them. See Suspension.
        update_user_meta($userId, Suspension::META_PHONES, Suspension::phonesOf($userId));

        // Told while the address is still theirs.
        do_action('mk_user_withdrawn', $userId, $user->user_email);

        // Take a creator's listings down, remembering what they were.
        $listings = get_posts([
            'post_type'   => 'product',
            'author'      => $userId,
            'post_status' => ['publish', 'pending', \MK\Product\Statuses::RESERVED],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        if ($listings !== []) {
            update_user_meta($userId, self::META_LISTINGS, array_map('intval', $listings));

            foreach ($listings as $productId) {
                wp_update_post(['ID' => (int) $productId, 'post_status' => 'draft']);
            }
        }

        if (function_exists('dokan_is_user_seller') && dokan_is_user_seller($userId)) {
            update_user_meta($userId, 'dokan_enable_selling', 'no');
        }

        // Free the address for a future sign-up, without the "your email
        // changed" message WordPress would otherwise send to the old one.
        add_filter('send_email_change_email', '__return_false', 99);
        wp_update_user([
            'ID'         => $userId,
            'user_email' => sprintf('withdrawn-%d-%s@withdrawn.invalid', $userId, strtolower(wp_generate_password(8, false, false))),
        ]);
        remove_filter('send_email_change_email', '__return_false', 99);

        \WP_Session_Tokens::get_instance($userId)->destroy_all();
    }

    /**
     * @param WP_User|WP_Error $user
     * @return WP_User|WP_Error
     */
    public static function refuseLogin($user)
    {
        if ($user instanceof WP_User && self::isWithdrawn($user->ID)) {
            return new WP_Error('mk_withdrawn', 'このアカウントは退会済みのため、ログインできません。');
        }

        return $user;
    }

    /** @param bool|WP_Error $allow */
    public static function refuseReset($allow, $userId = 0)
    {
        return self::isWithdrawn((int) $userId) ? false : $allow;
    }

    // --------------------------------------------------------------- page

    public static function handleSubmit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['mk_withdraw_submit'])) {
            return;
        }

        $user = wp_get_current_user();

        if (!$user->exists() || user_can($user, 'manage_woocommerce')) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_mk_withdraw_nonce'] ?? '')));

        if (!wp_verify_nonce($nonce, self::NONCE)) {
            self::$errors = ['画面の有効期限が切れました。お手数ですが、もう一度お試しください。'];

            return;
        }

        // Again, now: the page may have been open while an order came in.
        if (self::blockers($user->ID) !== []) {
            self::$errors = ['現在、未完了の取引または手続きがあるため退会できません。'];

            return;
        }

        if (empty($_POST['mk_withdraw_confirm'])) {
            self::$errors[] = '退会に関する注意事項を確認し、チェックを入れてください。';
        }

        $password = (string) wp_unslash($_POST['mk_withdraw_password'] ?? '');

        if ($password === '' || !wp_check_password($password, $user->user_pass, $user->ID)) {
            self::$errors[] = 'パスワードが正しくありません。';
        }

        if (self::$errors !== []) {
            return;
        }

        self::withdraw($user->ID);
        wp_logout();

        wp_safe_redirect(add_query_arg('mk_withdrawn', '1', wc_get_page_permalink('myaccount')));
        exit;
    }

    public static function render(): void
    {
        $userId = get_current_user_id();

        if (current_user_can('manage_woocommerce')) {
            echo '<p>管理者アカウントは、この画面から退会できません。</p>';

            return;
        }

        echo '<section class="mk-withdraw">';

        echo '<p>退会すると、次のようになります。</p><ul class="mk-withdraw__notes">'
            . '<li>このアカウントでログインできなくなります。</li>'
            . '<li>出品中の商品はすべて非公開になります。</li>'
            . '<li>退会前に成立した取引に関する義務は、退会後も残ります。</li>'
            . '<li>法令上保存が必要な情報や、取引の確認・紛争対応・不正防止等に必要な情報は、退会後も必要な期間保存されます。</li>'
            . '</ul>';

        $terms = Terms::url();

        if ($terms !== '') {
            printf('<p>詳しくは<a href="%s" target="_blank" rel="noopener">利用規約</a>第9条をご確認ください。</p>', esc_url($terms));
        }

        $blockers = self::blockers($userId);

        if ($blockers !== []) {
            echo '<div class="woocommerce-error mk-withdraw__blocked" role="alert">'
                . '<p><strong>現在、未完了の取引または手続きがあるため退会できません。'
                . 'すべての取引・手続きが完了してから退会してください。</strong></p><ul>';

            foreach ($blockers as $reason) {
                printf('<li>%s</li>', esc_html($reason));
            }

            echo '</ul></div></section>';

            return;
        }

        if (self::$errors !== []) {
            echo '<div class="woocommerce-error" role="alert"><ul>';

            foreach (self::$errors as $message) {
                printf('<li>%s</li>', esc_html($message));
            }

            echo '</ul></div>';
        }

        echo '<form method="post" class="mk-withdraw__form">';
        wp_nonce_field(self::NONCE, '_mk_withdraw_nonce');
        echo '<input type="hidden" name="mk_withdraw_submit" value="1">';
        echo '<p class="mk-withdraw__field"><label for="mk_withdraw_password">パスワード<span class="required">*</span></label>'
            . '<input type="password" name="mk_withdraw_password" id="mk_withdraw_password" autocomplete="current-password" required></p>';
        echo '<p><label><input type="checkbox" name="mk_withdraw_confirm" value="1" required> 上記の内容を確認し、退会します</label></p>';
        echo '<p><button type="submit" class="button mk-withdraw__button" onclick="return confirm(\'退会すると、このアカウントではログインできなくなります。よろしいですか？\');">退会する</button></p>';
        echo '</form></section>';
    }

    public static function farewell(): void
    {
        if (isset($_GET['mk_withdrawn'])) {
            echo '<div class="woocommerce-message" role="status">退会手続きが完了しました。これまでTREASURE BUZZをご利用いただき、ありがとうございました。</div>';
        }
    }

    /** @param array<string,string> $columns */
    public static function userColumn(array $columns): array
    {
        $columns['mk_withdrawn'] = '退会';

        return $columns;
    }

    /** @param mixed $value */
    public static function userColumnValue($value, $column = '', $userId = 0)
    {
        if ($column !== 'mk_withdrawn') {
            return $value;
        }

        $at    = (string) get_user_meta((int) $userId, self::META_AT, true);
        $lines = [];

        if ($at !== '') {
            $lines[] = esc_html('退会済み（' . get_date_from_gmt($at, 'Y/m/d') . '）')
                . '<br><small>' . esc_html((string) get_user_meta((int) $userId, self::META_EMAIL, true)) . '</small>';
        }

        if (Suspension::isSuspended((int) $userId)) {
            $lines[] = '<strong style="color:#b32d2e">利用停止中</strong>';
        } elseif (\MK\Creator\Restriction::isRestricted((int) $userId)) {
            $lines[] = '<strong style="color:#b32d2e">出品制限中</strong>';
        }

        return $lines === [] ? '—' : implode('<br>', $lines);
    }
}
