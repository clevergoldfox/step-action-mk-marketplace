<?php
declare(strict_types=1);

namespace MK\Order;

/**
 * The transaction state machine, expressed as WooCommerce order statuses.
 *
 * The Bubble build carried a separate Transaction type alongside the order,
 * with hand-written guards on every transition. WooCommerce already has a
 * first-class status system with transition hooks, so the state machine and
 * the order are one object here. There is no second record to drift.
 *
 *   pending      決済待ち     awaiting payment
 *   mk-paid      購入済       captured on the PLATFORM balance (escrow begins)
 *   mk-shipped   発送済       schedules auto-complete at +7d
 *   mk-received  受取確認     schedules the transfer at +7d or +14d
 *   completed    完了         transfer executed
 *   cancelled    キャンセル    refunded, nothing ever transferred
 *   refunded     返金済       refunded AND transfer reversed
 *
 * Money only ever moves to the creator on the received -> completed edge.
 * Everything before it is reversible by refunding funds the platform still
 * holds, which is the entire point of the escrow design.
 */
final class Statuses
{
    /**
     * Order statuses in their BARE form, without the `wc-` prefix.
     *
     * WooCommerce uses two spellings of the same status and is strict about
     * which goes where:
     *
     *   bare  (mk-paid)     WC_Order::get_status() returns this,
     *                       woocommerce_order_status_changed passes this,
     *                       WC_Order::update_status() accepts this.
     *   wc-   (wc-mk-paid)  the registered post status, and the array keys of
     *                       wc_get_order_statuses().
     *
     * Getting this wrong does not raise an error, which is what makes it
     * dangerous. WC_Abstract_Order::set_status() validates the incoming status
     * against array_keys(wc_get_order_statuses()) and, on no match, silently
     * substitutes 'pending' instead of failing. A status registered under the
     * wrong spelling therefore produces a state machine that looks correct in
     * every source file and does nothing at runtime: orders sit in 決済待ち,
     * no transition hook fires, and no transfer is ever scheduled.
     *
     * The constants are bare because that is the form the code compares
     * against most often. Anywhere WooCommerce wants a status KEY, convert
     * with wcKey(); never hand-write the prefix.
     */
    public const PAID     = 'mk-paid';
    public const SHIPPED  = 'mk-shipped';
    public const RECEIVED = 'mk-received';

    /** The `wc-`-prefixed form WooCommerce registers and indexes by. */
    public static function wcKey(string $slug): string
    {
        return str_starts_with($slug, 'wc-') ? $slug : 'wc-' . $slug;
    }

    /** The bare form, as returned by WC_Order::get_status(). */
    public static function bare(string $status): string
    {
        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }

    /** @return array<string, array{label:string, plural:string}> keyed by BARE slug */
    public static function custom(): array
    {
        return [
            self::PAID     => ['label' => '購入済',   'plural' => '購入済'],
            self::SHIPPED  => ['label' => '発送済',   'plural' => '発送済'],
            self::RECEIVED => ['label' => '受取確認', 'plural' => '受取確認'],
        ];
    }

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostStatuses']);
        add_filter('wc_order_statuses', [self::class, 'addToOrderStatusList']);
        add_filter(
            'woocommerce_reports_order_statuses',
            [self::class, 'includeInReports']
        );
    }

    public static function registerPostStatuses(): void
    {
        foreach (self::custom() as $slug => $labels) {
            // Registered under the wc- spelling, matching every core status.
            register_post_status(self::wcKey($slug), [
                'label'                     => $labels['label'],
                'public'                    => false,
                'internal'                  => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: order count */
                'label_count'               => _n_noop(
                    $labels['plural'] . ' <span class="count">(%s)</span>',
                    $labels['plural'] . ' <span class="count">(%s)</span>',
                    'mk-marketplace'
                ),
            ]);
        }
    }

    /**
     * Insert the custom statuses in lifecycle order.
     *
     * This array is what set_status() validates against, so a status missing
     * here is a status that cannot be set. WooCommerce also renders it as-is
     * in the admin dropdown, so building it in sequence keeps the list
     * readable for whoever runs the shop.
     *
     * @param array<string,string> $statuses keyed by `wc-` slug
     * @return array<string,string>
     */
    public static function addToOrderStatusList(array $statuses): array
    {
        $ordered = [];

        foreach ($statuses as $key => $label) {
            $ordered[$key] = $label;

            if ($key === 'wc-pending') {
                foreach (self::custom() as $slug => $labels) {
                    $ordered[self::wcKey($slug)] = $labels['label'];
                }
            }
        }

        // If core ever stops shipping wc-pending, append rather than drop the
        // statuses entirely -- losing them here disables the state machine.
        foreach (self::custom() as $slug => $labels) {
            if (!isset($ordered[self::wcKey($slug)])) {
                $ordered[self::wcKey($slug)] = $labels['label'];
            }
        }

        return $ordered;
    }

    /**
     * WC_Admin_Report re-adds the `wc-` prefix itself, so this filter takes
     * the bare form -- the opposite of wc_order_statuses above.
     *
     * @param array<int,string> $statuses
     * @return array<int,string>
     */
    public static function includeInReports(array $statuses): array
    {
        return array_merge($statuses, array_keys(self::custom()));
    }

    /**
     * Statuses in which the platform is still holding the buyer's money.
     *
     * A refund from any of these costs the platform nothing: no transfer has
     * been made, so there is nothing to reverse and nothing to recover.
     *
     * Bare form, for comparison against WC_Order::get_status().
     *
     * @return string[]
     */
    public static function escrowHeld(): array
    {
        return ['pending', self::PAID, self::SHIPPED, self::RECEIVED];
    }
}
