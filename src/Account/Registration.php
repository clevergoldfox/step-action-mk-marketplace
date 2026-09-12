<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * A sign-up page of its own, separate from the login page.
 *
 * WooCommerce puts both forms on one screen, side by side. On a phone that
 * stacks into a single column where the second form looks like a continuation
 * of the first -- people fill in the login boxes, fail, and try again. The
 * client asked for the two to be separate pages that link to each other.
 *
 * The form posts the fields WooCommerce's own handler expects
 * (WC_Form_Handler::process_registration, on wp_loaded), so registration,
 * validation, the privacy text and Dokan's "customer or seller" choice all
 * keep working exactly as they do on the built-in screen. Nothing about
 * account creation is reimplemented here; only where it is shown.
 */
final class Registration
{
    public const PAGE_OPTION = 'mk_register_page_id';

    private const SLUG  = 'register';
    private const TITLE = '新規登録';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensurePage']);
        add_shortcode('mk_register', [self::class, 'render']);
    }

    public static function url(): string
    {
        $pageId = (int) get_option(self::PAGE_OPTION);

        return $pageId > 0
            ? (string) get_permalink($pageId)
            : home_url('/' . self::SLUG . '/');
    }

    public static function loginUrl(): string
    {
        return (string) wc_get_page_permalink('myaccount');
    }

    /**
     * Create the page, and put it back if it is ever trashed.
     *
     * Same reasoning as the checkout page: a stored id can outlive the page
     * it points at, and a sign-up link that 404s costs a customer silently.
     */
    public static function ensurePage(): void
    {
        $existing = (int) get_option(self::PAGE_OPTION);

        if ($existing > 0) {
            $page = get_post($existing);

            if ($page && $page->post_status === 'publish') {
                return;
            }
        }

        $pageId = wp_insert_post([
            'post_title'     => self::TITLE,
            'post_name'      => self::SLUG,
            'post_content'   => '[mk_register]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);

        if (!is_wp_error($pageId)) {
            update_option(self::PAGE_OPTION, (int) $pageId);
        }
    }

    public static function render(): string
    {
        if (is_user_logged_in()) {
            return sprintf(
                '<div class="mk-auth"><p>すでにログインしています。</p>'
                . '<p><a class="mk-auth__button" href="%s">マイページへ</a></p></div>',
                esc_url(self::loginUrl())
            );
        }

        ob_start();

        // Registration errors are queued as WooCommerce notices, and this is
        // not a WooCommerce page, so nothing would print them: the form would
        // simply reappear with no explanation.
        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }

        ?>
        <div class="mk-auth">
            <form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action('woocommerce_register_form_tag'); ?>>

                <?php do_action('woocommerce_register_form_start'); ?>

                <?php if ('no' === get_option('woocommerce_registration_generate_username')) : ?>
                    <p class="woocommerce-form-row form-row form-row-wide">
                        <label for="reg_username">ユーザー名&nbsp;<span class="required">*</span></label>
                        <input type="text" class="woocommerce-Input input-text" name="username" id="reg_username"
                               autocomplete="username" required
                               value="<?php echo isset($_POST['username']) ? esc_attr(wp_unslash((string) $_POST['username'])) : ''; ?>">
                    </p>
                <?php endif; ?>

                <p class="woocommerce-form-row form-row form-row-wide">
                    <label for="reg_email">メールアドレス&nbsp;<span class="required">*</span></label>
                    <input type="email" class="woocommerce-Input input-text" name="email" id="reg_email"
                           autocomplete="email" required
                           value="<?php echo isset($_POST['email']) ? esc_attr(wp_unslash((string) $_POST['email'])) : ''; ?>">
                </p>

                <?php if ('no' === get_option('woocommerce_registration_generate_password')) : ?>
                    <p class="woocommerce-form-row form-row form-row-wide">
                        <label for="reg_password">パスワード&nbsp;<span class="required">*</span></label>
                        <input type="password" class="woocommerce-Input input-text" name="password" id="reg_password"
                               autocomplete="new-password" required>
                    </p>
                <?php else : ?>
                    <p>パスワードを設定するためのリンクを、ご登録のメールアドレスへお送りします。</p>
                <?php endif; ?>

                <?php
                // Dokan's customer/seller choice and the privacy notice hang
                // off this hook, as does the registration captcha.
                do_action('woocommerce_register_form');
                ?>

                <p class="woocommerce-form-row form-row">
                    <?php wp_nonce_field('woocommerce-register', 'woocommerce-register-nonce'); ?>
                    <button type="submit" class="woocommerce-Button button woocommerce-form-register__submit"
                            name="register" value="登録する">登録する</button>
                </p>

                <?php do_action('woocommerce_register_form_end'); ?>
            </form>

            <p class="mk-auth__switch">
                すでにアカウントをお持ちの方は
                <a href="<?php echo esc_url(self::loginUrl()); ?>">ログイン</a>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
