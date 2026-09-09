<?php
declare(strict_types=1);

namespace MK\Option;

/**
 * Where the operator defines which options exist marketplace-wide.
 *
 * Kept to the platform rather than left to creators so that "ラッピング" means
 * the same thing on every listing and can be filtered on. Creators set the
 * price; they do not invent the vocabulary.
 */
final class Admin
{
    private const SLUG  = 'mk-options';
    private const NONCE = 'mk_option_groups';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_save_option_group', [self::class, 'handleSave']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            'オプション設定',
            'オプション設定',
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

        $service = new Service();
        $groups  = $service->allGroups();
        $rate    = (float) get_option('mk_option_fee_rate', 0.40);

        echo '<div class="wrap"><h1>オプション設定</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>';
        }

        printf(
            '<p>出品者が商品ごとに提供できるオプションを設定します。'
            . '<strong>価格は出品者が商品ごとに設定します</strong>ので、ここでは名称のみを管理します。<br>'
            . 'オプション売上に対する運営手数料は現在 <strong>%s%%</strong> です'
            . '（商品本体とは別の料率です）。</p>',
            esc_html((string) round($rate * 100, 1))
        );

        echo '<h2>登録済みのオプション</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th style="width:60px">ID</th><th>名称</th>'
            . '<th style="width:100px">並び順</th><th style="width:100px">状態</th>'
            . '<th style="width:220px">操作</th></tr></thead><tbody>';

        if (!$groups) {
            echo '<tr><td colspan="5">まだ登録がありません。下のフォームから追加してください。</td></tr>';
        }

        foreach ($groups as $g) {
            printf('<tr><form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
            wp_nonce_field(self::NONCE);
            echo '<input type="hidden" name="action" value="mk_save_option_group">';
            printf('<input type="hidden" name="group_id" value="%d">', (int) $g->id);

            printf('<td>%d</td>', (int) $g->id);
            printf('<td><input type="text" name="name" value="%s" style="width:100%%" required></td>',
                esc_attr((string) $g->name));
            printf('<td><input type="number" name="sort_order" value="%d" style="width:80px"></td>',
                (int) $g->sort_order);
            printf('<td><label><input type="checkbox" name="is_active" value="1"%s> 有効</label></td>',
                $g->is_active ? ' checked' : '');
            echo '<td><button type="submit" class="button button-primary">保存</button></td>';
            echo '</form></tr>';
        }

        echo '</tbody></table>';

        echo '<h2>新しいオプションを追加</h2>';
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_save_option_group">';
        echo '<table class="form-table"><tr>'
            . '<th><label for="mk_new_name">名称</label></th>'
            . '<td><input type="text" id="mk_new_name" name="name" class="regular-text" '
            . 'placeholder="例：ギフトラッピング" required></td></tr>'
            . '<tr><th><label for="mk_new_sort">並び順</label></th>'
            . '<td><input type="number" id="mk_new_sort" name="sort_order" value="0" style="width:80px">'
            . '<p class="description">小さい数字ほど先に表示されます。</p></td></tr></table>';
        echo '<p><button type="submit" class="button button-primary">追加する</button></p>';
        echo '</form>';

        echo '<p><small>オプションを削除する機能は設けておりません。'
            . '過去の注文が名称を参照しているため、削除すると「何を購入したか」が'
            . '分からない注文が生じます。提供を終える場合は「有効」のチェックを外してください。'
            . '既存の注文はそのまま残り、新規の出品では選べなくなります。</small></p>';

        echo '</div>';
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $service = new Service();
        $name    = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $sort    = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $id      = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        try {
            if ($id > 0) {
                $service->updateGroup($id, $name, $sort, !empty($_POST['is_active']));
            } else {
                $service->createGroup($name, $sort);
            }
        } catch (\Throwable $e) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }
}
