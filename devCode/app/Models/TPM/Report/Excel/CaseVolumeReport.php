<?php
/**
 * Created by:  Rich Jones
 * Create Date: 2016-04-19
 *
 * Model: CaseVolumeReport
 */

namespace Models\TPM\Report\Excel;

use Lib\Support\Xtra;
use Lib\DevTools\DevDebug;

/**
 * Provides data access for CaseVolumeReport
 *
 * @keywords caseVolumeReport, cvr, analytics, report, bi, business intelligence
 */
#[\AllowDynamicProperties]
class CaseVolumeReport
{

    public const STEELE_CIS_CLIENT_ID = 93;

    /**
     * @var null
     */
    private $DB = null;

    /**
     * @var string
     */
    private $clientDB = '';

    /**
     * @var int
     */
    public $clientID = 0;

    /**
     * class Instance of application logger
     * @var null
     */
    private $log = null;

    /**
     * @var resource DevDebug class
     */
    private $DevDebug = null;

    /**
     * @var null
     */
    private $authDB = null;
    /**
     * @var null
     */
    private $globalDB = null;


    /**
     * CaseVolumeReport constructor.
     *
     * @param int $clientID client id
     *
     * @return void
     */
    public function __construct($clientID)
    {
        $this->DevDebug = new DevDebug;
        $app = Xtra::app();
        $this->log = $app->log;
        $this->DB = $app->DB;
        $this->authDB = $this->DB->authDB;
        $this->globalDB = $this->DB->globalDB;
        $this->clientID = (int)$clientID;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
    }

    /**
     * get cv.clientId and cd.name for the $authUserID
     *
     * @param int $authUserID authorized user id.
     *
     * @return mixed
     */
    public function getClientIdAndClientName($authUserID)
    {
        // pdo data bindings
        $bindData = [
            ':auid' => $authUserID
        ];

        $sql = "select cv.clientId \n"
             . "      ,cd.clientName \n"
             . "  from $this->globalDB.g_caseVolumeAccess as cv \n"
             . "  left join $this->authDB.clientDBlist cd \n"
             . "    on cv.clientId = cd.clientID \n"
             . " where cv.userID = :auid \n"
             . " order by cd.clientName asc ";

        return $this->DB->fetchObjectRows($sql, $bindData);
    }

    /**
     * determines if $authUserID has access to the Case Volume Report
     *
     * @param int $authUserID authorized user id.
     *
     * @return bool
     */
    public function verifyAccess($authUserID)
    {
        // pdo data bindings
        $bindData = [
            ':auid' => $authUserID
        ];

        $sql = "select count(*) as row_count \n"
             . "  from $this->globalDB.g_caseVolumeAccess \n"
             . " where userID = :auid limit 1 ";

        $rows = $this->DB->fetchObjectRows($sql, $bindData);

        // if $rows not found or $rows[0]->row_count == 0; userID does not exist, so exit with false.
        // if sql is malformed or table does not exist an error is thrown by fetchObjectRows().
        if (!$rows || $rows[0]->row_count == 0) {
            return false;
        }

        if ($this->clientID !== self::STEELE_CIS_CLIENT_ID) {
            return false;
        }

        return true;
    }
}
