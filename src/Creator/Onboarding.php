<?php
declare(strict_types=1);

namespace MK\Creator;

use MK\Stripe\AccountService;
use Throwable;
use WP_User;

/**
 * The creator's route from "registered" to "able to be paid".
 *
 * Three things have to happen before a creator can sell, and they are
 * deliberately separate:
 *
 *   1. a creator number is allocated       (on registration, automatic)
 *   2. a Stripe connected account is made  (on first click, idempotent)
 *   3. Stripe finishes verifying them      (their own bank/ID details)
 *
 * Only step 3 gates selling, and only Stripe can decide when it is done --
 * which is why AccountService::canSell() reads a status synced from Stripe
 * rather than a flag we set ourselves. A creator who cannot receive transfers
 * must not be able to take money, or the platform ends up holding funds with
 * nowhere lawful to send them.
 *
 * The Stripe round-trip uses query arguments on the home URL rather than
 * pretty paths. Account Links are generated server-side and only ever seen by
 * the browser mid-redirect, so there is nothing to gain from a tidy URL and
 * something real to lose: a rewrite rule that was not flushed would land the
 * creator on a 404 at the exact moment they finish onboarding.
 */
final class Onboarding
{
    /** Dokan dashboard sub-page slug. */
    public const PAGE = 'mk-payouts';

    /** Query argument carrying the Stripe round-trip and button actions. */
    public const ACTION_ARG = 'mk_onboarding';

    public const USER_META_NUMBER = 'mk_creator_number';

    /** Records the plugin version whose rewrite rules are currently flushed. */
    private const OPTION_REWRITES = 'mk_rewrites_flushed_for';

    public static function register(): void
    {
        add_action('dokan_new_seller_created', [self::class, 'onSellerCreated'], 10, 1);

        add_filter('dokan_query_var_filter', [self::class, 'addQueryVar']);
        add_filter('dokan_get_dashboard_nav', [self::class, 'addNavItem']);
        add_action('dokan_load_custom_template', [self::class, 'renderPage']);

        add_action('template_redirect', [self::class, 'handleAction']);

        // Late, so Dokan has already registered its own rules by now.
        add_action('init', [self::class, 'maybeFlushRewrites'], 99);
    }

    /**
     * Make sure /dashboard/mk-payouts/ actually resolves.
     *
     * Adding a Dokan query var contributes a rewrite rule, and a rule that has
     * not been flushed into the stored rewrite_rules option does not exist as
     * far as WordPress is concerned -- the page 404s. Nothing reports this:
     * the nav item renders, the URL looks right, and clicking it fails.
     *
     * Flushing on activation alone is not enough. It leaves the rule missing
     * if Dokan is activated after us, or if the option is later rebuilt
     * without our filter having run. So this checks the rules themselves
     * rather than trusting a one-time flag, and repairs them whenever the
     * page is genuinely absent. Flushing is expensive, hence the version
     * stamp: the common case is one cheap option read and no work.
     */
    public static function maybeFlushRewrites(): void
    {
        // Plain permalinks have no rules to flush, and checking would loop.
        if ((string) get_option('permalink_structure') === '') {
            return;
        }

        $rules   = (array) get_option('rewrite_rules');
        $present = false;

        foreach ($rules as $target) {
            if (is_string($target) && str_contains($target, self::PAGE)) {
                $present = true;
                break;
            }
        }

        if ($present && get_option(self::OPTION_REWRITES) === MK_VERSION) {
            return;
        }

        // Soft flush: regenerating .htaccess needs write access this host does
        // not reliably grant, and the rules live in the database regardless.
        flush_rewrite_rules(false);
        update_option(self::OPTION_REWRITES, MK_VERSION);
    }

    // --------------------------------------------------------- registration

    /**
     * Give every new creator a number.
     *
     * Failure must not take the registration down with it. A creator who is
     * registered but unnumbered is a small, fixable problem; a registration
     * form that fatals because the sequence row misbehaved is a creator who
     * never signs up at all. The number is backfilled on their next visit to
     * the payouts page.
     */
    public static function onSellerCreated(int $userId): void
    {
        self::ensureNumber($userId);
    }

    /** Idempotent: an existing number is never reissued. */
    public static function ensureNumber(int $userId): string
    {
        $existing = (string) get_user_meta($userId, self::USER_META_NUMBER, true);

        if ($existing !== '') {
            return $existing;
        }

        try {
            $number = (new Numbering())->allocate();
        } catch (Throwable $e) {
            error_log(sprintf(
                '[mk-marketplace] could not allocate a creator number for user %d: %s',
                $userId,
                $e->getMessage()
            ));

            return '';
        }

        update_user_meta($userId, self::USER_META_NUMBER, $number);

        return $number;
    }

    // ------------------------------------------------------------- dashboard

    /**
     * @param array<string,string> $vars
     * @return array<string,string>
     */
    public static function addQueryVar(array $vars): array
    {
        $vars[self::PAGE] = self::PAGE;

        return $vars;
    }

    /**
     * @param array<string,mixed> $nav
     * @return array<string,mixed>
     */
    public static function addNavItem(array $nav): array
    {
        $nav[self::PAGE] = [
            'title' => '売上受取設定',
            'icon'  => '<i class="fas fa-yen-sign"></i>',
            'url'   => dokan_get_navigation_url(self::PAGE),
            'pos'   => 55,
        ];

        return $nav;
    }

    /** @param array<string,mixed> $queryVars */
    public static function renderPage(array $queryVars): void
    {
        if (!isset($queryVars[self::PAGE])) {
            return;
        }

        $userId = get_current_user_id();

        if ($userId === 0 || !current_user_can('dokan_view_store')) {
            echo '<div class="dokan-error">この画面を表示する権限がありません。</div>';

            return;
        }

        $accounts = new AccountService();
        $status   = (string) get_user_meta($userId, AccountService::META_STATUS, true);

        self::renderMarkup(
            self::ensureNumber($userId),
            $status !== '' ? $status : AccountService::STATUS_NOT_STARTED,
            $accounts->canSell($userId),
            isset($_GET['mk_error'])
        );
    }

    private static function renderMarkup(
        string $number,
        string $status,
        bool $canSell,
        bool $showError
    ): void {
        [$label, $tone, $help] = match ($status) {
            AccountService::STATUS_COMPLETED => [
                '設定完了',
                'success',
                '売上の受け取り設定は完了しています。商品を出品できます。',
            ],
            AccountService::STATUS_IN_PROGRESS => [
                '確認中 / 未完了',
                'warning',
                'Stripe での本人確認が完了していません。「設定を続ける」から残りの情報をご入力ください。',
            ],
            AccountService::STATUS_RESTRICTED => [
                '追加情報が必要',
                'danger',
                'Stripe から追加の情報を求められています。対応が完了するまで売上は送金されません。',
            ],
            default => [
                '未設定',
                'danger',
                '売上を受け取るには、Stripe での口座設定が必要です。',
            ],
        };

        $startUrl = wp_nonce_url(
            add_query_arg(self::ACTION_ARG, 'start', home_url('/')),
            'mk_onboarding_start'
        );

        $button = match ($status) {
            AccountService::STATUS_COMPLETED   => '受取口座の情報を更新する',
            AccountService::STATUS_NOT_STARTED => '受取口座を設定する',
            default                            => '設定を続ける',
        };

        echo '<div class="dokan-dashboard-content mk-payouts"><article>';
        echo '<header class="dokan-dashboard-header"><h1 class="entry-title">売上受取設定</h1></header>';

        if ($showError) {
            echo '<div class="dokan-alert dokan-alert-danger">'
                . '設定画面へ接続できませんでした。しばらくしてからもう一度お試しください。'
                . '</div>';
        }

        echo '<div class="dokan-panel dokan-panel-default"><div class="dokan-panel-body">';

        printf(
            '<p><strong>クリエイター番号：</strong> <code>%s</code></p>',
            esc_html($number !== '' ? $number : '発行中')
        );

        printf(
            '<p><strong>受取設定の状態：</strong> <span class="dokan-label dokan-label-%s">%s</span></p>',
            esc_attr($tone),
            esc_html($label)
        );

        printf('<p>%s</p>', esc_html($help));

        if (!$canSell) {
            echo '<div class="dokan-alert dokan-alert-warning">'
                . '受取設定が完了するまで、商品は公開されません。'
                . '</div>';
        }

        printf(
            '<p><a href="%s" class="dokan-btn dokan-btn-theme">%s</a></p>',
            esc_url($startUrl),
            esc_html($button)
        );

        echo '<p class="description">口座情報・本人確認書類は Stripe が直接お預かりします。'
            . '当サイトでは保持いたしません。</p>';

        echo '</div></div></article></div>';
    }

    // ------------------------------------------------------- stripe handoff

    /**
     * Handle the three query-argument actions: start, return, refresh.
     *
     * Stripe sends the creator back to return_url whether or not they actually
     * finished, and to refresh_url when the single-use Account Link expired
     * before they got there. Neither is evidence of anything, so the return
     * path re-reads the account from Stripe rather than assuming an outcome.
     */
    public static function handleAction(): void
    {
        $action = isset($_GET[self::ACTION_ARG])
            ? sanitize_key(wp_unslash($_GET[self::ACTION_ARG]))
            : '';

        if ($action === '') {
            return;
        }

        $userId = get_current_user_id();

        if ($userId === 0) {
            return;
        }

        $accounts  = new AccountService();
        $dashboard = dokan_get_navigation_url(self::PAGE);

        try {
            switch ($action) {
                case 'start':
                    // Only the nonce-carrying button starts onboarding. The
                    // refresh case has no nonce because Stripe originates it.
                    check_admin_referer('mk_onboarding_start');
                    self::redirectToStripe($accounts, $userId);
                    // no break: redirectToStripe exits

                case 'refresh':
                    self::redirectToStripe($accounts, $userId);
                    // no break: redirectToStripe exits

                case 'return':
                    $accountId = (string) get_user_meta(
                        $userId,
                        AccountService::META_ACCOUNT_ID,
                        true
                    );

                    if ($accountId !== '') {
                        $accounts->syncStatus($accountId);
                    }

                    wp_safe_redirect($dashboard);
                    exit;
            }
        } catch (Throwable $e) {
            error_log(sprintf(
                '[mk-marketplace] onboarding action "%s" failed for user %d: %s',
                $action,
                $userId,
                $e->getMessage()
            ));

            wp_safe_redirect(add_query_arg('mk_error', '1', $dashboard));
            exit;
        }
    }

    private static function redirectToStripe(AccountService $accounts, int $userId): void
    {
        self::ensureNumber($userId);

        $user = get_userdata($userId);

        if (!$user instanceof WP_User) {
            return;
        }

        // Not wp_safe_redirect: this deliberately leaves the site for Stripe,
        // and wp_safe_redirect would refuse an external host.
        wp_redirect($accounts->onboardingLink($accounts->ensureAccount($user)));
        exit;
    }
}
