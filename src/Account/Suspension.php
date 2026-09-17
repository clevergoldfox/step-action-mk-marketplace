<?php
declare(strict_types=1);

namespace MK\Account;

use MK\Creator\Restriction;
use WP_Error;
use WP_User;

/**
 * 利用停止 -- the operator closing an account (terms 第8条), and keeping it closed.
 *
 * ---------------------------------------------------------------------------
 * Suspension
 * ---------------------------------------------------------------------------
 * Applied by hand on the user's profile screen in wp-admin. A suspended
 * account cannot log in or reset its password, every session ends at once,
 * and a creator's listings come down through the same path as 出品制限. The
 * data stays: orders, messages and reports are still needed to settle what
 * the account left behind.
 *
 * ---------------------------------------------------------------------------
 * No way back in under the same address (2026-09-19)
 * ---------------------------------------------------------------------------
 * Withdrawal frees an account's email address so an ordinary member can sign
 * up again later. The client does not want that for anyone the operator has
 * acted against: a suspended account, or a creator under 出品制限. Leaving and
 * re-registering must not wipe the slate.
 *
 * So registration, and changing the email on an existing account, are
 * refused when the address -- or, for creator sign-up, the phone number --
 * matches an account under either measure. It is decided from the measure as
 * it stands now, not from a list written at withdrawal: lifting the measure
 * lets the person back, which is what lifting it means.
 *
 * Addresses are compared loosely on purpose. 「同一メールアドレス等」 has to
 * survive the usual tricks: case, a +tag, and the dots Gmail ignores.
 */
final class Suspension
{
    public const META         = 'mk_suspended';
    public const META_AT      = 'mk_suspended_at';
    public const META_NOTE    = 'mk_suspended_note';
    public const META_PHONES  = 'mk_withdrawn_phones';

    private const NONCE = 'mk_suspension';

    public const REFUSAL = 'このメールアドレス・電話番号ではご登録いただけません。詳しくは運営までお問い合わせください。';

    public static function register(): void
    {
        add_filter('wp_authenticate_user', [self::class, 'refuseLogin'], 98, 1);
        add_filter('allow_password_reset', [self::class, 'refuseReset'], 98, 2);

        add_filter('woocommerce_registration_errors', [self::class, 'guardRegistration'], 30, 3);
        add_action('woocommerce_save_account_details_errors', [self::class, 'guardEmailChange'], 10, 2);

        add_action('show_user_profile', [self::class, 'renderProfile']);
        add_action('edit_user_profile', [self::class, 'renderProfile']);
        add_action('edit_user_profile_update', [self::class, 'saveProfile']);
    }

    public static function isSuspended(int $userId): bool
    {
        return $userId > 0 && get_user_meta($userId, self::META, true) === 'yes';
    }

    /** Suspended, or a creator under 出品制限: the measures that bar re-registration. */
    public static function isUnderMeasure(int $userId): bool
    {
        return self::isSuspended($userId) || Restriction::isRestricted($userId);
    }

    public static function suspend(int $userId, string $note, int $adminId): void
    {
        update_user_meta($userId, self::META, 'yes');
        update_user_meta($userId, self::META_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_NOTE, $note);

        // A suspended creator is also a restricted one: listings down, no new ones.
        if (function_exists('dokan_is_user_seller') && dokan_is_user_seller($userId) && !Restriction::isRestricted($userId)) {
            Restriction::set($userId, true, $note !== '' ? '利用停止：' . $note : '利用停止');
        }

        \WP_Session_Tokens::get_instance($userId)->destroy_all();

        do_action('mk_user_suspended', $userId, $note, $adminId);
    }

    public static function lift(int $userId, string $note): void
    {
        delete_user_meta($userId, self::META);
        delete_user_meta($userId, self::META_AT);
        update_user_meta($userId, self::META_NOTE, $note);

        do_action('mk_user_unsuspended', $userId);
    }

    // --------------------------------------------------------- matching

    /** One shape for an address, so aliases of it compare equal. */
    public static function normaliseEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if (!str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local  = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', function_exists('mb_convert_kana') ? mb_convert_kana($phone, 'n') : $phone) ?? '';

        return strlen($digits) >= 10 ? $digits : '';
    }

    /**
     * Does this address or phone number belong to an account under a measure?
     *
     * Checked against the address the account has now and the one it had
     * when it withdrew, and every phone number it has given.
     */
    public static function isBarred(string $email, string $phone = ''): bool
    {
        $email = $email !== '' ? self::normaliseEmail($email) : '';
        $phone = self::normalisePhone($phone);

        if ($email === '' && $phone === '') {
            return false;
        }

        $ids = get_users([
            'fields'     => 'ID',
            'number'     => -1,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::META, 'value' => 'yes'],
                ['key' => Restriction::USER_META, 'value' => 'yes'],
            ],
        ]);

        foreach ($ids as $id) {
            $id   = (int) $id;
            $user = get_userdata($id);

            if (!$user instanceof WP_User) {
                continue;
            }

            if ($email !== '') {
                $addresses = [$user->user_email, (string) get_user_meta($id, Withdrawal::META_EMAIL, true)];

                foreach ($addresses as $address) {
                    if ($address !== '' && self::normaliseEmail($address) === $email) {
                        return true;
                    }
                }
            }

            if ($phone !== '' && in_array($phone, self::phonesOf($id), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] every phone number this account has given, normalised */
    public static function phonesOf(int $userId): array
    {
        $profile = get_user_meta($userId, 'dokan_profile_settings', true);
        $stored  = get_user_meta($userId, self::META_PHONES, true);

        $raw = array_merge(
            [
                (string) get_user_meta($userId, 'billing_phone', true),
                (string) get_user_meta($userId, 'shipping_phone', true),
                is_array($profile) ? (string) ($profile['phone'] ?? '') : '',
            ],
            is_array($stored) ? $stored : []
        );

        return array_values(array_unique(array_filter(array_map([self::class, 'normalisePhone'], $raw))));
    }

    // ----------------------------------------------------------- guards

    /**
     * @param WP_Error $errors
     * @return WP_Error
     */
    public static function guardRegistration($errors, $username = '', $email = '')
    {
        if (!$errors instanceof WP_Error) {
            return $errors;
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash((string) $_POST['phone'])) : '';

        if (self::isBarred((string) $email, $phone)) {
            $errors->add('mk_barred', self::REFUSAL);
        }

        return $errors;
    }

    /**
     * @param WP_Error $errors
     * @param mixed    $user the account being saved, with the new address on it
     */
    public static function guardEmailChange($errors, $user): void
    {
        if (!$errors instanceof WP_Error || !is_object($user) || empty($user->user_email)) {
            return;
        }

        $current = get_userdata((int) ($user->ID ?? 0));

        if ($current instanceof WP_User && self::normaliseEmail($current->user_email) === self::normaliseEmail((string) $user->user_email)) {
            return;
        }

        if (self::isBarred((string) $user->user_email)) {
            $errors->add('mk_barred', 'このメールアドレスはご利用いただけません。詳しくは運営までお問い合わせください。');
        }
    }

    /**
     * @param WP_User|WP_Error $user
     * @return WP_User|WP_Error
     */
    public static function refuseLogin($user)
    {
        if ($user instanceof WP_User && self::isSuspended($user->ID)) {
            return new WP_Error('mk_suspended', 'このアカウントは利用停止中のため、ログインできません。詳しくは運営までお問い合わせください。');
        }

        return $user;
    }

    /** @param bool|WP_Error $allow */
    public static function refuseReset($allow, $userId = 0)
    {
        return self::isSuspended((int) $userId) ? false : $allow;
    }

    // ----------------------------------------------------------- admin

    public static function renderProfile(WP_User $user): void
    {
        if (!current_user_can('manage_woocommerce') || user_can($user, 'manage_woocommerce')) {
            return;
        }

        $suspended = self::isSuspended($user->ID);

        echo '<h2>TREASURE BUZZ：利用停止</h2><table class="form-table"><tbody><tr><th scope="row">利用停止</th><td>';
        wp_nonce_field(self::NONCE . '_' . $user->ID, '_mk_suspension_nonce');

        printf(
            '<label><input type="checkbox" name="mk_suspended" value="1"%s> この会員を利用停止にする</label>'
            . '<p class="description">利用停止中はログインできません。出品者の場合は出品も非公開になります。'
            . '利用停止中の会員（および出品制限中の出品者）は、退会後も同じメールアドレス・電話番号で再登録できません。</p>',
            checked($suspended, true, false)
        );

        if ($suspended) {
            printf(
                '<p>停止日時：%s</p>',
                esc_html(get_date_from_gmt((string) get_user_meta($user->ID, self::META_AT, true), 'Y/m/d H:i'))
            );
        }

        printf(
            '<p><label for="mk_suspended_note">理由・メモ（運営のみ）</label><br>'
            . '<textarea name="mk_suspended_note" id="mk_suspended_note" rows="3" class="large-text">%s</textarea></p>',
            esc_textarea((string) get_user_meta($user->ID, self::META_NOTE, true))
        );

        echo '</td></tr></tbody></table>';
    }

    public static function saveProfile(int $userId): void
    {
        if (!current_user_can('manage_woocommerce') || user_can($userId, 'manage_woocommerce')) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_mk_suspension_nonce'] ?? '')));

        if (!wp_verify_nonce($nonce, self::NONCE . '_' . $userId)) {
            return;
        }

        $note = isset($_POST['mk_suspended_note']) ? sanitize_textarea_field(wp_unslash($_POST['mk_suspended_note'])) : '';
        $want = !empty($_POST['mk_suspended']);

        if ($want && !self::isSuspended($userId)) {
            self::suspend($userId, $note, get_current_user_id());
        } elseif (!$want && self::isSuspended($userId)) {
            self::lift($userId, $note);
        } else {
            update_user_meta($userId, self::META_NOTE, $note);
        }
    }
}
