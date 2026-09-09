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
