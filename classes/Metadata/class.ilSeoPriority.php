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
 * The sitemap-priority vocabulary an SEO row may hold: the whole numbers 1 to
 * 10, which the sitemap builder divides by 10 into the 0.1..1.0 the protocol
 * allows.
 *
 * Same contract as ilSeoFrequency: every screen offering, validating or
 * pre-selecting a priority reads the range from here, and every stored value
 * passes normalize() before it reaches a KS Select, which throws on a value
 * outside its options.
 */
class ilSeoPriority
{
    /** @var int value a row falls back to when it holds nothing usable */
    public const DEFAULT_VALUE = 5;

    /** @var int */
    public const MIN = 1;

    /** @var int */
    public const MAX = 10;

    /**
     * The stored value clamped into the offered range, so a legacy row keeps
     * the end of the scale it was set to rather than jumping to the middle.
     * @param int $priority stored priority value
     * @return int
     */
    public static function normalize(int $priority): int
    {
        return max(self::MIN, min(self::MAX, $priority));
    }

    /**
     * The range as select options, value => label. PHP coerces the
     * numeric-string keys from array_combine() back to int, so the real key
     * type is int though every value is a string. PHPStan models this, Psalm
     * doesn't; suppressed here since PHPStan is correct.
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     * @return array<int,string>
     */
    public static function options(): array
    {
        $options = array_map("strval", range(self::MIN, self::MAX));

        return array_combine($options, $options);
    }
}
