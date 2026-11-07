<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * A search that forgives how an Arabic name is spelled: أ إ آ ٱ match ا,
 * ى matches ي and ة matches ه, short vowels and the tatweel are ignored,
 * Arabic-Indic digits match Western ones, and each word of the search may
 * be found anywhere, in any order.
 */
final class ArabicSearch
{
    /** Letters written in more than one way, mapped to the one compared. */
    private const LETTERS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه'];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** The longest search worth running: further words only slow it down. */
    private const MAX_TERMS = 5;

    /** The text as it is compared: one spelling, no vowel marks, Western digits, lower case. */
    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, self::LETTERS + self::DIGITS);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * The words of a search, each of which a match must contain.
     *
     * @return array<int, string>
     */
    public static function terms(string $search): array
    {
        $normalized = self::normalize($search);

        if ($normalized === '') {
            return [];
        }

        return array_slice(array_values(array_unique(explode(' ', $normalized))), 0, self::MAX_TERMS);
    }

    /**
     * Also match rows whose `$column`, spelled as normalize() spells it,
     * contains `$term` (already normalized).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function orWhereContains(Builder $query, string $column, string $term): Builder
    {
        $expression = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));

        foreach (self::LETTERS as $from => $to) {
            $expression = "replace({$expression}, '{$from}', '{$to}')";
        }

        return $query->orWhereRaw(
            "lower({$expression}) like ? escape '!'",
            ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%'],
        );
    }
}
