<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * Which page is which document.
 *
 * The two terms pages are created as placeholders so the sign-up links work
 * from day one, but the real text is being drafted by the client's lawyer
 * and may well arrive as pages they have written themselves. This screen
 * points each document at whichever page holds it, so swapping in the final
 * wording is a dropdown rather than a developer.
 *
 * It also shows when each document was last edited, because that date is
 * what an account's recorded agreement is pinned to.
 */
final class TermsAdmin
{
    private const SLUG  = 'mk-terms';
    private const NONCE = 'mk_save_terms';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_mk_save_terms', [self::class, 'handleSave']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            '規約ページ設定',
            '規約ページ設定',
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

        echo '<div class="wrap"><h1>規約ページ設定</h1>';

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>';
        }

        echo '<p>会員登録画面・出品者登録画面の「利用規約およびプライバシーポリシーに同意する」のリンク先になるページです。'
            . '購入者・出品者とも同じ利用規約に同意します。</p>';

        echo '<div class="notice notice-info inline"><p>'
            . '同意した記録（日時・購入者／出品者のどちらとして同意したか・その時点の規約の最終更新日）は、会員ごとに保存されます。'
            . '規約を改定された場合、<strong>改定前に登録された方の記録は改定前のもの</strong>として残ります。</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="mk_save_terms">';
        echo '<table class="form-table"><tbody>';

        foreach (Terms::documents() as $role => $document) {
            $current = (int) get_option($document['option']);

            printf('<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr($document['option']), esc_html($document['title']));

            wp_dropdown_pages([
                'name'              => esc_attr($document['option']),
                'id'                => esc_attr($document['option']),
                'selected'          => $current,
                'show_option_none'  => '— 未設定 —',
                'option_none_value' => '0',
            ]);

            if ($current > 0 && get_post_status($current) === 'publish') {
                printf(
                    ' <a href="%s" target="_blank" rel="noopener">表示</a> / <a href="%s">編集</a>'
                    . '<p class="description">最終更新：%s</p>',
                    esc_url((string) get_permalink($current)),
                    esc_url((string) get_edit_post_link($current)),
                    esc_html(get_post_modified_time('Y/m/d H:i', false, $current) ?: '—')
                );
            } else {
                echo '<p class="description">ページが未設定です。設定するまで、会員登録画面のリンクは表示されません。</p>';
            }

            echo '</td></tr>';
        }

        $privacy = (int) get_option('wp_page_for_privacy_policy');

        printf(
            '<tr><th scope="row">プライバシーポリシー</th><td>%s<p class="description">'
            . 'WordPress の「設定 → プライバシー」で指定されているページです。</p></td></tr>',
            $privacy > 0
                ? sprintf(
                    '<strong>%s</strong> <a href="%s" target="_blank" rel="noopener">表示</a>',
                    esc_html(get_the_title($privacy)),
                    esc_url((string) get_permalink($privacy))
                )
                : '<em>未設定</em>'
        );

        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">保存する</button></p>';
        echo '</form></div>';
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        foreach (Terms::documents() as $document) {
            $pageId = isset($_POST[$document['option']]) ? (int) $_POST[$document['option']] : 0;

            // Only a real, published page: pointing an "I agree" link at a
            // draft or a deleted page is worse than leaving it unset.
            if ($pageId > 0 && get_post_status($pageId) === 'publish') {
                update_option($document['option'], $pageId);
            } elseif ($pageId === 0) {
                update_option($document['option'], 0);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }
}
