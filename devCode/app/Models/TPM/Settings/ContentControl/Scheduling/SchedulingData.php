<?php
/**
 * Model: check if any scheduling are available for subscriber
 *
 * @keywords SchedulingData, scheduling, data
 */

namespace Models\TPM\Settings\ContentControl\Scheduling;

use Xtra;
use Lib\AppCron;
use Models\Globals\Features\TenantFeatures;
use Controllers\Widgets;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class SchedulingData
{
    private $DB = null;
    private $app = null;

    public const SCHEDPROC_3P_MONITOR = 1;
    public const SCHED_PROC_3PMONITOR = 1;

    /**
     * Constructor - initialization
     *
     * @return void
     */
    public function __construct()
    {
        $this->app = Xtra::app();
        $this->DB = $this->app->DB;
    }

    /**
     * Display any Scheduled Automated Process
     *
     * @param integer $clientID client id
     *
     * @return Array of scheduling data
     */
    public function displaySchedulingData($clientID)
    {
        $globaldb = $this->DB->authDB;
        $realglobaldb = $this->DB->globalDB;
        $clientID = (int)$clientID;
        $tenantFeatures = (new TenantFeatures($clientID))->tenantHasFeatures(
            [\Feature::TENANT_GDC_PREMIUM],
            \Feature::APP_TPM
        );
        $premium3pMonitorOn = $tenantFeatures[\Feature::TENANT_GDC_PREMIUM];

        // default neutral response
        $Result = 0;
        $ErrorMsg = '';
        $Error = '';
        $procTypes     = [];
        $procIntervals = [];
        $procRunLogs   = [];
        $tmpProcList = [];
        $runDay        = '';
        $runDayOrd     = '';
        //$clientID = $clientID;

        $procTypes = $this->DB->fetchObjectRows("SELECT id , processName, processDescription "
            . "FROM $realglobaldb.`g_schedProcTypes` "
            . "ORDER BY id ASC");
        if (is_array($procTypes) && count($procTypes) > 0) {
            $procTypes = $procTypes;
        }

        $procIntervals = $this->DB->fetchObjectRows("SELECT id, intervalAlias, intervalDescription "
            . "FROM $realglobaldb.`g_schedProcIntervals` "
            . "ORDER BY id ASC");
        if (is_array($procIntervals) && count($procIntervals) > 0) {
            $procIntervals = $procIntervals;
        }

        $sql = "SELECT sp.*, spi.intervalAlias, spt.processName, "
            . "'1' AS candel, '1' AS edit "
            . "FROM $realglobaldb.`g_schedProcesses` AS sp "
            . "LEFT JOIN $realglobaldb.`g_schedProcIntervals` AS spi ON spi.id=sp.intervalID "
            . "LEFT JOIN $realglobaldb.`g_schedProcTypes` AS spt ON spt.id=sp.procTypeID "
            . "WHERE sp.clientID=:clientID "
            . "AND spt.hidden=0 "
            . "AND sp.active=1";
        $params = [':clientID' => $clientID];
        $procList = $this->DB->fetchObjectRows($sql, $params);

        // Create a new array of objects with clean data
        if (is_array($procList) && count($procList) > 0) {
            $i=0;
            foreach ($procList as $proc) {
                $tmpProcList[$i] = [];
                foreach ($proc as $k => $v) {
                    switch ($k) {
                        case 'runCount':
                            $tmpProcList[$i]['runCount'] = (strlen((string) $v))?$v:'0';
                            break;
                        case 'lastRunDate':
                            if (strtotime((string) $v) > (date('U') - (86400 * 365 * 10))) {
                                $tmpProcList[$i]['lastRunDate'] = date('Y-m-d', strtotime((string) $v));
                            } else {
                                $tmpProcList[$i]['lastRunDate'] = '';
                            }
                            break;
                        case 'nextRunDate':
                            if (strtotime((string) $v) > date('U')) {
                                $tmpProcList[$i]['nextRunDate'] = date('Y-m-d', strtotime((string) $v));
                            } else {
                                $tmpProcList[$i]['nextRunDate'] = 'Pending';
                            }
                            break;
                        default:
                            $tmpProcList[$i][$k] = $v;
                    }
                } // End foreach $procList
                $i++;
            }
        }

        // initialize exclude var to skip counting ineligible processes for this clientID
        $sqlExclude = '';

        // skip 3P Monitor Process, if client is ineligible
        if (!$premium3pMonitorOn) {
            $sqlExclude .= "AND spt.id != " . self::SCHED_PROC_3PMONITOR . " ";
        }



        // Finalize the exclusion string
        if (strlen($sqlExclude) > 0) {
            $sqlExclude = trim(substr($sqlExclude, 3)); // Shave off initial 'AND', plus whitespace
            $sqlExclude = "AND (" . $sqlExclude . ")"; // Form proper SQL
        }

        $sql = "SELECT count(spt.id) "
            . "FROM $realglobaldb.g_schedProcTypes AS spt "
            . "LEFT JOIN $realglobaldb.g_schedProcesses AS sp ON "
            . "(sp.procTypeID=spt.id AND sp.clientID=:clientID1) "
            . "WHERE spt.hidden=0 "
            . $sqlExclude
            . "AND (sp.clientID IS NULL OR (sp.clientID=:clientID2 AND sp.active=0))"
            . "";
        $params = [':clientID1' => $clientID, ':clientID2' => $clientID];
        $intervals = ($this->DB->fetchValue($sql, $params)) ? true : false;


        $runDay = AppCron::getclientIDDate($clientID);
        $runDayOrd = date(
            "jS",
            mktime(date('H'), date('i'), date('s'), 1, $runDay, date('Y'))
        );
        return json_encode(['premium3pMonitor' => $premium3pMonitorOn, 'procIntervals' => $tmpProcList, 'procTypes' => $procTypes, 'procRunLogs' => $procRunLogs, 'runDay' => $runDay, 'runDayOrd' => $runDayOrd, 'sqlExclude' => $sqlExclude, 'ErrorMsg' => $ErrorMsg, 'Error' => $Error]);
    }

    /**
     * Display any Scheduled Automated Process
     *
     * @param integer $clientID client id
     *
     * @return Array of scheduling data
     */
    public function changeSchedulingData($clientID)
    {
        $clientID = (int)$clientID;
        $globaldb = $this->DB->authDB;
        $realglobaldb = $this->DB->globalDB;
        $post = $this->app->clean_POST;

        $tenantFeatures = (new TenantFeatures($clientID))->tenantHasFeatures(
            [\Feature::TENANT_GDC_PREMIUM, \Feature::TENANT_GDC_DAILY],
            \Feature::APP_TPM
        );
        $premium3pMonitorOn = $tenantFeatures[\Feature::TENANT_GDC_PREMIUM];
        $daily3pMonitorOn = $tenantFeatures[\Feature::TENANT_GDC_DAILY];

        $ErrorMsg = $Error = '';

        $tpMonitorProc = self::SCHED_PROC_3PMONITOR;

        $process = $post['proc'];
        switch ($process) {
            case 'add_process':
                $procID = intval($post['procID']);
                $intervalID = intval($post['intervalID']);
                if ($procID == 0 || $intervalID == 0) {
                    $ErrorMsg = 'No Process or Schedule Selected.';
                    $Error = 'true';
                    break;
                }
                $runDay = AppCron::getclientIDDate($clientID);
                $Run = mktime(0, 0, 1, 1, $runDay, 2000);
                $runDayOrd = date("jS", $Run);
                $lastRunStamp = 1;

                $sql = "SELECT * "
                . "FROM $realglobaldb.g_schedProcIntervals "
                . "WHERE id=:intervalID";
                $params = [':intervalID' => $intervalID];
                $interval = $this->DB->fetchObjectRow($sql, $params);

                $nextRunStamp = AppCron::findNextRunDate(
                    $lastRunStamp,
                    $interval->intervalPeriod,
                    $interval->intervalValue,
                    $runDay
                );
                $nextRun = ", nextRunDate=FROM_UNIXTIME($nextRunStamp) ";
                $sql = "SELECT id "
                    . "FROM $realglobaldb.g_schedProcesses "
                    . "WHERE clientID=:clientID "
                    . "AND procTypeID=:procID";
                $params = [':clientID' => $clientID, ':procID' => $procID];
                if (!$tblProcID = $this->DB->fetchValue($sql, $params)) {
                    $sql = "INSERT INTO $realglobaldb.g_schedProcesses "
                        . "SET clientID=:clientID, procTypeID=:procID, intervalID=:intervalID, "
                        . "changeDate=now(), lastRunDate=FROM_UNIXTIME($lastRunStamp), "
                        . "nextRunDate=FROM_UNIXTIME($nextRunStamp), active=1 "
                        . "";
                    $params = [':clientID' => $clientID, ':procID' => $procID, ':intervalID' => $intervalID];
                    $update = $this->DB->query($sql, $params);
                } else {
                    $sql = "UPDATE $realglobaldb.g_schedProcesses "
                    . "SET active=1, procTypeID=:procID, intervalID=:intervalID "
                    . "WHERE id=:tblProcID";
                    $params = [':tblProcID' => $tblProcID, ':procID' => $procID, ':intervalID' => $intervalID];
                    $update = $this->DB->query($sql, $params);
                }
                return json_encode(['clientID' => $clientID, 'sql' => $sql, 'ErrorMsg' => $ErrorMsg, 'Error' => $Error]);

            break;
            case 'edit_process':
                $procID = intval($post['procID']);
                $intervalID = intval($post['intervalID']);

                if ($procID == 0 || $intervalID == 0) {
                    $ErrorMsg = 'No Process or Schedule Selected.';
                    $Error = 'true';
                    break;
                }

                $runDay = AppCron::getclientIDDate($clientID);
                $sql = "SELECT * "
                . "FROM $realglobaldb.g_schedProcIntervals "
                . "WHERE id=:intervalID";
                $params = [':intervalID' => $intervalID];
                $interval = $this->DB->fetchObjectRow($sql, $params);

                $sql = "SELECT UNIX_TIMESTAMP(lastRunDate) AS lrd, "
                . "UNIX_TIMESTAMP(changeDate) AS cd "
                . "FROM $realglobaldb.g_schedProcesses "
                . "WHERE clientID=:clientID "
                . "AND id=:procID";
                $params = [':clientID' => $clientID, ':procID'   => $procID];
                $doUpdate = $this->DB->fetchObjectRow($sql, $params);
                $nextRunStamp = AppCron::findNextRunDate(
                    $doUpdate->lrd,
                    $interval->intervalPeriod,
                    $interval->intervalValue,
                    $runDay
                );
                $nextRun = ", nextRunDate=FROM_UNIXTIME($nextRunStamp) ";
                if (intval($doUpdate->lrd) > 0) {
                    $sql = "UPDATE $realglobaldb.g_schedProcesses "
                        . "SET intervalID=:intervalID, "
                        . "changeDate=now(), active=1"
                        . $nextRun
                        . "WHERE clientID=:clientID "
                        . "AND id=:procID";
                    $params = [':intervalID' => $intervalID, ':clientID' => $clientID, ':procID' => $procID];
                    $update = $this->DB->query($sql, $params);

                    if ($update) {
                        $Result = 1;
                        $runDayOrd = date("jS", $nextRunStamp);
                    }
                }

                return json_encode(['clientID' => $clientID, 'interval' => $interval, 'ErrorMsg' => $ErrorMsg, 'Error' => $Error]);

            break;
            case 'delete_process':
                $procID = $post['procID'];
                if ($procID == 0) {
                    $ErrorMsg = 'No Process Selected.';
                    $Error = 'true';
                    break;
                }

                $details = (new SchedulingData())->schedProcessDetails($procID);

                $delSQL = "UPDATE $realglobaldb.g_schedProcesses "
                . "SET active=0 "
                . "WHERE clientID=:clientID "
                . "AND id=:procID "
                . "LIMIT 1";
                $params = [':clientID' => $clientID, ':procID' => $procID];
                $update = $this->DB->query($delSQL, $params);

                if ($update) {
                    foreach ($details as $k => $v) {
                        switch ($k) {
                            case 'runAction':
                            case 'failureAction':
                                unset($details->$k);
                                break;
                            case 'allowedIntervals':
                                $rows = self::allowedSchedProcIntervalRows($details->procTypeID, $daily3pMonitorOn);
                                $intervalAliases = [];
                                foreach ($rows as $row) {
                                    $intervalAliases[] = $row->intervalAlias;
                                }
                                $details->$k = implode(',', $intervalAliases);
                                unset($intervalAliases);
                                break;
                            default:
                                $details->$k = $v;
                        }
                    }

                    $details->runDay = AppCron::getclientIDDate($clientID);
                    $details->runDayOrd = date(
                        "jS",
                        mktime(date('H'), date('i'), date('s'), date('n'), $details->runDay, date('Y'))
                    );
                }
                return $clientID;

            break;
            case 'detail_process':
                $spid = intval($post['spid']);
                $details = self::schedProcessDetails($spid);
                $intervalAliases = [];

                foreach ($details as $k => $v) {
                    switch ($k) {
                        case 'runAction':
                        case 'failureAction':
                            unset($details->$k);
                            break;
                        case 'changeDate':
                            $details->$k = date('Y-m-d', strtotime((string) $v));
                            break;
                        case 'lastRunDate':
                            if (strtotime((string) $v) > (date('U') - (86400 * 365 * 10))) {
                                $details->$k = date('Y-m-d', strtotime((string) $v));
                            } else {
                                $details->$k = 'Never';
                            }
                            break;
                        case 'nextRunDate':
                            if (strtotime((string) $v) > date('U')) {
                                $details->$k = date('Y-m-d', strtotime((string) $v));
                            } else {
                                $details->$k = 'Pending';
                            }
                            break;
                        case 'allowedIntervals':
                            $rows = self::allowedSchedProcIntervalRows($details->procTypeID, $daily3pMonitorOn);
                            $intervalAliases = [];
                            foreach ($rows as $row) {
                                $intervalAliases[] = $row->intervalAlias;
                            }
                            $details->$k = implode(',', $intervalAliases);
                            unset($intervalAliases);
                            break;
                        default:
                            $details->$k = $v;
                    }
                }

                $details->runDay = AppCron::getclientIDDate($clientID);
                $details->runDayOrd = date(
                    "jS",
                    mktime(date('H'), date('i'), date('s'), date('n'), $details->runDay, date('Y'))
                );

                if (is_array($details) && count($details) > 0) {
                    $details = $details;
                    $Result  = 1;
                }
                return json_encode(
                    ['details' => $details]
                );
            break;
            case 'dropdown_getProcessList':
                // initialize exclude var to skip counting ineligible processes for this clientID
                $sqlExclude = '';

                // skip 3P Monitor Process, if client is ineligible
                if (!$premium3pMonitorOn) {
                    $sqlExclude .= "AND spt.id != 1 ";
                }

                // Finalize the exclusion string
                if (strlen($sqlExclude) > 0) {
                    $sqlExclude = trim(substr($sqlExclude, 3)); // Shave off initial 'AND', plus whitespace
                    $sqlExclude = "AND (" . $sqlExclude . ")"; // Form proper SQL
                }

                $sql = "SELECT spt.id AS v, spt.processName AS t "
                . "FROM $realglobaldb.g_schedProcTypes AS spt "
                . "LEFT JOIN $realglobaldb.g_schedProcesses AS sp ON "
                . "(sp.procTypeID=spt.id AND sp.clientID=:clientID1) "
                . "WHERE hidden=0 "
                . $sqlExclude
                . "AND (sp.clientID IS NULL OR (sp.clientID=:clientID2 AND sp.active=0))"
                . "";
                $params = [':clientID1' => $clientID, ':clientID2' => $clientID];
                $result = $this->DB->fetchObjectRows($sql, $params);
                $intervalsGetProcessList = $result;


                return json_encode($intervalsGetProcessList);
            break;
            case 'dropdown_byProcess':
                $procID = $post['procID'];
                if ($procID == 0) {
                    $ErrorMsg = 'No Process Selected.';
                    $Error = 'true';
                    break;
                }
                $intervalsByProcess = [];

                $inClause = implode(',', self::allowedSchedProcIntervals($procID, $daily3pMonitorOn));
                if (strlen($inClause) > 0) {
                    $sql = "SELECT id as v, intervalAlias as t "
                    . "FROM $realglobaldb.g_schedProcIntervals "
                    . "WHERE id IN($inClause) "
                    . "ORDER BY id ASC";
                    $result = $this->DB->fetchObjectRows($sql);
                    $intervalsByProcess = $result;
                }
                return json_encode($intervalsByProcess);
            break;
            default:
        }
    }

    /**
     * returns details regarding a scheduled process
     *
     * @param integer $procID g_schedProcesses.id
     *
     * @return object
     */
    public function schedProcessDetails($procID)
    {
        $realglobaldb = $this->DB->globalDB;

        $sql = "SELECT * "
            . "FROM $realglobaldb.g_schedProcesses AS sp "
            . "LEFT JOIN $realglobaldb.g_schedProcTypes AS spt ON spt.id=sp.procTypeID "
            . "LEFT JOIN $realglobaldb.g_schedProcIntervals AS spi ON spi.id=sp.intervalID "
            . "WHERE sp.id=:procID ";
        $params = [':procID' => $procID];
        $details = $this->DB->fetchObjectRow($sql, $params);
        return $details;
    }

    /**
     * ordered scheduled interval rows
     *
     * @return array of objects
     */
    public function allScheduledProcIntervalRows()
    {
        $realglobaldb = $this->DB->globalDB;

        $sql = "SELECT id, intervalAlias, intervalPeriod, intervalValue, \n"
            . "(CASE intervalPeriod \n"
            . "WHEN 'HOURS'  THEN (1/24 * intervalValue) \n"
            . "WHEN 'DAYS'   THEN (1 * intervalValue)    \n"
            . "WHEN 'WEEKS'  THEN (7 * intervalValue)    \n"
            . "WHEN 'MONTHS' THEN (30 * intervalValue)   \n"
            . "WHEN 'YEARS'  THEN (365 * intervalValue)  \n"
            . "ELSE 0 \n"
            . "END \n"
            . ") as num_days \n"
            . "FROM $realglobaldb.g_schedProcIntervals \n"
            . "ORDER by num_days \n";
        return $this->DB->fetchObjectRows($sql);
    }

    /**
     * filter allScheduledProcIntervalRows() by the allowed intervals
     *
     * @param integer $procTypeID       g_schedProcTypes.id
     * @param boolean $daily3pMonitorOn Daily 3P Monitor setting
     *
     * @return array
     */
    public function allowedSchedProcIntervalRows($procTypeID, $daily3pMonitorOn)
    {
        $rows = self::allScheduledProcIntervalRows();
        $allowedSchedProcIntervals = self::allowedSchedProcIntervals($procTypeID, $daily3pMonitorOn);
        $ret = [];
        foreach ($rows as $row) {
            if (in_array($row->id, $allowedSchedProcIntervals)) {
                $ret[] = $row;
            }
        }
        return $ret;
    }

    /**
     * returns array of id.g_schedProcIntervals that are allowed for a given scheduled process
     *
     * @param integer $procTypeID       g_schedProcTypes.id
     * @param boolean $daily3pMonitorOn Daily 3P Monitor setting
     *
     * @return array
     */
    public function allowedSchedProcIntervals($procTypeID, $daily3pMonitorOn)
    {
        $realglobaldb = $this->DB->globalDB;

        $sql = "SELECT allowedIntervals "
            . "FROM $realglobaldb.g_schedProcTypes "
            . "WHERE id=:procTypeID ";
        $params = [':procTypeID' => $procTypeID];
        $allowedIntervals_str = $this->DB->fetchValue($sql, $params);

        $intervals = explode(',', (string) $allowedIntervals_str);

        // add Daily to schedule dropdown, only for 3P monitoring w/subscriber setting "Allow daily 3P monitoring"
        if ($procTypeID == self::SCHEDPROC_3P_MONITOR && $daily3pMonitorOn) {
            return array_unique(array_merge($intervals, self::schedProcIntervals('Daily')));
        } else {
            return $intervals;
        }
    }

    /**
    * finds the interval ids of all possible intervals >= $minIntervalAlias
    * (there is no reason to deny access to longer intervals of time so include them all)
    *
    * @param string $minIntervalAlias (e.g. 'Daily') see g_schedProcIntervals.intervalAlias
    *
    * @return array of integers, will be empty if $minIntervalAlias is invalid
    */
    public function schedProcIntervals($minIntervalAlias)
    {
        $rows = self::allScheduledProcIntervalRows();
        $ret = [];
        $start = false;
        foreach ($rows as $row) {
            if ($row->intervalAlias == $minIntervalAlias) {
                $start = true;
            }
            if ($start) {
                $ret[] = $row->id;
            }
        }
        return $ret;
    }
}
