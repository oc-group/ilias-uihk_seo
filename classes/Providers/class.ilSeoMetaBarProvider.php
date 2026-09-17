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

use ILIAS\GlobalScreen\Scope\MetaBar\Provider\AbstractStaticMetaBarPluginProvider;
use ilSeo\ObjectHandlers\HandlerFactory;

/**
 * SEO MetaBar provider class
 */
class ilSeoMetaBarProvider extends AbstractStaticMetaBarPluginProvider
{
    /** @var string no SEO record exists for this page yet */
    public const STATUS_UNSAVED = "unsaved";

    /** @var string the permalink is published, but the object denies anonymous access */
    public const STATUS_NO_ACCESS = "no_access";

    /** @var string configured and reachable, but deliberately kept out of the index */
    public const STATUS_NOINDEX = "noindex";

    /** @var string metadata saved and the page is indexable */
    public const STATUS_INDEXED = "indexed";

    /**
     * SEO metabar items.
     * @return \ILIAS\GlobalScreen\Scope\MetaBar\Factory\isItem[]
     */
    public function getMetaBarItems(): array
    {
        $query = $this->dic->http()->wrapper()->query();
        $refinery = $this->dic->refinery();
        $factory = $this->dic->ui()->factory();

        if (!$this->plugin->isActive()) {
            return [];
        }

        if (!$query->has("ref_id")) {
            return [];
        }
        $ref_id = $query->retrieve("ref_id", $refinery->kindlyTo()->int());

        // Same predicate ilSeoGUI enforces on click; RBAC alone misses ilAccess
        // availability rules the button must also respect.
        if (!ilSeoAccess::canEditObject($ref_id)) {
            return [];
        }

        $object = ilObjectFactory::getInstanceByRefId($ref_id);
        if ($object === null) {
            return [];
        }
        $obj_type = $object->getType();
        $secondary_id = 0;
        $secondary_id_key = ilSeo::SECONDARY_ID_KEYS[$obj_type] ?? null;

        if ($secondary_id_key !== null && $query->has($secondary_id_key)) {
            $secondary_id = $query->retrieve($secondary_id_key, $refinery->kindlyTo()->int());
        }

        if (!HandlerFactory::forType($obj_type)->isPageReachable($secondary_id, $this->dic->user()->getId())) {
            // Resolves to "Permission Denied" here, so "manage SEO" would
            // mislead; still editable from Administration > SEO > Overview.
            return [];
        }

        if (!ilSeo::isPageIntendedForWeb()) {
            return [];
        }

        $slate = (new ilSeoGUI())->renderQuickForm();

        $title = $this->plugin->txt("seo");
        // Icon file and label both derive from status; item title stays "SEO",
        // the panel's own name, rendered as visible text.
        $status = $this->getStatus($ref_id, $secondary_id);
        $icon = $factory->symbol()->icon()->custom(
            ilSeoPlugin::iconPath("seo_{$status}"),
            $this->plugin->txt("status_{$status}"),
            "large"
        );

        return [
            $this->meta_bar->topLegacyItem($this->if->identifier("meta_edit"))
                ->withLegacyContent($factory->legacy($slate))
                ->withSymbol($icon)
                ->withTitle($title)
                ->withPosition(1),
        ];
    }

    /**
     * Check the object metadata and return the page's SEO status.
     *
     * The returned value names a condition, not a verdict: it is the suffix of
     * both the icon file ("seo_<status>.svg") and the lang key
     * ("status_<status>"), so the two cannot fall out of step. Callers must not
     * call this twice per request (it reads the database).
     *
     * @param int $ref_id
     * @return string one of the STATUS_* constants
     */
    protected function getStatus(int $ref_id, int $secondary_id): string
    {
        $seo = new ilSeo();
        $settings = new ilSeoSettings();

        // With nothing configured, fall back to "-": it is what the
        // default-language row is keyed by, so the lookup below still finds it.
        $default = $settings->getDefaultLang() ?: "-";
        $user_lang = $this->dic->language()->getLangKey();
        $lang = in_array($user_lang, array_merge([$default], $settings->getSecondaryLangs()))
            ? $user_lang
            : $default;

        // Normalize to the "-" sentinel for the default-language row
        // (ilSeoLanguage::toDbLang()); a literal language code would miss it.
        $data = $seo->fetchById($ref_id, $secondary_id, ilSeoLanguage::toDbLang($lang));

        if ($data == null) {
            return self::STATUS_UNSAVED;
        }

        if (!$seo->hasPublicAccess($ref_id)) {
            return self::STATUS_NO_ACCESS;
        }

        if (ilSeoRobots::hasToken((string) $data["robots"], "noindex")) {
            return self::STATUS_NOINDEX;
        }

        return self::STATUS_INDEXED;
    }
}
