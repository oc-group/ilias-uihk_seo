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
 * Blog posting dispatch: a redirect-free ilCtrl forward into ilBlogPostingGUI,
 * needed because ilRepositoryGUI plus a bare cmd cannot reach that nested class
 * on its own.
 */
class BlogHandler extends AbstractHandler
{
    /**
     * @param int $secondary_id
     * @return void
     */
    public function prepareGotoDispatch(int $secondary_id): void
    {
        $_GET["baseClass"] = \ilRepositoryGUI::class;
        $_GET["cmd"] = "";

        $param = $this->getSecondaryIdParam();
        if ($param !== null && $secondary_id !== 0) {
            $_GET[$param] = $secondary_id;
        }
    }

    /**
     * Two direct parent-child ilCtrl hops (ilRepositoryGUI -> ilObjBlogGUI ->
     * ilBlogPostingGUI) plus setCmd(), so the permalink resolves within the
     * same request/response instead of a real HTTP redirect.
     * @param int $secondary_id
     * @return void
     */
    public function finalizeGotoDispatch(int $secondary_id): void
    {
        global $DIC;

        if ($secondary_id === 0) {
            return;
        }

        $DIC->ctrl()->setCmdClass(\ilObjBlogGUI::class);
        $DIC->ctrl()->setCmdClass(\ilBlogPostingGUI::class);
        $DIC->ctrl()->setCmd("previewFullscreen");
    }

    /**
     * The same destination finalizeGotoDispatch() primes above, spelled as a
     * link.
     * @return array{0: class-string[], 1: string}
     */
    protected function getPageTarget(): ?array
    {
        return [
            [\ilRepositoryGUI::class, \ilObjBlogGUI::class, \ilBlogPostingGUI::class],
            "previewFullscreen",
        ];
    }

    /**
     * A posting permalink only serves the posting when the posting is active:
     * ilObjBlogGUI redirects "previewFullscreen" to the blog overview for an
     * inactive (draft/withdrawn) posting unless the visitor may contribute,
     * which an anonymous crawler never may.
     * @param int $secondary_id
     * @param int $user_id
     * @return bool
     */
    public function isPageReachable(int $secondary_id, int $user_id): bool
    {
        if ($secondary_id === 0) {
            return true;
        }

        return \ilBlogPosting::_lookupActive($secondary_id, "blp");
    }

    /**
     * A posting's own date lives on its COPage row; "il_blog_posting" keeps
     * only "created".
     * @param int[] $secondary_ids
     * @param string $lang
     * @return array<int, string>
     */
    public function lastChangesFor(array $secondary_ids, string $lang): array
    {
        return $this->copageLastChanges("blp", $secondary_ids, $lang);
    }

    /**
     * A posting's title lives on its own "il_blog_posting" row, keyed by the
     * posting id alone: the table has no lang column and Blog has no
     * multilingual mode at all, so the language plays no part. Missing row ->
     * "".
     * @param int $secondary_id
     * @param string $lang
     * @return string
     */
    public function itemTitle(int $secondary_id, string $lang): string
    {
        return \ilBlogPosting::lookupTitle($secondary_id);
    }

    /**
     * ilObjBlogGUI::renderFullScreen() calls ilTabsGUI::clearTargets() right
     * after dispatching previewFullscreen(), resetting the help screen-id
     * component to "" with no fallback, so a posting permalink's screen_id is
     * always "", matching none of the parent's screen-id clauses.
     * ilCtrl::getCmdClass()/getCmd() are untouched by clearTargets() (it only
     * calls $DIC->help()->setScreenIdComponent("")) and stay set to exactly
     * what finalizeGotoDispatch() primed, so they are used here as the fallback
     * content-view signal instead.
     * @return array{0: class-string, 1: string}
     */
    protected function getContentViewSignature(): ?array
    {
        return [\ilBlogPostingGUI::class, "previewFullscreen"];
    }
}
