<?php
/**
 * Model: handle Media Monitor settings for Tenants
 *
 * @keywords media monitor, data, threshold
 */

namespace Models\TPM\MediaMonitor;

use Models\Globals\Features\TenantFeatures;
use Models\LogData;
use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\ThirdPartyManagement\RiskModel;

/**
 * Provides data access for MediaMonitor search filters
 */
#[\AllowDynamicProperties]
class MediaMonitorFilterData
{
    /**
     * Reference to the app db object
     *
     * @var \Lib\Database\MySqlPdo
     */
    protected $DB = null;

    /**
     * Reference to global app
     *
     * @var \Skinny\Skinny
     */
    protected $app = null;

    /**
     * Reference to profile table
     *
     * @var string
     */
    protected $tbl = '';

    /**
     * Amount of time to wait before starting first run of filter
     *
     * @var string
     */
    protected $startPause = '5 minutes';

    /**
     * Search filter frequencies
     *
     * @var array
     */
    public $frequencies = [3 => 'Weekly', 5 => 'Monthly', 6 => 'Quarterly', 7 => 'Biannually', 8 => 'Yearly'];

    /**
     * Search filter associates
     *
     * @var array
     */
    public $associates = [
        'owner' => 'Owner',
        'key_manager' => 'Key Manager',
        'board_member' => 'Board Member',
        'key_consultant' => 'Key Consultant',
        'employee' => 'Employee',
        'has_government_relationship' => 'Has government relationship',
        'primary_poc' => 'Primary Point of Contact',
        'poc' => 'Point of Contact'
    ];

    /**
     * Search filter entities
     *
     * @var array
     */
    public $entities = ['company_name' => 'Official Company Name', 'alternate' => 'Alternate Trade Name'];

    /**
     * Search filter 3P Types and affiliated categories
     *
     * @var array
     */
    public $typeCats = [];

    /**
     * Search filter risk levels
     *
     * @var array
     */
    public $riskLevels = [];

    /**
     * Search filter types and their configurations
     *
     * @var array
     */
    private $filterTypes = [
        'riskLevels' => [
            'name' => 'Risk Levels',
            'cfg' => 'mapping',
            'mapping' => ['valSrc' => 'id', 'lblSrc' => 'tierName']
        ],
        'typeCats' => [
            'name' => '3P Types/3P Categories',
            'cfg' => 'nodes',
            'nodes' => [
                0 => [
                    'id' => 'root',
                    'name' => 'Type',
                    'lkupPrefix' => 't-',
                    'valSrc' => 'id',
                    'lblSrc' => 'name'
                ], 1 => [
                    'id' => 'categories',
                    'name' => 'Categories',
                    'lkupPrefix' => 'c-',
                    'valSrc' => 'id',
                    'lblSrc' => 'name'
                ]
            ]
        ],
        'entities' => ['name' => 'Entities', 'cfg' => 'keyvaluepairs'],
        'associates' => ['name' => 'Associates', 'cfg' => 'keyvaluepairs'],
        'onlyNew' => ['name' => 'Only New', 'cfg' => 'convert', 'conversion' => [0 => 'No', 1 => 'Yes']],
        'includeEntities' => [
            'name' => 'Include Entities',
            'cfg' => 'convert',
            'conversion' => [0 => 'No', 1 => 'Yes']
        ]
    ];

    /**
     * Delimiter between values that have changed in audit log entries
     *
     * @var string
     */
    private $valDelimiter;

    /**
     * Provides details about MM global refinement feature on a per Tenant basis for use with queue
     *
     * @var array
     */
    public $globalRefinements = [];

    /**
     * Initialize data for model
     *
     * @param integer $tenantID Tenant ID
     */
    public function __construct(/**
         * Tenant ID
         */
        protected $tenantID = null
    ) {
        $this->app          = \Xtra::app();
        $this->DB           = $this->app->DB;
        $this->tbl          = $this->DB->prependDbName('global', 'g_mediaMonSearchFilters');
        if (!empty($this->tenantID)) {
            $this->typeCats     = $this->get3pTypesAndCategories();
            $this->riskLevels   = $this->getRiskLevels();
        }
        $this->valDelimiter = htmlentities(' => ');
    }

    /**
     * Get list of risk levels
     *
     * @return array
     */
    private function getRiskLevels()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $risk_models = RiskModel::getActiveModels($this->tenantID);
        $rtn = [];
        // Select and merge tiers from all models
        if (!empty($risk_models)) {
            $riskModel = new RiskModel($risk_models[0]['id']);
            foreach ($risk_models as $model) {
                $tiers = $riskModel->getRiskTiers($model['id']);
                foreach ($tiers as $tier) {
                    $rtn[$tier->id] = $tier;
                }
            }
        }
        // Make sure tiers are sorted by threshold after merging and reset array keys so it becomes a json array
        usort($rtn, function ($a, $b) {
            if ($a->threshold == $b->threshold) {
                return 0;
            }
            return ($a->threshold < $b->threshold) ? 1 : -1;
        });
        $rtn = json_decode(json_encode($rtn), true);
        return array_merge([['id' => 'noscore', 'tierName' => 'No Score']], $rtn);
    }

    /**
     * Get list of available 3P types and categories
     *
     * @return array
     */
    private function get3pTypesAndCategories()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = $categories = [];
        $types = (new TpType($this->tenantID))->selectMultiple(['id', 'name']);
        foreach ($types as $type) {
            $cat = (new TpTypeCategory($this->tenantID))->getCleanCategoriesByType($type['id']);
            $categories[] = $cat;
            $rtn[] = array_merge($type, ['categories' => $cat]);
        }
        return $rtn;
    }

    /**
     * Update filter status
     *
     * @param array  $filter Filter data for filter being updated
     * @param string $status New status for filter
     *
     * @throws \Exception
     * @return void
     */
    public function updateStatus($filter, $status)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        if (($orig = $this->getFilterByID($filter['id']))) {
            $id = $filter['id'];
        } else {
            throw new \Exception('Unable to find search filter.');
        }
        $params = [
            ':status'   => $status,
            ':nextRun'  => $this->calcNextRun($filter, true),
            ':id'       => $id
        ];
        $this->DB->query(
            "UPDATE {$this->tbl} SET status = :status, next_run = :nextRun WHERE id = :id",
            $params
        );
        $nextRun = ($orig['next_run'] != $params[':nextRun'])
            ? "Next Run (" . $orig['next_run'] . $this->valDelimiter . $params[':nextRun'] . "), "
            : "Next Run (" . $params[':nextRun'] . "), ";
        $eventID = ($status === 'active') ? 223 : 224;
        $msg = "Filter #{$id} Name (" . $orig['name'] . "), "
            . $nextRun
            . "Status (" . (($status == 'active') ? 'paused' : 'active') . $this->valDelimiter . "{$status})";
        $this->auditLogEntry($eventID, $msg);
    }

    /**
     * Fetch all filters for current tenant
     *
     * @return array
     */
    public function getFilters($getActiveOnly = false)
    {
        $results = null;
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $where = '';

        // Added condition to get Active filter records only
        if ($getActiveOnly) {
            $where = "AND status = 'active'";
        }
        $sql = "SELECT `id`, `tenantID`, `frequency`, `name`, `filters`, `last_run`, "
            . "IF(`status` = 'paused', 'paused', next_run) AS next_run, `modified`, `created`, `status` "
            . "FROM {$this->tbl} WHERE tenantID = :tenantID " . $where;

        $params = [':tenantID' => $this->tenantID];
        $results = $this->DB->fetchAssocRows($sql, $params);
        $rtn = [];
        if (count($results) > 0) {
            foreach ($results as $result) {
                $filter = $result;
                $data   = json_decode((string) $result['filters'], true);
                if (empty($result['last_run'])) {
                    $result['last_run'] = 'never';
                }
                foreach ($data as $key => $val) {
                    // Make sure filter element is a valid filter type
                    if (array_key_exists($key, $this->filterTypes)) {
                        $filter[$key] = $val;
                    }
                }
                if (empty($result['next_run'])) {
                    $filter['next_run'] = $this->calcNextRun($filter);
                }
                $rtn[] = $filter;
            }
        } else {
            $rtn = $results;
        }

        return $rtn;
    }

    /**
     * Save filter
     *
     * @param array $new New filter info to save
     *
     * @return integer g_mediaMonSearchFilters.id
     */
    public function saveFilter($new)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        if (!empty($new['id']) && ($orig = $this->getFilterByID($new['id']))) {
            $rtn = $this->updateFilter($orig, $new);
        } else {
            $rtn = $this->saveNewFilter($new);
        }
        return $rtn;
    }

    /**
     * Update specified filter with new data
     *
     * @param array $orig Original data for existing filter
     * @param array $new  New data to update existing filter
     *
     * @return integer g_mediaMonSearchFilters.id
     */
    private function updateFilter($orig, $new)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        unset($new['filters']);
        $new = [
            'name'      => $new['name'],
            'frequency' => $new['frequency'][0],
            'filters'   => json_encode($this->cleanFilterData($new))
        ];
        $params = [':id' => $orig['id']];
        $filters = $sqlFlds = $frequency = '';
        $name = $orig['name'];
        if ($orig['name'] != $new['name']) {
            $name = $orig['name'] . $this->valDelimiter . $new['name'];
            $sqlFlds .= ', name = :name';
            $params[':name'] = $new['name'];
        }
        if ($orig['frequency'] != $new['frequency']) {
            $frequency = ', Frequency ('
                . $this->frequencies[(int)$orig['frequency']]
                . $this->valDelimiter
                . $this->frequencies[(int)$new['frequency']]
                . ')';
            $sqlFlds .= ', frequency = :frequency';
            $params[':frequency'] = $new['frequency'];
        }
        if ($orig['filters'] != $new['filters']) {
            $origFltrs = json_decode((string) $orig['filters'], true);
            $newFltrs = json_decode($new['filters'], true);
            $filters = ', Filters (' . $this->formatUpdatedFilterAuditLogMsg($origFltrs, $newFltrs) . ')';
            $sqlFlds .= ', filters = :filters';
            $params[':filters'] = $new['filters'];
        }
        if (!empty($sqlFlds)) {
            $sqlFlds = "next_run = NULL, status = 'paused'{$sqlFlds}";
            $this->DB->query("UPDATE {$this->tbl} SET {$sqlFlds} WHERE id = :id", $params);
            $eventID = 168; // Media Monitor Filter Updated
            $msg = "Filter #" . $orig['id'] . " Name ({$name}), "
                . "Next Run (" . $orig['next_run'] . $this->valDelimiter . 'Not Scheduled)'
                . (($orig['status'] == 'active') ? ', Status (active' . $this->valDelimiter . 'paused)' : '')
                . $frequency
                . $filters;
            $this->auditLogEntry($eventID, $msg);
        }
        return $orig['id'];
    }

    /**
     * Save new MM search filter. New filters start with a status of 'running'
     *
     * @param array $data Search filter data to save
     *
     * @return integer g_mediaMonSearchFilters.id
     */
    private function saveNewFilter($data)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        unset($data['filters']);
        $params = [
            ':tenantID'  => $this->tenantID,
            ':frequency' => $data['frequency'][0],
            ':name'      => $data['name'],
            ':filters'   => json_encode($this->cleanFilterData($data)),
            ':status'    => 'active',
            ':nextRun'   => $this->calcNextRun($data, true)
        ];
        $sql = 'INSERT INTO ' . $this->tbl . ' '
            . '(tenantID, frequency, name, filters, status, created, next_run) '
            . 'values (:tenantID, :frequency, :name, :filters, :status, NOW(), :nextRun)';
        $this->DB->query($sql, $params);
        $id = $this->DB->lastInsertId();
        $eventID = 167; // Media Monitor Filter Added
        $msg = "Filter #{$id} "
            . "Name (" . $params[':name'] . "), "
            . "Frequency (" . $this->frequencies[(int)$params[':frequency']] . "), "
            . "Filters (" . $this->formatAuditLogFiltersMsg($params[':filters']) . "), "
            . "Next Run (" . $params[':nextRun'] . "), "
            . "Status (" . $params[':status'] . ")";
        $this->auditLogEntry($eventID, $msg);
        return $id;
    }

    /**
     * Loop through and cleanup filter data
     *
     * @param array $data All filter data returned from the front-end
     *
     * @return array
     */
    private function cleanFilterData($data)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = [];
        foreach ($data as $k => $v) {
            if (array_key_exists($k, $this->filterTypes)) {
                $rtn[$k] = $v;
            }
        }
        return $rtn;
    }

    /**
     * Get list of available interval options (sharing these with Scheduled Process tables)
     *
     * @return array
     */
    private function getIntervalMap()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = [];
        $sql = 'SELECT id, intervalPeriod, intervalValue FROM '
            . $this->DB->prependDbName('global', 'g_schedProcIntervals') . ';';
        $results = $this->DB->fetchAssocRows($sql);
        foreach ($results as $el) {
            $rtn[$el['id']] = [
                'period' => $el['intervalPeriod'],
                'value'  => $el['intervalValue']
            ];
        }
        return $rtn;
    }

    /**
     * Calculate next run date for filter
     *
     * @param array   $filter Parsed filter array.
     * @param boolean $runNow Set to true if next run should be as immediate as possible
     *
     * @return string Time stamp of next run as string
     */
    private function calcNextRun($filter, $runNow = false)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $last = '';
        // If this has never been run, setup to run at first run interval.
        if (isset($filter['last_run'])) {
            $last = $filter['last_run'];
        }
        if (empty($last) || $last == 'never' || $runNow) {
            $date = date('Y-m-d H:i:s', strtotime('+' . $this->startPause, time()));
            return $date;
        }

        // Get list of available intervals
        $intervals = $this->getIntervalMap();

        $freq = $filter['frequency'];
        $result = strtotime('+' . $this->startPause, strtotime((string) $last));
        if (isset($intervals[$freq])) {
            $freq = $intervals[$freq];
            $result = strtotime(
                '+' . $freq['value'] . ' ' . strtolower((string) $freq['period']),
                strtotime((string) $last)
            );
        }
        return date('Y-m-d H:i:s', $result);
    }

    /**
     * Retrieve a single formatted filter
     *
     * @param integer $id ID of the filter to retrieve
     *
     * @return mixed Array if result, else false boolean
     */
    private function getFilterByID($id)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = false;
        $id = (int)$id;
        $sql = "SELECT * FROM {$this->tbl} WHERE id = :id";
        if (!empty($id) && ($rtn = $this->DB->fetchAssocRow($sql, [':id' => $id]))) {
            if (empty($rtn['last_run'])) {
                $rtn['last_run'] = 'never';
            }
            if (empty($rtn['next_run'])) {
                $rtn['next_run'] = $this->calcNextRun($rtn);
            }
        }
        $this->debugLog(__FILE__, __LINE__, $rtn);
        return $rtn;
    }

    /**
     * Get search count for specified filter
     *
     * @param integer $id ID of the search filter to use
     *
     * @return integer
     */
    public function getFilterCount($id)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        return ($this->getFilterProfiles($id, 0, true) + $this->getFilterPersons($id, 0, true));
    }

    /**
     * Get array of Media Monitor search filters ready to be run
     * For memory's sake, limited to 100 at a time.
     *
     * @return array
     */
    public function getFiltersPending()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $filtersTbl = $this->DB->prependDbName('global', 'g_mediaMonSearchFilters');
        $freqTbl = $this->DB->prependDbName('global', 'g_schedProcIntervals');
        $sql = "SELECT fi.id AS id, CONCAT(frq.intervalValue, ' ', frq.intervalPeriod) AS frequency, filters, "
            . "fi.last_run AS last_run, fi.frequency AS orig_frequency, fi.tenantID AS tenantID\n"
            . "FROM {$filtersTbl} AS fi\n"
            . "JOIN {$freqTbl} AS frq ON frq.id = fi.frequency\n"
            . "WHERE `status` = 'active' AND next_run < CONVERT_TZ(NOW(), @@session.time_zone, '+00:00')\n"
            . "LIMIT 100";
        $rtn = $this->DB->fetchAssocRows($sql);
        return $rtn;
    }

    /**
     * Mark filters as processed and advance next run date
     *
     * @param array $filters An array of filters that were processed
     *
     * @return void
     */
    public function markFiltersProcessed($filters)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        foreach ($filters as $filter) {
            // Reset frequency to original value
            $filter['frequency'] = $filter['orig_frequency'];
            $filter['last_run']  = date('Y-m-d H:i:s');
            $sql = "UPDATE {$this->tbl} SET next_run = :nextRun, last_run = :lastRun\n"
                . "WHERE id = :id AND tenantID = :tenantID LIMIT 1";
            $params = [
                ':id'       => (int)$filter['id'],
                ':tenantID' => $filter['tenantID'],
                ':nextRun'  => $this->calcNextRun($filter),
                ':lastRun'  => $filter['last_run']
            ];
            $this->DB->query($sql, $params);
        }
    }

    /**
     * Get all Third Party Profile's based on search filters.
     *
     * @param integer      $id        ID of the filter to retrieve
     * @param integer      $lastID    ID of the last thirdPartyProfile.id that was retrieved
     * @param boolean      $count     True if we only want to return the count
     * @param null|integer $profileID ThirdpartyProfile.id else null
     *
     * @return mixed Either array or integer
     */
    public function getFilterProfiles($id, $lastID = 0, $count = false, $profileID = null)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $lastID = (int)$lastID;
        $sql = "SELECT tenantID, filters FROM {$this->tbl} WHERE id = :id";
        $params[':id'] = $id;
        $result = $this->DB->fetchAssocRow($sql, $params);

        $tenantID = (int)$result['tenantID'];
        $filter   = json_decode((string) $result['filters'], true);

        $clientDB = $this->DB->getClientDB($tenantID);
        $tables   = [
            'thirdPartyProfile' => $this->DB->prependDbName($clientDB, 'thirdPartyProfile'),
            'riskAssessment'    => $this->DB->prependDbName($clientDB, 'riskAssessment'),
            'searchTable'       => $this->DB->prependDbName($clientDB, 'mediaMonSrch'),
        ];

        // Init query params and define tenantID
        $params = [':tenantID' => $tenantID];

        // Setup risk level query if provided
        $riskQuery = $this->getRiskQuery(\Xtra::arrayGet($filter, 'riskLevels', []), $clientDB);
        $params    = array_merge($params, $riskQuery['params']);
        $riskQuery = $riskQuery['query'];

        // Setup Type/Category params
        // @todo: refactor - extract to separate method
        $typeCats = \Xtra::arrayGet($filter, 'typeCats', []);
        $tcQuery  = '';
        foreach ($typeCats as $i => $tc) {
            if (str_starts_with((string) $tc, 'c')) {
                if (!empty($tcQuery)) {
                    $tcQuery .= ',';
                }
                $params[':tc' . $i] = substr((string) $tc, 2, strlen((string) $tc));
                $tcQuery .= ':tc' . $i;
            }
        }
        $tcQuery = (!empty($tcQuery) ? 'AND tpp.tpTypeCategory IN(' . $tcQuery . ')' : '');

        $onlyNew = '';
        $existingProfiles = $this->DB->fetchValueArray(
            "SELECT DISTINCT profileID FROM {$tables['searchTable']}"
        );
        if (\Xtra::arrayGet($filter, 'onlyNew', false) && !empty($existingProfiles)) {
            $onlyNew = "AND tpp.id NOT IN('" . implode("', '", $existingProfiles) . "')";
        }

        $sql = 'SELECT ';
        $chunk = $limit = '';

        if ($count) {
            $sql .= 'COUNT(*) ';
        } else {
            $sql .= 'tpp.id AS profileID, tpp.clientID AS tenantID, ';
            $sql .= 'tpp.legalName AS legalName, tpp.DBAname AS DBAname ';
            $limit = 'ORDER BY profileID ASC LIMIT 100';
            if (!empty($profileID)) {
                $chunk = 'AND tpp.id = :profileID';
                $params[':profileID'] = (int)$profileID;
            } elseif (!empty($lastID)) {
                $chunk = 'AND tpp.id > :lastID';
                $params[':lastID'] = $lastID;
            }
        }

        $sql .= 'FROM ' . $tables['thirdPartyProfile']  . ' AS tpp '
            . 'LEFT JOIN ' . $tables['riskAssessment'] . ' AS ra ON ra.tpID = tpp.id '
            . "WHERE tpp.clientID = :tenantID AND tpp.status <> 'deleted' AND tpp.status <> 'inactive'"
            . ((!empty($riskQuery)) ? " $riskQuery" : '')
            . ((!empty($tcQuery)) ? " $tcQuery" : '')
            . ((!empty($onlyNew)) ? " $onlyNew" : '')
            . ((!empty($chunk)) ? " $chunk" : '')
            . ((!empty($limit)) ? " $limit" : '');
        if ($count) {
            $rtn = $this->DB->fetchValue($sql, $params);
        } else {
            $rtn = $this->DB->fetchAssocRows($sql, $params);
        }
        return $rtn;
    }

    /**
     * Get all TPP's based on search filters.
     *
     * @param integer       $id              ID of the filter to retrieve
     * @param integer       $lastID          ID of the last tpPerson.id that was retrieved
     * @param boolean       $count           True if we only want to return the count
     * @param boolean       $ovrdIncEntities True if you would like the count to include entities
     *                                       regardless of filter setting
      * @param null|integer $profileID       ThirdpartyProfile.id else null
     *
     * @return mixed Either array or integer
     */
    public function getFilterPersons($id, $lastID = 0, $count = false, $ovrdIncEntities = false, $profileID = null)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $lastID = (int)$lastID;
        $params[':id'] = $id;
        $sql    = 'SELECT tenantID, filters FROM ' . $this->tbl . ' WHERE id = :id';
        $result = $this->DB->fetchAssocRow($sql, $params);
        $tenantID = (int)$result['tenantID'];
        $filter   = json_decode((string) $result['filters'], true);

        $clientDB = $this->DB->getClientDB($tenantID);
        $tables   = [
            'tpPerson'          => $this->DB->prependDbName($clientDB, 'tpPerson'),
            'tpPersonMap'       => $this->DB->prependDbName($clientDB, 'tpPersonMap'),
            'thirdPartyProfile' => $this->DB->prependDbName($clientDB, 'thirdPartyProfile'),
            'riskAssessment'    => $this->DB->prependDbName($clientDB, 'riskAssessment'),
            'searchTable'       => $this->DB->prependDbName($clientDB, 'mediaMonSrch'),
        ];

        // Init query params and define tenantID
        $params = [':tenantID' => $tenantID];

        // Setup risk level query if provided
        $riskQuery = $this->getRiskQuery(\Xtra::arrayGet($filter, 'riskLevels', []), $clientDB);
        $params    = array_merge($params, $riskQuery['params']);
        $riskQuery = $riskQuery['query'];

        // Setup Type/Category params
        // @todo: refactor - extract to separate method
        $typeCats = \Xtra::arrayGet($filter, 'typeCats', []);
        $tcQuery  = '';
        foreach ($typeCats as $i => $tc) {
            if (str_starts_with((string) $tc, 'c')) {
                if (!empty($tcQuery)) {
                    $tcQuery .= ',';
                }
                $params[':tc' . $i] = substr((string) $tc, 2, strlen((string) $tc));
                $tcQuery .= ':tc' . $i;
            }
        }
        $tcQuery = (!empty($tcQuery) ? 'AND tpp.tpTypeCategory IN(' . $tcQuery . ')' : '');


        // Setup Associates query/params
        // todo: refactor - extract to separate method
        $associates = \Xtra::arrayGet($filter, 'associates', []);
        $assQuery   = ''; // (a one-in-a-million query, doc!)
        $assMap     = [
            'owner'          => 'bOwner',
            'key_manager'    => 'bKeyMgr',
            'board_member'   => 'bBoardMem',
            'key_consultant' => 'bKeyConsult',
            'employee'       => 'bEmployee',
            'has_government_relationship' => 'bGovRelation',
            'primary_poc' => 'bPrimaryPOC',
            'poc'         => 'bPOC',
        ];
        foreach ($associates as $associate) {
            if (isset($assMap[$associate])) {
                if (!empty($assQuery)) {
                    $assQuery .= ' OR ';
                }
                $assQuery .= ' ' . $assMap[$associate] . ' = 1 ';
            }
        }
        if (!empty($assQuery)) {
            $assQuery = 'AND (' . $assQuery . ')';
        } else {
            if ($count) {
                return 0;
            } else {
                // If no associate types selected none should be returned.
                return [];
            }
        }

        $onlyNew = '';
        if (\Xtra::arrayGet($filter, 'onlyNew', false)) {
            $onlyNew = 'AND tp.id NOT IN (SELECT DISTINCT tpID FROM '
                . $tables['searchTable'] . ' WHERE idType = "person") ';
        }

        $sql = 'SELECT ';
        $chunk = $limit = '';

        if ($count) {
            $sql .= 'COUNT(*) ';
        } else {
            $sql .= 'tp.id AS id, tp.fullName AS term, tp.clientID AS tenantID, '
                . 'tpp.id AS profileID, tp.recType AS recordType ';
            $limit = 'ORDER BY id ASC LIMIT 100';
            if (!empty($profileID)) {
                $chunk = 'AND tpp.id = :profileID';
                $params[':profileID'] = (int)$profileID;
            } elseif (!empty($lastID)) {
                $chunk = 'AND tp.id > :lastID';
                $params[':lastID'] = $lastID;
            }
        }

        $sql .= 'FROM ' . $tables['tpPerson']  . ' AS tp '
            . 'LEFT JOIN ' . $tables['tpPersonMap'] . ' AS tpm ON tpm.personID = tp.id '
            . 'LEFT JOIN ' . $tables['thirdPartyProfile'] . ' AS tpp ON tpp.id = tpm.tpID '
            . 'LEFT JOIN ' . $tables['riskAssessment'] . ' AS ra ON ra.tpID = tpp.id '
            . "WHERE tpp.clientID = :tenantID AND tpp.status <> 'deleted' AND tpp.status <> 'inactive'"
            . ((!$ovrdIncEntities && (isset($filter['includeEntities']) && $filter['includeEntities'] == 0))
                ? ' AND tp.recType = "Person"'
                : '')
            . ((!empty($riskQuery)) ? " $riskQuery" : '')
            . ((!empty($tcQuery)) ? " $tcQuery" : '')
            . " $assQuery"
            . ((!empty($onlyNew)) ? " $onlyNew" : '')
            . ((!empty($chunk)) ? " $chunk" : '')
            . ((!empty($limit)) ? " $limit" : '');
        if ($count) {
            $rtn = $this->DB->fetchValue($sql, $params);
        } else {
            $rtn = $this->DB->fetchAssocRows($sql, $params);
        }

        return $rtn;
    }

    /**
     * Determine whether a tpPerson or Entity is flagged to be included in the GDC screening
     * @todo: Should probably be part of the GDC class and not a function in MediaMonitorFilterData
     *
     * @param integer $clientID  g_tenants.id
     * @param integer $personID  tpPerson.id or thirdPartyProfile.id
     * @param integer $profileID thirdPartyProfile.id
     *
     * @return mixed
     */
    public function gdcIncludeStatus($clientID, $personID, $profileID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $clientDB = $this->DB->getClientDB($clientID);

        $sql = "SELECT bIncludeInGDC FROM {$clientDB}.tpPersonMap WHERE tpID = :tpID "
            . "AND personID = :personID AND clientID = :clientID";
        $params = [
            ':tpID'      => $profileID,
            ':personID'  => $personID,
            ':clientID'  => $clientID
        ];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Build WHERE parameter for risk levels
     *
     * @param array  $riskLevels An array containing risk levels to filter by
     * @param string $clientDB   A string containing client DB name to filter by (Optional)
     *
     * @return array
     */
    private function getRiskQuery($riskLevels, $clientDB = '')
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $query   = '';
        $params  = [];
        $noScore = false;

        foreach ($riskLevels as $i => $risk) {
            if ($risk != 'all' && $risk != 'noscore') {
                if (!empty($query)) {
                    $query .= ',';
                }
                $params[':risk' . $i] = $risk;
                $query .= ':risk' . $i;
            } elseif ($risk == 'noscore') {
                $noScore = true;
            }
        }
        if (!empty($query)) {
            $primaryModelCondition = '';
            if ($clientDB !== '') {
                $riskModelMapTable = $this->DB->prependDbName($clientDB, 'riskModelMap');
                $riskModelRoleTable = $this->DB->prependDbName($clientDB, 'riskModelRole');
                $primaryModelCondition = "AND ra.model = (
                                            SELECT riskModel
                                            FROM {$riskModelMapTable} rmm
                                            INNER JOIN {$riskModelRoleTable} rmr
                                                ON rmm.riskModelRole = rmr.id    
                                            WHERE rmm.clientID = ra.clientID
                                                AND rmm.tpType = tpp.tpType
                                                AND rmm.tpCategory = tpp.tpTypeCategory
                                            ORDER BY rmr.orderNum ASC
                                            LIMIT 1
                                        )";
            }
            $query = 'ra.status = "current" ' . $primaryModelCondition . ' AND ra.tier IN (' . $query . ')';
            if ($noScore) {
                $query = 'AND ((' . $query . ') OR ra.tier IS NULL)';
            } else {
                $query = 'AND ' . $query;
            }
        } else {
            if ($noScore) {
                $query = 'AND ra.tier IS NULL';
            }
        }
        return compact('query', 'params');
    }

    /**
     * Retrieve the threshold settings for the tenant
     *
     * @return array Assoc. array containing Threshold and Relevancy values
     */
    public function getThresholds()
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $clientDB = $this->DB->getClientDB($this->tenantID);
        $table    = $this->DB->prependDbName($clientDB, 'clientProfile');

        $params[':tenantID'] = $this->tenantID;
        $sql = 'SELECT mediaMonThreshold, mediaMonRelevancy ' .
               'FROM ' . $table . ' WHERE id = :tenantID';
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Update the threshold settings for the tenant with new data
     *
     * @param array $data Data to update tenant threshold configuration
     *
     * @return integer Returns the number of rows modified (should be 1 if modified, zero if no changes).
     */
    public function updateThresholds($data)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $original = $this->getThresholds();
        $clientDB = $this->DB->getClientDB($this->tenantID);
        $table    = $this->DB->prependDbName($clientDB, 'clientProfile');
        $sql = "UPDATE {$table} SET mediaMonThreshold = :threshold, mediaMonRelevancy = :relevancy\n"
            . "WHERE id = :tenantID LIMIT 1";
        $params = [
            ':threshold' => $data['threshold'],
            ':relevancy' => $data['relevancy'],
            ':tenantID'  => $this->tenantID
        ];
        $stmt = $this->DB->query($sql, $params);
        $rtn = $stmt->rowCount();
        if ($rtn > 0) {
            $eventID  = 163; // Update Media Monitor Threshold
            $msg = ($original['mediaMonThreshold'] != $data['threshold'])
                ? " Matching ({$original['mediaMonThreshold']}{$this->valDelimiter}{$data['threshold']})"
                : '';
            if ($original['mediaMonRelevancy'] != (int)$data['relevancy']) {
                $msg .= (($original['mediaMonThreshold'] != $data['threshold']) ? ', ' : '')
                    . " Relevancy ({$original['mediaMonRelevancy']}{$this->valDelimiter}{$data['relevancy']})";
            }
            $this->auditLogEntry($eventID, $msg);
        }
        return $rtn;
    }

    /**
     * Get the last Media Monitor search date of a tpPerson, Entity or Profile
     *
     * @param integer $tenantID  g_tenants.id
     * @param integer $profileID thirdPartyProfile.id
     * @param integer $personID  thirdPartyProfile.id or tpPerson.id depending on $recType
     * @param string  $recType
     *
     * @return mixed
     */
    public function lastSearchDate($tenantID, $profileID, $personID, $recType)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $clientDB = $this->DB->getClientDB($tenantID);
        $sql = "SELECT received FROM {$clientDB}.mediaMonSrch WHERE tpID = :tpID AND profileID = :profileID "
            . "AND idType = :idType AND tenantID = :tenantID ORDER BY id DESC LIMIT 1";
        $params = [
            ':tenantID'  => $tenantID,
            ':idType'    => $recType,
            ':tpID'      => $personID,
            ':profileID' => $profileID
        ];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Sets tenantID class property
     *
     * @param integer $tenantID Tenant ID
     *
     * @return void
     */
    public function setFiltersTenant($tenantID)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $tenantID = (int)$tenantID;
        if (!empty($tenantID)) {
            $this->tenantID = $tenantID;
        }
    }

    /**
     * Formats the audit log message for an updated filter's values
     *
     * @param array $orig Field data prior to being updated
     * @param array $new  Field data after being updated
     *
     * @return string
     */
    private function formatUpdatedFilterAuditLogMsg($orig, $new)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = '';
        foreach ($this->filterTypes as $type => $cfg) {
            $typeName = $cfg['name'];
            $typeRemoved = (array_key_exists($type, $orig) && !array_key_exists($type, $new));
            $typeAdded = (!array_key_exists($type, $orig) && array_key_exists($type, $new));
            if ($typeRemoved) {
                // All values for a filter type that was included have been removed
                $rtn .= ((!empty($rtn)) ? ', ' : '')
                    . "{$typeName} REMOVED: "
                    . $this->formatAuditLogFilterMsg($type, $orig[$type], true);
            } elseif ($typeAdded) {
                // A filter type that was not included has values that were added
                $rtn .= ((!empty($rtn)) ? ', ' : '')
                    . "{$typeName} ADDED: "
                    . $this->formatAuditLogFilterMsg($type, $new[$type], true);
            } elseif (($changed = $this->fltrChanged($type, $orig[$type], $new[$type]))
                && ($changed['changed'] === true)
            ) {
                // The filter type remains, and its values have changed
                $rtn .= ((!empty($rtn)) ? ', ' : '');
                if (in_array($type, ['includeEntities', 'onlyNew'])) {
                    // Non-array type
                    $rtn .= $this->formatAuditLogFilterMsg($type, $orig[$type])
                        . $this->valDelimiter
                        . $this->formatAuditLogFilterMsg($type, $new[$type], true);
                } elseif (in_array($type, ['riskLevels', 'typeCats', 'entities', 'associates'])) {
                    // Array type
                    if (!empty($changed['removed'])) {
                        // Items were removed
                        $rtn .= "{$typeName} REMOVED: "
                            . $this->formatAuditLogFilterMsg($type, $changed['removed'], true);
                    }
                    if (!empty($changed['added'])) {
                        // Items were added
                        $rtn .= ((!empty($changed['removed'])) ? ', ' : '')
                            . "{$typeName} ADDED: "
                            . $this->formatAuditLogFilterMsg($type, $changed['added'], true);
                    }
                }
            }
        }
        return $rtn;
    }

    /**
     * Determines if a filter's values have changed, and returns pertinent data depending on type
     *
     * @param string $type Either 'includeEntities', 'onlyNew', 'riskLevels', 'typeCats', 'entities'
     *                     or 'associates'
     * @param array  $orig Original filter value(s)
     * @param array  $new  New filter value(s) to compare to the original
     *
     * @return array Contains changed, added and removed items
     */
    private function fltrChanged($type, $orig, $new)
    {
        $rtn = ['changed' => false, 'added' => [], 'removed' => []];
        if (in_array($type, ['includeEntities', 'onlyNew'])) {
            // Non-array filter types
            $orig = (is_numeric($orig)) ? (int)$orig : $orig;
            $new = (is_numeric($orig)) ? (int)$new : $new;
            $rtn['changed'] = ($orig != $new);
        } elseif (in_array($type, ['riskLevels', 'typeCats', 'entities', 'associates'])) {
            // Array filter types
            $orig = array_map(fn($value) => (is_numeric($value)) ? (int)$value : $value, $orig);
            if (($key = array_search('all', $orig)) !== false) {
                unset($orig[$key]);
            }
            // Remove any occurance of "all" from the orig/new arrays
            $new = array_map(fn($value) => (is_numeric($value)) ? (int)$value : $value, $new);
            if (($key = array_search('all', $new)) !== false) {
                unset($new[$key]);
            }
            $rtn['removed'] = array_diff($orig, $new);
            $rtn['added'] = array_diff($new, $orig);
            $rtn['changed'] = (!empty($rtn['removed']) || !empty($rtn['added']));
        }
        return $rtn;
    }

    /**
     * Format a filter from g_mediaMonSearchFilters for audit log message
     *
     * @param string  $type     Either 'includeEntities', 'onlyNew', 'riskLevels', 'typeCats', 'entities'
     *                          or 'associates'
     * @param mixed   $vals     Filter value(s), either array or string
     * @param boolean $justVals If true, exclude the filter name label and just include its values
     *
     * @return string
     */
    private function formatAuditLogFilterMsg($type, mixed $vals, $justVals = false)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $lkupsCfg = $this->filterTypes[$type];
        $this->debugLog(__FILE__, __LINE__, $lkupsCfg);
        $rtn = '';
        if (in_array($type, ['includeEntities', 'onlyNew'])) {
            // Non-array filter types
            $val = (is_numeric($vals)) ? (int)$vals : $vals;
            if (array_key_exists('cfg', $lkupsCfg) && ($lkupsCfg['cfg'] == 'convert')
                && array_key_exists('conversion', $lkupsCfg)
            ) {
                $val = $lkupsCfg['conversion'][$val];
            }
            $rtn .= $val;
        } elseif (in_array($type, ['riskLevels', 'typeCats', 'entities', 'associates'])) {
            // Array filter types
            $lookups = $this->{$type};
            $this->debugLog(__FILE__, __LINE__, $lookups);
            if ($lkupsCfg['cfg'] == 'nodes' && array_key_exists('nodes', $lkupsCfg)) {
                $nodeItems = [];
                foreach ($lookups as $idx => $lookup) {
                    foreach ($lkupsCfg['nodes'] as $node) {
                        $prefix = $node['lkupPrefix'];
                        $valSrc = $node['valSrc'];
                        $lblSrc = $node['lblSrc'];
                        foreach ($vals as $val) {
                            if ($node['id'] == 'root') {
                                $lookupVal = (is_numeric($val))
                                    ? (int)($prefix . $lookup[$valSrc])
                                    : ($prefix . $lookup[$valSrc]);
                                $lookupLbl = $lookup[$lblSrc];
                                $val = (is_numeric($val)) ? (int)$val : $val;
                                if ($lookupVal === $val) {
                                    $nodeItems[$idx][$node['id']][] = $lookupLbl;
                                }
                            } else {
                                foreach ($lookup[$node['id']] as $nonRootLkup) {
                                    $lookupVal = (is_numeric($val))
                                        ? (int)($prefix . $nonRootLkup[$valSrc])
                                        : ($prefix . $nonRootLkup[$valSrc]);
                                    $lookupLbl = $nonRootLkup[$lblSrc];
                                    $val = (is_numeric($val)) ? (int)$val : $val;
                                    if ($lookupVal === $val) {
                                        $nodeItems[$idx][$node['id']][] = $lookupLbl;
                                    }
                                }
                            }
                        }
                    }
                }
                $this->debugLog(__FILE__, __LINE__, $nodeItems);
                if (!empty($nodeItems)) {
                    $rootItem = '';
                    foreach ($nodeItems as $lookupIdx => $nodeItem) {
                        $rtn .= (!empty($rtn)) ? '; ' : '';
                        foreach ($lkupsCfg['nodes'] as $node) {
                            if ($node['id'] == 'root') {
                                if (isset($nodeItem[$node['id']])) {
                                    $rootItem = implode(', ', $nodeItem[$node['id']]);
                                } else {
                                    // Grab the rootItem name from the lookup
                                    $rootItem = $lookups[$lookupIdx]['name'];
                                }
                                $rtn .= $node['name'] . ": $rootItem";
                            } elseif (isset($nodeItem[$node['id']])) {
                                $rtn .= ", $rootItem " . $node['name'] . ': '
                                    . implode(', ', $nodeItem[$node['id']]);
                            }
                        }
                    }
                }
            } else {
                $items = array_map(function ($val) use ($lookups, $lkupsCfg) {
                    $rtn = $val;
                    foreach ($lookups as $idx => $lookup) {
                        if ($lkupsCfg['cfg'] == 'mapping') {
                            $lookupVal = (is_numeric($val))
                                ? (int)$lookup[$lkupsCfg['mapping']['valSrc']]
                                : $lookup[$lkupsCfg['mapping']['valSrc']];
                            $lookupLbl = $lookup[$lkupsCfg['mapping']['lblSrc']];
                        } else {
                            $lookupVal = (is_numeric($val)) ? (int)$idx : $idx;
                            $lookupLbl = $lookup;
                        }
                        $val = (is_numeric($val)) ? (int)$val : $val;
                        if ($lookupVal === $val) {
                            $rtn = $lookupLbl;
                            break;
                        }
                    }
                    return $rtn;
                }, $vals);
                $items = array_diff($items, ['all']);
                $rtn .= implode(', ', $items);
            }
            $rtn = "[{$rtn}]";
            $this->debugLog(__FILE__, __LINE__, $rtn);
        }
        $rtn = ($justVals) ? $rtn : $lkupsCfg['name'] . ": $rtn";
        return $rtn;
    }

    /**
     * Formats data in g_mediaMonSearchFilters.filters for audit log message
     *
     * @param string $filters g_mediaMonSearchFilters.filters
     *
     * @return string
     */
    private function formatAuditLogFiltersMsg($filters)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        $rtn = '';
        $filters = json_decode($filters, true);
        $this->debugLog(__FILE__, __LINE__, $filters);
        foreach ($filters as $type => $vals) {
            $rtn .= ((!empty($rtn)) ? ', ' : '')
                . $this->formatAuditLogFilterMsg($type, $vals);
        }
        $this->debugLog(__FILE__, __LINE__, $rtn);
        return $rtn;
    }

    /**
     * Create Media Monitor filter audit log entry
     *
     * @param integer $eventID g_userLogEvents.id
     * @param string  $msg     userLog.details
     */
    private function auditLogEntry($eventID, $msg)
    {
        $this->debugLog(__FILE__, __LINE__, '', __METHOD__, func_get_args());
        (new LogData($this->tenantID, $this->app->ftr->user))->saveLogEntry(
            $eventID,
            $msg,
            null,
            false,
            $this->tenantID
        );
    }

    /**
     * Media Monitor Debugger
     *
     * @param string  $file   File name
     * @param integer $line   Line number
     * @param mixed   $msg    Message to log (object|array|string|null)
     * @param string  $method Method name
     * @param array   $args   Method arguments
     *
     * @return void
     */
    public function debugLog($file, $line, mixed $msg = '', $method = '', $args = [])
    {
        if ($this->app->confValues['cms']['mmDebug'] == 'off' || empty($file) || empty($line)) {
            return;
        }
        $logMsg = "~~~~~~~~~~~~~~>" . PHP_EOL
            . "~~~~~~~~~ MediaMonitor DELTA deBuggerON ~~~~~~~~~>" . PHP_EOL
            . "File: $file, Line: $line";
        if (!empty($method)) {
            $logMsg .= ", Method: $method";
            if (!empty($args)) {
                $logMsg .= PHP_EOL . "Args: ";
                foreach ($args as $idx => $arg) {
                    $logMsg .= (($idx > 0) ? ', ' : '') . print_r($arg, true);
                }
            }
        }
        if (!empty($msg)) {
            $logMsg .= PHP_EOL . print_r($msg, true);
        }
        $logMsg .= PHP_EOL . "<~~~~~~~~~ MediaMonitor DELTA deBuggerOFF ~~~~~~~~"
            . PHP_EOL . "<~~~~~~~~~";

        \Xtra::app()->log->info($logMsg);
    }

    /**
     * Get the Global Refinement Term for the current Tenant if one exists
     *
     * @param integer $tenantID g_tenants.id
     *
     * @return mixed
     */
    public function getGlobalRefinementTerm($tenantID = null)
    {
        $tenantID ??= $this->tenantID;
        return $this->DB->fetchValue(
            "SELECT refinement FROM {$this->DB->globalDB}.g_mediaMonGlobalRefinements WHERE tenantID = :tenantID",
            [':tenantID' => $tenantID]
        );
    }

    /**
     * Set the Global Refinement Term for a Tenant
     *
     * @param integer $tenantID g_tenants.id
     * @param string  $term     desired global refinement term such as 'corruption' or 'bribery'
     *
     * @return boolean
     */
    public function setGlobalRefinementTerm($term, $tenantID = null)
    {
        $tenantID ??= $this->tenantID;
        $sql = "INSERT INTO {$this->DB->globalDB}.g_mediaMonGlobalRefinements (tenantID, term) "
            . "VALUES (:tenantID, :term)";
        return ($this->DB->query($sql, [':tenantID' => $tenantID, ':term' => $term]));
    }

    /**
     * Indexes the globalRefinements property with Tenant details for the MM global refinement feature
     *
     * @param integer $tenantID g_tenants.id
     *
     * @return void
     */
    public function indexGlobalRefinementsProperty($tenantID)
    {
        // Check if the Tenant exists in the array and if they have the MM global refinement feature enabled.
        if (isset($tenantID) && !array_key_exists($tenantID, $this->globalRefinements)) {
            $ftrs = new TenantFeatures($tenantID);
            $this->globalRefinements[$tenantID]['enabled'] = $ftrs->tenantHasFeature(
                \Feature::TENANT_MEDIA_MONITOR_GLOBAL_REFINEMENT,
                \Feature::APP_TPM
            );
            // If the Tenant is enabled, look up the global refinement term.
            $this->globalRefinements[$tenantID]['term'] = ($this->globalRefinements[$tenantID]['enabled'])
                ? $this->getGlobalRefinementTerm($tenantID)
                : null;
        }
    }
}
