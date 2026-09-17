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
 * Wiki page dispatch: a raw GET-superglobal write, no ilCtrl priming
 * (ilObjWikiGUI forwards internally based on cmd/wpg_id alone). Content
 * detection needs its own rule: wiki page content renders under screen-id
 * component "copgwpg" (the shared COPage renderer), not one of the generic
 * "content"/"view_content" scr values or the empty-scr catch-all.
 */
class WikiHandler extends AbstractHandler
{
    /**
     * @param int $secondary_id
     * @return void
     */
    public function prepareGotoDispatch(int $secondary_id): void
    {
        $_GET["baseClass"] = \ilRepositoryGUI::class;
        $_GET["cmd"] = "view";

        $param = $this->getSecondaryIdParam();
        if ($param !== null && $secondary_id !== 0) {
            $_GET["cmd"] = "viewPage";
            $_GET[$param] = $secondary_id;
        }
    }

    /**
     * The same destination prepareGotoDispatch() primes above, spelled as a
     * link: built through the class chain, not the bare baseClass+cmd the
     * dispatch uses, so it carries the cmdNode every in-app wiki page link
     * carries.
     * @return array{0: class-string[], 1: string}
     */
    protected function getPageTarget(): ?array
    {
        return [[\ilRepositoryGUI::class, \ilObjWikiGUI::class], "viewPage"];
    }

    /**
     * A wiki page's own date lives on its COPage row; "il_wiki_page" keeps only
     * "create_date".
     * @param int[] $secondary_ids
     * @param string $lang
     * @return array<int, string>
     */
    public function lastChangesFor(array $secondary_ids, string $lang): array
    {
        return $this->copageLastChanges("wpg", $secondary_ids, $lang);
    }

    /**
     * A wiki page's title is per language and lives on the page's own row, not
     * its COPage one: "il_wiki_page" has primary key (id, lang). The "-" row is
     * the master language and the fallback when the requested language has no
     * page of its own.
     * @param int $secondary_id
     * @param string $lang
     * @return string
     */
    public function itemTitle(int $secondary_id, string $lang): string
    {
        // Null for a page id with no row in that language at all, "" for one
        // whose row carries no title; the cast folds both into the same miss.
        $title = (string) \ilWikiPage::lookupTitle($secondary_id, $lang);
        if ($title === "" && $lang !== "-") {
            $title = (string) \ilWikiPage::lookupTitle($secondary_id, "-");
        }

        return $title;
    }

    /**
     * "copgwpg" is set by ilWikiPageGUI::setScreenIdComponent() only when a
     * request forwards into the page GUI for content viewing; the wiki
     * container's own admin/list screens use component "wiki" instead
     * (ilObjWikiGUI::getTabs()) and must not match here.
     * @param string $screen_id
     * @return bool
     */
    public function isContentScreen(string $screen_id): bool
    {
        [$component, $scr] = array_pad(explode("/", $screen_id, 3), 2, "");

        return ($component === "copgwpg" && !str_starts_with($scr, "edit"))
            || parent::isContentScreen($screen_id);
    }
}
