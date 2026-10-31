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

// The plugin's install path is 7 segments below the ILIAS root:
// Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Seo
$ilias_root = realpath(dirname(__DIR__, 7));
if ($ilias_root === false || !is_file($ilias_root . "/ilias.php")) {
    // Resolved path does not look like an ILIAS root; fail loudly instead of chdir()'ing blindly.
    header($_SERVER["SERVER_PROTOCOL"] . " 500", true, 500);
    echo "Configuration missing. Contact the administrator.";
    exit;
}
chdir($ilias_root);
require_once "./libs/composer/vendor/autoload.php";

// Read by ilSeoUIHookGUI::ensureBaseHref() to scope <base href> injection to
// permalink requests only.
define("IL_SEO_PERMALINK_REQUEST", true);

// Convert PHP errors to exceptions so the handler below catches them all.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Handle uncaught exceptions before ILIAS is initialized.
// After ilInitialisation::initILIAS() ILIAS installs its own handler.
set_exception_handler(static function (Throwable $e): never {
    error_log(
        get_class($e) . ": " . $e->getMessage() .
        " in " . $e->getFile() . ":" . $e->getLine() .
        "\n" . $e->getTraceAsString()
    );
    try {
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

// The rewrite puts the permalink in the FIRST "page" parameter and appends the visitor's
// query string; PHP keeps the last duplicate, so read the first from the raw query.
$page = ilSeoInitialisation::firstQueryParam((string) ($_SERVER["QUERY_STRING"] ?? ""), "page")
    ?? ($_GET["page"] ?? "");
unset($_GET["page"]);
$visitor_query = ilSeoInitialisation::queryWithoutFirstParam(
    (string) ($_SERVER["QUERY_STRING"] ?? ""),
    "page"
);
if (!empty($page) && !preg_match("/\/$/", $page)) {
    header("Location: {$http_path}/{$page}/{$visitor_query}", true, 301);
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
        header("Location: {$http_path}/{$new_permalink}{$visitor_query}", true, 301);
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

// The permalink alone decides what is rendered: drop every dispatch and sub-target
// parameter a visitor appended, since a handler sets its own only when it has them.
unset($_GET["cmd"], $_GET["cmdNode"], $_GET["cmdClass"]);
unset($_GET["table_id"], $_GET["tableview_id"]);
foreach (ilSeo::SECONDARY_ID_KEYS as $secondary_key) {
    unset($_GET[$secondary_key]);
}

// Initialize ILIAS with ref_id and other required information.
$_GET["ref_id"] = $data["ref_id"];
// The "-" sentinel is a DB-only marker, not an ISO code; map it to the real default language
// so the permalink forces that language regardless of the visitor's session/browser language.
$_GET["lang"] = $data["lang"] !== "-" ? $data["lang"] : ilSeoLanguage::getDefaultLang();
// Each object type's dispatch shape lives in its own handler.
$handler = HandlerFactory::forType($data["type"]);
$handler->prepareGotoDispatch((int) $data["secondary_id"]);

ilInitialisation::initILIAS();

// The lookup needs the repository tree, which exists only once ILIAS is initialised; no
// dispatch has happened yet here, so the request can still be answered with a status.
if (!ilSeoReachability::isReachable($DIC->database(), $DIC->repositoryTree(), (int) $data["ref_id"])) {
    // The Site Structure panel flags the same condition and offers deleting the SEO metadata;
    // 410 keeps the record and tells the crawler instead, so the two are complementary.
    ilSeoInitialisation::respondGone();
}

// Last gate before the core dispatch: the permalink must not hand an object to a visitor who
// cannot read it. A user who can only see it is left to core; see enforceAccess().
ilSeoInitialisation::enforceAccess(
    $handler,
    (string) $data["type"],
    (int) $data["ref_id"],
    (int) $data["secondary_id"]
);

// ilCtrl freezes $_GET["cmd"] at initILIAS(); only ilCtrl::setCmd() can
// override it, so the handler primes the nested forward here.
$handler->finalizeGotoDispatch((int) $data["secondary_id"]);

$DIC->ctrl()->callBaseClass($_GET["baseClass"]);

$DIC["ilBench"]->save();
$DIC["http"]?->close();
