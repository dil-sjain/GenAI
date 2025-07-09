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
 * Class ManageUtilityUsage controller for support utility to track utility usage
 */
class ManageUtilityUsage extends Base
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
     * Utility Usage object
     *
     * @var \Models\Globals\UtilityUsage
     */
    protected $m = null;

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
    protected $tpl = 'utilityUsage.tpl';


    /**
     * Call parent constructor to init for view and then set additional local variables
     *
     * @param int   $clientID   Client ID for the currently logged in subscriber
     * @param array $initValues Any additional values needed for Base constructor
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app = \Xtra::app();
        $this->m =  new UtilityUsage($clientID);
        $this->request = $this->app->request();
    }

    /**
     * Set vars on page load, initialize page display.
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgTitle', 'Manage Usage List');
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * Get data from g_supportUtiliyLog table
     *
     * @return mixed object or null
     */
    public function ajaxGetUtilityUsageData()
    {
        $draw = (int)\Xtra::arrayGet($this->app->clean_POST, 'draw', '');
        $start = (int)\Xtra::arrayGet($this->app->clean_POST, 'start', '');
        $length = (int)\Xtra::arrayGet($this->app->clean_POST, 'length', '');
        $data['order'] = \Xtra::arrayGet($this->app->clean_POST, 'order', '');
        $data['columns'] = \Xtra::arrayGet($this->app->clean_POST, 'columns', '');
        $search = \Xtra::arrayGet($this->app->clean_POST, 'search', '');
        $dataTableSearchValue = $search['value'];
        //column Index
        $columnIndex = $data['order'][0]['column'];
        $data['columnName'] = $data['columns'][$columnIndex]['data'];
        $data['columnSortOrder'] = $data['order'][0]['dir'];
        $data = $this->m->getUsagelist($dataTableSearchValue, $data['columnName'], $start, $length);
        $rtn = (object)null;
        $rtn->draw = (int)$draw;
        $rtn->iTotalRecords = $data['allcountrows'];
        $rtn->iTotalDisplayRecords = $data['totalRecordwithFilter'] ?? 0;
        $rtn->aaData = $data['records'];
        return $rtn;
    }
}
