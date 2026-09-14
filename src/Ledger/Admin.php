<?php
declare(strict_types=1);

namespace MK\Ledger;

use MK\Support\Money;

/**
 * 未回収額の管理 — what sellers owe, and what has been done about it.
 *
 * A refund at the seller's cost leaves them owing the platform money. The
 * first line of recovery is automatic: the next payout is reduced by whatever
 * is outstanding, which for an active seller clears the balance within a sale
 * or two and needs nobody's attention.
 *
 * This screen exists for the case where that never happens -- the seller who
 * caused the refund and then stopped selling. Nothing automatic can recover
 * from them, because there is no payout to deduct from, so the client's
 * process is to bill them directly. That turns into two facts somebody has to
 * be able to record and read back:
 *
 *   請求済み   a bill went out on this date. Recorded, and deliberately does
 *              NOT reduce the balance -- sending an invoice collects nothing,
 *              and treating it as recovery would erase the debt from the
 *              payout path as well, so the money would then be taken from
 *              nowhere at all.
 *   入金確認   they paid. This does reduce it.
 *
 * Everything shown here is derived from the ledger rather than typed into it,
 * so the totals cannot disagree with the history that explains them.
 */
final class Admin
{
    private const SLUG  = 'mk-balances';
    private const NONCE = 'mk_ledger_action';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_ledger_action', [self::class, 'handle']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            '未回収額の管理',
            '未回収額の管理',
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function url(int $userId = 0): string
    {
        $url = admin_url('admin.php?page=' . self::SLUG);

        return $userId > 0 ? $url . '#creator-' . $userId : $url;
    }

    /** Japanese for each ledger entry type. */
    public static function entryLabel(string $type): string
    {
        return [
            Recorder::TRANSFER          => '売上送金',
            Recorder::REVERSAL          => '送金の巻き戻し',
            Recorder::DEBT_INCURRED     => '未回収額の発生（出品者負担）',
            Recorder::DEBT_RECOVERED    => '未回収額の回収',
            Recorder::PLATFORM_ABSORBED => '返金費用（運営負担）',
            Recorder::INVOICED          => '別途請求',
        ][$type] ?? $type;
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。');
        }

        $ledger  = new Recorder();
        $debtors = $ledger->debtors();

        echo '<div class="wrap"><h1>未回収額の管理</h1>';

        if (isset($_GET['done'])) {
            echo '<div class="notice notice-success is-dismissible"><p>記録しました。</p></div>';
        }

        echo '<p>返金にかかった費用のうち、出品者負担としたものの残高です。'
            . '<strong>次回の送金から自動的に差し引かれます。</strong>'
            . '出品を停止しているなどで売上から回収できない場合は、'
            . '別途ご請求のうえ、入金を確認したらこの画面で記録してください。</p>';

        if (!$debtors) {
            echo '<p>未回収額のある出品者はいません。</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
                . '<th>出品者</th><th style="width:120px">未回収額</th>'
                . '<th style="width:150px">最終更新</th><th style="width:420px">操作</th>'
                . '</tr></thead><tbody>';

            foreach ($debtors as $row) {
                $userId = (int) $row->user_id;
                $user   = get_userdata($userId);
                $amount = (int) $row->outstanding;

                printf('<tr id="creator-%d">', $userId);
                printf(
                    '<td><a href="%s">%s</a><br><small>%s</small></td>',
                    esc_url((string) get_edit_user_link($userId)),
                    esc_html($user ? $user->display_name : '#' . $userId),
                    esc_html($user ? $user->user_email : '')
                );
                printf('<td><strong>%s</strong></td>', esc_html(Money::format($amount)));
                printf('<td>%s</td>', esc_html(get_date_from_gmt((string) $row->updated_at, 'Y-m-d H:i')));

                echo '<td>';
                self::renderForm($userId, $amount);
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        self::renderHistory($ledger);

        echo '</div>';
    }

    private static function renderForm(int $userId, int $amount): void
    {
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_ledger_action">';
        printf('<input type="hidden" name="user_id" value="%d">', $userId);

        printf(
            '<p><input type="number" name="amount" value="%d" min="1" max="%d" class="small-text"> 円 '
            . '<input type="text" name="note" placeholder="メモ（任意）" style="width:200px"></p>',
            $amount,
            $amount
        );

        echo '<button type="submit" name="op" value="invoice" class="button">請求したことを記録</button> ';
        echo '<button type="submit" name="op" value="paid" class="button button-primary" '
            . 'onclick="return confirm(\'入金を確認したものとして、未回収額から差し引きます。よろしいですか？\');">'
            . '入金を確認</button>';
        echo '</form>';
    }

    /** The last movements across everyone, so the screen explains itself. */
    private static function renderHistory(Recorder $ledger): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mk_creator_ledger
           ORDER BY id DESC LIMIT 30"
        ) ?: [];

        if (!$rows) {
            return;
        }

        echo '<h2 style="margin-top:28px">最近の入出金記録</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th style="width:150px">日時</th><th>出品者</th><th style="width:220px">種別</th>'
            . '<th style="width:110px">金額</th><th style="width:110px">残高</th><th>備考</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $user = get_userdata((int) $row->user_id);

            echo '<tr>';
            printf('<td>%s</td>', esc_html(get_date_from_gmt((string) $row->created_at, 'Y-m-d H:i')));
            printf('<td>%s</td>', esc_html($user ? $user->display_name : '#' . $row->user_id));
            printf('<td>%s</td>', esc_html(self::entryLabel((string) $row->entry_type)));
            printf('<td>%s</td>', esc_html(Money::format((int) $row->amount)));
            printf('<td>%s</td>', esc_html(Money::format((int) $row->balance_after)));
            printf(
                '<td>%s%s</td>',
                esc_html((string) ($row->note ?? '')),
                $row->order_id ? sprintf(' <small>(注文 #%d)</small>', (int) $row->order_id) : ''
            );
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $amount = isset($_POST['amount']) ? (int) $_POST['amount'] : 0;
        $note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
        $op     = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

        if ($userId > 0 && $amount > 0) {
            $ledger = new Recorder();

            if ($op === 'invoice') {
                $ledger->invoice($userId, $amount, $note);
            } elseif ($op === 'paid') {
                $ledger->recordPayment($userId, $amount, $note);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&done=1'));
        exit;
    }
}
