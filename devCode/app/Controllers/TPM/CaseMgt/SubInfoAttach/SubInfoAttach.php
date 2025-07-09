<?php
/**
 * SubInfoAttach controller
 */
namespace Controllers\TPM\CaseMgt\SubInfoAttach;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Lib\UpDnLoadFile;
use Models\TPM\CaseMgt\SubInfoAttach\SubInfoAttachData;
use Models\ThirdPartyManagement\Cases;
use Lib\IO;
use Lib\Support\AuthDownload;
use Lib\EditAttach;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\ClientFileManagement;
use Models\User;

/**
 * SubInfoAttach controller
 *
 * @see public_html/cms/case/subInfoAttach.php
 * @see public_html/cms/case/subInfoAttachForm.php
 * edit
 * @see public_html/cms/case/subInfoDelAttach.php
 *
 * @keywords reject/close, reject case
 */
#[\AllowDynamicProperties]
class SubInfoAttach extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = null;

    /**
     * @var string Base template for View
     */
    protected $tpl = null;

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var integer users.id
     */
    protected $userID = null;

    /**
     * @var integer cases.id
     */
    protected $caseID = null;

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($tenantID, $initValues = [])
    {
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
        $this->userID = $this->app->session->authUserID;
        $this->processParams($initValues);
    }

    /**
     * Determines if user has access to SubInfoAttach (i.e. if there is a cases record).
     *
     * @return boolean True if case exists, false otherwise.
     */
    public function canAccess()
    {
        if (!empty($this->caseID)) {
            $case = (new Cases($this->tenantID))->findById($this->caseID);
            if (!empty($case)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check and process passed in params as needed for further processing.
     *
     * @param array $initValues Contains any passed in params that may need some processing.
     *
     * @return void Sets class properties from associative array of parameters.
     */
    private function processParams($initValues)
    {
        if (isset($initValues['params']) && isset($initValues['params']['caseID'])) {
            $this->caseID = $initValues['params']['caseID'];
        } elseif ($this->app->session->has('currentID.case')) {
            $this->caseID = $this->app->session->get('currentID.case');
        }
    }

    /**
     * Sets required properties to display the SubInfoAttach dialog.
     *
     * @return void Sets jsObj
     */
    private function ajaxGetContent()
    {
        $this->jsObj->Result = 0;
        $this->tplRoot = 'TPM/CaseMgt/SubInfoAttach/';
        $this->tpl = 'SubInfoAttach.tpl';

        $txtTr = $this->app->trans->codeKeys(
            [
            'intro_head',
            'reassign_case_elements'
            ]
        );
        $this->setViewValue('txtTr', $txtTr);

        $case = (new Cases($this->tenantID))->findById($this->caseID);
        $this->setViewValue('case', $case);

        $this->setViewValue('jsName', 'subInfoAttach');
        $this->setViewValue('jsController', 'tpm/case/subInfoAttach');


        $tenantDB = $this->app->DB->getClientDB($this->clientID);
        $categories = $this->app->DB->fetchKeyValueRows(
            "SELECT id, name FROM {$tenantDB}.docCategory WHERE clientID = :clientID AND active = 1 ORDER BY name ASC",
            [ ':clientID' => $this->clientID ]
        );
        asort($categories);

        $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());
        $this->jsObj->FuncName = 'appNS.subInfoAttach.displayDialogContent';
        $this->jsObj->Args = [
            $html, $categories
        ];
        $this->jsObj->Result = 1;
    }

    /**
     * Outputs the DataTable row data.
     *
     * @return void
     */
    public function getTableData()
    {
        echo IO::jsonEncodeResponse($this->getJsData());
    }

    /**
     * Gets the subInfoAttach data in a way it is useful to DataTable.
     *
     * @return \stdClass
     */
    private function getJsData()
    {
        $jsData = new \stdClass();
        $draw = intval(\Xtra::arrayGet($this->app->clean_POST, 'draw', 0));
        $jsData->draw = ($draw + 1); // Used to track versions of asynchronous data

        $subInfoAttachData = new SubInfoAttachData($this->tenantID, $this->caseID);
        $attachments = $subInfoAttachData->getAttachments();
        $data = [];
        foreach ($attachments as $attachment) {
            $attach = new \stdClass();
            $attach->id = $attachment->id;
            $attach->description = $attachment->description;
            $attach->filename = $attachment->filename;
            $attach->category = $attachment->name; //category.name
            $attach->tooltip = $attachment->tooltip;
            $data[] = $attach;
        }
        $jsData->recordsTotal = count($data);
        $jsData->recordsFiltered = count($data);
        $jsData->data = $data;
        return $jsData;
    }

    /**
     * [ajaxAefFetch description]
     *
     * @note This is Legacy's 'aef-fetch'
     * @return void Sets jsObj
     */
    private function ajaxAefFetch()
    {
        $this->jsObj->Result = 0;
        $attachID = intval(\Xtra::arrayGet(\Xtra::app()->clean_POST, 'fid', -1));
        $editLocation = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'editLocation', 'subinfodoc');
        if ($attachID > -1) {
            $fileDependencies = [
                '/assets/jq/jqx/jqwidgets/jqxpanel.js',
            ];
            $this->addFileDependency($fileDependencies);
            $this->setViewValue('jsName', 'subInfoAttach');
            $this->setViewValue('jsController', 'tpm/case/subInfoAttach');

            $editAttach = new EditAttach($editLocation, $this->tenantID);
            $result = $editAttach->getFormData($attachID);
            $this->jsObj->Args['IsRTL'] = intval($editAttach->isRTL);
            $this->jsObj->Args['TrPnl'] = $editAttach->trPanel; // translations for edit attachment modal
            $this->jsObj->Args['TrText'] = $editAttach->trText; // translations for edit attachment modal
            if ($result->success) {
                $this->jsObj->Args['Desc']       = $result->desc;
                $this->jsObj->Args['IncludeCat'] = intval($result->inclCat);
                $this->jsObj->Args['CurCatID']   = $result->curCatID;
                $this->jsObj->Args['Cats']       = $result->cats;
            } else {
                $this->jsObj->ErrorTitle = $result->errTitle;
                $this->jsObj->ErrorMsg = $result->errMsg;
            }
            $this->jsObj->Result = intval($result->success);
        } else {
            // error
        }
    }

    /**
     * [ajaxAefSave description]
     *
     * @return void Sets jsObj
     */
    private function ajaxAefSave()
    {
        $this->jsObj->Result = 0;

        $attachID = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'id');
        $desc     = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'dsc');
        $category = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'cat');
        $desc     = \Xtra::normalizeLF(trim((string) $desc));

        // validation?
        $editAttach = new EditAttach('subinfodoc', $this->tenantID);
        $result = $editAttach->updateRecord($attachID, $desc, $category);
        if ($result->success) {
            $this->jsObj->Args['OldDesc']    = $result->oldDesc;
            $this->jsObj->Args['IncludeCat'] = intval($result->inclCat);
            $this->jsObj->Args['OldCatID']   = $result->oldCatID;
        } else {
            $this->jsObj->ErrorTitle = $result->errTitle;
            $this->jsObj->ErrorMsg = $result->errMsg;
        }
        $this->jsObj->Result = intval($result->success);
    }


    /**
     * Download the SubInfoAttach file.
     *
     * @todo Clean up caseID logic.
     * @return void
     */
    public function downloadSubInfoAttach()
    {
        $subInfoAttachID = intval(\Xtra::arrayGet($this->app->clean_GET, 'id', -1));
        if ($subInfoAttachID >= 0) {
            $sql = "SELECT filename, fileType, fileSize, caseID FROM subInfoAttach WHERE id=:id";
            $subInfoAttach = $this->app->DB->fetchObjectRow($sql, [':id' => $subInfoAttachID]);
            $case = (new Cases($this->tenantID))->findById($subInfoAttach->caseID);
            if (!empty($case)) {
                $upDnLoadFile = new UpDnLoadFile(['type' => 'dnLoad']);
                $filePath = $upDnLoadFile->echoClientFilePrep(
                    'subInfoAttach',
                    $case->get('clientID'),
                    $subInfoAttachID
                ); // $this->tenantID?
                AuthDownload::outputDownloadHeaders(
                    $subInfoAttach->filename,
                    $subInfoAttach->fileType,
                    $subInfoAttach->fileSize
                );
                $upDnLoadFile->echoClientFile($filePath);
            } else {
                // logout
            }
        } else {
            // invalid arguments
        }
    }

    /**
     * Sets required properties to display the Reject Case dialog.
     *
     * @return void Sets jsObj
     */
    private function ajaxDeleteSubInfoAttach()
    {
        $this->jsObj->Result = 0;
        if ($this->app->ftr->hasAllOf([\Feature::CASE_DOCS, \Feature::CASE_DOCS_ADD])) {
            $subInfoAttachID = intval(\Xtra::arrayGet($this->app->clean_POST, 'id', -1));
            if ($subInfoAttachID >= 0) {
                $subInfoAttachData = new SubInfoAttachData($this->tenantID, $this->caseID);
                if ($subInfoAttachData->deleteAttachment($subInfoAttachID, $this->userID)) {
                    $this->jsObj->Result = 1;
                } else {
                    //
                }
            } else {
                // invalid argument
            }
        } else {
            $this->redirect('accessDenied');
        }
    }

    /**
     * Sets required properties to display the Reject Case dialog.
     *
     * @note This is implemented such that it can be used in page.
     * @return void Sets jsObj
     */
    private function ajaxCreateForm()
    {
        $this->jsObj->Result = 0;

        $this->tplRoot = 'TPM/CaseMgt/SubInfoAttachForm/';
        $this->tpl = 'SubInfoAttachForm.tpl';

        $subInfoAttachData = new SubInfoAttachData($this->tenantID, $this->caseID);
        $this->setViewValue('documentCategories', $subInfoAttachData->getDocumentCategories());

        $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());
        $this->jsObj->Args = [
            'html' => $html
        ];
        $this->jsObj->Result = 1;
    }

    /**
     * Uploads SubInfoAttach file.
     *
     * @return void Sets $this->jsObj to reflect result status/completion.
     */
    private function ajaxUploadAttachment()
    {
        $this->jsObj->Result = 0;
        $filename    = \Xtra::arrayGet($this->app->clean_POST, 'filename', null);
        $description = \Xtra::arrayGet($this->app->clean_POST, 'description', null);
        $category    = intval(\Xtra::arrayGet($this->app->clean_POST, 'category', 0));
        $uploadFile  = \Xtra::arrayGet($this->app->clean_POST, 'uploadFile', '');

        try {
            $clientFileManagement = new ClientFileManagement();
            $case = (new Cases($this->tenantID))->findById($this->caseID);
            $user = (new User())->findById($this->userID);
            $tenantDB = $this->app->DB->getClientDB($this->tenantID);
            $sql =  "INSERT INTO {$tenantDB}.subInfoAttach SET "
                .   "caseID = :caseID,"
                .   "description = :description,"
                .   "filename = :filename,"
                .   "fileType = :fileType,"
                .   "fileSize = :fileSize,"
                .   "contents = :contents,"
                .   "ownerID  = :ownerID,"
                .   "caseStage = :caseStage,"
                .   "emptied = :emptied,"
                .   "catID = :catID,"
                .   "clientID = :clientID";
            $params = [
                ':caseID'      =>  $this->caseID,
                ':description' =>  $description,
                ':filename'    =>  $filename,
                ':fileType'    =>  AuthDownload::mimeType($uploadFile),
                ':fileSize'    =>  filesize($uploadFile),
                ':contents'    =>  '',
                ':ownerID'     =>  $user->get('userid'),
                ':caseStage'   =>  $case->get('caseStage'),
                ':emptied'     => '1',
                ':catID'       =>  $category,   // 0
                ':clientID'    =>  $this->tenantID
            ];
            $result = $this->app->DB->query($sql, $params);
            if (!is_null($result) && $result->rowCount()) {
                $recID = $this->app->DB->lastInsertId();
                $clientFilePath = $clientFileManagement->getClientFilePath();
                if ($clientFileManagement->createClientFileDir('subInfoAttach', $this->tenantID)) {
                    $destination = $clientFilePath . '/' . 'subInfoAttach' . '/' . $this->tenantID . '/' . $recID;
                    $var = $clientFileManagement->moveFile($source = $uploadFile, $destination);
                    $test = $clientFileManagement->putClientInfoFile('subInfoAttach', $this->tenantID, $recID);
                }
                $this->jsObj->Result = 1;
            }
        } catch (\Exception $e) {
            $this->app->log->debug($e->getMessage());
            // Do I want to catch the exception here or outside the class?
        }
    }
}
