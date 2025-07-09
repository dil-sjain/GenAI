<?php
/**
 * CasePdf creates the PDF file for a case
 *
 * @keywords CasePdf, Case Pdf, case folder PDF
 *
 * @see This class creates a PDF from a case folder and it's various sub-tabs
 */

namespace Controllers\TPM\CaseMgt\CaseFolder;

use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\ClientFileManagement;
use Lib\Services\AppMailer;
use Lib\Traits\CommonHelpers;
use Lib\Traits\EmailHelpers;
use Models\TPM\CaseMgt\CaseFolder\CasePdf as Model;

/**
 * Class to facilitates generating a PDF file for CloneWizardModel
 *
 * @keywords clone wizard, case pdf
 */
#[\AllowDynamicProperties]
class CasePdf
{
    use CommonHelpers; // use CommonHelpers trait. (logging, etc)
    use EmailHelpers; // use the EmailHelpers trait (validEmailPattern, fixEmailAddr, etc)

    /**
     * Database class instance
     *
     * @var object
     */
    private $DB = null;

    /**
     * Client database name
     *
     * @var object
     */
    private $authDB = null;

    /**
     * Temporary directory to store generated PDFs
     *
     * @var string $tempPath
     */
    private $tempPath = '/tmp';

    /**
     * Service Provider database name
     *
     * @var object
     */
    private $spDb = null;

    /**
     * Model object
     *
     * @var object
     */
    private $m = null;

    /**
     * Base directory for View
     *
     * @var string
     */
    protected $tplRoot = 'TPM/SpLite/';

    /**
     * Base template for View
     *
     * @var string
     */
    protected $tpl = 'SplCase.tpl';

    /**
     * Template for display of uploaded docs (file list)
     *
     * @var string
     */
    private $tplSpDocs = 'SplCaseSpDocs.tpl';

    /**
     * Template for Pdf
     *
     * @var string
     */
    private $tplSpPdf = 'SplCasePdf.tpl';

    /**
     * phantomjs args
     *
     * @var string
     */
    private $phantomArgs = '--ssl-protocol=any';

    /**
     * Class constructor
     *
     * @param integer $clientID Client ID
     */
    public function __construct($clientID)
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->DB->setClientDB($clientID);
        $this->authDB  = $this->DB->getClientDB($clientID);
        $this->spDb    = $this->app->DB->spGlobalDB;
        $this->session  = $this->app->session;
        $this->sitePath = $this->app->sitePath;
        $this->m = new Model($clientID);
    }


    /**
     * Return various property values
     *
     * @param string $propertyName Name of property for which to return value
     *
     * @throws \Exception Throws an exception if a property is not found
     *
     * @return mixed The value of the specified property
     */
    public function get($propertyName)
    {
        $result = match ($propertyName) {
            'authDB' => $this->authDB,
            'spDb' => $this->spDb,
            default => throw new \Exception("Unknown property: `$propertyName`"),
        };
        return $result;
    }


    /**
     * Generate a PDF of the case.
     *
     * @param object $authRow Contains all the auth information
     * @param object $caseRow Contains all the case information
     * @param array  $spDocs  An array of all the documents (attachments) that have been uploaded
     *
     * @return object $rtn Object containing all the information to generate a PDF of the case.
     *
     * @see This is a refactor of legacy splCaseDetails.sec
     */
    public function getCasePdfInfo($authRow, $caseRow, $spDocs)
    {
        return $this->m->getCasePdfInfo($authRow, $caseRow, $spDocs);
    }


    /**
     * Returns an array row from g_userLogEventContexts depending on the $contextKey
     *
     * @param string $contextKey caseFolder, profileDetail, fullLog
     *
     * @return int $contextID 0 if no results, otherwise a valid id
     */
    public function getContextID($contextKey)
    {
        return $this->m->getContextID($contextKey);
    }


    /**
     * Returns an array containing user role id and name depending on the $userType value
     *
     * @param int $userType 0, 10, 30, 60, 70, 80, 100
     *
     * @return mixed $userRole either array if results, or empty string if no results.
     */
    public function getUserRole($userType = 0)
    {
        return $this->m->getUserRole($userType);
    }



    /**
     * Make a PDF for a case using the auth information, case data and case documents
     *
     * @param object $authRow  cases.id and tenant.id
     * @param array  $caseRow  case cols and values
     * @param array  $caseDocs arrays of documents for the current case
     *
     * @return string   pdf file location
     */
    public function makeCasePDF($authRow, $caseRow, $caseDocs)
    {
        $tm = time();
        $pdfMakeTime = date("h:i:s", $tm);
        $pdfMakeDate = date("D M d Y", $tm);
        // heart of pdf generation is here
        $casePdf = $this->getCasePdfInfo($authRow, (object)$caseRow, $caseDocs);
        if (!is_object($casePdf)) {
            return $casePdf;
        }
        $companyName = $caseRow['caseName'];
        $caseAssignedDate = $caseRow['caseAssignedDate'];
        // let Smarty create the HTML for the PDF
        $msg = "This document is confidential material of $companyName "
            . "and may not be copied or shared without permission.";
        $footer = ['top' => $msg, 'left' => "Case Assign Date $caseAssignedDate", 'middle' => "PDF created on $pdfMakeDate at $pdfMakeTime"];
        // attach css and render html
        $casePdf->pdfCss = $this->getOverrideCss($footer);
        $html = $this->app->view->render($this->tplRoot . $this->tplSpPdf, (array)$casePdf);
        // generate the case pdf
        $casePDF = $this->generatePDF($caseRow['userCaseNum'], $html);
        return $casePDF;
    }


    /**
     * generatePdf function
     *
     * @param string $pdfTitle Document name
     * @param string $html     HTML to be used to create the PDF
     *
     * @return string Error string on failure
     */
    public function generatePDF($pdfTitle, $html)
    {
        $session = \Xtra::app()->session;
        $this->_cmsDir = \Xtra::conf('cms.docRoot') . '/cms';

        // Save the html output
        $srch = [' media="screen"', ' media="print"'];
        $rplc = [' media="all"', ' media="all"'];
        $html = str_replace($srch, $rplc, $html);

        // Create unique file names
        $fileBase = tempnam($this->tempPath, 'cvtPDF-');
        $htmlFile = $fileBase . '.html';
        $pdfFile  = $fileBase . '.pdf';

        // Write html to file
        if (!($fp = fopen($htmlFile, 'w'))) {
            return "Unable to write temporary file.";
        }
        fwrite($fp, $html);
        fclose($fp);
        $exec = "/usr/local/bin/phantomjs --config=/etc/phantomjs.conf $this->phantomArgs "
            . $this->_cmsDir . "/includes/php/html2pdf.js "
            . "$htmlFile $pdfFile ; echo -n \$?";
        if (!str_contains(trim(shell_exec($exec)), 'Status: success')) {
            $this->FileManager = new ClientFileManagement();
            $this->FileManager->zapTmpFiles($fileBase);
            $subject    = 'Third Party Risk Management - Compliance System Alert: PDF Generator Failure';
            $message    = 'There was an error in generating a PDF. Please see details below.' . "\n"
                . 'Client ID: ' . $session['clientID'] . "\n"
                . 'PDF: ' . $pdfTitle . "\n"
                . 'User: ' . $session['userName'] . "\n" . 'User-Email: ' . $session['userEmail'];

            $this->logMessage($message, 'error');

            $from    = $this->fixEmailAddr(\Xtra::conf('email.sys_from'));
            $sender  = $this->fixEmailAddr(\Xtra::conf('email.sys_replyto'));
            $address = $this->fixEmailAddr(\Xtra::conf('email.admin_alert'));
            $mailSent = AppMailer::mail(\Xtra::app()->session->get('clientID'), $address, $subject, $message, [
                'from'       => $from,
                'realSender' => $sender
                ]);
            if ($mailSent !== true) {
                $this->logMessage($mailSent, 'error');
            }

            return "Your PDF file can not be generated at this time.";
        }

        return $pdfFile;
    }


    /**
     * Return css to override YUI styles and for logo on cover page.
     * Also puts footer callback in head tag
     *
     * @param string $footer information to be placed in footer
     *
     * @return string
     */
    public function getOverrideCss($footer)
    {
        $this->setFooter($footer);
        $sitepath = \Xtra::conf('cms.sitePath');
        $css      =<<<EOT

<link rel="stylesheet" type="text/css" href="{$sitepath}/cms/css/pdfOverride.css" media="all" />

<style type="text/css">

h6 {
    font-style: italic;
    font-weight: bold;
    color: blue;
}
.pgbrk {
    page-break-before:always;
}
.sect {
    font-weight: bold;
    font-size: 10pt;
}

.title_text {
    font-size:26px;
}

.logoSize {
    max-width: 225px;
    width:auto;
    height:auto;
}

div.comparisonPanel div.responseContainer textarea {
    width: 270px;
}
div.comparisonPanel div.previousResponseContainer {
    width: 278px;
}
div.comparisonPanel div.newResponseContainer {
    width: 278px;
}

</style>

$this->_footer;

EOT;

        return $css;
    }


    /**
     * Parse input from constructor and build suitable Javascript callback
     *
     * @param array $footer Associative array of string to include in page footer
     *                      'left'   bottom left, after page numbers
     *                      'middle' bottom middle between page numbers and copyright
     *                      'top'    above horizontal footer line
     *
     * @return void
     */
    private function setFooter($footer)
    {
        $left   = '';
        $top    = '';
        $middle = '';
        $topDiv = '';
        if (is_array($footer) && count($footer)) {
            // overrides
            if (isset($footer['left'])) {
                $left = $footer['left'];
            }
            if (isset($footer['top'])) {
                $top = $footer['top'];
            }
            if (isset($footer['middle'])) {
                $middle = $footer['middle'];
            }
        }
        $yr = gmdate('Y');

        $topSty  = 'margin-top:10px';
        $fntSty  = 'font-family:Source Sans Pro,sans-serif; font-size: 9px';
        $taCent  = 'text-align: center';
        $taRight = 'text-align: right';
        $noWrap  = 'white-space: nowrap';

        if ($top) {
            $exSty  = 'margin-top: 3px;';
            $high   = '0.5in';
            $tmp    = "$topSty;$fntSty;$taCent";
            $topDiv = '<div style="' . $tmp . '">' . $top . '</div>';
        } else {
            $exSty = 'margin-top: 10px;';
            $high  = '0.4in';
        }
        $exSty .= 'padding-top: 2px; border-top: 1px solid #cccccc';
        $this->_footer =<<<EOT

<script type="text/javascript">
var PhantomJSPrinting = {
    footer: {
        height: "$high",
        contents: function(pageNum, numPages) {
            if (numPages <= 1) {
                return '';
            }
            var txt = '$topDiv<div style="$exSty">'
                + '<table width="100%" cellpadding="0" cellspacing="0">'
                + '<tr>'
                + '  <td style="$noWrap;$fntSty">Page '
                + pageNum + ' of ' + numPages + '<td>'
                + '  <td style="$noWrap;$fntSty;$taCent">$left<td>'
                + '  <td style="$noWrap;$fntSty;$taCent">$middle<td>'
                + '  <td style="$noWrap;$fntSty;$taRight">&copy; $yr '
                + 'Diligent Corporation, All Rights Reserved.<td>'
                + '</tr></table></div>';
            return txt;
        }
    }
};
</script>

EOT;
    }
}
