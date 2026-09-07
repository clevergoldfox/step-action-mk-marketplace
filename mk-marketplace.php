<?php
/**
 * Plugin Name:       MK Marketplace
 * Description:       C2C marketplace money layer — Stripe Connect escrow, dual-rate fees, scheduled transfers, reversals and netting.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Text Domain:       mk-marketplace
 *
 * This plugin owns every movement of money.
 *
 * The multivendor plugin owns the storefront and the vendor dashboard, and its
 * own payment gateway is DISABLED. Multivendor gateways split funds between
 * platform and vendor at the moment of purchase, which is Destination/Direct
 * Charges — the model this project rejected because it makes escrow impossible,
 * prevents platform-initiated refunds, and disqualifies PayPay.
 *
 * Do not add Stripe calls anywhere outside src/Stripe.
 */

declare(strict_types=1);

namespace MK;

if (!defined('ABSPATH')) {
    exit;
}

define('MK_PLUGIN_FILE', __FILE__);
define('MK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MK_VERSION', '0.1.0');

$mk_autoload = MK_PLUGIN_DIR . 'vendor/autoload.php';

if (!file_exists($mk_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>MK Marketplace:</strong> '
            . 'dependencies are not installed. Run <code>composer install</code> '
            . 'in the plugin directory.</p></div>';
    });

    return;
}

require_once $mk_autoload;

register_activation_hook(__FILE__, static function (): void {
    Install\Migrator::run();
});

add_action('plugins_loaded', static function (): void {
    // WooCommerce is a hard dependency: orders, statuses and the scheduler all
    // come from it. Failing loudly beats half-booting into a broken state.
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p><strong>MK Marketplace:</strong> '
                . 'WooCommerce is required and is not active.</p></div>';
        });

        return;
    }

    if (Install\Migrator::needsUpgrade()) {
        Install\Migrator::run();
    }

    Order\Statuses::register();
}, 20);

/**
 * Declare compatibility with High-Performance Order Storage.
 *
 * Without this WooCommerce refuses to enable HPOS while the plugin is active,
 * and orders stay in wp_posts — which is exactly the table we do not want a
 * growing marketplace's order volume living in.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            MK_PLUGIN_FILE,
            true
        );
    }
});
