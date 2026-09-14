<?php
declare(strict_types=1);

namespace MK\Product;

/**
 * Lets a creator actually write a tag.
 *
 * The client reported that typing in 商品の特徴・キーワード saved nothing. It
 * could not have: in Dokan Lite 5.1 the vendor tag field can only ever attach
 * tags that already exist, and on a new marketplace none do. Three separate
 * things stand in the way, and all three have to be moved.
 *
 *   1. dokan_selling → product_vendors_can_create_tags is off by default.
 *      Turned on by the installer (see Install\Migrator::allowVendorTags).
 *
 *   2. That setting is never handed to the browser. dokan.js reads
 *      `dokan.product_vendors_can_create_tags` to decide whether select2 may
 *      create entries, and Dokan does not put the key in its localised data
 *      at all -- so the check is `undefined === 'on'` and typing is refused
 *      no matter what the setting says. Added here through Dokan's own
 *      dokan_localized_args filter.
 *
 *   3. Even with the box allowing it, select2's createTag() produces an entry
 *      whose value is the TEXT that was typed, while Dokan's save runs
 *      array_map('absint', ...) over the posted values and attaches the
 *      result. Any new tag therefore arrives as the integer 0 and is dropped
 *      on the floor. The text is turned into a real term here, after Dokan
 *      has finished, and attached alongside whatever ids came through.
 *
 * None of this is patched into Dokan. The setting is a setting, the flag goes
 * through a filter Dokan provides, and the save runs on WooCommerce's own
 * product hooks afterwards.
 */
final class Tags
{
    /** As many as a listing can carry, to stop a paste turning into 400 terms. */
    private const MAX_TAGS = 20;

    private const MAX_LENGTH = 40;

    public static function register(): void
    {
        add_filter('dokan_localized_args', [self::class, 'exposeSetting']);

        // After Dokan's own save, which has already attached the ids.
        add_action('woocommerce_new_product', [self::class, 'saveTyped'], 20, 1);
        add_action('woocommerce_update_product', [self::class, 'saveTyped'], 20, 1);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function exposeSetting(array $args): array
    {
        $args['product_vendors_can_create_tags'] = (string) dokan_get_option(
            'product_vendors_can_create_tags',
            'dokan_selling',
            'off'
        );

        return $args;
    }

    /**
     * Attach the tags that were typed rather than chosen.
     *
     * Runs on the product hooks rather than on Dokan's, because the same form
     * reaches the database through WooCommerce either way and this is the
     * point at which Dokan has finished writing terms -- appending before
     * that would be overwritten by its wp_set_object_terms().
     *
     * @param int $productId
     */
    public static function saveTyped($productId): void
    {
        $productId = (int) $productId;

        if ($productId <= 0 || empty($_POST['product_tag']) || !is_array($_POST['product_tag'])) {
            return;
        }

        // Only from a form that carried the tag field. Without this, any save
        // during a request that happens to have product_tag in POST -- a bulk
        // action, another plugin's form -- would rewrite this product's tags.
        if (!isset($_POST['dokan_update_product']) && !isset($_POST['dokan_add_product'])) {
            return;
        }

        $submitted = array_map(
            static fn ($value): string => sanitize_text_field((string) wp_unslash($value)),
            $_POST['product_tag']
        );

        $termIds = [];

        foreach (array_slice($submitted, 0, self::MAX_TAGS) as $value) {
            $termId = self::resolve($value);

            if ($termId > 0) {
                $termIds[] = $termId;
            }
        }

        // Replaces rather than appends: an empty submission means the creator
        // removed their tags, and appending would make them unremovable.
        wp_set_object_terms($productId, array_values(array_unique($termIds)), 'product_tag');
    }

    /**
     * One submitted value to a term id, creating the term if it is new.
     *
     * A numeric value is an existing term the creator picked from the list --
     * but it is still checked, because a term id in a POST is not proof that
     * the term exists.
     */
    private static function resolve(string $value): int
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return 0;
        }

        if (ctype_digit($value)) {
            $term = get_term((int) $value, 'product_tag');

            return $term instanceof \WP_Term ? $term->term_id : 0;
        }

        $existing = get_term_by('name', $value, 'product_tag');

        if ($existing instanceof \WP_Term) {
            return $existing->term_id;
        }

        $created = wp_insert_term($value, 'product_tag');

        if (is_wp_error($created)) {
            // A term created by a concurrent request: take the one that won.
            $data = $created->get_error_data('term_exists');

            return is_array($data) ? (int) ($data['term_id'] ?? 0) : (int) $data;
        }

        return (int) $created['term_id'];
    }
}
