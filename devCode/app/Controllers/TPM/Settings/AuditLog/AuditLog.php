<?php
/**
 * Site Audit Log controller
 */

namespace Controllers\TPM\Settings\AuditLog;

use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Settings\AuditLog\AuditLogData;

/**
 * SiteNotices controller
 *
 * @keywords site, notices, settings
 */
class AuditLog extends Base
{
    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/AuditLog/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'AuditLog.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var object Application Session instance
     */
    protected $session = null;

    /**
     * @var object AuditLogData instance
     */
    private $data = null;

    /**
     * @var object \Xtra::app()->log instance
     */
    private $logger = null;

    /**
     * @var integer Records benchmarks in log and in browser.
     *              KEEP THIS SET TO 0 IN PRODUCTION UNLESS NEEDED FOR TROUBLESHOOTING!
     *              If set to 1, backend benchmarks will be recorded in the log.
     *              Front-end benchmarks will be recorded in the browser console.
     */
    public const BENCHMARKS = 0;

    /**
     * Sets SiteNotice template to view
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @throws \Exception
     */
    public function __construct($clientID, $initValues = [])
    {
        $clientID = (int)$clientID;
        if ($clientID <= 0) {
            throw new \Exception('Invalid clientID');
        }
        parent::__construct($clientID, $initValues);
        $this->app = \Xtra::app();
        $this->session = $this->app->session;
        $this->data = new AuditLogData($clientID);
        $this->logger = \Xtra::app()->log;
    }

    /**
     * initialize the page
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgAuth', $this->session->getToken());
        $this->setViewValue('benchmarks', self::BENCHMARKS);
        $this->setViewValue('allEvents', $this->data->fetchEvents());

        $startDateDefault = ($this->session->has("stickySettings.auditLog.dt1")
            && preg_match('#^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$#', (string) $this->session->get("stickySettings.auditLog.dt1"))
        )
            ? $this->session->get("stickySettings.auditLog.dt1")
            : '';
        $myEventsDefault = ($this->session->has("stickySettings.auditLog.my")
            && $this->session->get("stickySettings.auditLog.my") == 0
        )
            ? 0
            : 1;
        $orderDirDefault = ($this->session->has("stickySettings.auditLog.dtDir")
            && $this->session->get("stickySettings.auditLog.dtDir") == 'asc'
        )
            ? 'asc'
            : 'desc';
        $orderCols = ['date' => 0,'event' => 1,'user' => 2];
        $srt = ($this->session->has("stickySettings.auditLog.sort"))
            ? $this->session->get("stickySettings.auditLog.sort")
            : null;
        $orderColDefault = (($srt) && array_key_exists($srt, $orderCols))
            ? $srt
            : 'date';
        $rowsPerPageDefault = ($this->session->has("stickySettings.auditLog.pp")
            && (int)$this->session->get("stickySettings.auditLog.pp") > 15
        )
            ? $this->session->get("stickySettings.auditLog.pp")
            : 15;

        $filters = [
            'filteredEvents' => [],
            'myEvents' => $myEventsDefault,
            'startDate' => $startDateDefault,
            'endDate' => '',
            'caseNumbers' => '',
            'rowsPerPage' => $rowsPerPageDefault,
            'startingIdx' => 0,
            'orderCol' => $orderCols[$orderColDefault],
            'orderDir' => $orderDirDefault,
            'timestamp' => time(),
        ];
        foreach ($filters as $filter => $default) {
            if ($this->session->has('auditLog' . ucfirst($filter))) {
                ${$filter} = $this->session->get('auditLog' . ucfirst($filter));
            } else {
                ${$filter} = $default;
            }
            $this->setViewValue($filter, ${$filter});
        }
        $this->setViewValue('exportLimit', number_format(AuditLogData::CSV_ROW_LIMIT, 0));
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }



    /**
     * Kick out streaming CSV of audit log data
     *
     * @return void
     */
    public function downloadExport()
    {
        $filters['startDate'] = \Xtra::arrayGet($this->app->clean_POST, 'startDate', 0);
        $filters['endDate'] = \Xtra::arrayGet($this->app->clean_POST, 'endDate', 0);
        $filters['caseNumbers'] = \Xtra::arrayGet($this->app->clean_POST, 'caseNumbers', 0);
        $filters['myEventsOnly'] = \Xtra::arrayGet($this->app->clean_POST, 'myEventsOnly', 0);
        $filters['filteredEvents'] = json_decode(
            (string) \Xtra::arrayGet($this->app->clean_POST, 'filteredEvents', 0)
        );
        $filters['benchmarks'] = \Xtra::arrayGet($this->app->clean_POST, 'benchmarks', 0);
        $filters['isCSV'] = 1;

        // This will have been set via the datatable build-out that precedes a download.
        if ($this->session->get('auditLogSortCol')) {
            $filters['sortCol'] = $this->session->get('auditLogSortCol');
        }

        $this->data->init($filters);
    }


    /**
     * Populate index table if necessary, and retrieve data for data table
     *
     * @return mixed object or null
     */
    public function ajaxGetData()
    {
        $filters['startingIdx'] = (int)\Xtra::arrayGet($this->app->clean_POST, 'start', 0);
        $filters['rowsPerPage'] = (int)\Xtra::arrayGet($this->app->clean_POST, 'length', 0);
        $order = \Xtra::arrayGet($this->app->clean_POST, 'order', 0);
        $sortAliases = [0 => 'date', 1 => 'event', 2 => 'user'];
        $filters['sortAlias'] = $sortAliases[(int)$order[0]['column']];
        $filters['sortDirection'] = $order[0]['dir'];
        $filters['startDate'] = \Xtra::arrayGet($this->app->clean_POST, 'startDate', 0);
        $filters['endDate'] = \Xtra::arrayGet($this->app->clean_POST, 'endDate', 0);
        $filters['caseNumbers'] = \Xtra::arrayGet($this->app->clean_POST, 'caseNumbers', 0);
        $filters['myEventsOnly'] = \Xtra::arrayGet($this->app->clean_POST, 'myEventsOnly', 0);
        $filters['filteredEvents'] = \Xtra::arrayGet($this->app->clean_POST, 'filteredEvents', 0);
        $filters['benchmarks'] = \Xtra::arrayGet($this->app->clean_POST, 'benchmarks', 0);
        $filters['indexingConfirmed'] = (int)\Xtra::arrayGet(
            $this->app->clean_POST,
            'indexingConfirmed',
            0
        );
        $filters['timestamp'] = \Xtra::arrayGet($this->app->clean_POST, 'timestamp', 0);
        $filters['freshData'] = (int)\Xtra::arrayGet($this->app->clean_POST, 'freshData', 0);
        $filters['idxTbl'] = $this->session->get('auditLogIdxTbl') ?: '';
        $filters['idxTblCnt'] = $this->session->get('auditLogIdxTblCnt') ?: 0;
        $filters['idxTblCreated'] = $this->session->get('auditLogIdxTblCreated') ?: false;

        if (!empty($filters['startDate'])) {
            $this->session->set("stickySettings.auditLog.dt1", $filters['startDate']);
        } else {
            $this->session->set("stickySettings.auditLog.dt1", '');
        }
        if (!empty($filters['myEventsOnly'])) {
            $this->session->set("stickySettings.auditLog.my", 1);
        } else {
            $this->session->set("stickySettings.auditLog.my", 0);
        }
        if (!empty($filters['sortDirection'])) {
            $this->session->set("stickySettings.auditLog.dtDir", $filters['sortDirection']);
        } else {
            $this->session->set("stickySettings.auditLog.dtDir", 'desc');
        }
        if (!empty($filters['sortAlias'])) {
            $this->session->set("stickySettings.auditLog.sort", $filters['sortAlias']);
        } else {
            $this->session->set("stickySettings.auditLog.sort", 'date');
        }
        if (!empty($filters['rowsPerPage'])) {
            $this->session->set("stickySettings.auditLog.pp", $filters['rowsPerPage']);
        } else {
            $this->session->set("stickySettings.auditLog.pp", 15);
        }

        $data = $this->data->init($filters);
        if ($data) {
            if ($data->stickyConfig && !empty($data->stickyConfig)) {
                // Loop through "sticky" configurations, and set for the session.
                foreach ($data->stickyConfig as $key => $val) {
                    $this->session->set($key, $val);
                }
            }
            $this->session->set("stickySettings.auditLog.total", $data->total);
            $rtn = (object)null;
            $rtn->draw = (int)\Xtra::arrayGet($this->app->clean_POST, 'draw', 0);
            $rtn->recordsFiltered = $data->total;
            $rtn->recordsTotal = $data->total;
            $rtn->data = $data->rows;
            $rtn->timestamp = $data->timestamp;
            $rtn->needThreshConfirmed = $data->needThreshConfirmed;
            return $rtn;
        } else {
            $this->session->set("stickySettings.auditLog.total", 0);
        }
    }
}
