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

use ilSeo\ObjectHandlers\AbstractSpecialPageHandler;
use ilSeo\ObjectHandlers\HandlerFactory;

/**
 * Resolves an anchor href to a SEO-relevant object target. Shared by
 * ilSeoUIHookGUI::rewritePermalinks() (rewrites the anchor) and the graph
 * builder cron job (records the target as an edge instead).
 */
class ilSeoLinkTargetResolver
{
    /** @var array<string,string[]> obj_type => read-only view cmds, so an edit/moderate link is not taken for content */
    private const SECONDARY_ID_VIEW_CMDS = [
        "blog" => ["preview", "previewFullscreen"],
        "dcl" => ["renderRecord"],
        "frm" => ["viewThread"],
        "lm" => ["resume"],
        "wiki" => ["viewPage"],
    ];

    /**
     * obj_type => the one non-empty command a listing row uses to open that
     * type's default view on ILIAS 10 (the "default" entry of
     * ilObj<Type>Access::_getCommands()).
     * The command alone is ambiguous across types: "render" is the default
     * view of a data collection or a category, but the Edit action of a blog,
     * whose public view is "preview". An empty command opens the default view
     * of any type and needs no entry.
     * A type missing here is rewritten only for an empty command.
     * @var array<string,string>
     */
    private const LISTING_VIEW_CMD = [
        "blog" => "preview",
        "cat" => "render",
        "dcl" => "render",
        "fold" => "view",
        "glo" => "view",
        "htlm" => "view",
        "lm" => "view",
        "poll" => "preview",
        "root" => "render",
        "sahs" => "view",
        "wiki" => "view",
    ];

    /**
     * The only query keys a listing-row link may carry. A link with any other
     * key (rtoken, item_ref_id, sort_by, a secondary-id key, ...) carries state
     * or targets something finer than the object's default view, so it is left
     * alone.
     * @var string[]
     */
    private const LISTING_QUERY_KEYS = ["baseclass", "cmdclass", "cmdnode", "ref_id", "cmd"];

    /** @var string[] base classes of a listing row's object view */
    private const LISTING_BASE_CLASSES = ["ilrepositorygui", "ilwikihandlergui"];

    /**
     * Resolve a href to a SEO target, trying the goto scheme first, then an
     * in-page ilCtrl link carrying a secondary-id key, then a plain
     * repository-listing row.
     * @param string $href
     * @return array{type: string, ref_id: int, secondary_id: int}|null
     */
    public static function resolveTarget(string $href): ?array
    {
        return self::resolveGotoTarget($href)
            ?? self::resolveSecondaryIdTarget($href)
            ?? self::resolveListingRowTarget($href);
    }

    /**
     * Resolve a href to the permalink path that replaces it, with a leading
     * slash ("/slug/", "/" for the homepage), or null when the href must stay
     * as it is: it names no SEO-relevant object, the object has no SEO row
     * for the language, or the stored permalink is broken (see
     * ilSeoPermalink::isValid()). Shared by the page-HTML
     * rewrite (ilSeoUIHookGUI) and the footer modification
     * (ilSeoLayoutProvider).
     * @param string $href decoded href
     * @param ilSeo $seo
     * @param string $lang DB language key (ilSeoLanguage::toDbLang())
     * @return string|null
     */
    public static function resolvePermalinkPath(string $href, ilSeo $seo, string $lang): ?string
    {
        $target = self::resolveTarget($href);
        if ($target === null) {
            return null;
        }

        $special_handler = HandlerFactory::forType($target["type"]);
        if ($special_handler instanceof AbstractSpecialPageHandler) {
            // Special page (e.g. imprint) has no ref id.
            return "/" . $special_handler->getGotoRedirectTarget();
        }

        // Normalize to the "-" sentinel for the default-language row; a
        // literal language code would miss it.
        $data = $seo->fetchById($target["ref_id"], $target["secondary_id"], $lang);
        if ($data === null) {
            // Not handled by SEO plugin.
            return null;
        }

        $permalink = (string) $data["permalink"];
        if (!ilSeoPermalink::isValid($permalink, $permalink === "")) {
            // Broken permalink stored for this row: leave the original href
            // untouched.
            return null;
        }

        return "/" . $permalink;
    }

    /**
     * Match a repository-listing row link: a same-site ilias.php link that
     * opens one object's default view and nothing finer. The conditions, all
     * required:
     *  - the path is ilias.php, on this host or relative, with no fragment;
     *  - the base class, when present, is ilrepositorygui or ilwikihandlergui;
     *  - the query holds only baseClass, cmdClass, cmdNode, ref_id and cmd
     *    (so no rtoken, item_ref_id, sort or secondary-id key);
     *  - ref_id is one positive integer;
     *  - cmd is empty, or is the object type's own default-view command in
     *    LISTING_VIEW_CMD (case-insensitive).
     * The secondary id is always 0: the row names the object itself. Whether
     * that object has a permalink is the caller's lookup.
     * @param string $href
     * @return array{type: string, ref_id: int, secondary_id: int}|null
     */
    public static function resolveListingRowTarget(string $href): ?array
    {
        $parts = parse_url($href);
        if (
            $parts === false
            || isset($parts["fragment"])
            || !isset($parts["query"])
            || !self::isSameSiteHref($href)
            || !self::isIliasEntryPointPath((string) ($parts["path"] ?? ""))
        ) {
            return null;
        }

        parse_str($parts["query"], $query);
        foreach ($query as $key => $value) {
            if (!is_string($value) || !in_array(strtolower((string) $key), self::LISTING_QUERY_KEYS, true)) {
                return null;
            }
        }

        $query = array_change_key_case($query);
        if (!isset($query["ref_id"]) || !ctype_digit($query["ref_id"]) || (int) $query["ref_id"] < 1) {
            return null;
        }

        if (isset($query["baseclass"]) && !in_array(strtolower($query["baseclass"]), self::LISTING_BASE_CLASSES, true)) {
            return null;
        }

        $ref_id = (int) $query["ref_id"];
        $type = (string) ilObject::_lookupType($ref_id, true);
        $cmd = strtolower($query["cmd"] ?? "");
        if ($cmd !== "" && $cmd !== (self::LISTING_VIEW_CMD[$type] ?? null)) {
            return null;
        }

        return ["type" => $type, "ref_id" => $ref_id, "secondary_id" => 0];
    }

    /**
     * Whether a URL path is this installation's ilias.php entry point,
     * relative or under the configured base path.
     * @param string $path
     * @return bool
     */
    private static function isIliasEntryPointPath(string $path): bool
    {
        $base_path = defined("ILIAS_HTTP_PATH") ? (string) parse_url((string) ILIAS_HTTP_PATH, PHP_URL_PATH) : "";
        if ($base_path !== "" && str_starts_with($path, $base_path)) {
            $path = substr($path, strlen($base_path));
        }

        return preg_match('#^(?:\./|/)?ilias\.php$#', $path) === 1;
    }

    /**
     * Match the goto.php/go/ static-URL scheme, /go/xyz/123, /goto.php/xyz/123,
     * or /goto.php?target=xyz_123[_456].
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param string $href
     * @return array{type: string, ref_id: int, secondary_id: int}|null
     */
    public static function resolveGotoTarget(string $href): ?array
    {
        if (preg_match("/\/go(?:to\.php)?\/([a-z]+)\/([0-9]+)(?:\/([0-9]+))?/", $href, $matches)) {
            return [
                "type" => $matches[1],
                "ref_id" => (int) $matches[2],
                "secondary_id" => isset($matches[3]) ? (int) $matches[3] : 0,
            ];
        }

        if (preg_match("/goto\.php\?target=([a-z]+)_([0-9]+)(?:_([0-9]+))?/", $href, $matches)) {
            return [
                "type" => $matches[1],
                "ref_id" => (int) $matches[2],
                "secondary_id" => isset($matches[3]) ? (int) $matches[3] : 0,
            ];
        }

        return null;
    }

    /**
     * Match an in-page ilCtrl link carrying "ref_id" plus a
     * ilSeo::SECONDARY_ID_KEYS param, gated by SECONDARY_ID_VIEW_CMDS so an
     * edit/moderate action is never mistaken for the content view.
     * @param string $href
     * @return array{type: string, ref_id: int, secondary_id: int}|null
     */
    public static function resolveSecondaryIdTarget(string $href): ?array
    {
        $query_string = parse_url($href, PHP_URL_QUERY);
        if (!is_string($query_string) || $query_string === "") {
            return null;
        }

        parse_str($query_string, $query);
        if (!isset($query["ref_id"]) || $query["ref_id"] === "") {
            return null;
        }

        $cmd = strtolower((string) ($query["cmd"] ?? ""));

        foreach (ilSeo::SECONDARY_ID_KEYS as $type => $param_name) {
            if (!isset($query[$param_name]) || $query[$param_name] === "") {
                continue;
            }

            // Mirrors ilSeo::SECONDARY_ID_KEYS' keys; a mismatch surfaces as a
            // static-analysis error, not a silent gap.
            $allowed_cmds = array_map("strtolower", self::SECONDARY_ID_VIEW_CMDS[$type]);
            if (!in_array($cmd, $allowed_cmds, true)) {
                // Not a read-only view cmd for this type (e.g.
                // edit/moderate/delete), so never rewrite.
                return null;
            }

            return [
                "type" => $type,
                "ref_id" => (int) $query["ref_id"],
                "secondary_id" => (int) $query[$param_name],
            ];
        }

        return null;
    }

    /**
     * Resolve one getInternalLinks() entry (author-inserted <IntLink>) to a
     * graph edge target. Distinct input shape from resolveTarget() above (a
     * structured array, not an href), kept as its own method rather than folded
     * into the goto-URL parsers.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param array{Target: string, Type: string, TargetFrame?: string,
     *     Anchor?: string} $int_link
     * @return array{ref_id: int, secondary_id: int}|null
     */
    public static function resolveIntLinkTarget(array $int_link): ?array
    {
        $id = self::lastIdSegment((string) ($int_link["Target"] ?? ""));
        if ($id === null) {
            return null;
        }

        return match ($int_link["Type"] ?? "") {
            // Despite the "obj" in Target, the trailing id is already a ref_id,
            // not an obj_id: no _getAllReferences() hop needed.
            "RepositoryItem" => self::firstSeoEnabledRef([$id], 0),
            // The trailing id is the lm page's own obj_id; the container ref_id
            // still needs the lm_id lookup.
            "PageObject" => self::resolveViaContainer(ilLMObject::_lookupContObjID($id), $id),
            // Same shape as PageObject, container resolved via the wiki's own
            // id lookup.
            "WikiPage" => self::resolveViaContainer((int) (ilWikiPage::lookupWikiId($id) ?? 0), $id),
            default => null,
        };
    }

    /**
     * Resolve one <ExtLink Href="..."> (author-inserted external link) to a
     * graph edge target, only when the href is same-site and matches one of
     * this plugin's own stored permalinks. ILIAS core's own ExtLinkMapper only
     * recognizes goto.php/go/ URL shapes, not this plugin's rewritten
     * permalinks, so it cannot be reused here.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param string $href
     * @return array{ref_id: int, secondary_id: int}|null
     */
    public static function resolveExtLinkTarget(string $href): ?array
    {
        global $DIC;

        if (!self::isSameSiteHref($href)) {
            return null;
        }

        $permalink = self::extractPermalinkPath($href);
        if ($permalink === null) {
            return null;
        }

        $data = (new ilSeoPermalinkResolver($DIC->database()))->fetchByPermalink($permalink);
        return $data !== null
            ? ["ref_id" => (int) $data["ref_id"], "secondary_id" => (int) $data["secondary_id"]]
            : null;
    }

    /**
     * The numeric id embedded in an "il_..." Target string is always its last
     * "_"-separated segment, regardless of type (same technique ilPageLinker's
     * own getLinkXML() and LinkManager's copy-mapping use to read it back).
     * @param string $target
     * @return int|null
     */
    private static function lastIdSegment(string $target): ?int
    {
        if (!str_starts_with($target, "il_")) {
            return null;
        }

        // $target already passed str_starts_with() above, so explode() is
        // always non-empty and end() can never be false here.
        $parts = explode("_", $target);
        $last = end($parts);
        return ctype_digit($last) ? (int) $last : null;
    }

    /**
     * Resolve a PageObject/WikiPage Target's container id to a ref_id, then
     * apply the same SEO-enabled tie-break as the RepositoryItem case.
     * @param int $container_obj_id
     * @param int $secondary_id
     * @return array{ref_id: int, secondary_id: int}|null
     */
    private static function resolveViaContainer(int $container_obj_id, int $secondary_id): ?array
    {
        if ($container_obj_id === 0) {
            return null;
        }

        return self::firstSeoEnabledRef(array_values(ilObject::_getAllReferences($container_obj_id)), $secondary_id);
    }

    /**
     * An object can have multiple references; only one worth recording as a
     * graph edge target: the first that is itself SEO-enabled at this
     * secondary_id.
     * @param int[] $ref_ids
     * @param int $secondary_id
     * @return array{ref_id: int, secondary_id: int}|null
     */
    private static function firstSeoEnabledRef(array $ref_ids, int $secondary_id): ?array
    {
        $seo = new ilSeo();
        foreach ($ref_ids as $ref_id) {
            if ($seo->fetchById($ref_id, $secondary_id) !== null) {
                return ["ref_id" => $ref_id, "secondary_id" => $secondary_id];
            }
        }

        return null;
    }

    /**
     * A relative href (no host at all) is same-site by definition; an absolute
     * one must match this installation's own configured host.
     * @param string $href
     * @return bool
     */
    public static function isSameSiteHref(string $href): bool
    {
        $host = parse_url($href, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return true;
        }

        return defined("ILIAS_HTTP_PATH") && $host === parse_url((string) ILIAS_HTTP_PATH, PHP_URL_HOST);
    }

    /**
     * Strip scheme/host and ILIAS_HTTP_PATH's own base path, normalizing to the
     * trailing-slash shape ilSeoPermalinkResolver::fetchByPermalink() expects
     * (mirrors go.php's own "$page" shape, which is always "/"-terminated).
     * @param string $href
     * @return string|null
     */
    private static function extractPermalinkPath(string $href): ?string
    {
        $path = (string) parse_url($href, PHP_URL_PATH);
        if ($path === "") {
            return null;
        }

        $base_path = defined("ILIAS_HTTP_PATH") ? (string) parse_url((string) ILIAS_HTTP_PATH, PHP_URL_PATH) : "";
        if ($base_path !== "" && str_starts_with($path, $base_path)) {
            $path = substr($path, strlen($base_path));
        }

        $path = ltrim($path, "/");
        if ($path === "") {
            // The site root: fetchByPermalink("") is exactly how it finds a
            // homepage row.
            return "";
        }

        return str_ends_with($path, "/") ? $path : $path . "/";
    }
}
