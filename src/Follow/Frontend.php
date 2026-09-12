<?php
declare(strict_types=1);

namespace MK\Follow;

/**
 * The follow button on a creator's store, and the buyer's following list.
 *
 * The button is a form POST rather than AJAX. It is one action on a page the
 * visitor is already looking at, a reload costs nothing, and it works without
 * JavaScript — which matters more than the flicker, since a marketplace's
 * traffic is mostly phones on patchy connections.
 */
final class Frontend
{
    private const NONCE    = 'mk_follow';
    private const ENDPOINT = 'mk-following';

    public static function register(): void
    {
        add_action('dokan_store_header_info_fields', [self::class, 'renderButton']);
        add_action('template_redirect', [self::class, 'handleSubmit']);

        // "Following" as a tab in the buyer's account area.
        add_action('init', [self::class, 'addEndpoint']);
        add_filter('woocommerce_account_menu_items', [self::class, 'addMenuItem']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'renderList']);
        add_filter('woocommerce_get_query_vars', [self::class, 'addQueryVar']);

        // A deleted account must not linger in anyone's counts or lists.
        add_action('delete_user', [Service::class, 'purgeUser']);
    }

    public static function addEndpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_PAGES);

        // Same self-healing rule as the payouts page: an endpoint whose
        // rewrite rule was never flushed is a menu item leading to a 404.
        if (get_option('mk_follow_endpoint_flushed') !== MK_VERSION) {
            flush_rewrite_rules(false);
            update_option('mk_follow_endpoint_flushed', MK_VERSION);
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
            // Before logout, which belongs last.
            if ($key === 'customer-logout') {
                $new[self::ENDPOINT] = 'フォロー中';
            }

            $new[$key] = $label;
        }

        if (!isset($new[self::ENDPOINT])) {
            $new[self::ENDPOINT] = 'フォロー中';
        }

        return $new;
    }

    public static function renderButton(int $creatorId): void
    {
        $service = new Service();
        $userId  = get_current_user_id();

        printf(
            '<div class="mk-follow"><span class="mk-follower-count">フォロワー %d 人</span> ',
            $service->followerCount($creatorId)
        );

        if ($userId === 0) {
            printf(
                '<a href="%s" class="dokan-btn dokan-btn-sm dokan-btn-theme">ログインしてフォロー</a>',
                esc_url(wp_login_url(dokan_get_store_url($creatorId)))
            );
            echo '</div>';

            return;
        }

        if ($userId === $creatorId) {
            echo '</div>';   // your own store: a count, no button

            return;
        }

        $following = $service->isFollowing($userId, $creatorId);

        $action = wp_nonce_url(
            add_query_arg(
                ['mk_follow' => $creatorId, 'mk_follow_do' => $following ? 'unfollow' : 'follow'],
                dokan_get_store_url($creatorId)
            ),
            self::NONCE
        );

        printf(
            '<form method="post" action="%s" style="display:inline">'
            . '<button type="submit" class="dokan-btn dokan-btn-sm %s">%s</button></form>',
            esc_url($action),
            $following ? 'dokan-btn-default' : 'dokan-btn-theme',
            $following ? 'フォロー中' : 'フォローする'
        );

        echo '</div>';
    }

    public static function handleSubmit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['mk_follow'])) {
            return;
        }

        check_admin_referer(self::NONCE);

        $userId    = get_current_user_id();
        $creatorId = (int) $_GET['mk_follow'];

        if ($userId === 0) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $do      = isset($_GET['mk_follow_do']) ? sanitize_key(wp_unslash($_GET['mk_follow_do'])) : 'follow';
        $service = new Service();

        if ($do === 'unfollow') {
            $service->unfollow($userId, $creatorId);
        } else {
            $service->follow($userId, $creatorId);
        }

        wp_safe_redirect(dokan_get_store_url($creatorId));
        exit;
    }

    public static function renderList(): void
    {
        $service = new Service();
        $ids     = $service->following(get_current_user_id());

        if (!$ids) {
            echo '<p>まだどのクリエイターもフォローしていません。</p>';
            echo '<p><a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="button">'
                . '商品を見る</a></p>';

            return;
        }

        echo '<ul class="mk-following-list" style="list-style:none;padding:0">';

        foreach ($ids as $creatorId) {
            $user = get_userdata($creatorId);

            if (!$user) {
                continue;   // purge_user handles this, but never trust it blindly
            }

            $number = (string) get_user_meta($creatorId, 'mk_creator_number', true);

            printf(
                '<li style="padding:12px 0;border-bottom:1px solid #eee">'
                . '<a href="%s"><strong>%s</strong></a>%s<br>'
                . '<small>フォロワー %d 人</small></li>',
                esc_url(dokan_get_store_url($creatorId)),
                esc_html($user->display_name),
                $number !== '' ? ' <code>' . esc_html($number) . '</code>' : '',
                $service->followerCount($creatorId)
            );
        }

        echo '</ul>';
    }
}
