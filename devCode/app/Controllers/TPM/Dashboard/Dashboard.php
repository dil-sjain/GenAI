<?php
/**
 * Dashboard Controller
 *
 * @keywords dashboard, widget
 */
namespace Controllers\TPM\Dashboard;

use Lib\Traits\AjaxDispatcher;
use Controllers\ThirdPartyManagement\Base;
use Controllers\TPM\Dashboard\Subs\DashboardSubCtrlFactory as DashboardFactory;
use Models\Globals\Sync\UserScopeSyncTPM;
use Models\TPM\Dashboard\DashboardData;
use Models\TPM\Dashboard\Subs\DataRibbon;

/**
 * Class Dashboard
 *
 * @package Controllers\TPM\Dashboard
 */
#[\AllowDynamicProperties]
class Dashboard extends Base
{
    use AjaxDispatcher;

    /**
     * @var \Slim\Slim Current app instance
     */
    protected $app = null;

    /**
     * @var object Session variable
     */
    protected $session = null;

    /**
     * @var object Instance of app logger
     */
    protected $log = null;

    /**
     * @var string Root for tpls
     */
    protected $tplRoot = 'TPM/Dashboard/';

    /**
     * @var string
     */
    protected $tpl = 'dashboard.html.tpl';

    /**
     * @var DashboardData
     */
    protected $dsh_m;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var int User id
     */
    protected $userID;

    /**
     * @var int Role id
     */
    protected $roleID;

    /**
     * @var int Current tenant ID
     */
    protected $tenantID;

    /**
     * @var string name of this node
     */
    private $navBarNodeName = 'Dashboard';

    /**
     * @var array Tooltips descs for widgets
     */
    private $widgetDescriptions = [];


    /**
     * Dashboard constructor.
     *
     * @param int   $tenantID   tenant id
     * @param array $initValues init values
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->ajaxExceptionLogging = true;
        \Xtra::requireInt($tenantID);
        $this->sitePath   = \Xtra::conf('cms.sitePath');

        $this->app     = \Xtra::app();
        $this->log     = $this->app->log;
        $this->session = $this->app->session;
        $this->userID  = $this->app->ftr->user;
        $this->roleID  = $this->app->ftr->role;

        // Top tab must be set before instantiating parent constructor
        $this->session->set('navSync.Top', $this->navBarNodeName);

        parent::__construct($tenantID, $initValues);

        $this->dsh_m = new DashboardData($this->roleID, $this->userID);
    }


    /**
     * method for initial view load
     *
     * @throws \Exception
     *
     * @return null
     */
    public function initialize()
    {
        $this->syncUsersRegionsAndDepartments($this->userID);
        $dashboardDoNotDisplayFunctionBar = true;
        $dshAuthToken = $this->session->getToken();
        $this->setViewValue('cvrAuthToken', $dshAuthToken);
        $this->setViewValue('dashboardDoNotDisplayFunctionBar', $dashboardDoNotDisplayFunctionBar);

        $this->fetchAndCacheDescriptions();
        $widgMeta = $this->getWidgMetaData();

        $this->setViewValue('widgMeta', json_encode($widgMeta));
        $this->setViewValue('userWidgets', json_encode($this->getEnabledList($widgMeta)));
        $this->setViewValue('widgetDescriptions', json_encode($this->getEnabledList($widgMeta)));
        $this->setViewValue('defaultTileList', $this->getDefaultTileList(true));
        $this->setViewValue('gearTitle', $this->app->trans->codeKey('configure_dashboard'));
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * perform the legacy to delta sync of regions and departments
     *
     * @param integer $userID users.id
     *
     * @return void
     *
     * @todo remove this after User Management refactor
     */
    private function syncUsersRegionsAndDepartments($userID)
    {
        $m = new UserScopeSyncTPM();
        $m->smartInitUser($userID);
    }

    /**
     * Fetch data for the initial page load
     *
     * @return string Json-encoded object
     */
    private function fetchAndCacheDescriptions()
    {
        $widgets = $this->dsh_m->getWidgets('all');
        $jsDataArray = [];

        foreach ($widgets as $widget) {
            if (empty($this->widgetDescriptions[$widget->ctrlClass])) {
                $this->widgetDescriptions[$widget->ctrlClass] = $this->fetchWidgetDescription($widget->ctrlClass);
            }
        }
    }

    /**
     * Get description without data fetch.
     *
     * @param string $cl Class name (name) of the the sc.
     *
     * @return object
     */
    private function fetchWidgetDescription($cl)
    {
        $jsData = (new DashboardFactory($cl, $this->app->ftr->tenant))
            ->getBuiltClass()
            ->getDescription();

        return $jsData;
    }


    /**
     * Get the list of default tiles according to that subcontroller
     *
     * @param bool $asJson Whether to return it as json
     *
     * @return null
     */
    private function getDefaultTileList($asJson = false)
    {
        $tiles = (new DataRibbon($this->tenantID))->getTiles();

        if ($asJson) {
            $tiles = json_encode($tiles);
        }

        return $tiles;
    }

    /**
     * get required data for specific user/widget controller, aka: subCtrlClassName.
     *
     * @return via AjaxDispatcher response.
     */
    protected function ajaxGetData()
    {
        $jsData = [];
        $err = false;

        if (($params = json_decode((string) $this->app->request->post('data'))) === null) {
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $params;
        } else {
            if (is_object($params)) {
                try {
                    $jsData = (new DashboardFactory($params->subCtrlClassName, $this->app->ftr->tenant))
                        ->getBuiltClass()
                        ->getDashboardSubCtrlData();

                    $jsData = $this->encodeAndClean($jsData);
                } catch (\RuntimeException $e) {
                    $err = $e->getMessage();
                }
            } else {
                $err = $params;
            }

            if (empty($err)) {
                $this->jsObj->Result = 1; // success
                $this->jsObj->Data = [$jsData];
                $this->jsObj->Args = $params->subCtrlClassName;
            } else {
                $this->jsObj->ErrTitle = 'Operation Failed';
                $this->jsObj->ErrMsg = $err;
            }
        }
    }

    /**
     * Encodes json. Cleans out newlines introduced through a textarea into user
     * fields.
     *
     * @param object $jsData Data item
     *
     * @return mixed
     */
    private function encodeAndClean($jsData)
    {
        $jsData = str_replace('\\\n', '', json_encode($jsData));
        if (strpos('\n', $jsData)) {
            $jsData = str_replace('\n', '', json_encode($jsData));
        }

        return $jsData;
    }

    /**
     * Calls the widget-specific persistWidgetState() method.
     *
     * @return void
     */
    protected function ajaxPersistWidgetState()
    {
        if (($params = json_decode((string) $this->app->request->post('data'))) === null) {
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $params;
        } else {
            if (is_object($params)) {
                try {
                    // - persist widget state to DB.
                    // - note we are using the DashboardData model to support method callbacks.
                    (new DashboardFactory($params->subCtrlClassName, $this->app->ftr->tenant))
                        ->getBuiltClass()
                        ->persistWidgetState($this->dsh_m, $params);
                } catch (\RuntimeException $e) {
                    $err = $e->getMessage();
                }
            } else {
                $err = $params;
            }

            if (empty($err)) {
                $this->jsObj->Result = 1; // success
            } else {
                $this->jsObj->ErrTitle = 'Operation Failed';
                $this->jsObj->ErrMsg = $err;
            }
        }
    }

    /**
     * if user rearranges widgets on page, this method will persist that new arrangement.
     *
     * @return void via AjaxDispatcher response.
     */
    protected function ajaxUpdateWidgetSequence()
    {
        if (($params = json_decode((string) $this->app->request->post('data'))) === null) {
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $params;
        } else {
            $err = '';
            if (is_object($params)) {
                $this->updateWidgetSequence($params);
            } else {
                $err = $params;
            }

            if (empty($err)) {
                $this->jsObj->Result = 1; // success
                $this->jsObj->Data = null;
            } else {
                $this->jsObj->ErrTitle = 'Operation Failed';
                $this->jsObj->ErrMsg = $err;
            }
        }
    }

    /**
     * perform widget sequence update.
     *
     * @param string $params contains a list of widgets
     *
     * @return null
     */
    protected function updateWidgetSequence($params)
    {
        $widgets = $params->listOfWidgets;

        for ($i=0; $i < count($widgets); $i++) {
            $clientRec = $this->dsh_m->getWidgetByClassName($widgets[$i]);

            if ($i + 1 === $clientRec->sequence) {
                continue;
            }

            $DBRec = $this->dsh_m->getWidgetBySequence($i + 1);
            if ($DBRec === false) {
                $this->dsh_m->updateWidgetSequence($widgets[$i], $i + 1);

                continue;
            }

            $saveSequence = $i+1;
            $saveSequence *= -1;
            $this->dsh_m->updateWidgetSequence($widgets[$i], $saveSequence);
            $this->dsh_m->updateWidgetSequence($DBRec->ctrlClass, $clientRec->sequence);
            $this->dsh_m->updateWidgetSequence($clientRec->ctrlClass, $DBRec->sequence);
        }
    }

    /**
     * Get initial widget data
     *
     * @return null ; Sets ajax obj
     */
    public function ajaxFetchInitialWidgets()
    {
        $jsData = [];
        $widgetNames = $this->app->clean_POST['widgets'];
        $reqEnabled = $this->app->clean_POST['reqEnabled'] == (1 ? true : false);

        if ($reqEnabled) {
            $widgets = $this->dsh_m->getEnabledWidgets($widgetNames);
        } else {
            $widgets = $this->dsh_m->getWidgets($widgetNames);
        }

        if ($widgets === false) {
            return false;
        }

        foreach ($widgets as $widget) {
            $jsData[$widget->ctrlClass] = [
                'seq'       => $widget->sequence,
                'name'      => $widget->name,
                'state'     => $widget->state,
                'ctrlClass' => $widget->ctrlClass,
                'files'     => $widget->files
            ];
        }

        $jsData = json_encode($jsData);

        $this->jsObj->Result = 1; // success
        $this->jsObj->Args = $jsData;
    }

    /**
     * Save user settings
     *
     * @return null
     */
    public function ajaxSaveUserSettings()
    {
        $cleanDat = $this->postedSettings();
        $widgets = $this->dsh_m->saveUserSettings($cleanDat);

        if ($widgets && !isset($widgets['error'])) {
            $this->ajaxGetWidgets();
        } else {
            $this->jsObj->Result = 0; // success
            $this->jsObj->Args = ['error' => "Failed to save settings"];
        }
    }

    /**
     * Get posted tile and widget settings data
     * for each of the logged-in user's
     * widgets.
     *
     * Converts posted values to integer 1 / 0 for checkbox
     * input storage.
     *
     * @return array
     */
    private function postedSettings()
    {
        $ret = [];
        if (isset($this->app->clean_POST['widgetSettings'])) {
            $ret['widgetSettings'] = $this->postedWidgSettings($this->app->clean_POST['widgetSettings']);
        }

        if (isset($this->app->clean_POST['dataTiles'])) {
            $ret['dataTiles'] = $this->postedTileSettings($this->app->clean_POST['dataTiles']);
        }

        return $ret;
    }

    /**
     * Get posted data ribbon tile settings for each of the logged-in user's
     * widgets.
     *
     * Converts posted values to integer 1 / 0 for checkbox
     * input storage.
     *
     * @param array $ws Motherload of posted settings
     *
     * @return array
     */
    private function postedTileSettings($ws)
    {
        $cleanDat = [];
        foreach ($ws as $wd) {
            if ($wd['isChecked'] === 'true') {
                $active = true;
            } elseif ($wd['isChecked'] === 'false') {
                $active = false;
            } else {
                continue;
            }

            $cleanDat[] = [
                'tile' => $wd['tile'],
                'active' => $active
            ];
        }

        return $cleanDat;
    }

    /**
     * Get posted widget settings data for logged in user
     *
     * @param array $ws Motherload of posted settings
     *
     * @return array
     */
    private function postedWidgSettings($ws)
    {
        $userList = $this->dsh_m->getUserClasses();
        $cleanDat = [];
        foreach ($ws as $wd) {
            $cl = $wd['ctrlClass'];
            if (in_array($cl, $userList) && isset($wd['isChecked'])) {
                if ($wd['isChecked'] === 'true') {
                    $active = true;
                } elseif ($wd['isChecked'] === 'false') {
                    $active = false;
                } else {
                    continue;
                }

                $cleanDat[] = [
                    'ctrlClass' => $cl,
                    'active' => $active
                ];
            }
        }
        return $cleanDat;
    }

    /**
     * Get widgets data from the model
     *
     * @return null  sets ajax return obj
     */
    public function ajaxGetWidgets()
    {
        $reqEnabled
            = isset($this->app->clean_POST['reqEnabled'])
        && $this->app->clean_POST['reqEnabled'] == 1
            ? true
            : false;

        if ($reqEnabled) {
            $this->jsObj->Args = (array)$this->dsh_m->allWidgetData();
        } else {
            $this->jsObj->Args = (array)$this->dsh_m->getWidgets('all');
        }
        $this->jsObj->Result = 1;
    }

    /**
     * Fetches all initial widget data, including whether each
     * is enabled and its description.
     *
     * @return array
     */
    private function getWidgMetaData()
    {
        $allDat = $this->dsh_m->getWidgets('all');
        $ret = [];

        array_map(
            function ($itm) use (&$ret) {
                $ret[] = [
                    'ctrlClass' => $itm->ctrlClass,
                    'active' => $itm->active
                ];
            },
            $allDat
        );

        $this->addWidgetDescriptions($ret);

        return $ret;
    }


    /**
     * Merge widget descriptions into the array.
     *
     * @param array &$allDat referential Receptacle for widget descs
     *
     * @return void
     */
    private function addWidgetDescriptions(&$allDat)
    {
        $descs = $this->widgetDescriptions;

        foreach ($allDat as &$dat) {
            $class = $dat['ctrlClass'];
            if (isset($descs[$class])) {
                $dat['description'] = $descs[$class];
            }
        }

        return $dat;
    }

    /**
     * This is a filter for the specified widget; returns
     * a list of enabled widgets for this user.
     *
     * @param array $widgMeta Widget meta data from which to obtain enabled widg class names
     *
     * @return array
     *
     * @todo: Ultimately widgets should not arrive here
     * if the tenant or Securimate has disabled them via the
     * "active" col in g_dashboardWidgetsActive
     */
    private function getEnabledList($widgMeta)
    {
        $ret = array_map(
            fn($itm) => (int)$itm['active'] === 1 ? $itm['ctrlClass'] : null,
            $widgMeta
        );

        return array_values(array_filter($ret));
    }
}
