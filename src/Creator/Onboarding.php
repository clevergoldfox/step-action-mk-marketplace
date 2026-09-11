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

        /*
         * Dokan's own dashboard figures are permanently zero here, so they are
         * removed and replaced rather than left to be misread.
         *
         * Its sales and order widgets read wp_dokan_orders and
         * wp_dokan_vendor_balance, filled by the commission engine this
         * project switches off — it splits money at purchase, which is the
         * model that makes escrow impossible. Both tables are empty, so a
         * creator who has sold five things is shown 売上 ¥0 and 注文 0件.
         *
         * The client asked for the ¥0 to be hidden. Hiding it alone would
         * leave a dashboard that says nothing, so the space is filled with
         * the real numbers from our own records.
         *
         * The product-count widget stays: it reads WordPress post counts,
         * which are true.
         */
        add_filter('dokan_dashboard_widget_applicable', [self::class, 'hideEmptyWidgets'], 10, 2);
        add_action('dokan_dashboard_left_widgets', [self::class, 'renderDashboardSummary'], 5);
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

        /*
         * Skip any number that is already held.
         *
         * Allocation is atomic, so two registrations can never be handed the
         * same number. What that does NOT protect against is the counter
         * falling behind numbers that were already issued -- and it did.
         * Test scripts allocated a number to a throwaway user, crashed before
         * deleting that user, and then rolled the counter back. The next real
         * allocation reissued A00001, and three accounts ended up holding it.
         * Searching A00001 then led buyers to whichever one the database
         * happened to return first, which was not the creator they wanted.
         *
         * Checking for an existing holder makes the counter's position
         * irrelevant: however it got behind, allocation walks forward past
         * every number in use. Numbers are never reused, even after the
         * holder is deleted, because a number that has been printed on a flyer
         * or shared with a friend must never quietly start pointing at
         * somebody else.
         */
        for ($attempt = 0; $attempt < 50; $attempt++) {
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

            if (!self::isNumberTaken($number)) {
                update_user_meta($userId, self::USER_META_NUMBER, $number);

                return $number;
            }

            error_log(sprintf(
                '[mk-marketplace] creator number %s is already held; the sequence counter '
                . 'is behind issued numbers, skipping forward',
                $number
            ));
        }

        error_log(sprintf('[mk-marketplace] gave up allocating a number for user %d', $userId));

        return '';
    }

    /** Is any account already holding this number? */
    public static function isNumberTaken(string $number): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->usermeta}
                  WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::USER_META_NUMBER,
                $number
            )
        );
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
            'title' => '売上・受取設定',
            'icon'  => '<i class="fas fa-yen-sign"></i>',
            'url'   => dokan_get_navigation_url(self::PAGE),
            'pos'   => 55,
        ];

        // Dokan's withdrawal system is not used: Stripe pays creators
        // directly on its own schedule, and there is nothing here to request.
        // Leaving the menu in place offers a form that can never do anything.
        unset($nav['withdraw']);

        return $nav;
    }

    /** @param array<string,mixed> $queryVars */
    public static function renderPage(array $queryVars): void
    {
        if (!isset($queryVars[self::PAGE])) {
            return;
        }

        $userId = get_current_user_id();

        // dokan_is_user_seller(), not a capability check. Dokan Lite's seller
        // role has 77 capabilities and 'dokan_view_store' is not among them --
        // gating on it locked every creator out of their own payout settings
        // while the menu item sat there in the sidebar. Vendor identity is the
        // actual question being asked, so ask it directly. PublishGate uses
        // the same test.
        if ($userId === 0
            || !function_exists('dokan_is_user_seller')
            || !dokan_is_user_seller($userId)
        ) {
            echo '<div class="dokan-error">この画面を表示する権限がありません。</div>';

            return;
        }

        $accounts = new AccountService();
        $status   = (string) get_user_meta($userId, AccountService::META_STATUS, true);

        self::renderMarkup(
            self::ensureNumber($userId),
            $status !== '' ? $status : AccountService::STATUS_NOT_STARTED,
            $accounts->canSell($userId),
            isset($_GET['mk_error']),
            get_user_meta($userId, AccountService::META_PAYOUTS_ENABLED, true) === 'no'
        );
    }

    private static function renderMarkup(
        string $number,
        string $status,
        bool $canSell,
        bool $showError,
        bool $payoutsHeld = false
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

        // No dokan-dashboard-content wrapper here. Dokan's dashboard template
        // already opens one before dokan_load_custom_template fires, and
        // nesting a second copy inherits its absolute positioning twice --
        // which shifted the whole panel left, under the sidebar, clipping the
        // first few characters of every line.
        echo '<article class="mk-payouts">';
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

        // Selling is allowed -- we can transfer to them -- but Stripe is not
        // paying their balance on to their bank. Say so rather than let money
        // quietly accumulate somewhere they cannot reach.
        if ($payoutsHeld && $canSell) {
            echo '<div class="dokan-alert dokan-alert-warning">'
                . '売上のお受け取り自体は可能ですが、Stripe からご登録口座への'
                . '入金が一時的に保留されています。出品・販売には影響ありません。'
                . '解除されない場合は運営までお問い合わせください。'
                . '</div>';
        }

        printf(
            '<p><a href="%s" class="dokan-btn dokan-btn-theme">%s</a></p>',
            esc_url($startUrl),
            esc_html($button)
        );

        echo '<p class="description">口座情報・本人確認書類は Stripe が直接お預かりします。'
            . '当サイトでは保持いたしません。</p>';

        echo '</div></div>';

        self::renderEarnings();

        echo '</article></div>';
    }

    /**
     * Suppress the dashboard widgets that can only ever show zero.
     *
     * 'reports' is the big counter row and the sales chart; 'orders' is the
     * order-status breakdown. Both read Dokan's commission tables, which this
     * project never writes to. 'products' is left alone — it counts posts,
     * and those are real.
     *
     * @param bool   $applicable
     * @param string $widget
     */
    public static function hideEmptyWidgets($applicable, $widget = ''): bool
    {
        return in_array($widget, ['reports', 'orders'], true) ? false : (bool) $applicable;
    }

    /**
     * The same figures as the payouts page, on the dashboard they land on.
     *
     * A creator should not have to know which of two numbers to believe, so
     * the wrong one is gone and this stands where it was.
     */
    public static function renderDashboardSummary(): void
    {
        $userId = get_current_user_id();

        if ($userId === 0
            || !function_exists('dokan_is_user_seller')
            || !dokan_is_user_seller($userId)
        ) {
            return;
        }

        $s = (new Earnings())->summary($userId);

        echo '<div class="dokan-w12 dokan-panel-inner-container">';
        echo '<div class="dokan-panel dokan-panel-default"><div class="dokan-panel-heading">'
            . '<strong>売上状況</strong></div><div class="dokan-panel-body">';

        if ($s['count'] === 0) {
            printf(
                '<p>まだ販売はありません。%s</p>',
                (new \MK\Stripe\AccountService())->canSell($userId)
                    ? ''
                    : '<br><a href="' . esc_url(dokan_get_navigation_url(self::PAGE)) . '">'
                        . '売上の受取設定</a>を完了すると商品が公開されます。'
            );
            echo '</div></div></div>';

            return;
        }

        echo '<table class="dokan-table" style="width:100%"><tbody>';
        printf('<tr><th style="width:45%%">販売件数</th><td>%d 件</td></tr>', $s['count']);
        printf('<tr><th>お受け取り額（合計）</th><td><strong>%s</strong></td></tr>',
            esc_html(Earnings::yen($s['net'])));
        printf('<tr><th>送金済み</th><td>%s</td></tr>', esc_html(Earnings::yen($s['paid'])));
        printf('<tr><th>送金予定</th><td>%s</td></tr>', esc_html(Earnings::yen($s['scheduled'])));
        printf('<tr><th>取引進行中</th><td>%s</td></tr>', esc_html(Earnings::yen($s['awaiting'])));

        if ($s['withheld'] > 0) {
            printf('<tr><th>保留中</th><td>%s</td></tr>', esc_html(Earnings::yen($s['withheld'])));
        }

        echo '</tbody></table>';

        printf(
            '<p style="margin-top:12px"><a href="%s" class="dokan-btn dokan-btn-theme dokan-btn-sm">'
            . '内訳・受取設定を見る</a></p>',
            esc_url(dokan_get_navigation_url(self::PAGE))
        );

        echo '</div></div></div>';
    }

    /**
     * The creator's sales, from our own records.
     *
     * Dokan's dashboard shows ¥0 for every creator here and always will: it
     * reads its own commission tables, which this project deliberately never
     * writes to. See Creator\Earnings for why those are not back-filled.
     */
    private static function renderEarnings(): void
    {
        $userId   = get_current_user_id();
        $earnings = new Earnings();
        $s        = $earnings->summary($userId);

        echo '<div class="dokan-panel dokan-panel-default"><div class="dokan-panel-heading">'
            . '<strong>売上状況</strong></div><div class="dokan-panel-body">';

        if ($s['count'] === 0) {
            echo '<p>まだ販売はありません。</p></div></div>';

            return;
        }

        echo '<table class="dokan-table" style="width:100%;margin-bottom:16px"><tbody>';
        printf('<tr><th style="width:40%%">販売件数</th><td>%d 件</td></tr>', $s['count']);
        printf('<tr><th>販売総額</th><td>%s</td></tr>', esc_html(Earnings::yen($s['gross'])));
        printf('<tr><th>運営手数料</th><td>− %s</td></tr>', esc_html(Earnings::yen($s['commission'])));
        printf('<tr><th><strong>お受け取り額（合計）</strong></th><td><strong>%s</strong></td></tr>',
            esc_html(Earnings::yen($s['net'])));
        echo '</tbody></table>';

        echo '<table class="dokan-table" style="width:100%"><tbody>';
        printf('<tr><th style="width:40%%">送金済み</th><td>%s</td></tr>',
            esc_html(Earnings::yen($s['paid'])));
        printf('<tr><th>送金予定</th><td>%s</td></tr>',
            esc_html(Earnings::yen($s['scheduled'])));
        printf('<tr><th>取引進行中</th><td>%s<br><small>発送・受取確認が完了すると送金予定に変わります</small></td></tr>',
            esc_html(Earnings::yen($s['awaiting'])));

        if ($s['withheld'] > 0) {
            printf('<tr><th>保留中</th><td>%s<br><small>確認対応中のため送金を保留しています</small></td></tr>',
                esc_html(Earnings::yen($s['withheld'])));
        }

        if ($s['outstanding'] > 0) {
            printf('<tr><th>未回収額</th><td>− %s<br><small>返金等により生じた金額です。次回の送金から差し引かれます</small></td></tr>',
                esc_html(Earnings::yen($s['outstanding'])));
        }

        echo '</tbody></table>';

        echo '<h4 style="margin-top:20px">取引ごとの内訳</h4>';
        echo '<table class="dokan-table" style="width:100%"><thead><tr>'
            . '<th>注文</th><th>商品</th><th>お受け取り額</th><th>状態</th><th>送金予定日</th>'
            . '</tr></thead><tbody>';

        foreach ($earnings->orders($userId, 20) as $order) {
            printf(
                '<tr><td>#%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $order->get_id(),
                esc_html((string) $order->get_meta('_mk_title_snapshot')),
                esc_html(Earnings::yen((int) $order->get_meta('_mk_creator_amount'))),
                esc_html($earnings->stateLabel($order)),
                esc_html($earnings->dueLabel($order))
            );
        }

        echo '</tbody></table>';
        echo '<p><small>※ ダッシュボードのトップに表示される売上金額は、'
            . '本サービスでは使用していない集計のため 0 円のまま変わりません。'
            . '正しい売上はこちらの表をご覧ください。</small></p>';
        echo '</div></div>';
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
