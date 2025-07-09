<?php
/**
 * Provides handling of data for media monitor report
 */

namespace Models\TPM\Report\MediaMon;

use Lib\Database\ChunkResults;
use Lib\Database\MySqlPdo;
use Models\ThirdPartyManagement\ThirdParty;

/**
 * Model for the Media Monitor Report Data.
 *
 * @keywords Media Monitor, Media Monitor report
 */
#[\AllowDynamicProperties]
class MediaMonReportData
{
    /**
     * @var \Skinny\Skinny|null Class instance
     */
    protected $app = null;

    /**
     * @var MySqlPdo|null Class instance
     */
    protected $DB  = null;

    /**
     * @var object|null Properties added for various database table names
     */
    protected $tbl = null;

    /**
     * @var ChunkResults|null Class instance
     */
    protected $chunkDB = null;

    /**
     * @var int TPM client ID
     */
    protected $tenantID = 0;

    /**
     * @var string TPM client database name
     */
    protected $clientDB = '';

    /**
     * Class constructor
     *
     * @param integer $tenantID Delta tenantID
     *
     * @return void;
     */
    public function __construct($tenantID)
    {
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;

        $this->tenantID     = (int)$tenantID;
        $this->clientDB     = $this->DB->getClientDB($this->tenantID);
        $this->tbl          = (object)null;
        $this->tbl->mmr     = $this->clientDB . '.mediaMonRequests';
        $this->tbl->mmres   = $this->clientDB . '.mediaMonResults';
        $this->tbl->mmSrch  = $this->clientDB . '.mediaMonSrch';
        $this->tbl->mmMap   = $this->clientDB . '.tpPersonMap';
        $this->tbl->client  = $this->clientDB . '.clientProfile';
        $this->tbl->tp      = $this->clientDB . '.thirdPartyProfile';
        $this->tbl->regionTbl = $this->clientDB . '.region';
    }


    /**
     * Count number of hits matching supplied criteria
     *
     * @param array $data Search criteria as input by user and validated by controller.
     *
     * @return integer Number of profile hits
     */
    public function countHits($data)
    {
        $third = (new ThirdParty($this->tenantID, ['authUserID' => $this->app->session->authUserID]))
            ->getUserConditions();
        $data['userCond'] = $third['userCond'];
        $data['tparams']  =  $third['sqlParams'];
        $whereData = $this->createWhereData($data);
        $sql = "SELECT COUNT(DISTINCT(tp.id)) \n"
           . "FROM {$this->tbl->mmSrch} AS mms \n"
           . "LEFT JOIN {$this->tbl->tp} AS tp ON (tp.id = mms.profileID) \n"
           . $whereData['where'];

        return $this->DB->fetchValue($sql, $whereData['params']);
    }


    /**
     * Setup the search query for the stats array in bgProcess.
     *
     * @param array $data Search criteria as input by user and validated by controller.
     *
     * @return array Array with sql query, the params to be replaced (none), and the maxID for chunk results
     */
    public function getSearchQuery($data)
    {
        $thirdParty = (new ThirdParty($this->tenantID, ['authUserID' => $this->app->session->authUserID]))
            ->getUserConditions();
        $data['userCond']  =  $thirdParty['userCond'];
        if (!empty($thirdParty['sqlParams'])) {
            $data['tparams'] = $thirdParty['sqlParams'];
        }

        $whereData = $this->createWhereData($data, false);
        $sql = "SELECT DISTINCT(tp.id), tp.legalName AS name, 1 AS assocSrch, 1 AS entitySrch, 1 AS ttlSrch \n"
           . "FROM {$this->tbl->mmSrch} AS mms \n"
           . "LEFT JOIN {$this->tbl->tp} AS tp ON (tp.id = mms.profileID) \n"
           . $whereData['where'];
           return ['sql' => $sql, 'params' => $whereData['params'], 'maxID' => $this->getMaxID()];
    }



    /**
     * Execute the search query and return the results. ONLY called from
     * the CLI version of the report class.
     *
     * @param array $query Array created by getSearchQuery()
     *
     * @return object DB result of query.
     */
    public function searchProfiles($query)
    {
        if (!is_string($query['sql']) || !is_array($query['params'])) {
            return null;
        }
        if (!$this->chunkDB) {
            $this->chunkDB = new ChunkResults(
                $this->DB,
                $query['sql'],
                $query['params'],
                'tp.id',
                $query['maxID']
            );
        }
        return $this->chunkDB->getRecord();
    }


    /**
     * Fetch a 3P record for the report.
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return object DB object
     */
    public function getProfile($tpID)
    {
        $sql = "SELECT legalName AS name \n"
            . "FROM {$this->tbl->tp} WHERE id = :tpID AND clientID = :tenantID \n";
        $params = [':tpID' => $tpID, ':tenantID' => $this->tenantID];
        return $this->DB->fetchObjectRow($sql, $params);
    }



    /**
     * Grabs tenant companyName for PDF use.
     * No sense in loading client model just for this.
     *
     * @return string clientProfile.clientName
     */
    public function getTenantName()
    {
        return $this->DB->fetchValue(
            "SELECT clientName FROM {$this->tbl->client} WHERE id = :tid LIMIT 1",
            [':tid' => $this->tenantID]
        );
    }


    /**
     * Convert userTpNum(s) into the true tpID(s).
     *
     * @param array $tpNums Array of userTpNums
     *
     * @return array Array of key => value (tp.userTpNum => tp.id)
     */
    public function convertToTpID($tpNums)
    {
        if (empty($tpNums) || !is_array($tpNums)) {
            return [];
        }
        $params = [':clientID' => $this->tenantID];

        $list = [];
        for ($i = 0; $i < count($tpNums); $i++) {
            $params[':tpNum' . $i] = $tpNums[$i];
            $list[] = 'userTpNum = :tpNum' . $i;
        }
        $list = implode(' OR ', $list);
        $sql = "SELECT userTpNum, id FROM {$this->tbl->profile} \n"
            . "WHERE clientID = :clientID AND ({$list}) \n"
            . "LIMIT " . count($tpNums);
        return $this->DB->fetchKeyValueRows($sql, $params);
    }


    /**
     * Get the maximum expected ID from the gdcScreening table
     *
     * @return integer Returns highest id from gdcScreening based on current tenantID
     */
    protected function getMaxID()
    {
        $sql = "SELECT id FROM {$this->tbl->tp} WHERE clientID = :clientID ORDER BY id DESC LIMIT 1";
        $rtn = $this->DB->fetchValue($sql, [':clientID' => $this->tenantID]);

        return ($rtn + 1);
    }


    /**
     * Put together the WHERE clause for our count/search query
     *
     * @param array   $data    Search criteria as input by user and validated by controller.
     * @param boolean $isCount Set false if creating the search query, else true (default) on count query.
     *
     * @return array Returns array with keys of params and where (where is the prepared where clause)
     */
    protected function createWhereData($data, $isCount = true)
    {
        $where = "WHERE ";

        if (!$isCount) {
            // uniqueID is handled by chunkResults, so it is not added into the params array.
            $where .= "tp.id < :uniqueID AND ";
        }
        $where .= "mms.tenantID = :clientID AND ";
        $params = [':clientID' => $this->tenantID];

        $where .= "tp.status <> 'inactive' AND tp.status <> 'deleted' AND ";

        $where .= "mms.received BETWEEN :stDate AND :endDate";
        $params[':stDate'] = $data['stDate'] . ' 00:00:00';
        $params[':endDate'] = $data['endDate'] . ' 23:59:59';

        $userCondition = $data['userCond'];
        $where .= " AND {$userCondition} ";

        if (!empty($data['tparams']) && $userCondition != '(1)') {
            // mgrRegions
            if (!empty($data['tparams'][':mgrRegions'])) {
                $params[':mgrRegions'] = $data['tparams'][':mgrRegions'];
            }
            // mgrDepartments
            if (!empty($data['tparams'][':mgrDepartments'])) {
                $params[':mgrDepartments'] = $data['tparams'][':mgrDepartments'];
            }
            $params[':uid'] = $data['tparams'][':uid'];
        }

        if (!$isCount) {
            $where .= " GROUP BY tp.id ";
            $where .= " ORDER BY tp.id DESC";
        }

        return ['where' => $where, 'params' => $params];
    }

    /**
     * Gather a row of CSV data for this tpID in array format
     *
     * @param string $tpID   integer tpID to gather results for
     * @param string $tpName 3P name
     * @param array  $params query parameters based on user input
     *
     * @return array 3P data for the report
     */
    public function getReportData($tpID, $tpName, $params)
    {


        $tblTypeP = 'person';
        $tblTypeE = 'profile';

        $sqlA = "SELECT count(mms.id) FROM {$this->tbl->mmSrch} AS mms \n"
            . "LEFT JOIN {$this->tbl->tp} AS tp ON (tp.id = mms.profileID) \n"
            . "WHERE mms.tenantID = :clientID \n"
            . "AND mms.profileID = :tpid \n"
            . "AND mms.idType = :idType \n"
            . "AND mms.received BETWEEN :stDate AND :endDate ";

        $paramA = [
            ':idType'    => $tblTypeP,
            ':clientID'  => $params[':clientID'],
            ':stDate'    => $params[':stDate'],
            ':endDate'   => $params[':endDate'],
            ':tpid'      => $tpID
        ];

        $sqlB = "SELECT count(mms.id) FROM {$this->tbl->mmSrch} AS mms \n"
            . "LEFT JOIN {$this->tbl->tp} AS tp ON (tp.id = mms.profileID) \n"
            . "WHERE mms.tenantID = :clientID \n"
            . "AND mms.profileID = :tpid \n"
            . "AND mms.idType = :idType \n"
            . "AND mms.received BETWEEN :stDate AND :endDate ";

        $paramB = [
            ':idType'    => $tblTypeE,
            ':clientID'  => $params[':clientID'],
            ':stDate'    => $params[':stDate'],
            ':endDate'   => $params[':endDate'],
            ':tpid'      => $tpID
        ];

        $assocSrch = $this->DB->fetchValue($sqlA, $paramA);
        $entitySrch = $this->DB->fetchValue($sqlB, $paramB);

        $ret = [$tpName, $assocSrch, $entitySrch, ($assocSrch + $entitySrch)];

        return $ret;
    }
}
