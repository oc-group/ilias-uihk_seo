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
 * Overview screen of the SEO administration area: an entry per page the plugin
 * knows about, as a permalinks table, plus its mass-edit/mass-delete commands.
 * Forwarded to from ilSeoConfigGUI, which owns the page header and the tabs.
 *
 * @ilCtrl_isCalledBy ilSeoOverviewGUI: ilSeoConfigGUI
 */
class ilSeoOverviewGUI implements ilCtrlSecurityInterface
{
    /** @var string show the Overview screen command */
    public const CMD_SHOW = "show";

    /** @var string session key for the Overview screen's KS Filter (ilUIFilterService) */
    public const FILTER_ID = "uihk_seo_pages_filter";

    /** @var string edit page command */
    public const CMD_PAGE_EDIT = "editPage";

    /** @var string update page command */
    public const CMD_PAGE_UPDATE = "updatePage";

    /** @var string show mass edit command */
    public const CMD_SHOW_MASS_EDIT = "showMassEdit";

    /** @var string apply mass edit command */
    public const CMD_APPLY_MASS_EDIT = "applyMassEdit";

    /** @var string show mass delete command */
    public const CMD_SHOW_MASS_DELETE = "showMassDelete";

    /** @var string apply mass delete command */
    public const CMD_APPLY_MASS_DELETE = "applyMassDelete";

    /** @var string delete page request command */
    public const CMD_PAGE_DELETE_REQUEST = "deletePageRequest";

    /** @var string delete page command */
    public const CMD_PAGE_DELETE = "deletePage";

    /** @var string[] offered by the mass-edit form and enforced again by applyMassEdit() */
    private const MASS_EDIT_INDEX_OPTIONS = ["index", "noindex"];

    /** @var string[] */
    private const MASS_EDIT_FOLLOW_OPTIONS = ["follow", "nofollow"];

    /** @var ilCtrlInterface */
    protected ilCtrlInterface $ctrl;

    /** @var ilLanguage */
    protected ilLanguage $lng;

    /** @var ilGlobalTemplateInterface */
    protected ilGlobalTemplateInterface $tpl;

    /** @var \ILIAS\UI\Factory */
    protected \ILIAS\UI\Factory $factory;

    /** @var \ILIAS\UI\Renderer */
    protected \ILIAS\UI\Renderer $renderer;

    /** @var \ILIAS\HTTP\Services */
    protected \ILIAS\HTTP\Services $http;

    /** @var \ILIAS\Refinery\Factory */
    protected \ILIAS\Refinery\Factory $refinery;

    /** @var ilUIService */
    protected ilUIService $ui_service;

    /** @var ilSeoPlugin */
    protected ilSeoPlugin $plugin;

    /** @var ilSeo */
    protected ilSeo $seo;

    /** @var ilSeoMetadataEditor */
    protected ilSeoMetadataEditor $editor;

    /** @var ilSeoOverviewTableGUI */
    protected ilSeoOverviewTableGUI $pages_table;

    /** @var ilSeoGUIRequest */
    protected ilSeoGUIRequest $request;

    /** @var ilSeoSettings */
    protected ilSeoSettings $settings;

    /**
     * Class constructor.
     */
    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->lng = $DIC->language();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->http = $DIC->http();
        $this->refinery = $DIC->refinery();
        $this->ui_service = $DIC->uiService();

        $this->plugin = ilSeoPlugin::getInstance();
        $this->seo = new ilSeo();
        $this->editor = new ilSeoMetadataEditor($this->plugin, $this->lng, $this->seo);
        $this->pages_table = new ilSeoOverviewTableGUI();
        $this->request = new ilSeoGUIRequest($this->http, $this->refinery);
        $this->settings = new ilSeoSettings();
    }

    /**
     * Handles commands. The page header and tabs are set by the forwarding
     * parent (ilSeoConfigGUI) before reaching here.
     *
     * Every command requires "write" on the administration root, the table
     * render included, since it lists every page the plugin knows about. The
     * switch is also the allowlist that makes $this->$cmd() safe.
     *
     * @throws ilException if command is not known
     * @return void
     */
    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd(self::CMD_SHOW);

        // ILIAS enters a plugin's configuration through ilPluginConfigGUI
        // with cmd=configure, and ilCtrl carries that command through the
        // forward into this screen, which has no command by that name. The
        // entry command cannot be rewritten before the forward either:
        // ilCtrl::setCmd() exists on ILIAS 9 but was removed from the public
        // interface on 10, so it is normalised here, where both majors agree.
        if ($cmd === ilSeoConfigGUI::CMD_CONFIGURE) {
            $cmd = self::CMD_SHOW;
        }

        switch ($cmd) {
            case self::CMD_PAGE_EDIT:
            case self::CMD_PAGE_DELETE_REQUEST:
                ilSeoAccess::requireAdminWriteAsync();
                break;

            case self::CMD_SHOW:
            case self::CMD_PAGE_UPDATE:
            case self::CMD_SHOW_MASS_EDIT:
            case self::CMD_APPLY_MASS_EDIT:
            case self::CMD_SHOW_MASS_DELETE:
            case self::CMD_APPLY_MASS_DELETE:
            case self::CMD_PAGE_DELETE:
                ilSeoAccess::requireAdminWrite();
                break;

            default:
                throw new ilException("Unknown command: '$cmd'");
        }

        $this->$cmd();
    }

    /**
     * Every command that changes stored state, whatever shape the browser
     * submits it in. ilCtrl decides POST vs GET from the "cmd=post" query
     * parameter, not the HTTP method, so a cross-site POST is judged against
     * this list and getSafePostCommands() never sees it. The real form actions
     * are unaffected: getFormAction() already carries the rtoken.
     * @return string[]
     */
    public function getUnsafeGetCommands(): array
    {
        return [
            self::CMD_PAGE_DELETE,
            self::CMD_PAGE_UPDATE,
            self::CMD_APPLY_MASS_EDIT,
            self::CMD_APPLY_MASS_DELETE,
        ];
    }

    /**
     * Nothing opts out of POST protection: every POST command here writes or
     * deletes.
     * @return string[]
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    /**
     * Show admin pages list: KS Filter (ilUIFilterService) plus the KS Pages
     * table, redirecting to mass-edit/mass-delete on a bulk action. Building
     * the filter via ilUIFilterService::standard() is unconditional and
     * self-dispatching: it reads/writes its own session state and the
     * "cmdFilter" GET param on every call, regardless of the ilCtrl command
     * that reached this method.
     *
     * The permalink sanitiser is registered here, not in editPage(): this is
     * the only full-page render of this screen, and the row modal's field
     * cannot register it itself. Nothing else of the object-page slate applies
     * here. Its stylesheet only hides a doubled submit button inside a wrapper
     * this screen does not render.
     * @return void
     */
    public function show(): void
    {
        ilSeoMetadataFields::injectPermalinkAssets($this->tpl);

        $filter_action = $this->ctrl->getLinkTarget($this, self::CMD_SHOW);
        /**
         * ilUIFilterService.php's own docblock imports
         * `ILIAS\UI\Component\Input\Field\FilterInput`, which does not exist
         * anywhere in ILIAS 9 core; the real interface is
         * `ILIAS\UI\Component\Input\Container\Filter\FilterInput` (implemented
         * by `Select` and every other filterable field). A core `use`-import
         * typo, not a real type mismatch: buildPagesFilterInputs() returns
         * `Select` instances, which do implement the real (Container\Filter)
         * FilterInput.
         * @psalm-suppress InvalidArgument
         */
        $ks_filter = $this->ui_service->filter()->standard(
            self::FILTER_ID,
            $filter_action,
            $this->buildPagesFilterInputs(), // @phpstan-ignore-line argument.type
            [
                "lang" => true,
                "robots" => true,
                "frequency" => true,
                ilSeoOverviewTableGUI::COL_SITEMAP_STATUS => true,
            ],
            true,
            true
        );
        $filter = $this->ui_service->filter()->getData($ks_filter) ?? [
            "lang" => "",
            "robots" => "",
            "frequency" => "",
            ilSeoOverviewTableGUI::COL_SITEMAP_STATUS => "",
        ];

        $list_url = ILIAS_HTTP_PATH . "/" . ltrim($this->ctrl->getLinkTarget($this, self::CMD_SHOW), "./");
        [$bulk_builder, $action_token, $bulk_row_token] = $this->pages_table->acquireBulkActions($list_url);

        $query = $this->http->wrapper()->query();
        if ($query->has($action_token->getName())) {
            $action = $query->retrieve($action_token->getName(), $this->refinery->kindlyTo()->string());
            $ids = $this->retrieveRowIds($bulk_row_token->getName());

            if (count($ids) === 0) {
                $this->tpl->setOnScreenMessage("failure", $this->lng->txt("no_checkbox_selected"), true);
                $this->ctrl->redirect($this, self::CMD_SHOW);
            }

            // ilCtrl can't carry an array parameter here, so comma-join instead
            // (encodeRowId() output never contains a comma).
            $this->ctrl->setParameter($this, "ids", implode(",", $ids));
            if ($action === ilSeoOverviewTableGUI::ACTION_MASS_EDIT) {
                $this->ctrl->redirect($this, self::CMD_SHOW_MASS_EDIT);
            }
            if ($action === ilSeoOverviewTableGUI::ACTION_MASS_DELETE) {
                $this->ctrl->redirect($this, self::CMD_SHOW_MASS_DELETE);
            }
            $this->ctrl->setParameter($this, "ids", null);
        }

        $edit_url = ILIAS_HTTP_PATH . "/" . ltrim($this->ctrl->getLinkTarget($this, self::CMD_PAGE_EDIT), "./");
        [$edit_builder, $edit_row_token] = $this->pages_table->acquireEditAction($edit_url);

        $table = $this->pages_table->build(
            $bulk_builder,
            $action_token,
            $bulk_row_token,
            $edit_builder,
            $edit_row_token,
            $filter,
            $this->seo->countDistinctPages(),
            $this->http->request(),
            "uihk_seo_pages"
        );

        $this->tpl->setContent($this->renderer->render([
            $ks_filter,
            $table,
        ]));
    }

    /**
     * Build the Overview screen's KS Filter inputs; session persistence,
     * activation/expand state and value restoration are all handled inside
     * ilUIFilterService::standard(), so this method only builds the (empty)
     * inputs. No manual "" => "All" option here: FilterContextRenderer already
     * prepends its own empty-value placeholder ("-") to every Select, so adding
     * one duplicates the no-filter choice as both "-" and "All". The sitemap
     * select is named after what it can actually do: of the eight statuses the
     * column shows, only SQL_FILTERABLE survives a WHERE clause; "not
     * public"/"not reachable" are per-row PHP and would need the whole table
     * scanned, which pagination rules out. Since offering them would return
     * only a subset of what the option promises, the input is labelled an
     * exclusion picker listing only the reasons it answers completely;
     * FilterContextRenderer renders no byline, so the scope must live in the
     * label.
     * @return
     *     array<string,\ILIAS\UI\Component\Input\Container\Filter\FilterInput>
     */
    private function buildPagesFilterInputs(): array
    {
        $default = $this->settings->getDefaultLang();
        $active_langs = array_merge($default !== "" ? [$default] : [], $this->settings->getSecondaryLangs());

        $lang_options = [];
        foreach ($active_langs as $key) {
            $lang_options[$key] = $this->lng->txt("meta_l_" . $key);
        }

        $exclusion_options = [];
        foreach (ilSeoSitemapStatus::SQL_FILTERABLE as $status) {
            $exclusion_options[$status] = $this->plugin->txt(ilSeoSitemapStatus::langKey($status));
        }

        $field_factory = $this->factory->input()->field();

        return [
            "lang" => $field_factory->select($this->plugin->txt("language"), $lang_options),
            "robots" => $field_factory->select($this->plugin->txt("robots"), [
                "index,follow" => $this->plugin->txt("robots_index_follow"),
                "index,nofollow" => $this->plugin->txt("robots_index_nofollow"),
                "noindex,follow" => $this->plugin->txt("robots_noindex_follow"),
                "noindex,nofollow" => $this->plugin->txt("robots_noindex_nofollow"),
            ]),
            "frequency" => $field_factory->select(
                $this->plugin->txt("frequency"),
                ilSeoFrequency::options($this->plugin)
            ),
            ilSeoOverviewTableGUI::COL_SITEMAP_STATUS => $field_factory->select(
                $this->plugin->txt("sitemap_exclusion"),
                $exclusion_options
            ),
        ];
    }

    /**
     * Read a KS table row-id token's value; a bulk (multi) action always
     * sends an array of the checked rows' ids.
     * @param string $param_name
     * @return string[]
     */
    private function retrieveRowIds(string $param_name): array
    {
        $query = $this->http->wrapper()->query();
        if (!$query->has($param_name)) {
            return [];
        }

        return $query->retrieve(
            $param_name,
            $this->refinery->custom()->transformation(
                fn ($v) => is_array($v) ? array_map("strval", $v) : [(string) $v]
            )
        );
    }

    /**
     * Read the comma-joined "ids" GET parameter forwarded by show() (see the
     * setParameter() comment there for why it is not a plain array).
     * @return string[]
     */
    private function retrieveCommaJoinedIds(): array
    {
        $query = $this->http->wrapper()->query();
        if (!$query->has("ids")) {
            return [];
        }
        $joined = $query->retrieve("ids", $this->refinery->kindlyTo()->string());
        return $joined !== "" ? explode(",", $joined) : [];
    }

    /**
     * Show mass edit form for selected pages. Ids arrive as a comma-joined
     * GET string, forwarded by show() from the KS bulk action's selection.
     * @return void
     */
    protected function showMassEdit(): void
    {
        $ids = $this->retrieveCommaJoinedIds();

        if (count($ids) === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("no_checkbox_selected"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        $form = $this->initMassEditForm($ids);
        $this->tpl->setContent($form->getHTML());
    }

    /**
     * Show mass delete confirmation. Ids arrive as a comma-joined GET
     * string, see showMassEdit().
     * @return void
     */
    protected function showMassDelete(): void
    {
        $ids = $this->retrieveCommaJoinedIds();

        if (count($ids) === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("no_checkbox_selected"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        $this->tpl->setOnScreenMessage("question", sprintf($this->plugin->txt("delete_permalink_mass_message"), count($ids)));

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, self::CMD_APPLY_MASS_DELETE));

        foreach ($ids as $id) {
            $hidden = new ilHiddenInputGUI("ids[]");
            $hidden->setValue((string) $id);
            $form->addItem($hidden);
        }

        $form->addCommandButton(self::CMD_APPLY_MASS_DELETE, $this->lng->txt("delete"));
        $form->addCommandButton(self::CMD_SHOW, $this->lng->txt("cancel"));

        $this->tpl->setContent($form->getHTML());
    }

    /**
     * Apply mass delete.
     * @return void
     */
    protected function applyMassDelete(): void
    {
        $post = $this->http->wrapper()->post();
        $ids = $post->has("ids")
            ? $post->retrieve("ids", $this->refinery->kindlyTo()->listOf($this->refinery->kindlyTo()->string()))
            : [];

        if (count($ids) === 0) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("no_checkbox_selected"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        foreach ($ids as $id) {
            $row_id = ilSeoOverviewDataRetrieval::decodeRowId($id);

            $this->seo->delete($row_id["ref_id"], $row_id["secondary_id"], $row_id["lang"]);
        }

        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("permalink_deleted"), true);
        $this->ctrl->redirect($this, self::CMD_SHOW);
    }

    /**
     * Show delete confirmation in modal.
     * @return never
     */
    public function deletePageRequest(): void
    {
        $query = $this->http->wrapper()->query();

        if (!$query->has("replaceSignal") || !$query->has("seo_ref_id")) {
            exit;
        }

        $this->ctrl->saveParameterByClass(self::class, "replaceSignal");
        $this->ctrl->saveParameterByClass(self::class, "seo_ref_id");
        $this->ctrl->saveParameterByClass(self::class, "secondary_id");
        $this->ctrl->saveParameterByClass(self::class, "seo_lang");

        $modal = $this->factory->modal()->roundtrip(
            $this->plugin->txt("delete_permalink"),
            [
                $this->factory->messageBox()->confirmation($this->plugin->txt("delete_permalink_message")),
            ]
        )->withActionButtons([
            $this->factory->button()->standard(
                $this->lng->txt("delete"),
                $this->ctrl->getLinkTarget($this, self::CMD_PAGE_DELETE)
            ),
        ]);

        echo $this->renderer->renderAsync($modal);
        exit;
    }

    /**
     * Delete SEO page record.
     * @return void
     */
    public function deletePage(): void
    {
        $query = $this->http->wrapper()->query();
        $refinery = $this->refinery;

        if (!$query->has("seo_ref_id")) {
            throw new LogicException("SEO: seo_ref_id not set");
        }
        $seo_ref_id = $query->retrieve("seo_ref_id", $refinery->kindlyTo()->int());

        if (!$query->has("seo_lang")) {
            throw new LogicException("SEO: seo_lang not set");
        }
        $lang = $query->retrieve("seo_lang", $refinery->kindlyTo()->string());
        $secondary_id = $query->has("secondary_id")
            ? $query->retrieve("secondary_id", $refinery->kindlyTo()->int())
            : 0;

        $this->seo->delete($seo_ref_id, $secondary_id, $lang);

        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("permalink_deleted"), true);
        $this->ctrl->redirect($this, self::CMD_SHOW);
    }

    /**
     * Async target of the Pages table's per-row "edit" action; row identity
     * arrives via the action's row-id token (ilSeo-encoded), not individual GET
     * params.
     * @return void
     */
    public function editPage(): void
    {
        $query = $this->http->wrapper()->query();
        $param_name = ilSeoOverviewTableGUI::paramName(ilSeoOverviewTableGUI::PARAM_EDIT_ROW_ID);

        if (!$query->has($param_name)) {
            exit;
        }

        $raw = $query->retrieve(
            $param_name,
            $this->refinery->custom()->transformation(fn ($v) => is_array($v) ? $v : [$v])
        );
        $row_id = ilSeoOverviewDataRetrieval::decodeRowId((string) ($raw[0] ?? ""));
        $ref_id = $row_id["ref_id"];
        $secondary_id = $row_id["secondary_id"];
        $lang = $row_id["lang"];

        // Before getFormAction(): the POST action has to carry the row's own
        // parameters.
        $this->primeRowParameters($ref_id, $secondary_id, $lang);

        // Only the field set gets the pending-draft overlay; head and label
        // below describe the stored row.
        $seo_data = $this->seo->fetchById($ref_id, $secondary_id, $lang);
        $form_seo_data = ilSeoPendingHomepage::mergeIntoSeoData($seo_data, $ref_id, $secondary_id, $lang);

        $content = [$this->editor->headFromRow($ref_id, $secondary_id, $lang, $this->resolveRowObject($ref_id), $seo_data)];
        if (ilSeoPendingHomepage::isPendingFor($ref_id, $secondary_id, $lang)) {
            $content[] = $this->editor->pendingHomepageNotice();
        }

        $modal = $this->factory->modal()->roundtrip(
            $this->plugin->txt("seo_edit"),
            $content,
            $this->editor->inputsForRow($form_seo_data),
            $this->ctrl->getFormAction(
                $this,
                self::CMD_PAGE_UPDATE
            )
        );

        // Delete button replaces this same modal's content, so use its own
        // replace signal.
        $this->ctrl->setParameter($this, "replaceSignal", $modal->getReplaceSignal()->getId());
        $this->ctrl->saveParameterByClass(self::class, "replaceSignal");

        $modal = $modal->withActionButtons([
            $this->factory->button()->standard(
                $this->lng->txt("delete"),
                "#"
            )->withOnClick(
                $modal->getReplaceSignal()->withAsyncRenderUrl(
                    $this->ctrl->getLinkTarget(
                        $this,
                        self::CMD_PAGE_DELETE_REQUEST
                    )
                )
            ),
        ]);

        // Last: a roundtrip modal inherits withSubmitLabel() from the form
        // interface, which returns a form, so nothing else chains after it.
        echo $this->renderer->renderAsync($modal->withSubmitLabel($this->editor->submitLabel($seo_data)));
        exit;
    }

    /**
     * A mass-edit select's options, led by the empty "leave unchanged" entry.
     *
     * It has to come first and it has to exist: ilSelectInputGUI always
     * pre-selects its first option, so whichever option leads the list is what
     * every submission writes to every selected row, whether or not the admin
     * touched the field. Without an empty first entry the "unchanged" semantics
     * applyMassEdit() implements are unreachable.
     * @param array<string,string> $options value => label
     * @return array<string,string>
     */
    private function withUnchangedOption(array $options): array
    {
        return ["" => $this->plugin->txt("mass_edit_unchanged")] + $options;
    }

    /**
     * Initialize mass edit form for selected pages.
     * @param string[] $ids
     * @return ilPropertyFormGUI
     */
    protected function initMassEditForm(array $ids): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction(
            $this->ctrl->getFormAction($this, self::CMD_APPLY_MASS_EDIT)
        );

        $index = new ilSelectInputGUI($this->plugin->txt("index"), "index");
        $index->setOptions($this->withUnchangedOption(
            array_combine(self::MASS_EDIT_INDEX_OPTIONS, self::MASS_EDIT_INDEX_OPTIONS)
        ));
        $form->addItem($index);

        $follow = new ilSelectInputGUI($this->plugin->txt("follow"), "follow");
        $follow->setOptions($this->withUnchangedOption(
            array_combine(self::MASS_EDIT_FOLLOW_OPTIONS, self::MASS_EDIT_FOLLOW_OPTIONS)
        ));
        $form->addItem($follow);

        $priority = new ilNumberInputGUI($this->plugin->txt("priority"), "priority");
        $priority->setInfo($this->plugin->txt("mass_edit_unchanged_info"));
        $priority->setMinValue(ilSeoPriority::MIN);
        $priority->setMaxValue(ilSeoPriority::MAX);
        $form->addItem($priority);

        $frequency = new ilSelectInputGUI($this->plugin->txt("frequency"), "frequency");
        $frequency->setOptions($this->withUnchangedOption(ilSeoFrequency::options($this->plugin)));
        $form->addItem($frequency);

        foreach ($ids as $id) {
            $hidden = new ilHiddenInputGUI("ids[]");
            $hidden->setValue((string) $id);
            $form->addItem($hidden);
        }

        $form->addCommandButton(self::CMD_APPLY_MASS_EDIT, $this->lng->txt("save"));
        $form->addCommandButton(self::CMD_SHOW, $this->lng->txt("cancel"));

        return $form;
    }

    /**
     * The object an SEO row describes, or null once its ref_id stops resolving.
     * A row outlives the object it was saved for, and it still has to be
     * editable and deletable from here, so a purged reference is a state the
     * modal reports rather than an error.
     * @param int $ref_id
     * @return ilObject|null
     */
    private function resolveRowObject(int $ref_id): ?ilObject
    {
        return ilObjectFactory::getInstanceByRefId($ref_id, false);
    }

    /**
     * Put the edited row's identity on ilCtrl, so every link and form action
     * built after this call carries it. Both the modal and the command it posts
     * to prime the same three.
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @return void
     */
    private function primeRowParameters(int $ref_id, int $secondary_id, string $lang): void
    {
        // Prefixed: "ref_id" is ilCtrl own routing param, "lang" is the
        // UI-language switch, re-rendering the row screen in that language.
        $this->ctrl->setParameter($this, "seo_ref_id", $ref_id);
        $this->ctrl->setParameter($this, "secondary_id", $secondary_id);
        $this->ctrl->setParameter($this, "seo_lang", $lang);
    }

    /**
     * Update SEO data for a single page.
     * @return void
     */
    public function updatePage(): void
    {
        $request = $this->http->request();
        $query = $this->http->wrapper()->query();
        $refinery = $this->refinery;

        if (!$query->has("seo_ref_id")) {
            throw new LogicException("SEO: seo_ref_id not set");
        }
        $seo_ref_id = $query->retrieve("seo_ref_id", $refinery->kindlyTo()->int());
        $secondary_id = $query->has("secondary_id")
            ? $query->retrieve("secondary_id", $refinery->kindlyTo()->int())
            : 0;

        if (!$query->has("seo_lang")) {
            throw new LogicException("SEO: seo_lang not set");
        }
        $lang = $query->retrieve("seo_lang", $refinery->kindlyTo()->string());

        // One read of the row, rebuilding the field set the modal rendered, so
        // what withRequest() validates against is what the admin was shown.
        $this->primeRowParameters($seo_ref_id, $secondary_id, $lang);

        $existing = $this->seo->fetchById($seo_ref_id, $secondary_id, $lang);

        $form = $this->factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, self::CMD_PAGE_UPDATE),
            $this->editor->inputsForRow($existing)
        )->withRequest($request);

        $data = $form->getData();

        if ($data === null) {
            // Named after the field that failed, like both object-level forms:
            // this modal enforces the same rules, so owes the same answer.
            $this->tpl->setOnScreenMessage(
                "failure",
                ilSeoFormValidation::failureMessage($form->getInputs(), $this->plugin),
                true
            );
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        // An empty permalink is now legitimate, resolved below, not by the
        // field/form; $existing lets decode() keep priority/frequency when off.
        $values = $this->editor->decode($data, $existing);

        if (ilSeoPermalink::isReserved($values["permalink"])) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("permalink_reserved"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        if ($values["permalink"] === "") {
            $this->routeToHomepageConfirmation($seo_ref_id, $secondary_id, $lang, $values);
            return;
        }

        // Through ilSeo::save(), never raw: the duplicate-permalink check and
        // history-redirect row both live only there.
        $saved = $this->seo->save(
            $seo_ref_id,
            $secondary_id,
            $lang,
            $values["permalink"],
            $values["title"],
            $values["description"],
            $values["robots"],
            $values["priority"],
            $values["frequency"],
            false
        );

        if (!$saved) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("permalink_already_exists"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        ilSeoPendingHomepage::clearIfMatches($seo_ref_id, $secondary_id, $lang);
        $this->tpl->setOnScreenMessage("success", $this->lng->txt("saved_successfully"), true);
        $this->ctrl->redirect($this, self::CMD_SHOW);
    }

    /**
     * Stash the submitted row and send the browser to ilSeoGUI's own homepage
     * confirmation screen; see that class's confirmHomepage()/applyHomepage()
     * and its own routeToHomepageConfirmation(), which this mirrors rather than
     * duplicating the screen itself. The row modal that offered this edit is
     * async and cannot be reopened from a plain GET redirect, so both the
     * confirm and the decline path return here to the Overview list rather than
     * to the row itself; a decline still loses nothing, because the pending
     * draft resurfaces as the row's initial values the next time editPage()
     * opens it (see ilSeoPendingHomepage::mergeIntoSeoData(), used in
     * editPage() above).
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $lang
     * @param array{permalink: string, title: string, description: string,
     *     robots: string, priority: int, frequency: string} $values
     * @return void
     */
    private function routeToHomepageConfirmation(int $ref_id, int $secondary_id, string $lang, array $values): void
    {
        if ($this->seo->isPermalinkTaken("", $ref_id, $secondary_id)) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("homepage_already_exists"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
            return;
        }

        ilSeoPendingHomepage::stash(
            $ref_id,
            $secondary_id,
            $lang,
            $values["title"],
            $values["description"],
            $values["robots"],
            $values["priority"],
            $values["frequency"],
            $this->ctrl->getLinkTarget($this, self::CMD_SHOW)
        );

        $this->ctrl->setParameterByClass(ilSeoGUI::class, "ref_id", $ref_id);
        $this->ctrl->redirectToUrl(
            $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, ilSeoGUI::class], ilSeoGUI::CMD_CONFIRM_HOMEPAGE)
        );
    }

    /**
     * Whether a submitted mass-edit value is one the form actually offers. The
     * empty string means "leave unchanged" and is always accepted.
     * @param string $value submitted value
     * @param string[] $options the values the form declares
     * @return bool
     */
    private function isValidMassEditOption(string $value, array $options): bool
    {
        return $value === "" || in_array($value, $options, true);
    }

    /**
     * Whether a submitted mass-edit priority is a whole number inside the
     * form's range. The empty string means "leave unchanged"; the column is an
     * integer, so a fractional or signed value is rejected rather than
     * truncated.
     * @param string $priority submitted value
     * @return bool
     */
    private function isValidMassEditPriority(string $priority): bool
    {
        if ($priority === "") {
            return true;
        }

        if (!ctype_digit($priority)) {
            return false;
        }

        $value = (int) $priority;

        return $value >= ilSeoPriority::MIN && $value <= ilSeoPriority::MAX;
    }

    /**
     * Whether every submitted mass-edit field holds a value the form itself
     * offers. An empty value is always accepted: it is the "leave unchanged"
     * option.
     * @param string $index submitted value
     * @param string $follow submitted value
     * @param string $priority submitted value
     * @param string $frequency submitted value
     * @return bool
     */
    private function isValidMassEditRequest(
        string $index,
        string $follow,
        string $priority,
        string $frequency
    ): bool {
        return $this->isValidMassEditOption($index, self::MASS_EDIT_INDEX_OPTIONS)
            && $this->isValidMassEditOption($follow, self::MASS_EDIT_FOLLOW_OPTIONS)
            && $this->isValidMassEditOption($frequency, ilSeoFrequency::VALUES)
            && $this->isValidMassEditPriority($priority);
    }

    /**
     * Resolve the crawl directives to write for one row given the submitted
     * (possibly empty, meaning "leave unchanged") field values. Every column
     * gets a slot, so "leave unchanged" travels onwards as null.
     * @param array<string,mixed> $row current row (needs "robots")
     * @param string $index "index"/"noindex", or "" to leave unchanged
     * @param string $follow "follow"/"nofollow", or "" to leave unchanged
     * @param string $priority new priority, or "" to leave unchanged
     * @param string $frequency new frequency, or "" to leave unchanged
     * @return array{robots: string|null, priority: int|null, frequency:
     *     string|null}
     */
    private function resolveMassEditUpdates(
        array $row,
        string $index,
        string $follow,
        string $priority,
        string $frequency
    ): array {
        $robots = null;

        if ($index !== "" || $follow !== "") {
            // Read the kept directive by name, not position: swapping the two,
            // or giving only one, fills the wrong slot.
            [$current_index, $current_follow] = ilSeoRobots::directives(
                (string) ($row["robots"] ?? ilSeoRobots::DEFAULT_VALUE)
            );

            $robots = implode(",", [
                $index !== "" ? $index : $current_index,
                $follow !== "" ? $follow : $current_follow,
            ]);
        }

        return [
            "robots" => $robots,
            "priority" => $priority !== "" ? (int) $priority : null,
            "frequency" => $frequency !== "" ? $frequency : null,
        ];
    }

    /**
     * Apply the submitted mass-edit values to one selected row.
     * @param string $id row key as submitted, in encodeRowId() form
     * @param string $index "index"/"noindex", or "" to leave unchanged
     * @param string $follow "follow"/"nofollow", or "" to leave unchanged
     * @param string $priority new priority, or "" to leave unchanged
     * @param string $frequency new frequency, or "" to leave unchanged
     * @return bool false when the row no longer exists and was skipped
     */
    private function applyMassEditToRow(
        string $id,
        string $index,
        string $follow,
        string $priority,
        string $frequency
    ): bool {
        $row_id = ilSeoOverviewDataRetrieval::decodeRowId($id);

        $row = $this->seo->fetchById($row_id["ref_id"], $row_id["secondary_id"], $row_id["lang"]);

        if ($row === null) {
            // In the rendered table but matching no record now (deleted
            // concurrently, stale resubmit): skip it, do not fail the batch.
            return false;
        }

        $updates = $this->resolveMassEditUpdates($row, $index, $follow, $priority, $frequency);

        // Not save(): mass edit owns only crawl directives; a whole-row write
        // would append a history entry per row for an untouched permalink.
        $this->seo->updateCrawlDirectives(
            $row_id["ref_id"],
            $row_id["secondary_id"],
            $row_id["lang"],
            $updates["robots"],
            $updates["priority"],
            $updates["frequency"]
        );

        return true;
    }

    /**
     * Apply mass edit changes to selected pages.
     * @return void
     */
    protected function applyMassEdit(): void
    {
        $ids = $this->request->getIds();

        if (count($ids) === 0) {
            $this->tpl->setOnScreenMessage(
                "failure",
                $this->lng->txt("no_checkbox_selected"),
                true
            );
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        $index = $this->request->getMassEditIndex();
        $follow = $this->request->getMassEditFollow();
        $priority = $this->request->getMassEditPriority();
        $frequency = $this->request->getMassEditFrequency();

        // These values reach the public sitemap, so form constraints apply here
        // too; refused, not coerced, so a rewrite never hides.
        if (!$this->isValidMassEditRequest($index, $follow, $priority, $frequency)) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("form_input_not_valid"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        $skipped = 0;

        foreach ($ids as $id) {
            if (!$this->applyMassEditToRow($id, $index, $follow, $priority, $frequency)) {
                $skipped++;
            }
        }

        [$message_type, $message] = $skipped > 0
            ? ["info", sprintf($this->plugin->txt("mass_edit_rows_skipped"), $skipped)]
            : ["success", $this->lng->txt("saved_successfully")];

        $this->tpl->setOnScreenMessage($message_type, $message, true);

        $this->ctrl->redirect($this, self::CMD_SHOW);
    }
}
