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
 * Requesting `transfers` alone keeps identity verification, which satisfies
 * the 本人確認 requirement, without configuring the creator as a merchant.
 *
 * Do not add `card_payments` "just in case". It is not free.
 *
 * ---------------------------------------------------------------------------
 * Why business_profile is prefilled
 * ---------------------------------------------------------------------------
 * Dropping `card_payments` did NOT, on its own, remove the business-website
 * demand. That was assumed here for some time and it is not what Stripe does.
 * Measured against the live test API, a bare transfers-only JP account comes
 * back with 26 outstanding requirements, and business_profile.url,
 * .product_description and .mcc are all among them.
 *
 * Prefilling business_profile removes all three, taking the creator's own
 * burden from 26 items to 23 and, more to the point, removing the question
 * that actually costs sign-ups: an individual selling one used jacket being
 * asked for their business website.
 *
 * This is accurate rather than a trick. For a marketplace seller the platform
 * IS the storefront, so their Dokan store page is a truthful answer to "where
 * do you sell?" and Stripe supports platforms supplying it on their behalf.
 *
 * business_type is deliberately NOT prefilled. Sending 'individual' would
 * save one more item, but it would silently misclassify a 法人 creator, and
 * a wrong answer on a verification form is worth more than one saved field.
 * The creator declares it themselves in Stripe's hosted flow.
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
            // Answered on the creator's behalf, so Stripe never asks them for
            // a business website. See the class docblock.
            'business_profile' => self::businessProfile($user),
            'settings' => [
                'payouts' => [
                    /*
                     * Weekly, because Japan leaves no faster automatic option:
                     * Stripe rejects interval "daily" outright for JP
                     * merchants, and delay_days is fixed at 4 whatever is
                     * requested. The alternative, "manual", would let us issue
                     * each payout ourselves and shave a few days off, but it
                     * would also make this plugin responsible for creating
                     * payouts and for handling their failures and retries --
                     * a second money-moving subsystem to get right, in
                     * exchange for days. Stripe does that job well; we take
                     * the days.
                     *
                     * The anchor is set explicitly rather than left to
                     * Stripe's default (currently also friday) so that every
                     * creator behaves identically and documented timings do
                     * not silently drift if that default ever changes.
                     *
                     * Resulting wait, measured from the buyer's 受取確認:
                     * our own hold, then 4-10 days for Stripe (funds season
                     * for 4 days, then leave on the first Friday after that).
                     *
                     *   standard     ( 7-day hold)   11-17 days
                     *   new creator  (10-day hold)   14-20 days
                     *   high value   (14-day hold)   18-24 days
                     *
                     * All three hold periods are admin-editable options
                     * (mk_payout_hold_days, mk_payout_hold_days_new,
                     * mk_payout_hold_days_high), so the operator can retune
                     * this without a code change.
                     */
                    'schedule' => [
                        'interval'      => 'weekly',
                        'weekly_anchor' => 'friday',
                    ],
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
     * What this creator sells, and where.
     *
     * The creator's own store page is preferred over the marketplace home
     * page: it is the more truthful answer to "where do you sell?" and gives
     * Stripe something specific to review. It falls back to the site root for
     * a creator whose store has no URL yet.
     *
     * 5999 is Miscellaneous and Specialty Retail Stores, which covers the
     * agreed seven categories without claiming to be any one of them.
     *
     * @return array{mcc:string, product_description:string, url:string}
     */
    private static function businessProfile(WP_User $user): array
    {
        $url = function_exists('dokan_get_store_url')
            ? (string) dokan_get_store_url($user->ID)
            : '';

        return apply_filters('mk_stripe_business_profile', [
            'mcc'                 => '5999',
            'product_description' => 'オンラインマーケットプレイスでのハンドメイド作品・クリエイターグッズの販売',
            'url'                 => $url !== '' ? $url : home_url('/'),
        ], $user);
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

        $previous = (string) get_user_meta($userId, self::META_STATUS, true);

        update_user_meta($userId, self::META_STATUS, $status);

        // Fired on the edge, not on every sync. account.updated arrives
        // repeatedly for an already-verified creator, and re-firing would
        // republish listings the creator had since deliberately unpublished.
        if ($status === self::STATUS_COMPLETED && $previous !== self::STATUS_COMPLETED) {
            do_action('mk_creator_onboarding_completed', $userId);
        }

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
