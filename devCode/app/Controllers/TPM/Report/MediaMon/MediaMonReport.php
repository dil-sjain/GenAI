<?php
/**
 * TPM - Analytics - Media Monitor Report
 */

namespace Controllers\TPM\Report\MediaMon;

use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Report\MediaMon\MediaMonReportData;
use Lib\Traits\AjaxDispatcher;
use Lib\Session\SecurityHash;
use Models\Cli\BackgroundProcessData;
use Models\ThirdPartyManagement\ThirdParty;
use Lib\Csv;
use Lib\Legacy\GenPDF;
use Models\ADMIN\Config\MediaMonitor\MediaMonitorData;

/**
 * Controls the Media Monitor report
 */
#[\AllowDynamicProperties]
class MediaMonReport
{
    use AjaxDispatcher;
    public const MIN_PAGE_SIZE = 15;
    public const LIST_MAX_CHAR = 2000;
    public const REPORT_TITLE = 'Media Monitor Usage &mdash; Third Party Profiles with Hits';

    /**
     * @var \Skinny\Skinny|null Class instance
     */
    protected $app = null;

    /**
     * @var Base|null Base controller instance
     */
    protected $baseCtrl = null;

    /**
     * @var BackgroundProcessData|null Class instance
     */
    protected $bgData = null;

    /**
     * @var MediaMonReportData|null Class instance
     */
    protected $data = null;

    // protected $txtTr = null;

    /**
     * @var mixed|null Instance of application session
     */
    protected $session  = null;

    /**
     * @var SecurityHash|null Security hash
     */
    protected $secHash  = null;

    /**
     * @var int TPM tenant ID
     */
    protected $tenantID = 0;

    /**
     * @var int users.id of user generating report
     */
    protected $userID   = 0;

    /**
     * @var array|array[] Text translations (more)
     */
    protected $trTxt = [];

    /**
     * @var string[] Text translation group
     */
    protected $trGroups = ['report_3p_monitor', 'report_media_monitor', 'ddq_misc', 'common'];

    /**
     * @var string Root directory for templates
     */
    protected $tplRoot = 'TPM/Reports/MediaMon/';

    /**
     * @var string Template for UI report
     */
    protected $tpl  = 'MediaMonReport.tpl';

    /**
     * @var string Template for PDF
     */
    protected $tplPdf = 'MediaMonReportPdf.tpl';

    /**
     * @var string[] Required asset files to load
     */
    protected $reqFiles = [
        '/assets/js/src/errBox.js',
        '/assets/jq/jqx/jqwidgets/jqxdatetimeinput.js',
        '/assets/js/TPM/Reports/mmr.js',
        '/assets/css/TPM/Reports/mmr.css',
        '/assets/css/pbar.css'
    ];

    /**
     * @var int[] Options to select rows per page
     */
    protected $rowsPerPage = [self::MIN_PAGE_SIZE, 20, 30, 50, 75, 100];

    /**
     * @var bool Indicates if PDF is being generated
     */
    protected $isPdf = false;


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
        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $this->baseCtrl = new Base($this->tenantID, $initValues);
        $this->data     = new MediaMonReportData($this->tenantID);
        $this->bgData   = new BackgroundProcessData();
        $this->trTxt    = $this->setupTranslation();
        $this->secHash  = new SecurityHash();
        $this->session  = $this->app->session;
        $this->userID   = $this->session->authUserID;
    }


    /**
     * Setup for initial page load
     *
     * @return void
     */
    public function initialize()
    {
        $rptInProgress = $this->isReportInProgress();
        $showConfig = (!$rptInProgress) ? 1 : 0;
        $this->baseCtrl->setViewValue('pgTitle', $this->trTxt['tpl']['pgTitle']);
        $this->baseCtrl->setViewValue('rptPbarName', 'mmr-pbar');
        $this->baseCtrl->setViewValue('tplTxt', $this->trTxt['tpl']);
        $this->baseCtrl->setViewValue('jsTxt', json_encode($this->trTxt['js']));
        $this->baseCtrl->setViewValue('rptInProgress', $rptInProgress);
        $this->baseCtrl->setViewValue('showConfig', $showConfig);
        $this->baseCtrl->setViewValue('showIcons', (($this->session->get('searchPerms.userGlobalAlways')) ? 1 : 0));
        $this->baseCtrl->setViewValue('use3pSearch', (($this->session->get('last3pListGdcReview')) ? 1 : 0));
        $this->baseCtrl->addFileDependency($this->reqFiles);
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

        $hits = $this->data->countHits($data);

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
        $prog = 'Controllers.TPM.Report.MediaMon.MediaMonReportCli::createReport';
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
        $this->jsObj->ProfileAccess = ($access > 0) ? 1 : 0;
    }


    /**
     * Clear jobID from Session
     *
     * @return void
     */
    private function ajaxClearReport()
    {
        $this->session->forget('MediaMonReportJobID');
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
            $this->session->set('mmr.rowsPerPage', $newPP);
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
        $html = $this->app->view->fetch($this->tplRoot . $this->tplPdf, $viewVals);

        $pdfFileName = 'MediaMon_Report_' . date('Y-m-d_H-i') . '.pdf';
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
        $control = $this->bgData->getBgRecordByType('MediaMonReport', $this->tenantID, $this->userID);
        if ($control) {
            if ($control->status == 'running' || $control->status == 'completed') {
                if (isset($control->jobFiles['csv']) && file_exists($control->jobFiles['csv'])) {
                    $this->session->set('MediaMonReportJobID', $control->id);
                    $this->setSecurityHashes($control->id, $control->jobFiles['csv']);
                    return ['jid' => $control->id, 'show' => (($control->status == 'completed') ? 1 : 0)];
                }
            }
        }
        $this->session->forget('MediaMonReportJobID');
        return false;
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

        if ($withRecs && !$data['ttlRecs']) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->trTxt['error']['noRecordsFound'];
            $this->jsObj->ErrMsg = $this->trTxt['error']['noProfiles'];
            return false;
        }
        $data['status'] = 'MediaMonReview';
        if (date('Y-m-d', strtotime((string) $data['stDate'])) != $data['stDate']) {
            $data['stDate'] = '1970-01-01';
        }
        if (date('Y-m-d', strtotime((string) $data['endDate'])) != $data['endDate']) {
            $data['endDate'] = date('Y-m-d');
        }
        return $data;
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
            'MediaMonReport',
            'monitor'
        );
        $this->secHash->setSecurityHash(
            $this->tenantID,
            $this->userID,
            $csvFile,
            'MediaMonReport',
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
        $rptPath = '/var/local/bgProcess/' . $this->app->mode;
        if (!is_dir($rptPath)) {
            mkdir($rptPath);
        }
        $rptPath .= '/MediaMonReport';
        if (!is_dir($rptPath)) {
            mkdir($rptPath);
        }
        $rptPath .= '/c' . $this->tenantID . 'u' . $this->userID . 'b' . $jobID . '-MediaMon-report';
        return [
            'csv' => $rptPath . '.csv',
            'idx' => $rptPath . '.idx',
            'log' => $rptPath . '.log',
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

        $stats['query'] = $this->data->getSearchQuery($data);

        $stats['debug'] = 0; // set to 1 to output to log file
        $stats['rptCols'] = $this->getDtCols();
        $insData = ['clientID' => $this->tenantID, 'userID'   => $this->userID, 'jobType'  => 'MediaMonReport', 'recordsToProcess' => (int)$data['ttlRecs'], 'stats' => $stats, 'expires' => date('Y-m-d H:i:s', strtotime('+ 30 days'))];
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
            $this->secHash->remove('MediaMonReport.monitorHash');
            $this->secHash->remove('MediaMonReport.downloadHash');
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

        $srchRemain = (new MediaMonitorData($this->tenantID))->getRemainingSearches($this->tenantID);

        $miniReport = [
            'checked'    => $jobRec->recordsCompleted,
            'srchRemain' => "&nbsp;" . number_format($srchRemain, 0),
        ];
        return [
            'jobID'        => $jobRec->id,
            'dataFile'     => $jobRec->jobFiles['csv'],
            'ttlRecords'   => $stats['sums']['profiles'],
            'miniReport'   => $miniReport,
            'dtPageLength' => $this->session->get('mmr.rowsPerPage', 50),
            'dtPageSizes'  => $this->rowsPerPage,
            'dtHeadCols'   => $stats['rptCols'],
            'pgAuthToken'  => $this->baseCtrl->getViewValue('pgAuthToken'),
            'pagingType'   => ($stats['sums']['profiles'] <= 3000) ? 'select_links' : 'input_nohide',
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
                   'name'         => ['width' => '19%', 'class' => ''],
                   'assocSrch'    => ['width' => '27%', 'class' => ''],
                   'entitySrch'   => ['width' => '27%', 'class' => ''],
                   'ttlSrch'      => ['width' => '27%', 'class' => ''],
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
        foreach ($rows as $k => $r) {
            if (!$r) {
                continue;
            }
            $data = CSV::parse($r);
            $rtn['records'][] = $data;
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
                    'noRecordsFound'     => $tr['gdc_no_records_report'],
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
                ],
                'tpl' => [
                    'srchRemain'     => $tr['gdc_srch_remain'],
                    'allSelected'    => $tr['all_capital_a'],
                    'allProfiles'    => $tr['gdc_all_3p_profiles'],
                    'catModeTitle'   => $tr['gdc_choose_3p_cats'],
                    'currListMode'   => $tr['gdc_current_3p_list'],
                    'charsRemain'    => $tr['characters_remaining'],
                    'clear'          => $tr['clear_results'],
                    'cntProfileHits' => $tr['gdc_count_with_hits'],
                    'dateRangeTitle' => $tr['select_date_range'],
                    'dateStart'      => $tr['start_date'],
                    'dateEnd'        => $tr['end_date'],
                    'export'         => $tr['icon_export'],
                    'listModeTitle'  => $tr['gdc_enter_profiles'],
                    'mainTitle'      => self::REPORT_TITLE,
                    'pgTitle'        => $tr['gdc_pgTitle'],
                    'popTypeTitle'   => $tr['gdc_define_pop_type'],
                    'print'          => $tr['icon_print'],
                    'profileList'    => $tr['gdc_profile_list'],
                    'pfChecked'      => $tr['gdc_profiles_checked'],
                    'pfHits'         => $tr['gdc_profiles_with_hits'],
                    'statusTitle'    => $tr['gdc_define_review_status'],
                    'statusNR'       => $tr['gdc_needs_review'],
                    'statusR'        => $tr['gdc_reviewed'],
                    'statusB'        => $tr['gdc_both_statuses'],
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
                    'countingRecs'    => $tr['gdc_counting_recs'],
                    'errAccessMsg'    => $tr['error_thirdparty_access'],
                    'errAccessTitle'  => $tr['title_access_denied'],
                    'cancel'          => $tr['cancel'],
                    'genReport'       => $tr['gdc_gen_report'],
                    'nameErrorIcon'   => $tr['gdc_name_error_icon'],
                    'readyToRunTitle' => $tr['ready_to_run_report'],
                    'dateToday'       => $tr['today'],
                    'nothingToDo'     => $tr['nothing_to_do'],
                    'noRecords'       => $tr['gdc_no_records_report'],
                    'preparingToGen'  => $tr['gdc_preparing_to_gen_report'],
                    'profileCheck'    => $tr['gdc_profiles_to_check'],
                    'dateBlank'       => $tr['blank_date'],
                ],
                // do not mess with rptCols order.
                'rptCols' => [
                    'name'         => $tr['mm_3P_name'],
                    'assocSrch'    => $tr['mm_assoc_search'],
                    'entitySrch'   => $tr['mm_entity_search'],
                    'ttlSrch'      => $tr['mm_total_search'],

                ],
            ];
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
                    'reportTitle'   => self::REPORT_TITLE,

                ];
            }
            $this->trTxt = $trTxt;
        }
        return $this->trTxt;
    }
}
