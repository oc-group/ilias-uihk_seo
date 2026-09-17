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
 * What the three SEO editing forms share about their inputs: the mandatory rule
 * they declare, and how a rejected input is worded back to the user. The
 * metabar quick form, the advanced modal and the admin row-edit modal all save
 * the same row shape, so all three enforce one rule rather than each wording it
 * its own way.
 */
class ilSeoFormValidation
{
    /**
     * The mandatory-field rule, handed to FormInput::withRequired()'s own
     * second parameter.
     *
     * Not the framework default: that is hasMinLength(1) against the raw posted
     * string, so a value of only spaces counts as filled and is then stored as
     * the empty string it trims down to. Its wording ("falls below the minimum
     * length 1") is not for an author either.
     * @param ilSeoPlugin $plugin
     * @return ILIAS\Refinery\Constraint
     */
    public static function mandatory(ilSeoPlugin $plugin): ILIAS\Refinery\Constraint
    {
        global $DIC;

        return $DIC->refinery()->custom()->constraint(
            static fn ($value): bool => trim((string) $value) !== "",
            $plugin->txt("field_required")
        );
    }

    /**
     * Why a Kitchen Sink form refused the request: the first input it marked as
     * failed, named after its label. Every one of these saves ends in a
     * redirect, so the errors the form attached to its inputs are never
     * rendered; the on-screen message is the only channel that survives.
     *
     * getError() and getLabel() are declared on the implementation class rather
     * than on the Input interface getInputs() returns, hence the guard.
     * @param array<int|string, ILIAS\UI\Component\Input\Input> $inputs already
     *     run through withRequest()
     * @param ilSeoPlugin $plugin
     * @return string
     */
    public static function failureMessage(array $inputs, ilSeoPlugin $plugin): string
    {
        foreach ($inputs as $input) {
            if (!$input instanceof ILIAS\UI\Implementation\Component\Input\Field\FormInput) {
                continue;
            }

            $error = trim((string) $input->getError());
            if ($error !== "") {
                return sprintf(
                    $plugin->txt("metadata_not_saved_invalid"),
                    $input->getLabel() . ": " . $error
                );
            }
        }

        return $plugin->txt("metadata_not_saved");
    }
}
