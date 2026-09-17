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
 * The three inputs every SEO form carries (permalink, title, description), plus
 * the label its submit button gets and the script the permalink field binds to.
 * One builder per field, so no form can drift apart from another on a field's
 * rules, its byline or its wording.
 *
 * The metabar quick form takes these three on their own; ilSeoMetadataEditor
 * adds the index group and the follow checkbox on top for the two full editors.
 *
 * Deliberately not a GUI class and not reachable through ilCtrl: it constructs
 * Kitchen Sink components from values it is handed and touches neither the
 * request nor ilCtrl's parameter state, which is what keeps it usable from any
 * form regardless of the order in which the caller primed its links.
 */
class ilSeoMetadataFields
{
    /** @var int bytes the permalink field accepts, the column's own width; public because ilSeoInitialValues fits its suggestion to it */
    public const PERMALINK_MAX_LENGTH = 255;

    /** @var int bytes the title field accepts, and the ceiling any preset title is cut to */
    private const TITLE_MAX_LENGTH = 250;

    /** @var int bytes the description field accepts; public because ilSeoInitialValues cuts its seed to it */
    public const DESCRIPTION_MAX_LENGTH = 250;

    /** @var string the permalink field's own dedicated name */
    public const PERMALINK_KEY = "permalink";

    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /** @var ilLanguage */
    private ilLanguage $lng;

    /**
     * @param ilSeoPlugin $plugin
     * @param ilLanguage $lng
     * @return void
     */
    public function __construct(ilSeoPlugin $plugin, ilLanguage $lng)
    {
        $this->plugin = $plugin;
        $this->lng = $lng;
    }

    /**
     * Register the script backing permalinkInput()'s bind() call, handed
     * ilSeoPermalink::clientRules() so client and server share one set of
     * rules.
     *
     * Full-page render only: an async request's addJavaScript() is discarded. A
     * screen whose permalink field appears only later, inside an async modal,
     * therefore has to register the script while it is still rendering itself.
     * @param ilGlobalTemplateInterface $tpl
     * @return void
     */
    public static function injectPermalinkAssets(ilGlobalTemplateInterface $tpl): void
    {
        $tpl->addJavaScript(ilSeoPlugin::PLUGIN_DIR . "/templates/js/seo-permalink.js");

        // Escaped \uXXXX, not raw UTF-8: the page later runs through
        // DOMDocument mb_convert_encoding(HTML-ENTITIES), rewriting raw keys.
        $json = json_encode(ilSeoPermalink::clientRules(), JSON_UNESCAPED_SLASHES) ?: "{}";

        $tpl->addOnLoadCode("il.SeoPermalink.init({$json});");
    }

    /**
     * The permalink field, one builder for every form, so no two of them can
     * drift apart on its rules. The limit is the column's width and must not be
     * lowered: every stored row is displayed through here and withValue()
     * throws above it, so a tighter cap would white-screen already-accepted
     * rows; mb_strcut() cannot stand in for it here as it does in titleInput(),
     * since a cut permalink is a different published URL. Enforced again after
     * normalize(), because withMaxLength() runs before the transformation
     * below, on a value that has not yet gained its trailing "/". Deliberately
     * not required: an empty value now means "make this page the site's
     * homepage", decided by the caller, never by this field's own constraint;
     * see ilSeoGUI::routeToHomepageConfirmation().
     * @param array{permalink: string, title: string, description: string, note:
     *     string} $values
     * @return ILIAS\UI\Component\Input\Field\Text
     */
    public function permalinkInput(array $values): ILIAS\UI\Component\Input\Field\Text
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $refinery = $DIC->refinery();

        return $factory->input()->field()->text(
            $this->plugin->txt("slug"),
            $this->permalinkInfo($values["note"])
        )
            ->withDedicatedName(self::PERMALINK_KEY)
            ->withMaxLength(self::PERMALINK_MAX_LENGTH)
            // The renderer generates this field's id, so only it can name the
            // element to bind. bind() tolerates running before init().
            ->withAdditionalOnLoadCode(
                static fn (string $id): string => "il.SeoPermalink.bind('{$id}');"
            )
            ->withAdditionalTransformation(
                // Normalize first (e.g. "About Us" -> "about-us/") so this
                // validates the final permalink, not what was typed.
                $refinery->custom()->transformation(
                    static fn (string $value): string => ilSeoPermalink::normalize($value)
                )
            )
            ->withAdditionalTransformation(
                // strlen(), not mb_strlen(): the limit this reproduces is a
                // byte count.
                $refinery->custom()->constraint(
                    static fn (string $value): bool => strlen($value) <= self::PERMALINK_MAX_LENGTH,
                    sprintf($this->plugin->txt("permalink_too_long"), self::PERMALINK_MAX_LENGTH)
                )
            )
            ->withValue($values["permalink"]);
    }

    /**
     * The title field, shared by every form. Mandatory: a saved row with no
     * title publishes a page search engines have nothing to name.
     * @param array{permalink: string, title: string, description: string, note:
     *     string} $values
     * @return ILIAS\UI\Component\Input\Field\Text
     */
    public function titleInput(array $values): ILIAS\UI\Component\Input\Field\Text
    {
        global $DIC;

        return $DIC->ui()->factory()->input()->field()->text(
            $this->plugin->txt("title"),
            $this->plugin->txt("title_info")
        )
            ->withDedicatedName("title")
            ->withRequired(true, ilSeoFormValidation::mandatory($this->plugin))
            ->withMaxLength(self::TITLE_MAX_LENGTH)
            // A stored value over the limit makes withValue() throw, taking the
            // screen down: bytes, via mb_strcut(), not mb_substr().
            ->withValue(mb_strcut($values["title"], 0, self::TITLE_MAX_LENGTH));
    }

    /**
     * The description field, shared by every form. Never mandatory: a page may
     * legitimately publish without one.
     *
     * No mb_strcut() on the value here, unlike titleInput(): withMaxLimit()
     * attaches the same byte-counting hasMaxLength constraint, but
     * Textarea::isClientSideValueOk() is only is_string(), so an over-long
     * value is refused on save rather than taking the screen down while the
     * form is built. ilSeoInitialValues cuts its seed to DESCRIPTION_MAX_LENGTH
     * anyway.
     * @param array{permalink: string, title: string, description: string, note:
     *     string} $values
     * @return ILIAS\UI\Component\Input\Field\Textarea
     */
    public function descriptionInput(array $values): ILIAS\UI\Component\Input\Field\Textarea
    {
        global $DIC;

        return $DIC->ui()->factory()->input()->field()->textarea(
            $this->plugin->txt("description"),
            $this->plugin->txt("description_info")
        )
            ->withDedicatedName("description")
            ->withMaxLimit(self::DESCRIPTION_MAX_LENGTH)
            ->withValue($values["description"]);
    }

    /**
     * What a form's submit button says: Save while the target has no SEO row
     * yet, Update once it has one.
     * @param array<string,mixed>|null $seo_data row for the edited target, null
     *     when unsaved
     * @return string
     */
    public function submitLabel(?array $seo_data): string
    {
        return $seo_data === null ? $this->lng->txt("save") : $this->plugin->txt("update");
    }

    /**
     * The permalink field's byline, with a note appended while the value on
     * screen is a suggestion rather than something already saved. Without the
     * note a prefilled field is indistinguishable from a configured one.
     * ilSeoInitialValues picks which note.
     * @param string $note lang key from ilSeoInitialValues::initialValues(), ""
     *     for no note
     * @return string
     */
    private function permalinkInfo(string $note): string
    {
        $info = $this->plugin->txt("permalink_info");

        return $note === "" ? $info : $info . "<br/>" . $this->plugin->txt($note);
    }
}
