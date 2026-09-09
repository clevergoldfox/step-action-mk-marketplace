<?php
/**
 * Deployment smoke test. Run against a REAL WordPress install:
 *
 *     wp eval-file wp-content/plugins/mk-marketplace/tests/integration/smoke.php
 *
 * On the production host wp-cli must be given the right interpreter, because
 * /usr/bin/php there is 8.0 and WP_CLI_PHP is not honoured:
 *
 *     /usr/bin/php8.3 /usr/bin/wp eval-file <path>
 *
 * This complements the unit tests rather than repeating them. Money maths is
 * covered by phpunit with no database; what cannot be tested that way is
 * whether WordPress and WooCommerce actually accepted what we registered.
 * Both bugs this file was written to catch -- order statuses silently falling
 * back to 'pending', and a webhook URL resolving to http -- were invisible to
 * unit tests and to the source code alike.
 *
 * Side effects, all reverted: it allocates five creator numbers and restores
 * the counter, and creates one order which it force-deletes.
 */

// wp eval-file includes this inside a function, so plain top-level variables
// are locals while check()'s `global` sees the real globals -- which is why
// the counters previously always printed 0 passed, 0 failed.
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%-6s %-46s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail);
}

echo "=== classes autoload ===\n";
foreach ([
    'MK\Support\Money', 'MK\Fee\Calculator', 'MK\Fee\Breakdown',
    'MK\Creator\Numbering', 'MK\Install\Migrator',
    'MK\Order\Statuses', 'MK\Order\Transitions',
    'MK\Product\Statuses', 'MK\Product\Reservation',
    'MK\Checkout\OrderBuilder', 'MK\Ledger\Recorder',
    'MK\Schedule\Jobs', 'MK\Stripe\Client', 'MK\Stripe\AccountService',
    'MK\Stripe\PaymentService', 'MK\Stripe\TransferService',
    'MK\Stripe\WebhookController',
] as $c) {
    check(substr($c, 3), class_exists($c));
}

echo "\n=== stripe sdk ===\n";
check('Stripe\StripeClient', class_exists('Stripe\StripeClient'));
check('keys configured', MK\Stripe\Client::isConfigured());
check('test mode', MK\Stripe\Client::isTestMode(),
    MK\Stripe\Client::isTestMode() ? '(sk_test_)' : '!! LIVE KEY !!');

$mkStatuses = [MK\Order\Statuses::PAID, MK\Order\Statuses::SHIPPED, MK\Order\Statuses::RECEIVED];

echo "\n=== order statuses: registered ===\n";
$os = wc_get_order_statuses();
foreach ($mkStatuses as $bare) {
    $k = MK\Order\Statuses::wcKey($bare);
    check($k, isset($os[$k]), $os[$k] ?? 'missing');
}

echo "\n=== order statuses: round-trip ===\n";
// Presence in that array is not the property that matters. set_status() falls
// back to 'pending' on an unknown status WITHOUT raising anything, so the only
// honest test is to set each status and read it back off a reloaded order.
//
// Transitions are unhooked first: setting mk-shipped / mk-received would
// schedule real payout jobs, and deleting the order afterwards would leave
// them orphaned in the Action Scheduler queue.
remove_action('woocommerce_order_status_changed', ['MK\Order\Transitions', 'handle'], 10);

$tmp = wc_create_order();

if (is_wp_error($tmp)) {
    check('create throwaway order', false, $tmp->get_error_message());
} else {
    $tmpId = $tmp->get_id();

    foreach ($mkStatuses as $bare) {
        $tmp->set_status($bare);
        $tmp->save();

        $got = wc_get_order($tmpId)->get_status();   // reload: prove it persisted

        check("set -> {$bare}", $got === $bare, $got === $bare
            ? ''
            : "got '{$got}'" . ($got === 'pending' ? '  <-- silent fallback' : ''));
    }

    wc_get_order($tmpId)->delete(true);
    check('throwaway order deleted', !wc_get_order($tmpId), 'id ' . $tmpId);
}

add_action('woocommerce_order_status_changed', ['MK\Order\Transitions', 'handle'], 10, 4);

echo "\n=== payout authorisation (a creator must not pay themselves) ===\n";
// Dokan's REST bulk-action endpoint lets a vendor set any status on an order
// they own, so an order reaching 受取確認 is not by itself evidence that the
// buyer confirmed anything. The money layer has to refuse regardless of how
// the status arrived.
$seller = wp_insert_user([
    'user_login' => 'mk_smoke_seller_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($seller)) {
    check('create throwaway seller', false, $seller->get_error_message());
} else {
    $wasUser = get_current_user_id();

    // --- the attack: the creator moves their own order to 受取確認 ---
    $evil = wc_create_order();
    $evil->update_meta_data('_mk_creator_id', $seller);
    $evil->save();
    $evilId = $evil->get_id();

    wp_set_current_user($seller);
    $evil->set_status(MK\Order\Statuses::RECEIVED);
    $evil->save();

    $scheduled = as_has_scheduled_action('mk_execute_transfer', ['order_id' => $evilId], 'mk-marketplace');
    $blocked   = wc_get_order($evilId)->get_meta(MK\Order\Guard::META_BLOCKED) === 'yes';

    check('creator self-release: no transfer queued', !$scheduled,
        $scheduled ? 'TRANSFER WAS SCHEDULED' : '');
    check('creator self-release: order flagged', $blocked);

    // --- layer 1: the REST route itself must refuse ---
    // Dispatched through the real REST server, not by calling the filter, so
    // this also proves the route pattern actually matches what Dokan
    // registered rather than what we assumed it registered.
    wp_set_current_user($seller);

    $rest = wc_create_order();
    $rest->update_meta_data('_mk_creator_id', $seller);
    $rest->save();
    $restId = $rest->get_id();

    // Assert the route exists before asserting it is blocked: rest_pre_dispatch
    // fires before route resolution, so a 403 on a nonexistent route would
    // pass while proving nothing. (Dokan registers bulk-actions on v2 and v3
    // only -- there is no v1 bulk-actions route.)
    $dokanRoutes = rest_get_server()->get_routes();
    check('dokan bulk-actions route exists',
        isset($dokanRoutes['/dokan/v3/orders/bulk-actions']),
        isset($dokanRoutes['/dokan/v3/orders/bulk-actions']) ? '' : 'route renamed -- guard may be stale');

    $req = new WP_REST_Request('POST', '/dokan/v3/orders/bulk-actions');
    $req->set_param('status', MK\Order\Statuses::RECEIVED);
    $req->set_param('order_ids', [$restId]);
    $res = rest_do_request($req);

    $code = is_array($res->get_data()) ? ($res->get_data()['code'] ?? '') : '';
    check('REST self-release refused by our guard',
        $res->get_status() === 403 && $code === 'mk_forbidden_status_change',
        'HTTP ' . $res->get_status() . ' ' . $code);
    check('REST self-release: status unchanged',
        wc_get_order($restId)->get_status() !== MK\Order\Statuses::RECEIVED,
        wc_get_order($restId)->get_status());

    // the permitted one must still get through the guard
    $req2 = new WP_REST_Request('POST', '/dokan/v3/orders/bulk-actions');
    $req2->set_param('status', MK\Order\Statuses::SHIPPED);
    $req2->set_param('order_ids', [$restId]);
    $res2 = rest_do_request($req2);

    // Dokan may still refuse this for its own reasons (a bare seller account
    // is not a configured vendor), which is not our concern. What must be
    // true is that OUR guard let it through.
    $code2 = is_array($res2->get_data()) ? ($res2->get_data()['code'] ?? '') : '';
    check('REST shipped passed our guard', $code2 !== 'mk_forbidden_status_change',
        'HTTP ' . $res2->get_status() . ' ' . $code2);

    as_unschedule_all_actions('mk_auto_complete_order', ['order_id' => $restId], 'mk-marketplace');
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $restId], 'mk-marketplace');
    wc_get_order($restId)->delete(true);
    wp_set_current_user(0);

    // --- the legitimate path: the system releases it ---
    wp_set_current_user(0);
    $good = wc_create_order();
    $good->update_meta_data('_mk_creator_id', $seller);
    $good->save();
    $goodId = $good->get_id();

    $good->set_status(MK\Order\Statuses::RECEIVED);
    $good->save();

    $ok = as_has_scheduled_action('mk_execute_transfer', ['order_id' => $goodId], 'mk-marketplace');
    check('system release: transfer queued', $ok, $ok ? '' : 'not scheduled');

    check('Guard: creator may set shipped',    MK\Order\Guard::creatorMaySet(MK\Order\Statuses::SHIPPED));
    check('Guard: creator may NOT set received', !MK\Order\Guard::creatorMaySet(MK\Order\Statuses::RECEIVED));
    check('Guard: creator may NOT set completed', !MK\Order\Guard::creatorMaySet('completed'));

    // cleanup: queued jobs, orders, user
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $goodId], 'mk-marketplace');
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $evilId], 'mk-marketplace');
    wc_get_order($evilId)->delete(true);
    wc_get_order($goodId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($seller);
    wp_set_current_user($wasUser);

    check('payout-auth fixtures removed',
        !wc_get_order($evilId) && !wc_get_order($goodId) && !get_userdata($seller));
}

echo "\n=== creator onboarding (dashboard wiring) ===\n";
$navUrl = dokan_get_navigation_url(MK\Creator\Onboarding::PAGE);
$nav    = dokan_get_dashboard_nav();

check('nav item registered', isset($nav[MK\Creator\Onboarding::PAGE]),
    $nav[MK\Creator\Onboarding::PAGE]['title'] ?? 'missing');

// A registered query var contributes a rewrite rule, and a rule that was
// never flushed does not exist as far as WordPress is concerned: the nav
// item renders, the URL looks correct, and clicking it 404s.
$ruleFound = false;
foreach ((array) get_option('rewrite_rules') as $target) {
    if (is_string($target) && str_contains($target, MK\Creator\Onboarding::PAGE)) {
        $ruleFound = true;
        break;
    }
}
check('rewrite rule flushed', $ruleFound, $ruleFound ? $navUrl : 'page would 404');

check('onboarding return url is https',
    str_starts_with(add_query_arg(MK\Creator\Onboarding::ACTION_ARG, 'return', home_url('/')), 'https://'));

// Numbering must never reissue: a creator whose number changed would break
// every link and search that used the old one.
$numUser = wp_insert_user([
    'user_login' => 'mk_smoke_num_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($numUser)) {
    check('create throwaway creator', false, $numUser->get_error_message());
} else {
    $seq   = get_option('mk_creator_seq');
    $first = MK\Creator\Onboarding::ensureNumber($numUser);
    $again = MK\Creator\Onboarding::ensureNumber($numUser);

    check('number allocated', $first !== '' && MK\Creator\Numbering::isCreatorNumber($first), $first);
    check('number is idempotent', $first === $again, $first . ' / ' . $again);

    update_option('mk_creator_seq', $seq);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($numUser);
    check('onboarding fixture removed', !get_userdata($numUser));
}

echo "\n=== product statuses ===\n";
foreach (['mk-reserved', 'mk-sold'] as $s) {
    check($s, in_array($s, get_post_stati(), true));
}

echo "\n=== scheduled actions ===\n";
check('daily digest', as_has_scheduled_action('mk_send_daily_digest', [], 'mk-marketplace'));
check('reservation sweeper', as_has_scheduled_action('mk_sweep_reservations', [], 'mk-marketplace'));

echo "\n=== webhook route ===\n";
$routes = rest_get_server()->get_routes();
$hook   = MK\Stripe\WebhookController::endpointUrl();
check('/mk/v1/stripe-webhook', isset($routes['/mk/v1/stripe-webhook']), $hook);
// Stripe refuses to deliver to a plaintext endpoint, so http here means the
// webhook silently never arrives in production.
check('endpoint is https', str_starts_with($hook, 'https://'), $hook);

echo "\n=== fee calculation (live settings) ===\n";
$b = MK\Fee\Calculator::fromSettings()->calculate(10000, 2000);
check('total 12000', $b->total === 12000, (string) $b->total);
check('platform fee 2400', $b->platformFee === 2400, (string) $b->platformFee);
check('creator 9600', $b->creatorAmount === 9600, (string) $b->creatorAmount);
check('halves sum to total', $b->platformFee + $b->creatorAmount === $b->total);

echo "\n=== creator numbering (real DB, atomic) ===\n";
$before = get_option('mk_creator_seq');
$n = new MK\Creator\Numbering();
$got = [];
for ($i = 0; $i < 5; $i++) { $got[] = $n->allocate(); }
check('5 sequential', $got === ['A00001','A00002','A00003','A00004','A00005'], implode(' ', $got));
check('all distinct', count(array_unique($got)) === 5);
update_option('mk_creator_seq', $before);
check('counter reset', get_option('mk_creator_seq') === $before, 'back to ' . $before);

echo "\n=== timezone ===\n";
check('Asia/Tokyo', wp_timezone_string() === 'Asia/Tokyo', wp_timezone_string());

echo "\n=== woocommerce currency ===\n";
check('JPY', get_woocommerce_currency() === 'JPY', get_woocommerce_currency());
check('0 decimals', wc_get_price_decimals() === 0, (string) wc_get_price_decimals());

echo "\n=== HPOS ===\n";
$hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
    && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
check('custom order tables', $hpos, $hpos ? '' : 'still using wp_posts');

printf("\n%s  %d passed, %d failed\n",
    $GLOBALS['fail'] === 0 ? 'ALL PASS' : 'FAILURES',
    $GLOBALS['pass'], $GLOBALS['fail']);
