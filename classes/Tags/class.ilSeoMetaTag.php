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

use ILIAS\Data\Meta\Html\Tag;

/**
 * Meta tag for SEO plugin.
 */
class ilSeoMetaTag extends Tag
{
    /**
     * Meta tag constructor.
     * @param string $key
     * @param string $value
     */
    public function __construct(
        protected string $key,
        protected string $value
    ) {
    }

    /**
     * Return the html tag.
     * @return string
     */
    public function toHtml(): string
    {
        $key = ilSeoEscape::html($this->key);
        $value = ilSeoEscape::html($this->value);
        return "<meta name=\"{$key}\" content=\"{$value}\" />";
    }
}
