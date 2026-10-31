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

use ilSeo\ObjectHandlers\HandlerFactory;

/**
 * Seo class.
 */
class ilSeo
{
    /** @var array<string,string> obj_type => id_key */
    public const SECONDARY_ID_KEYS = [
        "blog" => "blpg",
        "dcl" => "record_id",
        "frm" => "thr_pk",
        "lm" => "obj_id",
        "wiki" => "wpg_id",
    ];

    /** @var ilDBInterface */
    protected ilDBInterface $db;

    /** @var ilLanguage */
    protected ilLanguage $lng;

    /** @var ilSeoPlugin */
    protected ilSeoPlugin $plugin;

    /** @var array<string, array<string, mixed>|null> cache of web data */
    protected static array $pages = [];

    /**
     * Seo constructor.
     */
    public function __construct()
    {
        global $DIC;

        $this->db = $DIC->database();
        $this->lng = $DIC->language();

        $this->plugin = ilSeoPlugin::getInstance();
    }

    /**
     * Check if the page is intended for web view. A permalink request always
     * is, because go.php dispatched it straight to the object type's public
     * content view. Every other request falls back to the screen-id rule below.
     * @return bool
     */
    public static function isPageIntendedForWeb(): bool
    {
        if (defined("IL_SEO_PERMALINK_REQUEST")) {
            return true;
        }

        return self::isContentScreenRequest();
    }

    /**
     * Whether the current, non-permalink request renders public content. The
     * per-object-type content-screen rule belongs to that type's handler; the
     * type is resolved from "ref_id" here because no caller has it resolved at
     * its own call site.
     * @return bool
     */
    private static function isContentScreenRequest(): bool
    {
        global $DIC;

        $screen_id = $DIC->help()->getScreenId();
        $handler = HandlerFactory::forType(self::resolveObjTypeFromRequest());

        if ($handler->isContentScreen($screen_id)) {
            return true;
        }

        $logger = ilLoggerFactory::getLogger(ilSeoPlugin::PLUGIN_ID);
        $logger->debug("Screen id not intended for web: '{$screen_id}'");
        return false;
    }

    /**
     * Resolve the current request's object type from "ref_id", if present.
     * @return string obj_type, or "" when "ref_id" is absent (e.g. imprint,
     *     login/logout)
     */
    private static function resolveObjTypeFromRequest(): string
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has("ref_id")) {
            return "";
        }

        $ref_id = $query->retrieve("ref_id", $DIC->refinery()->kindlyTo()->int());
        return (string) ilObject::_lookupType($ref_id, true);
    }

    /**
     * Check if the given object can be accessed by anonymous user.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param int $ref_id
     * @return bool
     */
    public function hasPublicAccess(int $ref_id): bool
    {
        global $DIC;

        $access = $DIC->access();

        return $access->checkAccessOfUser(ANONYMOUS_USER_ID, "read", "", $ref_id);
    }

    /**
     * Get the secondary key and value, if known for the given object type.
     * @param string $type of the object
     * @return ilSeoSecondaryComponent|null secondary key and id
     */
    public function getSecondaryComponentFromRequest(string $type): ?ilSeoSecondaryComponent
    {
        global $DIC;

        $key = self::SECONDARY_ID_KEYS[$type] ?? null;
        if ($key == null) {
            return null;
        }

        $query = $DIC->http()->wrapper()->query();

        if ($query->has($key)) {
            return new ilSeoSecondaryComponent(
                $key,
                $query->retrieve($key, $DIC->refinery()->kindlyTo()->int())
            );
        }

        if ($type === "wiki") {
            // "wpg_id" alone is not enough: ILIAS also addresses a wiki page by
            // "page" (title), or by neither param at all (start page).
            $resolved = $this->getWikiSecondaryComponentFromPageParam()
                ?? $this->getWikiSecondaryComponentFromStartPage();
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Resolve the wiki secondary_id (wpg_id) from the "page" title GET param.
     * @return ilSeoSecondaryComponent|null
     */
    private function getWikiSecondaryComponentFromPageParam(): ?ilSeoSecondaryComponent
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has("page") || !$query->has("ref_id")) {
            return null;
        }

        $refinery = $DIC->refinery();
        $ref_id = $query->retrieve("ref_id", $refinery->kindlyTo()->int());
        $page_title = $query->retrieve("page", $refinery->kindlyTo()->string());

        if ($page_title === "") {
            return null;
        }

        $wiki_id = ilObject::_lookupObjId($ref_id);
        $wpg_id = ilWikiPage::getPageIdForTitle($wiki_id, ilWikiUtil::makeDbTitle($page_title));

        return $wpg_id !== null
            ? new ilSeoSecondaryComponent(self::SECONDARY_ID_KEYS["wiki"], $wpg_id)
            : null;
    }

    /**
     * Resolve the wiki secondary_id (wpg_id) when neither "wpg_id" nor "page"
     * is present, mirroring NavigationManager's own start-page fallback; gated
     * on cmdClass/cmd.
     * @return ilSeoSecondaryComponent|null
     */
    private function getWikiSecondaryComponentFromStartPage(): ?ilSeoSecondaryComponent
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has("ref_id") || $query->has("wpg_id") || $query->has("page")) {
            return null;
        }

        $refinery = $DIC->refinery();
        $cmd_class = $query->has("cmdClass")
            ? strtolower($query->retrieve("cmdClass", $refinery->kindlyTo()->string()))
            : "";
        $cmd = $query->has("cmd") ? $query->retrieve("cmd", $refinery->kindlyTo()->string()) : "";

        $is_wiki_page_view = ($cmd_class === "ilobjwikigui" && $cmd === "viewPage")
            || ($cmd_class === "ilwikipagegui" && $cmd === "viewPage");
        if (!$is_wiki_page_view) {
            return null;
        }

        $ref_id = $query->retrieve("ref_id", $refinery->kindlyTo()->int());
        $wiki_id = ilObject::_lookupObjId($ref_id);
        $wpg_id = ilWikiPage::getPageIdForTitle($wiki_id, ilObjWiki::_lookupStartPage($wiki_id));

        return $wpg_id !== null
            ? new ilSeoSecondaryComponent(self::SECONDARY_ID_KEYS["wiki"], $wpg_id)
            : null;
    }

    /**
     * Cache key of one data row: the composite primary key of the data table.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang raw DB value, "-" for the default-language row
     * @return string
     */
    private static function cacheKey(int $ref_id, int $secondary_id, string $lang): string
    {
        return "{$ref_id}.{$secondary_id}.{$lang}";
    }

    /**
     * Drop one row from the fetchById() cache. Every method that writes or
     * deletes that row calls this, because nothing else invalidates the cache
     * for the rest of the request.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return void
     */
    private static function forgetCached(int $ref_id, int $secondary_id, string $lang): void
    {
        unset(self::$pages[self::cacheKey($ref_id, $secondary_id, $lang)]);
    }

    /**
     * Fetch object permalink.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return array<string, mixed>|null
     */
    public function fetchById(int $ref_id, int $secondary_id = 0, string $lang = "-"): ?array
    {
        $key = self::cacheKey($ref_id, $secondary_id, $lang);

        // null is a cached value meaning "no row for this key": the hit test is
        // array_key_exists(), since isset() would re-query every such key.
        if (array_key_exists($key, self::$pages)) {
            return self::$pages[$key];
        }

        $res = $this->db->queryF(
            "SELECT * FROM " . ilSeoPlugin::TABLE_DATA . " WHERE ref_id = %s AND secondary_id = %s AND lang = %s;",
            ["integer", "integer", "text"],
            [$ref_id, $secondary_id, $lang]
        );

        $data = $this->db->fetchAssoc($res) ?? null;

        self::$pages[$key] = $data;
        return $data;
    }

    /**
     * Fetch all object permalinks and save to cache.
     * @return void
     */
    public function fetchAll(): void
    {
        // Primes existing rows only; an absent key still costs one query on its
        // first fetchById() before the null gets cached.
        $res = $this->db->query("SELECT * FROM " . ilSeoPlugin::TABLE_DATA);
        while ($data = $this->db->fetchAssoc($res)) {
            $key = self::cacheKey((int) $data["ref_id"], (int) $data["secondary_id"], (string) $data["lang"]);
            self::$pages[$key] = $data;
        }
    }

    /**
     * Every row in the data table, every language, every type: unfiltered by
     * crawl-relevance. Unlike fetchCrawlableKeys(), which derives only a
     * ref_id.secondary_id => true set for two declared type partitions and
     * carries no other column, this is the bulk projection a caller needs when
     * it must reason about every language row of a page, or about a type that
     * is never itself crawlable but still appears as a link-target endpoint.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @return list<array{ref_id:int, secondary_id:int, lang:string,
     *     type:string, title:string, permalink:string, robots:string}>
     */
    public function fetchAllRows(): array
    {
        $rows = [];

        $res = $this->db->query(
            "SELECT ref_id, secondary_id, lang, type, title, permalink, robots FROM " . ilSeoPlugin::TABLE_DATA
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $rows[] = [
                "ref_id" => (int) $row["ref_id"],
                "secondary_id" => (int) $row["secondary_id"],
                "lang" => (string) $row["lang"],
                "type" => (string) $row["type"],
                "title" => (string) $row["title"],
                "permalink" => (string) $row["permalink"],
                "robots" => (string) $row["robots"],
            ];
        }

        return $rows;
    }

    /**
     * Fetch every language this page has its own SEO data row for, mapping the
     * "-" default-language sentinel to its real code. Deliberately unfiltered:
     * a language since deactivated in "seo_langs" is still reported, so a
     * caller that needs only the languages offerable to a visitor filters the
     * result itself.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param int $ref_id
     * @param int $secondary_id
     * @return string[]
     */
    public function fetchLangsFor(int $ref_id, int $secondary_id = 0): array
    {
        $res = $this->db->queryF(
            "SELECT DISTINCT lang FROM " . ilSeoPlugin::TABLE_DATA . " WHERE ref_id = %s AND secondary_id = %s;",
            ["integer", "integer"],
            [$ref_id, $secondary_id]
        );

        $langs = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $langs[] = ($row["lang"] === "-") ? ilSeoLanguage::getDefaultLang() : $row["lang"];
        }

        return $langs;
    }

    /**
     * Count configured pages per language (raw DB "lang" column, "-" =
     * default).
     * @return array<string,int> lang => page count
     */
    public function countByLanguage(): array
    {
        $counts = [];

        $res = $this->db->query(
            "SELECT lang, COUNT(*) AS cnt FROM " . ilSeoPlugin::TABLE_DATA . " GROUP BY lang"
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $counts[$row["lang"]] = (int) $row["cnt"];
        }

        return $counts;
    }

    /**
     * Count distinct pages (ref_id, secondary_id) across all languages:
     * the denominator for per-language translation percentages.
     * @return int
     */
    public function countDistinctPages(): int
    {
        $res = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM (
                SELECT DISTINCT ref_id, secondary_id FROM " . ilSeoPlugin::TABLE_DATA . "
            ) t"
        );
        $row = $this->db->fetchAssoc($res);

        return (int) ($row["cnt"] ?? 0);
    }

    /**
     * Every (ref_id, secondary_id) pair with a row in the data table whose
     * "type" falls in one of the two allowed sets, keyed
     * "<ref_id>.<secondary_id>" for a direct lookup against a caller's own node
     * keys. The two sets are taken as given, not derived here: the caller (a
     * companion plugin's own crawl-scope partition today) decides what
     * "object-level" and "sub-page" mean for its scope, so this needs no raw
     * SELECT DISTINCT against this table from outside the plugin; the schema
     * stays this class's own to evolve.
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param string[] $object_level_types type values matched only at
     *     secondary_id = 0
     * @param string[] $sub_page_types type values matched only at secondary_id
     *     <> 0
     * @return array<string,bool> "<ref_id>.<secondary_id>" => true
     */
    public function fetchCrawlableKeys(array $object_level_types, array $sub_page_types): array
    {
        $res = $this->db->query(
            "SELECT DISTINCT ref_id, secondary_id FROM " . ilSeoPlugin::TABLE_DATA . "
            WHERE (" . $this->db->in("type", $object_level_types, false, "text") . " AND secondary_id = 0)
            OR (" . $this->db->in("type", $sub_page_types, false, "text") . " AND secondary_id <> 0)"
        );

        $keys = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $keys[(int) $row["ref_id"] . "." . (int) $row["secondary_id"]] = true;
        }

        return $keys;
    }

    /**
     * The per-secondary-id last-change dates
     * HandlerFactory::forType($type)->lastChangesFor() reports, without
     * requiring the caller to import HandlerFactory directly; it lives in this
     * plugin's own namespaced corner and is not itself part of the public
     * surface. A type with no per-secondary-id date (every type outside
     * SECONDARY_ID_KEYS) reports an empty array, and a secondary_id not covered
     * is simply absent from the result, never present with a null or empty
     * value.
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * @param string $type the SEO row's own "type" column value
     * @param int[] $secondary_ids
     * @param string $lang DB lang value, "-" for the default-language sentinel
     * @return array<int, string> secondary_id => last-changed date in the DB's
     *     own datetime format, only for the secondary_ids the handler actually
     *     reports
     */
    public function lastChangeForType(string $type, array $secondary_ids, string $lang): array
    {
        return HandlerFactory::forType($type)->lastChangesFor($secondary_ids, $lang);
    }

    /**
     * Whether a permalink, empty or not, is already claimed by a different row.
     * Split out of save() so a caller can test uniqueness before writing
     * anything: the homepage confirmation flow needs the answer up front, to
     * decide between the duplicate-slug error and the confirmation screen,
     * before there is anything to save at all. save() itself calls this same
     * check right before it writes, so the two can never disagree.
     * @param string $permalink
     * @param int $ref_id the row that would claim it; excluded from the
     *     collision check
     * @param int $secondary_id
     * @return bool
     */
    public function isPermalinkTaken(string $permalink, int $ref_id, int $secondary_id): bool
    {
        $res = $this->db->queryF(
            "SELECT * FROM " . ilSeoPlugin::TABLE_DATA . "
            WHERE permalink = %s
            AND NOT (ref_id = %s AND secondary_id = %s)",
            ["text", "integer", "integer"],
            [$permalink, $ref_id, $secondary_id]
        );

        return $this->db->fetchAssoc($res) !== null;
    }

    /**
     * Save object web metadata. $is_homepage is the explicit toggle, not a
     * synonym for permalink === "": it is what tells this gate an empty string
     * was deliberately chosen rather than merely left blank.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @param string $permalink
     * @param string $title
     * @param string $description
     * @param string $robots index, follow
     * @param int $priority
     * @param string $frequency
     * @param bool $is_homepage whether this row is the one page marked as the
     *     site's homepage
     * @return bool success
     */
    public function save(
        int $ref_id,
        int $secondary_id,
        string $lang,
        string $permalink,
        string $title,
        string $description,
        string $robots,
        int $priority,
        string $frequency,
        bool $is_homepage = false
    ): bool {
        if (!ilSeoPermalink::isValid($permalink, $is_homepage)) {
            return false;
        }

        try {
            if ($this->isPermalinkTaken($permalink, $ref_id, $secondary_id)) {
                // Collides with another object; lang is excluded so a stale row
                // in a different lang is not misread as a self-match.
                return false;
            }

            $this->db->replace(
                ilSeoPlugin::TABLE_DATA,
                [
                    "ref_id" => ["integer", $ref_id],
                    "secondary_id" => ["integer", $secondary_id],
                    "lang" => ["text", $lang],
                ],
                [
                    "permalink" => ["text", $permalink],
                    "title" => ["text", $title],
                    "description" => ["text", $description],
                    "robots" => ["text", $robots],
                    "priority" => ["integer", $priority],
                    "frequency" => ["text", $frequency],
                    "type" => ["text", ilObject::_lookupType($ref_id, true)],
                ]
            );

            self::forgetCached($ref_id, $secondary_id, $lang);

            // "" is the homepage marker, not an address to 301-redirect from:
            // never chained into history, so nothing stale is left.
            if ($permalink !== "") {
                $this->db->insert(
                    ilSeoPlugin::TABLE_HISTORY,
                    [
                        "permalink" => ["text", $permalink],
                        "ref_id" => ["integer", $ref_id],
                        "secondary_id" => ["integer", $secondary_id],
                        "lang" => ["text", $lang],
                        "timestamp" => ["integer", strtotime("now")],
                        "title" => ["text", $title],
                        "description" => ["text", $description],
                    ]
                );
            }

            return true;
        } catch (Exception $e) {
            // Distinguishable from the deliberate "permalink collides" return
            // above only in the log.
            ilLoggerFactory::getLogger(ilSeoPlugin::PLUGIN_ID)->error("ilSeo::save() failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Return an array of seo properties.
     * @param int $ref_id
     * @param int $secondary_value
     * @param string $lang
     * @return array<string,ILIAS\UI\Component\Symbol\Icon\Icon>
     */
    public function properties(int $ref_id, int $secondary_value = 0, string $lang = "-"): array
    {
        global $DIC;

        $factory = $DIC->ui()->factory();

        // Seo metadata enabled.
        $not = $this->fetchById($ref_id, $secondary_value, $lang) != null ? "" : "not_";
        $enabled_icon = $factory->symbol()->icon()->custom(
            ilUtil::getImagePath("standard/icon_{$not}ok.svg"),
            $this->plugin->txt("{$not}enabled")
        );

        // Public access.
        $not = $this->hasPublicAccess($ref_id) ? "" : "not_";
        $public_icon = $factory->symbol()->icon()->custom(
            ilUtil::getImagePath("standard/icon_{$not}ok.svg"),
            $this->plugin->txt("page_{$not}public")
        );

        return [
            $this->plugin->txt("metadata") => $enabled_icon,
            $this->lng->txt("cont_public_access") => $public_icon,
        ];
    }

    /**
     * Update only the crawl directives of one row, leaving permalink, title and
     * description as they stand. A null argument means "leave this column
     * unchanged"; all three null writes nothing.
     *
     * Distinct from save(), which writes the whole row and appends a history
     * row on every call: a caller that cannot reach the permalink leaves no
     * previous permalink to keep redirecting, so that row would be noise. It
     * also needs no duplicate-permalink check for the same reason.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @param string|null $robots
     * @param int|null $priority
     * @param string|null $frequency
     * @return void
     */
    public function updateCrawlDirectives(
        int $ref_id,
        int $secondary_id,
        string $lang,
        ?string $robots,
        ?int $priority,
        ?string $frequency
    ): void {
        $values = [];

        if ($robots !== null) {
            $values["robots"] = ["text", $robots];
        }

        if ($priority !== null) {
            $values["priority"] = ["integer", $priority];
        }

        if ($frequency !== null) {
            $values["frequency"] = ["text", $frequency];
        }

        if ($values === []) {
            return;
        }

        $this->db->update(
            ilSeoPlugin::TABLE_DATA,
            $values,
            [
                "ref_id" => ["integer", $ref_id],
                "secondary_id" => ["integer", $secondary_id],
                "lang" => ["text", $lang],
            ]
        );

        self::forgetCached($ref_id, $secondary_id, $lang);
    }

    /**
     * Delete object web metadata.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return void
     */
    public function delete(
        int $ref_id,
        int $secondary_id = 0,
        string $lang = "-"
    ): void {
        $this->db->manipulateF(
            "DELETE FROM " . ilSeoPlugin::TABLE_DATA . " WHERE ref_id = %s AND secondary_id = %s AND lang = %s;",
            ["integer", "integer", "text"],
            [$ref_id, $secondary_id, $lang]
        );

        self::forgetCached($ref_id, $secondary_id, $lang);
    }

    /**
     * Delete one page's data rows in every language it has, leaving every other
     * page of the same ref_id untouched: the granularity between delete() (one
     * exact composite key) and deleteForRefId() (every page of the object).
     *
     * The languages come from the table itself, never from fetchLangsFor(),
     * which reports the "-" default-language sentinel as the site's real code
     * and so collapses two distinct rows onto one key for a page holding both.
     * History rows stay, as delete() leaves them: the object is still there,
     * and a history row whose data row is gone resolves to nothing anyway.
     *
     * @api Signature is a contract for an outside consumer: do not
     * change or remove it casually.
     * The only write among these contract methods; every other one only reads.
     * @param int $ref_id
     * @param int $secondary_id
     * @return void
     */
    public function deleteForPage(int $ref_id, int $secondary_id): void
    {
        $res = $this->db->queryF(
            "SELECT lang FROM " . ilSeoPlugin::TABLE_DATA . " WHERE ref_id = %s AND secondary_id = %s;",
            ["integer", "integer"],
            [$ref_id, $secondary_id]
        );

        while ($row = $this->db->fetchAssoc($res)) {
            $this->delete($ref_id, $secondary_id, (string) $row["lang"]);
        }
    }

    /**
     * Delete every data/history row for a ref_id (all languages/secondary_ids
     * at once), unlike delete(), which targets one exact composite key. For a
     * ref_id disappearing entirely; see ilSeoPlugin::handleEvent()'s
     * "deleteReference" hook.
     * @param int $ref_id
     * @return void
     */
    public function deleteForRefId(int $ref_id): void
    {
        $res = $this->db->queryF(
            "SELECT secondary_id, lang FROM " . ilSeoPlugin::TABLE_DATA . " WHERE ref_id = %s;",
            ["integer"],
            [$ref_id]
        );

        while ($row = $this->db->fetchAssoc($res)) {
            $this->delete($ref_id, (int) $row["secondary_id"], (string) $row["lang"]);
        }

        $this->db->manipulateF(
            "DELETE FROM " . ilSeoPlugin::TABLE_HISTORY . " WHERE ref_id = %s;",
            ["integer"],
            [$ref_id]
        );
    }
}
