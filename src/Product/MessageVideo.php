<?php
declare(strict_types=1);

namespace MK\Product;

use RuntimeException;
use WC_Order;

/**
 * メッセージ動画 -- a video a creator records for one buyer, after the sale.
 *
 * The first kind of digital content on the platform, and deliberately built
 * as a variant of the physical flow rather than beside it. A message video is
 * paid for, made, handed over and confirmed, which is exactly the shape of a
 * parcel: the only step that differs is how the thing travels. So the order
 * moves through the same statuses, the money sits in the same escrow and is
 * released by the same 受取確認, and the deadline, the cancellation request,
 * the refund button and the ledger all apply unchanged. What this class adds
 * is the part a parcel does not have: what kind of message, for whom, saying
 * what.
 *
 * ---------------------------------------------------------------------------
 * Who decides what
 * ---------------------------------------------------------------------------
 *   運営         the list of message types (誕生日, 応援, ...), so every creator
 *                offers the same recognisable menu
 *   クリエイター  which of those they will record, how long, how soon, price
 *   購入者        one type from that creator's list, the name to be called by,
 *                and anything they want said
 *
 * Everything the buyer chose is copied onto the order at purchase. A creator
 * who later stops offering 誕生日 must still see that this order asked for one.
 *
 * ---------------------------------------------------------------------------
 * Not a one-off item
 * ---------------------------------------------------------------------------
 * A listing is an offer to record, not a single object, so any number of
 * buyers can order it. Reservation skips these listings entirely; taking one
 * off sale after the first order would sell exactly one birthday message.
 */
final class MessageVideo
{
    public const KIND = 'message_video';

    /** Product and order: what kind of listing this is. */
    public const META_KIND = '_mk_kind';

    // Product settings.
    public const META_TYPES    = '_mk_message_types';
    public const META_LENGTH   = '_mk_video_length';
    public const META_DELIVERY = '_mk_video_delivery';

    // Order snapshot of what the buyer asked for.
    public const META_TYPE_KEY     = '_mk_message_type';
    public const META_TYPE_LABEL   = '_mk_message_type_label';
    public const META_REQUEST_NAME = '_mk_request_name';
    public const META_REQUEST_BODY = '_mk_request_body';

    /** When the buyer ticked the cautions before writing the request. */
    public const META_REQUEST_AGREED_AT = '_mk_request_agreed_at';

    /** The operator's list of message types. */
    public const OPTION_TYPES = 'mk_message_types';

    /** Agreed with the client: required, 20 characters. */
    public const NAME_MAX = 20;

    /** Agreed with the client: optional, 200 characters. */
    public const BODY_MAX = 200;

    public const DEFAULT_LENGTH   = '1m';
    public const DEFAULT_DELIVERY = '7';

    private static bool $rendered = false;

    public static function register(): void
    {
        add_action('dokan_product_edit_after_options', [self::class, 'renderFields'], 5, 2);
        add_action('dokan_new_product_form', [self::class, 'renderFields'], 5, 2);

        add_action('dokan_product_updated', [self::class, 'save'], 20, 1);
        add_action('dokan_new_product_added', [self::class, 'save'], 20, 1);

        add_action('woocommerce_single_product_summary', [self::class, 'renderOnProduct'], 25);
    }

    // ----------------------------------------------------------- vocabulary

    /**
     * The eight types the client specified, with the examples they gave.
     *
     * Seeded once by the installer. After that the operator's own list in
     * OPTION_TYPES is the only source, so renaming or retiring a type is an
     * admin change rather than a code change.
     *
     * @return array<int, array{key:string, label:string, description:string, active:bool}>
     */
    public static function defaultTypes(): array
    {
        return [
            ['key' => 'birthday',    'label' => '誕生日メッセージ',     'description' => '「誕生日おめでとう！」など', 'active' => true],
            ['key' => 'celebration', 'label' => 'お祝いメッセージ',     'description' => '合格、就職、結婚、記念日など', 'active' => true],
            ['key' => 'cheer',       'label' => '応援メッセージ',       'description' => '仕事、試験、スポーツ、挑戦など', 'active' => true],
            ['key' => 'thanks',      'label' => '感謝メッセージ',       'description' => '「いつもありがとう」など', 'active' => true],
            ['key' => 'anniversary', 'label' => '記念日メッセージ',     'description' => '○周年、○ヶ月記念、特別な日など', 'active' => true],
            ['key' => 'encourage',   'label' => '励ましメッセージ',     'description' => '落ち込んでいる人へのメッセージなど', 'active' => true],
            ['key' => 'welcome',     'label' => 'ウェルカムメッセージ', 'description' => '新生活、新しい仕事、新しい環境など', 'active' => true],
            ['key' => 'free',        'label' => 'フリーメッセージ',     'description' => '上記に当てはまらない自由な内容', 'active' => true],
        ];
    }

    /**
     * The operator's list, in their order.
     *
     * @return array<string, array{label:string, description:string, active:bool}>
     */
    public static function allTypes(): array
    {
        $stored = get_option(self::OPTION_TYPES);
        $rows   = is_array($stored) && $stored !== [] ? $stored : self::defaultTypes();
        $types  = [];

        foreach ($rows as $row) {
            $key = sanitize_key((string) ($row['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $types[$key] = [
                'label'       => (string) ($row['label'] ?? $key),
                'description' => (string) ($row['description'] ?? ''),
                'active'      => !empty($row['active']),
            ];
        }

        return $types;
    }

    /** @return array<string, array{label:string, description:string, active:bool}> */
    public static function activeTypes(): array
    {
        return array_filter(self::allTypes(), static fn (array $t): bool => $t['active']);
    }

    /** @return array<string, string> */
    public static function lengths(): array
    {
        return [
            '30s' => '〜30秒',
            '1m'  => '〜1分',
            '3m'  => '〜3分',
        ];
    }

    /**
     * Delivery promises, agreed with the client as 3 / 7 / 14 days.
     *
     * Longer than a parcel's 1-7, because the video does not exist yet when
     * the order is placed.
     *
     * @return array<string, array{label:string, days:int}>
     */
    public static function deliveryOptions(): array
    {
        return [
            '3'  => ['label' => '3日以内に送信',  'days' => 3],
            '7'  => ['label' => '7日以内に送信',  'days' => 7],
            '14' => ['label' => '14日以内に送信', 'days' => 14],
        ];
    }

    public static function deliveryDays(string $key): int
    {
        $options = self::deliveryOptions();

        return (int) ($options[$key]['days'] ?? $options[self::DEFAULT_DELIVERY]['days']);
    }

    public static function deliveryLabel(string $key): string
    {
        return (string) (self::deliveryOptions()[$key]['label'] ?? '');
    }

    // ---------------------------------------------------------------- reads

    public static function isMessageVideo(int $productId): bool
    {
        return $productId > 0 && get_post_meta($productId, self::META_KIND, true) === self::KIND;
    }

    public static function isMessageVideoOrder(WC_Order $order): bool
    {
        return $order->get_meta(self::META_KIND) === self::KIND;
    }

    /**
     * The types this listing offers that the operator still offers too.
     *
     * Intersected rather than trusted: a type the operator has retired must
     * disappear from every listing at once, not linger on the ones nobody
     * re-saved.
     *
     * @return array<string, array{label:string, description:string, active:bool}>
     */
    public static function supportedTypes(int $productId): array
    {
        $chosen = get_post_meta($productId, self::META_TYPES, true);
        $chosen = is_array($chosen) ? array_map('strval', $chosen) : [];

        return array_intersect_key(self::activeTypes(), array_flip($chosen));
    }

    public static function lengthOf(int $productId): string
    {
        $key = (string) get_post_meta($productId, self::META_LENGTH, true);

        return isset(self::lengths()[$key]) ? $key : self::DEFAULT_LENGTH;
    }

    public static function deliveryOf(int $productId): string
    {
        $key = (string) get_post_meta($productId, self::META_DELIVERY, true);

        return isset(self::deliveryOptions()[$key]) ? $key : self::DEFAULT_DELIVERY;
    }

    // ------------------------------------------------------- listing form

    /** @param int|\WP_Post|null $post */
    public static function renderFields($post = null, $postId = null): void
    {
        if (self::$rendered) {
            return;   // both hooks fire on the edit screen
        }

        self::$rendered = true;

        $productId = (int) ($postId ?: (is_object($post) ? ($post->ID ?? 0) : 0));
        $isVideo   = self::isMessageVideo($productId);
        $chosen    = $productId > 0 ? array_keys(self::supportedTypes($productId)) : [];
        $length    = $productId > 0 ? self::lengthOf($productId) : self::DEFAULT_LENGTH;
        $delivery  = $productId > 0 ? self::deliveryOf($productId) : self::DEFAULT_DELIVERY;

        echo '<div class="mk-kind" data-mk-kind="' . esc_attr($isVideo ? self::KIND : 'physical') . '">';
        echo '<p class="mk-kind__title">ファン向けデジタルコンテンツはこちら</p>';

        printf(
            '<label class="mk-kind__option"><input type="radio" name="mk_kind" value="physical"%s>'
            . '<span><strong>通常の商品</strong><small>発送してお届けする商品です。</small></span></label>',
            $isVideo ? '' : ' checked'
        );

        printf(
            '<label class="mk-kind__option"><input type="radio" name="mk_kind" value="%s"%s>'
            . '<span><strong>メッセージ動画</strong>'
            . '<small>購入者のリクエストに合わせて動画を撮影し、Vimeoの限定公開URLでお届けします。</small></span></label>',
            esc_attr(self::KIND),
            $isVideo ? ' checked' : ''
        );

        echo '<div class="mk-video-fields">';

        echo '<p class="mk-video-fields__label">対応できるメッセージの種類<span class="required">*</span></p>';
        echo '<p class="mk-field-help">対応できるものをすべて選んでください。購入者はこの中から1つ選んで購入します。</p>';
        echo '<ul class="mk-video-types">';

        foreach (self::activeTypes() as $key => $type) {
            printf(
                '<li><label><input type="checkbox" name="mk_message_types[]" value="%s"%s> '
                . '<strong>%s</strong><small>%s</small></label></li>',
                esc_attr($key),
                in_array($key, $chosen, true) ? ' checked' : '',
                esc_html($type['label']),
                esc_html($type['description'])
            );
        }

        echo '</ul>';

        echo '<p class="mk-details__field"><label for="mk_video_length">動画時間</label>';
        echo '<select name="mk_video_length" id="mk_video_length" class="dokan-form-control">';

        foreach (self::lengths() as $key => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($length, $key, false), esc_html($label));
        }

        echo '</select></p>';

        echo '<p class="mk-details__field"><label for="mk_video_delivery">送信までの期限</label>';
        echo '<select name="mk_video_delivery" id="mk_video_delivery" class="dokan-form-control">';

        foreach (self::deliveryOptions() as $key => $option) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($delivery, $key, false), esc_html($option['label']));
        }

        echo '</select>';
        echo '<small>購入代金の支払いが確認できた日から数えます。期限を過ぎると、購入者がキャンセルを申請できます。</small></p>';

        echo '<p class="mk-field-help">デジタルコンテンツを販売する際の出品画像等は、ご自身で編集・作成したうえで、'
            . '通常の商品の出品と同様に、上記の画像アップロード欄からアップロードしてください。</p>';

        echo '<p class="mk-field-help">動画の内容は「商品の詳しい説明」に、価格は「価格」欄にご入力ください。'
            . '撮影した動画は、ご自身のVimeoアカウントに<strong>限定公開</strong>でアップロードし、'
            . '取引画面からURLを送信していただきます。</p>';

        echo '</div></div>';
    }

    /** @param int $productId */
    public static function save($productId): void
    {
        $productId = (int) $productId;

        if ($productId <= 0 || !isset($_POST['mk_kind'])) {
            return;
        }

        $kind = sanitize_key(wp_unslash((string) $_POST['mk_kind']));

        if ($kind !== self::KIND) {
            update_post_meta($productId, self::META_KIND, 'physical');

            return;
        }

        update_post_meta($productId, self::META_KIND, self::KIND);

        $posted = isset($_POST['mk_message_types']) && is_array($_POST['mk_message_types'])
            ? array_map(static fn ($v): string => sanitize_key((string) wp_unslash($v)), $_POST['mk_message_types'])
            : [];

        // Only types that exist. A key the operator never created is not a
        // type, whoever posted it.
        $types = array_values(array_intersect($posted, array_keys(self::activeTypes())));

        update_post_meta($productId, self::META_TYPES, $types);

        $length = isset($_POST['mk_video_length']) ? sanitize_key(wp_unslash((string) $_POST['mk_video_length'])) : '';
        update_post_meta($productId, self::META_LENGTH, isset(self::lengths()[$length]) ? $length : self::DEFAULT_LENGTH);

        $delivery = isset($_POST['mk_video_delivery']) ? sanitize_key(wp_unslash((string) $_POST['mk_video_delivery'])) : '';
        update_post_meta($productId, self::META_DELIVERY, isset(self::deliveryOptions()[$delivery]) ? $delivery : self::DEFAULT_DELIVERY);

        self::normaliseStock($productId);
    }

    /**
     * An offer to record, not an object: no stock, no per-order limit, nothing
     * to post.
     *
     * Written here as well as enforced elsewhere because the physical-goods
     * rules run on every save and would otherwise put a stock count and a
     * condition grade back on a listing that has neither.
     */
    public static function normaliseStock(int $productId): void
    {
        update_post_meta($productId, '_manage_stock', 'no');
        update_post_meta($productId, '_sold_individually', 'no');
        update_post_meta($productId, '_stock_status', 'instock');
        update_post_meta($productId, '_virtual', 'yes');
        delete_post_meta($productId, Details::META_CONDITION);
    }

    // ---------------------------------------------------------- product page

    public static function renderOnProduct(): void
    {
        global $product;

        if (!$product instanceof \WC_Product || !self::isMessageVideo($product->get_id())) {
            return;
        }

        $productId = $product->get_id();
        $types     = self::supportedTypes($productId);

        echo '<ul class="mk-details-list mk-details-list--video">';

        printf(
            '<li><span class="mk-details-list__key">商品の種類</span>'
            . '<span class="mk-details-list__value"><strong>メッセージ動画</strong>'
            . '<small>ご購入後にクリエイターが撮影し、動画のURLでお届けします。</small></span></li>'
        );

        printf(
            '<li><span class="mk-details-list__key">動画時間</span>'
            . '<span class="mk-details-list__value"><strong>%s</strong></span></li>',
            esc_html(self::lengths()[self::lengthOf($productId)])
        );

        printf(
            '<li><span class="mk-details-list__key">お届けまで</span>'
            . '<span class="mk-details-list__value"><strong>%s</strong>'
            . '<small>ご入金の確認後、クリエイターが撮影して送信します。</small></span></li>',
            esc_html(self::deliveryLabel(self::deliveryOf($productId)))
        );

        printf(
            '<li><span class="mk-details-list__key">対応メッセージ</span>'
            . '<span class="mk-details-list__value">%s</span></li>',
            $types
                ? esc_html(implode('／', array_column($types, 'label')))
                : '<small>現在受け付けているメッセージはありません。</small>'
        );

        echo '</ul>';
    }

    // -------------------------------------------------------------- buying

    /**
     * The client's cautions, verbatim, shown immediately before the request.
     *
     * @return string[]
     */
    public static function cautions(): array
    {
        return [
            '性的・成人向けの内容',
            '暴力、脅迫、差別、誹謗中傷等',
            '犯罪行為を助長・誘発する内容',
            '他人への嫌がらせを目的とする内容',
            '個人情報や第三者の権利を侵害する内容',
            '第三者への無断での共有・転載・拡散を前提とした内容',
            'その他、公序良俗に反する内容',
        ];
    }

    /**
     * The request part of the buy form.
     *
     * The cautions come FIRST, and the request fields stay locked until the
     * buyer ticks that they have read them. The client's point: agreeing to a
     * long document at sign-up is not the same as seeing the rules at the
     * moment of typing a request, and the moment of typing is when an
     * inappropriate one gets written.
     *
     * Locked in the browser for the experience, and enforced on the server by
     * validateRequest(), because a disabled attribute is not a rule.
     */
    public static function renderBuyFields(int $productId): string
    {
        $types = self::supportedTypes($productId);

        if (!$types) {
            return '';
        }

        $html  = '<div class="mk-video-request">';

        $html .= '<div class="mk-video-caution">';
        $html .= '<p class="mk-video-caution__title">ご依頼前に必ずご確認ください</p>';
        $html .= '<p class="mk-video-caution__lead">次のような内容のご依頼はできません。</p><ul>';

        foreach (self::cautions() as $caution) {
            $html .= '<li>' . esc_html($caution) . '</li>';
        }

        $html .= '</ul>';
        $html .= '<p class="mk-video-caution__body">不適切な依頼は禁止されており、該当する場合はクリエイターが依頼を辞退し、'
            . 'キャンセル・返金となる場合があります。また、購入した動画やその他のデジタルコンテンツを、'
            . 'クリエイターの許可なく第三者へ共有、転載、複製、配布、公開、SNS等へ投稿・拡散する行為は禁止されています。'
            . '内容や方法によっては、著作権、肖像、プライバシーその他の権利を侵害し、法令に抵触する可能性があります。</p>';

        $html .= '<label class="mk-video-caution__agree"><input type="checkbox" name="mk_request_agree" id="mk_request_agree" value="1" required> '
            . '上記の注意事項を確認し、不適切な依頼や、購入したコンテンツの無断での共有・転載・拡散を行わないことに同意します。</label>';
        $html .= '</div>';

        $html .= '<fieldset class="mk-video-request__fields">';
        $html .= '<p class="mk-video-request__title">メッセージの種類を選んでください<span class="required">*</span></p>';
        $html .= '<ul class="mk-video-request__types">';

        // One type on offer is not a choice. Left unticked it is a required
        // radio nobody reads as a question, and the buyer meets a browser
        // message about "options" on a form that shows none (2026-09-18).
        $only = count($types) === 1;

        foreach ($types as $key => $type) {
            $html .= sprintf(
                '<li><label><input type="radio" name="mk_message_type" value="%s"%s required disabled data-mk-requires-agree> '
                . '<strong>%s</strong><small>%s</small></label></li>',
                esc_attr($key),
                $only ? ' checked' : '',
                esc_html($type['label']),
                esc_html($type['description'])
            );
        }

        $html .= '</ul>';

        $html .= sprintf(
            '<p><label for="mk_request_name">呼んでほしいお名前<span class="required">*</span></label>'
            . '<input type="text" name="mk_request_name" id="mk_request_name" maxlength="%1$d" required disabled data-mk-requires-agree '
            . 'placeholder="例：さくらちゃん">'
            . '<small>%1$d文字まで</small></p>',
            self::NAME_MAX
        );

        $html .= sprintf(
            '<p><label for="mk_request_body">メッセージに入れてほしい内容（任意）</label>'
            . '<textarea name="mk_request_body" id="mk_request_body" rows="4" maxlength="%1$d" disabled data-mk-requires-agree '
            . 'placeholder="例：来週の試験、がんばってと伝えてほしいです"></textarea>'
            . '<small>%1$d文字まで</small></p>',
            self::BODY_MAX
        );

        $html .= '<p class="mk-video-request__locked">上の注意事項に同意すると、ご依頼内容を入力できます。</p>';
        $html .= '</fieldset>';

        $html .= '<p class="mk-video-request__error" role="alert" hidden></p>';
        $html .= '<p class="mk-video-request__note">動画はクリエイターが撮影後、取引画面からお届けします。</p>';

        $html .= '</div>';

        // Disabled fields are not submitted and do not validate, so they are
        // unlocked on the tick rather than merely made to look active.
        //
        // The browser's own wording for a missing choice is about "options",
        // which on this form means the gift options of a physical item -- it
        // sent a buyer looking for a field that is not there. Each field says
        // in its own words what it wants instead. Every message is cleared on
        // the next edit: a custom one sticks until it is, and a stale one
        // would block a form the buyer has since filled in correctly.
        $html .= '<script>(function(){'
            . 'var box=document.getElementById("mk_request_agree");if(!box){return;}'
            . 'var form=box.form;'
            . 'var fields=document.querySelectorAll("[data-mk-requires-agree]");'
            . 'var note=document.querySelector(".mk-video-request__locked");'
            . 'function sync(){Array.prototype.forEach.call(fields,function(f){f.disabled=!box.checked;});'
            . 'if(note){note.hidden=box.checked;}}'
            . 'box.addEventListener("change",sync);'
            // Coming back with the browser's Back button restores the tick
            // without firing change, and the fields would stay locked.
            . 'window.addEventListener("pageshow",sync);sync();'
            . 'var box2=document.querySelector(".mk-video-request__error");'
            . 'if(!form||!box2){return;}'
            // required stays in the markup for a browser without this script;
            // with it, the page says which field is missing, in its own words.
            . 'form.noValidate=true;'
            . 'function fail(e,field,message){e.preventDefault();box2.textContent=message;box2.hidden=false;'
            . 'if(field){field.focus();}}'
            . 'form.addEventListener("submit",function(e){'
            . 'box2.hidden=true;box2.textContent="";'
            . 'if(!box.checked){fail(e,box,"ご依頼前の注意事項をご確認のうえ、同意のチェックを入れてください。");return;}'
            . 'var types=form.querySelectorAll("[name=\'mk_message_type\']");'
            . 'if(types.length&&!form.querySelector("[name=\'mk_message_type\']:checked")){'
            . 'fail(e,types[0],"メッセージの種類を選んでください。");return;}'
            . 'var name=document.getElementById("mk_request_name");'
            . 'if(name&&!name.value.replace(/^\s+|\s+$/g,"")){'
            . 'fail(e,name,"呼んでほしいお名前を入力してください。");return;}'
            . '});'
            . '})();</script>';

        return $html;
    }

    /**
     * The buyer's request, validated against this listing.
     *
     * Checked on the server whatever the form enforced: maxlength is a hint to
     * a browser, and a type the creator does not offer is a request they never
     * agreed to record.
     *
     * @param array<string,mixed> $post
     * @return array{type:string, label:string, name:string, body:string}
     * @throws RuntimeException with a buyer-facing message
     */
    public static function validateRequest(int $productId, array $post): array
    {
        // First, and on the server: the fields are locked in the browser until
        // this is ticked, but a locked field is not a rule.
        if (empty($post['mk_request_agree'])) {
            throw new RuntimeException('ご依頼前の注意事項をご確認のうえ、同意のチェックを入れてください。');
        }

        $types = self::supportedTypes($productId);
        $type  = isset($post['mk_message_type']) ? sanitize_key(wp_unslash((string) $post['mk_message_type'])) : '';

        if (!isset($types[$type])) {
            throw new RuntimeException('メッセージの種類を選んでください。');
        }

        $name = isset($post['mk_request_name'])
            ? trim(sanitize_text_field(wp_unslash((string) $post['mk_request_name'])))
            : '';

        if ($name === '') {
            throw new RuntimeException('呼んでほしいお名前を入力してください。');
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException(sprintf('お名前は%d文字以内で入力してください。', self::NAME_MAX));
        }

        $body = isset($post['mk_request_body'])
            ? trim(sanitize_textarea_field(wp_unslash((string) $post['mk_request_body'])))
            : '';

        if (mb_strlen($body) > self::BODY_MAX) {
            throw new RuntimeException(sprintf('メッセージの内容は%d文字以内で入力してください。', self::BODY_MAX));
        }

        return [
            'type'  => $type,
            'label' => $types[$type]['label'],
            'name'  => $name,
            'body'  => $body,
        ];
    }

    /**
     * Freeze the request and the listing's promises onto the order.
     *
     * @param array{type:string, label:string, name:string, body:string} $request
     */
    public static function snapshot(WC_Order $order, int $productId, array $request): void
    {
        $order->update_meta_data(self::META_KIND, self::KIND);
        $order->update_meta_data(self::META_TYPE_KEY, $request['type']);
        $order->update_meta_data(self::META_TYPE_LABEL, $request['label']);
        $order->update_meta_data(self::META_REQUEST_NAME, $request['name']);
        $order->update_meta_data(self::META_REQUEST_BODY, $request['body']);

        // validateRequest() refuses a request without the agreement, so reaching
        // here means it was given. Kept with the time, because a dispute about
        // an inappropriate request turns on whether the buyer saw the rules.
        $order->update_meta_data(self::META_REQUEST_AGREED_AT, gmdate('Y-m-d H:i:s'));
        $order->update_meta_data(self::META_LENGTH, self::lengthOf($productId));

        // The delivery promise goes where the dispatch promise goes, so the
        // deadline, the overdue alarm and the cancellation right all read it
        // without knowing this is a video.
        $order->update_meta_data(\MK\Order\DispatchDeadline::META_DISPATCH, 'video-' . self::deliveryOf($productId));
    }

    /** The request, for display on either side of the order. */
    public static function renderRequestSummary(WC_Order $order): string
    {
        $length = (string) $order->get_meta(self::META_LENGTH);
        $body   = (string) $order->get_meta(self::META_REQUEST_BODY);

        return sprintf(
            '<table class="mk-video-summary"><tbody>'
            . '<tr><th>メッセージの種類</th><td>%s</td></tr>'
            . '<tr><th>呼んでほしいお名前</th><td>%s</td></tr>'
            . '<tr><th>入れてほしい内容</th><td>%s</td></tr>'
            . '<tr><th>動画時間</th><td>%s</td></tr>'
            . '</tbody></table>',
            esc_html((string) $order->get_meta(self::META_TYPE_LABEL)),
            esc_html((string) $order->get_meta(self::META_REQUEST_NAME)),
            $body !== '' ? nl2br(esc_html($body)) : '<span class="mk-muted">（指定なし）</span>',
            esc_html(self::lengths()[$length] ?? '—')
        );
    }
}
