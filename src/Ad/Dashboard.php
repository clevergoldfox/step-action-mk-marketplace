<?php
declare(strict_types=1);

namespace MK\Ad;

/**
 * Advertising on the creator's own management page.
 *
 * The client sells space to companies and wants the creators reachable too
 * (2026-10-01) -- a workshop supplier, a printer, a photography service.
 * Same screen in wp-admin, same fields, same dates; only the placement
 * differs, so an ad can be aimed at buyers or at creators without the
 * operator learning a second thing.
 *
 * It renders on every page of the management area rather than the front one
 * alone: a creator who goes straight to 取引・発送 every morning would
 * otherwise never see it.
 */
final class Dashboard
{
    public static function register(): void
    {
        add_action('dokan_dashboard_content_inside_before', [self::class, 'render'], 4);
    }

    public static function render(): void
    {
        $ads = Service::active(Service::PLACEMENT_DASHBOARD);

        if (!$ads) {
            return;
        }

        printf(
            '<div class="mk-dash-ads%s" data-tb-ads>',
            count($ads) > 1 ? ' mk-dash-ads--many' : ''
        );

        foreach ($ads as $ad) {
            $image = sprintf(
                '<img src="%s" alt="%s" loading="lazy" decoding="async">',
                esc_url($ad['image']),
                esc_attr($ad['alt'])
            );

            if (($ad['url'] ?? '') !== '') {
                printf(
                    '<a class="mk-dash-ads__item" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($ad['url']),
                    $image   // escaped above
                );

                continue;
            }

            printf('<span class="mk-dash-ads__item">%s</span>', $image);
        }

        echo '</div>';
    }
}
