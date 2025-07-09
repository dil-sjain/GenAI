<?php
/**
 * Site Audit Log controller
 */

namespace Controllers\TPM\Settings\EmailLog;

use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Settings\EmailLog\EmailLogData;

/**
 * EmailLog controller
 *
 * @keywords site, notices, settings
 */
#[\AllowDynamicProperties]
class EmailLog extends Base
{
    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/EmailLog/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'EmailLog.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var object Application Session instance
     */
    protected $session = null;

    /**
     * @var object EmailLogData instance
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
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @throws \Exception
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        $clientID = (int)$clientID;
        \Xtra::requireInt($clientID, 'clientID must be an integer value');
        parent::__construct($clientID, $initValues);
        $this->app = \Xtra::app();
        $this->session = $this->app->session;
        $this->data = new EmailLogData($clientID);
        $this->logger = \Xtra::app()->log;
    }

    /**
     * initialize the page
     *
     * @throws \Exception
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgAuth', $this->session->getToken());
        $this->setViewValue('benchmarks', self::BENCHMARKS);
        $this->setViewValue('allEvents', $this->data->fetchEvents());

        $startDateDefault = ($this->session->has("stickySettings.emailLog.dt1")
            && preg_match('#^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$#', (string) $this->session->get("stickySettings.emailLog.dt1"))
        )
            ? $this->session->get("stickySettings.emailLog.dt1")
            : '';
        $myEventsDefault = ($this->session->has("stickySettings.emailLog.my")
            && $this->session->get("stickySettings.emailLog.my") == 0
        )
            ? 0
            : 1;
        $orderDirDefault = ($this->session->has("stickySettings.emailLog.dtDir")
            && $this->session->get("stickySettings.emailLog.dtDir") == 'asc'
        )
            ? 'asc'
            : 'desc';
        $orderCols = ['date' => 0,'event' => 1,'user' => 2];
        $srt = ($this->session->has("stickySettings.emailLog.sort"))
            ? $this->session->get("stickySettings.emailLog.sort")
            : null;
        $orderColDefault = (($srt) && array_key_exists($srt, $orderCols))
            ? $srt
            : 'date';
        $rowsPerPageDefault = ($this->session->has("stickySettings.emailLog.pp")
            && (int)$this->session->get("stickySettings.emailLog.pp") > 15
        )
            ? $this->session->get("stickySettings.emailLog.pp")
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
            if ($this->session->has('emailLog' . ucfirst($filter))) {
                ${$filter} = $this->session->get('emailLog' . ucfirst($filter));
            } else {
                ${$filter} = $default;
            }
            $this->setViewValue($filter, ${$filter});
        }
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
        if ($this->session->get('emailLogSortCol')) {
            $filters['sortCol'] = $this->session->get('emailLogSortCol');
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
        $filters['idxTbl'] = $this->session->get('emailLogIdxTbl') ?: '';
        $filters['idxTblCnt'] = $this->session->get('emailLogIdxTblCnt') ?: 0;
        $filters['idxTblCreated'] = $this->session->get('emailLogIdxTblCreated') ?: false;

        if (!empty($filters['startDate'])) {
            $this->session->set("stickySettings.emailLog.dt1", $filters['startDate']);
        } else {
            $this->session->set("stickySettings.emailLog.dt1", '');
        }
        if (!empty($filters['myEventsOnly'])) {
            $this->session->set("stickySettings.emailLog.my", 1);
        } else {
            $this->session->set("stickySettings.emailLog.my", 0);
        }
        if (!empty($filters['sortDirection'])) {
            $this->session->set("stickySettings.emailLog.dtDir", $filters['sortDirection']);
        } else {
            $this->session->set("stickySettings.emailLog.dtDir", 'desc');
        }
        if (!empty($filters['sortAlias'])) {
            $this->session->set("stickySettings.emailLog.sort", $filters['sortAlias']);
        } else {
            $this->session->set("stickySettings.emailLog.sort", 'date');
        }
        if (!empty($filters['rowsPerPage'])) {
            $this->session->set("stickySettings.emailLog.pp", $filters['rowsPerPage']);
        } else {
            $this->session->set("stickySettings.emailLog.pp", 15);
        }

        $data = $this->data->init($filters);
        if ($data) {
            if ($data->stickyConfig && !empty($data->stickyConfig)) {
                // Loop through "sticky" configurations, and set for the session.
                foreach ($data->stickyConfig as $key => $val) {
                    $this->session->set($key, $val);
                }
            }
            $rtn = (object)null;
            $rtn->draw = (int)\Xtra::arrayGet($this->app->clean_POST, 'draw', 0);
            $rtn->recordsFiltered = $data->total;
            $rtn->recordsTotal = $data->total;
            $rtn->data = $data->rows;
            $rtn->timestamp = $data->timestamp;
            return $rtn;
        }
    }
}
