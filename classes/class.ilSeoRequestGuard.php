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
 * The CSRF guard of the commands that change state.
 *
 * ilCtrl enforces the rtoken only when the request names a command class. A
 * request that keeps the cmdNode path but omits cmdClass is judged against the
 * base class (ilUIPluginRouterGUI or ilAdministrationGUI), which declares no
 * unsafe commands, yet it still reaches this plugin's GUIs. So every command
 * listed in getUnsafeGetCommands() of a GUI is checked here, by that GUI's
 * executeCommand(), before any side effect.
 */
class ilSeoRequestGuard
{
    /**
     * Whether a command must be refused: it changes state (it is listed in the
     * GUI's getUnsafeGetCommands()) and the request does not carry the
     * session's CSRF token.
     * @param string $cmd the command about to run
     * @param string[] $protected the GUI's state-changing commands
     * @return bool
     */
    public static function refuses(string $cmd, array $protected): bool
    {
        return in_array($cmd, $protected, true) && !self::hasValidToken();
    }

    /**
     * The command to run: the empty command when it was refused.
     * @param string $cmd the command about to run
     * @param bool $refused result of refuses() for it
     * @return string
     */
    public static function commandUnlessRefused(string $cmd, bool $refused): string
    {
        return [$cmd, ""][(int) $refused];
    }

    /**
     * Whether the request carries the session's CSRF token. A missing,
     * malformed or wrong token is refused (fail closed).
     * @return bool
     */
    public static function hasValidToken(): bool
    {
        global $DIC;

        $query = $DIC->http()->wrapper()->query();
        if (!$query->has(ilCtrlInterface::PARAM_CSRF_TOKEN)) {
            return false;
        }

        try {
            $sent = $query->retrieve(
                ilCtrlInterface::PARAM_CSRF_TOKEN,
                $DIC->refinery()->kindlyTo()->string()
            );
        } catch (\Throwable $e) {
            return false;
        }

        return (new ilCtrlTokenRepository())->getToken()->verifyWith($sent);
    }
}
