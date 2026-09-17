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
 * Plugin GUI main class: the object-level SEO metadata editor and MetaBar quick
 * form.
 *
 * Named as a cmdClass under ilUIPluginRouterGUI, it runs its own commands,
 * which edit the SEO metadata of one repository object or one page inside it.
 * The plugin's administration screens (Overview, Configuration) do not route
 * through this class: they are reached via ilSeoConfigGUI, the classic
 * plugin-configuration GUI, forwarded to from ilObjComponentSettingsGUI
 * (Administration > Plugins > Seo > Configure). This plugin has no
 * Administration menu group of its own.
 *
 * @ilCtrl_isCalledBy ilSeoGUI: ilSeoUIHookGUI, ilUIPluginRouterGUI
 */
class ilSeoGUI implements ilCtrlBaseClassInterface, ilCtrlSecurityInterface
{
    /** @var string save quick form command */
    public const CMD_QUICK_SAVE = "quickSave";

    /** @var string show advanced seo metadata form */
    public const CMD_CONFIGURE = "configure";

    /** @var string save advanced seo metadata */
    public const CMD_SAVE = "save";

    /** @var string render the delete confirmation */
    public const CMD_DELETE_REQUEST = "delete";

    /** @var string delete metadata command; must equal doDelete()'s name, executeCommand() calls $this->$cmd() */
    public const CMD_DELETE = "doDelete";

    /** @var string show the homepage confirmation screen quickSave()/save()/ilSeoOverviewGUI::updatePage() all route to */
    public const CMD_CONFIRM_HOMEPAGE = "confirmHomepage";

    /** @var string commit the pending homepage save the confirmation screen offered */
    public const CMD_APPLY_HOMEPAGE = "applyHomepage";

    /** @var string GET param: its presence forces the object-level row instead of the page context's secondary_id */
    public const PARAM_SWITCH_TO_OBJECT = "seo_switch_object";

    /** @var ilCtrlInterface */
    protected ilCtrlInterface $ctrl;

    /** @var ilGlobalTemplateInterface */
    protected ilGlobalTemplateInterface $tpl;

    /** @var ilLanguage */
    protected ilLanguage $lng;

    /** @var ilDBInterface */
    protected ilDBInterface $db;

    /** @var ilTabsGUI */
    protected ilTabsGUI $tabs;

    /** @var ilSeoPlugin */
    protected ilSeoPlugin $plugin;

    /** @var ilSeo */
    protected ilSeo $seo;

    /** @var ilSeoMetadataFields */
    protected ilSeoMetadataFields $fields;

    /** @var ilSeoMetadataEditor */
    protected ilSeoMetadataEditor $editor;

    /** @var ilSeoInitialValues */
    protected ilSeoInitialValues $initial_values;

    /** @var ilSeoSettings */
    protected ilSeoSettings $settings;

    /**
     * GUI constructor
     */
    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->db = $DIC->database();
        $this->tabs = $DIC->tabs();

        $this->plugin = ilSeoPlugin::getInstance();
        $this->seo = new ilSeo();
        $this->fields = new ilSeoMetadataFields($this->plugin, $this->lng);
        $this->editor = new ilSeoMetadataEditor($this->plugin, $this->lng, $this->seo);
        $this->initial_values = new ilSeoInitialValues($this->seo);
        $this->settings = new ilSeoSettings();

        $this->lng->loadLanguageModule(ilSeoPlugin::PREFIX);
        $this->lng->loadLanguageModule("content");
    }

    /**
     * Delegates incoming commands: each resolves the object named in the
     * request and checks "write" on that object's ref_id. An actor who may edit
     * one course's SEO metadata is not thereby an administrator; the
     * administration screens are a separate class entirely (ilSeoConfigGUI),
     * gated on "write" on the administration root instead.
     *
     * The metabar slate reaches none of this: ilSeoMetaBarProvider calls
     * renderQuickForm() directly on every page render, so nothing added here
     * runs on that path.
     *
     * @throws ilException if the command is not known
     * @return void
     */
    public function executeCommand(): void
    {
        if (!$this->plugin->isActive()) {
            throw new ilException("Plugin not active.");
        }

        $cmd = $this->ctrl->getCmd();

        switch ($cmd) {
            case self::CMD_QUICK_SAVE:
            case self::CMD_CONFIGURE:
            case self::CMD_SAVE:
            case self::CMD_DELETE_REQUEST:
            case self::CMD_DELETE:
            case self::CMD_CONFIRM_HOMEPAGE:
            case self::CMD_APPLY_HOMEPAGE:
                $this->$cmd();
                break;

            case "":
                $this->redirectWithoutCommand();
                break;

            default:
                throw new ilException("Unknown command: '$cmd'");
        }
    }

    /**
     * Every command that changes stored state, whatever shape the browser
     * submits it in. ilCtrl decides POST vs GET from the "cmd=post" query
     * parameter, not the HTTP method, so a cross-site POST is judged against
     * this list and getSafePostCommands() never sees it. Both save forms post
     * to a getLinkTargetByClass() URL, which appends the rtoken only for a
     * command named here, so listing them protects them and keeps them working.
     *
     * Only this class's own commands belong here: this class dispatches no
     * other GUI's commands any more, so a request naming it as cmdClass can
     * only ever reach the command switch above, which knows only the commands
     * listed here.
     * @return string[]
     */
    public function getUnsafeGetCommands(): array
    {
        return [
            self::CMD_DELETE,
            self::CMD_QUICK_SAVE,
            self::CMD_SAVE,
            self::CMD_APPLY_HOMEPAGE,
        ];
    }

    /**
     * Empty by construction: this class builds no "cmd=post" form action, so no
     * command of its own ever reaches the POST branch.
     * @return string[]
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    /**
     * Landing for a request with no runnable command, which is what ilCtrl
     * produces by emptying the command when it rejects an unsafe-GET rtoken.
     *
     * getCmd() is deliberately called without a default: unlike
     * ilSeoOverviewGUI, this class owns no standalone screen of its own to fall
     * back to. Every one of its own commands either writes or renders an async
     * modal fragment for JS to consume, so the quiet degrade is a redirect back
     * to the object with a notice, the same landing every other failure path
     * here uses, rather than an error screen for a rejected token.
     * @throws ilException if the request carries no ref_id either
     * @return void
     */
    private function redirectWithoutCommand(): void
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has("ref_id")) {
            throw new ilException("No command given and no ref_id to return to.");
        }

        $ref_id = $query->retrieve("ref_id", $DIC->refinery()->kindlyTo()->int());
        $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("request_not_processed"), true);
        $this->ctrl->redirectToUrl(ilLink::_getLink($ref_id));
    }

    /**
     * Get the current language of the given object.
     * It depends on the object multilang settings and/or the user settings.
     * @param ilObject $object
     * @return string
     */
    protected function getCurrentLanguageOfObject($object): string
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        $refinery = $DIC->refinery();

        if ($query->has("transl")) {
            return $query->retrieve("transl", $refinery->kindlyTo()->string());
        }

        $available = $this->getAvailableLanguageCodes($object);
        $default = ilSeoLanguage::getDefaultLang();
        $user_lang = $this->lng->getLangKey();

        if (in_array($user_lang, $available, true)) {
            return $user_lang;
        }

        return in_array($default, $available, true) ? $default : ($available[0] ?? $default);
    }

    /**
     * Get the languages supported by the given object.
     * They depends on the object type and on its multilang settings.
     * @param ilObject $object
     * @return array<string,string> keyword => label
     */
    protected function getLanguagesOfObject($object): array
    {
        $result = [];
        foreach ($this->getAvailableLanguageCodes($object) as $key) {
            $result[$key] = $this->lng->txt("meta_l_" . $key);
        }
        return $result;
    }

    /**
     * Plugin's "seo_langs" config narrowed to the object's own ILIAS
     * translation languages; an empty translation list means translation was
     * never activated, so it is no restriction.
     * @param ilObject $object
     * @return string[] language codes
     */
    private function getAvailableLanguageCodes($object): array
    {
        $default = $this->settings->getDefaultLang();
        $secondary = $this->settings->getSecondaryLangs();
        $configured = array_values(array_unique(array_merge($default !== "" ? [$default] : [], $secondary)));

        $object_langs = array_keys(ilObjectTranslation::getInstance($object->getId())->getLanguages());
        if (empty($object_langs)) {
            return $configured;
        }

        $available = array_values(array_intersect($configured, $object_langs));
        if (!empty($available)) {
            return $available;
        }

        // No overlap with configured SEO languages: fall back to default so the
        // selector is never empty.
        return $default !== "" ? [$default] : [];
    }

    /**
     * Ensure that an existing object is in the request.
     * @throws ilException if: missing ref_id; not found; permission denied.
     * @return ilObject
     */
    protected function checkObjectFromRequest(): ilObject
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        $refinery = $DIC->refinery();

        if (!$query->has("ref_id")) {
            throw new ilException("Ref_id not set!");
        }
        $ref_id = $query->retrieve("ref_id", $refinery->kindlyTo()->int());

        $object = ilObjectFactory::getInstanceByRefId($ref_id);
        if ($object == null) {
            throw new ilObjectException("Object must exist!");
        }

        if (!ilSeoAccess::canEditObject($ref_id)) {
            throw new ilPermissionException("Permission denied");
        }

        return $object;
    }

    /**
     * The current request's natural secondary component (whatever page context
     * ilSeoMetaBarProvider itself resolved), plus whether this class's own
     * "edit the object instead" switch is active for this request.
     * presentationItem(), initAdvancedForm(), and save() all need the same
     * resolution so the preview, the form, and the save target always agree on
     * which row is being edited.
     * @param string $obj_type
     * @return array{0: ilSeoSecondaryComponent|null, 1: bool} [natural
     *     secondary component, switch active]
     */
    private function resolveEditTarget(string $obj_type): array
    {
        global $DIC;
        $query = $DIC->http()->wrapper()->query();

        $natural = $this->seo->getSecondaryComponentFromRequest($obj_type);
        $switched_to_object = $natural !== null && $query->has(self::PARAM_SWITCH_TO_OBJECT);

        return [$natural, $switched_to_object];
    }

    /**
     * The metabar quick form: the advanced form's own permalink, title and
     * description fields, as a page-level form posting to quickSave().
     * @throws LogicException if ref_id not set
     * @return ILIAS\UI\Component\Input\Container\Form\Standard
     */
    public function initQuickForm(): ILIAS\UI\Component\Input\Container\Form\Standard
    {
        global $DIC;

        $factory = $DIC->ui()->factory();

        $object = $this->checkObjectFromRequest();
        $ref_id = $object->getRefId();
        $obj_type = $object->getType();
        $lang = $this->getCurrentLanguageOfObject($object);

        $secondary_key = $this->seo->getSecondaryComponentFromRequest($obj_type);
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;
        $db_lang = ilSeoLanguage::toDbLang($lang);
        $seo_data = $this->seo->fetchById($ref_id, $secondary_value, $db_lang);
        // Overrides shown values on a pending draft; submitLabel() still reads
        // the real $seo_data, since a draft never means the row was saved.
        $form_seo_data = ilSeoPendingHomepage::mergeIntoSeoData($seo_data, $ref_id, $secondary_value, $db_lang);
        $values = $this->initial_values->initialValues($form_seo_data, $object, $secondary_value, $db_lang);

        $this->ctrl->setParameterByClass(self::class, "ref_id", $ref_id);
        if ($secondary_key != null) {
            $this->ctrl->setParameterByClass(self::class, $secondary_key->key, $secondary_value);
        }
        // In the action, so quickSave() gets the resolved language even when
        // the page was loaded with ?transl=<lang>.
        $this->ctrl->setParameterByClass(self::class, "transl", $lang);

        return $factory->input()->container()->form()->standard(
            $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_QUICK_SAVE),
            [
                $this->fields->permalinkInput($values),
                $this->fields->titleInput($values),
                $this->fields->descriptionInput($values),
            ]
        )->withSubmitLabel($this->fields->submitLabel($seo_data));
    }

    /**
     * The whole metabar slate: an item naming the edited page and stating its
     * SEO status, the advanced-modal trigger as that item's main action, and
     * the quick form below.
     *
     * Collected while the page is still being built (MetaBarMainCollector runs
     * before the page renderer writes its script and onload-code block), so the
     * Kitchen Sink renderer's own asset registration still reaches the page.
     * The wrapper element is what templates/css/seo-slate.css scopes on: the
     * markup inside it is Kitchen Sink's own, shared with every other form on
     * the page.
     * @throws LogicException if ref_id not set
     * @return string html
     */
    public function renderQuickForm(): string
    {
        global $DIC;

        $renderer = $DIC->ui()->renderer();

        $object = $this->checkObjectFromRequest();
        $secondary_key = $this->seo->getSecondaryComponentFromRequest($object->getType());
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;
        $db_lang = ilSeoLanguage::toDbLang($this->getCurrentLanguageOfObject($object));

        $this->injectSlateAssets();

        // Before advancedTrigger(): the async URL inherits the ref_id,
        // secondary key and transl the form action just primed on ilCtrl.
        $form = $this->initQuickForm();
        [$modal, $trigger] = $this->advancedTrigger();

        $components = [
            $this->editor->head($object->getRefId(), $secondary_value, $db_lang, $object)->withMainAction($trigger),
        ];
        if (ilSeoPendingHomepage::isPendingFor($object->getRefId(), $secondary_value, $db_lang)) {
            $components[] = $this->editor->pendingHomepageNotice();
        }
        $components[] = $form;
        $components[] = $modal;

        $html = $renderer->render($components);

        return '<div class="seo-slate">' . $html . '</div>';
    }

    /**
     * The advanced modal and the button opening it: the modal is empty until
     * its async URL fills it, and the button carries the show signal. A
     * standard button, not a shy one, because Item\Standard::withMainAction()
     * accepts only a standard button or a link.
     * @return array{0: ILIAS\UI\Component\Modal\RoundTrip, 1:
     *     ILIAS\UI\Component\Button\Standard}
     */
    private function advancedTrigger(): array
    {
        global $DIC;

        $factory = $DIC->ui()->factory();

        $modal = $factory->modal()->roundtrip($this->plugin->txt("seo_edit"), [])
            ->withCancelButtonLabel($this->lng->txt("close"))
            ->withOnLoadCode(function ($id) {
                // Force the modal out of the meta bar.
                return "document.getElementById('mainspacekeeper').appendChild(document.getElementById('{$id}'));";
            });

        $this->ctrl->setParameterByClass(self::class, "replaceSignal", $modal->getReplaceSignal()->getId());
        $modal = $modal->withAsyncRenderUrl(
            $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_CONFIGURE)
        );

        $button = $factory->button()->standard($this->plugin->txt("advanced_settings"), "#")
            ->withOnClick($modal->getShowSignal());

        return [$modal, $button];
    }

    /**
     * Save quick edit form.
     * @throws ilPermissionException if ref_id writable by user
     * @return void
     */
    public function quickSave(): void
    {
        global $DIC;

        $object = $this->checkObjectFromRequest();
        $ref_id = $object->getRefId();

        if (!ilSeoAccess::canEditObject($ref_id)) {
            throw new ilPermissionException("Permission denied.");
        }

        $obj_type = $object->getType();
        $secondary_component = $this->seo->getSecondaryComponentFromRequest($obj_type);
        $secondary_value = $secondary_component !== null ? $secondary_component->value : 0;

        $page_url = $this->editedPageUrl($object, $secondary_value);

        $form = $this->initQuickForm()->withRequest($DIC->http()->request());
        $data = $form->getData();

        if ($data === null) {
            $this->rejectSave($page_url, ilSeoFormValidation::failureMessage($form->getInputs(), $this->plugin));
            return;
        }

        ilSession::clear("seo_alerts");

        // Positional: [0]=permalink [1]=title [2]=description; validating a
        // possibly-empty permalink is now this method's job, not the field.
        $lang = ilSeoLanguage::toDbLang($this->getCurrentLanguageOfObject($object));
        $permalink = trim((string) ($data[0] ?? ""));
        $title = trim((string) ($data[1] ?? ""));
        $description = trim((string) ($data[2] ?? ""));

        if (ilSeoPermalink::isReserved($permalink)) {
            $this->rejectSave($page_url, $this->plugin->txt("permalink_reserved"));
            return;
        }

        // Fetch existing data to preserve robots/priority/frequency.
        $existing = $this->seo->fetchById($ref_id, $secondary_value, $lang);
        $robots = $existing["robots"] ?? ilSeoRobots::DEFAULT_VALUE;
        $priority = ilSeoPriority::normalize((int) ($existing["priority"] ?? ilSeoPriority::DEFAULT_VALUE));
        $frequency = ilSeoFrequency::normalize((string) ($existing["frequency"] ?? ""));

        if ($permalink === "") {
            $this->routeToHomepageConfirmation(
                $ref_id,
                $secondary_value,
                $lang,
                $title,
                $description,
                $robots,
                $priority,
                $frequency,
                $page_url
            );
            return;
        }

        $result = $this->seo->save(
            $ref_id,
            $secondary_value,
            $lang,
            $permalink,
            $title,
            $description,
            $robots,
            $priority,
            $frequency,
            false
        );

        if (!$result) {
            $this->rejectSave($page_url, $this->plugin->txt("permalink_already_exists"));
            return;
        }

        ilSeoPendingHomepage::clearIfMatches($ref_id, $secondary_value, $lang);
        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("metadata_saved"), true);
        $this->ctrl->redirectToUrl($page_url);
    }

    /**
     * Where a save or a delete lands: the page whose SEO row it acted on, never
     * the object holding that page. On the object's own screen the metabar
     * resolves no secondary_id, so a retry started there would silently write
     * the object's row instead of the page's.
     *
     * Rebuilt from the ref_id and secondary_id of the edited row, so it names
     * the right page but not necessarily the exact view it was opened from.
     * Incidental query context such as a forum thread's paging is not
     * reproduced (see AbstractHandler::pageUrl(), which also explains why no
     * language rides along). A row that is the object's own resolves to the
     * object's permanent link: there is no page below it to return to.
     * @param ilObject $object
     * @param int $secondary_value secondary_id of the edited row, 0 for the
     *     object itself
     * @return string
     */
    private function editedPageUrl(ilObject $object, int $secondary_value): string
    {
        return HandlerFactory::forType($object->getType())
            ->pageUrl($object->getRefId(), $secondary_value);
    }

    /**
     * Report a rejected save and send the user back to the page they were
     * editing. Neither form can re-render itself in place (both are embedded in
     * a core-owned page), so the on-screen message is the only way the reason
     * reaches the user.
     * @param string $page_url as resolved by editedPageUrl()
     * @param string $message
     * @return void
     */
    private function rejectSave(string $page_url, string $message): void
    {
        $this->tpl->setOnScreenMessage("failure", $message, true);
        $this->ctrl->redirectToUrl($page_url);
    }

    /**
     * Where an empty, syntactically valid permalink lands instead of a save.
     * All three save paths call this: uniqueness is tested first, so only a
     * free permalink reaches the confirmation screen, reusing
     * "homepage_already_exists" for a collision. The pending row travels in
     * session (ilSeoPendingHomepage), since the confirmation screen has no form
     * to carry hidden fields.
     * @param int $ref_id
     * @param int $secondary_value
     * @param string $lang
     * @param string $title
     * @param string $description
     * @param string $robots
     * @param int $priority
     * @param string $frequency
     * @param string $return_url where the confirm/cancel actions lead back to
     * @return void
     */
    private function routeToHomepageConfirmation(
        int $ref_id,
        int $secondary_value,
        string $lang,
        string $title,
        string $description,
        string $robots,
        int $priority,
        string $frequency,
        string $return_url
    ): void {
        if ($this->seo->isPermalinkTaken("", $ref_id, $secondary_value)) {
            $this->rejectSave($return_url, $this->plugin->txt("homepage_already_exists"));
            return;
        }

        ilSeoPendingHomepage::stash(
            $ref_id,
            $secondary_value,
            $lang,
            $title,
            $description,
            $robots,
            $priority,
            $frequency,
            $return_url
        );

        $this->ctrl->setParameterByClass(self::class, "ref_id", $ref_id);
        $this->ctrl->redirectToUrl(
            $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_CONFIRM_HOMEPAGE)
        );
    }

    /**
     * The ref_id confirmHomepage()/applyHomepage() may act on, or null when
     * there is none. Never throws: both are reached by a redirect the plugin
     * itself generated, so a stale, malformed or unauthorized request gets the
     * same graceful landing as an expired draft, not an exception page.
     *
     * Permission is ilSeoAccess::canConfirmHomepage()'s union, not the stricter
     * canEditObject()-only gate checkObjectFromRequest() uses elsewhere in this
     * class: the screen only confirms a save either origin (quickSave()/save()
     * or ilSeoOverviewGUI::updatePage()) could already perform unconfirmed, so
     * it must accept either origin's own authority, not the intersection of
     * both.
     * @return int|null
     */
    private function resolveHomepageConfirmationRefId(): ?int
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has("ref_id")) {
            return null;
        }
        $ref_id = $query->retrieve("ref_id", $DIC->refinery()->kindlyTo()->int());

        if (ilObjectFactory::getInstanceByRefId($ref_id, false) === null) {
            return null;
        }

        return ilSeoAccess::canConfirmHomepage($ref_id) ? $ref_id : null;
    }

    /**
     * Where confirmHomepage()/applyHomepage() both land when there is nothing
     * this actor may confirm: a stale link, an already-applied or
     * already-abandoned draft, a draft for a different ref_id, or a ref_id this
     * actor may not act on. One message for every case deliberately:
     * distinguishing "expired" from "not authorized" would tell an unauthorized
     * visitor a draft exists for a page they cannot touch.
     * @param int|null $ref_id resolved target, null when the request named none
     *     at all
     * @return void
     */
    private function landNoHomepageConfirmation(?int $ref_id): void
    {
        $this->tpl->setOnScreenMessage("info", $this->plugin->txt("homepage_confirm_expired"), true);
        $this->ctrl->redirectToUrl(
            $ref_id !== null ? ilLink::_getLink($ref_id) : ilLink::_getLink(ROOT_FOLDER_ID)
        );
    }

    /**
     * The homepage confirmation screen itself: a real navigable page, not a
     * modal, since the submit reaching here is already a native, full-page POST
     * navigation.
     * @return void
     */
    public function confirmHomepage(): void
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        $ref_id = $this->resolveHomepageConfirmationRefId();
        $pending = $ref_id !== null ? ilSeoPendingHomepage::peek() : null;

        if ($ref_id === null || $pending === null || (int) ($pending["ref_id"] ?? null) !== $ref_id) {
            $this->landNoHomepageConfirmation($ref_id);
            return;
        }

        $object = ilObjectFactory::getInstanceByRefId($ref_id, false);
        $title = $object !== null ? $object->getTitle() : "#" . $ref_id;

        $this->ctrl->setParameterByClass(self::class, "ref_id", $ref_id);
        $confirm_url = $this->ctrl->getLinkTargetByClass(
            [ilUIPluginRouterGUI::class, self::class],
            self::CMD_APPLY_HOMEPAGE
        );

        $message = $factory->messageBox()->confirmation(
            sprintf($this->plugin->txt("homepage_confirm_message"), ilSeoEscape::html($title))
        );

        $html = $renderer->render([
            $message,
            $factory->button()->primary($this->plugin->txt("homepage_confirm_action"), $confirm_url),
            $factory->button()->standard($this->lng->txt("cancel"), (string) $pending["return_url"]),
        ]);

        $this->tpl->setTitle($this->plugin->txt("homepage_confirm_title"));
        $this->tpl->setContent($html);
        $this->tpl->printToStdout();
    }

    /**
     * Commit the pending homepage save the confirmation screen offered.
     * Declining never reaches this method at all: the Cancel button on
     * confirmHomepage() is a plain link back to the pending draft's own
     * return_url, so the draft simply stays in session until the admin reopens
     * the same edit and it resurfaces as that form's initial values (see
     * ilSeoPendingHomepage::mergeIntoSeoData()).
     *
     * Re-tests uniqueness rather than trusting confirmHomepage()'s own check:
     * time passed on a screen the admin had to read, and another admin could
     * have claimed the empty permalink in between.
     * @return void
     */
    public function applyHomepage(): void
    {
        $ref_id = $this->resolveHomepageConfirmationRefId();
        $pending = $ref_id !== null ? ilSeoPendingHomepage::peek() : null;

        if ($ref_id === null || $pending === null || (int) ($pending["ref_id"] ?? null) !== $ref_id) {
            $this->landNoHomepageConfirmation($ref_id);
            return;
        }

        $secondary_value = (int) $pending["secondary_id"];
        $lang = (string) $pending["lang"];
        $return_url = (string) $pending["return_url"];

        if ($this->seo->isPermalinkTaken("", $ref_id, $secondary_value)) {
            ilSeoPendingHomepage::clear();
            $this->rejectSave($return_url, $this->plugin->txt("homepage_already_exists"));
            return;
        }

        $result = $this->seo->save(
            $ref_id,
            $secondary_value,
            $lang,
            "",
            (string) $pending["title"],
            (string) $pending["description"],
            (string) $pending["robots"],
            (int) $pending["priority"],
            (string) $pending["frequency"],
            true
        );

        ilSeoPendingHomepage::clear();

        if (!$result) {
            // Only reachable if the race the check above guards against still
            // lost: another save claimed the empty permalink meanwhile.
            $this->rejectSave($return_url, $this->plugin->txt("homepage_already_exists"));
            return;
        }

        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("metadata_saved"), true);
        $this->ctrl->redirectToUrl($return_url);
    }

    /**
     * Init advanced edit form.
     * @throws LogicException if ref_id not set
     * @return ILIAS\UI\Component\Input\Container\Form\Standard
     */
    public function initAdvancedForm(): ILIAS\UI\Component\Input\Container\Form\Standard
    {
        global $DIC;

        $factory = $DIC->ui()->factory();

        $object = $this->checkObjectFromRequest();
        $ref_id = $object->getRefId();
        $obj_type = $object->getType();

        [$natural_secondary, $switched_to_object] = $this->resolveEditTarget($obj_type);
        $secondary_key = $switched_to_object ? null : $natural_secondary;
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;

        $db_lang = ilSeoLanguage::toDbLang($this->getCurrentLanguageOfObject($object));
        $seo_data = $this->seo->fetchById($ref_id, $secondary_value, $db_lang);
        // See initQuickForm(): submitLabel() below still reads the real
        // $seo_data; only shown field values are overridden by the draft.
        $form_seo_data = ilSeoPendingHomepage::mergeIntoSeoData($seo_data, $ref_id, $secondary_value, $db_lang);
        $values = $this->initial_values->initialValues($form_seo_data, $object, $secondary_value, $db_lang);

        $this->ctrl->saveParameterByClass(self::class, "ref_id");

        // The natural key is always propagated, even under the switch override,
        // matching presentationItem(), so both stay consistent either order.
        if ($natural_secondary !== null) {
            $this->ctrl->setParameterByClass(self::class, $natural_secondary->key, $natural_secondary->value);
        }

        // Carries the "edit the object" switch to this form POST action, so
        // save() matches what the form and preview showed.
        if ($switched_to_object) {
            $this->ctrl->setParameterByClass(self::class, self::PARAM_SWITCH_TO_OBJECT, "1");
        }

        // Set resolved language explicitly: saveParameterByClass is unreliable
        // when transl is set in the async configure URL.
        $this->ctrl->setParameterByClass(self::class, "transl", $this->getCurrentLanguageOfObject($object));

        return $factory->input()->container()->form()->standard(
            $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_SAVE),
            $this->editor->inputs($values, $form_seo_data)
        )->withSubmitLabel($this->fields->submitLabel($seo_data));
    }

    /**
     * Show the advanced SEO metadata form for the requested object or page.
     * @return never
     */
    public function configure(): never
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        $query = $DIC->http()->wrapper()->query();

        if (!$query->has("replaceSignal")) {
            exit;
        }

        $this->ctrl->saveParameterByClass(self::class, "replaceSignal");
        $this->ctrl->saveParameterByClass(self::class, "ref_id");
        // Forward the language context so the advanced form POST URL carries
        // transl.
        $this->ctrl->saveParameterByClass(self::class, "transl");

        $item = $this->presentationItem();
        $form = $this->initAdvancedForm();

        // Same resolution as initAdvancedForm(), only to test for a pending
        // draft; the already-built form cannot be influenced by it.
        $object = $this->checkObjectFromRequest();
        [$natural_secondary, $switched_to_object] = $this->resolveEditTarget($object->getType());
        $secondary_key = $switched_to_object ? null : $natural_secondary;
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;
        $db_lang = ilSeoLanguage::toDbLang($this->getCurrentLanguageOfObject($object));

        $content = ilSeoPendingHomepage::isPendingFor($object->getRefId(), $secondary_value, $db_lang)
            ? [$this->editor->pendingHomepageNotice(), $item]
            : $item;

        // The modal renders its own submit button, not the form, so it must be
        // told the label.
        $modal = $factory->modal()->roundtrip(
            $this->plugin->txt("seo_edit"),
            $content,
            $form->getInputs(),
            $form->getPostURL()
        )->withSubmitLabel($form->getSubmitLabel() ?? $this->lng->txt("save"));

        echo $renderer->renderAsync($modal);
        exit;
    }

    /**
     * The preview item's actions dropdown: language options (when more than
     * one) plus the object/page switch (when a natural secondary_id exists for
     * the page context), merged into one dropdown since
     * Item\Standard::withActions() accepts only one. Extracted out of
     * presentationItem() to keep its complexity down.
     * @param ilObject $object
     * @param string $obj_type
     * @param ilSeoSecondaryComponent|null $natural_secondary the page context's
     *     own secondary_id, ignoring the switch override; null means none
     *     exists for this view (e.g. an object-level list), so nothing
     *     unambiguous to switch to
     * @param bool $switched_to_object whether the switch override is currently
     *     active
     * @param ILIAS\UI\Implementation\Component\ReplaceSignal $replace_signal
     * @return ILIAS\UI\Component\Dropdown\Standard|null
     */
    private function buildActionsDropdown(
        ilObject $object,
        string $obj_type,
        ?ilSeoSecondaryComponent $natural_secondary,
        bool $switched_to_object,
        ILIAS\UI\Implementation\Component\ReplaceSignal $replace_signal
    ): ?ILIAS\UI\Component\Dropdown\Standard {
        global $DIC;
        $factory = $DIC->ui()->factory();
        $query = $DIC->http()->wrapper()->query();
        $refinery = $DIC->refinery();

        // Preserved and restored after the loop: setParameterByClass() inside
        // it leaves ctrl tracked "transl" on whichever language ran last.
        $current_transl = $query->has("transl")
            ? $query->retrieve("transl", $refinery->kindlyTo()->string())
            : null;

        $languages = $this->getLanguagesOfObject($object);
        $has_language_options = count($languages) > 1;
        $options = [];

        foreach ($has_language_options ? $languages : [] as $lang_key => $label) {
            $this->ctrl->setParameterByClass(self::class, "transl", $lang_key);
            $options[] = $factory->button()->shy($label, "#")->withOnClick(
                $replace_signal->withAsyncRenderUrl(
                    $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_CONFIGURE)
                )
            );
        }
        if ($has_language_options) {
            $this->ctrl->setParameterByClass(self::class, "transl", $current_transl);
        }

        if ($natural_secondary !== null) {
            $switch_label = $switched_to_object
                ? sprintf($this->plugin->txt("advanced_switch_to_page"), $this->plugin->txt("advanced_content_type_{$obj_type}"))
                : sprintf($this->plugin->txt("advanced_switch_to_object"), $this->lng->txt("obj_{$obj_type}"));

            // Flip the override for this one link (it links to the other
            // state), then restore it so both keep the same target.
            $this->ctrl->setParameterByClass(self::class, self::PARAM_SWITCH_TO_OBJECT, $switched_to_object ? null : "1");
            $options[] = $factory->button()->shy($switch_label, "#")->withOnClick(
                $replace_signal->withAsyncRenderUrl(
                    $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_CONFIGURE)
                )
            );
            $this->ctrl->setParameterByClass(self::class, self::PARAM_SWITCH_TO_OBJECT, $switched_to_object ? "1" : null);
        }

        if ($options === []) {
            return null;
        }

        // "Switch language" only when that is genuinely the only option; a
        // generic label once the object/page switch is in the same menu.
        $label = ($has_language_options && $natural_secondary === null)
            ? $this->lng->txt("switch_language")
            : $this->plugin->txt("advanced_actions_label");

        return $factory->dropdown()->standard($options)->withLabel($label);
    }

    /**
     * SEO presentation of the object for the modal: the shared head, plus the
     * actions only the modal offers (the language/target dropdown and the
     * delete of the shown row).
     * @return ILIAS\UI\Component\Item\Standard
     */
    public function presentationItem(): ILIAS\UI\Component\Item\Standard
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $query = $DIC->http()->wrapper()->query();
        $refinery = $DIC->refinery();

        $object = $this->checkObjectFromRequest();
        $obj_type = $object->getType();

        [$natural_secondary, $switched_to_object] = $this->resolveEditTarget($obj_type);
        $secondary_key = $switched_to_object ? null : $natural_secondary;
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;
        // The natural key stays propagated under the switch override, so links
        // below track this modal page; the flag is set separately.
        if ($natural_secondary !== null) {
            $this->ctrl->setParameterByClass(self::class, $natural_secondary->key, $natural_secondary->value);
        }
        if ($switched_to_object) {
            $this->ctrl->setParameterByClass(self::class, self::PARAM_SWITCH_TO_OBJECT, "1");
        }

        $lang = $query->has("transl")
            ? $query->retrieve("transl", $refinery->kindlyTo()->string())
            : "-";

        $signal_id = $query->retrieve("replaceSignal", $refinery->kindlyTo()->string());
        $replace_signal = new ILIAS\UI\Implementation\Component\ReplaceSignal($signal_id);

        $db_lang = ilSeoLanguage::toDbLang($lang);
        $item = $this->editor->head($object->getRefId(), $secondary_value, $db_lang, $object);

        $actions = $this->buildActionsDropdown($object, $obj_type, $natural_secondary, $switched_to_object, $replace_signal);
        if ($actions !== null) {
            $item = $item->withActions($actions);
        }

        if ($this->seo->fetchById($object->getRefId(), $secondary_value, $db_lang) !== null) {
            $delete_button = $factory->button()->standard($this->lng->txt("delete"), "#")
                ->withOnClick(
                    $replace_signal->withAsyncRenderUrl(
                        $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_DELETE_REQUEST)
                    )
                );
            $item = $item->withMainAction($delete_button);
        }

        return $item;
    }

    /**
     * Save the advanced SEO metadata form.
     * @return void
     */
    public function save(): void
    {
        global $DIC;
        $factory = $DIC->ui()->factory();
        $request = $DIC->http()->request();

        if ("POST" !== $request->getMethod()) {
            throw new ilFormException("Not a POST request. Cannot save.");
        }

        $object = $this->checkObjectFromRequest();
        $ref_id = $object->getRefId();
        $obj_type = $object->getType();

        // Same resolution as presentationItem()/initAdvancedForm(): the switch
        // param feeds this form POST, so save() must match what was shown.
        [, $switched_to_object] = $this->resolveEditTarget($obj_type);
        $secondary_key = $switched_to_object ? null : $this->seo->getSecondaryComponentFromRequest($obj_type);
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;
        $page_url = $this->editedPageUrl($object, $secondary_value);

        $query = $DIC->http()->wrapper()->query();
        $refinery = $DIC->refinery();
        // Normalized immediately: this local $lang is only ever used for the
        // save() DB call below, never for display.
        $lang = ilSeoLanguage::toDbLang(
            $query->has("transl")
                ? $query->retrieve("transl", $refinery->kindlyTo()->string())
                : "-"
        );

        $modal = $factory->modal()->roundtrip("", null, $this->initAdvancedForm()->getInputs())
            ->withRequest($request);
        $data = $modal->getData();

        if ($data === null) {
            $this->rejectSave($page_url, ilSeoFormValidation::failureMessage($modal->getInputs(), $this->plugin));
            return;
        }

        // Fetch existing data so decode() can preserve priority/frequency while
        // the index group is off, instead of resetting them to defaults.
        $existing = $this->seo->fetchById($ref_id, $secondary_value, $lang);
        $values = $this->editor->decode($data, $existing);

        if (ilSeoPermalink::isReserved($values["permalink"])) {
            $this->rejectSave($page_url, $this->plugin->txt("permalink_reserved"));
            return;
        }

        if ($values["permalink"] === "") {
            $this->routeToHomepageConfirmation(
                $ref_id,
                $secondary_value,
                $lang,
                $values["title"],
                $values["description"],
                $values["robots"],
                $values["priority"],
                $values["frequency"],
                $page_url
            );
            return;
        }

        $result = $this->seo->save(
            $ref_id,
            $secondary_value,
            $lang,
            $values["permalink"],
            $values["title"],
            $values["description"],
            $values["robots"],
            $values["priority"],
            $values["frequency"],
            false
        );

        if (!$result) {
            $this->rejectSave($page_url, $this->plugin->txt("permalink_already_exists"));
            return;
        }

        ilSeoPendingHomepage::clearIfMatches($ref_id, $secondary_value, $lang);
        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("metadata_saved"), true);
        $this->ctrl->redirectToUrl($page_url);
    }

    /**
     * Request the deletion of SEO metadata.
     *
     * PARAM_SWITCH_TO_OBJECT is propagated like ref_id and transl:
     * presentationItem() builds the link to this command with the switch state
     * it was rendering, and doDelete() resolves its target from the same
     * parameter, so without carrying it here a confirmed object-level deletion
     * would delete the page-level row instead.
     * @return never
     */
    public function delete(): never
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        $query = $DIC->http()->wrapper()->query();
        $refinery = $DIC->refinery();

        if (!$query->has("replaceSignal")) {
            exit;
        }

        $object = $this->checkObjectFromRequest();

        $this->ctrl->saveParameterByClass(self::class, "replaceSignal");
        $this->ctrl->saveParameterByClass(self::class, "ref_id");
        $this->ctrl->saveParameterByClass(self::class, self::PARAM_SWITCH_TO_OBJECT);

        if ($query->has("transl")) {
            $this->ctrl->saveParameterByClass(self::class, "transl");
        }

        $obj_type = $object->getType();

        $secondary_key = $this->seo->getSecondaryComponentFromRequest($obj_type);
        $secondary_value = $secondary_key !== null ? $secondary_key->value : 0;

        if ($secondary_key != null) {
            $this->ctrl->setParameterByClass(self::class, $secondary_key->key, $secondary_value);
        }

        $signal_id = $query->retrieve("replaceSignal", $refinery->kindlyTo()->string());
        $replace_signal = new ILIAS\UI\Implementation\Component\ReplaceSignal($signal_id);

        $modal = $factory->modal()->roundtrip(
            $this->plugin->txt("reset_metadata"),
            [
                $factory->button()->shy($this->lng->txt("back"), "#")
                    ->withOnClick(
                        $replace_signal->withAsyncRenderUrl(
                            $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_CONFIGURE)
                        )
                    ),
                $factory->messageBox()->confirmation(sprintf(
                    $this->plugin->txt("reset_metadata_message"),
                    ilSeoEscape::html($this->displayLanguage())
                )),
            ]
        )
            ->withActionButtons([
                $factory->button()->primary(
                    $this->lng->txt("delete"),
                    $this->ctrl->getLinkTargetByClass([ilUIPluginRouterGUI::class, self::class], self::CMD_DELETE)
                ),
            ]);

        echo $renderer->renderAsync($modal);
        exit;
    }

    /**
     * Delete the SEO metadata of exactly the row the modal was showing: one
     * language of one page, or of the object itself while the object/page
     * switch is active.
     *
     * The target is resolved the same way save() resolves it, so the delete and
     * the save offered in the same modal always act on the same row. The scope
     * stays per-language, unlike the Site Structure's own delete action: there
     * the unit is a node, which has no language at all, while this modal edits
     * one language and names it.
     * @throws ilPermissionException if ref_id not writable by user
     * @return void
     */
    public function doDelete(): void
    {
        $object = $this->checkObjectFromRequest();
        $ref_id = $object->getRefId();
        $obj_type = $object->getType();

        [, $switched_to_object] = $this->resolveEditTarget($obj_type);
        $secondary_component = $switched_to_object ? null : $this->seo->getSecondaryComponentFromRequest($obj_type);
        $secondary_value = $secondary_component !== null ? $secondary_component->value : 0;

        $display_lang = $this->displayLanguage();
        // Same landing rule as a save: the page whose metadata was just deleted
        // is where a user re-adds it.
        $page_url = $this->editedPageUrl($object, $secondary_value);
        $this->seo->delete($ref_id, $secondary_value, ilSeoLanguage::toDbLang($display_lang));

        $this->tpl->setOnScreenMessage(
            "success",
            sprintf($this->plugin->txt("metadata_deleted"), ilSeoEscape::html($display_lang)),
            true
        );
        $this->ctrl->redirectToUrl($page_url);
    }

    /**
     * The language this request acts on, as the code to show a user: the
     * "transl" parameter when present, otherwise the site's SEO default, which
     * is what the "-" sentinel stands for in the data rows. toDbLang() maps it
     * back for a DB call.
     * @return string
     */
    private function displayLanguage(): string
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has("transl")) {
            return ilSeoLanguage::getDefaultLang();
        }

        $transl = $query->retrieve("transl", $DIC->refinery()->kindlyTo()->string());

        return $transl === "-" ? ilSeoLanguage::getDefaultLang() : $transl;
    }

    /**
     * The slate's own stylesheet, plus the permalink field's client-side
     * sanitiser.
     *
     * Full-page render only: an async request's addCss() is discarded. The
     * stylesheet is the slate's alone. It hides a doubled submit button inside
     * the .seo-slate wrapper, which no other screen renders.
     * @return void
     */
    private function injectSlateAssets(): void
    {
        $this->tpl->addCss(ilSeoPlugin::PLUGIN_DIR . "/templates/css/seo-slate.css");

        ilSeoMetadataFields::injectPermalinkAssets($this->tpl);
    }
}
