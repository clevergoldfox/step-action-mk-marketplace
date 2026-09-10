<?php
declare(strict_types=1);

namespace MK\Creator;

use MK\Ledger\Recorder;
use MK\Order\Statuses;
use MK\Stripe\TransferService;
use MK\Support\Money;

/**
 * What a creator has earned, from the only records that know.
 *
 * ---------------------------------------------------------------------------
 * Why Dokan's own figures are ¥0 and always will be
 * ---------------------------------------------------------------------------
 * Dokan computes vendor earnings from its own tables — wp_dokan_orders and
 * wp_dokan_vendor_balance — which its commission engine populates at checkout.
 * That engine is switched off here on purpose: it splits money at the moment
 * of purchase, which is the Destination/Direct Charges model this project
 * rejected because it makes escrow impossible.
 *
 * So those tables are empty, and Dokan's dashboard reports zero sales for a
 * creator who has sold four things. It is not wrong about its own data; it
 * simply has none. A client testing the flow bought four items and then found
 * 売上合計 ¥0 on the seller side, which is exactly as alarming as it sounds.
 *
 * The authoritative record is the order itself: _mk_creator_amount is the
 * creator's share, snapshotted at purchase from the fee rates in force at that
 * moment, so it stays correct even after an admin changes the rates. The
 * ledger holds anything owed back. This class reads those and nothing else.
 *
 * Nothing is written to Dokan's tables to "fix" the number. Fabricating rows
 * in another plugin's schema to make its widget agree with ours would leave
 * two sources of truth for money, and the whole design of this plugin is that
 * there is one.
 */
final class Earnings
{
    /**
     * @return array{
     *     count:int, gross:int, commission:int, net:int,
     *     awaiting:int, scheduled:int, paid:int, withheld:int, outstanding:int
     * }
     */
    public function summary(int $creatorId): array
    {
        $totals = [
            'count' => 0, 'gross' => 0, 'commission' => 0, 'net' => 0,
            'awaiting' => 0, 'scheduled' => 0, 'paid' => 0, 'withheld' => 0,
            'outstanding' => (new Recorder())->outstanding($creatorId),
        ];

        foreach ($this->orders($creatorId) as $order) {
            $net = (int) $order->get_meta('_mk_creator_amount');

            $totals['count']++;
            $totals['gross']      += (int) $order->get_total();
            $totals['net']        += $net;
            $totals['commission'] += (int) $order->get_meta('_mk_platform_fee');

            // Four states, because "how much have I earned" and "when do I get
            // it" are different questions and a single figure answers neither.
            if ($order->get_meta(TransferService::META_TRANSFER_ID) !== '') {
                $totals['paid'] += $net;
            } elseif ($order->get_meta('_mk_has_open_report') === 'yes') {
                $totals['withheld'] += $net;
            } elseif ($order->get_status() === Statuses::RECEIVED) {
                $totals['scheduled'] += $net;
            } else {
                $totals['awaiting'] += $net;
            }
        }

        return $totals;
    }

    /** @return \WC_Order[] newest first */
    public function orders(int $creatorId, int $limit = 100): array
    {
        $orders = wc_get_orders([
            'limit'      => $limit,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_key'   => '_mk_creator_id',
            'meta_value' => $creatorId,
        ]);

        return is_array($orders) ? $orders : [];
    }

    /** One row's state, in the creator's words. */
    public function stateLabel(\WC_Order $order): string
    {
        if ($order->get_meta(TransferService::META_TRANSFER_ID) !== '') {
            return '送金済み';
        }

        if ($order->get_meta('_mk_has_open_report') === 'yes') {
            return '保留中（確認対応中）';
        }

        return match ($order->get_status()) {
            Statuses::PAID     => '発送待ち',
            Statuses::SHIPPED  => '受取確認待ち',
            Statuses::RECEIVED => '送金待ち',
            'completed'        => '完了',
            'cancelled'        => 'キャンセル',
            'refunded'         => '返金済み',
            default            => '—',
        };
    }

    /** When this order's money is due, if it is scheduled. */
    public function dueLabel(\WC_Order $order): string
    {
        if ($order->get_meta(TransferService::META_TRANSFER_ID) !== '') {
            return '—';
        }

        $due = (string) $order->get_meta('_mk_transfer_due_at');

        return $due !== '' ? get_date_from_gmt($due, 'Y/n/j') . '頃' : '—';
    }

    public static function yen(int $amount): string
    {
        return Money::format($amount);
    }
}
