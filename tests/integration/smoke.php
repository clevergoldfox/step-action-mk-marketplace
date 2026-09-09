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
    ]);

    check('publish demoted to draft', get_post_status($held) === 'draft', get_post_status($held));
    check('held marker written', get_post_meta($held, MK\Product\PublishGate::META_HELD, true) === 'yes');

    // Onboarding completes -> everything that was only waiting goes live.
    update_user_meta($gateUser, MK\Stripe\AccountService::META_STATUS,
        MK\Stripe\AccountService::STATUS_COMPLETED);

    do_action('mk_creator_onboarding_completed', $gateUser);

    check('released on completion', get_post_status($held) === 'publish', get_post_status($held));
    check('held marker cleared', get_post_meta($held, MK\Product\PublishGate::META_HELD, true) === '');

    $free = wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => 'MK smoke free product',
        'post_author' => $gateUser,
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
check('platform fee 2400', $b->platformFee === 2400, (string) $b->platformFee);
check('creator 9600', $b->creatorAmount === 9600, (string) $b->creatorAmount);
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
