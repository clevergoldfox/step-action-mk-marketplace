<?php
declare(strict_types=1);

namespace MK\Ad;

use WP_Post;

/**
 * 広告 in wp-admin: a picture, a link, a publish button.
 *
 * Everything here is WordPress's own furniture -- the media library for the
 * image, the publish box for going live and coming down, 並び順 for the order
 * -- because the operator already knows how those work, and because none of
 * it is code that can develop a bug of its own. What is added is one small
 * panel: the link, who it is for, where it goes and, if a campaign is sold by
 * the week, when it runs.
 */
final class Admin
{
    private const NONCE = 'mk_save_ad';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerType']);
        add_action('add_meta_boxes', [self::class, 'addBox']);
        add_action('save_post_' . Service::POST_TYPE, [self::class, 'save'], 10, 2);
        add_filter('manage_' . Service::POST_TYPE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . Service::POST_TYPE . '_posts_custom_column', [self::class, 'column'], 10, 2);
        add_action('admin_notices', [self::class, 'noticeWithoutImage']);
    }

    public static function registerType(): void
    {
        register_post_type(Service::POST_TYPE, [
            'labels' => [
                'name'          => '広告',
                'singular_name' => '広告',
                'add_new'       => '新しい広告',
                'add_new_item'  => '広告を追加',
                'edit_item'     => '広告を編集',
                'search_items'  => '広告を検索',
                'not_found'     => '広告はまだありません',
                'all_items'     => '広告一覧',
                'menu_name'     => '広告',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 26,
            'menu_icon'           => 'dashicons-megaphone',
            'capability_type'     => 'post',
            'capabilities'        => ['create_posts' => 'manage_woocommerce'],
            'map_meta_cap'        => true,
            'supports'            => ['title', 'thumbnail', 'page-attributes'],
            'exclude_from_search' => true,
            'has_archive'         => false,
            'rewrite'             => false,
        ]);
    }

    public static function addBox(): void
    {
        add_meta_box(
            'mk-ad-settings',
            '広告の設定',
            [self::class, 'renderBox'],
            Service::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function renderBox(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE, 'mk_ad_nonce');

        $url       = (string) get_post_meta($post->ID, Service::META_URL, true);
        $audience  = (string) get_post_meta($post->ID, Service::META_AUDIENCE, true);
        $placement = (string) get_post_meta($post->ID, Service::META_PLACEMENT, true);
        $starts    = (string) get_post_meta($post->ID, Service::META_STARTS, true);
        $ends      = (string) get_post_meta($post->ID, Service::META_ENDS, true);

        $audience  = $audience !== '' ? $audience : Service::AUDIENCE_ALL;
        $placement = $placement !== '' ? $placement : Service::PLACEMENT_BANNER;

        echo '<style>.mk-ad-field{margin:0 0 20px}.mk-ad-field label.mk-ad-label{display:block;'
            . 'font-weight:600;margin:0 0 6px}.mk-ad-field .description{margin:6px 0 0}</style>';

        printf(
            '<div class="mk-ad-field"><label class="mk-ad-label" for="mk_ad_url">リンク先URL</label>'
            . '<input type="url" id="mk_ad_url" name="mk_ad_url" value="%s" class="large-text" '
            . 'placeholder="https://example.com/campaign">'
            . '<p class="description">バナーをタップしたときに開くページです。空欄の場合、画像は表示されますがリンクはしません。</p></div>',
            esc_attr($url)
        );

        echo '<div class="mk-ad-field"><span class="mk-ad-label">表示する場所</span>';

        foreach (Service::placements() as $key => $label) {
            printf(
                '<label style="margin-right:18px"><input type="radio" name="mk_ad_placement" value="%s"%s> %s</label>',
                esc_attr($key),
                checked($placement, $key, false),
                esc_html($label)
            );
        }

        echo '<p class="description">ポップアップは、サイトを開いた方に1日1回だけ表示されます。</p></div>';

        echo '<div class="mk-ad-field"><span class="mk-ad-label">表示する相手</span>';

        foreach (Service::audiences() as $key => $label) {
            printf(
                '<label style="margin-right:18px"><input type="radio" name="mk_ad_audience" value="%s"%s> %s</label>',
                esc_attr($key),
                checked($audience, $key, false),
                esc_html($label)
            );
        }

        echo '<p class="description">ホーム画面で「メンズ」「レディース」を選んだ方に出し分けられます。'
            . '「全員に表示」は、どちらを選んでいる方にも表示されます。</p></div>';

        printf(
            '<div class="mk-ad-field"><span class="mk-ad-label">掲載期間（任意）</span>'
            . '<input type="date" name="mk_ad_starts" value="%s"> 〜 '
            . '<input type="date" name="mk_ad_ends" value="%s">'
            . '<p class="description">空欄なら、公開してから下書きに戻すまで掲載され続けます。'
            . '終了日を指定した場合、その日の終わりまで表示されます。</p></div>',
            esc_attr($starts),
            esc_attr($ends)
        );

        echo '<div class="mk-ad-field"><span class="mk-ad-label">画像</span>'
            . '<p class="description">右側の「アイキャッチ画像」から設定してください。'
            . '横長（横1200×縦400ピクセル程度）がおすすめです。'
            . '<strong>画像がないと、この広告は表示されません。</strong></p></div>';

        echo '<div class="mk-ad-field"><span class="mk-ad-label">並び順</span>'
            . '<p class="description">右側の「ページ属性」の「順序」に小さい数字を入れるほど先に表示されます。</p></div>';
    }

    /**
     * @param mixed $postId
     * @param mixed $post
     */
    public static function save($postId, $post = null): void
    {
        $postId = (int) $postId;

        if (!isset($_POST['mk_ad_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['mk_ad_nonce'])), self::NONCE)
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $url = isset($_POST['mk_ad_url']) ? esc_url_raw(wp_unslash((string) $_POST['mk_ad_url'])) : '';
        update_post_meta($postId, Service::META_URL, $url);

        $placement = isset($_POST['mk_ad_placement'])
            ? sanitize_key(wp_unslash((string) $_POST['mk_ad_placement']))
            : Service::PLACEMENT_BANNER;
        update_post_meta(
            $postId,
            Service::META_PLACEMENT,
            isset(Service::placements()[$placement]) ? $placement : Service::PLACEMENT_BANNER
        );

        $audience = isset($_POST['mk_ad_audience'])
            ? sanitize_key(wp_unslash((string) $_POST['mk_ad_audience']))
            : Service::AUDIENCE_ALL;
        update_post_meta($postId, Service::META_AUDIENCE, Service::normaliseAudience($audience));

        foreach ([Service::META_STARTS => 'mk_ad_starts', Service::META_ENDS => 'mk_ad_ends'] as $meta => $field) {
            $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string) $_POST[$field])) : '';
            $value = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
            update_post_meta($postId, $meta, $value);
        }
    }

    /**
     * @param mixed $columns
     * @return mixed
     */
    public static function columns($columns)
    {
        if (!is_array($columns)) {
            return $columns;
        }

        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'title') {
                $out['mk_ad_image']     = '画像';
                $out['mk_ad_placement'] = '表示場所';
                $out['mk_ad_audience']  = '表示する相手';
                $out['mk_ad_period']    = '掲載期間';
            }
        }

        return $out;
    }

    /**
     * @param mixed $column
     * @param mixed $postId
     */
    public static function column($column, $postId): void
    {
        $postId = (int) $postId;

        switch ($column) {
            case 'mk_ad_image':
                $thumb = get_the_post_thumbnail($postId, [80, 30]);
                echo $thumb !== '' ? $thumb : '<span style="color:#b32d2e">未設定</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
                break;

            case 'mk_ad_placement':
                $key = (string) get_post_meta($postId, Service::META_PLACEMENT, true);
                echo esc_html(Service::placements()[$key] ?? Service::placements()[Service::PLACEMENT_BANNER]);
                break;

            case 'mk_ad_audience':
                echo esc_html(Service::audienceLabel((string) get_post_meta($postId, Service::META_AUDIENCE, true)));
                break;

            case 'mk_ad_period':
                $starts = (string) get_post_meta($postId, Service::META_STARTS, true);
                $ends   = (string) get_post_meta($postId, Service::META_ENDS, true);

                if ($starts === '' && $ends === '') {
                    echo '—';
                    break;
                }

                printf(
                    '%s 〜 %s%s',
                    esc_html($starts !== '' ? $starts : '（指定なし）'),
                    esc_html($ends !== '' ? $ends : '（指定なし）'),
                    Service::isRunning($postId) ? '' : ' <strong style="color:#b32d2e">期間外</strong>'
                );
                break;
        }
    }

    /** An ad with no picture is published and invisible; say so. */
    public static function noticeWithoutImage(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== Service::POST_TYPE || $screen->base !== 'edit') {
            return;
        }

        $missing = get_posts([
            'post_type'      => Service::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_thumbnail_id',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        if (!$missing) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>画像が設定されていない公開中の広告が %d 件あります。'
            . '画像のない広告はサイトに表示されません。</p></div>',
            count($missing)
        );
    }
}
