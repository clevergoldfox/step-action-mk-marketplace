<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * 商品リンクをコピー -- a short, shareable link to a listing.
 *
 * The client wants listings shared on LINE, X, TikTok and Instagram by anyone:
 * the creator, a buyer thinking it over, a follower cheering a creator on
 * (2026-09-20). Three things make that work.
 *
 * A short address. A listing's permalink carries its Japanese title
 * percent-encoded -- /product/%e3%83%86%e3%82%b9... -- which is unreadable and
 * gets cut off in a post. /item/{id}/ is stable (renaming the listing does not
 * break links already posted) and short enough to type. It is resolved at
 * parse_request, like Line\Links, so there is no rewrite rule to go missing.
 *
 * A copy button everywhere a link might be wanted: the product page, each row
 * of the creator's product list, and the notice after listing. On phones the
 * OS share sheet is offered as well, which is how people actually post to
 * those apps.
 *
 * Nothing for a listing nobody can open. A draft or a listing waiting for
 * approval shows "公開後にリンクをコピーできます" instead: a link that lands on
 * a 404 is worse than no link. A sold item keeps its page but offers no
 * button -- there is nothing left to share it for.
 */
final class ShareLink
{
    public const PREFIX = 'item';

    private static bool $scriptNeeded = false;

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'route'], 1);

        // Just after the buy button (priority 30).
        add_action('woocommerce_single_product_summary', [self::class, 'renderOnProduct'], 31);

        add_action('dokan_product_list_table_after_column_content_name_row_actions', [self::class, 'renderInList'], 20, 1);

        add_action('wp_footer', [self::class, 'script'], 30);
    }

    public static function url(int $productId): string
    {
        return home_url('/' . self::PREFIX . '/' . $productId . '/');
    }

    /** Can someone other than its creator open this listing right now? */
    public static function isShareable(int $productId): bool
    {
        return get_post_type($productId) === 'product'
            && in_array(get_post_status($productId), ['publish', Statuses::RESERVED], true);
    }

    /** Statuses whose page resolves at all (see Statuses::keepSinglePagesVisible). */
    private static function isViewable(int $productId): bool
    {
        return get_post_type($productId) === 'product'
            && in_array(get_post_status($productId), ['publish', Statuses::RESERVED, Statuses::SOLD], true);
    }

    public static function route(\WP $wp): void
    {
        if (!preg_match('#^' . self::PREFIX . '/(\d+)/?$#', trim((string) $wp->request, '/'), $m)) {
            return;
        }

        $productId = (int) $m[1];

        if (!self::isViewable($productId)) {
            $wp->query_vars = ['error' => '404'];

            return;
        }

        $target = (string) get_permalink($productId);

        // Carries the private preview key through before launch, as Line\Links does.
        if (isset($_GET['woo-share'])) {
            $target = add_query_arg('woo-share', sanitize_text_field(wp_unslash($_GET['woo-share'])), $target);
        }

        wp_safe_redirect($target, 302, 'Treasure Buzz item link');
        exit;
    }

    /**
     * The buttons, or the reason there are none yet.
     *
     * $compact is the one-line form used in the creator's product list.
     */
    public static function buttons(int $productId, bool $compact = false): string
    {
        $status = get_post_status($productId);

        if ($status === Statuses::SOLD || get_post_type($productId) !== 'product') {
            return '';
        }

        if (!self::isShareable($productId)) {
            return sprintf(
                '<p class="mk-share mk-share--pending%s">公開後にリンクをコピーできます</p>',
                $compact ? ' mk-share--compact' : ''
            );
        }

        self::$scriptNeeded = true;

        return sprintf(
            '<div class="mk-share%s" data-url="%s" data-title="%s">'
            . '%s'
            . '<button type="button" class="mk-share__copy%s">%s</button>'
            . '<button type="button" class="mk-share__native%s" hidden>共有する</button>'
            . '<span class="mk-share__status" role="status" aria-live="polite"></span>'
            . '</div>',
            $compact ? ' mk-share--compact' : '',
            esc_attr(self::url($productId)),
            esc_attr(get_the_title($productId)),
            // Asking is what makes anyone do it. Not on the dashboard list,
            // where the creator is working through their own listings and the
            // same sentence on every row is noise (2026-09-23).
            $compact ? '' : '<p class="mk-share__prompt">SNSでシェアしよう！</p>',
            $compact ? '' : ' button',
            $compact ? 'リンクをコピー' : '商品リンクをコピー',
            $compact ? '' : ' button'
        );
    }

    public static function renderOnProduct(): void
    {
        global $product;

        if ($product instanceof \WC_Product) {
            echo self::buttons($product->get_id()); // escaped inside
        }
    }

    /** @param mixed $product */
    public static function renderInList($product): void
    {
        if ($product instanceof \WC_Product) {
            echo self::buttons($product->get_id(), true); // escaped inside
        }
    }

    /**
     * Copy with the Clipboard API, falling back to a selected text field for
     * the browsers that refuse it (older in-app browsers, LINE's included);
     * the share sheet only where the device offers one and has a touch screen.
     */
    public static function script(): void
    {
        if (!self::$scriptNeeded) {
            return;
        }
        ?>
<script>
(function () {
    function status(box, text) {
        var s = box.querySelector('.mk-share__status');
        if (!s) { return; }
        s.textContent = text;
        clearTimeout(s._t);
        s._t = setTimeout(function () { s.textContent = ''; }, 3000);
    }

    function fallbackCopy(text) {
        var field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(field);
        return ok;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.mk-share__copy, .mk-share__native');
        if (!button) { return; }
        var box = button.closest('.mk-share');
        var url = box.getAttribute('data-url');

        if (button.classList.contains('mk-share__native')) {
            navigator.share({ title: box.getAttribute('data-title') || '', url: url }).catch(function () {});
            return;
        }

        var done = function () { status(box, 'リンクをコピーしました'); };
        var failed = function () {
            if (fallbackCopy(url)) { done(); } else { status(box, url); }
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done, failed);
        } else {
            failed();
        }
    });

    if (navigator.share && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
        Array.prototype.forEach.call(document.querySelectorAll('.mk-share__native'), function (b) { b.hidden = false; });
    }
})();
</script>
        <?php
    }
}
