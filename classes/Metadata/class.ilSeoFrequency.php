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
 * The change-frequency vocabulary an SEO row may hold: the seven values
 * sitemaps.org defines for <changefreq>, which is also the only set the sitemap
 * generator accepts.
 *
 * Every screen that offers, labels or pre-selects a frequency reads it from
 * here. A KS Select throws an uncaught InvalidArgumentException when
 * withValue() gets a value outside its own options, so a screen offering a
 * narrower set than another screen can write does not merely look inconsistent:
 * it fails to render the rows the other screen created.
 */
class ilSeoFrequency
{
    /** @var string value a row falls back to when it holds nothing usable */
    public const DEFAULT_VALUE = "monthly";

    /** @var string[] the whole vocabulary, in sitemaps.org's own order */
    public const VALUES = ["always", "hourly", "daily", "weekly", "monthly", "yearly", "never"];

    /**
     * The stored value when it belongs to the vocabulary, DEFAULT_VALUE
     * otherwise. Every withValue() and every label lookup reads through here,
     * so no stored value can reach a Select that cannot hold it.
     * @param string $frequency stored frequency value
     * @return string
     */
    public static function normalize(string $frequency): string
    {
        $value = strtolower(trim($frequency));

        return in_array($value, self::VALUES, true) ? $value : self::DEFAULT_VALUE;
    }

    /**
     * The lang key labelling a stored value. Every stored value maps to a key
     * that exists, because normalize() always resolves to one of the seven.
     * @param string $frequency stored frequency value
     * @return string
     */
    public static function langKey(string $frequency): string
    {
        return "frequency_" . self::normalize($frequency);
    }

    /**
     * The vocabulary as select options, value => translated label.
     * @param ilSeoPlugin $plugin
     * @return array<string,string>
     */
    public static function options(ilSeoPlugin $plugin): array
    {
        $options = [];

        foreach (self::VALUES as $value) {
            $options[$value] = $plugin->txt(self::langKey($value));
        }

        return $options;
    }
}
