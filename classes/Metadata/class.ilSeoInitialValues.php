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
 * What a metadata form is filled with when it opens: a saved row verbatim, or
 * (when the target has no row yet) values derived from the target itself,
 * including a permalink that continues the path its container already publishes
 * under.
 *
 * Deliberately not a GUI class and not reachable through ilCtrl: it derives
 * values from the repository tree and the stored SEO rows, and reads neither
 * the request nor ilCtrl's parameter state. The caller resolves which target is
 * being edited and passes it in.
 */
class ilSeoInitialValues
{
    /** @var int cycle guard: levels the permalink walk may climb, since ilTree::getParentId() detects no cycles */
    private const CONTAINER_MAX_DEPTH = 20;

    /** @var ilSeo */
    private ilSeo $seo;

    /**
     * @param ilSeo $seo
     * @return void
     */
    public function __construct(ilSeo $seo)
    {
        $this->seo = $seo;
    }

    /**
     * The values a form opens with: a saved row verbatim (including a
     * description the user deliberately cleared), or seeded values when no row
     * exists yet for this ref_id/secondary_id/language. An object-level row
     * seeds from the object's own title and description; a secondary
     * (page/posting/record) row seeds from the addressed item's own title only,
     * never a description, since the object's summarises the object, not any
     * single page of it.
     * @param array<string,mixed>|null $seo_data row for the edited target, null
     *     when unsaved
     * @param ilObject $object
     * @param int $secondary_value secondary_id of the edited target, 0 for the
     *     object itself
     * @param string $db_lang language of the edited row, "-" for the
     *     default-language one
     * @return array{permalink: string, title: string, description: string,
     *     note: string}
     */
    public function initialValues(?array $seo_data, ilObject $object, int $secondary_value, string $db_lang): array
    {
        if ($seo_data !== null) {
            return [
                "permalink" => (string) ($seo_data["permalink"] ?? ""),
                "title" => (string) ($seo_data["title"] ?? ""),
                "description" => (string) ($seo_data["description"] ?? ""),
                "note" => "",
            ];
        }

        $suggestion = $this->suggestedPermalink($object, $secondary_value, $db_lang);

        return [
            "permalink" => $suggestion["permalink"],
            "title" => $this->targetTitle($object, $secondary_value, $db_lang),
            "description" => $secondary_value === 0 ? $this->targetDescription($object) : "",
            "note" => $suggestion["note"],
        ];
    }

    /**
     * The object's description as the SEO field should first show it: the
     * author's whole text, cut only where the field itself would refuse it.
     *
     * getLongDescription() rather than getDescription(), which answers with the
     * form cut to ilObject::DESC_LENGTH 128, mid-word and inside what a search
     * engine would have shown, while the full text is stored to
     * LONG_DESC_LENGTH 4000. The cut here is by bytes and with mb_strcut()
     * because the field's limit is the refinery's hasMaxLength, which measures
     * with strlen(); mb_strcut() also cannot leave a half-finished UTF-8
     * sequence behind.
     * @param ilObject $object
     * @return string
     */
    private function targetDescription(ilObject $object): string
    {
        return mb_strcut(
            $object->getLongDescription(),
            0,
            ilSeoMetadataFields::DESCRIPTION_MAX_LENGTH
        );
    }

    /**
     * The title of the exact target being edited: the object's own at
     * secondary_id 0, otherwise the addressed item's. Empty when the item has
     * none. A DCL record never has one, and neither does a secondary_id that no
     * longer resolves.
     * @param ilObject $object
     * @param int $secondary_value secondary_id of the edited target, 0 for the
     *     object itself
     * @param string $db_lang language of the edited row, "-" for the
     *     default-language one
     * @return string
     */
    private function targetTitle(ilObject $object, int $secondary_value, string $db_lang): string
    {
        return $secondary_value === 0
            ? $object->getTitle()
            : HandlerFactory::forType($object->getType())->itemTitle($secondary_value, $db_lang);
    }

    /**
     * The permalink offered for a target that has none saved yet: the
     * container's path, continued by one segment naming this target. The leaf
     * is targetTitle(), not the object's title, because for a secondary row the
     * object's title is already the prefix and would repeat.
     *
     * Composed through normalize(), so a prefix and a leaf cannot produce a
     * doubled separator. The returned note is the lang key explaining the value
     * offered, "" when there is none.
     * @param ilObject $object
     * @param int $secondary_value secondary_id of the edited target, 0 for the
     *     object itself
     * @param string $db_lang language of the edited row, "-" for the
     *     default-language one
     * @return array{permalink: string, note: string}
     */
    private function suggestedPermalink(ilObject $object, int $secondary_value, string $db_lang): array
    {
        $leaf = ilSeoPermalink::normalize($this->targetTitle($object, $secondary_value, $db_lang));

        // An object whose title transliterates to nothing gets no suggestion:
        // the bare prefix collides on save, so nothing is offered.
        if ($leaf === "" && $secondary_value === 0) {
            return ["permalink" => "", "note" => ""];
        }

        $prefix = $this->containerPermalink($object->getRefId(), $secondary_value, $db_lang);
        $note = $prefix === "" ? "permalink_suggested" : "permalink_suggested_inherited";

        if ($leaf === "") {
            // No title of its own (a DCL record never has one): the id stands
            // in, unique per item, but only under a container path.
            if ($prefix === "") {
                return ["permalink" => "", "note" => ""];
            }

            $leaf = (string) $secondary_value;
            $note = "permalink_suggested_numbered";
        }

        return [
            "permalink" => self::fit($prefix === "" ? $leaf : ilSeoPermalink::normalize($prefix . $leaf)),
            "note" => $note,
        ];
    }

    /**
     * A suggested permalink brought down to what the field accepts. A
     * container's permalink is prepended to a title ILIAS caps at 255
     * characters, so an untouched suggestion can outrun the field's limit,
     * where withValue() throws and takes down every screen building the form.
     *
     * Cutting is safe here and not on a stored row for one reason: nothing is
     * published yet, and the byline says as much. A saved permalink is never
     * cut, that being a silent move of a URL search engines already hold.
     *
     * One byte short and re-normalized, so the cut cannot strand a segment
     * without the trailing "/" isValid() demands, nor can the restored "/"
     * cross the limit again.
     * @param string $permalink already normalized, may be ""
     * @return string
     */
    private static function fit(string $permalink): string
    {
        if (strlen($permalink) <= ilSeoMetadataFields::PERMALINK_MAX_LENGTH) {
            return $permalink;
        }

        return ilSeoPermalink::normalize(
            mb_strcut($permalink, 0, ilSeoMetadataFields::PERMALINK_MAX_LENGTH - 1)
        );
    }

    /**
     * The permalink this target's container already publishes under, so a
     * suggestion continues that path instead of starting a new one at the site
     * root; empty when there is none. A secondary row's container is its own
     * object (same ref_id, secondary_id 0); an object's is its nearest ancestor
     * with one of its own, not the whole chain, since that ancestor's prefix
     * already carries its own ancestors'. A homepage ancestor stops the walk
     * like a slugged one, but contributes an empty prefix: it is the URL
     * space's root, so a page beneath it takes a leaf-only slug instead of
     * inheriting further up.
     * @param int $ref_id
     * @param int $secondary_value secondary_id of the edited target, 0 for the
     *     object itself
     * @param string $db_lang language of the edited row, "-" for the
     *     default-language one
     * @return string a valid, "/"-terminated permalink, or "" when there is
     *     none or the nearest configured ancestor is the homepage
     */
    private function containerPermalink(int $ref_id, int $secondary_value, string $db_lang): string
    {
        global $DIC;

        // A secondary row never climbs past its own object, so null (unset)
        // and "" (homepage) both collapse to the same "no prefix" answer.
        if ($secondary_value !== 0) {
            return $this->objectPermalink($ref_id, $db_lang) ?? "";
        }

        $tree = $DIC->repositoryTree();

        $current = $ref_id;
        for ($depth = 0; $depth < self::CONTAINER_MAX_DEPTH; $depth++) {
            if ($current === ROOT_FOLDER_ID) {
                return "";
            }

            // null means the node is not in the tree at all, 0 means a tree
            // root was reached; neither has a parent to inherit from.
            $parent = $tree->getParentId($current);
            if ($parent === null || $parent === 0) {
                return "";
            }

            $permalink = $this->objectPermalink($parent, $db_lang);
            if ($permalink !== null) {
                return $permalink;
            }

            $current = $parent;
        }

        return "";
    }

    /**
     * One candidate container's object-level permalink for the language being
     * edited: the requested language first, then the default-language row,
     * which the data layer stores under the "-" sentinel and not under that
     * language's own code.
     *
     * Null and "" are two different facts and must stay distinguishable: null
     * means no row exists for this ref_id at all, so the caller should keep
     * climbing toward an ancestor. "" means a row exists and its stored
     * permalink is empty, which a row can only reach by being saved as the
     * homepage, so the caller should stop climbing here and use an empty
     * prefix.
     * @param int $ref_id
     * @param string $db_lang "-" for the default-language row
     * @return string|null a valid, "/"-terminated permalink; "" for the
     *     homepage; or null when no row exists
     */
    private function objectPermalink(int $ref_id, string $db_lang): ?string
    {
        $row = $this->seo->fetchById($ref_id, 0, $db_lang);
        if ($row === null && $db_lang !== "-") {
            $row = $this->seo->fetchById($ref_id, 0, "-");
        }

        if ($row === null) {
            return null;
        }

        $permalink = (string) ($row["permalink"] ?? "");

        return ilSeoPermalink::isValid($permalink, $permalink === "") ? $permalink : null;
    }
}
