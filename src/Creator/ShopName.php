<?php
declare(strict_types=1);

namespace MK\Creator;

/**
 * Every creator has a shop name, whether they typed one or not.
 *
 * Dokan stores it in the vendor's profile settings and reads it back with no
 * fallback, so a creator who registers without filling that field gets an
 * empty string everywhere it is shown. On the shop list that is a card with
 * a picture, a button and no name -- the page looks broken, and the creator
 * cannot be found by name because there is none.
 *
 * There is no filter on the read path (dokan_get_store_info goes straight to
 * the vendor object), so the name is filled in when the account is created
 * and backfilled for accounts that already exist. The creator can still
 * change it in 設定 afterwards; this only decides what it starts as.
 */
final class ShopName
{
    private const SETTINGS_KEY = 'dokan_profile_settings';

    public static function register(): void
    {
        // Dokan's own signal, and the generic one for accounts made any
        // other way (admin, import, our own tests).
        add_action('dokan_new_seller_created', [self::class, 'ensureFor'], 20, 1);
        add_action('user_register', [self::class, 'ensureFor'], 99, 1);
    }

    /** @return bool true if a name was written */
    public static function ensureFor(int $userId): bool
    {
        $user = get_userdata($userId);

        if (!$user || !function_exists('dokan_is_user_seller') || !dokan_is_user_seller($userId)) {
            return false;
        }

        $settings = get_user_meta($userId, self::SETTINGS_KEY, true);

        if (!is_array($settings)) {
            $settings = [];
        }

        if (trim((string) ($settings['store_name'] ?? '')) !== '') {
            return false;
        }

        $name = trim((string) $user->display_name);

        if ($name === '') {
            $name = trim((string) $user->user_login);
        }

        if ($name === '') {
            return false;
        }

        $settings['store_name'] = $name;
        update_user_meta($userId, self::SETTINGS_KEY, $settings);

        return true;
    }

    /**
     * Fill in the creators who registered before this existed.
     *
     * @return int how many were given a name
     */
    public static function backfill(): int
    {
        $filled = 0;

        foreach (get_users(['role' => 'seller', 'fields' => 'ID']) as $userId) {
            if (self::ensureFor((int) $userId)) {
                $filled++;
            }
        }

        return $filled;
    }
}
