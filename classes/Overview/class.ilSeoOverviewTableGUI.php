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
use ILIAS\UI\URLBuilder;
use ILIAS\UI\URLBuilderToken;
use Psr\Http\Message\ServerRequestInterface;

/**
 * KitchenSink data table for the Overview screen: per-row async edit plus
 * mass-edit/mass-delete bulk actions. Row identity is the encoded (ref_id,
 * secondary_id, lang) triple ilSeoOverviewDataRetrieval::encodeRowId()
 * produces.
 */
class ilSeoOverviewTableGUI
{
    /** @var string bulk action id: mass edit */
    public const ACTION_MASS_EDIT = "mass_edit";

    /** @var string bulk action id: mass delete */
    public const ACTION_MASS_DELETE = "mass_delete";

    /** @var string[] URLBuilder parameter namespace, shared by every acquired token below */
    public const NS = ["xseo", "pages"];

    /** @var string local name of the bulk action-selector token */
    public const PARAM_TABLE_ACTION = "table_action";

    /** @var string local name of the bulk row-id token (checkbox selection) */
    public const PARAM_ROW_ID = "row_id";

    /** @var string local name of the single "edit" action's row-id token */
    public const PARAM_EDIT_ROW_ID = "edit_row_id";

    /** @var string column id of the sitemap status, shared with the filter key it is driven by */
    public const COL_SITEMAP_STATUS = "sitemap_status";

    /** @var string column id of the duplicate-metadata flag */
    public const COL_DUPLICATE = "duplicate";

    /** @var \ILIAS\UI\Factory */
    private \ILIAS\UI\Factory $factory;

    /** @var ilLanguage */
    private ilLanguage $lng;

    /** @var ilDBInterface */
    private ilDBInterface $db;

    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /**
     * @return void
     */
    public function __construct()
    {
        global $DIC;

        $this->factory = $DIC->ui()->factory();
        $this->lng = $DIC->language();
        $this->db = $DIC->database();
        $this->plugin = ilSeoPlugin::getInstance();
    }

    /**
     * Compute a token's final parameter name under self::NS without
     * re-acquiring it (URLBuilderToken::getName() is deterministic: namespace +
     * separator + local name).
     * @param string $local_name
     * @return string
     */
    public static function paramName(string $local_name): string
    {
        return implode(URLBuilder::SEPARATOR, self::NS) . URLBuilder::SEPARATOR . $local_name;
    }

    /**
     * Acquire the URL builder + tokens for the bulk (mass-edit/mass-delete)
     * actions, targeting the Pages list command itself.
     *
     * @param string $list_url absolute URL of CMD_SHOW
     * @return array{0: URLBuilder, 1: URLBuilderToken, 2: URLBuilderToken}
     *     builder, action token, row-id token
     */
    public function acquireBulkActions(string $list_url): array
    {
        $df = new \ILIAS\Data\Factory();
        $url_builder = new URLBuilder($df->uri($list_url));

        return $url_builder->acquireParameters(self::NS, self::PARAM_TABLE_ACTION, self::PARAM_ROW_ID);
    }

    /**
     * Acquire the URL builder + token for the per-row async "edit" action,
     * targeting the dedicated edit command.
     *
     * @param string $edit_url absolute URL of CMD_PAGE_EDIT
     * @return array{0: URLBuilder, 1: URLBuilderToken} builder, row-id token
     */
    public function acquireEditAction(string $edit_url): array
    {
        $df = new \ILIAS\Data\Factory();
        $url_builder = new URLBuilder($df->uri($edit_url));

        return $url_builder->acquireParameter(self::NS, self::PARAM_EDIT_ROW_ID);
    }

    /**
     * Build the data table component.
     *
     * @param URLBuilder $bulk_url_builder
     * @param URLBuilderToken $action_token
     * @param URLBuilderToken $bulk_row_token
     * @param URLBuilder $edit_url_builder
     * @param URLBuilderToken $edit_row_token
     * @param array<string,mixed> $filter lang/robots/frequency, "" meaning
     *     "all"
     * @param int $pages_total distinct pages the plugin holds metadata for,
     *     shown in the title and unfiltered
     * @param ServerRequestInterface $request
     * @param string $table_id
     * @return \ILIAS\UI\Component\Table\Data
     */
    public function build(
        URLBuilder $bulk_url_builder,
        URLBuilderToken $action_token,
        URLBuilderToken $bulk_row_token,
        URLBuilder $edit_url_builder,
        URLBuilderToken $edit_row_token,
        array $filter,
        int $pages_total,
        ServerRequestInterface $request,
        string $table_id
    ): \ILIAS\UI\Component\Table\Data {
        $columns = $this->buildColumns();
        $actions = $this->buildActions($bulk_url_builder, $action_token, $bulk_row_token, $edit_url_builder, $edit_row_token);
        $retrieval = new ilSeoOverviewDataRetrieval($this->db, $this->plugin, $this->lng, $filter);
        $title = sprintf($this->plugin->txt("title_admin_overview_count"), $pages_total);

        return $this->factory->table()->data($title, $columns, $retrieval)
            ->withId($table_id)
            ->withActions($actions)
            ->withRequest($request);
    }

    /**
     * @return array<string,\ILIAS\UI\Component\Table\Column\Column>
     */
    private function buildColumns(): array
    {
        $col_factory = $this->factory->table()->column();

        return [
            "title" => $col_factory->text($this->plugin->txt("title"))->withIsSortable(false),
            "permalink" => $col_factory->text($this->plugin->txt("permalink"))->withIsSortable(false),
            "robots" => $col_factory->text($this->plugin->txt("robots"))->withIsSortable(false),
            "priority" => $col_factory->number($this->plugin->txt("priority"))->withIsSortable(false),
            "frequency" => $col_factory->text($this->plugin->txt("frequency"))->withIsSortable(false),
            "lang" => $col_factory->text($this->plugin->txt("language"))->withIsSortable(false),
            // Optional but shown by default: hiding it also skips its per-row
            // cost in ilSeoOverviewDataRetrieval::getRows().
            self::COL_SITEMAP_STATUS => $col_factory->status($this->plugin->txt("sitemap_status"))
                ->withIsSortable(false)
                ->withIsOptional(true, true),
            // Same shape as the sitemap-status column: optional but shown by
            // default, and hiding it skips the two GROUP BY queries behind it.
            self::COL_DUPLICATE => $col_factory->status($this->plugin->txt("duplicate_metadata"))
                ->withIsSortable(false)
                ->withIsOptional(true, true),
        ];
    }

    /**
     * @param URLBuilder $bulk_url_builder
     * @param URLBuilderToken $action_token
     * @param URLBuilderToken $bulk_row_token
     * @param URLBuilder $edit_url_builder
     * @param URLBuilderToken $edit_row_token
     * @return array<string,\ILIAS\UI\Component\Table\Action\Action>
     */
    private function buildActions(
        URLBuilder $bulk_url_builder,
        URLBuilderToken $action_token,
        URLBuilderToken $bulk_row_token,
        URLBuilder $edit_url_builder,
        URLBuilderToken $edit_row_token
    ): array {
        $action_factory = $this->factory->table()->action();

        return [
            "edit" => $action_factory->single(
                $this->lng->txt("edit"),
                $edit_url_builder,
                $edit_row_token
            )->withAsync(true),
            self::ACTION_MASS_EDIT => $action_factory->multi(
                $this->plugin->txt("mass_edit"),
                $bulk_url_builder->withParameter($action_token, self::ACTION_MASS_EDIT),
                $bulk_row_token
            ),
            self::ACTION_MASS_DELETE => $action_factory->multi(
                $this->lng->txt("delete"),
                $bulk_url_builder->withParameter($action_token, self::ACTION_MASS_DELETE),
                $bulk_row_token
            ),
        ];
    }
}
