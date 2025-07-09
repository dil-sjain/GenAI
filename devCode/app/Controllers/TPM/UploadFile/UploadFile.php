<?php
/**
 * File Upload controller
 */

namespace Controllers\TPM\UploadFile;

use Controllers\ThirdPartyManagement\Base;
use Lib\IO;
use Lib\Traits\AjaxDispatcher;
use Lib\SettingACL;

/**
 * UploadFile controller, automates file upload processing (upload dialog, progress bar,
 * transfer of data to the server, etc. However, it is up to the caller of this utility
 * to handle the post processing of the upload file (such as moving the file to the final
 * directory/location, update of upload file tracking tables, etc.)
 *
 * @keywords file, upload, file upload
 */
class UploadFile extends Base
{
    use AjaxDispatcher;

    /**
     * Absolute max for file upload size
     *
     * @var integer
     */
    protected $maxUplSize = 10485760;

    /**
     * Base directory for View
     *
     * @var string
     */
    protected $tplRoot = 'TPM/UploadFile/';

    /**
     * Base template for View
     *
     * @var string
     */
    protected $tpl = 'UploadFileForm.tpl';

    /**
     * Application instance
     *
     * @var object
     */
    private $app = null;

    /**
     * Class constructor
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($clientID, $initValues = [])
    {
        $clientID = (int)$clientID;
        $this->app     = \Xtra::app();
        $this->session = $this->app->session;
        if ($this->app->request->params('spl') || $this->session->get('splExtra')) {
            $this->setViewValue('splExtra', '?spl=1');
            $this->session->set('splExtra', '?spl=1');
            $initValues['isSpLite'] = true;
        }
        $setting = (new SettingACL($clientID))->get(
            SettingACL::MAX_UPLOAD_FILESIZE,
            ['lookupOptions' => 'disabled']
        );
        $uplMax = (!empty($setting['value']) && intval($setting['value']) > 0) ? $setting['value'] : 10;
        $this->maxUplSize = $uplMax * (1024 * 1024);
        $this->setViewValue('uplMaxSize', $uplMax);
        $this->setViewValue('uplMaxFile', $this->maxUplSize);
        parent::__construct($clientID, $initValues);
        $this->setCommonViewValues();
        $inline = ($this->app->request->params('inline') || $this->app->session->get('inline'));
        $this->session->set('inline', $inline);
        $this->setViewValue('inline', $inline);
    }

    /**
     * Set common view values for display the main spLite layout.
     *
     * @return void
     */
    private function setCommonViewValues()
    {
        $this->setViewValue('isSP', null, 1);
        $this->setViewValue('pgTitle', 'Service Provider');
        $this->setViewValue('recentlyViewed', [], 1);
        $this->setViewValue('tabData', [], 1);
        $this->setViewValue('colorScheme', 0, 1);
        $this->setViewValue('jsName', 'uplFile');
        $this->setViewValue('jsController', 'upl/uploadFile/UploadFile');
        $this->setViewValue('jsMonitor', 'auxx/UploadMonitor.php');
    }

    /**
     * This method is called by the Perl script once the upload is done. Therefore to complete the overall process,
     * the docFinishUpload method is invoked to do things like update the database tracking the uploads, move the
     * file to the appropriate location on the server, etc.
     *
     * @param int $fid Upload ID of file (this is not the same as the database ID of an already uploaded file)
     *
     * @return void
     */
    public function uploadFinish($fid)
    {
        $upload = $this->session->get("uploads.$fid");
        $upload = json_decode(json_encode($upload), true);

        $upload['fid'] = $fid;
        $upload['uploadFile'] = '/tmp/' . $upload['filePrefix'] . 'upload';
        $upload['inline'] = $this->app->session->get('inline');
        $params = json_encode($upload);
        $parentAction = "appNS.uplFileFrm.uploadFinishReload(".$params.");";
        $this->setViewValue('parentAction', $parentAction);
        $this->session->forget('uploads.' . $fid);
        $this->session->forget('inline');
    }

    /**
     * Abort upload, generally initiated by the user cancelling an upload after it has been started.
     *
     * @return object Response object
     */
    private function ajaxUploadAbort()
    {
        if ($fid = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'fid')) {
            $this->session->forget("uploads.$fid");
        }
        $this->jsObj->Result = 1;
        return $this->jsObj;
    }

    /**
     * Initialize a file upload
     *
     * @return object Response object
     */
    private function ajaxUploadInit()
    {
        // Use raw, unsanitized value
        $rawFileName = \Xtra::arrayGet($this->app->clean_POST, 'fn', '');
        if (!($filename = IO::filterFilename($rawFileName))) {
            $this->jsObj->ErrTitle = 'Input Error';
            $this->jsObj->ErrMsg   = 'Missing or invalid filename';
            return $this->jsObj;
        }

        if (!($location = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'loc')))) {
            $this->jsObj->ErrTitle = 'Missing Input';
            $this->jsObj->ErrMsg   = 'Missing file location.';
            return $this->jsObj;
        }

        if (!($category = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'cat')))) {
            $this->jsObj->ErrTitle = 'Missing Input';
            $this->jsObj->ErrMsg   = 'Missing file category.';
            return $this->jsObj;
        }

        $useDesc = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'useDsc')) == 'false' ? false : true;
        if (!($description = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'dsc'))) && $useDesc) {
            $this->jsObj->ErrTitle = 'Missing Input';
            $this->jsObj->ErrMsg   = 'Missing file description.';
            return $this->jsObj;
        }
        $title = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'ttl'));
        $maxsize = \Xtra::arrayGet($this->app->clean_POST, 'fileSizeMax');
        if ($maxsize === '' || $maxsize === null || $maxsize === false) {
            $maxsize = $this->maxUplSize;
        } else {
            $maxsize = (int)$maxsize;
        }

        if (!$this->validateFileSizeLimit($maxsize)) {
            $this->jsObj->ErrTitle = 'Bad Upload Parameters';
            $this->jsObj->ErrMsg   = 'File max size limit is invalid.';
            return $this->jsObj;
        }

        $authUserID = $this->session->get('authUserID');
        $fid        = md5(microtime() . $filename . $authUserID);
        $start      = time();
        $upload_id  = md5($fid . \Xtra::conf('cms.acc'));
        $filePrefix = 'upl_' . $upload_id . '_';
        $rtnPage    = $this->app->sitePath . 'upl/uplFile/ifr';

        // Perl upload handler needs these values

        $spec = "field=upload|max=$maxsize|return=$rtnPage";
        $fil  = '/tmp/' . $filePrefix . 'spec';

        if (file_put_contents($fil, $spec)) {
            $this->jsObj->Result = 1;
            $data = [
                'uplToken' => $fid,
                'uplStart' => $start,
                'filename' => $filename,
            ];
            $this->session->set(
                "uploads.$fid",
                [
                'start'       => $start,
                'location'    => $location,
                'filename'    => $filename,
                'description' => $description,
                'category'    => $category,
                'title'       => $title,
                'filePrefix'  => $filePrefix
                ]
            );

            $this->jsObj->FuncName = 'appNS.uplFileFrm.formPostProcessFields';
            $this->jsObj->Args     = [$data];
        } else {
            $this->jsObj->ErrTitle = 'File Upload Error';
            $this->jsObj->ErrMsg   = 'Failed initializing file transfer.';
        }

        return $this->jsObj;
    }

    /**
     * Return true if $fileSize is an integer and less than
     * $absoluteMax (and greater than zero)
     *
     * @param integer $fileSize filesize for validation
     *
     * @return boolean
     */
    private function validateFileSizeLimit($fileSize)
    {
        if (is_int($fileSize) && $fileSize <= $this->maxUplSize && $fileSize > 0) {
            return true;
        }
        return false;
    }
}
