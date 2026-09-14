<?php
declare(strict_types=1);

namespace MK\Install;

/**
 * Creates the plugin's own tables and seeds its settings.
 *
 * Relational, high-volume data is NOT stored in post meta. Meta queries cannot
 * be indexed usefully and degrade sharply once a marketplace has real traffic;
 * follows and messages in particular would be unqueryable at any scale worth
 * having. Products, orders and categories stay native to WooCommerce, where
 * the platform already provides good structures.
 */
final class Migrator
{
    /** Bump when a table definition changes. */
    public const SCHEMA_VERSION = 12;

    private const OPTION_VERSION = 'mk_schema_version';

    public static function run(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Captured before seeding, so a data migration can tell a fresh
        // install from an upgrade and act only on the latter.
        $from = (int) get_option(self::OPTION_VERSION, 0);

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        $tables = [];

        // Buyer <-> creator conversation, scoped to one order.
        $tables[] = "CREATE TABLE {$p}mk_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            receiver_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY receiver_unread (receiver_id, is_read)
        ) {$charset};";

        // UNIQUE stops a double-tap creating two follows and inflating the
        // denormalised follower_count, which nothing would ever correct.
        $tables[] = "CREATE TABLE {$p}mk_follows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            follower_id BIGINT UNSIGNED NOT NULL,
            creator_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY follower_creator (follower_id, creator_id),
            KEY creator_id (creator_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE {$p}mk_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reporter_id BIGINT UNSIGNED NOT NULL,
            target_type VARCHAR(20) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(40) NOT NULL,
            comment TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            admin_note TEXT NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY target (target_type, target_id)
        ) {$charset};";

        // Who changed the fee rates, when, and from what. Needed the first
        // time a creator disputes the commission on an old order.
        $tables[] = "CREATE TABLE {$p}mk_fee_rate_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            changed_by BIGINT UNSIGNED NOT NULL,
            old_product_rate DECIMAL(6,4) NULL,
            new_product_rate DECIMAL(6,4) NULL,
            old_option_rate DECIMAL(6,4) NULL,
            new_option_rate DECIMAL(6,4) NULL,
            changed_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};";

        // Admin-defined option types. Deliberately carries no price: the
        // creator sets the price per product in mk_product_options.
        $tables[] = "CREATE TABLE {$p}mk_option_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY active_sorted (is_active, sort_order)
        ) {$charset};";

        $tables[] = "CREATE TABLE {$p}mk_product_options (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            option_group_id BIGINT UNSIGNED NOT NULL,
            price INT NOT NULL DEFAULT 0,
            is_offered TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY product_group (product_id, option_group_id)
        ) {$charset};";

        $tables[] = "CREATE TABLE {$p}mk_carriers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            supports_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY active_sorted (is_active, sort_order)
        ) {$charset};";

        // Append-only. Every credit and debit against a creator, with the
        // balance after it. A single `unrecovered_amount` field can tell you
        // what is owed but never why, and "why" is the whole of a payout
        // dispute.
        $tables[] = "CREATE TABLE {$p}mk_creator_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            entry_type VARCHAR(30) NOT NULL,
            amount INT NOT NULL,
            balance_after INT NOT NULL,
            stripe_object_id VARCHAR(80) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_created (user_id, created_at),
            KEY order_id (order_id)
        ) {$charset};";

        /*
         * The creator's outstanding balance, with user_id as the PRIMARY KEY.
         *
         * This lived in wp_usermeta and was maintained with
         * INSERT ... ON DUPLICATE KEY UPDATE, which requires a unique index on
         * the conflicting columns. wp_usermeta has none: its keys on user_id
         * and meta_key are both non-unique. So the statement never updated
         * anything -- it inserted a second row every time, get_user_meta()
         * kept returning the original value, and a debt could be deducted
         * from every future payout while never being cleared.
         *
         * A primary key on user_id is exactly the constraint that statement
         * always needed, and makes the read-modify-write genuinely atomic in
         * one round trip.
         */
        $tables[] = "CREATE TABLE {$p}mk_creator_balances (
            user_id BIGINT UNSIGNED NOT NULL,
            outstanding INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id)
        ) {$charset};";

        // Idempotency guard for Stripe webhooks. Stripe retries on any
        // non-2xx and can redeliver even after a success, so every event id is
        // claimed here before it is processed. The UNIQUE index is what makes
        // the claim atomic: two concurrent deliveries race on the INSERT and
        // exactly one wins. Without it, a redelivered payment_intent.succeeded
        // would mark a second product sold, and a redelivered refund would
        // reverse a transfer twice.
        $tables[] = "CREATE TABLE {$p}mk_webhook_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(80) NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            received_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY received_at (received_at)
        ) {$charset};";

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        self::seedOptions();
        self::seedCarriers();
        self::splitHoldPeriods($from);
        self::rebuildCreatorBalances($from);
        self::recordListingModeration();
        self::useFullListingForm();
        self::useLegacyProductEditor();
        self::nameExistingShops();
        self::withdrawPricelessListings();

        update_option(self::OPTION_VERSION, self::SCHEMA_VERSION);
    }

    /**
     * Take down anything already on sale that has no price.
     *
     * PriceGate stops it happening from now on, but it runs on save and these
     * listings are already published -- nothing will save them again until
     * someone edits them, and until then they keep offering a buy button that
     * cannot work. One such listing is what the client reported.
     *
     * Withdrawn to draft rather than deleted: the listing is somebody's work
     * and the only thing wrong with it is a missing number. It carries the
     * same marker a gated save would leave, so its creator gets the same
     * explanation and the same link back to the edit screen.
     */
    private static function withdrawPricelessListings(): void
    {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => 500,
            'fields'         => 'ids',
        ]);

        $withdrawn = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            if (!\MK\Product\PriceGate::applies($id)) {
                continue;
            }

            if (\MK\Product\PriceGate::isSellable(\MK\Product\PriceGate::priceOf($id))) {
                continue;
            }

            wp_update_post(['ID' => $id, 'post_status' => 'draft']);
            update_post_meta($id, \MK\Product\PriceGate::META_HELD, 'yes');

            $withdrawn++;
        }

        if ($withdrawn > 0) {
            error_log(sprintf('[mk-marketplace] withdrew %d listings with no price', $withdrawn));
        }
    }

    /**
     * Seed settings, without ever overwriting a value the admin has changed.
     */
    private static function seedOptions(): void
    {
        $defaults = [
            'mk_fee_rate'              => 0.14,
            'mk_option_fee_rate'       => 0.40,
            'mk_auto_complete_days'    => 7,
            // Three tiers, agreed with the client. A new creator waits longer
            // than an established one because a first-sale runner is the
            // cheapest fraud to commit; a large order waits longest because
            // it is the one worth committing it for. These were a single
            // setting until the client chose to separate them.
            'mk_payout_hold_days'      => 7,
            'mk_payout_hold_days_new'  => 10,
            'mk_payout_hold_days_high' => 14,
            'mk_new_creator_threshold' => 3,
            'mk_high_value_threshold'  => 50_000,
            'mk_shipping_mask_days'    => 30,
            // How many missed dispatch deadlines make a pattern worth the
            // operator's attention. Nothing happens automatically at this
            // number -- it is when they are told. See Creator\Restriction.
            'mk_late_dispatch_threshold' => 3,
        ];

        foreach ($defaults as $key => $value) {
            add_option($key, $value);
        }

        // The creator counter. add_option() is a no-op if it already exists,
        // which is what protects previously issued numbers on reactivation.
        add_option('mk_creator_seq', '0');
    }

    /**
     * Record the client's choice of approval-based publishing explicitly.
     *
     * New creator listings already went to `pending`, but only because Dokan
     * defaults dokan_selling[product_status] to 'pending' when it is unset. A
     * decision the client made deliberately should not rest on another
     * plugin's default, which a Dokan update could change without anyone
     * noticing — listings would start going live unreviewed, silently.
     *
     * Set only when unset. The client has said they may move to instant
     * publishing later; once someone changes it in Dokan's settings, this
     * must never put it back.
     */
    private static function recordListingModeration(): void
    {
        $selling = get_option('dokan_selling', []);

        if (!is_array($selling)) {
            $selling = [];
        }

        if (!isset($selling['product_status'])) {
            $selling['product_status'] = 'pending';
            update_option('dokan_selling', $selling);
        }
    }

    /**
     * One listing form, not two.
     *
     * Dokan offers a second, cut-down "quick add" form in a popup, reached
     * from 新しい商品を追加 on the product list; the tab bar's 出品 goes to the
     * full page. Two forms meant every change the client asked for -- button
     * sizes, the image controls, the 受取設定 callout -- had a second place to
     * go wrong, and a modal form is the cramped one on a phone.
     *
     * Set only when unset, like product_status: turning the popup back on in
     * Dokan's settings must stick.
     */
    private static function useFullListingForm(): void
    {
        $selling = get_option('dokan_selling', []);

        if (!is_array($selling)) {
            $selling = [];
        }

        if (!isset($selling['disable_product_popup'])) {
            $selling['disable_product_popup'] = 'on';
            update_option('dokan_selling', $selling);
        }
    }

    /**
     * One product editor, the one everything else is built on.
     *
     * Dokan 4.x ships two: the PHP form (dokan_get_navigation_url('new-product'),
     * where the tab bar's 出品 goes) and a React editor at
     * /dashboard/new/#products/{id}/edit. The site was set to the React one,
     * so a creator CREATED a listing in the PHP form and EDITED it in the
     * React one -- two different screens for one job.
     *
     * Everything built for creators hangs off the PHP form: the option
     * pricing panel, the 受取設定 callout, and the button, image and label
     * work the client reviewed. In the React editor none of it exists, so
     * options could not be priced on an existing product at all.
     *
     * Pinned once. If the operator later chooses the React editor in Dokan's
     * settings, the marker stops this putting it back -- the same rule as
     * product_status.
     */
    private static function useLegacyProductEditor(): void
    {
        if (get_option('mk_product_editor_pinned') === 'yes') {
            return;
        }

        $appearance = get_option('dokan_appearance', []);

        if (!is_array($appearance)) {
            $appearance = [];
        }

        $appearance['vendor_product_editor'] = 'legacy';

        update_option('dokan_appearance', $appearance);
        update_option('mk_product_editor_pinned', 'yes');
    }

    /**
     * Give every existing creator a shop name.
     *
     * Dokan reads the name with no fallback, so creators who registered
     * without filling that field showed as a nameless card on the shop list.
     * New accounts are handled as they are created; this catches the ones
     * that already existed.
     */
    private static function nameExistingShops(): void
    {
        $filled = \MK\Creator\ShopName::backfill();

        if ($filled > 0) {
            error_log(sprintf('[mk-marketplace] named %d shop(s) that had no store name', $filled));
        }
    }

    /**
     * Rebuild outstanding balances from the ledger.
     *
     * The ledger is append-only and is the authoritative record: every debt
     * incurred and every recovery is a row. The balance is a cache of it, and
     * the old cache in wp_usermeta is not merely stale but ambiguous -- the
     * broken write left duplicate rows per user, so there is no single value
     * to carry across.
     *
     * Deriving it from the ledger repairs whatever the duplicates did, and is
     * correct regardless of how far the corruption had progressed.
     */
    private static function rebuildCreatorBalances(int $from): void
    {
        if ($from === 0 || $from >= 6) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT user_id,
                    GREATEST(0, SUM(CASE entry_type
                        WHEN 'debt_incurred'  THEN amount
                        WHEN 'debt_recovered' THEN -amount
                        ELSE 0 END)) AS outstanding
               FROM {$wpdb->prefix}mk_creator_ledger
              GROUP BY user_id"
        );

        foreach ((array) $rows as $row) {
            $wpdb->replace(
                $wpdb->prefix . 'mk_creator_balances',
                [
                    'user_id'     => (int) $row->user_id,
                    'outstanding' => (int) $row->outstanding,
                    'updated_at'  => current_time('mysql', true),
                ],
                ['%d', '%d', '%s']
            );
        }

        // Remove the duplicated meta rows the broken write left behind.
        $wpdb->delete($wpdb->usermeta, ['meta_key' => 'mk_unrecovered_amount'], ['%s']);
    }

    /**
     * Separate the new-creator hold from the high-value hold.
     *
     * One option, mk_payout_hold_days_new, used to drive both conditions, so
     * they could not differ. The client has since chosen 10 days for a new
     * creator and 14 for a large order, which needs two settings.
     *
     * seedOptions() gives a fresh install the right values, and add_option()
     * is a no-op on an existing one -- which is exactly the problem here,
     * because that leaves an upgraded site holding the old shared 14.
     *
     * Only a value still sitting at that old default is retuned. If the
     * operator has already chosen something else, their number is theirs and
     * an installer must not quietly overwrite a payout policy someone set on
     * purpose.
     */
    private static function splitHoldPeriods(int $from): void
    {
        // 0 is a fresh install: seedOptions() has just written 10 and 14.
        if ($from === 0 || $from >= 4) {
            return;
        }

        if ((int) get_option('mk_payout_hold_days_new') === 14) {
            update_option('mk_payout_hold_days_new', 10);
        }
    }

    /**
     * The carriers a creator can pick from when registering a shipment.
     *
     * Seeded only when the table is empty. The operator can add, rename and
     * deactivate carriers from the admin, and re-running the installer must
     * not resurrect a carrier they deliberately turned off or overwrite a
     * name they changed.
     *
     * supports_anonymous is 0 across the board: 匿名配送 needs a contract and
     * an API integration per carrier, and none is in place. The column exists
     * so that turning one on later is a data change rather than a migration.
     */
    private static function seedCarriers(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mk_carriers';

        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0) {
            return;
        }

        $carriers = [
            'ヤマト運輸',
            '日本郵便',
            '佐川急便',
            '西濃運輸',
            '福山通運',
            // Kept last and deliberately vague. A creator who used a method
            // nobody listed still has to be able to register the shipment --
            // otherwise the order never reaches 発送済, the auto-complete
            // timer never starts, and they never get paid.
            'その他',
        ];

        foreach ($carriers as $i => $name) {
            $wpdb->insert(
                $table,
                [
                    'name'               => $name,
                    'sort_order'         => ($i + 1) * 10,
                    'is_active'          => 1,
                    'supports_anonymous' => 0,
                ],
                ['%s', '%d', '%d', '%d']
            );
        }
    }

    public static function needsUpgrade(): bool
    {
        return (int) get_option(self::OPTION_VERSION, 0) < self::SCHEMA_VERSION;
    }
}
