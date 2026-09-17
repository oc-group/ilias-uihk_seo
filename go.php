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

use ilSeo\ObjectHandlers\HandlerFactory;

// The plugin's install path is 8 segments below the true ILIAS root:
// public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Seo. ILIAS 10 nests
// the webserver docroot under public/, while vendor/ and ilias.ini.php stay one level above it.
$ilias_root = realpath(dirname(__DIR__, 8));
if ($ilias_root === false || !is_file($ilias_root . "/ilias.ini.php")) {
    // Resolved path does not look like an ILIAS root; fail loudly instead of chdir()'ing blindly.
    header($_SERVER["SERVER_PROTOCOL"] . " 500", true, 500);
    echo "Configuration missing. Contact the administrator.";
    exit;
}
chdir($ilias_root);
require_once "./vendor/composer/vendor/autoload.php";

// Read by ilSeoUIHookGUI::ensureBaseHref() to scope <base href> injection to
// permalink requests only.
define("IL_SEO_PERMALINK_REQUEST", true);

// Convert PHP errors to exceptions so the handler below catches them all.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Handle uncaught exceptions before ILIAS is initialized.
// After ilInitialisation::initILIAS() ILIAS installs its own handler.
set_exception_handler(static function (Throwable $e) use ($ilias_root): never {
    error_log(
        get_class($e) . ": " . $e->getMessage() .
        " in " . $e->getFile() . ":" . $e->getLine() .
        "\n" . $e->getTraceAsString()
    );
    try {
        // Same cwd restore as the main path below, in case the exception fired before that
        // line ran; chdir() is harmless to repeat.
        chdir($ilias_root . "/public");
        ilInitialisation::initILIAS();
        ilSeoInitialisation::respondInternalError($e);
    } catch (Throwable) {
        http_response_code(500);
        header("Content-Type: text/html; charset=utf-8");
        echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
        echo "<p>An unexpected error occurred.</p>";
        echo "</body></html>";
    }
    exit;
});

$ini_file = new ilIniFile("./ilias.ini.php");
$ini_file->read();
$http_path = $ini_file->readVariable("server", "http_path");
if (empty($http_path)) {
    header($_SERVER["SERVER_PROTOCOL"] . " 500", true, 500);
    echo "Configuration missing. Contact the administrator.";
    exit;
}

$url = parse_url($http_path);
$path = $url["path"] ?? "";
$_SERVER["REQUEST_URI"] = "$path/ilias.php";
// Without this the session cookie is issued for this script's own directory, so the browser
// never sends it back on a permalink URL and every view starts a fresh session. ILIAS derives
// the cookie path from the running script unless this global names one explicitly.
$GLOBALS["COOKIE_PATH"] = $path;

// Do not initialize ILIAS yet. Only the database is required.
ilSeoInitialisation::initDatabase();

// Restore cwd to public/ before initILIAS(): core resolves some asset paths cwd-relatively
// (e.g. ilUtil::getNewContentStyleSheetLocation()), and chdir() above left it one level up.
chdir($ilias_root . "/public");

$page = $_GET["page"] ?? "";
unset($_GET["page"]);
// Server/vhost-context mod_rewrite captures the leading slash too; strip it, downstream code
// expects a bare slug.
$page = ltrim($page, "/");

// None of these three names is a valid permalink (PERMALINK_REGEXP requires a trailing
// slash), so answer them here, before the trailing-slash redirect below wrongly appends one.
$static_file = ilSeoSitemapBuilderCronJob::resolveStaticFile($page);
if ($static_file !== null) {
    ilSeoInitialisation::respondStaticFile($static_file);
}

if (!empty($page) && !preg_match("/\/$/", $page)) {
    header("Location: {$http_path}/{$page}/", true, 301);
    exit;
}

if (!empty($page) && !preg_match("/^([a-z0-9][a-z0-9-]*\/)*$/s", $page)) {
    ilInitialisation::initILIAS();
    ilSeoInitialisation::respondNotFound();
    exit;
}

$special_handler = HandlerFactory::forPermalink($page);
if ($special_handler !== null) {
    $special_handler->prepareEarlyDispatch();
    ilInitialisation::initILIAS();
    $DIC->globalScreen()->layout()->meta()->addMetaDatum(new ilSeoBaseTag());
    $DIC->ctrl()->callBaseClass($_GET["baseClass"]);
    $DIC["ilBench"]->save();
    $DIC["http"]?->close();
    exit;
}

$permalink_resolver = new ilSeoPermalinkResolver($DIC->database());

$data = $permalink_resolver->fetchByPermalink($page);
if ($data == null) {
    $new_permalink = $permalink_resolver->getPermalinkFromHistory($page);
    if ($new_permalink != null) {
        header("Location: {$http_path}/{$new_permalink}", true, 301);
        exit;
    }

    ilInitialisation::initILIAS();

    if (empty($page)) {
        ilInitialisation::redirectToStartingPage();
        exit;
    }

    ilSeoInitialisation::respondNotFound();
    exit;
}

// Initialize ILIAS with ref_id and other required information.
$_GET["ref_id"] = $data["ref_id"];
// The "-" sentinel is a DB-only marker, not an ISO code; map it to the real default language
// so the permalink forces that language regardless of the visitor's session/browser language.
$_GET["lang"] = $data["lang"] !== "-" ? $data["lang"] : ilSeoLanguage::getDefaultLang();
// Each supported type's dispatch shape is consolidated into its own handler;
// an unsupported type falls through to the plain repository view.
$handler = isset(ilSeo::SECONDARY_ID_KEYS[$data["type"]])
    ? HandlerFactory::forType($data["type"])
    : null;

if ($handler !== null) {
    $handler->prepareGotoDispatch((int) $data["secondary_id"]);
    // ilCtrlContext::adoptRequestParameters() reads $_GET["cmdNode"] only once, while
    // ilInitialisation::initILIAS() below is building the ilCtrl context; priming it any
    // later has no effect. finalizeGotoDispatch() (blog/dcl) sets it here for that reason.
    $handler->finalizeGotoDispatch((int) $data["secondary_id"]);
} else {
    $_GET["baseClass"] = ilRepositoryGUI::class;
    $_GET["cmd"] = "view";
    // Without a primed cmdNode, ilCtrl finds no "next class" to dispatch to, and
    // ilRepositoryGUI::executeCommand() falls back to ilCtrl::redirectByClass(): a real
    // HTTP redirect back to this same URL with the resolved cmdClass, defeating a
    // permalink's whole purpose of a direct 200 render. Prime it the same way the object
    // handlers above already do for their own fixed class chains; here the target class is
    // not fixed, so it is resolved from the object type via objectGuiClassFor() instead.
    $object_gui_class = ilSeoInitialisation::objectGuiClassFor($data["type"]);
    if ($object_gui_class !== null) {
        try {
            $_GET["cmdNode"] = ilSeoInitialisation::buildCidPath([
                ilRepositoryGUI::class,
                $object_gui_class,
            ]);
            $_GET["cmdClass"] = strtolower($object_gui_class);
        } catch (ilCtrlException) {
            // Class exists in il_object_def but not in the ilCtrl structure artifact (should
            // not happen for a real ilRepositoryGUI child); fall back to the pre-fix behavior
            // rather than fail the whole request.
            unset($_GET["cmdNode"], $_GET["cmdClass"]);
        }
    }
}

ilInitialisation::initILIAS();

// The lookup needs the repository tree, which exists only once ILIAS is initialised; no
// dispatch has happened yet here, so the request can still be answered with a status.
if (!ilSeoReachability::isReachable($DIC->database(), $DIC->repositoryTree(), (int) $data["ref_id"])) {
    // The Site Structure panel flags the same condition and offers deleting the SEO metadata;
    // 410 keeps the record and tells the crawler instead, so the two are complementary.
    ilSeoInitialisation::respondGone();
}

$DIC->ctrl()->callBaseClass($_GET["baseClass"]);

$DIC["ilBench"]->save();
$DIC["http"]?->close();
