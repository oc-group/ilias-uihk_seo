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
 * Where the plugin's permalinks meet ILIAS core shortlinks (table
 * il_shortlinks, Administration > Shortlinks).
 *
 * A core shortlink is a one-segment address (/alias) global to the
 * installation. The web server hands every one-segment path to the plugin's
 * go.php first, so a permalink with the same single segment would shadow the
 * shortlink. The rule is that the shortlink wins: go.php delegates an active
 * alias to core before any permalink lookup, and no editing surface accepts a
 * new permalink equal to an alias, active or not. An inactive alias is not
 * delegated (core answers 404 for it), but it stays reserved so that
 * re-activating it later cannot collide.
 *
 * Aliases are compared case-insensitively here, whatever the collation of the
 * column: permalinks are always lowercase, aliases keep the case they were
 * typed in.
 *
 * The same rule applies to outgoing links: a permalink an active shortlink
 * shadows is never pointed at (see isShadowed()), so the object stays reachable
 * at its normal ILIAS address.
 *
 * Depends on ilDB only, so go.php can use it before ILIAS itself is
 * initialised.
 */
class ilSeoShortlinks
{
    /** @var string core's own table of shortlinks */
    public const TABLE = "il_shortlinks";

    /** @var string the characters core allows in an alias */
    private const ALIAS_REGEXP = "/^[a-zA-Z0-9_-]+$/";

    /** @var bool|null whether the table exists, memoized per request */
    private static ?bool $table_exists = null;

    /** @var array<string,true>|null active aliases, lowercase, per request */
    private static ?array $active_aliases = null;

    /** @var ilDBInterface */
    private ilDBInterface $db;

    /**
     * @param ilDBInterface $db
     * @return void
     */
    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * The one segment a path consists of, or null for any other path (empty,
     * nested, or carrying characters no alias may hold). A leading and a
     * trailing slash do not count: "about/" and "/about" are both "about".
     * @param string $path a request path or a stored permalink
     * @return string|null
     */
    public static function singleSegment(string $path): ?string
    {
        $segment = trim($path, "/");

        return preg_match(self::ALIAS_REGEXP, $segment) === 1 ? $segment : null;
    }

    /**
     * The alias of the active shortlink a request segment addresses, as stored,
     * so core receives the exact spelling it looks up.
     * @param string $segment
     * @return string|null null when no active shortlink carries it
     */
    public function findActiveAlias(string $segment): ?string
    {
        return $this->lookup($segment, true);
    }

    /**
     * Whether a permalink collides with a shortlink alias, active or not. Only
     * a single-segment permalink can.
     * @param string $permalink a normalized permalink such as "about/"
     * @return bool
     */
    public function isReserved(string $permalink): bool
    {
        $segment = self::singleSegment($permalink);

        return $segment !== null && $this->lookup($segment, false) !== null;
    }

    /**
     * Whether an active shortlink shadows a permalink: a single-segment
     * permalink equal to the alias of an active shortlink is never reached,
     * because the shortlink answers that address first. The active aliases are
     * read once per request, so testing every link of a page costs no further
     * query.
     * @param string $permalink a stored permalink such as "about/"
     * @return bool
     */
    public function isShadowed(string $permalink): bool
    {
        $segment = self::singleSegment($permalink);

        return $segment !== null && isset($this->activeAliases()[strtolower($segment)]);
    }

    /**
     * Which of the given permalinks collide with a shortlink alias, in one
     * query however many there are.
     * @param string[] $permalinks stored permalinks
     * @return array<string,bool> permalink => whether the colliding shortlink
     *     is active; permalinks without a collision are absent
     */
    public function collisions(array $permalinks): array
    {
        $candidates = [];
        foreach ($permalinks as $permalink) {
            $segment = self::singleSegment($permalink);
            if ($segment !== null) {
                $candidates[$permalink] = strtolower($segment);
            }
        }

        if ($candidates === [] || !$this->tableExists()) {
            return [];
        }

        // The table holds one row per alias an administrator typed in, so it is
        // read whole once instead of once per row.
        $aliases = [];
        $res = $this->db->query("SELECT alias, active FROM " . self::TABLE);
        while ($row = $this->db->fetchAssoc($res)) {
            $key = strtolower((string) $row["alias"]);
            $aliases[$key] = ($aliases[$key] ?? false) || (int) $row["active"] === 1;
        }

        $collisions = [];
        foreach ($candidates as $permalink => $key) {
            if (isset($aliases[$key])) {
                $collisions[$permalink] = $aliases[$key];
            }
        }

        return $collisions;
    }

    /**
     * The shortlink a permalink collides with: its alias as stored and whether
     * it is active.
     * @param string $permalink
     * @return array{alias: string, active: bool}|null null when none collides
     */
    public function describe(string $permalink): ?array
    {
        $segment = self::singleSegment($permalink);
        if ($segment === null || !$this->tableExists()) {
            return null;
        }

        $res = $this->db->queryF(
            "SELECT alias, active FROM " . self::TABLE
            . " WHERE LOWER(alias) = LOWER(%s) ORDER BY active DESC",
            ["text"],
            [$segment]
        );
        $row = $this->db->fetchAssoc($res);

        return $row === null
            ? null
            : ["alias" => (string) $row["alias"], "active" => (int) $row["active"] === 1];
    }

    /**
     * @param string $segment
     * @param bool $active_only
     * @return string|null the stored alias
     */
    private function lookup(string $segment, bool $active_only): ?string
    {
        if (preg_match(self::ALIAS_REGEXP, $segment) !== 1 || !$this->tableExists()) {
            return null;
        }

        $res = $this->db->queryF(
            "SELECT alias FROM " . self::TABLE
            . " WHERE LOWER(alias) = LOWER(%s)" . ($active_only ? " AND active = 1" : "")
            . " ORDER BY active DESC",
            ["text"],
            [$segment]
        );
        $row = $this->db->fetchAssoc($res);

        return $row === null ? null : (string) $row["alias"];
    }

    /**
     * @return array<string,true> lowercase alias => true, empty when the table
     *     is absent
     */
    private function activeAliases(): array
    {
        if (self::$active_aliases !== null) {
            return self::$active_aliases;
        }

        $aliases = [];
        if ($this->tableExists()) {
            $res = $this->db->query("SELECT alias FROM " . self::TABLE . " WHERE active = 1");
            while ($row = $this->db->fetchAssoc($res)) {
                $aliases[strtolower((string) $row["alias"])] = true;
            }
        }

        return self::$active_aliases = $aliases;
    }

    /**
     * Core creates the table with its own update steps; a database that has not
     * run them has no shortlinks to honour.
     * @return bool
     */
    private function tableExists(): bool
    {
        return self::$table_exists ??= $this->db->tableExists(self::TABLE);
    }
}
