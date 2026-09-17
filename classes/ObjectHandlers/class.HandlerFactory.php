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
 * Resolves the object-type handler go.php and ilSeo::isPageIntendedForWeb()
 * delegate to.
 */
class HandlerFactory
{
    /** @var class-string<AbstractSpecialPageHandler>[] every ref_id-less "special page" handler */
    private const SPECIAL_PAGES = [
        ImprintHandler::class,
    ];

    /**
     * @param string $obj_type
     * @return AbstractHandler
     */
    public static function forType(string $obj_type): AbstractHandler
    {
        return match ($obj_type) {
            "blog" => new BlogHandler($obj_type),
            "dcl" => new DataCollectionHandler($obj_type),
            "frm" => new ForumHandler($obj_type),
            "impr" => new ImprintHandler($obj_type),
            "lm" => new LearningModuleHandler($obj_type),
            "wiki" => new WikiHandler($obj_type),
            // Reached by every obj_type outside SECONDARY_ID_KEYS (e.g. course,
            // category): AbstractHandler is the generic fallback, not dead code
            default => new AbstractHandler($obj_type),
        };
    }

    /**
     * Resolve a special-page handler by its GUI baseClass, for call sites
     * (ilSeoLayoutProvider) that only have the requested "baseClass" query
     * parameter to check, not a parsed goto-target. Safe to call before
     * ilInitialisation::initILIAS() (no DIC/DB access).
     * @param string $base_class
     * @return AbstractSpecialPageHandler|null
     */
    public static function forBaseClass(string $base_class): ?AbstractSpecialPageHandler
    {
        foreach (self::SPECIAL_PAGES as $class) {
            $handler = new $class("");
            if ($handler->isBaseClass($base_class)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Resolve a special-page handler by its reserved permalink, e.g. go.php's
     * early dispatch before ref_id/permalink resolution. Safe to call before
     * ilInitialisation::initILIAS() (no DIC/DB access).
     * @param string $page
     * @return AbstractSpecialPageHandler|null
     */
    public static function forPermalink(string $page): ?AbstractSpecialPageHandler
    {
        foreach (self::SPECIAL_PAGES as $class) {
            $handler = new $class("");
            if ($handler->getPermalink() === $page) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Every special page's reserved permalink, for
     * ilSeoPermalink::isReserved(). Generalizes to any handler added to
     * SPECIAL_PAGES with no further changes at that call site.
     * @return string[]
     */
    public static function getReservedPermalinks(): array
    {
        $permalinks = [];
        foreach (self::SPECIAL_PAGES as $class) {
            $permalinks[] = (new $class(""))->getPermalink();
        }

        return $permalinks;
    }
}
