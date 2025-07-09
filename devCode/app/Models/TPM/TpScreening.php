<?php
/**
 * TpScreening model
 **/

namespace Models\TPM;

use Models\Logging\LogRunTimeDetails;

/**
 * TpScreening model for updating/recalculating various TPM counts, such as gdc/mm hit counts, determination counts,
 * remediation counts, etc.
 */
#[\AllowDynamicProperties]
class TpScreening
{
    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var array List of adjudication (determination) reasons
     */
    private $adjReasons = null;

    /**
     * @var string Client database name
     */
    private $clientDB = '';

    /**
     * @var integer Client ID
     */
    private $clientID = 0;

    /**
     * Stores LogRunTimeDetails class
     *
     * @var null
     */
    private $scrnLog = null;

    /**
     * Constructor
     *
     * @param integer $clientID  clientProfile.id
     * @param integer $preflight Flag to indicate if DB updates are to be performed
     *                                 0 = perform DB updates
     *                                 1 = preflight mode - no DB update operations
     *
     * @return void
     */
    public function __construct($clientID, private $preflight = 1)
    {
        \Xtra::requireInt($clientID, 'clientID must be an integer value');

        $this->clientID  = $clientID;
        $this->app       = \Xtra::app();
        $this->DB        = $this->app->DB;
        $this->clientDB  = $this->DB->getClientDB($clientID);
    }

    /**
     * Calculate all Third Party Profile GDC related hits (ie; GDC hits, falsePositive, trueMatch, needReview, etc.)
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return array Return various hit counts
     */
    public function calcTpGdcCounts($tpID)
    {
        // method based on cms/includes/php/class_gdc.php profileStatus()
        $screening = $this->getTpGdcCurrentScreeningID($tpID);
        $subjects = $this->getTpScreeningSubjects($screening['gdcScreeningID']);

        if (empty($this->adjReasons)) {
            $this->adjReasons = $this->getAdjudicationReasons('Both', true);
        }

        // iterate overall all subjects (persons/entities) in the tpProfile associated with the screening
        $rtn = $this->initCounts();
        foreach ($subjects as $subject) {
            if (!isset($subject['details'])) {
                continue;
            }

            if ($subject['nameError']) {
                $rtn['status']['error']++;
            }

            if (!$subject['hits']) {
                continue;
            }

            $details = unserialize($subject['details']);

            // iterate over search list type(s) (pep, watch, etc.) that contain hits
            foreach ($details['ref'] as $searchListName => $searchList) {
                $searchType = $this->getTpSearchType($searchListName);
                // iterate over the hits by list type (pep, watch, etc.)
                foreach ($searchList as $hitRef) {
                    if ($searchList) { // update hit counts for the list type
                        $rtn[$searchType]['hits'][$searchListName]++;
                    }

                    [$source_recid, $source_ID] = explode(':', (string) $hitRef);
                    $determination = $this->getRecentDetermination(
                        $searchListName,
                        $tpID,
                        $screening['gdcScreeningID'],
                        $subject,
                        $source_ID
                    );

                    $currDeterm = 'undetermined';

                    if ($determination) { // must be trueMatch, falsePositive
                        $currDeterm = $determination['determination'];

                        if ($source_recid == $determination['source_recid']) {
                            if ($currDeterm == 'match') {
                                $rtn[$searchType]['status']['unchanged']++;

                                // if we have a 'match' we need to see if the adjudication reason 'needsRemediation' is
                                // set, then check if the gdcReviewLog.remediation is < 1, if so remediation is needed
                                if ($currDeterm == 'match') {
                                    $this->updateReviewAndRemediation($searchType, $determination, $rtn);
                                }
                            }
                        } else {
                            if ($currDeterm == 'match') {
                                $rtn[$searchType]['status']['changed']++;
                            }
                        }
                    } else { // undetermined
                        $rtn[$searchType]['status']['new']++;
                        $rtn[$searchType]['needsReview'] = 1;
                    }

                    // update the determination count
                    $rtn[$searchType]['determination'][$currDeterm]++;
                }
            }
        }

        return $rtn;
    }

    /**
     * Get the adjudication record (if any) associated with a mm/gdc hit
     *
     * @param string  $table       Client DB table name to search against (gdcReviewLog or mmReviewLog)
     * @param integer $screeningID The screening ID associated with a mm/gdc Hit
     * @param integer $hitID       Either gdcResults.id or mmResults.id
     *
     * @return mixed Return the adjudication row for the hit or false if not found
     */
    public function getAdjudicationRec($table, $screeningID, $hitID)
    {
        $this->scrnLog("in " . __METHOD__ . ", args: "
            . print_r(func_get_args(), true), false, $this->clientID);

        // see if we have an adjudication record for the hit
        $sql = "SELECT id, screeningID, source_recid, determination, reason FROM $table"
            . " WHERE clientID = :clientID"
            . " AND screeningID = :screeningID"
            . " AND source_recid = :hitID";
        $params = [
            ':clientID'    => $this->clientID,
            ':screeningID' => $screeningID,
            ':hitID'       => $hitID
        ];

        $adjRec = $this->DB->fetchAssocRow($sql, $params);
        $this->scrnLog("return from " . __METHOD__);

        return $adjRec;
    }

    /**
     * Gets the adjudication reasons associated with the feature provided - to get all adjudication reasons for a global
     * non-feature specific implementation pass in 'Both' for the $type arg
     *
     * @param string  $type                 'Both'|'Entity'|'Individual'
     * @param boolean $onlyNeedsRemediation If true only return reasons that require remediation, otherwise return
     *                                      all reasons
     *
     * @return object Return list of adjudication reasons
     */
    public function getAdjudicationReasons($type, $onlyNeedsRemediation = false)
    {
        $adjudicationTypes   = ['match', 'falsePositive'];
        $adjudicationReasons = [];
        $hideReasons         = [];
        $noReasonTxt         = 'No reasons available';

        // Get list of reasons to ignore for this client
        $sql = "SELECT reasonID FROM {$this->DB->globalDB}.g_gdcAdjudicationReasonsHideTenantMap"
            . " WHERE tenantID = :tenantID";
        $rslt = $this->DB->fetchAssocRows($sql, [':tenantID' => $this->clientID]);

        if (is_array($rslt) && count($rslt) > 0) {
            foreach ($rslt as $r) {
                $hideReasons[] = array_shift($r);
            }
        }

        $entityClause = '';
        if ($type != 'Both') {
            $isEntity = ($type == 'Entity') ? 1 : 0;
            $entityClause = "AND r.entity = $isEntity";
        }

        $needsRemediationClause = '';
        if ($onlyNeedsRemediation) {
            $needsRemediationClause = "AND r.needsRemediation = 1";
        }

        $cols = "r.id, r.listText, r.noteText, r.association,"
            . " GROUP_CONCAT(m.featureID SEPARATOR ',') AS features, r.needsRemediation";
        $reasonSql = "SELECT $cols FROM {$this->DB->globalDB}.g_gdcAdjudicationReasons AS r "
            . " LEFT JOIN {$this->DB->globalDB}.g_gdcAdjudicationReasonsFeatureMap AS m ON r.id = m.reasonID "
            . " WHERE (r.tenantID = 0 OR r.tenantID = :tenantID) $entityClause $needsRemediationClause"
            . " AND r.active = 1 "
            . " GROUP BY r.id";

        $defaultReasons = $this->DB->fetchObjectRows($reasonSql, [':tenantID' => $this->clientID]);

        if (is_array($defaultReasons) && count($defaultReasons) > 0) {
            foreach ($defaultReasons as $reason) {
                if (!in_array($reason->id, $hideReasons)) {
                    $reason->noteText = htmlspecialchars_decode((string) $reason->noteText, ENT_QUOTES);
                    $reason->listText = htmlspecialchars_decode((string) $reason->listText, ENT_QUOTES);
                    if ($reason->association == 1) {
                        $adjudicationReasons['match'][$reason->id] = $reason;
                    } else {
                        $adjudicationReasons['falsePositive'][$reason->id] = $reason;
                    }
                }
            }
        }

        if (!$onlyNeedsRemediation) {
            // Handle empty reasons
            foreach ($adjudicationTypes as $adjType) {
                if (empty($adjudicationReasons[$adjType])) {
                    $adjudicationReasons[$adjType][] = (object)[
                        'listText' => $noReasonTxt,
                        'noteText' => $noReasonTxt,
                        'association' => 1,
                        'needsRemediation' => false
                    ];
                    $adjudicationReasons[$adjType][] = (object)[
                        'listText' => $noReasonTxt,
                        'noteText' => $noReasonTxt,
                        'association' => 0,
                        'needsRemediation' => false
                    ];
                }
            }
        }

        return $adjudicationReasons;
    }

    /**
     * This method serves a couple of purposes:
     *     * It works with either GDC or MediaMon search results
     *     * It can return an array of hits based upon a person/entity
     *     * Alternately it can return the number of hits that exist for a person/entity or for a profile
     *
     * Code based foreign key relationships are as follows:
     *
     *    gdcScreening.tpID     -> thirdPartyProfile.id
     *    gdcResult.screeningID -> gdcScreening.id
     *    gdcResult.nameFromID  -> tpPerson.id
     *    gdcResult.nameID      -> g_gdcSearchName.id
     *
     *    mediaMonReviewLog.screeningID       -> mediaMonSrch.id
     *    mediaMonResults.searchID            -> mediaMonSrch.id
     *    mediaMonSrch.userRequestID          -> mediaMonRequests.id
     *    mediaMonRequestParams.userRequestID -> mediaMonRequests.id
     *
     *    tpPersonMap.personID -> tpPerson.id
     *    tpPersonMap.tpID     -> thirdPartyProfile.id
     *
     * @param integer $clientID      Client ID
     * @param integer $polymorphicID Contains either thirdPartyProfile.id or tpPerson.id
     * @param string  $searchType    Can be either 'gdc' or 'mm' to indicate type of search
     * @param string  $searchName    Name of person/entity searched
     * @param boolean $termOnly      If true search only mediaMonRequest.term, else search includes
     *                               mediaMonResults.title and mediaMonResults.snippet
     * @param boolean $countsOnly    Return only a count of the hits present for a third party profile
     *
     * @return mixed Return an array of search results (hits) or a count of search results
     */
    public function getHits(
        $clientID,
        $polymorphicID, // depending upon searchType can be tpProfileID or tpPersonID
        $searchType,
        $searchName,
        $termOnly = true,
        $countsOnly = false
    ) {
        $this->scrnLog("in " . __METHOD__ . ", args: " . print_r(func_get_args(), true), false, $clientID);

        $msg = "$searchType hits found: ";
        $DB = $this->clientDB;

        if ($searchType == 'mm') {
            if ($countsOnly) {
                $sql = "SELECT COUNT(*) FROM $DB.mediaMonResults AS rslt"
                    . " WHERE rslt.tenantID = :clientID"
                    . " AND  rslt.deleted = 0"
                    . " AND tpProfileID = :profileID";
                $params = [
                    ':clientID' => $clientID,
                    ':profileID' => $polymorphicID
                ];
            } else {
                $whereCondition = "";
                $params = [
                    ':clientID' => $clientID,
                    ':term' => '%' . $searchName . '%'
                ];

                if (!$termOnly) {
                    $whereCondition = " OR title LIKE :name1 OR snippet LIKE :name2";
                    $params += [
                        ':name1' => '%' . $searchName . '%',
                        ':name2' => '%' . $searchName . '%'
                    ];
                }

                $sql = "SELECT rslt.id, searchID, tpProfileID, tpPersonID, term FROM $DB.mediaMonResults AS rslt"
                    . " LEFT JOIN $DB.mediaMonSrch AS srch ON srch.id = rslt.searchID"
                    . " LEFT JOIN $DB.mediaMonRequests AS req ON req.id = srch.`userRequestID`"
                    . " WHERE rslt.tenantID = :clientID"
                    . " AND  rslt.deleted = 0"
                    . " AND (term LIKE :term{$whereCondition})"
                    . " ORDER BY searchID";
            }
        } else { // gdcResults (hits)
            if ($countsOnly) {
                $sql = "SELECT COUNT(*) FROM $DB.gdcResult AS rslt"
                    . " LEFT JOIN $DB.gdcScreening AS scrn ON scrn.id = rslt.screeningID"
                    . " WHERE rslt.clientID = :clientID"
                    . " AND tpID = :profileID";
                $params = [
                    ':clientID' => $clientID,
                    ':profileID' => $polymorphicID
                ];
            } else {
                $sql = "SELECT id, screeningID FROM $DB.gdcResult"
                    . " WHERE clientID = :clientID"
                    . " AND nameFromID = :nameFromID";
                $params = [
                    ':clientID' => $clientID,
                    ':nameFromID' => $polymorphicID
                ];
            }
        }

        if ($countsOnly) {
            $hits = $this->DB->fetchValue($sql, $params);
            $msg = ($hits) ? $msg . $hits : "$msg None";
        } else {
            $hits = $this->DB->fetchAssocRows($sql, $params);
            $msg = ($hits) ? $msg . count($hits) : "$msg None";
        }

        $this->scrnLog("return from " . __METHOD__ . ", $msg");
        return $hits;
    }

    /**
     * Get the most recent determination to figure out if changes to hit counts have changed or not
     *
     * @param string  $searchType  Indicates what type of search ('gdc' or 'mm' to fetch the determination record
     * @param integer $tpID        thirdPartyProfile.id
     * @param integer $screeningID thirdPartyProfile.screeningID
     * @param string  $subject     Name of person/entity
     * @param integer $source_ID   gdcReviewLog.source_ID or mediaMonReviewLog.source_ID
     *
     * @return mixed
     */
    private function getRecentDetermination($searchType, $tpID, $screeningID, $subject = '', $source_ID = 0)
    {
        if ($this->getTpSearchType($searchType) == 'mm') {
            // get the most recent determination
            $sql = "SELECT * FROM $this->clientDB.mediaMonReviewLog"
                . " WHERE tpID = :tpID AND clientID = :clientID "
                . " AND screeningID = :scrID"
                . " AND source_recid = :sourceID"
                . " ORDER BY id DESC LIMIT 1";
            $params = [
                ':tpID' => $tpID,
                ':clientID' => $this->clientID,
                ':scrID' => $screeningID
            ];
        } else { // assume 'gdc' or 'icij'
            // get most recent GDC review record (determination)
            $sql = "SELECT * FROM $this->clientDB.gdcReviewLog"
                . " WHERE tpID = :tpID AND clientID = :clientID "
                . " AND screeningID <= :scrID"
                . " AND source_ID = :sourceID"
                . " AND nameFromID = :nameFromID"
                . " AND nameFrom = :nameFrom"
                . " ORDER BY id DESC LIMIT 1";
            $params = [
                ':tpID' => $tpID,
                ':clientID' => $this->clientID,
                ':scrID' => $screeningID,
                ':sourceID' => $source_ID,
                ':nameFrom' => $subject['nameFrom'],
                ':nameFromID' => $subject['nameFromID'],
            ];
        }

        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Get the GDC thirdPartyProfile.gdcScreeningID and associated gdcReview
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return mixed Return the thirdPartyProfile record or false if not found
     */
    private function getTpGdcCurrentScreeningID($tpID)
    {
        $sql = "SELECT gdcScreeningID, gdcReview FROM $this->clientDB.thirdPartyProfile "
            . " WHERE clientID = :clientID AND id = :tpID LIMIT 1";
        $params = [':clientID' => $this->clientID, ':tpID' => $tpID];
        if (!($tpRec = $this->DB->fetchAssocRow($sql, $params))) {
            return false;
        }
        return $tpRec;
    }

    /**
     * Get the person/entity info for the GDC result
     *
     * @param integer $screeningID thirdPartyProfile.screeningID
     *
     * @return mixed Return the thirdPartyProfile record or false if not found
     */
    private function getTpScreeningSubjects($screeningID)
    {
        $rtn = [];

        $sql = "SELECT * FROM $this->clientDB.gdcResult"
            . " WHERE clientID = :clientID AND screeningID = :screeningID"
            . " ORDER BY id ASC";
        $params = [':clientID' => $this->clientID, ':screeningID' => $screeningID];
        if (($subjects = $this->DB->fetchAssocRows($sql, $params))) {
            $rtn = $subjects;
        }
        return $rtn;
    }

    /**
     * Based up the search list name passed in determine if it's a GDC, ICIJ or MM search type
     *
     * @param string $searchListName Contains type of search to perform (ie; pep, watch, gdc, icij, mm)
     *
     * @return string
     */
    private function getTpSearchType($searchListName)
    {
        $srchType = match ($searchListName) {
            'icij', 'mm' => $searchListName,
            default => 'gdc',
        };

        return $srchType;
    }

    /**
     * Establish and initialize various array constructs used to keep track of various counts/flags when
     * processing/updating counts after deleting records
     *
     * @return array Array of counts/flags
     */
    private function initCounts()
    {
        $rtn = [
            'lists' => [
                'pep' => 0,
                'watch' => 0,
                'sanction' => 0,
                'mex' => 0,
                'soe' => 0,
                'rights' => 0,
                'col' => 0,
                'icij' => 0
            ],
            'determination' => [
                'falsePositive' => 0,
                'undetermined' => 0,
                'match' => 0
            ],
            'status' => [
                'error' => 0
            ],
            'gdc' => [
                'needsReview' => 0,
                'hits' => [
                    'pep' => 0,
                    'watch' => 0,
                    'sanction' => 0,
                    'mex' => 0,
                    'soe' => 0,
                    'rights' => 0,
                    'col' => 0,
                    'remediation' => 0
                ],
                'determination' => [
                    'falsePositive' => 0,
                    'match' => 0,
                    'undetermined' => 0
                ],
                'status' => [
                    'new' => 0,
                    'changed' => 0,
                    'unchanged' => 0,
                    'error' => 0,
                ]
            ],
            'icij' => [
                'needsReview' => 0,
                'hits' => [
                    'icij' => 0,
                    'remediation' => 0
                ],
                'determination' => [
                    'falsePositive' => 0,
                    'match' => 0,
                    'undetermined' => 0
                ],
                'status' => [
                    'new' => 0,
                    'changed' => 0,
                    'unchanged' => 0,
                    'error' => 0,
                ]
            ],
            'mm' => [
                'needsReview' => 0,
                'hits' => [
                    'mm' => 0,
                    'remediation' => 0
                ],
                'determination' => [
                    'falsePositive' => 0,
                    'match' => 0,
                    'undetermined' => 0
                ],
                'status' => [
                    'new' => 0,
                    'changed' => 0,
                    'unchanged' => 0,
                    'error' => 0,
                ]
            ]
        ];
        return $rtn;
    }

    /**
     * Log to database. For debugging DDQ issues.
     * logAbbrev = ddq in cms_global.g_logConfig table
     *
     * @param string  $logMsg   Message to log
     * @param bool    $verbose  Verbose flag
     * @param integer $clientID Client ID
     *
     * @return void
     */
    private function scrnLog($logMsg, $verbose = false, $clientID = 0)
    {
        if (!$this->scrnLog && empty($clientID)) {
            return;
        }

        if (!$this->scrnLog) {
            $this->scrnLog = new LogRunTimeDetails($clientID, 'tpScreening');
        }

        $msgLvl = $verbose ? LogRunTimeDetails::LOG_VERBOSE : LogRunTimeDetails::LOG_BASIC;
        $this->scrnLog->logDetails($msgLvl, $logMsg);
    }

    /**
     * Based upon a 'match' determination figure out if the 'needsReview' flag should be set, or if the 'Remediation'
     * count should be incremented.
     *
     * @param string $searchType    Can be either 'gdc' or 'mm'
     * @param array  $determination Most recent GDC or MM review log record (determination)
     * @param array  &$rtn          Pointer to array of counts to update
     *
     * @return void
     */
    public function updateReviewAndRemediation($searchType, $determination, &$rtn)
    {
        $reasonID = $determination['reasonID'];
        if (array_key_exists($reasonID, $this->adjReasons['match'])
            && ($determination['remediation'] < 1)
        ) {
            $rtn[$searchType]['hits']['remediation']++;
        } else {
            $rtn[$searchType]['needsReview'] = 1;
        }
    }
}
