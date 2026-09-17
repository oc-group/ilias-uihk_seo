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

use ILIAS\HTTP\Response\ResponseHeader;

/**
 * Every access question the plugin asks, in one place: three predicates, plus
 * the two refusals the administration screens raise on top of hasAdminWrite().
 * The require* pair is meant for a GUI gating its own dispatch: both end the
 * request instead of returning a value.
 *
 * Two unrelated permission models, neither implying the other: the
 * administration screens need "write" on the administration root, editing one
 * object's SEO metadata needs "write" on that object. canConfirmHomepage() is
 * the one deliberate exception: see its own docblock.
 *
 * Static rather than injected: the predicates are asked by the providers and
 * the layout hook under a partial bootstrap, where threading an instance
 * through is not practical.
 */
final class ilSeoAccess
{
    /**
     * Whether the actor may administer the plugin: "write" on the
     * administration root.
     *
     * Deliberately stricter than the core administration-access helper, which
     * checks "visible". That would advertise the SEO menu group to users the
     * screens then refuse.
     * @return bool
     */
    public static function hasAdminWrite(): bool
    {
        global $DIC;

        return $DIC->rbac()->system()->checkAccess("write", (int) SYSTEM_FOLDER_ID);
    }

    /**
     * Whether the actor may edit the SEO metadata of one repository object:
     * "write" on that object's own ref_id. Says nothing about administration
     * rights.
     *
     * @param int $ref_id ref_id of the object whose metadata is being edited
     * @return bool
     */
    public static function canEditObject(int $ref_id): bool
    {
        global $DIC;

        return $DIC->access()->checkAccess("write", "", $ref_id);
    }

    /**
     * Whether the actor may act on the homepage confirmation screen for this
     * ref_id: either predicate above is enough. This is the one place either
     * grants the other, because the screen only confirms a save that either
     * entry point could already perform unconfirmed: quickSave()/save() reach
     * it via canEditObject(), ilSeoOverviewGUI::updatePage() via
     * hasAdminWrite(). Requiring both would refuse an actor who can already
     * reach the same outcome from where they started.
     * @param int $ref_id ref_id of the object the confirmation screen is acting
     *     on
     * @return bool
     */
    public static function canConfirmHomepage(int $ref_id): bool
    {
        return self::canEditObject($ref_id) || self::hasAdminWrite();
    }

    /**
     * Refuse the current command unless the actor holds "write" on the
     * administration root.
     *
     * The landing is the actor's starting page and never a command of the
     * calling class: callers gate their whole dispatch, so a command offered as
     * a landing would refuse again.
     * @return void
     */
    public static function requireAdminWrite(): void
    {
        global $DIC;

        if (self::hasAdminWrite()) {
            return;
        }

        $DIC->ui()->mainTemplate()->setOnScreenMessage(
            "failure",
            ilSeoPlugin::getInstance()->txt("err_no_permission"),
            true
        );

        ilInitialisation::redirectToStartingPage();
    }

    /**
     * Refuse the current command with a bodiless 403 unless the actor holds
     * "write" on the administration root, and end the request there.
     *
     * For a command called by a fetch(), which reads the status but cannot
     * follow the redirect requireAdminWrite() sends.
     * @return void
     */
    public static function requireAdminWriteAsync(): void
    {
        global $DIC;

        if (self::hasAdminWrite()) {
            return;
        }

        $response = $DIC->http()->response()
            ->withStatus(403)
            ->withHeader(ResponseHeader::CONTENT_TYPE, "application/json; charset=utf-8")
            ->withHeader(ResponseHeader::CONTENT_LENGTH, "0")
            ->withHeader(ResponseHeader::CACHE_CONTROL, "no-store")
            ->withHeader(ResponseHeader::X_CONTENT_TYPE_OPTIONS, "nosniff");

        $DIC->http()->saveResponse($response);
        $DIC->http()->sendResponse();
        $DIC->http()->close();
    }
}
