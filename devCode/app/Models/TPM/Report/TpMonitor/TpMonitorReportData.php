<?php
/**
 * Provides handling of data for 3P monitor report
 */

namespace Models\TPM\Report\TpMonitor;

use Lib\Database\ChunkResults;
use Lib\Database\MySqlPdo;
use Models\Globals\Region;
use Models\ThirdPartyManagement\ThirdParty;
use Skinny\Skinny;

/**
 * Model for the 3P Monitor Report Data.
 *
 * @keywords 3P Monitor, 3P Monitor report
 */
#[\AllowDynamicProperties]
class TpMonitorReportData
{
    /**
     * @var null|Skinny Class instance
     */
    protected $app = null;

    /**
     * @var null|MysqlPdo Class instance
     */
    protected $DB = null;

    /**
     * @var null|object Table names as properties
     */
    protected $tbl = null;

    /**
     * @var null|ChunkResults Class instance (unused in this class)
     */
    protected $chunkDB = null;

    /**
     * @var int TPM client ID
     */
    protected $tenantID = 0;

    /**
     * @var string Client database name
     */
    protected $clientDB = '';

    /**
     * @var string Global database name
     */
    protected $globalDB = '';

    /**
     * @var null|object (not used in this class)
     */
    protected $gdc = null;

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

        $this->tenantID   = (int)$tenantID;
        $this->clientDB = $this->DB->getClientDB($this->tenantID);
        $this->globalDB = $this->DB->globalDB;
        $this->tbl = (object)null;
        $this->tbl->client    = $this->clientDB . '.clientProfile';
        $this->tbl->country   = $this->DB->isoDB . '.legacyCountries';
        $this->tbl->profile   = $this->clientDB . '.thirdPartyProfile';
        $this->tbl->region    = $this->clientDB . '.region';
        $this->tbl->result    = $this->clientDB . '.gdcResult';
        $this->tbl->screen    = $this->clientDB . '.gdcScreening';
        $this->tbl->tpType    = $this->clientDB . '.tpType';
        $this->tbl->typeCat   = $this->clientDB . '.tpTypeCategory';
        $this->tbl->mmReview  = $this->clientDB . '.mediaMonReviewLog';
        $this->tbl->gdcReview = $this->clientDB . '.gdcReviewLog';
        $this->tbl->gdcAdj    = $this->globalDB . '.g_gdcAdjudicationReasons';
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
        $thirdParty = (new ThirdParty($this->tenantID, ['authUserID' => $this->app->session->authUserID]))
            ->getUserConditions();
        $data['userCond']  =  $thirdParty['userCond'];
        if (!empty($thirdParty['sqlParams'])) {
            $data['tparams'] = $thirdParty['sqlParams'];
        }
        $whereData = $this->createWhereData($data);
        $sql = "SELECT COUNT(scrn.id) AS cnt \n"
            . "FROM {$this->tbl->screen} AS scrn \n"
            . "LEFT JOIN {$this->tbl->profile} AS tp ON (scrn.id = tp.gdcScreeningID) \n"
            . $whereData['where'];
        return $this->DB->fetchValue($sql, $whereData['params']);
    }


    /**
     * Count number of hits matching supplied criteria when using existing 3P GDC Review search.
     *
     * @return integer Number of profile hits
     */
    public function countHitsFromGdcReview()
    {
        if (!$this->app->session->get('last3pListGdcReview')) {
            return 0;
        }
        $sql = "SELECT COUNT(scrn.id) AS cnt \n"
            . "FROM {$this->tbl->screen} AS scrn \n"
            . "LEFT JOIN {$this->tbl->profile} AS tp ON (scrn.id = tp.gdcScreeningID) \n"
            . $this->genQueryFromGdcReview();

        // no params as the where clause comes from legacy search class.
        // not ideal, but if the search class query isn't sanitized,
        // we have way bigger issues than this.
        return $this->DB->fetchValue($sql);
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
        $whereData = $this->createWhereData($data, false);
        if (\Xtra::usingGeography2()) {
            $countryField = 'IFNULL(c.displayAs, c.legacyName)';
            $countryOn = '(c.legacyCountryCode = tp.country '
                . 'OR c.codeVariant = tp.country OR c.codeVariant2 = tp.country) '
                . 'AND (c.countryCodeID > 0 OR c.deferCodeTo IS NULL)';
        } else {
            $countryField = 'c.legacyName';
            $countryOn = 'c.legacyCountryCode = tp.country';
        }
        $sql = "SELECT scrn.id, DATE_FORMAT(scrn.created, '%Y-%m-%d') AS screenDate, "
            . "tp.id AS tpID, tp.gdcScreeningID, tp.userTpNum, tp.legalName, "
            . "tp.gdcReview, tp.gdcReviewIcij, tp.gdcReviewMM, "
            . "tp.gdcNameError, $countryField AS country, r.name AS region, "
            . "tp.gdcUndeterminedHits, tp.gdcTrueMatchHits, tp.gdcFalsePositiveHits, tp.gdcRemediationHits, "
            . "tp.icijUndeterminedHits, tp.icijTrueMatchHits, tp.icijFalsePositiveHits, tp.icijRemediationHits, "
            . "tp.mmUndeterminedHits, tp.mmTrueMatchHits, tp.mmFalsePositiveHits, tp.mmRemediationHits "
            . "\n"
            . "FROM {$this->tbl->screen} AS scrn\n"
            . "LEFT JOIN {$this->tbl->profile} AS tp ON scrn.id = tp.gdcScreeningID\n"
            . "LEFT JOIN {$this->tbl->country} AS c ON $countryOn\n"
            . "LEFT JOIN {$this->tbl->region} AS r ON r.id = tp.region\n"
            . $whereData['where'];

        return ['sql' => $sql, 'params' => $whereData['params'], 'maxID' => $this->getMaxID()];
    }


    /**
     * Setup the search query for the stats array in bgProcess when using existing 3P GDC Review search.
     *
     * @return array Array with sql query, the params to be replaced (none), and the maxID for chunk results
     */
    public function getSearchQueryFromGdcReview()
    {
        $sql = "SELECT scrn.id, scrn.tpID \n"
            . "FROM {$this->tbl->screen} AS scrn \n"
            . "LEFT JOIN {$this->tbl->profile} AS tp ON (scrn.id = tp.gdcScreeningID) \n"
            . $this->genQueryFromGdcReview(false);

        return ['sql' => $sql, 'params' => [], 'maxID' => $this->getMaxID()];
    }


    /**
     * Handle an existing 3P Search (Gdc Review) by evaluating the legacy search
     * and acting accordingly.
     *
     * @param boolean $isCount True if count query, else false.
     *
     * @return string Modified legacy search sql
     */
    public function genQueryFromGdcReview($isCount = true)
    {
        $legacyQ = $this->app->session->get('last3pListGdcReview');
        $legacyQ = $legacyQ['search'];
        $legacyQ = explode('ORDER BY ', (string) $legacyQ); // gets rid of order by and limit
        $legacyQ = trim($legacyQ[0]);
        $legacyQ = explode('FROM thirdPartyProfile AS tp', $legacyQ);
        $legacyQ = trim($legacyQ[1]); // gets rid of select and from.
        // strip out gdcScreening as it's taken care of by caller.
        $legacyQ = str_replace(
            "LEFT JOIN gdcScreening AS scrn ON tp.gdcScreeningID = scrn.id",
            "",
            $legacyQ
        );
        $legacyQ = str_replace("LEFT JOIN ", "LEFT JOIN {$this->clientDB}.", $legacyQ);
        if (!$isCount) {
            // now we need to add scrn.id to the WHERE clause so chunk results is happy.
            $legacyQ = str_replace("WHERE ", "WHERE scrn.id < :uniqueID AND ", $legacyQ);
            // add the correct order by clause
            $legacyQ = trim($legacyQ) . " ORDER BY scrn.id DESC";
        }
        return trim($legacyQ);
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
                'scrn.id',
                $query['maxID']
            );
        }
        return $this->chunkDB->getRecord();
    }


    /**
     * Get the type/cat map
     *
     * @return object DB result
     */
    public function getTypeCatMap()
    {
        $sql = "SELECT ttc.id AS catID, ttc.name AS catName, tt.id AS typeID, tt.name AS typeName \n"
            . "FROM {$this->tbl->typeCat} AS ttc \n"
            . "LEFT JOIN {$this->tbl->tpType} AS tt ON ( ttc.tpType = tt.id ) \n"
            . "WHERE (ttc.clientID = :clientID AND tt.clientID = :clientID2) \n"
            . "ORDER BY typeName ASC , catName ASC \n";
        $params = [':clientID' => $this->tenantID, ':clientID2' => $this->tenantID];
        return $this->DB->fetchObjectRows($sql, $params);
    }


    /**
     * Get the region map
     *
     * @return array Array for multiselect list with keys of name, value, ck. ck will always be 1.
     */
    public function getRegionMap()
    {
        $data = [];
        $regions = $this->getRegionsForTenantRoles();

        $sql = "SELECT DISTINCT(tp.region), r.id, r.name FROM {$this->tbl->profile} AS tp \n"
           . "LEFT JOIN {$this->tbl->region} AS r ON (tp.region = r.id) \n"
           . "WHERE tp.clientID = :clientID ";
        if (!empty($regions) && (!$this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacyClientAdmin())) {
            $sql .= " AND  tp.region IN (" . implode(",", $regions) . ")";
        }
        $sql .= " ORDER BY r.name ASC";

        $regions = $this->DB->fetchObjectRows($sql, [':clientID' => $this->tenantID]);
        if (!$regions || (count($regions) == 1 && empty($regions[0]->id))) {
            return $data;
        }
        $hasUndefined = false;
        foreach ($regions as $r) {
            if (empty($r->id) && !$hasUndefined) {
                $data[] = ['name'  => 'Undefined Regions', 'value' => 0, 'ck'    => 1];
                $hasUndefined = true;
            } else {
                $data[] = ['name'  => $r->name, 'value' => $r->id, 'ck'    => 1];
            }
        }
        return $data;
    }


    /**
     * Get the country map
     *
     * @return array Array for multiselect list with keys of name, value, ck. ck will always be 1.
     */
    public function getCountryMap()
    {
        $data = array();
        if (\xtra::usingGeography2()) {
            $sql = "SELECT DISTINCT(tp.country) iso, IFNULL(c.displayAs, c.legacyName) `name`\n"
                . "FROM {$this->tbl->profile} tp \n"
                . "LEFT JOIN {$this->tbl->country} c ON (c.legacyCountryCode = tp.country "
                . "  OR c.codeVariant = tp.country OR c.codeVariant2 = tp.country)\n"
                . "  AND (c.countryCodeID > 0 OR c.deferCodeTo IS NULL)\n"
                . "WHERE tp.clientID = :clientID ORDER BY c.legacyName";
        } else {
            $sql = "SELECT DISTINCT(tp.country) AS iso, c.legacyName AS `name` FROM {$this->tbl->profile} AS tp \n"
                . "LEFT JOIN {$this->tbl->country} AS c ON (tp.country = c.legacyCountryCode) \n"
                . "WHERE tp.clientID = :clientID ORDER BY c.legacyName ASC";
        }

        $countries = $this->DB->fetchObjectRows($sql, [':clientID' => $this->tenantID]);
        if (!$countries) {
            return $data;
        }
        foreach ($countries as $c) {
            if (empty($c->iso)) {
                continue;
            } else {
                $data[] = ['name'  => ((!empty($c->name)) ? $c->name : $c->iso), 'value' => $c->iso, 'ck'    => 1];
            }
        }
        $deduplicationTracker = [];
        $uniqueCountryList = [];
        foreach ($data as $row) {
            // Deduplicate by both 'name' and 'value' or just 'name'
            if (!isset($deduplicationTracker[$row['name']])) {
                $uniqueCountryList[] = $row;
                $deduplicationTracker[$row['name']] = true;
            }
        }
        $data = $uniqueCountryList;
        return $data;
    }


    /**
     * Fetch a concat value of tpType:id from the tpTypeCategory table.
     *
     * @param integer $catID  tpTypeCategory.id
     * @param integer $typeID tpTypeCategory.typeID
     *
     * @return string tpID list
     */
    public function getTypeCat($catID, $typeID)
    {
        $sql = "SELECT CONCAT(tpType, ':', id) AS ref FROM {$this->tbl->typeCat} \n"
            . "WHERE id = :catID AND tpType = :typeID AND clientID = :clientID LIMIT 1";
        $params = [':catID' => $catID, ':typeID' => $typeID, ':clientID' => $this->tenantID];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Zero out the GDC info on the profile as it doesn't have a screeningID
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return void;
     */
    public function zeroProfileGdc($tpID)
    {
        $sql = "UPDATE {$this->tbl->profile} SET "
            . "gdcScreeningID = NULL, "
            . "gdcReview = 0, "
            . "gdcNameError = 0, "
            . "gdcSkipped = NULL "
            . "WHERE id = :tpID AND clientID = :tenantID LIMIT 1";
        $params = [':tpID' => $tpID, ':tenantID' => $this->tenantID];
        $this->DB->query($sql, $params);
    }

    /**
     * sum results to check for name errors
     *
     * @param integer $scrID gdcScreening.id
     *
     * @return integer Sum of nameError
     */
    public function getErrSum($scrID)
    {
        return $this->DB->fetchValue(
            "SELECT SUM(nameError) AS `errors` FROM {$this->tbl->result} "
            . "WHERE screeningID = :scrID AND clientID = :tenantID",
            [':scrID' => $scrID, ':tenantID' => $this->tenantID]
        );
    }

    /**
     * Update profile review or name error
     *
     * @param integer $type 0 for both, 1 for review, 2 for nameError;
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return void
     */
    public function zeroProfileReviewNameError($type, $tpID)
    {
        if ($type < 0 || $type > 2) {
            return;
        }
        $type = (int)$type;
        $sql = "UPDATE thirdPartyProfile SET ";
        if ($type == 1) {
            $sql .= "gdcReview = 0 ";
        } elseif ($type == 2) {
            $sql .= "gdcNameError = 0 ";
        } else {
            $sql .= "gdcReview = 0, gdcNameError = 0 ";
        }
        $sql .= "WHERE id = :tpID AND clientID = :tenantID LIMIT 1";
        $params = [':tpID' => $tpID, ':tenantID' => $this->tenantID];
        $this->DB->query($sql, $params);
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
        $sql = "SELECT id FROM {$this->tbl->screen} WHERE clientID = :clientID ORDER BY id DESC LIMIT 1";
        $rtn = $this->DB->fetchValue($sql, [':clientID' => $this->tenantID]);
        return ($rtn + 1);
    }


    /**
     * Put together the WHERE clause for our count/search query
     *
     * @param array   $data    Search criteria as input by user and validated by controller.
     * @param boolean $isCount Set false if creating the search query, else false (default) on count query.
     *
     * @return array Returns array with keys of params and where (where is the prepared where clause)
     */
    protected function createWhereData($data, $isCount = true)
    {
        $where = "WHERE ";
        $userAccessData = (new ThirdParty($this->tenantID, ['authUserID' => $this->app->session->authUserID]))
            ->getUserConditions();
        $data['userCond'] = $userAccessData['userCond'];
        $data['tparams']  =  $userAccessData['sqlParams'];
        if (!$isCount) {
            // uniqueID is handled by chunkResults, so it is not added into the params array.
            $where .= "scrn.id < :uniqueID AND ";
        }
        $where .= "scrn.clientID = :clientID AND tp.clientID = :clientID2 \n";
        $params = [':clientID' => $this->tenantID, ':clientID2' => $this->tenantID];

        if ($data['status'] == 'gdcReview') {
            // needs reviewed
            // make sure to keep this “needs review” logic in sync with the following:
            // public_html/cms/includes/php/class_search.php parse3pInput() ‘gdcreview’
            // and app/Lib/Legacy/Search/Search3pData.php parse3pInput()
            $where .= "AND (tp.gdcReview = '1' OR tp.gdcReviewicij = '1' OR tp.gdcReviewMM = '1') ";
        } elseif ($data['status'] == 'gdcReview0') {
            // reviewed
            $where .= "AND (tp.gdcReview = '0' AND tp.gdcReviewicij = '0' AND tp.gdcReviewMM = '0') ";
        }
        $where .= "AND tp.status <> 'inactive' AND tp.status <> 'deleted' \n";

        if ($data['rptMode'] == 'list') {
            $list = [];
            if ($data['modeData']['list']) {
                $list[] = "FIND_IN_SET (tp.id, :profileList) ";
                $params[':profileList'] = $data['modeData']['list'];
            }
            if ($data['modeData']['ranges']) {
                $ranges = [];
                $x = 0;
                foreach ($data['modeData']['ranges'] as $r) {
                    $ranges[] = "(tp.id BETWEEN :rngStart{$x} AND :rngEnd{$x}) ";
                    $params[':rngStart' . $x] = $r['st'];
                    $params[':rngEnd' . $x]   = $r['end'];
                    $x++;
                }
                $ranges = implode(' OR ', $ranges);
                $list[] = "{$ranges}";
            }
            $list = implode(" OR ", $list);
            $where .= "AND ({$list}) \n";
        } elseif ($data['rptMode'] == 'typecat') {
            $where .= "AND FIND_IN_SET(tp.tpTypeCategory, :catList) \n";
            $params[':catList'] = $data['modeData'];
        }

        $rgnCtry = '';
        $userCondition = $data['userCond'];

        $where .= " AND {$userCondition} ";

        if (!empty($data['tparams']) && $userCondition != '(1)' && empty($data['rgList'])) {
            // mgrRegions
            if (!empty($data['tparams'][':mgrRegions'])) {
                $params[':mgrRegions'] = $data['tparams'][':mgrRegions'];
            }
            // mgrDepartments
            if (!empty($data['tparams'][':mgrDepartments'])) {
                $params[':mgrDepartments'] = $data['tparams'][':mgrDepartments'];
            }
            $params[':uid'] = $data['tparams'][':uid'];
        } elseif ($data['rgList']) {
            $rgnCtry .= (!empty($rgnCtry)) ? ' OR ' : '';
            $rgnCtry .= "FIND_IN_SET(tp.region, :rgList)";
            $params[':rgList'] = $data['rgList'];
        }

        if ($data['ctList']) {
            $rgnCtry .= (!empty($rgnCtry)) ? ' OR ' : '';
            $rgnCtry .= "FIND_IN_SET(tp.country, :ctList)";
            $params[':ctList'] = $data['ctList'];
        }
        if (!empty($rgnCtry)) {
            $where .= "AND ({$rgnCtry}) \n";
        }

        $where .= "AND (scrn.created BETWEEN :stDate AND :endDate OR scrn.created = NULL)";
        $params[':stDate'] = $data['stDate'] . ' 00:00:00';
        $params[':endDate'] = $data['endDate'] . ' 23:59:59';

        if (!$isCount) {
            //$where .= " ORDER BY scrn.created DESC";
            $where .= " ORDER BY scrn.id DESC";
        }
        return ['where' => $where, 'params' => $params];
    }

    /**
     * Getting all the Regions for Current User based on Tenant and Roles.
     *
     * @return array $regions regionID
     */
    public function getRegionsForTenantRoles()
    {
        $regions = [];
        $regionsList = (new Region($this->app->ftr->tenant))
            ->getUserRegions($this->app->ftr->user, $this->app->ftr->role);
        foreach ($regionsList as $regionObj) {
            $regions[] = $regionObj["id"];
        }
        return $regions;
    }
}
