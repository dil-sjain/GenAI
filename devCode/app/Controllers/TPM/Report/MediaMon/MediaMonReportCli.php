<?php
/**
 * TPM - Analytics - Media Monitor Report - Cli Control
 */

namespace Controllers\TPM\Report\MediaMon;

use Models\TPM\Report\MediaMon\MediaMonReportData;
use Models\Cli\BackgroundProcessData;
use Lib\Csv;
use Lib\FeatureACL;

/**
 * Controls the Cli aspect of the Media Monitor report
 */
#[\AllowDynamicProperties]
class MediaMonReportCli
{

    protected $app      = null; // app instance
    protected $bgData   = null; // bg process model instance
    protected $data     = null; // model instance
    private $csvFile  = null; // file resource
    private $idxFile  = null; // file resource

    private $csvHead   = false;
    private $csvOffset = 0;  // file offset counter.
    private $processed = 0;
    private $csvCols   = [
        'name',
        'assocSrch',
        'entitySrch',
        'ttlSrch',
    ];

    protected $tenantID  = 0;
    protected $userID    = 0;
    private $jobID     = 0;
    private $job       = null;
    private $track     = ['hits' => 0, 'errors' => 0, 'profiles' => 0];
    protected $debugMode = false;
    private $markTime  = 0;
    private $assocSrchTtl  = 0;
    private $entitySrchTtl = 0;
    private $ttlSrchTtl    = 0;
    protected $lv2limit = 100000;
    protected $lv2chunk = 100;
    protected $lv3limit = 1000000;
    protected $lv3chunk = 1000;


    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        if (!defined('LF')) {
            define('LF', "\n");
        }
        $this->lv2limit = $this->app->confValues['cms']['lv2limit'] ?? 0;
        $this->lv2chunk = $this->app->confValues['cms']['lv2chunk'] ?? 0;
        $this->lv3limit = $this->app->confValues['cms']['lv3limit'] ?? 0;
        $this->lv3chunk = $this->app->confValues['cms']['lv3chunk'] ?? 0;
        $this->lv2limit = ($this->lv2limit > 0) ? $this->lv2limit : 100000;
        $this->lv2chunk = ($this->lv2chunk > 0) ? $this->lv2chunk : 100;
        $this->lv3limit = ($this->lv3limit > 0) ? $this->lv3limit : 1000000;
        $this->lv3chunk = ($this->lv3chunk > 0) ? $this->lv3chunk : 1000;
    }


    /**
     * Manage report creation
     *
     * @return void
     */
    public function createReport()
    {
        $this->setupJobEnv();
        $this->job->stats['query']['sql'] .= ' LIMIT '. $this->job->recordsToProcess;
        $this->initializeCSV();
        $numRecs = 0;
        while ($rec = $this->data->searchProfiles($this->job->stats['query'])) {
            $numRecs++;
            $this->processRecord($rec['id']);
        }
        if (!$numRecs) {
            $this->writeToLog('No records found by model::getProfiles.');
        } else {
            $this->processRecord(0, true); // last row of the report
        }
        $this->logDebug('processed all profiles');
        $this->closeFiles();
        // mark job completed
        $this->job->stats['sums'] = $this->track;
        $bgUpdate = [
            'recordsCompleted' => $this->processed,
            'stats' => $this->job->stats,
            'expires' => date('Y-m-d H:i:s', strtotime('+30 DAYS')),
        ];
        $this->bgData->toggleProcessStatus($this->jobID, $this->tenantID, $this->userID, false, $bgUpdate);
        $this->logDebug('updated/marked completed process control record');
    }


    /**
     * Process each record
     *
     * @param integer $tpID  thirdPartyProfile.id
     * @param boolean $final flag to mark the last row in the table/csv
     *
     * @return void;
     */
    private function processRecord($tpID, $final = false)
    {
        if ($final) {
            $rowData = ['Totals', $this->assocSrchTtl, $this->entitySrchTtl, $this->ttlSrchTtl];
        } else {
            $this->logDebug('begin processing tpID ' . $tpID);
            $tpRec = $this->data->getProfile($tpID);
            if (!$tpRec) {
                $this->logDebug('FAILED to retrieve tpID ' . $tpID);
                $this->processed++;
                return;
            }
            if (!$tpRec->name) {
                $this->logDebug('Excluded tpID ' . $tpID . ' - no Media Monitor ID');
                $this->processed++;
                return;
            }

            $rowData = $this->data->getReportData($tpID, $tpRec->name, $this->job->stats['query']['params']);
            $this->assocSrchTtl  += $rowData[1];
            $this->entitySrchTtl += $rowData[2];
            $this->ttlSrchTtl    += $rowData[3];
        }


        $csv = Csv::make($rowData, 'std', true);
        $this->csvOffset += strlen($csv);
        fwrite($this->csvFile, $csv);
        fwrite($this->idxFile, pack('V', $this->csvOffset));

        // update profiles processed for monitor
        $this->processed++;
        $this->logDebug('  completed tpID ' . $tpID);

        if (((time() - $this->markTime) >= 2 && $this->processed <= $this->lv2limit)
            || ($this->processed > $this->lv3limit && $this->processed % $this->lv3chunk === 0)
            || ($this->processed < $this->lv3limit && $this->processed > $this->lv2limit && $this->processed % $this->lv2chunk === 0)
            || $final
        ) {
            // update completed records count
            $this->bgData->updateProcessRecord(
                $this->jobID,
                $this->tenantID,
                $this->userID,
                ['recordsCompleted' => $this->processed]
            );
            $this->markTime = time();
        }
    }


    /**
     * Initially write the csv file headers.
     *
     * @return void
     */
    private function initializeCSV()
    {
        if (!$this->csvHead) {
            $csv = Csv::make($this->csvCols, 'std', true);
            $this->csvOffset += strlen($csv);
            // write the record
            fwrite($this->csvFile, $csv);
            // first index value is offset to start of first record
            // and also the length of the header
            fwrite($this->idxFile, pack('V', $this->csvOffset));
            $this->csvHead = true;
        }
    }


    /**
     * Setup the environment for job processing
     *
     * @return void;
     */
    private function setupJobEnv()
    {
        $this->jobID = intval($_SERVER['argv'][2]);
        if (!$this->jobID) {
            $this->exitJob('Missing or invalid jobID.', __LINE__);
        }
        $this->bgData = new BackgroundProcessData;

        $this->job = $this->bgData->getBgRecord($this->jobID);
        if (!$this->job || $this->job->procID != null || $this->job->status != null
            || !$this->job->recordsToProcess || $this->job->jobType != 'MediaMonReport'
        ) {
            $this->exitJob('Missing or invalid control record.', __LINE__);
        }
        $this->job->stats = @unserialize($this->job->stats);
        $this->tenantID = (int)$this->job->clientID;
        $this->userID   = (int)$this->job->userID;
        $this->debugMode = ($this->job->stats['debug']) ? true:false;
        $this->app = \Xtra::app();
        $this->data   = new MediaMonReportData($this->tenantID);
        $this->app->ftr = null;
        $this->app->ftr = new FeatureACL($this->userID, 0, $this->tenantID);


        if (is_array($this->job->stats['rptCols'])) {
            $this->csvCols = [];
            foreach ($this->job->stats['rptCols'] as $c) {
                $this->csvCols[] = $c['title'];
            }
        }
        $this->setupFileResources();
        $this->bgData->toggleProcessStatus($this->jobID, $this->tenantID, $this->userID, true);
        if ($this->debugMode) {
            $this->logDebug('MediaMon Report running as pid ' . posix_getpid());
            $this->logDebug('Updated process control record.');
        }
    }


    /**
     * Setup the file resources and open them for writing.
     *
     * @return void;
     */
    private function setupFileResources()
    {
        $rptPath = '/var/local/bgProcess/'. $this->app->mode .'/MediaMonReport'
            .'/c'. $this->tenantID .'u'. $this->userID .'b' . $this->jobID . '-MediaMon-report';

        if (!$this->job->jobFiles['csv']) {
            $this->job->jobFiles['csv'] = $rptPath .'.csv';
        }
        if (!$this->job->jobFiles['idx']) {
            $this->job->jobFiles['idx'] = $rptPath .'.idx';
        }

        $this->csvFile = fopen($this->job->jobFiles['csv'], 'w');
        $this->idxFile = fopen($this->job->jobFiles['idx'], 'w');
        if (!$this->csvFile || !$this->idxFile) {
            $this->exitJob('Error opening file(s)', __LINE__);
        }
    }


    /**
     * Make an entry into the process log if debugging is true.
     *
     * @param string $msg String to be written to the process log.
     *
     * @return void;
     */
    private function logDebug($msg)
    {
        if (!$this->debugMode) {
            return;
        }
        $this->writeToLog($msg);
    }


    /**
     * Make an entry into the process log, regardless of debugMode setting. (Errors, etc.)
     *
     * @param string $msg String to be written to the process log.
     *
     * @return void;
     */
    private function writeToLog($msg)
    {
        if (is_array($msg) || is_object($msg)) {
            $msg = print_r($msg);
        }
        if (empty($msg) || !is_string($msg)) {
            return;
        }
        echo date('H:i:s'), ': ', $msg, LF;
    }


    /**
     * Close any open files
     *
     * @return void
     */
    private function closeFiles()
    {
        if (is_resource($this->csvFile)) {
            fclose($this->csvFile);
            $this->csvFile = null;
        }
        if (is_resource($this->idxFile)) {
            fclose($this->idxFile);
            $this->idxFile = null;
        }
    }


    /**
     * Exit the job, aka this instance of the class, cleanly.
     *
     * @param string  $msg  Message to be written to the log.
     * @param integer $line __LINE__ from which exitJob is called. (this->exitJob(__LINE__);)
     *
     * @return void
     */
    private function exitJob($msg, $line): never
    {
        $this->closeFiles();
        $this->writeToLog($msg);
        exit($line);
    }
}
