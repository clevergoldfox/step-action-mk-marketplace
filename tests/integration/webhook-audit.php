<?php
/**
 * Audit the Stripe event destination against what we actually handle.
 *
 *     /usr/bin/php8.3 /usr/bin/wp eval-file \
 *         wp-content/plugins/mk-marketplace/tests/integration/webhook-audit.php
 *
 * Run this after creating ANY destination, test or live.
 *
 * Stripe's "Add destination" flow pre-selects a recommended event set, and the
 * default is the billing/subscriptions preset: invoices and subscription
 * schedules, none of which this project uses. The test destination was created
 * that way and subscribed to 18 event types, exactly one of which we handle.
 *
 * Nothing reports this. An unsubscribed event is not an error -- it simply
 * never arrives, and the handler waiting for it never runs. That failure is
 * invisible from inside WordPress and invisible in the Stripe dashboard, whose
 * error rate stays at 0% because nothing was ever delivered to fail. What it
 * costs: a failed payment leaves the item locked as reserved forever, and a
 * chargeback no longer halts the payout, so the money leaves anyway.
 *
 * Read-only. Reports; changes nothing.
 */

/** Every event type dispatched in Stripe\WebhookController::dispatch(). */
const MK_HANDLED_EVENTS = [
    'payment_intent.succeeded',
    'payment_intent.payment_failed',
    'charge.dispute.created',
    'account.updated',
    'transfer.reversed',
    'charge.refunded',
];

$mode = MK\Stripe\Client::isTestMode() ? 'TEST' : 'LIVE';
printf("mode: %s\n\n", $mode);

$endpoints = MK\Stripe\Client::get()->webhookEndpoints->all(['limit' => 20]);

if (!$endpoints->data) {
    echo "no destinations configured in this mode.\n";
    return;
}

$problems = 0;

foreach ($endpoints->data as $ep) {
    printf("destination : %s\n", $ep->url);
    printf("status      : %s\n", $ep->status);
    printf("api_version : %s\n", $ep->api_version ?: '(account default)');

    $enabled = (array) $ep->enabled_events;
    $all     = in_array('*', $enabled, true);

    printf("subscribed  : %d event types%s\n\n", count($enabled), $all ? '  (wildcard *)' : '');

    if (!str_starts_with($ep->url, 'https://')) {
        // Stripe does not follow redirects on delivery: an http URL that 301s
        // to https is a failed delivery, not a redirected one.
        echo "  FAIL  destination is not https -- Stripe will not deliver here\n\n";
        $problems++;
    }

    $missing = [];

    echo "events we handle:\n";
    foreach (MK_HANDLED_EVENTS as $h) {
        $ok = $all || in_array($h, $enabled, true);
        if (!$ok) { $missing[] = $h; }
        printf("  %-5s %s\n", $ok ? 'ok' : 'MISS', $h);
    }

    $extra = array_values(array_diff($enabled, MK_HANDLED_EVENTS));
    if (!$all && $extra) {
        echo "\nsubscribed but not handled (harmless, just noise):\n";
        foreach ($extra as $e) { echo "  -  $e\n"; }
    }

    if ($missing) {
        $problems += count($missing);
        printf("\n=> %d handled event(s) NOT subscribed. Those features are dead:\n",
            count($missing));
        foreach ($missing as $m) { echo "     $m\n"; }
    } else {
        echo "\n=> every handled event is subscribed\n";
    }

    echo "\n";
}

printf("%s\n", $problems === 0
    ? 'AUDIT CLEAN'
    : "AUDIT FOUND {$problems} PROBLEM(S)");
