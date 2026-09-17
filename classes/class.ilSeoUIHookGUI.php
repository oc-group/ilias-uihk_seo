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

use ilSeo\ObjectHandlers\AbstractSpecialPageHandler;
use ilSeo\ObjectHandlers\HandlerFactory;

/**
 * UI Hook class.
 *
 * @ilCtrl_Calls ilSeoUIHookGUI: ilRepositoryGUI
 */
class ilSeoUIHookGUI extends ilUIHookPluginGUI
{
    /** @var ilCtrlInterface */
    protected ilCtrlInterface $ctrl;

    /** @var ilLanguage */
    protected ilLanguage $lng;

    /** @var ilDBInterface */
    protected ilDBInterface $db;

    /** @var ilSeoPlugin */
    protected ilSeoPlugin $plugin;

    /**
     * UI Hook class constructor.
     */
    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->lng = $DIC->language();
        $this->db = $DIC->database();

        $this->plugin = ilSeoPlugin::getInstance();
    }

    /**
     * Check permalink and redirect to the target.
     * @return void
     */
    public function gotoHook(): void
    {
        global $DIC;

        $http = $DIC->http();
        $request_builder = $DIC["static_url.request_builder"];
        // LegacyGotoHandler is @internal/@deprecated, the only handler parsing
        // goto.php?target=xyz_123; no public replacement exists.
        /** @psalm-suppress InternalClass */
        $request = $request_builder->buildRequest(
            $http,
            $DIC->refinery(),
            [new ILIAS\StaticURL\Handler\LegacyGotoHandler()]
        );
        $requested_target = $request->getAdditionalParameters()["target"] ?? "";
        $target_arr = explode("_", $requested_target);
        $target_type = $target_arr[0];
        $target_id = $target_arr[1] ?? "";
        $additional = $target_arr[2] ?? "";

        if (!empty($additional) && !isset(ilSeo::SECONDARY_ID_KEYS[$target_type])) {
            return;
        }

        $handler = HandlerFactory::forType($target_type);
        if ($handler instanceof AbstractSpecialPageHandler) {
            // Special page (e.g. imprint) has no ref id.
            ilSeoInitialisation::redirectPermanent($handler->getGotoRedirectTarget());
        }

        if (empty($target_id)) {
            // Cannot be handled by SEO plugin.
            return;
        }

        $seo = new ilSeo();
        $lang = $this->lng->getLangKey();
        $secondary_id = !empty($additional) ? (int) $additional : 0;
        // Normalize to the "-" sentinel for the default-language row
        // (ilSeoLanguage::toDbLang()); a literal language code would miss it.
        $data = $seo->fetchById((int) $target_id, $secondary_id, ilSeoLanguage::toDbLang($lang));
        if ($data == null) {
            // This object is not handled by SEO plugin.
            return;
        }

        $permalink = (string) $data["permalink"];
        if (!ilSeoPermalink::isValid($permalink, $permalink === "")) {
            // Broken permalink stored for this row: fall through to native
            // ILIAS goto dispatch instead of redirecting into a dead link.
            return;
        }

        ilSeoInitialisation::redirectPermanent($permalink);
    }

    /**
     * Do something whenever a template file is loaded/shown.
     *
     * @param string $a_comp component name (e.g. Services/Utilities,
     *     Services/Dashboard, Services/MainMenu, Services/Container)
     * @param string $a_part template operation (template_load, template_get or
     *     template_show)
     * @param array<string, mixed> $a_par contains template data, including
     *     tpl_id and html
     *
     * @return array{mode: string, html: string} HTML operation (APPEND,
     *     PREPEND, REPLACE, KEEP) and HTML code
     * @see ilUIHookPluginGUI
     */
    public function getHTML(
        string $a_comp,
        string $a_part,
        array $a_par = []
    ): array {
        if ($this->isMainPageTemplate($a_comp, $a_part, $a_par)) {
            return $this->fixedMainPage($a_comp, $a_part, $a_par);
        }

        return parent::getHTML($a_comp, $a_part, $a_par);
    }

    /**
     * Check ctrl position by cmdnode.
     * @param string[] $classes names
     * @return bool true iff the current ctrl path is the given one
     */
    protected function checkCmdNode(array $classes): bool
    {
        $class_path = $this->ctrl->getCurrentClassPath();
        $class_count = count($class_path);
        if ($class_count != count($classes)) {
            return false;
        }
        for ($i = 0; $i < $class_count; $i++) {
            if (strtolower($class_path[$i]) != strtolower($classes[$i])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return true if the main page template is being used.
     * @param string $a_comp
     * @param string $a_part
     * @param array<string, mixed> $a_par
     * @return bool
     */
    protected function isMainPageTemplate(
        string $a_comp,
        string $a_part,
        array $a_par = []
    ): bool {
        $tpl = "src/UI/templates/default/Layout/tpl.standardpage.html";
        return isset($a_par["tpl_id"]) && $a_par["tpl_id"] == $tpl && $a_part == "template_get";
    }

    /**
     * Replace the page title tag in the html.
     * @param string $a_comp
     * @param string $a_part
     * @param array<string, mixed> $a_par
     * @return array{mode: string, html: string}
     */
    protected function fixedMainPage(
        string $a_comp,
        string $a_part,
        array $a_par = []
    ): array {
        $html = $a_par["html"];

        // The rewrite only has meaning on a page served at a permalink slug;
        // parsing every other page was wasted work and a source of silent
        // corruption of inline scripts. Gate the whole round-trip on it.
        if (!defined("IL_SEO_PERMALINK_REQUEST")) {
            return [
                "mode" => ilUIHookPluginGUI::REPLACE,
                "html" => $html,
            ];
        }

        try {
            $html = $this->rewritePermalinks($html);
        } catch (Throwable $e) {
            // A rewriting failure must never take down the whole page, so fall
            // back to the original, unrewritten HTML.
            $this->plugin->logger->error(
                "SEO permalink link rewriting failed, serving original HTML: " . $e->getMessage()
            );
            $html = $a_par["html"];
        }

        return [
            "mode" => ilUIHookPluginGUI::REPLACE,
            "html" => $html,
        ];
    }

    /** @var string one <script ...>...</script> element, quote-aware and non-greedy so it stops at the first close */
    private const SCRIPT_ELEMENT_PATTERN = '/(<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?<\/script\s*>)/is';

    /** @var string one <a ...> start tag, quote-aware so a ">" inside a quoted attribute value is never its own close */
    private const ANCHOR_OPEN_TAG_PATTERN = '/<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

    /** @var string one <script ...> start tag, same quote-aware shape as ANCHOR_OPEN_TAG_PATTERN */
    private const SCRIPT_OPEN_TAG_PATTERN = '/<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

    /**
     * Rewrite internal links to SEO-managed objects to their clean permalink.
     * String-based, not DOM-based: a full parse/re-serialize round-trip on a
     * real page can corrupt inline <script> content it never needed to touch.
     * The page is split into <script> regions and everything else; only the
     * non-script regions are searched for <a href> to rewrite, and only the
     * script regions are searched for the fragment-navigation idiom
     * neutralizeHashNavigation() compensates for.
     * @param string $html
     * @return string
     */
    private function rewritePermalinks(string $html): string
    {
        $seo = new ilSeo();
        // Prime the static cache with one query: every fetchById() below then
        // reads from memory, regardless of how many links are on the page.
        $seo->fetchAll();
        $lang = ilSeoLanguage::toDbLang($this->lng->getLangKey());

        $html = $this->ensureBaseHref($html);

        $segments = preg_split(self::SCRIPT_ELEMENT_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            return $html;
        }

        foreach ($segments as $index => $segment) {
            // Odd indices are the captured <script>...</script> delimiters;
            // even indices are the non-script text between them.
            $segments[$index] = ($index % 2 === 1)
                ? $this->neutralizeHashNavigation($segment)
                : $this->rewriteAnchorHrefs($segment, $seo, $lang);
        }

        return implode("", $segments);
    }

    /**
     * Rewrite every <a href> found in a non-script region.
     * @param string $html
     * @param ilSeo $seo
     * @param string $lang
     * @return string
     */
    private function rewriteAnchorHrefs(string $html, ilSeo $seo, string $lang): string
    {
        $rewritten = preg_replace_callback(
            self::ANCHOR_OPEN_TAG_PATTERN,
            fn (array $tag_match): string => $this->rewriteHrefInAnchorTag($tag_match[0], $seo, $lang),
            $html
        );

        return $rewritten !== null ? $rewritten : $html;
    }

    /**
     * Find the href attribute inside one <a ...> start tag and replace only
     * its value, leaving the rest of the tag byte-identical. Attributes are
     * matched left to right as name=value pairs, so a quoted value belonging
     * to an earlier attribute (e.g. title="says href=x") consumes its own
     * quotes as one token and can never be mistaken for the href attribute.
     * @param string $tag
     * @param ilSeo $seo
     * @param string $lang
     * @return string
     */
    private function rewriteHrefInAnchorTag(string $tag, ilSeo $seo, string $lang): string
    {
        $found = preg_match_all(
            '/([A-Za-z_:][-A-Za-z0-9_:.]*)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/',
            $tag,
            $attributes,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        if (!$found) {
            return $tag;
        }

        foreach ($attributes as $attribute) {
            [$name] = $attribute[1];
            if (strcasecmp($name, "href") !== 0) {
                continue;
            }

            [$raw_value, $value_offset] = $attribute[3];
            $quote = ($raw_value !== "" && ($raw_value[0] === "\"" || $raw_value[0] === "'"))
                ? $raw_value[0]
                : "";
            $inner = $quote !== "" ? substr($raw_value, 1, -1) : $raw_value;

            $href = html_entity_decode($inner, ENT_QUOTES | ENT_HTML5);
            if ($href === "") {
                return $tag;
            }

            $new_href = $this->resolveHrefTarget($href, $seo, $lang);
            if ($new_href === null) {
                return $tag;
            }

            // An unquoted original value is re-quoted with double quotes: the
            // rewritten value is always a "/"-led path, safe either way, and
            // quoting removes any ambiguity about where the value ends.
            $replacement_quote = $quote !== "" ? $quote : "\"";
            $new_raw_value = $replacement_quote
                . htmlspecialchars($new_href, ENT_QUOTES | ENT_HTML5)
                . $replacement_quote;

            return substr($tag, 0, $value_offset)
                . $new_raw_value
                . substr($tag, $value_offset + strlen($raw_value));
        }

        return $tag;
    }

    /**
     * Resolve a decoded href to its rewritten target, or null to leave the
     * original href untouched.
     * @param string $href
     * @param ilSeo $seo
     * @param string $lang
     * @return string|null
     */
    private function resolveHrefTarget(string $href, ilSeo $seo, string $lang): ?string
    {
        $target = ilSeoLinkTargetResolver::resolveTarget($href);
        if ($target === null) {
            return null;
        }

        $special_handler = HandlerFactory::forType($target["type"]);
        if ($special_handler instanceof AbstractSpecialPageHandler) {
            // Special page (e.g. imprint) has no ref id.
            return "/" . $special_handler->getGotoRedirectTarget();
        }

        // Normalize to the "-" sentinel for the default-language row; a
        // literal language code would miss it.
        $data = $seo->fetchById($target["ref_id"], $target["secondary_id"], $lang);
        if ($data === null) {
            // Not handled by SEO plugin.
            return null;
        }

        $permalink = (string) $data["permalink"];
        if (!ilSeoPermalink::isValid($permalink, $permalink === "")) {
            // Broken permalink stored for this row: leave the original href
            // untouched.
            return null;
        }

        return "/" . $permalink;
    }

    /**
     * Neutralize core's "do nothing" idiom inside a script region. Under a
     * <base href>, "window.location = '#';" no longer resolves against the
     * current page and instead navigates to the site root. Only the
     * assignment statement is replaced, by an empty statement, so the
     * surrounding handler and its own return value are untouched.
     * @param string $script_segment
     * @return string
     */
    private function neutralizeHashNavigation(string $script_segment): string
    {
        $result = preg_replace(
            '/window\s*\.\s*location\s*=\s*([\'"])#\1\s*;/',
            ";",
            $script_segment
        );

        return $result !== null ? $result : $script_segment;
    }

    /**
     * Ensure the page has a <base href>, but only for a permalink request
     * served via go.php. A normal ilias.php request must never get one
     * injected.
     *
     * A <base> changes the resolution base for the whole document, not just
     * the asset paths it is there for: core's own "do nothing" idiom,
     * <a href="#">, and any <a href="#section">, stop resolving against the
     * current page and navigate to the site root instead. injectFragmentGuard()
     * compensates for that, and runs whenever a <base> ends up on the page
     * here, whether this method inserted it or one was already present.
     * @param string $html
     * @return string
     */
    private function ensureBaseHref(string $html): string
    {
        if (!defined("IL_SEO_PERMALINK_REQUEST")) {
            return $html;
        }

        if (!preg_match('/<head\b[^>]*>/i', $html, $head_open, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $head_start = $head_open[0][1] + strlen($head_open[0][0]);
        $head_end = preg_match('/<\/head\s*>/i', $html, $head_close, PREG_OFFSET_CAPTURE, $head_start)
            ? $head_close[0][1]
            : strlen($html);

        $head_region = substr($html, $head_start, $head_end - $head_start);

        if (preg_match('/<base\b[^>]*>/i', $head_region, $base, PREG_OFFSET_CAPTURE)) {
            $insert_at = $base[0][1] + strlen($base[0][0]);
        } elseif (defined("ILIAS_HTTP_PATH")) {
            $base_tag = '<base href="'
                . htmlspecialchars(rtrim(ILIAS_HTTP_PATH, "/") . "/", ENT_QUOTES | ENT_HTML5)
                . '">';
            $head_region = $base_tag . $head_region;
            $insert_at = strlen($base_tag);
        } else {
            // No <base> ended up on the page (ILIAS_HTTP_PATH undefined and
            // none pre-existing): nothing resolves differently, so there is
            // nothing for the guard to compensate for.
            return $html;
        }

        $head_region = $this->injectFragmentGuard($head_region, $insert_at);

        return substr($html, 0, $head_start) . $head_region . substr($html, $head_end);
    }

    /**
     * Insert the static script that keeps "#" and "#fragment" links working
     * once a <base> is on the page, at the given offset into $head_region
     * (immediately after the <base> element itself), so the compensation
     * cannot end up on the page without its cause, or vice versa. A no-op if
     * the guard is already present anywhere in the head region, so running
     * the whole rewrite twice over the same page never duplicates it.
     *
     * Shipped as a real file, not an inline <script> or a data: URL: a
     * strict Content-Security-Policy on an installation this plugin does not
     * control can block both.
     * @param string $head_region
     * @param int $insert_at
     * @return string
     */
    private function injectFragmentGuard(string $head_region, int $insert_at): string
    {
        if ($this->headRegionHasFragmentGuard($head_region)) {
            return $head_region;
        }

        $script = '<script src="' . ilSeoPlugin::PLUGIN_DIR . '/templates/js/seo-fragment-guard.js"></script>';

        return substr($head_region, 0, $insert_at) . $script . substr($head_region, $insert_at);
    }

    /**
     * Whether a <script src="...seo-fragment-guard.js"> is already present
     * anywhere in the head region, regardless of who put it there.
     * @param string $head_region
     * @return bool
     */
    private function headRegionHasFragmentGuard(string $head_region): bool
    {
        if (!preg_match_all(self::SCRIPT_OPEN_TAG_PATTERN, $head_region, $script_tags)) {
            return false;
        }

        foreach ($script_tags[0] as $script_tag) {
            if (!preg_match('/\bsrc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $script_tag, $src_match)) {
                continue;
            }

            $raw_value = $src_match[1];
            $quote = ($raw_value !== "" && ($raw_value[0] === "\"" || $raw_value[0] === "'"))
                ? $raw_value[0]
                : "";
            $src = $quote !== "" ? substr($raw_value, 1, -1) : $raw_value;

            if (str_ends_with($src, "seo-fragment-guard.js")) {
                return true;
            }
        }

        return false;
    }
}
