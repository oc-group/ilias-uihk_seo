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
 * The single definition of whether an SEO row reaches sitemap.xml and, when it
 * does, where its "lastmod" came from. Both the sitemap builder cron job and
 * the admin Pages table classify through here, so the column an administrator
 * reads can never disagree with the file that is actually written.
 */
class ilSeoSitemapStatus
{
    /** @var string in the sitemap; lastmod is the addressed sub-page's own date */
    public const IN_PAGE_DATE = "in_page_date";

    /** @var string in the sitemap; lastmod is the object-level date */
    public const IN_OBJECT_DATE = "in_object_date";

    /** @var string in the sitemap; no usable date, so lastmod is omitted */
    public const IN_NO_DATE = "in_no_date";

    /** @var string excluded: the row's language is no longer active in "seo_langs" */
    public const OUT_LANG_INACTIVE = "out_lang_inactive";

    /** @var string excluded: the reference is trashed, or its object row is gone */
    public const OUT_NO_OBJECT = "out_no_object";

    /** @var string excluded: the robots value carries "noindex" */
    public const OUT_NOINDEX = "out_noindex";

    /** @var string excluded: not readable by the anonymous user */
    public const OUT_NOT_PUBLIC = "out_not_public";

    /** @var string excluded: the addressed page cannot be opened by a visitor */
    public const OUT_UNREACHABLE = "out_unreachable";

    /** @var string[] the statuses that mean the row is written to sitemap.xml */
    public const PUBLISHED = [self::IN_PAGE_DATE, self::IN_OBJECT_DATE, self::IN_NO_DATE];

    /** @var string[] the exclusion reasons a WHERE clause can decide alone, hence the only ones the Pages tab may filter on */
    public const SQL_FILTERABLE = [
        self::OUT_LANG_INACTIVE,
        self::OUT_NO_OBJECT,
    ];

    /** @var string[] parent_type values whose page_object.parent_id is not an obj_id and must not be aggregated as one */
    private const FOREIGN_PAGE_PARENT_TYPES = [
        "auth", "impr", "lobj", "prtf", "prtt", "qfbg", "qfbs", "qht", "stys",
    ];

    /** @var string[]|null memoized activeDbLangs() */
    private static ?array $active_langs = null;

    /** @var ilSeo */
    private ilSeo $seo;

    /**
     * @param ?ilSeo $seo
     * @return void
     */
    public function __construct(?ilSeo $seo = null)
    {
        $this->seo = $seo ?? new ilSeo();
    }

    /**
     * The object-level date of every live reference, plus a "has_object" marker
     * telling a trashed or object-less reference apart from one whose date is
     * merely NULL. INNER-JOIN it to publish live references only (the sitemap
     * builder), LEFT-JOIN it to keep the dead ones visible and labelled (the
     * Pages table).
     * @param ilDBInterface $db
     * @return string
     */
    public static function objectDateSubquery(ilDBInterface $db): string
    {
        return "SELECT r.ref_id, 1 AS has_object, COALESCE(p.last_change, r.last_update) AS last_change
            FROM (
                SELECT r.ref_id, r.obj_id, o.last_update
                FROM object_reference r LEFT JOIN object_data o
                ON o.obj_id = r.obj_id
                WHERE r.deleted IS NULL
                AND o.obj_id IS NOT NULL
            ) r LEFT JOIN (
                SELECT parent_id, MAX(last_change) AS last_change
                FROM page_object
                WHERE active = 1
                AND " . $db->in("parent_type", self::FOREIGN_PAGE_PARENT_TYPES, true, "text") . "
                GROUP BY parent_id
            ) p
            ON p.parent_id = r.obj_id";
    }

    /**
     * The "lang" values a row may carry and still be published: the "-" default
     * sentinel plus every code currently active in "seo_langs". Rows left
     * behind by a language that was later deactivated are dropped here.
     * @return string[]
     */
    public static function activeDbLangs(): array
    {
        if (self::$active_langs !== null) {
            return self::$active_langs;
        }

        $settings = new ilSeoSettings();
        $default = $settings->getDefaultLang();

        return self::$active_langs = array_values(
            array_unique(array_merge(["-"], $default !== "" ? [$default] : [], $settings->getSecondaryLangs()))
        );
    }

    /**
     * A WHERE fragment selecting exactly the rows one SQL-decidable exclusion
     * reason applies to, correlated on the given alias of the SEO data table.
     * OUT_NOT_PUBLIC and OUT_UNREACHABLE are per-row PHP and cannot be pushed
     * down here at all; OUT_NOINDEX stays out because deciding it in SQL means
     * substring-matching the "robots" token list, the exact reading ilSeoRobots
     * exists to forbid.
     * @param ilDBInterface $db
     * @param string $status one of SQL_FILTERABLE
     * @param string $alias table alias the fragment correlates on
     * @throws InvalidArgumentException if the status is not SQL-decidable
     * @return string
     */
    public static function sqlExclusion(ilDBInterface $db, string $status, string $alias): string
    {
        return match ($status) {
            self::OUT_LANG_INACTIVE => $db->in("{$alias}.lang", self::activeDbLangs(), true, "text"),
            // Negation of objectDateSubquery()'s own inner join: trashed,
            // absent, or no object_data row.
            self::OUT_NO_OBJECT => "NOT EXISTS (
                SELECT 1 FROM object_reference r INNER JOIN object_data o ON o.obj_id = r.obj_id
                WHERE r.ref_id = {$alias}.ref_id AND r.deleted IS NULL
            )",
            default => throw new InvalidArgumentException("SEO: status '{$status}' is not SQL-decidable"),
        };
    }

    /**
     * Whether a status means the row is written to sitemap.xml.
     * @param string $status
     * @return bool
     */
    public static function isInSitemap(string $status): bool
    {
        return in_array($status, self::PUBLISHED, true);
    }

    /**
     * Whether a row clears every gate decidable from its own SEO columns: a
     * language still active in "seo_langs" and no "noindex" token. Public
     * access and page reachability are properties of the reference, not of the
     * row, and are decided once per page by each caller.
     * @param array<string, mixed> $row
     * @return bool
     */
    public static function isPublishableRow(array $row): bool
    {
        return self::rowExclusion($row) === null;
    }

    /**
     * Whether a stored date can be published as "lastmod" at all. A zero date
     * reaches DateTime without throwing and formats as year -1, so it is
     * rejected here rather than shipped.
     * @param string $last_change
     * @return bool
     */
    public static function hasUsableDate(string $last_change): bool
    {
        return strtotime($last_change) > 0;
    }

    /**
     * The lang key labelling a status.
     * @param string $status
     * @return string
     */
    public static function langKey(string $status): string
    {
        return "sitemap_status_" . $status;
    }

    /**
     * Classify a batch of SEO rows: one page of the admin table, or the
     * builder's whole candidate set. Every row needs ref_id, secondary_id,
     * lang, type, permalink and robots, plus has_object and last_change from
     * objectDateSubquery(). Result keys mirror the input keys.
     * @param array<int|string, array<string, mixed>> $rows
     * @return array<int|string, array{status: string, last_change: string}>
     */
    public function classify(array $rows): array
    {
        $own_dates = $this->ownLastChanges($rows);
        $classified = [];

        foreach ($rows as $key => $row) {
            $own_date = $own_dates[$key] ?? null;
            $last_change = (string) ($own_date ?? $row["last_change"] ?? "");

            $classified[$key] = [
                "status" => $this->statusOf($row, $own_date !== null, $last_change),
                "last_change" => $last_change,
            ];
        }

        return $classified;
    }

    /**
     * The verdict for one row: the SQL-decidable exclusions first, then the
     * per-row PHP ones, then the provenance of whatever date survived.
     * @param array<string, mixed> $row
     * @param bool $has_own_date whether a handler supplied the addressed
     *     sub-page's own date
     * @param string $last_change the date that would be published
     * @return string
     */
    private function statusOf(array $row, bool $has_own_date, string $last_change): string
    {
        // A trashed or object-less reference outranks the row's own columns;
        // only this classification path has the joined marker to decide it.
        if ((int) ($row["has_object"] ?? 0) !== 1) {
            return self::OUT_NO_OBJECT;
        }

        $exclusion = self::rowExclusion($row);
        if ($exclusion !== null) {
            return $exclusion;
        }

        if (!$this->seo->hasPublicAccess((int) $row["ref_id"])) {
            return self::OUT_NOT_PUBLIC;
        }

        // Object-level "read" is not page reachability: a permalink can address
        // a sub-page no visitor can open, e.g. an inactive blog post.
        $reachable = HandlerFactory::forType((string) ($row["type"] ?? ""))
            ->isPageReachable((int) $row["secondary_id"], ANONYMOUS_USER_ID);
        if (!$reachable) {
            return self::OUT_UNREACHABLE;
        }

        if (!self::hasUsableDate($last_change)) {
            return self::IN_NO_DATE;
        }

        return $has_own_date ? self::IN_PAGE_DATE : self::IN_OBJECT_DATE;
    }

    /**
     * The exclusion reason decidable from the row's own SEO columns, or null
     * when none applies. An empty permalink is no longer excluded here: it is
     * the homepage row, published like any other, since addUrls() already emits
     * "/" . "" as the correct "/" href.
     * @param array<string, mixed> $row
     * @return string|null
     */
    private static function rowExclusion(array $row): ?string
    {
        if (!in_array((string) ($row["lang"] ?? ""), self::activeDbLangs(), true)) {
            return self::OUT_LANG_INACTIVE;
        }

        if (ilSeoRobots::hasToken((string) ($row["robots"] ?? ""), "noindex")) {
            return self::OUT_NOINDEX;
        }

        return null;
    }

    /**
     * The addressed sub-page's own date for each row that has one, keyed like
     * $rows. Rows whose type keeps no date per sub-page are absent, and fall
     * back to the object-level date.
     * @param array<int|string, array<string, mixed>> $rows
     * @return array<int|string, string>
     */
    private function ownLastChanges(array $rows): array
    {
        $groups = [];
        foreach ($rows as $key => $row) {
            if ((int) $row["secondary_id"] !== 0) {
                $groups[(string) ($row["type"] ?? "")][(string) $row["lang"]][$key] = (int) $row["secondary_id"];
            }
        }

        $dates = [];
        foreach ($groups as $type => $by_lang) {
            $handler = HandlerFactory::forType((string) $type);

            foreach ($by_lang as $lang => $secondary_ids) {
                $resolved = $handler->lastChangesFor(array_values(array_unique($secondary_ids)), (string) $lang);

                foreach ($secondary_ids as $key => $secondary_id) {
                    if (isset($resolved[$secondary_id])) {
                        $dates[$key] = $resolved[$secondary_id];
                    }
                }
            }
        }

        return $dates;
    }
}
