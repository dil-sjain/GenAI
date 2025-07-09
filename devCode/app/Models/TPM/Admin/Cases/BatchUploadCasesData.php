<?php
/**
 * Model: super admin utility Batch Upload Cases
 *
 * @keywords cases, batch upload cases
 */

namespace Models\TPM\Admin\Cases;

use Lib\Csv;
use Lib\CsvIO;
use Lib\Csv\CsvParseFile;
use Lib\Legacy\UserType;
use Lib\Support\ForkProcess;
use Lib\Support\ValidationCustom;
use Models\Cli\BackgroundProcessData;
use Models\ImportData;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\LegacyUserAccess;
use Models\ThirdPartyManagement\ClientProfile;
use Models\TPM\Admin\Utility\AdminFileManager;

/**
 * Provides data access for admin utility Batch Upload Cases
 */
#[\AllowDynamicProperties]
class BatchUploadCasesData
{
    private $app = null;
    private $DB = null;
    private $clientID = 0;
    private $userID = 0;
    private $importOpType = null;
    private $importType   = null;
    private $fileManager = null;
    private $bgProcessData = null;
    private $subDir = null;
    private $rptDir = null;
    private $jobType = null;
    private $logOpen = null;
    public $logFiles = null;
    public $phases = null;
    protected $hasTpAccess = null;
    private $legacyUserAccess = null;

    /**
     * Constructor - initialization
     *
     * @param integer $clientID Client ID
     *
     * @return void
     */
    public function __construct($clientID = 0)
    {
        // allow PHPUNIT to mock LegacyUserAccess
        if (!class_exists('\LegacyUserAccessAlias')) {
            class_alias(\Models\ThirdPartyManagement\LegacyUserAccess::class, 'LegacyUserAccessAlias');
        }

        \Xtra::requireInt($clientID, 'clientID must be an integer value.');
        if ($clientID <= 0) {
            throw new \Exception('Invalid Client ID value.');
        }
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->clientID = $clientID;
        $this->userID = $this->app->session->authUserID;
        $this->legacyUserAccess = new \LegacyUserAccessAlias($this->clientID);
        $this->hasTpAccess = $this->has3pAccess();

        // import class settings
        $this->importOpType = 'INSERT';
        $this->importType = ($this->hasTpAccess) ? 3 : 1;

        $this->fileManager = new AdminFileManager();
        $this->bgProcessData = new BackgroundProcessData();
        $this->subDir = $this->jobType = 'batchCase';
        $this->rptDir = '/var/local/adminData/'. $this->app->getMode() .'/batchCase/uid'. $this->userID .'/';
        $this->logOpen = ['badCsvFile'    => null, 'badDataFile'   => null, 'badDataRows'   => null, 'batchErrorLog' => null, 'logFile'       => null, 'rejectedRows'  => null, 'rowValIdx'     => null];
        $this->logFiles = ['badCsvFile'    => '_bad_csv_log.txt', 'badDataFile'   => '_bad_data_log.txt', 'badDataRows'   => '_failed_records.csv', 'batchErrorLog' => '_batch_error.log', 'logFile'       => '_bg.log', 'rejectedRows'  => '_rejected_rows.csv', 'rowValIdx'     => '_valIndex.idx'];
        // step progression to complete batch insertion
        $this->phases = [-1 => 'Rejected', 0  => 'New', 1  => 'File Info', 2  => 'Mapping', 3  => 'Mapped', 4  => 'Data Validated', 5  => 'Batch Completed'];
    }



    /**
     * Checks for a running batchCase background process for a given user/subscriber combo
     *
     * @param bool $deadAsNotRunning If true (default), set 'dead' to false
     *
     * @return array Contains jobID and processType items
     */
    public function catchRunningJob($deadAsNotRunning = true)
    {
        $rtn = ['id' => 0, 'type' => ''];
        $job = $this->bgProcessData->hasRunningProcess('batchCase', $this->clientID, $this->userID);
        if (is_object($job)) {
            $stats = unserialize($job->stats);
            $rtn['id'] = $job->id;
            if ($stats['bgTask'] == 'gen-import-report') {
                $rtn['type'] = 'import';
            } elseif ($stats['bgTask'] == 'gen-data-report') {
                $rtn['type'] = 'data';
            } else {
                $rtn['type'] = 'csv';
            }
        } elseif ($deadAsNotRunning && $job === 'dead') {
            $job = false; // treat 'dead' as not having a running job
        }
        return $rtn;
    }

    /**
     * Configuration object for a specified uploaded admin file or a specified batchCase bgprocess job
     *
     * @param integer $fileID            g_adminFiles.id
     * @param integer $jobID             g_bgProcess.id
     * @param boolean $includeImportData if set to true, will include Import Data
     *
     * @return object Configuration object to be used for batchCase processes
     */
    private function config($fileID = 0, $jobID = 0, $includeImportData = false)
    {
        $config = (object)null;
        $config->fileData = (object)null;
        $config->jobData = (object)null;
        $fileID = (int)$fileID;
        $jobID = (int)$jobID;

        $fileSetup = $this->setupFiles($fileID);

        if (!$fileSetup->Result) {
            $config->fileData->fileManErr = $fileSetup->fileManErr;
        } else {
            $config->fileData->curFile = $fileSetup->curFile;
            $config->fileData->curFileInfo = $fileSetup->curFileInfo;
            $config->fileData->fileMap = $fileMap = $fileSetup->fileMap;
            $config->fileData->fileIDs = $fileIDs = $fileSetup->fileIDs;
            $config->fileData->curMap = $fileSetup->curMap;
        }

        $config->jobData->job = false;
        $config->jobData->stats = [];
        $config->jobData->filename = '';
        $config->jobData->fileFound = false;

        $jobData = $this->getBgProcJobs($jobID, $fileIDs, $fileMap);
        if ($jobData->Result) {
            $config->jobData->job = $jobData->job;
            $config->jobData->stats = $jobData->stats;
            $config->jobData->filename = $jobData->filename;
            $config->jobData->fileFound = $jobData->fileFound;
            if ($jobData->Result == 1) {
                $config->jobData->fullFilename = $jobData->fullFilename;
                $config->jobData->hasLog = $jobData->hasLog;
                $config->jobData->hasBadData = $jobData->hasBadData;
                $config->jobData->logFile = $jobData->logFile;
                $config->jobData->badDataFile = $jobData->badDataFile;
            } elseif ($jobData->Result == 2) {
                $config->jobData->jobs = $jobData->jobs;
                $config->jobData->unassignedFiles = $jobData->unassignedFiles;
                $config->jobData->fileIDs = $jobData->fileIDs;
            }
        } else {
            $config->jobData->ErrTitle = $jobData->ErrTitle;
            $config->jobData->ErrMsg = $jobData->ErrMsg;
        }
        if ($includeImportData) {
            $config->importData = new ImportData($this->clientID, $this->importOpType, $this->importType);
            $config->importData->info = (object)null;
            $config->importData->info->includeCustom = true;
            $config->importData->info->refRoles = $config->importData->getRefTypeLookup();
            $config->importData->info->map = $config->importData->getMapMeta($config->importData->info->includeCustom);
            $config->importData->info->groups = $config->importData->info->map['class'];
            $config->importData->info->groupsMap = $config->importData->info->map['map'];
            // Define reference columns
            $config->importData->info->refCols = $config->importData->getRefCols();
            // Define data columns
            $config->importData->info->dataCols = $config->importData->getDataCols(true);
        }
        return $config;
    }


    /**
     * Convert assoc array to object array
     *
     * @param array   $ary    Associative array to convert
     * @param boolean $sorted Sort array if true. Default is false
     *
     * @return array Convert array of v/t objects
     */
    private function convertToVtObjects($ary, $sorted = false)
    {
        $tmp = [];
        // format for csmutil.populateSelect
        foreach ($ary as $v => $t) {
            $obj = (object)null;
            $obj->v = $v;
            $obj->t = $t;
            $tmp[] = $obj;
        }
        if ($sorted) {
            asort($tmp);
        }
        return $tmp;
    }


    /**
     * Create an array of fields and its options.
     *
     * @param array $opts Initial array of fields with type and data info sub arrays.
     *
     * @return array Array of fields sorted into types
     */
    private function createFieldOpts($opts)
    {
        $rtn = [];
        foreach ($opts as $type => $t) {
            $rtn[$type] = [];
            foreach ($t as $key => $data) {
                // sub array (ex: ref)
                if (is_array($data)) {
                    foreach ($data as $d) {
                        $rtn[$type][$d->v] = $d->t;
                    }
                } else {
                    $rtn[$type][$data->v] = $data->t;
                }
            }
        }
        return $rtn;
    }


    /**
     * Create an array of groups.
     *
     * @param array $opts Initial array of VT object groups.
     *
     * @return array Array of groups
     */
    private function createGroupOpts($opts)
    {
        $rtn = [];
        foreach ($opts as $o) {
            $rtn[$o->v] = $o->t;
        }

        return $rtn;
    }



    /**
     * Take a new file and initiate a new "job" in the bgProcess table.
     *
     * @param int $fileID File ID from g_adminFiles table, via admin file class;
     *
     * @return object $newJob Boolean value for success, as well as error title/message on failure;
     */
    public function createNewJob($fileID)
    {
        $newJob = (object)null;
        $newJob->ErrTitle = $newJob->ErrMsg = '';
        $newJob->Result = false;
        $fileID = (int)$fileID;
        $config = $this->config($fileID);

        // We're going to subject this file to a high degree of scrutiny. Here goes:

        // Rule out any errors occuring during the file's configuration
        if (!empty($config->fileData->fileManErr)
            || !array_key_exists($fileID, $config->jobData->unassignedFiles)
        ) {
            $newJob->ErrTitle = 'Invalid Request';
            $newJob->ErrMsg = 'File does not have unassigned status';
            return $newJob;
        }

        // Test that the file can be found
        $filename = $config->fileData->fileMap[$fileID]['filename'];
        $fullFilename = $this->fileManager->getPathFromID($fileID);
        if (!file_exists($fullFilename)) {
            $newJob->ErrTitle = 'File Not Found';
            $newJob->ErrMsg = "The selected data file $filename does not exist.";
            return $newJob;
        }

        // Append trailing LF if needed
        CsvParseFile::fixCsvEof($fullFilename);

        // Test that the CSV is a valid CSV file.
        $buffer = '';
        $csv = new CsvParseFile($buffer, $fullFilename, true);
        if (!$csv->isCsvFile()) {
            $newJob->ErrTitle = 'Invalid CSV File';
            $newJob->ErrMsg = "The selected data file $filename is not suitable for use with this utility.";
            return $newJob;
        }

        // Test that the CSV's field counts contain no variances between rows.
        while ($rec = $csv->parseFile($buffer)) {
            // Zilch to do here. Just let it parse away and rack up any Variants.
            $a = 1; // silence phpcs
        }
        $rpt = $csv->getReport(true);
        if ($rpt['Variants'] > 0) {
            $newJob->ErrTitle = 'CSV File Contains Varying Field Counts';
            $newJob->ErrMsg = "Field count in one or more CSV records does not match  count in first record.<br />"
                . "Expecting " . $rpt['Number of Fields'] . " fields in all " . $rpt['Records'] . " records.";
            return $newJob;
        }
        unset($csv);

        $fileHash = md5_file($fullFilename);
        // check for existing filehash.
        if ($this->bgProcessData->hashExists($fileHash)) {
            $newJob->ErrTitle = 'Data Source Already Exists';
            $newJob->ErrMsg = "The selected data file $filename has already been used with this utility. "
                . "Note: it may have been under another file name, or by another user.";
            return $newJob;
        }

        // Note: stats is serialized and sanitized by bgData->createProcess;
        $stats = ['fid' => $fileID, 'filename' => $filename, 'fullFilename' => $fullFilename, 'fileHash' => $fileHash, 'csvReport' => false, 'colMap' => false, 'phase' => 'New', 'phaseID' => 0, 'status' => ['mapped'    => 0, 'validated' => 0, 'imported'  => 0]];

        $fileList = [];
        foreach ($this->logFiles as $f) {
            $fileList[] = $this->rptDir . $filename . $f;
        }

        $data = ['jobType'  => $this->jobType, 'stats'    => $stats, 'fileHash' => $fileHash, 'jobFiles' => $fileList];

        if ($this->bgProcessData->createProcess($this->clientID, $this->userID, $data)) {
            $newJob->Result = 1;
        } else {
            $newJob->ErrTitle = 'Unexpected Error';
            $newJob->ErrMsg = 'New job creation failed';
        }
        return $newJob;
    }


    /**
     * Create an array of all principal fields bases on number of principals.
     *
     * @param integer $num  Number of principals
     * @param string  $type Type of field information to return. (template, name, field or all)
     *
     * @return array Array of data as specified by type.
     */
    public function createPrincipalFields($num = 10, $type = 'all')
    {
        $principalTemplate = [1  => ['name' => '', 'field' => 'principal[num]'], 2  => ['name' => 'Email', 'field' => 'p[num]email'], 3  => ['name' => 'Phone', 'field' => 'p[num]phone'], 4  => ['name' => 'Relationship', 'field' => 'pRelationship[num]'], 5  => ['name' => 'Owner', 'field' => 'bp[num]Owner'], 6  => ['name' => 'Percent Owenership', 'field' => 'p[num]OwnPercent'], 7  => ['name' => 'Board Member', 'field' => 'bp[num]BoardMem'], 8  => ['name' => 'Key Manager', 'field' => 'bp[num]KeyMgr'], 9  => ['name' => 'Key Consultant', 'field' => 'bp[num]KeyConsult'], 10 => ['name' => 'Unknown', 'field' => 'bp[num]Unknown']];
        if ($type == 'template') {
            return $principalTemplate;
        }
        $max = 10; // max num principals.
        $min = 1; // min num principals.
        $num = (is_numeric($num)) ? $num : 10;
        $num = ($num <= $max && $num >= $min) ? intval($num) : 10;

        for ($i=1; $i<=$num; $i++) {
            foreach ($principalTemplate as $p) {
                $name = trim('Principal '. $i .' '. $p['name']);
                $field = str_replace('[num]', $i, $p['field']);
                $principalFields['name'][] = $name;
                $principalFields['field'][] = $field;
                $principalFields['all'][$i][] = ['name' => $name, 'field' => $field];
            }
        }
        if (in_array($type, ['name', 'field', 'all'])) {
            return $principalFields[$type];
        }
        return $principalFields;
    }


    /**
     * Display jobs retrieved fron the bgProcess table.
     *
     * @return object $jobsDisplayed Boolean value for success, as well as error title/message on failure;
     */
    public function displayJobs()
    {
        $jobsDisplayed = (object)null;
        $config = $this->config();
        if (!empty($config->fileData->fileManErr)) {
            $jobsDisplayed->ErrTitle = 'Configuration Error';
            $jobsDisplayed->ErrMsg = $config->fileData->fileManErr;
            return $jobsDisplayed;
        }
        $jobsDisplayed->Result = 1;
        $jobsDisplayed->hasTpAccess = $this->hasTpAccess;
        $jobsDisplayed->jobs = ($config->jobData->jobs ?: '');
        $jobsDisplayed->unassignedFiles = ($config->jobData->unassignedFiles ?: '');
        return $jobsDisplayed;
    }


    /**
     * Drop job from the bgProcess table.
     *
     * @param int $jobID ID for the job (id from bg process table);
     *
     * @return object $jobDropped Modified mixed content passed back to controller.
     */
    public function dropJob($jobID)
    {
        $jobDropped = (object)null;
        $jobDropped->Result = 0;
        $jobDropped->ErrTitle = '';
        $jobDropped->ErrMsg = '';
        $config = $this->config(0, $jobID);
        if (!empty($config->fileData->fileManErr)) {
            $jobDropped->ErrTitle = 'Configuration Error';
            $jobDropped->ErrMsg = $config->fileData->fileManErr;
            return $jobDropped;
        }
        $jobID = (int)$jobID;
        if ($jobID > 0 && $this->bgProcessData->dropJobByID($jobID)) {
            if (property_exists($config->jobData, 'fullFilename')) {
                $fullFileName = $config->jobData->fullFilename;
                foreach ($this->logFiles as $title => $ext) {
                    $file = $fullFileName . $ext;
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
            }
            $jobDropped->Result = 1;
        } else {
            $jobDropped->ErrTitle = 'Background Process Error';
            $jobDropped->ErrMsg = 'Failed to drop job. Check with your administrator.';
        }
        return $jobDropped;
    }


    /**
     * Extract col def elements with labels
     *
     * @param array   $ary       Array from which to extract presentation labels
     * @param boolean $tvObjects Format for display
     *
     * @return array Sorted acssociative array of presentation labels
     */
    private function extractLabels($ary, $tvObjects = true)
    {
        $assoc = [];
        foreach ($ary as $v => $cfg) {
            foreach ($cfg as $prop => $t) {
                if ($prop != 'lbl') {
                    continue;
                }
                $assoc[$v] = $t;
            }
        }
        asort($assoc);
        if ($tvObjects) {
            $tmp = [];
            foreach ($assoc as $v => $t) {
                $obj = (object)null;
                $obj->v = $v;
                $obj->t = $t;
                $tmp[] = $obj;
            }
            $assoc = $tmp;
        }
        return $assoc;
    }



    /**
     * Extract a principal field's number from the fieldname.
     *
     * @param string $field Field name
     *
     * @return string principal field number
     */
    public function extractPrincipalNumber($field)
    {
        return preg_replace('([a-zA-z]+)', '', $field);
    }




    /**
     * Extract pertinent info from csv report to display on front-end
     *
     * @param array  $rawRpt CSV report from CsvFileParser::getReport()
     * @param string $type   Type of report being extracted, used in the switch.
     *
     * @return array Associative array of elements to show with formatted values
     */
    private function extractReport($rawRpt, $type)
    {
        $rpt = match ($type) {
            'data' => ['Data Source' => basename((string) $rawRpt['File']), 'File Size' => number_format(floatval($rawRpt['File Size']), 0), 'Read Operations' => number_format(floatval($rawRpt['File Seeks']), 0), 'Total Records' => number_format(floatval($rawRpt['Records']), 0), 'Variant Records' => number_format(floatval($rawRpt['Variants']), 0), 'Longest Record' => number_format(floatval($rawRpt['Max Record Length']), 0), 'Number of Fields' => number_format(floatval($rawRpt['Number of Fields']), 0), 'Field Names' => $rawRpt['First Record'], 'Good Records' => $rawRpt['Good Records'], 'Bad Records' => $rawRpt['Bad Records'], 'Bad Data' => $rawRpt['Bad Data']],
            'import' => ['Data Source' => basename((string) $rawRpt['File']), 'File Size' => number_format(floatval($rawRpt['File Size']), 0), 'Read Operations' => number_format(floatval($rawRpt['File Seeks']), 0), 'Total Records' => number_format(floatval($rawRpt['Records']), 0), 'Variant Records' => number_format(floatval($rawRpt['Variants']), 0), 'Longest Record' => number_format(floatval($rawRpt['Max Record Length']), 0), 'Number of Fields' => number_format(floatval($rawRpt['Number of Fields']), 0), 'Valid Rows' => number_format(floatval($rawRpt['Valid Rows']), 0), 'Rejected Rows' => number_format(floatval($rawRpt['Rejected Rows']), 0), 'Import Message' => $rawRpt['Import Message']],
            default => ['Data Source' => basename((string) $rawRpt['File']), 'File Size' => number_format(floatval($rawRpt['File Size']), 0), 'Read Operations' => number_format(floatval($rawRpt['File Seeks']), 0), 'Total Records' => number_format(floatval($rawRpt['Records']), 0), 'Variant Records' => number_format(floatval($rawRpt['Variants']), 0), 'Longest Record' => number_format(floatval($rawRpt['Max Record Length']), 0), 'Number of Fields' => number_format(floatval($rawRpt['Number of Fields']), 0), 'Field Names' => $rawRpt['First Record']],
        };
        return $rpt;
    }




    /**
     * Get data for a job report (CSV, Data, or Import.)
     *
     * @param integer $jobID ID for the job (id from bg process table);
     * @param string  $type  'csv'|'data'|'rejection'|'import'
     *
     * @return object $fileRpt Modified mixed content to be displayed for report.
     */
    public function fetchReport($jobID, $type)
    {
        $fileRpt = (object)null;
        $fileRpt->ErrTitle = '';
        $fileRpt->ErrMsg = '';
        $fileRpt->Result = 0;
        $jobID = (int)$jobID;
        if ($jobID <= 0 || !in_array($type, ['csv', 'data', 'import', 'rejection'])) {
            $fileRpt->ErrTitle = 'Fetch Report Error';
            $fileRpt->ErrMsg = 'The report cannot be fetched due to a problem with the supplied parameters';
            return $fileRpt;
        }
        $config = $this->config(0, $jobID);
        $stats = $config->jobData->stats;
        $logFile = $config->jobData->logFile;
        $badDataFile = $config->jobData->badDataFile;
        $format = ($type == 'rejection') ? 'csv' : $type;
        $fileRpt->report = $this->extractReport($stats[$format . "Report"], $format);
        $fileRpt->operation = ($type == 'rejection')
            ? 'fetch-rejection-report'
            : $config->jobData->stats['afterBgOp'];
        $fileRpt->hasLog = intval(file_exists($logFile) && filesize($logFile));
        $fileRpt->hasBadData = intval(
            file_exists($badDataFile) && filesize($badDataFile)
        );
        if ($type == 'rejection') {
            $fileRpt->rejectionData = $this->getRejectionData($stats);
        }
        if ($type == 'csv') {
            $fileRpt->actions = $this->setupActions($config, "fetch-csv-report");
        } else {
            $fileRpt->actions = [];
            if ($type == 'data') {
                $fileRpt->actions['gen-import-report'] = 'Import Batch';
            }
            $fileRpt->actions['ask-job'] = 'View Jobs/Files';
            $rejectedLogFile = '/var/local/adminData/' . $this->app->mode . '/batchCase/uid'
                . $this->userID . '/' . $fileRpt->report['Data Source'] . $this->logFiles['rejectedRows'];
            if (file_exists($rejectedLogFile)) {
                $fileRpt->actions['get-rejected-data'] = 'Export Rejected Rows<br />to Excel';
            }
            if ($type == 'data' && $this->app->auth->isSuperAdmin) {
                $fileRpt->actions['view-error-log'] = 'View Error Log';
                $fileRpt->actions['view-log'] = 'View Log File';
            }
        }
        $fileRpt->Result = 1;
        $fileRpt->jobID = $jobID;
        return $fileRpt;
    }





    /**
     * Get job(s) from the bgProcess table.
     *
     * @param int   $jobID   Current jobID;
     * @param array $fileIDs File ID's for this user;
     * @param array $fileMap Map of current files;
     *
     * @return object $jobs Mixed content including job/stats arrays, and job/file info.
     */
    private function getBgProcJobs($jobID, $fileIDs = false, $fileMap = false)
    {
        $jobs = (object)null;
        $jobs->Result = false;
        $jobs->job = false;
        $jobs->stats = [];
        $jobs->filename = '';
        $jobs->fileFound = false;
        if ($jobID > 0) {
            if ($tmp = $this->bgProcessData->getBgRecord($jobID)) {
                $jobs->stats = unserialize($tmp->stats);
                $jobs->filename = $jobs->stats['filename'];
                $jobs->fullFilename = $jobs->stats['fullFilename'];
                $fileHash = '';
                $jobs->logFile = $jobs->fullFilename .'_bg.log';
                $jobs->badDataFile = $jobs->fullFilename .'_bad_data.txt';

                // Get the filename stored in g_adminFiles, as it could have been renamed.
                $currFileName = $this->fileManager->getFileInfoByID($jobs->stats['fid'])['filename'];
                if (empty($currFileName)) {
                    $jobs->ErrTitle = 'Uploaded CSV file not attached to job';
                    $jobs->ErrMsg = "Batch job's file is no longer affiliated with this job.<br />"
                        . "It was likely co-opted by another subscriber via the File Manager.<br />"
                        . "For best results, drop this job and begin again.";
                } elseif ($jobs->filename != $currFileName) {
                    $jobs->ErrTitle = 'Uploaded CSV filename has changed';
                    $jobs->ErrMsg = "Batch job's file is incorrectly named <strong>`$currFileName`</strong>"
                        . "<br />and may not be accessed.<br /><br />"
                        . "Rename file as <strong>`" . $jobs->filename . "`</strong>"
                        . "<br />via the File Manager in order to access the batch job";
                } elseif (file_exists($jobs->fullFilename)) {
                    $jobs->Result = true;
                    $jobs->fileFound = true;
                    $jobs->hasLog = intval(file_exists($jobs->logFile) && filesize($jobs->logFile));
                    $jobs->hasBadData = intval(file_exists($jobs->badDataFile) && filesize($jobs->badDataFile));
                    $fileHash = md5_file($jobs->fullFilename);
                    if ($jobs->stats['fileHash'] != $fileHash) {
                        // nullify previous file operations
                        $jobs->stats['fileHash'] = $fileHash;
                        $jobs->stats['csvReport'] = false;
                        $jobs->stats['colMap'] = false;
                        $jobs->stats['phase'] = 'New';
                        $jobs->stats['phaseID'] = 0;
                        $jobs->stats['status'] = ['validated' => 0, 'imported' => 0, 'accepted' => 0];
                        if ($jobs->hasLog) {
                            unlink($jobs->logFile);
                            $jobs->hasLog = 0;
                        }
                        if ($jobs->hasBadData) {
                            unlink($jobs->badDataFile);
                            $jobs->hasBadData = 0;
                        }
                    }

                    $this->updateStats($jobID, $jobs->stats);

                    $jobs->job = ['id' => $tmp->id, 'bgStatus' => $tmp->status, 'recordsToProcess' => $tmp->recordsToProcess, 'recordsCompleted' => $tmp->recordsCompleted, 'stats' => $jobs->stats];
                } else {
                    $jobs->ErrTitle = 'Missing CSV File';
                    $jobs->ErrMsg = 'Source file `' . $jobs->fullFilename . '` not found';
                }
            } else {
                $jobs->ErrTitle = 'Job Not Found';
                $jobs->ErrMsg = 'The requested job was not found.';
            }
        } else {
            $jobs->Result = 2;

            // check across all clients in order to account for files used with other clients
            $allJobs = $this->bgProcessData->getUserJobsByType($this->userID, $this->jobType);
            $jobs->jobs = [];
            foreach ($allJobs as $j) {
                $stats = unserialize($j->stats);
                if (array_key_exists($stats['fid'], $fileIDs)) {
                    $fileIDs[$stats['fid']] = 1;
                }
                if ($j->clientID != $this->clientID) {
                    continue;
                }
                $jobs->jobs[$j->id] = ['id' => $j->id, 'status' => $j->status, 'phase' => $stats['phase'], 'phaseID' => $stats['phaseID'], 'recordsToProcess' => $j->recordsToProcess, 'recordsCompleted' => $j->recordsCompleted, 'stats' => $stats];
            }
            $jobs->unassignedFiles = [];
            foreach ($fileIDs as $fileID => $assigned) {
                if ($assigned) {
                    continue;
                }
                $jobs->unassignedFiles[$fileID] = $fileMap[$fileID];
            }

            $jobs->fileIDs = $fileIDs;
        }

        return $jobs;
    }


    /**
     * Return data for the display of column specifications.
     *
     * @return array $columnSpecs column specifications data;
     */
    public function getColumnSpecifications()
    {
        $colSpecs = ['error' => '', 'refRoles' => [], 'refRolesDesc' => [], 'includeCustom' => true, 'grpClass' => [], 'grpLookup' => [], 'grpDesc' => [], 'refCols' => [], 'dataCols' => [], 'referenceInfo' => [], 'columnInfo' => []];

        if ($this->app->session->get('authUserType', 0) != UserType::SUPER_ADMIN
            || !$this->hasTpAccess
            || !$this->legacyUserAccess->allow('accSysAdmin')
        ) {
            $colSpecs['error'] = 'Access denied.';
            return $colSpecs;
        }
        $importData = new ImportData($this->clientID, $this->importOpType, $this->importType);
        $colSpecs['refRoles'] = $importData->getRefTypeLookup();
        $colSpecs['refRolesDesc'] = $importData->getRefTypeLookupDesc();

        // Define import class map
        $map = $importData->getMapMeta($colSpecs['includeCustom']);
        $colSpecs['grpClass'] = $map['class'];
        $colSpecs['grpLookup'] = $map['lookup'];
        $colSpecs['grpDesc'] = $map['description'];

        // Define reference columns
        $colSpecs['refCols'] = $importData->getRefCols();

        // Define data columns
        $colSpecs['dataCols'] = $importData->getDataCols($colSpecs['includeCustom']);

        // Extract info needed to dislay reference info
        $refs = [];
        $defs = $colSpecs['refCols'];
        foreach ($colSpecs['refRoles'] as $ref => $refName) {
            $refs[$ref] = [];
            foreach ($defs as $fld => $cfg) {
                if ($cfg['type'] == 'alias') {
                    continue;
                }
                if (array_key_exists('role', $cfg) && $ref == $cfg['role']) {
                    $refs[$ref][$fld] = ['desc' => $cfg['desc'], 'names' => $importData->getRefAliases($ref, $fld, $colSpecs['refCols'])];
                }
            }
        }
        $colSpecs['referenceInfo'] = $refs;

        // Extract info needed to dislay column names
        $cols = [];
        foreach ($colSpecs['dataCols'] as $grp => $defs) {
            $cols[$grp] = [];
            foreach ($defs as $fld => $cfg) {
                if (array_key_exists('type', $cfg)
                    && ($cfg['type'] == 'direct' || $cfg['type'] == 'lookup')
                ) {
                    if (array_key_exists('desc', $cfg)) {
                        $desc = $cfg['desc'];
                    } else {
                        $desc = $cfg['lbl'];
                    }
                    /*
                    if ($includeCustom && ($grp == 'cscf' || $grp == '3pcf')) {
                        $names = array($fld);
                    } else {
                        $names = $impData->getColAliases($grp, $fld, $dataCols);
                    }
                     */
                    $names = $importData->getColAliases($grp, $fld, $colSpecs['dataCols']);
                    $cols[$grp][$fld] = ['desc' => $desc, 'names' => $names];
                }
            }
        }
        $colSpecs['columnInfo'] = $cols;
        return $colSpecs;
    }


    /**
     * Return settings for the display of final column mapping.
     *
     * @param object $mappedColumns Mapped Column configuration
     *
     * @return object $mappedColumns Modified object;
     */
    private function getFinalMap($mappedColumns)
    {
        $mappedColumns->rejected = 0;
        $reqFields = $this->verifyRequiredColumns($mappedColumns->ColMap);
        if (!$reqFields['status']) {
            $stats = $mappedColumns->stats;
            $stats['phase'] = $this->phases[-1];
            $stats['phaseID'] = -1;
            $stats['rejected'] = 1;
            $stats['status']['rejected'] = 1;
            $stats['rejection-report'] = $reqFields['fields'];
            $this->updateStats($mappedColumns->jobID, $stats);
            $mappedColumns->rejected = 1;
            $mappedColumns->hasTpAccess = ($this->hasTpAccess) ? 1 : 0;
            $mappedColumns->reqFields = $reqFields['fields'];
            $mappedColumns->Result = 1;
            return $mappedColumns;
        }
        $mappedColumns->groupList = $this->createGroupOpts($mappedColumns->GroupOpts);
        $mappedColumns->fieldList = $this->createFieldOpts($mappedColumns->FieldOpts);
        $mappedColumns->Result = 1;
        return $mappedColumns;
    }



    /**
     * Get log file or errors
     *
     * @param integer $jobID   id from g_bgProcess table
     * @param string  $logType either 'data update' or 'error'
     *
     * @return array $rtn contains error and file items
     */
    public function getLogFile($jobID, $logType = '')
    {
        $rtn = ['error' => '', 'file' => ''];
        $jobID = (int)$jobID;
        if (empty($jobID) || empty($logType)
            || !$this->legacyUserAccess->allow('accSysAdmin')
            || $this->app->session->get('authUserType', 0) != UserType::SUPER_ADMIN
            || !($job = $this->bgProcessData->getBgRecord($jobID, $this->clientID, $this->userID))
            || !($stats = unserialize($job->stats))
        ) {
            $rtn['error'] = 'Access denied.';
            return $rtn;
        }

        // show bad data by default, unless log file is requested.
        $file = $stats['fullFilename'];
        if ($logType == "data update") {
            $file .= '_bg.log';
        } else {
            $file .= '_bad_data.txt';
        }
        if (!file_exists($file) || !filesize($file)) {
            $rtn['error'] = 'Nothing to show.';
            return $rtn;
        }
        $rtn['file'] = $file;
        return $rtn;
    }



    /**
     * Extract rejection data for use in Rejection Report
     *
     * @param array $stats contains data to be extracted
     *
     * @return array extracted data to be dispatched to the controller
     */
    private function getRejectionData($stats)
    {
        $data = [];
        foreach ($stats['csvReport']['First Record'] as $colNum => $name) {
            if ($stats['colMap'][$colNum]['r'] == 'ignore') {
                $rel = 'Column Ignored';
                $fld = '';
            } elseif ($stats['colMap'][$colNum]['g'] == 'ref') {
                $rel = 'Third Party';
                $fld = 'thirdPartyID';
            } elseif ($stats['colMap'][$colNum]['table'] == 'cases') {
                $rel = 'Case';
                $fld = $stats['colMap'][$colNum]['field'];
            } elseif ($stats['colMap'][$colNum]['table'] == 'subjectInfoDD') {
                $rel = 'Case Subject';
                $fld = $stats['colMap'][$colNum]['field'];
            } else {
                $rel = $stats['colMap'][$colNum]['table'];
                $fld = $stats['colMap'][$colNum]['field'];
            }
            $data[] = ['name' => $name, 'relation' => $rel, 'field' => $fld];
        }
        return $data;
    }


    /**
     * Determine if a client has 3P access.
     *
     * @return boolean True if has access, else false
     */
    private function has3pAccess()
    {
        if ($this->hasTpAccess === true || $this->hasTpAccess === false) {
            return $this->hasTpAccess;
        }

        $tpEnabled = $this->app->ftr->tenantHas(\Feature::TENANT_TPM);
        if ($this->legacyUserAccess->allow('acc3pMng')
            && $this->app->session->get('authUserType', 0) > UserType::VENDOR_ADMIN && $tpEnabled
        ) {
            return true;
        }
        return false;
    }



    /**
     *  Kick off CLI script for generation of csv, data or import reports.
     *
     * @param integer $jobID ID for the job (id from bg process table);
     * @param string  $type  csv, data or import
     *
     * @return object $fileRpt Modified mixed content to be displayed for report.
     */
    public function initCLI($jobID, $type)
    {
        $fileRpt = (object)null;

        $jobID = (int)$jobID;
        // prevent access from unauthorized callers.
        if ($jobID <= 0 || !in_array($type, ['csv', 'data', 'import'])) {
            $fileRpt->ErrTitle = 'Unauthorized';
            $fileRpt->ErrMsg = 'Access to this task is not authorized';
            return $fileRpt;
        }
        $config = $this->config(0, $jobID);
        $fullFilename = $config->jobData->fullFilename;
        $logFile = $config->jobData->logFile;
        $badDataFile = $config->jobData->badDataFile;
        $filename = $config->jobData->filename;
        $job = $config->jobData->job;
        $stats = $config->jobData->stats;
        $operation = "gen-$type-report";
        $fileRpt->operation = $operation;

        $buffer = '';
        $csv = new CsvParseFile($buffer, $fullFilename);
        $okay = $csv->isCsvFile();
        unset($csv);

        if (!$okay) {
            $fileRpt->ErrTitle = 'Invalid CSV File';
            $fileRpt->ErrMsg = "$filename has been modified and is no longer valid for use with this utility.";
            return $fileRpt;
        }
        // return if bg process is already running.
        if ($config->jobData->job['bgStatus'] == 'running') {
            $fileRpt->bgStatus = 'running';
            $fileRpt->jobID = $jobID;
            $fileRpt->Result = 1;
            return $fileRpt;
        }

        $esa_filename = escapeshellarg((string) $fullFilename);
        $cmd = "/usr/bin/wc -l $esa_filename";
        $tmp = trim(shell_exec($cmd));
        $approx = 0;
        $match = [];
        if (preg_match('/^([0-9]+) /', $tmp, $match)) {
            $approx = intval($match[1]);
        }
        if ($approx) {
            $stats['approx'] = $approx;
            $stats['bgTask'] = $operation;
            $stats['afterBgOp'] = str_replace('gen', 'fetch', $operation);
            $job['stats'] = $stats;
            if ($this->startBgProcess($job)) {
                $bgRow = $this->bgProcessData->getBgRecord($jobID);
                $stats = unserialize($bgRow->stats);
                $fileRpt->bgStatus = $bgRow->status;
                $fileRpt->jobID = $jobID;
                $fileRpt->Result = 1;
                if ($bgRow->status == 'completed') {
                    $fileRpt = $this->fetchReport($jobID, $type);
                    $fileRpt->bgStatus = 'completed';
                    if ($type == 'csv') {
                        $stats['phase'] = $this->phases[1];
                        $stats['phaseID'] = 1;
                    } elseif ($type == 'data') {
                        $stats['phase'] = $this->phases[4];
                        $stats['phaseID'] = 4;
                    } elseif ($type == 'import') {
                        $stats['phase'] = $this->phases[5];
                        $stats['phaseID'] = 5;
                        $this->fileManager->setProcessedById($stats['fid']);
                    }
                }
            } else {
                $fileRpt->ErrTitle = 'Background Process Status';
                $fileRpt->ErrMsg = 'Unable to initiate background process';
            }
        } else {
            $fileRpt->ErrTitle = 'Nothing to Do';
            $fileRpt->ErrMsg = 'CSV data file is empty';
        }
        $this->updateStats($jobID, $stats);
        return $fileRpt;
    }



    /**
     *  Verify and map spreadsheet to db columns.
     *
     * @param integer $jobID         Current jobID
     * @param string  $op            Current operation
     * @param mixed   $mappedColVals if not null, multi-dim array of roles, groups, fields
     *                               from Col Mapping saving/completion
     *
     * @return object $mappedColumns mixed content;
     */
    public function mapColumns($jobID, $op, mixed $mappedColVals = null)
    {
        $config = $this->config(0, $jobID, true);
        $mappedColumns = (object)null;
        $mappedColumns->jobID = $jobID;
        $mappedColumns->operation = $op;
        $notMapped = 1; // prove it wrong.
        if ($op == 'save-col-map' || $op == 'complete-col-map') {
            $notMapped = 0;
            $mappedColumns->operation = ($op == 'complete-col-map') ? 'complete-col-map': 'fetch-col-map';

            if (is_array($mappedColVals)
                && array_key_exists('roles', $mappedColVals) && is_array($mappedColVals['roles'])
                && array_key_exists('groups', $mappedColVals) && is_array($mappedColVals['groups'])
                && array_key_exists('fields', $mappedColVals) && is_array($mappedColVals['fields'])
            ) {
                $roles = $mappedColVals['roles'];
                $groups = $mappedColVals['groups'];
                $fields = $mappedColVals['fields'];
                $colMap = [];

                for ($idx = 0; $idx < count($roles); $idx++) {
                    // if a role is empty (not specified) set as ignore.
                    $cfg = ['r' => (!empty($roles[$idx]) ? $roles[$idx] : 'ignore'), 'g' => (!empty($groups[$idx]) ? $groups[$idx] : ''), 'f' => (!empty($fields[$idx]) ? $fields[$idx] : '')];

                    // validation not necessary on an ignored column.
                    if ($cfg['r'] != 'ignore') {
                        if ($cfg['g'] == 'ref') {
                            $importSource = $config->importData->info->refCols;
                        } elseif (array_key_exists($cfg['g'], $config->importData->info->dataCols)
                            && array_key_exists($cfg['f'], $config->importData->info->dataCols[$cfg['g']])
                        ) {
                            $importSource = $config->importData->info->dataCols[$cfg['g']];
                        } else {
                            $mappedColumns->Result = 0;
                            $mappedColumns->ErrTitle = '"Class" and "Map To" selections error.';
                            $mappedColumns->ErrMsg = 'Please provide appropriate "Class" and "Map To" '
                                . 'selections for rows whose "Role" selection is not set to "Ignore".';
                            return $mappedColumns;
                        }
                        $cfg['validation'] = $importSource[$cfg['f']]['validation'] ?? '';

                        if ($importSource[$cfg['f']]['type'] != 'alias') {
                            $cfg['table'] = $importSource[$cfg['f']]['table'] ?? '';

                            $cfg['desc'] = $importSource[$cfg['f']]['desc'] ?? '';

                            if ($cfg['g'] == 'ref') {
                                if ($importSource[$cfg['f']]['type'] == 'lookup') {
                                    $cfg['field'] = $importSource[$cfg['f']]['on'];
                                } else {
                                    $cfg['field'] = $importSource[$cfg['f']]['fld'];
                                }
                            } else {
                                $cfg['field'] = $importSource[$cfg['f']]['field'];
                            }
                        }
                    }
                    $colMap[$idx] = $cfg;
                    if (empty($roles[$idx])) {
                        $notMapped++;
                    } elseif (empty($groups[$idx]) && $roles[$idx] != 'ignore') {
                        $notMapped++;
                    } elseif (empty($fields[$idx]) && $roles[$idx] != 'ignore') {
                        $notMapped++;
                    }
                }
                $config->jobData->stats['colMap'] = $colMap;
                $this->updateStats($jobID, $config->jobData->stats);
            }
        }

        if ($config->jobData->stats['phase'] == 'Mapped') {
            $notMapped = 0;
        }

        if ($notMapped >= 0) {
            $config->jobData->stats['phase'] = 'Mapping';
            $config->jobData->stats['phaseID'] = 2;
        } else {
            $notMapped = 1;
        }

        // fg process
        $roles = $config->importData->info->refRoles;
        $roles['required'] = 'Required Data';
        $roles['optional'] = 'Optional Data';
        $roles['ignore'] = 'Ignore';
        $mappedColumns->Roles = $roles;
        $mappedColumns->RoleOpts = $this->convertToVtObjects($roles);
        $opts = [];
        $opts['ref'] = [];
        foreach ($config->importData->info->groups as $grp => $group) {
            $refType = $config->importData->info->groupsMap[$grp];
            if (!array_key_exists($refType, $opts['ref'])) {
                $tmp = [];
                foreach ($config->importData->info->refCols as $f => $rc) {
                    if (($rc['type'] == 'direct' || $rc['type'] === 'lookup')
                        && array_key_exists('role', $rc) && $rc['role'] === $refType
                    ) {
                        $tmp[$f] = $rc['lbl'];
                    }
                }
                $opts['ref'][$refType] = $this->convertToVtObjects($tmp);
            }
            $opts[$grp] = $this->extractLabels($config->importData->info->dataCols[$grp]);
        }
        $mappedColumns->GroupOpts = $this->convertToVtObjects($config->importData->info->groups);
        $mappedColumns->FieldOpts = $opts;
        $mappedColumns->ColHeads = $config->jobData->stats['csvReport']['First Record'];

        $fieldCnt = (!empty($mappedColumns->ColHeads) && is_array($mappedColumns->ColHeads))
            ? count($mappedColumns->ColHeads)
            : 0;
        $refsNeeded = [];
        $refsFound  = [];
        $primaryRef = false;
        $primaryRefType = null;
        $colMap = $config->importData->mapColumns(
            $config->jobData->stats['csvReport']['First Record'],
            $config->jobData->stats['colMap'],
            $config->jobData->stats['csvReport']['Empty Values'],
            'new-case'
        );

        // map columns and get sample records
        $buffer = '';
        $maxSamples = 25;
        $samples = [];
        $csv = new CsvParseFile($buffer, $config->jobData->fullFilename);
        $srchFlds  = [];
        $cnt = 0;
        while ($rec = $csv->parseFile($buffer)) {
            if (!$cnt++) {
                continue; // skip first row
            } else {
                $srch = ["\n", "\r", "\t"];
                $rplc = ' ';
                // rein in size of sample data
                for ($idx = 0; $idx < $fieldCnt; $idx++) {
                    $data = str_replace($srch, $rplc, trim((string) $rec[$idx]));
                    $origLen = mb_strlen($data);
                    $data = mb_substr($data, 0, 100);
                    if ($origLen != mb_strlen($data)) {
                        $data .= '&hellip;';
                    }
                    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8', false);
                    $rec[$idx] = $data;
                }
                $samples[] = $rec;
                if (count($samples) >= $maxSamples) {
                    break;
                }
            }
        }
        unset($csv); // closes file

        // update column map and map metaData
        $config->jobData->stats['colMap'] = $colMap;
        if ($notMapped > 0) {
            $config->jobData->stats['phase'] = 'Mapping';
            $config->jobData->stats['phaseID'] = 2;
            $config->jobData->stats['status']['mapped'] = 0;
            $mappedColumns->mapResult = 0;
        } else {
            $config->jobData->stats['phase'] = 'Mapped';
            $config->jobData->stats['phaseID'] = 3;
            $config->jobData->stats['status']['mapped'] = 1;
            $mappedColumns->mapResult = 1;
        }
        $config->jobData->stats['notMapped'] = $notMapped;
        $this->updateStats($jobID, $config->jobData->stats);
        $mappedColumns->SampleData = $samples;
        $mappedColumns->ColMap = $colMap;
        $mappedColumns->stats = $config->jobData->stats;
        $mappedColumns->Result = 1;
        if ($op == 'complete-col-map') {
            $mappedColumns = $this->getFinalMap($mappedColumns);
        }
        $mappedColumns->actions = $this->setupActions($config, $op);
        return $mappedColumns;
    }




    /**
     * Resume job progression based on jobs current phase.
     *
     * @param int $jobID id from g_bgProcess table
     *
     * @return string $op   The op to call based on current phase;
     */
    public function resumeJob($jobID)
    {
        $resumedJob = (object)null;
        $resumedJob->ErrTitle = '';
        $resumedJob->ErrMsg = '';
        $resumedJob->Result = false;
        $jobID = (int)$jobID;
        $config = $this->config(0, $jobID);
        if ($config->jobData->job) {
            $phaseID = $config->jobData->stats['phaseID'];
            $importData = false;
            if ($phaseID == -1) {
                // Rejection Report
                $resumedJob = $this->fetchReport($jobID, 'rejection');
            } elseif ($phaseID == 0) {
                // Generate CSV Report
                $resumedJob = $this->initCLI($jobID, 'csv');
            } elseif ($phaseID == 1) {
                // Fetch CSV Report
                $resumedJob = $this->fetchReport($jobID, 'csv');
            } elseif ($phaseID == 2) {
                // Fetch Column Map
                $resumedJob = $this->mapColumns($jobID, 'fetch-col-map');
            } elseif ($phaseID == 3) {
                // Complete Column Map
                $resumedJob = $this->mapColumns($jobID, 'complete-col-map');
            } elseif ($phaseID == 4) {
                // Fetch Data Report
                $resumedJob = $this->fetchReport($jobID, 'data');
            } elseif ($phaseID == 5) {
                // Fetch Import Report
                $resumedJob = $this->fetchReport($jobID, 'import');
            } else {
                // Ask Job
                $operation = 'ask-job';
            }
        } elseif ($config->jobData->ErrTitle && $config->jobData->ErrMsg) {
            $resumedJob->ErrTitle = $config->jobData->ErrTitle;
            $resumedJob->ErrMsg = $config->jobData->ErrMsg;
        }
        return $resumedJob;
    }



    /**
     * Set up the possible actions based on the operation.
     *
     * @param object $config    Configuration object
     * @param string $operation Current operation
     *
     * @return array  Return array of available actions.
     */
    private function setupActions($config, $operation)
    {
        $errLogLabel = '';
        $statsStatus = $config->jobData->stats['status'];
        $actions = ['ask-job' => 'View Jobs/Files'];
        if ($config->jobData->fileFound && $config->jobData->stats['csvReport']) {
            if ($operation != 'fetch-csv-report') {
                $actions['fetch-csv-report'] = 'CSV File Info';
            }
            if ($statsStatus['imported']) {
                if ($operation != 'fetch-import-report') {
                    $actions['fetch-import-report'] = 'Show Import Results';
                }
                /*
                if (!$statsStatus['accepted']) {
                    $actions['accept-import'] = 'Accept Import';
                    $actions['undo-import'] = 'Undo Import';
                }
                */
            } elseif ($config->jobData->stats['colMap']) {
                if ($operation != 'map-cols' && $operation != 'fetch-col-map'
                    && $operation != 'save-col-map'
                ) {
                    $actions['map-cols'] = 'Review Column Map';
                } else {
                    $actions['view-column-specifications'] = 'Column Specs';
                }
                if ($statsStatus['validated']) {
                    if ($operation != 'fetch-validation-report') {
                        $actions['fetch-validation-report'] = 'Show Validation Report';
                    }
                }
            } else {
                if ($config->jobData->stats['csvReport']['Records'] >= 1
                    && $config->jobData->stats['csvReport']['Variants'] == 0
                ) {
                    $actions['map-cols'] = 'Map Columns';
                } else {
                    if ($config->jobData->stats['csvReport']['Variants'] > 0) {
                        $errLogLabel = 'View Variant CSV Records';
                    }
                }
            }
        }
        if ($this->app->auth->isSuperAdmin && $config->jobData->hasBadData) {
            $actions['view-error-log'] = $errLogLabel ?: 'View Error Log';
        }
        if ($this->app->auth->isSuperAdmin && $config->jobData->hasLog) {
            $actions['view-log'] = 'View Log File';
        }
        $actions['setup-csv-template'] = 'Setup Batch Template';
        return $actions;
    }



    /**
     * Setup object of information about current job file.
     *
     * @param integer $fileID ID of the current job file.
     *
     * @return object Object of current file information.
     */
    private function setupFiles($fileID)
    {
        $fileID = (int)$fileID;
        $fileSetup = (object)null;
        $fileSetup->Result = false;

        $fileDir = $this->fileManager->getAdminFilePath($this->subDir);
        if (!is_dir($fileDir) && !mkdir($fileDir)) {
            $fileSetup->fileManErr = 'Unable to create ' . $fileDir;
        } else {
            $fileSetup->curFile = false;
            $fileSetup->curFileInfo = false;
            $fileSetup->curMap = false;
            $fileSetup->fileMap = [];
            $myFiles = $this->fileManager->getAdminFiles($this->subDir);
            $fileSetup->fileIDs = [];
            foreach ($myFiles as $f) {
                $fileSetup->fileIDs[$f['id']] = 0;
                $fileSetup->fileMap[$f['id']] = $f;
            }
            if (!empty($fileID)) {
                $fileSetup->curFileInfo = $this->fileManager->getFileInfoByID($fileID);
                $fileSetup->curFile = $fileDir .'/uid'. $this->userID .'/'. $fileSetup->curFileInfo['filename'];
                $fileSetup->curMap = preg_replace('/^(.+)(\.csv)$/i', '$1-map.txt', $fileSetup->curFile);
            }
            $fileSetup->Result = 1;
        }

        return $fileSetup;
    }



    /**
     * Lauch background process
     *
     * @param array $jobInfo Array holding job info
     *
     * @return boolean true/false for success/failure
     */
    private function startBgProcess($jobInfo)
    {

        $logFile = $jobInfo['stats']['fullFilename'] . '_bg.log';
        $e_logFile = escapeshellarg($logFile);
        $jobID = intval($jobInfo['id']);
        $stats = $jobInfo['stats'];
        $stats['debug'] = 1;

        ignore_user_abort(true);
        $toProcess = 0;

        if (isset($stats['csvRecords'])) {
            $toProcess = $stats['csvRecords'];
        } elseif (isset($stats['approx'])) {
            $toProcess = $stats['approx'];
        }

        $data = ['procID' => 'NULL', 'status' => 'scheduled', 'recordsToProcess' => $toProcess, 'recordsCompleted' => 0, 'stats' => $stats];

        $this->bgProcessData->updateProcessRecord($jobID, $this->clientID, $this->userID, $data);

        $fork = new ForkProcess();
        $target = 'Controllers.Cli.BatchUploadCases::init' . ' ' . $jobID;
        $pid = $fork->launch($target, $e_logFile);
        if ($pid > 0) {
            $result = true;
            sleep(1);
        } else {
            $result = false;
        }
        return $result;
    }




    /**
     * Generates Rejection Report and streams it for download
     *
     * @param integer $jobID Job ID
     *
     * @return null
     */
    public function streamRejectionRpt($jobID)
    {
        $jobID = (int)$jobID;
        if ($jobID <= 0) {
            throw new \Exception('Invalid Job Identifier.');
        }
        $config = $this->config(0, $jobID);
        $stats = $config->jobData->stats;
        $rrFile = $stats['fullFilename'] . $this->logFiles['rejectedRows'];
        if (!file_exists($rrFile)) {
            throw new \Exception('Rejected Row file does not exist.');
        }
        $nmInfo = pathinfo((string) $stats['fullFilename']);
        $nmAdd = '_Rejected_Rows_'. date('Y-m-d') .'.csv';
        if (!empty($nmInfo['extension'])) {
            $fileName = str_replace('.'. $nmInfo['extension'], $nmAdd, (string) $stats['filename']);
        } else {
            $fileName = $stats['filename'] . $nmAdd;
        }
        $buffer = '';
        $csv = new CsvParseFile($buffer, $rrFile);
        $idx = 0;
        while ($rec = $csv->parseFile($buffer)) {
            if ($idx == 0) {
                if (!file_exists('/tmp/Batch_Case_Rejection')) {
                    mkdir('/tmp/Batch_Case_Rejection', 0775, true);
                }
                $filePath = tempnam('/tmp/Batch_Case_Rejection', 'rejectionRpt_' . $this->clientID);
                $handle = fopen($filePath, 'a');
                fwrite(
                    $handle,
                    mb_convert_encoding(
                        Csv::make($rec, 'excel', true, true, false, true),
                        'UTF-16LE',
                        'UTF-8'
                    )
                );
                $markTime = time();
            } else {
                fwrite(
                    $handle,
                    mb_convert_encoding(
                        Csv::make($rec, 'excel', true, true, false, true),
                        'UTF-16LE',
                        'UTF-8'
                    )
                );
                if ((time() - $markTime) >= 12) {
                    set_time_limit(15);
                    $markTime = time();
                }
            }
            $idx++;
        }
        unset($csv);
        fseek($handle, 0);
        $isIE = (str_contains(strtoupper((string) \Xtra::app()->environment['HTTP_USER_AGENT']), 'MSIE'))
            ? true
            : false;
        $io = new CsvIO($isIE);
        $io->sendExcelHeaders($fileName, $filePath);
        readfile($filePath);
        ignore_user_abort(true);
        unlink($filePath);
        ob_flush();
        flush();
        exit;
    }



    /**
     * Generate and stream CSV Template for download
     *
     * @param array   $regions       selected regions
     * @param integer $principalsQty number of principals
     *
     * @return void
     */
    public function streamTemplate($regions, $principalsQty = 2)
    {
        // setup number of principals.
        $principalsQty = (int)$principalsQty;
        $principalFields = [];
        for ($i=1; $i <= $principalsQty; $i++) {
            $principalFields[] = "Principal $i";
            $principalFields[] = "Principal $i Email";
            $principalFields[] = "Principal $i Phone";
            $principalFields[] = "Principal $i Relationship";
            $principalFields[] = "Principal $i Owner";
            $principalFields[] = "Principal $i Percent Ownership";
            $principalFields[] = "Principal $i Board Member";
            $principalFields[] = "Principal $i Key Manager";
            $principalFields[] = "Principal $i Key Consultant";
            $principalFields[] = "Principal $i Unknown";
        }

        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM)) {
            if (!is_array($regions) || empty($regions)) {
                throw new \Exception('No regions selected');
            }

            // setup full field list.
            $csvHeader = [
                'Due Date',
                'Case Type',
                'Case Cost',
                'EDD on Principals',
                'Delivery Option',
                'Billing Unit',
                'Billing Unit PO',
                '3P #',
                'Case Owner',
                'Region Name',
                'Description',
                'Subject Name',
                'DBA Name',
                'Address 1',
                'Address 2',
                'City',
                'Subject State',
                'Subject Country',
                'Postal Code',
                'POC Name',
                'POC Email',
                'POC Phone',
                'POC Position',
            ];

            if (count($principalFields) > 0) {
                // add principals to header row, and setup empty principal cell values.
                foreach ($principalFields as $p) {
                    $csvHeader[] = $p;
                }
            }

            // now get the 3P data and prep it.
            $tempTable = 'tmpBatchCase' . chr(mt_rand(ord('a'), ord('z'))) . substr(md5(time()), 1);
            $tmpOpenCaseTable = "CREATE TEMPORARY TABLE $tempTable (tpID int) ENGINE=MyISAM";
            $this->DB->query($tmpOpenCaseTable);

            $addOpenCases = "INSERT INTO $tempTable (tpID)\n"
                ."SELECT DISTINCT(tpID) FROM cases WHERE clientID = :clientID AND caseStage < :caseStage";
            $cases = new Cases($this->clientID);
            $params = [':clientID' => $this->clientID, ':caseStage' => $cases::COMPLETED_BY_INVESTIGATOR];
            $this->DB->query($addOpenCases, $params);
            $this->DB->query("ALTER TABLE $tempTable ADD INDEX tpID(tpID)");

            $csvHeaderSent = false;
            for ($start=0; $start >= 0; $start+=1000) {
                $sql = "SELECT tp.id, tp.legalName, tp.DBAname, tp.addr2, tp.country,\n"
                    . "IF(tp.city <> '', tp.city, '(not provided)') AS 'city',\n"    // require for case
                    . "IF(tp.addr1 <> '', tp.addr1, '(not provided)') AS 'addr1',\n" // ...
                    . "tp.state, tp.postcode, tp.userTpNum, u.userid AS 'ownerLogin',\n"
                    . "tp.POCname, tp.POCemail, tp.POCphone1, tp.POCposi, r.name AS 'regionName'\n"
                    . "FROM thirdPartyProfile AS tp\n"
                    . "LEFT JOIN $tempTable AS co ON (tp.id = co.tpID)\n"
                    . "LEFT JOIN {$this->DB->authDB}.users AS u ON u.id = tp.ownerID\n"
                    . "LEFT JOIN region AS r ON r.id = tp.region\n"
                    . "WHERE tp.clientID = :clientID AND tp.status = 'active' AND tp.country <> '' "
                    . "AND co.tpID IS NULL";
                if (!empty($regions[0])) {
                    $regionList = implode(', ', $regions);
                    $sql .= " AND tp.region IN ($regionList) ";
                }
                $sql .= " ORDER BY tp.country ASC, tp.legalName ASC LIMIT $start, 1000";
                $thirdParties = $this->DB->fetchObjectRows($sql, [':clientID' => $this->clientID]);
                if (!$thirdParties) {
                    break;
                }
                if (!$csvHeaderSent) {
                    if (!file_exists('/tmp/Batch_Case_Template')) {
                        mkdir('/tmp/Batch_Case_Template', 0775, true);
                    }
                    $filePath = tempnam('/tmp/Batch_Case_Template', 'uploadCases_' . $this->clientID);
                    $handle = fopen($filePath, 'a');
                    fwrite(
                        $handle,
                        mb_convert_encoding(
                            Csv::make($csvHeader, 'excel', true, true, false, true),
                            'UTF-16LE',
                            'UTF-8'
                        )
                    );
                    $csvHeaderSent = true;
                }

                // loop through current result set.
                $markTime = time();
                foreach ($thirdParties as $tp) {
                    $valueRows = [
                        '',
                        // Due Date
                        '',
                        // Case Type
                        '',
                        // Case Cost
                        '',
                        // EDD on Princpals
                        '',
                        // Delivery Option
                        '',
                        // Billing Unit
                        '',
                        // Billing Unit PO
                        $tp->userTpNum,
                        // 3P #
                        $tp->ownerLogin,
                        // Case Owner
                        $tp->regionName,
                        // Region Name
                        '',
                        // Description
                        $tp->legalName,
                        // Subject Name
                        $tp->DBAname,
                        // DBA Name
                        $tp->addr1,
                        // Address 1
                        $tp->addr2,
                        // Address 2
                        $tp->city,
                        // City
                        $tp->state,
                        // Subject State
                        $tp->country,
                        // Subject Country
                        $tp->postcode,
                        // Postal Code
                        $tp->POCname,
                        // POC Name
                        $tp->POCemail,
                        // POC Emali
                        $tp->POCphone1,
                        // POC Phone
                        $tp->POCposi,
                    ];

                    if (count($principalFields) > 0) {
                        foreach ($principalFields as $p) {
                            $valueRows[] = '';
                        }
                    }
                    fwrite(
                        $handle,
                        mb_convert_encoding(
                            Csv::make($valueRows, 'excel', true, true, false, true),
                            'UTF-16LE',
                            'UTF-8'
                        )
                    );
                    if ((time() - $markTime) >= 12) {
                        set_time_limit(15);
                        $markTime = time();
                    }
                }
            }
        } else {
            // case-only client
            $csvHeader = [
                'Due Date',
                'Case Type',
                'Case Cost',
                'EDD on Principals',
                'Delivery Option',
                'Billing Unit',
                'Billing Unit PO',
                'Case Owner',
                'Region Name',
                'Description',
                'Subject Name',
                'Address 1',
                'City',
                'Subject State',
                'Subject Country',
                'Postal Code',
                'POC Name',
                'POC Email',
                'POC Phone',
                'POC Position',
            ];
            if (count($principalFields) > 0) {
                // add principals to header row, and setup empty principal cell values.
                foreach ($principalFields as $p) {
                    $csvHeader[] = $p;
                }
            }
            if (!file_exists('/tmp/Batch_Case_Template')) {
                mkdir('/tmp/Batch_Case_Template', 0775, true);
            }
            $filePath = tempnam('/tmp/Batch_Case_Template', 'uploadCases_' . $this->clientID);
            $handle = fopen($filePath, 'a');
            fwrite(
                $handle,
                mb_convert_encoding(
                    Csv::make($csvHeader, 'excel', true, true, false, true),
                    'UTF-16LE',
                    'UTF-8'
                )
            );
        }

        $clientProfile = (new ClientProfile())->findById($this->clientID, ['clientName']);
        $fileName = preg_replace('/[^\da-z\_-]/i', '', (string) $clientProfile->get('clientName'));
        $fileName = (!empty($fileName) ? trim($fileName) : 'Batch_Case_Template');
        $nameLastChar = substr($fileName, -1);
        if (!preg_match('/^[a-zA-Z0-9]+$/', $nameLastChar)) {
            $fileName = substr($fileName, 0, -1);
        }
        $fileName .= '_'. date('Y-m-d') . '.csv';
        fseek($handle, 0);
        $isIE = (str_contains(strtoupper((string) \Xtra::app()->environment['HTTP_USER_AGENT']), 'MSIE'))
            ? true
            : false;
        $io = new CsvIO($isIE);
        $io->sendExcelHeaders($fileName, $filePath);
        readfile($filePath);
        ignore_user_abort(true);
        unlink($filePath);
        ob_flush();
        flush();
        exit;
    }

    /**
     * Set which fields are reserved/allowed for the cases and subjectInfoDD tables.
     *
     * @return array Array of reserved/allowed fields for the cases and subjectInfoDD tables.
     */
    public function tableReservedFields()
    {
        /* Reserved Fields (reserved):
         *     Reserved fields will absolutely not be allowed from a batch import.
         *     They may however be allowed from current db records.
         *
         * Allowed Fields (allowed):
         *     Allowed fields are fields which are allowed to come from a batch import.
         *     They may/may not be required.
         *
         * Array return format:
         *     [table_name] => [(reserved/allowed)] => [field_name]
         */
        $fields = [];

        $fields['cases'] = ['reserved' => ['id', 'clientID', 'caseName', 'caseType', 'region', 'dept', 'casePriority', 'caseDueDate', 'caseState', 'caseCountry', 'caseStage', 'requestor', 'caseCreated', 'caseAssignedDate', 'caseAssignedAgent', 'caseAcceptedByInvestigator', 'caseInvestigatorUserID', 'caseCompletedByInvestigator', 'caseAcceptedByRequestor', 'caseClosed', 'budgetType', 'budgetAmount', 'budgetDescription', 'billingCode', 'userCaseNum', 'acceptingInvestigatorID', 'rejectReason', 'rejectDescription', 'numOfBusDays', 'POnum', 'invoiceNum', 'creatorUID', 'assigningProjectMgrID', 'reassignDate', 'tpID', 'passORfail', 'passFailReason', 'raTstamp', 'rmID', 'approveDDQ', 'spProduct', 'linkedCaseID', 'batchID', 'billingUnit', 'billingUnitPO'], 'allowed' => ['caseDescription']];

        $fields['subjectInfoDD'] = ['reserved' => ['id', 'caseID', 'clientID', 'subType', 'subStat', 'legalForm', 'name', 'street', 'city', 'state', 'country', 'pointOfContact', 'phone', 'emailAddr', 'bAwareInvestigation', 'reasonInvestigating', 'addInfo', 'getQuestMethod', 'addr2', 'postCode', 'mailDDquestionnaire', 'DBAname', 'POCposition', 'SBIonPrincipals', 'otherLegalFormComp', 'bInfoQuestnrAttach'], 'allowed' => ['principal1', 'pRelationship1', 'bp1Owner', 'p1OwnPercent', 'bp1KeyMgr', 'bp1BoardMem', 'bp1KeyConsult', 'bp1Unknown', 'p1phone', 'p1email', 'principal2', 'pRelationship2', 'bp2Owner', 'p2OwnPercent', 'bp2KeyMgr', 'bp2BoardMem', 'bp2KeyConsult', 'bp2Unknown', 'p2phone', 'p2email', 'principal3', 'pRelationship3', 'bp3Owner', 'p3OwnPercent', 'bp3KeyMgr', 'bp3BoardMem', 'bp3KeyConsult', 'bp3Unknown', 'p3phone', 'p3email', 'principal4', 'pRelationship4', 'bp4Owner', 'p4OwnPercent', 'bp4KeyMgr', 'bp4BoardMem', 'bp4KeyConsult', 'bp4Unknown', 'p4phone', 'p4email', 'principal5', 'pRelationship5', 'bp5Owner', 'p5OwnPercent', 'bp5KeyMgr', 'bp5BoardMem', 'bp5KeyConsult', 'bp5Unknown', 'p5phone', 'p5email', 'principal6', 'pRelationship6', 'bp6Owner', 'p6OwnPercent', 'bp6KeyMgr', 'bp6BoardMem', 'bp6KeyConsult', 'bp6Unknown', 'p6phone', 'p6email', 'principal7', 'pRelationship7', 'bp7Owner', 'p7OwnPercent', 'bp7KeyMgr', 'bp7BoardMem', 'bp7KeyConsult', 'bp7Unknown', 'p7phone', 'p7email', 'principal8', 'pRelationship8', 'bp8Owner', 'p8OwnPercent', 'bp8KeyMgr', 'bp8BoardMem', 'bp8KeyConsult', 'bp8Unknown', 'p8phone', 'p8email', 'principal9', 'pRelationship9', 'bp9Owner', 'p9OwnPercent', 'bp9KeyMgr', 'bp9BoardMem', 'bp9KeyConsult', 'bp9Unknown', 'p9phone', 'p9email', 'principal10', 'pRelationship10', 'bp10Owner', 'p10OwnPercent', 'bp10KeyMgr', 'bp10BoardMem', 'bp10KeyConsult', 'bp10Unknown', 'p10phone', 'p10email']];
        return $fields;
    }



    /**
     * Update stats for the specified job
     *
     * @param integer $jobID bgProcess.id
     * @param array   $stats array of stats values (serialized & sanitized by bgProcessData method)
     *
     * @return void
     */
    private function updateStats($jobID, $stats)
    {
        $this->bgProcessData->updateProcessRecord($jobID, $this->clientID, $this->userID, ['stats' => $stats]);
    }



    /**
     * Check whether the field being passed is one of the principal fields in the cases table.
     *
     * @param string $field field/column name from the cases table to be checked.
     *
     * @return array Array of data for the principal field.
     */
    public function verifyPrincipalFlds($field)
    {
        $numPrincipals = 10;
        $principalFields = [];
        $fieldTemplate = ['principal[num]', 'pRelationship[num]', 'bp[num]Owner', 'p[num]OwnPercent', 'bp[num]KeyManager', 'bp[num]BoardMem', 'bp[num]KeyConsult', 'bp[num]Unknown'];
        for ($i=1; $i<=$numPrincipals; $i++) {
            foreach ($fieldTemplate as $f) {
                $fR = str_replace('[num]', $i, $f);
                $principalFields[$fR] = $i;
            }
        }

        $rtn = ['result' => false, 'principalNum' => ''];
        if (array_key_exists($field, $principalFields)) {
            $rtn = ['result' => true, 'principalNum' => $principalFields[$field]];
        }

        return $rtn;
    }



    /**
     * Verify any required fields are present in the imported file.
     *
     * @param array $colMap Current mapping of imported columns
     *
     * @return array Array of data based on whether or not all required columns are present.
     */
    private function verifyRequiredColumns($colMap)
    {
        if ($this->hasTpAccess) {
            $required = ['data' => ['caseType'], 'ref' => []];
        } else {
            $required = ['data' => ['caseDueDate', 'caseType', 'street', 'city', 'state', 'country', 'postCode'], 'ref' => []];
        }

        $numReq = count($required['data']);

        $reqFields = [];
        // sort out 3P stuff. Did they provide an ID or Num?
        $hasTpRef = false;
        $tpRefFields = ['3pid', '3p#'];
        foreach ($colMap as $col) {
            if ($col['r'] == 'ignore') {
                continue;
            }
            $field = ($col['g'] == 'ref') ? $col['f'] : $col['field'];
            if (in_array($field, $required['data'])) {
                $reqFields[] = $field;
            }

            if ($this->hasTpAccess && in_array($field, $tpRefFields)) {
                $hasTpRef = true;
            }
        }
        if ($this->hasTpAccess) {
            $required['ref'][] = '3pID or 3P#';
        }

        $rtn = [];
        if (count($reqFields) != $numReq || ($this->hasTpAccess && !$hasTpRef)) {
            $rtn['status'] = false;
            $rtn['fields'] = $required;
        } else {
            $rtn['status'] = true;
        }
        return $rtn;
    }


    /**
     * Output debug message if $debug property is true.
     * Assumes output redirected to logfile.
     *
     * @param string $msg Message to output. Function adds linefeed.
     *
     * @return void
     */
    private function logDebug($msg)
    {
        $this->app->log->debug(date('H:i:s') . ' : ' . $msg . self::LF);
    }
}
