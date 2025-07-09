<?php
/**
* Provide responses to requests from user interaction on AI DD-Report
*/
 
namespace Controllers\TPM\ThirdPartyMgt\ProfileDetail;
 
use Models\TPM\ThirdPartyMgt\ProfileDetail\AiDdInit;
use Skinny\Skinny;
use Lib\Traits\AjaxDispatcher;
use Lib\Support\Xtra;
use Models\LogData;
use Lib\Services\AppMailer;
use Models\User;

/**
 * initiate AI DD-Report and save the report details
 */
#[\AllowDynamicProperties]
class AiDdRequestHandler
{
    use AjaxDispatcher;
 
    /**
     * @var Skinny Instance of PHP framework
     */
    protected Skinny $app;
 
    /**
     * @var int TPM tenant ID
     */
    protected int $clientID;
 
    public function __construct(int $clientID)
    {
        $this->app = Xtra::app();
        $this->clientID = $clientID;
    }
    
    /**
     * Handle ajax requests from UI
     */
    public function ajaxGenerateReport(): void
    {
        $tpID = $this->getPostVar('tp', '0');
        $forceGenerate = $this->getPostVar('forceGenerate', 0);
        
        if (empty(getenv("DD_AI_CALL_BACK_URL")) || empty(getenv("DD_AI_RID_URL"))) {
            $this->jsObj->Result = 4;
            $this->jsObj->ErrorTitle = "Error";
            $this->jsObj->ErrorMessage = "One Click URL is not set.";
            return;
        }
        $aiDDMdl = new AiDdInit($this->clientID);

        // Update the in progress to failure ,
        // because when the report is not getting any response from RID more than 1 hour
        $aiDDMdl->updateProgressToFailureReports($tpID);

        if ($aiDDMdl->isReportInProgressStage($tpID)) {
            $this->jsObj->Result = 3;
            $this->jsObj->ErrorTitle = "Error";
            $this->jsObj->ErrorMessage = "Report is already in progress for this profile.";
            $this->jsObj->Args = $aiDDMdl->getTpProfile($tpID);
            return;
        }

        if ($forceGenerate == 0) {
            if ($aiDDMdl->isCompletedStage($tpID)) {
                $this->jsObj->Result = 2;
                return;
            }
        }

        $response = $aiDDMdl->initiateCurl($tpID);
        $curlOutput = $response['curlOutput'];
        $responseCode = $response['responseCode'];

        if ($responseCode < 200 || $responseCode > 299) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorTitle = "Error";
            $this->jsObj->ErrorMessage = "Error in generating the report, please try again later.";
            $this->jsObj->Args = $aiDDMdl->getTpProfile($tpID);
            return;
        }

        $result = json_decode($curlOutput, true);
        if (!isset($result['report_id'])) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorTitle = "Error";
            $this->jsObj->ErrorMessage = "Error in generating the report ,report id not found.";
            $this->jsObj->Args = $aiDDMdl->getTpProfile($tpID);
            return;
        }

        $reportId = $result['report_id'];
        $userID = $this->app->session->get('authUserID');

        $aiDDMdl->saveReportDetailsInGlobalTbl($reportId);
        $aiDDMdl->saveReportDetailsInClientTbl($reportId, $tpID, $userID);

        $auditLog = new LogData($this->clientID, $userID);
        $auditLog->save3pLogEntry(226, '3P AI DD Report initiated', $tpID);

        $this->User = new User;
        $uRec  = $this->User->getValuesById('userEmail', $userID);
        $tpRec = $aiDDMdl->getTpProfile($tpID);

        if (isset($uRec) && !empty($uRec['userEmail']) && !empty($tpRec)) {
            $thirdPartyPath = \Xtra::conf('cms.sitePath') .
                "cms/thirdparty/thirdparty_home.sec?id={$tpRec['tpID']}" .
                "&tname=thirdPartyFolder&pdt=dd";

            $contents = [
                'subj' => "AI Due Diligence Report for {$tpRec['tpName']} (ID: {$tpRec['tpNumber']}) initiated",
                'msg' => "Report generation for {$tpRec['tpName']} (ID: {$tpRec['tpNumber']})" .
                    (!empty($tpRec['tpRegion']) ? " | Region: {$tpRec['tpRegion']}" : "") .
                    " has been initiated! This may take a few minutes." .
                    " We will notify you once it is available to download at 3 PM from {$thirdPartyPath}",
            ];
            $address = ['to' => $uRec['userEmail']];

            AppMailer::mail(
                0,
                $address['to'],
                $contents['subj'],
                $contents['msg'],
                ['addHistory' => true, 'forceSystemEmail' => true]
            );
        }

        $this->jsObj->Result = 1;
        $this->jsObj->Args = $tpRec;
    }

    /**
     * Handle download & View DD Report Request
     *
     * @param int $reportID Report ID
     * @param int $isDownload Download or view
     */
    public function ajaxViewDownload($reportID, $userID, $isDownload): void
    {
        if ($isDownload == 1) {
            $disposition = 'attachment';
        } else {
            $disposition = 'inline';
        }
        $aiDDMdl = new AiDdInit($this->clientID);
        $reportDetails = $aiDDMdl->getReportDetails($reportID);
        if (!$reportDetails) {
            die('Report not found');
        }
        $filePath = '/clientfiles/' . $this->app->mode . '/aiDDReport/' .
            $reportDetails['clientID'] . '/' . $reportDetails['file_name'];
        if (!file_exists($filePath)) {
            die('File not found');
        }
        
        // Get the file's MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        // Get the file's size
        $fileSize = filesize($filePath);

        $filename = strip_tags(htmlspecialchars_decode($reportDetails["reportNum"], ENT_QUOTES | ENT_HTML401)) ?: 'N/A';

        $encodedFilename = rawurlencode($filename);
        $encodedFilename = str_replace(['%20', '%22', '%27'], [' ', '"', "'"], $encodedFilename);
        
        // Set the appropriate headers
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $disposition . '; filename="' . $encodedFilename . '.pdf"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Expires: 0');
        
        // Read the file and send it to the browser
        readfile($filePath);
        if ($isDownload == 1) {
            $auditLog = new LogData($this->clientID, $userID);
            $auditLog->save3pLogEntry(228, $reportDetails["reportNum"] . '.pdf', $reportDetails['tpID']);
            $aiDDMdl->updateViewAndDownloadStatus($reportID, 0, 1);
        } else {
            $aiDDMdl->updateViewAndDownloadStatus($reportID, 1);
        }
        exit;
    }
}
