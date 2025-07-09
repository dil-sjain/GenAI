<?php
/**
 * Allow super admins to upload/download/rename files
 *
 * @keywords upload
 */

namespace Controllers\TPM\Admin\Utility;

use Controllers\ThirdPartyManagement\Base;
use Lib\IO;
use Lib\UpDnLoadFile;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\Admin\Utility\UtilityFileManager;
use Models\Globals\UtilityUsage;
use Lib\SettingACL;

/**
 * Class ManageUtilityDataFiles controller for managing utility file uploads
 */
class ManageUtilityDataFiles extends Base
{
    use AjaxDispatcher;

    /**
     * Return object for AJAX requests
     *
     * @var object
     */
    protected $jsObj = null;

    /**
     * Framework instance
     *
     * @var \Skinny\Skinny
     */
    protected $app = null;

    /**
     * Object containing the current request
     *
     * @var framework Http\Request
     */
    protected $request = null;

    /**
     * File manager object
     *
     * @var \Models\TPM\Admin\Utility\UtilityFileManager
     */
    protected $utilFileMgr = null;

    /**
     * Base directory for View
     *
     * @var string
     */
    protected $tplRoot = 'TPM/Admin/Utility/';

    /**
     * Allowed tempates for View
     *
     * @var string
     */
    protected $tpl = [
        'main' => 'utilityFileManagement.tpl', // main page template
        'list' => 'utilityFileManagementList.tpl' // file list tpl
    ];


    /**
     * Call parent constructor to init for view and then set additional local variables
     *
     * @param int   $clientID   Client ID for the currently logged in subscriber
     * @param array $initValues Any additional values needed for Base constructor
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);

        // Create new instance of UtilityFileManger
        $this->utilFileMgr = new UtilityFileManager($clientID);

        // Init local access to \Xtra::app() and \Xtra::app->request()
        $this->app     = \Xtra::app();
        $this->request = $this->app->request();

        if ($location = $this->utilFileMgr->getParentConfig('location')) {
            $this->setViewValue('subDirDefault', $location);
        }
        $this->setViewValue('subDirOptions', json_encode(['ddq'=>'ddq','email'=>'email']));
        $setting = (new SettingACL($clientID))->get(
            SettingACL::MAX_UPLOAD_FILESIZE,
            ['lookupOptions' => 'disabled']
        );
        $uplMax = ($setting) ? $setting['value'] : 10;
        $this->setViewValue('uplMaxSize', $uplMax);


        // Set namespace for JS in view
        $this->setViewValue('jsName', 'fileMgrUtility');
        $viewDependencies = [
            'assets/css/pbar.css',
            'assets/css/main/gray-sp.css',
            'assets/jq/jqx/jqwidgets/jqxpanel.js',
        ];
        $this->addFileDependency($viewDependencies);
    }

    /**
     * Clean up on object destruction
     *
     * @return void
     */
    public function __destruct()
    {
        unset($this->utilFileMgr);
        unset($this->jsObj);
    }

    /**
     * Set vars on page load, initialize page display.
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgTitle', 'Manage DDQ/Email Data Files');
        if ($origin = $this->utilFileMgr->getParentConfig()) {
            $this->setViewValue('previousUtilityPg', $origin);
        }
        $this->app->view->display($this->getTemplate('main'), $this->getViewValues());
        if ($_SERVER['REQUEST_URI'] == '/tpm/adm/utilityfilemanagement/') {
            (new UtilityUsage())->addUtility('Upload/Manage DDQ & Email Files');
        }
    }

    /**
     * Delete an uploaded file.
     * Requires file id passed in through $_POST['fid']
     *
     * @return void
     */
    private function ajaxUploadDelete()
    {
        $fname  = $this->request->post('fname');
        $cat  = $this->request->post('cat');
        $msg      = '';
        try {
            $affected = $this->utilFileMgr->deleteFileByID($fname, $cat);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        if ($affected > 0) {
            $this->jsObj->Result = 1;
        }
        // If $msg is set then there was an error while attempting to delete the file
        if (!empty($msg)) {
            $this->jsObj->ErrTitle = 'Operation Terminated';
            $this->jsObj->ErrMsg   = $msg;
        }
    }

    /**
     * This method is called by the upload utility once the upload is done. Therefore to complete the overall process,
     * the storeUploadedFile method is invoked to update the database tracking the uploads and move the
     * file to the appropriate location on the server, etc.
     *
     * @return void
     */
    private function ajaxUploadFinish()
    {
        $result['error'] = 'Upload Failed';
        $upload = \Xtra::arrayGet($this->app->clean_POST, 'uploadData');

        $uploadFile = $upload['uploadFile'];
        $fileSize = filesize($uploadFile);
        // validate file type here
        $filename = $upload['filename'];
        $validUpload = (new UpDnLoadFile(['type' => 'upLoad']))->validateUpload($filename, $uploadFile, 'spnl');
        if ($validUpload !== true) {
            if (file_exists($uploadFile)) {
                unlink($uploadFile);
            }
            $result['error'] = $validUpload;
        } else {
            if ($upload && preg_match('/^[a-f0-9]{32}$/i', (string) $upload['fid'])) {
                if (!is_array($upload) || !file_exists($upload['uploadFile'])) {
                    $result['error'] = 'Uploaded file not found.';
                } else {
                    $result = $this->utilFileMgr->storeUploadedFile(
                        $upload['uploadFile'],
                        $upload['category'],
                        $upload['filename']
                    );
                    $result['error'] = !$result['stored'] ? $result['message'] : '';
                }
            }
        }
        if (empty($result['error'])) {
            $this->jsObj->Result   = 1;
            $this->jsObj->FuncName = 'parent.appNS.uplTop.getFileList';
        } else {
            $this->jsObj->Result   = 0;
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg   = $result['error'];
        }
        return $this->jsObj;
    }

    /**
     * Rename an uploaded file based on file id
     * Requires file id and new filename passed in through $_POST['fid'] & $_POST['nn']
     *
     * @return void
     */
    private function ajaxUploadRename()
    {
        $fname  = $this->request->post('fname');
        $cat  = $this->request->post('cat');
        $filename = $this->request->post('nn');
        $msg  = '';
        if (pathinfo((string) $fname, PATHINFO_EXTENSION) !== pathinfo((string) $filename, PATHINFO_EXTENSION)) {
            $msg = 'Cannot change the uploaded file extension.';
        } else {
            try {
                $this->utilFileMgr->renameUtilityFileByID($fname, $cat, $filename);
            } catch (\Exception $e) {
                $msg = $e->getMessage();
            }
        }
        // If $msg is set then there was an error while attempting to delete the file
        if (!empty($msg)) {
            $this->jsObj->ErrTitle = 'Operation Terminated';
            $this->jsObj->ErrMsg   = $msg;
        } else {
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Get list of files currently uploaded
     * Optionally can provide a subdirectory to search $_GET['subdir']
     *
     * @return void
     */
    private function ajaxGetFileList()
    {
        $files    = $this->utilFileMgr->getUtilityFiles();
        // Get Smarty to lay it out
        $html = $this->app->view->render('TPM/Admin/Utility/utilityFileManagementList.tpl', ['files' => $files]);
        if ($this->app->phpunit) {
            $this->jsObj->Args   = [$html, $files];
        } else {
            $this->jsObj->Args   = [$html];
        }
        $this->jsObj->Result = 1;
    }
}
