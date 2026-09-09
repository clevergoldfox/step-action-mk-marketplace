<?php
declare(strict_types=1);

namespace MK\Stripe;

use RuntimeException;
use Stripe\StripeClient;

/**
 * Single point of access to the Stripe SDK.
 *
 * Keys are read from constants defined in wp-config.php and are never stored
 * in wp_options. A secret key in the database is readable by anything with DB
 * access, ends up in every backup, and is dumped verbatim by half the
 * debugging plugins on the internet.
 *
 * Add to wp-config.php:
 *
 *   define('MK_STRIPE_SECRET_KEY',      'sk_test_...');
 *   define('MK_STRIPE_PUBLISHABLE_KEY', 'pk_test_...');
 *   define('MK_STRIPE_WEBHOOK_SECRET',  'whsec_...');
 */
final class Client
{
    private static ?StripeClient $instance = null;

    public static function get(): StripeClient
    {
        if (self::$instance instanceof StripeClient) {
            return self::$instance;
        }

        if (!class_exists(StripeClient::class)) {
            throw new RuntimeException(
                'The Stripe SDK is not installed. Run composer install in the plugin directory.'
            );
        }

        self::$instance = new StripeClient([
            'api_key'        => self::secretKey(),
            'stripe_version' => '2024-06-20',
        ]);

        return self::$instance;
    }

    public static function secretKey(): string
    {
        return self::constant('MK_STRIPE_SECRET_KEY');
    }

    public static function publishableKey(): string
    {
        return self::constant('MK_STRIPE_PUBLISHABLE_KEY');
    }

    public static function webhookSecret(): string
    {
        return self::constant('MK_STRIPE_WEBHOOK_SECRET');
    }

    /**
     * Whether the configured key is a test key.
     *
     * Used to refuse live payments while the site is still in coming-soon
     * mode, and to warn in the admin if a test key survives to launch.
     */
    public static function isTestMode(): bool
    {
        return str_starts_with(self::secretKey(), 'sk_test_');
    }

    public static function isConfigured(): bool
    {
        foreach (['MK_STRIPE_SECRET_KEY', 'MK_STRIPE_PUBLISHABLE_KEY', 'MK_STRIPE_WEBHOOK_SECRET'] as $name) {
            if (!defined($name) || !is_string(constant($name)) || constant($name) === '') {
                return false;
            }
        }

        return true;
    }

    private static function constant(string $name): string
    {
        if (!defined($name) || !is_string(constant($name)) || constant($name) === '') {
            throw new RuntimeException(
                sprintf('%s is not defined in wp-config.php.', $name)
            );
        }

        return constant($name);
    }

    /** @internal for tests */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
