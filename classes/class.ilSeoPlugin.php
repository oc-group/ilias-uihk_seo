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
 * Plugin main class.
 */
class ilSeoPlugin extends ilUserInterfaceHookPlugin implements ilCronJobProvider
{
    /** @var string */
    public const PLUGIN_ID = "seo";

    /** @var string */
    public const PLUGIN_NAME = "Seo";

    /** @var string */
    public const PLUGIN_DIR = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Seo";

    /** @var string */
    public const PREFIX = "ui_uihk_seo";

    /** @var string */
    public const TABLE_DATA = "ui_uihk_seo_data";

    /** @var string */
    public const TABLE_HISTORY = "ui_uihk_seo_history";

    /** @var self|null */
    protected static ?self $instance = null;

    /** @var ilLogger */
    public ilLogger $logger;

    /** @var ilSeoRewriteRules */
    protected ilSeoRewriteRules $rules;

    /**
     * Initialize plugin.
     *
     * The rewrite rules are wired up before the GlobalScreen guard below, since
     * the three lifecycle hooks that use them run in contexts where
     * GlobalScreen is absent.
     * @return void
     */
    protected function init(): void
    {
        global $DIC;

        if (self::$instance === null) {
            self::$instance = $this;
        }

        include_once self::PLUGIN_DIR . "/vendor/autoload.php";

        $this->logger = ilLoggerFactory::getLogger(self::PLUGIN_ID);
        $this->rules = new ilSeoRewriteRules($this);

        if (!isset($DIC["global_screen"])) {
            return;
        }

        $this->provider_collection->setModificationProvider(new ilSeoLayoutProvider($DIC, $this));
        $this->provider_collection->setMetaBarProvider(new ilSeoMetaBarProvider($DIC, $this));
    }

    /**
     * This method prevent the plugin constructor to be called several times.
     * @return self
     */
    public static function getInstance(): self
    {
        global $DIC;

        if (self::$instance == null) {
            /** @var ilComponentFactory */
            $component_factory = $DIC["component.factory"];

            /** @var self */
            $plugin = $component_factory->getPlugin(self::PLUGIN_ID);
            self::$instance = $plugin;
        }

        return self::$instance;
    }

    /**
     * Must correspond to the plugin subdirectory.
     * @return string
     */
    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Web path of one of the plugin's own icons, for anything that takes a path
     * rather than a Kitchen Sink component: a custom menu symbol, a MetaBar
     * symbol, a page-header title icon.
     * @param string $name icon file name without the extension
     * @return string
     */
    public static function iconPath(string $name): string
    {
        return self::PLUGIN_DIR . "/templates/images/{$name}.svg";
    }

    /**
     * Procedure that is called when uninstalling the plugin. Drops the tables
     * this plugin owns.
     * @return void
     */
    protected function afterUninstall(): void
    {
        if (!$this->rules->remove()) {
            // Reported, never thrown: an exception here would abort the
            // uninstall with the plugin's own tables still in the database.
            $this->reportRulesNotRemoved();
        }

        (new ilSetting(ilSeoSettings::MODULE))->deleteAll();

        $tables = [
            self::TABLE_DATA,
            self::TABLE_HISTORY,
        ];
        foreach ($tables as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->dropTable($table);
            }
        }
    }

    /**
     * Plugin activation.
     *
     * A failed rewrite-rule write is raised as an exception, because that is
     * the only channel either caller reads: both the plugin administration
     * screen and the command-line setup discard this method's return value, so
     * returning false would report success and leave every permalink answering
     * with the ILIAS error page.
     *
     * The activation itself stands when this fires. Everything else the plugin
     * does keeps working, and saving the settings screen writes the rules again
     * once the file is writable, so refusing the activation outright would
     * remove that way out for an installation that carries the rules in its
     * server configuration instead.
     *
     * @throws ilException if the rewrite rules could not be written
     * @return bool
     */
    public function activate(): bool
    {
        if (!parent::activate()) {
            return false;
        }

        if (!$this->rules->apply()) {
            throw new ilException(
                "Seo: the plugin is active, but the permalink rewrite rules could not be written. "
                . "Permalinks stay broken until the file is writable and the settings are saved again."
            );
        }

        return true;
    }

    /**
     * Plugin deactivation.
     *
     * A failed removal is reported, not raised: an administrator must always be
     * able to switch a plugin off, and the administration screen turns anything
     * other than an invalid-argument exception into a fatal error rather than a
     * message.
     *
     * @return bool
     */
    public function deactivate(): bool
    {
        if (!parent::deactivate()) {
            return false;
        }

        if (!$this->rules->remove()) {
            $this->reportRulesNotRemoved();
        }

        return true;
    }

    /**
     * Report rewrite rules still sitting in the web server configuration after
     * the plugin stopped answering for them. Left there they keep routing
     * unknown paths to the plugin.
     *
     * The log entry is the record that survives; the on-screen message is added
     * only when a request context exists to render one, which deactivation has
     * and an uninstall run outside a request does not.
     * @return void
     */
    private function reportRulesNotRemoved(): void
    {
        global $DIC;

        $message = "Seo: the permalink rewrite rules could not be removed. Delete the block "
            . "between the two ilias-uihk_seo markers by hand.";

        $this->logger->error($message);

        if (isset($DIC["tpl"])) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage("failure", $message, true);
        }
    }

    /**
     * Event hook (see plugin.xml's "Services/Object" listen subscription):
     * ILIAS raises "deleteReference" right before removing a ref_id's
     * object_reference row. Cleans up this plugin's SEO data so it never
     * outlives the object. A one-time retroactive cleanup already ran once for
     * rows orphaned before this hook existed; that migration predates
     * dbupdate.php's current single step and is not part of it.
     * @param string $a_component
     * @param string $a_event
     * @param array<string, mixed> $a_parameter
     * @return void
     */
    public function handleEvent(string $a_component, string $a_event, array $a_parameter): void
    {
        if ($a_component !== "Services/Object" || $a_event !== "deleteReference") {
            return;
        }

        $ref_id = (int) ($a_parameter["ref_id"] ?? 0);
        if ($ref_id > 0) {
            (new ilSeo())->deleteForRefId($ref_id);
        }
    }

    /**
     * Get list of cron jobs.
     * @return array<int, ilCronJob>
     */
    public function getCronJobInstances(): array
    {
        $instances = [
            new ilSeoSitemapBuilderCronJob($this),
        ];

        return $instances;
    }

    /**
     * Get a CronJob object given its id.
     * @param string $job_id
     * @return ilCronJob
     */
    public function getCronJobInstance(string $job_id): ilCronJob
    {
        foreach ($this->getCronJobInstances() as $cron_job) {
            if ($job_id === $cron_job->getId()) {
                return $cron_job;
            }
        }

        throw new OutOfBoundsException("Could not find any job for id '{$job_id}'");
    }
}
