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

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;

/**
 * KS DataRetrieval for ilSeoOverviewTableGUI: the filtered query behind the
 * Overview table and the column shape its rows are built from.
 */
class ilSeoOverviewDataRetrieval implements DataRetrieval
{
    /** @var ilDBInterface */
    private ilDBInterface $db;

    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /** @var ilLanguage */
    private ilLanguage $lng;

    /** @var array<string,mixed> lang/robots/frequency, "" meaning "all" */
    private array $filter;

    /**
     * @param ilDBInterface $db
     * @param ilSeoPlugin $plugin
     * @param ilLanguage $lng
     * @param array<string,mixed> $filter
     * @return void
     */
    public function __construct(ilDBInterface $db, ilSeoPlugin $plugin, ilLanguage $lng, array $filter)
    {
        $this->db = $db;
        $this->plugin = $plugin;
        $this->lng = $lng;
        $this->filter = $filter;
    }

    /**
     * @param DataRowBuilder $row_builder
     * @param string[] $visible_column_ids
     * @param Range $range
     * @param Order $order
     * @param ?array<string,mixed> $filter_data
     * @param ?array<string,mixed> $additional_parameters
     * @return \Generator
     */
    public function getRows(
        DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): \Generator {
        [$where, $types, $params] = $this->buildWhere();

        $sql = "SELECT s.ref_id, s.secondary_id, s.lang, s.title, s.description, s.permalink, s.robots, s.priority,"
            . " s.frequency, s.type, d.has_object, d.last_change"
            . " FROM " . ilSeoPlugin::TABLE_DATA . " s"
            . " LEFT JOIN (" . ilSeoSitemapStatus::objectDateSubquery($this->db) . ") d ON d.ref_id = s.ref_id"
            . $where
            . " ORDER BY s.ref_id ASC, s.lang ASC";

        $this->db->setLimit($range->getLength(), $range->getStart());
        $res = $params === []
            ? $this->db->query($sql)
            : $this->db->queryF($sql, $types, $params);

        $rows = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $rows[] = $row;
        }

        // Classifying costs an RBAC check and a reachability lookup per row, so
        // it is skipped entirely while the column is hidden.
        $verdicts = in_array(ilSeoOverviewTableGUI::COL_SITEMAP_STATUS, $visible_column_ids, true)
            ? (new ilSeoSitemapStatus())->classify($rows)
            : [];

        // Duplicate sets span the whole table, not just this page: hiding the
        // column only skips the two GROUP BY queries, not their scan.
        $duplicates = in_array(ilSeoOverviewTableGUI::COL_DUPLICATE, $visible_column_ids, true)
            ? (new ilSeoOverviewDuplicates($this->db))->classify($rows)
            : [];

        foreach ($rows as $index => $row) {
            yield $row_builder->buildDataRow(
                self::encodeRowId((int) $row["ref_id"], (int) ($row["secondary_id"] ?? 0), (string) $row["lang"]),
                $this->toRecord($row, $verdicts[$index]["status"] ?? null, $duplicates[$index] ?? ilSeoOverviewDuplicates::NONE)
            );
        }
    }

    /**
     * Encode a row's full key (ref_id, secondary_id, lang) so mass actions
     * target one exact row instead of every row sharing just the ref_id.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return string
     */
    public static function encodeRowId(int $ref_id, int $secondary_id, string $lang): string
    {
        return "{$ref_id}.{$secondary_id}.{$lang}";
    }

    /**
     * Decode a row key produced by encodeRowId() back into its parts.
     * @param string $id
     * @return array{ref_id: int, secondary_id: int, lang: string}
     */
    public static function decodeRowId(string $id): array
    {
        $parts = explode(".", $id, 3);

        return [
            "ref_id" => (int) ($parts[0] ?? 0),
            "secondary_id" => (int) ($parts[1] ?? 0),
            "lang" => $parts[2] ?? "-",
        ];
    }

    /**
     * @param ?array<string,mixed> $filter_data
     * @param ?array<string,mixed> $additional_parameters
     * @return int
     */
    public function getTotalRowCount(?array $filter_data, ?array $additional_parameters): int
    {
        [$where, $types, $params] = $this->buildWhere();

        $sql = "SELECT COUNT(*) cnt FROM " . ilSeoPlugin::TABLE_DATA . " s" . $where;
        $res = $params === []
            ? $this->db->query($sql)
            : $this->db->queryF($sql, $types, $params);

        $row = $this->db->fetchAssoc($res);
        return (int) ($row["cnt"] ?? 0);
    }

    /**
     * Map one DB row to a display record; Text columns render raw, so
     * user-controlled fields are escaped here.
     * @param array<string,mixed> $row
     * @param ?string $status ilSeoSitemapStatus verdict, null while the column
     *     is hidden
     * @param string $duplicate ilSeoOverviewDuplicates verdict, NONE while the
     *     column is hidden
     * @return array<string,mixed>
     */
    private function toRecord(array $row, ?string $status, string $duplicate): array
    {
        $freq = (string) ($row["frequency"] ?? "");
        // "-" is the DB-only default-language sentinel; resolve to the real
        // code before building "meta_l_*", or txt() finds nothing to match.
        $lang = (string) $row["lang"];
        $lang = $lang === "-" ? ilSeoLanguage::getDefaultLang() : $lang;

        return [
            "title" => ilSeoEscape::html((string) $row["title"]),
            "permalink" => ilSeoEscape::html((string) $row["permalink"]),
            "robots" => $this->plugin->txt(ilSeoRobots::langKey((string) $row["robots"])),
            "priority" => (int) ($row["priority"] ?? 0),
            "frequency" => $freq !== "" ? $this->plugin->txt(ilSeoFrequency::langKey($freq)) : "",
            "lang" => $this->lng->txt("meta_l_" . $lang),
            ilSeoOverviewTableGUI::COL_SITEMAP_STATUS => $status !== null
                ? $this->plugin->txt(ilSeoSitemapStatus::langKey($status))
                : "",
            ilSeoOverviewTableGUI::COL_DUPLICATE => $duplicate !== ilSeoOverviewDuplicates::NONE
                ? $this->plugin->txt(ilSeoOverviewDuplicates::langKey($duplicate))
                : "",
        ];
    }

    /**
     * @return array{0: string, 1: string[], 2: string[]} SQL WHERE clause, bind
     *     types, bind values
     */
    private function buildWhere(): array
    {
        $where = [];
        $types = [];
        $params = [];

        if (!empty($this->filter["lang"])) {
            $selected_lang = (string) $this->filter["lang"];
            // A default-language row may sit under the ISO code or the "-"
            // sentinel; match both only when the selected code is the default.
            $lang_values = $selected_lang === ilSeoLanguage::getDefaultLang()
                ? [$selected_lang, "-"]
                : [$selected_lang];

            $where[] = "s.lang IN (" . implode(", ", array_fill(0, count($lang_values), "%s")) . ")";
            foreach ($lang_values as $lang_value) {
                $types[] = "text";
                $params[] = $lang_value;
            }
        }
        if (!empty($this->filter["robots"])) {
            $where[] = "s.robots = %s";
            $types[] = "text";
            $params[] = $this->filter["robots"];
        }
        if (!empty($this->filter["frequency"])) {
            $where[] = "s.frequency = %s";
            $types[] = "text";
            $params[] = $this->filter["frequency"];
        }

        // Only the SQL-decidable exclusions are offered (SQL_FILTERABLE); each
        // fragment is correlated on "s", so COUNT needs no join either.
        $status = (string) ($this->filter[ilSeoOverviewTableGUI::COL_SITEMAP_STATUS] ?? "");
        if (in_array($status, ilSeoSitemapStatus::SQL_FILTERABLE, true)) {
            $where[] = ilSeoSitemapStatus::sqlExclusion($this->db, $status, "s");
        }

        $sql = $where !== [] ? " WHERE " . implode(" AND ", $where) : "";
        return [$sql, $types, $params];
    }
}
