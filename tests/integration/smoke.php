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
// Reported, not required. Since the switch to live keys (2026-09-19) this
// suite runs against the live account, which it can do because it never
// writes to Stripe: every transfer, charge and refund id below is invented,
// and the only calls it makes are reads that fail harmlessly on them.
check('Stripe mode', true, MK\Stripe\Client::isTestMode() ? 'test (sk_test_)' : 'LIVE -- no Stripe writes in this suite');

// mk_test_creator stands in for an onboarded creator. Its test-mode Stripe
// account was removed at the live cutover, which leaves it unable to sell, so
// it is marked onboarded for the length of this run and put back afterwards
// -- on a fatal error too.
$fixtureCreator = get_user_by('login', 'mk_test_creator');

if ($fixtureCreator) {
    $fixtureKey    = MK\Stripe\AccountService::META_STATUS;
    $fixtureStatus = (string) get_user_meta($fixtureCreator->ID, $fixtureKey, true);

    update_user_meta($fixtureCreator->ID, $fixtureKey, MK\Stripe\AccountService::STATUS_COMPLETED);

    register_shutdown_function(static function () use ($fixtureCreator, $fixtureKey, $fixtureStatus): void {
        $fixtureStatus === ''
            ? delete_user_meta($fixtureCreator->ID, $fixtureKey)
            : update_user_meta($fixtureCreator->ID, $fixtureKey, $fixtureStatus);
    });
}

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

echo "\n=== publish gate (no payout account, no public listing) ===\n";
$gateUser = wp_insert_user([
    'user_login' => 'mk_smoke_gate_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($gateUser)) {
    check('create throwaway creator', false, $gateUser->get_error_message());
} else {
    check('creator cannot sell yet', !(new MK\Stripe\AccountService())->canSell($gateUser));

    // A brand-new product has no ID inside wp_insert_post_data, so this also
    // proves the held-marker survives the case the filter alone cannot see.
    $held = wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => 'MK smoke held product',
        'post_author' => $gateUser,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000'],
    ]);

    check('publish demoted to draft', get_post_status($held) === 'draft', get_post_status($held));
    check('held marker written', get_post_meta($held, MK\Product\PublishGate::META_HELD, true) === 'yes');
    check('unreviewed: headed for approval, not live',
        get_post_meta($held, MK\Product\PublishGate::META_RELEASE_TO, true) === 'pending',
        (string) get_post_meta($held, MK\Product\PublishGate::META_RELEASE_TO, true));

    // The same, from a creator the operator has marked trusted: Dokan would
    // publish their listings directly, so release does too.
    update_user_meta($gateUser, 'dokan_publishing', 'yes');
    $trusted = wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => 'MK smoke held product (trusted)',
        'post_author' => $gateUser,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000'],
    ]);
    check('trusted creator still held while unpayable', get_post_status($trusted) === 'draft', get_post_status($trusted));

    // Onboarding completes -> everything moves on to where it was headed.
    update_user_meta($gateUser, MK\Stripe\AccountService::META_STATUS,
        MK\Stripe\AccountService::STATUS_COMPLETED);

    do_action('mk_creator_onboarding_completed', $gateUser);

    // The bypass this guards against: list -> held -> onboard -> live with
    // nobody having reviewed it.
    check('unreviewed listing released to the approval queue, not live',
        get_post_status($held) === 'pending', get_post_status($held));
    check('trusted listing released live', get_post_status($trusted) === 'publish', get_post_status($trusted));
    check('held marker cleared', get_post_meta($held, MK\Product\PublishGate::META_HELD, true) === '');
    check('release target cleared', get_post_meta($held, MK\Product\PublishGate::META_RELEASE_TO, true) === '');
    delete_user_meta($gateUser, 'dokan_publishing');
    wp_delete_post($trusted, true);

    $free = wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => 'MK smoke free product',
        'post_author' => $gateUser,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000'],
    ]);

    check('publishes freely once onboarded', get_post_status($free) === 'publish', get_post_status($free));

    wp_delete_post($held, true);
    wp_delete_post($free, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($gateUser);
    check('publish-gate fixtures removed', !get_post($held) && !get_post($free) && !get_userdata($gateUser));
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

echo "\n=== Webhook：クリエイターの口座用（Connect）の署名 ===\n";
if (!defined('MK_STRIPE_CONNECT_WEBHOOK_SECRET')) {
    define('MK_STRIPE_CONNECT_WEBHOOK_SECRET', 'whsec_smoke_' . wp_generate_password(24, false));
}

check('2つ目の署名シークレットを使う', in_array(MK_STRIPE_CONNECT_WEBHOOK_SECRET, MK\Stripe\Client::webhookSecrets(), true)
    && count(MK\Stripe\Client::webhookSecrets()) === 2);

$whEvent   = 'evt_smoke_' . wp_generate_password(12, false);
$whPayload = (string) wp_json_encode([
    'id'     => $whEvent,
    'object' => 'event',
    'type'   => 'mk.smoke_noop',   // どの処理にも当たらない種類
    'data'   => ['object' => ['id' => 'noop', 'object' => 'noop']],
]);
$whSend = static function (string $secret) use ($whPayload): int {
    $t   = time();
    $req = new WP_REST_Request('POST', '/mk/v1/stripe-webhook');
    $req->set_body($whPayload);
    $req->set_header('content-type', 'application/json');
    $req->set_header('stripe-signature', 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $whPayload, $secret));

    return rest_do_request($req)->get_status();
};

check('Connect側の署名で届いた通知を受け付ける', ($s = $whSend(MK_STRIPE_CONNECT_WEBHOOK_SECRET)) === 200, (string) $s);
check('どちらの署名でもない通知は拒否する', ($s = $whSend('whsec_not_ours')) === 400, (string) $s);

global $wpdb;
$wpdb->delete($wpdb->prefix . 'mk_webhook_events', ['event_id' => $whEvent], ['%s']);

echo "\n=== fee calculation (live settings) ===\n";
$b = MK\Fee\Calculator::fromSettings()->calculate(10000, 2000);
check('total 12000', $b->total === 12000, (string) $b->total);
// Derived from the live rates, not written in: the rates are a setting the
// operator can change (WooCommerce -> 手数料設定), so a hard-coded figure here
// fails the day they use it and tells them nothing about what broke.
$feeNow = MK\Support\Money::applyRate(10000, MK\Fee\Settings::productRate())
        + MK\Support\Money::applyRate(2000, MK\Fee\Settings::optionRate());
check('platform fee matches the live rates', $b->platformFee === $feeNow, (string) $b->platformFee);
check('creator gets the rest', $b->creatorAmount === 12000 - $feeNow, (string) $b->creatorAmount);
check('halves sum to total', $b->platformFee + $b->creatorAmount === $b->total);

echo "\n=== creator numbering (real DB, atomic) ===\n";
$before = get_option('mk_creator_seq');
$n = new MK\Creator\Numbering();
$got = [];
for ($i = 0; $i < 5; $i++) { $got[] = $n->allocate(); }

// Expected values are derived from the counter, not hardcoded. Asserting
// A00001 silently required an empty database, so the first real creator on
// the site broke a test about concurrency for reasons having nothing to do
// with concurrency.
$expected = [];
for ($i = 1; $i <= 5; $i++) { $expected[] = $n->format((int) $before + $i); }

check('5 sequential', $got === $expected,
    implode(' ', $got) . ($got === $expected ? '' : '  expected ' . implode(' ', $expected)));
check('all distinct', count(array_unique($got)) === 5);
update_option('mk_creator_seq', $before);
check('counter reset', get_option('mk_creator_seq') === $before, 'back to ' . $before);

echo "\n=== carriers ===\n";
$carriers = MK\Order\Shipping::carriers();
check('seeded', count($carriers) >= 6, count($carriers) . ' active');
check('sorted, その他 last',
    !$carriers || end($carriers)->name === 'その他',
    implode(' / ', array_map(fn($c) => $c->name, $carriers)));

echo "\n=== transaction lifecycle (paid -> shipped -> received) ===\n";
$lcSeller = wp_insert_user([
    'user_login' => 'mk_smoke_lc_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($lcSeller)) {
    check('create lifecycle seller', false, $lcSeller->get_error_message());
} else {
    wp_set_current_user(0);   // act as the system, not the creator

    $lc = wc_create_order();
    $lc->update_meta_data('_mk_creator_id', $lcSeller);
    $lc->save();
    $lcId = $lc->get_id();

    $lc->set_status(MK\Order\Statuses::PAID);
    $lc->save();
    check('1. paid', wc_get_order($lcId)->get_status() === MK\Order\Statuses::PAID);

    // Register a shipment the way Shipping::handleSubmit does, using the
    // tracking-less path -- the one a required field would have broken.
    $c = $carriers[1] ?? $carriers[0];
    $lc = wc_get_order($lcId);
    $lc->update_meta_data(MK\Order\Shipping::META_CARRIER_ID, (int) $c->id);
    $lc->update_meta_data(MK\Order\Shipping::META_CARRIER_NAME, $c->name);
    $lc->update_meta_data(MK\Order\Shipping::META_TRACKING, '');
    $lc->update_meta_data(MK\Order\Shipping::META_NO_TRACKING, 'yes');
    $lc->update_meta_data(MK\Order\Shipping::META_SHIPPED_AT, gmdate('Y-m-d H:i:s'));
    $lc->save();
    $lc->update_status(MK\Order\Statuses::SHIPPED, 'smoke: 発送登録');

    check('2. shipped', wc_get_order($lcId)->get_status() === MK\Order\Statuses::SHIPPED);
    check('   carrier recorded', wc_get_order($lcId)->get_meta(MK\Order\Shipping::META_CARRIER_NAME) === $c->name, $c->name);
    check('   no-tracking recorded', wc_get_order($lcId)->get_meta(MK\Order\Shipping::META_NO_TRACKING) === 'yes');
    check('   auto-complete queued',
        as_has_scheduled_action('mk_auto_complete_order', ['order_id' => $lcId], 'mk-marketplace'));

    // Buyer confirms receipt.
    wc_get_order($lcId)->update_status(MK\Order\Statuses::RECEIVED, 'smoke: 受取確認');

    check('3. received', wc_get_order($lcId)->get_status() === MK\Order\Statuses::RECEIVED);
    check('   auto-complete cancelled',
        !as_has_scheduled_action('mk_auto_complete_order', ['order_id' => $lcId], 'mk-marketplace'));
    check('   transfer queued',
        as_has_scheduled_action('mk_execute_transfer', ['order_id' => $lcId], 'mk-marketplace'));
    check('   payout not blocked',
        wc_get_order($lcId)->get_meta(MK\Order\Guard::META_BLOCKED) !== 'yes');

    $due = (string) wc_get_order($lcId)->get_meta('_mk_transfer_due_at');
    check('   transfer due date set', $due !== '', $due);

    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $lcId], 'mk-marketplace');
    as_unschedule_all_actions('mk_auto_complete_order', ['order_id' => $lcId], 'mk-marketplace');
    wc_get_order($lcId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($lcSeller);
    check('lifecycle fixtures removed', !wc_get_order($lcId) && !get_userdata($lcSeller));
}

echo "\n=== payout hold tiers (client option C) ===\n";
check('standard = 7',    (int) get_option('mk_payout_hold_days') === 7,      (string) get_option('mk_payout_hold_days'));
check('new creator = 10', (int) get_option('mk_payout_hold_days_new') === 10,  (string) get_option('mk_payout_hold_days_new'));
check('high value = 14',  (int) get_option('mk_payout_hold_days_high') === 14, (string) get_option('mk_payout_hold_days_high'));

$tierSeller = wp_insert_user([
    'user_login' => 'mk_smoke_tier_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($tierSeller)) {
    check('create tier seller', false, $tierSeller->get_error_message());
} else {
    wp_set_current_user(0);

    // holdDaysFor() is private, so drive it the way production does: schedule
    // the transfer and read back the hold it recorded on the order.
    $tierOf = function (int $sales, int $amount) use ($tierSeller): int {
        update_user_meta($tierSeller, 'mk_completed_sales_count', $sales);

        $o = wc_create_order();
        $o->update_meta_data('_mk_creator_id', $tierSeller);
        $o->update_meta_data('_mk_product_amount', $amount);
        $o->update_meta_data('_mk_option_amount', 0);
        $o->save();

        MK\Schedule\Jobs::scheduleTransfer($o->get_id());

        $days = (int) wc_get_order($o->get_id())->get_meta('_mk_payout_hold_days');

        as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $o->get_id()], 'mk-marketplace');
        wc_get_order($o->get_id())->delete(true);

        return $days;
    };

    check('established + small -> 7',  $tierOf(10, 10000) === 7,  $tierOf(10, 10000) . ' days');
    check('new + small        -> 10', $tierOf(0, 10000) === 10, $tierOf(0, 10000) . ' days');
    check('established + large -> 14', $tierOf(10, 80000) === 14, $tierOf(10, 80000) . ' days');
    // The riskiest combination. Taking the first matching rule rather than the
    // longest would have returned 10 here -- shorter than the large-order rule
    // alone gives, for an order that is both large AND from an unknown seller.
    check('new + large        -> 14', $tierOf(0, 80000) === 14, $tierOf(0, 80000) . ' days');

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($tierSeller);
    check('tier fixtures removed', !get_userdata($tierSeller));
}

echo "\n=== checkout ===\n";
check('shortcode registered', shortcode_exists('mk_checkout'));

$coPage = (int) get_option(MK\Checkout\Controller::PAGE_OPTION);
$coPost = $coPage > 0 ? get_post($coPage) : null;
check('checkout page exists', $coPost && $coPost->post_status === 'publish',
    $coPost ? get_permalink($coPage) : 'missing');
check('page contains shortcode', $coPost && str_contains($coPost->post_content, '[mk_checkout]'));
check('checkoutUrl resolves', str_starts_with(MK\Checkout\Controller::checkoutUrl(), 'https://'),
    MK\Checkout\Controller::checkoutUrl());

// The page can be trashed by an admin tidying up; the buy button would then
// point nowhere. Re-running the installer must bring it back.
if ($coPost) {
    wp_trash_post($coPage);
    MK\Checkout\Controller::ensurePage();
    $healed = (int) get_option(MK\Checkout\Controller::PAGE_OPTION);
    $healedPost = $healed > 0 ? get_post($healed) : null;
    check('trashed page is recreated',
        $healedPost && $healedPost->post_status === 'publish' && $healed !== $coPage,
        $healedPost ? 'new id ' . $healed : 'not recreated');

    // Put it back exactly as it was. Without this the test leaks a page every
    // run, and each new one takes a suffixed slug (mk-checkout-2, -3, ...)
    // because the original still holds the good one.
    if ($healed !== $coPage) {
        wp_delete_post($healed, true);
    }

    wp_untrash_post($coPage);
    wp_update_post(['ID' => $coPage, 'post_status' => 'publish']);
    update_option(MK\Checkout\Controller::PAGE_OPTION, $coPage);

    check('checkout page restored',
        get_post_status($coPage) === 'publish'
            && (int) get_option(MK\Checkout\Controller::PAGE_OPTION) === $coPage,
        'id ' . $coPage . ' ' . get_permalink($coPage));
}

// The add-to-cart button must be gone: two routes to buying one unique item
// is two ways to reserve it.
do_action('wp');
check('add-to-cart replaced',
    has_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart') === false,
    'default add-to-cart removed');
check('buy button hooked',
    has_action('woocommerce_single_product_summary', ['MK\Checkout\Controller', 'renderBuyButton']) !== false);

echo "\n=== payouts page actually renders for a creator ===\n";
// Capability names are not guessable. Gating this page on a capability the
// seller role does not have locked every creator out of their own payout
// settings while the menu item sat in the sidebar looking fine -- and no test
// caught it, because nothing rendered the page.
$rndSeller = wp_insert_user([
    'user_login' => 'mk_smoke_render_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($rndSeller)) {
    check('create render seller', false, $rndSeller->get_error_message());
} else {
    $wasUser = get_current_user_id();
    $seq     = get_option('mk_creator_seq');

    wp_set_current_user($rndSeller);

    ob_start();
    MK\Creator\Onboarding::renderPage([MK\Creator\Onboarding::PAGE => MK\Creator\Onboarding::PAGE]);
    $html = (string) ob_get_clean();

    $number = get_user_meta($rndSeller, 'mk_creator_number', true);

    check('not refused', !str_contains($html, '権限がありません'),
        str_contains($html, '権限がありません') ? 'PERMISSION DENIED' : '');
    check('shows creator number', $number !== '' && str_contains($html, (string) $number), (string) $number);
    check('shows setup button', str_contains($html, '受取口座を設定する'));
    check('warns selling is blocked', str_contains($html, '出品（運営への審査申請）はできません'));

    // A logged-in customer is not a vendor and must still be refused.
    $cust = wp_insert_user([
        'user_login' => 'mk_smoke_cust_' . wp_rand(1000, 9999),
        'user_pass'  => wp_generate_password(24),
        'role'       => 'customer',
    ]);
    wp_set_current_user($cust);
    ob_start();
    MK\Creator\Onboarding::renderPage([MK\Creator\Onboarding::PAGE => MK\Creator\Onboarding::PAGE]);
    $custHtml = (string) ob_get_clean();
    check('non-vendor refused', str_contains($custHtml, '権限がありません'));

    wp_set_current_user($wasUser);
    update_option('mk_creator_seq', $seq);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($rndSeller);
    wp_delete_user($cust);
    check('render fixtures removed', !get_userdata($rndSeller) && !get_userdata($cust));
}

echo "\n=== transfer completes the order (no Stripe call) ===\n";
// execute() returns early when a transfer id is already present, so the
// completion and counting logic can be exercised without moving real money.
$cmpSeller = wp_insert_user([
    'user_login' => 'mk_smoke_cmp_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($cmpSeller)) {
    check('create completion seller', false, $cmpSeller->get_error_message());
} else {
    wp_set_current_user(0);

    $co = wc_create_order();
    $co->update_meta_data('_mk_creator_id', $cmpSeller);
    $co->update_meta_data(MK\Stripe\TransferService::META_TRANSFER_ID, 'tr_smoke_fake');
    $co->update_meta_data(MK\Stripe\TransferService::META_STATUS, MK\Stripe\TransferService::STATUS_SENT);
    $co->save();
    $coId = $co->get_id();
    $co->update_status(MK\Order\Statuses::RECEIVED, 'smoke: 受取確認');

    MK\Schedule\Jobs::runTransfer($coId);

    check('order completed', wc_get_order($coId)->get_status() === 'completed',
        wc_get_order($coId)->get_status());
    check('sale counted once',
        (int) get_user_meta($cmpSeller, 'mk_completed_sales_count', true) === 1,
        (string) get_user_meta($cmpSeller, 'mk_completed_sales_count', true));
    // onCompleted schedules this; if the order never completes, the buyer's
    // address is never masked and the creator keeps it indefinitely.
    check('address masking queued',
        as_has_scheduled_action('mk_mask_shipping_address', ['order_id' => $coId], 'mk-marketplace'));

    // Action Scheduler retries jobs whose later steps failed.
    MK\Schedule\Jobs::runTransfer($coId);
    MK\Schedule\Jobs::runTransfer($coId);
    check('retries do not re-count',
        (int) get_user_meta($cmpSeller, 'mk_completed_sales_count', true) === 1,
        (string) get_user_meta($cmpSeller, 'mk_completed_sales_count', true));

    // A withheld transfer must NOT complete the order.
    $wo = wc_create_order();
    $wo->update_meta_data('_mk_creator_id', $cmpSeller);
    $wo->update_meta_data('_mk_has_open_report', 'yes');
    $wo->save();
    $woId = $wo->get_id();
    $wo->update_status(MK\Order\Statuses::RECEIVED, 'smoke: 通報あり');
    MK\Schedule\Jobs::runTransfer($woId);

    check('withheld order stays open',
        wc_get_order($woId)->get_status() !== 'completed',
        wc_get_order($woId)->get_status());

    as_unschedule_all_actions('mk_mask_shipping_address', ['order_id' => $coId], 'mk-marketplace');
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $coId], 'mk-marketplace');
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $woId], 'mk-marketplace');
    wc_get_order($coId)->delete(true);
    wc_get_order($woId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($cmpSeller);
    check('completion fixtures removed', !wc_get_order($coId) && !get_userdata($cmpSeller));
}

echo "\n=== stripe SDK methods we depend on ===\n";
// TransferService::reverseTransfer() did not exist. Nothing caught it: the
// call sat on a refund path no test reached, so the first refund threw --
// after the buyer had already been repaid and before the creator's transfer
// was pulled back, costing the platform the whole order value.
//
// A mistyped SDK method is invisible in PHP until the line runs. This asserts
// the surface exists, without a single network call.
foreach ([
    ['transfers',        'Stripe\Service\TransferService',       ['create', 'retrieve', 'createReversal']],
    ['paymentIntents',   'Stripe\Service\PaymentIntentService',  ['create', 'retrieve', 'confirm', 'cancel']],
    ['refunds',          'Stripe\Service\RefundService',         ['create']],
    ['accounts',         'Stripe\Service\AccountService',        ['create', 'retrieve', 'update', 'delete', 'createExternalAccount']],
    ['accountLinks',     'Stripe\Service\AccountLinkService',    ['create']],
    ['webhookEndpoints', 'Stripe\Service\WebhookEndpointService', ['create', 'all', 'update', 'delete']],
    ['balance',          'Stripe\Service\BalanceService',        ['retrieve']],
    ['charges',          'Stripe\Service\ChargeService',         ['retrieve']],
] as [$label, $class, $methods]) {
    foreach ($methods as $m) {
        $ok = method_exists($class, $m);
        check($label . '->' . $m . '()', $ok, $ok ? '' : 'MISSING FROM SDK');
    }
}

check('Webhook::constructEvent', method_exists('Stripe\Webhook', 'constructEvent'));

echo "\n=== creator balance arithmetic ===\n";
// This lived in wp_usermeta and was maintained with INSERT ... ON DUPLICATE
// KEY UPDATE, which only fires on a UNIQUE or PRIMARY key -- and wp_usermeta
// has neither on (user_id, meta_key). Every write inserted another row, reads
// kept returning the first, and the balance never moved: a creator's debt was
// deducted from a payout and remained outstanding, so it would have been
// deducted again from every future payout.
$ldgUser = wp_insert_user([
    'user_login' => 'mk_smoke_ldg_' . wp_rand(1000, 9999),
    'user_pass'  => wp_generate_password(24),
    'role'       => 'seller',
]);

if (is_wp_error($ldgUser)) {
    check('create ledger seller', false, $ldgUser->get_error_message());
} else {
    global $wpdb;
    $ledger = new MK\Ledger\Recorder();

    check('starts at zero', $ledger->outstanding($ldgUser) === 0);

    $ledger->record($ldgUser, null, MK\Ledger\Recorder::DEBT_INCURRED, 500, 500, null, 'smoke');
    check('debt incurred', $ledger->outstanding($ldgUser) === 500, (string) $ledger->outstanding($ldgUser));

    $ledger->record($ldgUser, null, MK\Ledger\Recorder::DEBT_RECOVERED, 300, -300, null, 'smoke');
    check('partial recovery reduces it', $ledger->outstanding($ldgUser) === 200,
        (string) $ledger->outstanding($ldgUser));

    $ledger->record($ldgUser, null, MK\Ledger\Recorder::DEBT_RECOVERED, 900, -900, null, 'smoke');
    check('never goes negative', $ledger->outstanding($ldgUser) === 0,
        (string) $ledger->outstanding($ldgUser));

    // The direct guard: one row per creator. Duplicates are what the broken
    // statement produced, and they are invisible through the accessor.
    $rows = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}mk_creator_balances WHERE user_id = %d", $ldgUser));
    check('exactly one balance row', $rows === 1, $rows . ' row(s)');

    // An informational entry must not disturb the balance.
    $ledger->record($ldgUser, null, MK\Ledger\Recorder::DEBT_INCURRED, 250, 250, null, 'smoke');
    $ledger->record($ldgUser, null, MK\Ledger\Recorder::TRANSFER, 1000, 0, null, 'smoke');
    check('transfer entry leaves debt alone', $ledger->outstanding($ldgUser) === 250,
        (string) $ledger->outstanding($ldgUser));

    $wpdb->delete($wpdb->prefix . 'mk_creator_ledger', ['user_id' => $ldgUser], ['%d']);
    $wpdb->delete($wpdb->prefix . 'mk_creator_balances', ['user_id' => $ldgUser], ['%d']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($ldgUser);
    check('ledger fixtures removed', !get_userdata($ldgUser));
}

echo "\n=== 通報 stops the money, resolving restarts it ===\n";
$rpSeller = wp_insert_user(['user_login' => 'mk_smoke_rp_s_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$rpBuyer  = wp_insert_user(['user_login' => 'mk_smoke_rp_b_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (is_wp_error($rpSeller) || is_wp_error($rpBuyer)) {
    check('create report fixtures', false, 'user creation failed');
} else {
    wp_set_current_user(0);
    $svc = new MK\Report\Service();

    $ro = wc_create_order(['customer_id' => $rpBuyer, 'status' => 'pending']);
    $ro->update_meta_data('_mk_creator_id', $rpSeller);
    $ro->update_meta_data('_mk_product_amount', 3000);
    $ro->update_meta_data('_mk_option_amount', 0);
    $ro->save();
    $roId = $ro->get_id();

    $ro->update_status(MK\Order\Statuses::RECEIVED, 'smoke');
    check('transfer queued before report',
        as_has_scheduled_action('mk_execute_transfer', ['order_id' => $roId], 'mk-marketplace'));

    $rid = $svc->open($rpBuyer, MK\Report\Service::TARGET_ORDER, $roId, 'not_arrived', '届きません');
    check('report created', $rid > 0, 'id ' . $rid);
    check('order flagged',
        wc_get_order($roId)->get_meta(MK\Report\Service::ORDER_FLAG) === 'yes');
    // The whole point: money must stop.
    check('transfer cancelled',
        !as_has_scheduled_action('mk_execute_transfer', ['order_id' => $roId], 'mk-marketplace'));
    check('auto-complete cancelled',
        !as_has_scheduled_action('mk_auto_complete_order', ['order_id' => $roId], 'mk-marketplace'));

    // Even forced, the payout must refuse while a report is open.
    MK\Schedule\Jobs::runTransfer($roId);
    check('forced payout refused',
        wc_get_order($roId)->get_meta(MK\Stripe\TransferService::META_TRANSFER_ID) === '',
        wc_get_order($roId)->get_meta(MK\Stripe\TransferService::META_TRANSFER_ID) ?: 'not paid');

    check('duplicate report blocked',
        $svc->alreadyReported($rpBuyer, MK\Report\Service::TARGET_ORDER, $roId));

    // A second, unrelated report on the same order.
    $rid2 = $svc->open($rpSeller, MK\Report\Service::TARGET_ORDER, $roId, 'nuisance', '');
    $svc->resolve($rid, 1, '確認済み', true);
    check('hold survives while another is open',
        wc_get_order($roId)->get_meta(MK\Report\Service::ORDER_FLAG) === 'yes');

    $svc->resolve($rid2, 1, '解決', true);
    check('hold lifts on the last one',
        wc_get_order($roId)->get_meta(MK\Report\Service::ORDER_FLAG) === 'no',
        wc_get_order($roId)->get_meta(MK\Report\Service::ORDER_FLAG));
    check('transfer re-queued',
        as_has_scheduled_action('mk_execute_transfer', ['order_id' => $roId], 'mk-marketplace'));

    // Resolving without release must leave the money frozen.
    $rid3 = $svc->open($rpBuyer, MK\Report\Service::TARGET_ORDER, $roId, 'damaged', '');
    $svc->resolve($rid3, 1, '返金対応', false);
    check('no-release keeps it frozen',
        !as_has_scheduled_action('mk_execute_transfer', ['order_id' => $roId], 'mk-marketplace'));

    check('invalid reason rejected', (function () use ($svc, $rpBuyer, $roId) {
        try { $svc->open($rpBuyer, MK\Report\Service::TARGET_ORDER, $roId, 'nonsense', ''); return false; }
        catch (Throwable $e) { return true; }
    })());

    global $wpdb;
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $roId], 'mk-marketplace');
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mk_reports WHERE target_id = %d", $roId));
    wc_get_order($roId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($rpSeller); wp_delete_user($rpBuyer);
    check('report fixtures removed', !wc_get_order($roId) && !get_userdata($rpSeller));
}

echo "\n=== チャージバック（第16条7〜9項） ===\n";
$cbSeller = wp_insert_user(['user_login' => 'mk_smoke_cb_s_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$cbBuyer  = wp_insert_user(['user_login' => 'mk_smoke_cb_b_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (is_wp_error($cbSeller) || is_wp_error($cbBuyer)) {
    check('create chargeback fixtures', false, 'user creation failed');
} else {
    $muteCb = static fn (): bool => false;
    add_filter('mk_should_notify', $muteCb, 99);

    $cbOrder = wc_create_order(['customer_id' => $cbBuyer, 'status' => 'pending']);
    $cbOrder->update_meta_data('_mk_creator_id', $cbSeller);
    $cbOrder->update_meta_data('_mk_product_amount', 5000);
    $cbOrder->update_meta_data('_mk_option_amount', 0);
    $cbOrder->update_meta_data(MK\Stripe\PaymentService::META_CHARGE_ID, 'ch_smoke_' . wp_rand(1000, 9999));
    $cbOrder->save();
    $cbId = $cbOrder->get_id();
    $cbOrder->update_status(MK\Order\Statuses::RECEIVED, 'smoke');

    check('送金は予約されている', as_has_scheduled_action('mk_execute_transfer', ['order_id' => $cbId], 'mk-marketplace'));

    // Stripe の dispute オブジェクトの形だけを真似る。
    $dispute = (object) [
        'id'                   => 'dp_smoke_1',
        'charge'               => (string) $cbOrder->get_meta(MK\Stripe\PaymentService::META_CHARGE_ID),
        'amount'               => 5000,
        'status'               => 'needs_response',
        'balance_transactions' => [(object) ['fee' => 1500, 'amount' => -5000]],
    ];

    check('Stripeが知らせた手数料を使う', MK\Order\Chargeback::feeFrom($dispute) === 1500,
        (string) MK\Order\Chargeback::feeFrom($dispute));
    check('手数料が分からないときは1,500円', MK\Order\Chargeback::feeFrom((object) ['id' => 'dp_x']) === 1500);
    check('状況は日本語で伝える',
        str_contains(MK\Order\Chargeback::statusLabel('lost'), 'チャージバックが確定')
        && str_contains(MK\Order\Chargeback::statusLabel('won'), '取り消され'));

    MK\Order\Chargeback::record(wc_get_order($cbId), $dispute);
    $cbOrder = wc_get_order($cbId);
    check('チャージバックの内容を取引に記録する',
        $cbOrder->get_meta(MK\Order\Chargeback::META_ID) === 'dp_smoke_1'
        && (int) $cbOrder->get_meta(MK\Order\Chargeback::META_AMOUNT) === 5000
        && (int) $cbOrder->get_meta(MK\Order\Chargeback::META_FEE) === 1500);

    // 確定：代金はもう戻せない。誰の負担かを記録するだけ。
    $dispute->status = 'lost';
    MK\Order\Chargeback::onChanged(wc_get_order($cbId), $dispute);
    $cbNotes = wc_get_order_notes(['order_id' => $cbId, 'limit' => 20]);
    check('確定したら運営に対応を促す記録が残る',
        (bool) array_filter($cbNotes, static fn ($n): bool => str_contains($n->content, 'チャージバックが確定しました')));

    $cbLedger = new MK\Ledger\Recorder();
    check('確定しただけでは請求しない', $cbLedger->outstanding($cbSeller) === 0,
        (string) $cbLedger->outstanding($cbSeller));

    // 原則どおりクリエイター負担で記録する（送金前なので巻き戻しはない）。
    // 決済手数料は Stripe に問い合わせるが、このテストの取引は Stripe 上に存在
    // しない。取得できなくても返金処理そのものは止まらない、という確認も兼ねる。
    (new MK\Stripe\TransferService())->absorbDispute(wc_get_order($cbId), 1500, true);
    check('手数料が取れなくても処理は止まらない', (bool) array_filter(
        wc_get_order_notes(['order_id' => $cbId, 'limit' => 20]),
        static fn ($n): bool => str_contains($n->content, '決済手数料を Stripe から取得できませんでした')
    ));
    check('クリエイター負担なら未回収額に載る', $cbLedger->outstanding($cbSeller) >= 1500,
        (string) $cbLedger->outstanding($cbSeller));
    check('チャージバック中は送金しない',
        wc_get_order($cbId)->get_meta(MK\Report\Service::ORDER_FLAG) === 'yes');

    // 運営の過失なら、記録は残すがクリエイターには請求しない。
    $cbOrder2 = wc_create_order(['customer_id' => $cbBuyer, 'status' => 'pending']);
    $cbOrder2->update_meta_data('_mk_creator_id', $cbSeller);
    $cbOrder2->update_meta_data('_mk_product_amount', 5000);
    $cbOrder2->update_meta_data('_mk_option_amount', 0);
    $cbOrder2->save();
    $cbId2 = $cbOrder2->get_id();
    $before = $cbLedger->outstanding($cbSeller);
    (new MK\Stripe\TransferService())->absorbDispute(wc_get_order($cbId2), 1500, false);
    check('運営負担ならクリエイターに請求しない', $cbLedger->outstanding($cbSeller) === $before,
        $before . ' -> ' . $cbLedger->outstanding($cbSeller));

    global $wpdb;
    $absorbed = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}mk_creator_ledger WHERE order_id = %d AND entry_type = %s",
        $cbId2,
        MK\Ledger\Recorder::PLATFORM_ABSORBED
    ));
    check('運営負担でも履歴には残る', $absorbed === 1, (string) $absorbed);

    echo "\n=== Stripe管理画面で行われた返金 ===\n";

    $exOrder = wc_create_order(['customer_id' => $cbBuyer, 'status' => 'pending']);
    $exOrder->update_meta_data('_mk_creator_id', $cbSeller);
    $exOrder->update_meta_data('_mk_product_amount', 4000);
    $exOrder->update_meta_data('_mk_option_amount', 0);
    $exCharge = 'ch_smoke_ext_' . wp_rand(1000, 9999);
    $exOrder->update_meta_data(MK\Stripe\PaymentService::META_CHARGE_ID, $exCharge);
    $exOrder->save();
    $exId = $exOrder->get_id();
    $exOrder->update_status(MK\Order\Statuses::RECEIVED, 'smoke');

    check('送金は予約されている', as_has_scheduled_action('mk_execute_transfer', ['order_id' => $exId], 'mk-marketplace'));

    // 当サイトが行った返金は、こちらが記録している。
    $exOrder = wc_get_order($exId);
    $exOrder->update_meta_data(MK\Stripe\PaymentService::META_KNOWN_REFUNDS, ['re_ours_1']);
    $exOrder->save();

    MK\Order\ExternalRefund::onCharge((object) [
        'id'              => $exCharge,
        'amount_refunded' => 4000,
        'refunds'         => (object) ['data' => [(object) ['id' => 're_ours_1', 'amount' => 4000]]],
    ]);
    check('自分で行った返金は警告しない',
        (int) wc_get_order($exId)->get_meta(MK\Order\ExternalRefund::META_AMOUNT) === 0);

    // Stripe の画面から行われた返金。
    MK\Order\ExternalRefund::onCharge((object) [
        'id'              => $exCharge,
        'amount_refunded' => 4000,
        'refunds'         => (object) ['data' => [(object) ['id' => 're_dashboard_1', 'amount' => 4000]]],
    ]);
    $exOrder = wc_get_order($exId);
    check('Stripe側の返金を見つける',
        (int) $exOrder->get_meta(MK\Order\ExternalRefund::META_AMOUNT) === 4000,
        (string) $exOrder->get_meta(MK\Order\ExternalRefund::META_AMOUNT));
    check('見つけたらすぐ送金を止める',
        !as_has_scheduled_action('mk_execute_transfer', ['order_id' => $exId], 'mk-marketplace')
        && $exOrder->get_meta(MK\Report\Service::ORDER_FLAG) === 'yes');
    check('運営に分かるよう取引に記録する', (bool) array_filter(
        wc_get_order_notes(['order_id' => $exId, 'limit' => 20]),
        static fn ($n): bool => str_contains($n->content, 'Stripe の管理画面で返金が行われました')
    ));

    // 返金した直後の通知は、自分の返金の記録が終わるまで判断を待つ。
    $raceOrder = wc_create_order(['customer_id' => $cbBuyer, 'status' => 'pending']);
    $raceOrder->update_meta_data('_mk_creator_id', $cbSeller);
    $raceCharge = 'ch_smoke_race_' . wp_rand(1000, 9999);
    $raceOrder->update_meta_data(MK\Stripe\PaymentService::META_CHARGE_ID, $raceCharge);
    $raceOrder->update_meta_data(MK\Stripe\PaymentService::META_REFUND_STARTED, time());
    $raceOrder->save();
    $raceId = $raceOrder->get_id();

    MK\Order\ExternalRefund::onCharge((object) [
        'id'              => $raceCharge,
        'amount_refunded' => 3000,
        'refunds'         => (object) ['data' => [(object) ['id' => 're_unknown_yet', 'amount' => 3000]]],
    ]);
    check('返金直後は決めつけず、後で見直す',
        (int) wc_get_order($raceId)->get_meta(MK\Order\ExternalRefund::META_AMOUNT) === 0
        && wp_next_scheduled('mk_recheck_external_refund', [$raceId, $raceCharge]) > 0);

    wp_unschedule_hook('mk_recheck_external_refund');
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $cbId], 'mk-marketplace');
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $exId], 'mk-marketplace');
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mk_creator_ledger WHERE user_id = %d", $cbSeller));
    $wpdb->delete($wpdb->prefix . 'mk_creator_balances', ['user_id' => $cbSeller], ['%d']);
    wc_get_order($cbId)->delete(true);
    wc_get_order($cbId2)->delete(true);
    wc_get_order($exId)->delete(true);
    wc_get_order($raceId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($cbSeller); wp_delete_user($cbBuyer);
    remove_filter('mk_should_notify', $muteCb, 99);
    check('チャージバックの後片付け', !wc_get_order($cbId) && !get_userdata($cbSeller));
}

echo "\n=== 取引メッセージ ===\n";
$msSeller = wp_insert_user(['user_login' => 'mk_smoke_ms_s_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$msBuyer  = wp_insert_user(['user_login' => 'mk_smoke_ms_b_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);
$msThird  = wp_insert_user(['user_login' => 'mk_smoke_ms_x_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (is_wp_error($msSeller) || is_wp_error($msBuyer) || is_wp_error($msThird)) {
    check('create message fixtures', false, 'user creation failed');
} else {
    $svc = new MK\Message\Service();

    $mo = wc_create_order(['customer_id' => $msBuyer, 'status' => 'pending']);
    $mo->update_meta_data('_mk_creator_id', $msSeller);
    $mo->save();
    $moId = $mo->get_id();

    $id1 = $svc->send($moId, $msBuyer, '発送はいつ頃になりますか？');
    check('buyer can send', $id1 > 0);
    $id2 = $svc->send($moId, $msSeller, '本日発送いたします。');
    check('creator can reply', $id2 > 0);

    $thread = $svc->forOrder($moId);
    check('thread has both', count($thread) === 2, count($thread) . ' message(s)');
    check('oldest first', (int) $thread[0]->id === $id1);
    check('receiver resolved',
        (int) $thread[0]->receiver_id === $msSeller && (int) $thread[1]->receiver_id === $msBuyer);

    // A stranger must not be able to post into someone else's transaction.
    check('outsider refused', (function () use ($svc, $moId, $msThird) {
        try { $svc->send($moId, $msThird, 'hello'); return false; }
        catch (Throwable $e) { return true; }
    })());
    check('outsider has no counterparty',
        $svc->counterpartyOf(wc_get_order($moId), $msThird) === null);
    check('empty body refused', (function () use ($svc, $moId, $msBuyer) {
        try { $svc->send($moId, $msBuyer, '   '); return false; }
        catch (Throwable $e) { return true; }
    })());

    check('seller has 1 unread', $svc->unreadCount($msSeller) === 1, (string) $svc->unreadCount($msSeller));
    $svc->markRead($moId, $msSeller);
    check('read clears it', $svc->unreadCount($msSeller) === 0);
    check('other side unaffected', $svc->unreadCount($msBuyer) === 1, (string) $svc->unreadCount($msBuyer));

    $long = str_repeat('あ', MK\Message\Service::MAX_LENGTH + 500);
    $svc->send($moId, $msBuyer, $long);
    $last = end($svc->forOrder($moId));
    check('over-long body truncated',
        mb_strlen((string) $last->body) === MK\Message\Service::MAX_LENGTH,
        mb_strlen((string) $last->body) . ' chars');

    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mk_messages WHERE order_id = %d", $moId));
    wc_get_order($moId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($msSeller); wp_delete_user($msBuyer); wp_delete_user($msThird);
    check('message fixtures removed', !wc_get_order($moId) && !get_userdata($msBuyer));
}

echo "\n=== フォロー機能 ===\n";
$fwCreator = wp_insert_user(['user_login' => 'mk_smoke_fw_c_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$fwFan1 = wp_insert_user(['user_login' => 'mk_smoke_fw_1_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);
$fwFan2 = wp_insert_user(['user_login' => 'mk_smoke_fw_2_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (is_wp_error($fwCreator) || is_wp_error($fwFan1) || is_wp_error($fwFan2)) {
    check('create follow fixtures', false, 'user creation failed');
} else {
    $svc = new MK\Follow\Service();

    check('starts at zero', $svc->followerCount($fwCreator) === 0);
    check('follow succeeds', $svc->follow($fwFan1, $fwCreator));
    check('count is 1', $svc->followerCount($fwCreator) === 1, (string) $svc->followerCount($fwCreator));
    check('isFollowing true', $svc->isFollowing($fwFan1, $fwCreator));

    // The UNIQUE index is what makes a double-tap harmless.
    $svc->follow($fwFan1, $fwCreator);
    $svc->follow($fwFan1, $fwCreator);
    check('double-tap does not inflate', $svc->followerCount($fwCreator) === 1,
        (string) $svc->followerCount($fwCreator));

    $svc->follow($fwFan2, $fwCreator);
    check('second follower counted', $svc->followerCount($fwCreator) === 2);

    check('cannot follow self', !$svc->follow($fwCreator, $fwCreator));
    check('cannot follow a non-seller', !$svc->follow($fwFan1, $fwFan2));
    check('cannot follow a ghost', !$svc->follow($fwFan1, 99999999));

    check('following list', $svc->following($fwFan1) === [$fwCreator]);
    check('followers list has both',
        count(array_intersect($svc->followers($fwCreator), [$fwFan1, $fwFan2])) === 2);

    $svc->unfollow($fwFan1, $fwCreator);
    check('unfollow works', !$svc->isFollowing($fwFan1, $fwCreator));
    check('count drops to 1', $svc->followerCount($fwCreator) === 1);

    // A deleted account must not keep being counted.
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($fwFan2);
    check('deleted user purged', $svc->followerCount($fwCreator) === 0,
        (string) $svc->followerCount($fwCreator));

    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mk_follows WHERE creator_id = %d", $fwCreator));
    wp_delete_user($fwCreator); wp_delete_user($fwFan1);
    check('follow fixtures removed', !get_userdata($fwCreator));
}

echo "\n=== 手数料率（納品後に変更できること） ===\n";
check('商品本体 14%', abs(MK\Fee\Settings::productRate() - 0.14) < 0.0001,
    (string) MK\Fee\Settings::productRate());
check('オプション 40%', abs(MK\Fee\Settings::optionRate() - 0.40) < 0.0001,
    (string) MK\Fee\Settings::optionRate());

check('typed as a percentage', abs(MK\Fee\Settings::fromPercent('14.5') - 0.145) < 1e-9);
check('full-width digits accepted', abs(MK\Fee\Settings::fromPercent('１４') - 0.14) < 1e-9);

$rejected = static function (callable $fn): bool {
    try { $fn(); return false; } catch (Throwable) { return true; }
};
check('non-numeric rejected', $rejected(fn() => MK\Fee\Settings::fromPercent('あ')));
check('absurd rate rejected', $rejected(fn() => MK\Fee\Settings::update(0.95, 0.40, 0)));

$feeP = MK\Fee\Settings::productRate();
$feeO = MK\Fee\Settings::optionRate();
$histWas = count(MK\Fee\Settings::history(100));

// An order placed now, then the operator changes the rates.
$atPurchase = MK\Fee\Calculator::fromSettings()->calculate(10000, 1000);
MK\Fee\Settings::update(0.20, 0.30, 0);

check('new rate applies to the next order',
    MK\Fee\Calculator::fromSettings()->calculate(10000, 0)->platformFee === 2000,
    (string) MK\Fee\Calculator::fromSettings()->calculate(10000, 0)->platformFee);

// The promise to the creator: an order already placed is untouched.
$rebuilt = MK\Fee\Calculator::fromSnapshot($atPurchase->productRate, $atPurchase->optionRate)
    ->calculate(10000, 1000);
check('past order keeps the rates it was bought at',
    $rebuilt->platformFee === $atPurchase->platformFee
        && $rebuilt->creatorAmount === $atPurchase->creatorAmount,
    "then={$atPurchase->creatorAmount} now={$rebuilt->creatorAmount}");

check('change written to the history', count(MK\Fee\Settings::history(100)) === $histWas + 1);
$entry = MK\Fee\Settings::history(1)[0];
check('history records old -> new',
    abs((float) $entry->old_product_rate - $feeP) < 0.0001
        && abs((float) $entry->new_product_rate - 0.20) < 0.0001);

MK\Fee\Settings::update($feeP, $feeO, 0);
check('rates restored', abs(MK\Fee\Settings::productRate() - $feeP) < 0.0001);

$wpdb->query("DELETE FROM {$wpdb->prefix}mk_fee_rate_history ORDER BY id DESC LIMIT 2");
check('fee-history fixtures removed', count(MK\Fee\Settings::history(100)) === $histWas);

echo "\n=== オプション価格設定 ===\n";
$opSvc = new MK\Option\Service();
global $wpdb;

$gid1 = $opSvc->createGroup('スモークテスト：ラッピング', 10);
$gid2 = $opSvc->createGroup('スモークテスト：メッセージカード', 20);
check('groups created', $gid1 > 0 && $gid2 > 0, "#$gid1 #$gid2");

$names = array_map(fn($g) => $g->name, $opSvc->activeGroups());
check('appears in active list', in_array('スモークテスト：ラッピング', $names, true));

$opProd = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft',
    'post_title' => 'MK smoke option product']);

// The creator prices them; the platform only named them.
$opSvc->saveForProduct($opProd, [
    $gid1 => ['offered' => true,  'price' => 500],
    $gid2 => ['offered' => true,  'price' => 300],
]);
$offered = $opSvc->offeredFor($opProd);
check('two options offered', count($offered) === 2, count($offered) . ' offered');

// Zero-priced options must not reach the buyer: they would show in the list
// and add nothing to the order.
$opSvc->saveForProduct($opProd, [$gid2 => ['offered' => true, 'price' => 0]]);
check('zero price is not offered', count($opSvc->offeredFor($opProd)) === 1,
    count($opSvc->offeredFor($opProd)) . ' offered');

// Re-saving must update, never accumulate.
$opSvc->saveForProduct($opProd, [$gid1 => ['offered' => true, 'price' => 800]]);
$opSvc->saveForProduct($opProd, [$gid1 => ['offered' => true, 'price' => 900]]);
$rows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}mk_product_options WHERE product_id=%d AND option_group_id=%d",
    $opProd, $gid1));
check('re-save updates in place', $rows === 1, $rows . ' row(s)');
check('price updated', (int) $opSvc->offeredFor($opProd)[0]->price === 900);

// A posted id for a group that does not exist must be ignored, not trusted.
$opSvc->saveForProduct($opProd, [999999 => ['offered' => true, 'price' => 5000]]);
$bogus = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}mk_product_options WHERE product_id=%d AND option_group_id=%d",
    $opProd, 999999));
check('unknown group ignored', $bogus === 0, $bogus . ' row(s)');

// Options are charged at their own, higher rate.
$b = MK\Fee\Calculator::fromSettings()->calculate(3000, 900);
$expectedFee = MK\Support\Money::applyRate(3000, MK\Fee\Settings::productRate())
             + MK\Support\Money::applyRate(900, MK\Fee\Settings::optionRate());
check('option fee rate differs from product',
    $b->total === 3900 && $b->platformFee === $expectedFee && $b->optionFee === 360,
    "total={$b->total} fee={$b->platformFee} creator={$b->creatorAmount}");
check('halves still sum exactly', $b->platformFee + $b->creatorAmount === $b->total);

// The creator's per-product switch: hidden here, still offered elsewhere.
$opProd2 = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft',
    'post_title' => 'MK smoke option product 2']);
$opSvc->saveForProduct($opProd2, [$gid1 => ['offered' => true, 'price' => 700]]);
$opSvc->saveForProduct($opProd,  [$gid1 => ['offered' => false, 'price' => 900]]);
check('creator can hide an option on one product', count($opSvc->offeredFor($opProd)) === 0);
check('the same option stays on another product', count($opSvc->offeredFor($opProd2)) === 1);

$opSvc->saveForProduct($opProd, [$gid1 => ['offered' => true, 'price' => 900]]);
check('re-showing keeps the price', (int) $opSvc->offeredFor($opProd)[0]->price === 900);

// The panel the creator does that in.
check('option panel on the creation form',
    has_action('dokan_new_product_form', ['MK\Option\ProductPanel', 'render']) !== false);
check('option panel on the edit form',
    has_action('dokan_product_edit_after_options', ['MK\Option\ProductPanel', 'render']) !== false);

ob_start(); MK\Option\ProductPanel::render(null, $opProd); $panel1 = (string) ob_get_clean();
ob_start(); MK\Option\ProductPanel::render(null, $opProd); $panel2 = (string) ob_get_clean();
check('panel offers a per-product 表示する toggle',
    str_contains($panel1, '表示する') && str_contains($panel1, 'mk_option['));
check('panel drawn once, not twice on the edit page', $panel2 === '');

// Retiring a group hides it from new listings but keeps old orders legible.
$opSvc->deactivateGroup($gid1);
check('retired group not offered', count($opSvc->offeredFor($opProd)) === 0);
check('retired group still exists',
    count(array_filter($opSvc->allGroups(), fn($g) => (int) $g->id === $gid1)) === 1);

$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mk_product_options WHERE product_id IN (%d,%d)", $opProd, $opProd2));
wp_delete_post($opProd2, true);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mk_option_groups WHERE id IN (%d,%d)", $gid1, $gid2));
wp_delete_post($opProd, true);
check('option fixtures removed', !get_post($opProd));

echo "\n=== notification layer ===\n";
check('email channel present',
    count(array_filter(MK\Notify\Dispatcher::channels(),
        fn($c) => $c->name() === 'email')) === 1);

// The client asked that LINE be addable later. Prove a new channel can be
// bolted on without touching a single line that raises a notification.
$fake = new class implements MK\Notify\Channel {
    public array $got = [];
    public function name(): string { return 'fake-line'; }
    public function isAvailableFor(int $userId): bool {
        return get_user_meta($userId, 'mk_line_user_id', true) !== '';
    }
    public function send(MK\Notify\Notification $n): bool { $this->got[] = $n; return true; }
};
$GLOBALS['mk_fake_channel'] = $fake;
add_filter('mk_notification_channels', function (array $ch) { $ch[] = $GLOBALS['mk_fake_channel']; return $ch; });

check('channel added by filter', count(MK\Notify\Dispatcher::channels()) === 2,
    count(MK\Notify\Dispatcher::channels()) . ' channels');

$nfUser = wp_insert_user(['user_login' => 'mk_smoke_nf_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($nfUser)) {
    check('create notify fixture', false, $nfUser->get_error_message());
} else {
    $n = new MK\Notify\Notification('test.event', $nfUser, '件名', '本文', '一行', home_url('/'));

    // Not linked yet: the new channel must decline, mail must still go.
    MK\Notify\Dispatcher::send($n);
    check('unlinked user skipped by new channel', count($fake->got) === 0,
        count($fake->got) . ' delivered');

    // Linked: both channels take it, with no change to the caller.
    update_user_meta($nfUser, 'mk_line_user_id', 'U_fake_123');
    MK\Notify\Dispatcher::send($n);
    check('linked user reached by new channel', count($fake->got) === 1);
    check('same notification object', $fake->got[0]->type === 'test.event');
    check('short form carried for LINE', $fake->got[0]->short === '一行');

    // A channel that explodes must not take the transaction down with it.
    $boom = new class implements MK\Notify\Channel {
        public function name(): string { return 'boom'; }
        public function isAvailableFor(int $u): bool { return true; }
        public function send(MK\Notify\Notification $n): bool { throw new RuntimeException('down'); }
    };
    $GLOBALS['mk_boom_channel'] = $boom;
    add_filter('mk_notification_channels', function (array $ch) { $ch[] = $GLOBALS['mk_boom_channel']; return $ch; });

    $before = count($fake->got);
    $threw = false;
    try { MK\Notify\Dispatcher::send($n); } catch (Throwable $e) { $threw = true; }
    check('failing channel does not throw', !$threw);
    check('other channels still delivered', count($fake->got) === $before + 1);

    // The seam a digest would use, to keep LINE's per-message billing down.
    add_filter('mk_should_notify', '__return_false');
    $before = count($fake->got);
    MK\Notify\Dispatcher::send($n);
    check('mk_should_notify can suppress', count($fake->got) === $before);
    remove_filter('mk_should_notify', '__return_false');

    // Unregister both test channels. Left in place they fire on every
    // notification raised by later sections -- harmless, since a throwing
    // channel is caught, but it buries real output under fake failures.
    remove_all_filters('mk_notification_channels');

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($nfUser);
    check('notify fixture removed', !get_userdata($nfUser));
}

echo "\n=== no route into WooCommerce's cart ===\n";
// A client hit "支払い可能な方法がございません" by clicking add-to-cart on the
// shop listing: it reached WooCommerce's checkout, which has no gateway and
// by design never will. Every entrance is now shut, not just that one.
check('listing button filtered',
    has_filter('woocommerce_loop_add_to_cart_link', ['MK\Checkout\Controller', 'loopButton']) !== false);
check('cart additions blocked',
    has_filter('woocommerce_add_to_cart_validation', '__return_false') !== false);
check('cart pages redirect',
    has_action('template_redirect', ['MK\Checkout\Controller', 'blockCartPages']) !== false);
check('single add-to-cart removed',
    has_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart') === false);

$ctProd = get_page_by_path('test-item-2', OBJECT, 'product');
if ($ctProd) {
    $p = wc_get_product($ctProd->ID);
    $html = MK\Checkout\Controller::loopButton('<a class="add_to_cart_button">buy</a>', $p);
    // esc_url() encodes & as &#038;, so compare against the escaped form.
    // A sold item shows 売切れ rather than 詳細を見る; both are product links,
    // which is the property under test.
    check('listing button is a product link',
        !str_contains($html, 'add_to_cart_button')
            && str_contains($html, esc_url(get_permalink($p->get_id()))),
        strip_tags($html));

    // The real guard: even a direct add-to-cart call must fail.
    check('add_to_cart is refused',
        apply_filters('woocommerce_add_to_cart_validation', true, $p->get_id(), 1) === false);
}

// The client's abandoned block-checkout draft should not linger as an order.
$drafts = wc_get_orders(['limit' => 20, 'status' => 'checkout-draft', 'return' => 'ids']);
check('no checkout-draft orders left', count($drafts) === 0, count($drafts) . ' draft(s)');

echo "\n=== a claimed product keeps its page ===\n";
// A reserved product used to 404 -- for the buyer at the checkout most of all.
// The reservation was working; it was indistinguishable from a deleted listing.
check('block cart suppression registered',
    has_filter('render_block', ['MK\Checkout\Controller', 'suppressCartBlocks']) !== false);
check('single pages widened',
    has_action('pre_get_posts', ['MK\Product\Statuses', 'keepSinglePagesVisible']) !== false);
check('pay-now action registered',
    has_filter('woocommerce_my_account_my_orders_actions', ['MK\Checkout\Controller', 'orderActions']) !== false);

foreach (['woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options',
          'woocommerce/mini-cart', 'woocommerce/mini-cart-contents',
          'woocommerce/mini-cart-footer-block'] as $b) {
    check('suppressed: ' . $b,
        MK\Checkout\Controller::suppressCartBlocks('<button>買う</button>', ['blockName' => $b]) === '');
}
// Kept: the loop filter already turns it into a 詳細を見る link, and blanking
// the block removed that too, leaving listing cards with no action at all.
check('product-button kept for the loop filter',
    MK\Checkout\Controller::suppressCartBlocks('<a>詳細を見る</a>',
        ['blockName' => 'woocommerce/product-button']) === '<a>詳細を見る</a>');
check('other blocks untouched',
    MK\Checkout\Controller::suppressCartBlocks('<p>hi</p>', ['blockName' => 'core/paragraph']) === '<p>hi</p>');

// The catalogue must still exclude claimed items; only the single page opens.
$loop = new WP_Query(['post_type' => 'product', 'posts_per_page' => 1]);
MK\Product\Statuses::keepSinglePagesVisible($loop);
check('shop loop not widened', $loop->get('post_status') === '', var_export($loop->get('post_status'), true));

$single = new WP_Query(['post_type' => 'product', 'product' => 'some-slug']);
$single->is_main_query = true;
// is_main_query() compares against the global; call the method directly instead.
$statuses = (function () {
    $q = new WP_Query();
    $q->set('post_type', 'product');
    $q->set('product', 'some-slug');
    return $q;
})();
check('single product query targets the right vars',
    (string) $statuses->get('product') !== '' && (string) $statuses->get('post_type') === 'product');

echo "\n=== creator earnings (Dokan's own figures are always 0 here) ===\n";
$erSeller = wp_insert_user(['user_login' => 'mk_smoke_er_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($erSeller)) {
    check('create earnings seller', false, $erSeller->get_error_message());
} else {
    wp_set_current_user(0);
    $er = new MK\Creator\Earnings();
    check('starts empty', $er->summary($erSeller)['count'] === 0);

    $make = function (string $status, int $total, int $net, array $meta = []) use ($erSeller) {
        $o = wc_create_order();
        $o->update_meta_data('_mk_creator_id', $erSeller);
        $o->update_meta_data('_mk_creator_amount', $net);
        $o->update_meta_data('_mk_platform_fee', $total - $net);
        $o->update_meta_data('_mk_title_snapshot', 'smoke item');
        foreach ($meta as $k => $v) { $o->update_meta_data($k, $v); }
        $o->set_total((string) $total);
        $o->save();
        if ($status !== 'pending') { $o->update_status($status, 'smoke'); }
        return $o->get_id();
    };

    $paidId     = $make(MK\Order\Statuses::PAID, 3000, 2520);
    $recvId     = $make(MK\Order\Statuses::RECEIVED, 8000, 6720);
    $doneId     = $make(MK\Order\Statuses::RECEIVED, 1500, 1260,
                      [MK\Stripe\TransferService::META_TRANSFER_ID => 'tr_smoke']);
    $heldId     = $make(MK\Order\Statuses::RECEIVED, 5000, 4200, ['_mk_has_open_report' => 'yes']);

    $s = $er->summary($erSeller);
    check('counts every sale', $s['count'] === 4, (string) $s['count']);
    check('gross', $s['gross'] === 17500, (string) $s['gross']);
    check('net', $s['net'] === 14700, (string) $s['net']);
    check('commission', $s['commission'] === 2800, (string) $s['commission']);

    // The four states answer "how much" and "when" separately.
    check('already paid', $s['paid'] === 1260, (string) $s['paid']);
    check('scheduled', $s['scheduled'] === 6720, (string) $s['scheduled']);
    check('still in progress', $s['awaiting'] === 2520, (string) $s['awaiting']);
    check('withheld by a report', $s['withheld'] === 4200, (string) $s['withheld']);
    check('states sum to net',
        $s['paid'] + $s['scheduled'] + $s['awaiting'] + $s['withheld'] === $s['net']);

    check('label: 発送待ち', $er->stateLabel(wc_get_order($paidId)) === '発送待ち');
    check('label: 送金待ち', $er->stateLabel(wc_get_order($recvId)) === '送金待ち');
    check('label: 送金済み', $er->stateLabel(wc_get_order($doneId)) === '送金済み');
    check('label: 保留中', str_contains($er->stateLabel(wc_get_order($heldId)), '保留中'));

    // Another creator's sales must never appear here.
    $other = wp_insert_user(['user_login' => 'mk_smoke_er2_' . wp_rand(1000,9999),
        'user_pass' => wp_generate_password(24), 'role' => 'seller']);
    check('scoped to one creator', $er->summary($other)['count'] === 0);

    foreach ([$paidId, $recvId, $doneId, $heldId] as $id) {
        as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $id], 'mk-marketplace');
        as_unschedule_all_actions('mk_auto_complete_order', ['order_id' => $id], 'mk-marketplace');
        wc_get_order($id)->delete(true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($erSeller); wp_delete_user($other);
    check('earnings fixtures removed', !get_userdata($erSeller));
}

echo "\n=== dashboard widgets ===\n";
// Dokan's sales and order widgets read its commission tables, which this
// project never writes to, so they show 0 for a creator who has sold things.
check('reports widget hidden',
    MK\Creator\Onboarding::hideEmptyWidgets(true, 'reports') === false);
check('orders widget hidden',
    MK\Creator\Onboarding::hideEmptyWidgets(true, 'orders') === false);
// This one counts posts, which are real. Removing it would lose good data.
check('products widget kept',
    MK\Creator\Onboarding::hideEmptyWidgets(true, 'products') === true);
check('unknown widgets untouched',
    MK\Creator\Onboarding::hideEmptyWidgets(true, 'something-else') === true);
check('an already-false widget stays false',
    MK\Creator\Onboarding::hideEmptyWidgets(false, 'products') === false);

$wgSeller = wp_insert_user(['user_login' => 'mk_smoke_wg_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$wgBuyer  = wp_insert_user(['user_login' => 'mk_smoke_wgb_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (is_wp_error($wgSeller)) {
    check('create widget fixtures', false, 'user creation failed');
} else {
    $was = get_current_user_id();
    wp_set_current_user($wgSeller);

    ob_start(); MK\Creator\Onboarding::renderDashboardSummary(); $empty = ob_get_clean();
    check('no sales yet reads sensibly', str_contains($empty, 'まだ販売はありません'));

    $o = wc_create_order();
    $o->update_meta_data('_mk_creator_id', $wgSeller);
    $o->update_meta_data('_mk_creator_amount', 2520);
    $o->update_meta_data('_mk_platform_fee', 480);
    $o->set_total('3000');
    $o->save();
    $oId = $o->get_id();
    $o->update_status(MK\Order\Statuses::PAID, 'smoke');

    ob_start(); MK\Creator\Onboarding::renderDashboardSummary(); $html = ob_get_clean();
    check('shows the sale', str_contains($html, '1 件'));
    check('shows the real amount', str_contains($html, '2,520'));
    check('links to the detail page',
        str_contains($html, dokan_get_navigation_url(MK\Creator\Onboarding::PAGE)));

    // A buyer is not a seller and must get nothing here.
    wp_set_current_user($wgBuyer);
    ob_start(); MK\Creator\Onboarding::renderDashboardSummary(); $none = ob_get_clean();
    check('non-seller sees nothing', $none === '');

    wp_set_current_user($was);
    as_unschedule_all_actions('mk_auto_complete_order', ['order_id' => $oId], 'mk-marketplace');
    wc_get_order($oId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($wgSeller); wp_delete_user($wgBuyer);
    check('widget fixtures removed', !get_userdata($wgSeller));
}

echo "\n=== creator numbers: unique, and findable by search ===\n";
global $wpdb;

// The property that matters most. Three accounts once held A00001 because a
// test rolled the counter back after a crashed script left its user behind.
$dupes = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (SELECT meta_value FROM {$wpdb->usermeta}
      WHERE meta_key = 'mk_creator_number' GROUP BY meta_value HAVING COUNT(*) > 1) d");
check('no creator number held twice', $dupes === 0, $dupes . ' duplicated');

// Normalisation: what people actually type.
foreach (['A00001', 'a00001', 'Ａ００００１', 'A-00001', ' A 00001 '] as $in) {
    check('normalises ' . $in, MK\Creator\Numbering::normalise($in) === 'A00001',
        MK\Creator\Numbering::normalise($in));
}
foreach (['A0001', 'I00001', 'マグカップ'] as $in) {
    check('not a number: ' . $in,
        !MK\Creator\Numbering::isCreatorNumber(MK\Creator\Numbering::normalise($in)));
}

$scUser = wp_insert_user(['user_login' => 'mk_smoke_sc_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($scUser)) {
    check('create search fixture', false, $scUser->get_error_message());
} else {
    $seq = get_option('mk_creator_seq');
    $num = MK\Creator\Onboarding::ensureNumber($scUser);

    $found = MK\Creator\Search::findByNumber($num);
    check('found by its own number', $found && $found->ID === $scUser, $num);
    $fw = mb_convert_kana($num, 'A', 'UTF-8');   // back to full-width
    $found = MK\Creator\Search::findByNumber($fw);
    check('found by full-width number', $found && $found->ID === $scUser, $fw);
    check('unknown number finds nobody', MK\Creator\Search::findByNumber('Z99999') === null);

    // The guard: wind the counter back so the next allocation would collide.
    update_option('mk_creator_seq', (string) ((int) get_option('mk_creator_seq') - 1));
    $other = wp_insert_user(['user_login' => 'mk_smoke_sc2_' . wp_rand(1000,9999),
        'user_pass' => wp_generate_password(24), 'role' => 'seller']);
    $second = MK\Creator\Onboarding::ensureNumber($other);
    check('counter behind: still no reuse', $second !== '' && $second !== $num, "$num then $second");

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($scUser); wp_delete_user($other);
    update_option('mk_creator_seq', $seq);
    check('search fixtures removed', !get_userdata($scUser));
}

echo "\n=== listing approval ===\n";
$sel = get_option('dokan_selling', []);
check('approval-based publishing recorded', ($sel['product_status'] ?? '') === 'pending',
    $sel['product_status'] ?? '(unset - resting on a Dokan default)');

$apAdmin = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
$apSeller = wp_insert_user(['user_login' => 'mk_smoke_ap_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($apSeller) || $apAdmin === 0) {
    check('create approval fixtures', false, 'fixture setup failed');
} else {
    $was = get_current_user_id();

    // Capture notifications without sending mail.
    $sent = [];
    $spy = function ($n) use (&$sent) { $sent[] = $n; };
    add_action('mk_notification_sent', $spy);

    // By reference, not an arrow fn: an arrow fn captures $sent by value when
    // it is created, so every call would see the empty snapshot and the
    // "NOT notified" checks below would pass without testing anything.
    $typed = function (string $t) use (&$sent): array {
        return array_values(array_filter($sent, fn($n) => $n->type === $t));
    };

    // An unpayable creator's submission is kept out of the approval queue:
    // approving it could not put it on sale.
    $p1 = wp_insert_post(['post_type' => 'product', 'post_status' => 'pending',
        'post_title' => 'smoke: awaiting approval', 'post_author' => $apSeller,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000']]);
    check('unpayable submission held out of the queue', get_post_status($p1) === 'draft', get_post_status($p1));
    check('operator NOT asked to review it', !$typed('listing.pending'));

    // The scenario that was proven broken: an administrator approving a
    // listing for a creator who cannot yet be paid.
    wp_set_current_user($apAdmin);
    wp_update_post(['ID' => $p1, 'post_status' => 'publish']);
    check('admin approval cannot publish for an unpayable creator',
        get_post_status($p1) !== 'publish', get_post_status($p1));
    check('held for automatic release', get_post_meta($p1, MK\Product\PublishGate::META_HELD, true) === 'yes');
    check('approval remembered for release',
        get_post_meta($p1, MK\Product\PublishGate::META_RELEASE_TO, true) === 'publish');
    check('creator NOT told it is live', !$typed('listing.published'));

    // A second, never-approved submission from the same creator.
    wp_set_current_user($apSeller);
    $p4 = wp_insert_post(['post_type' => 'product', 'post_status' => 'pending',
        'post_title' => 'smoke: submitted before onboarding', 'post_author' => $apSeller,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000']]);
    wp_set_current_user($apAdmin);

    // Onboarding completes: the approved one goes live and the creator hears
    // about it; the unapproved one enters the queue and the operator does.
    update_user_meta($apSeller, MK\Stripe\AccountService::META_STATUS, MK\Stripe\AccountService::STATUS_COMPLETED);
    do_action('mk_creator_onboarding_completed', $apSeller);
    check('approved listing live after onboarding', get_post_status($p1) === 'publish', get_post_status($p1));
    check('creator told the approved listing is live',
        (bool) array_filter($typed('listing.published'), fn($n) => ($n->context['product_id'] ?? 0) === $p1));
    check('unapproved listing enters the queue', get_post_status($p4) === 'pending', get_post_status($p4));
    check('operator now asked to review it',
        (bool) array_filter($typed('listing.pending'), fn($n) => ($n->context['product_id'] ?? 0) === $p4));

    // Once the creator can be paid, approval works normally.
    $sent = [];
    $p2 = wp_insert_post(['post_type' => 'product', 'post_status' => 'pending',
        'post_title' => 'smoke: approvable', 'post_author' => $apSeller,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000']]);
    check('payable submission goes straight to the queue', get_post_status($p2) === 'pending', get_post_status($p2));
    wp_update_post(['ID' => $p2, 'post_status' => 'publish']);
    check('admin approval publishes for a payable creator', get_post_status($p2) === 'publish');
    check('creator told it is live', (bool) $typed('listing.published'));

    // An administrator's own products are not a seller's and are unaffected.
    $p3 = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish',
        'post_title' => 'smoke: admin own', 'post_author' => $apAdmin,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000']]);
    check("admin's own product publishes", get_post_status($p3) === 'publish');

    check('pending count visible', MK\Product\Approval::pendingCount() >= 0);

    // The operator edited three 審査待ち listings and reported the site had
    // not changed. It had not: nothing about a pending listing is public.
    // The edit screen now says so.
    $_GET['post'] = $p4;
    set_current_screen('post');
    $screen = get_current_screen();
    $screen->post_type = 'product';

    ob_start(); MK\Product\Approval::editScreenNotice(); $notice = (string) ob_get_clean();
    check('edit screen warns that a pending listing is not public',
        str_contains($notice, '公開されていません'), trim(wp_strip_all_tags($notice)));

    wp_update_post(['ID' => $p4, 'post_status' => 'publish']);
    ob_start(); MK\Product\Approval::editScreenNotice(); $afterPublish = (string) ob_get_clean();
    check('no warning once it is published', $afterPublish === '');

    // set_current_screen() makes is_admin() true for the rest of the run --
    // it consults $current_screen before the WP_ADMIN constant -- which
    // silently disabled the category-archive guard further down.
    unset($_GET['post'], $GLOBALS['current_screen']);
    check('admin screen state cleaned up', !is_admin());

    remove_action('mk_notification_sent', $spy);
    wp_set_current_user($was);
    foreach ([$p1, $p2, $p3, $p4] as $pid) { wp_delete_post($pid, true); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($apSeller);
    check('approval fixtures removed', !get_userdata($apSeller));
}

echo "\n=== listing form ===\n";
ob_start();
dokan_get_template_part('products/product-brand', '', ['product_brands' => []]);
$brandHtml = (string) ob_get_clean();
check('brand field not rendered', !str_contains($brandHtml, 'product_brand'), trim(substr($brandHtml, 0, 60)));

// The filter must hide only what it names; a neighbouring part still renders.
// Not products/downloadable any more -- that is now hidden too, and a control
// that quietly becomes part of the thing it controls for proves nothing.
ob_start();
dokan_get_template_part('products/inventory', '', ['post_id' => 0, 'class' => '']);
check('other form parts still render', trim((string) ob_get_clean()) !== '');

$sel = get_option('dokan_selling', []);
check('single listing form (quick-add popup off)', ($sel['disable_product_popup'] ?? '') === 'on',
    $sel['disable_product_popup'] ?? '(unset)');

// Creating and editing must open the same screen: the PHP form every
// creator-facing feature is built on. Dokan's React editor has none of it.
$app = get_option('dokan_appearance', []);
check('one product editor (the one with our panels)',
    ($app['vendor_product_editor'] ?? '') !== 'latest', $app['vendor_product_editor'] ?? '(unset)');

$editUrl = dokan_edit_product_url(494);
check('edit link goes to that form', is_string($editUrl) && str_contains($editUrl, 'action=edit'),
    (string) $editUrl);

echo "\n=== category archives are not the seller dashboard ===\n";

// A term id and a page id are unrelated numbers from different tables, and
// Dokan compares them without checking which was queried. Four category
// pages rendered the dashboard shell -- blank, to a logged-out shopper.
$dashPage = (int) (get_option('dokan_pages')['dashboard'] ?? 0);
$collision = get_terms([
    'taxonomy'   => 'product_cat',
    'hide_empty' => false,
    'include'    => [$dashPage],
]);

check('the id collision this guards against still exists',
    !is_wp_error($collision) && count($collision) === 1,
    'term #' . $dashPage . ' ' . (is_wp_error($collision) || !$collision ? '(none)' : $collision[0]->name));

if (!is_wp_error($collision) && $collision) {
    $term = $collision[0];

    global $wp_query;
    $realQuery = $wp_query;

    $fake = new WP_Query();
    $fake->queried_object    = $term;
    $fake->queried_object_id = $term->term_id;
    $wp_query = $fake;

    $treatedAsDashboard = dokan_is_seller_dashboard();

    $wp_query = $realQuery;

    check('a category archive is not treated as the dashboard', !$treatedAsDashboard,
        $treatedAsDashboard ? 'term ' . $term->term_id . ' hijacked by the dashboard' : '');
}

// The dashboard page itself must still be recognised.
global $wp_query;
$realQuery = $wp_query;
$page      = get_post($dashPage);

if ($page instanceof WP_Post) {
    $fake = new WP_Query();
    $fake->queried_object    = $page;
    $fake->queried_object_id = $page->ID;
    $wp_query = $fake;

    $isDashboard = dokan_is_seller_dashboard();

    $wp_query = $realQuery;

    check('the dashboard page is still the dashboard', $isDashboard);
}

echo "\n=== 新規登録ページ ===\n";
$regPage = (int) get_option(MK\Account\Registration::PAGE_OPTION);
check('sign-up page exists', $regPage > 0 && get_post_status($regPage) === 'publish',
    $regPage > 0 ? get_post_status($regPage) : '(no page)');
check('sign-up page carries the shortcode',
    str_contains((string) get_post_field('post_content', $regPage), '[mk_register]'));

// The page is recreated if it is ever trashed, like the checkout page.
wp_update_post(['ID' => $regPage, 'post_status' => 'draft']);
MK\Account\Registration::ensurePage();
$healed = (int) get_option(MK\Account\Registration::PAGE_OPTION);
check('a trashed sign-up page comes back', $healed > 0 && get_post_status($healed) === 'publish');

if ($healed !== $regPage) {
    wp_delete_post($healed, true);
    update_option(MK\Account\Registration::PAGE_OPTION, $regPage);
}

wp_update_post(['ID' => $regPage, 'post_status' => 'publish']);

// Logged out, the form must carry everything WooCommerce's own handler
// needs, or the page would look right and register nobody.
$was = get_current_user_id();
wp_set_current_user(0);
$form = MK\Account\Registration::render();
wp_set_current_user($was);

check('form posts what WooCommerce expects',
    str_contains($form, 'name="email"')
        && str_contains($form, 'name="register"')
        && str_contains($form, 'woocommerce-register-nonce'));
check('password is chosen at sign-up, not emailed',
    get_option('woocommerce_registration_generate_password') === 'no',
    (string) get_option('woocommerce_registration_generate_password'));
check('sign-up links to login', str_contains($form, (string) wc_get_page_permalink('myaccount')));

// Logged in, it must not offer a second account.
wp_set_current_user(1);
$already = MK\Account\Registration::render();
wp_set_current_user($was);
check('already logged in: no second form', !str_contains($already, 'name="register"'));

check('login page links to sign-up',
    str_contains((string) file_get_contents(get_stylesheet_directory() . '/woocommerce/myaccount/form-login.php'), 'mk-auth__switch'));

echo "\n=== 利用規約への同意 ===\n";

$termsPage = MK\Account\Terms::pageId();
check('利用規約のページがある', $termsPage > 0 && get_post_status($termsPage) === 'publish',
    $termsPage > 0 ? get_the_title($termsPage) : '(未設定)');
check('購入者も出品者も同じ利用規約', MK\Account\Terms::url('customer') === MK\Account\Terms::url('seller')
    && MK\Account\Terms::url() !== '');
check('プライバシーポリシーが公開されている', MK\Account\Terms::privacyUrl() !== '',
    MK\Account\Terms::privacyUrl() ?: '(未公開)');

ob_start(); MK\Account\Terms::renderCheckbox(); $box = (string) ob_get_clean();
check('同意チェックボックスがある', str_contains($box, 'name="mk_terms_agree"'));
check('利用規約にリンク', str_contains($box, MK\Account\Terms::url()) && str_contains($box, '>利用規約</a>'));
ob_start(); MK\Account\Terms::renderMigrationCheckbox(); $migrationBox = (string) ob_get_clean();
check('出品者登録画面も同じ利用規約', str_contains($migrationBox, MK\Account\Terms::url()) && str_contains($migrationBox, 'name="mk_terms_agree"'));
check('プライバシーポリシーにもリンク', str_contains($box, MK\Account\Terms::privacyUrl()));

// The check that decides: a browser's `required` attribute is a hint, and
// a registration that skipped it must not be possible.
unset($_POST['mk_terms_agree']);
$errors = new WP_Error();
MK\Account\Terms::validate('u', 'u@example.invalid', $errors);
check('未同意なら登録を拒否する', $errors->has_errors(), $errors->get_error_message('mk_terms_required'));

$_POST['mk_terms_agree'] = '1';
$ok = new WP_Error();
MK\Account\Terms::validate('u', 'u@example.invalid', $ok);
check('同意すれば通る', !$ok->has_errors());
unset($_POST['mk_terms_agree']);

// What was agreed to has to survive on the account.
$termsUser = wp_insert_user(['user_login' => 'mk_smoke_terms_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (!is_wp_error($termsUser)) {
    MK\Account\Terms::stamp($termsUser, 'seller');

    check('同意の記録が残る',
        get_user_meta($termsUser, MK\Account\Terms::META_AGREED_AT, true) !== ''
            && get_user_meta($termsUser, MK\Account\Terms::META_AGREED_ROLE, true) === 'seller'
            && (int) get_user_meta($termsUser, MK\Account\Terms::META_AGREED_PAGE, true) === MK\Account\Terms::pageId('seller'));
    check('その時点の規約の更新日も残る',
        get_user_meta($termsUser, MK\Account\Terms::META_PAGE_EDITED, true) !== '');

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($termsUser);
}

// Becoming a seller later is agreeing to the seller terms.
$_POST['dokan_migration'] = 'Become a Vendor';
MK\Account\Terms::guardMigration();
check('未同意の出品者登録は止まる', !isset($_POST['dokan_migration']));

$_POST['dokan_migration'] = 'Become a Vendor';
$_POST['mk_terms_agree']  = '1';
MK\Account\Terms::guardMigration();
check('同意していれば進む', isset($_POST['dokan_migration']));
unset($_POST['dokan_migration'], $_POST['mk_terms_agree']);

echo "\n=== LINE rich-menu links ===\n";
$lnBuyer  = wp_insert_user(['user_login' => 'mk_smoke_lnb_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);
$lnSeller = wp_insert_user(['user_login' => 'mk_smoke_lns_' . wp_rand(1000,9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($lnBuyer) || is_wp_error($lnSeller)) {
    check('create LINE fixtures', false, 'fixture setup failed');
} else {
    $account = wc_get_page_permalink('myaccount');

    foreach (array_keys(MK\Line\Links::destinations()) as $slug) {
        $u = MK\Line\Links::resolve($slug, 0);
        check("/line/$slug/ resolves for a visitor", is_string($u) && str_starts_with($u, home_url()), (string) $u);
    }

    check('unknown slug refused', MK\Line\Links::resolve('nope', 0) === null);
    check('出品する: visitor -> login', MK\Line\Links::resolve('sell', 0) === $account);
    check('出品する: buyer -> become a seller',
        str_contains((string) MK\Line\Links::resolve('sell', $lnBuyer), 'account-migration'));
    check('出品する: seller -> listing form',
        str_contains((string) MK\Line\Links::resolve('sell', $lnSeller), 'new-product'));
    check('受取設定: seller -> payouts',
        str_contains((string) MK\Line\Links::resolve('payouts', $lnSeller), MK\Creator\Onboarding::PAGE));
    check('購入履歴: buyer -> orders',
        str_contains((string) MK\Line\Links::resolve('orders', $lnBuyer), 'orders'));
    check('creator -> home lookup anchor',
        str_ends_with((string) MK\Line\Links::resolve('creator', 0), '#creator-search'));

    // A stale cookie from someone else's tap must not hijack an ordinary login.
    $_COOKIE['mk_line_after_login'] = 'https://evil.example/';
    check('menu alias resolves the same', str_contains(MK\Line\Links::menuUrl('sell'), '/go/sell/'),
        MK\Line\Links::menuUrl('sell'));

    check('tampered return cookie ignored', MK\Line\Links::afterLogin('/x') === '/x');
    $_COOKIE['mk_line_after_login'] = 'sell';
    check('return cookie goes back through /line/',
        @MK\Line\Links::afterLogin('/x') === MK\Line\Links::url('sell'));
    unset($_COOKIE['mk_line_after_login']);

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($lnBuyer); wp_delete_user($lnSeller);
    check('LINE fixtures removed', !get_userdata($lnBuyer) && !get_userdata($lnSeller));
}

echo "\n=== 状態ランク・発送目安（出品フォーム／商品ページ） ===\n";

$conditions = MK\Product\Details::conditions();
check('状態ランクは A〜D の4段階', array_keys($conditions) === ['A', 'B', 'C', 'D'],
    implode(',', array_keys($conditions)));
check('A の表記が依頼どおり',
    $conditions['A']['label'] === 'A｜非常に良い'
        && $conditions['A']['description'] === '使用感がほとんどなく、目立つ傷・汚れがない状態');
check('D の表記が依頼どおり',
    $conditions['D']['label'] === 'D｜傷・汚れあり'
        && $conditions['D']['description'] === '目立つ傷・汚れ・使用感などあり');

$dispatch = MK\Product\Details::dispatchOptions();
check('発送目安は3種類', array_keys($dispatch) === ['1-2', '2-3', '4-7'],
    implode(',', array_keys($dispatch)));
check('1〜2日 は最長2日として期限を計算', MK\Product\Details::dispatchDays('1-2') === 2);
check('4〜7日 は最長7日として期限を計算', MK\Product\Details::dispatchDays('4-7') === 7);
// Listings made before this feature existed still have to produce a deadline,
// or their orders would sit with no clock at all.
check('未設定の商品も既定値で期限を持つ',
    MK\Product\Details::dispatchDays('') === MK\Product\Details::dispatchDays(MK\Product\Details::DEFAULT_DISPATCH));

ob_start(); MK\Product\Details::renderFields(null, 0); $form = (string) ob_get_clean();
check('出品フォームに状態ランクの選択がある', str_contains($form, 'name="mk_condition"'));
check('出品フォームに発送日数の選択がある', str_contains($form, 'name="mk_dispatch"'));
check('状態ランクの説明も選択肢に出る', str_contains($form, '通常使用には問題ない状態'));
check('発送期限の意味がフォームに書いてある', str_contains($form, 'キャンセルを申請できます'));

$detailProduct = wp_insert_post([
    'post_title'  => 'mk smoke 状態ランク',
    'post_type'   => 'product',
    'post_status' => 'draft',
]);

if (is_wp_error($detailProduct)) {
    check('create detail fixture', false, $detailProduct->get_error_message());
} else {
    update_post_meta($detailProduct, MK\Product\Details::META_CONDITION, 'B');
    update_post_meta($detailProduct, MK\Product\Details::META_DISPATCH, '4-7');

    check('保存した状態ランクを読み戻せる', MK\Product\Details::conditionOf($detailProduct) === 'B');
    check('保存した発送目安を読み戻せる', MK\Product\Details::dispatchOf($detailProduct) === '4-7');

    // Anything not in our list is not a grade, whoever posted it.
    update_post_meta($detailProduct, MK\Product\Details::META_CONDITION, 'Z');
    check('知らない状態ランクは無視する', MK\Product\Details::conditionOf($detailProduct) === '');
    update_post_meta($detailProduct, MK\Product\Details::META_CONDITION, 'B');

    $GLOBALS['product'] = wc_get_product($detailProduct);
    ob_start(); MK\Product\Details::renderOnProduct(); $page = (string) ob_get_clean();
    unset($GLOBALS['product']);

    check('商品ページに状態ランクが出る', str_contains($page, 'B｜良好'));
    check('商品ページに説明文も出る', str_contains($page, '目立つ傷・汚れが少なく'));
    check('商品ページに発送目安が出る', str_contains($page, '4〜7日で発送'));

    wp_delete_post($detailProduct, true);
}

echo "\n=== 発送期限・期限超過・キャンセル申請 ===\n";

// Notifications are suppressed for the duration: this runs against the real
// site, and a smoke test must not post mail to real creators and buyers.
$muted = static fn (): bool => false;
add_filter('mk_should_notify', $muted, 99);

$lateSeller = wp_insert_user(['user_login' => 'mk_smoke_late_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

$deadlineOrder = wc_create_order();

if (is_wp_error($lateSeller) || is_wp_error($deadlineOrder)) {
    check('create deadline fixtures', false, 'fixture setup failed');
} else {
    $deadlineOrder->update_meta_data('_mk_creator_id', $lateSeller);
    $deadlineOrder->update_meta_data('_mk_title_snapshot', 'mk smoke 発送期限');
    $deadlineOrder->update_meta_data(MK\Order\DispatchDeadline::META_DISPATCH, '1-2');
    $deadlineOrder->set_status(MK\Order\Statuses::PAID);
    $deadlineOrder->save();

    $deadlineId = $deadlineOrder->get_id();

    MK\Order\DispatchDeadline::start($deadlineOrder);
    $deadlineOrder = wc_get_order($deadlineId);

    $due = strtotime((string) $deadlineOrder->get_meta(MK\Order\DispatchDeadline::META_DUE_AT) . ' UTC');

    check('支払い確認から約2日後が期限', abs($due - (time() + 2 * DAY_IN_SECONDS)) < 120,
        gmdate('Y-m-d H:i', $due));
    check('期限の見張りが予約されている',
        as_next_scheduled_action(MK\Schedule\Jobs::DISPATCH_OVERDUE, ['order_id' => $deadlineId], 'mk-marketplace') !== false);
    check('期限前は超過扱いにならない', !MK\Order\DispatchDeadline::isOverdue($deadlineOrder));

    // Wind the deadline back into the past: the alarm is what we are testing,
    // not Action Scheduler's ability to count to two days.
    $deadlineOrder->update_meta_data(MK\Order\DispatchDeadline::META_DUE_AT, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
    $deadlineOrder->save();

    check('期限を過ぎれば超過扱いになる', MK\Order\DispatchDeadline::isOverdue($deadlineOrder));

    $before = MK\Order\DispatchDeadline::lateCount($lateSeller);

    MK\Order\DispatchDeadline::markOverdue($deadlineOrder);
    $deadlineOrder = wc_get_order($deadlineId);

    check('超過を記録する', $deadlineOrder->get_meta(MK\Order\DispatchDeadline::META_OVERDUE_AT) !== '');
    check('出品者の遅延件数が1件増える',
        MK\Order\DispatchDeadline::lateCount($lateSeller) === $before + 1,
        (string) MK\Order\DispatchDeadline::lateCount($lateSeller));

    // Action Scheduler retries a job whose later steps failed. Counting the
    // same lateness twice would push a creator towards 出品制限 for one parcel.
    MK\Order\DispatchDeadline::markOverdue($deadlineOrder);
    check('再実行しても二重に数えない',
        MK\Order\DispatchDeadline::lateCount($lateSeller) === $before + 1);

    // Rendered as the creator: the panel is theirs, and it refuses to render
    // for anyone else -- which is the reason this line exists.
    wp_set_current_user($lateSeller);
    ob_start(); MK\Order\DispatchDeadline::renderForCreator($deadlineOrder); $creatorView = (string) ob_get_clean();
    wp_set_current_user(0);
    check('出品者側に期限超過の警告が出る', str_contains($creatorView, '発送期限'), '');

    // Shipping late still clears the alarm: the parcel is gone, so there is
    // nothing left for the job to check.
    MK\Schedule\Jobs::cancelDispatchOverdue($deadlineId);
    check('発送登録で期限の見張りが消える',
        as_next_scheduled_action(MK\Schedule\Jobs::DISPATCH_OVERDUE, ['order_id' => $deadlineId], 'mk-marketplace') === false);

    // The cancellation request is a 通報 of this reason; the payout freeze and
    // the operator queue come with it for free.
    check('キャンセル申請の理由が用意されている',
        isset(MK\Report\Service::reasons()['not_shipped']),
        MK\Report\Service::reasonLabel('not_shipped'));

    $reports  = new MK\Report\Service();
    $reportId = $reports->open(1, MK\Report\Service::TARGET_ORDER,
        $deadlineId, 'not_shipped', '【キャンセル申請】smoke test');

    $deadlineOrder = wc_get_order($deadlineId);

    check('申請で送金が保留される',
        $deadlineOrder->get_meta(MK\Report\Service::ORDER_FLAG) === 'yes');
    check('未対応の申請を注文から引ける',
        count($reports->openFor(MK\Report\Service::TARGET_ORDER, $deadlineId)) === 1);

    $reports->resolve($reportId, 1, 'smoke test', false);
    check('運営が閉じれば未対応から消える',
        $reports->openFor(MK\Report\Service::TARGET_ORDER, $deadlineId) === []);

    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'mk_reports', ['id' => $reportId], ['%d']);

        echo "\n=== キャンセル時にクリエイターへ請求される額 ===\n";

    // The bug this guards against: cancelling an order that was never paid
    // out billed the creator the whole value of the sale, as an "unrecovered"
    // amount deducted from their next payout. Undelivered-item cancellations
    // are always pre-payout, so this was the common case, not the edge.
    $unwind = wc_create_order();

    if (is_wp_error($unwind)) {
        check('create unwind fixture', false, 'fixture setup failed');
    } else {
        $unwind->update_meta_data('_mk_creator_id', $lateSeller);
        $unwind->update_meta_data('_mk_creator_amount', 2520);
        $unwind->save();

        check('送金前のキャンセルは出品者に請求しない',
            MK\Stripe\TransferService::unrecoverableShare($unwind, 0) === 0,
            (string) MK\Stripe\TransferService::unrecoverableShare($unwind, 0));

        // Once the money HAS gone out, whatever could not be clawed back is
        // genuinely owed, and that is the case the calculation exists for.
        $unwind->update_meta_data(MK\Stripe\TransferService::META_TRANSFER_ID, 'tr_smoke');
        $unwind->save();

        check('送金後に全額戻れば請求なし',
            MK\Stripe\TransferService::unrecoverableShare($unwind, 2520) === 0);
        check('送金後に一部しか戻らなければ差額を請求',
            MK\Stripe\TransferService::unrecoverableShare($unwind, 1000) === 1520,
            (string) MK\Stripe\TransferService::unrecoverableShare($unwind, 1000));
        check('戻り額が多くてもマイナス請求にはしない',
            MK\Stripe\TransferService::unrecoverableShare($unwind, 9999) === 0);

        $unwind->delete(true);
    }

echo "\n=== 出品制限（運営操作） ===\n";

    check('初期状態は制限なし', !MK\Creator\Restriction::isRestricted($lateSeller));

    $allowed = MK\Creator\Restriction::gate(
        ['post_type' => 'product', 'post_status' => 'publish', 'post_author' => $lateSeller],
        []
    );
    check('制限していない出品者は公開できる', $allowed['post_status'] === 'publish');

    // The listing has to exist as published before the restriction, which is
    // what PublishGate would otherwise prevent for a creator with no Stripe
    // account -- a different rule, tested elsewhere.
    remove_filter('wp_insert_post_data', ['MK\Product\PublishGate', 'gate'], 10);
    remove_filter('wp_insert_post_data', ['MK\Creator\Restriction', 'gate'], 20);

    $liveProduct = wp_insert_post([
        'post_title'  => 'mk smoke 出品制限',
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_author' => $lateSeller,
        'meta_input' => ['_regular_price' => '3000', '_price' => '3000'],
    ]);

    add_filter('wp_insert_post_data', ['MK\Creator\Restriction', 'gate'], 20, 2);

    check('準備：公開中の商品がある', get_post_status($liveProduct) === 'publish',
        (string) get_post_status($liveProduct));

    MK\Creator\Restriction::set($lateSeller, true, 'smoke test');

    check('制限すると公開中の商品が下書きに戻る', get_post_status($liveProduct) === 'draft',
        (string) get_post_status($liveProduct));
    check('下書きに戻した理由が残る',
        get_post_meta($liveProduct, MK\Creator\Restriction::META_BLOCKED, true) === 'yes');

    $blocked = MK\Creator\Restriction::gate(
        ['post_type' => 'product', 'post_status' => 'publish', 'post_author' => $lateSeller],
        []
    );
    check('制限中は新規公開もできない', $blocked['post_status'] === 'draft');

    $pendingBlocked = MK\Creator\Restriction::gate(
        ['post_type' => 'product', 'post_status' => 'pending', 'post_author' => $lateSeller],
        []
    );
    check('審査申請も止まる', $pendingBlocked['post_status'] === 'draft');

    // Existing sales must keep working: cutting off a restricted creator's
    // ability to post a parcel would punish the buyer, not the creator.
    check('制限中でも発送登録は止めない',
        has_action('dokan_order_detail_after_order_items', ['MK\Order\Shipping', 'renderForm']) !== false);

    wp_set_current_user($lateSeller);
    ob_start(); MK\Creator\Restriction::notice(); $restrictNotice = (string) ob_get_clean();
    wp_set_current_user(0);
    check('出品者に制限中であることを伝える', str_contains($restrictNotice, '制限'));

    MK\Creator\Restriction::set($lateSeller, false);
    check('解除できる', !MK\Creator\Restriction::isRestricted($lateSeller));

    add_filter('wp_insert_post_data', ['MK\Product\PublishGate', 'gate'], 10, 2);

    wp_delete_post($liveProduct, true);
    $deadlineOrder->delete(true);

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($lateSeller);

    check('後始末：テスト用の出品者と注文を削除',
        !get_userdata($lateSeller) && !wc_get_order($deadlineId));
}

remove_filter('mk_should_notify', $muted, 99);

echo "\n=== クリエイターが自分の注文を開けること ===\n";

// Dokan grants a vendor their order only if dokan_orders has a row for it.
// Our checkout bypasses the cart that normally writes that row, so no order
// was ever openable by its creator -- 発送登録 included. Found by driving a
// real order through the real creator page.
$syncSeller = get_user_by('login', 'mk_test_creator');
$syncBuyer  = get_user_by('login', 'mk_test_buyer');

if (!$syncSeller || !$syncBuyer) {
    check('sync fixtures', false, 'test creator or buyer missing');
} else {
    $muteSync = static fn (): bool => false;
    add_filter('mk_should_notify', $muteSync, 99);

    $syncProduct = new WC_Product_Simple();
    $syncProduct->set_name('mk smoke 注文の紐付け');
    $syncProduct->set_status('publish');
    $syncProduct->set_regular_price('2000');
    $syncProduct->save();
    $syncProductId = $syncProduct->get_id();
    wp_update_post(['ID' => $syncProductId, 'post_author' => $syncSeller->ID]);

    $syncOrder   = (new MK\Checkout\OrderBuilder())->create(wc_get_product($syncProductId), $syncBuyer);
    $syncOrderId = $syncOrder->get_id();

    global $wpdb;
    $syncRows = static fn (): array => $wpdb->get_results($wpdb->prepare(
        "SELECT seller_id, order_status, net_amount FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d",
        $syncOrderId
    ));

    check('作成した注文がクリエイターに紐付く', dokan_is_seller_has_order($syncSeller->ID, $syncOrderId));
    check('他のユーザーには紐付かない', !dokan_is_seller_has_order($syncBuyer->ID, $syncOrderId));
    check('Dokanが出品者を正しく判定する', dokan_get_seller_id_by_order($syncOrderId) === $syncSeller->ID,
        (string) dokan_get_seller_id_by_order($syncOrderId));

    // The list is a different lookup from the detail page: it filters on the
    // _dokan_vendor_id order meta. With only the table row the creator could
    // open this order from a link but never see it listed.
    check('注文にDokanの出品者IDが入る',
        (int) wc_get_order($syncOrderId)->get_meta('_dokan_vendor_id') === $syncSeller->ID);
    check('クリエイターの注文一覧に出る',
        in_array($syncOrderId, array_map('intval', (array) dokan()->order->all([
            'seller_id' => $syncSeller->ID,
            'return'    => 'ids',
            'limit'     => 50,
        ])), true));

    // Dokan's own sync would also book the sale into its vendor balance,
    // showing a second, withdrawable figure that never matches Stripe.
    check('Dokanの残高には計上しない',
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dokan_vendor_balance WHERE trn_id = %d AND trn_type = 'dokan_orders'",
            $syncOrderId
        )) === 0);

    MK\Order\DokanSync::ensure($syncOrder);
    check('二重に登録しない', count($syncRows()) === 1, (string) count($syncRows()));

    $syncOrder->update_meta_data('_mk_creator_amount', 1720);
    $syncOrder->save();
    $syncOrder->update_status(MK\Order\Statuses::PAID, 'smoke');

    $row = $syncRows()[0] ?? null;
    check('状態がDokan側にも反映される', $row && $row->order_status === 'wc-mk-paid', (string) ($row->order_status ?? '-'));
    check('クリエイターの受取額が入る', $row && (int) $row->net_amount === 1720, (string) ($row->net_amount ?? '-'));

    check('既存注文の登録は重複しない', MK\Order\DokanSync::backfill() >= 0 && count($syncRows()) === 1);

    MK\Schedule\Jobs::cancelDispatchOverdue($syncOrderId);
    (new MK\Product\Reservation())->release($syncProductId);
    $syncOrder->delete(true);
    $wpdb->delete($wpdb->prefix . 'dokan_orders', ['order_id' => $syncOrderId], ['%d']);
    wp_delete_post($syncProductId, true);
    remove_filter('mk_should_notify', $muteSync, 99);

    check('後始末：紐付けテストの注文と商品を削除',
        !wc_get_order($syncOrderId) && !get_post($syncProductId) && count($syncRows()) === 0);
}

echo "\n=== メッセージ動画：種類と設定 ===\n";

$defaults = MK\Product\MessageVideo::defaultTypes();
check('種類は依頼どおり8種類', count($defaults) === 8, (string) count($defaults));
check('1番目は誕生日メッセージ', $defaults[0]['label'] === '誕生日メッセージ');
check('8番目はフリーメッセージ', $defaults[7]['label'] === 'フリーメッセージ');
check('種類の一覧が保存されている', is_array(get_option(MK\Product\MessageVideo::OPTION_TYPES)));
check('動画時間は3択', array_keys(MK\Product\MessageVideo::lengths()) === ['30s', '1m', '3m'],
    implode(',', array_keys(MK\Product\MessageVideo::lengths())));
check('送信期限は3日・7日・14日',
    MK\Product\MessageVideo::deliveryDays('3') === 3
        && MK\Product\MessageVideo::deliveryDays('7') === 7
        && MK\Product\MessageVideo::deliveryDays('14') === 14);

// 運営の一覧編集：種類は消さずに停止する。鍵は名前から作らない。
$cleaned = MK\Product\MessageTypesAdmin::sanitise([
    ['key' => 'birthday', 'label' => '誕生日メッセージ（改）', 'description' => '', 'active' => '1', 'order' => 1],
    ['key' => '', 'label' => '卒業メッセージ', 'description' => '卒業おめでとう', 'active' => '1', 'order' => 2],
]);
$cleanedKeys = array_column($cleaned, 'key');

check('名前を変えても鍵は変わらない', $cleaned[0]['key'] === 'birthday' && $cleaned[0]['label'] === '誕生日メッセージ（改）');
check('新しい種類には新しい鍵', $cleaned[1]['key'] === 'custom_1', $cleaned[1]['key']);
check('一覧から消えた種類は削除せず停止する',
    in_array('cheer', $cleanedKeys, true)
        && !array_values(array_filter($cleaned, static fn ($r): bool => $r['key'] === 'cheer'))[0]['active']);

$videoSeller = get_user_by('login', 'mk_test_creator');
$videoBuyer  = get_user_by('login', 'mk_test_buyer');

if (!$videoSeller || !$videoBuyer) {
    check('video fixtures', false, 'test creator or buyer missing');
} else {
    $muteVideo = static fn (): bool => false;
    add_filter('mk_should_notify', $muteVideo, 99);

    $video = new WC_Product_Simple();
    $video->set_name('mk smoke メッセージ動画');
    $video->set_status('publish');
    $video->set_regular_price('3000');
    $video->save();

    $videoId = $video->get_id();
    wp_update_post(['ID' => $videoId, 'post_author' => $videoSeller->ID]);

    update_post_meta($videoId, MK\Product\MessageVideo::META_KIND, MK\Product\MessageVideo::KIND);
    update_post_meta($videoId, MK\Product\MessageVideo::META_TYPES, ['birthday', 'cheer', 'no_such_type']);
    update_post_meta($videoId, MK\Product\MessageVideo::META_LENGTH, '30s');
    update_post_meta($videoId, MK\Product\MessageVideo::META_DELIVERY, '3');

    check('メッセージ動画だと分かる', MK\Product\MessageVideo::isMessageVideo($videoId));
    check('対応する種類だけが出る（存在しない種類は無視）',
        array_keys(MK\Product\MessageVideo::supportedTypes($videoId)) === ['birthday', 'cheer'],
        implode(',', array_keys(MK\Product\MessageVideo::supportedTypes($videoId))));

    // 運営が種類を停止したら、すべての出品から一斉に消える。
    $retire = static function ($value) {
        $rows = MK\Product\MessageVideo::defaultTypes();

        foreach ($rows as &$row) {
            if ($row['key'] === 'cheer') {
                $row['active'] = false;
            }
        }

        return $rows;
    };
    add_filter('pre_option_' . MK\Product\MessageVideo::OPTION_TYPES, $retire);
    check('停止した種類は出品から消える',
        array_keys(MK\Product\MessageVideo::supportedTypes($videoId)) === ['birthday']);
    remove_filter('pre_option_' . MK\Product\MessageVideo::OPTION_TYPES, $retire);

    // 一点物ではない。何人でも注文できる。
    $videoLock = new MK\Product\Reservation();
    check('1人目が注文しても', $videoLock->lock($videoId, 91001));
    check('2人目も注文できる', $videoLock->lock($videoId, 91002));
    check('売り切れにならない', get_post_status($videoId) === 'publish', (string) get_post_status($videoId));

    echo "\n=== メッセージ動画：購入時のリクエスト ===\n";

    $request = static function (array $post) use ($videoId): string {
        try {
            MK\Product\MessageVideo::validateRequest($videoId, $post + ['mk_request_agree' => '1']);

            return 'ok';
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };

    check('注意事項に同意しないと依頼できない', (static function () use ($videoId): bool {
        try {
            MK\Product\MessageVideo::validateRequest($videoId, ['mk_message_type' => 'birthday', 'mk_request_name' => 'さくら']);

            return false;
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), '注意事項');
        }
    })());
    check('種類を選ばないと購入できない',
        $request(['mk_request_name' => 'さくら']) !== 'ok');
    check('対応していない種類は選べない',
        $request(['mk_message_type' => 'thanks', 'mk_request_name' => 'さくら']) !== 'ok');
    check('お名前は必須',
        $request(['mk_message_type' => 'birthday', 'mk_request_name' => '  ']) !== 'ok');
    check('お名前は20文字まで',
        $request(['mk_message_type' => 'birthday', 'mk_request_name' => str_repeat('あ', 21)]) !== 'ok');
    check('20文字ちょうどは通る',
        $request(['mk_message_type' => 'birthday', 'mk_request_name' => str_repeat('あ', 20)]) === 'ok');
    check('内容は200文字まで',
        $request(['mk_message_type' => 'birthday', 'mk_request_name' => 'さくら', 'mk_request_body' => str_repeat('い', 201)]) !== 'ok');
    check('内容は任意',
        $request(['mk_message_type' => 'birthday', 'mk_request_name' => 'さくら']) === 'ok');

    $GLOBALS['product'] = wc_get_product($videoId);
    wp_set_current_user($videoBuyer->ID);
    ob_start(); MK\Checkout\Controller::renderBuyButton(); $buyForm = (string) ob_get_clean();
    wp_set_current_user(0);
    unset($GLOBALS['product']);

    check('購入画面に種類の選択が出る', str_contains($buyForm, 'name="mk_message_type"'));
    check('対応している種類だけが選べる',
        str_contains($buyForm, '誕生日メッセージ') && !str_contains($buyForm, '感謝メッセージ'));
    check('お名前欄は20文字制限', str_contains($buyForm, 'name="mk_request_name" id="mk_request_name" maxlength="20" required'));
    check('依頼の直前に注意事項を表示する', str_contains($buyForm, 'ご依頼前に必ずご確認ください')
        && str_contains($buyForm, '性的・成人向けの内容'));
    check('無断での共有・転載・拡散の禁止を伝える', str_contains($buyForm, 'SNS等へ投稿・拡散する行為は禁止'));
    check('同意のチェック欄がある', str_contains($buyForm, 'name="mk_request_agree"'));
    check('同意するまで依頼欄は操作できない', substr_count($buyForm, 'disabled data-mk-requires-agree') >= 3,
        (string) substr_count($buyForm, 'disabled data-mk-requires-agree'));
    check('動画にはオプションを出さない', !str_contains($buyForm, 'mk_options[]'));
    check('種類が複数あるときは選ばれていない', !str_contains($buyForm, 'checked required disabled'));
    check('足りない項目は日本語で伝える',
        str_contains($buyForm, 'mk-video-request__error')
        && str_contains($buyForm, 'メッセージの種類を選んでください。')
        && str_contains($buyForm, '呼んでほしいお名前を入力してください。'));
    check('ブラウザ任せの「オプション」表示は使わない', str_contains($buyForm, 'form.noValidate=true;'));
    check('戻るボタンで入力欄が固まらない', str_contains($buyForm, 'pageshow'));

    // 種類が1つだけの出品は、選択肢ではなく確認事項。最初から選んでおく。
    update_post_meta($videoId, MK\Product\MessageVideo::META_TYPES, ['birthday']);
    $soleForm = MK\Product\MessageVideo::renderBuyFields($videoId);
    check('種類が1つなら最初から選ばれている',
        str_contains($soleForm, 'value="birthday" checked required disabled'));
    update_post_meta($videoId, MK\Product\MessageVideo::META_TYPES, ['birthday', 'cheer']);

    ob_start(); MK\Product\MessageVideo::renderFields(null, $videoId); $videoFields = (string) ob_get_clean();
    check('出品画面で出品画像の作成方法を案内する',
        str_contains($videoFields, 'デジタルコンテンツを販売する際の出品画像等')
        && str_contains($videoFields, '上記の画像アップロード欄からアップロードしてください'));

    echo "\n=== メッセージ動画：注文・送信・辞退 ===\n";

    $videoOrder = (new MK\Checkout\OrderBuilder())->create(
        wc_get_product($videoId),
        $videoBuyer,
        [],
        MK\Product\MessageVideo::validateRequest($videoId, [
            'mk_request_agree' => '1',
            'mk_message_type'  => 'birthday',
            'mk_request_name'  => 'さくら',
            'mk_request_body'  => '10歳の誕生日です',
        ])
    );
    $videoOrderId = $videoOrder->get_id();

    check('注文に種類が記録される',
        $videoOrder->get_meta(MK\Product\MessageVideo::META_TYPE_LABEL) === '誕生日メッセージ');
    check('注文にお名前が記録される',
        $videoOrder->get_meta(MK\Product\MessageVideo::META_REQUEST_NAME) === 'さくら');
    check('同意した日時が注文に残る',
        $videoOrder->get_meta(MK\Product\MessageVideo::META_REQUEST_AGREED_AT) !== '');
    check('注文に内容が記録される',
        $videoOrder->get_meta(MK\Product\MessageVideo::META_REQUEST_BODY) === '10歳の誕生日です');
    check('送信期限が注文に記録される', MK\Order\DispatchDeadline::daysFor($videoOrder) === 3,
        (string) MK\Order\DispatchDeadline::daysFor($videoOrder));
    check('期限の表記は「送信」', MK\Order\DispatchDeadline::promiseLabel($videoOrder) === '3日以内に送信'
        && MK\Order\DispatchDeadline::verb($videoOrder) === '送信');

    $videoOrder->set_status(MK\Order\Statuses::PAID);
    $videoOrder->save();
    $videoOrder = wc_get_order($videoOrderId);

    check('支払いで送信期限が始まる', MK\Order\DispatchDeadline::dueAt($videoOrder) !== '');

    // 動画の注文に発送登録は出さない。
    wp_set_current_user($videoSeller->ID);
    ob_start(); MK\Order\Shipping::renderForm($videoOrder); $shipForm = (string) ob_get_clean();
    ob_start(); MK\Order\VideoDelivery::renderForCreator($videoOrder); $creatorPanel = (string) ob_get_clean();
    wp_set_current_user(0);

    check('発送登録は出ない', $shipForm === '');
    check('クリエイターに依頼内容が見える', str_contains($creatorPanel, 'さくら') && str_contains($creatorPanel, '10歳の誕生日です'));
    check('動画の送信欄がある', str_contains($creatorPanel, 'name="mk_video_url"'));
    check('辞退の欄がある', str_contains($creatorPanel, 'name="mk_decline_reason"'));

    check('VimeoのURLは受け付ける',
        MK\Order\VideoDelivery::normaliseUrl('https://vimeo.com/123456789/abcdef1234') !== '');
    check('Vimeoのプレイヤー URLも受け付ける',
        MK\Order\VideoDelivery::normaliseUrl('https://player.vimeo.com/video/123456789') !== '');
    check('http は受け付けない',
        MK\Order\VideoDelivery::normaliseUrl('http://vimeo.com/123456789') === '');
    check('Vimeo以外は受け付けない',
        MK\Order\VideoDelivery::normaliseUrl('https://www.youtube.com/watch?v=123456789') === '');
    check('動画ではないVimeoのページは受け付けない',
        MK\Order\VideoDelivery::normaliseUrl('https://vimeo.com/user123') === '');
    check('偽装したドメインは受け付けない',
        MK\Order\VideoDelivery::normaliseUrl('https://vimeo.com.evil.example/123456789') === '');

    // 辞退：運営の確認待ちになり、期限切れ扱いにしない。
    $declineReport = MK\Order\VideoDelivery::decline($videoOrder, $videoSeller->ID, '不適切な表現が含まれていたため', 'buyer_request');
    $videoOrder = wc_get_order($videoOrderId);

    check('辞退の種類が記録される',
        $videoOrder->get_meta(MK\Order\VideoDelivery::META_DECLINE_KIND) === 'buyer_request');

    check('辞退が記録される', MK\Order\VideoDelivery::isDeclined($videoOrder));
    check('運営への申し出になる',
        (new MK\Report\Service())->find($declineReport)->reason === MK\Order\VideoDelivery::REASON);
    check('送金が保留される', $videoOrder->get_meta(MK\Report\Service::ORDER_FLAG) === 'yes');
    check('辞退は購入者の返品理由に入らない',
        !isset(MK\Report\Service::returnGrounds()[MK\Order\VideoDelivery::REASON]));
    check('辞退の負担は運営が判断する（自動でクリエイター負担にしない）',
        !MK\Report\Service::isSellerFault(MK\Order\VideoDelivery::REASON));

    $videoOrder->update_meta_data(MK\Order\DispatchDeadline::META_DUE_AT, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));
    $videoOrder->save();
    check('辞退中は期限切れにならない', !MK\Order\DispatchDeadline::isOverdue($videoOrder));

    wp_set_current_user($videoBuyer->ID);
    ob_start(); MK\Order\VideoDelivery::renderForBuyer($videoOrder); $buyerDeclined = (string) ob_get_clean();
    ob_start(); MK\Report\Frontend::render($videoOrder); $buyerReportBox = (string) ob_get_clean();
    wp_set_current_user(0);

    check('購入者に辞退と返金の確認中を伝える', str_contains($buyerDeclined, '運営が内容を確認'));
    check('購入者に「通報」の文言を出さない', !str_contains($buyerReportBox, '通報'));

    // 運営が辞退を認めなかった場合：撮影を続ける。期限は再設定。
    (new MK\Report\Service())->resolve($declineReport, 1, 'smoke: 撮影継続', true);
    $videoOrder = wc_get_order($videoOrderId);

    check('辞退を認めなければ撮影に戻る', !MK\Order\VideoDelivery::isDeclined($videoOrder));
    check('期限は再設定される', !MK\Order\DispatchDeadline::isOverdue($videoOrder));

    // 送信 → 購入者に動画と受取完了
    $videoOrder->update_meta_data(MK\Order\VideoDelivery::META_URL, 'https://vimeo.com/123456789/abcdef1234');
    $videoOrder->save();
    $videoOrder->update_status(MK\Order\Statuses::SHIPPED, 'smoke');
    $videoOrder = wc_get_order($videoOrderId);

    wp_set_current_user($videoBuyer->ID);
    ob_start(); MK\Order\VideoDelivery::renderForBuyer($videoOrder); $buyerSent = (string) ob_get_clean();
    ob_start(); MK\Order\Receipt::render($videoOrder); $receiptPanel = (string) ob_get_clean();
    wp_set_current_user(0);

    check('購入者に動画のリンクが出る', str_contains($buyerSent, 'https://vimeo.com/123456789/abcdef1234'));
    check('受取完了ボタンが出る', str_contains($buyerSent, '受取完了') && str_contains($buyerSent, 'mk_receive='));
    check('再配布禁止を表示する', str_contains($buyerSent, '再配布は禁止'));
    check('配送情報の欄は出さない', $receiptPanel === '');

    // 他人には動画のURLを見せない。
    ob_start(); MK\Order\VideoDelivery::renderForBuyer($videoOrder); $stranger = (string) ob_get_clean();
    check('購入者以外には動画を見せない', $stranger === '');

    MK\Schedule\Jobs::cancelDispatchOverdue($videoOrderId);
    MK\Schedule\Jobs::cancelAutoComplete($videoOrderId);

    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'mk_reports', ['id' => $declineReport], ['%d']);

    $videoOrder->delete(true);
    wp_delete_post($videoId, true);

    remove_filter('mk_should_notify', $muteVideo, 99);

    check('後始末：動画テストの注文と商品を削除', !wc_get_order($videoOrderId) && !get_post($videoId));
}

echo "\n=== 在庫のある商品（1点のみ / 複数） ===\n";

$stockSeller = get_user_by('login', 'mk_test_creator');
$stockSeller = $stockSeller ? (int) $stockSeller->ID : 0;

$stocked = new WC_Product_Simple();
$stocked->set_name('mk smoke 在庫3点');
$stocked->set_status('publish');
$stocked->set_regular_price('1000');
$stocked->set_manage_stock(true);
$stocked->set_stock_quantity(3);
$stocked->save();

$stockedId = $stocked->get_id();
wp_update_post(['ID' => $stockedId, 'post_author' => $stockSeller]);

$reservation = new MK\Product\Reservation();

check('在庫管理の商品だと分かる', MK\Product\Reservation::tracksStock($stockedId));
check('在庫数を読める', MK\Product\Reservation::stockOf($stockedId) === 3,
    (string) MK\Product\Reservation::stockOf($stockedId));

check('1人目は買える', $reservation->lock($stockedId, 9001));
check('1点減る', MK\Product\Reservation::stockOf($stockedId) === 2,
    (string) MK\Product\Reservation::stockOf($stockedId));
check('まだ売り切れにならない', get_post_status($stockedId) === 'publish',
    (string) get_post_status($stockedId));

$reservation->lock($stockedId, 9002);
$reservation->lock($stockedId, 9003);

check('在庫が0になる', MK\Product\Reservation::stockOf($stockedId) === 0,
    (string) MK\Product\Reservation::stockOf($stockedId));
check('0になったら自動的に売り切れ',
    get_post_status($stockedId) === MK\Product\Statuses::SOLD,
    (string) get_post_status($stockedId));

// 在庫が尽きたあとに買えてしまうと、出品者は持っていない物を売ることになる。
check('在庫切れ後は買えない', !$reservation->lock($stockedId, 9004));
check('マイナスにならない', MK\Product\Reservation::stockOf($stockedId) === 0,
    (string) MK\Product\Reservation::stockOf($stockedId));

$reservation->release($stockedId);

check('手続きをやめた分は在庫に戻る', MK\Product\Reservation::stockOf($stockedId) === 1,
    (string) MK\Product\Reservation::stockOf($stockedId));
check('在庫が戻れば再び購入できる状態になる', get_post_status($stockedId) === 'publish',
    (string) get_post_status($stockedId));

// 決済確定は在庫を動かさない。確保した時点ですでに1点引いている。
$beforeSold = MK\Product\Reservation::stockOf($stockedId);
$reservation->markSold($stockedId);
check('決済確定で二重に減らさない',
    MK\Product\Reservation::stockOf($stockedId) === $beforeSold,
    (string) MK\Product\Reservation::stockOf($stockedId));

// 一点物の動きは変えていない。ここが壊れると二重販売が起きる。
$oneOff = new WC_Product_Simple();
$oneOff->set_name('mk smoke 一点物');
$oneOff->set_status('publish');
$oneOff->set_regular_price('1000');
$oneOff->save();

$oneOffId = $oneOff->get_id();
wp_update_post(['ID' => $oneOffId, 'post_author' => $stockSeller]);

check('一点物：先に押した人が確保する', $reservation->lock($oneOffId, 9101));
check('一点物：2人目は確保できない', !$reservation->lock($oneOffId, 9102));
check('一点物：手続き中は他の人から購入できない',
    get_post_status($oneOffId) === MK\Product\Statuses::RESERVED,
    (string) get_post_status($oneOffId));

$reservation->markSold($oneOffId);
check('一点物：決済確定で売り切れ',
    get_post_status($oneOffId) === MK\Product\Statuses::SOLD,
    (string) get_post_status($oneOffId));

wp_delete_post($stockedId, true);
wp_delete_post($oneOffId, true);

check('後始末：在庫テスト用の商品を削除', !get_post($stockedId) && !get_post($oneOffId));

echo "\n=== タグの保存 ===\n";

// Dokan は投稿された値を absint() で整数に変換してから付けるので、入力した
// 文字は 0 になって消える。クライアントが「保存されない」と報告した現象。
check('出品者がタグを作れる設定になっている',
    dokan_get_option('product_vendors_can_create_tags', 'dokan_selling') === 'on',
    (string) dokan_get_option('product_vendors_can_create_tags', 'dokan_selling', '(未設定)'));

// その設定はブラウザにも渡らないと、入力そのものが拒否される。
$localized = apply_filters('dokan_localized_args', []);
check('その設定がブラウザにも渡る',
    ($localized['product_vendors_can_create_tags'] ?? '') === 'on',
    (string) ($localized['product_vendors_can_create_tags'] ?? '(未設定)'));

$tagProduct = new WC_Product_Simple();
$tagProduct->set_name('mk smoke タグ');
$tagProduct->set_status('draft');
$tagProduct->set_regular_price('1000');
$tagProduct->save();

$tagProductId = $tagProduct->get_id();

$_POST['dokan_update_product'] = 'Save Product';
$_POST['product_tag'] = ['オーバーサイズ', '古着'];

MK\Product\Tags::saveTyped($tagProductId);

$saved = wp_get_post_terms($tagProductId, 'product_tag', ['fields' => 'names']);
sort($saved);

check('入力した文字がタグとして保存される', $saved === ['オーバーサイズ', '古着'],
    implode(',', $saved));

// 既存タグは ID で送られてくる。混在しても両方付く。
$existing = get_term_by('name', '古着', 'product_tag');
$_POST['product_tag'] = [(string) $existing->term_id, 'ストリート'];

MK\Product\Tags::saveTyped($tagProductId);

$saved = wp_get_post_terms($tagProductId, 'product_tag', ['fields' => 'names']);
sort($saved);

check('選んだタグと入力したタグが混ざっても保存される', $saved === ['ストリート', '古着'],
    implode(',', $saved));

// 空で送れば全部外れる。外せないタグは付けられないのと同じくらい困る。
$_POST['product_tag'] = [''];
MK\Product\Tags::saveTyped($tagProductId);
check('タグを外せる', wp_get_post_terms($tagProductId, 'product_tag', ['fields' => 'names']) === []);

// 出品フォーム以外の保存でタグを書き換えない。
$_POST['product_tag'] = ['勝手に付けたタグ'];
unset($_POST['dokan_update_product']);
MK\Product\Tags::saveTyped($tagProductId);
check('出品フォーム以外からは書き換えない',
    wp_get_post_terms($tagProductId, 'product_tag', ['fields' => 'names']) === []);

unset($_POST['product_tag']);

foreach (['オーバーサイズ', '古着', 'ストリート', '勝手に付けたタグ'] as $name) {
    $term = get_term_by('name', $name, 'product_tag');

    if ($term) {
        wp_delete_term($term->term_id, 'product_tag');
    }
}

wp_delete_post($tagProductId, true);

check('後始末：タグテスト用の商品と用語を削除',
    !get_post($tagProductId)
        && (int) wp_count_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]) === 0);

echo "\n=== 保存と出品の2つのボタン ===\n";

ob_start(); MK\Product\FormGuide::submitButtons(0); $buttons = (string) ob_get_clean();

check('「商品を保存」がある', str_contains($buttons, '商品を保存'));
check('「出品する」がある', str_contains($buttons, '出品する'));
check('保存は下書きにする', str_contains($buttons, 'data-mk-status="draft"'));
check('出品するは審査へ回す',
    str_contains($buttons, 'data-mk-status="pending"')
        || str_contains($buttons, 'data-mk-status="publish"'));
check('押す前は状態を指定しない', str_contains($buttons, 'id="mk_post_status" value=""'));

echo "\n=== 出品フォームの案内 ===\n";

$guideSeller = get_user_by('login', 'mk_test_creator');
$guideSeller = $guideSeller ? (int) $guideSeller->ID : 0;

if ($guideSeller === 0) {
    check('create guide fixture', false, 'no seller to test with');
} else {
    // ラベルは翻訳カタログで変える。テンプレートは一切上書きしていない。
    check('「簡単な説明」を「商品について一言」に',
        __('Short Description', 'dokan-lite') === '商品について一言',
        __('Short Description', 'dokan-lite'));
    check('「商品説明」を「商品の詳しい説明」に',
        __('Description', 'dokan-lite') === '商品の詳しい説明',
        __('Description', 'dokan-lite'));
    check('「タグ」を「商品の特徴・キーワード」に',
        __('Tags', 'dokan-lite') === '商品の特徴・キーワード',
        __('Tags', 'dokan-lite'));
    check('商品番号の重複エラーが日本語になる',
        str_contains(__('Product SKU must be unique', 'dokan-lite'), '重複しない番号'),
        __('Product SKU must be unique', 'dokan-lite'));

    ob_start(); MK\Product\FormGuide::tagHelp(); $tagHelp = (string) ob_get_clean();
    check('タグ欄に何を書くか説明がある', str_contains($tagHelp, '特徴やキーワード'));
    check('タグ欄に記入例がある', str_contains($tagHelp, '例：'));

    ob_start(); MK\Product\FormGuide::inventoryHelp(); $invHelp = (string) ob_get_clean();
    check('在庫は2択で選ばせる',
        str_contains($invHelp, 'value="single"') && str_contains($invHelp, 'value="stock"'));
    check('一点物は在庫数が要らないと書いてある',
        str_contains($invHelp, '在庫数の入力は必要ありません'));
    check('複数在庫の動きを説明している',
        str_contains($invHelp, '0になると自動的に「売り切れ」'));

    // ダウンロード商品・配送なしはフォームから消す。
    check('ダウンロード商品・配送なしのテンプレートを出さない',
        MK\Product\ListingForm::hideParts('/path/to/template.php', 'products/download-virtual') === false);
    check('他のテンプレートには触らない',
        MK\Product\ListingForm::hideParts('/path/to/template.php', 'products/inventory') === '/path/to/template.php');

    $guideProduct = wp_insert_post([
        'post_title'  => 'mk smoke フォーム案内',
        'post_type'   => 'product',
        'post_status' => 'draft',
        'post_author' => $guideSeller,
        'meta_input'  => [
            '_regular_price'     => '3000',
            '_price'             => '3000',
            '_downloadable'      => 'yes',
            '_virtual'           => 'yes',
            '_manage_stock'      => 'yes',
            '_sold_individually' => 'yes',
        ],
    ]);

    if (is_wp_error($guideProduct)) {
        check('create guide product', false, $guideProduct->get_error_message());
    } else {
        MK\Product\FormGuide::forcePhysical($guideProduct);

        // 隠れているだけの項目は REST から立てられる。値ごと落とす。
        check('ダウンロード商品を必ず無効にする',
            get_post_meta($guideProduct, '_downloadable', true) === 'no',
            (string) get_post_meta($guideProduct, '_downloadable', true));
        check('配送なしを必ず無効にする',
            get_post_meta($guideProduct, '_virtual', true) === 'no',
            (string) get_post_meta($guideProduct, '_virtual', true));

        // 在庫管理と「1点のみ」は同時に立たない。
        check('在庫管理を選んだら「1点のみ」は外れる',
            get_post_meta($guideProduct, '_sold_individually', true) === 'no',
            (string) get_post_meta($guideProduct, '_sold_individually', true));
        check('在庫管理はそのまま残る',
            get_post_meta($guideProduct, '_manage_stock', true) === 'yes');

        // 在庫管理を使わない一点物は「1点のみ」を保つ。
        update_post_meta($guideProduct, '_manage_stock', 'no');
        update_post_meta($guideProduct, '_sold_individually', 'yes');
        MK\Product\FormGuide::forcePhysical($guideProduct);

        check('一点物の設定は残す',
            get_post_meta($guideProduct, '_sold_individually', true) === 'yes');

        // 保存後にどこへ行ったかを必ず伝える。
        wp_set_current_user($guideSeller);
        $_GET['message']    = 'success';
        $_GET['product_id'] = (string) $guideProduct;

        foreach (['pending' => '審査待ち', 'publish' => '公開中', 'draft' => '下書き'] as $status => $word) {
            wp_update_post(['ID' => $guideProduct, 'post_status' => $status]);

            ob_start(); MK\Product\FormGuide::savedNotice(); $notice = (string) ob_get_clean();

            check('保存後に「' . $word . '」だと伝える', str_contains($notice, $word), '');
        }

        // 公開中のときだけ、実際のページへのリンクを出す。
        wp_update_post(['ID' => $guideProduct, 'post_status' => 'publish']);
        ob_start(); MK\Product\FormGuide::savedNotice(); $liveNotice = (string) ob_get_clean();
        check('公開中なら商品ページへのリンクを出す',
            str_contains($liveNotice, '公開中の商品ページを見る'));

        // 他人の商品の保存結果は出さない。
        wp_set_current_user(0);
        ob_start(); MK\Product\FormGuide::savedNotice(); $foreign = (string) ob_get_clean();
        check('他人の保存結果は表示しない', $foreign === '');

        unset($_GET['message'], $_GET['product_id']);

        // プレビューから編集画面へ戻れる。出品者本人にだけ出す。
        $GLOBALS['product'] = wc_get_product($guideProduct);

        wp_set_current_user($guideSeller);
        ob_start(); MK\Product\FormGuide::backToEdit(); $ownerBar = (string) ob_get_clean();

        wp_set_current_user(0);
        ob_start(); MK\Product\FormGuide::backToEdit(); $guestBar = (string) ob_get_clean();

        unset($GLOBALS['product']);

        check('出品者には「編集画面に戻る」を出す', str_contains($ownerBar, '編集画面に戻る'));
        check('購入者には出さない', $guestBar === '');

        wp_delete_post($guideProduct, true);
        check('後始末：案内テスト用の商品を削除', !get_post($guideProduct));
    }
}

echo "\n=== 価格未設定の出品は売り物にしない ===\n";

// 客先で出た不具合そのもの：価格のない商品が公開され、購入ボタンは押せるのに
// 何も起きなかった。原因は2つあり、どちらも直している。
$priceSeller = get_user_by('login', 'mk_test_creator');
$priceSeller = $priceSeller ? (int) $priceSeller->ID : 0;

if ($priceSeller === 0) {
    $priceSeller = wp_insert_user(['user_login' => 'mk_smoke_price_' . wp_rand(1000, 9999),
        'user_pass' => wp_generate_password(24), 'role' => 'seller']);
}

check('50円未満は売り物にしない', !MK\Product\PriceGate::isSellable(0));
check('50円は売り物になる', MK\Product\PriceGate::isSellable(MK\Support\Money::MIN_YEN));
check('上限を超える価格は売り物にしない',
    !MK\Product\PriceGate::isSellable(MK\Support\Money::MAX_YEN + 1));

// Saved the way Dokan saves: WC_Product::save(), which writes the post row
// first and the price meta afterwards. A gate that reads the price too early
// sees the value from BEFORE the save -- which is exactly what happened to the
// client's ¥3,000 listing.
$priced = new WC_Product_Simple();
$priced->set_name('mk smoke priced');
$priced->set_status('draft');
$priced->set_regular_price('3000');
$priced->save();

wp_update_post(['ID' => $priced->get_id(), 'post_author' => $priceSeller]);

$pricedId = $priced->get_id();

// wc_get_product() needs WooCommerce's data stores, which do not exist during
// plugin bootstrap -- reading the price through it reported 0 for every
// product and withdrew seven priced listings on the live site.
check('価格はメタから直接読む', MK\Product\PriceGate::priceOf($pricedId) === 3000,
    (string) MK\Product\PriceGate::priceOf($pricedId));

$priced->set_status('publish');
$priced->save();

check('価格のある商品は公開できる', get_post_status($pricedId) === 'publish',
    (string) get_post_status($pricedId));

// The regression itself: price and status set in the SAME save, as the vendor
// form does it. Read a moment too early and this publishes as a draft.
$together = new WC_Product_Simple();
$together->set_name('mk smoke priced in one save');
$together->set_status('publish');
$together->set_regular_price('4500');
$together->save();

wp_update_post(['ID' => $together->get_id(), 'post_author' => $priceSeller]);
$together->set_status('publish');
$together->save();

check('価格と公開を同時に保存しても公開できる',
    get_post_status($together->get_id()) === 'publish',
    (string) get_post_status($together->get_id()));

$free = new WC_Product_Simple();
$free->set_name('mk smoke priceless');
$free->set_status('draft');
$free->save();

wp_update_post(['ID' => $free->get_id(), 'post_author' => $priceSeller]);

$freeId = $free->get_id();

$free->set_status('publish');
$free->save();

check('価格のない商品は公開されない', get_post_status($freeId) === 'draft',
    (string) get_post_status($freeId));
check('保留した理由が残る',
    get_post_meta($freeId, MK\Product\PriceGate::META_HELD, true) === 'yes');

$free->set_status('pending');
$free->save();
check('審査申請も止まる', get_post_status($freeId) === 'draft',
    (string) get_post_status($freeId));

$free->set_regular_price('2000');
$free->set_status('publish');
$free->save();

check('価格を入れれば公開できる', get_post_status($freeId) === 'publish',
    (string) get_post_status($freeId));
check('保留の印は消える', get_post_meta($freeId, MK\Product\PriceGate::META_HELD, true) === '');

// Dokan の内部商品（Reverse Withdrawal Payment）は価格0が正常。これを下書きに
// してしまい、実際に運営サイトで機能を壊した。
$admin = new WC_Product_Simple();
$admin->set_name('mk smoke platform');
$admin->set_status('draft');
$admin->save();

$adminProduct = $admin->get_id();
wp_update_post(['ID' => $adminProduct, 'post_author' => 1]);

check('運営自身の商品は対象外', !MK\Product\PriceGate::applies($adminProduct));

$admin->set_status('publish');
$admin->save();

check('価格0でも運営の商品は公開できる', get_post_status($adminProduct) === 'publish',
    (string) get_post_status($adminProduct));

wp_delete_post($together->get_id(), true);

// 購入できない理由を必ず表示する。表示されないと「ボタンが効かない」に見える。
$GLOBALS['product'] = wc_get_product($pricedId);
$_GET['mk_error'] = '購入手続きを開始できませんでした。';
ob_start(); MK\Checkout\Controller::renderBuyButton(); $withError = (string) ob_get_clean();
unset($_GET['mk_error']);

check('失敗した理由を商品ページに表示する', str_contains($withError, 'mk-error')
    && str_contains($withError, '購入手続きを開始できませんでした。'));

$_GET['mk_error'] = '<script>alert(1)</script>';
ob_start(); MK\Checkout\Controller::renderBuyButton(); $xss = (string) ob_get_clean();
unset($_GET['mk_error']);

check('理由にタグを混ぜられない', !str_contains($xss, '<script>'));
check('空になった理由は表示しない', !str_contains($xss, 'mk-error'));

// A published listing whose price is emptied by some other route must not
// offer a button that cannot work.
update_post_meta($pricedId, '_price', '');
update_post_meta($pricedId, '_regular_price', '');
$GLOBALS['product'] = wc_get_product($pricedId);

ob_start(); MK\Checkout\Controller::renderBuyButton(); $noPrice = (string) ob_get_clean();

check('価格がなければ購入ボタンを出さない', !str_contains($noPrice, 'mk-buy-form'));
check('代わりに理由を書く', str_contains($noPrice, '販売価格が設定されていない'));

unset($GLOBALS['product']);

wp_delete_post($pricedId, true);
wp_delete_post($freeId, true);
wp_delete_post($adminProduct, true);

check('後始末：価格テスト用の商品を削除',
    !get_post($pricedId) && !get_post($freeId) && !get_post($adminProduct));

echo "\n=== 返品・返金のルール ===\n";

$grounds = MK\Report\Service::returnGrounds();

// 購入者都合は申請の理由にならない。フォームに出さないことがルールの実装。
foreach (['nuisance', 'other'] as $notAGround) {
    check('返品理由に入らない：' . $notAGround, !isset($grounds[$notAGround]));
}

foreach (['not_as_described', 'size_mismatch', 'condition_mismatch', 'wrong_item'] as $ground) {
    check('返品理由にある：' . MK\Report\Service::reasonLabel($ground), isset($grounds[$ground]));
}

check('届かない・発送されないも申請できる',
    isset($grounds['not_arrived']) && isset($grounds['not_shipped']));

check('サイズ相違は出品者の責を既定とする',
    MK\Report\Service::isSellerFault('size_mismatch'));
check('状態相違は出品者の責を既定とする',
    MK\Report\Service::isSellerFault('condition_mismatch'));
check('別商品が届いたは出品者の責を既定とする',
    MK\Report\Service::isSellerFault('wrong_item'));
// 配送中の破損と梱包不良は同じ理由で申請される。人が見なければ区別できない。
check('破損は自動で出品者の責としない', !MK\Report\Service::isSellerFault('damaged'));

$policyOrder = wc_create_order();

if (is_wp_error($policyOrder)) {
    check('create policy fixture', false, 'fixture setup failed');
} else {
    $policyBuyer = wp_insert_user(['user_login' => 'mk_smoke_pb_' . wp_rand(1000, 9999),
        'user_pass' => wp_generate_password(24), 'role' => 'customer']);

    $policyOrder->set_customer_id((int) $policyBuyer);
    $policyOrder->set_status(MK\Order\Statuses::PAID);
    $policyOrder->save();

    wp_set_current_user((int) $policyBuyer);
    ob_start(); MK\Report\Frontend::render($policyOrder); $panel = (string) ob_get_clean();
    // Buyers raise problems on the claim page now; the policy is stated there.
    ob_start(); MK\Report\ClaimPage::render(); $form = (string) ob_get_clean();
    wp_set_current_user(0);

    check('注文画面でも購入者都合では返金しないと明記している',
        str_contains($panel, 'ご都合による返品・返金はお受けしておりません'));
    check('購入者都合では返金しないと明記している',
        str_contains($form, 'ご都合による返品・返金はお受けしておりません'));
    check('具体例まで書いてある', str_contains($form, '気が変わった'));
    check('相違がある場合は申し出られると書いてある',
        str_contains($form, '明らかな相違がある場合'));
    check('自動返金ではなく運営が判断すると書いてある',
        str_contains($form, '運営が内容を確認のうえ'));
    check('購入者のフォームに迷惑行為は出さない', !str_contains($form, '>迷惑行為<'));

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($policyBuyer);
    $policyOrder->delete(true);
}

echo "\n=== 事業者申請 ===\n";

check('事業者区分は法人と個人事業主', array_keys(MK\Creator\Business::businessTypes()) === ['corporation', 'sole_proprietor']);

$errorsFor = static fn (array $post, array $files = []): array => MK\Creator\Business::validationErrors($post, $files);

check('区分を選ばないと登録できない', $errorsFor([]) !== []);
check('個人は申請項目なしで登録できる', $errorsFor(['mk_seller_kind' => 'individual']) === []);

$businessPost = [
    'mk_seller_kind' => 'business',
    'mk_business'    => [
        'business_type'  => 'corporation',
        'business_name'  => '株式会社スモーク',
        'representative' => '山田太郎',
        'address'        => '東京都千代田区1-1',
        'phone'          => '0332105555',
        'email'          => 'smoke@example.com',
        'products'       => '古着',
    ],
];

check('必須項目がそろえば申請できる', $errorsFor($businessPost) === [], implode(' / ', $errorsFor($businessPost)));

$missing = $businessPost;
unset($missing['mk_business']['representative']);
check('代表者名がないと申請できない', (bool) array_filter($errorsFor($missing), static fn ($e) => str_contains($e, '代表者名')));

$badMail = $businessPost;
$badMail['mk_business']['email'] = 'not-an-email';
check('メールアドレスの形式を確認する', $errorsFor($badMail) !== []);

$badInvoice = $businessPost;
$badInvoice['mk_business']['invoice_number'] = '1234';
check('インボイス番号の形式を確認する', $errorsFor($badInvoice) !== []);

$kobutsu = $businessPost;
$kobutsu['mk_business']['needs_kobutsu'] = '1';
check('古物商許可が必要なら許可番号と画像が必須', count($errorsFor($kobutsu)) >= 3, (string) count($errorsFor($kobutsu)));

// Licence storage: outside the web root, random name, only real image/PDF content.
check('許可証の保管場所は公開領域の外',
    !str_starts_with(MK\Creator\Business::privateDir(), untrailingslashit(ABSPATH)),
    MK\Creator\Business::privateDir());

$png = wp_tempnam('mk-license.png');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
check('画像ファイルは受け付ける', MK\Creator\Business::fileProblem($png, 'license.png', (int) filesize($png)) === null);

$fake = wp_tempnam('mk-license.png');
file_put_contents($fake, '<?php echo "not an image";');
check('画像を装ったファイルは受け付けない', MK\Creator\Business::fileProblem($fake, 'license.png', (int) filesize($fake)) !== null);
@unlink($fake);

$bizSeller = wp_insert_user(['user_login' => 'mk_smoke_biz_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($bizSeller)) {
    check('business fixture', false, $bizSeller->get_error_message());
} else {
    $muteBiz = static fn (): bool => false;
    add_filter('mk_should_notify', $muteBiz, 99);

    $stored = MK\Creator\Business::storeFile($png, 'license.png', false);
    check('許可証を保存できる', $stored !== '' && (bool) preg_match('/^[a-f0-9]{32}\.png$/', $stored), $stored);
    update_user_meta($bizSeller, MK\Creator\Business::META_LICENSE, $stored);
    check('保存した許可証を運営が参照できる', MK\Creator\Business::licensePath($bizSeller) !== '');

    update_user_meta($bizSeller, MK\Creator\Business::META_LICENSE, '../../wp-config.php');
    check('任意のパスは参照させない', MK\Creator\Business::licensePath($bizSeller) === '');
    update_user_meta($bizSeller, MK\Creator\Business::META_LICENSE, $stored);

    $publish = static fn (int $author): string => MK\Creator\Business::gate(
        ['post_type' => 'product', 'post_status' => 'publish', 'post_author' => $author], []
    )['post_status'];

    check('個人は公開できる', $publish($bizSeller) === 'publish');

    update_user_meta($bizSeller, MK\Creator\Business::META_KIND, MK\Creator\Business::KIND_BUSINESS);
    update_user_meta($bizSeller, MK\Creator\Business::META_STATUS, MK\Creator\Business::STATUS_PENDING);

    check('審査中の事業者は公開できない', $publish($bizSeller) === 'draft');
    check('審査中の事業者は審査申請もできない',
        MK\Creator\Business::gate(['post_type' => 'product', 'post_status' => 'pending', 'post_author' => $bizSeller], [])['post_status'] === 'draft');
    check('審査中は事業者表示を出さない', !MK\Creator\Business::isApproved($bizSeller));

    wp_set_current_user($bizSeller);
    ob_start(); MK\Creator\Business::notice(); $pendingNotice = (string) ob_get_clean();
    wp_set_current_user(0);
    check('審査中であることと目安の期間を伝える', str_contains($pendingNotice, '最大1週間程度'));

    MK\Creator\Business::decide($bizSeller, true, '', 1);

    check('承認した事業者は公開できる', $publish($bizSeller) === 'publish');
    ob_start(); MK\Creator\Business::renderStoreBadge($bizSeller); $badge = (string) ob_get_clean();
    check('承認した事業者にはショップに「事業者」表示', str_contains($badge, '事業者'));

    MK\Creator\Business::decide($bizSeller, false, 'smoke', 1);
    check('承認されなかった事業者は公開できない', $publish($bizSeller) === 'draft');

    check('運営の商品は対象外',
        MK\Creator\Business::gate(['post_type' => 'product', 'post_status' => 'publish', 'post_author' => 1], [])['post_status'] === 'publish');

    @unlink(MK\Creator\Business::privateDir() . '/' . $stored);
    remove_filter('mk_should_notify', $muteBiz, 99);

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($bizSeller);
    check('後始末：事業者テストのユーザーと許可証を削除', !get_userdata($bizSeller) && !is_file(MK\Creator\Business::privateDir() . '/' . $stored));
}

echo "\n=== 既存の出品者による後からの事業者申請 ===\n";

$lateSeller = wp_insert_user(['user_login' => 'mk_smoke_late_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($lateSeller)) {
    check('late business fixture', false, $lateSeller->get_error_message());
} else {
    $muteLate = static fn (): bool => false;
    add_filter('mk_should_notify', $muteLate, 99);

    $B = MK\Creator\Business::class;
    update_user_meta($lateSeller, $B::META_KIND, $B::KIND_INDIVIDUAL);

    $latePublish = static fn (): string => $B::gate(
        ['post_type' => 'product', 'post_status' => 'publish', 'post_author' => $lateSeller], []
    )['post_status'];

    $latePage = static function () use ($B, $lateSeller): string {
        ob_start();
        $B::renderPageBody($lateSeller);

        return (string) ob_get_clean();
    };

    $lateFile = tempnam(sys_get_temp_dir(), 'mkl');
    copy($png, $lateFile);

    $latePost = ['mk_seller_kind' => $B::KIND_BUSINESS, 'mk_business' => [
        'business_type' => 'sole_proprietor', 'business_name' => '古着屋スモーク', 'representative' => '山田花子',
        'address' => '東京都千代田区丸の内1-1', 'phone' => '0332105556', 'email' => 'smoke-late@example.com',
        'products' => '古着', 'needs_kobutsu' => '1', 'kobutsu_number' => '第1号', 'kobutsu_authority' => '東京都公安委員会',
    ]];
    $lateFiles = ['mk_business_kobutsu_file' => [
        'name' => 'license.png', 'tmp_name' => $lateFile, 'size' => (int) filesize($lateFile), 'error' => UPLOAD_ERR_OK,
    ]];

    check('ダッシュボードに「事業者申請」メニュー', isset($B::addNavItem([])[$B::PAGE]));
    check('既存の出品者は後から申請できる', $B::canApply($lateSeller) && !$B::hasApplication($lateSeller));
    check('申請前の画面に申請フォーム', str_contains($latePage(), 'name="mk_business[representative]"')
        && str_contains($latePage(), '審査中もこれまでどおり販売を続けられます'));
    check('後からの申請も登録時と同じ基準で確認する', $B::validationErrors($latePost, $lateFiles) === []);

    $B::recordApplication($lateSeller, $latePost, $lateFiles, false, $B::ROUTE_DASHBOARD);

    check('後からの申請は審査中になる', $B::statusOf($lateSeller) === $B::STATUS_PENDING && $B::hasApplication($lateSeller));
    check('審査中も個人のまま出品を続けられる', !$B::isBusiness($lateSeller) && $latePublish() === 'publish');
    check('審査中はバッジを出さない', !$B::isApproved($lateSeller));
    check('審査中は重ねて申請できない', !$B::canApply($lateSeller));
    check('申請経路を運営に示す', str_contains($B::routeLabel($lateSeller), '既存の出品者'));
    check('審査中の画面は販売継続を伝える', str_contains($latePage(), '審査中も、これまでどおり出品・販売を続けられます')
        && !str_contains($latePage(), 'name="mk_business[representative]"'));

    $firstLicense = $B::licensePath($lateSeller);
    check('後からの申請でも許可証を保管する', $firstLicense !== '');

    $B::decide($lateSeller, false, 'スモーク', 1);
    check('承認しなければ個人のまま出品できる', !$B::isBusiness($lateSeller) && $latePublish() === 'publish');
    check('承認されなかった後は再申請できる', $B::canApply($lateSeller) && str_contains($latePage(), '前回の事業者申請は承認されませんでした')
        && str_contains($latePage(), 'スモーク'));

    $latePost['mk_business']['needs_kobutsu'] = '';
    $B::recordApplication($lateSeller, $latePost, [], false, $B::ROUTE_DASHBOARD);
    check('許可証なしで再申請すると古い許可証は残さない', $B::licensePath($lateSeller) === '' && !is_file($firstLicense));

    $B::decide($lateSeller, true, '', 1);
    ob_start(); $B::renderStoreBadge($lateSeller); $lateBadge = (string) ob_get_clean();
    check('承認で事業者に切り替わり、バッジが出る', $B::isApproved($lateSeller) && str_contains($lateBadge, '事業者'));
    check('承認後も出品できる', $latePublish() === 'publish');
    check('承認後の画面は承認済みを示す', str_contains($latePage(), 'として承認されています') && !$B::canApply($lateSeller));

    @unlink($lateFile);
    remove_filter('mk_should_notify', $muteLate, 99);

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($lateSeller);
    check('後始末：後からの申請テストのユーザーを削除', !get_userdata($lateSeller));
}

@unlink($png);

echo "\n=== 返金の負担はクリエイター側の事情で決まる ===\n";

$costCreator = get_user_by('login', 'mk_test_creator');

if (!$costCreator) {
    check('cost fixtures', false, 'test creator missing');
} else {
    $muteCost = static fn (): bool => false;
    add_filter('mk_should_notify', $muteCost, 99);

    $makeOrder = static function () use ($costCreator): WC_Order {
        $o = wc_create_order();
        $o->update_meta_data('_mk_creator_id', $costCreator->ID);
        $o->set_status(MK\Order\Statuses::PAID);
        $o->save();

        return wc_get_order($o->get_id());
    };

    $renderBox = static function (WC_Order $o): string {
        ob_start();
        MK\Order\CancelAdmin::renderBox($o);

        return (string) ob_get_clean();
    };

    $costReports = [];
    $service     = new MK\Report\Service();

    // No grounds at all: the operator chooses, and silence means the platform.
    $plain = $makeOrder();
    check('理由がなければ運営が選べる（運営負担）', MK\Order\CancelAdmin::costBearer($plain, 'platform') === 'platform');
    check('理由がなければ運営が選べる（クリエイター負担）', MK\Order\CancelAdmin::costBearer($plain, 'creator') === 'creator');
    check('未選択なら運営負担', MK\Order\CancelAdmin::costBearer($plain, '') === 'platform');
    $plainBox = $renderBox($plain);
    check('選べる画面には3つの結果', str_contains($plainBox, '①クリエイターの責任')
        && str_contains($plainBox, '②購入者の責任') && str_contains($plainBox, '③責任が明確でない'));
    check('不適切な依頼は原則②と案内する', str_contains($plainBox, '不適切な依頼 → 原則②'));
    check('購入者の責任も選べる', MK\Order\CancelAdmin::costBearer($plain, 'buyer') === 'buyer');
    check('不明な値は運営負担', MK\Order\CancelAdmin::costBearer($plain, 'bogus') === 'platform');

    // A ground that is nobody's fault on its face stays the operator's call.
    $damaged = $makeOrder();
    $costReports[] = $service->open(1, MK\Report\Service::TARGET_ORDER, $damaged->get_id(), 'damaged', 'smoke');
    check('破損は運営が判断できる', MK\Order\CancelAdmin::costBearer($damaged, 'platform') === 'platform');

    // Creator-side grounds: the creator pays whatever the form says.
    $mismatch = $makeOrder();
    $mismatchReport = $service->open(1, MK\Report\Service::TARGET_ORDER, $mismatch->get_id(), 'size_mismatch', 'smoke');
    $costReports[] = $mismatchReport;

    check('サイズ相違はクリエイター負担', MK\Order\CancelAdmin::costBearer($mismatch, 'platform') === 'creator');

    $mismatchBox = $renderBox($mismatch);
    check('クリエイター側の事情では他の結果を出さない', !str_contains($mismatchBox, '③責任が明確でない')
        && !str_contains($mismatchBox, '②購入者の責任'));
    check('クリエイター側の事情は購入者負担にもできない', MK\Order\CancelAdmin::costBearer($mismatch, 'buyer') === 'creator');
    check('クリエイター負担だと明示する', str_contains($mismatchBox, 'クリエイター負担です'));

    // Closing the report before refunding does not change why the refund happens.
    $service->resolve($mismatchReport, 1, 'smoke', false);
    check('申し出を閉じてもクリエイター負担のまま',
        MK\Order\CancelAdmin::costBearer(wc_get_order($mismatch->get_id()), 'platform') === 'creator');

    // A missed deadline counts even when nobody asked to cancel.
    $late = $makeOrder();
    $late->update_meta_data(MK\Order\DispatchDeadline::META_OVERDUE_AT, gmdate('Y-m-d H:i:s'));
    $late->save();
    check('期限超過はクリエイター負担', MK\Order\CancelAdmin::costBearer(wc_get_order($late->get_id()), 'platform') === 'creator');

    // A declined message video is the creator's side too.
    $declined = $makeOrder();
    $costReports[] = $service->open(1, MK\Report\Service::TARGET_ORDER, $declined->get_id(), MK\Order\VideoDelivery::REASON, 'smoke');
    check('辞退は運営が判断：クリエイター負担', MK\Order\CancelAdmin::costBearer($declined, 'creator') === 'creator');
    check('辞退は運営が判断：購入者負担', MK\Order\CancelAdmin::costBearer($declined, 'buyer') === 'buyer');
    check('辞退は運営が判断：運営負担', MK\Order\CancelAdmin::costBearer($declined, 'platform') === 'platform');

    // The creator's own account of the decline decides which outcome is offered first.
    $declined->update_meta_data(MK\Product\MessageVideo::META_KIND, MK\Product\MessageVideo::KIND);
    $declined->update_meta_data(MK\Order\VideoDelivery::META_DECLINED_AT, gmdate('Y-m-d H:i:s'));
    $declined->update_meta_data(MK\Order\VideoDelivery::META_DECLINE_KIND, 'buyer_request');
    $declined->save();
    check('不適切な依頼の申告なら購入者負担を先に示す',
        MK\Order\CancelAdmin::recommendedBearer(wc_get_order($declined->get_id())) === 'buyer');

    $declined->update_meta_data(MK\Order\VideoDelivery::META_DECLINE_KIND, 'creator');
    $declined->save();
    check('クリエイター都合の申告ならクリエイター負担を先に示す',
        MK\Order\CancelAdmin::recommendedBearer(wc_get_order($declined->get_id())) === 'creator');
    check('クリエイター都合の辞退は運営が選んでもクリエイター負担',
        MK\Order\CancelAdmin::costBearer(wc_get_order($declined->get_id()), 'platform') === 'creator'
        && MK\Order\CancelAdmin::costBearer(wc_get_order($declined->get_id()), 'buyer') === 'creator');
    check('クリエイター都合の辞退では①だけを出す', !str_contains($renderBox(wc_get_order($declined->get_id())), '③責任が明確でない'));

    $declined->update_meta_data(MK\Order\VideoDelivery::META_DECLINE_KIND, 'buyer_request');
    $declined->save();
    check('不適切な依頼でも運営判断で③にできる',
        MK\Order\CancelAdmin::costBearer(wc_get_order($declined->get_id()), 'platform') === 'platform');

    $declined->update_meta_data(MK\Stripe\TransferService::META_TRANSFER_ID, 'tr_smoke');
    $declined->save();
    check('送金後の不適切な依頼は③を先に示す（②は選べないため）',
        MK\Order\CancelAdmin::recommendedBearer(wc_get_order($declined->get_id())) === 'platform');
    $declined->delete_meta_data(MK\Stripe\TransferService::META_TRANSFER_ID);
    $declined->save();

    global $wpdb;

    foreach ($costReports as $reportId) {
        $wpdb->delete($wpdb->prefix . 'mk_reports', ['id' => $reportId], ['%d']);
    }

    foreach ([$plain, $damaged, $mismatch, $late, $declined] as $o) {
        MK\Schedule\Jobs::cancelDispatchOverdue($o->get_id());
        MK\Schedule\Jobs::cancelAutoComplete($o->get_id());
        $wpdb->delete($wpdb->prefix . 'dokan_orders', ['order_id' => $o->get_id()], ['%d']);
        wc_get_order($o->get_id())->delete(true);
    }

    remove_filter('mk_should_notify', $muteCost, 99);

    check('後始末：負担テストの注文を削除', !wc_get_order($plain->get_id()) && !wc_get_order($declined->get_id()));
}

echo "\n=== 返金費用の負担先 ===\n";

// The ledger is the only record of who was charged, so it is what is checked.
$costSeller = wp_insert_user(['user_login' => 'mk_smoke_cost_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($costSeller)) {
    check('create cost fixture', false, 'fixture setup failed');
} else {
    $ledger = new MK\Ledger\Recorder();

    check('初期の未回収額は0', $ledger->outstanding((int) $costSeller) === 0);

    $debtId = $ledger->record((int) $costSeller, null, MK\Ledger\Recorder::DEBT_INCURRED, 500, 500, null, 'smoke');
    check('出品者負担は残高に積まれる', $ledger->outstanding((int) $costSeller) === 500,
        (string) $ledger->outstanding((int) $costSeller));

    $absorbedId = $ledger->record((int) $costSeller, null, MK\Ledger\Recorder::PLATFORM_ABSORBED, 300, 0, null, 'smoke');
    check('運営負担は残高を動かさない', $ledger->outstanding((int) $costSeller) === 500,
        (string) $ledger->outstanding((int) $costSeller));
    check('運営負担も履歴には残る',
        count(array_filter($ledger->historyFor((int) $costSeller),
            static fn ($r): bool => $r->entry_type === MK\Ledger\Recorder::PLATFORM_ABSORBED)) === 1);

    // 請求書を送っただけでは回収ではない。ここを減らすと、売上からも引かれなくなる。
    $invoiceId = $ledger->invoice((int) $costSeller, 500, 'smoke 請求');
    check('請求を記録しても残高は減らない', $ledger->outstanding((int) $costSeller) === 500,
        (string) $ledger->outstanding((int) $costSeller));

    check('未回収のある出品者は一覧に出る',
        in_array((int) $costSeller, array_map(
            static fn ($r): int => (int) $r->user_id, $ledger->debtors()), true));

    $paidId = $ledger->recordPayment((int) $costSeller, 200, 'smoke 入金');
    check('入金を記録すると残高が減る', $ledger->outstanding((int) $costSeller) === 300,
        (string) $ledger->outstanding((int) $costSeller));

    $overId = $ledger->recordPayment((int) $costSeller, 99999, 'smoke 過入金');

    check('完済の出品者は一覧から消える',
        !in_array((int) $costSeller, array_map(
            static fn ($r): int => (int) $r->user_id, $ledger->debtors()), true));
    check('債務を超える入金でもマイナスにならない', $ledger->outstanding((int) $costSeller) === 0,
        (string) $ledger->outstanding((int) $costSeller));


    global $wpdb;

    foreach ([$debtId, $absorbedId, $invoiceId, $paidId, $overId] as $rowId) {
        $wpdb->delete($wpdb->prefix . 'mk_creator_ledger', ['id' => $rowId], ['%d']);
    }

    $wpdb->delete($wpdb->prefix . 'mk_creator_balances', ['user_id' => (int) $costSeller], ['%d']);

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($costSeller);

    check('後始末：台帳のテスト行を削除',
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mk_creator_ledger WHERE user_id = %d",
            (int) $costSeller
        )) === 0);
}

echo "\n=== お届け先の取得と表示 ===\n";

$SA = MK\Checkout\ShippingAddress::class;

[$addr, $addrErrors] = $SA::normalise([
    'last_name' => '山田', 'first_name' => '花子', 'postcode' => '１５０ー０００１', 'state' => 'JP13',
    'city' => '渋谷区', 'address_1' => '神宮前１－２－３', 'address_2' => '', 'phone' => '090-1234-5678',
]);
check('全角の郵便番号をそろえて受け付ける', $addrErrors === [] && $addr['postcode'] === '150-0001', implode(' / ', $addrErrors) . ' ' . ($addr['postcode'] ?? ''));
check('電話番号は数字だけで保存', ($addr['phone'] ?? '') === '09012345678');
check('番地の全角数字もそろえる', ($addr['address_1'] ?? '') === '神宮前1-2-3', $addr['address_1'] ?? '');

[, $badErrors] = $SA::normalise(['last_name' => '', 'first_name' => '花子', 'postcode' => '123', 'state' => 'XX',
    'city' => '渋谷区', 'address_1' => '1-2-3', 'phone' => '12345']);
check('不足・不正な住所は理由つきで拒否',
    in_array('姓を入力してください。', $badErrors, true)
    && in_array('郵便番号は7桁の数字で入力してください。', $badErrors, true)
    && in_array('都道府県を選んでください。', $badErrors, true)
    && in_array('電話番号は、0から始まる10桁または11桁の数字で入力してください。', $badErrors, true),
    implode(' / ', $badErrors));
check('都道府県の一覧は47件', count($SA::prefectures()) === 47, (string) count($SA::prefectures()));

$shipOrder = wc_create_order();
$shipOrder->update_meta_data($SA::META_NEEDS, 'yes');
$shipOrder->set_status('pending');
$shipOrder->save();
$shipOrder = wc_get_order($shipOrder->get_id());

check('配送が必要な注文', $SA::needsShipping($shipOrder));
check('住所がなければ未登録扱い', !$SA::hasAddress($shipOrder));
check('住所がないとお支払いの前に入力画面', str_contains($SA::renderForm($shipOrder), 'name="mk_shipping[postcode]"'));

$SA::saveToOrder($shipOrder, $addr);
$shipOrder = wc_get_order($shipOrder->get_id());
check('住所を注文に保存', $SA::hasAddress($shipOrder) && $shipOrder->get_shipping_postcode() === '150-0001' && $shipOrder->get_shipping_phone() === '09012345678');
check('発送用の表記', implode('|', $SA::lines($shipOrder)) === '〒150-0001|東京都渋谷区神宮前1-2-3|山田 花子 様|TEL 09012345678', implode('|', $SA::lines($shipOrder)));

check('支払い前はクリエイターに見せない', !$SA::visibleToCreator($shipOrder));
$shipOrder->set_status(MK\Order\Statuses::PAID);
$shipOrder->save();
check('支払い後はクリエイターに見せる', $SA::visibleToCreator(wc_get_order($shipOrder->get_id())));

$shipOrder = wc_get_order($shipOrder->get_id());
$shipOrder->update_meta_data($SA::META_MASKED, 'yes');
$shipOrder->save();
check('非表示後はクリエイターに見せない', !$SA::visibleToCreator(wc_get_order($shipOrder->get_id())));

$dokanSelling = get_option('dokan_selling');
check('Dokanの顧客情報欄（メール・IP）は出さない', is_array($dokanSelling) && ($dokanSelling['hide_customer_info'] ?? '') === 'on');

$videoOrder = wc_create_order();
$videoOrder->update_meta_data($SA::META_NEEDS, 'no');
$videoOrder->save();
check('配送のない注文は住所を求めない', !$SA::needsShipping(wc_get_order($videoOrder->get_id())));

$maskUser = wp_insert_user(['user_login' => 'mk_smoke_ship_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'role' => 'customer']);
if (!is_wp_error($maskUser)) {
    $SA::saveToProfile($maskUser, $addr);
    $fresh = wc_create_order(['customer_id' => $maskUser]);
    check('アカウントの住所を次回の初期値にする', $SA::prefill($fresh, $maskUser)['postcode'] === '150-0001');
    $fresh->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($maskUser);
}

// Transitions: 受取確認 schedules the mask; cancellation hides at once.
$flowOrder = wc_create_order();
$flowOrder->update_meta_data($SA::META_NEEDS, 'yes');
$flowOrder->set_status(MK\Order\Statuses::SHIPPED);
$flowOrder->save();
add_filter('mk_should_notify', '__return_false', 99);
$flowOrder = wc_get_order($flowOrder->get_id());
$flowOrder->update_status(MK\Order\Statuses::RECEIVED);
check('受取確認で30日後の非表示を予約',
    (bool) as_next_scheduled_action(MK\Schedule\Jobs::MASK_ADDRESS, ['order_id' => $flowOrder->get_id()], 'mk-marketplace'));
MK\Schedule\Jobs::cancelTransfer($flowOrder->get_id());

$cancelOrder = wc_create_order();
$cancelOrder->update_meta_data($SA::META_NEEDS, 'yes');
$cancelOrder->set_status(MK\Order\Statuses::PAID);
$cancelOrder->save();
wc_get_order($cancelOrder->get_id())->update_status('cancelled');
check('キャンセルで直ちに非表示', $SA::isMasked(wc_get_order($cancelOrder->get_id())));
remove_filter('mk_should_notify', '__return_false', 99);

$unscheduleAll = static function (int $orderId): void {
    foreach ([MK\Schedule\Jobs::AUTO_COMPLETE, MK\Schedule\Jobs::EXECUTE_TRANSFER, MK\Schedule\Jobs::MASK_ADDRESS, MK\Schedule\Jobs::DISPATCH_OVERDUE] as $hook) {
        as_unschedule_all_actions($hook, ['order_id' => $orderId], 'mk-marketplace');
    }
};

foreach ([$shipOrder, $videoOrder, $flowOrder, $cancelOrder] as $o) {
    $unscheduleAll($o->get_id());
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'dokan_orders', ['order_id' => $o->get_id()], ['%d']);
    wc_get_order($o->get_id())->delete(true);
}
check('後始末：お届け先テストの注文を削除', !wc_get_order($shipOrder->get_id()) && !wc_get_order($cancelOrder->get_id()));

echo "\n=== レビューは受取完了した購入者のみ ===\n";

check('「購入者のみ」を強制', get_option('woocommerce_review_rating_verification_required') === 'yes');

$reviewBuyer = wp_insert_user(['user_login' => 'mk_smoke_rev_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'role' => 'customer']);
$reviewProduct = new WC_Product_Simple();
$reviewProduct->set_name('smoke review product');
$reviewProduct->set_regular_price('1000');
$reviewProduct->save();
$rp = $reviewProduct->get_id();

if (!is_wp_error($reviewBuyer)) {
    check('未ログインは投稿できない', !wc_customer_bought_product('', 0, $rp));
    check('購入していなければ投稿できない', !wc_customer_bought_product('', $reviewBuyer, $rp));

    $ro = wc_create_order(['customer_id' => $reviewBuyer]);
    $ro->update_meta_data('_mk_product_id', $rp);
    $ro->set_status(MK\Order\Statuses::SHIPPED);
    $ro->save();
    check('発送済みでも受取前は投稿できない', !wc_customer_bought_product('', $reviewBuyer, $rp));

    $ro = wc_get_order($ro->get_id());
    $ro->set_status(MK\Order\Statuses::RECEIVED);
    $ro->save();
    check('受取確認後は投稿できる', wc_customer_bought_product('', $reviewBuyer, $rp));
    check('別の商品には投稿できない', !wc_customer_bought_product('', $reviewBuyer, $rp + 999999));

    $ro = wc_get_order($ro->get_id());
    $ro->set_status('cancelled');
    $ro->save();
    check('キャンセルされた注文では投稿できない', !wc_customer_bought_product('', $reviewBuyer, $rp));

    // A one-off item is sold by the time its buyer can review it.
    wp_update_post(['ID' => $rp, 'post_status' => MK\Product\Statuses::SOLD]);
    check('売却済み商品は通常は非公開のまま', get_post_status($rp) === MK\Product\Statuses::SOLD);
    $savedScript = $_SERVER['SCRIPT_NAME'] ?? '';
    $_SERVER['SCRIPT_NAME'] = '/wp-comments-post.php';
    $_POST['comment_post_ID'] = (string) $rp;
    check('売却済み商品にもレビューを受け付ける', get_post_status($rp) === 'publish');
    $_POST['comment_post_ID'] = (string) ($rp + 1);
    check('別の投稿へのコメント処理では変えない', get_post_status($rp) === MK\Product\Statuses::SOLD);
    unset($_POST['comment_post_ID']);
    $_SERVER['SCRIPT_NAME'] = $savedScript;

    check('案内文は日本語で受取完了に言及',
        __('Only logged in customers who have purchased this product may leave a review.', 'woocommerce') === 'この商品を購入し、受取が完了した方のみレビューを投稿できます。');

    $unscheduleAll($ro->get_id());
    $ro->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($reviewBuyer);
}

wp_delete_post($rp, true);

echo "\n=== 承認済み事業者の表示 ===\n";

$pubSeller = wp_insert_user(['user_login' => 'mk_smoke_pub_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (!is_wp_error($pubSeller)) {
    $B = MK\Creator\Business::class;
    update_user_meta($pubSeller, $B::META_DATA, [
        'business_type' => 'corporation', 'business_name' => '株式会社スモーク', 'representative' => '山田太郎',
        'address' => '大阪府大阪市北区1-1', 'phone' => '0643218765', 'email' => 'smoke@example.com',
        'invoice_number' => 'T1234567890123', 'kobutsu_number' => '第1号', 'needs_kobutsu' => true,
    ]);
    update_user_meta($pubSeller, $B::META_KIND, $B::KIND_BUSINESS);
    update_user_meta($pubSeller, $B::META_STATUS, $B::STATUS_PENDING);

    ob_start(); $B::renderStoreInfo((object) ['ID' => $pubSeller]); $pendingInfo = (string) ob_get_clean();
    check('審査中は事業者情報を公開しない', $pendingInfo === '');

    update_user_meta($pubSeller, $B::META_STATUS, $B::STATUS_APPROVED);
    ob_start(); $B::renderStoreInfo((object) ['ID' => $pubSeller]); $info = (string) ob_get_clean();
    check('承認後はショップに事業者情報を表示',
        str_contains($info, '株式会社スモーク') && str_contains($info, '山田太郎') && str_contains($info, '大阪府大阪市北区1-1')
        && str_contains($info, '0643218765') && str_contains($info, 'smoke@example.com'));
    check('許可番号・インボイス番号は公開しない', !str_contains($info, 'T1234567890123') && !str_contains($info, '第1号'));
    check('申請時に公開される項目を案内する', str_contains($B::publicNotice(), 'ショップページに表示されます'));

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($pubSeller);
}

echo "\n=== ガイドライン ===\n";

$guidelinePage = get_page_by_path('guideline');
check('ガイドラインのページが公開されている', $guidelinePage && $guidelinePage->post_status === 'publish');
ob_start(); MK\Product\FormGuide::guidelineNotice(); $guidelineNotice = (string) ob_get_clean();
check('出品画面からガイドラインへ案内', $guidelinePage && str_contains($guidelineNotice, (string) get_permalink($guidelinePage)));

echo "\n=== 運営への申し出 ===\n";

$RS = MK\Report\Service::class;
$CP = MK\Report\ClaimPage::class;
$CF = MK\Report\ClaimFiles::class;
$J  = MK\Schedule\Jobs::class;

check('申し出の内容はご指定の7項目', array_values($RS::claimCategories()) === [
    '商品の未着', '商品説明や掲載内容との相違', 'その他取引上の問題', '返品・返金について',
    'キャンセルについて', 'デジタルコンテンツに関する問題', 'その他',
]);
check('申し出の内容はすべて通報として登録できる', array_diff_key($RS::claimCategories(), $RS::reasons()) === []);
check('LINEの入口から申し出ページへ', MK\Line\Links::resolve('claim', 1) === $CP::url());
check('LINEの入口：未ログインはログイン画面へ', MK\Line\Links::resolve('claim', 0) === wc_get_page_permalink('myaccount'));
check('マイアカウントの注文履歴の次に申し出', array_keys($CP::addMenuItem(['dashboard' => '', 'orders' => '', 'customer-logout' => '']))
    === ['dashboard', 'orders', $CP::ENDPOINT, 'customer-logout']);

add_filter('mk_should_notify', '__return_false', 99);

$claimBuyer = wp_insert_user(['user_login' => 'mk_smoke_claim_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'role' => 'customer']);
$otherBuyer = wp_insert_user(['user_login' => 'mk_smoke_claim2_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'role' => 'customer']);

$claimOrders = [];
$makeClaimOrder = static function (int $buyer, string $status) use (&$claimOrders): int {
    $o = wc_create_order(['customer_id' => $buyer]);
    $o->update_meta_data('_mk_title_snapshot', 'smoke claim item');
    $o->set_status($status);
    $o->save();
    $claimOrders[] = $o->get_id();

    return $o->get_id();
};

$pngPath = tempnam(sys_get_temp_dir(), 'mkc');
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$fakePath = tempnam(sys_get_temp_dir(), 'mkc');
file_put_contents($fakePath, '<?php echo "not an image";');

$filesOf = static fn (array $paths, array $names): array => [
    'name' => $names, 'tmp_name' => $paths,
    'size' => array_map(static fn ($p): int => (int) filesize($p), $paths),
    'error' => array_fill(0, count($paths), UPLOAD_ERR_OK),
];

if (!is_wp_error($claimBuyer) && !is_wp_error($otherBuyer)) {
    $paidOrder      = $makeClaimOrder($claimBuyer, MK\Order\Statuses::PAID);
    $secondOrder    = $makeClaimOrder($claimBuyer, MK\Order\Statuses::SHIPPED);
    $pendingOrder   = $makeClaimOrder($claimBuyer, 'pending');
    $cancelledOrder = $makeClaimOrder($claimBuyer, 'cancelled');
    $strangerOrder  = $makeClaimOrder($otherBuyer, MK\Order\Statuses::PAID);

    $eligibleIds = array_map(static fn ($o): int => $o->get_id(), $CP::eligibleOrders($claimBuyer));
    check('支払い後の注文を選べる', in_array($paidOrder, $eligibleIds, true) && in_array($secondOrder, $eligibleIds, true));
    check('未払い・キャンセル済み・他人の注文は選べない',
        !in_array($pendingOrder, $eligibleIds, true) && !in_array($cancelledOrder, $eligibleIds, true) && !in_array($strangerOrder, $eligibleIds, true));

    $good = ['mk_claim_order' => (string) $paidOrder, 'mk_claim_category' => 'not_arrived', 'mk_claim_detail' => '発送連絡から10日経っても届きません。'];
    check('正しい申し出は受け付ける', $CP::problems($claimBuyer, $good, []) === [], implode(' / ', $CP::problems($claimBuyer, $good, [])));
    check('他人の注文では申し出できない',
        in_array('申し出の対象となる注文を選んでください。', $CP::problems($claimBuyer, ['mk_claim_order' => (string) $strangerOrder] + $good, []), true));
    $missing = $CP::problems($claimBuyer, ['mk_claim_order' => (string) $paidOrder], []);
    check('内容と詳細は必須', in_array('申し出の内容を選んでください。', $missing, true) && in_array('詳細を入力してください。', $missing, true));
    check('画像以外のファイルは受け付けない', $CF::problems($filesOf([$fakePath], ['photo.png'])) !== []);
    check('画像は3枚まで', $CF::problems($filesOf([$pngPath, $pngPath, $pngPath, $pngPath], ['1.png', '2.png', '3.png', '4.png'])) === ['添付できる画像は3枚までです。']);
    check('画像を選ばなくても申し出できる', $CF::problems(['name' => [''], 'tmp_name' => [''], 'size' => [0], 'error' => [UPLOAD_ERR_NO_FILE]]) === []);

    as_schedule_single_action(time() + DAY_IN_SECONDS, $J::EXECUTE_TRANSFER, ['order_id' => $paidOrder], 'mk-marketplace');
    $claimReport = $CP::submit($claimBuyer, $good, $filesOf([$pngPath, $pngPath], ['a.png', 'b.png']), false);

    check('申し出が運営の確認待ちとして登録される', $claimReport > 0 && (new $RS())->openCountFor($RS::TARGET_ORDER, $paidOrder) === 1);
    check('申し出で売上金の支払いを保留（第17条）',
        wc_get_order($paidOrder)->get_meta($RS::ORDER_FLAG) === 'yes'
        && !as_next_scheduled_action($J::EXECUTE_TRANSFER, ['order_id' => $paidOrder], 'mk-marketplace'));

    $claimNames = $CF::namesFor(wc_get_order($paidOrder), $claimReport);
    check('画像を公開領域の外に保存', count($claimNames) === 2 && $CF::pathFor($claimNames[0]) !== ''
        && !str_starts_with($CF::pathFor($claimNames[0]), untrailingslashit(ABSPATH)));
    check('画像の任意パスは参照させない', $CF::pathFor('../../wp-config.php') === '');
    check('同じ注文への重ねての申し出は受け付けない',
        in_array('この注文については、すでに申し出を受け付けています。運営の確認をお待ちください。', $CP::problems($claimBuyer, $good, []), true));

    wp_set_current_user($claimBuyer);
    ob_start(); MK\Report\Frontend::render(wc_get_order($secondOrder)); $orderPanel = (string) ob_get_clean();
    ob_start(); $CP::render(); $claimPageHtml = (string) ob_get_clean();
    wp_set_current_user(0);
    check('注文画面から、その注文を選んだ申し出ページへ', str_contains($orderPanel, esc_url($CP::url($secondOrder))) && !str_contains($orderPanel, 'mk_report_reason'));
    check('申し出ページに履歴と画像添付欄', str_contains($claimPageHtml, 'これまでの申し出') && str_contains($claimPageHtml, 'name="mk_claim_files[]"'));

    (new $RS())->resolve($claimReport, 1, 'smoke', false);

    foreach ($claimNames as $name) {
        @unlink($CF::pathFor($name));
    }

    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'mk_reports', ['id' => $claimReport], ['%d']);
}

foreach ($claimOrders as $id) {
    foreach ([$J::AUTO_COMPLETE, $J::EXECUTE_TRANSFER, $J::MASK_ADDRESS, $J::DISPATCH_OVERDUE] as $hook) {
        as_unschedule_all_actions($hook, ['order_id' => $id], 'mk-marketplace');
    }
    if ($o = wc_get_order($id)) { $o->delete(true); }
}

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$claimBuyer, $otherBuyer] as $u) {
    if (!is_wp_error($u)) { wp_delete_user($u); }
}

echo "\n=== 退会（第9条） ===\n";

$W = MK\Account\Withdrawal::class;

check('マイアカウントのログアウトの前に退会', array_keys($W::addMenuItem(['dashboard' => '', 'customer-logout' => '']))
    === ['dashboard', $W::ENDPOINT, 'customer-logout']);

$wBuyer   = wp_insert_user(['user_login' => 'mk_smoke_wb_' . wp_rand(1000, 9999), 'user_pass' => 'WithdrawTest!123', 'user_email' => 'mk-smoke-wb-' . wp_rand(1000, 9999) . '@example.com', 'role' => 'customer']);
$wCreator = wp_insert_user(['user_login' => 'mk_smoke_wc_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'user_email' => 'mk-smoke-wc-' . wp_rand(1000, 9999) . '@example.com', 'role' => 'seller']);
$wOrders  = [];
$wProduct = 0;

if (!is_wp_error($wBuyer) && !is_wp_error($wCreator)) {
    check('取引のない会員は退会できる', $W::blockers($wBuyer) === []);

    $wo = wc_create_order(['customer_id' => $wBuyer]);
    $wo->update_meta_data('_mk_creator_id', $wCreator);
    $wo->set_status(MK\Order\Statuses::PAID);
    $wo->save();
    $wOrders[] = $wo->get_id();

    $buyerBlocks = $W::blockers($wBuyer);
    check('取引中の購入者は退会できない', $buyerBlocks !== [] && str_contains(implode('', $buyerBlocks), 'お届け・受取が完了していないご注文'));
    check('未発送の注文があるクリエイターは退会できない', str_contains(implode('', $W::blockers($wCreator)), '発送（提供）が完了していない注文'));

    $setStatus = static function (int $id, string $status): void {
        $o = wc_get_order($id);
        $o->set_status($status);
        $o->save();
    };

    $setStatus($wo->get_id(), MK\Order\Statuses::SHIPPED);
    check('受取確認前はクリエイターも退会できない', str_contains(implode('', $W::blockers($wCreator)), '受取確認が完了していない'));

    $setStatus($wo->get_id(), MK\Order\Statuses::RECEIVED);
    check('受取確認後は購入者として退会できる', $W::blockers($wBuyer) === []);
    check('売上金の支払い前はクリエイターは退会できない', str_contains(implode('', $W::blockers($wCreator)), '売上金の支払い手続き'));

    $setStatus($wo->get_id(), 'completed');
    check('取引が完了すればクリエイターも退会できる', $W::blockers($wCreator) === [], implode(' / ', $W::blockers($wCreator)));

    (new MK\Ledger\Recorder())->record($wCreator, null, MK\Ledger\Recorder::DEBT_INCURRED, 500, 500, null, 'smoke');
    check('未回収額が残っていると退会できない', str_contains(implode('', $W::blockers($wCreator)), '未回収額'));
    $wpdb->delete($wpdb->prefix . 'mk_creator_ledger', ['user_id' => $wCreator], ['%d']);
    $wpdb->delete($wpdb->prefix . 'mk_creator_balances', ['user_id' => $wCreator], ['%d']);
    check('未回収額が精算されれば退会できる', $W::blockers($wCreator) === []);

    $openedReport = (new $RS())->open($wBuyer, $RS::TARGET_ORDER, $wo->get_id(), 'refund', 'smoke');
    check('運営が確認中の申し出があると退会できない',
        str_contains(implode('', $W::blockers($wBuyer)), '運営が確認中') && str_contains(implode('', $W::blockers($wCreator)), '運営が確認中'));
    (new $RS())->resolve($openedReport, 1, 'smoke', false);
    $wpdb->delete($wpdb->prefix . 'mk_reports', ['id' => $openedReport], ['%d']);
    check('申し出の対応が終われば退会できる', $W::blockers($wBuyer) === []);

    $product = new WC_Product_Simple();
    $product->set_name('smoke withdraw listing');
    $product->set_regular_price('1000');
    $product->save();
    $wProduct = $product->get_id();
    $wpdb->update($wpdb->posts, ['post_author' => $wCreator, 'post_status' => 'publish'], ['ID' => $wProduct]);
    clean_post_cache($wProduct);

    $creatorEmail = get_userdata($wCreator)->user_email;
    $W::withdraw($wCreator);
    clean_user_cache($wCreator);

    check('退会すると出品は非公開', get_post_status($wProduct) === 'draft');
    check('退会後も取引記録は残る', wc_get_order($wo->get_id()) instanceof WC_Order && get_userdata($wCreator) instanceof WP_User);
    check('元のメールアドレスを記録として保存', get_user_meta($wCreator, $W::META_EMAIL, true) === $creatorEmail);
    check('同じメールアドレスで再登録できる', !email_exists($creatorEmail));

    $buyerLogin = get_userdata($wBuyer)->user_login;
    $W::withdraw($wBuyer);
    clean_user_cache($wBuyer);
    check('退会後はログインできない', is_wp_error(wp_authenticate($buyerLogin, 'WithdrawTest!123')));
    check('退会後はパスワード再設定もできない', !apply_filters('allow_password_reset', true, $wBuyer));
}

foreach ($wOrders as $id) {
    foreach ([$J::AUTO_COMPLETE, $J::EXECUTE_TRANSFER, $J::MASK_ADDRESS, $J::DISPATCH_OVERDUE] as $hook) {
        as_unschedule_all_actions($hook, ['order_id' => $id], 'mk-marketplace');
    }
    if ($o = wc_get_order($id)) { $o->delete(true); }
}
if ($wProduct) { wp_delete_post($wProduct, true); }
foreach ([$wBuyer, $wCreator] as $u) {
    if (!is_wp_error($u)) { wp_delete_user($u); }
}
@unlink($pngPath);
@unlink($fakePath);
remove_filter('mk_should_notify', '__return_false', 99);

echo "\n=== 利用停止と再登録の制限 ===\n";

$SU = MK\Account\Suspension::class;
$WD = MK\Account\Withdrawal::class;

check('メールアドレスの別名をそろえて比較', $SU::normaliseEmail('Taro.Yamada+shop@GMAIL.com') === 'taroyamada@gmail.com'
    && $SU::normaliseEmail('Hanako+x@Example.jp') === 'hanako@example.jp');
check('電話番号の表記ゆれをそろえて比較', $SU::normalisePhone('０９０－１２３４－５６７８') === '09012345678' && $SU::normalisePhone('123') === '');

add_filter('mk_should_notify', '__return_false', 99);
require_once ABSPATH . 'wp-admin/includes/user.php';

$barLocal  = 'mksmokebar' . wp_rand(100000, 999999);
$barEmail  = $barLocal . '@gmail.com';
$barPhone  = '090' . wp_rand(10000000, 99999999);
$barSeller = wp_insert_user(['user_login' => 'mk_smoke_bar_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'user_email' => $barEmail, 'role' => 'seller']);

if (!is_wp_error($barSeller)) {
    update_user_meta($barSeller, 'billing_phone', $barPhone);

    $WD::withdraw($barSeller);
    clean_user_cache($barSeller);
    check('通常の退会者は同じメールアドレスで再登録できる', !$SU::isBarred($barEmail) && !email_exists($barEmail));

    update_user_meta($barSeller, MK\Creator\Restriction::USER_META, 'yes');
    $variant = strtoupper(substr($barLocal, 0, 3)) . '.' . substr($barLocal, 3) . '+again@googlemail.com';
    check('出品制限中に退会した出品者は同じメールアドレスで再登録できない', $SU::isBarred($barEmail));
    check('「+」や大文字・ドットの違いでも再登録できない', $SU::isBarred($variant), $variant);
    check('同じ電話番号でも再登録できない', $SU::isBarred('', '090-' . substr($barPhone, 3, 4) . '-' . substr($barPhone, 7)));

    $_POST['phone'] = '';
    $regErrors = $SU::guardRegistration(new WP_Error(), 'someone', $variant);
    unset($_POST['phone']);
    check('会員登録画面で拒否する', $regErrors->get_error_message('mk_barred') === $SU::REFUSAL);

    $otherMember = wp_insert_user(['user_login' => 'mk_smoke_bar2_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(24), 'role' => 'customer']);
    if (!is_wp_error($otherMember)) {
        $emailErrors = new WP_Error();
        $SU::guardEmailChange($emailErrors, (object) ['ID' => $otherMember, 'user_email' => $barEmail]);
        check('既存会員のメールアドレス変更でも使えない', $emailErrors->has_errors());
        wp_delete_user($otherMember);
    }

    delete_user_meta($barSeller, MK\Creator\Restriction::USER_META);
    check('措置を解除すれば再登録できる', !$SU::isBarred($barEmail));

    wp_delete_user($barSeller);
}

$suspendPass = 'SuspendTest!' . wp_rand(1000, 9999);
$suspendUser = wp_insert_user(['user_login' => 'mk_smoke_sus_' . wp_rand(1000, 9999), 'user_pass' => $suspendPass, 'user_email' => 'mk-smoke-sus-' . wp_rand(1000, 9999) . '@example.com', 'role' => 'customer']);

if (!is_wp_error($suspendUser)) {
    $suspendLogin = get_userdata($suspendUser)->user_login;
    WP_Session_Tokens::get_instance($suspendUser)->create(time() + HOUR_IN_SECONDS);

    $SU::suspend($suspendUser, 'smoke', 1);
    $attempt = wp_authenticate($suspendLogin, $suspendPass);
    check('利用停止中はログインできない', is_wp_error($attempt) && $attempt->get_error_code() === 'mk_suspended');
    check('利用停止でログイン中のセッションも終了', count(WP_Session_Tokens::get_instance($suspendUser)->get_all()) === 0);
    check('利用停止中はパスワード再設定できない', !apply_filters('allow_password_reset', true, $suspendUser));
    check('利用停止中の会員のメールアドレスでは登録できない', $SU::isBarred(get_userdata($suspendUser)->user_email));

    $SU::lift($suspendUser, 'smoke lifted');
    check('利用停止を解除すればログインできる', wp_authenticate($suspendLogin, $suspendPass) instanceof WP_User);

    wp_delete_user($suspendUser);
}

remove_filter('mk_should_notify', '__return_false', 99);

echo "\n=== 登録情報の確認（⑥） ===\n";
$PC = MK\Account\ProfileChecks::class;

foreach (['山田', '太郎', 'やまだ', 'ヤマダ', 'Smith', 'オニール', '齋藤', '佐々木'] as $good) {
    check('名前として通す：' . $good, $PC::nameProblem($good, '姓') === null, (string) $PC::nameProblem($good, '姓'));
}
foreach (['テスト', 'ﾃｽﾄ', 'test', '山田1', '★☆', 'ああああ', '名無し', 'ｘｘｘ'] as $bad) {
    check('名前として断る：' . $bad, $PC::nameProblem($bad, '姓') !== null);
}

foreach (['090-2468-1357', '０９０２４６８１３５７', '03-3210-5555', '0120-444-222'] as $good) {
    check('電話番号として通す：' . $good, $PC::phoneProblem($good) === null, (string) $PC::phoneProblem($good));
}
foreach (['12345', '090-0000-0000', '00000000000', '03-1234-5678', '0123456789', '080-1111-1111'] as $bad) {
    check('電話番号として断る：' . $bad, $PC::phoneProblem($bad) !== null);
}

check('住所として通す', $PC::addressProblem('東京都渋谷区渋谷1-2-3') === null
    && $PC::addressProblem('大阪府大阪市北区梅田一丁目1番') === null);
check('都道府県がない住所は断る', str_contains((string) $PC::addressProblem('渋谷区渋谷1-2-3'), '都道府県'));
check('番地がない住所は断る', str_contains((string) $PC::addressProblem('東京都渋谷区'), '番地'));

$RC = MK\Account\RegistrationChecks::class;
check('正しい登録内容は通す', $RC::problems([
    'lname' => '山田', 'fname' => '花子', 'phone' => '090-2468-1357', 'shopurl' => 'hanako-shop',
]) === []);
check('不正な登録内容はまとめて伝える', count($RC::problems([
    'lname' => 'テスト', 'fname' => 'ああああ', 'phone' => '000-0000-0000', 'shopurl' => 'ショップ',
])) === 4);

check('事業者申請の所在地・電話番号も確認する', (static function (): bool {
    $errors = MK\Creator\Business::validationErrors([
        'mk_seller_kind' => MK\Creator\Business::KIND_BUSINESS,
        'mk_business'    => [
            'business_type' => 'corporation', 'business_name' => '株式会社スモーク', 'representative' => 'テスト',
            'address' => '千代田区', 'phone' => '0300000000', 'email' => 'smoke@example.com', 'products' => '古着',
        ],
    ], []);
    $text = implode(' ', $errors);

    return str_contains($text, '代表者名') && str_contains($text, '都道府県') && str_contains($text, '電話番号');
})());

echo "\n=== ショップのURL（任意）（②） ===\n";
$savedPost = $_POST;
$savedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['register' => '登録する', 'role' => 'seller', 'shopurl' => ''];
$RC::fillShopUrl();
$generated = (string) ($_POST['shopurl'] ?? '');
check('空欄なら自動で作る', (bool) preg_match('/^shop-[a-z0-9]{8}$/', $generated), $generated);
check('作ったURLは誰も使っていない', !get_user_by('slug', $generated));

$_POST = ['register' => '登録する', 'role' => 'seller', 'shopurl' => 'my-own-shop'];
$RC::fillShopUrl();
check('入力されたURLはそのまま', $_POST['shopurl'] === 'my-own-shop');

$_POST = ['register' => '登録する', 'role' => 'customer', 'shopurl' => ''];
$RC::fillShopUrl();
check('購入者の登録では作らない', $_POST['shopurl'] === '');

$_POST = $savedPost;
if ($savedMethod === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $savedMethod; }

echo "\n=== 登録画面の英語表示（⑤） ===\n";
check('英語の初期設定ウィザードを出さない',
    dokan_get_option('disable_welcome_wizard', 'dokan_selling', 'off') === 'on',
    (string) dokan_get_option('disable_welcome_wizard', 'dokan_selling', 'off'));
check('「Go to Vendor Dashboard」を日本語に',
    apply_filters('dokan_set_go_to_vendor_dashboard_btn_text', 'Go to Vendor Dashboard') === '出品者ダッシュボードへ');
foreach ([
    'This field is required'            => 'この項目は必須です',
    'Please enter a valid email address.' => '正しいメールアドレスを入力してください。',
    'Shop URL is not available'         => 'このショップのURLはすでに使われています。別のURLを入力してください。',
    'Not Available'                     => '使用できません',
] as $en => $ja) {
    check('日本語：' . $en, __($en, 'dokan-lite') === $ja, __($en, 'dokan-lite'));
}
ob_start(); $RC::renderNotice(); $regNotice = (string) ob_get_clean();
check('登録画面で正確な入力をお願いする', str_contains($regNotice, '登録情報は正確に入力してください')
    && str_contains($regNotice, '第4条'));

echo "\n=== 利用規約などの「戻る」（①） ===\n";
$legalIds = MK\Account\LegalPages::pageIds();
foreach (['terms', 'privacy-policy', 'tokushoho', 'guideline'] as $slug) {
    $legalPage = get_page_by_path($slug);
    check('対象ページ：' . $slug, $legalPage && in_array((int) $legalPage->ID, $legalIds, true));
}

echo "\n=== 受取設定が終わるまで出品できない（③） ===\n";
$lockSeller = wp_insert_user(['user_login' => 'mk_smoke_lock_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (is_wp_error($lockSeller)) {
    check('lock fixtures', false, $lockSeller->get_error_message());
} else {
    wp_set_current_user($lockSeller);

    check('受取設定前は出品できない', MK\Product\PublishGate::mustOnboard($lockSeller));
    ob_start(); MK\Product\FormGuide::submitButtons(0); $lockedButtons = (string) ob_get_clean();
    check('出品ボタンを止めて理由を伝える', str_contains($lockedButtons, 'data-mk-locked')
        && str_contains($lockedButtons, '受取設定の完了後に出品できます'));
    check('下書き保存はできる', str_contains($lockedButtons, 'mk-submit__btn--save')
        && !str_contains(substr($lockedButtons, 0, (int) strpos($lockedButtons, 'mk-submit__btn--publish')), 'data-mk-locked'));
    check('押したときの案内は依頼どおりの文言',
        MK\Product\PublishGate::LOCKED_MESSAGE === '受取設定が完了していないため、出品できません。先に受取設定を完了してください。');

    update_user_meta($lockSeller, MK\Stripe\AccountService::META_STATUS, MK\Stripe\AccountService::STATUS_COMPLETED);
    ob_start(); MK\Product\FormGuide::submitButtons(0); $openButtons = (string) ob_get_clean();
    check('受取設定が済めば出品できる', !MK\Product\PublishGate::mustOnboard($lockSeller)
        && !str_contains($openButtons, 'data-mk-locked'));

    echo "\n=== 審査中の商品の重複出品（④） ===\n";
    $dupId = wp_insert_post(['post_type' => 'product', 'post_status' => 'pending',
        'post_title' => 'スモーク　ヴィンテージ Tシャツ', 'post_author' => $lockSeller]);
    $otherId = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish',
        'post_title' => '公開中の別商品', 'post_author' => $lockSeller]);

    $pendingList = MK\Product\FormGuide::pendingTitles($lockSeller);
    check('審査中の商品を照合の対象にする', in_array('スモーク　ヴィンテージ Tシャツ', array_column($pendingList, 'title'), true));
    check('公開中の商品は対象にしない', !in_array('公開中の別商品', array_column($pendingList, 'title'), true));
    check('編集中の商品そのものは対象にしない', MK\Product\FormGuide::pendingTitles($lockSeller, (int) $dupId) === []);
    check('他のクリエイターの商品は対象にしない', !in_array('スモーク　ヴィンテージ Tシャツ',
        array_column(MK\Product\FormGuide::pendingTitles(1), 'title'), true));

    wp_delete_post((int) $dupId, true);
    wp_delete_post((int) $otherId, true);
    wp_set_current_user(0);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($lockSeller);
}

echo "\n=== 売れたことが画面で分かる（②） ===\n";
$soldSeller = wp_insert_user(['user_login' => 'mk_smoke_sold_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$soldBuyer  = wp_insert_user(['user_login' => 'mk_smoke_soldb_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (is_wp_error($soldSeller) || is_wp_error($soldBuyer)) {
    check('sold fixtures', false, 'user creation failed');
} else {
    $muteSold = static fn (): bool => false;
    add_filter('mk_should_notify', $muteSold, 99);

    wp_set_current_user($soldSeller);
    ob_start(); MK\Creator\SalesAlert::banner(); $quiet = (string) ob_get_clean();
    check('売れていないときは何も出さない', $quiet === '');

    $soldOrder = wc_create_order(['customer_id' => $soldBuyer, 'status' => 'pending']);
    $soldOrder->update_meta_data('_mk_creator_id', $soldSeller);
    $soldOrder->update_meta_data('_mk_title_snapshot', 'スモーク　黒いコート');
    $soldOrder->update_meta_data('_mk_product_amount', 5000);
    $soldOrder->update_meta_data('_mk_option_amount', 0);
    $soldOrder->save();
    $soldId = $soldOrder->get_id();
    $soldOrder->update_status(MK\Order\Statuses::PAID, 'smoke');

    check('購入済の取引を見つける', count(MK\Creator\SalesAlert::waiting($soldSeller)) === 1);

    ob_start(); MK\Creator\SalesAlert::banner(); $sold = (string) ob_get_clean();
    check('「商品が購入されました」と表示する', str_contains($sold, '商品が購入されました（1件）'));
    check('商品名と注文番号を出す', str_contains($sold, 'スモーク　黒いコート')
        && str_contains($sold, '注文 #' . $soldId));
    check('次にすることを伝える', str_contains($sold, '発送のご準備をお願いします')
        && str_contains($sold, '取引・発送の画面を開く'));

    $navBadge = MK\Creator\SalesAlert::badge(['orders' => ['title' => '注文']]);
    check('メニューに件数を出す', str_contains((string) $navBadge['orders']['title'], 'mk-nav-badge')
        && str_contains((string) $navBadge['orders']['title'], '1'));

    // 発送登録が済めば消える。
    $soldOrder = wc_get_order($soldId);
    $soldOrder->update_status(MK\Order\Statuses::SHIPPED, 'smoke');
    ob_start(); MK\Creator\SalesAlert::banner(); $afterShip = (string) ob_get_clean();
    check('発送登録すると消える', $afterShip === '' && MK\Creator\SalesAlert::waiting($soldSeller) === []);
    check('他のクリエイターには出さない', MK\Creator\SalesAlert::waiting($soldBuyer) === []);

    echo "\n=== マイページの「出品履歴」（①） ===\n";
    $menu = apply_filters('woocommerce_account_menu_items', [
        'dashboard' => 'ダッシュボード', 'orders' => '注文', 'edit-account' => 'アカウント詳細',
    ]);
    check('出品者には「出品履歴」を出す', ($menu[MK\Account\SellerMenu::LISTINGS] ?? '') === '出品履歴');
    check('「注文」は「購入履歴」に', ($menu['orders'] ?? '') === '購入履歴');
    check('ダッシュボードの次に置く', array_slice(array_keys($menu), 0, 2) === ['dashboard', MK\Account\SellerMenu::LISTINGS],
        implode(',', array_keys($menu)));
    check('出品履歴は出品者ダッシュボードへ',
        str_contains(wc_get_endpoint_url(MK\Account\SellerMenu::LISTINGS), '/dashboard/products'),
        wc_get_endpoint_url(MK\Account\SellerMenu::LISTINGS));

    wp_set_current_user($soldBuyer);
    $buyerMenu = apply_filters('woocommerce_account_menu_items', ['dashboard' => 'ダッシュボード', 'orders' => '注文']);
    check('購入者には出品履歴を出さない', !isset($buyerMenu[MK\Account\SellerMenu::LISTINGS])
        && ($buyerMenu['orders'] ?? '') === '購入履歴');

    wp_set_current_user(0);
    remove_filter('mk_should_notify', $muteSold, 99);
    as_unschedule_all_actions('mk_execute_transfer', ['order_id' => $soldId], 'mk-marketplace');
    as_unschedule_all_actions('mk_dispatch_overdue_check', ['order_id' => $soldId], 'mk-marketplace');
    wc_get_order($soldId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($soldSeller); wp_delete_user($soldBuyer);
    check('後片付け', !wc_get_order($soldId) && !get_userdata($soldSeller));
}

echo "\n=== 一覧の表示件数とページ送り（①） ===\n";
if (!function_exists('tb_current_per_page')) {
    check('表示件数の仕組みが読み込まれている', false, 'theme not loaded');
} else {
    $savedGet    = $_GET;
    $savedCookie = $_COOKIE;

    check('選べるのは10・20・50件', tb_per_page_options() === [10, 20, 50], implode(',', tb_per_page_options()));
    check('初期値は20件', tb_per_page_default() === 20);

    // 一覧の件数は、この値がそのまま使われる。
    check('WooCommerceの一覧に反映される', (int) apply_filters('loop_shop_per_page', 24) === tb_current_per_page(),
        (string) apply_filters('loop_shop_per_page', 24));

    // 実際に商品を並べて、10件ずつなら2ページになることを確かめる。
    $pageProducts = [];
    $pageSeller   = get_user_by('login', 'mk_test_creator');

    for ($i = 0; $i < 13; $i++) {
        $p = new WC_Product_Simple();
        $p->set_name('mk smoke ページ送り ' . $i);
        $p->set_status('publish');
        $p->set_regular_price('1000');
        $p->save();
        $pageProducts[] = $p->get_id();

        if ($pageSeller) {
            wp_update_post(['ID' => $p->get_id(), 'post_author' => $pageSeller->ID]);
        }
    }

    $countPages = static function (int $perPage): array {
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => 1,
            'fields'         => 'ids',
        ]);

        return [count($q->posts), (int) $q->max_num_pages, (int) $q->found_posts];
    };

    [$first, $pages, $found] = $countPages(10);
    check('10件ずつなら1ページ目は10件', $first === 10, (string) $first);
    check('残りは次のページへ', $pages >= 2 && $found > 10, $pages . 'ページ／' . $found . '件');

    [$first50, $pages50] = $countPages(50);
    check('50件ずつなら1ページに収まる', $pages50 === 1 && $first50 === $found, $first50 . '件');

    foreach ($pageProducts as $id) {
        wp_delete_post($id, true);
    }

    check('ページ送りの確認用の商品を片付けた', count(get_posts([
        'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1,
        's' => 'mk smoke ページ送り', 'fields' => 'ids',
    ])) === 0);

    $_GET    = $savedGet;
    $_COOKIE = $savedCookie;
}

echo "\n=== 取引・発送の画面（③） ===\n";
$CO = MK\Order\CreatorOrders::class;
check('メニュー名を「取引・発送」に', ($CO::renameMenu(['orders' => ['title' => '注文']])['orders']['title'] ?? '') === '取引・発送');
check('メニューの件数表示と両立する', (static function () use ($CO): bool {
    $nav = $CO::renameMenu(['orders' => ['title' => '注文']]);
    $nav = MK\Creator\SalesAlert::badge($nav);

    return str_starts_with((string) $nav['orders']['title'], '取引・発送');
})());

foreach ([MK\Order\Statuses::PAID => '購入済', MK\Order\Statuses::SHIPPED => '発送済',
          MK\Order\Statuses::RECEIVED => '受取確認'] as $status => $label) {
    check('状態が空欄にならない：' . $label, $CO::statusLabel('', $status) === $label
        && $CO::statusLabel('', 'wc-' . $status) === $label, $CO::statusLabel('', $status));
}
check('Dokanの状態はそのまま', $CO::statusLabel('完了', 'completed') === '完了');
check('一覧の上の件数に数えられる', (static function () use ($CO): bool {
    $counts = $CO::countableStatuses(['wc-completed' => 0, 'total' => 0]);

    return array_key_exists('wc-mk-paid', $counts)
        && array_key_exists('wc-mk-shipped', $counts)
        && array_key_exists('wc-mk-received', $counts)
        && $counts['wc-completed'] === 0;
})());
check('状態の色も付く', $CO::statusClass('', MK\Order\Statuses::PAID) === 'info'
    && $CO::statusClass('', 'wc-' . MK\Order\Statuses::RECEIVED) === 'success'
    && $CO::statusClass('success', 'completed') === 'success');

ob_start(); $CO::intro(); $ordersIntro = (string) ob_get_clean();
check('注文履歴だと一目で分かる見出し', str_contains($ordersIntro, '取引・発送（注文履歴）')
    && str_contains($ordersIntro, '過去の取引も'));
check('取引一覧の先頭に出す', has_action('dokan_order_content_inside_before', [$CO, 'intro']) === 10);
check('ショップに商品がないときの案内も日本語',
    __('No products were found of this vendor!', 'dokan-lite') === 'このクリエイターの商品は、まだありません。',
    __('No products were found of this vendor!', 'dokan-lite'));

$coSeller = wp_insert_user(['user_login' => 'mk_smoke_co_s_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);
$coBuyer  = wp_insert_user(['user_login' => 'mk_smoke_co_b_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'customer']);

if (!is_wp_error($coSeller) && !is_wp_error($coBuyer)) {
    $coOrder = wc_create_order(['customer_id' => $coBuyer, 'status' => 'pending']);
    $coOrder->update_meta_data('_mk_creator_id', $coSeller);
    $coOrder->update_meta_data('_mk_creator_amount', 6880);
    $coOrder->set_shipping_last_name('山田');
    $coOrder->set_shipping_first_name('花子');
    $coOrder->save();
    $coId = $coOrder->get_id();

    check('受取額は手数料を引いた実際の金額', (int) $CO::earning(8000.0, wc_get_order($coId)) === 6880,
        (string) $CO::earning(8000.0, wc_get_order($coId)));
    check('運営側の取り分には手を出さない', $CO::earning(1120.0, wc_get_order($coId), 'admin') === 1120.0);
    check('当サイトの取引でなければそのまま', $CO::earning(500.0, wc_create_order()) === 500.0);

    // Dokan reads its own table before any filter, and writes the whole order
    // total into it. Our figure has to go back over the top.
    global $wpdb;
    $coTable = $wpdb->prefix . 'dokan_orders';
    $wpdb->replace($coTable, [
        'order_id' => $coId, 'seller_id' => $coSeller,
        'order_total' => 8000.0, 'net_amount' => 8000.0, 'order_status' => 'wc-mk-paid',
    ], ['%d', '%d', '%f', '%f', '%s']);

    check('Dokanが書いた受取額を直す', MK\Order\DokanSync::writeFigures(wc_get_order($coId))
        && (float) $wpdb->get_var($wpdb->prepare("SELECT net_amount FROM {$coTable} WHERE order_id = %d", $coId)) === 6880.0,
        (string) $wpdb->get_var($wpdb->prepare("SELECT net_amount FROM {$coTable} WHERE order_id = %d", $coId)));
    check('Dokanの計算もこの金額を返す',
        (int) dokan()->commission->get_earning_by_order(wc_get_order($coId)) === 6880,
        (string) dokan()->commission->get_earning_by_order(wc_get_order($coId)));
    check('取引の状態が変わるたびに直す', has_action('woocommerce_order_status_changed',
        [MK\Order\DokanSync::class, 'syncFigures']) === 99);

    $wpdb->delete($coTable, ['order_id' => $coId], ['%d']);

    check('購入者名はお届け先から', wc_get_order($coId)->get_billing_last_name() === '山田'
        && wc_get_order($coId)->get_billing_first_name() === '花子',
        wc_get_order($coId)->get_formatted_billing_full_name());

    // 受取確認のあと、お届け先は隠される。名前も一緒に消える。
    $coOrder = wc_get_order($coId);
    $coOrder->update_meta_data(MK\Checkout\ShippingAddress::META_MASKED, 'yes');
    $coOrder->save();
    check('お届け先を隠したあとは「購入者」', wc_get_order($coId)->get_billing_last_name() === '購入者'
        && wc_get_order($coId)->get_billing_first_name() === '');

    wc_get_order($coId)->delete(true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($coSeller); wp_delete_user($coBuyer);
}

echo "\n=== SNSでシェアしよう（②）とクリエイター番号（④） ===\n";
$shareSeller = get_user_by('login', 'mk_test_creator');
$shareProduct = null;

if ($shareSeller) {
    $shareProduct = new WC_Product_Simple();
    $shareProduct->set_name('mk smoke シェア');
    $shareProduct->set_status('publish');
    $shareProduct->set_regular_price('1200');
    $shareProduct->save();
    wp_update_post(['ID' => $shareProduct->get_id(), 'post_author' => $shareSeller->ID]);

    $shareHtml = MK\Product\ShareLink::buttons($shareProduct->get_id());
    check('商品ページでシェアを呼びかける', str_contains($shareHtml, 'SNSでシェアしよう！'));
    check('コピーのボタンはそのまま', str_contains($shareHtml, '商品リンクをコピー'));
    check('出品一覧では呼びかけない',
        !str_contains(MK\Product\ShareLink::buttons($shareProduct->get_id(), true), 'SNSでシェアしよう'));

    wp_delete_post($shareProduct->get_id(), true);

    ob_start(); MK\Creator\Onboarding::renderNumberCard($shareSeller->ID); $numberCard = (string) ob_get_clean();
    $creatorNumber = (string) get_user_meta($shareSeller->ID, 'mk_creator_number', true);
    check('クリエイター番号を本人に見せる', str_contains($numberCard, 'あなたのクリエイター番号')
        && $creatorNumber !== '' && str_contains($numberCard, $creatorNumber), $creatorNumber);
    check('番号をコピーできる', str_contains($numberCard, 'data-mk-copy="' . $creatorNumber . '"'));
    check('番号のない人には出さない', (static function (): bool {
        $buyer = get_user_by('login', 'mk_test_buyer');
        ob_start(); MK\Creator\Onboarding::renderNumberCard($buyer ? $buyer->ID : 0); $html = (string) ob_get_clean();

        return $html === '';
    })());
}

echo "\n=== 出品者ダッシュボードの英語表示と売上の数字 ===\n";
$A = MK\I18n\DokanTranslations::analyticsMessages();
foreach (['Overview' => '概要', 'Performance' => '販売状況', 'Charts' => 'グラフ', 'By day' => '日別',
          'Line chart' => '折れ線グラフ', 'Net sales' => '純売上', '%s Report' => '%sのレポート'] as $en => $ja) {
    check('日本語：' . $en, ($A[$en] ?? '') === $ja, $A[$en] ?? '(none)');
}
check('ヘッダーの訳と食い違わない', array_intersect_key($A, MK\I18n\DokanTranslations::scriptMessages()) === []);
check('サーバー側の見出しも日本語', __('Marketplace Commission', 'dokan-lite') === '運営手数料');

$figSchema = ['totals' => ['properties' => [
    'total_sales' => [], 'net_revenue' => [], 'orders_count' => [],
    'total_vendor_earning' => [], 'total_admin_discount' => [], 'total_vendor_discount' => [], 'total_admin_commission' => [],
]]];
$figSeller = wp_insert_user(['user_login' => 'mk_smoke_fig_' . wp_rand(1000, 9999),
    'user_pass' => wp_generate_password(24), 'role' => 'seller']);

if (!is_wp_error($figSeller)) {
    wp_set_current_user($figSeller);
    $forCreator = array_keys(MK\Creator\DashboardFigures::schema($figSchema)['totals']['properties']);
    check('出品者には誤った金額のカードを出さない', $forCreator === ['total_sales', 'net_revenue', 'orders_count'],
        implode(',', $forCreator));
    check('並び順からも外す', MK\Creator\DashboardFigures::indicators(
        ['revenue/total_sales', 'revenue/total_seller_earning', 'revenue/total_admin_commission', 'orders/orders_count']
    ) === ['revenue/total_sales', 'orders/orders_count']);

    wp_set_current_user(1);
    check('運営の管理画面はそのまま', count(MK\Creator\DashboardFigures::schema($figSchema)['totals']['properties']) === 7);

    wp_set_current_user(0);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($figSeller);
}

check('注文一覧の「All」も日本語', (apply_filters('dokan_vendor_dashboard_order_listing_statuses',
    ['all' => 'All', 'wc-mk-paid' => '購入済'])['all'] ?? '') === 'すべて');
check('画面読み上げ用のラベルも日本語', has_action('wp_print_footer_scripts',
    [MK\I18n\DokanTranslations::class, 'ariaLabels']) === 30);

check('売上状況をダッシュボードの一番上に', has_action('dokan_dashboard_before_widgets',
    [MK\Creator\Onboarding::class, 'renderDashboardSummary']) === 10);

echo "\n=== 商品リンクをコピー ===\n";

$SL = MK\Product\ShareLink::class;

$shareProduct = new WC_Product_Simple();
$shareProduct->set_name('スモーク シェアテスト');
$shareProduct->set_regular_price('1000');
$shareProduct->save();
$shareId = $shareProduct->get_id();

$setShareStatus = static function (string $status) use ($shareId): void {
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_status' => $status], ['ID' => $shareId]);
    clean_post_cache($shareId);
};

check('短い商品URL /item/{ID}/', $SL::url($shareId) === home_url('/item/' . $shareId . '/'));

$setShareStatus('publish');
$shareHtml = $SL::buttons($shareId);
check('公開中は商品ページにコピーボタン', str_contains($shareHtml, '商品リンクをコピー') && str_contains($shareHtml, 'data-url="' . esc_attr($SL::url($shareId)) . '"'));
check('スマホ用の共有ボタンも用意', str_contains($shareHtml, 'mk-share__native'));
check('出品者の商品一覧用は短い表記', str_contains($SL::buttons($shareId, true), '>リンクをコピー<'));

$setShareStatus(MK\Product\Statuses::RESERVED);
check('購入手続き中の商品もシェアできる', $SL::isShareable($shareId));

$setShareStatus('pending');
check('審査中は「公開後にリンクをコピーできます」', str_contains($SL::buttons($shareId), '公開後にリンクをコピーできます') && !str_contains($SL::buttons($shareId), 'data-url'));

$setShareStatus('draft');
check('下書きもリンクを出さない', !$SL::isShareable($shareId) && !str_contains($SL::buttons($shareId), 'data-url'));

$wpNotFound = new WP();
$wpNotFound->request = 'item/' . $shareId;
$SL::route($wpNotFound);
check('公開前の商品の短いURLは404', ($wpNotFound->query_vars['error'] ?? '') === '404');

$setShareStatus(MK\Product\Statuses::SOLD);
check('売り切れの商品にはボタンを出さない', $SL::buttons($shareId) === '');

wp_delete_post($shareId, true);

echo "\n=== リンクのプレビュー（OGP） ===\n";

$ogProduct = new WC_Product_Simple();
$ogProduct->set_name('スモーク OGP ワンピース');
$ogProduct->set_regular_price('4800');
$ogProduct->set_short_description("<p>ヴィンテージの\nワンピースです。</p>");
$ogProduct->save();
$og = MK\Product\OpenGraph::forProduct($ogProduct->get_id());

check('商品名がタイトル', ($og['og:title'] ?? '') === 'スモーク OGP ワンピース' && ($og['twitter:title'] ?? '') === 'スモーク OGP ワンピース');
check('説明はタグと改行を除いた一文', ($og['og:description'] ?? '') === 'ヴィンテージの ワンピースです。', $og['og:description'] ?? '');
check('種類は商品、価格は円', ($og['og:type'] ?? '') === 'product' && ($og['product:price:amount'] ?? '') === '4800' && ($og['product:price:currency'] ?? '') === 'JPY');
check('URLは商品ページ', ($og['og:url'] ?? '') === get_permalink($ogProduct->get_id()));
check('画像のない商品はサイトアイコンを使う', ($og['og:image'] ?? '') === (string) get_site_icon_url(512) && ($og['og:image'] ?? '') !== '');
check('サイト名つき', ($og['og:site_name'] ?? '') === get_bloginfo('name'));

$ogProduct->set_short_description('');
$ogProduct->save();
$ogBare = MK\Product\OpenGraph::forProduct($ogProduct->get_id());
check('説明がなければ価格を使う', str_starts_with($ogBare['og:description'] ?? '', '¥4,800'), $ogBare['og:description'] ?? '');

wp_delete_post($ogProduct->get_id(), true);

$ogUploads = wp_get_upload_dir();
$ogWebp    = $ogUploads['path'] . '/mk-smoke-og-' . wp_generate_password(6, false) . '.webp';
$ogImage   = new Imagick();
$ogImage->newImage(1600, 900, new ImagickPixel('#c9a14a'));
$ogImage->setImageFormat('webp');
$ogImage->writeImage($ogWebp);
$ogImage->clear();
$ogAttachment = wp_insert_attachment(['post_mime_type' => 'image/webp', 'post_title' => 'smoke og', 'post_status' => 'inherit'], $ogWebp);
$ogJpeg = MK\Product\OpenGraph::jpegCopy((int) $ogAttachment);
$ogJpegPath = is_array($ogJpeg) ? $ogUploads['basedir'] . '/' . get_post_meta($ogAttachment, MK\Product\OpenGraph::META_JPEG, true)['file'] : '';

check('WebPの写真はプレビュー用にJPEGを作る', is_array($ogJpeg) && str_ends_with($ogJpeg[0], '-og.jpg') && is_readable($ogJpegPath) && wp_get_image_mime($ogJpegPath) === 'image/jpeg', is_array($ogJpeg) ? $ogJpeg[0] : 'null');
check('大きな写真は1200pxに収める', is_array($ogJpeg) && $ogJpeg[1] === 1200 && $ogJpeg[2] === 675, is_array($ogJpeg) ? $ogJpeg[1] . 'x' . $ogJpeg[2] : '');
check('2回目は作り直さず同じものを使う', MK\Product\OpenGraph::jpegCopy((int) $ogAttachment) === $ogJpeg);

$ogPng = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'smoke og png', 'post_status' => 'inherit'], $ogWebp);
check('JPEG・PNGはそのまま使う', MK\Product\OpenGraph::jpegCopy((int) $ogPng) === null);

@unlink($ogJpegPath);
wp_delete_attachment((int) $ogPng, true);
wp_delete_attachment((int) $ogAttachment, true);
@unlink($ogWebp);

// Orders the fixtures above delete leave their Dokan row behind, and
// those rows are what Dokan counts orders from. Harmless -- they point
// at nothing -- but they pile up with every run.
global $wpdb;
$orphans = (int) $wpdb->query(
    "DELETE d FROM {$wpdb->prefix}dokan_orders d
     LEFT JOIN {$wpdb->prefix}wc_orders o ON d.order_id = o.id
     WHERE o.id IS NULL"
);
check('なくなった注文のDokan側の記録を片付けた', $orphans >= 0, $orphans . '件');

echo "\n=== Dokan dashboard header (JS) in Japanese ===\n";
$js = MK\I18n\DokanTranslations::scriptMessages();
check('Visit Store translated', ($js['Visit Store'] ?? '') === 'ショップを見る');
check('Dokan branding replaced by site name', ($js['Dokan'] ?? '') === get_bloginfo('name'));

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
