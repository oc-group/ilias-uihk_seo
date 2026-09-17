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
 * Secondary component key/value pair resolved from the current request
 * (see ilSeo::SECONDARY_ID_KEYS and ilSeo::getSecondaryComponentFromRequest()).
 */
final class ilSeoSecondaryComponent
{
    public function __construct(
        public readonly string $key,
        public readonly int $value
    ) {
    }
}
