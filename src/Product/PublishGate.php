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
 * register. Publication is what waits, and it moves on by itself the moment
 * Stripe reports onboarding complete -- no second submission. "Moves on"
 * respects approval-based publishing: to the approval queue, or straight to
 * live only if the operator had already approved it (see META_RELEASE_TO).
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
     * Where a held product goes once its creator can be paid: 'publish' or
     * 'pending'.
     *
     * Decided at hold time because only then is it known WHO pressed 公開.
     * The operator approving a listing has reviewed it, so it may go live the
     * moment the creator finishes onboarding. The creator publishing their
     * own listing (a trusted seller, or a direct REST call) has had no review,
     * so it goes to the approval queue like any other new listing.
     *
     * Releasing everything straight to publish -- which this once did --
     * turned "finish Stripe onboarding" into a way round approval-based
     * publishing: list, get held, onboard, and the listing is live with
     * nobody having looked at it.
     */
    public const META_RELEASE_TO = '_mk_held_release_to';

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

    /** Release target for the save in flight; see META_RELEASE_TO. */
    private static string $releaseTo = '';

    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'gate'], 10, 2);
        add_action('save_post_product', [self::class, 'markHeld'], 10, 1);
        add_action('mk_creator_onboarding_completed', [self::class, 'releaseHeld'], 10, 1);

        add_action('dokan_dashboard_content_inside_before', [self::class, 'notice']);

        // The product form is where the warning matters most, and neither
        // Dokan's new-product nor edit-product template fires the hook above.
        // Until this was added, a creator could fill in an entire listing
        // without ever being told it could not go on sale.
        add_action('dokan_new_product_before_product_area', [self::class, 'formNotice']);

        add_action('admin_notices', [self::class, 'adminHeldNotice']);
    }

    /**
     * The status a listing by this creator gets when nobody has reviewed it.
     *
     * Dokan's own answer, so a creator the operator has marked as trusted
     * (dokan_publishing) publishes directly here exactly as they would
     * anywhere else in Dokan, and everyone else waits for approval.
     */
    public static function unreviewedStatus(int $author): string
    {
        $status = function_exists('dokan_get_default_product_status')
            ? (string) dokan_get_default_product_status($author)
            : 'pending';

        return $status === 'publish' ? 'publish' : 'pending';
    }

    /**
     * Tell the administrator why their approval did not take.
     *
     * Under approval-based publishing the operator presses 公開 and the
     * listing stays a draft. Without an explanation that is indistinguishable
     * from the button being broken, and the natural response is to press it
     * again, or to go looking for a setting to override.
     */
    public static function adminHeldNotice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'product') {
            return;
        }

        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        if ($postId <= 0 || get_post_meta($postId, self::META_HELD, true) !== 'yes') {
            return;
        }

        $author = (int) get_post_field('post_author', $postId);
        $user   = get_userdata($author);

        $next = get_post_meta($postId, self::META_RELEASE_TO, true) === 'publish'
            ? '出品者が設定を完了すると、この商品は自動的に公開されます（承認済みとして扱います）。'
            : '出品者が設定を完了すると、この商品は「承認待ち」に移ります。そこで内容を確認して公開してください。';

        printf(
            '<div class="notice notice-warning"><p><strong>この商品はまだ公開できません。</strong><br>'
            . '出品者（%s）の売上受取設定が完了していないため、公開すると購入者の代金を'
            . '出品者へ送金できない状態になります。そのため下書きのまま保留しています。<br>%s</p></div>',
            esc_html($user ? $user->display_name : '#' . $author),
            esc_html($next)
        );
    }

    /** Pairs with the filter above; runs immediately after the row is written. */
    public static function markHeld(int $postId): void
    {
        if (!self::$demoted) {
            return;
        }

        self::$demoted = false;

        update_post_meta($postId, self::META_HELD, 'yes');
        update_post_meta($postId, self::META_RELEASE_TO, self::$releaseTo);
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

        $requested = (string) ($data['post_status'] ?? '');

        // 'pending' is held as well as 'publish'. Under approval-based
        // publishing a creator's new listing is saved as pending, and letting
        // it through would fill the operator's queue with listings that cannot
        // be sold even once approved -- the approval would just be held again
        // here, which reads as the 公開 button not working.
        if ($requested !== 'publish' && $requested !== 'pending') {
            return $data;
        }

        $author = (int) ($data['post_author'] ?? 0);

        if ($author === 0) {
            return $data;
        }

        /*
         * No exemption for administrators.
         *
         * There used to be one — "the platform can publish anything, including
         * on a creator's behalf" — and it was harmless while creators
         * published their own listings. The client then chose approval-based
         * publishing, which makes an administrator's click the ONLY way any
         * listing goes live. The exemption stopped being an edge case and
         * became the main path, silently switching the gate off.
         *
         * Proven rather than suspected: an administrator approving a pending
         * listing from a creator with no completed Stripe account put it live,
         * and a buyer could then have paid for an item whose seller cannot
         * receive the money.
         *
         * Whether the creator can be paid is a fact about the AUTHOR, not about
         * whoever pressed the button, so the check follows the author.
         *
         * Which means platform staff as AUTHOR is the exemption, not platform
         * staff as clicker. A listing the operator created themselves is the
         * platform's own: the platform is merchant of record and keeps that
         * money, no transfer to a creator exists, and "can the creator be
         * paid" has no meaning.
         *
         * That exemption cannot rely on dokan_is_user_seller() returning false
         * for administrators, because it returns TRUE — Dokan treats every
         * administrator as a seller. An earlier version of this comment
         * claimed the opposite without checking, and the operator's own
         * listings were then held for want of a Stripe account nobody would
         * ever give them.
         */
        if (user_can($author, 'manage_woocommerce')) {
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

        if ($requested === 'pending') {
            self::$releaseTo = 'pending';
        } else {
            self::$releaseTo = current_user_can('manage_woocommerce')
                ? 'publish'
                : self::unreviewedStatus($author);
        }

        return $data;
    }

    /**
     * Move on everything that was only ever waiting on onboarding.
     *
     * Fired when AccountService reports completion. Without this the creator
     * would have to go back and re-submit every listing they had made, having
     * been given no clear reason why any of them were drafts.
     *
     * "Move on" means to wherever it was headed when it was held: live if the
     * operator had approved it, the approval queue if not. See META_RELEASE_TO.
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

        $moved = ['publish' => 0, 'pending' => 0];

        foreach ($held as $productId) {
            $productId = (int) $productId;
            $target    = (string) get_post_meta($productId, self::META_RELEASE_TO, true);

            // A product held before the target was recorded has no way of
            // proving it was reviewed, so it is treated as not reviewed. The
            // cost is one extra approval; the alternative is a listing live
            // without anyone having looked at it.
            if ($target !== 'publish' && $target !== 'pending') {
                $target = self::unreviewedStatus($userId);
            }

            // Status first, meta after: Notify\Events reads META_HELD during
            // the transition to tell a draft -> publish release (an approval
            // taking effect) from a creator publishing an ordinary draft.
            wp_update_post([
                'ID'          => $productId,
                'post_status' => $target,
            ]);

            delete_post_meta($productId, self::META_HELD);
            delete_post_meta($productId, self::META_RELEASE_TO);

            $moved[$target]++;
        }

        if ($held) {
            error_log(sprintf(
                '[mk-marketplace] released held products for creator %d after onboarding: %d published, %d sent for approval',
                $userId,
                $moved['publish'],
                $moved['pending']
            ));
        }
    }

    /**
     * Whether this creator still has to finish 売上・受取設定 before selling.
     *
     * False for anyone who is not a creator, so the warnings below never
     * appear to the operator or to buyers.
     */
    private static function mustOnboard(int $userId): bool
    {
        if ($userId === 0 || user_can($userId, 'manage_woocommerce')) {
            return false;
        }

        if (!function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return false;
        }

        return !(new AccountService())->canSell($userId);
    }

    /**
     * The warning on the product form itself.
     *
     * Deliberately louder than the dashboard notice: this is the moment the
     * creator is investing effort in a listing, and the one piece of
     * information that changes what they should do next is that it cannot go
     * on sale yet.
     */
    public static function formNotice(): void
    {
        if (!self::mustOnboard(get_current_user_id())) {
            return;
        }

        printf(
            '<div class="mk-onboard-callout" role="alert">'
            . '<p class="mk-onboard-callout__title">出品前に売上の受取設定を完了してください</p>'
            . '<p class="mk-onboard-callout__body">受取設定（本人確認・振込先口座の登録）が完了するまで、'
            . '商品は購入できる状態になりません。入力した内容は下書きとして保存され、'
            . '設定が完了すると自動的に審査へ進みます。</p>'
            . '<a class="mk-onboard-callout__button" href="%s">受取設定をする（約5分）</a>'
            . '</div>',
            esc_url(dokan_get_navigation_url(Onboarding::PAGE))
        );
    }

    /** Tell the creator why their listings are not live. */
    public static function notice(): void
    {
        if (!self::mustOnboard(get_current_user_id())) {
            return;
        }

        printf(
            '<div class="dokan-alert dokan-alert-warning">'
            . '売上の受取設定が完了していないため、商品は公開されません。'
            . '下書きとして保存されますので、<a href="%s">売上・受取設定</a>を完了してください。'
            . '設定が完了すると、保留中の商品は自動的に審査（公開手続き）へ進みます。'
            . '</div>',
            esc_url(dokan_get_navigation_url(Onboarding::PAGE))
        );
    }
}
