<?php
/**
 * Model: Handle Media Monitor Queue/Stack data
 *
 * @keywords media monitor, queue, data
 */

namespace Models\TPM\MediaMonitor;

use Lib\IO;
use Lib\Services\AppMailer;
use Controllers\ADMIN\Logs\CronLogger;

/**
 * Class MediaMonitorQueueData
 *
 * @package Models\TPM
 */
#[\AllowDynamicProperties]
class MediaMonitorQueueData extends MediaMonitorFilterData
{
    public const FINISHED_THRESHOLD    = 20;        // Maximum amount of seconds the code will check one-off searches for completion
    public const CLEANUP_THRESHOLD     = '1 DAY';   // Anything older than this time interval is sufficient to greenlight queue cleanup
    public const STALLED_THRESHOLD     = '12 HOUR'; // Anything older than this time interval is sufficient to be considered stalled
    public const REQUEUED_THRESHOLD    = 2;         // Max number of times a stopped item can be requeued before it is purged.
    public const READIED_QUEUE_LIMIT   = 1000;      // Max number of ready items to be queued up at a time (these crank not so fast).
    public const STALLED_REQUEUE_LIMIT = 10000;     // Max number of stalled items to be requeued at a time (these crank fast).

    /**
     * g_mediaMonQueue table
     *
     * @param string
     */
    protected $queueTbl = 'g_mediaMonQueue';

    /**
     * g_mediaMonQueueStatus table
     *
     * @param string
     */
    protected $queueStatusTbl = 'g_mediaMonQueueStatus';

    /**
     * g_mediaMonQueueStalledLog table
     *
     * @param string
     */
    protected $queueStalledLogTbl = 'g_mediaMonQueueStalledLog';

    /**
     * Initialize data for model
     */
    public function __construct()
    {
        parent::__construct();
        $this->queueTbl           = $this->DB->globalDB . ".g_mediaMonQueue";
        $this->queueStatusTbl     = $this->DB->globalDB . ".g_mediaMonQueueStatus";
        $this->queueStalledLogTbl = $this->DB->globalDB . ".g_mediaMonQueueStalledLog";
    }

    /**
     * Add entries to Media Monitor queue
     *
     * @param array $items Contains items to add to the queue
     *
     * @return void
     */
    public function addItemsToQueue($items)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        foreach ($items as $item) {
            $this->addQueueItem($item);
        }
    }

    /**
     * This is the result of lacking a g_mediaMonQueue record at the time mediaMonRequests.apiID
     * is updated with a TransparInt apiID value, and will be the circumstances of a refinement search.
     * This adds a 'manual search' g_mediaMonQueue rec with the TransparInt apiID to refer back to
     * for the TransparInt POST request.
     *
     * @param integer $apiID    mediaMonRequests.apiID
     * @param integer $tenantID mediaMonRequests.tenantID
     * @param integer $spID     g_mediaMonQueue.spID
     *
     * @return mixed either integer else false boolean
     */
    public function addRefinementQueueItem($apiID, $tenantID, $spID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $apiID = (int)$apiID;
        $tenantID = (int)$tenantID;
        $spID = (int)$spID;
        if (empty($apiID) || empty($tenantID)) {
            $this->debugLog(__FILE__, __LINE__, 'Invalid params:' . print_r(func_get_args(), 1));
            return false;
        }
        $item = [
            'tenantID'    => $tenantID,
            'apiID'       => $apiID,
            'searchTerm'  => 'manual search',
            'status'      => 'started',
            'interactive' => true // search was triggered by a user interaction
        ];
        if (!empty($spID)) {
            $item['spID'] = $spID;
        }
        $mmQueueID = $this->addQueueItem($item);
        return $mmQueueID;
    }

    /**
     * Add entry to Media Monitor queue
     *
     * @param array $item Item to add to the queue
     *
     * @return mixed either integer else false boolean
     */
    public function addQueueItem($item)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = false;
        try {
            if (empty($item['tenantID'])) {
                $this->debugLog(__FILE__, __LINE__, 'Insufficient parameters supplied');
                return $rtn;
            }
            $request = [];
            if (isset($item['id'])) {
                $request['tpPersonID'] = $item['id'];
            } elseif (isset($item['personID'])) {
                $request['tpPersonID'] = $item['personID'];
            }
            if (isset($item['profileID'])) {
                $request['tpProfileID'] = $item['profileID'];
            } elseif (isset($item['tpProfileID'])) {
                $request['tpProfileID'] = $item['tpProfileID'];
            }
            if (isset($item['term'])) {
                $request['searchTerm'] = $item['term'];
            } elseif (isset($item['searchTerm'])) {
                $request['searchTerm'] = $item['searchTerm'];
            } else {
                $this->debugLog(__FILE__, __LINE__, 'A search term is required');
                return $rtn;
            }
            if (isset($item['refinements'])) {
                $request['searchRefinements'] = $item['refinements'];
            }
            if (isset($item['recordType'])) {
                $request['recordType'] = $item['recordType'];
            }
            if (isset($item['searchTermOrigin'])) {
                $request['searchTermOrigin'] = $item['searchTermOrigin'];
            }
            // Exempt requests will not be included as part of the Tenant's utilized searches for the support tool.
            if (isset($item['exempt'])) {
                $request['exempt'] = $item['exempt'];
            }
            // CaseID exclusive to SP side of application is used for google results logic
            if (isset($item['caseID'])) {
                $request['caseID'] = $item['caseID'];
            }
            // Global Refinement indicates if the refinement term is derived from the global refinement feature.
            if (isset($item['globalRefinement'])) {
                $request['globalRefinement'] = $item['globalRefinement'];
            }
            $request['interactive'] = (isset($item['interactive']) && !empty($item['interactive']));

            $params = [
                ':tenantID'  => $item['tenantID'],
                ':request'   => ((!empty($request)) ? json_encode($request) : null),
                ':startDate' => ((!empty($item['startDate'])) ? $item['startDate'] : null),
                ':endDate'   => ((!empty($item['endDate'])) ? $item['endDate'] : null),
                ':created'   => date('Y-m-d H:i:s'),
                ':status'    => ((!empty($item['status'])) ? $item['status'] : 'ready'),
                ':apiID'     => ((!empty($item['apiID'])) ? $item['apiID'] : null),
                ':exempt'    => ((!empty($item['exempt'])) ? $item['exempt'] : 0),
                ':priority'  => ((isset($item['interactive']) && !empty($item['interactive'])) ? 1 : 0)
            ];

            $spFld = $spParam = '';
            if (!empty($item['spID'])) {
                $spFld = " spID,";
                $spParam = " :spID,";
                $params[':spID'] = $item['spID'];
            }
            $searchFilterFld = $searchFilterParam = '';
            if (!empty($item['searchFilterID'])) {
                $searchFilterFld = ", searchFilterID";
                $searchFilterParam = ", :searchFilterID";
                $params[':searchFilterID'] = $item['searchFilterID'];
            }
            $flds = "apiID, tenantID,{$spFld} request, startDate, endDate, priority, `status`, created, exempt"
                . $searchFilterFld;
            $values = ":apiID, :tenantID,{$spParam} :request, :startDate, :endDate, :priority, :status, "
                . ":created, :exempt{$searchFilterParam}";
            $sql = "INSERT INTO {$this->queueTbl} ({$flds}) VALUES ({$values})";
            $this->debugLog(__FILE__, __LINE__, 'Query: ' . $this->DB->mockFinishedSQL($sql, $params));
            $this->DB->query($sql, $params);
            $rtn = $this->DB->lastInsertId();
            return $rtn;
        } catch (\OutOfRangeException $e) {
            $this->debugLog(__FILE__, __LINE__, 'Error: ' . $e->getMessage());
            return $rtn;
        }
    }

    /**
     * Update apiID for queue entry
     *
     * @param integer $id    Queue id
     * @param integer $apiID Internal ID to track requested search
     *
     * @return void
     */
    public function setQueueItemApiID($id, $apiID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $this->DB->query(
            "UPDATE {$this->queueTbl} SET apiID = :apiID WHERE id = :id",
            [':id' => $id, ':apiID' => $apiID]
        );
    }

    /**
     * Set queued item status. The statuses are as follows:
     *
     * 'ready':    The initial status a queue item gets when added, indicating readiness for processing.
     * 'queued':   The item has selected by the queue for processing, and processing has begun.
     * 'started':  The item has received its apiID from TransparInt, and is now awaiting TransparInt's results.
     * 'finished': TransparInt has sent its results, and they've been saved.
     * 'stopped':  An error has occurred over the course of processing.
     *
     * @param integer $id     g_mediaMonQueue.id whose status gets updated
     * @param string  $status new g_mediaMonQueue.status value
     *
     * @return void
     */
    public function setQueueItemStatusByID($id, $status)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $id = (int)$id;
        $statuses = ['ready', 'queued', 'started', 'finished', 'stopped'];
        if (empty($id) || empty($status) || !in_array($status, $statuses)) {
            return;
        }
        $this->DB->query(
            "UPDATE {$this->queueTbl} SET `status` = :status WHERE id = :id",
            [':id' => $id, ':status' => $status]
        );
    }

    /**
     * Set queued item status. The statuses are as follows:
     *
     * @param integer $id    g_mediaMonQueue.id whose status gets updated
     * @param integer $apiID new g_mediaMonQueue.apiID value (optional)
     *
     * @return void
     */
    public function closeQueueItemByID($id, $apiID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $id = (int)$id;
        $apiID = (int)$apiID;
        if (empty($id)) {
            return;
        }
        $sql = "UPDATE {$this->queueTbl} SET `status` = 'finished'";
        $params = [':id' => $id];
        if (!empty($apiID)) {
            $sql .= ", apiID = :apiID";
            $params[':apiID'] = $apiID;
        }
        $sql .= " WHERE id = :id";
        $this->DB->query($sql, $params);
    }

    /**
     * Update queued item status by apiID and tenantID.
     *
     * @param integer $apiID    g_mediaMonQueue.apiID
     * @param string  $status   new g_mediaMonQueue.status value
     * @param integer $tenantID g_mediaMonQueue.tenantID
     *
     * @return void
     */
    public function updateMMQueueStatus($apiID, $status, $tenantID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $apiID = (int)$apiID;
        $tenantID = (int)$tenantID;
        $statuses = ['ready', 'queued', 'started', 'finished', 'stopped'];
        if (empty($apiID) || empty($tenantID) || empty($status) || !in_array($status, $statuses)
            || !($item = $this->getQueueItemByApiAndTenantID($apiID, $tenantID))
        ) {
            return;
        }
        $this->setQueueItemStatusByID($item['id'], $status);
    }

    /**
     * Load pending TP data by filters to be searched into the queue
     *
     * @return boolean
     */
    public function loadPendingSearches()
    {
        $logger = CronLogger::getInstance();
        $filters = $this->getFiltersPending();
        $logger?->logDebug('filters : ' . print_r($filters, true));

        foreach ($filters as $filter) {
            $tenantID = $filter['tenantID'];
            // Update the globalRefinements property on MediaMonitorFilterData for the given Tenant.
            $logger?->logDebug('tenantID : ' . $tenantID);
            $this->indexGlobalRefinementsProperty($tenantID);
            $filterInfo = json_decode((string) $filter['filters'], true);
            $startDate = date('Y-m-d', strtotime("-30 years"));
            $endDate = date('Y-m-d');
            $globalRefinement = ($this->globalRefinements[$tenantID]['enabled'])
                ? $this->globalRefinements[$tenantID]['term']
                : null;

            // Get list of records from tpPersons table to search in chunks of 100
            $logger?->logDebug('filterInfo : ' . $filter['id']);
            $tpPersons = $this->getFilterPersons((int)$filter['id']);
            $lastPersonID = 0;
            while (!empty($tpPersons)) {
                foreach ($tpPersons as $item) {
                    $lastPersonID = $item['id'];
                    $data = [
                        'tenantID'         => $tenantID,
                        'personID'         => $item['id'],
                        'tpProfileID'      => $item['profileID'],
                        'searchTerm'       => $item['term'],
                        'recordType'       => $item['recordType'],
                        'interactive'      => false, // search was NOT triggered by a user interaction
                        'startDate'        => $startDate,
                        'endDate'          => $endDate,
                        'refinements'      => $globalRefinement,
                        'globalRefinement' => (($globalRefinement) ? 1 : 0),
                        'searchFilterID'   => $filter['id']
                    ];
                    $logger?->logDebug('forloop data : ' . print_r($data, true));
                    $this->addQueueItem($data);
                }
                // Grab the next chunk of tpPersons (if one exists)
                $tpPersons = $this->getFilterPersons((int)$filter['id'], $lastPersonID);
            }

            // Get list of records from thirdPartyProfile table to search in chunks of 100
            if (isset($filterInfo['includeEntities']) && $filterInfo['includeEntities'] == 1 && !empty($filterInfo['entities'])) {
                $tpProfiles = $this->getFilterProfiles((int)$filter['id']);
                $lastProfileID = 0;
                while (!empty($tpProfiles)) {
                    foreach ($tpProfiles as $item) {
                        $lastProfileID = $item['profileID'];
                        $all = in_array('all', $filterInfo['entities']) ||
                            (in_array('company_name', $filterInfo['entities'])
                                && in_array('alternate', $filterInfo['entities']));

                        if (($all || in_array('company_name', $filterInfo['entities']))
                            && !empty($item['legalName'])
                        ) {
                            $data = [
                                'tenantID'         => $tenantID,
                                'tpProfileID'      => $item['profileID'],
                                'searchTerm'       => $item['legalName'],
                                'recordType'       => 'Entity',
                                'searchTermOrigin' => 'legalName',
                                'interactive'      => false, // search was NOT triggered by a user interaction
                                'startDate'        => $startDate,
                                'endDate'          => $endDate,
                                'refinements'      => $globalRefinement,
                                'globalRefinement' => (($globalRefinement) ? 1 : 0),
                                'searchFilterID'   => $filter['id']
                            ];
                            $logger?->logDebug('company_name data : ' . print_r($data, true));
                            $this->addQueueItem($data);
                        }

                        if (($all || in_array('alternate', $filterInfo['entities']))
                            && !empty($item['DBAname'])
                        ) {
                            $data = [
                                'tenantID'         => $tenantID,
                                'tpProfileID'      => $item['profileID'],
                                'searchTerm'       => $item['DBAname'],
                                'recordType'       => 'Entity',
                                'searchTermOrigin' => 'DBAname',
                                'interactive'      => false, // search was NOT triggered by a user interaction
                                'startDate'        => $startDate,
                                'endDate'          => $endDate,
                                'refinements'      => $globalRefinement,
                                'globalRefinement' => (($globalRefinement) ? 1 : 0),
                                'searchFilterID'   => $filter['id']
                            ];
                            $logger?->logDebug('alternate data : ' . print_r($data, true));
                            $this->addQueueItem($data);
                        }
                    }
                    // Grab the next chunk of tpProfiles (if one exists)
                    $logger?->logDebug('lastProfileID : ' . $lastProfileID);
                    $tpProfiles = $this->getFilterProfiles((int)$filter['id'], $lastProfileID);
                }
            }
        }
        $this->markFiltersProcessed($filters);
        return true;
    }

    /**
     * Check a specific profile against filters, and return the profile and associated persons if included.
     *
     * @param integer $tenantID          g_tenants.id
     * @param integer $profileID         thirdPartyProfile.id
     * @param boolean $checkGDC          Should persons and entities be filtered by tpPersonMap.bIncludeInGDC value
     * @param boolean $automated         Whether or not the search was the result of an automated process
     *                                   (i.e. not via direct user interaction)
     * @param boolean $createProfileFlag Whether thridparty created or not
     *
     * @return mixed Array if items else false boolean
     */
    public function applyFiltersToProfile($tenantID, $profileID, $checkGDC = false, $automated = false, $createProfileFlag = false)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID = (int)$tenantID;
        $profileID = (int)$profileID;
        $rtn = false;
        if (empty($tenantID) || empty($profileID)) {
            return $rtn;
        }
        $this->setFiltersTenant($tenantID);

        // Get all filters for the tenant
        $filters = $this->getFilters(true);
        $this->debugLog(__FILE__, __LINE__, 'filters: ' . print_r($filters, true));
        // check each filter to determine if our profile belongs
        if (is_array($filters) && count($filters) > 0) {
            $persons = $items = [];
            foreach ($filters as $filter) {
                $filterInfo = json_decode((string) $filter['filters'], true);
                $this->debugLog(
                    __FILE__,
                    __LINE__,
                    "Running filter: " . print_r($filterInfo, true)
                );

                // if the filter is paused, skip it
                if ($filter['next_run'] == 'paused') {
                    $this->debugLog(__FILE__, __LINE__);
                    continue;
                }
                // Get list of records from thirdPartyProfile table to search
                if (isset($filterInfo['includeEntities']) && $filterInfo['includeEntities'] == 1) {
                    $tpProfiles = null;
                    if ($createProfileFlag) {
                        $tpProfiles = $this->getFilterProfiles((int)$filter['id'], 0, false, $profileID);
                    } else {
                        $tpProfiles = $this->getFilterProfiles((int)$filter['id']);
                    }
                    $lastProfileID = 0;
                    while (!empty($tpProfiles)) {
                        $this->debugLog(
                            __FILE__,
                            __LINE__,
                            "Filter profiles: " . print_r($tpProfiles, true)
                        );
                        $startDate = date('Y-m-d', strtotime("-30 years"));
                        $endDate = date('Y-m-d');
                        foreach ($tpProfiles as $item) {
                            $lastProfileID = $item['profileID'];

                            // If the current item is not the desired profile, skip it
                            if ($item['profileID'] != $profileID) {
                                $this->debugLog(__FILE__, __LINE__);
                                continue;
                            }

                            $all = in_array('all', $filterInfo['entities']) ||
                                (in_array('company_name', $filterInfo['entities'])
                                    && in_array('alternate', $filterInfo['entities']));

                            if (($all && !isset($items['legalName']) || in_array('company_name', $filterInfo['entities']))
                                && !empty($item['legalName'] && !isset($items['legalName']))
                            ) {
                                $items['legalName'] = [
                                    'tenantID'         => $item['tenantID'],
                                    'tpProfileID'      => $item['profileID'],
                                    'searchTerm'       => $item['legalName'],
                                    'recordType'       => 'Entity',
                                    'searchTermOrigin' => 'legalName',
                                    'interactive'      => ($automated === false), // whether or not search was triggered by a user interaction
                                    'startDate'        => $startDate,
                                    'endDate'          => $endDate
                                ];
                            }

                            if (($all && !isset($items['DBAname']) || in_array('alternate', $filterInfo['entities']))
                                && !empty($item['DBAname'] && !isset($items['DBAname']))
                            ) {
                                $items['DBAname'] = [
                                    'tenantID'         => $item['tenantID'],
                                    'tpProfileID'      => $item['profileID'],
                                    'searchTerm'       => $item['DBAname'],
                                    'recordType'       => 'Entity',
                                    'searchTermOrigin' => 'DBAname',
                                    'interactive'      => ($automated === false), // whether or not search was triggered by a user interaction
                                    'startDate'        => $startDate,
                                    'endDate'          => $endDate
                                ];
                            }
                        }
                        // Grab the next chunk of tpProfiles (if one exists)
                        if ($createProfileFlag) {
                            break;
                        } else {
                            $tpProfiles = $this->getFilterProfiles((int)$filter['id'], $lastProfileID);
                        }
                    }
                }
                // Get list of records from tpPersons table to search
                $tpPersons = null;
                if ($createProfileFlag) {
                    $tpPersons = $this->getFilterPersons((int)$filter['id'], 0, false, false, $profileID);
                } else {
                    $tpPersons = $this->getFilterPersons((int)$filter['id']);
                }
                $lastPersonID = 0;
                while (!empty($tpPersons)) {
                    $this->debugLog(
                        __FILE__,
                        __LINE__,
                        "Filter persons: " . print_r($tpPersons, true)
                    );
                    foreach ($tpPersons as $tp) {
                        $lastPersonID = $tp['id'];

                        // If the person does not belong to the current profile, skip it
                        if ($tp['profileID'] != $profileID) {
                            $this->debugLog(__FILE__, __LINE__);
                            continue;
                        }

                        // if $checkGDC is true and subject is not included in the GDC screening, skip it
                        if ($checkGDC && !$this->gdcIncludeStatus($tenantID, $tp['id'], $tp['profileID'])) {
                            $this->debugLog(__FILE__, __LINE__);
                            continue;
                        }

                        // prevent adding the same person when they appear in several filters
                        if (!isset($persons[$tp['id'] . $tp['recordType']])) {
                            $persons[$tp['id'] . $tp['recordType']] = [
                                'tenantID'    => $tp['tenantID'],
                                'tpProfileID' => $tp['profileID'],
                                'searchTerm'  => $tp['term'],
                                'recordType'  => $tp['recordType'],
                                'personID'    => $tp['id'],
                                'interactive' => ($automated === false), // whether or not search was triggered by a user interaction
                                'startDate'   => $startDate,
                                'endDate'     => $endDate
                            ];
                        }
                    }
                    // Grab the next chunk of tpPersons (if one exists)
                    if ($createProfileFlag) {
                        break;
                    } else {
                        $tpPersons = $this->getFilterPersons((int)$filter['id'], $lastPersonID);
                    }
                }
            }

            if (empty($items) && empty($persons)) {
                return $rtn;
            }
            // Reset to numeric keys
            $persons = array_values($persons);
            $items = array_values($items);

            // Append persons to items for queue insertion
            $rtn = array_merge($items, $persons);

            $this->debugLog(
                __FILE__,
                __LINE__,
                "Final queue items: " . print_r($rtn, true)
            );
        }

        return $rtn;
    }

    /**
     * Requeue a queue item by id.
     *
     * @param integer $id            g_mediaMonQueue.id
     * @param integer $spID          g_mediaMonQueue.spID
     * @param integer $tenantID      g_mediaMonQueue.tenantID
     * @param integer $timesRequeued g_mediaMonQueue.timesRequeued
     *
     * @return void
     */
    public function requeueItem($id, $spID, $tenantID, $timesRequeued)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $id            = (int)$id;
        $spID          = (int)$spID;
        $timesRequeued = (int)$timesRequeued;
        if (!empty($id)) {
            // Set the startDate to null for test Tenants. This is required for google results. #870
            $startDate = null;
            if (!empty($spID)) {
                $startDate = (!empty($spID)) ? date('Y-m-d', strtotime('-3 months')) : date('Y-m-d', strtotime("-30 years"));
            }
            $sql = "UPDATE {$this->queueTbl} SET\n"
                . "apiID = NULL, status = 'ready', timesRequeued = :timesRequeued, startDate = :startDate, endDate = :endDate, created = :created\n"
                . "WHERE id = :id";
            $params = [
                ':startDate' => $startDate,
                ':endDate' => date('Y-m-d'),
                ':created' => date('Y-m-d H:i:s'),
                ':timesRequeued' => ($timesRequeued + 1),
                ':id' => $id
            ];
            $this->DB->query($sql, $params);
        }
    }

    /**
     * Delete a queue item by id.
     *
     * @param integer $id g_mediaMonQueue.id
     *
     * @return void
     */
    public function deleteQueueItemByID($id)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $id = (int)$id;
        if (!empty($id)) {
            $this->DB->query("DELETE FROM {$this->queueTbl} WHERE id = :id", [':id' => $id]);
        }
    }

    /**
     * Determines whether or not the Media Monitor Queue CRON job is already running.
     *
     * @return boolean
     */
    public function isCronJobRunning()
    {
        try {
            $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
            $stuckQueueThreshold = $this->app->confValues['cms']['mmStuckQueueThreshold'];
            $sql = "SELECT running, queueSnapshot, startedRun, finishedRun, startedPendingSrchLoad, "
                . "finishedPendingSrchLoad, startedStalledItemsProcessing, "
                . "finishedStalledItemsProcessing, startedReadyItemsProcessing, "
                . "finishedReadyItemsProcessing, startedCleanup, finishedCleanup, "
                . "CONVERT_TZ(NOW(), @@session.time_zone, '+00:00') AS currentDateTime, "
                . "IF(running = 1 AND "
                . "CONVERT_TZ(NOW(), @@session.time_zone, '+00:00') "
                . ">= DATE_ADD(startedRun, INTERVAL {$stuckQueueThreshold}), "
                . "1, 0) AS checkIfQueueIsStuck\n"
                . "FROM {$this->queueStatusTbl}";
            $queueStatus = $this->DB->fetchAssocRow($sql, []);
            $this->debugLog(__FILE__, __LINE__, "queueStatus : " . print_r($queueStatus, 1), __METHOD__);
            $this->debugLog(__FILE__, __LINE__, "SQL: " . $this->DB->mockFinishedSQL($sql, []));
            $rtn = (!empty((int)$queueStatus['running']));
            $checkIfQueueIsStuck = (!empty((int)$queueStatus['checkIfQueueIsStuck']));
            $currentDateTime = $queueStatus['currentDateTime'];
            if ($checkIfQueueIsStuck) {
                $this->debugLog(__FILE__, __LINE__, "Checking whether MM queue is stuck.");
                $lastCapturedProcessing = [];
                if (!empty($queueStatus['queueSnapshot'])
                    && ($queueSnapshot = IO::detectJSON($queueStatus['queueSnapshot']))
                    && is_array($queueSnapshot) && !empty($queueSnapshot)
                    && isset($queueSnapshot['ready'])
                    && isset($queueSnapshot['queued'])
                    && isset($queueSnapshot['started'])
                ) {
                    $lastCapturedProcessing = [
                        'ready' => $queueSnapshot['ready'],
                        'queued' => $queueSnapshot['queued'],
                        'started' => $queueSnapshot['started']
                    ];
                }
                $this->debugLog(
                    __FILE__,
                    __LINE__,
                    [
                    'queueSnapshot' => $queueStatus['queueSnapshot'],
                    'detectJSON' => IO::detectJSON($queueStatus['queueSnapshot'])
                    ]
                );

                $currentlyProcessingSql = "SELECT COUNT(*) FROM {$this->queueTbl} WHERE status = :status";
                $currentlyProcessing = [
                    'ready' => $this->DB->fetchValue($currentlyProcessingSql, [':status' => 'ready']),
                    'queued' => $this->DB->fetchValue($currentlyProcessingSql, [':status' => 'queued']),
                    'started' => $this->DB->fetchValue($currentlyProcessingSql, [':status' => 'started'])
                ];
                $this->debugLog(
                    __FILE__,
                    __LINE__,
                    [
                    'lastCapturedProcessing' => $lastCapturedProcessing,
                    'currentlyProcessing' => $currentlyProcessing
                    ]
                );
                $isStuck = false;
                if (!empty($lastCapturedProcessing) && !empty($currentlyProcessing)) {
                    $isStuck = true;
                    foreach (['ready', 'queued', 'started'] as $process) {
                        if ((int)$currentlyProcessing[$process] != (int)$lastCapturedProcessing[$process]) {
                            $isStuck = false;
                        }
                    }
                }
                if ($isStuck) {
                    $this->debugLog(__FILE__, __LINE__, "MM queue is stuck.");
                    unset($queueStatus['running'], $queueStatus['stuck'], $queueStatus['currentDateTime']);
                    $this->unstickQueue($queueStatus);
                    $rtn = false;
                } else {
                    // Not stuck, so capture the queue process counts.
                    $json = '{'
                        . '"captured": "' . $currentDateTime . '", '
                        . '"ready": ' . $currentlyProcessing['ready'] . ', '
                        . '"queued": ' . $currentlyProcessing['queued'] . ', '
                        . '"started": ' . $currentlyProcessing['started']
                        . '}';
                    $this->DB->query(
                        "UPDATE {$this->queueStatusTbl} SET queueSnapshot = :queueSnapshot",
                        [':queueSnapshot' => $json]
                    );
                }
            }
            return $rtn;
        } catch (\Exception $e) {
            \Xtra::track("Exception occurred in isCronJobRunning: " . $e->getMessage() . "\n" . $e->getTraceAsString(), \Skinny\Log::ERROR);
            return false;
        }
    }

    /**
     * The queue has become stuck due to an outage.
     * Unstick it, and send out a devOps notification with pertinent info.
     *
     * @param array $queueStatus Datetime columns from g_mediaMonQueueStatus
     *
     * @return void
     */
    private function unstickQueue($queueStatus)
    {
        try {
            $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
            // Unstick the queue by setting running back to 0, uninhibiting the next cron run.
            $this->DB->query("UPDATE {$this->queueStatusTbl} SET running = 0", []);
            if ($this->app->mode == 'Development') {
                $locale = 'DEV';
            } elseif ($this->app->isEuropeanServer) {
                $locale = 'EU';
            } elseif ($this->app->isRussianServer) {
                $locale = 'RU';
            } else { // US Production
                $locale = 'US';
            }
            $stoppedResponding = date('Y-m-d H:i:s', max(array_map('strtotime', $queueStatus)));
            $subject = "[$locale] Media Monitor Critical Failure";
            $msg = "Media Monitor's queue processing has been interrupted, "
                . "likely as the result of a server outage. "
                . "Based on the following data collected from the g_mediaMonQueueStatus table, "
                . "the queue started processing at " . $queueStatus['startedRun'] . " and "
                . "stopped responding at {$stoppedResponding}:\n\n"
                . "startedRun: " . $queueStatus['startedRun'] . "\n"
                . "startedPendingSrchLoad: " . $queueStatus['startedPendingSrchLoad'] . "\n"
                . "finishedPendingSrchLoad: " . $queueStatus['finishedPendingSrchLoad'] . "\n"
                . "startedStalledItemsProcessing: " . $queueStatus['startedStalledItemsProcessing'] . "\n"
                . "finishedStalledItemsProcessing: " . $queueStatus['finishedStalledItemsProcessing'] . "\n"
                . "startedReadyItemsProcessing: " . $queueStatus['startedReadyItemsProcessing'] . "\n"
                . "finishedReadyItemsProcessing: " . $queueStatus['finishedReadyItemsProcessing'] . "\n"
                . "startedCleanup: " . $queueStatus['startedCleanup'] . "\n"
                . "finishedCleanup: " . $queueStatus['finishedCleanup'] . "\n"
                . "finishedRun: " . $queueStatus['finishedRun'] . "\n\n"
                . "The g_mediaMonQueueStatus table's 'running' column has been reset to 0, "
                . "which will permit the next cron run to proceed processing the queue.";
            $this->debugLog(__FILE__, __LINE__, print_r(['Subject' => $subject, 'Message' => $msg], true));
            AppMailer::mail(
                1,
                $_ENV['devopsEmailAddress'],
                $subject,
                $msg,
                ['from' => 'devopsCRONnote@steeleglobal.com', 'addHistory' => false, 'forceSystemEmail' => true]
            );
        } catch (\Exception $e) {
            \Xtra::track([
                'Message' => "Exception occurred in unstickQueue: " . $e->getMessage() . "\n" . $e->getTraceAsString(),
                'QueueStatus' => $queueStatus,
            ], \Skinny\Log::ERROR);
        }
    }

    /**
     * Determines whether or not it is time for the Media Monitor Queue CRON job to cleanup the queue.
     *
     * @return boolean
     */
    private function isItCleanupTime()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $sql = "SELECT IF(CONVERT_TZ(NOW(), @@session.time_zone, '+00:00') "
            . ">= DATE_ADD(finishedCleanup, INTERVAL " . self::CLEANUP_THRESHOLD . "), 1, 0)\n"
            . "FROM {$this->queueStatusTbl}";
        $cleanupTime = $this->DB->fetchValue($sql);
        $cleanupTime = (int)$cleanupTime;
        return (!empty($cleanupTime));
    }

    /**
     * Updates the specified Media Monitor Queue Status datetime field, and toggles the running column if startedRun or finishedRun mode.
     *
     * @param string $mode One of ten possible datetime fields to update
     *
     * @return void
     */
    public function updateQueueStatus($mode)
    {
        $logger = CronLogger::getInstance();
        $logger?->logDebug('mode : ' . $mode);
        $modes = [
            'startedRun',
            'startedPendingSrchLoad',
            'finishedPendingSrchLoad',
            'startedStalledItemsProcessing',
            'finishedStalledItemsProcessing',
            'startedReadyItemsProcessing',
            'finishedReadyItemsProcessing',
            'startedCleanup',
            'finishedCleanup',
            'finishedRun',
        ];
        if (empty($mode) || !in_array($mode, $modes)) {
            return;
        }
        $sql = "UPDATE {$this->queueStatusTbl} SET {$mode} = :newdate";
        if ($mode == 'startedRun') {
            $sql .= ", running = 1";
        } elseif ($mode == 'finishedRun') {
            $sql .= ", running = 0";
        }
        $this->DB->query($sql, [':newdate' => date('Y-m-d H:i:s')]);
    }

    /**
     * Purge the queue of all records having a status of 'finished',
     * or a timesRequeued that falls outside the "requeued threshold" with a created value falls outside of the "stalled" threshold..
     *
     * @return boolean
     */
    public function cleanupQueue()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        if (!$this->isItCleanupTime()) {
            return true;
        }
        $this->updateQueueStatus('startedCleanup');
        $sql = "DELETE FROM {$this->queueTbl}\n"
            . "WHERE status = 'finished'\n"
            . "OR (timesRequeued >= :timesRequeued AND created < "
            . "DATE_SUB(CONVERT_TZ(NOW(), @@session.time_zone, '+00:00'),INTERVAL " . self::STALLED_THRESHOLD . "))\n";
        $this->DB->query($sql, [':timesRequeued' => self::REQUEUED_THRESHOLD]);
        $this->updateQueueStatus('finishedCleanup');
        return true;
    }

    /**
     * Gather recs having a 'ready' status and a null apiID, and update status to 'queued'.
     * A limit was put into place for performance sake.
     *
     * @param integer $priority g_mediaMonQueue.priority
     * @param integer $lastID   If provided, this will constrain the query to results having id's higher than this value.
     *
     * @return array
     */
    public function queueUpReadyItems($priority = 0, $lastID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn        = [];
        $priority   = (int)$priority;
        $lastID     = (int)$lastID;
        $lastIDCond = '';
        $params     = [':priority' => $priority];
        if (!empty($lastID)) {
            $lastIDCond = 'id > :lastID AND ';
            $params[':lastID'] = $lastID;
        }

        // First, gather the 'ready' records for processing
        $sql = "SELECT id, apiID, tenantID, spID, request, startDate, endDate, `status`, exempt\n"
            . "FROM {$this->queueTbl} WHERE {$lastIDCond}priority = :priority AND apiID IS NULL\n"
            . "AND `status` = 'ready'\n"
            . "ORDER BY id ASC\n"
            . "LIMIT " . self::READIED_QUEUE_LIMIT;
        if ($records = $this->DB->fetchAssocRows($sql, $params)) {
            $total = (int)count($records);
            for ($r = 0; $r < $total; $r++) { // For loops are less memory-intensive than foreach loops.
                $this->setQueueItemStatusByID($records[$r]['id'], 'queued');
                $records[$r]['status'] = 'queued';
            }
            $rtn = $records;
        }
        return $rtn;
    }

    /**
     * Gather recs where status != 'finished' and created value falls outside of the stalled threshold.
     * A limit was put into place for performance sake.
     *
     * @param integer $lastID If provided, this will constrain the query to results having id's higher than this value.
     *
     * @return array
     */
    public function getStalledItems($lastID = 0)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = [];
        $lastID = (int)$lastID;
        $lastIDCond = '';
        $params = [':timesRequeued' => self::REQUEUED_THRESHOLD];
        if (!empty($lastID)) {
            $lastIDCond = 'id > :lastID AND ';
            $params[':lastID'] = $lastID;
        }
        $fields = "id, apiID, tenantID, spID, request, `status`, timesRequeued, exempt, searchFilterID";
        $sql = "SELECT {$fields} FROM {$this->queueTbl}\n"
            . "WHERE {$lastIDCond}status != 'finished' AND timesRequeued < :timesRequeued "
            . "AND ((created IS NULL) OR "
            . "(created < DATE_SUB(CONVERT_TZ(NOW(), @@session.time_zone, '+00:00'),INTERVAL " . self::STALLED_THRESHOLD . ")))\n"
            . "ORDER BY id ASC LIMIT " . self::STALLED_REQUEUE_LIMIT;
        $this->debugLog(__FILE__, __LINE__, "SQL: " . $this->DB->mockFinishedSQL($sql, $params));
        if ($recs = $this->DB->fetchAssocRows($sql, $params)) {
            $rtn = $recs;
        }
        return $rtn;
    }

    /**
     * Try to find a queue item by apiID and tenantID
     *
     * @param int $apiID    Internal search ID associated with queued item
     * @param int $tenantID ID of the tenant associated with the queued item and search
     *
     * @return mixed if result array else false boolean
     */
    public function getQueueItemByApiAndTenantID($apiID, $tenantID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = false;
        $apiID = (int)$apiID;
        $tenantID = (int)$tenantID;
        $sql = "SELECT * FROM {$this->queueTbl} WHERE apiID = :apiID AND tenantID = :tenantID LIMIT 1";
        if (!empty($apiID) && !empty($tenantID)
            && ($item = $this->DB->fetchAssocRow($sql, [':apiID' => $apiID, ':tenantID' => $tenantID]))
        ) {
            $rtn = $item;
        }
        return $rtn;
    }

    /**
     * Try to find a queue item by just its apiID
     *
     * @param int $apiID Internal search ID associated with queued item
     *
     * @return mixed if result array else false boolean
     */
    public function getQueueItemByApiID($apiID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = false;
        $apiID = (int)$apiID;
        $sql = "SELECT * FROM {$this->queueTbl} WHERE apiID = :apiID LIMIT 1";
        if (!empty($apiID) && ($item = $this->DB->fetchAssocRow($sql, [':apiID' => $apiID]))) {
            $rtn = $item;
        }
        return $rtn;
    }

    /**
     * Log the instance of a stalled item being requeued in g_mediaMonQueueStalledLog
     *
     * @param string  $type     Either refinementsNoApiIDs, refinementsApiIDs, noApiIDs, apiIDs
     * @param integer $tenantID g_mediaMonQueue.tenantID
     * @param integer $spID     g_mediaMonQueue.spID
     * @param string  $DB       Broken-out client DB name if no spID, else the SP DB
     * @param string  $status   g_mediaMonQueue.status value prior to being requeued
     * @param string  $request  g_mediaMonQueue.request
     * @param integer $apiID    mediaMonRequest.apiID
     * @param integer $srchID   mediaMonSrch.id
     * @param integer $exempt   Exempt requests will not be included as part of the Tenant's utilized searches
     *                          for the support tool. Exempt should only be 1 when the request is caused by the
     *                          RepairTool CLI.
     * @param integer $filterID g_mediaMonSearchFilters.id
     *
     * @return integer
     */
    public function logStalledQueueItem($type, $tenantID, $spID, $DB, $status, $request, $apiID, $srchID, $exempt, $filterID)
    {
        $tenantID = (int)$tenantID;
        $spID = (int)$spID;
        $exempt = (int)$exempt;
        $apiID = (int)$apiID;
        $srchID = (int)$srchID;
        $filterID = (int)$filterID;
        if (empty($type) || empty($DB) || empty($status) || empty($request)
            || !in_array($type, ['refinementsNoApiIDs', 'refinementsApiIDs', 'noApiIDs', 'apiIDs'])
        ) {
            return 0;
        }
        $fields = "`type`, tenantID, spID, DB, `status`, request, apiID, srchID, created, exempt, searchFilterID";
        $values = ":type, :tenantID, :spID, :DB, :status, :request, :apiID, :srchID, :created, :exempt, :filterID";
        $sql = "INSERT INTO {$this->queueStalledLogTbl} ({$fields}) VALUES({$values})";
        $params = [
            ':type' => $type,
            ':tenantID' => $tenantID,
            ':spID' => $spID,
            ':DB' => $DB,
            ':status' => $status,
            ':request' => $request,
            ':apiID' => $apiID,
            ':srchID' => $srchID,
            ':created' => date('Y-m-d H:i:s'),
            ':exempt' => $exempt,
            ':filterID' => $filterID
        ];
        $this->DB->query($sql, $params);
        return $this->DB->lastInsertId();
    }
}
