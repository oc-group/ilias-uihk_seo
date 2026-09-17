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

use ILIAS\Cron\Schedule\CronJobScheduleType;

/**
 * Cronjob that builds the sitemap in background.
 */
class ilSeoSitemapBuilderCronJob extends ilCronJob
{
    /** @var string */
    public const CRON_JOB_ID = ilSeoPlugin::PLUGIN_ID . "_sitemap_builder";

    /** @var string */
    public const SITEMAP_FILE_NAME = "sitemap.xml";

    /** @var string */
    public const SITEMAP_INDEX_FILE_NAME = "sitemap-index.xml";

    /** @var string */
    public const ROBOTS_FILE_NAME = "robots.txt";

    /** @var string */
    private const PING_UNCONFIRMED_NOTE = "The search engine ping was not confirmed; the sitemap itself is published. Check logs for details.";

    /** @var ilSeoPlugin */
    protected ilSeoPlugin $plugin;

    /** @var ilDBInterface */
    protected ilDBInterface $db;

    /** @var ilSeo */
    protected ilSeo $seo;

    /**
     * @param ilSeoPlugin $plugin
     */
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Cron Job id.
     * @return string
     */
    public function getId(): string
    {
        return self::CRON_JOB_ID;
    }

    /**
     * Cron Job title.
     * @return string
     */
    public function getTitle(): string
    {
        return $this->plugin->txt("sitemap_builder");
    }

    /**
     * Cron Job description.
     * @return string
     */
    public function getDescription(): string
    {
        return $this->plugin->txt("sitemap_builder_info");
    }

    /**
     * Cron Job schedule type.
     * @return CronJobScheduleType
     */
    public function getDefaultScheduleType(): CronJobScheduleType
    {
        return CronJobScheduleType::SCHEDULE_TYPE_IN_DAYS;
    }

    /**
     * Cron Job schedule value.
     * @return int
     */
    public function getDefaultScheduleValue(): ?int
    {
        return 1;
    }

    /**
     * Cron Job has auto activation.
     * @return bool
     */
    public function hasAutoActivation(): bool
    {
        return false;
    }

    /**
     * Cron Job has flexible schedule.
     * @return bool
     */
    public function hasFlexibleSchedule(): bool
    {
        return true;
    }

    /**
     * Cron Job has not custom settings.
     * @return bool
     */
    public function hasCustomSettings(): bool
    {
        return false;
    }

    /**
     * Allow manual execution.
     * @return bool
     */
    public function isManuallyExecutable(): bool
    {
        return true;
    }

    /**
     * Run the cron job
     * @return ilCronJobResult
     */
    public function run(): ilCronJobResult
    {
        global $DIC;

        $this->db = $DIC->database();
        $this->seo = new ilSeo();
        $settings = new ilSeoSettings();

        $pages = $this->fetchPublishablePages();
        $this->logRunSummary($pages);

        if ($pages === []) {
            return $this->deleteSitemap();
        }

        try {
            $generator = $this->createGenerator();
            $this->addUrls($generator, $pages);
            $generator->flush();
            $generator->finalize();
            $generator->updateRobots();
        } catch (Throwable $e) {
            $this->plugin->logger->error("Sitemap generation failed: " . $e->getMessage());
            return $this->result(ilCronJobResult::STATUS_FAIL, "Cannot create the sitemap. Check logs for details.");
        }

        $pages_count = count($pages);
        if (!$settings->isSubmitSitemap()) {
            return $this->result(ilCronJobResult::STATUS_OK, "Sitemap successfully generated with {$pages_count} urls.");
        }

        return $this->submitSitemap($generator, $pages_count);
    }

    /**
     * Every SEO row that may be published, in permalink order, each carrying
     * the resolved "last_change" and the ilSeoSitemapStatus verdict that
     * admitted it. Both the <loc> entries and their hreflang alternates are
     * built from this one set. The query enforces only the SQL-decidable gates;
     * every other rule belongs to ilSeoSitemapStatus.
     * @return array<int, array<string, mixed>>
     */
    protected function fetchPublishablePages(): array
    {
        $res = $this->db->query(
            "SELECT s.ref_id, s.secondary_id, s.lang, s.type, s.permalink, s.robots, s.priority, s.frequency,
                d.has_object, d.last_change
            FROM " . ilSeoPlugin::TABLE_DATA . " s
            INNER JOIN (" . ilSeoSitemapStatus::objectDateSubquery($this->db) . ") d ON d.ref_id = s.ref_id
            WHERE " . $this->db->in("s.lang", ilSeoSitemapStatus::activeDbLangs(), false, "text") . "
            ORDER BY s.permalink ASC;"
        );

        $candidates = [];
        while ($page = $this->db->fetchAssoc($res)) {
            $candidates[] = $page;
        }

        $pages = [];
        foreach ((new ilSeoSitemapStatus($this->seo))->classify($candidates) as $index => $verdict) {
            if (!ilSeoSitemapStatus::isInSitemap($verdict["status"])) {
                continue;
            }

            $pages[] = array_merge($candidates[$index], $verdict);
        }

        return $pages;
    }

    /**
     * One line per run recording what the sitemap holds and what it left out;
     * the skipped count is the only record of the rows the query above never
     * returned at all.
     * @param array<int, array<string, mixed>> $pages
     * @return void
     */
    protected function logRunSummary(array $pages): void
    {
        $written = count($pages);

        $without_lastmod = count(array_filter(
            $pages,
            fn (array $page): bool => $page["status"] === ilSeoSitemapStatus::IN_NO_DATE
        ));

        $row = $this->db->fetchAssoc(
            $this->db->query("SELECT COUNT(*) AS cnt FROM " . ilSeoPlugin::TABLE_DATA)
        );
        $skipped = max(0, (int) ($row["cnt"] ?? 0) - $written);

        $this->plugin->logger->info(
            "Sitemap run: {$written} urls, {$without_lastmod} without lastmod, {$skipped} rows skipped as unpublishable."
        );
    }

    /**
     * Absolute filesystem path for the generated sitemap/robots files. Not
     * under public/: the ILIAS 10 build step that runs on every composer du
     * wipes that directory each time, so anything placed there directly is
     * lost. This directory persists across that rebuild.
     * @return string
     */
    protected static function storageDir(): string
    {
        return ilSeoPlugin::PLUGIN_FS_DIR . "/assets";
    }

    /**
     * Maps a go.php "page" request to the absolute path of the matching
     * generated static file, or null if the page names something else (a normal
     * permalink lookup, in that case).
     * @param string $page
     * @return string|null
     */
    public static function resolveStaticFile(string $page): ?string
    {
        return match ($page) {
            self::SITEMAP_FILE_NAME, self::SITEMAP_INDEX_FILE_NAME, self::ROBOTS_FILE_NAME =>
                self::storageDir() . "/" . $page,
            default => null,
        };
    }

    /**
     * @throws InvalidArgumentException if the save directory is not writable
     * @return \Icamys\SitemapGenerator\SitemapGenerator
     */
    protected function createGenerator(): \Icamys\SitemapGenerator\SitemapGenerator
    {
        $config = new \Icamys\SitemapGenerator\Config();
        $config->setBaseURL(ILIAS_HTTP_PATH);
        $config->setSaveDirectory(self::storageDir());

        $generator = new \Icamys\SitemapGenerator\SitemapGenerator($config);
        $generator->setMaxURLsPerSitemap(50000); // Maximum allowed value (see http://www.sitemaps.org/protocol.html)
        $generator->setSitemapFileName(self::SITEMAP_FILE_NAME);
        $generator->setSitemapIndexFileName(self::SITEMAP_INDEX_FILE_NAME);
        $generator->setSitemapStylesheet(ilSeoPlugin::PLUGIN_DIR . "/assets/sitemap.xsl");

        return $generator;
    }

    /**
     * @param \Icamys\SitemapGenerator\SitemapGenerator $generator
     * @param array<int, array<string, mixed>> $pages
     * @return void
     */
    protected function addUrls(\Icamys\SitemapGenerator\SitemapGenerator $generator, array $pages): void
    {
        $alternates = $this->groupAlternates($pages);
        $timezone = new DateTimeZone(ilTimeZone::_getDefaultTimeZone() ?: "UTC");

        foreach ($pages as $page) {
            // One malformed row costs one URL, not the whole file: addURL()
            // validates; run()'s catch handler would else abandon the sitemap.
            $permalink = (string) $page["permalink"];
            // A row reaching here with permalink "" is the homepage by
            // construction.
            if (!ilSeoPermalink::isValid($permalink, $permalink === "")) {
                $this->plugin->logger->warning("Sitemap: skipping malformed permalink '{$permalink}'.");
                continue;
            }

            // Omitted, not stamped "now": engines discard site-wide lastmod on
            // a generation timestamp; a zero date also formats as year -1.
            $raw_last_change = (string) ($page["last_change"] ?? "");
            $last_change = ilSeoSitemapStatus::hasUsableDate($raw_last_change)
                ? new DateTime($raw_last_change, $timezone)
                : null;

            $generator->addURL(
                "/" . $permalink,
                $last_change,
                ilSeoFrequency::normalize((string) ($page["frequency"] ?? "")),
                round((float) ilSeoPriority::normalize((int) ($page["priority"] ?? ilSeoPriority::DEFAULT_VALUE)) / 10.0, 1),
                $alternates[self::pageKey($page)] ?? []
            );
        }
    }

    /**
     * The hreflang alternates of every page, keyed by page.
     * @param array<int, array<string, mixed>> $pages
     * @return array<string, array<int, array{hreflang: string, href: string}>>
     */
    protected function groupAlternates(array $pages): array
    {
        $alternates = [];

        foreach ($pages as $page) {
            $key = self::pageKey($page);
            $href = ILIAS_HTTP_PATH . "/" . $page["permalink"];

            if ((string) $page["lang"] !== "-") {
                $alternates[$key][] = ["hreflang" => (string) $page["lang"], "href" => $href];
                continue;
            }

            // Default-language sentinel row: emit the real default language
            // plus an x-default alternate (Google's convention).
            $alternates[$key][] = ["hreflang" => ilSeoLanguage::getDefaultLang(), "href" => $href];
            $alternates[$key][] = ["hreflang" => "x-default", "href" => $href];
        }

        return $alternates;
    }

    /**
     * The (ref_id, secondary_id) pair a row's language variants share.
     * @param array<string, mixed> $page
     * @return string
     */
    protected static function pageKey(array $page): string
    {
        return $page["ref_id"] . "." . $page["secondary_id"];
    }

    /**
     * Ping the finalized sitemap to the endpoint the generator knows about,
     * then report the run as the success it already is. The file is written
     * before this method is reached, so no outcome here can fail the job:
     * whether a third-party endpoint acknowledges a ping says nothing about the
     * work performed, and engines discover the sitemap from robots.txt anyway.
     * @param \Icamys\SitemapGenerator\SitemapGenerator $generator
     * @param int $pages_count
     * @return ilCronJobResult
     */
    protected function submitSitemap(\Icamys\SitemapGenerator\SitemapGenerator $generator, int $pages_count): ilCronJobResult
    {
        $message = "Sitemap successfully generated with {$pages_count} urls.";

        try {
            // Accepted limitation: the generator sets only
            // CURLOPT_RETURNTRANSFER, so a stuck endpoint stalls this cron run.
            $submission_results = $generator->submitSitemap();
        } catch (Throwable $e) {
            $this->plugin->logger->notice("Sitemap ping could not be sent: " . $e->getMessage());
            return $this->result(ilCronJobResult::STATUS_OK, $message . " " . self::PING_UNCONFIRMED_NOTE);
        }

        if (!$this->isSubmissionConfirmed($submission_results)) {
            return $this->result(ilCronJobResult::STATUS_OK, $message . " " . self::PING_UNCONFIRMED_NOTE);
        }

        return $this->result(ilCronJobResult::STATUS_OK, $message);
    }

    /**
     * Record what each endpoint answered and report whether every one of them
     * acknowledged. An empty result set, or a code outside 2xx, is a notice
     * rather than an error: the ping is a side effect on an endpoint nobody
     * here controls, so its refusal is not a plugin failure. The codes are
     * logged either way, so an admin chasing an indexing problem has the trace.
     * @param array<int, array<string, mixed>> $submission_results
     * @return bool
     */
    protected function isSubmissionConfirmed(array $submission_results): bool
    {
        if ($submission_results === []) {
            $this->plugin->logger->notice("Sitemap ping: the generator contacted no endpoint at all.");
            return false;
        }

        $confirmed = true;

        foreach ($submission_results as $submission_result) {
            $endpoint = (string) ($submission_result["fullsite"] ?? $submission_result["site"] ?? "unknown endpoint");
            $http_code = (int) ($submission_result["http_code"] ?? 0);

            if ($http_code >= 200 && $http_code < 300) {
                $this->plugin->logger->info("Sitemap ping accepted by {$endpoint} with HTTP {$http_code}.");
                continue;
            }

            $this->plugin->logger->notice("Sitemap ping not confirmed by {$endpoint}, HTTP {$http_code}.");
            $confirmed = false;
        }

        return $confirmed;
    }

    /**
     * Remove the generated sitemap and stop announcing it in robots.txt. Having
     * no publishable page is a legitimate configuration, not a failure.
     * @return ilCronJobResult
     */
    protected function deleteSitemap(): ilCronJobResult
    {
        // Removed, not emptied: a zero-byte file at the well-known URL is
        // invalid XML.
        $sitemap_path = self::storageDir() . "/" . self::SITEMAP_FILE_NAME;
        if (is_file($sitemap_path) && !unlink($sitemap_path)) {
            $this->plugin->logger->error("Cannot remove {$sitemap_path}.");
        }

        $this->removeSitemapFromRobots();

        return $this->result(
            ilCronJobResult::STATUS_NO_ACTION,
            "No sitemap written: there are no public web pages."
        );
    }

    /**
     * Drop the "Sitemap:" line the generator writes into robots.txt, so a
     * sitemap that is no longer published is no longer advertised either.
     * @return void
     */
    protected function removeSitemapFromRobots(): void
    {
        $robots_path = self::storageDir() . "/" . self::ROBOTS_FILE_NAME;
        if (!is_file($robots_path)) {
            return;
        }

        $content = file_get_contents($robots_path);
        if ($content === false) {
            return;
        }

        $lines = array_filter(
            explode(PHP_EOL, $content),
            fn (string $line): bool => !str_starts_with($line, "Sitemap:")
        );

        if (file_put_contents($robots_path, implode(PHP_EOL, $lines)) === false) {
            $this->plugin->logger->error("Cannot write {$robots_path}.");
        }
    }

    /**
     * @param int $status
     * @param string $message
     * @return ilCronJobResult
     */
    protected function result(int $status, string $message): ilCronJobResult
    {
        $result = new ilCronJobResult();
        $result->setStatus($status);
        $result->setMessage($message);
        return $result;
    }
}
