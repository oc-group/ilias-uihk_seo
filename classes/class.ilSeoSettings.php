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
 * Typed accessors over the installation's SEO settings, held in the core
 * "settings" table under the module named by self::MODULE. One named getter and
 * setter per key, so no caller has to know the key string, the stored encoding
 * or the default.
 *
 * Constructed without arguments rather than injected: several callers run under
 * a partial bootstrap, and injecting the store would make every one of them
 * name the module. Only the uninstall wipe needs it by name, which is what
 * self::MODULE is public for.
 */
final class ilSeoSettings
{
    /** @var string ilSetting module every key below is stored under */
    public const MODULE = "plugin_" . ilSeoPlugin::PLUGIN_ID;

    /** @var string site name published in the og:site_name tag */
    private const KEY_WEB_TITLE = "web_title";

    /** @var string whether the sitemap cron job pings the search engines after a successful build */
    private const KEY_SUBMIT_SITEMAP = "submit_sitemap";

    /** @var string JSON object: {"default": string, "secondary": string[]} */
    private const KEY_LANGUAGES = "seo_langs";

    /** @var ilSetting */
    private ilSetting $store;

    /**
     * ilSetting keeps one row set per module for the whole request, so building
     * this repeatedly costs no extra query.
     * @return void
     */
    public function __construct()
    {
        $this->store = new ilSetting(self::MODULE);
    }

    /**
     * The site name published alongside every page, defaulting to the
     * installation URL.
     *
     * The trim-empty check below is a read-side repair, not a duplicate of the
     * write-side mandatory rule in ilSeoFormValidation::mandatory(). That rule
     * stops a NEW empty title from being saved; it does nothing for an
     * installation whose stored value was already empty or whitespace-only
     * before that validation existed. ilSetting::set() persists "" as a real
     * row rather than deleting it, and ilSetting::get()'s own default only
     * fires when the key was never written, so a stored "" would otherwise come
     * back verbatim forever. Treating a stored value that is empty after trim()
     * as if it were unset restores the same ILIAS_HTTP_PATH default a
     * never-written key already gets.
     * @return string
     */
    public function getWebTitle(): string
    {
        $stored = $this->getString(self::KEY_WEB_TITLE, ILIAS_HTTP_PATH);

        return trim($stored) === "" ? ILIAS_HTTP_PATH : $stored;
    }

    /**
     * @param string $web_title
     * @return void
     */
    public function setWebTitle(string $web_title): void
    {
        $this->setString(self::KEY_WEB_TITLE, $web_title);
    }

    /**
     * @return bool
     */
    public function isSubmitSitemap(): bool
    {
        return $this->getBool(self::KEY_SUBMIT_SITEMAP);
    }

    /**
     * @param bool $submit
     * @return void
     */
    public function setSubmitSitemap(bool $submit): void
    {
        $this->setBool(self::KEY_SUBMIT_SITEMAP, $submit);
    }

    /**
     * The configured default SEO language code exactly as stored, or "" when
     * nothing is stored. The code is not checked against the installed
     * languages here. ilSeoLanguage::getDefaultLang() is the accessor that
     * validates it and falls back to the site default.
     * @return string
     */
    public function getDefaultLang(): string
    {
        $default = $this->languages()["default"] ?? "";

        return is_string($default) ? $default : "";
    }

    /**
     * The configured secondary SEO language codes, [] when nothing is stored.
     * Non-string entries are dropped, so the result is always a list of
     * strings.
     * @return string[]
     */
    public function getSecondaryLangs(): array
    {
        $secondary = $this->languages()["secondary"] ?? [];
        if (!is_array($secondary)) {
            return [];
        }

        return array_values(array_filter($secondary, "is_string"));
    }

    /**
     * Overwrite the whole language configuration: there is no partial update,
     * the language form always rebuilds both values from its own POST.
     * @param string $default
     * @param string[] $secondary
     * @return void
     */
    public function setLanguages(string $default, array $secondary): void
    {
        $config = [
            "default" => $default,
            "secondary" => array_values($secondary),
        ];

        $this->setString(self::KEY_LANGUAGES, json_encode($config) ?: "{}");
    }

    /**
     * The decoded language configuration, always an array: malformed or absent
     * JSON yields [], which makes both language getters total.
     * @return array<string, mixed>
     */
    private function languages(): array
    {
        $decoded = json_decode($this->getString(self::KEY_LANGUAGES, "{}"), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    private function getString(string $key, string $default): string
    {
        return (string) $this->store->get($key, $default);
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    private function setString(string $key, string $value): void
    {
        $this->store->set($key, $value);
    }

    /**
     * Stored as the "y"/"n" strings existing installations already hold, so no
     * migration is needed. Reading is case-insensitive and anything that is not
     * "y" is false.
     * @param string $key
     * @return bool
     */
    private function getBool(string $key): bool
    {
        return strtolower($this->getString($key, "n")) === "y";
    }

    /**
     * @param string $key
     * @param bool $value
     * @return void
     */
    private function setBool(string $key, bool $value): void
    {
        $this->setString($key, $value ? "y" : "n");
    }
}
