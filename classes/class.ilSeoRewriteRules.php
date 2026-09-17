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
    /** @var string file the rules are written into, relative to the ILIAS root */
    private const TARGET = ".htaccess";

    /** @var string matches the plugin's own block, markers included */
    private const REGEXP = "/[\n]*^# BEGIN ilias-uihk_seo$.*^# END ilias-uihk_seo$\n/sm";

    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /**
     * @param ilSeoPlugin $plugin
     * @return void
     */
    public function __construct(ilSeoPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Write the plugin's block into the target file, replacing an older one if
     * it is there.
     *
     * The shape is deliberate and must stay: read the whole file, strip a
     * previous block, write the remainder back with the new block appended. On
     * the ILIAS 10 branch the file only ever holds this plugin's own block, so
     * the strip is a no-op there and this body is identical on both branches.
     * Do not reduce it to a plain write on either one.
     *
     * ILIAS_RELATIVE_PATH is set on a variable the template does not declare,
     * so it changes nothing. It pairs with the template's root-anchored
     * RewriteRule, correct only for an installation at the domain root; a
     * subdirectory installation would need exactly that prefix. Both are left
     * alone: the fix cannot be verified without such an installation, and
     * dropping the dead call would drop the only trace of the limitation.
     * @return bool false when the file could not be written
     */
    public function apply(): bool
    {
        $current = file_get_contents(self::TARGET);
        if ($current === false) {
            // A failed read must not be coerced to "", or the write below would
            // replace whatever the file held with just this plugin's own block.
            return false;
        }

        $code = $this->plugin->getTemplate("default/tpl.htaccess", false, true);
        $code->setVariable("ILIAS_HTTP_PATH", ILIAS_HTTP_PATH);

        $relative_path = parse_url(ILIAS_HTTP_PATH, PHP_URL_PATH);
        $code->setVariable("ILIAS_RELATIVE_PATH", $relative_path);

        if ($this->isApplied()) {
            $current = preg_replace(self::REGEXP, "", $current);
        }

        return file_put_contents(self::TARGET, "{$current}\n\n{$code->get()}") !== false;
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
        if (!$this->isApplied()) {
            return true;
        }

        $content = file_get_contents(self::TARGET);
        if ($content === false) {
            return true;
        }

        return file_put_contents(self::TARGET, preg_replace(self::REGEXP, "", $content)) !== false;
    }

    /**
     * Whether the target file already carries the plugin's block. Asked by both
     * writers to decide whether an older block has to go first; nothing outside
     * this class asks it.
     * @return bool
     */
    private function isApplied(): bool
    {
        $content = file_get_contents(self::TARGET);
        if ($content === false) {
            return false;
        }

        return preg_match(self::REGEXP, $content) === 1;
    }
}
