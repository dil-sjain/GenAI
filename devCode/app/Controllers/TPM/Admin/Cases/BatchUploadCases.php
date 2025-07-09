<?php
/**
 * Controller: admin tool Batch Upload Cases
 *
 * @keywords admin, batch upload cases
 */

namespace Controllers\TPM\Admin\Cases;

use Controllers\ThirdPartyManagement\Base;
use Lib\IO;
use Lib\Session\SecurityHash;
use Lib\Traits\AjaxDispatcher;
use Models\Cli\BackgroundProcessData;
use Models\ThirdPartyManagement\Cases;
use Models\TPM\Admin\Cases\BatchUploadCasesData;
use Models\TPM\Admin\Utility\AdminFileManager;
use Models\Globals\UtilityUsage;

/**
 * Handles requests and responses for admin tool DdqLockedUsersAdmin
 */
class BatchUploadCases extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Admin/Cases/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'BatchUploadCases.tpl';

    /**
     * @var string Title of the current page
     */
    protected $pgTitle = 'Batch Case Upload';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var \Lib\Database\MySqlPdo $DB
     */
    protected $DB = null;

    /**
     * @var int
     */
    protected $clientID;

    /**
     * @var int
     */
    protected $userID;

    /**
     * @var boolean
     */
    protected $canAccess;

    /**
     * @var object Background Process Model instance
     */
    private $bgProcessData = null;

    /**
     * @var object BatchUploadCasesData Model instance
     */
    private $batchUploadData = null;

    /**
     * @var object AdminFileManager Model instance
     */
    private $fileManager = null;

    /**
     * @var object SecurityHash Model instance
     */
    private $securityHash = null;

    /**
     * @var array lookup array of bgProcess operations and post processing methods
     */
    private $opPostProcessors = null;


    /**
     * Constructor gets model instance and initializes other properties
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->clientID = $clientID;
        $this->userID = $this->session->get('authUserID');
        $this->bgProcessData = new BackgroundProcessData();
        $this->batchUploadData = new BatchUploadCasesData($this->clientID);
        $this->fileManager = new AdminFileManager();
        $this->canAccess = ($this->app->auth->isSuperAdmin);
        $this->securityHash = new SecurityHash();
        $this->opPostProcessors = ['complete-col-map' => 'ppCompleteColMap', 'save-col-map' => 'ppFetchColMap', 'fetch-col-map' => 'ppFetchColMap', 'fetch-csv-report' => 'ppFetchReport', 'fetch-data-report' => 'ppFetchReport', 'fetch-import-report' => 'ppFetchReport', 'fetch-rejection-report' => 'ppFetchReport', 'gen-csv-report' => 'ppGenerateReport', 'gen-data-report' => 'ppGenerateReport', 'gen-import-report' => 'ppGenerateReport'];
        $this->logFileTypes = ['data update', 'error'];
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgTitle', $this->pgTitle);

        // Detect if there's currently a job running, and set view vars accordingly
        $runningJob = $this->batchUploadData->catchRunningJob();
        $this->setViewValue('runningJobID', $runningJob['id']);
        $this->setViewValue('runningJobType', $runningJob['type']);

        // Make sure the File Manager's parent config settings are clean at the start
        $this->fileManager->clearParentConfig();

        $this->app->view->display($this->getTemplate(), $this->getViewValues());
        if ($_SERVER['REQUEST_URI'] == '/tpm/adm/case/batchupld') {
            (new UtilityUsage())->addUtility('Batch Upload Cases');
        }
    }




    /**
     * Detects if a batch job is currently running for the user and client.
     *
     * @return void
     */
    private function ajaxCatchRunningJob()
    {
        $runningJob = $this->batchUploadData->catchRunningJob();
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [[
            'runningJobID' => (int)$runningJob['id'],
            'runningJobType' => $runningJob['type'],
        ]];
    }



    /**
     * Inits creation of a new batch job.
     *
     * @return void
     */
    private function ajaxCreateNewJob()
    {
        $fileID = (int)\Xtra::arrayGet($this->app->clean_POST, 'fileID', 0);
        $newJobCreated = $this->batchUploadData->createNewJob($fileID);
        if ($newJobCreated && $newJobCreated->Result == 1
            && empty($newJobCreated->ErrTitle) && empty($newJobCreated->ErrMsg)
        ) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [[
                'jobCreated' => 1
            ]];
        } else {
            $this->jsObj->Args = [[
                'jobCreated' => 0
            ]];
            $this->jsObj->ErrTitle = $newJobCreated->ErrTitle;
            $this->jsObj->ErrMsg = $newJobCreated->ErrMsg;
        }
    }


    /**
     * Gathers data of batch jobs for a given user-subscriber combo.
     *
     * @return void
     */
    private function ajaxDisplayJobsFiles()
    {
        $jobsFilesDisplayed = $this->batchUploadData->displayJobs();
        if ($jobsFilesDisplayed && $jobsFilesDisplayed->Result == 1
            && empty($jobsFilesDisplayed->ErrTitle) && empty($jobsFilesDisplayed->ErrMsg)
        ) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [[
                'hasTpAccess' => $jobsFilesDisplayed->hasTpAccess,
                'jobs' => null,
                'unassignedFiles' => null
            ]];
            if (!empty($jobsFilesDisplayed->jobs)) {
                $this->jsObj->Args[0]['jobs'] = $jobsFilesDisplayed->jobs;
            }
            if (!empty($jobsFilesDisplayed->unassignedFiles)) {
                $this->jsObj->Args[0]['unassignedFiles'] = $jobsFilesDisplayed->unassignedFiles;
            }
        } else {
            $this->jsObj->ErrTitle = $jobsFilesDisplayed->ErrTitle;
            $this->jsObj->ErrMsg = $jobsFilesDisplayed->ErrMsg;
        }
    }


    /**
     * Inits deletion of a batch job.
     *
     * @return void
     */
    private function ajaxDropJob()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $jobDropped = $this->batchUploadData->dropJob($jobID);
        if ($jobDropped && $jobDropped->Result == 1) {
            $this->jsObj->Result = 1;
        } else {
            $this->jsObj->ErrTitle = $jobDropped->ErrTitle;
            $this->jsObj->ErrMsg = $jobDropped->ErrMsg;
        }
    }




    /**
     * Gathers data for File Information screen.
     *
     * @return void
     */
    private function ajaxFetchReport()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $type = \Xtra::arrayGet($this->app->clean_POST, 'type', '');
        if ($jobID <= 0 || empty($type) || !in_array($type, ['csv', 'data', 'import', 'rejection'])) {
            $this->jsObj->ErrTitle = 'Fetch report configuration error.';
            $this->jsObj->ErrMsg = 'Insufficient parameters were supplied.';
        }
        $fetchedRpt = $this->batchUploadData->fetchReport($jobID, $type);
        if ($fetchedRpt && $fetchedRpt->Result == 1
            && empty($fetchedRpt->ErrTitle) && empty($fetchedRpt->ErrMsg)
        ) {
            $this->ppFetchReport($fetchedRpt, $type);
        } else {
            $this->jsObj->ErrTitle = $fetchedRpt->ErrTitle;
            $this->jsObj->ErrMsg = $fetchedRpt->ErrMsg;
        }
    }


    /**
     * Dispatches the generation of a file report.
     *
     * @return void
     */
    private function ajaxGenerateReport()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $type = \Xtra::arrayGet($this->app->clean_POST, 'type', '');
        if ($jobID <= 0 || empty($type) || !in_array($type, ['csv', 'data', 'import'])) {
            $this->jsObj->ErrTitle = 'Generate report configuration error.';
            $this->jsObj->ErrMsg = 'Insufficient parameters were supplied.';
        }
        $genRpt = $this->batchUploadData->initCLI($jobID, $type);
        if ($genRpt && $genRpt->Result == 1 && empty($genRpt->ErrTitle) && empty($genRpt->ErrMsg)) {
            if ($genRpt->bgStatus == 'completed') {
                // The job is done and the report has been fetched, no need for a progress monitor.
                $this->ppFetchReport($genRpt, $type);
            } else {
                // The job is still running, so get the progress monitor going.
                $this->ppGenerateReport($genRpt, $type);
            }
        } else {
            $this->jsObj->ErrTitle = $genRpt->ErrTitle;
            $this->jsObj->ErrMsg = $genRpt->ErrMsg;
        }
    }




    /**
     * Gathers regions for any open subscriber cases.
     *
     * @return void
     */
    private function ajaxGetAvailableRegions()
    {
        $caseOnly = $this->app->ftr->tenantHas(\Feature::TENANT_TPM) ? 0 : 1;
        $cases = new Cases($this->clientID);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [
            ['regions' => $cases->getAvailableRegions()],
            $caseOnly,
        ];
    }



    /**
     * Sets proper session vars ahead of redirection to the File Manager for file uploads.
     *
     * @return void
     */
    private function ajaxInitMngDataFiles()
    {
        $this->fileManager->setParentConfig('/tpm/adm/case/batchupld', 'batchCase');
        $this->jsObj->Result = 1;
    }



    /**
     * Gathers data for Column Mapping screen.
     *
     * @return void
     */
    private function ajaxMapColumns()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $columnsMapped = $this->batchUploadData->mapColumns($jobID, 'map-cols');
        if ($columnsMapped && $columnsMapped->Result == 1
            && empty($columnsMapped->ErrTitle) && empty($columnsMapped->ErrMsg)
        ) {
            $this->ppFetchColMap($columnsMapped);
        } else {
            $this->jsObj->ErrTitle = $columnsMapped->ErrTitle;
            $this->jsObj->ErrMsg = $columnsMapped->ErrMsg;
        }
    }



    /**
     * AJAX handler for a job to be resumed.
     *
     * @return void
     */
    private function ajaxResumeJob()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $monitorComplete = (int)\Xtra::arrayGet($this->app->clean_POST, 'monitorComplete', 0);
        if ($monitorComplete == 1) {
            $this->securityHash->remove('batchCase.monitorHash');
        }
        $this->resumeJob($jobID);
    }



    /**
     * Inits the saving of data gathered from Column Mapping screen.
     *
     * @return void
     */
    private function ajaxSaveMappedColumns()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $mappedColVals = \Xtra::arrayGet($this->app->clean_POST, 'mappedColVals', []);
        $finalized = (int)\Xtra::arrayGet($this->app->clean_POST, 'finalized', 0);
        $operation = ($finalized > 0) ? 'complete-col-map' : 'save-col-map';
        $mappedColsSaved = $this->batchUploadData->mapColumns($jobID, $operation, $mappedColVals);
        if ($mappedColsSaved && $mappedColsSaved->Result == 1
            && empty($mappedColsSaved->ErrTitle) && empty($mappedColsSaved->ErrMsg)
        ) {
            $this->{$this->opPostProcessors[$operation]}($mappedColsSaved);
        } else {
            $this->jsObj->ErrTitle = $mappedColsSaved->ErrTitle;
            $this->jsObj->ErrMsg = $mappedColsSaved->ErrMsg;
        }
    }



    /**
     * Post-Processing of data for Complete Column Mapping screen.
     *
     * @param object $dataToPostProcess object to parse for necessary data
     *
     * @return void
     */
    private function ppCompleteColMap($dataToPostProcess)
    {
        $this->jsObj->Result = 1;
        if ($dataToPostProcess->rejected > 0) {
            $this->jsObj->Args = [[
                'jobID' => $dataToPostProcess->jobID,
                'startMonitor' => 0,
                'opHandler' => 'mapColumnsCompleteHndl',
                'rejected' => 1,
                'hasTpAccess' => $dataToPostProcess->hasTpAccess,
                'reqFields' => $dataToPostProcess->reqFields,
            ]];
        } else {
            $this->jsObj->Args = [[
                'jobID' => $dataToPostProcess->jobID,
                'startMonitor' => 0,
                'opHandler' => 'mapColumnsCompleteHndl',
                'rejected' => 0,
                'colMap' => $dataToPostProcess->ColMap,
                'colHeads' => $dataToPostProcess->ColHeads,
                'roles' => $dataToPostProcess->Roles,
                'sampleData' => $dataToPostProcess->SampleData,
                'groupList' => $dataToPostProcess->groupList,
                'fieldList' => $dataToPostProcess->fieldList,
            ]];
        }
    }


    /**
     * Post-Processing of data for Column Mapping screen.
     *
     * @param object $dataToPostProcess object to parse for necessary data
     *
     * @return void
     */
    private function ppFetchColMap($dataToPostProcess)
    {
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [[
            'jobID' => $dataToPostProcess->jobID,
            'startMonitor' => 0,
            'sampleData' => $dataToPostProcess->SampleData,
            'colMap' => $dataToPostProcess->ColMap,
            'colHeads' => $dataToPostProcess->ColHeads,
            'fieldOpts' => $dataToPostProcess->FieldOpts,
            'groupOpts' => $dataToPostProcess->GroupOpts,
            'roleOpts' => $dataToPostProcess->RoleOpts,
            'mapResult' => $dataToPostProcess->mapResult,
            'actions' => $dataToPostProcess->actions,
            'opHandler' => 'mapColumnsHndl',
        ]];
    }


    /**
     * Post-Processing of data for File Information screen.
     *
     * @param object $dataToPostProcess object to parse for necessary data
     * @param string $type              (optional) 'csv'|'data'|'import'|'rejection'
     *
     * @return void
     */
    private function ppFetchReport($dataToPostProcess, $type = '')
    {
        $this->jsObj->Result = 1;
        if (empty($type)) {
            // In case this method was called by resumeJob, deduce type from operation
            $strip = ['fetch-', '-report'];
            $type = str_replace($strip, '', (string) $dataToPostProcess->operation);
        }
        $opHandler = ($type == 'csv') ? 'preMappingRpt' : 'postMappingRpt';
        $this->jsObj->Args = [[
            'jobID' => $dataToPostProcess->jobID,
            'startMonitor' => 0,
            'reportData' => $dataToPostProcess->report,
            'actions' => $dataToPostProcess->actions,
            'opHandler' => $opHandler,
        ]];
        if ($type == 'data' || $type == 'import' || $type == 'rejection') {
            $this->jsObj->Args[0]['reportType'] = $type;
            if ($type == 'rejection') {
                $this->jsObj->Args[0]['rejectionData'] = $dataToPostProcess->rejectionData;
            } elseif ($type == 'data') {
                $this->jsObj->Args[0]['hasBadData'] = !empty($dataToPostProcess->report['Bad Data']);
            }
        }
    }


    /**
     * Post-Processing of data for Report generation (used to start the progress monitor).
     *
     * @param object $dataToPostProcess object to parse for necessary data
     * @param string $type              (optional) csv, data or import
     *
     * @return void
     */
    private function ppGenerateReport($dataToPostProcess, $type = '')
    {
        $this->jsObj->Result = 1;
        if (empty($type)) {
            // In case this method was called by resumeJob, deduce type from operation
            $strip = ['gen-', '-report'];
            $type = str_replace($strip, '', (string) $dataToPostProcess->operation);
        }
        $opHandler = ($type == 'csv') ? 'preMappingRpt' : 'postMappingRpt';
        $this->jsObj->Args = [[
            'jobID' => $dataToPostProcess->jobID,
            'opHandler' => $opHandler,
            'startMonitor' => 1,
        ]];
        // Set the security hash for the bgmonitor script to check against
        $this->securityHash->setSecurityHash(
            $this->clientID,
            $this->userID,
            $dataToPostProcess->jobID,
            'batchCase',
            'monitor'
        );
    }



    /**
     * Facilitates the generation of a batch template and download streaming.
     *
     * @return array dataFile and fileName
     */
    public function downloadBatchTemplate()
    {
        $regions = explode(',', (string) \Xtra::arrayGet($this->app->clean_POST, 'dlRegions', 0));
        $principalsQty = \Xtra::arrayGet($this->app->clean_POST, 'dlPrincipalsQty', 0);
        $this->batchUploadData->streamTemplate($regions, $principalsQty);
        $this->jsObj->Result = 1;
    }


    /**
     * Facilitates the generation of a rejection report and download streaming.
     *
     * @return void
     */
    public function downloadRejectionRpt()
    {
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $this->batchUploadData->streamRejectionRpt($jobID);
        $this->jsObj->Result = 1;
    }

    /**
     * Signals the next operation to proceed, routed from ajaxResumeJob.
     * Feeds returned data from the resumed operation to appropriate post-processing method.
     *
     * @param int $jobID id from g_bgProcess table
     *
     * @return void
     */
    private function resumeJob($jobID)
    {
        $jobResumed = $this->batchUploadData->resumeJob($jobID);
        if ($jobResumed && $jobResumed->Result == 1
            && empty($jobResumed->ErrTitle) && empty($jobResumed->ErrMsg)
        ) {
            $this->{$this->opPostProcessors[$jobResumed->operation]}($jobResumed);
        } else {
            $this->jsObj->ErrTitle = $jobResumed->ErrTitle;
            $this->jsObj->ErrMsg = $jobResumed->ErrMsg;
        }
    }


    /**
     * Dispatches cols specs data for ColSpecs view.
     * This view is opened in a separate window via Col Mapping screen.
     *
     * @return void
     */
    public function viewColSpecs()
    {
        $this->tplRoot = 'TPM/Admin/Cases/BatchUploadCases/';
        $this->tpl = 'ColSpecs.tpl';
        $this->setViewValue('pgTitle', 'Column Specs');
        $this->setViewValue('colSpecs', $this->batchUploadData->getColumnSpecifications());
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }


    /**
     * Dispatches log file data for Log view.
     * This view is opened in a separate window via Col Mapping or File Info screen.
     *
     * @return void
     */
    public function viewLogFile()
    {
        $this->tplRoot = 'TPM/Admin/Cases/BatchUploadCases/';
        $this->tpl = 'Log.tpl';
        $this->setViewValue('pgTitle', 'Data Update');
        $jobID = (int)\Xtra::arrayGet($this->app->clean_POST, 'jobID', 0);
        $logType = ((in_array(\Xtra::arrayGet($this->app->clean_POST, 'logType', ''), $this->logFileTypes))
            ? \Xtra::arrayGet($this->app->clean_POST, 'logType', '')
            : ''
        );
        if ($jobID > 0 && !empty($logType)) {
            $file = $this->batchUploadData->getLogFile($jobID, $logType);
            if ($file && !empty($file['error'])) {
                $this->setViewValue('error', $file['error']);
            } elseif ($file && !empty($file['file'])) {
                $this->setViewValue('file', $file['file']);
            } else {
                $this->setViewValue('error', 'Nothing to show.');
            }
        } else {
            $this->setViewValue('error', 'Nothing to show.');
        }
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }
}
