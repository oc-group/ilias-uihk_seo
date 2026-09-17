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
 * Default-language resolution and the "-" DB sentinel mapping; split out of
 * ilSeo so that class stays under the PHPMD TooManyPublicMethods ceiling. No
 * constructor, DIC-safe by design: getDefaultLang() is also reachable from
 * go.php before initILIAS() has registered "lng" on $DIC (guarded, see
 * resolveSiteDefaultLangWithoutLng()).
 */
class ilSeoLanguage
{
    /**
     * Get the configured default SEO language code, falling back to ILIAS's own
     * site default if "seo_langs" was never saved. Also reachable from go.php
     * before initILIAS() has registered "lng" on $DIC (guarded below, see
     * resolveSiteDefaultLangWithoutLng()).
     * @return string
     */
    public static function getDefaultLang(): string
    {
        global $DIC;

        $default = (new ilSeoSettings())->getDefaultLang();

        if ($default !== "" && in_array($default, ilLanguage::_getInstalledLanguages(), true)) {
            return $default;
        }

        if (isset($DIC["lng"])) {
            return $DIC->language()->getDefaultLanguage();
        }

        return self::resolveSiteDefaultLangWithoutLng();
    }

    /**
     * Resolve the site default language using only "ilDB": mirrors
     * ilLanguage::__construct()'s own algorithm (client ini default, overridden
     * by the common ilSetting "language" row). Falls back to the "-" sentinel
     * if even "ilDB" isn't registered (no known call path today).
     * @return string
     */
    private static function resolveSiteDefaultLangWithoutLng(): string
    {
        global $DIC;

        if (!isset($DIC["ilDB"])) {
            return "-";
        }

        $default = "en";

        $ilias_ini = new ilIniFile("./ilias.ini.php");
        $ilias_ini->read();
        $web_dir = $ilias_ini->readVariable("clients", "path");
        $default_client_id = $ilias_ini->readVariable("clients", "default");

        $client_ini = new ilIniFile("./{$web_dir}/{$default_client_id}/client.ini.php");
        $client_ini->read();
        $ini_default = (string) $client_ini->readVariable("language", "default");
        if ($ini_default !== "") {
            $default = $ini_default;
        }

        $setting = new ilSetting();
        $setting_default = (string) $setting->get("language", "");
        if ($setting_default !== "") {
            $default = $setting_default;
        }

        return $default;
    }

    /**
     * Normalize to the "-" sentinel this data layer uses for the
     * default-language row. DB-facing only. Callers must keep using the literal
     * code for anything user-facing.
     * @param string $lang
     * @return string
     */
    public static function toDbLang(string $lang): string
    {
        return $lang === self::getDefaultLang() ? "-" : $lang;
    }
}
