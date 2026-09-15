<?php
declare(strict_types=1);

namespace MK\Creator;

/**
 * WooCommerce → 事業者申請: where the operator reviews business applications.
 *
 * Not optional, for the same reason the report screen is not: a business is
 * held from publishing until someone approves it, and a hold with no release
 * is a creator who can never sell.
 */
final class BusinessAdmin
{
    private const SLUG           = 'mk-business';
    private const NONCE_DECISION = 'mk_business_decision';
    private const NONCE_LICENSE  = 'mk_business_license';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_business_decision', [self::class, 'handleDecision']);
        add_action('admin_post_mk_business_license', [self::class, 'streamLicense']);
    }

    /** @return int[] user ids with an application in this status */
    public static function applicants(string $status): array
    {
        return array_map('intval', get_users([
            'meta_key'   => Business::META_STATUS,
            'meta_value' => $status,
            'fields'     => 'ID',
            'number'     => 200,
        ]));
    }

    public static function addMenu(): void
    {
        $pending = count(self::applicants(Business::STATUS_PENDING));

        add_submenu_page(
            'woocommerce',
            '事業者申請',
            $pending > 0 ? sprintf('事業者申請 <span class="awaiting-mod">%d</span>', $pending) : '事業者申請',
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

        $userId = isset($_GET['user']) ? (int) $_GET['user'] : 0;

        echo '<div class="wrap"><h1>事業者申請</h1>';

        if (isset($_GET['decided'])) {
            echo '<div class="notice notice-success is-dismissible"><p>審査結果を保存し、申請者に通知しました。</p></div>';
        }

        if ($userId > 0 && Business::isBusiness($userId)) {
            self::renderDetail($userId);
        } else {
            self::renderList();
        }

        echo '</div>';
    }

    private static function renderList(): void
    {
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : Business::STATUS_PENDING;
        $labels = Business::statusLabels();

        if (!isset($labels[$status])) {
            $status = Business::STATUS_PENDING;
        }

        echo '<p>法人・個人事業主として出品する方の申請です。<strong>承認されるまで、その出品者は商品を公開できません。</strong>'
            . '申請画面では「最大1週間程度」とご案内しています。</p>';

        echo '<ul class="subsubsub">';

        foreach ($labels as $key => $label) {
            printf(
                '<li><a href="%s"%s>%s（%d）</a> | </li>',
                esc_url(admin_url('admin.php?page=' . self::SLUG . '&status=' . $key)),
                $status === $key ? ' class="current"' : '',
                esc_html($label),
                count(self::applicants($key))
            );
        }

        echo '</ul><br class="clear">';

        $ids = self::applicants($status);

        if (!$ids) {
            echo '<p>該当する申請はありません。</p>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th style="width:150px">申請日</th><th>申請者</th><th style="width:110px">区分</th>'
            . '<th>法人名・屋号</th><th style="width:120px">古物商許可</th><th style="width:90px"></th>'
            . '</tr></thead><tbody>';

        $types = Business::businessTypes();

        foreach ($ids as $id) {
            $user = get_userdata($id);
            $data = Business::applicationOf($id);

            echo '<tr>';
            printf('<td>%s</td>', esc_html(get_date_from_gmt((string) get_user_meta($id, Business::META_APPLIED_AT, true), 'Y-m-d H:i')));
            printf('<td>%s<br><small>%s</small></td>', esc_html($user ? $user->display_name : '#' . $id), esc_html($user ? $user->user_email : ''));
            printf('<td>%s</td>', esc_html($types[$data['business_type'] ?? ''] ?? '—'));
            printf('<td>%s</td>', esc_html((string) ($data['business_name'] ?? '—')));
            printf('<td>%s</td>', !empty($data['needs_kobutsu']) ? '必要（提出あり）' : '—');
            printf('<td><a class="button" href="%s">詳細</a></td>', esc_url(admin_url('admin.php?page=' . self::SLUG . '&user=' . $id)));
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderDetail(int $userId): void
    {
        $user   = get_userdata($userId);
        $data   = Business::applicationOf($userId);
        $status = Business::statusOf($userId);
        $labels = Business::statusLabels();
        $types  = Business::businessTypes();

        printf('<p><a href="%s">← 申請一覧に戻る</a></p>', esc_url(admin_url('admin.php?page=' . self::SLUG)));

        printf(
            '<h2>%s <small>（%s）</small></h2>',
            esc_html((string) ($data['business_name'] ?? '')),
            esc_html($labels[$status] ?? $status)
        );

        echo '<table class="form-table"><tbody>';

        $rows = [
            '申請者アカウント' => $user ? $user->display_name . '（' . $user->user_email . '）' : '#' . $userId,
            '申請日'           => get_date_from_gmt((string) get_user_meta($userId, Business::META_APPLIED_AT, true), 'Y-m-d H:i'),
            '事業者区分'       => $types[$data['business_type'] ?? ''] ?? '—',
        ];

        foreach (Business::fields() as $key => [$label]) {
            $rows[$label] = (string) ($data[$key] ?? '');
        }

        $rows['古物商許可'] = !empty($data['needs_kobutsu']) ? '必要（下記の許可証を確認してください）' : '不要と申告';

        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), $value !== '' ? nl2br(esc_html($value)) : '—');
        }

        echo '<tr><th scope="row">古物商許可証</th><td>';

        if (Business::licensePath($userId) !== '') {
            printf(
                '<a class="button" href="%s" target="_blank" rel="noopener">許可証を表示する</a>'
                . '<p class="description">運営のみ閲覧できます。ファイルは公開領域の外に保管されています。</p>',
                esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=mk_business_license&user=' . $userId),
                    self::NONCE_LICENSE . '_' . $userId
                ))
            );
        } else {
            echo '提出なし';
        }

        echo '</td></tr></tbody></table>';

        $decided = (string) get_user_meta($userId, Business::META_DECIDED_AT, true);

        if ($decided !== '') {
            printf(
                '<p>最終審査：%s／連絡事項：%s</p>',
                esc_html(get_date_from_gmt($decided, 'Y-m-d H:i')),
                esc_html((string) get_user_meta($userId, Business::META_DECISION, true) ?: '—')
            );
        }

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE_DECISION);
        echo '<input type="hidden" name="action" value="mk_business_decision">';
        printf('<input type="hidden" name="user_id" value="%d">', $userId);

        echo '<h3>審査結果</h3>'
            . '<p><textarea name="note" rows="3" class="large-text" placeholder="申請者への連絡事項（承認しない場合は理由をご記入ください）"></textarea></p>'
            . '<p><button type="submit" name="decision" value="approve" class="button button-primary">承認する</button> '
            . '<button type="submit" name="decision" value="reject" class="button" '
            . 'onclick="return confirm(\'この申請を承認しません。申請者に通知されます。よろしいですか？\');">承認しない</button></p>'
            . '</form>';
    }

    public static function handleDecision(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE_DECISION);

        $userId   = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
        $note     = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if ($userId > 0 && Business::isBusiness($userId) && in_array($decision, ['approve', 'reject'], true)) {
            Business::decide($userId, $decision === 'approve', $note, get_current_user_id());
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&user=' . $userId . '&decided=1'));
        exit;
    }

    /**
     * The licence file, to the operator only.
     *
     * The file lives outside the web root, so this handler is the only way to
     * it: capability-checked, nonce-bound to the one applicant, and resolved
     * through Business::licensePath(), which accepts only names this class
     * generated -- no path the request can steer.
     */
    public static function streamLicense(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        $userId = isset($_GET['user']) ? (int) $_GET['user'] : 0;

        check_admin_referer(self::NONCE_LICENSE . '_' . $userId);

        $path = Business::licensePath($userId);

        if ($path === '') {
            wp_die('許可証のファイルが見つかりません。', '', ['response' => 404]);
        }

        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
        $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        nocache_headers();
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="license.' . $ext . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        readfile($path);
        exit;
    }
}
