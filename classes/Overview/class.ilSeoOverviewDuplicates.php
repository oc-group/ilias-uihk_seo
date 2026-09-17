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
 * Whether an SEO row's title or description repeats, verbatim, on another row
 * in the same stored language. Permalink uniqueness is already enforced on
 * save; title and description are not, so two pages can carry an identical SEO
 * title indefinitely with nothing surfacing it. The Overview screen is where an
 * author edits these values, so the check is classified there rather than in
 * the Site Structure graph, which is about the link structure and not about the
 * metadata rows.
 *
 * Scoped per stored language rather than across all of them: a title that
 * happens to read the same in two different languages is not the
 * duplicate-content problem this check is for, and comparing across languages
 * would flag every installation that seeds a fallback title before translating
 * it.
 */
class ilSeoOverviewDuplicates
{
    /** @var string neither the title nor the description repeats elsewhere in the same language */
    public const NONE = "";

    /** @var string only the title repeats */
    public const TITLE = "title";

    /** @var string only the description repeats */
    public const DESCRIPTION = "description";

    /** @var string both the title and the description repeat */
    public const BOTH = "both";

    /** @var ilDBInterface */
    private ilDBInterface $db;

    /** @var array<string,bool>|null memoized "{lang}\0{title}" set, one row set per language */
    private ?array $duplicate_titles = null;

    /** @var array<string,bool>|null memoized "{lang}\0{description}" set, one row set per language */
    private ?array $duplicate_descriptions = null;

    /**
     * @param ilDBInterface $db
     * @return void
     */
    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    /**
     * The lang key labelling a status; NONE renders nothing, so it has none.
     * @param string $status one of self::TITLE/DESCRIPTION/BOTH
     * @return string
     */
    public static function langKey(string $status): string
    {
        return "duplicate_metadata_" . $status;
    }

    /**
     * Classify a batch of SEO rows: which ones share their title or
     * description, verbatim, with another row in the same stored language.
     * Result keys mirror the input keys.
     * @param array<int|string, array<string, mixed>> $rows each needs lang,
     *     title, description
     * @return array<int|string, string> one of
     *     self::NONE/TITLE/DESCRIPTION/BOTH per input key
     */
    public function classify(array $rows): array
    {
        $duplicate_titles = $this->duplicateTitles();
        $duplicate_descriptions = $this->duplicateDescriptions();

        $classified = [];
        foreach ($rows as $key => $row) {
            $lang = (string) ($row["lang"] ?? "");
            $has_title_dup = isset($duplicate_titles[$this->pairKey($lang, (string) ($row["title"] ?? ""))]);
            $has_description_dup = isset($duplicate_descriptions[$this->pairKey($lang, (string) ($row["description"] ?? ""))]);

            $classified[$key] = match (true) {
                $has_title_dup && $has_description_dup => self::BOTH,
                $has_title_dup => self::TITLE,
                $has_description_dup => self::DESCRIPTION,
                default => self::NONE,
            };
        }

        return $classified;
    }

    /**
     * @param string $lang
     * @param string $value
     * @return string
     */
    private function pairKey(string $lang, string $value): string
    {
        return $lang . "\0" . $value;
    }

    /**
     * Every "{lang}\0{title}" pair held by more than one row across the whole
     * table, not just the current page: a duplicate on a page the reader is not
     * looking at is still a duplicate.
     * @return array<string,bool>
     */
    private function duplicateTitles(): array
    {
        if ($this->duplicate_titles !== null) {
            return $this->duplicate_titles;
        }

        $res = $this->db->query(
            "SELECT lang, title FROM " . ilSeoPlugin::TABLE_DATA
            . " WHERE title != ''"
            . " GROUP BY lang, title"
            . " HAVING COUNT(*) > 1"
        );

        $set = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $set[$this->pairKey((string) $row["lang"], (string) $row["title"])] = true;
        }

        return $this->duplicate_titles = $set;
    }

    /**
     * Every "{lang}\0{description}" pair held by more than one row across the
     * whole table.
     * @return array<string,bool>
     */
    private function duplicateDescriptions(): array
    {
        if ($this->duplicate_descriptions !== null) {
            return $this->duplicate_descriptions;
        }

        $res = $this->db->query(
            "SELECT lang, description FROM " . ilSeoPlugin::TABLE_DATA
            . " WHERE description != ''"
            . " GROUP BY lang, description"
            . " HAVING COUNT(*) > 1"
        );

        $set = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $set[$this->pairKey((string) $row["lang"], (string) $row["description"])] = true;
        }

        return $this->duplicate_descriptions = $set;
    }
}
