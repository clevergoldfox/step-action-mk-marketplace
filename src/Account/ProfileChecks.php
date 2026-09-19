<?php
declare(strict_types=1);

namespace MK\Account;

/**
 * Is this plausibly a real name, phone number or address?
 *
 * The client asked that obviously made-up details be turned back at
 * registration (2026-09-19): names, addresses and phone numbers are what a
 * buyer, the operator and -- for a business -- the 特定商取引法 page rely on,
 * and 第4条2項 asks every member to enter them accurately.
 *
 * Deliberately narrow. Nothing here can tell whether a well-formed name is
 * the member's own; that is what Stripe's identity check is for. What it can
 * do is refuse the shapes that are never real -- digits in a name, the word
 * テスト, a phone number of one repeated digit -- and say so in words the
 * member can act on. Anything it is not sure about, it lets through: a real
 * person refused at the door is a worse outcome than a fake one let in and
 * found later under 第8条.
 */
final class ProfileChecks
{
    /**
     * Stand-ins people type when they do not want to give a name. Compared
     * after normalising, so width and case do not matter.
     */
    private const PLACEHOLDERS = [
        'test', 'testtest', 'テスト', 'てすと', 'sample', 'サンプル', 'さんぷる',
        'dummy', 'ダミー', 'だみー', 'なし', '無し', 'ナシ', '名無し', 'ななし', 'ナナシ',
        '匿名', 'とくめい', 'トクメイ', 'hoge', 'ほげ', 'ホゲ', 'fuga', 'asdf', 'qwerty',
        'aaa', 'xxx', '〇〇', '○○', '××', 'name', 'noname', 'unknown', 'user',
    ];

    /** Width- and space-folded, lower-case: the form every check compares. */
    public static function normalise(string $value): string
    {
        $value = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'asKV') : $value;
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtolower($value);
    }

    /** Why this is not a name, or null if it may be one. */
    public static function nameProblem(string $value, string $label): ?string
    {
        $name = self::normalise($value);

        if ($name === '') {
            return null;   // required-ness is the form's business
        }

        if (preg_match('/\d/u', $name)) {
            return sprintf('%sに数字は使えません。ご本人のお名前を入力してください。', $label);
        }

        // Letters of any script, and the few marks that appear in real names.
        if (!preg_match("/^[\\p{L}\\p{M}ー・々〆ヶ '\\.\\-]+$/u", $name)) {
            return sprintf('%sに記号は使えません。ご本人のお名前を入力してください。', $label);
        }

        if (self::isPlaceholder($name) || self::isOneCharacterRepeated($name)) {
            return sprintf('%sには、ご本人のお名前を正しく入力してください。', $label);
        }

        return null;
    }

    /**
     * Why this is not a Japanese phone number, or null if it may be one.
     *
     * 0 and nine or ten more digits, hyphens and full-width digits allowed --
     * the same rule the delivery address uses. Beyond the shape, the numbers
     * people type to get past a required field: one digit over and over, or
     * counting up.
     */
    public static function phoneProblem(string $value, string $label = '電話番号'): ?string
    {
        $digits = self::phoneDigits($value);

        if (trim($value) === '') {
            return null;
        }

        if (!preg_match('/^0\d{9,10}$/', $digits)) {
            return sprintf('%sは、0から始まる10桁または11桁の数字で入力してください。', $label);
        }

        $subscriber = substr($digits, -8);

        if (count(array_unique(str_split($subscriber))) === 1
            || in_array($subscriber, ['12345678', '23456789', '87654321', '98765432'], true)
            || str_contains($digits, '0123456789')) {
            return sprintf('%sは、実際にご連絡のつく番号を入力してください。', $label);
        }

        return null;
    }

    public static function phoneDigits(string $value): string
    {
        $value = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'n') : $value;

        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * Why this is not a Japanese address, or null if it may be one.
     *
     * An address shown on a 特定商取引法 page has to be findable, so it must
     * name its prefecture and carry a number -- a 番地 or 丁目 -- rather than
     * stop at the town.
     */
    public static function addressProblem(string $value, string $label = '所在地'): ?string
    {
        $address = self::normalise($value);

        if ($address === '') {
            return null;
        }

        if (self::isPlaceholder($address) || self::isOneCharacterRepeated($address)) {
            return sprintf('%sには、実際の住所を入力してください。', $label);
        }

        $hasPrefecture = false;

        foreach (self::prefectureNames() as $prefecture) {
            if (str_contains($address, $prefecture)) {
                $hasPrefecture = true;
                break;
            }
        }

        if (!$hasPrefecture) {
            return sprintf('%sは、都道府県名から入力してください。（例：東京都渋谷区渋谷1-2-3）', $label);
        }

        if (!preg_match('/[\d一二三四五六七八九十]+(?:丁目|番|号|-)|\d/u', $address)) {
            return sprintf('%sは、番地まで入力してください。（例：東京都渋谷区渋谷1-2-3）', $label);
        }

        return null;
    }

    /** @return array<int, string> */
    public static function prefectureNames(): array
    {
        $states = function_exists('WC') ? WC()->countries->get_states('JP') : [];

        return is_array($states) && $states ? array_values(array_map('strval', $states)) : [];
    }

    private static function isPlaceholder(string $normalised): bool
    {
        $compact = str_replace([' ', '　'], '', $normalised);

        foreach (self::PLACEHOLDERS as $word) {
            if ($compact === mb_strtolower(self::normalise($word))) {
                return true;
            }
        }

        return false;
    }

    private static function isOneCharacterRepeated(string $normalised): bool
    {
        $chars = preg_split('//u', str_replace(' ', '', $normalised), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($chars) >= 3 && count(array_unique($chars)) === 1;
    }
}
