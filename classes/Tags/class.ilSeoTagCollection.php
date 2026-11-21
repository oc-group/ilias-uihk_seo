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

use ILIAS\Data\Meta\Html\OpenGraph\TagCollection;
use ILIAS\Data\Meta\Html\Tag;

/**
 * SEO plugin tag collection class.
 */
class ilSeoTagCollection extends TagCollection
{
    /**
     * ILIAS core's own TagCollection::getTags() docblock says "Tag[]|Generator"
     * even though its native (and actual) return type is just Generator, so
     * Psalm infers parent::getTags() as that stale union and flags our precise,
     * native-type-matching declaration below as a mismatch.
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     * @return Generator<int, Tag>
     */
    public function getTags(): Generator
    {
        array_unshift($this->tags, new ilSeoReadmeTag(true));
        $this->tags[] = new ilSeoReadmeTag(false);
        return parent::getTags();
    }
}
