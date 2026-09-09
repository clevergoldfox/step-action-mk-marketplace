<?php
declare(strict_types=1);

namespace MK\Report;

/**
 * Where the operator actually deals with 通報.
 *
 * Not optional. Opening a report freezes a creator's payout, and without a
 * way to close one the freeze is permanent — the money stops and nobody can
 * start it again. A hold mechanism with no release is a bug, not a feature.
 */
final class Admin
{
    private const SLUG  = 'mk-reports';
    private const NONCE = 'mk_resolve_report';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_resolve_report', [self::class, 'handleResolve']);
        add_action('admin_notices', [self::class, 'pendingNotice']);
    }

    public static function addMenu(): void
    {
        $open  = (new Service())->listByStatus(Service::STATUS_OPEN);
        $count = count($open);

        add_submenu_page(
            'woocommerce',
            '通報の管理',
            $count > 0
                ? sprintf('通報 <span class="awaiting-mod">%d</span>', $count)
                : '通報',
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * An open report means money is stopped, so it is said loudly and on
     * every admin screen rather than only on the one nobody has open.
     */
    public static function pendingNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = get_current_screen();

        if ($screen && str_contains((string) $screen->id, self::SLUG)) {
            return;
        }

        $count = count((new Service())->listByStatus(Service::STATUS_OPEN));

        if ($count === 0) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>'
            . '未対応の通報が <strong>%d件</strong> あります。'
            . '該当する取引の送金は保留されています。'
            . '<a href="%s">通報の管理へ</a></p></div>',
            $count,
            esc_url(admin_url('admin.php?page=' . self::SLUG))
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。');
        }

        $status  = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : Service::STATUS_OPEN;
        $service = new Service();
        $reports = $service->listByStatus($status);

        echo '<div class="wrap"><h1>通報の管理</h1>';

        if (isset($_GET['resolved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>通報を解決済みにしました。</p></div>';
        }

        echo '<ul class="subsubsub">';
        foreach ([Service::STATUS_OPEN => '未対応', Service::STATUS_RESOLVED => '解決済み', 'all' => 'すべて'] as $key => $label) {
            printf(
                '<li><a href="%s"%s>%s</a> | </li>',
                esc_url(admin_url('admin.php?page=' . self::SLUG . '&status=' . $key)),
                $status === $key ? ' class="current"' : '',
                esc_html($label)
            );
        }
        echo '</ul><br class="clear">';

        if (!$reports) {
            echo '<p>該当する通報はありません。</p></div>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th style="width:60px">ID</th><th style="width:140px">対象</th>'
            . '<th style="width:140px">理由</th><th>詳細</th>'
            . '<th style="width:150px">通報者</th><th style="width:150px">日時</th>'
            . '<th style="width:260px">対応</th></tr></thead><tbody>';

        foreach ($reports as $r) {
            $reporter = get_userdata((int) $r->reporter_id);

            echo '<tr>';
            printf('<td>%d</td>', (int) $r->id);
            printf('<td>%s</td>', self::targetCell($r));
            printf('<td>%s</td>', esc_html(Service::reasonLabel((string) $r->reason)));
            printf('<td>%s</td>', $r->comment ? nl2br(esc_html((string) $r->comment)) : '—');
            printf('<td>%s</td>', esc_html($reporter ? $reporter->display_name : '#' . $r->reporter_id));
            printf('<td>%s</td>', esc_html(get_date_from_gmt((string) $r->created_at, 'Y-m-d H:i')));

            echo '<td>';
            if ($r->status === Service::STATUS_RESOLVED) {
                printf(
                    '<span class="dashicons dashicons-yes"></span> %s%s',
                    esc_html(get_date_from_gmt((string) $r->resolved_at, 'Y-m-d H:i')),
                    $r->admin_note ? '<br><small>' . esc_html((string) $r->admin_note) . '</small>' : ''
                );
            } else {
                self::renderResolveForm((int) $r->id);
            }
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function targetCell(object $r): string
    {
        $id = (int) $r->target_id;

        return match ($r->target_type) {
            Service::TARGET_ORDER => sprintf(
                '<a href="%s">注文 #%d</a>',
                esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . $id)),
                $id
            ),
            Service::TARGET_PRODUCT => sprintf(
                '<a href="%s">商品 #%d</a>',
                esc_url(get_edit_post_link($id) ?: '#'),
                $id
            ),
            default => sprintf('ユーザー #%d', $id),
        };
    }

    /**
     * Two buttons, because the two outcomes move money differently and the
     * operator must say which one they mean. A single "resolve" would have to
     * guess, and guessing here either pays a creator whose buyer was right or
     * strands one whose buyer was wrong.
     */
    private static function renderResolveForm(int $reportId): void
    {
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_resolve_report">';
        printf('<input type="hidden" name="report_id" value="%d">', $reportId);
        echo '<input type="text" name="admin_note" placeholder="対応メモ（任意）" style="width:100%;margin-bottom:4px">';
        echo '<button type="submit" name="release" value="1" class="button button-primary">解決（送金を再開）</button> ';
        echo '<button type="submit" name="release" value="0" class="button">解決（送金は保留のまま）</button>';
        echo '</form>';
    }

    public static function handleResolve(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $reportId = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        $note     = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : '';
        $release  = isset($_POST['release']) && $_POST['release'] === '1';

        if ($reportId > 0) {
            (new Service())->resolve($reportId, get_current_user_id(), $note, $release);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&resolved=1'));
        exit;
    }
}
