<?php
/**
 * search Third Party Profiles
 *
 */
namespace Controllers\TPM\Search;

use Controllers\ThirdPartyManagement\Base;
use Lib\Legacy\Search\Search3pData;

use Lib\Traits\AjaxDispatcher;

/**
 * search Third Party Profiles
 *
 * @keywords search, Third Party Profiles, 3P
 */
class Search3p extends Base
{

    use AjaxDispatcher;
    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'ThirdPartyManagement/Admin/Subscriber/Features/';

    /**
     * @var string Base tempate for View
     */
    protected $tpl = 'SubscriberFeatures.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var object JSON response tempalte
     */
    private $jsObj = null;

    /**
     * @var object Model instance
     */
    private $srch;

    

    /**
     * Constructor
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     *
     * @todo also check TENANT_TPM
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->ajaxExceptionLogging = true;
        $this->app  = \Xtra::app();
        parent::__construct($clientID, $initValues);
        $this->setViewValue('errorMsg', false);
        $this->srch = new Search3pData();
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('pgTitle', 'Subscriber Features');
        $this->setViewValue('subscrCanAccess', $this->canAccess);
        //$this->formConfig = $this->getFormConfig();
        $this->setViewValue('formConfig', $this->formConfig);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * perform search via ajax
     *
     * @return void
     */
    private function ajaxSearch()
    {
        $inValues = $this->app->clean_POST;
        $inValues['src'] = 'PL'; // @todo replace this
        $this->search($inValues);
    }
    
    /**
     * perform search and populate rows into response
     *
     * @param array $inValues input values for parseInput()
     *
     * @return void
     */
    private function search($inValues)
    {
        $validFlds
            = [
            'dat', // Date range: 'past30d'|'past90d'|
            'dir', // => 'yui-dt-asc',
            'fld', // => 'cname',
            'ord', // => 'coname',
            'pcat', // => '0', // integer, only when Type selected
            'pp', // => 15,
            'stat', // => 'active',
            'ptype', // => '0', // integer 3ptype?
            'reg', // => 0, // integer region
            'si', // => 0,
            'srch', // => 'searchname',
            'src', // => 'PL', // populated from session?
            'flds', // => 'id,name,fld3', // comma-delimited list of fields
            ];
        $p = [];
        foreach ($validFlds as $fld) {
            if (isset($inValues[$fld])) {
                $p[$fld] = $inValues[$fld];
            }
        }
        //$p['src'] = 'PL'; // populated from session
        $rows = [
            ['id' => 1, 'name' => 'thisone'],
            ['id' => 2, 'name' => 'thatone'],
            ['id' => 3, 'name' => 'theotherone'],
        ];
        $this->srch->parseInput($p);
        $rows = $this->srch->getRecords();
        if (0) { // delta response
            $this->jsObj->Result = 1; // success
            $this->jsObj->FuncName = 'appNS.subscr.f.updateResponse';
            $this->jsObj->Args = [
                $this->srch->recordsPerPage,
                $this->srch->startOffset,
                $rows
            ];
        } else { // legacy response
            $this->jsObj->Result = 1; // success
            $resp = $this->jsObj->Response = new \stdClass();
            $resp->Records = $rows;
            $resp->Total = $this->srch->rowCount;
            $resp->RowsPerPage = $this->srch->recordsPerPage;
            $resp->RecordOffset = $this->srch->startOffset;
            $resp->PgAuth = $this->app->session->getToken();
            $resp->PgAuthErr = '';
        }
    }

    

    /**
     * update subscriber settings and features
     *
     * @return void
     */
    private function ajaxSearch2()
    {
        $settings = \Xtra::arrayGet($this->app->clean_POST, 'settings', []);
        $features = \Xtra::arrayGet($this->app->clean_POST, 'features', []);

        $this->srch->parseInput($inValues);

        if (!$this->m->setFeaturesAndSettings($f_assoc, $s_assoc)) {
            $this->jsObj->ErrTitle = 'Settings Failure';
            $this->jsObj->ErrMsg = 'Error setting settings or features';
            $this->jsObj->ErrMsgWidth = 280; // optional
            return;
        }

        $rows = $this->m->getFeaturesAndSettings();
        if (empty($rows)) {
            $this->jsObj->ErrTitle = 'Settings';
            $this->jsObj->ErrMsg = 'Unable to retrieve settings.';
            $this->jsObj->ErrMsgWidth = 280; // optional
            return;
        } else {
            $randNum = mt_rand(1, 100);
            $this->jsObj->Result = 1; // success
            $this->jsObj->FuncName = 'appNS.subscr.f.updateResponse';
            $this->jsObj->Args = [
                $rows
            ];
        }

        // Sync legacy settings
        //$syncPerms = new SyncLegacyPermission();
        //$syncPerms->syncClientSettings();
    }
}
