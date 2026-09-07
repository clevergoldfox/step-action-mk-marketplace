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
    public const PAID     = 'mk-paid';
    public const SHIPPED  = 'mk-shipped';
    public const RECEIVED = 'mk-received';

    /** @return array<string, array{label:string, plural:string}> */
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
            register_post_status($slug, [
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
     * WooCommerce renders this array as-is in the admin dropdown, so building
     * it in sequence keeps the list readable for whoever runs the shop.
     *
     * @param array<string,string> $statuses
     * @return array<string,string>
     */
    public static function addToOrderStatusList(array $statuses): array
    {
        $ordered = [];

        foreach ($statuses as $key => $label) {
            $ordered[$key] = $label;

            if ($key === 'wc-pending') {
                foreach (self::custom() as $slug => $labels) {
                    $ordered[$slug] = $labels['label'];
                }
            }
        }

        return $ordered;
    }

    /**
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
     * @return string[]
     */
    public static function escrowHeld(): array
    {
        return ['wc-pending', self::PAID, self::SHIPPED, self::RECEIVED];
    }
}
