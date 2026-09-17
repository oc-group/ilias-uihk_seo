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
 * Generic fallback per-object-type behavior: a raw GET-superglobal secondary_id
 * write against ilRepositoryGUI, no ilCtrl priming, and a generic screen-id
 * content rule. Used for any object type with no dedicated handler, including
 * every type outside SECONDARY_ID_KEYS (e.g. a course or category), all reached
 * via HandlerFactory's default case. It also serves as the base class the
 * dedicated handlers extend.
 */
class AbstractHandler
{
    /** @var string */
    protected string $obj_type;

    /**
     * @param string $obj_type
     * @return void
     */
    public function __construct(string $obj_type)
    {
        $this->obj_type = $obj_type;
    }

    /**
     * The secondary_id GET param name for this type, if it has one.
     * @return string|null
     */
    public function getSecondaryIdParam(): ?string
    {
        return \ilSeo::SECONDARY_ID_KEYS[$this->obj_type] ?? null;
    }

    /**
     * Populate the GET superglobal before ilInitialisation::initILIAS(): flat
     * assignment, no ilCtrl priming.
     * @param int $secondary_id
     * @return void
     */
    public function prepareGotoDispatch(int $secondary_id): void
    {
        $_GET["baseClass"] = \ilRepositoryGUI::class;

        $param = $this->getSecondaryIdParam();
        if ($param === null || $secondary_id === 0) {
            return;
        }

        $_GET[$param] = $secondary_id;
    }

    /**
     * No ilCtrl priming needed by default: the dispatch above already reaches
     * the content via a flat cmd/param, with no nested ilCtrl hop to prime.
     * @param int $secondary_id
     * @return void
     */
    public function finalizeGotoDispatch(int $secondary_id): void
    {
    }

    /**
     * The ilCtrl class chain and cmd that open an addressed sub-item's own
     * view. This is the link counterpart of
     * prepareGotoDispatch()/finalizeGotoDispatch(), which prime that same
     * destination for an incoming permalink. Null by default: the generic
     * fallback handler addresses no sub-item at all.
     * @return array{0: class-string[], 1: string}|null [ilCtrl class chain,
     *     cmd]
     */
    protected function getPageTarget(): ?array
    {
        return null;
    }

    /**
     * Query parameters the sub-item's view needs beyond ref_id and this type's
     * own secondary_id param. Empty by default: only a DCL record is addressed
     * by more than its own id.
     * @param int $secondary_id
     * @return array<string, int|string>
     */
    protected function getPageParams(int $secondary_id): array
    {
        return [];
    }

    /**
     * The URL of exactly the page this ref_id and secondary_id address, for the
     * call sites that must send a user back to the page they were on rather
     * than to the object holding it. The object's own permanent link when no
     * sub-item is addressed, or when this type has no sub-item view of its own.
     *
     * Deliberately no language parameter: "transl" is core's own
     * content-translation selector on exactly these screens, and a wiki or LM
     * page with no translation row in the language passed does not fall back,
     * it ends the request on error.php. The landing page resolves the language
     * the way it always has.
     * @param int $ref_id
     * @param int $secondary_id 0 for the object itself
     * @return string
     */
    public function pageUrl(int $ref_id, int $secondary_id): string
    {
        global $DIC;

        $param = $this->getSecondaryIdParam();
        if ($param === null || $secondary_id === 0) {
            return \ilLink::_getLink($ref_id);
        }

        $target = $this->getPageTarget();
        if ($target === null) {
            return \ilLink::_getLink($ref_id);
        }

        $ctrl = $DIC->ctrl();

        [$chain, $cmd] = $target;
        $leaf = $chain[count($chain) - 1];
        $params = ["ref_id" => $ref_id, $param => $secondary_id] + $this->getPageParams($secondary_id);

        foreach ($params as $key => $value) {
            $ctrl->setParameterByClass($leaf, $key, $value);
        }
        $url = $ctrl->getLinkTargetByClass($chain, $cmd);
        foreach (array_keys($params) as $key) {
            // ilCtrl keeps a parameter for the rest of the request, so a link
            // built after this one would otherwise inherit the page just left.
            $ctrl->setParameterByClass($leaf, $key, null);
        }

        return $url;
    }

    /**
     * Generic content-screen rule: known content "scr" values, or a
     * "component//" pattern (empty scr/sub_scr) excluding the anonymous
     * login/logout screens.
     * @param string $screen_id "component/scr/sub_scr", per
     *     ilHelpGUI::getScreenId()
     * @return bool
     */
    public function isContentScreen(string $screen_id): bool
    {
        [$component, $scr] = array_pad(explode("/", $screen_id, 3), 2, "");

        if (
            in_array($scr, ["content", "view_content", "forums_threads"], true)
            || ($component === "repository" && $scr !== "")
            || (bool) preg_match("/\/(\/(?!login|logout))/", $screen_id)
        ) {
            return true;
        }

        return $this->matchesContentViewSignature();
    }

    /**
     * Whether the page a permalink addresses can actually be opened by the
     * given visitor, beyond the object-level "read" permission the caller
     * already checked. True by default: only the types with a page-level gate
     * of their own override this.
     *
     * Deliberately no $lang, unlike lastChangesFor()/itemTitle():
     * ilPageObject::_writeActive() only ever writes the "-" row, and every
     * LM/Blog activation gate in core reads it with the default "-" lang, so a
     * translated COPage row's active column is never consulted at presentation
     * time. Passing $lang here would drop pages ILIAS still serves.
     * @param int $secondary_id
     * @param int $user_id visitor the reachability is judged for
     * @return bool
     */
    public function isPageReachable(int $secondary_id, int $user_id): bool
    {
        return true;
    }

    /**
     * The last-modified date of each addressed sub-item, keyed by secondary_id,
     * in the DB's own datetime format. Empty by default: only the types that
     * keep a date per sub-item override this, and a secondary_id left out of
     * the result falls back to the object-level date.
     * @param int[] $secondary_ids
     * @param string $lang SEO row language, "-" for the default sentinel
     * @return array<int, string>
     */
    public function lastChangesFor(array $secondary_ids, string $lang): array
    {
        return [];
    }

    /**
     * The title of one addressed sub-item, for the places that must name the
     * item itself rather than the object containing it.
     *
     * Empty by default, and empty for a secondary_id that no longer resolves.
     * DataCollectionHandler deliberately does not override it: il_dcl_record
     * has no title column and core names a record only by id or ordinal
     * position, so there is nothing to return that ILIAS itself would recognize
     * as that record's name.
     * @param int $secondary_id
     * @param string $lang SEO row language, "-" for the default sentinel
     * @return string
     */
    public function itemTitle(int $secondary_id, string $lang): string
    {
        return "";
    }

    /**
     * The plugin-internal reserved permalink for this handler's object, for
     * types dispatched by a fixed value instead of a stored row (e.g. the
     * imprint, which has no ref_id to key a permalink row on). Null by default:
     * only ImprintHandler overrides this.
     * @return string|null
     */
    public function getPermalink(): ?string
    {
        return null;
    }

    /**
     * last_change of the COPage rows the given page ids address, preferring the
     * requested language over the "-" canonical one (the same resolution the
     * permalink dispatch applies to the page it renders). Page activity is not
     * filtered: an unreachable page is already withheld by isPageReachable(),
     * and a scheduled-activation page is reachable while its stored "active"
     * flag is still 0.
     * @param string $parent_type
     * @param int[] $page_ids
     * @param string $lang
     * @return array<int, string>
     */
    protected function copageLastChanges(string $parent_type, array $page_ids, string $lang): array
    {
        global $DIC;
        $db = $DIC->database();

        $res = $db->query(
            "SELECT page_id, lang, last_change FROM page_object
            WHERE parent_type = " . $db->quote($parent_type, "text") . "
            AND " . $db->in("page_id", $page_ids, false, "integer") . "
            AND " . $db->in("lang", array_values(array_unique([$lang, "-"])), false, "text")
        );

        // A row in the requested language always wins over the "-" one for the
        // same page, whichever order the two arrive in.
        $dates = [];
        $is_exact = [];
        while ($row = $db->fetchAssoc($res)) {
            $page_id = (int) $row["page_id"];
            if (($row["last_change"] ?? null) === null || ($is_exact[$page_id] ?? false)) {
                continue;
            }

            $dates[$page_id] = (string) $row["last_change"];
            $is_exact[$page_id] = (string) $row["lang"] === $lang;
        }

        return $dates;
    }

    /**
     * Fold a "<id>, last_change" result set into an id => date map, dropping
     * the rows that carry no date at all.
     * @param \ilDBInterface $db
     * @param \ilDBStatement $res
     * @param string $id_column
     * @return array<int, string>
     */
    protected static function toDateMap(\ilDBInterface $db, \ilDBStatement $res, string $id_column): array
    {
        $dates = [];
        while ($row = $db->fetchAssoc($res)) {
            if (($row["last_change"] ?? null) !== null) {
                $dates[(int) $row[$id_column]] = (string) $row["last_change"];
            }
        }

        return $dates;
    }

    /**
     * The content-view GUI class + cmd this handler's public view ultimately
     * dispatches to, for types whose help screen-id cannot be trusted (see
     * BlogHandler/DataCollectionHandler). Null by default: most types'
     * screen-id check above is sufficient on its own.
     * @return array{0: class-string, 1: string}|null [cmdClass, cmd]
     */
    protected function getContentViewSignature(): ?array
    {
        return null;
    }

    /**
     * Fallback signal for isContentScreen() when the screen-id check fails:
     * ilTabsGUI::clearTargets() only calls
     * $DIC->help()->setScreenIdComponent(""), never touching ilCtrl's
     * cmd/cmdClass state, so a handler whose content view sets its screen-id
     * via a path that later gets cleared can still be recognized here.
     * ilCtrl::getCmdClass() always returns a lowercase class name (or "" if
     * unset), hence the strtolower() on the signature side.
     * @return bool
     */
    private function matchesContentViewSignature(): bool
    {
        global $DIC;

        $signature = $this->getContentViewSignature();
        if ($signature === null) {
            return false;
        }

        [$cmd_class, $cmd] = $signature;

        return $DIC->ctrl()->getCmdClass() === strtolower($cmd_class)
            && $DIC->ctrl()->getCmd() === $cmd;
    }
}
