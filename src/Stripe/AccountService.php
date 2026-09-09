<?php
declare(strict_types=1);

namespace MK\Stripe;

use MK\Creator\Onboarding;
use RuntimeException;
use WP_User;

/**
 * Connected accounts for creators.
 *
 * ---------------------------------------------------------------------------
 * Why only the `transfers` capability
 * ---------------------------------------------------------------------------
 * Testing this on Bubble produced an account demanding SIX pieces of
 * verification, including a **business website**, before it could do anything.
 * Asking an individual selling one used jacket to supply a business website
 * destroys onboarding conversion.
 *
 * That burden came from requesting `card_payments`, which configures the
 * account as a Merchant — a business collecting payments from its own
 * customers. Our creators never do that. The platform is merchant of record;
 * creators only receive transfers.
 *
 * Requesting `transfers` alone keeps identity verification (which satisfies
 * the 本人確認 requirement) while dropping the business-website, product
 * description and MCC requirements entirely.
 *
 * Do not add `card_payments` "just in case". It is not free.
 */
final class AccountService
{
    public const META_ACCOUNT_ID = 'mk_stripe_account_id';
    public const META_STATUS     = 'mk_onboarding_status';

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_RESTRICTED  = 'restricted';

    /**
     * Create the creator's connected account, or return the existing one.
     *
     * Idempotent by design: a creator who double-submits the registration form
     * must not end up with two connected accounts, because only one of them
     * would ever receive transfers and the other would silently accumulate
     * nothing.
     */
    public function ensureAccount(WP_User $user): string
    {
        $existing = (string) get_user_meta($user->ID, self::META_ACCOUNT_ID, true);

        if ($existing !== '') {
            return $existing;
        }

        $account = Client::get()->accounts->create([
            'country' => 'JP',
            'email'   => $user->user_email,
            'controller' => [
                // The platform absorbs disputes and refunds first. This is the
                // necessary consequence of holding funds in escrow, and the
                // recovery mechanism lives in TransferService.
                'losses'                 => ['payments' => 'application'],
                'fees'                   => ['payer'    => 'application'],
                'stripe_dashboard'       => ['type'     => 'express'],
                'requirement_collection' => 'stripe',
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'settings' => [
                'payouts' => [
                    // Default is weekly, which would push a creator's total
                    // wait past three weeks once stacked on our own 7/14-day
                    // hold. The client was promised roughly two.
                    'schedule' => ['interval' => 'daily'],
                ],
            ],
            'metadata' => [
                'wp_user_id'     => (string) $user->ID,
                'creator_number' => (string) get_user_meta($user->ID, 'mk_creator_number', true),
            ],
        ]);

        update_user_meta($user->ID, self::META_ACCOUNT_ID, $account->id);
        update_user_meta($user->ID, self::META_STATUS, self::STATUS_IN_PROGRESS);

        return $account->id;
    }

    /**
     * A one-time URL into Stripe's hosted onboarding.
     *
     * Account Links expire in minutes and are single-use, so generate one per
     * click rather than storing it.
     */
    public function onboardingLink(string $accountId): string
    {
        $link = Client::get()->accountLinks->create([
            'account'     => $accountId,
            'type'        => 'account_onboarding',
            // Query arguments rather than pretty paths: these are only ever
            // seen mid-redirect, and an unflushed rewrite rule would 404 the
            // creator at the exact moment they finish onboarding.
            'refresh_url' => add_query_arg(Onboarding::ACTION_ARG, 'refresh', home_url('/')),
            'return_url'  => add_query_arg(Onboarding::ACTION_ARG, 'return', home_url('/')),
        ]);

        return $link->url;
    }

    /**
     * Refresh our local view of the account from Stripe.
     *
     * Called from the account.updated webhook and when a creator returns from
     * onboarding. Stripe is the authority; our meta is a cache that exists so
     * product pages do not have to make an API call per card.
     */
    public function syncStatus(string $accountId): string
    {
        $account = Client::get()->accounts->retrieve($accountId);

        $userId = $this->userIdForAccount($accountId);

        if ($userId === null) {
            throw new RuntimeException(
                sprintf('No WordPress user is linked to %s.', $accountId)
            );
        }

        $transfers = $account->capabilities->transfers ?? 'inactive';
        $dueNow    = $account->requirements->currently_due ?? [];
        $disabled  = $account->requirements->disabled_reason ?? null;

        if ($transfers === 'active' && $account->payouts_enabled) {
            $status = self::STATUS_COMPLETED;
        } elseif ($disabled !== null && !empty($dueNow)) {
            $status = self::STATUS_RESTRICTED;
        } else {
            $status = self::STATUS_IN_PROGRESS;
        }

        update_user_meta($userId, self::META_STATUS, $status);

        return $status;
    }

    /**
     * May this creator list products?
     *
     * Gating on completed onboarding prevents the situation where a buyer
     * pays for an item from a creator who cannot be paid — money held by the
     * platform with no lawful destination.
     */
    public function canSell(int $userId): bool
    {
        return get_user_meta($userId, self::META_STATUS, true) === self::STATUS_COMPLETED;
    }

    private function userIdForAccount(string $accountId): ?int
    {
        $users = get_users([
            'meta_key'   => self::META_ACCOUNT_ID,
            'meta_value' => $accountId,
            'number'     => 1,
            'fields'     => 'ID',
        ]);

        return isset($users[0]) ? (int) $users[0] : null;
    }
}
