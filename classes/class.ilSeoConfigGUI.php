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
 * Plugin configuration GUI: the classic ilCtrl container for the plugin's two
 * administration screens, reached the way a plugin with no promoted
 * Administration menu entry is reached (Administration > Plugins > Seo >
 * Configure, via core's ilObjComponentSettingsGUI forwarding).
 *
 * Registers two tabs, Overview first and Configuration second, and forwards
 * next_class to whichever screen the tab links to. The bare "configure"
 * command, reached with no next_class at all (what core's own Configure action
 * in the plugin list builds), defaults to Overview, the plugin's primary
 * screen. Neither screen sets its own page header: this class owns it. This
 * plugin has no Administration menu group of its own; a companion plugin's own
 * group links into these same two screens, through this exact route.
 *
 * @ilCtrl_isCalledBy ilSeoConfigGUI: ilObjComponentSettingsGUI
 * @ilCtrl_Calls ilSeoConfigGUI: ilSeoOverviewGUI, ilSeoSettingsGUI
 */
class ilSeoConfigGUI extends ilPluginConfigGUI
{
    /** @var string default command: the bare "Configure" action core's plugin list builds */
    public const CMD_CONFIGURE = "configure";

    /** @var string first-level tab identifier for the Overview screen */
    private const TAB_OVERVIEW = "tab_overview";

    /** @var string first-level tab identifier for the Configuration screen */
    private const TAB_CONFIGURATION = "tab_configuration";

    /** @var ilCtrlInterface */
    private ilCtrlInterface $ctrl;

    /** @var ilGlobalTemplateInterface */
    private ilGlobalTemplateInterface $tpl;

    /** @var ilTabsGUI */
    private ilTabsGUI $tabs;

    /** @var ilSeoPlugin */
    private ilSeoPlugin $plugin;

    /**
     * @return void
     */
    public function __construct()
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->tabs = $DIC->tabs();

        $this->plugin = ilSeoPlugin::getInstance();
    }

    /**
     * Forwards to whichever screen the URL names as next class, defaulting to
     * Overview when none is named. Both screens require "write" on the
     * administration root, enforced here before the forward and again inside
     * each screen's own executeCommand().
     *
     * @param string $cmd
     * @throws ilException if the command is not known
     * @return void
     */
    public function performCommand($cmd): void
    {
        $next_class = strtolower((string) $this->ctrl->getNextClass($this));

        if ($next_class === strtolower(ilSeoOverviewGUI::class)) {
            $this->forwardToOverview();
            return;
        }

        if ($next_class === strtolower(ilSeoSettingsGUI::class)) {
            $this->forwardToSettings();
            return;
        }

        switch ($cmd) {
            case self::CMD_CONFIGURE:
                $this->forwardToOverview();
                break;

            default:
                throw new ilException("Unknown command: '$cmd'");
        }
    }

    /**
     * Forward to the Overview screen: page header, tabs, then the gate and the
     * forward itself.
     * @return void
     */
    private function forwardToOverview(): void
    {
        $this->initHeader(self::TAB_OVERVIEW, "screen_overview_title", "screen_overview_desc", ilSeoPlugin::iconPath("seo_overview"));
        ilSeoAccess::requireAdminWrite();
        $this->ctrl->forwardCommand(new ilSeoOverviewGUI());
    }

    /**
     * Forward to the Configuration screen: page header, tabs, then the gate and
     * the forward itself.
     * @return void
     */
    private function forwardToSettings(): void
    {
        $this->initHeader(
            self::TAB_CONFIGURATION,
            "screen_config_title",
            "screen_config_desc",
            // The core administration icon, resolved through the skin rather
            // than copied into the plugin, so a skin overriding it is honoured.
            ilUtil::getImagePath("standard/icon_adm.svg")
        );
        ilSeoAccess::requireAdminWrite();
        $this->ctrl->forwardCommand(new ilSeoSettingsGUI());
    }

    /**
     * Register both tabs, activate the one being shown, and restate the page
     * header: ilPluginConfigGUI::executeCommand() has already set a generic
     * "Plugin: Seo" title and an empty description before reaching here, so
     * both are replaced with the screen's own strings, plus a title icon
     * neither screen sets for itself.
     * @param string $active_tab one of the TAB_* constants
     * @param string $title_key
     * @param string $desc_key
     * @param string $icon_path web path of the icon file
     * @return void
     */
    private function initHeader(string $active_tab, string $title_key, string $desc_key, string $icon_path): void
    {
        $this->tabs->addTab(
            self::TAB_OVERVIEW,
            $this->plugin->txt("screen_overview_title"),
            $this->ctrl->getLinkTargetByClass([self::class, ilSeoOverviewGUI::class], ilSeoOverviewGUI::CMD_SHOW)
        );
        $this->tabs->addTab(
            self::TAB_CONFIGURATION,
            $this->plugin->txt("screen_config_title"),
            $this->ctrl->getLinkTargetByClass([self::class, ilSeoSettingsGUI::class], ilSeoSettingsGUI::CMD_SHOW)
        );
        $this->tabs->activateTab($active_tab);

        $this->tpl->setTitle($this->plugin->txt($title_key));
        $this->tpl->setDescription($this->plugin->txt($desc_key));
        $this->tpl->setTitleIcon($icon_path);
    }
}
