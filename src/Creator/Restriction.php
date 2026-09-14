<?php
declare(strict_types=1);

namespace MK\Creator;

use MK\Order\DispatchDeadline;

/**
 * 出品制限 — stopping a creator from listing anything new.
 *
 * The last step of the undelivered-item flow the client specified: after
 * repeated or deliberate failures to post what people paid for, the operator
 * can stop the creator putting more items up for sale.
 *
 * ---------------------------------------------------------------------------
 * What it stops, and what it deliberately does not
 * ---------------------------------------------------------------------------
 * A restricted creator cannot PUBLISH. They keep their account, their shop
 * page, their dashboard, their existing orders and -- most importantly -- the
 * ability to register a shipment and to be paid for sales already made.
 *
 * Cutting those off would punish the buyers, not the creator: someone who has
 * already paid would be left with a seller who now cannot mark their parcel as
 * sent. The restriction is about not taking any MORE money from anyone.
 *
 * Existing listings are hidden as well as blocked, because leaving them
 * purchasable would let a restricted creator keep selling their entire
 * catalogue -- the restriction would only bite when they ran out of stock.
 * They go to draft, so nothing is destroyed and lifting the restriction is a
 * decision the operator can reverse in one click.
 *
 * Applied by hand, never automatically. DispatchDeadline counts lateness and
 * tells the operator when a pattern forms; whether that pattern is carelessness
 * or fraud is a judgement, and a counter cannot make it.
 */
final class Restriction
{
    public const USER_META = 'mk_listing_restricted';
    public const USER_NOTE = 'mk_listing_restricted_note';
    public const USER_AT   = 'mk_listing_restricted_at';

    /** Marks a listing that went to draft because of a restriction. */
    public const META_BLOCKED = '_mk_blocked_by_restriction';

    private const SLUG  = 'mk-creators';
    private const NONCE = 'mk_set_restriction';

    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'gate'], 20, 2);

        add_action('dokan_dashboard_content_inside_before', [self::class, 'notice']);
        add_action('dokan_new_product_before_product_area', [self::class, 'notice']);

        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_set_restriction', [self::class, 'handleSave']);
    }

    public static function isRestricted(int $userId): bool
    {
        return $userId > 0 && get_user_meta($userId, self::USER_META, true) === 'yes';
    }

    /**
     * Apply or lift the restriction.
     *
     * Applying it also hides everything currently on sale; lifting it does NOT
     * put those listings back automatically. They return through the normal
     * approval queue, because the reason they were pulled was a doubt about
     * the seller and the operator should see what goes back up.
     */
    public static function set(int $userId, bool $restricted, string $note = ''): void
    {
        if ($restricted) {
            update_user_meta($userId, self::USER_META, 'yes');
            update_user_meta($userId, self::USER_AT, current_time('mysql', true));
            update_user_meta($userId, self::USER_NOTE, $note);

            self::unpublishListings($userId);

            do_action('mk_creator_restricted', $userId, $note);

            return;
        }

        delete_user_meta($userId, self::USER_META);
        delete_user_meta($userId, self::USER_AT);
        update_user_meta($userId, self::USER_NOTE, $note);

        do_action('mk_creator_unrestricted', $userId);
    }

    /** @return int how many listings were taken down */
    private static function unpublishListings(int $userId): int
    {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'pending'],
            'author'         => $userId,
            'posts_per_page' => 500,
            'fields'         => 'ids',
        ]);

        foreach ($ids as $id) {
            wp_update_post(['ID' => (int) $id, 'post_status' => 'draft']);
            update_post_meta((int) $id, self::META_BLOCKED, 'yes');
        }

        return count($ids);
    }

    /**
     * The gate itself, in the one place every write to the posts table passes.
     *
     * Same reasoning as Product\PublishGate: blocking the vendor dashboard
     * form alone would leave the REST route open, and Dokan has one.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $postarr
     * @return array<string,mixed>
     */
    public static function gate(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'product') {
            return $data;
        }

        $status = (string) ($data['post_status'] ?? '');

        if ($status !== 'publish' && $status !== 'pending') {
            return $data;
        }

        $author = (int) ($data['post_author'] ?? 0);

        // Platform-owned listings are not part of this: the operator is not
        // restricting themselves, and an administrator's product has no
        // creator whose conduct is in question.
        if ($author === 0 || user_can($author, 'manage_woocommerce')) {
            return $data;
        }

        if (!self::isRestricted($author)) {
            return $data;
        }

        $data['post_status'] = 'draft';

        return $data;
    }

    /** Tell the creator, wherever they try to list. */
    public static function notice(): void
    {
        $userId = get_current_user_id();

        if (!self::isRestricted($userId)) {
            return;
        }

        $note = trim((string) get_user_meta($userId, self::USER_NOTE, true));

        printf(
            '<div class="dokan-alert dokan-alert-danger">'
            . '<strong>現在、新しい商品の公開を制限しています。</strong><br>'
            . '商品の保存はできますが、公開・審査申請はできません。%s<br>'
            . '進行中のお取引の発送登録と、売上のお受け取りはこれまでどおり行えます。'
            . '詳細は運営までお問い合わせください。</div>',
            $note !== '' ? '<br>運営からの連絡：' . esc_html($note) : ''
        );
    }

    // ------------------------------------------------------------------ admin

    public static function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            '出品者の管理',
            '出品者の管理',
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。');
        }

        echo '<div class="wrap"><h1>出品者の管理</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>設定を変更しました。</p></div>';
        }

        echo '<p>発送期限を過ぎた取引の件数と、出品制限の状態です。'
            . '出品制限を設定すると、その出品者は新しい商品を公開できなくなり、'
            . '公開中・審査待ちの商品は下書きに戻ります。'
            . '<strong>進行中の取引の発送登録と売上のお受け取りは制限されません。</strong></p>';

        $sellers = get_users([
            'role__in' => ['seller', 'vendor'],
            'number'   => 200,
            'orderby'  => 'display_name',
        ]);

        if (!$sellers) {
            echo '<p>出品者が登録されていません。</p></div>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th>出品者</th><th style="width:160px">ショップ名</th>'
            . '<th style="width:110px">発送遅延</th><th style="width:120px">状態</th>'
            . '<th style="width:380px">操作</th></tr></thead><tbody>';

        $threshold = (int) get_option('mk_late_dispatch_threshold', 3);

        foreach ($sellers as $seller) {
            $late       = DispatchDeadline::lateCount((int) $seller->ID);
            $restricted = self::isRestricted((int) $seller->ID);
            $shop       = function_exists('dokan_get_store_info')
                ? (string) (dokan_get_store_info($seller->ID)['store_name'] ?? '')
                : '';

            echo '<tr>';
            printf(
                '<td><a href="%s">%s</a><br><small>%s</small></td>',
                esc_url((string) get_edit_user_link($seller->ID)),
                esc_html($seller->display_name),
                esc_html($seller->user_email)
            );
            printf('<td>%s</td>', esc_html($shop !== '' ? $shop : '—'));
            printf(
                '<td>%s</td>',
                $late >= $threshold
                    ? sprintf('<strong style="color:#b32d2e">%d件</strong>', $late)
                    : sprintf('%d件', $late)
            );
            printf(
                '<td>%s</td>',
                $restricted
                    ? '<span style="color:#b32d2e">出品制限中</span>'
                    : '通常'
            );

            echo '<td>';
            self::renderForm((int) $seller->ID, $restricted);
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function renderForm(int $userId, bool $restricted): void
    {
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_set_restriction">';
        printf('<input type="hidden" name="user_id" value="%d">', $userId);

        printf(
            '<input type="text" name="note" value="%s" placeholder="理由・出品者への連絡事項（任意）" style="width:100%%;margin-bottom:4px">',
            esc_attr((string) get_user_meta($userId, self::USER_NOTE, true))
        );

        if ($restricted) {
            echo '<button type="submit" name="restrict" value="0" class="button button-primary">出品制限を解除する</button> ';
        } else {
            echo '<button type="submit" name="restrict" value="1" class="button" '
                . 'onclick="return confirm(\'この出品者の新規公開を制限し、公開中の商品を下書きに戻します。よろしいですか？\');">'
                . '出品を制限する</button> ';
        }

        echo '<button type="submit" name="reset_late" value="1" class="button">遅延件数をリセット</button>';
        echo '</form>';
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $note   = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if ($userId > 0) {
            if (!empty($_POST['reset_late'])) {
                delete_user_meta($userId, DispatchDeadline::USER_LATE_COUNT);
            } elseif (isset($_POST['restrict'])) {
                self::set($userId, $_POST['restrict'] === '1', $note);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }
}
