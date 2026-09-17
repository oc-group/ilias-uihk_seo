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
 * Tag link for SEO plugin.
 */
class ilSeoLinkTag extends Tag
{
    /**
     * Link tag constructor.
     * @param string $rel
     * @param string $href
     * @param string $hreflang
     */
    public function __construct(
        protected string $rel,
        protected string $href,
        protected string $hreflang = ""
    ) {
    }

    /**
     * Return the html tag.
     * @return string
     */
    public function toHtml(): string
    {
        $rel = ilSeoEscape::html($this->rel);
        $href = ilSeoEscape::html($this->href);
        $hreflang = ilSeoEscape::html($this->hreflang);

        if (!empty($hreflang)) {
            return "<link rel=\"{$rel}\" href=\"{$href}\" hreflang=\"{$hreflang}\" />";
        }

        return "<link rel=\"{$rel}\" href=\"{$href}\" />";
    }
}
