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

namespace ilSeo\ObjectHandlers;

/**
 * HTML learning module dispatch: its GUI has no "view" command, so a permalink
 * opens the info screen, as the object's own core goto does.
 */
class HtlmHandler extends AbstractHandler
{
    /**
     * @return string
     */
    protected function dispatchCmd(): string
    {
        return "infoScreen";
    }
}
