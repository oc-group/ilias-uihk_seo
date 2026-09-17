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
 * Resolves an incoming permalink to its stored SEO row, and falls back to
 * permalink history when the row moved (the pair go.php calls in sequence).
 * Depends on ilDB only; split out of ilSeo so that class stays under the PHPMD
 * TooManyPublicMethods ceiling.
 */
class ilSeoPermalinkResolver
{
    /** @var ilDBInterface */
    private ilDBInterface $db;

    /**
     * @param ilDBInterface $db
     */
    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Fetch object web metadata from a permalink.
     * @param string $permalink
     * @return array<string, mixed>|null
     */
    public function fetchByPermalink(string $permalink): ?array
    {
        $res = $this->db->queryF(
            "SELECT * FROM " . ilSeoPlugin::TABLE_DATA . " WHERE permalink = %s;",
            ["text"],
            [$permalink]
        );
        return $this->db->fetchAssoc($res) ?? null;
    }

    /**
     * Fetch object new metadata from old permalink.
     * @param string $permalink
     * @return string|null
     */
    public function getPermalinkFromHistory(string $permalink): ?string
    {
        $res = $this->db->queryF(
            "SELECT d.permalink
            FROM " . ilSeoPlugin::TABLE_HISTORY . " h
            INNER JOIN " . ilSeoPlugin::TABLE_DATA . " d
            ON h.ref_id = d.ref_id
            AND h.secondary_id = d.secondary_id
            AND h.lang = d.lang
            WHERE h.permalink = %s
            ORDER BY h.timestamp DESC
            LIMIT 1;",
            ["text"],
            [$permalink]
        );

        $row = $this->db->fetchAssoc($res);
        return $row ? $row["permalink"] : null;
    }
}
