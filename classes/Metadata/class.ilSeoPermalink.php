<?php

/**
 * This file is part of Seo UI Plugin for ILIAS,
 * developed by OC Open Consulting to enable
 * SEO functionalities in ILIAS.
 *
 * @author Vincenzo Padula <vincenzo@oc-group.eu>
 * @copyright 2026 OC Open Consulting SB Srl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

use ilSeo\ObjectHandlers\HandlerFactory;

/**
 * Pure permalink string rules: validation, normalization, and the
 * reserved-permalink check. No DB/DIC dependency; split out of ilSeo so that
 * class stays under the PHPMD TooManyPublicMethods ceiling.
 *
 * Transliteration is a hand-written table rather than Transliterator or
 * iconv("ASCII//TRANSLIT"): ILIAS 9 requires only ext-mbstring, neither
 * ext-intl nor ext-iconv, and iconv additionally emits ,, and " artifacts for
 * typographic quotes in every locale. Digraphs are expanded (ae/oe/ue/ss/aa/th)
 * the way ILIAS core's own ASCII filename handling expands them, so permalinks
 * read the way core's filenames do. Scripts the table does not cover (Greek,
 * Cyrillic, CJK) are not transliterated at all: normalize() turns them into a
 * separator, leaving the required field empty for the user to fill.
 */
class ilSeoPermalink
{
    /** @var string shape of a valid permalink: one or more "/"-terminated segments, each starting with a letter or digit */
    public const PERMALINK_REGEXP = "/^([a-z0-9][a-z0-9-]*\/)+$/s";

    /** @var string regexp character class a normalized permalink may contain; clientRules() ships it to the browser too */
    public const ALLOWED_CHARS = "a-z0-9/";

    /** @var string what a run outside ALLOWED_CHARS collapses into; shared with the client side like ALLOWED_CHARS */
    public const SEPARATOR = "-";

    /** @var array<string,string> diacritic and digraph Latin letters mapped to the plain ASCII a URL reader expects */
    private const TRANSLITERATIONS = [
        "À" => "A", "Á" => "A", "Â" => "A", "Ã" => "A", "Ā" => "A", "Ă" => "A", "Ą" => "A",
        "à" => "a", "á" => "a", "â" => "a", "ã" => "a", "ā" => "a", "ă" => "a", "ą" => "a",
        "Ä" => "Ae", "ä" => "ae", "Å" => "Aa", "å" => "aa", "Æ" => "Ae", "æ" => "ae",
        "Ç" => "C", "Ć" => "C", "Ĉ" => "C", "Ċ" => "C", "Č" => "C",
        "ç" => "c", "ć" => "c", "ĉ" => "c", "ċ" => "c", "č" => "c",
        "Ð" => "D", "Đ" => "D", "Ď" => "D", "ð" => "d", "đ" => "d", "ď" => "d",
        "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E", "Ē" => "E", "Ĕ" => "E", "Ė" => "E", "Ę" => "E", "Ě" => "E",
        "è" => "e", "é" => "e", "ê" => "e", "ë" => "e", "ē" => "e", "ĕ" => "e", "ė" => "e", "ę" => "e", "ě" => "e",
        "Ĝ" => "G", "Ğ" => "G", "Ġ" => "G", "Ģ" => "G", "ĝ" => "g", "ğ" => "g", "ġ" => "g", "ģ" => "g",
        "Ĥ" => "H", "Ħ" => "H", "ĥ" => "h", "ħ" => "h",
        "Ì" => "I", "Í" => "I", "Î" => "I", "Ï" => "I", "Ĩ" => "I", "Ī" => "I", "Ĭ" => "I", "Į" => "I", "İ" => "I",
        "ì" => "i", "í" => "i", "î" => "i", "ï" => "i", "ĩ" => "i", "ī" => "i", "ĭ" => "i", "į" => "i", "ı" => "i",
        "Ĵ" => "J", "ĵ" => "j", "Ķ" => "K", "ķ" => "k",
        "Ĺ" => "L", "Ļ" => "L", "Ľ" => "L", "Ŀ" => "L", "Ł" => "L",
        "ĺ" => "l", "ļ" => "l", "ľ" => "l", "ŀ" => "l", "ł" => "l",
        "Ñ" => "N", "Ń" => "N", "Ņ" => "N", "Ň" => "N", "Ŋ" => "N",
        "ñ" => "n", "ń" => "n", "ņ" => "n", "ň" => "n", "ŋ" => "n",
        "Ò" => "O", "Ó" => "O", "Ô" => "O", "Õ" => "O", "Ō" => "O", "Ŏ" => "O", "Ő" => "O",
        "ò" => "o", "ó" => "o", "ô" => "o", "õ" => "o", "ō" => "o", "ŏ" => "o", "ő" => "o",
        "Ö" => "Oe", "ö" => "oe", "Ø" => "Oe", "ø" => "oe", "Œ" => "Oe", "œ" => "oe",
        "Ŕ" => "R", "Ŗ" => "R", "Ř" => "R", "ŕ" => "r", "ŗ" => "r", "ř" => "r",
        "Ś" => "S", "Ŝ" => "S", "Ş" => "S", "Š" => "S", "Ș" => "S",
        "ś" => "s", "ŝ" => "s", "ş" => "s", "š" => "s", "ș" => "s",
        "Ţ" => "T", "Ť" => "T", "Ŧ" => "T", "Ț" => "T", "ţ" => "t", "ť" => "t", "ŧ" => "t", "ț" => "t",
        "Ù" => "U", "Ú" => "U", "Û" => "U", "Ũ" => "U", "Ū" => "U", "Ŭ" => "U", "Ů" => "U", "Ű" => "U", "Ų" => "U",
        "ù" => "u", "ú" => "u", "û" => "u", "ũ" => "u", "ū" => "u", "ŭ" => "u", "ů" => "u", "ű" => "u", "ų" => "u",
        "Ü" => "Ue", "ü" => "ue",
        "Ŵ" => "W", "ŵ" => "w", "Ý" => "Y", "Ŷ" => "Y", "Ÿ" => "Y", "ý" => "y", "ŷ" => "y", "ÿ" => "y",
        "Ź" => "Z", "Ż" => "Z", "Ž" => "Z", "ź" => "z", "ż" => "z", "ž" => "z",
        "Þ" => "Th", "þ" => "th", "ß" => "ss", "ẞ" => "Ss",
    ];

    /**
     * Check whether a permalink has the shape a saved SEO row is allowed to
     * have, i.e. it can be dispatched to without falling through to an
     * empty/broken redirect. The homepage row is the sole exception: its own
     * explicit toggle makes "" valid.
     * @param string $permalink
     * @param bool $is_homepage whether this permalink belongs to the row marked
     *     as homepage
     * @return bool
     */
    public static function isValid(string $permalink, bool $is_homepage = false): bool
    {
        if ($is_homepage) {
            return $permalink === "";
        }

        return $permalink !== "" && preg_match(self::PERMALINK_REGEXP, $permalink) === 1;
    }

    /**
     * Normalize a title or a user-typed permalink into the shape
     * PERMALINK_REGEXP expects, so e.g. "About Us" becomes "about-us/" instead
     * of being rejected outright. Server-side, so both save paths and the
     * suggestion shown in both forms always agree. Disallowed characters become
     * a separator instead of being deleted (deleting them would mash "Renamed
     * Forum" into "renamedforum/", defeating the readable-URL purpose of the
     * whole plugin); any run of them collapses into a single hyphen, and "/"
     * survives as the path separator it is. Idempotent: an already-stored
     * permalink re-normalizes to itself. Shape only: an empty result stays
     * empty, non-emptiness is still setRequired()'s/isValid()'s job.
     * @param string $raw
     * @return string
     */
    public static function normalize(string $raw): string
    {
        $permalink = strtr(trim($raw), self::TRANSLITERATIONS);
        $permalink = mb_strtolower($permalink, "UTF-8");
        $permalink = preg_replace("#[^" . self::ALLOWED_CHARS . "]+#", self::SEPARATOR, $permalink) ?? $permalink;

        // Per segment, so "-x-/-y-/" cleans to "x/y/"; empty segments drop out,
        // which also collapses "//" and strips leading/trailing slashes.
        $segments = [];
        foreach (explode("/", $permalink) as $segment) {
            $segment = trim($segment, self::SEPARATOR);
            if ($segment !== "") {
                $segments[] = $segment;
            }
        }

        return $segments === [] ? "" : implode("/", $segments) . "/";
    }

    /**
     * Check whether a permalink is reserved for a plugin-internal special route
     * and can therefore never be a legitimate user-chosen permalink. Sourced
     * from HandlerFactory::getReservedPermalinks() rather than a duplicated
     * literal, so the reserved list and each special page's own permalink never
     * drift apart.
     * @param string $permalink
     * @return bool
     */
    public static function isReserved(string $permalink): bool
    {
        return in_array($permalink, HandlerFactory::getReservedPermalinks(), true);
    }

    /**
     * The three things normalize() is made of (the transliteration table, the
     * allowed character class, the separator), handed out as data, so
     * templates/js/seo-permalink.js can sanitize the field as it is typed while
     * holding no copy of any rule.
     *
     * The client is a typing affordance only: normalize() still runs on every
     * save path and remains the authority.
     * @return array{transliterations: array<string,string>, allowed: string,
     *     separator: string}
     */
    public static function clientRules(): array
    {
        return [
            "transliterations" => self::TRANSLITERATIONS,
            "allowed" => self::ALLOWED_CHARS,
            "separator" => self::SEPARATOR,
        ];
    }
}
