<?php
/**
 * TPM - Analytics - 3P Monitor Report
 */

namespace Controllers\TPM\Report\TpMonitor;

use Controllers\ThirdPartyManagement\Base;
use Controllers\TPM\Report\TpMonitor\MediaMonitorStatus;
use Controllers\TPM\Report\TpMonitor\PanamaPaperStatus;
use Models\TPM\Report\TpMonitor\TpMonitorReportData;
use Lib\Csv;
use Lib\Legacy\GenPDF;
use Lib\Session\SecurityHash;
use Lib\Traits\AjaxDispatcher;
use Models\Cli\BackgroundProcessData;
use Models\ThirdPartyManagement\ThirdParty;
use Lib\SettingACL;

// debugging

/**
 * Controls the 3P Monitor report
 */
#[\AllowDynamicProperties]
class TpMonitorReport
{
    use AjaxDispatcher;
    public const MIN_PAGE_SIZE = 15;
    public const LIST_MAX_CHAR = 2000;

    protected $app      = null; // app instance
    protected $baseCtrl = null; // base controller instance
    protected $bgData   = null; // bg process model instance
    protected $data     = null; // model instance
    protected $txtTr    = null; // translation array
    protected $session  = null;
    protected $secHash  = null;
    protected $tenantID = 0;
    protected $userID   = 0;

    protected $trTxt    = [];
    protected $trGroups = ['report_3p_monitor', 'ddq_misc', 'common'];

    protected $tplRoot  = 'TPM/Reports/TpMonitor/';
    protected $tpl      = 'TpMonitorReport.tpl';
    protected $tplPdf   = 'TpMonitorReportPdf.tpl';
    protected $reqFiles = [
        '/assets/jq/jqx/jqwidgets/jqxdatetimeinput.js',
        '/assets/js/src/errBox.js',
        '/assets/js/TPM/Reports/tpmtr.js',
        '/assets/css/TPM/Reports/tpmtr.css',
        '/assets/css/pbar.css'
    ];

    protected $rowsPerPage = [self::MIN_PAGE_SIZE, 20, 30, 50, 75, 100];
    protected $isPdf       = false;
    private $panamaPapers  = false;
    private $mediaMon      = false;
    private $numReports    = 1;
    private $pageReport    = 0;
    private $enableTrueMatch = false;
    private $gdcSettings = [];

    /**
     * @var boolean AWS Environment variable
     */
    protected $awsEnabled = false;


    /**
     * Constructor
     *
     * @param integer $tenantID   Delta tenentID (aka: clientID)
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;
        $this->app = \Xtra::app();

        if (!empty($initValues['isPdf'])) {
            $this->isPdf = true;
            unset($initValues['isPdf']);
        }
        if (!empty($initValues['page'])) {
            $this->pageReport = (int)$initValues['page'];
        }
        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $settings = new SettingACL($this->tenantID);
        $this->gdcSettings = $settings->getGdcSettings();
        $this->enableTrueMatch = (bool)$this->gdcSettings['useTrueMatch'];
        $this->mediaMon = (bool)$this->gdcSettings['mediaMonitor'];
        $this->panamaPapers = (bool)$this->gdcSettings['search']['icij'];

        $this->baseCtrl = new Base($this->tenantID, $initValues);
        $this->data     = new TpMonitorReportData($this->tenantID);
        $this->bgData   = new BackgroundProcessData();
        $this->trTxt    = $this->setupTranslation();
        $this->secHash  = new SecurityHash();
        $this->session  = $this->app->session;
        $this->userID   = $this->session->authUserID;
        $numReports = 1; //GDC
        if ($this->mediaMon) {
            $numReports++; // has Media
        }
        $this->numReports = $numReports;
        $this->awsEnabled = filter_var(getenv('AWS_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Setup for initial page load
     *
     * @return void
     */
    public function initialize()
    {
        $rptInProgress = $this->isReportInProgress();
        $showConfig = (!$rptInProgress) ? 1:0;

        $this->baseCtrl->setViewValue('pageReport', $this->pageReport);
        $this->baseCtrl->setViewValue('numReports', $this->numReports);
        $this->baseCtrl->setViewValue('pgTitle', $this->trTxt['tpl']['pgTitle']);
        $this->baseCtrl->setViewValue('catMap', $this->getTypeCats());
        $this->baseCtrl->setViewValue('regions', json_encode($this->data->getRegionMap()));
        $this->baseCtrl->setViewValue('countries', json_encode($this->data->getCountryMap()));
        $this->baseCtrl->setViewValue('rptPbarName', 'tpmtr-pbar');
        $this->baseCtrl->setViewValue('tplTxt', $this->trTxt['tpl']);
        $this->baseCtrl->setViewValue('jsTxt', json_encode($this->trTxt['js']));
        $this->baseCtrl->setViewValue('rptInProgress', $rptInProgress);
        $this->baseCtrl->setViewValue('showConfig', $showConfig);
        $this->baseCtrl->setViewValue('showIcons', (($this->session->get('searchPerms.userGlobalAlways')) ? 1:0));
        $this->baseCtrl->setViewValue('use3pSearch', (($this->session->get('last3pListGdcReview')) ? 1:0));
        $this->baseCtrl->setViewValue('enableTrueMatch', $this->enableTrueMatch);
        $this->baseCtrl->setViewValue('showRemed', $this->getRemedSetting());
        $this->baseCtrl->addFileDependency($this->reqFiles);
        $this->baseCtrl->setViewValue('awsEnabled', $this->awsEnabled, true);
        $this->app->view->display($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());
    }

    /**
     * Handle return of the number of profile hits based on posted criteria.
     *
     * @return void;
     */
    private function ajaxGetProfileHits()
    {
        $this->jsObj->Result = 0;
        $data = $this->processReportParams();

        if (!$data) {
            // processReportParams sets jsObj error stuff
            return;
        }

        if ($data['rptMode'] == '3pList') {
            $hits = $this->data->countHitsFromGdcReview();
        } else {
            $hits = $this->data->countHits($data);
        }

        if (!$hits) {
            $this->jsObj->ErrTitle = $this->trTxt['error']['noRecordsFound'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['noProfiles'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->ProfileHits = number_format(floatval($hits), 0);
        $this->jsObj->JobID = 0;
    }


    /**
     * Kick off report generation
     *
     * @return void
     */
    private function ajaxGenerateReport()
    {
        $this->jsObj->Result = 0;
        $data = $this->processReportParams(true);
        if (!$data) {
            // processReportParams sets jsObj error stuff
            return;
        }
        $jobID = $this->createBgProcess($data);
        if (!$jobID) {
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['bgProcFail'];
            return;
        }
        $jobFiles = $this->mkReportFilenames($jobID);
        $this->bgData->updateProcessRecord($jobID, $this->tenantID, $this->userID, ['jobFiles' => $jobFiles]);
        $logFile = $jobFiles['log'];
        $phpcli = $this->app->skinnyCli;
        $prog = 'Controllers.TPM.Report.TpMonitor.TpMonitorReportCli::createReport';
        $cmd = "/usr/bin/nohup $phpcli $prog $jobID > $logFile 2>&1 &";
        $execRtn = 0;
        $execOutput = [];
        ignore_user_abort(true);
        exec($cmd, $execOutput, $execRtn);
        if ($execOutput || $execRtn) {
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['bgProcFailInit'];
            return;
        }
        sleep(1);
        $this->setSecurityHashes($jobID, $jobFiles['csv']);
        $this->jsObj->Result = 1;
        $this->jsObj->JobID = $jobID;
    }


    /**
     * Report is done. Send the data table configuration.
     *
     * @return void
     */
    private function ajaxGetReport()
    {
        $this->jsObj->Result = 0;
        $jobID = (int)$this->app->clean_POST['jid'];
        if (!$jobID) {
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['bgProcNotExist'];
            return;
        }
        $rptData = $this->getDtConfig($jobID);
        if (!$rptData) {
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['bgProcNotExist'];
            return;
        }
        $this->jsObj->ShowRemed = $rptData['showRemed'];
        $this->jsObj->Result = 1;
        $this->jsObj->dtConfig = $rptData;
    }


    /**
     * Check user access to specified Third Party Profile
     *
     * @return void
     */
    private function ajaxCheckProfileAccess()
    {
        $tpm    = new ThirdParty($this->tenantID);
        $tpID   = $tpm->getIdByUserNumber($this->app->clean_POST['tpnum']);
        $access = $tpm->canAccess3PP($tpID);
        $this->jsObj->Result = 1;
        $this->jsObj->tpID = $tpID;
        $this->jsObj->ProfileAccess = ($access > 0) ? 1:0;
    }


    /**
     * Clear jobID from Session
     *
     * @return void
     */
    private function ajaxClearReport()
    {
        $this->session->forget('gdcReportJobID');
        $this->jsObj->Result = 1;
    }


    /**
     * Update the reports per-page setting for the current session.
     *
     * @return void;
     */
    private function ajaxUpdatePerPage()
    {
        $newPP = (int)$this->app->clean_POST['pp'];
        if (in_array($newPP, $this->rowsPerPage)) {
            $this->session->set('tpmtr.rowsPerPage', $newPP);
        }
        $this->jsObj->Result = 1;
    }


    /**
     * Turn generated report into a pdf
     *
     * @return void
     */
    public function fetchPDF()
    {
        $pdfFooter = [
            'top' => $this->trTxt['pdf']['warning'],
            'left' => '',
            'middle' => $this->trTxt['pdf']['created'],
        ];
        $pdf = new GenPDF($pdfFooter);
        $jobID = (int)$this->app->clean_POST['jid'];
        if (!$jobID) {
            echo $pdf->altErrorHandler($this->trTxt['error']['pdfNoJobID']);
            return;
        }
        $jobRec = $this->bgData->getBgRecord($jobID, $this->tenantID, $this->userID);
        if (!$jobRec) {
            echo $pdf->altErrorHandler($this->trTxt['error']['pdfNoBgRecord']);
            return;
        }
        $data = $this->getPdfRecords($jobRec->jobFiles['csv']);
        if (!$data) {
            echo $pdf->altErrorHandler($this->trTxt['error']['pdfNoBgRecord']);
            return;
        }
        $viewVals = [
            'sitePath' => $this->app->sitePath,
            'rxCacheKey' => $this->app->rxCacheKey,
            'headers'  => $data['headers'],
            'records' => $data['records'],
            'txt'     => $this->trTxt['pdf'],
            'pdfCss'  => $pdf->getOverrideCss(),
        ];
        $html = $this->app->view->render($this->tplRoot . $this->tplPdf, $viewVals);

        $pdfFileName = 'GDC_Report_' . date('Y-m-d_H-i') . '.pdf';
        if ($err = $pdf->generatePDF($pdfFileName, $html)) {
            echo $pdf->altErrorHandler($err);
        }
    }


    /**
     * Determine status of an existing (most recent) report
     *
     * @return mixed Array if report exists, else false;
     */
    protected function isReportInProgress()
    {
        $control = $this->bgData->getBgRecordByType('gdcReport', $this->tenantID, $this->userID);
        if ($control) {
            if ($control->status == 'running' || $control->status == 'completed') {
                if (isset($control->jobFiles['csv']) && file_exists($control->jobFiles['csv'])) {
                    $this->session->set('gdcReportJobID', $control->id);
                    $this->setSecurityHashes($control->id, $control->jobFiles['csv']);
                    return ['jid'=>$control->id, 'show'=> (($control->status == 'completed') ? 1:0)];
                }
            }
        }
        $this->session->forget('gdcReportJobID');
        return false;
    }

    /**
     * Determine if the "Needs Remediation Only" option was selected for the report
     *
     * @return int
     */
    protected function getRemedSetting()
    {
        if ($this->enableTrueMatch) {
            $control = $this->bgData->getBgRecordByType('gdcReport', $this->tenantID, $this->userID);

            if (!$control || !property_exists($control, 'stats')) {
                return 0;
            }

            $stats = unserialize($control->stats);
            if (array_key_exists('remed', $stats) and $stats['remed'] >=1) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Eval post data and return for further control.
     *
     * @param boolean $withRecs Set true if 'ttlRecs' is present in POST data;
     *
     * @return mixed Array of validated data, else false on error.
     */
    protected function processReportParams($withRecs = false)
    {
        $data = $this->app->clean_POST['data'];
        $data['ttlRecs'] = (!empty($data['ttlRecs'])) ? (int)$data['ttlRecs'] : 0;
        if (!$this->validateReportMode($data['rptMode'])) {
            return false;
        }
        // Clean up user input from profile list field
        if ($data['rptMode'] == 'list') {
            $newlines = ["\r\n", "\n", "\r"]; // these are newline characters to identify in order of precedence
            $data['modeData'] = str_replace([' ', '+', '%20'], ',', (string) $data['modeData']); // ignore spaces
            $data['modeData'] = str_replace($newlines, ',', $data['modeData']); // newlines stand in as separators
            $data['modeData'] = str_replace([',,,,', ',,,', ',,'], ',', $data['modeData']); // empty flds are removed
            $data['modeData'] = trim($data['modeData'], ","); // trailing newlines (empty data as last item in array) rm
        }

        if ($data['rptMode'] != 'all') {
            $data['modeData'] = $this->validateModeData($data['rptMode'], $data['modeData']);
            if (!$data['modeData']) {
                return false;
            }
        }
        if (!empty($data['rgList']) && !$this->validateRegionList($data['rgList'])) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['rgInvalid'];
            return false;
        }
        if (!empty($data['ctList']) && !$this->validateCountryList($data['ctList'])) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['ctInvalid'];
            return false;
        }
        if ($withRecs && !$data['ttlRecs']) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->trTxt['error']['noRecordsFound'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['noProfiles'];
            return false;
        }

        $statusValues = ['gdcReview', 'gdcAll', 'gdcReview0'];
        $data['status'] = (in_array($data['status'], $statusValues)) ? $data['status'] : 'gdcReview';

        if (date('Y-m-d', strtotime((string) $data['stDate'])) != $data['stDate']) {
            $data['stDate'] = '1970-01-01';
        }
        if (date('Y-m-d', strtotime((string) $data['endDate'])) != $data['endDate']) {
            $data['endDate'] = date('Y-m-d');
        }

        if (array_key_exists('remed', $data)) {
            if ($data['remed'] != 'showRemed') {
                unset($data['remed']);
            }
        }

        return $data;
    }


    /**
     * Validate the selected mode.
     *
     * @param string $mode Valid mode of operation
     *
     * @return boolean True on success, else false;
     */
    protected function validateReportMode($mode)
    {
        $allowModes = ['all', '3pList', 'list', 'typecat', 'tpID'];
        if (!in_array($mode, $allowModes)) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['invalidMode'];
            return false;
        }
        return true;
    }


    /**
     * If mode is not 'all', validate mode data.
     *
     * @param string $mode Valid mode of operation as per this->validateReportMode
     * @param string $data String of posted mode data [optional ONLY if mode is 3pList]
     *
     * @return mixed Return false on any error.
     *               For list/typecat mode: return result of respective validation method.
     *               For 3pList mode: return true if pass.
     */
    protected function validateModeData($mode, $data = '')
    {
        $rtnData = false;
        if ($mode == 'list') {
            $check = $this->validateProfileList($data);
            if ($check['err']) {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
                $this->jsObj->ErrMsg = $check['errMsg'];
            } else {
                $rtnData = $check['profiles'];
            }
        } elseif ($mode == 'typecat') {
            $check = $this->validateTypeCats($data);
            if ($check['err']) {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
                $this->jsObj->ErrMsg = $check['errMsg'];
            } else {
                $rtnData = $check['catList'];
            }
        } elseif ($mode == '3pList') {
            if (!$this->session->get('last3pListGdcReview')) {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
                $this->jsObj->ErrMsg = $this->trTxt['error']['invalidMode'];
            } else {
                $rtnData = true;
            }
        }
        return $rtnData;
    }


    /**
     * Validate supplied profile numbers (singles or ranges)
     *
     * @param string $list String of desired profiles to be checked.
     *
     * @return array Results and error status, plus error title/message (if applicable).
     */
    protected function validateProfileList($list)
    {

        $errMsg = '';
        if (strlen($list) > self::LIST_MAX_CHAR) {
            $errMsg = $this->trTxt['error']['pfMaxChars'];
        }
        if (!preg_match('/^[A-Z]{2,}3P-\d{5,}([:,][A-Z]{2,}3P-\d{5,})*$/', $list)) {
            $errMsg = $this->trTxt['error']['pfInvalidList'];
        }
        $refs = explode(',', $list);
        $ranges = [];
        $singles = [];
        $singlePat = '/^[A-Z]{2,}3P-\d{5,}$/';
        $rangePat = '/^([A-Z]{2,}3P-\d{5,}):([A-Z]{2,}3P-\d{5,})$/';
        foreach ($refs as $ref) {
            if (preg_match($rangePat, $ref, $match) && ($match[1] < $match[2])) {
                $rangeIDs = $this->data->convertToTpID([$match[1], $match[2]]);
                if (!isset($rangeIDs[$match[1]])) {
                    $errMsg = $this->trTxt['error']['pfInvalidTpNum'];
                    $errMsg = str_replace('{user_tp_num}', $match[1], (string) $errMsg);
                    return ['err' => true, 'errMsg' => $errMsg];
                } elseif (!isset($rangeIDs[$match[2]])) {
                    $errMsg = $this->trTxt['error']['pfInvalidTpNum'];
                    $errMsg = str_replace('{user_tp_num}', $match[2], (string) $errMsg);
                    return ['err' => true, 'errMsg' => $errMsg];
                }
                $ranges[] = ['st' => $rangeIDs[$match[1]], 'end' => $rangeIDs[$match[2]]];
            } elseif (preg_match($singlePat, $ref)) {
                $rangeID =  $this->data->convertToTpID([$ref]);
                if (!isset($rangeID[$ref])) {
                    $errMsg = $this->trTxt['error']['pfInvalidTpNum'];
                    $errMsg = str_replace('{user_tp_num}', $ref, (string) $errMsg);
                    return ['err' => true, 'errMsg' => $errMsg];
                }
                $singles[] = $rangeID[$ref];
            }
        }
        if (empty($ranges) && empty($singles)) {
            $errMsg = $this->trTxt['error']['pfNoRefs'];
        }



        return [
            'err' => (!$errMsg) ? false : true,
            'errMsg' => $errMsg,
            'profiles' => [
                'ranges' => $ranges,
                'list' => implode(',', $singles),
            ],
        ];
    }


    /**
     * Check that all requested categories are valid.
     *
     * @param string $idList CSV list of catID:typeID
     *
     * @return array Results and error status, plus error title/message (if applicable).
     */
    protected function validateTypeCats($idList)
    {
        if (!$idList) {
            return ['err' => true, 'errMsg' => $this->trTxt['error']['tcNoRefs']];
        }
        if (!preg_match('/^\d+:\d+(,\d+:\d+)*$/', $idList)) {
            return ['err' => true, 'errMsg' => $this->trTxt['error']['tcInvalidChars']];
        }
        $refs = explode(',', $idList);
        $ids = [];
        foreach ($refs as $typeCat) {
            [$typeID, $catID] = explode(':', $typeCat);
            if ($this->data->getTypeCat($catID, $typeID) != $typeCat) {
                return ['err' => true, 'errMsg' => $this->trTxt['error']['tcInvalidRef']];
                break;
            }
            $ids[] = $catID;
        }
        return ['err' => false, 'errMsg' => '', 'catList' => implode(',', $ids)];
    }


    /**
     * Validate format of country (iso) list.
     *
     * @param string $list CSV list of ISOs
     *
     * @return boolean True if pass, else false
     */
    protected function validateCountryList($list)
    {
        return preg_match('/^([A-Z]{2}, *)*[A-Z]{2}$/i', trim($list));
    }


    /**
     * Validate format of region list.
     *
     * @param string $list CSV list of region IDs
     *
     * @return boolean True if pass, else false
     */
    protected function validateRegionList($list)
    {
        return preg_match('/^(\d+(,\d+)*)$/', trim($list));
    }


    /**
     * Build types/categories array for current tenant
     *
     * @return array
     */
    protected function getTypeCats()
    {
        $cats = $this->data->getTypeCatMap();
        $map = [];
        foreach ($cats as $c) {
            $map[] = [
                'typeName' => $c->typeName,
                'catName' => $c->catName,
                'htmlID' => 'typecat-'. $c->typeID .'-'. $c->catID,
                'value' => $c->typeID .':'. $c->catID,
            ];
        }
        return $map;
    }


    /**
     * Generate and store security hashes for monitoring and download
     *
     * @param integer $jobID   bgProcess.id
     * @param string  $csvFile Name of csv data file
     *
     * @return void
     */
    private function setSecurityHashes($jobID, $csvFile)
    {
        $this->secHash->setSecurityHash(
            $this->tenantID,
            $this->userID,
            $jobID,
            'gdcReport',
            'monitor'
        );
        $this->secHash->setSecurityHash(
            $this->tenantID,
            $this->userID,
            $csvFile,
            'gdcReport',
            'download'
        );
    }


    /**
     * Build report file path, without file extension.
     * All report files are the same with the exception of the extension.
     *
     * @param integer $jobID bgProcess.id
     *
     * @return array Array of absolute filenames for report files.
     */
    private function mkReportFilenames($jobID)
    {
        $jobID = (int)$jobID;
        $rptPath = '/var/local/bgProcess/'. $this->app->mode;
        if (!is_dir($rptPath)) {
            mkdir($rptPath);
        }
        $rptPath .= '/gdcReport';
        if (!is_dir($rptPath)) {
            mkdir($rptPath);
        }
        $rptPath .= '/c'. $this->tenantID .'u'. $this->userID .'b' . $jobID . '-gdc-report';
        return [
            'csv' => $rptPath .'.csv',
            'idx' => $rptPath .'.idx',
            'log' => $rptPath .'.log',
        ];
    }


    /**
     * Setup and create the background process
     *
     * @param array $data Current data parameters.
     *
     * @return integer Return jobID (bgProcess.id) if created, else 0;
     */
    private function createBgProcess($data)
    {
        $stats['mode']  = $data['rptMode'];
        if ($data['rptMode'] == '3pList') {
            $stats['query'] = $this->data->getSearchQueryFromGdcReview();
        } else {
            $stats['query'] = $this->data->getSearchQuery($data);
        }

        if ($this->enableTrueMatch and array_key_exists('remed', $data)) {
            $stats['remed'] = 1;
        }

        $stats['debug'] = 0; // set to 1 to output to log file
        $stats['rptCols'] = $this->getDtCols();
        $insData = ['clientID' => $this->tenantID, 'userID'   => $this->userID, 'jobType'  => 'gdcReport', 'recordsToProcess' => (int)$data['ttlRecs'], 'stats' => $stats, 'expires' => date('Y-m-d H:i:s', strtotime('+ 30 days'))];
        $jobID = $this->bgData->createProcess($this->tenantID, $this->userID, $insData);
        return (int)$jobID;
    }


    /**
     * Check for valid bg process, and send back data table init config.
     *
     * @param integer $jobID bgProcess.id
     *
     * @return mixed Array of config settings to initialize the data table, else false on error.
     */
    private function getDtConfig($jobID)
    {
        $jobID = (int)$jobID;
        if (!$jobID) {
            return false;
        }

        // check for active bgProgress record
        $jobRec = $this->bgData->getBgRecord($jobID, $this->tenantID, $this->userID);

        if (!$jobRec || $jobRec->status == 'error') {
            // no record, kill it off.
            $this->secHash->remove('gdcReport.monitorHash');
            $this->secHash->remove('gdcReport.downloadHash');
            if (is_object($jobRec)) {
                // update bgProcess record for "soft" error status
                $data = [
                    'procID' => null,
                    'status' => 'error',
                ];

                $this->bgData->updateProcessRecord($jobRec->id, $this->tenantID, $this->userID, $data);
            }
            $this->jsObj->ErrTitle = $this->trTxt['error']['errTitle'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['bgProcNotExist'];
            return false;
        }

        $stats = unserialize($jobRec->stats);

        if (empty($stats['sums'])) {
            $stats['sums'] = [];
        }
        $profHits = (!empty($stats['sums']['profiles'])) ? ceil($stats['sums']['profiles']) : 0;
        $ttlHits  = (!empty($stats['sums']['hits']))     ? ceil($stats['sums']['hits'])     : 0;
        $profErrs = (!empty($stats['sums']['errors']))   ? ceil($stats['sums']['errors'])   : 0;

        $miniReport = [
            'checked'  => ceil($jobRec->recordsCompleted),
            'profHits' => $profHits,
            'ttlHits'  => $ttlHits,
            'profErrs'  => $profErrs
        ];
        return [
            'jobID'        => $jobRec->id,
            'dataFile'     => $jobRec->jobFiles['csv'],
            'ttlRecords'   => $profHits,
            'miniReport'   => $miniReport,
            'dtPageLength' => $this->session->get('tpmtr.rowsPerPage', 50),
            'dtPageSizes'  => $this->rowsPerPage,
            'dtHeadCols'   => $stats['rptCols'],
            'pgAuthToken'  => $this->baseCtrl->getViewValue('pgAuthToken'),
            'pagingType'   => ($profHits <= 3000) ? 'select_links' : 'input_nohide',
            'showRemed'    => (array_key_exists('remed', $stats) && $stats['remed'] >=1) ? 1 : 0
        ];
    }

    /**
     * Setup of report columns for datatable.
     *
     * @return array of column objects.
     */
    protected function getDtCols()
    {
        $configs = [
            'screened'     => ['width' => '14%', 'class' => ''],
            'country'      => ['width' => '15%', 'class' => ''],
            'undetermined' => ['width' => '4%', 'class' => 'cent imgUndetermined'],
            'trueMatch'    => ['width' => '4%', 'class' => 'cent imgTrueMatch'],
            'falsePos'     => ['width' => '4%', 'class' => 'cent imgFalsePos'],
        ];
        $cols = $this->trTxt['rptCols'];
        $rtn = [];
        $i = 0;
        foreach ($cols as $key => $title) {
            $width = '';
            $className = '';
            if (array_key_exists($key, $configs)) {
                $className = $configs[$key]['class'];
                $width = $configs[$key]['width'];
            }

            $className = ($i > 4 && empty($className)) ? 'cent' : $className;
            $rtn[$i] = [
                'data' => $i,
                'title' => $title,
                'name' => $title,
                'className' => $className,
                'width' => $width,
                'searchable' => false,
                'orderable'  => false,
                'search' => [
                    'value' => '',
                    'regex' => false,
                ]
            ];
            $i++;
        }
        return $rtn;
    }


    /**
     * Read CSV report into an array.
     *
     * @param string $csvFile Full path to CSV data file
     *
     * @return array Array with header (column names) and recs (records), or empty array
     */
    protected function getPdfRecords($csvFile)
    {
        $rtn = [
            'header' => [],
            'recs'   => []
        ];
        if (!file_exists($csvFile)) {
            return [];
        }
        if (!$csv = file_get_contents($csvFile)) {
            return [];
        }

        $rows = explode(PHP_EOL, $csv);
        $rtn = [
            'headers' => [],
            'records' => []
        ];
        $imagePath = $_SERVER['DOCUMENT_ROOT'] .'/cms/images';
        foreach ($rows as $k => $r) {
            if (!$r) {
                continue;
            }
            $data = CSV::parse($r);
            if ($k == 0) {
                $rtn['headers'] = array_values($data);
            } else {
                if (stristr((string) $data[1], '|ne|')) {
                    $data[1] = htmlspecialchars((string) $data[1], ENT_QUOTES, 'UTF-8', false);
                    $data[1] = str_replace('|ne|', '<span class="fas fa-exclamation-triangle" '
                            . 'style="font-size: 16px;"></span>', $data[1]);
                }
                //  SEC-2091, cached reports won't have data[10]
                if (isset($data[10])) {
                    $data[10] = str_replace(['|nr|', '|r|'], '', (string) $data[10]);
                }

                $rtn['records'][] = $data;
            }
        }
        return $rtn;
    }


    /**
     * Setup translated text array data for use in errors/templates/js etc.
     *
     * @return array Array of translated text.
     */
    protected function setupTranslation()
    {

        if (empty($this->trTxt)) {
            $this->app->trans->langCode = 'EN_US';
            $this->app->trans->tenantID = $this->tenantID;
            $tr = $this->app->trans->groups($this->trGroups);
            $maxCharsErr = str_replace('{numeric_value}', self::LIST_MAX_CHAR, (string) $tr['gdc_list_greater_than']);
            $trTxt = [
                'error' => [
                    'errTitle'       => $tr['title_operation_failed'],
                    'bgProcFail'     => $tr['bg_process_fail_creating'],
                    'bgProcFailInit' => $tr['bg_process_fail_launch'],
                    'bgProcNotExist' => $tr['bg_process_not_exist'],
                    'ctInvalid'      => $tr['invalid_country_ref'],
                    'noProfiles'     => $tr['gdc_no_profiles_to_check'],
                    'pdfNoJobID'     => $tr['gdc_pdf_invalid_id'],
                    'pdfNoBgRecord'  => $tr['gdc_pdf_no_record'],
                    'pfInvalidList'  => $tr['gdc_profile_list_invalid'],
                    'pfInvalidMode'  => $tr['gdc_invalid_profile_source'],
                    'pfInvalidTpNum' => $tr['gdc_invalid_profile_number'],
                    'pfMaxChars'     => $maxCharsErr,
                    'pfNoRefs'       => $tr['gdc_no_usable_profiles'],
                    'rgInvalid'      => $tr['invalid_region_ref'],
                    'tcInvalidChars' => $tr['gdc_typecat_invalid_chars'],
                    'tcInvalidRef'   => $tr['gdc_typecat_invalid_ref'],
                    'tcNoRefs'       => $tr['gdc_no_typecat_refs'],
                    'noRecordsFound'      => $tr['gdc_no_records_report']
                ],
                'tpl' => [
                    'filtersTitle'   => $tr['gdc_addl_filters'],
                    'allSelected'    => $tr['all_capital_a'],
                    'allProfiles'    => $tr['gdc_all_3p_profiles'],
                    'catModeTitle'   => $tr['gdc_choose_3p_cats'],
                    'currListMode'   => $tr['gdc_current_3p_list'],
                    'charsRemain'    => $tr['characters_remaining'],
                    'clear'          => $tr['clear_results'],
                    'cntProfileHits' => $tr['gdc_count_with_hits'],
                    'countries'      => $tr['countries_capc'],
                    'ctrySelTitle'   => $tr['countries_selected'],
                    'dateRangeTitle' => $tr['select_date_range'],
                    'dateStart'      => $tr['start_date'],
                    'dateEnd'        => $tr['end_date'],
                    'export'         => $tr['icon_export'],
                    'listModeTitle'  => $tr['gdc_enter_profiles'],
                    'mainTitle'      => $tr['gdc_results_profiles_hits'],
                    'pgTitle'        => $tr['gdc_pgTitle'],
                    'popTypeTitle'   => $tr['gdc_define_pop_type'],
                    'print'          => $tr['icon_print'],
                    'profileList'    => $tr['gdc_profile_list'],
                    'pfChecked'      => $tr['gdc_profiles_checked'],
                    'pfHits'         => $tr['gdc_profiles_with_hits'],
                    'pfNameErr'      => $tr['gdc_name_errors'],
                    'pfTtlHits'      => $tr['gdc_total_hits'],
                    'regions'        => $tr['regions_capr'],
                    'rgnsSelTitle'   => $tr['regions_selected'],
                    'statusTitle'    => $tr['gdc_define_review_status'],
                    'statusNR'       => $tr['gdc_needs_review'],
                    'statusR'        => $tr['gdc_reviewed'],
                    'statusB'        => $tr['gdc_both_statuses'],
                    'tpTypeCat'      => $tr['gdc_type_category'],
                    'listEx'         => [
                        'enterRefs'   => $tr['gdc_list_example_refs'],
                        'sepRefs'     => $tr['gdc_list_example_seps'],
                        'singleRange' => $tr['gdc_list_example_types'],
                        'spaceBreaks' => $tr['gdc_list_example_spaces'],
                        'useColon'    => $tr['gdc_list_example_range'],
                        'example'     => $tr['gdc_list_example'],
                    ],
                ],
                'js' => [
                    'errTitle'        => $tr['title_operation_failed'],
                    'tcInvalidRef'    => $tr['gdc_typecat_invalid_ref'],
                    'pfNoRefs'        => $tr['gdc_no_usable_profiles'],
                    'allSelected'     => $tr['all_capital_a'],
                    'cancel'          => $tr['cancel'],
                    'countingRecs'    => $tr['gdc_counting_recs'],
                    'countrySelect'   => $tr['head_select_country'],
                    'errAccessMsg'    => $tr['error_thirdparty_access'],
                    'errAccessTitle'  => $tr['title_access_denied'],
                    'genReport'       => $tr['gdc_gen_report'],
                    'nameErrorIcon'   => $tr['gdc_name_error_icon'],
                    'nothingToDo'     => $tr['nothing_to_do'],
                    'noRecords'       => $tr['gdc_no_records_report'],
                    'preparingToGen'  => $tr['gdc_preparing_to_gen_report'],
                    'profileCheck'    => $tr['gdc_profiles_to_check'],
                    'regionSelect'    => $tr['select_regions'],
                    'readyToRunTitle' => $tr['ready_to_run_report'],
                    'dateToday'       => $tr['today'],
                    'dateBlank'       => $tr['blank_date'],
                ],
                // do not mess with rptCols order.
                'rptCols' => [
                    'profileNum'   => $tr['tp_fld_Profile_num'],
                    'name'         => $tr['name_person'],
                    'country'      => $tr['fld_Country'],
                    'region'       => $tr['fld_Region'],
                    'rptType'      => $tr['type'],
                    'screened'     => $tr['gdc_rptCol01'],
                    'undetermined' => $tr['gdc_rptCol06'],
                    'trueMatch'    => $tr['gdc_rptCol07'],
                    'falsePos'     => $tr['gdc_rptCol08'],
                    'hits'         => $tr['gdc_hits'],
                    'status'       => $tr['col_status'],
                ],
            ];

            if ($this->enableTrueMatch) {
                $trTxt['rptCols'][] = 'Needs Remediation';
            }

            if ($this->isPdf) {
                // only add the extra load if this is a pdf request
                $pdfCreated   = str_replace('{pdfDate}', date('D M d Y'), (string) $tr['pdf_created_datetime']);
                $pdfCreated   = str_replace('{pdfTime}', date('h:i:s'), $pdfCreated);
                $companyName  = $this->data->getTenantName();
                $pdfWarn      = str_replace('{companyName}', $companyName, (string) $tr['pdf_warning_message']);
                $trTxt['pdf'] = [
                    'companyName'   => $companyName,
                    'created'       => $pdfCreated,
                    'warning'       => $pdfWarn,
                    'errIconTitle'  => $tr['gdc_name_error_icon'],
                    'reportTitle'   => $tr['gdc_results_profiles_hits'],

                ];
            }
            $this->trTxt = $trTxt;
        }
        return $this->trTxt;
    }
}
