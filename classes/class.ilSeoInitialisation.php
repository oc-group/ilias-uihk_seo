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

class ilSeoInitialisation extends ilInitialisation
{
    /** @var bool whether the component bootstrap has already been entered in this request */
    private static bool $booted = false;

    /**
     * Run the full ILIAS initialisation. The component bootstrap's legacy entry
     * point builds a fresh service container and then calls
     * ilInitialisation::initILIAS() itself, so this replaces a direct call to it.
     * Needs the working directory set to public/, and any cmdNode priming done
     * beforehand (it is read once, during initialisation).
     * @param string $ilias_root absolute path of the ILIAS root, the directory
     *     holding artifacts/
     * @return void
     */
    public static function bootIlias(string $ilias_root): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        require_once $ilias_root . "/artifacts/bootstrap_default.php";
        // entry_point() is defined by the bootstrap artifact required just above.
        /** @psalm-suppress UndefinedFunction */
        entry_point("ILIAS Legacy Initialisation Adapter");
    }

    /**
     * Initialise database object.
     *
     * Runs before the ILIAS bootstrap, so the service container it registers
     * the connection in is created here when none exists yet; the bootstrap
     * later replaces it with its own.
     * @return void
     */
    public static function initDatabase(): void
    {
        global $DIC;

        if (!$DIC instanceof \ILIAS\DI\Container) {
            $DIC = new \ILIAS\DI\Container();
        }

        $ilias_ini = new ilIniFile("./ilias.ini.php");
        $ilias_ini->read();
        $web_dir = $ilias_ini->readVariable("clients", "path");
        $default_client_id = $ilias_ini->readVariable("clients", "default");

        $client_ini = new ilIniFile("./{$web_dir}/{$default_client_id}/client.ini.php");
        $client_ini->read();

        $db_type = $client_ini->readVariable("db", "type");
        if ($db_type === "") {
            $db_type = ilDBConstants::TYPE_INNODB;
        }

        $ilDB = ilDBWrapperFactory::getWrapper($db_type);
        $ilDB->initFromIniFile($client_ini);
        $ilDB->connect();

        parent::initGlobal("ilDB", $ilDB);
    }

    /**
     * Builds a cid path for the cmdNode request key, replacing the removed
     * ilCtrl::setCmdClass()/setCmd(). The chain's first class must be a
     * registered ilCtrl base class.
     * @param class-string[] $class_chain root-to-leaf, e.g.
     *     [ilRepositoryGUI::class, ilObjBlogGUI::class]
     * @throws ilCtrlException if a class has no entry in the control structure
     * @return string
     */
    public static function buildCidPath(array $class_chain): string
    {
        $structure = new ilCtrlStructure(
            require ilCtrlStructureArtifactObjective::PATH(),
            require ilCtrlBaseClassArtifactObjective::PATH(),
            require ilCtrlSecurityArtifactObjective::PATH()
        );

        $cids = [];
        foreach ($class_chain as $class_name) {
            $cid = $structure->getClassCidByName($class_name);
            if ($cid === null) {
                throw new ilCtrlException("Class '{$class_name}' has no entry in the control structure.");
            }

            $cids[] = $cid;
        }

        return implode(ilCtrlPathInterface::CID_PATH_SEPARATOR, $cids);
    }

    /**
     * Resolves an ILIAS repository object type (e.g. "crs", "cat") to its GUI
     * class name, via a direct read of il_object_def (the same table
     * ilObjectDefinition itself reads, see
     * ilCachedObjectDefinition::readFromDB()). Needed here because the full
     * objDefinition service is only available after initILIAS(), too late for
     * priming cmdNode (see buildCidPath()'s own docblock); $ilDB alone, already
     * connected via initDatabase(), is enough to answer this one lookup.
     * @param string $type
     * @return class-string|null null if $type has no row (a plugin-provided
     *     object type not registered the usual way, or a typo'd/stale permalink
     *     row)
     */
    public static function objectGuiClassFor(string $type): ?string
    {
        global $DIC;

        $res = $DIC->database()->queryF(
            "SELECT class_name FROM il_object_def WHERE id = %s;",
            ["text"],
            [$type]
        );
        $row = $DIC->database()->fetchAssoc($res);
        if ($row === null || (string) $row["class_name"] === "") {
            return null;
        }

        return "ilObj" . $row["class_name"] . "GUI";
    }

    /**
     * The first occurrence of a parameter in a raw query string, urldecoded.
     * PHP's own parsing keeps the last duplicate, which lets a visitor-supplied
     * parameter override a value the web-server rewrite placed first.
     * @return string|null null when the parameter does not occur
     */
    public static function firstQueryParam(string $query_string, string $name): ?string
    {
        foreach (explode("&", $query_string) as $pair) {
            $parts = explode("=", $pair, 2);
            if (urldecode($parts[0]) === $name) {
                return urldecode($parts[1] ?? "");
            }
        }

        return null;
    }

    /**
     * The visitor's own query string for a redirect: the raw pairs of the
     * query string without the first occurrence of the given parameter (the
     * one the rewrite added), as "?a=b" or "" when nothing is left. Only the
     * query is taken from the request, never a host or a path.
     */
    public static function queryWithoutFirstParam(string $query_string, string $name): string
    {
        $kept = [];
        $skipped = false;
        foreach (explode("&", $query_string) as $pair) {
            if ($pair === "") {
                continue;
            }
            if (!$skipped && urldecode(explode("=", $pair, 2)[0]) === $name) {
                $skipped = true;
                continue;
            }
            $kept[] = $pair;
        }

        return $kept === [] ? "" : "?" . implode("&", $kept);
    }

    /**
     * Serve a generated static file straight from disk, with no ILIAS
     * bootstrap: the content only changes between cron runs, so nothing here
     * needs ILIAS's own request lifecycle. A missing file gets a bare 404, not
     * the themed error page below, for the same reason.
     * @param string $path absolute filesystem path
     * @return never
     */
    public static function respondStaticFile(string $path): never
    {
        if (!is_file($path)) {
            header(($_SERVER["SERVER_PROTOCOL"] ?? "HTTP/1.1") . " 404", true, 404);
            exit;
        }

        header(
            "Content-Type: " . (str_ends_with($path, ".txt") ? "text/plain" : "application/xml") . "; charset=UTF-8"
        );
        header("Content-Length: " . (string) filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Return error 404 Page not found.
     * @return never
     */
    public static function respondNotFound(): never
    {
        global $DIC;

        $DIC->globalScreen()->layout()->meta()->addMetaDatum(new ilSeoBaseTag());
        $DIC->http()->saveResponse(
            $DIC->http()->response()->withStatus(404)
        );
        $DIC->ui()->mainTemplate()->setContent(ilSeoPlugin::getInstance()->txt("err_not_found"));
        $DIC->ui()->mainTemplate()->setTitle("404");
        $DIC->ui()->mainTemplate()->printToStdout();
        exit;
    }

    /**
     * Return error 410 Gone: the slug is still current, the object behind it is
     * no longer in the repository tree.
     *
     * 410 rather than the 404 an unknown slug gets: this address is known,
     * published and still stored, and only stopped serving content. Search
     * engines also drop a 410 from the index sooner. Restoring the object from
     * the trash still makes the URL answer 200, which the 410 does not prevent:
     * a status describes this response alone.
     * @return never
     */
    public static function respondGone(): never
    {
        global $DIC;

        $DIC->globalScreen()->layout()->meta()->addMetaDatum(new ilSeoBaseTag());
        $DIC->http()->saveResponse(
            $DIC->http()->response()->withStatus(410)
        );
        $DIC->ui()->mainTemplate()->setContent(ilSeoPlugin::getInstance()->txt("err_gone"));
        $DIC->ui()->mainTemplate()->setTitle("410");
        $DIC->ui()->mainTemplate()->printToStdout();
        exit;
    }

    /**
     * Gate a resolved permalink on the visitor's access, before the core dispatch.
     *
     * Access granted by the handler: nothing happens. Otherwise a visitor who
     * can still see the object (visible) is dispatched as before, because core
     * answers that case itself. With neither, a logged-in user gets the 403
     * page, and an anonymous visitor is sent to the login with the target
     * preserved, the way core's goto does it (a 403 would leave no way to log
     * in and come back).
     * @param \ilSeo\ObjectHandlers\AbstractHandler $handler
     * @param string $type object type of the permalink
     * @param int $ref_id
     * @param int $secondary_id
     * @return void
     */
    public static function enforceAccess(
        \ilSeo\ObjectHandlers\AbstractHandler $handler,
        string $type,
        int $ref_id,
        int $secondary_id
    ): void {
        global $DIC;

        $logger = $DIC->logger()->root();
        $user_id = (int) $DIC->user()->getId();

        if ($handler->isAccessGranted($ref_id, $secondary_id)) {
            $logger->debug("Seo: permalink access granted, user {$user_id}, ref_id {$ref_id}");
            return;
        }

        if ($DIC->access()->checkAccess("visible", "", $ref_id)) {
            $logger->debug("Seo: permalink without read but visible, left to core, user {$user_id}, ref_id {$ref_id}");
            return;
        }

        if (!$DIC->user()->isAnonymous() && $user_id !== ANONYMOUS_USER_ID) {
            $logger->info("Seo: permalink refused with 403, user {$user_id}, ref_id {$ref_id}");
            self::respondForbidden();
        }

        $logger->info("Seo: permalink needs a login, anonymous visitor redirected, ref_id {$ref_id}");
        $DIC->ctrl()->redirectToURL(
            "login.php?target=" . rawurlencode($type . "_" . $ref_id)
            . "&cmd=force_login&lang=" . rawurlencode($DIC->user()->getCurrentLanguage())
        );
        exit;
    }

    /**
     * Return error 403 Forbidden.
     * @return never
     */
    public static function respondForbidden(): never
    {
        global $DIC;

        $DIC->globalScreen()->layout()->meta()->addMetaDatum(new ilSeoBaseTag());
        $DIC->http()->saveResponse(
            $DIC->http()->response()->withStatus(403)
        );
        $DIC->ui()->mainTemplate()->setContent(ilSeoPlugin::getInstance()->txt("err_no_permission"));
        $DIC->ui()->mainTemplate()->setTitle("403");
        $DIC->ui()->mainTemplate()->printToStdout();
        exit;
    }

    /**
     * Return error 500 Internal Server Error.
     * Logs details server-side only; the browser sees just the generic message.
     * @param ?Throwable $e Exception whose details are sent to the log.
     * @return never
     */
    public static function respondInternalError(?Throwable $e = null): never
    {
        global $DIC;

        if ($e !== null) {
            try {
                $DIC->logger()->root()->error(
                    get_class($e) . ": " . $e->getMessage() .
                    " in " . $e->getFile() . ":" . $e->getLine() .
                    "\n" . $e->getTraceAsString()
                );
            } catch (Throwable) {
                // ILIAS logger not available; pre-init handler already wrote to
                // error_log.
            }
        }

        $DIC->http()->saveResponse(
            $DIC->http()->response()->withStatus(500)
        );
        $DIC->globalScreen()->layout()->meta()->addMetaDatum(new ilSeoBaseTag());
        $DIC->ui()->mainTemplate()->setContent(ilSeoPlugin::getInstance()->txt("err_internal"));
        $DIC->ui()->mainTemplate()->setTitle("500");
        $DIC->ui()->mainTemplate()->printToStdout();
        exit;
    }

    /**
     * Redirect to a permanent link.
     * @param string $permalink
     * @return never
     */
    public static function redirectPermanent(string $permalink): never
    {
        global $DIC;

        $http = $DIC->http();

        $http->saveResponse(
            $http->response()
                ->withAddedHeader("Location", ILIAS_HTTP_PATH . "/{$permalink}")
                ->withStatus(301)
        );
        $http->sendResponse();
        $http->close();
        exit;
    }
}
