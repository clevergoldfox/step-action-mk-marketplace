<?php
declare(strict_types=1);

namespace MK\Checkout;

use MK\Order\DispatchDeadline;
use MK\Product\Details;
use MK\Product\MessageVideo;
use MK\Product\Reservation;
use MK\Stripe\AccountService;
use MK\Support\Money;
use RuntimeException;
use WC_Order;
use WC_Product;
use WP_User;

/**
 * Builds a single-item order and freezes everything the money layer needs.
 *
 * ---------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------------
 * The title, price and selected options are copied onto the order rather than
 * referenced. This is not redundancy: in a flea market the product is edited
 * and then disappears the moment it sells, so an order that only holds a
 * pointer would show a blank line item -- or, worse, whatever the creator
 * edited the listing into afterwards.
 *
 * The fee rates are snapshotted separately by PaymentService at the moment the
 * PaymentIntent is created.
 *
 * ---------------------------------------------------------------------------
 * There is no cart
 * ---------------------------------------------------------------------------
 * One product, one order, one creator, one transfer. A cart spanning several
 * creators would mean one charge fanning out to several transfers, each with
 * its own hold period, reversal path and outstanding balance -- for a service
 * where every item is unique and buying two at once is rare.
 */
final class OrderBuilder
{
    public function __construct(
        private readonly Reservation $reservation = new Reservation(),
        private readonly AccountService $accounts = new AccountService(),
    ) {
    }

    /**
     * @param int[] $optionGroupIds options the buyer selected
     *
     * @throws RuntimeException if the item is gone or the creator cannot be paid.
     */
    public function create(
        WC_Product $product,
        WP_User $buyer,
        array $optionGroupIds = [],
        array $request = [],
    ): WC_Order
    {
        $productId = $product->get_id();
        $creatorId = (int) get_post_field('post_author', $productId);

        // Refuse before taking money rather than after. An order against a
        // creator who cannot receive transfers is funds the platform holds
        // with nowhere lawful to send them.
        if (!$this->accounts->canSell($creatorId)) {
            throw new RuntimeException('このクリエイターは現在販売を受け付けていません。');
        }

        if (!$this->reservation->lock($productId, $buyer->ID)) {
            throw new RuntimeException('この商品は他の方が購入手続き中か、既に売却されています。');
        }

        try {
            return $this->build($product, $buyer, $creatorId, $optionGroupIds, $request);
        } catch (\Throwable $e) {
            // Never leave an item locked because order creation failed.
            $this->reservation->release($productId);

            throw $e;
        }
    }

    /** @param int[] $optionGroupIds */
    private function build(
        WC_Product $product,
        WP_User $buyer,
        int $creatorId,
        array $optionGroupIds,
        array $request = [],
    ): WC_Order {
        $productAmount = (int) $product->get_price();

        // Checked here as well as by PriceGate, and thrown as a RuntimeException
        // on purpose: those carry a buyer-facing Japanese message, while
        // Money's InvalidArgumentException is a developer message that the
        // controller can only translate into "時間をおいてお試しください" --
        // advice that is useless, because waiting fixes nothing.
        if ($productAmount < Money::MIN_YEN || $productAmount > Money::MAX_YEN) {
            throw new RuntimeException(
                'この商品は販売価格が設定されていないため、購入手続きに進めません。'
                . '出品者の方に価格の設定をご依頼ください。'
            );
        }

        Money::assertValidPrice($productAmount);

        [$optionAmount, $optionLabel] = $this->resolveOptions($product->get_id(), $optionGroupIds);

        $order = wc_create_order([
            'customer_id' => $buyer->ID,
            'status'      => 'pending',
        ]);

        if (!$order instanceof WC_Order) {
            throw new RuntimeException('注文の作成に失敗しました。');
        }

        $order->add_product($product, 1, [
            'subtotal' => $productAmount + $optionAmount,
            'total'    => $productAmount + $optionAmount,
        ]);

        $order->update_meta_data('_mk_product_id', $product->get_id());
        $order->update_meta_data('_mk_creator_id', $creatorId);
        $order->update_meta_data('_mk_product_amount', $productAmount);
        $order->update_meta_data('_mk_option_amount', $optionAmount);
        $order->update_meta_data('_mk_title_snapshot', $product->get_name());
        $order->update_meta_data('_mk_option_snapshot', $optionLabel);
        $order->update_meta_data('_mk_has_open_report', 'no');

        // The two claims the buyer decided on, frozen at the moment they
        // decided. Read live afterwards, a creator could re-grade a disputed
        // item or stretch their own dispatch deadline by editing the listing.
        $order->update_meta_data(
            DispatchDeadline::META_DISPATCH,
            Details::dispatchOf($product->get_id()) ?: Details::DEFAULT_DISPATCH
        );
        $order->update_meta_data(Details::META_CONDITION, Details::conditionOf($product->get_id()));

        // A listing with a quantity has already had one unit taken for this
        // order. Nothing on the product records that -- several buyers can be
        // holding units at once -- so the order is where it is written down,
        // and it is what the sweeper reads to give an abandoned unit back.
        if (Reservation::tracksStock($product->get_id())) {
            $order->update_meta_data(Reservation::META_HOLDS_UNIT, 'yes');
        }

        // A message video records what the buyer asked for, and swaps the
        // parcel's dispatch promise for the listing's delivery promise.
        if ($request !== [] && MessageVideo::isMessageVideo($product->get_id())) {
            MessageVideo::snapshot($order, $product->get_id(), $request);
        }

        $order->set_currency('JPY');
        $order->calculate_totals(false); // false: no tax recalculation
        $order->save();

        // Without this row Dokan refuses the creator their own order, and every
        // action they take on it -- 発送登録, 送信, 辞退 -- is unreachable.
        // See Order\DokanSync.
        \MK\Order\DokanSync::ensure($order);

        return $order;
    }

    /**
     * Price the buyer's option selection from the creator's own rates.
     *
     * Prices are read from mk_product_options, never from anything the client
     * submitted. A posted price is a posted discount.
     *
     * @param int[] $optionGroupIds
     * @return array{0:int, 1:string} amount, human-readable label
     */
    private function resolveOptions(int $productId, array $optionGroupIds): array
    {
        if ($optionGroupIds === []) {
            return [0, ''];
        }

        global $wpdb;

        $ids = array_map('intval', $optionGroupIds);
        $in  = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT g.name, o.price
                   FROM {$wpdb->prefix}mk_product_options o
                   JOIN {$wpdb->prefix}mk_option_groups g ON g.id = o.option_group_id
                  WHERE o.product_id = %d
                    AND o.is_offered = 1
                    AND g.is_active = 1
                    AND o.option_group_id IN ({$in})",
                $productId,
                ...$ids
            )
        );

        if (count($rows) !== count($ids)) {
            throw new RuntimeException('選択されたオプションは現在利用できません。');
        }

        $amount = 0;
        $labels = [];

        foreach ($rows as $row) {
            $amount  += (int) $row->price;
            $labels[] = sprintf('%s（%s）', $row->name, Money::format((int) $row->price));
        }

        return [$amount, implode(' / ', $labels)];
    }
}
