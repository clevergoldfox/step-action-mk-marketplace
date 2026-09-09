<?php
declare(strict_types=1);

namespace MK\Order;

use WC_Order;
use WP_Error;
use WP_REST_Request;

/**
 * Who is allowed to move an order along.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * Registering our statuses with WooCommerce makes them globally available,
 * and Dokan hands the vendor a status dropdown built straight from
 * wc_get_order_statuses(). A creator could therefore select 受取確認 on their
 * own order, which schedules their own payout -- without shipping anything
 * and without the buyer ever confirming receipt.
 *
 * Turning off Dokan's "order status change" setting hides the dropdown, but
 * it is not the fix. REST/OrderControllerV2::process_orders_bulk_action()
 * calls dokan_apply_bulk_order_status_change() directly, and that function
 * checks order OWNERSHIP but never checks the setting -- only the frontend
 * form path checks it. A creator with a session can still POST to
 * /dokan/v1/orders/bulk-actions and set any status on an order they own.
 * dokan_apply_bulk_order_status_change() excludes only 'cancelled' and
 * 'refunded'; every other status, ours included, goes through untouched.
 *
 * ---------------------------------------------------------------------------
 * Two layers, deliberately
 * ---------------------------------------------------------------------------
 * 1. Refuse the request (guardRestStatusChange). Closes the known hole with a
 *    proper 403 rather than letting a bad write land and correcting it after.
 *
 * 2. Refuse to pay (mayReleaseFunds, enforced in Transitions::onReceived).
 *    Layer 1 enumerates routes, and an enumeration of an upstream plugin's
 *    routes is only accurate until that plugin adds one. Dokan Pro, a future
 *    Dokan release, a REST endpoint from some other plugin, or a hand-written
 *    update_status() call would all bypass it. The money layer does not care
 *    how the status arrived: if the creator is the one who caused their own
 *    order to reach 受取確認, no transfer is scheduled and the order is
 *    flagged for a human. That check holds for paths that do not exist yet.
 */
final class Guard
{
    /** Order meta set by Transitions when a release is refused. */
    public const META_BLOCKED = '_mk_release_blocked';

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'guardRestStatusChange'], 10, 3);
    }

    // ------------------------------------------------------------ layer 1

    /**
     * Reject vendor-initiated status writes that are not theirs to make.
     *
     * @param mixed            $result  null unless something already answered
     * @param mixed            $server  unused
     * @param WP_REST_Request  $request
     * @return mixed
     */
    public static function guardRestStatusChange($result, $server, $request)
    {
        // Someone earlier already produced a response; do not override it.
        if ($result !== null) {
            return $result;
        }

        if (!$request instanceof WP_REST_Request) {
            return $result;
        }

        // Reads are unaffected: the dashboard order list filters by ?status=,
        // and blocking that would break the creator's own order screen.
        if ($request->get_method() === 'GET') {
            return $result;
        }

        $route = $request->get_route();

        if (!str_starts_with($route, '/dokan/') || !str_contains($route, 'order')) {
            return $result;
        }

        // Platform staff are the ones who are supposed to be able to do this.
        if (current_user_can('manage_woocommerce')) {
            return $result;
        }

        $status = (string) ($request->get_param('status') ?? $request->get_param('order_status') ?? '');

        if ($status === '') {
            return $result;
        }

        if (self::creatorMaySet($status)) {
            return $result;
        }

        return new WP_Error(
            'mk_forbidden_status_change',
            'この操作は許可されていません。発送登録以外のステータス変更は運営が行います。',
            ['status' => 403]
        );
    }

    /**
     * The only status a creator may set on their own order is 発送済.
     *
     * Everything else either moves money (受取確認, 完了), reverses it
     * (キャンセル, 返金済), or is Stripe's to report (購入済).
     */
    public static function creatorMaySet(string $status): bool
    {
        return Statuses::bare($status) === Statuses::SHIPPED;
    }

    // ------------------------------------------------------------ layer 2

    /**
     * Whether the actor behind the current request may cause a payout.
     *
     * The creator must never be able to release their own funds, however the
     * status change reached us.
     *
     * A request with no logged-in user is the scheduler, a Stripe webhook or
     * WP-CLI -- all of which are the system acting, not the creator.
     */
    public static function mayReleaseFunds(WC_Order $order): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        $actor = get_current_user_id();

        if ($actor === 0) {
            return true;
        }

        return $actor !== (int) $order->get_meta('_mk_creator_id');
    }
}
