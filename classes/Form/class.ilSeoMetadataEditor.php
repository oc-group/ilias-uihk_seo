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
 * The one editor of an SEO row, used by the object page's advanced modal and by
 * the Overview screen's row modal alike. It owns the complete ordered field
 * set, the reading of what that field set posts back, and the head shown above
 * it, so no surface can offer a different field, a different rule or a
 * different summary of what is being edited.
 *
 * A caller keeps only what is genuinely its own: which row it edits, the
 * command it posts to, and where it lands afterwards.
 *
 * Deliberately not a GUI class and not reachable through ilCtrl: it builds
 * Kitchen Sink components from values it is handed and touches neither the
 * request nor ilCtrl's parameter state, which is what keeps it usable from
 * either surface.
 */
class ilSeoMetadataEditor
{
    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /** @var ilLanguage */
    private ilLanguage $lng;

    /** @var ilSeo */
    private ilSeo $seo;

    /** @var ilSeoMetadataFields */
    private ilSeoMetadataFields $fields;

    /**
     * @param ilSeoPlugin $plugin
     * @param ilLanguage $lng
     * @param ilSeo $seo
     * @return void
     */
    public function __construct(ilSeoPlugin $plugin, ilLanguage $lng, ilSeo $seo)
    {
        $this->plugin = $plugin;
        $this->lng = $lng;
        $this->seo = $seo;
        $this->fields = new ilSeoMetadataFields($plugin, $lng);
    }

    /**
     * The complete ordered field set of an SEO row.
     *
     * A bare array, not a form container: the advanced modal wraps it in a
     * Form\Standard and re-hosts the inputs in a roundtrip, while the row modal
     * builds its roundtrip from the array directly. decode() reads back exactly
     * this order. Change one, change the other.
     *
     * Position 0 is ilSeoMetadataFields::permalinkInput() directly: an empty
     * submission is a legitimate value now (see that method's own docblock),
     * not something a second field has to authorize.
     * @param array{permalink: string, title: string, description: string, note:
     *     string} $values
     * @param array<string,mixed>|null $seo_data stored row, null when the
     *     target has none yet
     * @return ILIAS\UI\Component\Input\Container\Form\FormInput[]
     */
    public function inputs(array $values, ?array $seo_data): array
    {
        return [
            $this->fields->permalinkInput($values),
            $this->fields->titleInput($values),
            $this->fields->descriptionInput($values),
            $this->indexInput($seo_data),
            $this->followInput($seo_data),
        ];
    }

    /**
     * What inputs() posts back, read by name: positions are stable and known
     * only here, so no save path unpacks the form by integer index. The
     * permalink comes back normalized only; the caller decides after this
     * decode whether it is non-empty or the row becomes the homepage (see
     * ilSeoGUI::routeToHomepageConfirmation()). The index group being off means
     * priority/frequency are not shown right now, not that they should be
     * reset: a switched-off group keeps whatever is already stored, falling
     * back to the hardcoded defaults only when nothing is stored yet.
     * @param array<int|string,mixed> $data as returned by getData() on the form
     *     or the modal
     * @param array<string,mixed>|null $existing_seo_data stored row, null when
     *     the target has none yet, used only as the priority/frequency fallback
     *     while the index group is off
     * @return array{permalink: string, title: string, description: string,
     *     robots: string, priority: int, frequency: string}
     */
    public function decode(array $data, ?array $existing_seo_data): array
    {
        // null: index group switched off; otherwise [frequency, priority].
        $index = $data[3] ?? null;
        $follow = (bool) ($data[4] ?? false);

        $priority = $index !== null
            ? (int) ($index[1] ?? ilSeoPriority::DEFAULT_VALUE)
            : (int) ($existing_seo_data["priority"] ?? ilSeoPriority::DEFAULT_VALUE);
        $frequency = $index !== null
            ? (string) ($index[0] ?? "")
            : (string) ($existing_seo_data["frequency"] ?? "");

        return [
            "permalink" => trim((string) ($data[0] ?? "")),
            "title" => trim((string) ($data[1] ?? "")),
            "description" => trim((string) ($data[2] ?? "")),
            "robots" => ($index === null ? "noindex" : "index") . "," . ($follow ? "follow" : "nofollow"),
            "priority" => ilSeoPriority::normalize($priority),
            "frequency" => ilSeoFrequency::normalize($frequency),
        ];
    }

    /**
     * The same field set for a caller that edits an existing row: filled from
     * the row verbatim, with no suggestion and no byline note.
     *
     * The pairing of values and row is made here rather than left to the
     * caller, so the two cannot be handed in describing different rows.
     * @param array<string,mixed>|null $seo_data stored row
     * @return ILIAS\UI\Component\Input\Container\Form\FormInput[]
     */
    public function inputsForRow(?array $seo_data): array
    {
        return $this->inputs(self::storedValues($seo_data), $seo_data);
    }

    /**
     * The field values of a row that already exists, taken verbatim and with no
     * byline note. ilSeoInitialValues covers the other case (a target with no
     * row yet, whose values are derived from the target itself), and a caller
     * editing a stored row must not let a suggestion overwrite what is saved.
     * @param array<string,mixed>|null $seo_data stored row
     * @return array{permalink: string, title: string, description: string,
     *     note: string}
     */
    private static function storedValues(?array $seo_data): array
    {
        return [
            "permalink" => (string) ($seo_data["permalink"] ?? ""),
            "title" => (string) ($seo_data["title"] ?? ""),
            "description" => (string) ($seo_data["description"] ?? ""),
            "note" => "",
        ];
    }

    /**
     * What the submit button says: Save while the target has no SEO row yet,
     * Update once it has one.
     * @param array<string,mixed>|null $seo_data stored row, null when the
     *     target has none yet
     * @return string
     */
    public function submitLabel(?array $seo_data): string
    {
        return $this->fields->submitLabel($seo_data);
    }

    /**
     * The notice a surface shows above its fields when
     * ilSeoPendingHomepage::isPendingFor() says the values it is about to
     * display were overlaid from an unanswered homepage confirmation, not read
     * from the stored row. One wording, built once here, so the quick form, the
     * advanced modal and the Overview row modal cannot drift apart on it.
     * @return ILIAS\UI\Component\MessageBox\MessageBox
     */
    public function pendingHomepageNotice(): ILIAS\UI\Component\MessageBox\MessageBox
    {
        global $DIC;

        return $DIC->ui()->factory()->messageBox()->info($this->plugin->txt("homepage_pending_notice"));
    }

    /**
     * The edited row as an item: what is being edited, plus the SEO facts about
     * it. Every surface heads with the same thing, differing only in the
     * actions its caller attaches. $object is null once its ref_id stops
     * resolving, so the head falls back to what the row itself stores. Looks
     * the row up itself; a caller that already holds it calls headFromRow()
     * instead.
     * @param int $ref_id
     * @param int $secondary_value secondary_id of the edited row, 0 for the
     *     object itself
     * @param string $db_lang language of the edited row, "-" for the
     *     default-language one
     * @param ilObject|null $object the row's object, null when its ref_id no
     *     longer resolves
     * @return ILIAS\UI\Component\Item\Standard
     */
    public function head(
        int $ref_id,
        int $secondary_value,
        string $db_lang,
        ?ilObject $object
    ): ILIAS\UI\Component\Item\Standard {
        return $this->headFromRow(
            $ref_id,
            $secondary_value,
            $db_lang,
            $object,
            $this->seo->fetchById($ref_id, $secondary_value, $db_lang)
        );
    }

    /**
     * head(), for a caller that has already read the row and would otherwise
     * read it again. The row is a required argument, not an optional one: null
     * here means the target has no SEO row yet, a real state this head renders;
     * an optional parameter would make that indistinguishable from a caller
     * that simply did not pass one.
     * @param int $ref_id
     * @param int $secondary_value secondary_id of the edited row, 0 for the
     *     object itself
     * @param string $db_lang language of the edited row, "-" for the
     *     default-language one
     * @param ilObject|null $object the row's object, null when its ref_id no
     *     longer resolves
     * @param array<string,mixed>|null $seo_data the row, null when the target
     *     has none yet
     * @return ILIAS\UI\Component\Item\Standard
     */
    public function headFromRow(
        int $ref_id,
        int $secondary_value,
        string $db_lang,
        ?ilObject $object,
        ?array $seo_data
    ): ILIAS\UI\Component\Item\Standard {
        global $DIC;

        $factory = $DIC->ui()->factory();

        $obj_type = $object !== null ? $object->getType() : (string) ($seo_data["type"] ?? "");
        $icon = $factory->symbol()->icon()->standard($obj_type, $this->typeLabel($obj_type), "medium");

        /** @var ILIAS\UI\Component\Item\Standard */
        return $factory->item()->standard($this->headTitle($ref_id, $object, $secondary_value, $seo_data))
            ->withLeadIcon($icon)
            ->withDescription($object !== null ? $object->getDescription() : "")
            ->withProperties([
                ...$this->contextProperties($obj_type, $secondary_value, $object),
                ...$this->seo->properties($ref_id, $secondary_value, $db_lang),
                // Core escapes Item property keys, not their values.
                $this->lng->txt("language") => ilSeoEscape::html($this->displayLanguage($db_lang)),
            ]);
    }

    /**
     * The switch deciding whether the page is indexed at all, wrapping the two
     * sitemap values that mean nothing while it is off. The sub-fields keep
     * their last-known value so re-enabling the group client-side never submits
     * empty required fields.
     * @param array<string,mixed>|null $seo_data stored row, null when the
     *     target has none yet
     * @return ILIAS\UI\Component\Input\Field\OptionalGroup
     */
    private function indexInput(?array $seo_data): ILIAS\UI\Component\Input\Field\OptionalGroup
    {
        global $DIC;

        $field_factory = $DIC->ui()->factory()->input()->field();

        $robots = (string) ($seo_data["robots"] ?? "");
        $frequency = ilSeoFrequency::normalize((string) ($seo_data["frequency"] ?? ""));
        $priority = ilSeoPriority::normalize((int) ($seo_data["priority"] ?? ilSeoPriority::DEFAULT_VALUE));

        /**
         * @psalm-suppress InvalidArgument ilSeoPriority::options() keys are int
         *     (PHP numeric-string key coercion); select()'s declared
         *     array<string,string> is Psalm-only, PHPStan agrees this is fine
         *     (see ilSeoPriority::options()).
         */
        return $field_factory->optionalGroup(
            [
                $field_factory->select(
                    $this->plugin->txt("frequency"),
                    ilSeoFrequency::options($this->plugin),
                    $this->plugin->txt("frequency_info")
                )
                    ->withDedicatedName("frequency")
                    ->withRequired(true)
                    ->withValue($frequency),
                $field_factory->select(
                    $this->plugin->txt("priority"),
                    ilSeoPriority::options(), // @phpstan-ignore-line argument.type
                    $this->plugin->txt("priority_info")
                )
                    ->withDedicatedName("priority")
                    ->withRequired(true)
                    ->withValue((string) $priority),
            ],
            $this->plugin->txt("index_page"),
            $this->plugin->txt("index_page_info")
        )
            ->withDedicatedName("index_group")
            ->withValue(
                ilSeoRobots::hasToken($robots, "noindex")
                ? null
                : [$frequency, (string) $priority]
            );
    }

    /**
     * Whether link equity may leave the page. Outside the index group on
     * purpose: a page excluded from the index still passes equity along the
     * links it carries.
     * @param array<string,mixed>|null $seo_data stored row, null when the
     *     target has none yet
     * @return ILIAS\UI\Component\Input\Field\Checkbox
     */
    private function followInput(?array $seo_data): ILIAS\UI\Component\Input\Field\Checkbox
    {
        global $DIC;

        return $DIC->ui()->factory()->input()->field()->checkbox(
            $this->plugin->txt("follow_links"),
            $this->plugin->txt("follow_links_info")
        )
            ->withDedicatedName("follow")
            ->withValue(ilSeoRobots::follows((string) ($seo_data["robots"] ?? "")));
    }

    /**
     * The name the head carries: a secondary item's own SEO title, since no
     * icon tells one apart from its container, otherwise the object's own. With
     * no object left, whatever the row still identifies itself by, down to its
     * bare ref_id.
     * @param int $ref_id
     * @param ilObject|null $object
     * @param int $secondary_value secondary_id of the edited row, 0 for the
     *     object itself
     * @param array<string,mixed>|null $seo_data stored row
     * @return string
     */
    private function headTitle(int $ref_id, ?ilObject $object, int $secondary_value, ?array $seo_data): string
    {
        $seo_title = (string) ($seo_data["title"] ?? "");

        if ($object !== null) {
            return ($secondary_value !== 0 && $seo_title !== "") ? $seo_title : $object->getTitle();
        }

        $fallback = $seo_title !== "" ? $seo_title : (string) ($seo_data["permalink"] ?? "");

        return $fallback !== "" ? $fallback : "#" . $ref_id;
    }

    /**
     * What the head says about the row's place in the repository, ahead of the
     * SEO facts: the content type of a secondary row, and a note once the
     * object it describes is gone.
     * @param string $obj_type
     * @param int $secondary_value secondary_id of the edited row, 0 for the
     *     object itself
     * @param ilObject|null $object
     * @return array<string,string>
     */
    private function contextProperties(string $obj_type, int $secondary_value, ?ilObject $object): array
    {
        $properties = [];

        if ($secondary_value !== 0 && $obj_type !== "") {
            $key = $this->plugin->txt("advanced_content_type_label");
            $properties[$key] = $this->plugin->txt("advanced_content_type_{$obj_type}");
        }

        if ($object === null) {
            $properties[$this->plugin->txt("target_missing_label")] = $this->plugin->txt("target_missing");
        }

        return $properties;
    }

    /**
     * How an object type reads to a user. Empty when the row names no type,
     * which only a row whose object is gone can do.
     * @param string $obj_type
     * @return string
     */
    private function typeLabel(string $obj_type): string
    {
        return $obj_type !== "" ? $this->lng->txt("obj_{$obj_type}") : "";
    }

    /**
     * The edited row's language as a code to show a user: the data layer stores
     * the default-language row under a sentinel rather than under that
     * language's own code.
     * @param string $db_lang
     * @return string
     */
    private function displayLanguage(string $db_lang): string
    {
        return $db_lang === "-" ? ilSeoLanguage::getDefaultLang() : $db_lang;
    }
}
