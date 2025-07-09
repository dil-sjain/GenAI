<?php
/**
 * Model: delivers data for Audit Logs
 *
 * @keywords EmailLogData, data, audit log
 */

namespace Models\TPM\Settings\EmailLog;

use Lib\Csv;
use Lib\CsvIO;
use Lib\DateTimeEx;
use Lib\FeatureACL;
use Models\Logging\EmailLogSql;
use Models\User;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class EmailLogData
{
    private $app = null;
    private $DB = null;
    private $authUserID = null;
    private $clientID = null;
    private $emailLogSql = null;
    private $userRoleID = null;
    private $userRoleName = null;
    private $userLogContextId = null;
    private $logger = null;
    private $isCsv = false;
    private $benchmarks = false;
    private $timestamp = null;
    private $dateFormat = "M j 'y h:i A";

    public const MINIMUM_ROWS_PER_PAGE = 15;
    public const MAXIMUM_ROWS_PER_PAGE = 100;

    /**
     * Constructor - initialization
     *
     * @param int $clientID Client ID
     * @throws \Exception
     * @return void
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
        if (!$this->app->ftr->has(FeatureACL::SETTINGS_EMAIL_LOG)) {
            throw new \Exception('Access Denied');
        }
        $this->DB = $this->app->DB;
        $this->clientID = $clientID;
        $this->emailLogSql = new EmailLogSql();
        $user = (new User)->findById($this->authUserID);
        $userType = $user->get('userType');
        $userRole = $this->emailLogSql->getUserRole($userType);
        $this->userRoleID = $userRole['id']; // should be $this->app->ftr->role
        $this->userRoleName = $userRole['name']; // should be SELECT name from g_roles WHERE id= $this->userRoleID
    }


    /**
     * Initializes Email Log data
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
            $rtn = $this->streamCsv($config['paginationConfig']);
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
     * Fetches Email Log data
     *
     * @param array $paginationConfig configuration for pagination
     *
     * @return object
     */
    private function fetchData($paginationConfig)
    {
        $memory = memory_get_usage(true);
        $start = microtime(true);
        $rows = [];
        $sqlConfig = ['isCSV' => $this->isCsv, 'howMany' => $paginationConfig['rowsPerPage'], 'clientDB' => $this->DB->getClientDB($this->clientID), 'contextID' => $this->userLogContextId, 'userRoleID' => $this->userRoleID, 'userRoleName' => $this->userRoleName, 'startingIdx' => intval($paginationConfig['startingIdx']), 'orderBy' => $paginationConfig['sortDir'], 'caseNumbers' => $_POST['caseNumbers'], 'startDate' => $_POST['startDate'], 'endDate' => $_POST['endDate']];
        $countQuery = $this->emailLogSql->fullEmailLogSQL($sqlConfig, true);
        $emailLogIdxTblCnt = $this->DB->fetchValue($countQuery['sql'], $countQuery['params']);
        if ($emailLogIdxTblCnt > 0) {
            $emailLogSQL = $this->emailLogSql->fullEmailLogSQL($sqlConfig);
            if (!empty($emailLogSQL) && isset($emailLogSQL['sql']) && isset($emailLogSQL['params'])) {
                $rows = $this->DB->fetchAssocRows($emailLogSQL['sql'], $emailLogSQL['params']);
            }
        }

        foreach ($rows as &$row) {
            $row['dt'] = date($this->dateFormat, strtotime((string) $row['dt']));
            //Cleanup "From:" string from sender field
            if (strstr((string) $row['sn'], 'From:')) {
                $row['sn'] = str_replace('From:', '', (string) $row['sn']);
                $row['sn'] = trim($row['sn']);
            }

            //Add CC info to recipient field
            if (strlen((string) $row['cc']) > 0) {
                $row['cc'] .= "<br><i> cc: " . $row['cc'] . "</i>";
            }
        }

        unset($row);

        $page = floor($paginationConfig['startingIdx'] / $paginationConfig['rowsPerPage']) + 1;
        $totalPages = ceil($emailLogIdxTblCnt / $paginationConfig['rowsPerPage']);

        $rtn = (object)null;
        $rtn->rows = $rows;
        $rtn->startingIndex = $paginationConfig['startingIdx'];
        $rtn->total = $emailLogIdxTblCnt;
        $rtn->page = $page;
        $rtn->totalPages = $totalPages;
        $rtn->rowsPerPage = $paginationConfig['rowsPerPage'];
        $rtn->timestamp = $this->timestamp;
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
        $baseJoins = $this->emailLogSql->eventFilterJoins('e');
        $baseWhere = $this->emailLogSql->eventFilterWhere($this->userLogContextId, $this->userRoleID);

        // Populate with Context and Viewer Role
        $sql = "SELECT e.id AS id, e.event AS name "
            . "FROM {$globalDb}.g_userLogEvents AS e\n"
            . $baseJoins
            . $baseWhere['sql']
            ."GROUP BY e.event ORDER BY e.event ASC";
        $events = $this->DB->fetchAssocRows($sql, $baseWhere['params']);
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('fetchEvents', $memoryUsage, $start);
        }
        return $events;
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
        DateTimeEx::logRuntime($startTime, "EmailLogData::$method");
        $this->logger->debug("Memory used: $memoryUsage bytes");
    }



    /**
     * Configuration settings for gathering Email Log data
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

        $stickyConfig['emailLogStartDate'] = $startDate;
        $stickyConfig['emailLogEndDate'] = $endDate;

        if ($freshData == 1) {
            // A filter event was called or a new column is being sorted
            // Grab a new endDate timestamp to make sure the data is fresh.
            // A pagination event or an active column changing direction
            // retains the original timestamp to keep the data stale.
            $this->timestamp = time();
        }

        $stickyConfig['emailLogTimestamp'] = $this->timestamp;

        $endDate .= ' '. ((date('Y-m-d', $this->timestamp) == $endDate)
            ? date('H:i:s', $this->timestamp)
            : "23:59:59"
        );
        if (!$blankDateTimes) {
            $where .= " AND ul.tstamp BETWEEN :startDate AND :endDate";
            $whereParams[':startDate'] = $startDate . ' 00:00:00';
            $whereParams[':endDate'] = $endDate;
        }

        $cntJoins = '';
        $stickyConfig['emailLogCaseNumbers'] = '';
        if (array_key_exists('caseNumbers', $filters) && !empty($filters['caseNumbers'])) {
            $caseNumbers = $filters['caseNumbers'];
            $stickyConfig['emailLogCaseNumbers'] = $caseNumbers;
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
        $stickyConfig['emailLogFilteredEvents'] = $filteredEvents;
        $where .= ' AND ul.eventID IN(' . implode(',', $filteredEvents) . ')';

        $this->isCSV = (array_key_exists('isCSV', $filters) && isset($filters['isCSV'])) ? 1 : 0;
        if ($this->isCSV) {
            $startingIdx = 0;
            $rowsPerPage = self::MAXIMUM_ROWS_PER_PAGE;
        } else {
            $startingIdx = (array_key_exists('startingIdx', $filters) && isset($filters['startingIdx']))
                ? (int)$filters['startingIdx']
                : 0;
            $stickyConfig['emailLogStartingIdx'] = $startingIdx;
            $rowsPerPage = (array_key_exists('rowsPerPage', $filters) && isset($filters['rowsPerPage'])
                && $filters['rowsPerPage'] > self::MINIMUM_ROWS_PER_PAGE
            )
                ? (int)$filters['rowsPerPage']
                : self::MINIMUM_ROWS_PER_PAGE;
            $rowsPerPage = ($rowsPerPage > self::MAXIMUM_ROWS_PER_PAGE)
                ? self::MAXIMUM_ROWS_PER_PAGE
                : $rowsPerPage;
            $stickyConfig['emailLogRowsPerPage'] = $rowsPerPage;
        }
        $sortDirection = (array_key_exists('sortDirection', $filters) && isset($filters['sortDirection'])
            && $filters['sortDirection'] == 'desc'
        )
            ? 'DESC'
            : 'ASC';
        $stickyConfig['emailLogOrderDir'] = strtolower($sortDirection);
        $sortAlias = (array_key_exists('sortAlias', $filters) && isset($filters['sortAlias'])
            && in_array($filters['sortAlias'], ['date','event','user'])
        )
            ? $filters['sortAlias']
            : 'date';
        $sortCols = ['date' => 0, 'event' => 1, 'user' => 2];
        $stickyConfig['emailLogOrderCol'] = $sortCols[$sortAlias];

        $idxTbl = (array_key_exists('idxTbl', $filters) && isset($filters['idxTbl']))
            ? $filters['idxTbl']
            : '';

        $idxTblCnt = (array_key_exists('idxTblCnt', $filters) && (int)$filters['idxTblCnt'] > 0)
            ? (int)$filters['idxTblCnt']
            : 0;

        $idxTblCreated = (array_key_exists('idxTblCreated', $filters) && isset($filters['idxTblCreated']))
            ? $filters['idxTblCreated']
            : false;

        $sortCfg = $this->emailLogSql->configSort(
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
            $stickyConfig['emailLogSortCol'] = $sortCol;
        }

        $paginationConfig = ['clientID' => $this->clientID, 'pagTbl' => 'userLog', 'pagTblDb' => 'ul', 'sortCol' => $sortCol, 'where' => $where, 'whereParams' => $whereParams, 'sortDir' => $sortDirection, 'rowsPerPage' => $rowsPerPage, 'startingIdx' => $startingIdx, 'freshData' => $freshData, 'indexingConfirmed' => $indexingConfirmed, 'benchmarks' => $this->benchmarks, 'idxTbl' => $idxTbl, 'idxTblCnt' => $idxTblCnt, 'idxTblCreated' => $idxTblCreated, 'orderBy' => $orderBy, 'joins' => $joins, 'cntJoins' => $cntJoins];
        $memoryUsage = (memory_get_usage(true) - $memory);
        if ($this->benchmarks) {
            $this->logBenchmarks('setConfig', $memoryUsage, $start);
        }
        return ['paginationConfig' => $paginationConfig, 'stickyConfig' => $stickyConfig];
    }



    /**
     * Streams Email Log Csv
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
        $csvHeader = ['Date', 'Sender', 'Receiver', 'CC', 'Subject', 'Reference'];

        // set HTTP headers
        $fileName = 'EmailLog_' . $this->authUserID . '_' . date('Y-m-d_H-i') . '.csv';
        $isIE = (str_contains(strtoupper((string) \Xtra::app()->environment['HTTP_USER_AGENT']), 'MSIE'))
            ? true
            : false;
        $io = new CsvIO($isIE);
        $io->sendExcelHeaders($fileName);
        echo mb_convert_encoding(
            Csv::make($csvHeader, 'excel', true, true, false, true),
            'UTF-16LE',
            'UTF-8'
        );

        $markTime = time();
        while ($currentPage <= $totalPages) {
            $dataObj = $this->fetchData($paginationConfig);
            foreach ($dataObj->rows as $row) {
                echo mb_convert_encoding(
                    Csv::make($row, 'excel', true, true, false, true),
                    'UTF-16LE',
                    'UTF-8'
                );
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
