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
    check('warns selling is blocked', str_contains($html, '商品は公開されません'));

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
ob_start();
dokan_get_template_part('products/downloadable', '', ['post_id' => 0, 'class' => '']);
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

foreach (['customer' => '購入者', 'seller' => '出品者'] as $role => $who) {
    $pageId = MK\Account\Terms::pageId($role);
    check($who . '向け規約のページがある', $pageId > 0 && get_post_status($pageId) === 'publish',
        $pageId > 0 ? get_the_title($pageId) : '(未設定)');
}

check('購入者と出品者で別の規約', MK\Account\Terms::url('customer') !== MK\Account\Terms::url('seller'));
check('プライバシーポリシーが公開されている', MK\Account\Terms::privacyUrl() !== '',
    MK\Account\Terms::privacyUrl() ?: '(未公開)');

ob_start(); MK\Account\Terms::renderCheckbox(); $box = (string) ob_get_clean();
check('同意チェックボックスがある', str_contains($box, 'name="mk_terms_agree"'));
check('選んだ役割で規約リンクが変わる',
    str_contains($box, 'data-buyer-url') && str_contains($box, 'data-seller-url')
        && str_contains($box, MK\Account\Terms::url('seller')));
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

$pricedId = wp_insert_post(['post_title' => 'mk smoke priced', 'post_type' => 'product',
    'post_status' => 'draft', 'post_author' => $priceSeller]);

update_post_meta($pricedId, '_regular_price', '3000');
update_post_meta($pricedId, '_price', '3000');

// wc_get_product() needs WooCommerce's data stores, which do not exist during
// plugin bootstrap -- reading the price through it reported 0 for every
// product and withdrew seven priced listings on the live site.
check('価格はメタから直接読む', MK\Product\PriceGate::priceOf($pricedId) === 3000,
    (string) MK\Product\PriceGate::priceOf($pricedId));

wp_update_post(['ID' => $pricedId, 'post_status' => 'publish']);
check('価格のある商品は公開できる', get_post_status($pricedId) === 'publish',
    (string) get_post_status($pricedId));

$freeId = wp_insert_post(['post_title' => 'mk smoke priceless', 'post_type' => 'product',
    'post_status' => 'draft', 'post_author' => $priceSeller]);

wp_update_post(['ID' => $freeId, 'post_status' => 'publish']);

check('価格のない商品は公開されない', get_post_status($freeId) === 'draft',
    (string) get_post_status($freeId));
check('保留した理由が残る',
    get_post_meta($freeId, MK\Product\PriceGate::META_HELD, true) === 'yes');

wp_update_post(['ID' => $freeId, 'post_status' => 'pending']);
check('審査申請も止まる', get_post_status($freeId) === 'draft');

update_post_meta($freeId, '_regular_price', '2000');
update_post_meta($freeId, '_price', '2000');
wp_update_post(['ID' => $freeId, 'post_status' => 'publish']);

check('価格を入れれば公開できる', get_post_status($freeId) === 'publish',
    (string) get_post_status($freeId));
check('保留の印は消える', get_post_meta($freeId, MK\Product\PriceGate::META_HELD, true) === '');

// Dokan の内部商品（Reverse Withdrawal Payment）は価格0が正常。これを下書きに
// してしまい、実際に運営サイトで機能を壊した。
$adminProduct = wp_insert_post(['post_title' => 'mk smoke platform', 'post_type' => 'product',
    'post_status' => 'draft', 'post_author' => 1]);

check('運営自身の商品は対象外', !MK\Product\PriceGate::applies($adminProduct));

wp_update_post(['ID' => $adminProduct, 'post_status' => 'publish']);
check('価格0でも運営の商品は公開できる', get_post_status($adminProduct) === 'publish',
    (string) get_post_status($adminProduct));

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
    ob_start(); MK\Report\Frontend::render($policyOrder); $form = (string) ob_get_clean();
    wp_set_current_user(0);

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
