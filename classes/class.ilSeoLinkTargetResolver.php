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
     * Resolve a href to a SEO target, trying the goto scheme first, then an
     * in-page ilCtrl link.
     * @param string $href
     * @return array{type: string, ref_id: int, secondary_id: int}|null
     */
    public static function resolveTarget(string $href): ?array
    {
        return self::resolveGotoTarget($href) ?? self::resolveSecondaryIdTarget($href);
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
     * @param array{Target: string, Type: string, TargetFrame?: string, Anchor?:
     *     string} $int_link
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
    private static function isSameSiteHref(string $href): bool
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
