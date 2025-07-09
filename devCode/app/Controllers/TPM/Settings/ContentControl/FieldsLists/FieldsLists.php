<?php
/**
 * Construct and control the "Fields/Lists" initial page and operations.
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists;

use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Settings\ContentControl\FieldsLists\FieldsListsData;
use Lib\Support\UserLock;
use Lib\Traits\AjaxDispatcher;
// These are the individual field/list (sub) controllers:
use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;

use function PHPSTORM_META\type;

/**
 * Class FieldsLists is little more than a traffic cop. It handles the initial setup and
 * page load of the tab, and produces the bare minimum display (page heading and the dropdown list.)
 * On selection from the dropdown list, FieldsLists will take that value and load the appropriate
 * sub-controller for the selection as an object. The FieldsLists controller then funnels all requests
 * into the sub-controller, which returns an object for use as jsObj. All sub-controllers will use
 * javascript to produce their display, which will be in the tpl portion of the individual script.
 *
 * @keywords tpm, fields lists, settings, content control
 */
#[\AllowDynamicProperties]
class FieldsLists
{
    use AjaxDispatcher;

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object Sub controller instance.
     */
    private $subCtrl = null;

    /**
     * @var object Model instance
     */
    private $flData = null;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var integer current userID
     */
    private $userID = 0;

    /**
     * @var string Base namespace path for TpmList controllers
     */
    private $ctrlRoot = 'Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\\';

    /**
     * @var The default TpmLists controller.
     */
    private $baseListController = 'TpmLists';

    /**
     * @var array Code managed list of allowed controllers
     */
    private $allowedControllers = [];

    /**
     * @var array Code managed list of allowed list types.
     */
    private $allowedListTypes = [];

    /**
     * @var array Text translations
     */
    private $txt = null;

    /**
     * @var boolean Useful in dev/troubleshooting to use the source js vice minified.
     */
    private $useSrc = false;

    /**
     * instantiate and dispatch from route file
     *
     * @return void
     */
    public static function invoke()
    {
        $tenantID = \Xtra::app()->ftr->aclSpec['tenant'];
        (new self($tenantID))->ajaxHandler();
    }

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Delta tenantID
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->app      = \Xtra::app();
        $this->userID   = $this->app->ftr->aclSpec['user'];
        $this->tenantID = (int)$tenantID;
        $this->flData   = new FieldsListsData($tenantID);
        $this->setAllowedControllers();
        $this->setAllowedListTypes();
        $this->txt = $this->getGroupText('field_list_srv');
        $this->flData->setTrText($this->txt);
    }

    /**
     * Load the js and all other dependencies for initial fields/lists tab
     *
     * @return void
     */
    private function ajaxInit()
    {
        $initArgs = $this->getInitVars(); // values to pass to appNS.flCtrl.init after it has been loaded by rxLoader
        $cssPath  = '/assets/css/';
        $jsPath   = '/assets/js/';
        $addSrc   = '';
        if ($this->useSrc) {
            $cssPath .= 'src/';
            $jsPath  .= 'src/';
            $addSrc   = '.src';
        }
        $filesToLoad = [
                $cssPath .'TPM/Settings/Control/FieldsLists/FieldsLists'. $addSrc .'.css',
                '/assets/js/views/TPM/settings/control/lists/flHome||flHome.html',
                $jsPath .'TPM/Settings/Control/FieldsLists/FlCtrl'. $addSrc .'.js',
                '/assets/js/smXtra.min.js',
        ];
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.rxLoader.loadFiles';
        // Args property must be a simple, indexed (non-associateve) array
        $this->jsObj->Args = [
            $filesToLoad,
            'appNS.flCtrl.init', // invoked by rxLoader after file loading is complete
            null,                 // ** placeholder argument required by loadFiles() and orderFiles() -- MUST BE NULL
            [$initArgs], // ** must also be a simple (not associative) array, hence wrapping initArgs in an array
        ];
    }

    /**
     * Method to get all the necessary data vars to initialize the Fields/Lists system.
     *
     * @return void
     */
    private function getInitVars()
    {
        $txt = $this->app->trans->group('field_list_js');
        // js no likey the key of "default". so we'll change it here. (minimizing issues)
        $txt['defaultWord'] = $txt['default'];
        unset($txt['default']);
        $list = $this->getJsListData();
        $tmpList = [];
        $x = 1;
        foreach ($list as $k => $v) {
            unset($v['files']);
            $v['srt'] = $x;
            $tmpList[] = $v;
            $x++;
        }
        return [
            'lists' => $list,
            'view' => [
                'lists'    => $tmpList,
                'txtView'  => $this->txt['view'],
                'errTitle' => $this->txt['title_operation_failed'],
                'errMsg'   => $this->txt['error_data_not_loaded'] .' '. $this->txt['page_auth_msg'],
                'nothing_to_do' => $txt['nothing_to_do'],
                'no_records' => $txt['no_records'],
            ],
            'text'  => $txt,
            'extendErrMsgs' => ($this->app->getMode() == 'Production' ? false : true),
            'awsEnabled' => filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Pass clean post data into the sub-controller. This is the face for all operations, and once
     * into the sub-controller it can decide what to do based on the data. Regardless of data passed
     * the following post array keys must ALWAYS be present on every ajax request, and will be checked
     * when the class is loaded.
     *     ctrl       => This must match an array value in setAllowedControllers method below
     *     listTypeID => This must match an array key value in setAllowedListTypes method below
     *     subOp      => The sub-controller operation to be used. (EX: saveList, listIndex, doSomething)
     *
     * ALL METHODS MUST RETURN AN OBJECT TO THIS METHOD WHICH WILL BE USED AS $this->jsObj.
     *
     * @return void
     */
    private function ajaxManageData()
    {
        if (!$this->subCtrl) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->txt['title_operation_failed'];
            $this->jsObj->ErrMsg = $this->txt['message_operation_not_recognized'];
        }
        $this->jsObj = $this->subCtrl->manageData($this->app->clean_POST, $this->jsObj);
    }

    /**
     * Set list of allowed Fields/Lists controllers. setAllowedListTypes will
     * fill in the empty array for each key with allowed listTypeID's.
     * Could be DB managed, but code managed makes more sense for now.
     *
     * @return void
     */
    private function setAllowedControllers()
    {
        $activeCtrl = $this->flData->getListTypes();
        foreach ($activeCtrl as $a) {
            if ($a['active'] == 1 && !empty($a['ctrl'])) {
                $this->allowedControllers[$a['ctrl']] = [];
            }
        }
    }

    /**
     * Set list of allowed Fields/Lists types.
     * Could be DB managed, but code managed makes more sense for now.
     *
     * @return void
     */
    private function setAllowedListTypes()
    {
        // below array is structured like a db array result.
        $listTypes = $this->flData->getListTypes();
        //sequence types, add ID's to allowed controllers.
        $this->allowedListTypes = $listTypes;
        foreach ($listTypes as $lt) {
            // only set list types on a controller match.
            // "no dough, no show." -Lucky Day
            if (array_key_exists($lt['ctrl'], $this->allowedControllers)) {
                $this->allowedControllers[$lt['ctrl']][] = $lt['id'];
            }
        }
    } // end setAllowedListTypes();

    /**
     * Setup data array for flCtrl script to save multiple ajax calls
     * when switching sub control modules.
     *
     * @return array Array of applicable data information.
     */
    private function getJsListData()
    {
        $jsListData = [];
        foreach ($this->allowedListTypes as $l) {
            $jsListData[$l['key']] = [
                'id'    => $l['id'],
                'key'   => $l['key'],
                'ctrl'  => $l['ctrl'],
                'files' => $l['files'],
                'name'  => (!empty($l['name']) ? $l['name'] : $l['default']),
            ];
        }
        return $jsListData;
    }

    /**
     * Instantiate the proper Fields/Lists controller.
     * Available classes need to be set for use above main class.
     *
     * @param string $controller Value originating from this->setAllowedControllers
     *
     * @return boolean True on success, else false.
     */
    private function loadListController($controller)
    {
        // make sure the controller is valid, and the controller/listTypeID combination is valid.
        if (array_key_exists($controller, $this->allowedControllers)
            && in_array($this->listTypeID, $this->allowedControllers[$controller])
        ) {
            $loadTpmList = $this->ctrlRoot . $controller;
            $initValues = [
                'txt' => $this->txt,
            ];
            $this->subCtrl = new $loadTpmList($this->tenantID, $this->listTypeID, $this->userID, $initValues);

            $subOp = \Xtra::arrayGet($this->app->clean_POST, 'subOp', false);
            if ($this->subCtrl->isLoaded()) {
                if (!$subOp || !method_exists($this->subCtrl, $subOp)) {
                    $this->jsObj->Result = 0;
                    $this->jsObj->ErrTitle = $this->txt['title_operation_failed'];
                    $this->jsObj->ErrMsg = $this->txt['message_operation_not_recognized'];
                }
                return true;
            }
        }
        $this->jsObj->Result = 0;
        $this->jsObj->ErrTitle = $this->txt['error_invalidListTitle'];
        $this->jsObj->ErrMsg = $this->txt['error_requestedListNotValid'];
        return false;
    }

    /**
     * Use stub method to ensure designated List controller has valid, required data
     *
     * @param string $op  Current $op in the ajaxHandler method. (Not used. Cannot alter signature.)
     * @param string $req Current $req value in the ajaxHandler method. (Not used. Cannot alter signature.)
     *
     * @return boolean True on success, else false.
     */
    protected function validateOperation($op, $req)
    {
        if ($op != 'manageData') {
            $this->jsObj->Result = 1;
            return true;
        }
        $ctrl = $this->app->clean_POST['ctrl'];
        $this->listTypeID = (int)$this->app->clean_POST['listTypeID'];
        if ($this->listTypeID <= 0) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->txt['error_invalidListTitle'];
            $this->jsObj->ErrMsg = $this->txt['error_requestedListNotValid'];
            return false;
        }
        if (!$this->loadListController($ctrl)) {
            // (loadListController checks posted subOp and also sets jsObj on error)
            return false;
        }
        return true;
    }

    /**
     * Pull translation group upon request.
     *
     * @return array Array of text translations
     */
    private function getGroupText($group)
    {
        return $this->app->trans->group($group, $this->app->trans->langCode, $this->tenantID);
    }
} // end class
