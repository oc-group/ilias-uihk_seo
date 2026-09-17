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
 * The ILIAS imprint/legal-notice page has no ref_id, so it never goes through a
 * repository goto/content-screen dispatch the other handlers exist for. This
 * handler's only job is to hold the reserved permalink that identifies it, for
 * the call sites that need it: go.php's imprint short-circuit, the goto
 * redirect and link-rewrite in ilSeoUIHookGUI, the canonical-URL check in
 * ilSeoLayoutProvider, and the reserved-permalink check in
 * ilSeoPermalink::isReserved(). "impr" is not an invented pseudo-type: it is
 * ILIAS's own native goto-target prefix for the imprint (see
 * ilSeoUIHookGUI::gotoHook() and ilSeoLinkTargetResolver::resolveTarget()).
 */
class ImprintHandler extends AbstractSpecialPageHandler
{
    /** @var string reserved permalink for the ILIAS imprint/legal-notice special case */
    private const PERMALINK = "legal-notice/";

    /**
     * The imprint's reserved permalink.
     * @return string
     */
    public function getPermalink(): string
    {
        return self::PERMALINK;
    }

    /**
     * Detect the imprint via its pseudo-type string, e.g. the goto-target
     * prefix parsed in ilSeoUIHookGUI::gotoHook()/rewritePermalinks() or
     * ilSeoLinkTargetResolver::resolveTarget().
     * @param string $type
     * @return bool
     */
    public function isType(string $type): bool
    {
        return $type === "impr";
    }

    /**
     * Detect the imprint via its GUI baseClass, for call sites
     * (ilSeoLayoutProvider) that only have the requested "baseClass" query
     * parameter to check, not a parsed goto-target.
     * @param string $base_class
     * @return bool
     */
    public function isBaseClass(string $base_class): bool
    {
        return $base_class === \ilImprintGUI::class;
    }

    /**
     * Populate the GET superglobal for the imprint's early, pre-initILIAS
     * short-circuit.
     * @return void
     */
    public function prepareEarlyDispatch(): void
    {
        $_GET["baseClass"] = \ilImprintGUI::class;
        $_GET["cmd"] = "preview";
    }
}
