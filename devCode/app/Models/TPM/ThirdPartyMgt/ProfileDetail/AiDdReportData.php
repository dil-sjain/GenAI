<?php
/**
 * Provide data access for responses to requests from user interaction on AI DD-Report
 */

namespace Models\TPM\ThirdPartyMgt\ProfileDetail;

use Exception;
use Lib\Database\MySqlPdo;
use Lib\Support\Xtra;
use Skinny\Skinny;

#[\AllowDynamicProperties]
class AiDdReportData
{
    /**
     * @var MySqlPdo PDO class instance for normal application data access
     */
    protected MySqlPdo $DB;

    /**
     * @var Skinny Class instance
     */
    protected Skinny $app;

    /**
     * @var int TPM tenant ID
     */
    protected int $clientID;

    /**
     * Instantiate class and set instance properties
     *
     * @param int $clientID TPM tenant ID
     */
    public function __construct(int $clientID)
    {
        $this->clientID = $clientID;
        $this->app = Xtra::app();
        $this->DB = $this->app->DB;
    }

    /**
     * Get count of pending notifications or in progress reports for an user
     *
     * @return array
     */
    public function checkInprogressReport($userID)
    {
        try {
            $sql = "SELECT count(*) as num FROM ddReports WHERE userID = :userID AND clientID = :clientID AND (`status` = 1 OR (`status` IN (2, 3) AND notification_status = 0))";
            $params = [':userID' => $userID, ':clientID' => $this->clientID];
            return $this->DB->fetchValue($sql, $params);
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Get user pending notifications
     *
     * @return array
     */
    public function getNotification($userID)
    {
        try {
            $sql = "SELECT dr.id as nID, dr.tpID, dr.ridReportID, dr.caseID, tp.userTpNum as tpNumber, tp.legalName tpName,
                    reg.name AS tpRegion, IF(dr.status='2', 'Success', 'Failed') as reportStatus,
                    dr.notification_status as nStatus 
                    FROM ddReports as dr 
                    JOIN thirdPartyProfile as tp on tp.id = dr.tpID 
                    LEFT JOIN region as reg ON reg.id = tp.region
                    WHERE dr.userID = :userID AND dr.clientID = :clientID 
                    AND dr.notification_status = 0 
                    AND dr.status IN ('2', '3') order by dr.updated_at ASC limit 1";
                $params = [':userID' => $userID, ':clientID' => $this->clientID];
                return $this->DB->fetchAssocRow($sql, $params);
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Update user notifications
     *
     * @return boolean
     */
    public function updateNotification($ID, $status)
    {
        try {
            $sql = "UPDATE ddReports SET notification_status = :status WHERE id = :ID limit 1";
            $params = [':ID' => $ID, ':status' => $status];
            return $this->DB->query($sql, $params);
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }
}
