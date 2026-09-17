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
