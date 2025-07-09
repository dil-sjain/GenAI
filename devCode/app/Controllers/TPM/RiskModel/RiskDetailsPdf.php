<?php
/**
 * Provide PDFs for Risk Model and Risk Assessment
 */

namespace Controllers\TPM\RiskModel;

use Models\TPM\RiskModel\RiskDetails as DetailData;
use Lib\Services\GeneratePdf;

/**
 * Provide PDFs for Risk Model and Risk Assessment
 *
 * @keywords pdf, assessment, risk model, assessment history, risk details
 */
#[\AllowDynamicProperties]
class RiskDetailsPdf
{
    /**
     * @var object Skinny instance
     */
    protected $app = null;

    /**
     * @var integer TPM tenantID
     */
    protected $tenantID = null;

    /**
     * Initialize instance properties
     *
     * @param integer $tenantID TPM clientID (clientProfie.id)
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->tenantID = $tenantID;
        $this->app = \Xtra::app();
    }

    /**
     * Genereate Risk Assessment Details PDF
     *
     * @param string $raRef Reference to a specific riskAssessment record.  (There is no primary ID)
     *
     * @return void
     */
    public function assessmentDetails($raRef)
    {
        // Attempt to parse the riskAssessment record reference
        $match = [];
        if (preg_match('/^(\d{14})M(\d+)N(\d+)T(\d+)$/', $raRef, $match)) {
            $ts = $match[1];
            $rmodelID = intval($match[2]);
            $normalized = intval($match[3]);
            $tpID = intval($match[4]);
            $raTstamp = sprintf(
                "%s-%s-%s %s:%s:%s",
                substr($ts, 0, 4),
                substr($ts, 4, 2),
                substr($ts, 6, 2),
                substr($ts, 8, 2),
                substr($ts, 10, 2),
                substr($ts, 12, 2)
            );
        } else {
            echo str_replace('{:ref}', $raRef, (string) $this->app->trans->codeKey('err_badReference'));
            return;
        }

        $debug = false;
        $genPdf = new GeneratePdf();
        [$docRoot, $creationTime, $clientName, $logoFileName]
            = $genPdf->getTpmVars($this->tenantID, $debug);

        $details = (new DetailData($this->tenantID))->assessmentDetail($tpID, $rmodelID, null, $raTstamp);
        if (empty($details)) {
            echo str_replace('{:ref}', $raRef, (string) $this->app->trans->codeKey('err_badReference'));
            return;
        }
        // Additional values for template
        $details['isPDF'] = true;
        $details['docRoot'] = $docRoot;
        $details['logoFileName'] = $logoFileName;
        $details['pageCounter'] = $this->app->trans->codeKey('pdf_pageCounter');
        $details['footerTop'] = str_replace(
            '{companyName}',
            $clientName,
            (string) $this->app->trans->codeKey('pdf_warning_message')
        );
        $details['footerLeft'] = str_replace(
            '{assessmentDate}',
            substr((string) $details['assess']['tstamp'], 0, 10),
            (string) $this->app->trans->codeKey('pdf_footerAssessmentDate')
        );
        $details['footerMiddle'] = str_replace(
            '{timestamp}',
            $creationTime,
            (string) $this->app->trans->codeKey('pdf_created_timestamp')
        );
        $pdfTitle = 'RiskAssessment.' . $details['profile']['userTpNum'] . '_' . date('Y-m-d') . '.pdf';
        $details['trans'] = $this->app->trans->group('risk_detail');

        $html = $this->app->view->fetch('Widgets/RiskInventory/AssessmentDetailPdf.tpl', $details);
        if ($debug) {
            echo $html;
        } else {
            (new GeneratePdf())->pdf($html, $pdfTitle);
        }
    }

    /**
     * Genereate Risk Model Details PDF
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return void
     */
    public function riskModelDetails($rmodelID)
    {
        if (empty($rmodelID)) {
            return;
        }
        $rmodelID = (int)$rmodelID;

        $debug = false;
        $genPdf = new GeneratePdf();
        [$docRoot, $creationTime, $clientName, $logoFileName]
            = $genPdf->getTpmVars($this->tenantID, $debug);

        $details = (new DetailData($this->tenantID))->modelDetail($rmodelID);
        if (empty($details)) {
            echo str_replace('{:ref}', $rmodelID, (string) $this->app->trans->codeKey('err_badReference'));
            return;
        }

        // Additional values for template
        $details['isPDF'] = true;
        $details['docRoot'] = $docRoot;
        $details['logoFileName'] = $logoFileName;
        $details['pageCounter'] = $this->app->trans->codeKey('pdf_pageCounter');
        $details['footerTop'] = str_replace(
            '{companyName}',
            $clientName,
            (string) $this->app->trans->codeKey('pdf_warning_message')
        );
        $details['footerLeft'] = str_replace(
            '{riskModelDate}',
            substr((string) $details['model']['created'], 0, 10),
            (string) $this->app->trans->codeKey('pdf_footerModelDate')
        );
        $details['footerMiddle'] = str_replace(
            '{timestamp}',
            $creationTime,
            (string) $this->app->trans->codeKey('pdf_created_timestamp')
        );
        $pdfTitle = 'RiskModelConfig_' . date('Y-m-d') . '.pdf';
        $details['trans'] = $this->app->trans->group('risk_detail');
        $details['trans']['customItemType'] = [
            'one' => $this->app->trans->codeKey('word_one'),
            'multiple' => $this->app->trans->codeKey('word_multiple'),
        ];

        $html = $this->app->view->fetch('Widgets/RiskInventory/ModelDetailPdf.tpl', $details);
        if ($debug) {
            echo $html;
        } else {
            (new GeneratePdf())->pdf($html, $pdfTitle);
        }
    }
}
