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
 * DCL dispatch: a redirect-free ilCtrl forward into ilDclRecordListGUI (root,
 * secondary_id 0) or ilDclDetailedViewGUI (record, secondary_id != 0), needed
 * because ilRepositoryGUI plus a bare cmd cannot reach either nested class on
 * its own. Without the ilCtrl priming below, ilObjDataCollectionGUI's own "no
 * next class" fallback lands on its final listRecords() stub, which redirects
 * (HTTP 302) rather than rendering. "table_id" is resolved via a direct query
 * rather than ilDclCache::getRecordCache(), whose full record-model constructor
 * needs $DIC->event(), not registered before initILIAS().
 */
class DataCollectionHandler extends AbstractHandler
{
    /**
     * @param int $secondary_id
     * @return void
     */
    public function prepareGotoDispatch(int $secondary_id): void
    {
        $_GET["baseClass"] = \ilRepositoryGUI::class;
        $_GET["cmd"] = "listRecords";

        $param = $this->getSecondaryIdParam();
        if ($param === null || $secondary_id === 0) {
            return;
        }

        $_GET[$param] = $secondary_id;

        foreach ($this->getPageParams($secondary_id) as $key => $value) {
            $_GET[$key] = $value;
        }
    }

    /**
     * Two direct parent-child ilCtrl hops plus setCmd(), so the permalink
     * resolves within the same request/response instead of a real HTTP
     * redirect. Root case (secondary_id 0): ilRepositoryGUI ->
     * ilObjDataCollectionGUI -> ilDclRecordListGUI, cmd "listRecords". Without
     * this priming, ilObjDataCollectionGUI::executeCommand()'s "no next class"
     * fallback reaches its own final listRecords(), which is a bare
     * redirectByClass(ilDclRecordListGUI::class, "show") stub, not the renderer
     * itself (the actual table HTML is only ever built by
     * ilDclRecordListGUI::listRecords()). Record case (secondary_id != 0):
     * ilRepositoryGUI -> ilObjDataCollectionGUI -> ilDclDetailedViewGUI, cmd
     * "renderRecord".
     * @param int $secondary_id
     * @return void
     */
    public function finalizeGotoDispatch(int $secondary_id): void
    {
        global $DIC;

        $DIC->ctrl()->setCmdClass(\ilObjDataCollectionGUI::class);

        if ($secondary_id === 0) {
            $DIC->ctrl()->setCmdClass(\ilDclRecordListGUI::class);
            $DIC->ctrl()->setCmd("listRecords");
            return;
        }

        $DIC->ctrl()->setCmdClass(\ilDclDetailedViewGUI::class);
        $DIC->ctrl()->setCmd("renderRecord");
    }

    /**
     * The record case of finalizeGotoDispatch() above, spelled as a link. The
     * root case needs none: secondary_id 0 never reaches here (see
     * AbstractHandler::pageUrl()).
     * @return array{0: class-string[], 1: string}
     */
    protected function getPageTarget(): ?array
    {
        return [
            [\ilRepositoryGUI::class, \ilObjDataCollectionGUI::class, \ilDclDetailedViewGUI::class],
            "renderRecord",
        ];
    }

    /**
     * A record is addressed by its table as well as by its own id, both for the
     * permalink dispatch above and for a link built to it, which is why
     * prepareGotoDispatch() reads its "table_id" from here. The direct query is
     * explained in the class docblock.
     * @param int $secondary_id
     * @return array<string, int|string>
     */
    protected function getPageParams(int $secondary_id): array
    {
        global $DIC;

        $record_row = $DIC->database()->fetchAssoc(
            $DIC->database()->queryF(
                "SELECT table_id FROM il_dcl_record WHERE id = %s;",
                ["integer"],
                [$secondary_id]
            )
        );

        return $record_row !== null ? ["table_id" => (int) $record_row["table_id"]] : [];
    }

    /**
     * A record permalink is only reachable when its table view's "Detailed
     * View" is active: ilDclDetailedViewGUI::checkAccess() gates every visitor,
     * root included, on ilDclDetailedViewDefinition::isActive(). The table view
     * is resolved the same way this plugin's own go.php dispatch does
     * (ilDclTable::getFirstTableViewId()).
     * @param int $secondary_id
     * @param int $user_id
     * @return bool
     */
    public function isPageReachable(int $secondary_id, int $user_id): bool
    {
        if ($secondary_id === 0) {
            return true;
        }

        $record = \ilDclCache::getRecordCache($secondary_id);
        if ($record->getId() === 0) {
            // Record no longer exists; nothing for a visitor to reach either
            // way.
            return false;
        }

        $tableview_id = $record->getTable()->getFirstTableViewId($user_id);
        if ($tableview_id === null) {
            return false;
        }

        return \ilDclDetailedViewDefinition::isActive($tableview_id);
    }

    /**
     * A record's own date, falling back to its creation date while it has never
     * been edited (the same fallback ilDclBaseRecordModel::doRead() applies).
     * The record's Detailed View COPage is keyed on the table view, not the
     * record, so it is not a per-record date.
     * @param int[] $secondary_ids
     * @param string $lang
     * @return array<int, string>
     */
    public function lastChangesFor(array $secondary_ids, string $lang): array
    {
        global $DIC;
        $db = $DIC->database();

        $res = $db->query(
            "SELECT id, COALESCE(last_update, create_date) AS last_change FROM il_dcl_record
            WHERE " . $db->in("id", $secondary_ids, false, "integer")
        );

        return self::toDateMap($db, $res, "id");
    }

    /**
     * ilObjDataCollectionGUI::executeCommand() sets the help screen-id
     * component to "dcl" unconditionally, then
     * ilDclDetailedViewGUI::renderRecord() sets scr to "dcl_record". Neither
     * matches the parent's screen-id clauses, and the record's own COPage
     * ("Detailed View") renders via a direct showPage() call in presentation
     * mode, which never sets the "copg" component the wiki/blog COPage flows
     * do. ilCtrl::getCmdClass()/getCmd() stay set to exactly what
     * finalizeGotoDispatch() primed regardless, so they are used here as the
     * fallback content-view signal instead.
     * @return array{0: class-string, 1: string}
     */
    protected function getContentViewSignature(): ?array
    {
        return [\ilDclDetailedViewGUI::class, "renderRecord"];
    }
}
