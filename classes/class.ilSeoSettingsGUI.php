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
 * Settings screen of the SEO administration area: one Kitchen Sink form, split
 * into a general-settings section and a per-language section. Forwarded to from
 * ilSeoConfigGUI, which owns the page header and the tabs.
 *
 * Reached only through ilSeoConfigGUI, the plugin's classic configuration GUI
 * class: core's plugin list builds a Configure action for it again, so reaching
 * this screen first requires "read" on the plugin-administration node, checked
 * by ilObjComponentSettingsGUI before it ever forwards here. The plugin-side
 * gate is unchanged and asks for "write" on the administration root, enforced
 * twice (in ilSeoConfigGUI and again below).
 *
 * @ilCtrl_isCalledBy ilSeoSettingsGUI: ilSeoConfigGUI
 */
class ilSeoSettingsGUI implements ilCtrlSecurityInterface
{
    /** @var string show the Settings screen command */
    public const CMD_SHOW = "show";

    /** @var string save the settings command */
    public const CMD_SAVE = "save";

    /** @var string form key of the general-settings section */
    private const KEY_SETTINGS = "settings";

    /** @var string form key of the per-language section */
    private const KEY_LANGUAGES = "languages";

    /** @var string form key and input name of the website title */
    private const KEY_WEB_TITLE = "web_title";

    /** @var string form key and input name of the sitemap submission flag */
    private const KEY_SUBMIT_SITEMAP = "submit_sitemap";

    /** @var string form key of the default language, and the key ilSeoSettings stores it under */
    private const KEY_DEFAULT_LANG = "default";

    /** @var string key the normalised secondary languages are handed over under */
    private const KEY_SECONDARY_LANGS = "secondary";

    /** @var string input name of the default-language select */
    private const NAME_DEFAULT_LANG = "seo_default_lang";

    /** @var string input name prefix of the per-language activation checkboxes */
    private const NAME_ACTIVE_PREFIX = "seo_lang_";

    /** @var int bytes the website title accepts */
    private const WEB_TITLE_MAX_LENGTH = 100;

    /** @var ilCtrlInterface */
    protected ilCtrlInterface $ctrl;

    /** @var ilGlobalTemplateInterface */
    protected ilGlobalTemplateInterface $tpl;

    /** @var ilLanguage */
    protected ilLanguage $lng;

    /** @var \ILIAS\UI\Factory */
    protected \ILIAS\UI\Factory $factory;

    /** @var \ILIAS\UI\Renderer */
    protected \ILIAS\UI\Renderer $renderer;

    /** @var \ILIAS\HTTP\Services */
    protected \ILIAS\HTTP\Services $http;

    /** @var \ILIAS\Refinery\Factory */
    protected \ILIAS\Refinery\Factory $refinery;

    /** @var ilSeoPlugin */
    protected ilSeoPlugin $plugin;

    /** @var ilSeoSettings */
    protected ilSeoSettings $settings;

    /** @var ilSeoRewriteRules */
    protected ilSeoRewriteRules $rules;

    /**
     * Class constructor.
     * @return void
     */
    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->http = $DIC->http();
        $this->refinery = $DIC->refinery();

        $this->plugin = ilSeoPlugin::getInstance();
        $this->settings = new ilSeoSettings();
        $this->rules = new ilSeoRewriteRules($this->plugin);
    }

    /**
     * Handles commands. The page header and tabs are set by the forwarding
     * parent (ilSeoConfigGUI) before reaching here.
     *
     * Both commands require "write" on the administration root, the form render
     * included: it shows the installation's whole SEO configuration. The switch
     * is also the allowlist that makes $this->$cmd() safe.
     *
     * @throws ilException if command is not known
     * @return void
     */
    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd(self::CMD_SHOW);

        switch ($cmd) {
            case self::CMD_SHOW:
            case self::CMD_SAVE:
                ilSeoAccess::requireAdminWrite();
                break;

            default:
                throw new ilException("Unknown command: '$cmd'");
        }

        $this->$cmd();
    }

    /**
     * The GET commands ilCtrl must CSRF-protect: save() rewrites the general
     * SEO settings, the language configuration and the rewrite rules. A GET
     * carries no parsed body for the form to read, but that is the form's
     * doing; the rtoken only exists for classes declaring this, and without the
     * declaration a bare link would reach the command untouched.
     * @return string[]
     */
    public function getUnsafeGetCommands(): array
    {
        return [self::CMD_SAVE];
    }

    /**
     * Nothing opts out of POST protection: the only POST command here writes.
     * @return string[]
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    /**
     * Show the settings form.
     * @return void
     */
    public function show(): void
    {
        $this->tpl->setContent($this->renderer->render($this->initSettingsForm()));
    }

    /**
     * Save the settings: the two general ones and the language configuration,
     * in one request. A rejected input is reported by re-rendering the form,
     * which is the only way its inline errors survive; a redirect would drop
     * them.
     * @return void
     */
    public function save(): void
    {
        $form = $this->initSettingsForm()->withRequest($this->http->request());
        $data = $form->getData();

        if ($data === null) {
            $this->tpl->setContent($this->renderer->render($form));
            return;
        }

        $settings = (array) $data[self::KEY_SETTINGS];
        $languages = (array) $data[self::KEY_LANGUAGES];

        $this->settings->setWebTitle((string) $settings[self::KEY_WEB_TITLE]);
        $this->settings->setSubmitSitemap((bool) $settings[self::KEY_SUBMIT_SITEMAP]);
        $this->settings->setLanguages(
            (string) $languages[self::KEY_DEFAULT_LANG],
            (array) $languages[self::KEY_SECONDARY_LANGS]
        );

        if (!$this->rules->apply()) {
            // The three values above stay written: rules depend on the install
            // URL alone, so an unwritable file must not block a settings edit.
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_redirection"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
        }

        $this->tpl->setOnScreenMessage("success", $this->lng->txt("saved_successfully"), true);
        $this->ctrl->redirect($this, self::CMD_SHOW);
    }

    /**
     * The whole screen as one form. Form\Standard has no title of its own, so
     * each group of settings is a section and the sections carry the headings.
     * @return \ILIAS\UI\Component\Input\Container\Form\Standard
     */
    private function initSettingsForm(): \ILIAS\UI\Component\Input\Container\Form\Standard
    {
        return $this->factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, self::CMD_SAVE),
            [
                self::KEY_SETTINGS => $this->settingsSection(),
                self::KEY_LANGUAGES => $this->languagesSection(),
            ]
        );
    }

    /**
     * The general-settings section. withMaxLength() additionally validates
     * server side the limit it advertises to the browser.
     *
     * The website title is mandatory here, which the legacy form it replaces
     * let stay empty. Clearing it does not restore the installation URL the
     * getter names as its default: that default only answers for a key never
     * written, and an empty title is written, so every page then publishes an
     * empty og:site_name.
     *
     * The rule is the plugin's shared one rather than withRequired()'s
     * single-argument form. That default asks the raw posted string for one
     * byte, before any transformation runs, so a value of only spaces counts as
     * filled and is then stored as the empty string it trims down to.
     * @return \ILIAS\UI\Component\Input\Field\Section
     */
    private function settingsSection(): \ILIAS\UI\Component\Input\Field\Section
    {
        $field = $this->factory->input()->field();

        $web_title = $field->text(
            $this->plugin->txt("web_title"),
            sprintf($this->plugin->txt("web_title_info"), ILIAS_HTTP_PATH)
        )
            ->withMaxLength(self::WEB_TITLE_MAX_LENGTH)
            ->withRequired(true, ilSeoFormValidation::mandatory($this->plugin))
            ->withDedicatedName(self::KEY_WEB_TITLE)
            // A stored value over the limit makes withValue() throw, taking the
            // screen down: bytes, via mb_strcut(), not mb_substr().
            ->withValue(mb_strcut($this->settings->getWebTitle(), 0, self::WEB_TITLE_MAX_LENGTH));

        $submit_sitemap = $field->checkbox(
            $this->plugin->txt("submit_sitemap"),
            $this->plugin->txt("submit_sitemap_info")
        )
            ->withDedicatedName(self::KEY_SUBMIT_SITEMAP)
            ->withValue($this->settings->isSubmitSitemap());

        return $field->section(
            [
                self::KEY_WEB_TITLE => $web_title,
                self::KEY_SUBMIT_SITEMAP => $submit_sitemap,
            ],
            $this->plugin->txt("section_settings")
        );
    }

    /**
     * The per-language section: which installed language is the default, and
     * which of them the plugin publishes metadata for. The default is a select
     * rather than one radio per language, so the choice is exclusive by
     * construction instead of by a correction applied to the posted values
     * afterwards.
     *
     * Each checkbox carries that language's share of the configured pages as a
     * byline. The figure is display-only text: a read-only diagnostic must not
     * be rendered as a field the user appears able to edit, and a byline per
     * option is what a checkbox each gives that a single multi-select field
     * could not.
     * @return \ILIAS\UI\Component\Input\Field\Section
     */
    private function languagesSection(): \ILIAS\UI\Component\Input\Field\Section
    {
        $field = $this->factory->input()->field();
        $seo = new ilSeo();

        $counts = $seo->countByLanguage();
        $total = $seo->countDistinctPages();
        $secondary = $this->settings->getSecondaryLangs();

        $options = [];
        foreach (ilLanguage::_getInstalledLanguages() as $lang_key) {
            $options[$lang_key] = $this->lng->txt("meta_l_" . $lang_key);
        }

        // A select refuses a preset value outside its options, so an install
        // whose site default is missing would take this screen down.
        $default = ilSeoLanguage::getDefaultLang();
        if (!isset($options[$default])) {
            $default = (string) array_key_first($options);
        }

        $inputs = [
            self::KEY_DEFAULT_LANG => $field->select(
                $this->plugin->txt("seo_default_language"),
                $options,
                $this->plugin->txt("seo_default_language_info")
            )
                ->withRequired(true, ilSeoFormValidation::mandatory($this->plugin))
                ->withDedicatedName(self::NAME_DEFAULT_LANG)
                ->withValue($default),
        ];

        foreach ($options as $lang_key => $label) {
            $inputs[$lang_key] = $field->checkbox($label, $this->pagesByline($counts, $total, $lang_key))
                ->withDedicatedName(self::NAME_ACTIVE_PREFIX . $lang_key)
                ->withValue($lang_key === $default || in_array($lang_key, $secondary, true));
        }

        /** @var \ILIAS\UI\Component\Input\Field\Section $section */
        $section = $field->section(
            $inputs,
            $this->plugin->txt("section_languages"),
            $this->plugin->txt("section_languages_info")
        )
            ->withAdditionalTransformation($this->languageNormalisation());

        return $section;
    }

    /**
     * How many of the configured pages already carry metadata in one language,
     * and what share of the total that is. The default language's rows are
     * stored under the data layer's own sentinel rather than under its code,
     * which is what toDbLang() resolves here.
     * @param array<string,int> $counts pages per stored language code
     * @param int $total distinct configured pages across all languages
     * @param string $lang_key
     * @return string
     */
    private function pagesByline(array $counts, int $total, string $lang_key): string
    {
        $pages = (int) ($counts[ilSeoLanguage::toDbLang($lang_key)] ?? 0);
        $percent = $total > 0 ? (int) round(((float) $pages / (float) $total) * 100.0) : 0;

        return sprintf($this->plugin->txt("seo_lang_pages_info"), $pages, $total, $percent);
    }

    /**
     * Folds the section's raw values into the pair
     * ilSeoSettings::setLanguages() stores: the chosen default, and the active
     * languages with that default removed.
     *
     * This is also where "the default language is always active" holds. It
     * lives in the form rather than in the save path so that no reading of this
     * form can observe the invariant broken, and so that switching the default
     * onto a language whose own checkbox is clear stays an ordinary edit rather
     * than a rejected one. A section applies its own operations only once every
     * child has validated, so this never runs on partial values.
     * @return ILIAS\Refinery\Transformation
     */
    private function languageNormalisation(): ILIAS\Refinery\Transformation
    {
        return $this->refinery->custom()->transformation(
            static function (array $values): array {
                $default = (string) ($values[self::KEY_DEFAULT_LANG] ?? "");
                unset($values[self::KEY_DEFAULT_LANG]);

                $active = array_keys(array_filter($values));

                return [
                    self::KEY_DEFAULT_LANG => $default,
                    self::KEY_SECONDARY_LANGS => array_values(array_diff($active, [$default])),
                ];
            }
        );
    }
}
