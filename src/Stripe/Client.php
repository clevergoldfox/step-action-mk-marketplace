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
 *
 * Optional, for the second webhook destination that listens to creators'
 * connected accounts (see webhookSecrets()):
 *
 *   define('MK_STRIPE_CONNECT_WEBHOOK_SECRET', 'whsec_...');
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

        // No explicit stripe_version. The SDK uses the version it was built
        // against, which is also what the account and the webhook destination
        // are on. Pinning an older version here would put API calls and
        // webhook payloads on different schemas -- and pinning *backwards*
        // past what the installed SDK expects risks parameter shapes it does
        // not produce and response fields it does not know about.
        //
        // If a future upgrade needs pinning, pin forward and to the version
        // the destination shows in the Stripe dashboard, not an arbitrary one.
        self::$instance = new StripeClient([
            'api_key' => self::secretKey(),
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
     * Every signing secret a delivery may legitimately carry.
     *
     * Stripe sends events about the platform's own account and events about
     * creators' connected accounts through separate destinations, and each
     * destination signs with its own secret. account.updated for a creator --
     * the event that says their identity check has cleared and they can be
     * paid -- only ever comes through the second. With one secret, that event
     * was rejected, and a creator verified after leaving the onboarding page
     * stayed "in progress" until they happened to come back (2026-09-19).
     *
     * @return array<int, string>
     */
    public static function webhookSecrets(): array
    {
        $secrets = [self::webhookSecret()];

        if (defined('MK_STRIPE_CONNECT_WEBHOOK_SECRET') && is_string(MK_STRIPE_CONNECT_WEBHOOK_SECRET)
            && MK_STRIPE_CONNECT_WEBHOOK_SECRET !== '') {
            $secrets[] = MK_STRIPE_CONNECT_WEBHOOK_SECRET;
        }

        return array_values(array_unique(array_filter($secrets)));
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
