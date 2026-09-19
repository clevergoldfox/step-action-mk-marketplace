<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * A way back from 利用規約, プライバシーポリシー and the other rule pages.
 *
 * The registration form opens them in a new tab, so that what has been typed
 * is not lost -- and on a phone a new tab has no back button at all: the
 * member is left on the terms with no visible way to the form they came from
 * (2026-09-19). Opened from the footer instead, they are an ordinary page and
 * the browser's back works, but the same button serves both.
 *
 * The button goes back where there is somewhere to go back to, closes the tab
 * where it was opened as one, and failing both says how to return. The link
 * itself points at the home page, so it still leads somewhere without
 * scripting.
 */
final class LegalPages
{
    /** @var array<int, string> */
    private const SLUGS = ['terms', 'privacy-policy', 'tokushoho', 'guideline'];

    public static function register(): void
    {
        add_filter('the_content', [self::class, 'addBackLinks'], 20);
    }

    /** @return array<int, int> page ids of the rule pages that exist */
    public static function pageIds(): array
    {
        $ids = [Terms::pageId(), (int) get_option('wp_page_for_privacy_policy')];

        foreach (self::SLUGS as $slug) {
            $page = get_page_by_path($slug);

            if ($page) {
                $ids[] = (int) $page->ID;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public static function addBackLinks(string $content): string
    {
        if (!is_page() || !in_the_loop() || !is_main_query()
            || !in_array((int) get_the_ID(), self::pageIds(), true)) {
            return $content;
        }

        return self::bar('top') . $content . self::bar('bottom') . self::script();
    }

    private static function bar(string $where): string
    {
        return sprintf(
            '<p class="mk-legal-back mk-legal-back--%s"><a href="%s" class="mk-legal-back__link" data-mk-back>'
            . '<span aria-hidden="true">←</span> 戻る</a></p>',
            esc_attr($where),
            esc_url(home_url('/'))
        );
    }

    private static function script(): string
    {
        return '<script>(function(){'
            . 'var links=document.querySelectorAll("[data-mk-back]");'
            . 'Array.prototype.forEach.call(links,function(a){a.addEventListener("click",function(e){'
            . 'e.preventDefault();'
            // Came from a page on this site in this tab: go back to it, with
            // whatever was typed there still in place.
            . 'var internal=document.referrer&&document.referrer.indexOf(location.origin)===0;'
            . 'if(history.length>1&&internal){history.back();return;}'
            // Arrived from elsewhere in this tab: nothing of ours to go back
            // to, so the home page.
            . 'if(history.length>1){location.href=a.href;return;}'
            // Opened as a new tab: closing it shows the tab it came from. Not
            // every browser lets a page close its own tab, so if it is still
            // here a moment later, say how.
            . 'window.close();'
            . 'setTimeout(function(){'
            . 'var n=document.querySelector(".mk-legal-back__note");'
            . 'if(!n){n=document.createElement("p");n.className="mk-legal-back__note";'
            . 'n.textContent="このページは新しいタブで開いています。このタブを閉じると、元の画面に戻れます。";'
            . 'a.parentNode.appendChild(n);}'
            . '},300);'
            . '});});'
            . '})();</script>';
    }
}
