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

use ILIAS\Data\Meta\Html\NullTag;
use ILIAS\Data\Meta\Html\OpenGraph\Image;
use ILIAS\Data\Meta\Html\OpenGraph\TagCollection;
use ILIAS\Data\URI;
use ILIAS\GlobalScreen\Scope\Layout\Factory\BreadCrumbsModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\ContentModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\FooterModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\LogoModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\MainBarModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\MetaBarModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\PageBuilderModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\ShortTitleModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\TitleModification;
use ILIAS\GlobalScreen\Scope\Layout\Factory\ViewTitleModification;
use ILIAS\GlobalScreen\Scope\Layout\Provider\AbstractModificationPluginProvider;
use ILIAS\GlobalScreen\ScreenContext\ScreenContext;
use ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts;
use ILIAS\GlobalScreen\ScreenContext\Stack\ContextCollection;
use ILIAS\UI\Component\Button\Bulky as UIBulkyButton;
use ILIAS\UI\Component\Divider\Horizontal as UIHorizontalDivider;
use ILIAS\UI\Component\Link\Bulky as UIBulkyLink;
use ILIAS\UI\Component\MainControls\MetaBar as UIMetaBar;
use ILIAS\UI\Component\MainControls\Slate\Combined as CombinedSlate;
use ILIAS\UI\Component\MainControls\Slate\Slate as UISlate;
use ILIAS\UICore\PageContentProvider;
use ilSeo\ObjectHandlers\HandlerFactory;

/**
 * SEO Layout modifications provider class
 */
class ilSeoLayoutProvider extends AbstractModificationPluginProvider
{
    /** @var string internal id of the language-switcher MetaBar item, set by core's StartUpMetaBarProvider */
    private const LANGUAGE_SELECTION_ITEM_ID = "language_selection";

    /**
     * This modification provider is interested in any context.
     * @return ILIAS\GlobalScreen\ScreenContext\Stack\ContextCollection
     */
    public function isInterestedInContexts(): ContextCollection
    {
        return $this->context_collection
            ->main()
            ->internal()
            ->external()
            ->repository();
    }

    /**
     * Modify the page content.
     * @see ILIAS\Data\Meta\Html\OpenGraph\Factory::website
     *
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ContentModification|null
     */
    public function getContentModification(CalledContexts $screen_context_stack): ?ContentModification
    {
        if (!$this->plugin->isActive()) {
            return null;
        }

        $query = $this->dic->http()->wrapper()->query();
        $refinery = $this->dic->refinery();

        // Check for a special page (e.g. imprint).
        if ($query->has("baseClass")) {
            $special_handler = HandlerFactory::forBaseClass($query->retrieve("baseClass", $refinery->kindlyTo()->string()));
            if ($special_handler !== null) {
                // Special page has no ref id.
                $canonical_url = $special_handler->getCanonicalUrlOverride();
                $script = "history.replaceState({},'','{$canonical_url}');";
                $this->dic->ui()->mainTemplate()->addJavaScript("data:application/javascript;base64," . base64_encode($script));
                $this->dic->globalScreen()->layout()->meta()->addMetaDatum(new ilSeoLinkTag("canonical", $canonical_url));
            }
        }

        if (!ilSeo::isPageIntendedForWeb()) {
            $meta_tags = $this->generateNoFollowMetadata();
            $this->globalScreen()->layout()->meta()->addOpenGraphMetaDatum($meta_tags);
            return null;
        }

        $current_context = $this->ensureRepoContext($screen_context_stack)->current();
        $ref_id = $current_context->getReferenceId()->toInt();

        if (!$ref_id || !$this->dic->access()->checkAccess("read", "", $ref_id)) {
            $meta_tags = $this->generateNoFollowMetadata();
            $this->globalScreen()->layout()->meta()->addOpenGraphMetaDatum($meta_tags);
            return null;
        }

        $object = $this->getObjectOfContext($current_context);
        if ($object == null) {
            $meta_tags = $this->generateNoFollowMetadata();
            $this->globalScreen()->layout()->meta()->addOpenGraphMetaDatum($meta_tags);
            return null;
        }
        $obj_type = $object->getType();

        $secondary_id_key = ilSeo::SECONDARY_ID_KEYS[$obj_type] ?? null;

        $secondary_id = 0;
        if ($secondary_id_key !== null && $this->dic->http()->wrapper()->query()->has($secondary_id_key)) {
            $secondary_id = $this->dic->http()->wrapper()->query()->retrieve(
                $secondary_id_key,
                $this->dic->refinery()->kindlyTo()->int()
            );
        }

        $this->updateSeoMetadata($ref_id, $object, $secondary_id);

        return null;
    }

    /**
     * Ensure that the context matches the repository.
     * @see ILIAS\Repository\Provider\RepositoryOpenGraphExposer::ensureRepoContext
     *
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     */
    protected function ensureRepoContext(CalledContexts $screen_context_stack): CalledContexts
    {
        // ContextCollection is @internal with no non-mutating "match" API, so a
        // throwaway collection tests the match without touching the real stack.
        /** @psalm-suppress InternalClass */
        $collection = new ContextCollection(
            $this->dic->globalScreen()->tool()->context()->availableContexts()
        );
        $collection = $collection->repository();

        if (!$screen_context_stack->hasMatch($collection)) {
            $screen_context_stack = $screen_context_stack->repository();
        }
        return $screen_context_stack;
    }

    /**
     * Retrieve the object from the context.
     * @see ILIAS\Repository\Provider\RepositoryOpenGraphExposer::getObjectOfContext
     *
     * @param ILIAS\GlobalScreen\ScreenContext\ScreenContext $context
     * @return ilObject|null
     */
    protected function getObjectOfContext(ScreenContext $context): ?ilObject
    {
        if (!$context->hasReferenceId()) {
            return null;
        }

        try {
            return \ilObjectFactory::getInstanceByRefId($context->getReferenceId()->toInt());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Update the webpage metadata.
     * @param int $ref_id
     * @param ilObject $object
     * @return void
     */
    protected function updateSeoMetadata(int $ref_id, ilObject $object, int $secondary_id = 0): void
    {
        // The default-language row is stored under the "-" sentinel, not its
        // language code.
        $seo = new ilSeo();
        $lang = $this->dic->language()->getLangKey();
        $resolved_lang = $lang;
        $data = $seo->fetchById($ref_id, $secondary_id, ilSeoLanguage::toDbLang($lang));

        if ($data === null && $lang !== ilSeoLanguage::getDefaultLang()) {
            $logger = ilLoggerFactory::getLogger(ilSeoPlugin::PLUGIN_ID);
            $logger->debug("No SEO row for ref_id {$ref_id}, secondary_id {$secondary_id}, lang '{$lang}'; falling back to default language.");
            $data = $seo->fetchById($ref_id, $secondary_id, "-");
            $resolved_lang = ilSeoLanguage::getDefaultLang();
        }

        if ($data == null) {
            $meta_tags = $this->generateNoFollowMetadata();
            $this->globalScreen()->layout()->meta()->addOpenGraphMetaDatum($meta_tags);
            return;
        }

        // Fires only when $data is a real row (see the null check above);
        // raise() is a no-op with no listener, so no consumer is active.
        $this->dic->event()->raise(
            "Plugins/Seo",
            "pageResolved",
            [
                "ref_id" => $ref_id,
                "secondary_id" => $secondary_id,
                "lang" => $resolved_lang,
            ]
        );

        // og:locale/hreflang must describe the language $data was actually
        // fetched in, not the object's unrelated ilObjectTranslation default.
        $languages = $this->getLanguages($seo, $ref_id, $secondary_id, $resolved_lang);
        $image = $this->getPresentationImage($object);
        $path = $data["permalink"];
        $permalink = ILIAS_HTTP_PATH . "/{$path}";

        $hreflang_alternates = $this->buildHreflangAlternates(
            $seo,
            $ref_id,
            $object->getType(),
            $secondary_id,
            $languages["current"],
            $permalink,
            $languages["available"]
        );

        // Remove footer permalink.
        PageContentProvider::setPermaLink("");

        $script = "history.replaceState({},'','{$permalink}');";
        $this->dic->ui()->mainTemplate()->addJavaScript("data:application/javascript;base64," . base64_encode($script));

        $meta_tags = $this->generateSeoMetadata(
            $permalink,
            $image,
            [
                "title" => $data["title"],
                "description" => $data["description"],
                "robots" => $data["robots"],
                "is_publishable" => ilSeoSitemapStatus::isPublishableRow($data),
            ],
            [
                "default_locale" => $languages["current"],
                "alternative_locales" => $languages["available"],
                "hreflang_alternates" => $hreflang_alternates,
            ]
        );

        $this->globalScreen()->layout()->meta()->addOpenGraphMetaDatum($meta_tags);
    }

    /**
     * Resolve each installed language's permalink, falling back to "?lang="
     * when none is configured.
     *
     * @param int $ref_id
     * @param int $secondary_id
     * @return array<string, string> language code => absolute URL
     */
    protected function resolveLangPermalinks(int $ref_id, int $secondary_id): array
    {
        $seo = new ilSeo();
        $permalinks = [];

        foreach ($this->dic->language()->getInstalledLanguages() as $lang) {
            // Map stays keyed by the literal ISO code (matched by
            // extractLangFromAction()); only the DB lookup is normalized.
            $data = $seo->fetchById($ref_id, $secondary_id, ilSeoLanguage::toDbLang($lang));
            $permalink = $data !== null ? (string) $data["permalink"] : null;
            // !empty() misreads a stored homepage row (permalink "") as "none
            // configured", falling back to "?lang="; isValid() knows better.
            $permalinks[$lang] = ($permalink !== null && ilSeoPermalink::isValid($permalink, $permalink === ""))
                ? ILIAS_HTTP_PATH . "/" . $permalink
                : ILIAS_HTTP_PATH . "/?lang=" . $lang;
        }

        return $permalinks;
    }

    /**
     * Extract the "lang" query parameter from a language-switcher action URL.
     * @param string $action
     * @return string|null
     */
    private static function extractLangFromAction(string $action): ?string
    {
        $query = parse_url($action, PHP_URL_QUERY);
        if (!is_string($query) || $query === "") {
            return null;
        }

        parse_str($query, $params);

        return (isset($params["lang"]) && is_string($params["lang"])) ? $params["lang"] : null;
    }

    /**
     * Generate no follow metadata tags.
     * @return ilSeoTagCollection
     */
    protected function generateNoFollowMetadata(): ilSeoTagCollection
    {
        $website_title = (new ilSeoSettings())->getWebTitle();
        return new ilSeoTagCollection(
            new ilSeoPropertyTag("og:type", "website"),
            new ilSeoPropertyTag("og:site_name", $website_title),
            new ilSeoMetaTag("robots", "noindex, nofollow")
        );
    }

    /**
     * Generate SEO metadata
     * @see ILIAS\Data\Meta\Html\OpenGraph\Factory::website
     * @param string $canonical_url
     * @param Image $image
     * @param array{title: string, description: string, robots: string,
     *     is_publishable: bool} $row_values values read from this page's SEO
     *     row; "is_publishable" is whether that row clears the sitemap gate
     * @param array{default_locale: string, alternative_locales: string[],
     *     hreflang_alternates: array<int, array{hreflang: string, href:
     *     string}>} $language_context
     * @param \ILIAS\Data\Meta\Html\Tag[] $additional_resources
     * @return ilSeoTagCollection
     */
    protected function generateSeoMetadata(
        string $canonical_url,
        Image $image,
        array $row_values,
        array $language_context,
        array $additional_resources = []
    ): ilSeoTagCollection {
        $website_title = (new ilSeoSettings())->getWebTitle();
        $default_locale = $language_context["default_locale"];
        $description = $row_values["description"];

        return new ilSeoTagCollection(
            new ilSeoPropertyTag("og:type", "website"),
            new ilSeoPropertyTag("og:site_name", $website_title),
            new ilSeoBaseTag(),
            new ilSeoPropertyTag("og:title", $row_values["title"]),
            new ilSeoPropertyTag("og:url", $canonical_url),
            new ilSeoLinkTag("canonical", $canonical_url),
            // Unlike the canonical, this is an hreflang target of itself, so it
            // holds only for a row the sitemap publishes.
            $row_values["is_publishable"] ? new ilSeoLinkTag("alternate", $canonical_url, $default_locale) : new NullTag(),
            $this->getHreflangAlternatesTag($language_context["hreflang_alternates"]),
            $image,
            new ilSeoPropertyTag("og:description", $description),
            new ilSeoMetaTag("description", $description),
            new ilSeoMetaTag("robots", $row_values["robots"]),
            ($default_locale !== "") ? new ilSeoPropertyTag("og:locale", $default_locale) : new NullTag(),
            $this->getAlternativeLocalesTag($language_context["alternative_locales"]),
            new TagCollection(...$additional_resources)
        );
    }

    /**
     * @see ILIAS\Data\Meta\Html\OpenGraph\Factory::getAlternativeLocalesTag
     * @param string[] $locales
     * @return NullTag|TagCollection
     */
    protected function getAlternativeLocalesTag(array $locales): NullTag|TagCollection
    {
        if (empty($locales)) {
            return new NullTag();
        }

        $alternative_languages = [];
        foreach ($locales as $locale) {
            $alternative_languages[] = new ilSeoPropertyTag("og:locale:alternative", $locale);
        }

        return new TagCollection(...$alternative_languages);
    }

    /**
     * Build the hreflang <link> tags for this page's other configured
     * languages.
     * @param array<int, array{hreflang: string, href: string}> $alternates
     * @return NullTag|TagCollection
     */
    protected function getHreflangAlternatesTag(array $alternates): NullTag|TagCollection
    {
        if (empty($alternates)) {
            return new NullTag();
        }

        $tags = [];
        foreach ($alternates as $alternate) {
            $tags[] = new ilSeoLinkTag("alternate", $alternate["href"], $alternate["hreflang"]);
        }

        return new TagCollection(...$tags);
    }

    /**
     * Get the installation default image.
     * @see
     *     ILIAS\Repository\Provider\RepositoryOpenGraphExposer::getDefaultImage
     *
     * @return ILIAS\Data\Meta\Html\OpenGraph\Image
     */
    protected function getDefaultImage(): Image
    {
        $image_path_resolver = new ilImagePathResolver();

        return $this->data->openGraphMetadata()->image(
            $this->data->uri(
                ILIAS_HTTP_PATH . ltrim(
                    $image_path_resolver->resolveImagePath(
                        "logo/Sharing.jpg"
                    ),
                    "."
                )
            ),
            "image/jpg"
        );
    }

    /**
     * Get the object presentation image.
     * @see ILIAS\Repository\Provider\RepositoryOpenGraphExposer::getPresentationImage
     *
     * @param ilObject $object
     * @return ILIAS\Data\Meta\Html\OpenGraph\Image
     */
    protected function getPresentationImage(ilObject $object): Image
    {
        $image_factory = $this->dic->ui()->factory()->image();
        $image = $this->getDefaultImage();

        try {
            $tile_image = $object->getObjectProperties()->getPropertyTileImage()->getTileImage();
            if ($tile_image !== null && $tile_image->getRid() !== null) {
                $tile_image_factory = $tile_image->getImage($image_factory);
                $uri_string = $tile_image_factory->getAdditionalHighResSources()["960"] ?? $tile_image_factory->getSource();

                $image = $this->data->openGraphMetadata()->image(
                    $this->data->uri($uri_string),
                    "image/jpg"
                );
            }
        } catch (\Throwable $e) {
            // Nothing to do.
        }

        return $image;
    }

    /**
     * The language this render used and the other languages this page has SEO
     * data for, limited to those still active in "seo_langs":
     * ilSeo::fetchLangsFor() reports every stored language and leaves that
     * policy to its callers.
     *
     * @param ilSeo $seo
     * @param int $ref_id
     * @param int $secondary_id
     * @param string $current_lang
     * @return array{current: string, available: string[]}
     */
    protected function getLanguages(ilSeo $seo, int $ref_id, int $secondary_id, string $current_lang): array
    {
        $active_langs = ilSeoSitemapStatus::activeDbLangs();

        $available = array_values(array_filter(
            array_diff($seo->fetchLangsFor($ref_id, $secondary_id), [$current_lang]),
            // fetchLangsFor() returns resolved codes, activeDbLangs() DB langs:
            // map back, or the default language never matches the "-" sentinel.
            static fn (string $lang): bool => in_array(ilSeoLanguage::toDbLang($lang), $active_langs, true)
        ));

        return [
            "current" => $current_lang,
            "available" => $available,
        ];
    }

    /**
     * Build the hreflang alternates for this page's other configured languages,
     * gated on the same ilSeoSitemapStatus predicate the sitemap builder
     * publishes through. Empty when the reference is not readable by a visitor
     * or the addressed sub-page cannot be opened: neither varies by language,
     * so either one withholds the whole cluster.
     *
     * @param ilSeo $seo
     * @param int $ref_id
     * @param string $type
     * @param int $secondary_id
     * @param string $current_lang
     * @param string $canonical_url
     * @param string[] $available
     * @return array<int, array{hreflang: string, href: string}>
     */
    protected function buildHreflangAlternates(
        ilSeo $seo,
        int $ref_id,
        string $type,
        int $secondary_id,
        string $current_lang,
        string $canonical_url,
        array $available
    ): array {
        $is_public = $seo->hasPublicAccess($ref_id);
        $is_reachable = HandlerFactory::forType($type)->isPageReachable($secondary_id, ANONYMOUS_USER_ID);
        if (!$is_public || !$is_reachable) {
            return [];
        }

        $alternates = [];
        $default_lang = ilSeoLanguage::getDefaultLang();

        if ($current_lang === $default_lang) {
            // x-default reuses this URL, but only while the sitemap publishes
            // this row.
            $current_row = $seo->fetchById($ref_id, $secondary_id, ilSeoLanguage::toDbLang($current_lang));
            if ($current_row !== null && ilSeoSitemapStatus::isPublishableRow($current_row)) {
                $alternates[] = ["hreflang" => "x-default", "href" => $canonical_url];
            }
        }

        foreach ($available as $lang) {
            $data = $seo->fetchById($ref_id, $secondary_id, ilSeoLanguage::toDbLang($lang));
            if ($data === null || !ilSeoSitemapStatus::isPublishableRow($data)) {
                continue;
            }

            $href = ILIAS_HTTP_PATH . "/" . $data["permalink"];
            $alternates[] = ["hreflang" => $lang, "href" => $href];

            if ($lang === $default_lang) {
                $alternates[] = ["hreflang" => "x-default", "href" => $href];
            }
        }

        return $alternates;
    }

    /**
     * Replace standard logo link with website link.
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\Scope\Layout\Factory\LogoModification
     */
    public function getLogoModification(CalledContexts $screen_context_stack): ?LogoModification
    {
        $additional_data = $screen_context_stack->current()->getAdditionalData();
        return $this->globalScreen()->layout()->factory()->logo()->withModification(
            static function (?ILIAS\UI\Component\Image\Image $current) use ($additional_data): ?ILIAS\UI\Component\Image\Image {
                if ($current == null) {
                    return null;
                }

                if ($additional_data->exists(\ilLMGSToolProvider::LM_OFFLINE)) {
                    $current->withAction("javascript:void(0)");
                }

                return $current->withAction(ILIAS_HTTP_PATH);
            }
        )->withPriority(20);
    }

    /**
     * Replace mobile logo link with website link.
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\Scope\Layout\Factory\LogoModification
     */
    public function getResponsiveLogoModification(CalledContexts $screen_context_stack): ?LogoModification
    {
        return $this->getLogoModification($screen_context_stack);
    }

    /**
     * @inheritDoc
     */
    public function getMainBarModification(CalledContexts $screen_context_stack): ?MainBarModification
    {
        return null;
    }

    /**
     * Replace core language-switcher MetaBar links with the resolved SEO
     * permalink, so non-JS crawlers follow the clean URL instead of the
     * internal "?lang=" one.
     * @see ILIAS\Init\Provider\StartUpMetaBarProvider::getMetaBarItems
     *
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\Scope\Layout\Factory\MetaBarModification|null
     */
    public function getMetaBarModification(CalledContexts $screen_context_stack): ?MetaBarModification
    {
        if (!$this->plugin->isActive()) {
            return null;
        }

        if (!ilSeo::isPageIntendedForWeb()) {
            return null;
        }

        $current_context = $this->ensureRepoContext($screen_context_stack)->current();
        $ref_id = $current_context->getReferenceId()->toInt();

        if (!$ref_id || !$this->dic->access()->checkAccess("read", "", $ref_id)) {
            return null;
        }

        $object = $this->getObjectOfContext($current_context);
        if ($object == null) {
            return null;
        }
        $obj_type = $object->getType();

        $secondary_id_key = ilSeo::SECONDARY_ID_KEYS[$obj_type] ?? null;

        $secondary_id = 0;
        if ($secondary_id_key !== null && $this->dic->http()->wrapper()->query()->has($secondary_id_key)) {
            $secondary_id = $this->dic->http()->wrapper()->query()->retrieve(
                $secondary_id_key,
                $this->dic->refinery()->kindlyTo()->int()
            );
        }

        $permalinks = $this->resolveLangPermalinks($ref_id, $secondary_id);
        $ui_factory = $this->dic->ui()->factory();

        return $this->globalScreen()->layout()->factory()->metabar()->withModification(
            static function (?UIMetaBar $metabar) use ($permalinks, $ui_factory): ?UIMetaBar {
                if ($metabar === null) {
                    return $metabar;
                }

                $language_slate = $metabar->getEntries()[self::LANGUAGE_SELECTION_ITEM_ID] ?? null;
                if (!$language_slate instanceof CombinedSlate) {
                    // Item not present (e.g. logged-in user, or only one
                    // installed language) or not shaped as expected.
                    return $metabar;
                }

                try {
                    $rebuilt_slate = $ui_factory->mainControls()->slate()->combined(
                        $language_slate->getName(),
                        $language_slate->getSymbol()
                    );

                    foreach ($language_slate->getContents() as $entry) {
                        if ($entry instanceof UIBulkyLink) {
                            $lang = self::extractLangFromAction($entry->getAction());
                            if ($lang !== null && isset($permalinks[$lang])) {
                                $entry = $ui_factory->link()->bulky(
                                    $entry->getSymbol(),
                                    $entry->getLabel(),
                                    new URI($permalinks[$lang])
                                );
                            }
                        }
                        if (
                            $entry instanceof UIBulkyButton
                            || $entry instanceof UIHorizontalDivider
                            || $entry instanceof UIBulkyLink
                            || $entry instanceof UISlate
                        ) {
                            $rebuilt_slate = $rebuilt_slate->withAdditionalEntry($entry);
                        }
                    }

                    return $metabar->withAdditionalEntry(self::LANGUAGE_SELECTION_ITEM_ID, $rebuilt_slate);
                } catch (\Throwable $e) {
                    // Defensive: never break the page because of an
                    // unexpected MetaBar shape.
                    return $metabar;
                }
            }
        )->withPriority(20);
    }

    /**
     * Edit the breadcrumbs using SEO permalinks.
     * @see ILIAS\GlobalScreen\Scope\Layout\Provider\PagePart\StandardPagePartProvider::getBreadCrumbs
     *
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\Scope\Layout\Factory\BreadCrumbsModification
     */
    public function getBreadCrumbsModification(CalledContexts $screen_context_stack): ?BreadCrumbsModification
    {
        // No need, since the permalinks are edited in the UIHook class.
        return null;
    }

    /**
     * Edit the page footer.
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\Scope\Layout\Factory\FooterModification|null
     */
    public function getFooterModification(CalledContexts $screen_context_stack): ?FooterModification
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getPageBuilderDecorator(CalledContexts $screen_context_stack): ?PageBuilderModification
    {
        return null;
    }

    /**
     * Edit the ILIAS main title.
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return ILIAS\GlobalScreen\Scope\Layout\Factory\TitleModification|null
     */
    public function getTitleModification(CalledContexts $screen_context_stack): ?TitleModification
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getShortTitleModification(CalledContexts $screen_context_stack): ?ShortTitleModification
    {
        return null;
    }

    /**
     * Edit the first part of the ILIAS page title.
     * @param ILIAS\GlobalScreen\ScreenContext\Stack\CalledContexts
     *     $screen_context_stack
     * @return
     *     ILIAS\GlobalScreen\Scope\Layout\Factory\ViewTitleModification|null
     */
    public function getViewTitleModification(CalledContexts $screen_context_stack): ?ViewTitleModification
    {
        if (!$this->plugin->isActive()) {
            return null;
        }

        if (!ilSeo::isPageIntendedForWeb()) {
            return null;
        }

        $current_context = $this->ensureRepoContext($screen_context_stack)->current();
        $ref_id = $current_context->getReferenceId()->toInt();

        if (!$ref_id || !$this->dic->access()->checkAccess("read", "", $ref_id)) {
            return null;
        }

        $object = $this->getObjectOfContext($current_context);
        if ($object == null) {
            return null;
        }
        $obj_type = $object->getType();

        $secondary_id_key = ilSeo::SECONDARY_ID_KEYS[$obj_type] ?? null;

        $secondary_id = 0;
        if ($secondary_id_key !== null && $this->dic->http()->wrapper()->query()->has($secondary_id_key)) {
            $secondary_id = $this->dic->http()->wrapper()->query()->retrieve(
                $secondary_id_key,
                $this->dic->refinery()->kindlyTo()->int()
            );
        }

        // The default-language row is stored under the "-" sentinel, not its
        // language code.
        $seo = new ilSeo();
        $lang = $this->dic->language()->getLangKey();
        $data = $seo->fetchById($ref_id, $secondary_id, ilSeoLanguage::toDbLang($lang));

        if ($data === null && $lang !== ilSeoLanguage::getDefaultLang()) {
            $data = $seo->fetchById($ref_id, $secondary_id, "-");
        }

        if ($data == null) {
            return null;
        }

        // <title> is RCDATA and core writes VIEW_TITLE raw, so escaping has to
        // happen here.
        $title = ilSeoEscape::html((string) $data["title"]);
        return $this->globalScreen()->layout()->factory()->view_title()
            ->withModification(
                function (?string $content) use ($title): string {
                    return $title;
                }
            )->withHighPriority();
    }
}
