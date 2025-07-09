<?php
/**
 * Allow super admins to upload/download/rename files
 *
 * @keywords upload
 */

namespace Controllers\TPM\Admin\Utility;

use Controllers\ThirdPartyManagement\Base;
use Lib\UpDnLoadFile;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\Admin\Utility\RegionCountryTransFileManager;
use Lib\SettingACL;

/**
 * Class ManageDataFiles controller for managing admin file uploads
 */
class ManageRegionCountryTransFiles extends Base
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
     * @var \Models\TPM\Admin\Utility\regionCountryTransFileManagement
     */
    protected $transFileMgr = null;

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
        'main' => 'regionCountryTransFileManagement.tpl', // main page template
        'list' => 'regionCountryTransFileManagementList.tpl' // file list tpl
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

        // Create new instance of TransFileManger
        $this->transFileMgr = new RegionCountryTransFileManager($clientID);

        // Init local access to \Xtra::app() and \Xtra::app->request()
        $this->app     = \Xtra::app();
        $this->request = $this->app->request();

        $this->setViewValue('subDirDefault', 'regionCountryTrans');
        $this->setViewValue('subDirOptions', json_encode(['regionCountryTrans' => 'regionCountryTrans']));
        $setting = (new SettingACL($clientID))->get(
            SettingACL::MAX_UPLOAD_FILESIZE,
            ['lookupOptions' => 'disabled']
        );
        $uplMax = ($setting) ? $setting['value'] : 10;
        $this->setViewValue('uplMaxSize', $uplMax);


        // Set namespace for JS in view
        $this->setViewValue('jsName', 'fileMgrTrans');
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
        unset($this->transFileMgr);
        unset($this->jsObj);
    }

    /**
     * Set vars on page load, initialize page display.
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgTitle', 'Upload/Manage Region Country Trans Data Files');
        if ($origin = $this->transFileMgr->getParentConfig()) {
            $this->setViewValue('previousTransPg', $origin);
        }
        $this->app->render($this->getTemplate('main'), $this->getViewValues());
    }

    /**
     * Delete an uploaded file.
     * Requires file id passed in through $_POST['fid']
     *
     * @return void
     */
    private function ajaxUploadDelete()
    {
        $filename = $this->request->post('filename');
        $msg      = '';
        try {
            $affected = $this->transFileMgr->deleteFileByID($filename);
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
        $upload = $this->getPostVar('uploadData', '');

        if ($upload && !empty($upload['uploadFile']) && !empty($upload['filename'])) {
            $uploadFile = $upload['uploadFile'];
            $filename = $upload['filename'];

            if (!file_exists($uploadFile)) {
                $result['error'] = 'Uploaded file not found.';
            } else {
                // validate file type here
                $upDnLoadFile = new UpDnLoadFile(['type' => 'upLoad']);
                $validUpload = $upDnLoadFile->validateUpload($filename, $uploadFile, 'csv');
                if ($validUpload !== true) {
                    if (file_exists($uploadFile)) {
                        unlink($uploadFile);
                    }
                    $result['error'] = $validUpload;
                } else {
                    if ($upload && preg_match('/^[a-f0-9]{32}$/i', (string) $upload['fid'])) {
                        $result = $this->transFileMgr->storeUploadedFile(
                            $uploadFile,
                            $filename,
                            $upload
                        );
                    }
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
     * Get list of files currently uploaded
     * Optionally can provide a subdirectory to search $_GET['subdir']
     *
     * @return void
     */
    private function ajaxGetFileList()
    {
        $files = $this->transFileMgr->getTransFiles();
        // Get Smarty to lay it out
        $html = $this->app->view->fetch($this->getTemplate('list'), ['files' => $files]);
        if ($this->app->phpunit) {
            $this->jsObj->Args   = [$html, $files];
        } else {
            $this->jsObj->Args   = [$html];
        }
        $this->jsObj->Result = 1;
    }
}
