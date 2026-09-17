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
    /**
     * Initialise database object.
     * @return void
     */
    public static function initDatabase(): void
    {
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
        if ($row === null || $row === false || (string) $row["class_name"] === "") {
            return null;
        }

        return "ilObj" . $row["class_name"] . "GUI";
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
            header($_SERVER["SERVER_PROTOCOL"] . " 404", true, 404);
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
