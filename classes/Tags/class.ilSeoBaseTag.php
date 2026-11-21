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
 * Meta property tag for SEO plugin.
 */
class ilSeoBaseTag extends Tag
{
    /**
     * Base tag constructor.
     */
    public function __construct()
    {
    }

    /**
     * Return the html tag.
     * @return string
     */
    public function toHtml(): string
    {
        $url = ilSeoEscape::html(ILIAS_HTTP_PATH . "/");
        return "<base href=\"{$url}\" />";
    }
}
