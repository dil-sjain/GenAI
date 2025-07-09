<?php
/**
 * Base class for handling Media Monitor API calls
 *
 * A little background on the polymorphicID param used for various methods:
 * this was originally named tpID, and was intended to accept a thirdPartyProfile.id or a tpPerson.id value.
 * Given that tpID typically refers to "third party ID" (or more specifically thirdPartyProfile.id) in our app,
 * it was decided to go with a term that would describe the polymorphic dynamic instead of confusing it with a
 * term so widely used for a specific purpose.
 *
 * @keywords media monitor
 */
namespace Models\TPM\MediaMonitor;

use Models\Globals\Features\TenantFeatures;
use Models\SP\ServiceProvider;
use Lib\FeatureACL;

/**
 * Class MediaMonitorCliData
 *
 * @package Models\TPM
 */
class MediaMonitorCliData extends MediaMonitorSrchData
{
    /**
     * Reference to a DB class (on app)
     *
     * @var DB \Lib\Database\MySqlPdo
     */
    protected $DB;

    /**
     * mediaMonRequests table
     *
     * @param string
     */
    protected $reqsTbl = 'mediaMonRequests';

    /**
     * mediaMonReqParams table
     *
     * @param string
     */
    protected $reqPsTbl = 'mediaMonReqParams';

    /**
     * MediaMonitorCli constructor.
     *
     * Build instance
     *
     * @param \Lib\FeatureACL $ftr Reference to FeatureACL
     */
    public function __construct(FeatureACL $ftr = null)
    {
        $this->app = \Xtra::app();
        $this->ftr = $ftr ?? \Xtra::app()->ftr;
        $this->DB = \Xtra::app()->DB;
        parent::__construct();
    }

    /**
     * Update mediaMonRequests with TransparInt apiID.
     * If a g_mediaMonQueue.id is included, update its apiID value with the TransparInt apiID.
     * If not, then this is a refinement and a 'manual search' rec is created in g_mediaMonQueue.
     *
     * @param integer $mmRequestsID mediaMonRequests.id
     * @param integer $apiID mediaMonRequests.apiID
     * @param integer $tenantID mediaMonRequests.tenantID
     * @param integer $mmQueueID g_mediaMonQueue.id
     * @param integer $spID g_mediaMonQueue.spID
     *
     * @return void
     */
    public function saveTransparIntApiID($mmRequestsID, $apiID, $tenantID, $mmQueueID = 0, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $mmRequestsID = (int)$mmRequestsID;
        $apiID = (int)$apiID;
        $tenantID = (int)$tenantID;
        $mmQueueID = (int)$mmQueueID;
        $spID = (int)$spID;
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        if (!$DB || empty($mmRequestsID) || empty($apiID)) {
            $this->debugLog(__FILE__, __LINE__, 'Invalid params');
            return;
        }
        $this->DB->query(
            "UPDATE $DB.{$this->reqsTbl} SET apiID = :apiID WHERE id = :id",
            [':id' => $mmRequestsID, ':apiID' => $apiID]
        );
        if (!empty($mmQueueID)) {
            $this->setQueueItemApiID($mmQueueID, $apiID);
        } else {
            $this->addRefinementQueueItem($apiID, $tenantID, $spID);
        }
    }

    /**
     * Create a mediaMonRequests row
     *
     * @param string  $term               Primary search term for TransparInt search
     * @param integer $tenantID           Tenant ID
     * @param string  $startDate          Beginning date for publication range
     * @param string  $endDate            Ending date for publication range
     * @param array   $paramNamesWithVals Refinement param
     * @param integer $spID               g_mediaMonQueue.spID
     * @param integer $exempt             Exempt requests will not be included as part of the Tenant's utilized searches
     *                                    for the support tool. Exempt should only be 1 when the request is caused by
     *                                    the RepairTool CLI.
     * @param boolean $globalRefinement   Whether the request uses the Tenant's global refinement term
     *
     * @return integer
     */
    public function createRequest(
        $term,
        $tenantID,
        $startDate,
        $endDate,
        $paramNamesWithVals = null,
        $spID = 0,
        $exempt = 0,
        $globalRefinement = false
    ) {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        //@todo: probably badly needs validation of args
        $rtn = 0;
        $tenantID = (int)$tenantID;
        $spID = (int)$spID;
        if (empty($tenantID)) {
            $this->debugLog(__FILE__, __LINE__, "Non-zero client id is required");
            return $rtn;
        }
        $DB = (!empty($spID)) ? $this->DB->spGlobalDB : $this->DB->getClientDB($tenantID);
        $params = [':term' => $term, ':tenantID' => $tenantID, ':exempt' => $exempt];

        $googleResultsEnabled = (
            $this->ftr->tenantHas(\Feature::TENANT_MEDIA_MONITOR_GOOGLE_RESULTS) ||
            ServiceProvider::isSPTenant($tenantID)
        );

        if ((!is_null($startDate) || (is_null($startDate) && $googleResultsEnabled)) && !is_null($endDate)) {
            $this->debugLog(__FILE__, __LINE__);
            $datesClause = ', startDate, endDate';
            $datesVals = ', :startDate, :endDate';
            $params[':startDate'] = $startDate;
            $params[':endDate'] = $endDate;
            $this->debugLog(__FILE__, __LINE__);
        } elseif (!$this->validateDate($startDate) || !$this->validateDate($endDate)) {
            $this->debugLog(__FILE__, __LINE__, "Invalid date");
            return $rtn;
        }
        $spClause = $spVal = '';

        if (!empty($spID)) {
            $params[':spID'] = $spID;
            $spClause = ", spID";
            $spVal = ", :spID";
        }
        $this->debugLog(__FILE__, __LINE__);
        $sql = "INSERT INTO $DB.{$this->reqsTbl}\n"
            . "(term, tenantID, exempt, requestedAt {$datesClause}{$spClause})\n"
            . "VALUES (:term, :tenantID, :exempt, CONVERT_TZ(NOW(), @@session.time_zone, '+00:00'){$datesVals}{$spVal})";
        $mockQuery = $this->DB->mockFinishedSql($sql, $params);
        $this->debugLog(__FILE__, __LINE__, "Final query: " . $mockQuery);
        $rtn = false;
        try {
            $this->DB->query($sql, $params);
            $rtn = $this->DB->lastInsertId();
        } catch (\Exception $e) {
            $this->debugLog(__FILE__, __LINE__, "createRequest query failed; exception: {$e}; query: {$mockQuery}");
            return $rtn;
        }
        if ($paramNamesWithVals) {
            $this->createReqParams($rtn, $paramNamesWithVals, $DB, $globalRefinement);
        }
        return $rtn;
    }

    /**
     * Get the queue item by apiID and tenantID
     *
     * @param int $apiID Internal search ID
     * @param int $tenantID ID of associated tenant
     *
     * @return array|bool
     */
    protected function getQueueForApiID($apiID, $tenantID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $item = $this->getQueueItemByApiAndTenantID($apiID, $tenantID);
        return $item;
    }

    /**
     * Insert to mediaMonReqParams table
     *
     * @param integer $lastInsertID     Last inserted id from mediaMonRequests
     * @param array   $reqVals          Key-value array of arrays; keys in parent are
     *                                  names for the set of corresponding values
     * @param string  $DB               Either SP or client DB
     * @param boolean $globalRefinement Whether the request uses the Tenant's global refinement term
     *
     * @return void
     */
    protected function createReqParams($lastInsertID, $reqVals, $DB, $globalRefinement = false)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $sql = "INSERT INTO $DB.{$this->reqPsTbl}\n"
            . "(paramName, paramValue, userRequestID, `global`) VALUES ";
        $params = [];
        $cnt = 0;
        foreach ($reqVals as $name => $arr) {
            foreach ($arr as $idx => $val) {
                $pIdx = $cnt . "_" . $idx;
                $sql .= "(:name$pIdx, :val$pIdx, :uReqID$pIdx, :glbl$pIdx)";
                $params = array_merge(
                    $params,
                    [
                        ":name$pIdx"   => $name,
                        ":val$pIdx"    => $val,
                        ":uReqID$pIdx" => $lastInsertID,
                        ":glbl$pIdx"   => ($globalRefinement) ? 1 : 0
                    ]
                );
                if ($idx < count($arr) - 1) {
                    $sql .= ', ';
                }
            }
            $cnt++;
        }
        $this->DB->query($sql, $params);
    }

    /**
     * Validate a date to specified format
     *
     * @param string $date Date to validate
     *
     * @return boolean
     */
    protected function validateDate($date)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $format = 'Y-m-d';
        $d = \DateTime::createFromFormat($format, $date);
        $rtn = ($d && $d->format($format) == $date);
        $this->debugLog(__FILE__, __LINE__, "Date Valid: $rtn");
        return $rtn;
    }

    /**
     * Get case subjects for the SP App
     *
     * @param array $cfg Screening configuration
     * @param integer $spID Service Provider ID
     * @param integer $clientID Client ID
     * @param integer $profileID cases.tpID
     * @param integer $caseID cases.id
     * @param boolean $automated Whether or not the search was the result of an automated process (i.e. not via direct user interaction)
     *
     * @return mixed
     */
    public function getSpCaseSubjects($cfg, $spID, $clientID, $profileID, $caseID, $automated = false)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = false;
        $spID = (int)$spID;
        $clientID = (int)$clientID;
        $profileID = (int)$profileID;
        $caseID = (int)$caseID;
        if (empty($cfg) || empty($spID) || empty($clientID) || empty($profileID) || empty($caseID)) {
            $this->debugLog(__FILE__, __LINE__);
            return $rtn;
        }
        $clientDB = $this->DB->getClientDB($clientID);
        if ($profileID = $this->DB->fetchValue("SELECT tpID FROM $clientDB.cases WHERE id = :caseID", [':caseID' => $caseID])) {
            $this->debugLog(__FILE__, __LINE__);
            $sql = "SELECT * FROM {$this->DB->spGlobalDB}.spGdcResult AS r\n"
                . "INNER JOIN {$this->DB->globalDB}.g_gdcSearchName AS n ON(r.nameID = n.id)\n"
                . "WHERE r.caseID = :caseID AND r.clientID = :clientID AND r.spID = :spID";
            $params = [':caseID' => $caseID, ':clientID' => $clientID, ':spID' => $spID];
            if ($subjects = $this->DB->fetchAssocRows($sql, $params)) {
                $startDate = date('Y-m-d', strtotime('-3 months'));
                $endDate = date('Y-m-d');
                $items = [];
                // Reverse the array so that the most recent results appear first
                $subjects = array_reverse($subjects);
                $items = [];
                foreach ($subjects as $sub) {
                    // Prevent older results from being added
                    if (isset($items[$sub['nameID']])) {
                        continue;
                    }
                    // If the subject is not included in the screening config, skip it
                    $subject = [
                        'recType' => $sub['recType'],
                        'nameBasis' => $sub['nameBasis'],
                        'nameFrom' => $sub['nameFrom'],
                        'name' => $sub['name']
                    ];
                    if (!$this->screeningCfgHasSubject($cfg, $subject)) {
                        continue;
                    }
                    // Add subject to items array
                    $items[$sub['nameID']] = [
                        'spID' => $spID,
                        'tenantID' => $sub['clientID'],
                        'tpProfileID' => $profileID,
                        'searchTerm' => $sub['name'],
                        'recordType' => (($sub['recType'] == 'Person') ? 'person' : 'profile'),
                        'personID' => $sub['id'],
                        'interactive' => ($automated === false), // whether or not search was triggered by a user interaction
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                        'caseID' => $caseID
                    ];
                }
                if (!empty($items)) {
                    $rtn = $items;
                }
            }
        }
        $this->debugLog(__FILE__, __LINE__, $rtn);
        return $rtn;
    }

    /**
     * Determines if an spGdcResult (subject) is included in the current configuration
     *
     * @param array $cfg Screening config
     * @param array $subject Subject details
     *
     * @return boolean
     */
    private function screeningCfgHasSubject($cfg, $subject)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        if (is_array($cfg) && !empty($cfg) && is_array($subject) && !empty($subject)) {
            foreach ($cfg as $c) {
                $details = explode('|', (string) $c);
                $current['recType'] = ($details[0] == 'P') ? 'Person' : 'Entity';
                $current['nameBasis'] = $details[1];
                $current['nameFrom'] = $details[2];
                $current['name'] = (isset($details[4])) ? $details[3] . ' ' . $details[4] : $details[3];
                if (!array_diff_assoc($current, $subject)) {
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * Determines if the current search is eligible for Google Results based on whether this term has been run against
     * the current profile or case folder previously
     *
     * @param string $DB Tenant or SP DB
     * @param integer $tenantID g_tenants.id
     * @param integer $profileID thirdPartyProfile.id
     * @param string $term Term that will be searched
     * @param string $idType Type for polymorphicID (person or profile)
     * @param integer $caseID cases.id only applicable to SP side
     *
     * @return bool
     */
    public function googleResultsEligible($DB, $tenantID, $profileID, $term, $idType, $caseID = null)
    {
        $googleResults = (new TenantFeatures($tenantID))->tenantHasFeature(
            \Feature::TENANT_MEDIA_MONITOR_GOOGLE_RESULTS,
            \Feature::APP_TPM
        );
        $isSP = ServiceProvider::isSPTenant($tenantID);

        if ($googleResults || $isSP) {
            $DB = ($isSP) ? $this->app->DB->spGlobalDB : $DB;
            $SPClause = ($isSP) ? ", AND s.caseID = :caseID" : "";

            $sql = "SELECT IF(COUNT(*) > 0, 0, 1) FROM {$DB}.mediaMonSrch s "
                . "INNER JOIN {$DB}.mediaMonRequests r ON (s.userRequestID = r.id) "
                . "WHERE s.tenantID = :tenantID AND r.term = :term AND s.profileID = :profileID "
                . "AND s.idType = :idType AND (r.startDate IS NULL OR r.startDate = '0000-00-00 00:00:00') {$SPClause}";

            $params = [
                ':tenantID' => $tenantID,
                ':term' => $term,
                ':profileID' => $profileID,
                ':idType' => ($idType == 'Entity') ? 'profile' : $idType
            ];

            $params = ($isSP) ? array_merge($params, [':caseID' => $caseID]) : $params;

            return $this->DB->fetchValue($sql, $params);
        }

        return false;
    }

    /**
     * Adjust the details for items/subjects to allow Google results to be retrieved
     *
     * @param integer $tenantID  g_tenants.id
     * @param integer $profileID thirdPartyProfile.id
     * @param array   $items     items/subjects to be modified
     * @param boolean $process   Start searching against the persons/entities immediately
     * @param boolean $forceGR   Force google results to be returned by removing the startDate
     *
     * @return mixed
     */
    public function adjustItemsForGoogleResults($tenantID, $profileID, $items, $process, $forceGR)
    {
        if (is_array($items) && count($items) > 0) {
            $clientDB = $this->app->DB->getClientDB($tenantID);

            foreach ($items as &$item) {
                $eligible = $this->googleResultsEligible(
                    $clientDB,
                    $tenantID,
                    $profileID,
                    $item['term'] ?? $item['searchTerm'],
                    $item['recordType']
                );
                // Remove the start date to receive Google Results
                $item['startDate'] = ($eligible || $forceGR) ? null : $item['startDate'];
                $item['interactive'] = $process ?: $item['interactive'];
            }
        }

        return $items;
    }

    /**
     * Adjust the details for items/subjects to include the Tenant's global refinement term
     *
     * @param array   $items    items/subjects to be modified
     * @param integer $tenantID g_tenants.id
     *
     * @return mixed
     */
    public function adjustItemsForGlobalRefinement($items, $tenantID = null)
    {
        if (is_array($items) && count($items) > 0) {
            if ($refinement = $this->getGlobalRefinementTerm($tenantID)) {
                foreach ($items as &$item) {
                    $item['refinements'] = $refinement;
                    $item['globalRefinement'] = 1;
                }
            }
        }
        return $items;
    }
}
