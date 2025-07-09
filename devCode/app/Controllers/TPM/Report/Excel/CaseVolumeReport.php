<?php
/**
 * Created by:  Rich Jones
 * Create Date: 2016-04-19
 *
 * Controller: CaseVolumeReport
 */

namespace Controllers\TPM\Report\Excel;

use Lib\Support\Xtra;
use Lib\Traits\AjaxDispatcher;
use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Report\Excel\CaseVolumeReport as mCaseVolumeReport;
use Models\TPM\Report\Excel\CaseVolumeReportExcel;

/**
 * Class CaseVolumeReport
 *
 * @keywords caseVolumeReport, cvr, analytics, report, bi, business intelligence
 *
 * @package Controllers\TPM\Report\Excel
 */
class CaseVolumeReport extends Base
{
    use AjaxDispatcher;

    /**
     * @var object Instance of app logger
     */
    protected $log = null;

    /**
     * @var int
     */
    protected $clientID;

    /**
     * @var string
     */
    protected $tplRoot = 'TPM/Reports/Excel/';

    /**
     * @var string
     */
    protected $tpl = 'caseVolumeReport.html.tpl';

    /**
     * @var mCaseVolumeReport
     */
    protected $cvr_m;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var
     */
    protected $authUserEmail;
    /**
     * @var
     */
    protected $authUserID;


    /**
     * CaseVolumeReport constructor.
     *
     * @param int   $clientID   client id
     * @param array $initValues init values
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $app = Xtra::app();
        $this->app = $app;
        $this->log = $app->log;
        $this->clientID = (int)$clientID;
        $this->authUserEmail = $this->session->authUserEmail;
        $this->authUserID = $this->session->authUserID;

        // instantiate the CaseVolumeReport model.
        $this->cvr_m = new mCaseVolumeReport($this->clientID);
    }

    /**
     * init the class instance.
     *
     * @return void
     */
    public function initialize()
    {
        if (!$this->cvr_m->verifyAccess($this->authUserID)) {
            // redirect back to prevent a Skinny stack trace dump from being displayed to the user.
            $this->app->redirect('/tpReport');
        }

        $this->setViewValue('cvrCanAccess', $this->canAccess);
        $this->setViewValue('title', 'Case Volume Report');
        $this->setViewValue('cvrRows', $this->getClientData());

        $cvrAuthToken = $this->session->getToken();
        $this->setViewValue('cvrAuthToken', $cvrAuthToken);

        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * get case volume report client id and name.
     *
     * @return mixed
     */
    private function getClientData()
    {
        // get data for view.
        $cvrRows = $this->cvr_m->getClientIdAndClientName($this->authUserID);

        // create unique id; used by js in the view.
        foreach ($cvrRows as $clientRow) {
            $clientRow->Id = 'cid' . $clientRow->clientId;
        }

        return $cvrRows;
    }

    /**
     * handles client .js AjaxReq() call.
     *
     * @return void
     */
    public function ajaxGenSpreadsheet()
    {
        $err = false;

        if (($params = json_decode((string) $this->app->request->post('data'))) === null) {
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $params;
        } else {
            if (is_object($params)) {
                $cvrExcel = new CaseVolumeReportExcel($this->clientID);
                $cvrExcel->initReport($this->authUserID, $this->authUserEmail, $params);
                $cvrExcel->createReport();
            } else {
                $err = $params;
            }

            if (empty($err)) {
                $this->jsObj->Result = 1; // success
                $this->jsObj->FuncName = 'appNS.cvr.spreadsheetSuccess';
                $this->jsObj->Args = [
                    $cvrExcel->getReportLocation()
                ];
            } else {
                $this->jsObj->ErrTitle = 'Operation Failed';
                $this->jsObj->ErrMsg = $err;
            }
        }
    }

    /**
     * down load the excel spreadsheet to the client machine
     *
     * @return void
     */
    public function downloadReport()
    {
        $fname = CaseVolumeReportExcel::deriveFilePath() . \Xtra::app()->mode .
                       CaseVolumeReportExcel::DS .
                       'caseVolumeReport' .
                       CaseVolumeReportExcel::DS .
                       $this->app->clean_POST['rptFileName'] . '-' . $this->authUserID . '.xlsx';

        if ($fname && file_exists($fname)) {
            $isIE = (str_contains(strtoupper((string) \Xtra::app()->environment['HTTP_USER_AGENT']), 'MSIE'))
                ? true
                : false;
            if ($isIE) {
                // http://support.microsoft.com/kb/234067
                header('Pragma: public; no-cache'); // public required for SSL IE7 & IE8
                header('Expires: -1');
            }

            $size = filesize($fname);
            header("Content-Disposition: attachment; filename=CaseVolumeReport.xlsx");
            header("Content-Length: $size");
            header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
            readfile($fname);
            @unlink($fname);
        } else {
            // redirect back to prevent a Skinny stack trace dump from being displayed to the user.
            $this->app->redirect('/tpm/rpt/cvr');
        }
    }
}
