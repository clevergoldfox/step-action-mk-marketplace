<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * WooCommerce → メッセージ動画の種類.
 *
 * The operator owns the list of message types, so the list is edited here
 * rather than in code. Renaming, rewording, reordering and retiring a type are
 * all admin changes.
 *
 * Types are retired, never deleted. Every order carries its own copy of the
 * label it was bought under, so deleting could not break history -- but a
 * creator's listing refers to types by key, and a key that silently vanished
 * and was later reused for something different would put the new meaning on
 * old listings. Switching a type off removes it from every listing and every
 * buy form at once, and switching it back on restores it.
 */
final class MessageTypesAdmin
{
    private const SLUG  = 'mk-message-types';
    private const NONCE = 'mk_save_message_types';

    private const LABEL_MAX = 30;
    private const DESC_MAX  = 60;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_save_message_types', [self::class, 'handleSave']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            'メッセージ動画の種類',
            'メッセージ動画の種類',
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

        echo '<div class="wrap"><h1>メッセージ動画の種類</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>';
        }

        echo '<p>クリエイターが「対応できるメッセージ」として選び、購入者が購入時に1つ選ぶ一覧です。'
            . '表示順は上から並びます。</p>';
        echo '<p><strong>種類は削除せず、「受付」のチェックを外して停止してください。</strong>'
            . '停止した種類は、すべての出品と購入画面から一斉に表示されなくなります。'
            . 'すでに購入された注文には、購入時の種類名がそのまま残ります。</p>';

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_save_message_types">';

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
            . '<th style="width:70px">表示順</th><th style="width:220px">種類名</th>'
            . '<th>説明（購入者・クリエイターに表示）</th><th style="width:60px">受付</th>'
            . '</tr></thead><tbody>';

        $index = 0;

        foreach (MessageVideo::allTypes() as $key => $type) {
            self::renderRow($index++, $key, $type['label'], $type['description'], $type['active']);
        }

        // One empty row to add a new type.
        self::renderRow($index, '', '', '', true);

        echo '</tbody></table>';
        echo '<p class="description">新しい種類を追加するには、一番下の空欄に入力して保存してください。</p>';
        echo '<p><button type="submit" class="button button-primary">保存する</button></p>';
        echo '</form></div>';
    }

    private static function renderRow(int $index, string $key, string $label, string $description, bool $active): void
    {
        echo '<tr>';
        printf(
            '<td><input type="number" name="types[%1$d][order]" value="%2$d" class="small-text">'
            . '<input type="hidden" name="types[%1$d][key]" value="%3$s"></td>',
            $index,
            $index + 1,
            esc_attr($key)
        );
        printf(
            '<td><input type="text" name="types[%d][label]" value="%s" maxlength="%d" style="width:100%%"%s></td>',
            $index,
            esc_attr($label),
            self::LABEL_MAX,
            $key === '' ? ' placeholder="新しい種類を追加"' : ''
        );
        printf(
            '<td><input type="text" name="types[%d][description]" value="%s" maxlength="%d" style="width:100%%"></td>',
            $index,
            esc_attr($description),
            self::DESC_MAX
        );
        printf(
            '<td><input type="checkbox" name="types[%d][active]" value="1"%s></td>',
            $index,
            checked($active, true, false)
        );
        echo '</tr>';
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $posted = isset($_POST['types']) && is_array($_POST['types']) ? wp_unslash($_POST['types']) : [];

        update_option(MessageVideo::OPTION_TYPES, self::sanitise($posted));

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }

    /**
     * The posted table, cleaned into the stored shape.
     *
     * Public so the rules can be tested without a request.
     *
     * @param array<int|string, mixed> $posted
     * @return array<int, array{key:string, label:string, description:string, active:bool}>
     */
    public static function sanitise(array $posted): array
    {
        $existing = MessageVideo::allTypes();
        $rows     = [];

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = mb_substr(trim(sanitize_text_field((string) ($row['label'] ?? ''))), 0, self::LABEL_MAX);
            $key   = sanitize_key((string) ($row['key'] ?? ''));

            // An existing type keeps its row even with the label cleared, so
            // a slip of the keyboard cannot erase a type that listings use.
            if ($label === '') {
                if ($key === '' || !isset($existing[$key])) {
                    continue;
                }

                $label = $existing[$key]['label'];
            }

            // A new row gets a fresh key. Never derived from the label: two
            // types could share a label, and a renamed type must keep its key.
            if ($key === '' || !isset($existing[$key])) {
                $key = self::freshKey($existing, $rows);
            }

            $rows[] = [
                'key'         => $key,
                'label'       => $label,
                'description' => mb_substr(trim(sanitize_text_field((string) ($row['description'] ?? ''))), 0, self::DESC_MAX),
                'active'      => !empty($row['active']),
                'order'       => (int) ($row['order'] ?? 0),
            ];
        }

        // Types missing from the post entirely are kept, switched off: removing
        // a row from the form must not delete a type (see the class docblock).
        $seen = array_column($rows, 'key');

        foreach ($existing as $key => $type) {
            if (!in_array($key, $seen, true)) {
                $rows[] = ['key' => $key, 'label' => $type['label'], 'description' => $type['description'], 'active' => false, 'order' => PHP_INT_MAX];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static function (array $row): array {
            unset($row['order']);

            return $row;
        }, $rows);
    }

    /**
     * @param array<string, mixed>     $existing
     * @param array<int, array<string, mixed>> $rows
     */
    private static function freshKey(array $existing, array $rows): string
    {
        $taken = array_merge(array_keys($existing), array_column($rows, 'key'));
        $n     = 1;

        while (in_array('custom_' . $n, $taken, true)) {
            $n++;
        }

        return 'custom_' . $n;
    }
}
