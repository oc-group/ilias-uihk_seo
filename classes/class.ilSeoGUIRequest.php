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

use ILIAS\Repository\BaseGUIRequest;

/**
 * Typed accessors for the request parameters the plugin's GUI classes read
 * directly, i.e. everything not already handled by a form class. Each accessor
 * resolves POST first and the query string second, and returns a neutral empty
 * value when the parameter is absent, so callers never guard with has()
 * themselves.
 */
class ilSeoGUIRequest
{
    use BaseGUIRequest;

    /**
     * @param \ILIAS\HTTP\Services $http
     * @param \ILIAS\Refinery\Factory $refinery
     * @param array<string, mixed>|null $passed_query_params
     * @param array<string, mixed>|null $passed_post_data
     * @return void
     */
    public function __construct(
        \ILIAS\HTTP\Services $http,
        \ILIAS\Refinery\Factory $refinery,
        ?array $passed_query_params = null,
        ?array $passed_post_data = null
    ) {
        $this->initRequest($http, $refinery, $passed_query_params, $passed_post_data);
    }

    /**
     * The row ids submitted by a table bulk action as an "ids[]" array, keys
     * preserved. A scalar "ids" value yields [], not a one-element selection:
     * the mass-edit and mass-delete forms always submit the array form.
     * @return string[]
     */
    public function getIds(): array
    {
        return $this->strArray("ids");
    }

    /**
     * The mass-edit form's "index" field, "" when the form's "leave unchanged"
     * option was selected. Callers validate the value against the allowed set.
     * @return string
     */
    public function getMassEditIndex(): string
    {
        return $this->str("index");
    }

    /**
     * The mass-edit form's "follow" field, "" when left unchanged.
     * @return string
     */
    public function getMassEditFollow(): string
    {
        return $this->str("follow");
    }

    /**
     * The mass-edit form's "priority" field as submitted, "" when left
     * unchanged. Kept as a string so that "unchanged" stays distinguishable
     * from the value 0.
     * @return string
     */
    public function getMassEditPriority(): string
    {
        return $this->str("priority");
    }

    /**
     * The mass-edit form's "frequency" field, "" when left unchanged.
     * @return string
     */
    public function getMassEditFrequency(): string
    {
        return $this->str("frequency");
    }
}
