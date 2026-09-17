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
 * Learning module page dispatch: a raw GET-superglobal write, no ilCtrl priming
 * (ilLMPresentationGUI forwards internally based on cmd/obj_id alone). "resume"
 * is set unconditionally, letting the LM presentation resolve its own
 * current/first page when no secondary_id is present. Content detection needs
 * no override: it matches the parent's generic rule already.
 */
class LearningModuleHandler extends AbstractHandler
{
    /**
     * @param int $secondary_id
     * @return void
     */
    public function prepareGotoDispatch(int $secondary_id): void
    {
        $_GET["baseClass"] = \ilLMPresentationGUI::class;
        $_GET["cmd"] = "resume";

        $param = $this->getSecondaryIdParam();
        if ($param !== null && $secondary_id !== 0) {
            $_GET[$param] = $secondary_id;
        }
    }

    /**
     * The same destination prepareGotoDispatch() primes above, spelled as a
     * link: the LM presentation is its own baseClass, so the chain is that
     * single class.
     * @return array{0: class-string[], 1: string}
     */
    protected function getPageTarget(): ?array
    {
        return [[\ilLMPresentationGUI::class], "resume"];
    }

    /**
     * ilLMPresentationGUI reaches "resume" through a direct call rather than
     * a forwarded command, so the generic content-view detection this plugin
     * relies on elsewhere never matches on an LM page reached via plain
     * navigation: without this override the SEO MetaBar button never
     * appears, with no indication why. This fallback reads ilCtrl's own
     * cmdClass/cmd directly instead.
     * @return array{0: class-string, 1: string}
     */
    protected function getContentViewSignature(): ?array
    {
        return [\ilLMPresentationGUI::class, "resume"];
    }

    /**
     * A page permalink only serves the page when the page is active: an
     * inactive page renders a "deactivated" placeholder instead of its
     * content in ILIAS itself. Same call core makes, with the LM's own type
     * as page_object.parent_type ("lm" or "dbk") and the global
     * "time_scheduled_page_activation" switch deciding whether the window
     * applies.
     * @param int $secondary_id
     * @param int $user_id
     * @return bool
     */
    public function isPageReachable(int $secondary_id, int $user_id): bool
    {
        if ($secondary_id === 0 || \ilLMObject::_lookupType($secondary_id) !== "pg") {
            // No page addressed, or a chapter: the LM resolves an active page
            // itself.
            return true;
        }

        return \ilLMPage::_lookupActive(
            $secondary_id,
            $this->obj_type,
            (bool) (new \ilSetting("lm"))->get("time_scheduled_page_activation")
        );
    }

    /**
     * An LM page's or chapter's title is per language and spread over "lm_data"
     * and "lm_data_transl", with fallbacks that depend on the LM's
     * content-translation setting. _getPresentationTitle() is that whole rule
     * and is what every LM renderer calls, so it is used here rather than
     * reimplemented over the two tables.
     *
     * The mode argument matters: the default CHAPTER_TITLE returns a page's
     * parent chapter's title instead of its own.
     * @param int $secondary_id
     * @param string $lang
     * @return string
     */
    public function itemTitle(int $secondary_id, string $lang): string
    {
        $lm_id = \ilLMObject::_lookupContObjID($secondary_id);
        if ($lm_id === 0) {
            return "";
        }

        if (\ilLMObject::_lookupType($secondary_id) === "st") {
            return \ilStructureObject::_getPresentationTitle(
                $secondary_id,
                \ilLMObject::CHAPTER_TITLE,
                false,
                false,
                false,
                $lm_id,
                $lang
            );
        }

        return \ilLMPageObject::_getPresentationTitle(
            $secondary_id,
            \ilLMObject::PAGE_TITLE,
            false,
            false,
            false,
            $lm_id,
            $lang
        );
    }

    /**
     * A page's own date lives on its COPage row; "lm_data.last_update" is
     * written only on import-id changes, never on an edit. A chapter
     * secondary_id matches no COPage row and so keeps the object-level date,
     * which is what the LM resolves for it anyway.
     * @param int[] $secondary_ids
     * @param string $lang
     * @return array<int, string>
     */
    public function lastChangesFor(array $secondary_ids, string $lang): array
    {
        return $this->copageLastChanges($this->obj_type, $secondary_ids, $lang);
    }
}
