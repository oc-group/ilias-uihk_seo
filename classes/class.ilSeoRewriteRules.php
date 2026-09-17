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
 * The permalink rewrite rules the plugin installs into the web server
 * configuration.
 *
 * Deliberately free of DIC services. The callers are the plugin's activation,
 * deactivation and uninstall hooks plus the settings screen's save, and the
 * uninstall hook can run without a full request context; the only collaborator
 * is the plugin itself, for its template loader.
 *
 * TARGET is the single line that differs between this plugin's ILIAS 9 and
 * ILIAS 10 branches, which is why neither the class nor any method is named
 * after the file. On ILIAS 10 the rules are written under Customizing/ instead:
 * the webroot there is a build artefact that a composer autoload dump purges
 * and recreates, and only Customizing/ and data/ survive that. A future move of
 * the file has to stay inside one of those two, or the rules disappear at the
 * next build.
 */
class ilSeoRewriteRules
{
    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /**
     * Absolute filesystem path to the Apache rewrite-rules file.
     * @return string
     */
    private function apacheTargetPath(): string
    {
        return ilSeoPlugin::PLUGIN_FS_DIR . "/assets/apache.conf";
    }

    /**
     * Absolute filesystem path to the nginx config file.
     * @return string
     */
    private function nginxTargetPath(): string
    {
        return ilSeoPlugin::PLUGIN_FS_DIR . "/assets/nginx.conf";
    }

    /**
     * @param ilSeoPlugin $plugin
     * @return void
     */
    public function __construct(ilSeoPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Best-effort web-server detection, from SERVER_SOFTWARE alone. This never
     * gates which file(s) apply() writes (see the class docblock); it only
     * tells the settings screen's status panel which generated file to lead
     * with. Falls back to false under CLI/PHPUnit, where the key is simply
     * absent, and on any value that is not the plain string a real HTTP SAPI
     * sets it to.
     * @return bool
     */
    public static function isNginx(): bool
    {
        $software = $_SERVER["SERVER_SOFTWARE"] ?? "";

        return is_string($software) && stripos($software, "nginx") !== false;
    }

    /**
     * The Apache block's path in the shape shown to an administrator: relative
     * to the ILIAS installation, matching how the plugin already names itself
     * elsewhere (see ilSeoPlugin::PLUGIN_DIR), not the raw filesystem path
     * apacheTargetPath() resolves for file I/O.
     * @return string
     */
    public function apacheDisplayPath(): string
    {
        return ltrim(ilSeoPlugin::PLUGIN_DIR, "./") . "/assets/apache.conf";
    }

    /**
     * The nginx snippet's path in the shape shown to an administrator: relative
     * to the ILIAS installation, matching how the plugin already names itself
     * elsewhere (see ilSeoPlugin::PLUGIN_DIR), not the raw filesystem path
     * nginxTargetPath() resolves for file I/O.
     * @return string
     */
    public function nginxDisplayPath(): string
    {
        return ltrim(ilSeoPlugin::PLUGIN_DIR, "./") . "/assets/nginx.conf";
    }

    /**
     * The Apache block currently on disk, for the settings screen's status
     * panel to show verbatim (the Apache-side twin of nginxConfigContents()
     * below, which documents the null semantics and the "verbatim ends at this
     * return value" caveat both methods share).
     * @return string|null
     */
    public function apacheConfigContents(): ?string
    {
        return $this->readFile($this->apacheTargetPath());
    }

    /**
     * One-time vhost snippet an administrator pastes into their own Apache
     * <Directory> block, with this installation's real absolute path. Unlike
     * apacheConfigContents()'s regenerated rule block (what apply() writes and
     * Apache re-reads), this path is stable for the life of the installation
     * and never needs re-pasting. Shown unconditionally: IncludeOptional
     * tolerates a missing target file, so this is safe even before the first
     * apply().
     *
     * realpath() only cleans up the displayed string; PLUGIN_FS_DIR's own "/.."
     * segment already works for PHP's file functions either way, but left
     * unresolved it would show a confusing literal path. Falls back to the
     * unresolved path on failure (a symlink or permission edge case), still
     * valid for Apache to read.
     * @return string
     */
    public function apacheIncludeSnippet(): string
    {
        $plugin_dir = realpath(ilSeoPlugin::PLUGIN_FS_DIR) ?: ilSeoPlugin::PLUGIN_FS_DIR;

        return "IncludeOptional " . $plugin_dir . "/assets/apache.conf\nRewriteOptions Inherit";
    }

    /**
     * Illustrative php-fpm block for the settings screen's non-standard-port
     * note.
     * @return string
     */
    public function nginxPortFixSnippet(): string
    {
        return implode("\n", [
            "location ~ ^(.+\.php)(/.+)?\$ {",
            "    fastcgi_split_path_info ^(.+\.php)(/.+)?\$;",
            "    fastcgi_pass unix:/run/php/php-fpm.sock;",
            "    include fastcgi_params;",
            "    fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;",
            "    fastcgi_param PATH_INFO \$fastcgi_path_info;",
            "    fastcgi_param HTTP_HOST \$http_host;",
            "}",
        ]);
    }

    /**
     * The nginx block, for the settings screen's status panel to show verbatim.
     * Built in memory, not read from disk (see class docblock): apply() no
     * longer writes assets/nginx.conf at all, since nginx never reads it back
     * either, so there is nothing to read here that this method could not build
     * itself just as well. Always succeeds (no file I/O to fail), unlike
     * apacheConfigContents(), which is why this one returns a plain string
     * rather than a nullable one.
     *
     * "Verbatim" ends at this return value: whoever embeds this in a page still
     * has to route it through ilSeoEscape::html() first, never
     * htmlspecialchars() directly. See that method's own docblock for why a
     * generated config's content specifically depends on it.
     * @return string
     */
    public function nginxConfigContents(): string
    {
        $nginx = $this->plugin->getTemplate("default/tpl.nginx.conf", false, true);
        $nginx->setVariable("ILIAS_HTTP_PATH", ILIAS_HTTP_PATH);
        $nginx->setVariable("ILIAS_RELATIVE_PATH", parse_url(ILIAS_HTTP_PATH, PHP_URL_PATH));

        return $nginx->get();
    }

    /**
     * Shared by apacheConfigContents()/nginxConfigContents(): null before the
     * first successful apply() (a fresh install not yet activated) or when the
     * file has since become unreadable. Both read the same as "nothing to show
     * yet" to the caller, which is the only distinction either panel block
     * needs.
     * @param string $target
     * @return string|null
     */
    private function readFile(string $target): ?string
    {
        if (!is_file($target)) {
            return null;
        }

        $content = file_get_contents($target);

        return $content !== false ? $content : null;
    }

    /**
     * Write the plugin's rewrite rules into the Apache target file. nginx has
     * no target file to write any more (see class docblock and
     * nginxConfigContents()): apply() used to write assets/nginx.conf too, but
     * nothing ever read that file back except this class's own display code,
     * which now builds the same content in memory instead.
     *
     * ILIAS_RELATIVE_PATH is set on a variable the template does not declare,
     * so it changes nothing today. It pairs with the template's root-anchored
     * rewrite rule, correct only for an installation at the domain root; a
     * subdirectory installation would need exactly that prefix. Left alone: the
     * fix cannot be verified without such an installation, and dropping the
     * dead call would drop the only trace of the limitation.
     * @return bool false when the file could not be written
     */
    public function apply(): bool
    {
        $apache = $this->plugin->getTemplate("default/tpl.apache.conf", false, true);
        $apache->setVariable("ILIAS_HTTP_PATH", ILIAS_HTTP_PATH);
        $apache->setVariable("ILIAS_RELATIVE_PATH", parse_url(ILIAS_HTTP_PATH, PHP_URL_PATH));

        return file_put_contents($this->apacheTargetPath(), $apache->get()) !== false;
    }

    /**
     * Strip the plugin's block out of the target file, leaving whatever else it
     * holds.
     *
     * Strip and rewrite rather than unlink, for the same reason apply() keeps
     * its shape: on ILIAS 9 the file belongs to the installation, and on the
     * ILIAS 10 branch an emptied file is what the next apply() expects to find.
     * A file that cannot be read counts as done, since there is no block left
     * in it either way.
     * @return bool false only when the rewrite itself failed
     */
    public function remove(): bool
    {
        $apache_removed = $this->removeFile($this->apacheTargetPath());
        $nginx_removed = $this->removeFile($this->nginxTargetPath());

        return $apache_removed && $nginx_removed;
    }

    /**
     * @param string $target
     * @return bool false only when an existing file could not be deleted
     */
    private function removeFile(string $target): bool
    {
        if (!is_file($target)) {
            return true;
        }

        return unlink($target);
    }
}
