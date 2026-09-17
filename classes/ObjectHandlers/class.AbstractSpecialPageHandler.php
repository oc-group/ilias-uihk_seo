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
 * Base for object-less "special pages": routes with no ref_id to key a
 * permalink row on (e.g. the imprint; future candidates are ILIAS's own privacy
 * policy and accessibility statement pages). Unlike the 5 real object-type
 * handlers extending AbstractHandler directly, a special-page handler is
 * reached by HandlerFactory::forBaseClass()/forPermalink() as well as
 * forType(), since there is no repository object to resolve a type from.
 */
abstract class AbstractSpecialPageHandler extends AbstractHandler
{
    /**
     * The reserved permalink for this special page. Every subclass must
     * override this.
     * @return string
     */
    public function getPermalink(): string
    {
        throw new \LogicException(static::class . " must override getPermalink().");
    }

    /**
     * Detect this special page via its pseudo-type string (the goto-target
     * prefix parsed in ilSeoUIHookGUI::gotoHook()/rewritePermalinks() or
     * ilSeoLinkTargetResolver::resolveTarget()).
     * @param string $type
     * @return bool
     */
    abstract public function isType(string $type): bool;

    /**
     * Detect this special page via its GUI baseClass, for call sites
     * (ilSeoLayoutProvider) that only have the requested "baseClass" query
     * parameter to check.
     * @param string $base_class
     * @return bool
     */
    abstract public function isBaseClass(string $base_class): bool;

    /**
     * Populate the GET superglobal before ilInitialisation::initILIAS() for
     * this special page's early, pre-initILIAS short-circuit (today: the
     * imprint's own preview). No $secondary_id parameter, unlike
     * AbstractHandler::prepareGotoDispatch(): a special page has none.
     * @return void
     */
    abstract public function prepareEarlyDispatch(): void;

    /**
     * The canonical URL for this special page, in the same shared shape
     * ilSeoLayoutProvider already builds for the imprint today.
     * @return string
     */
    public function getCanonicalUrlOverride(): string
    {
        return ILIAS_HTTP_PATH . "/" . $this->getPermalink();
    }

    /**
     * The goto/link-rewrite redirect target for this special page. It has no
     * ref_id, so "redirect to my own permalink" is the entire behavior,
     * generalized from the imprint's current goto/rewrite handling.
     * @return string
     */
    public function getGotoRedirectTarget(): string
    {
        return $this->getPermalink();
    }
}
