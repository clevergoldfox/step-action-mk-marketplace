<?php
declare(strict_types=1);

namespace MK\Stripe;

use MK\Fee\Calculator;
use MK\Support\Money;
use WC_Order;

/**
 * Collects payment onto the PLATFORM's balance.
 *
 * There is deliberately no `on_behalf_of` and no `transfer_data`. Omitting
 * both makes the platform the merchant of record, which is what allows:
 *
 *   - holding the buyer's money until receipt is confirmed
 *   - the platform, not the creator, deciding when a refund happens
 *   - PayPay, which Stripe does not support for direct or on_behalf_of charges
 *
 * The creator's share leaves later, as a separate Transfer. See TransferService.
 */
final class PaymentService
{
    public const META_INTENT_ID = '_mk_stripe_payment_intent_id';
    public const META_CHARGE_ID = '_mk_stripe_charge_id';

    /**
     * Create the PaymentIntent and snapshot the fee split onto the order.
     *
     * The snapshot happens here, at purchase, and nowhere else. An admin who
     * changes the fee rate tomorrow must not alter what this creator is paid
     * for this sale.
     *
     * @return array{id:string, client_secret:string}
     */
    public function createIntent(WC_Order $order, int $productAmount, int $optionAmount): array
    {
        Money::assertValidPrice($productAmount + $optionAmount);

        $breakdown = Calculator::fromSettings()->calculate($productAmount, $optionAmount);

        foreach ($breakdown->toOrderMeta() as $key => $value) {
            $order->update_meta_data($key, $value);
        }

        $intent = Client::get()->paymentIntents->create([
            // JPY is zero-decimal. Money::toStripeAmount is an identity
            // function that exists so this is explicit and tested rather than
            // an unremarked absence.
            'amount'   => Money::toStripeAmount($breakdown->total),
            'currency' => 'jpy',

            // Ties the eventual Transfer back to this charge in the Stripe
            // dashboard, which is what makes a payout auditable.
            'transfer_group' => 'ORDER_' . $order->get_id(),

            'automatic_payment_methods' => ['enabled' => true],

            'payment_method_options' => [
                // Enforced for every card payment. Authenticated transactions
                // shift liability for fraudulent-use chargebacks to the card
                // issuer, which is the platform's largest uncovered exposure
                // given returns are refused by policy.
                'card' => ['request_three_d_secure' => 'any'],
            ],

            'metadata' => [
                'order_id'   => (string) $order->get_id(),
                'creator_id' => (string) $this->creatorId($order),
            ],
        ]);

        $order->update_meta_data(self::META_INTENT_ID, $intent->id);
        $order->save();

        return [
            'id'            => $intent->id,
            'client_secret' => $intent->client_secret,
        ];
    }

    /**
     * Record the charge id once payment succeeds.
     *
     * TransferService needs it for `source_transaction`, which is what lets a
     * transfer be scheduled against funds that have not finished settling.
     */
    public function recordCharge(WC_Order $order, string $intentId): ?string
    {
        $intent   = Client::get()->paymentIntents->retrieve($intentId);
        $chargeId = $intent->latest_charge;

        if (!is_string($chargeId) || $chargeId === '') {
            return null;
        }

        $order->update_meta_data(self::META_CHARGE_ID, $chargeId);
        $order->save();

        return $chargeId;
    }

    /**
     * Refund the buyer.
     *
     * ⚠️ This does NOT return money the creator has already been sent. Stripe
     * treats charges and transfers as independent. Every refund on an order
     * that has been transferred must be followed by
     * TransferService::reverse(); TransferService::refundAndReverse() does
     * both and is the only method that should be called from a UI.
     */
    public function refund(WC_Order $order, ?int $amount = null): string
    {
        $intentId = (string) $order->get_meta(self::META_INTENT_ID);

        $params = ['payment_intent' => $intentId];

        if ($amount !== null) {
            $params['amount'] = Money::toStripeAmount($amount);
        }

        return Client::get()->refunds->create($params)->id;
    }

    private function creatorId(WC_Order $order): int
    {
        return (int) $order->get_meta('_mk_creator_id');
    }
}
