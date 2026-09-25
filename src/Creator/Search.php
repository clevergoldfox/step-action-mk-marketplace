<?php
declare(strict_types=1);

namespace MK\Creator;

/**
 * Finding a creator by their number.
 *
 * Creator numbers exist so that a buyer who met someone at a market, saw a
 * number on a flyer or was told one by a friend can go straight to that
 * person's shop. The number format has existed since registration was built;
 * nothing ever read it. Typing "A00001" into the search bar ran an ordinary
 * product-title search, found nothing, and told the buyer so — the one piece
 * of information that should have been a perfect match returned zero results.
 *
 * The site has one search box, not two. A second "search by creator number"
 * field would mean every buyer has to decide which box their query belongs
 * in, and the ones who guess wrong get nothing. So the ordinary search
 * recognises the shape of a creator number and routes it; everything else
 * falls through untouched.
 */
final class Search
{
    private const NOT_FOUND_ARG = 'mk_creator_not_found';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'routeCreatorNumber'], 5);
        add_action('woocommerce_before_shop_loop', [self::class, 'notFoundNotice'], 5);
        add_action('woocommerce_no_products_found', [self::class, 'notFoundNotice'], 5);
    }

    /**
     * The creator this number belongs to, or null.
     *
     * Accepts whatever a person typed, not just the canonical form — see
     * Numbering::normalise() for why full-width input matters here.
     */
    public static function findByNumber(string $input): ?\WP_User
    {
        $number = Numbering::normalise($input);

        if (!Numbering::isCreatorNumber($number)) {
            return null;
        }

        $users = get_users([
            'meta_key'   => Onboarding::USER_META_NUMBER,
            'meta_value' => $number,
            'number'     => 1,
        ]);

        return $users[0] ?? null;
    }

    public static function routeCreatorNumber(): void
    {
        if (!is_search()) {
            return;
        }

        $raw = (string) get_search_query(false);

        // Only the normalised copy is used to TEST the query. The ordinary
        // search below keeps what the person actually typed, so folding
        // characters to make a number match never alters a title search.
        $number = Numbering::normalise($raw);

        if (!Numbering::isCreatorNumber($number)) {
            return;
        }

        $creator = self::findByNumber($raw);

        if ($creator instanceof \WP_User && function_exists('dokan_get_store_url')) {
            wp_safe_redirect(dokan_get_store_url($creator->ID));
            exit;
        }

        // Well-formed but unknown. Say so explicitly: an empty product grid
        // under the heading "A00099" reads as a broken search, not as "no
        // creator has that number".
        wp_safe_redirect(add_query_arg(
            [self::NOT_FOUND_ARG => $number],
            function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')
        ));
        exit;
    }

    public static function notFoundNotice(): void
    {
        static $shown = false;

        if ($shown || !isset($_GET[self::NOT_FOUND_ARG])) {
            return;
        }

        $shown  = true;
        $number = Numbering::normalise(sanitize_text_field(wp_unslash($_GET[self::NOT_FOUND_ARG])));

        printf(
            '<div class="woocommerce-info">クリエイター番号「%s」のクリエイターは見つかりませんでした。'
            . '番号をご確認のうえ、もう一度お試しください。</div>',
            esc_html($number)
        );
    }
}
