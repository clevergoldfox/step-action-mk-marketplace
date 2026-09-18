<?php
declare(strict_types=1);

namespace MK\Stripe;

use MK\Order\Statuses;
use MK\Schedule\Jobs;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Receives Stripe events.
 *
 * ---------------------------------------------------------------------------
 * Signature verification
 * ---------------------------------------------------------------------------
 * Every payload is verified cryptographically before a single byte of it is
 * trusted. Without this, anyone who guesses the endpoint can post a fabricated
 * `payment_intent.succeeded` and receive goods for free.
 *
 * The Bubble build could not do this — no-code tools cannot compute an HMAC
 * over a raw request body — and had to work around it by re-fetching every
 * object from Stripe by id. Here it is one line, and it is the reason this
 * layer is more trustworthy on WordPress.
 *
 * ---------------------------------------------------------------------------
 * Idempotency
 * ---------------------------------------------------------------------------
 * Stripe retries on any non-2xx response and can deliver the same event more
 * than once even on success. Processing `payment_intent.succeeded` twice would
 * create two orders; processing a refund twice would reverse a transfer twice.
 * Every event id is claimed in the database first, and a duplicate claim ends
 * the request.
 */
final class WebhookController
{
    private const NAMESPACE = 'mk/v1';
    private const ROUTE     = '/stripe-webhook';

    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route(self::NAMESPACE, self::ROUTE, [
                'methods'  => 'POST',
                'callback' => [self::class, 'handle'],
                // Stripe cannot authenticate as a WordPress user. The
                // signature check inside IS the authentication.
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function endpointUrl(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $payload   = $request->get_body();
        $signature = $request->get_header('stripe_signature') ?? '';

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                Client::webhookSecret()
            );
        } catch (SignatureVerificationException $e) {
            /*
             * Say nothing in the RESPONSE, but always log it.
             *
             * The response stays bare so an attacker probing the endpoint
             * learns nothing beyond "rejected". Logging nothing at all was a
             * different mistake, and an expensive one: a wrong signing secret
             * in wp-config produced exactly this path, and it was silent on
             * both sides. Stripe reported the delivery as failed in a
             * dashboard nobody was watching, our webhook table stayed empty
             * because rejection happens before the event is claimed, and a
             * genuinely paid order simply sat at 決済待ち. Nothing anywhere
             * said "the secret is wrong".
             *
             * The count of recent failures is what distinguishes the two
             * causes: a handful is someone probing, a continuous stream that
             * began when the endpoint was configured is a secret mismatch.
             */
            error_log(sprintf(
                '[mk-marketplace] webhook signature rejected from %s: %s '
                . '(if this repeats for every delivery, MK_STRIPE_WEBHOOK_SECRET '
                . 'does not match the signing secret of the Stripe destination)',
                $request->get_header('x_forwarded_for') ?: 'unknown',
                $e->getMessage()
            ));

            return new WP_REST_Response(['error' => 'invalid signature'], 400);
        } catch (Throwable $e) {
            error_log('[mk-marketplace] webhook payload rejected: ' . $e->getMessage());

            return new WP_REST_Response(['error' => 'malformed payload'], 400);
        }

        if (!self::claim($event->id, $event->type)) {
            // Already handled. 200 so Stripe stops retrying.
            return new WP_REST_Response(['status' => 'duplicate'], 200);
        }

        try {
            self::dispatch($event->type, $event->data->object);
        } catch (Throwable $e) {
            self::release($event->id);

            error_log(sprintf(
                '[mk-marketplace] webhook %s (%s) failed: %s',
                $event->id,
                $event->type,
                $e->getMessage()
            ));

            // 500 asks Stripe to retry. Because the claim was released, the
            // retry will actually be processed rather than dismissed as a
            // duplicate.
            return new WP_REST_Response(['error' => 'handler failed'], 500);
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    /** @param object $object the Stripe object carried by the event */
    private static function dispatch(string $type, object $object): void
    {
        switch ($type) {
            case 'payment_intent.succeeded':
                self::onPaymentSucceeded($object);
                break;

            case 'payment_intent.payment_failed':
                self::onPaymentFailed($object);
                break;

            case 'charge.dispute.created':
                self::onDisputeOpened($object);
                break;

            case 'charge.dispute.closed':
            case 'charge.dispute.updated':
                self::onDisputeChanged($object);
                break;

            case 'account.updated':
                (new AccountService())->syncStatus($object->id);
                break;

            case 'transfer.reversed':
            case 'charge.refunded':
                // Recorded by TransferService when we initiate them. Handled
                // here only to acknowledge Stripe-side actions taken from the
                // dashboard, which must not be silently ignored.
                do_action('mk_stripe_' . str_replace('.', '_', $type), $object);
                break;
        }
    }

    private static function onPaymentSucceeded(object $intent): void
    {
        $order = self::orderFor($intent);

        if (!$order || $order->get_status() !== 'pending') {
            return;
        }

        (new PaymentService())->recordCharge($order, $intent->id);

        $order->update_status(
            Statuses::PAID,
            '決済が完了しました。'
        );

        // The product is committed only now, on Stripe's word rather than the
        // browser's. A client-side callback can be lost or forged.
        $productId = (int) $order->get_meta('_mk_product_id');

        if ($productId > 0) {
            (new \MK\Product\Reservation())->markSold($productId);
        }
    }

    private static function onPaymentFailed(object $intent): void
    {
        $order = self::orderFor($intent);

        if (!$order) {
            return;
        }

        // Release the reservation. Without this an abandoned checkout locks
        // the item permanently and nobody can buy it.
        $productId = (int) $order->get_meta('_mk_product_id');

        if ($productId > 0) {
            (new \MK\Product\Reservation())->release($productId);
        }

        $order->update_status('failed', '決済に失敗したため、商品の予約を解除しました。');
    }

    private static function onDisputeOpened(object $dispute): void
    {
        $order = self::orderForDispute($dispute);

        if (!$order instanceof \WC_Order) {
            return;
        }

        // Stop the money before it leaves. A dispute raised while the transfer
        // is still queued is the cheapest possible moment to intervene: the
        // funds are still ours and no reversal is needed.
        $order->update_meta_data('_mk_has_open_report', 'yes');
        $order->add_order_note('チャージバックが申し立てられました。送金を保留します。');
        $order->save();

        Jobs::cancelTransfer($order->get_id());
        Jobs::cancelAutoComplete($order->get_id());

        do_action('mk_dispute_opened', $order, $dispute);
    }

    /**
     * The dispute moved on: the bank decided, or Stripe re-stated it.
     *
     * Opening one is not the end of it. A dispute lost is a sale the platform
     * has paid for and must now put on somebody; a dispute won is a payout
     * that was held for nothing and should be released. Neither happens by
     * itself, and until this event was handled the operator had no way of
     * learning which one it was except by looking in Stripe (2026-09-19).
     */
    private static function onDisputeChanged(object $dispute): void
    {
        $order = self::orderForDispute($dispute);

        if (!$order instanceof \WC_Order) {
            return;
        }

        do_action('mk_dispute_changed', $order, $dispute);
    }

    private static function orderForDispute(object $dispute): ?\WC_Order
    {
        $charge = (string) ($dispute->charge ?? '');

        if ($charge === '') {
            return null;
        }

        $orders = wc_get_orders([
            'meta_key'   => PaymentService::META_CHARGE_ID,
            'meta_value' => $charge,
            'limit'      => 1,
        ]);

        return empty($orders) ? null : $orders[0];
    }

    private static function orderFor(object $intent): ?\WC_Order
    {
        $orderId = (int) ($intent->metadata->order_id ?? 0);

        if ($orderId <= 0) {
            return null;
        }

        $order = wc_get_order($orderId);

        return $order instanceof \WC_Order ? $order : null;
    }

    /**
     * Claim an event id. Returns false if another delivery already has it.
     *
     * The UNIQUE index on event_id is what makes this atomic — two concurrent
     * deliveries race on the INSERT and exactly one wins.
     */
    private static function claim(string $eventId, string $type): bool
    {
        global $wpdb;

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}mk_webhook_events
                     (event_id, event_type, received_at)
                 VALUES (%s, %s, %s)",
                $eventId,
                $type,
                current_time('mysql', true)
            )
        );

        return $inserted === 1;
    }

    private static function release(string $eventId): void
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'mk_webhook_events',
            ['event_id' => $eventId],
            ['%s']
        );
    }
}
