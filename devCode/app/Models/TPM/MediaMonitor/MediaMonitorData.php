<?php
/**
 * Duplicated functionality from
 * public_html/cms/includes/php/Models/TPM/MediaMonitor/MediaMonitorData.php
 * Used by Analytic > 3P Monitor tool to determine number of MM hits needing remediation
 *
 * @keywords Media, Monitor, GDC, thirdParty, monitor, refine, refinement
 *
 */

namespace Models\TPM\MediaMonitor;

use Lib\SettingACL;
use Lib\FeatureACL;
use Models\ThirdPartyManagement\Gdc;
use Models\Globals\Features\TenantFeatures;

/**
 * Code for accessing data related to the Media Monitor UI tool;
 */
#[\AllowDynamicProperties]
class MediaMonitorData
{
    /**
     * Reference to a passed in DB class object
     *
     * @var object
     */
    private $DB;

    /**
     * SP ID
     *
     * @var integer
     */
    private $spID = 0;

    /**
     * Name of tenant cid DB
     *
     * @var string
     */
    private $clientDB;

    /**
     * @var bool
     */
    public $enableTrueMatch = false;

    /**
     * Hold results of review log query so it only runs once
     *
     * @var null|array
     */
    private $reviewLogIDs = null;

    /**
     * Hold adjudication reasons so it only runs once
     *
     * @var null|array
     */
    private $adjReasons = null;

    /**
     * Cache gdc results to reduce number of queries
     *
     * @var array
     */
    private $gdcResultCache = [];

    /**
     * Build instance
     *
     * @param integer $tenantID Tenant ID
     * @param integer $spID     SP ID
     */
    public function __construct(/**
         * Client ID
         */
        private $tenantID,
        $spID = 0
    ) {
        $app = \Xtra::app();
        $this->DB = $app->DB;
        $this->clientDB = $this->DB->getClientDB($this->tenantID);
    }


    /**
     * Get Media Monitor hits awaiting review for a gdcResult subject
     *
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $polymorphicID ID of the a third party or tpPerson
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $nameFrom      mediaMonSrch.nameFrom
     *
     *
     * @return integer
     */
    public function getMediaMonitorReviewForSubject($profileID, $polymorphicID, $idType, $nameFrom = null)
    {
        $profileID     = (int)$profileID;
        $polymorphicID = (int)$polymorphicID;
        $rtn           = (int)0;
        $DB            = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;
        if (empty($this->tenantID) || empty($profileID) || empty($polymorphicID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
        ) {
            return $rtn;
        }
        if (!($searchIDs = $this->getSearchIDs($profileID, $polymorphicID, $idType, $nameFrom)) || empty($searchIDs)) {
            return $rtn;
        }
        $searchIDs = implode(",", $searchIDs);
        $joinData = $this->getMmJoin($polymorphicID, $idType);
        $searchAsFilter = $this->getNameFromFilter($idType, $nameFrom);
        $nameFromClause = $searchAsFilter['filter']
            ? $searchAsFilter['filter'] . " AND "
            : "WHERE";
        // Get all mediaMon search 'results' for our client/tenant, 3pprofile and person/entity
        $sql = "SELECT res.id AS id, res.searchID, params.paramValue AS refinement\n"
            . "{$joinData['select']}\n"
            . "FROM {$DB}.mediaMonResults AS res\n"
            . "INNER JOIN {$DB}.mediaMonSrch AS srch ON srch.idType = :idType\n"
            . "AND srch.tpID = :polymorphicID AND srch.profileID = :profileID AND res.searchID = srch.id\n"
            . "LEFT JOIN {$DB}.mediaMonRequests AS reqs ON reqs.id = srch.userRequestID\n"
            . "LEFT JOIN {$DB}.mediaMonReqParams AS params ON reqs.id = params.userRequestID\n"
            . "{$joinData['join']}\n"
            . "{$nameFromClause}\n"
            . "res.searchID IN ({$searchIDs})\n"
            . "GROUP BY res.tenantID, res.tpProfileID, res.tpPersonID, res.tpPersonType, res.url\n"
            . "ORDER BY reqs.id DESC, res.relevance DESC";
        $params = [':idType' => $idType, ':polymorphicID' => $polymorphicID, ':profileID' => $profileID];
        if (array_key_exists(':nameFrom', $searchAsFilter['params'])) {
            $params[':nameFrom'] = $searchAsFilter['params'][':nameFrom'];
        }
        $resRows = $this->DB->fetchAssocRows($sql, $params);

        if (!empty($resRows)) {
            foreach ($resRows as $resRow) {
                $rtn += $this->isDeterminationMatched($resRow['id'], $resRow['searchID'], 'undetermined');
            }
        }
        return $rtn;
    }


    /**
     * Get a query piece that distinguishes which name-from table
     * and field to pull from
     *
     * @param string $idType   Indicates person vs profile (i.e. "entity")
     * @param string $nameFrom string indicating to use the tpPersons table or a column
     *                         in thirdPartyProfile table
     * @param string $prefix   SQL logical operator
     *
     * @return array
     */
    private function getNameFromFilter($idType, $nameFrom, $prefix = 'WHERE')
    {
        $DB = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;
        $rtn = [
            'filter' => "{$prefix} (srch.nameFrom = :nameFrom OR srch.nameFrom IS NULL) AND res.deleted = 0",
            'params' => [':nameFrom' => $nameFrom]
        ];
        return $rtn;
    }

    /**
     * Get mm join (a query piece that adds in a join
     * to the query returned by getCachedResults as well as getMediaMonitorReviewForSubject).
     *
     * @param integer $id     ID in srch table
     * @param string  $idType "person" vs "profile"
     *
     * @return array Array containing the join data to add to a query
     *
     * @throws \Exception
     */
    private function getMmJoin($id, $idType)
    {
        if (!in_array($idType, ['profile', 'person'])) {
            throw new \Exception('Bad tp type');
        }
        $DB = (!empty($this->spID)) ? $this->DB->globalDB : $this->DB->getClientDB($this->tenantID);
        if ($idType === 'person') {
            // @todo: consider taking g_gdcSearchName out of here as a string and provide as property?
            $tbl = (!empty($this->spID)) ? 'g_gdcSearchName' : 'tpPerson';
            $fld = (!empty($this->spID)) ? 'name' : 'fullName';
            $join = "LEFT JOIN {$DB}.{$tbl} AS tpOrTpp ON tpOrTpp.id = srch.tpID";
            $select = ", tpOrTpp.{$fld} AS tpOrTppNm, tpOrTpp.id AS personID, NULL AS profileID";
        } else {
            $tbl = (!empty($this->spID)) ? 'g_gdcSearchName' : 'thirdPartyProfile';
            $fld = (!empty($this->spID)) ? 'name' : 'legalName';
            $join = "LEFT JOIN {$DB}.{$tbl} AS tpOrTpp ON tpOrTpp.id = srch.tpID";
            $select = ", tpOrTpp.{$fld} AS tpOrTppNm, NULL AS personID, tpOrTpp.id AS profileID";
        }
        return ['join' => $join, 'select' => $select];
    }

    /**
     * Based upon the type of determination passed in, see if any adjudication records match
     *
     * @param integer $rsltsID           mediaMonResults.id
     * @param integer $srchID            mediaMonSrch.id
     * @param string  $determinationType String containing the determination type to test against (ie; 'undetermined')
     *
     * @return integer
     */
    private function isDeterminationMatched($rsltsID, $srchID, $determinationType = '')
    {
        $srcID = (int)$srchID;
        $recID = (int)$rsltsID;
        $DB    = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;

        if (empty($srcID) || empty($recID)) {
            return 0;
        }

        // Get all existing adjudications for the record
        $sql = "SELECT determination, source_recid, prevDetermination FROM {$DB}.mediaMonReviewLog\n"
            . "WHERE screeningID = :srcID AND source_recid = :recID\n"
            . "ORDER BY whenReviewed DESC LIMIT 1";
        $params = [':srcID' => $srcID, ':recID' => $recID];
        $details = $this->DB->fetchAssocRow($sql, $params);

        /**
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

        return ($matched) ? 1 : 0;
    }

    /**
     * Gets the media monitor hits whose adjudications have not changed this session
     * This functionality is session based in legacy and the function is incomplete, currently it will always return 0
     *
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param integer $record      gdcResult.id
     *
     * @todo need to solve the unchanged
     * @return integer
     */
    public function getMediaMonitorUnchangedForSubject($profileID, $screeningID, $record)
    {
        return 0;
    }

    /**
     * Get Media Monitor hits marked as false positives review for a gdcResult subject
     *
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param integer $gdcResultID gdcResult.id
     * @param string  $idType      Either 'person' or 'profile'
     *
     * @return integer
     */
    public function getMediaMonitorFalsePositiveForSubject($profileID, $screeningID, $gdcResultID, $idType)
    {
        return $this->getMediaMonAdjudicationCount($profileID, $screeningID, $gdcResultID, $idType, 'falsePositive');
    }

    /** Get number of Media Monitor hits needing remediation
     *
     * @param $profileID
     * @param $screeningID
     * @param $gdcResultID
     * @param $idType
     * @return int
     */
    public function getMediaMonitorRemediationCount($profileID, $screeningID, $gdcResultID, $idType)
    {
        return $this->getMediaMonAdjudicationCount($profileID, $screeningID, $gdcResultID, $idType, 'remediation');
    }

    /**
     * Get Media Monitor hits marked as true matches for a gdcResult subject
     *
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param integer $gdcResultID gdcResult.id
     * @param string  $idType      Either 'person' or 'profile'
     *
     * @return integer
     */
    public function getMediaMonitorMatchForSubject($profileID, $screeningID, $gdcResultID, $idType)
    {
        return $this->getMediaMonAdjudicationCount($profileID, $screeningID, $gdcResultID, $idType, 'match');
    }

    /**
     * Get a count of the adjudication records for a person/entity based upon the adjudication type
     *
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $screeningID   gdcScreening.id
     * @param integer $gdcResultID   gdcResult.id
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $determination Type of adjudication to reference (ie; match, falsePositive)
     *
     * @return integer
     */
    private function getMediaMonAdjudicationCount($profileID, $screeningID, $gdcResultID, $idType, $determination)
    {
        $gdc = new Gdc($this->tenantID);
        if ($determination == 'remediation') {
            $determination = 'match';
            $getRemediationCount = true;
            if (is_null($this->adjReasons)) {
                $this->adjReasons = $gdc->getAdjudicationReasons('Both');
            }
        } else {
            $getRemediationCount = false;
        }

        $profileID    = (int)$profileID;
        $screeningID  = (int)$screeningID;
        $gdcResultID  = (int)$gdcResultID;
        $rtn          = (int)0;
        $DB           = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;
        $gdcResultTbl = (!empty($this->spID)) ? 'spGdcResult' : 'gdcResult';
        $polymorphKey = (!empty($this->spID)) ? 'nameID' : 'nameFromID';
        $nameFromCond = (!empty($this->spID)) ? '' : ' AND gr.nameFrom = :nameFrom';

        if (empty($this->tenantID) || empty($profileID) || empty($screeningID) || empty($gdcResultID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
            || empty($determination) || !in_array($determination, ['match', 'falsePositive'])
        ) {
            return $rtn;
        }

        $sql = "SELECT {$polymorphKey}, nameFrom FROM {$DB}.{$gdcResultTbl} WHERE id = :gdcResultID";
        if (!($gdcResultRow = $this->DB->fetchAssocRow($sql, [':gdcResultID' => $gdcResultID]))) {
            return $rtn;
        }
        $polymorphicID = $gdcResultRow[$polymorphKey];
        $nameFrom      = (!empty($this->spID)) ? null : $gdcResultRow['nameFrom'];

        if (!($searchIDs = $this->getSearchIDs($profileID, $polymorphicID, $idType, $nameFrom)) || empty($searchIDs)) {
            return $rtn;
        }
        $searchIDs = implode(",", $searchIDs);

        if (is_null($this->reviewLogIDs)) {
            $sql = "SELECT MAX(id), source_recid FROM {$DB}.mediaMonReviewLog \n"
                . "WHERE screeningID IN ({$searchIDs}) GROUP BY source_recid";
            if (!($reviewLogResult = $this->DB->fetchValueArray($sql))) {
                return $rtn;
            }
            $this->reviewLogIDs = $reviewLogResult;
        }

        if (!$this->gdcResultCache) {
            $sql = "SELECT l.id, l.determination, l.reasonID, l.remediation FROM {$DB}.mediaMonReviewLog AS l\n"
                . "INNER JOIN {$DB}.{$gdcResultTbl} AS gr ON (l.tpID = gr.{$polymorphKey})\n"
                . "WHERE l.screeningID IN ({$searchIDs}) AND gr.screeningID = :screeningID {$nameFromCond}";
            $params = [':screeningID' => $screeningID];
            if (empty($this->spID)) {
                $params[':nameFrom'] = $nameFrom;
            }
            $this->gdcResultCache = $this->DB->fetchAssocRows($sql, $params);
        }

        $count = 0;

        foreach ($this->gdcResultCache as $info) {
            if ($info['determination'] == $determination && in_array($info['id'], $this->reviewLogIDs)) {
                if ($getRemediationCount) {
                    foreach ($this->adjReasons['match'] as $matchReason) {
                        if ($matchReason->id == $info['reasonID']
                            && $matchReason->needsRemediation
                            && $info['remediation'] == 0) {
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
     * Get searchIDs
     *
     * @param integer $profileID     thirdPartyProfile.id
     * @param integer $polymorphicID ID of the a third party or tpPerson
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $nameFrom      mediaMonSrch.nameFrom
     *
     * @return array
     */
    private function getSearchIDs($profileID, $polymorphicID, $idType, $nameFrom = null)
    {
        $profileID     = (int)$profileID;
        $polymorphicID = (int)$polymorphicID;
        $clientDB      = $this->clientDB;
        $DB            = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;
        $spCond        = (!empty($this->spID)) ? ' AND s.spID = :spID' : '';
        $personMapJoin = "INNER JOIN {$clientDB}.tpPersonMap AS m ON (s.profileID = m.tpID AND s.tenantID = m.clientID "
            . "AND s.tpID = m.personID)\n";
        $idTypeJoin    = (empty($this->spID) && $idType == 'person') ? $personMapJoin : '';
        $rtn           = [];

        if (!empty($this->spID)) {
            $nameFromCond  = ($nameFrom)
                ? " AND (s.nameFrom = :nameFrom OR s.nameFrom = 'name' OR s.nameFrom IS NULL OR s.nameFrom = '')"
                : "";
        } else {
            $nameFromCond  = ($nameFrom)
                ? " AND (s.nameFrom = :nameFrom OR s.nameFrom IS NULL OR s.nameFrom = '')"
                : "";
        }
        if (empty($this->tenantID) || empty($profileID) || empty($polymorphicID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
        ) {
            return $rtn;
        }

        // Get all mediaMon searches for our client/tenant, 3pProfile/person
        $sql = "SELECT DISTINCT s.id FROM {$DB}.mediaMonSrch AS s\n"
            . $idTypeJoin
            . "WHERE s.tenantID = :tenantID AND s.profileID = :profileID AND s.tpID = :polymorphicID "
            . "AND s.idType = :idType{$spCond}{$nameFromCond}\n"
            . "ORDER BY s.id DESC";
        $params = [
            ':tenantID' => $this->tenantID,
            ':profileID' => $profileID,
            ':polymorphicID' => $polymorphicID,
            ':idType' => $idType
        ];
        if (!empty($this->spID)) {
            $params[':spID'] = $this->spID;
        }
        if (!empty($nameFrom)) {
            $params[':nameFrom'] = $nameFrom;
        }

        if (!($srchIDs = $this->DB->fetchValueArray($sql, $params))) {
            return $rtn;
        }
        $rtn = $srchIDs;
        return $rtn;
    }

    /**
     * Get an array containing the rvw, uc, tm, fp and uc counts for Media Monitor hits
     * based on profileID and screeningID
     *
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param array   $subjects    gdcResult includes id, nameID, nameFromID, nameFrom and recType cols
     *
     * @return mixed
     */
    public function getMediaMonitorStatusObj($profileID, $screeningID, $subjects)
    {
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

        if (empty($this->spID)) {
            $rtn->sums['r6000']['uc'] = 0;
        }

        if (empty($this->tenantID) || empty($profileID) || empty($screeningID)) {
            return $rtn;
        }

        $totalReviews = 0;
        $mmCounts = $this->formatSubjectSums($profileID, $screeningID, $subjects);
        if (is_array($mmCounts) && count($mmCounts)) {
            foreach ($mmCounts as $record) {
                $totalReviews += $record['rvw'];
            }
        }
        if (empty($totalReviews)) {
            $rtn->message->yesNo = 'No';
        }
        $rtn->sums = $mmCounts;
        if (empty($this->spID)) {
            $rtn->sumsrvw = $mmCounts;
        }
        return $rtn;
    }

    /**
     * Calculates and formats the sums for each of the gdcResult(s) MM hits
     * Used as part of getMediaMonitorStatusObj
     *
     * @param integer $profileID   thirdPartyProfile.id
     * @param integer $screeningID gdcScreening.id
     * @param array   $subjects    gdcResult includes id, nameID, nameFromID, nameFrom and recType cols
     *
     * @return array
     */
    private function formatSubjectSums($profileID, $screeningID, $subjects)
    {
        $profileID    = (int)$profileID;
        $screeningID  = (int)$screeningID;
        $polymorphKey = (!empty($this->spID)) ? 'nameID' : 'nameFromID';
        $rtn          = [];

        $settings = new SettingACL($this->tenantID);
        $this->enableTrueMatch = ($setting = $settings->get(SettingACL::TRUE_MATCH_REMEDIATION))
            ? $setting['value'] : 0;

        if (empty($this->tenantID) || empty($profileID) || empty($screeningID)
            || !is_array($subjects) || empty($subjects)
        ) {
            return $rtn;
        }

        $struct = ['rvw' => 0, 'tm' => 0, 'fp' => 0];
        if (empty($this->spID)) {
            $struct['uc'] = 0;
        }

        foreach ($subjects as $subject) {
            $this->clearCache();
            $idType = ($subject['recType'] == 'Person') ? 'person' : 'profile';
            $gdcResultID = $subject['id'];
            $polymorphicID = $subject[$polymorphKey];
            $nameFrom = ($polymorphKey == 'nameFromID') ? $subject['nameFrom'] : null;

            $rtn['r'.$gdcResultID] = $struct;
            $rtn['r'.$gdcResultID]['rvw']= $this->getMediaMonitorReviewForSubject(
                $profileID,
                $polymorphicID,
                $idType,
                $nameFrom
            );
            $rtn['r'.$gdcResultID]['tm'] = $this->getMediaMonitorMatchForSubject(
                $profileID,
                $screeningID,
                $gdcResultID,
                $idType
            );
            $rtn['r'.$gdcResultID]['fp'] = $this->getMediaMonitorFalsePositiveForSubject(
                $profileID,
                $screeningID,
                $gdcResultID,
                $idType
            );
            if (empty($this->spID)) {
                $rtn['r'.$gdcResultID]['uc'] = $this->getMediaMonitorUnchangedForSubject(
                    $profileID,
                    $screeningID,
                    $gdcResultID
                );
            }
            if ($this->enableTrueMatch) {
                $rtn['r'.$gdcResultID]['remed'] = $this->getMediaMonitorRemediationCount(
                    $profileID,
                    $screeningID,
                    $gdcResultID,
                    $idType
                );
            }
        }
        return $rtn;
    }

    /**
     * Clear cached items
     */
    public function clearCache()
    {
        $this->gdcResultCache = null;
        $this->reviewLogIDs = null;
    }

    /**
     * Get naming column based on input string
     *
     * @param string $rrNameFrom String to analyze
     *
     * @return mixed Either false boolean or string
     */
    public function getMediaMonitorNamingCol($rrNameFrom)
    {
        $rtn = false;
        if (!empty($this->spID)) {
            $rtn = 'name';
        } elseif ($rrNameFrom === 'tpPerson' || $rrNameFrom === 'person') {
            $rtn = 'tpPerson';
        } else {
            if ($rrNameFrom === 'profile') {
                $rrNameFrom = 'legalName';
            }
            if ($rrNameFrom == 'legalName' || $rrNameFrom == 'DBAname') {
                $rtn = $rrNameFrom;
            }
        }
        return $rtn;
    }

    /**
     * Once a search is complete, fetch all of this tp/tpPerson's links from
     * TransparInt stored on our database.
     *
     * @param integer $polymorphicID ID of the a third party or tpPerson
     * @param string  $idType        Type for polymorphicID; e.g. person, legalName, DBAname
     * @param integer $profileID     ID of the third party profile
     * @param boolean $ordByReq      Optionally order by requestID (mediaMonRequests.id)
     * @param string  $nameFrom      Indicates use of the tpPersons table or a column in thirdPartyProfile table
     *
     * @return array
     */
    public function getCachedResults($polymorphicID, $idType, $profileID, $ordByReq = false, $nameFrom = null)
    {
        $DB = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($this->tenantID);
        $rtn = [];
        $joinData = $this->getMmJoin($polymorphicID, $idType);
        $searchAsFilter = $this->getNameFromFilter($idType, $nameFrom);
        $ordStr = ($ordByReq) ? 'requestID DESC,' : '';
        $ftr = new TenantFeatures($this->tenantID);
        $groupHashField = (!$ftr->tenantHasFeature(\Feature::TENANT_MEDIA_MONITOR_HIDE_REVISIONS, \Feature::APP_TPM))
            ? 'res.hash'
            : 'res.hashNoPub';

        $sql = "SELECT res.url AS url, reqs.term AS term, res.title AS title, res.relevance AS relevance,\n"
            . "hex(res.hash) AS 'hash', res.id AS source_recid, srch.tpID, srch.idType AS idType,\n"
            . "srch.profileID AS relatedProfID, params.paramValue AS refinement, reqs.id AS requestID,\n"
            . "params.global AS globalRefinement\n"
            . "{$joinData['select']}\n"
            . "FROM $DB.mediaMonResults AS res\n"
            . "INNER JOIN $DB.mediaMonSrch AS srch ON srch.idType = :idType AND srch.tpID = :polymorphicID "
            . "AND srch.profileID = :profileID AND res.searchID = srch.id\n"
            . "LEFT JOIN $DB.mediaMonRequests AS reqs ON reqs.id = srch.userRequestID\n"
            . "LEFT JOIN $DB.mediaMonReqParams AS params ON reqs.id = params.userRequestID\n"
            . "{$joinData['join']}\n"
            . "{$searchAsFilter['filter']}\n"
            . "AND res.deleted = 0\n"
            . "GROUP BY res.tenantID, res.tpProfileID, res.tpPersonID, res.tpPersonType, {$groupHashField}\n"
            . "ORDER BY {$ordStr} relevance DESC";
        $params = [':idType' => $idType, ':polymorphicID' => $polymorphicID, ':profileID' => $profileID];
        if (array_key_exists(':nameFrom', $searchAsFilter['params'])) {
            $params[':nameFrom'] = $searchAsFilter['params'][':nameFrom'];
        }
        $rtn = $this->DB->fetchAssocRows($sql, $params);
        return $rtn;
    }

    /**
     * Determines if a mediaMonResult requires adjudication based on the id and hash of the published+url+title
     *
     * @param integer $resultID mediaMonResults.id
     *
     * @return bool
     */
    public function mmRsltWasAdjudicated($resultID)
    {
        $rtn = false;
        $resultID = (int)$resultID;
        if (empty($resultID)) {
            return $rtn;
        }
        $DB      = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;
        $spWhere = (!empty($this->spID)) ? ' AND spID = :spID' : '';
        $sql = "SELECT COUNT(*) FROM {$DB}.mediaMonReviewLog WHERE source_recid = :id{$spWhere}";
        $params = [':id' => $resultID];
        if (!empty($this->spID)) {
            $params[':spID'] = $this->spID;
        }
        $count = $this->DB->fetchValue($sql, $params);
        $rtn = ($count > 0);
        return $rtn;
    }

    /**
     * Returns the previously adjudicated media monitor hits for the current entity or person
     *
     * @param integer $polymorphicID thirdparty or tpperson.id (mediaMonSrch.tpID)
     * @param integer $profileID     thirdPartyProfile.id
     * @param string  $idType        Either 'person' or 'profile'
     * @param string  $nameFrom      thirdparty col where name is drawn (mediaMonSrch.nameFrom)
     * @param integer $remediation   If 1, include remediations
     *
     * @return mixed
     */
    public function getMediaMonitorAdjudicated($polymorphicID, $profileID, $idType, $nameFrom = null, $remediation = 0)
    {
        $polymorphicID = (int)$polymorphicID;
        $profileID     = (int)$profileID;
        $rtn           = [];

        if (empty($this->tenantID) || empty($profileID) || empty($polymorphicID)
            || empty($idType) || !in_array($idType, ['person', 'profile'])
        ) {
            return $rtn;
        }

        $DB             = (!empty($this->spID)) ? $this->DB->spGlobalDB : $this->clientDB;
        $categoryCol    = (!empty($this->spID)) ? '' : ', l.categoryID';
        $gdcResultJoin  = (!empty($this->spID)) ? '' : "INNER JOIN {$DB}.gdcResult AS gr ON (l.tpID = gr.nameFromID)\n";
        $gdcResultWhere = (!empty($this->spID)) ? '' : " AND gr.nameFrom = :nameFrom";

        if (!($searchIDs = $this->getSearchIDs($profileID, $polymorphicID, $idType, $nameFrom)) || empty($searchIDs)) {
            return $rtn;
        }
        $searchIDs = implode(",", $searchIDs);

        $sql = "SELECT MAX(id), source_recid FROM {$DB}.mediaMonReviewLog\n"
            . "WHERE screeningID IN ({$searchIDs}) AND tpID = :polymorphicID\n"
            . "GROUP BY source_recid";
        if (!($reviewLogResult = $this->DB->fetchValueArray($sql, [':polymorphicID' => $polymorphicID]))) {
            return $rtn;
        }
        $reviewLogIDs = implode(",", $reviewLogResult);

        $remediationFields = '';
        if ($remediation) {
            $remediationFields = ', l.remediation, l.remediationReason';
        }

        // All adjudicated results for this tpPerson for this client
        $sql = "SELECT l.id, l.screeningID, l.tpID, l.source_recid, l.determination, l.reason, l.reasonID "
            . "{$remediationFields} {$categoryCol}\n"
            . "FROM {$DB}.mediaMonReviewLog AS l\n"
            . $gdcResultJoin
            . "WHERE l.clientID = :tenantID AND l.id IN ({$reviewLogIDs}){$gdcResultWhere}\n"
            . "ORDER BY l.id DESC";
        $params = [':tenantID' => $this->tenantID];
        if (empty($this->spID)) {
            $params[':nameFrom'] = $nameFrom;
        }
        if (!($records = $this->DB->fetchAssocRows($sql, $params))) {
            return $rtn;
        }
        foreach ($records as &$record) {
            if (isset($rtn[$record['source_recid']])) {
                continue;
            }
            $sql = "SELECT hex(r.hash) hash, r.hashNoPub, r.relevance, r.url, r.title, q.term, s.idType AS idType, "
                . "p.paramValue AS refinement, r.relevance, r.url, r.title FROM {$DB}.mediaMonResults AS r "
                . "INNER JOIN {$DB}.mediaMonSrch AS s ON r.searchID = s.id\n"
                . "INNER JOIN {$DB}.mediaMonRequests AS q ON (s.userRequestID = q.id)\n"
                . "LEFT JOIN {$DB}.mediaMonReqParams AS p ON (q.id = p.userRequestID)\n"
                . "WHERE r.id = :id\n"
                . "AND r.deleted = 0";
            $result = $this->DB->fetchAssocRow($sql, [':id' => $record['source_recid']]);
            // Only push into the array if the result is associated with the current profile,
            // Prevents mixing results of shared tpPersons on different profiles
            if ($result) {
                $rtn[$record['source_recid']] = array_merge($record, $result);
            }
        }
        // Sort array by numeric keys to meet desired order for front end
        ksort($rtn, SORT_NUMERIC);
        // Restore numeric keys for sub-arrays
        return array_values($rtn);
    }
    public function getNameFromFilterForUrlValidation($idType, $nameFrom)
    {
        return $this->getNameFromFilter($idType, $nameFrom);
    }
}
