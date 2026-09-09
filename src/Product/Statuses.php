<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * Product lifecycle states beyond WordPress's publish/draft.
 *
 * Both are `public => false`, which is what removes an item from the shop loop
 * and from search the moment it is claimed. A reserved product must disappear
 * for everyone except the buyer holding it, and a sold one must stay out of
 * the catalogue while remaining in the database -- order history references
 * it, and an admin investigating a dispute months later needs to see what was
 * actually listed.
 *
 * Deleting sold products would be the obvious shortcut and is the reason some
 * marketplaces cannot answer "what did I actually buy".
 */
final class Statuses
{
    public const RESERVED = 'mk-reserved';
    public const SOLD     = 'mk-sold';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostStatuses']);
        add_filter('display_post_states', [self::class, 'label'], 10, 2);
    }

    public static function registerPostStatuses(): void
    {
        register_post_status(self::RESERVED, [
            'label'                     => '取引手続き中',
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                '取引手続き中 <span class="count">(%s)</span>',
                '取引手続き中 <span class="count">(%s)</span>',
                'mk-marketplace'
            ),
        ]);

        register_post_status(self::SOLD, [
            'label'                     => '売却済み',
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                '売却済み <span class="count">(%s)</span>',
                '売却済み <span class="count">(%s)</span>',
                'mk-marketplace'
            ),
        ]);
    }

    /**
     * Show the state in the admin product list.
     *
     * @param array<string,string> $states
     * @return array<string,string>
     */
    public static function label(array $states, \WP_Post $post): array
    {
        if ($post->post_type !== 'product') {
            return $states;
        }

        if ($post->post_status === self::RESERVED) {
            $states['mk'] = '取引手続き中';
        } elseif ($post->post_status === self::SOLD) {
            $states['mk'] = '売却済み';
        }

        return $states;
    }
}
