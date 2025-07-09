<?php
/**
 * SpLite Case controller
 *
 * @keywords splite, splite case handling
 */

namespace Controllers\TPM\SpLite;

use Controllers\ThirdPartyManagement\Base;
use Lib\EditAttach;
use Lib\UpDnLoadFile;
use Lib\Support\AuthDownload;
use Lib\Legacy\GenPDF;
use Lib\ResourceManager\Manager;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\SpLite\SpLite;

/**
 * SplResendNotice controller
 */
class SplCase extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/SpLite/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'SplCase.tpl';

    /**
     * @var string Template for display of uploaded docs (file list)
     */
    private $tplSpDocs = 'SplCaseSpDocs.tpl';

    /**
     * @var string Template for Pdf
     */
    private $tplSpPdf = 'SplCasePdf.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * Constructor for SplCase
     *
     * Note: At the time of instantiation of this class, SplCase does not
     * have a Client ID, so for the purposes of the constructor, the Client ID
     * value is not used.
     *
     * @param integer $clientID   Client ID
     * @param array   $initValues Indicates what constructor options are needed
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, ['isSpLite' => true]);
        $this->app       = \Xtra::app();
        $this->session   = $this->app->session;
        $this->resources = new Manager();
        $this->spLite    = new SpLite();

        $this->setCommonViewValues();
        $this->configConstructor($initValues);
    }

    /**
     * Return smarty template name for View
     *
     * @param array $initValues indicates which type of template to return
     *
     * @return void
     */
    private function configConstructor($initValues)
    {
        switch ($initValues['action']) {
            case 'ajax':
                $op = \Xtra::arrayGet($this->app->clean_POST, 'op', 0);
                switch ($op) {
                    case 'aefFetch':
                    case 'aefSave':
                        $case = $this->session->get('splCaseData');
                        $this->editAttach = $this->getEditAttachment($case['clientID'], $case['spID']);
                        break;
                    default:
                        $this->getUploadViewValues();
                }
                break;
            case 'uploadFinish':
                $this->getUploadViewValues();
                break;
            case 'dnload':
                $this->getDownloadFile();
                break;
            case 'pdf':
                $this->getCaseDetailsPdf();
                break;
            default: // main tpl
                $case       = $this->getCase();
                $editAttach = $this->getEditAttachment($case['clientID'], $case['spID']);
                $spDocs     = $this->getSpDocs($case['clientID'], $case['spID'], $case['caseID'], $case['accepted']);

                $this->getUploadViewValues();

                $this->setViewValue('case', $case);
                $this->setViewValue('editAttach', $editAttach);
                $this->setViewValue('spDocs', $spDocs);

                if ($case['completeReject']) {
                    $this->session->forget("splCaseData");
                }
        }
    }

    /**
     * Send the download file to the users browser.
     *
     * @return void
     */
    public function dnLoadFile()
    {
        if ($this->dnLoadfile) {
            AuthDownload::outputDownloadHeaders(
                $this->dnLoadfile->filename,
                $this->dnLoadfile->fileType,
                $this->dnLoadfile->fileSize
            );
            $this->dnLoad->echoClientFile($this->dnLoadfile->filePath);
            exit;
        }
    }

    /**
     * Builds the html used to render the list of service provider documents (spDocs files) that have been
     * uploaded.
     *
     * @param array $case Contains the case information
     *
     * @return array Formatted HTML
     */
    private function generateSpDocsList($case)
    {
        $spDocs = $this->getSpDocs($case['clientID'], $case['spID'], $case['caseID'], $case['accepted']);
        $viewValues = ['spDocs'               => $spDocs, 'sitePath'             => $this->app->sitePath, 'rxCacheKey'           => $this->app->rxCacheKey, 'jsController'         => $this->getViewValue('jsController'), 'jsControllerReload'   => $this->getViewValue('jsControllerReload'), 'jsControllerCallback' => $this->getViewValue('jsControllerCallback')];

        $spDocs['htmlList'] = $this->app->view->fetch($this->tplRoot . $this->tplSpDocs, $viewValues);
        unset($spDocs['docs']);

        return $spDocs;
    }

    /**
     * Gets all the information needed to process a case, all the information for the display of case data,
     * and sets up key case information in a session variable that is needed to be used/referenced
     * by the file upload iframe.
     *
     * @return array
     */
    private function getCase()
    {
        $key = \Xtra::arrayGet($this->app->clean_GET, 'ky', 0);
        $authenticated = $this->spLite->authenticate($key);

        if (!$authenticated) {
            $this->redirectToLogin();
        }

        $caseStatus = $this->spLite->getCaseStatus($this->spLite);
        $caseStatus = $this->spLite->prepareCase($this->spLite, $caseStatus);

        $caseStatus['key']      = $key;
        $caseStatus['clientID'] = $this->spLite->authRow->clientID;
        $caseStatus['spID']     = $this->spLite->authRow->spID;
        $caseStatus['caseID']   = $this->spLite->authRow->caseID;
        $caseStatus['accepted'] = $this->spLite->authRow->accepted;

        $this->session->set("splCaseData", [
            'key'       => $key,
            'clientID'  => $this->spLite->authRow->clientID,
            'spID'      => $this->spLite->authRow->spID,
            'caseID'    => $this->spLite->authRow->caseID,
            'accepted'  => $this->spLite->authRow->accepted
            ]);

        return $caseStatus;
    }

    /**
     * Generate a PDF of the case and send it to the users browser.
     *
     * @return void
     */
    private function getCaseDetailsPdf()
    {
        $key = $this->session->get('splCaseData.key');

        $spl = new SpLite();
        $this->casePdf = $spl->generateCasePdf($key);
    }

    /**
     * Set common view values for display the main spLite layout.
     *
     * @return void
     */
    private function setCommonViewValues()
    {
        $fileDependencies = [
            '/assets/jq/jqx/jqwidgets/jqxpanel.js',
        ];
        $this->addFileDependency($fileDependencies);

        $this->setViewValue('isSP', null, 1);
        $this->setViewValue('pgTitle', 'Service Provider');
        $this->setViewValue('recentlyViewed', [], 1);
        $this->setViewValue('tabData', [], 1);
        $this->setViewValue('colorScheme', 0, 1);
        $this->setViewValue('jsName', 'splCase');
        $this->setViewValue('jsController', 'spl/SpLite/SplCase');
        $this->setViewValue('jsControllerReload', 'getSpDocs');
        $this->setViewValue('jsControllerCallback', 'appNS.spLite.updateFileTotal');
    }

    /**
     * Download handler for SP Lite. Takes a variety of file types and prepares the content headers
     * and associated file data for rendering into a download web page.
     *
     * @return void
     */
    public function getDownloadFile()
    {
        $fileId     = \Xtra::arrayGet(\Xtra::app()->clean_GET, 'fid');
        $fs         = \Xtra::arrayGet(\Xtra::app()->clean_GET, 'fs');
        $case       = $this->session->get('splCaseData');
        $showErrors = false;

        if (\Xtra::app()->mode == "Development") {
            $showErrors = true;
        }

        if (empty($fileId) || !intval($fileId) || empty($fs) || !in_array($fs, ['i', 'c', 'd'])) {
            if ($showErrors) {
                echo 'Missing record id.';
            }
            exit;
        }

        $spl = new SpLite();
        $dnLoad = new UpDnLoadFile(['type' => 'dnLoad']);

        $file = $spl->getDnLoadInfo($fileId, $fs, $case['key'], $showErrors);
        $filePath = $dnLoad->echoClientFilePrep($file->table, $case['clientID'], $fileId);

        if ($filePath) {
            $file->filePath = $filePath;
        }

        $this->dnLoadfile = $file;
        $this->dnLoad = $dnLoad;
    }

    /**
     * Creates an instance of the EditAttach class used for modifying the file attachment information.
     *
     * @param string $clientID case client ID
     * @param string $spID     case service provider ID
     *
     * @return array
     */
    private function getEditAttachment($clientID, $spID)
    {
        $editAttach = new EditAttach('spnl', $clientID, $spID);
        return $editAttach;
    }

    /**
     * Gets and returns the list of service provider docs (files) associated with the case.
     *
     * @param string $clientID case client ID
     * @param string $spID     case service provider ID
     * @param string $caseID   case ID
     * @param string $accepted if case accepted then this contains the date of acceptance
     *
     * @return array
     */
    private function getSpDocs($clientID, $spID, $caseID, $accepted)
    {
        $spDocs = $this->spLite->getSpDocs($clientID, $spID, $caseID, $accepted);
        return $spDocs;
    }

    /**
     * Set common view values for display of the file upload iframe.
     *
     * @return void
     */
    private function getUploadViewValues()
    {
        $case = $this->session->get('splCaseData');
        $categories = $this->spLite->getVendorDocumentCategories($case['spID'], $case['clientID']);

        $categoriesById = [];
        foreach ($categories as $cat) {
            $categoriesById[$cat['id']] = $cat['name'];
        }

        $config = ['categoryList' => json_encode($categoriesById), 'caseKey'      => $case['key']];

        $this->setViewValue('upload', $config);
    }

    /**
     * Generate a PDF of the case and send it to the users browser.
     *
     * @return void
     */
    public function outputPdf()
    {
        if ($this->casePdf->success) {
            $pdf = new GenPDF($this->casePdf->footer);
            $this->casePdf->pdfCss = $pdf->getOverrideCss();
            $this->casePdf->rxCacheKey = $this->app->rxCacheKey;
            $html = $this->app->view->render($this->tplRoot . $this->tplSpPdf, (array)$this->casePdf);

            if ($err = $pdf->generatePDF($this->casePdf->pdfTitle, $html)) {
                echo $pdf->altErrorHandler($err);
            }
        }
    }

    /**
     * Redirect to the SpLite Login screen.
     *
     * @return void
     */
    public function redirectToLogin()
    {
        $urlParams = $this->app->clean_GET;
        $redirectParams = null;
        $redirectUrl = '/spl/login';

        foreach ($urlParams as $key => $value) {
            $redirectParams .= $redirectParams ? "&$key=$value" : "?$key=$value";
        }

        if ($redirectParams) {
            $redirectUrl .= $redirectParams;
        }
        $this->app->redirect($redirectUrl);
    }

    /**
     * Gets the list of service provider documents (files) that have been uploaded and returns the HTML used to
     * re-render the list. This is in response to a file being edited/deleted.
     * This is largely Legacy's 'aef-fetch'
     *
     * @return object Response object
     */
    private function ajaxAefFetch()
    {
        // a.k.a Legacy 'aef-fetch'
        $args = [];
        $attachID = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'fid');

        $result = $this->editAttach->getFormData($attachID);
        $args['IsRTL'] = (int)$this->editAttach->isRTL;
        $args['TrPnl'] = $this->editAttach->trPanel; // translations for edit attachment modal
        $args['TrText'] = $this->editAttach->trText; // translations for edit attachment modal

        if ($result->success) {
            $args['Desc']       = $result->desc;
            $args['IncludeCat'] = (int)$result->inclCat;
            $args['CurCatID']   = $result->curCatID;
            $args['Cats']       = $result->cats;
        } else {
            $this->jsObj->ErrorTitle = $result->errTitle;
            $this->jsObj->ErrorMsg = $result->errMsg;
        }

        $this->jsObj->Result = (int)$result->success;
        $this->jsObj->Args = $args;
        return $this->jsObj;
    }

    /**
     * Save the user changes to the file description and category (EditAttachment feature)
     * This is largely Legacy's 'aef-save'
     *
     * @return object Response object
     */
    private function ajaxAefSave()
    {
        $args = [];
        $attachID = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'id');
        $desc     = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'dsc');
        $category = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'cat');
        $desc     = \Xtra::normalizeLF(trim((string) $desc));

        $result = $this->editAttach->updateRecord($attachID, $desc, $category);

        if ($result->success) {
            $args['OldDesc']    = $result->oldDesc;
            $args['IncludeCat'] = (int)$result->inclCat;
            $args['OldCatID']   = $result->oldCatID;
        } else {
            $this->jsObj->ErrorTitle = $result->errTitle;
            $this->jsObj->ErrorMsg = $result->errMsg;
        }

        $this->jsObj->Result = (int)$result->success;
        $this->jsObj->Args = $args;
        return $this->jsObj;
    }

    /**
     * Generate a PDF of the case and send it to the users browser.
     *
     * @return void
     */
    private function ajaxCaseDetailsPdf()
    {
        $key = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'ky');

        $spl = new SpLite();
        $casePdf = $spl->generateCasePdf($key);
        $casePdf->rxCacheKey = $this->app->rxCacheKey;
        if ($casePdf->success) {
            // let Smarty create the HTML for the PDF
            $html = $this->app->view->render($this->tplRoot . $this->tplSpPdf, (array)$casePdf);

            $this->jsObj->Result = 1;
            $this->jsObj->Args   = ['data' => $html];
        } else {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = $casePdf->errTitle;
            $this->jsObj->ErrMsg   = $casePdf->errMsg;
        }

        return $this->jsObj;
    }

    /**
     * Gets the list of service provider documents (files) that have been uploaded and returns the HTML used to
     * re-render the list. This is in response to a file being edited/deleted.
     *
     * @return object Response object
     */
    private function ajaxGetSpDocs()
    {
        $case = $this->session->get('splCaseData');
        $spDocs = $this->generateSpDocsList($case);

        $this->jsObj->Result   = 1;
        $this->jsObj->FuncName = 'appNS.spDocsList.showSpDocs';
        $this->jsObj->Args     = [$spDocs];

        return $this->jsObj;
    }

    /**
     * Updates auth record with value from form
     *
     * @return integer Number of iAttachments for this case
     */
    private function ajaxRejectCase()
    {
        $spl = new SpLite();

        try {
            $spl->rejectCase();
            $this->jsObj->Result = 1;
        } catch (\Exception) {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = $spl->errTitle;
            $this->jsObj->ErrMsg   = $spl->errMsg;
        }

        return $this->jsObj;
    }

    /**
     * Updates auth record with value from form
     *
     * @return integer Number of iAttachments for this case
     */
    private function ajaxSaveInProgress()
    {
        $spl = new SpLite();

        if ($spl->saveInProgress()) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args   = ['title' => $spl->resTitle, 'msg' => $spl->resMsg];
        } else {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = $spl->errTitle;
            $this->jsObj->ErrMsg   = $spl->errMsg;
        }

        return $this->jsObj;
    }

    /**
     * Updates auth record with value from form
     *
     * @return integer Number of iAttachments for this case
     */
    private function ajaxSubmitCase()
    {
        try {
            $spl = new SpLite();
            $spl->submitCase();
            $this->jsObj->Result = 1;
        } catch (\Exception) {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = $spl->errTitle;
            $this->jsObj->ErrMsg   = $spl->errMsg;
        }

        return $this->jsObj;
    }

    /**
     * Delete a file that has been uploaded
     *
     * @return object Response object
     */
    private function ajaxUploadDelete()
    {
        $fileID = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'fid');
        $case = $this->session->get('splCaseData');

        $deleted = $this->spLite->deleteSpDoc($fileID, $case['clientID'], $case['caseID'], $case['accepted']);

        if ($deleted) {
            $spDocs = $this->generateSpDocsList($case);

            $this->jsObj->Result   = 1;
            $this->jsObj->FuncName = 'appNS.spDocsList.showSpDocs';
            $this->jsObj->Args     = [$spDocs];
        } else {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg   = 'File was not removed.';
        }

        return $this->jsObj;
    }

    /**
     * This method is called by the upload utility once the upload is done. Therefore to complete the overall process,
     * the docFinishUpload method is invoked to do things like update the database tracking the uploads, move the
     * file to the appropriate location on the server, etc.
     *
     * @return void
     */
    private function ajaxUploadFinish()
    {
        $upload = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'uploadData');

        $spl = new SpLite();
        $result = $spl->docFinishUpload($upload);

        if (empty($result)) {
            $case = $this->session->get('splCaseData');
            $spDocs = $this->generateSpDocsList($case);

            $this->jsObj->Result   = 1;
            $this->jsObj->FuncName = 'appNS.spDocsList.showSpDocs';
            $this->jsObj->Args     = [$spDocs];
        } else {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg   = implode(",", $result);
        }
        return $this->jsObj;
    }
}
