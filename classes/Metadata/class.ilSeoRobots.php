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

/**
 * Reading of the comma-separated "robots" value an SEO row stores, e.g.
 * "index,follow" or "noindex,nofollow".
 *
 * Every directive test goes through hasToken(), never through a substring
 * search: "nofollow" contains "follow" and "noindex" contains "index", so
 * str_contains() or strpos() on the bare directive reports the exact opposite
 * of the stored value for half the combinations. Splitting on the comma first
 * and comparing whole tokens is the only reading that cannot invert.
 */
class ilSeoRobots
{
    /** @var string permissive default an unset or unreadable value falls back to */
    public const DEFAULT_VALUE = "index,follow";

    /**
     * The individual directives of a stored value, lowercased and trimmed,
     * empties dropped.
     * @param string $robots stored robots value, e.g. "index, follow"
     * @return string[]
     */
    public static function tokens(string $robots): array
    {
        $tokens = array_map(
            fn (string $token): string => strtolower(trim($token)),
            explode(",", $robots)
        );

        return array_values(array_filter($tokens, fn (string $token): bool => $token !== ""));
    }

    /**
     * Whether a stored value carries one exact directive.
     * @param string $robots stored robots value, e.g. "index,follow"
     * @param string $token single directive to look for, e.g. "follow"
     * @return bool
     */
    public static function hasToken(string $robots, string $token): bool
    {
        return in_array(strtolower(trim($token)), self::tokens($robots), true);
    }

    /**
     * Whether a stored value lets link equity flow out of the page. Only an
     * explicit "nofollow" directive stops it: an absent, empty or unrecognised
     * value reads as the permissive DEFAULT_VALUE the edit forms also start
     * from.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param string $robots stored robots value, e.g. "index,follow"
     * @return bool
     */
    public static function follows(string $robots): bool
    {
        return !self::hasToken($robots, "nofollow");
    }

    /**
     * The two directives a stored value carries, canonically ordered and
     * spelled: the index directive first, the follow directive second. Both are
     * read by name, so a value that omits one, spells them in the other order
     * or repeats one still yields exactly this pair. That is what makes the
     * result safe to write back. A missing or unrecognised directive reads as
     * the permissive DEFAULT_VALUE half.
     * @param string $robots stored robots value, e.g. "index,follow"
     * @return array{0:string,1:string}
     */
    public static function directives(string $robots): array
    {
        return [
            self::hasToken($robots, "noindex") ? "noindex" : "index",
            self::follows($robots) ? "follow" : "nofollow",
        ];
    }

    /**
     * The lang key labelling a stored value, one of the four
     * "robots_<index>_<follow>" combinations. Every stored value maps to a key
     * that exists, because directives() always resolves to one of the four
     * canonical pairs.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param string $robots stored robots value, e.g. "index,follow"
     * @return string
     */
    public static function langKey(string $robots): string
    {
        [$index, $follow] = self::directives($robots);

        return "robots_{$index}_{$follow}";
    }
}
