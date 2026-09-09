<?php
declare(strict_types=1);

namespace MK\Product;

use MK\Creator\Onboarding;
use MK\Stripe\AccountService;

/**
 * A product may not go on sale until its creator can actually be paid.
 *
 * Dokan's "new vendors can sell immediately" setting is left ON deliberately.
 * Turning it off would block the creator from even preparing a listing, and
 * the point is not to slow them down -- it is to stop a buyer paying for an
 * item whose seller has no completed Stripe account. That is the one failure
 * this system cannot resolve on its own: the platform holds the money, the
 * creator cannot receive it, and the only ways out are a refund or an
 * indefinite hold. Better to never take the payment.
 *
 * So creators may write, edit and save listings freely from the moment they
 * register. Publication is what waits, and it releases by itself the moment
 * Stripe reports onboarding complete -- no admin action, no second submission.
 *
 * The gate is enforced in wp_insert_post_data, the single point every path
 * into the posts table passes through: the vendor dashboard form, the REST
 * API, quick-edit, and bulk-edit alike. Gating the dashboard form alone would
 * leave the REST route wide open, which is the same mistake that made
 * Order\Guard necessary.
 */
final class PublishGate
{
    /** Set on a product that was held back, so the creator can be told why. */
    public const META_HELD = '_mk_held_pending_onboarding';

    /**
     * Whether the save currently in flight was demoted to draft.
     *
     * wp_insert_post_data cannot record the meta itself: a brand-new product
     * has no ID yet at that point, so only edits to existing products would
     * ever get marked -- and an unmarked product is one releaseHeld() will
     * never find, leaving the creator's first listings drafts forever.
     *
     * WordPress runs the filter and then save_post for one post at a time, so
     * this flag is unambiguous even during a bulk edit.
     */
    private static bool $demoted = false;

    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'gate'], 10, 2);
        add_action('save_post_product', [self::class, 'markHeld'], 10, 1);
        add_action('mk_creator_onboarding_completed', [self::class, 'releaseHeld'], 10, 1);

        add_action('dokan_dashboard_content_inside_before', [self::class, 'notice']);
    }

    /** Pairs with the filter above; runs immediately after the row is written. */
    public static function markHeld(int $postId): void
    {
        if (!self::$demoted) {
            return;
        }

        self::$demoted = false;

        update_post_meta($postId, self::META_HELD, 'yes');
    }

    /**
     * @param array<string,mixed> $data    sanitised post data, about to be written
     * @param array<string,mixed> $postarr raw post array as submitted
     * @return array<string,mixed>
     */
    public static function gate(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'product') {
            return $data;
        }

        if (($data['post_status'] ?? '') !== 'publish') {
            return $data;
        }

        $author = (int) ($data['post_author'] ?? 0);

        if ($author === 0) {
            return $data;
        }

        // The platform can publish anything, including on a creator's behalf.
        if (current_user_can('manage_woocommerce')) {
            return $data;
        }

        // Only creators are gated. A product with a non-vendor author is not
        // part of this flow and is none of our business.
        if (!function_exists('dokan_is_user_seller') || !dokan_is_user_seller($author)) {
            return $data;
        }

        if ((new AccountService())->canSell($author)) {
            return $data;
        }

        $data['post_status'] = 'draft';

        // Recorded on the post itself rather than in a transient: the creator
        // may not see the result of this save until much later, and a message
        // that expired before they read it explains nothing. The write happens
        // in markHeld(), once the post actually has an ID.
        self::$demoted = true;

        return $data;
    }

    /**
     * Publish everything that was only ever waiting on onboarding.
     *
     * Fired when AccountService reports completion. Without this the creator
     * would have to go back and re-submit every listing they had made, having
     * been given no clear reason why any of them were drafts.
     */
    public static function releaseHeld(int $userId): void
    {
        if (!(new AccountService())->canSell($userId)) {
            return;
        }

        $held = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'draft',
            'author'         => $userId,
            'posts_per_page' => 100,
            'meta_key'       => self::META_HELD,
            'meta_value'     => 'yes',
            'fields'         => 'ids',
        ]);

        foreach ($held as $productId) {
            delete_post_meta((int) $productId, self::META_HELD);

            wp_update_post([
                'ID'          => (int) $productId,
                'post_status' => 'publish',
            ]);
        }

        if ($held) {
            error_log(sprintf(
                '[mk-marketplace] published %d held product(s) for creator %d after onboarding completed',
                count($held),
                $userId
            ));
        }
    }

    /** Tell the creator why their listings are not live. */
    public static function notice(): void
    {
        $userId = get_current_user_id();

        if ($userId === 0 || (new AccountService())->canSell($userId)) {
            return;
        }

        if (!function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return;
        }

        printf(
            '<div class="dokan-alert dokan-alert-warning">'
            . '売上の受取設定が完了していないため、商品は公開されません。'
            . '下書きとして保存されますので、<a href="%s">売上受取設定</a>を完了してください。'
            . '設定が完了すると、保留中の商品は自動的に公開されます。'
            . '</div>',
            esc_url(dokan_get_navigation_url(Onboarding::PAGE))
        );
    }
}
