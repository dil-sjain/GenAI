<?php
/**
 * TPM - Analytics - 3P Monitor Report - Cli Control
 */

namespace Controllers\TPM\Report\TpMonitor;

use Controllers\TPM\Report\TpMonitor\PanamaPaperStatus;
use Controllers\TPM\Report\TpMonitor\MediaMonitorStatus;
use Models\TPM\MediaMonitor\MediaMonitorSrchData;
use Models\TPM\Report\TpMonitor\TpMonitorReportData;
use Models\Cli\BackgroundProcessData;
use Models\ThirdPartyManagement\Gdc;
use Lib\Csv;
use Lib\FeatureACL;
use Lib\SettingACL;

/**
 * Controls the Cli aspect of the 3P Monitor report
 */
#[\AllowDynamicProperties]
class TpMonitorReportCli
{

    protected $app     = null; // app instance
    protected $bgData  = null; // bg process model instance
    protected $data    = null; // model instance
    protected $mm      = null; // MediaMonitorSrchData instance
    protected $gdc     = null; // gdc model
    private $csvFile   = null; // file resource
    private $idxFile   = null; // file resource
    private $csvHead   = false;
    private $csvOffset = 0;  // file offset counter.
    private $processed = 0;
    private $csvCols   = [
        'Profile #',
        'Name',
        'Country',
        'Region',
        'rptType',
        'Screened',
        'Undetermined',
        'True Match',
        'False Positive',
        'Status',
        'Hits',
        'Remediation'
    ];

    protected $tenantID  = 0;
    protected $userID    = 0;
    private $jobID     = 0;
    private $job       = null;
    private $track     = ['hits' => 0, 'errors' => 0, 'profiles' => 0];
    protected $debugMode = false;
    private $markTime  = 0;
    private $panamaPapers = false;
    private $mediaMon  = false;
    private $naChar    = ' - '; // Character relpacement for non-applicable datacells
    private $enableTrueMatch = false;
    private $remediationResults = [];

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
        $rpts = ['gdc'];
        if ($this->mediaMon) {
            $rpts[] = 'media';
        }
        $numRecs = 0;
        while ($rec = $this->data->searchProfiles($this->job->stats['query'])) {
            $numRecs++;
            $this->processRecord($rec, $rpts);
        }
        if (!$numRecs) {
            $this->writeToLog('No records found by model::getProfiles.');
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
     * Setting the value to true or false for ICIJ
     *
     * @return void;
     */
    private function setPanamaPapers()
    {
        $this->panamaPapers = PanamaPaperStatus::hasPanamaPapers($this->tenantID);
    }


    /**
     * Setting the value to true or false for Media Monitor
     *
     * @return void;
     */
    private function setMediaMon()
    {
        $this->mediaMon = MediaMonitorStatus::hasMediaMonitor($this->tenantID);
    }


    /**
     * Process each thirdPartyProfile record
     *
     * @param array $tpRec    thirdPartyProfile record
     * @param array $rptTypes Can include gdc and media
     *
     * @return void;
     */
    private function processRecord($tpRec, $rptTypes)
    {
        $this->logDebug($tpRec);
        if (!$tpRec) {
            $this->logDebug('FAILED to retrieve tpID ' . $tpRec['tpID']);
            $this->processed++;
            return;
        }
        $hitSum = $errSum = 0;
        $this->logDebug("Begin processing tpID #" . $tpRec['tpID']);
        $hitData = $this->compileHitsData($tpRec, $rptTypes);

        $this->track['hits'] += $hitData['hitSum'];
        $this->track['errors'] += $hitData['errorSum'];
        $this->track['profiles']++;
        $nameError = (!empty($hitData['errorSum'])) ? '|ne| ' : '';

        foreach ($rptTypes as $rptType) {
            if ($hitData[$rptType]['status'] == '|r|Reviewed'
                && $hitData[$rptType]['matchCount'] == 0
                && $hitData[$rptType]['falsePositiveCount'] == 0
            ) {
                $hitData[$rptType]['status'] = '';
            }
            $data = [
                $tpRec['userTpNum'],
                $nameError . $tpRec['legalName'],
                $tpRec['country'],
                $tpRec['region'],
                $hitData[$rptType]['type'],
                $hitData[$rptType]['screenDate'],
                $hitData[$rptType]['undeterminedCount'],
                $hitData[$rptType]['matchCount'],
                $hitData[$rptType]['falsePositiveCount'],
                ($hitData[$rptType]['undeterminedCount']
                    + $hitData[$rptType]['matchCount']
                    + $hitData[$rptType]['falsePositiveCount']),
                $hitData[$rptType]['status']

            ];
            $countRecord = true;
            $tpID = $tpRec['tpID'];
            if ($this->gdc->enableTrueMatch) {
                $data[] = $hitData[$rptType]['remediationCount'];
                if (!empty($this->job->stats['remed'])) {
                    if ($hitData[$rptType]['remediationCount'] < 1) {
                        $countRecord = false;
                    }
                }
            }

            if ($countRecord) {
                $csv = Csv::make($data, 'std', true);
                $this->csvOffset += strlen($csv);
                fwrite($this->csvFile, $csv);
                fwrite($this->idxFile, pack('V', $this->csvOffset));
            }
        }

        // update profiles processed for monitor
        $this->processed++;
        $this->logDebug('  completed tpID ' . $tpRec['tpID']);

        if ((time() - $this->markTime) >= 2) {
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
     * Compile array of hits data for gdc and media
     *
     * @param array $tpRec    thirdPartyProfile record
     * @param array $rptTypes can include gdc and media
     *
     * @return array;
     */
    private function compileHitsData($tpRec, $rptTypes)
    {
        $hitData = [
            'gdc' => [
                'type' => 'GDC',
                'status' => '',
                'undeterminedCount' => 0,
                'matchCount' => 0,
                'falsePositiveCount' => 0,
                'screenDate' => '',
                'remediationCount' => 0,
            ],
            'media' => [
                'type' => 'Media',
                'status' => '',
                'undeterminedCount' => 0,
                'matchCount' => 0,
                'falsePositiveCount' => 0,
                'screenDate' => '',
                'remediationCount' => 0,
            ],
            'errorSum' => 0,
            'hitSum' => 0
        ];
        $gdcAndIcijSum = 0;
        foreach ($rptTypes as $rptType) {
            if (in_array($rptType, ['gdc', 'media'])) {
                switch ($rptType) {
                    case 'gdc':
                        // 2020-04-02 grh: wrap icij into gdc stats
                        $hitData['gdc']['status']  = (!empty($tpRec['gdcReview']) || !empty($tpRec['icijReview']))
                            ? '|nr|Needs Review'
                            : '|r|Reviewed'; // not correct, calc value
                        $hitData['gdc']['undeterminedCount'] = (int)$tpRec['gdcUndeterminedHits']
                            + (int)$tpRec['icijUndeterminedHits'];
                        $hitData['gdc']['matchCount'] = (int)$tpRec['gdcTrueMatchHits']
                            + (int)$tpRec['icijTrueMatchHits'];
                        $hitData['gdc']['falsePositiveCount'] = (int)$tpRec['gdcFalsePositiveHits']
                            + (int)$tpRec['icijFalsePositiveHits'];
                        $hitData['gdc']['remediationCount'] = (int)$tpRec['gdcRemediationHits']
                            + (int)$tpRec['icijRemediationHits'];
                        $hitSum = ((int)$tpRec['gdcUndeterminedHits']
                            + (int)$tpRec['gdcTrueMatchHits']
                            + (int)$tpRec['gdcFalsePositiveHits']
                            + (int)$tpRec['icijUndeterminedHits']
                            + (int)$tpRec['icijTrueMatchHits']
                            + (int)$tpRec['icijFalsePositiveHits']
                        );
                        $gdcAndIcijSum += $hitSum;
                        break;
                    case 'media':
                        $hitData['media']['screenDate']
                            = $this->mm->getCurrentScreeningDateByProfile($this->tenantID, $tpRec['tpID']);
                        $hitData['media']['status']
                            = (!empty($tpRec['gdcReviewMM']))  ? '|nr|Needs Review' : '|r|Reviewed';
                        $hitData['media']['undeterminedCount'] = (int)$tpRec['mmUndeterminedHits'];
                        $hitData['media']['matchCount'] = (int)$tpRec['mmTrueMatchHits'];
                        $hitData['media']['falsePositiveCount'] = (int)$tpRec['mmFalsePositiveHits'];
                        $hitData['media']['remediationCount'] = (int)$tpRec['mmRemediationHits'];
                        break;
                }
            }
        }

        // Deal with various possible kinds errors....
        if (!$tpRec['gdcScreeningID']) {
            $this->logDebug('Excluded tpID ' . $tpRec['tpID'] . ' - no GDC screening ID');
            $hitData = $this->resetGdcAndIcijHitData($hitData);
        } else {
            $hitData['errorSum'] = $this->data->getErrSum($tpRec['gdcScreeningID']);
        }
        if (!$hitData['errorSum'] && !$gdcAndIcijSum) {
            $this->logDebug('Excluded tpID ' . $tpRec['tpID'] . ' - no hits');
            $hitData = $this->resetGdcAndIcijHitData($hitData);
            $this->data->zeroProfileReviewNameError(0, $tpRec['tpID']);
        } elseif (!$gdcAndIcijSum && (int)$tpRec['gdcReview']) {
            // $hitData['gdc']['screenDate'] = $tpRec['screenDate'];
            $this->data->zeroProfileReviewNameError(1, $tpRec['tpID']);
        } elseif (!$hitData['errorSum'] && (int)$tpRec['gdcNameError']) {
            // $hitData['gdc']['screenDate'] = $tpRec['screenDate'];
            $this->data->zeroProfileReviewNameError(2, $tpRec['tpID']);
        } else {
            // $hitData['gdc']['screenDate'] = $tpRec['screenDate'];
        }

        $hitData['gdc']['screenDate'] = $tpRec['screenDate'];

        // Now tally up the hit sum
        $gdcHitSum = $hitData['gdc']['undeterminedCount']
            + $hitData['gdc']['matchCount']
            + $hitData['gdc']['falsePositiveCount'];
        $mediaHitSum = $hitData['media']['undeterminedCount']
            + $hitData['media']['matchCount']
            + $hitData['media']['falsePositiveCount'];
        $hitData['hitSum'] = ($gdcHitSum + $mediaHitSum);
        return $hitData;
    }

    /**
     * Resets GDC and ICIJ hits data
     *
     * @param array $hitData Contains gdc, icij and media items
     *
     * @return array
     */
    private function resetGdcAndIcijHitData($hitData)
    {
        $hitData['gdc']['undeterminedCount'] = 0;
        $hitData['gdc']['matchCount'] = 0;
        $hitData['gdc']['falsePositiveCount'] = 0;
        $hitData['icij']['undeterminedCount'] = 0;
        $hitData['icij']['matchCount'] = 0;
        $hitData['icij']['falsePositiveCount'] = 0;
        return $hitData;
    }

    /**
     * Initially write the csv file headers.
     *
     * @return void
     */
    private function initializeCSV()
    {
        if (!$this->csvHead) {
            $cols = $this->csvCols;
            if (!empty($cols)) {
                $countryTxt = $this->app->trans->codeKey('country');
                foreach ($cols as $idx => $data) {
                    if (preg_match('/country/i', (string) $data)) {
                        $cols[$idx] = preg_replace('/country/i', (string) $countryTxt, (string) $data);
                    }
                }
            }
            $csv = Csv::make($cols, 'std', true);
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
            || !$this->job->recordsToProcess || $this->job->jobType != 'gdcReport'
        ) {
            $this->exitJob('Missing or invalid control record.', __LINE__);
        }
        $this->job->stats = @unserialize($this->job->stats);
        $this->tenantID = (int)$this->job->clientID;
        $this->userID   = (int)$this->job->userID;
        $this->debugMode = ($this->job->stats['debug']) ? true:false;
        $this->app = \Xtra::app();
        $this->data = new TpMonitorReportData($this->tenantID);
        $this->app->ftr = null;
        $this->app->ftr = new FeatureACL($this->userID, 0, $this->tenantID);
        $this->gdc = new Gdc($this->tenantID);
        $this->mm = new MediaMonitorSrchData();
        $this->app->trans->tenantID = $this->tenantID;
        $countryTxt = $this->app->trans->codeKey('country');
        $settings = new SettingACL($this->tenantID);
        $this->enableTrueMatch = ($settings->get(SettingACL::TRUE_MATCH_REMEDIATION)['value'] > 0);

        $allowModes = ['all', '3pList', 'list', 'typecat', 'tpID'];
        if (!in_array($this->job->stats['mode'], $allowModes)) {
            $this->exitJob('Invalid report mode specified in stats.', __LINE__);
        }
        if (is_array($this->job->stats['rptCols'])) {
            $this->csvCols = [];
            foreach ($this->job->stats['rptCols'] as $c) {
                if (preg_match('/country/i', (string) $c['title'])) {
                    $this->csvCols[] = preg_replace('/country/i', (string) $countryTxt, (string) $c['title']);
                } else {
                    $this->csvCols[] = $c['title'];
                }
            }
        }
        $this->setPanamaPapers();
        $this->setMediaMon();
        $this->setupFileResources();
        $this->bgData->toggleProcessStatus($this->jobID, $this->tenantID, $this->userID, true);
        if ($this->debugMode) {
            $this->logDebug('GDC Report running as pid ' . posix_getpid());
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
        $rptPath = '/var/local/bgProcess/'. $this->app->mode .'/gdcReport'
            .'/c'. $this->tenantID .'u'. $this->userID .'b' . $this->jobID . '-gdc-report';

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
