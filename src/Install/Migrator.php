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
    public const SCHEMA_VERSION = 1;

    private const OPTION_VERSION = 'mk_schema_version';

    public static function run(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        self::seedOptions();

        update_option(self::OPTION_VERSION, self::SCHEMA_VERSION);
    }

    /**
     * Seed settings, without ever overwriting a value the admin has changed.
     */
    private static function seedOptions(): void
    {
        $defaults = [
            'mk_fee_rate'              => 0.16,
            'mk_option_fee_rate'       => 0.40,
            'mk_auto_complete_days'    => 7,
            'mk_payout_hold_days'      => 7,
            'mk_payout_hold_days_new'  => 14,
            'mk_new_creator_threshold' => 3,
            'mk_high_value_threshold'  => 50_000,
            'mk_shipping_mask_days'    => 30,
        ];

        foreach ($defaults as $key => $value) {
            add_option($key, $value);
        }

        // The creator counter. add_option() is a no-op if it already exists,
        // which is what protects previously issued numbers on reactivation.
        add_option('mk_creator_seq', '0');
    }

    public static function needsUpgrade(): bool
    {
        return (int) get_option(self::OPTION_VERSION, 0) < self::SCHEMA_VERSION;
    }
}
