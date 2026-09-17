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
 * Readme tag for SEO plugin.
 */
class ilSeoReadmeTag extends Tag
{
    /**
     * Readme tag constructor.
     * @param bool $start
     */
    public function __construct(
        protected bool $start
    ) {
    }

    /**
     * Return the html tag.
     * @return string
     */
    public function toHtml(): string
    {
        return $this->start
            ? PHP_EOL . "<!-- SEO plugin for ILIAS -->"
            : "<!-- End SEO plugin for ILIAS -->";
    }
}
