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
 * The single definition of how a value is made safe to place in HTML: html()
 * for the normal case, inertText() for a value that must stay readable in a
 * medium that does not decode entities.
 *
 * The flag set is the point of html(). ENT_QUOTES escapes the single quote as
 * well as the double, so one call covers both attribute quoting styles.
 * ENT_SUBSTITUTE replaces a malformed UTF-8 byte sequence with U+FFFD instead
 * of returning an empty string, which is what PHP does by default when neither
 * ENT_SUBSTITUTE nor ENT_IGNORE is given: a single bad byte anywhere in a title
 * would otherwise blank the whole value silently. Passing a bare ENT_QUOTES
 * drops ENT_SUBSTITUTE back out of PHP 8.1's own default and reintroduces
 * exactly that, so the two flags belong together in one place rather than at
 * every call site.
 */
class ilSeoEscape
{
    /**
     * A value made safe for HTML text and for quoted attribute values alike.
     * @param string $value
     * @return string
     */
    public static function html(string $value): string
    {
        return str_replace(
            ["{", "}"],
            ["&#123;", "&#125;"],
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")
        );
    }

    /**
     * A value with "<", ">" and both quote characters removed, leaving it inert
     * in element text, in a quoted attribute and inside a JSON payload alike.
     * Nothing is entity-encoded, so "&" survives as one character: use this in
     * place of html() where the string also reaches a medium that does not
     * decode entities, such as a canvas.
     * @param string $value
     * @return string
     */
    public static function inertText(string $value): string
    {
        return str_replace(["<", ">", "\"", "'"], "", $value);
    }
}
