<?php
/**
 * File containing the class for dealing with data in and connected with mediaMonSrch tables.
 *
 * @keywords Media, Monitor, GDC, thirdParty, monitor, 3p, cli, refine, refinement, api, filter, data
 */

namespace Models\TPM\MediaMonitor;

use Lib\Support\XtraLite;
use Models\Logging\LogRunTimeDetails;
use Models\ThirdPartyManagement\Gdc;
use Models\Globals\Features\TenantFeatures;
use Lib\Support\ForkProcess;

/**
 * Class for dealing with data in and connected with mediaMonSrch tables.
 */
#[\AllowDynamicProperties]
class MediaMonitorSrchData extends MediaMonitorQueueData
{
    /**
     * mediaMonSrch table
     *
     * @var string $srchTbl
     */
    protected $srchTbl = 'mediaMonSrch';

    /**
     * mediaMonResults table
     *
     * @var string $rsltTbl
     */
    protected $rsltTbl = 'mediaMonResults';

    /**
     * mediaMonReviewLog table
     *
     * @var string $rvlogTb
     */
    protected $rvlogTbl = 'mediaMonReviewLog';

    /**
     * Hold results of review log query so it only runs once
     *
     * @var null|array $reviewLogIDs
     */
    private $reviewLogIDs = null;

    /**
     * Hold adjudication reasons so it only runs once
     *
     * @var null|array $adjReasons
     */
    private $adjReasons = null;

    /**
     * Cache gdc results to reduce number of queries
     *
     * @var null $gdcResultCache
     */
    private $gdcResultCache = null;

    /**
     * Instance of GDC class
     *
     * @var object  $gdc
     */
    private $gdc;

    /**
     * @var object $mLog Logging model
     */
    private $mLog = null;

    /**
     * Initialize data for model
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get mediaMonSrch.id via an apiID
     *
     * @param integer $apiID mediaMonRequests.apiID
     * @param string  $DB    DB alias
     *
     * @return mixed integer if a valid value else a false boolean
     */
    public function getSearchIDbyApiID($apiID, $DB)
    {
        $rtn = false;
        $apiID = (int)$apiID;
        if (!empty($apiID) && !empty($DB)) {
            $sql = "SELECT srch.id AS id FROM $DB.{$this->srchTbl} AS srch\n"
                . "LEFT JOIN $DB.{$this->reqsTbl} AS reqs ON reqs.id = srch.userRequestID\n"
                . "WHERE reqs.apiID = :apiID";
            $rtn = $this->DB->fetchValue($sql, [':apiID' => $apiID]);
        }
        return $rtn;
    }

    /**
     * Insert search data to mediaMonSrch
     *
     * @param integer $userRequestID mediaMonRequests.id
     * @param integer $tenantID      mediaMonSrch.tenantID
     * @param string  $idType        mediaMonSrch.idType (Either 'person' or 'profile')
     * @param string  $polymorphicID mediaMonSrch.tpID (Either thirdPartyProfile.id or tpPerson.id)
     * @param integer $profileID     mediaMonSrch.profileID (third party profile id)
     * @param integer $userID        mediaMonSrch.userID
     * @param integer $spID          If true, this pertains to the SP application
     * @param integer $caseID        cases.id only applicable to SP side, used for google results
     *
     * @return integer
     */
    public function createSearch(
        $userRequestID,
        $tenantID,
        $idType,
        $polymorphicID,
        $profileID,
        $userID = null,
        $spID = 0,
        $caseID = null
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $userRequestID = (int)$userRequestID;
        $polymorphicID = (int)$polymorphicID;
        $rtn = 0;
        if (!in_array($idType, ['profile', 'person'])) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $tenantID = (int)$tenantID;
        $userID = (int)$userID;
        $DB = $this->DB->getClientDB($tenantID);
        $spCol = $spVal = '';
        $params = [
            ':userRequestID' => $userRequestID,
            ':tenantID' => $tenantID,
            ':idType' => $idType,
            ':polymorphicID' => $polymorphicID,
            ':profileID' => (int)$profileID,
            ':userID' => $userID
        ];
        if (!empty($spID)) {
            $DB = $this->DB->spGlobalDB;
            $spCol = ", spID, caseID";
            $spVal = ", :spID, :caseID";
            $params[':spID'] = $spID;
            $params[':caseID'] = $caseID;
        }
        $sql = "INSERT INTO $DB.{$this->srchTbl}\n"
            . "(userRequestID, tenantID, idType, tpID, profileID, userID" . $spCol . ")\n"
            . "VALUES (:userRequestID, :tenantID, :idType, :polymorphicID, :profileID, :userID" . $spVal . ")";
        $mockQuery = $this->DB->mockFinishedSql($sql, $params);
        try {
            $this->debugLog(__FILE__, __LINE__, "Query: {$mockQuery}");
            $this->DB->query($sql, $params);
            $rtn = $this->DB->lastInsertId();
            return $rtn;
        } catch (\Exception $e) {
            $this->debugLog(__FILE__, __LINE__, "createSearch query failed; exception: {$e}; query: {$mockQuery}");
            return $rtn;
        }
    }

    /**
     * Given a json object of presumed results, insert to mediaMonResults.
     *
     * Multiple TPs may have shared an apiID (mediaMonRequests), so multiple
     * mediaMonSrch IDs may receive results insertion.
     *
     * @param object $data JSON posted by TransparInt
     *
     * @return void
     */
    public function saveData($data)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        if (empty($data) || !isset($data['Adverse Media']) || empty($data['metadata'])
            || empty($data['metadata']['search_id']) || empty($data['metadata']['client_reference'])
        ) {
            $this->debugLog(__FILE__, __LINE__, 'Insufficient metadata in response.');
            return;
        }
        $resultRows = [];
        $apiID = (int)$data['metadata']['search_id'];
        $tenantID = (int)$data['metadata']['client_reference'];
        $this->mLog = new LogRunTimeDetails($tenantID, 'mediaMonitor');
        $msgLvl = LogRunTimeDetails::LOG_VERBOSE;
        $this->mLog->logDetails($msgLvl, $data);

        // Fetch the g_mediaMonQueue row via the api and tenant.
        if (!($queueItem = $this->getQueueItemByApiAndTenantID($apiID, $tenantID))) {
            $this->debugLog(__FILE__, __LINE__, 'No queue record associated with apiID and tenantID.');
            return;
        }

        $spID = (!empty($queueItem['spID'])) ? (int)$queueItem['spID'] : 0;

        $clientDB = $this->DB->getClientDB($tenantID);
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $clientDB;

        // Fetch the mediaMonSrch row to associate with these results.
        $sql = "SELECT srch.id, srch.profileID\n"
            . "FROM {$DB}.{$this->srchTbl} as srch\n"
            . "LEFT JOIN {$DB}.{$this->reqsTbl} as reqs ON reqs.id = srch.userRequestID\n"
            . "WHERE reqs.apiID = :apiID AND reqs.tenantID = :tenantID AND srch.status = :status\n"
            . "ORDER BY srch.received DESC LIMIT 1";
        $params = [':apiID' => $apiID, ':tenantID' => $tenantID, ':status' => 'started'];
        $this->mLog->logDetails($msgLvl, [$sql, $params]);
        if (!($mmSrchRow = $this->DB->fetchAssocRow($sql, $params))) {
            $this->debugLog(__FILE__, __LINE__, 'No search record associated with apiID and tenantID.');
            return;
        }
        $mmSrchID  = (int)$mmSrchRow['id'];
        $profileID = (int)$mmSrchRow['profileID'];
        if (empty($profileID)) {
            $this->debugLog(__FILE__, __LINE__, 'Profile ID is required');
            return;
        }
        if (!empty($data['Adverse Media'])) {
            $resultRows = $this->createResults($data['Adverse Media'], $mmSrchID, $tenantID, $profileID, $spID);
        }
        // Update the third party profile to indicate needing review (should not happen for SP)
        if (empty($spID) && !empty($resultRows)) {
            $und = 0;
            // Get the current number of undetermined hits for the profile
            $sql = "SELECT mmUndeterminedHits FROM {$clientDB}.thirdPartyProfile WHERE id = :profileID";
            $currentUnd = $this->DB->fetchValue($sql, [':profileID' => $profileID]);
            foreach ($resultRows as $result) {
                // Find the most recent hit based on url, profile, personID and personType, excluding this hit
                $sql = "SELECT COUNT(*) FROM {$clientDB}.mediaMonResults res "
                    . "WHERE res.url = :url "
                    . "AND res.id != :resultID "
                    . "AND res.tpProfileID = :tpProfileID "
                    . "AND res.tpPersonID = :tpPersonID "
                    . "AND res.tpPersonType = :tpPersonType "
                    . "AND res.deleted = 0 "
                    . "ORDER BY res.id DESC "
                    . "LIMIT 1";
                $params = [
                    ':url'          => $result[':url'],
                    ':resultID'     => $result[':id'],
                    ':tpProfileID'  => $result[':tpProfileID'],
                    ':tpPersonID'   => $result[':tpPersonID'],
                    ':tpPersonType' => $result[':tpPersonType']
                ];
                $row = $this->DB->fetchValue($sql, $params);
                $this->mLog->logDetails($msgLvl, [$sql, $params, $row]);

                // If no adjudications exist for the record and the hit does not already exist, increase the count
                if (empty($row)) {
                    $und++;
                }
            }
            // Update the profile record with new undetermined hits
            if ($und > 0) {
                $total = (int) $currentUnd + (int) $und;
                $sql = "UPDATE {$clientDB}.thirdPartyProfile SET gdcReviewMM = 1, mmUndeterminedHits = :hits 
                WHERE id = :profileID";
                $params = [':hits' => $total, ':profileID' => $profileID];
                $this->DB->query($sql, $params);
                $this->mLog->logDetails($msgLvl, [$sql, $params]);
            }
        }

        $this->updateMMSearchStatus($mmSrchID, 'finished', $tenantID, $spID);
        $this->updateMMQueueStatus($apiID, 'finished', $tenantID);
    }

    /**
     * Get the results table rows for a particular search id
     *
     * @param integer $searchID value to be checked in mediaMonResults.searchID
     * @param integer $tenantID Tenant ID
     * @param integer $spID     If true, fetch from the SP global DB
     *
     * @return array
     */
    public function fetchCachedResultsTpRequest($searchID, $tenantID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $searchID = (int)$searchID;
        if (empty($searchID)) {
            return [];
        }
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        return $this->DB->fetchAssocRows(
            "SELECT * FROM $DB.{$this->rsltTbl} WHERE searchID = :searchID AND deleted = 0;",
            [':searchID' => $searchID]
        );
    }

    /**
     * Update status in mediaMonSrch
     * So as to differentiate between the previous version and the current version and match up with g_mediaMonQueue,
     * we are using somewhat different statuses.
     * Previous: 'Initialized', 'Finished'
     * Current: 'started', 'finished', 'stopped', 'requeued'
     *
     * @param integer $idDat    Raw id datum
     * @param string  $status   Status to which to update
     * @param integer $tenantID Tenant id
     * @param integer $spID     g_mediaMonQueue.spID
     *
     * @return array
     */
    public function updateMMSearchStatus($idDat, $status, $tenantID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $statuses = ['started', 'finished', 'stopped', 'requeued'];
        if (empty($idDat) || empty($tenantID) || empty($status) || !in_array($status, $statuses)) {
            return;
        }
        $id = is_array($idDat) ? $idDat['id'] : $idDat;
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $this->DB->query(
            "UPDATE $DB.{$this->srchTbl} SET received = :date, `status` = :status WHERE id = :id",
            [':id' => $id, ':date' => date('Y-m-d H:i:s'), ':status' => $status]
        );
        $result = $this->DB->fetchAssocRow("SELECT * FROM $DB.{$this->srchTbl} WHERE id = :id", [':id' => $id]);
        return $result;
    }

    /**
     * Validate if record is already deleted or not.
     * If deleted then skips it else inserts the result.
     *
     * @param array   $results   Array with results
     * @param integer $mmSrchID  mediaMonSrch.id
     * @param integer $tenantID  Tenant id
     * @param integer $profileID TPP ID
     * @param integer $spID      g_mediaMonQueue.spID
     *
     * @return array  array of params used to create the mediaMonResults rows including the id for the rows
     */
    private function createResults($results, $mmSrchID, $tenantID, $profileID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = [];
        if (!empty($spID)) {
            // Investigation Services does not want MM. There are also schema incompatibilities.
            return $rtn;
        }
        $mmSrchID = (int)$mmSrchID;
        $tenantID = (int)$tenantID;
        $profileID = (int)$profileID;
        $this->debugLog(__FILE__, __LINE__);
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $spClause = (!empty($spID)) ? ", spID" : "";
        $spVal = (!empty($spID)) ? ", :spID" : "";

        $ftr = new TenantFeatures();

        $tenantFtr = new TenantFeatures($tenantID);
        $tenantHasAIFeature = $tenantFtr->tenantHasFeature(
            \Feature::AI_SUMMARISATION,
            \Feature::APP_TPM
        );
        $hideResultRevisions = $tenantFtr->tenantHasFeature(
            \Feature::TENANT_MEDIA_MONITOR_HIDE_REVISIONS,
            \Feature::APP_TPM
        ) || !empty($spID);
        $aiSummaryEnabled = $tenantHasAIFeature && filter_var(getenv("AI_SUMMARY_ENABLED"), FILTER_VALIDATE_BOOLEAN);
        $validateUrlEnable = false;
        $aiSummaryClause = $aiSummaryEnabled ? ", urlHash" : "";
        $aiSummaryVal = $aiSummaryEnabled ? ", :urlHash" : "";

        $sql = "INSERT INTO $DB.{$this->rsltTbl}
            (tenantID, searchID, title, relevance, published, snippet, url, tpProfileID, tpPersonID,
            tpPersonType, hash, hashNoPub $spClause $aiSummaryClause) VALUES
            (:tenantID, :searchID, :title, :relevance, :published, :snippet, :url, :tpProfileID, :tpPersonID,
            :tpPersonType, :hash, :hashNoPub $spVal $aiSummaryVal)";
        $tpId = '';
        $nameFrom = '';
        $idType = '';

        // Lookup queries to prevent duplicate results
        if ($hideResultRevisions) {
            $lookUpNoPubSql = "SELECT id FROM $DB.{$this->rsltTbl}
                WHERE tenantID = :tenantID AND tpProfileID = :profileID
                    AND tpPersonID = :tpPersonID AND tpPersonType = :tpPersonType
                    AND hashNoPub = :hashNoPub
                ORDER BY id DESC LIMIt 1";
        } else {
            $lookUpSql = "SELECT id FROM $DB.{$this->rsltTbl}
                WHERE tenantID = :tenantID AND tpProfileID = :profileID
                    AND tpPersonID = :tpPersonID AND tpPersonType = :tpPersonType
                    AND hash = :hash
                ORDER BY id DESC LIMIt 1";
        }

        foreach ($results as $result) {
            $tpSubInfo = $this->DB->fetchAssocRow(
                "SELECT tpID, idType ,nameFrom  FROM $DB.{$this->srchTbl} WHERE id = :mmSrchID",
                [':mmSrchID' => $mmSrchID]
            );
            $tpId = (int)$tpSubInfo['tpID'];
            $nameFrom = $tpSubInfo['nameFrom'];
            $idType = $tpSubInfo['idType'];

            $this->debugLog(__FILE__, __LINE__, 'tpSubInfo: ' . print_r($tpSubInfo, true));
            if (!empty($result['date'])) {
                $dateTime = (new \DateTime($result['date']))->format('Y-m-d H:i:s');
            } else {
                $dateTime = date('Y-m-d H:i:s');
            }
            $hash = md5($dateTime . $result['url'] . $result['title'], true);
            $hashNoPub = md5($result['url'] . $result['title'], true);
            $params = [
                ':tenantID'     => $tenantID,
                ':searchID'     => $mmSrchID,
                ':title'        => $result['title'],
                ':relevance'    => $result['relevance'],
                ':published'    => $dateTime,
                ':snippet'      => $result['snippet'],
                ':url'          => $result['url'],
                ':tpProfileID'  => $profileID,
                ':tpPersonID'   => $tpSubInfo['tpID'],
                ':tpPersonType' => $tpSubInfo['idType'],
                ':hash'         => $hash,
                ':hashNoPub'    => $hashNoPub,
            ];

            if ($aiSummaryEnabled) {
                $params[':urlHash'] = md5($result['url'], true);
            }

            if (!empty($spID)) {
                $params[':spID'] = (int)$spID;
            }

            // Check for identical article for same subjext
            $lookupParams = [
                ':tenantID' => $tenantID,
                ':profileID'  => $profileID,
                ':tpPersonID'   => $tpSubInfo['tpID'],
                ':tpPersonType' => $tpSubInfo['idType'],
            ];
            if ($hideResultRevisions) {
                $ckSql = $lookUpNoPubSql;
                $lookupParams[':hashNoPub'] = $hashNoPub;
            } else {
                $ckSql = $lookUpSql;
                $lookupParams[':hash'] = $hash;
            }
            if ($this->DB->fetchValue($ckSql, $lookupParams)) {
                continue; // Duplicate result; ignore it
            }

            if ($this->DB->tableExists('mediaMonResults_del', $DB)) {
                $this->debugLog(__FILE__, __LINE__, "mediaMonResults_del TABLE EXITS");
                $delSql = "SELECT count(*) FROM {$DB}.mediaMonResults_del WHERE 
                tpProfileID = :tpProfileID AND tenantID = :tenantID AND url = :url AND title = :title AND 
                snippet = :snippet AND tpPersonID = :tpPersonID AND tpPersonType = :tpPersonType";
                $delParams = [
                    ':tpProfileID'  => $profileID,
                    ':tenantID'     => $tenantID,
                    ':title'        => $result['title'],
                    ':snippet'      => $result['snippet'],
                    ':url'          => $result['url'],
                    ':tpPersonID'   => $tpSubInfo['tpID'],
                    ':tpPersonType' => $tpSubInfo['idType']
                ];
                $delCount = 0;
                $delCount = $this->DB->fetchValue($delSql, $delParams);
                $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($delSql, $delParams));
                if ($delCount == 0) {
                    $this->DB->query($sql, $params);
                    $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($sql, $params));
                    $params[':id'] = $this->DB->lastInsertId();
                    $validateUrlEnable = true;
                } else {
                    $this->debugLog(__FILE__, __LINE__, "This record is already deleted");
                    continue;
                }
            } else {
                $this->debugLog(__FILE__, __LINE__, "mediaMonResults_del table is not Present");
                $this->DB->query($sql, $params);
                $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($sql, $params));
                $params[':id'] = $this->DB->lastInsertId();
                $validateUrlEnable = true;
            }
            // Push params into array for return including the id for the result row
            $rtn[] = $params;
        }
        $this->debugLog(__FILE__, __LINE__);
        if ($validateUrlEnable && $aiSummaryEnabled) {
            $fork = new ForkProcess();
            $spID = (int)$spID;
            $runTarget = "Models.ThirdPartyManagement.ValidateMediaMonitorUrl::init $tpId $idType $profileID "
                . "$nameFrom $tenantID $spID $DB";
            $fork->launch($runTarget);
        }
        return $rtn;
    }

    /**
     * Returns number of media monitor results needing review for a 3P profile
     *
     * @param integer $tenantID  Tenant ID
     * @param integer $profileID thirdPartyProfile.id
     *
     * @return integer
     */
    public function getUnadjudicatedHitCountsByProfile($tenantID, $profileID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn          = 0;
        $tenantID     = (int)$tenantID;
        $profileID    = (int)$profileID;

        if (empty($tenantID) || empty($profileID)) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $DB           = $this->DB->getClientDB($tenantID);
        $gdcScreenTbl = 'gdcScreening';

        $sql = "SELECT id AS screeningID FROM {$DB}.{$gdcScreenTbl} \n"
            . "WHERE clientID = :tenantID AND tpID = :tpID ORDER BY id DESC LIMIT 1";
        $params = [':tenantID' => $tenantID, ':tpID' => $profileID];
        $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($sql, $params));
        if (!($screeningID = $this->DB->fetchValue($sql, $params))) {
            $this->debugLog(__FILE__, __LINE__, 'No screeningID');
            return $rtn;
        }
        $rtn = $this->syncHitsByDetermination(0, $tenantID, $profileID, $screeningID, 'undetermined');
        return $rtn;
    }

    /**
     * Returns an array of the total number of media monitor results needing review (based on current profile)
     *
     * @param integer $spID          Service Provider ID
     * @param integer $tenantID      Tenant ID
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $screeningID   gdcScreening.id or spGdcScreening.id
     * @param string  $determination Either 'all', 'undetermined', 'falsePositive', 'match'
     *                               or 'adjudicatedOnly'
     *
     * @return array Can contain keys 'match', 'falsePositive', 'undetermined' with integer values for each
     */
    public function getHitsByDetermination($spID, $tenantID, $profileID, $screeningID, $determination)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $spID         = (int)$spID;
        $tenantID     = (int)$tenantID;
        $profileID    = (int)$profileID;
        $screeningID  = (int)$screeningID;
        $determTypes  = ['all', 'adjudicatedOnly', 'undetermined', 'falsePositive', 'match'];
        $DB           = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $gdcResultTbl = (!empty($spID)) ? 'spGdcResult' : 'gdcResult';
        $spCond1      = (!empty($spID)) ? ' AND spID = :spID' : '';
        $polymorphKey = (!empty($this->spID)) ? 'nameID' : 'nameFromID';

        if (empty($tenantID) || empty($profileID) || empty($screeningID)
            || (empty($determination) || !in_array($determination, $determTypes))
        ) {
            $this->debugLog(__FILE__, __LINE__);
            return;
        }

        $rtn = [];
        if (in_array($determination, ['undetermined', 'falsePositive', 'match'])) {
            $rtn[$determination] = 0;
        }
        if (in_array($determination, ['all', 'adjudicatedOnly'])) {
            $rtn['match'] = 0;
            $rtn['falsePositive'] = 0;
            if ($determination == 'all') {
                $rtn['undetermined'] = 0;
            }
        }

        //get gdc results data
        $gdcSql = "SELECT * FROM {$DB}.{$gdcResultTbl} WHERE screeningID = :screeningID AND clientID = :clientID";
        $gdcSql .= $spCond1;
        $params = [':screeningID' => $screeningID, ':clientID' => $tenantID];
        if (!empty($spID)) {
            $params[':spID'] = $spID;
        }
        $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($gdcSql, $params));
        if (!($rows = $this->DB->fetchObjectRows($gdcSql, $params))) {
            $this->debugLog(__FILE__, __LINE__, 'No gdcResults results');
            return $rtn;
        }
        $this->debugLog(__FILE__, __LINE__, print_r($rows, 1));

        if (is_array($rows) && count($rows) > 0) {
            foreach ($rows as &$row) {
                $this->clearCache();
                $gdcResultID   = (int)$row->id;
                $polymorphicID = (int)$row->$polymorphKey;
                $nameFrom      = $row->nameFrom;
                $idType        = ($row->recType == 'Person') ? 'person' : 'profile';
                $searchIDs     = $this->getSearchIDs($spID, $tenantID, $profileID, $polymorphicID, $idType, $nameFrom);
                if (array_key_exists('undetermined', $rtn)) {
                    $rtn['undetermined'] += $this->getMediaMonitorReviewForSubject(
                        $tenantID,
                        $profileID,
                        $polymorphicID,
                        $idType,
                        $nameFrom,
                        $spID,
                        $searchIDs
                    );
                }
                if (array_key_exists('match', $rtn)) {
                    $rtn['match'] += $this->getMediaMonitorMatchForSubject(
                        $tenantID,
                        $profileID,
                        $screeningID,
                        $gdcResultID,
                        $idType,
                        $spID,
                        $searchIDs
                    );
                }
                if (array_key_exists('falsePositive', $rtn)) {
                    $rtn['falsePositive'] += $this->getMediaMonitorFalsePositiveForSubject(
                        $tenantID,
                        $profileID,
                        $screeningID,
                        $gdcResultID,
                        $idType,
                        $spID,
                        $searchIDs
                    );
                }
            }
        }

        $this->debugLog(__FILE__, __LINE__, "getHitsByDetermination results: " . print_r($rtn, true));
        return $rtn;
    }

    /**
     * Gets the total number of media monitor results needing review (based on current profile).
     * If called from the TPM side and not SP, updates appropriate thirdPartyProfile MM Hits field
     * based on determination.
     *
     * @param integer $spID          Service Provider ID
     * @param integer $tenantID      Tenant ID
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $screeningID   gdcScreening.id or spGdcScreening.id
     * @param string  $determination Either 'all', 'undetermined', 'falsePositive', 'match'
     *                               or 'adjudicatedOnly'
     * @param boolean $readOnly      If true this will not update the 3pp columns, else it will
     *
     * @return mixed Array if $determination is 'all' or 'adjudicatedOnly', else integer
     */
    public function syncHitsByDetermination(
        $spID,
        $tenantID,
        $profileID,
        $screeningID,
        $determination,
        $readOnly = false
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $spID        = (int)$spID;
        $tenantID    = (int)$tenantID;
        $profileID   = (int)$profileID;
        $screeningID = (int)$screeningID;
        $writeable   = (!$readOnly && empty($spID));
        $determTypes = ['all', 'adjudicatedOnly', 'undetermined', 'falsePositive', 'match'];
        $fldsMap     = [
            'undetermined' => 'mmUndeterminedHits',
            'falsePositive' => 'mmFalsePositiveHits',
            'match' => 'mmTrueMatchHits'
        ];

        if (empty($tenantID) || empty($profileID) || empty($screeningID)
            || (empty($determination) || !in_array($determination, $determTypes))
        ) {
            $this->debugLog(__FILE__, __LINE__);
            return;
        }

        $rtn = $this->getHitsByDetermination($spID, $tenantID, $profileID, $screeningID, $determination);

        if ($writeable && in_array($determination, ['all', 'adjudicatedOnly'])) {
            // Loop through all determination types, and store # of hits for this 3P
            foreach ($rtn as $determ => $hits) {
                $this->updateProfileMMcol($tenantID, $profileID, $fldsMap[$determ], $hits);
            }
        } elseif ($writeable) {
            // Store # of hits for the particular determination type for this 3P
            $rtn = (int)array_shift($rtn);
            $this->updateProfileMMcol($tenantID, $profileID, $fldsMap[$determination], $rtn);
        }
        $this->debugLog(__FILE__, __LINE__, $rtn);
        return $rtn;
    }

    /**
     * Updates one of several thirdPartyProfile columns
     *
     * @param integer $tenantID  Tenant ID
     * @param integer $profileID thirdPartyProfile.id
     * @param string  $column    thirdPartyProfile MM column to update - either gdcReviewMM,
     *                           mmUndeterminedHits, mmFalsePositiveHits or mmFalsePositiveHits
     * @param integer $value     Value with which to update the column
     *
     * @return void
     */
    private function updateProfileMMcol($tenantID, $profileID, $column, $value)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID  = (int)$tenantID;
        $profileID = (int)$profileID;
        $value     = (int)$value;
        $columns   = ['mmUndeterminedHits', 'mmFalsePositiveHits', 'mmTrueMatchHits'];
        if (empty($tenantID) || empty($profileID) || empty($column) || !in_array($column, $columns)) {
            $this->debugLog(__FILE__, __LINE__);
            return;
        }
        $DB = $this->DB->getClientDB($tenantID);
        $flds   = "$column = :mmCol";
        $params = [':mmCol' => $value, ':profileID' => $profileID];
        if ($column == 'mmUndeterminedHits') {
            $flds .= ", gdcReviewMM = :gdcReviewMM";
            $params[':gdcReviewMM'] = (!empty($value)) ? 1 : 0;
        }
        $sql = "UPDATE {$DB}.thirdPartyProfile SET $flds WHERE id = :profileID";
        $this->DB->query($sql, $params);
    }

    /**
     * Get searchIDs
     *
     * @param integer $spID          Service Provider ID
     * @param integer $tenantID      Tenant ID
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $polymorphicID ID of the a third party or tpPerson
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $nameFrom      mediaMonSrch.nameFrom
     *
     * @return array
     */
    private function getSearchIDs($spID, $tenantID, $profileID, $polymorphicID, $idType, $nameFrom = null)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $spID          = (int)$spID;
        $tenantID      = (int)$tenantID;
        $profileID     = (int)$profileID;
        $polymorphicID = (int)$polymorphicID;
        $rtn           = [];
        if (empty($tenantID) || empty($profileID) || empty($polymorphicID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
        ) {
            $this->debugLog(__FILE__, __LINE__, 'Bad parameters');
            return $rtn;
        }

        $clientDB      = $this->DB->getClientDB($tenantID);
        $DB            = (!empty($spID)) ? $this->DB->spGlobalDB : $clientDB;
        $spCond        = (!empty($spID)) ? ' AND s.spID = :spID' : '';
        $personMapJoin = "INNER JOIN {$clientDB}.tpPersonMap AS m ON (s.profileID = m.tpID AND s.tenantID = m.clientID "
            . "AND s.tpID = m.personID)\n";
        $idTypeJoin    = (empty($spID) && $idType == 'person') ? $personMapJoin : '';

        if (!empty($spID)) {
            $nameFromCond  = ($nameFrom)
                ? " AND (s.nameFrom = :nameFrom OR s.nameFrom = 'name' OR s.nameFrom IS NULL OR s.nameFrom = '')"
                : "";
        } else {
            $nameFromCond  = ($nameFrom)
                ? " AND (s.nameFrom = :nameFrom OR s.nameFrom IS NULL OR s.nameFrom = '')"
                : "";
        }


        // Get all mediaMon searches for our client/tenant, 3pProfile/person
        $sql = "SELECT DISTINCT s.id FROM {$DB}.mediaMonSrch AS s\n"
            . $idTypeJoin
            . "WHERE s.tenantID = :tenantID AND s.profileID = :profileID AND s.tpID = :polymorphicID "
            . "AND s.idType = :idType{$spCond}{$nameFromCond}\n"
            . "ORDER BY s.id DESC";
        $params = [
            ':tenantID' => $tenantID,
            ':profileID' => $profileID,
            ':polymorphicID' => $polymorphicID,
            ':idType' => $idType
        ];
        if (!empty($spID)) {
            $params[':spID'] = $spID;
        }
        if (!empty($nameFrom)) {
            $params[':nameFrom'] = $nameFrom;
        }

        $srchIDs = $this->DB->fetchValueArray($sql, $params);
        if (!$srchIDs) {
            $this->debugLog(__FILE__, __LINE__, "no srchIDS, here is the SQL: "
            . $this->DB->mockFinishedSQL($sql, $params));
            return $rtn;
        }
        $this->debugLog(__FILE__, __LINE__, print_r($srchIDs, 1));
        $rtn = $srchIDs;
        return $rtn;
    }

    /**
     * Common post processing for methods counting the total number of MM results needing review
     *
     * @param integer $spID                       Service Provider ID
     * @param integer $tenantID                   Tenant ID
     * @param array   $searchIDs                  Service Provider ID
     * @param array   $polymorphicIDidTypeKeyVals Key value array of polymorphicIDs and idTypes
     *
     * @return integer
     */
    private function reviewsNeededCountPP($spID, $tenantID, $searchIDs, $polymorphicIDidTypeKeyVals)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $spID         = (int)$spID;
        $tenantID     = (int)$tenantID;
        $rtn          = 0;
        $DB           = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $searchIDs    = implode(",", $searchIDs);
        // Get all mediaMonResults for the current record
        $sql = "SELECT rslt.id AS rsltsID, srch.id AS srchID, srch.idType, srch.tpID AS polymorphicID\n"
            . "FROM {$DB}.mediaMonResults AS rslt\n"
            . "LEFT JOIN {$DB}.mediaMonSrch AS srch ON rslt.searchID = srch.id\n"
            . "WHERE srch.id IN({$searchIDs})\n"
            . "AND rslt.deleted = 0\n"
            . "GROUP BY rslt.tenantID, rslt.tpProfileID, rslt.tpPersonID, rslt.tpPersonType, "
            . "rslt.url";
        $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($sql, []));

        $resRows = $this->DB->fetchAssocRows($sql, []);
        if ($resRows && count($resRows) > 0) {
            $this->debugLog(__FILE__, __LINE__, print_r($resRows, 1));
            foreach ($resRows as $resRow) {
                if ($polymorphicIDidTypeKeyVals[$resRow['polymorphicID']] != $resRow['idType']) {
                    continue;
                }
                $rtn += $this->isDeterminationMatched(
                    $spID,
                    $tenantID,
                    $resRow['rsltsID'],
                    $resRow['srchID'],
                    'undetermined'
                );
            }
        }
        return $rtn;
    }

    /**
     * Based upon the type of determination passed in, see if any adjudication records match
     *
     * @param integer $spID              Service Provider ID
     * @param integer $tenantID          Tenant ID
     * @param integer $rsltsID           mediaMonResults.id
     * @param integer $srchID            mediaMonSrch.id
     * @param string  $determinationType String containing the determination type to test against (ie; 'undetermined')
     *
     * @return integer
     */
    private function isDeterminationMatched($spID, $tenantID, $rsltsID, $srchID, $determinationType = '')
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $spID     = (int)$spID;
        $tenantID = (int)$tenantID;
        $srcID    = (int)$srchID;
        $recID    = (int)$rsltsID;

        if (empty($tenantID) || empty($srcID) || empty($recID)) {
            return 0;
        }

        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);

        // Get all existing adjudications for the record
        $sql = "SELECT determination, source_recid, prevDetermination FROM {$DB}.{$this->rvlogTbl}\n"
            . "WHERE screeningID = :srcID AND source_recid = :recID\n"
            . "ORDER BY whenReviewed DESC LIMIT 1";
        $params = [':srcID' => $srcID, ':recID' => $recID];

        $details = $this->DB->fetchAssocRow($sql, $params);
        $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($sql, $params));

        /*
         * If we are checking for unadjudicated hits, there will either not be any results logged
         * in mediaMonReviewLog or else there will be results marked as 'undetermined'.
         * If we are checking for adjudicated hits, there will be results logged in mediaMonReviewLog
         * marked as either 'falsePositive' or 'match'
         */

        $matched = (($determinationType == 'undetermined'
            && (!isset($details['determination']) || $details['determination'] == $determinationType))
            || (in_array($determinationType, ['falsePositive', 'match']) && isset($details['determination'])
                && $details['determination'] == $determinationType)
        );
        $this->debugLog(__FILE__, __LINE__, 'isDeterminationMatched: {$matched}');
        return ($matched) ? 1 : 0;
    }

    /**
     * Update nameFrom col in mediaMonSrch
     *
     * @param integer $srchID   mediaMonsrch id to update
     * @param string  $nameFrom nameFrom val to update to
     * @param integer $tenantID tenant id
     * @param integer $spID     g_mediaMonQueue.spID
     *
     * @return void
     */
    public function updateNameFrom($srchID, $nameFrom, $tenantID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $srchID = (int)$srchID;
        $tenantID = (int)$tenantID;
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        if (!($nameFrom == 'tpPerson' || $nameFrom == 'name') && !$this->isValidTpNameCol($nameFrom, $DB, $spID)) {
            return;
        }
        $this->DB->query(
            "UPDATE $DB.{$this->srchTbl} SET nameFrom = :nameFrom WHERE id = :srchID",
            [':nameFrom' => $nameFrom, ':srchID' => $srchID]
        );
    }

    /**
     * Return whether this is a valid tpNameCol
     *
     * @param string  $rrNameFrom nameFrom value
     * @param integer $DB         Name of client DB
     * @param integer $spID       g_mediaMonQueue.spID
     *
     * @return boolean
     */
    private function isValidTpNameCol($rrNameFrom, $DB, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        if ($spID && $rrNameFrom === 'name') {
            return true;
        }
        $colArr = [
            'legalName',
            'DBAname'
        ];
        if (!in_array($rrNameFrom, $colArr)) {
            return false;
        }
        return true;
    }

    /**
     * Get the search thresholds for the tenant
     *
     * @param integer $tenantID Tenant ID
     *
     * @return array Assoc array of tenant Thresholds
     */
    public function getTenantThresholds($tenantID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID = (int)$tenantID;
        $rtn = [];
        if (empty($tenantID)) {
            $this->debugLog(__FILE__, __LINE__, 'Invalid tenant');
            return $rtn;
        }
        $clientDB = $this->DB->getClientDB($tenantID);
        if (!$clientDB || !$this->DB->databaseExists($clientDB)) {
            $this->debugLog(__FILE__, __LINE__, 'Invalid clientDB');
            return $rtn;
        }
        $sql = "SELECT LOWER(mediaMonThreshold) AS scope, mediaMonRelevancy AS threshold\n"
            . "FROM $clientDB.clientProfile\n"
            . "WHERE id = :id LIMIT 1";
        $params = [':id' => $tenantID];
        $rtn = $this->DB->fetchAssocRow($sql, $params);
        $this->debugLog(__FILE__, __LINE__, "Thresholds: " . print_r($rtn, true));
        return $rtn;
    }

    /**
     * Keep querying until we know all searches have a finished status
     *
     * @param array   $apiIDs   mediaMonRequests.apiID
     * @param integer $tenantID Tenant ID
     * @param integer $spID     Service Provider ID
     *
     * @return boolean If all searches were finished true, else false
     */
    public function didAllSearchesFinish($apiIDs, $tenantID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $spID     = (int)$spID;
        $tenantID = (int)$tenantID;

        //Removing blank values from apiIDs array to fix SQL error TPM-2919
        $apiIDsInts = [];
        foreach ($apiIDs as $oneV) {
            if (!empty($oneV)) {
                array_push($apiIDsInts, $oneV);
            }
        }
        $apiIDs = $apiIDsInts;
        unset($apiIDsInts);

        if (empty($tenantID) || !is_array($apiIDs) || empty($apiIDs)) {
            return;
        }
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $sql = "SELECT srch.status AS status FROM {$DB}.mediaMonSrch AS srch\n"
            . "LEFT JOIN {$DB}.mediaMonRequests AS reqs ON reqs.id = srch.userRequestID\n"
            . "WHERE reqs.apiID IN(" . implode(",", $apiIDs) . ")\n"
            . "GROUP BY status ORDER BY status ASC";
        $areWeThereYet = false;
        $seconds = 0;
        while ($areWeThereYet === false) {
            $srchStatuses = $this->DB->fetchValueArray($sql);

            if (($seconds == self::FINISHED_THRESHOLD)
                || (count($srchStatuses) === 1 && in_array('finished', $srchStatuses))
            ) {
                $this->debugLog(__FILE__, __LINE__, "didAllSearchesFinish took {$seconds} seconds");
                $areWeThereYet = true;
            } else {
                $seconds++;
                sleep(1); // Give the DB a break
            }
        }
        return ($seconds < self::FINISHED_THRESHOLD);
    }

    /**
     * Get the date screened
     *
     * @param integer $tenantID  Tenant ID
     * @param integer $profileID thirdPartyProfile.id
     * @param integer $spID      Service Provider ID
     *
     * @return string Date(Y-m-d)
     */
    public function getCurrentScreeningDateByProfile($tenantID, $profileID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID  = (int)$tenantID;
        $spID      = (int)$spID;
        $profileID = (int)$profileID;
        $rtn       = '';
        if (empty($tenantID) || empty($profileID)) {
            $this->debugLog(__FILE__, __LINE__, '', __METHOD__);
            return $rtn;
        }
        $DB       = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $spClause = (!empty($spID)) ? ' AND srch.spID = :spID' : '';
        $sql = "SELECT DATE_FORMAT(srch.received, '%Y-%m-%d')\n"
            . "FROM {$DB}.mediaMonSrch AS srch\n"
            . "LEFT JOIN {$DB}.mediaMonResults AS rslt ON rslt.searchID = srch.id\n"
            . "WHERE srch.id > 0 AND rslt.searchID IS NOT NULL AND srch.status = 'finished' "
            . "AND rslt.deleted = 0\n"
            . "AND srch.profileID = :profileID AND srch.tenantID = :tenantID{$spClause}\n"
            . "ORDER BY srch.received DESC LIMIT 1";
        $params = [':profileID' => $profileID, ':tenantID' => $tenantID];
        if (!empty($spID)) {
            $params[':spID'] = $spID;
        }
        if ($date = $this->DB->fetchValue($sql, $params)) {
            $rtn = $date;
        }
        $this->debugLog(__FILE__, __LINE__, "Date: $rtn");
        return $rtn;
    }

    /**
     * Get an array containing the rvw, uc, tm, fp and uc counts for Media Monitor hits
     * based on profileID and screeningID
     *
     * @param integer $tenantID    Tenant ID
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param array   $subjects    gdcResult includes id, nameID, nameFromID, nameFrom and recType cols
     * @param integer $spID        Service Provider ID
     *
     * @return mixed
     */
    public function getMediaMonitorStatusObj($tenantID, $profileID, $screeningID, $subjects, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID    = (int)$tenantID;
        $spID        = (int)$spID;
        $profileID   = (int)$profileID;
        $screeningID = (int)$screeningID;

        // Base MM status object
        $rtn = (object) [
            'attn' => 0,
            'sums' => [
                'r6000' => [
                    'rvw'   =>  0,
                    'tm'    =>  0,
                    'fp'    =>  0,
                ],
            ],
            'message' => (object) [
                'yesNo'   => 'Yes', // based on needing to be reviewed i.e. undetermined > 1 then 'Yes', else 'No'
                'details' => ''
            ],
            'lists' => (object) [
                'mm' => 0
            ],
            'flags' => (object) [ // aggregate of all of the individual sums of the various subjects in the screening
                'falsePositive' => 0,
                'undetermined'  => 0,
                'match'         => 0
            ],
            'status' => (object) [ // relates to flags object
                'new'       => 0,
                'changed'   => 0,
                'unchanged' => 0,
                'error'     => 0
            ]
        ];

        if (empty($spID)) {
            $rtn->sums['r6000']['uc'] = 0;
        }

        if (empty($tenantID) || empty($profileID) || empty($screeningID)) {
            return $rtn;
        }

        $mmCounts = $this->formatSubjectSums($tenantID, $profileID, $screeningID, $subjects, $spID);
        if (is_array($mmCounts) && count($mmCounts)) {
            foreach ($mmCounts as $record) {
                $rtn->flags->undetermined += $record['rvw'];
                $rtn->flags->match += $record['tm'];
                $rtn->flags->falsePositive += $record['fp'];
                $rtn->lists->mm += ($record['rvw'] + $record['tm'] + $record['fp']);
            }
        }
        if (empty($rtn->flags->undetermined)) {
            $rtn->message->yesNo = 'No';
        }
        $rtn->sums = $mmCounts;
        if (empty($spID)) {
            $rtn->sumsrvw = $mmCounts;
        }
        $this->debugLog(__FILE__, __LINE__, 'end of getMediaMonitorStatusObj');
        return $rtn;
    }

    /**
     * Calculates and formats the sums for each of the gdcResult(s) MM hits
     * Used as part of getMediaMonitorStatusObj
     *
     * @param integer $tenantID    Tenant ID
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param array   $subjects    gdcResult includes id, nameID, nameFromID, nameFrom and recType cols
     * @param integer $spID        Service Provider ID
     *
     * @return array
     */
    private function formatSubjectSums($tenantID, $profileID, $screeningID, $subjects, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID     = (int)$tenantID;
        $spID         = (int)$spID;
        $profileID    = (int)$profileID;
        $screeningID  = (int)$screeningID;
        $polymorphKey = (!empty($spID)) ? 'nameID' : 'nameFromID';
        $rtn          = [];

        if (empty($tenantID) || empty($profileID) || empty($screeningID)
            || !is_array($subjects) || empty($subjects)
        ) {
            return $rtn;
        }

        $struct = ['rvw' => 0, 'tm' => 0, 'fp' => 0];
        if (empty($spID)) {
            $struct['uc'] = 0;
        }

        foreach ($subjects as $subject) {
            $this->clearCache();
            $idType = ($subject['recType'] == 'Person') ? 'person' : 'profile';
            $nameFrom = ($polymorphKey == 'nameFromID') ? $subject['nameFrom'] : null;
            $gdcResultID = $subject['id'];
            $polymorphicID = $subject[$polymorphKey];
            $rtn['r' . $gdcResultID] = $struct;
            $rtn['r' . $gdcResultID]['rvw'] = $this->getMediaMonitorReviewForSubject(
                $tenantID,
                $profileID,
                $polymorphicID,
                $idType,
                $nameFrom,
                $spID
            );
            $rtn['r' . $gdcResultID]['tm'] = $this->getMediaMonitorMatchForSubject(
                $tenantID,
                $profileID,
                $screeningID,
                $gdcResultID,
                $idType,
                $spID
            );
            $rtn['r' . $gdcResultID]['fp'] = $this->getMediaMonitorFalsePositiveForSubject(
                $tenantID,
                $profileID,
                $screeningID,
                $gdcResultID,
                $idType,
                $spID
            );
            if (empty($spID)) {
                $rtn['r' . $gdcResultID]['uc'] = $this->getMediaMonitorUnchangedForSubject(
                    $tenantID,
                    $profileID,
                    $screeningID,
                    $gdcResultID
                );
            }
        }
        $this->debugLog(__FILE__, __LINE__, 'end of formatSubjectSums');
        return $rtn;
    }

    /**
     * Get Media Monitor hits awaiting review for a gdcResult subject
     *
     * @param integer $tenantID      Tenant ID
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $polymorphicID ID of the a third party or tpPerson
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $nameFrom      mediaMonSrch.nameFrom
     * @param integer $spID          Service Provider ID
     * @param array   $searchIDS     Array of mediaMonSrch.ids for determining adjudications
     *
     * @return integer
     */
    public function getMediaMonitorReviewForSubject(
        $tenantID,
        $profileID,
        $polymorphicID,
        $idType,
        $nameFrom = null,
        $spID = 0,
        $searchIDS = null
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID      = (int)$tenantID;
        $spID          = (int)$spID;
        $profileID     = (int)$profileID;
        $polymorphicID = (int)$polymorphicID;
        $rtn           = 0;
        if (empty($tenantID) || empty($profileID) || empty($polymorphicID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
        ) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $searchIDs = (!empty($searchIDS))
            ? $searchIDS
            : $this->getSearchIDs($spID, $tenantID, $profileID, $polymorphicID, $idType, $nameFrom);
        if (empty($searchIDs)) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $rtn = $this->reviewsNeededCountPP($spID, $tenantID, $searchIDs, [$polymorphicID => $idType]);
        $this->debugLog(__FILE__, __LINE__, "getMediaMonitorReviewForSubject results: {$rtn}");
        return $rtn;
    }

    /**
     * Get Media Monitor hits marked as true matches for a gdcResult subject
     *
     * @param integer $tenantID    Tenant ID
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param integer $gdcResultID gdcResult.id
     * @param string  $idType      Either 'person' or 'profile'
     * @param integer $spID        Service Provider ID
     *
     * @return integer
     */
    public function getMediaMonitorMatchForSubject(
        $tenantID,
        $profileID,
        $screeningID,
        $gdcResultID,
        $idType,
        $spID = 0
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $result = $this->getMediaMonAdjudicationCount(
            $tenantID,
            $profileID,
            $screeningID,
            $gdcResultID,
            $idType,
            'match',
            $spID
        );
        return $result;
    }

    /**
     * Get Media Monitor hits marked as false positives review for a gdcResult subject
     *
     * @param integer $tenantID    Tenant ID
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param integer $gdcResultID gdcResult.id
     * @param string  $idType      Either 'person' or 'profile'
     * @param integer $spID        Service Provider ID
     *
     * @return integer
     */
    public function getMediaMonitorFalsePositiveForSubject(
        $tenantID,
        $profileID,
        $screeningID,
        $gdcResultID,
        $idType,
        $spID = 0
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $result = $this->getMediaMonAdjudicationCount(
            $tenantID,
            $profileID,
            $screeningID,
            $gdcResultID,
            $idType,
            'falsePositive',
            $spID
        );
        return $result;
    }

    /**
     * Gets the media monitor hits whose adjudications have not changed this session
     * This functionality is session based in legacy and the function is incomplete, currently it will always return 0
     *
     * @param integer $tenantID    Tenant ID
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param integer $record      gdcResult.id
     *
     * @TO-DO need to solve the unchanged
     *
     * @return integer
     */
    public function getMediaMonitorUnchangedForSubject($tenantID, $profileID, $screeningID, $record)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        return 0;
    }

    /**
     * Get a count of the adjudication records for a person/entity based upon the adjudication type
     *
     * @param integer $tenantID      Tenant ID
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $screeningID   gdcScreening.id
     * @param integer $gdcResultID   gdcResult.id
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $determination Type of adjudication to reference (ie; match, falsePositive)
     * @param integer $spID          Service Provider ID
     * @param array   $searchIDs     Array of mediaMonSrch.ids for determining adjudications
     *
     * @return integer
     */
    private function getMediaMonAdjudicationCount(
        $tenantID,
        $profileID,
        $screeningID,
        $gdcResultID,
        $idType,
        $determination,
        $spID = 0,
        $searchIDs = null
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID     = (int)$tenantID;
        $spID         = (int)$spID;
        $profileID    = (int)$profileID;
        $screeningID  = (int)$screeningID;
        $gdcResultID  = (int)$gdcResultID;
        $rtn          = (int)0;
        $gdcResultTbl = (!empty($spID)) ? 'spGdcResult' : 'gdcResult';
        $polymorphKey = (!empty($spID)) ? 'nameID' : 'nameFromID';
        $nameFromCond = (!empty($spID)) ? '' : ' AND gr.nameFrom = :nameFrom';

        if (empty($this->gdc)) {
            $this->gdc = new Gdc($tenantID);
        }
        if ($determination == 'remediation') {
            $determination = 'match';
            $getRemediationCount = true;
            if (is_null($this->adjReasons)) {
                $this->adjReasons = $this->gdc->getAdjudicationReasons('Both');
            }
        } else {
            $getRemediationCount = false;
        }

        if (empty($tenantID) || empty($profileID) || empty($screeningID) || empty($gdcResultID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
            || empty($determination) || !in_array($determination, ['match', 'falsePositive'])
        ) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }

        $DB  = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $sql = "SELECT {$polymorphKey}, nameFrom FROM {$DB}.{$gdcResultTbl} WHERE id = :gdcResultID";
        $params = [':gdcResultID' => $gdcResultID];

        $gdcResultRow = $this->DB->fetchAssocRow($sql, $params);

        if (!$gdcResultRow) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $polymorphicID = $gdcResultRow[$polymorphKey];
        $nameFrom      = (!empty($spID)) ? null : $gdcResultRow['nameFrom'];
        $searchIDs = (!empty($searchIDS))
            ? $searchIDS
            : $this->getSearchIDs($spID, $tenantID, $profileID, $polymorphicID, $idType, $nameFrom);
        if (empty($searchIDs)) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $searchIDs = implode(",", $searchIDs);

        if (is_null($this->reviewLogIDs)) {
            $sql = "SELECT MAX(id), source_recid FROM {$DB}.{$this->rvlogTbl} \n"
                . "WHERE screeningID IN ({$searchIDs}) GROUP BY source_recid";
            $reviewLogResult = $this->DB->fetchValueArray($sql);

            if (!$reviewLogResult) {
                return $rtn;
            }
            $this->reviewLogIDs = $reviewLogResult;
        }

        if (!$this->gdcResultCache) {
            $sql = "SELECT l.id, l.determination, l.reasonID, l.remediation FROM {$DB}.{$this->rvlogTbl} AS l\n"
                . "INNER JOIN {$DB}.{$gdcResultTbl} AS gr ON (l.tpID = gr.{$polymorphKey})\n"
                . "WHERE l.screeningID IN ({$searchIDs}) AND gr.screeningID = :screeningID {$nameFromCond}";
            $params = [':screeningID' => $screeningID];
            if (empty($spID)) {
                $params[':nameFrom'] = $nameFrom;
            }
            $this->gdcResultCache = $this->DB->fetchAssocRows($sql, $params);
            $this->debugLog(__FILE__, __LINE__, $this->DB->mockFinishedSQL($sql, $params));
        }

        $count = 0;

        foreach ($this->gdcResultCache as $info) {
            if ($info['determination'] == $determination && in_array($info['id'], $this->reviewLogIDs)) {
                if ($getRemediationCount) {
                    foreach ($this->adjReasons['match'] as $matchReason) {
                        if ($matchReason->id == $info['reasonID']
                            && $matchReason->needsRemediation
                            && $info['remediation'] == 0
                        ) {
                            $count++;
                        }
                    }
                } else {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Clear cached items
     *
     * @return void
     */
    public function clearCache()
    {
        $this->gdcResultCache = null;
        $this->reviewLogIDs = null;
    }
}
