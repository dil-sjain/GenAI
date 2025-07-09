<?php
/**
 * Model: delivers data for Audit Logs
 *
 * @keywords AuditLogData, data, audit log
 */

namespace Models\TPM\Settings\AuditLog;

use Lib\Csv;
use Lib\CsvIO;
use Lib\Database\MySqlPdo;
use Lib\DateTimeEx;
use Lib\FeatureACL;
use Models\Logging\AuditLogSql;
use Models\Pagination\IndexedPagination;
use Models\User;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class AuditLogData
{
    protected const MINIMUM_ROWS_PER_PAGE = 15;
    protected const MAXIMUM_ROWS_PER_PAGE = 100;
    public const CSV_ROW_LIMIT = 10000;

    /**
     * @var \Skinny\Skinny|null Application framework instance
     */
    private $app = null;

    /**
     * @var MySqlPdo|null Class instance
     */
    private $DB = null;

    /**
     * @var null|int users.id of logged in user
     */
    private $authUserID = null;

    /**
     * @var int|null TPM tenant ID
     */
    private $clientID = null;

    /**
     * @var string|null SQL for filtered audit log records
     */
    private $auditLogSql = null;

    /**
     * @var int|null g_roles.id of logged-in user
     */
    private $userRoleID = null;

    /**
     * @var string|null Name of user role
     */
    private $userRoleName = null;

    /**
     * @var int|null Audit Log context
     */
    private $userLogContextId = null;

    /**
     * @var object|null logger class instance
     */
    private $logger = null;

    /**
     * @var bool int/bool If true output is csv
     */
    private $isCsv = false;

    /**
     * @var bool|int If true log progress
     */
    private $benchmarks = false;

    /**
     * @var int|null UNIX timestamp
     */
    private $timestamp = null;

    /**
     * Constructor - initialization
     *
     * @param int $clientID Client ID
     *
     * @throws \Exception
     */
    public function __construct($clientID = 0)
    {
        $clientID = (int)$clientID;
        if ($clientID <= 0) {
            throw new \Exception('Invalid clientID');
        }
        $this->app = \Xtra::app();
        $this->logger = \Xtra::app()->log;
        $this->authUserID = $this->app->session->authUserID;
        $featureACL = new FeatureACL($this->authUserID);
        if (!($featureACL->has($featureACL::SETTINGS_AUDIT_LOG))) {
            throw new \Exception('Access Denied');
        }
        $this->DB = $this->app->DB;
        $this->clientID = $clientID;
        $this->auditLogSql = new AuditLogSql();
        $user = (new User())->findById($this->authUserID);
        $userType = $user->get('userType');
        $userRole = $this->auditLogSql->getUserRole($userType);
        $this->userRoleID = $userRole['id'];
        $this->userRoleName = $userRole['name'];
        $this->userLogContextId = $this->auditLogSql->getContextID('fullLog');
    }


    /**
     * Initializes Audit Log data
     *
     * @param array $filters filters that will affect results of data set
     *
     * @return array
     */
    public function init($filters)
    {
        $memory = memory_get_usage(true);
        $start = microtime(true);
        $config = $this->setConfig($filters);
        if ($this->isCSV) {
            // Limit export size
            $records = $this->app->session->get("stickySettings.auditLog.total") ?? 0;
            if ($records === 0 || $records > self::CSV_ROW_LIMIT) {
                $message = $records === 0
                    ? "There are no records to export."
                    : "Export is limited to " . number_format(self::CSV_ROW_LIMIT, 0)
                        . " records. Adjust filters to reduce size of export.";
                echo "<html><body><h3>Invalid Export Request</h3>$message</body></html>";
                exit;
            } else {
                $rtn = $this->streamCsv($config['paginationConfig']);
            }
        } else {
            $rtn = $this->fetchData($config['paginationConfig']);
            $rtn->stickyConfig = $config['stickyConfig'];
        }
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('init', $memoryUsage, $start);
        }
        return $rtn;
    }



    /**
     * Fetches Audit Log data
     *
     * @param array   $paginationConfig configuration for pagination
     * @param boolean $forCSV           If true, return rows as multi-dimensional array, else object array
     *
     * @return object
     */
    private function fetchData($paginationConfig, $forCSV = false)
    {
        $memory = memory_get_usage(true);
        $start = microtime(true);
        $paginatedData = (new IndexedPagination($paginationConfig))->data;
        $needThreshConfirmed = (int)$paginatedData->needThreshConfirmed;
        $auditLogIdxTblCnt = (int)$paginatedData->indexTableCount;
        $rows = [];
        if (($auditLogIdxTblCnt > 0) && $needThreshConfirmed <= 0) {
            $sqlConfig = ['isCSV' => $this->isCsv, 'sortID' => $paginatedData->pageSortID, 'howMany' => $paginatedData->limit, 'idxTbl' => $paginatedData->indexTable, 'logTbl' => $paginationConfig['pagTbl'], 'logTblDb' => $paginationConfig['pagTblDb'], 'clientDB' => $this->DB->getClientDB($this->clientID), 'contextID' => $this->userLogContextId, 'userRoleID' => $this->userRoleID, 'userRoleName' => $this->userRoleName];
            $auditLogSQL = $this->auditLogSql->fullAuditLogSQL($sqlConfig);
            if (!empty($auditLogSQL) && isset($auditLogSQL['sql']) && isset($auditLogSQL['params'])) {
                $rows = ($forCSV)
                    ? $this->DB->fetchAssocRows($auditLogSQL['sql'], $auditLogSQL['params'])
                    : $this->DB->fetchObjectRows($auditLogSQL['sql'], $auditLogSQL['params']);
                if ($paginationConfig['sortDir'] == 'DESC') {
                    $rows = array_reverse($rows);
                }
            }
        }
        $rtn = (object)null;
        $rtn->rows = $rows;
        $rtn->startingIndex = $paginatedData->startingIndex;
        $rtn->total = (int)$paginatedData->indexTableCount;
        $rtn->page = $paginatedData->currentPage;
        $rtn->totalPages = $paginatedData->totalPages;
        $rtn->rowsPerPage = $paginationConfig['rowsPerPage'];
        $rtn->timestamp = $this->timestamp;
        $rtn->needThreshConfirmed = $paginatedData->needThreshConfirmed;
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('fetchData', $memoryUsage, $start);
        }
        return $rtn;
    }



    /**
     * Returns an array of events' id/name pairs specific to a user role.
     *
     * @return array includes id/name pairs
     */
    public function fetchEvents()
    {
        $memory = memory_get_usage(true);
        $start = microtime(true);
        $globalDb = $this->DB->globalDB;
        $baseJoins = $this->auditLogSql->eventFilterJoins('e');
        $baseWhere = $this->auditLogSql->eventFilterWhere($this->userLogContextId, $this->userRoleID);

        // Populate with Context and Viewer Role
        $sql = "SELECT e.id AS id, e.event AS name "
            . "FROM {$globalDb}.g_userLogEvents AS e\n"
            . $baseJoins
            . $baseWhere['sql']
            . "GROUP BY e.event ORDER BY e.event ASC";
        $events = $this->DB->fetchAssocRows($sql, $baseWhere['params']);
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('fetchEvents', $memoryUsage, $start);
        }
        return $events;
    }


    /**
     * Retrieve UserLogEvents for the API
     *
     * @return void
     */
    public function getUserLogEvents()
    {
        // Populate with Context and Viewer Role
        $sql = "SELECT id AS id, event AS name "
            . "FROM {$this->DB->globalDB}.g_userLogEvents";
        return $this->DB->fetchAssocRows($sql, []);
    }


    /**
     * If Benchmarks are turned on, log them (memory usage and time elapsed).
     *
     * @param string    $method      name of method
     * @param integer   $memoryUsage memory usage of method
     * @param microtime $startTime   microtime(true) when start was marked
     *
     * @return string message
     */
    private function logBenchmarks($method, $memoryUsage, $startTime)
    {
        DateTimeEx::logRuntime($startTime, "AuditLogData::$method");
        $this->logger->debug("Memory used: $memoryUsage bytes");
    }



    /**
     * Configuration settings for gathering Audit Log data
     *
     * @param array $filters filters that will change results of data set
     *
     * @return array
     */
    private function setConfig($filters)
    {
        $memory = memory_get_usage(true);
        $start = microtime(true);
        $this->benchmarks = (array_key_exists('benchmarks', $filters) && $filters['benchmarks'] > 0)
            ? 1
            : 0;
        if ($this->benchmarks == 1) {
            $this->logger = \Xtra::app()->log;
        }

        $stickyConfig = [];
        $where = "WHERE ul.batchID IS NULL AND ul.clientID = :clientID";
        $whereParams[':clientID'] = $this->clientID;

        $indexingConfirmed = (array_key_exists('indexingConfirmed', $filters)
            && ((int)$filters['indexingConfirmed'] > 0)
        )
            ? 1
            : 0;

        $freshData = (array_key_exists('freshData', $filters) && ((int)$filters['freshData'] > 0))
            ? 1
            : 0;

        $this->timestamp = (array_key_exists('timestamp', $filters) && !empty($filters['timestamp']))
            ? $filters['timestamp']
            : time();

        $startDate = (array_key_exists('startDate', $filters) && !empty($filters['startDate']))
            ? $filters['startDate']
            : '';
        $endDate = (array_key_exists('endDate', $filters) && !empty($filters['endDate']))
            ? $filters['endDate']
            : '';
        $blankDateTimes = (empty($startDate) && empty($endDate)) ? true : false;
        $startDate = (!$blankDateTimes && empty($startDate))
            ? '0000-00-00'
            : $startDate;
        $endDate = (!$blankDateTimes && empty($endDate))
            ? date('Y-m-d', $this->timestamp)
            : $endDate;
        if ($startDate > $endDate) {
            $tmp = $startDate;
            $startDate = $endDate;
            $endDate = $tmp;
        }

        $stickyConfig['auditLogStartDate'] = $startDate;
        $stickyConfig['auditLogEndDate'] = $endDate;

        if ($freshData == 1) {
            // A filter event was called or a new column is being sorted
            // Grab a new endDate timestamp to make sure the data is fresh.
            // A pagination event or an active column changing direction
            // retains the original timestamp to keep the data stale.
            $this->timestamp = time();
        }

        $stickyConfig['auditLogTimestamp'] = $this->timestamp;

        $endDate .= ' ' . ((date('Y-m-d', $this->timestamp) == $endDate)
            ? date('H:i:s', $this->timestamp)
            : "23:59:59"
        );
        if (!$blankDateTimes) {
            $where .= " AND ul.tstamp BETWEEN :startDate AND :endDate";
            $whereParams[':startDate'] = $startDate . ' 00:00:00';
            $whereParams[':endDate'] = $endDate;
        }

        $stickyConfig['auditLogMyEvents'] = 0;
        if (array_key_exists('myEventsOnly', $filters) && $filters['myEventsOnly'] > 0) {
            $stickyConfig['auditLogMyEvents'] = 1;
            $where .= " AND ul.userID = :authUserID";
            $whereParams[':authUserID'] = $this->authUserID;
        }
        $cntJoins = '';
        $stickyConfig['auditLogCaseNumbers'] = '';
        if (array_key_exists('caseNumbers', $filters) && !empty($filters['caseNumbers'])) {
            $caseNumbers = $filters['caseNumbers'];
            $stickyConfig['auditLogCaseNumbers'] = $caseNumbers;
            $srch = ['%', '\%'];
            $rplc = ['_', '\_'];
            $cnum = '%' . str_replace($srch, $rplc, (string) $caseNumbers) . '%';
            $where .= " AND (c.userCaseNum LIKE :userCaseNum OR t.userTpNum LIKE :userTpNum)";
            $whereParams[':userCaseNum'] = $whereParams[':userTpNum'] = $cnum;
            $cntJoins = "LEFT JOIN cases AS c ON c.id = ul.caseID\n"
                . "LEFT JOIN thirdPartyProfile AS t ON t.id = ul.tpID\n";
        }

        $filteredEvents = [1,2];
        if (array_key_exists('filteredEvents', $filters) && !empty($filters['filteredEvents'])) {
            $filteredEvents = array_map('intval', $filters['filteredEvents']);
        }
        $stickyConfig['auditLogFilteredEvents'] = $filteredEvents;
        $where .= ' AND ul.eventID IN(' . implode(',', $filteredEvents) . ')';

        $this->isCSV = (array_key_exists('isCSV', $filters) && isset($filters['isCSV'])) ? 1 : 0;
        if ($this->isCSV) {
            $startingIdx = 0;
            $rowsPerPage = self::MAXIMUM_ROWS_PER_PAGE;
        } else {
            $startingIdx = (array_key_exists('startingIdx', $filters) && isset($filters['startingIdx']))
                ? (int)$filters['startingIdx']
                : 0;
            $stickyConfig['auditLogStartingIdx'] = $startingIdx;
            $rowsPerPage = (array_key_exists('rowsPerPage', $filters) && isset($filters['rowsPerPage'])
                && $filters['rowsPerPage'] > self::MINIMUM_ROWS_PER_PAGE
            )
                ? (int)$filters['rowsPerPage']
                : self::MINIMUM_ROWS_PER_PAGE;
            $rowsPerPage = ($rowsPerPage > self::MAXIMUM_ROWS_PER_PAGE)
                ? self::MAXIMUM_ROWS_PER_PAGE
                : $rowsPerPage;
            $stickyConfig['auditLogRowsPerPage'] = $rowsPerPage;
        }
        $sortDirection = (array_key_exists('sortDirection', $filters) && isset($filters['sortDirection'])
            && $filters['sortDirection'] == 'desc'
        )
            ? 'DESC'
            : 'ASC';
        $stickyConfig['auditLogOrderDir'] = strtolower($sortDirection);
        $sortAlias = (array_key_exists('sortAlias', $filters) && isset($filters['sortAlias'])
            && in_array($filters['sortAlias'], ['date','event','user'])
        )
            ? $filters['sortAlias']
            : 'date';
        $sortCols = ['date' => 0, 'event' => 1, 'user' => 2];
        $stickyConfig['auditLogOrderCol'] = $sortCols[$sortAlias];

        $idxTbl = (array_key_exists('idxTbl', $filters) && isset($filters['idxTbl']))
            ? $filters['idxTbl']
            : '';

        $idxTblCnt = (array_key_exists('idxTblCnt', $filters) && (int)$filters['idxTblCnt'] > 0)
            ? (int)$filters['idxTblCnt']
            : 0;

        $idxTblCreated = (array_key_exists('idxTblCreated', $filters) && isset($filters['idxTblCreated']))
            ? $filters['idxTblCreated']
            : false;

        $sortCfg = $this->auditLogSql->configSort(
            [
            'sortAlias' => $sortAlias,
            'logTblDb' => 'ul',
            'sortDir' => 'ASC',
            'dtDir' => 'ASC',
            'isFullLog' => true,
            'caseNumJoin' => $cntJoins,
            ]
        );

        $joins = $sortCfg['joins'];
        $sortCol = $sortCfg['sortCol'];
        $orderBy = $sortCfg['orderBy'];

        if ($this->isCSV && (array_key_exists('sortCol', $filters) && isset($filters['sortCol']))) {
            $sortCol = $filters['sortCol'];
        } else {
            $stickyConfig['auditLogSortCol'] = $sortCol;
        }

        $paginationConfig = ['clientID' => $this->clientID, 'pagTbl' => 'userLog', 'pagTblDb' => 'ul', 'sortCol' => $sortCol, 'where' => $where, 'whereParams' => $whereParams, 'sortDir' => $sortDirection, 'rowsPerPage' => $rowsPerPage, 'startingIdx' => $startingIdx, 'freshData' => $freshData, 'indexingConfirmed' => $indexingConfirmed, 'benchmarks' => $this->benchmarks, 'idxTbl' => $idxTbl, 'idxTblCnt' => $idxTblCnt, 'idxTblCreated' => $idxTblCreated, 'orderBy' => $orderBy, 'joins' => $joins, 'cntJoins' => $cntJoins];
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('setConfig', $memoryUsage, $start);
        }
        return ['paginationConfig' => $paginationConfig, 'stickyConfig' => $stickyConfig];
    }



    /**
     * Streams Audit Log Csv
     *
     * @param array $paginationConfig configuration for pagination
     *
     * @return void
     */
    private function streamCsv($paginationConfig)
    {
        $memory = memory_get_usage(true);
        $start = microtime(true);
        $currentPage = $totalPages = 1;
        $csvHeader = ['Date', 'Event', 'User', 'Reference', 'Details'];

        // set HTTP headers, fixFilename adds '.csv' to filename
        $fileName = 'AuditLog_' . $this->authUserID . '_' . date('Y-m-d_H-i');
        $io = new CsvIO(false);
        $io->setForCsv($fileName);
        echo Csv::std($csvHeader);

        $markTime = time();
        while ($currentPage <= $totalPages) {
            $dataObj = $this->fetchData($paginationConfig, true);
            foreach ($dataObj->rows as $row) {
                echo Csv::std($row);
                if ((time() - $markTime) >= 12) {
                    set_time_limit(15);
                    $markTime = time();
                }
            }
            // Advance the current page in order to progress through the records
            $currentPage = intval($dataObj->page + 1);
            $totalPages = intval($dataObj->totalPages);
            $paginationConfig['startingIdx'] += $paginationConfig['rowsPerPage'];
        }
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('streamCsv', $memoryUsage, $start);
        }
        exit;
    }
}
