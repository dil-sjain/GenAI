<?php
/**
 * Controller: admin tool GdcSearchStats
 */

namespace Controllers\TPM\Admin\Gdc;

use Controllers\TPM\Base\TopNavBarTabs;
use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Admin\Gdc\GdcSearchStatsData;
use Lib\Traits\AjaxDispatcher;
use Lib\CsvIO;
use Lib\Csv;

/**
 * Handles requests and responses for admin tool GdcSearchStats
 */
class GdcSearchStats extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View (see: Base::getTemplate())
     */
    protected $tplRoot = 'TPM/Admin/Gdc/';

    /**
     * @var string Base template for View (Can also be an array. see: Base::getTemplate())
     */
    protected $tpl = 'GdcSearchStats.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var object Model instance
     */
    private $m = null;

    /**
     * Constructor gets model instance and initializes other properties
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app  = \Xtra::app();
        $this->app->session->set('navSync.Top', TopNavBarTabs::$tabs['Analytics']['sync']);
        parent::__construct($clientID, $initValues);
        $this->m = new GdcSearchStatsData();
    }

    /**
     * Export csv data
     *
     * @return void
     */
    public function export()
    {
        $info = json_decode((string) \Xtra::arrayGet($this->app->request->post(), 'gdcstat-export-data', ''));
        $output = '';

        // The summary
        $output .= CSV::std(['', '', 'GDC Usage Report', '', '', '']);
        $output .= CSV::std(['', '', '', '', '', '']);
        $s = $info->summary;
        $data = [
            '',
            'Start time',
            $s->startTime,
            'Screenings',
            $s->screens,
            '',
        ];
        $output .= Csv::std($data);
        $data = [
            '',
            'End time',
            $s->endTime,
            'Name searches',
            $s->names,
            '',
        ];
        $output .= Csv::std($data);
        $data = [
            '',
            'Tenants',
            ($s->tpmTenants + $s->spTenants) . " (TPM: $s->tpmTenants, SP: $s->spTenants)",
            'Unique names',
            $s->unique,
            '',
        ];
        $output .= Csv::std($data);
        $output .= CSV::std(['', '', '', '', '', '']);

        // Add the stats
        $cols = [
            'Tenant ID',
            'Application',
            'Tenant Name',
            'Screenings',
            'Name Searches',
            'Unique Names',
        ];
        $output .= Csv::std($cols);
        foreach ($info->stats as $stat) {
            $data = [
                $stat->id,
                $stat->app,
                $stat->tenant,
                $stat->screens,
                $stat->names,
                $stat->unique,
            ];
            $output .= Csv::std($data);
        }
        // Send it out
        $contentLen = strlen($output);
        $isIE = false;
        if (str_contains(strtoupper((string) $_SERVER['HTTP_USER_AGENT']), 'MSIE')) {
            $isIE = true;
        }
        $csvOut = new CsvIO($isIE);
        $csvOut->setForCsv('GDC Usage Report', $contentLen);
        echo $output;
        exit;
    }


    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        if (!$this->canAccess) {
            UserLock::denyAccess();
        }
        $this->setViewValue('gdcstatCanAccess', $this->canAccess);
        $this->setViewValue('pgTitle', 'GDC Usage');
        $this->setViewValue('startDate', date('Y-m-d', strtotime('1 year ago')));
        $this->setViewValue('endDate', date('Y-m-d'));
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * Generate the report
     *
     * @return void
     */
    private function ajaxRun()
    {
        $dt1 = \Xtra::arrayGet($this->app->clean_POST, 's', '');
        $dt2 = \Xtra::arrayGet($this->app->clean_POST, 'e', '');
        $this->m->getStats($dt1, $dt2);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.gdcstat.finishRpt';
        $this->jsObj->AppNotice = ['Report complete'];
    }

    /**
     * Monitor report generation and update incrementally
     *
     * @return void
     */
    private function ajaxMonitor()
    {
        // 0 if last call
        $repeat = (int)\Xtra::arrayGet($this->app->clean_POST, 'r');
        $lastRecID = (int)\Xtra::arrayGet($this->app->clean_POST, 'id');
        $key = $this->m->monitorKey;
        $sessVals = $this->app->session->get($key, ['rptPID' => 0, 'rptTbl' => '']);
        $monitorFile = '/tmp/' . $key . '_' . $sessVals['rptPID'];
        $totals = [];
        if (file_exists($monitorFile)) {
            if ($ser = file_get_contents($monitorFile)) {
                $totals = unserialize($ser);
            }
        }
        $hasData = (empty($totals)) ? 0 : 1;
        if (!empty($sessVals['rptTbl'])) {
            // Not sure how the table name doesn't make it into the session vals, but it happens.
            $rows = $this->m->getAvailableStatRows($lastRecID, $sessVals['rptTbl'], ($repeat == 0));
        } else {
            $rows = [];
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.gdcstat.updateRpt';
        $this->jsObj->Args = [$hasData, $totals, $rows];
        if (!$repeat) {
            $this->app->session->remove($key);
            if (file_exists($monitorFile)) {
                @unlink($monitorFile);
            }
        } else {
            // Throttle the rate of monitoring. Be a good citizen of server resources.
            // It works only if montiroing requests are chained: this one triggers the next
            // Depending on how much work has to be done on each trip, this time may be adjusted.
            usleep(333333); // 1/3 second delay
        }
    }
}
