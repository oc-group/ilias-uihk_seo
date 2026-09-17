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
 * Forum thread dispatch: a raw GET-superglobal write, no ilCtrl priming
 * (ilRepositoryGUI forwards internally based on cmd/thr_pk alone). Content
 * detection needs no override: forum thread content matches the
 * "forums_threads" scr value already covered by the parent's generic rule.
 */
class ForumHandler extends AbstractHandler
{
    /**
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

        $_GET["cmd"] = "viewThread";
        $_GET[$param] = $secondary_id;
    }

    /**
     * Primes the ilCtrl chain via the cmdNode request key instead of the
     * redirect that drops thr_pk (see class docblock). Needed for both the
     * thread case and the forum-root case (secondary_id 0). Must run before
     * ILIAS finishes initializing.
     * @param int $secondary_id
     * @return void
     */
    public function finalizeGotoDispatch(int $secondary_id): void
    {
        $_GET["cmdNode"] = \ilSeoInitialisation::buildCidPath([
            \ilRepositoryGUI::class,
            \ilObjForumGUI::class,
        ]);
        $_GET["cmdClass"] = strtolower(\ilObjForumGUI::class);

        if ($secondary_id === 0) {
            return;
        }

        $_GET["cmd"] = "viewThread";
    }

    /**
     * The same destination prepareGotoDispatch()/finalizeGotoDispatch()
     * prime above, spelled as a link.
     * @return array{0: class-string[], 1: string}
     */
    protected function getPageTarget(): ?array
    {
        return [[\ilRepositoryGUI::class, \ilObjForumGUI::class], "viewThread"];
    }

    /**
     * A thread's title is its subject, on its own "frm_threads" row: no lang
     * column, so the language plays no part. The stored value has "<" and ">"
     * entity-encoded, and this static lookup returns it raw where
     * ilForumTopic::read() would have decoded it, so it is decoded here.
     * Missing row -> "".
     * @param int $secondary_id
     * @param string $lang
     * @return string
     */
    public function itemTitle(int $secondary_id, string $lang): string
    {
        return html_entity_decode(\ilObjForum::_lookupThreadSubject($secondary_id), ENT_QUOTES, "UTF-8");
    }

    /**
     * A thread's own date is the newest date among its posts:
     * "frm_threads.thr_update" stays NULL unless the thread's root post is
     * edited. Posts still awaiting moderator activation are excluded, since an
     * anonymous visitor is never served them.
     * @param int[] $secondary_ids
     * @param string $lang
     * @return array<int, string>
     */
    public function lastChangesFor(array $secondary_ids, string $lang): array
    {
        global $DIC;
        $db = $DIC->database();

        $res = $db->query(
            "SELECT pos_thr_fk, MAX(COALESCE(pos_update, pos_date)) AS last_change FROM frm_posts
            WHERE pos_status = " . $db->quote(1, "integer") . "
            AND " . $db->in("pos_thr_fk", $secondary_ids, false, "integer") . "
            GROUP BY pos_thr_fk"
        );

        return self::toDateMap($db, $res, "pos_thr_fk");
    }
}
