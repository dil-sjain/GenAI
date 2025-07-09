<?php
/**
 * Model: admin tool GdcSearchStats
 */

namespace Models\TPM\Admin\Gdc;

use Lib\DateTimeEx;
use Models\TPM\SubscriberLists;
use Models\SP\ServiceProvider;
use Lib\BumpTimeLimit;

/**
 * Provides data acces for admin tool GdcSearchStats
 */
#[\AllowDynamicProperties]
class GdcSearchStatsData
{
    protected $DB = null;
    protected $tpmExclDB = [];
    protected $totals = [];
    protected $tempishTbl = '';
    protected $tempishRows = '';
    protected $tempishLog = '';

    /**
     * Monitor session key
     *
     * @var string
     */
    private $monitorKey = 'GdcUsageRpt';
    private $rptPID = null;
    private $bump = null;
    private $tmLimit = null;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->DB = \Xtra::app()->DB;
        $this->tpmExclDB = [
            $this->DB->dbPrefix . 'cms_cid76',
            $this->DB->dbPrefix . 'cms_cid13',
            $this->DB->dbPrefix . 'cms_cid500',
        ];
        $this->tempishLog = $this->DB->dbPrefix . 'pagingIndex.pagingIndexLog';
    }

    /**
     * Read private properties
     *
     * @param string $prop Property name
     *
     * @return requested value or numm
     */
    public function __get($prop)
    {
        if ($prop == 'monitorKey') {
            return $this->monitorKey;
        }
        return null;
    }

    /**
     * Get GDC usage statistics for given date rante
     *
     * @param string $startDate Start of date range
     * @param string $endDate   End of date range
     *
     * @return void
     */
    public function getStats($startDate, $endDate)
    {
        $tm = date('YmdHis');
        $this->tempishTbl = $this->DB->dbPrefix . 'pagingIndex.gdcstat_' . $tm;
        $this->tempishRows = $this->DB->dbPrefix . 'pagingIndex.gdcstatRows_' . $tm;
        [$startDT, $endDT] = DateTimeEx::mkDateTimeRange($startDate, $endDate);
        $this->totals = [
            'startTime' => $startDT,
            'endTime' => $endDT,
            'tpmTenants' => 0,
            'spTenants' => 0,
            'screens' => 0,
            'names' => 0,
            'unique' => '',
        ];
        $this->rptPID = posix_getpid();
        $this->writeRptMonitor();
        $this->tmLimit = BumpTimeLimit::getInstance();
        $app = \Xtra::app();
        // Provide means for monitor requests to know the process ID and name of the table to check
        $app->session->set($this->monitorKey, [
            'rptPID' => $this->rptPID,
            'rptTbl' => $this->tempishRows
        ]);
        $app->session->forceClose(); // allow monitoring requests to be processed

        // Start the tempish table for consolidate unique name search count
        $this->DB->query("DROP TABLE IF EXISTS $this->tempishTbl");
        $sql = "CREATE TABLE $this->tempishTbl (nameID INT NOT NULL DEFAULT '0') ENGINE=MyISAM";
        $this->DB->query($sql);
        $this->DB->query("DROP TABLE IF EXISTS $this->tempishRows");
        $sql = "CREATE TABLE $this->tempishRows (\n"
            . "recID int(11) NOT NULL AUTO_INCREMENT,\n"
            . "id int(11) NOT NULL DEFAULT '0',\n"
            . "app varchar(20) NOT NULL DEFAULT '',\n"
            . "tenant varchar(255) NOT NULL DEFAULT '',\n"
            . "screens int(11) NOT NULL DEFAULT '0',\n"
            . "`names` int(11) NOT NULL DEFAULT '0',\n"
            . "`unique` int(11) NOT NULL DEFAULT '0',\n"
            . "PRIMARY KEY (recID)\n"
            . ") ENGINE=MyISAM DEFAULT CHARSET=utf8";
        $this->DB->query($sql);
        $sql = "INSERT INTO $this->tempishLog (tblName, created) VALUES (:tbl, NOW())";
        $params = [':tbl' => 'gdcstat_' . $tm];
        $this->DB->query($sql, $params);
        $params = [':tbl' => 'gdcstatRows_' . $tm];
        $this->DB->query($sql, $params);

        // Get the stats
        $this->getStatsTPM($startDate, $endDate);
        $this->getStatsSP($startDate, $endDate);
        $this->calcOverallUniqueSearches();

        // Clean up
        $this->DB->query("DROP TABLE IF EXISTS $this->tempishTbl");
        $sql = "DELETE FROM $this->tempishLog WHERE tblName = :tbl LIMIT 1";
        $this->DB->query($sql, [':tbl' => 'gdcstat_' . $tm]);
    }

    /**
     * Get GDC usage statistics for TPM tenants for given date rante
     *
     * @param string $startDate Start of date range
     * @param string $endDate   End of date range
     *
     * @return void
     */
    protected function getStatsTPM($startDate, $endDate)
    {
        $sl = new SubscriberLists();
        $tenants = $sl->getSubscribers('1', [], [1, 500], $this->tpmExclDB);
        $rangeSpec = DateTimeEx::mkSqlDateTimeRange($startDate, $endDate, 's.created');
        $rangeSql = $rangeSpec['sql'];
        $rangeParams = $rangeSpec['params'];
        $globalDB = $this->DB->globalDB;
        foreach ($tenants as $tenant) {
            if ($tenant->cid >= 8000) { // 8000-8999 are test tenants, 9000-9999 are QC sites
                continue;
            }
            $this->tmLimit->bump();
            $st = [
                'id' => $tenant->cid,
                'app' => 'TPM',
                'tenant' => '',
                'screens' => 0,
                'names' => 0,
                'unique' => 0,
            ];
            $sql = "SELECT COUNT(s.id) FROM $tenant->db.gdcScreening AS s\n"
                . "WHERE $rangeSql AND clientID = :cid AND s.dataSource = 'info4c'";
            $params = array_merge([':cid' => $tenant->cid], $rangeParams);
            $screens = $this->DB->fetchValue($sql, $params);
            if (empty($screens)) {
                continue;
            }
            $sql = "SELECT clientName FROM $tenant->db.clientProfile WHERE id = :cid LIMIT 1";
            $st['tenant'] = $this->DB->fetchValue($sql, [':cid' => $tenant->cid]);
            $st['screens'] = $screens;

            $sql = "SELECT COUNT(r.id) FROM $tenant->db.gdcResult AS r\n"
                . "INNER JOIN $tenant->db.gdcScreening AS s ON s.id = r.screeningID\n"
                . "WHERE $rangeSql AND s.clientID = :cid AND s.dataSource = 'info4c'";
            $st['names'] = $names = $this->DB->fetchValue($sql, $params);

            $sql = "INSERT INTO $this->tempishTbl SELECT DISTINCT u.id FROM $tenant->db.gdcResult AS r\n"
                . "INNER JOIN ($tenant->db.gdcScreening AS s, $globalDB.g_gdcSearchName AS u)\n"
                . "ON (s.id = r.screeningID AND u.id = r.nameID)\n"
                . "WHERE $rangeSql AND s.clientID = :cid AND s.dataSource = 'info4c'";
            $res = $this->DB->query($sql, $params);
            $unique = 0;
            if (is_object($res)) {
                $unique = $res->rowCount();
            }
            $st['unique'] = $unique;
            $this->insertStatRow($st);
            // update totals
            $this->totals['tpmTenants']++;
            $this->totals['screens'] += $screens;
            $this->totals['names'] += $names;
            $this->writeRptMonitor();
        }
    }

    /**
     * Get GDC usage statistics for SP tenants for given date range
     *
     * @param string $startDate Start of date range
     * @param string $endDate   End of date range
     *
     * @return void
     */
    protected function getStatsSP($startDate, $endDate)
    {
        $sp = new ServiceProvider();
        $tenants = $sp->getSPs(false, 'full');
        $rangeSpec = DateTimeEx::mkSqlDateTimeRange($startDate, $endDate, 's.created');
        $rangeSql = $rangeSpec['sql'];
        $rangeParams = $rangeSpec['params'];
        $globalDB = $this->DB->globalDB;
        $spGlobalDB = $this->DB->spGlobalDB;
        foreach ($tenants as $tenant) {
            if (in_array($tenant['id'], [10, 14])) {
                continue;
            }
            $this->tmLimit->bump();
            $st = [
                'id' => $tenant['id'] + \Feature::SP_TENANT_OFFSET,
                'app' => 'SP',
                'tenant' => '',
                'screens' => 0,
                'names' => 0,
                'unique' => 0,
            ];
            $sql = "SELECT COUNT(s.id) FROM $spGlobalDB.spGdcScreening AS s\n"
                . "WHERE $rangeSql AND spID = :id AND s.dataSource = 'info4c'";
            $params = array_merge([':id' => $tenant['id']], $rangeParams);
            $screens = $this->DB->fetchValue($sql, $params);
            if (empty($screens)) {
                continue;
            }
            $st['tenant'] = $tenant['investigatorName'];
            $st['screens'] = $screens;

            $sql = "SELECT COUNT(r.id) FROM $spGlobalDB.spGdcResult AS r\n"
                . "INNER JOIN $spGlobalDB.spGdcScreening AS s ON s.id = r.screeningID\n"
                . "WHERE $rangeSql AND s.spID = :id AND s.dataSource = 'info4c'";
            $st['names'] = $names = $this->DB->fetchValue($sql, $params);

            $sql = "INSERT INTO $this->tempishTbl SELECT DISTINCT u.id FROM $spGlobalDB.spGdcResult AS r\n"
                . "INNER JOIN ($spGlobalDB.spGdcScreening AS s, $globalDB.g_gdcSearchName AS u)\n"
                . "ON (s.id = r.screeningID AND u.id = r.nameID)\n"
                . "WHERE $rangeSql AND s.spID = :id AND s.dataSource = 'info4c'";
            $res = $this->DB->query($sql, $params);
            $unique = 0;
            if (is_object($res)) {
                $unique = $res->rowCount();
            }
            $st['unique'] = $unique;
            $this->insertStatRow($st);
            // update totals
            $this->totals['spTenants']++;
            $this->totals['screens'] += $screens;
            $this->totals['names'] += $names;
            $this->writeRptMonitor();
        }
    }

    /**
     * Insert statistic row in tempishRows table
     *
     * @param araay $stat Values to insert
     *
     * @return void
     */
    private function insertStatRow($stat)
    {
        $tokens = $params = [];
        foreach ($stat as $k => $v) {
            $tok = ':' . $k;
            $tokens[] = $tok;
            $params[$tok] = $v;
        }
        $paramList = implode(', ', $tokens);
        $sql = "INSERT INTO $this->tempishRows VALUES (NULL, $paramList)";
        $this->DB->query($sql, $params);
    }

    /**
     * Count overall unique name searches after all other stats are determeined
     *
     * @return int total
     */
    public function calcOverallUniqueSearches()
    {
        $this->DB->query("ALTER TABLE $this->tempishTbl ADD INDEX nameID (nameID)");
        $sql = "SELECT COUNT(DISTINCT nameID) FROM $this->tempishTbl";
        $this->totals['unique'] = $this->DB->fetchValue($sql);
        $this->writeRptMonitor();
    }

    /**
     * Remove stale report progress files
     *
     * @return void
     */
    private function rmStaleMonitorFiles()
    {
        $pat = '/tmp/' . $this->monitorKey . '_*';
        $files = glob($pat);
        foreach ($files as $f) {
            if ((filectime($f) + 86400) < time()) {
                unlink($f); // delete after 24 hrs if still hanging around.
            }
        }
    }

    /**
     * Write serialized monitor data to disk
     *
     * @return void
     */
    private function writeRptMonitor()
    {
        file_put_contents(
            '/tmp/' . $this->monitorKey . '_' . $this->rptPID,
            serialize($this->totals),
            LOCK_EX
        );
    }

    /**
     * Get available stat rows from tempish table during monitoring
     *
     * @param int    $lastRecID Last recID read from table
     * @param string $rptTbl    Name of stat rows table
     * @param bool   $dropTbl   Drop the table at end of monitoring
     *
     * @return array Assoc arrays or rowID values > $lastRecID
     */
    public function getAvailableStatRows($lastRecID, $rptTbl, $dropTbl)
    {
        $rtn = [];
        $rptTblArray = explode('.', $rptTbl);
        if (!($this->DB->tableExists($rptTblArray[1], $rptTblArray[0]))) {
            return $rtn;
        }
        $dropTbl = (bool)$dropTbl;
        $tblName = $this->DB->esc($rptTbl);
        $rptTbl = \Xtra::app()->session->get($this->monitorKey . '.rptTbl');
        $sql = "SELECT * FROM $rptTbl WHERE recID > :recID ORDER BY recID ASC";
        $rtn = $this->DB->fetchAssocRows($sql, [':recID' => $lastRecID]);
        if ($dropTbl) {
            $this->DB->query("DROP TABLE IF EXISTS $rptTbl");
            [$db, $tbl] = explode('.', (string) $rptTbl);
            $sql = "DELETE FROM $this->tempishLog WHERE tblName = :tbl LIMIT 1";
            $params = [':tbl' => $tbl];
            $this->DB->query($sql, $params);
        }
        return $rtn;
    }
}
