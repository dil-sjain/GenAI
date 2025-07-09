<?php
/**
 * Construct the "Third Party Management" sub-tabs
 */

namespace Controllers\TPM\ThirdPartyMgt;

use Controllers\ThirdPartyManagement\Base;
use Lib\Navigation\Navigation;
use Models\TPM\ThirdPartyMgt\ThirdPartyMgtNavBarData;

/**
 * Class ThirdPartyMgtNavBar controls display of the Third Party Management sub-tabs
 *
 * @keywords third party management, third party management tab, third party management navigation
 */
class ThirdPartyMgtNavBar extends Base
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object Current Skinny route
     */
    private $activeRoute = '';

    /**
     * @var integer Client ID
     */
    protected $clientID = null;

    /**
     * @var object Instance of the model for this controller
     */
    private $model = null;

    /**
     * @var object Session variable
     */
    protected $session = null;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/ThirdPartyMgt/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Home.tpl';

    /**
     * @var integer Third Party Profile ID
     */
    protected $tpID = null;

    /**
     * @var array Contains the nav (tab) bar configuration (name, parent reference, etc.)
     */
    protected $navBar = null;

    /**
     * @var string name of this node
     */
    private $navBarNodeName = 'ThirdPartyMgt';

    /**
     * @var boolean Flag to allow multi-tenant access
     */
    private $allowMultiTenantAccess = false;

    /**
     * Init class constructor
     *
     * @param int   $clientID   Current client ID
     * @param array $initValues Any additional parameters that need to be passed in
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app     = \Xtra::app();
        $this->session = $this->app->session;

        $this->processParams($clientID, $initValues);

        // activeTopTab must be set before instantiating parent constructor
        $this->session->set('navSync.Top', $this->navBarNodeName);

        parent::__construct($clientID, $initValues);
    }

    /**
     * Dispatch from route
     *
     * @return void
     */
    public static function invoke()
    {
        $app = \Xtra::app();
        $initValues = [];
        $curPath = rtrim((string) $app->request->getPath(), '/');
        switch ($curPath) {
            case '/tpm/3p/uber':
                $initValues = [
                'route' => 'tpm/3p/uber',
                'params' => $app->request()->params()
                ];
                $method = 'renderNavBar';
                break;
            default:
                return;
        }
        (new self($app->ftr->tenant, $initValues))->{$method}();
    }

    /**
     * Check if access is allowed to a navigation element (tab) based upon factors other than user permissions handled
     * by 'Features' as specified in each tabs configuration. For example, the Custom Fields tab is only displayed
     * if Custom Fields have been defined.
     *
     * @param array $tab Contains the tab configuration
     *
     * @return boolean True/false indicator if access is allowed or not
     */
    private function allowAccess($tab)
    {
        switch ($tab['me']) {
            case 'ProfileDetail':
                $allow = $this->tpID && $this->model->allowProfileDetailAccess($this->tpID);
                break;
            case 'PendingReview':
                $allow = $this->model->allowPendingReviewAccess();
                break;
            case 'UberSearch':
                if ($allow = $this->model->allowUberSearch()) {
                    $this->allowMultiTenantAccess = true;
                }
                break;
            default:
                $allow = true; // This is for 3P List
        }
        return $allow;
    }

    /**
     * Create the Third Party Management navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        $navBars      = ['parent' => $this->navBar, 'current' => $this->navBarNodeName];
        $nav          = new Navigation($navBars);
        $this->navBar = $nav->navBar;

        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL1', json_encode($tabs));
    }

    /**
     * Create the navigation (tabs) for the Third Party Management nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        foreach (ThirdPartyMgtNavBarTabs::$tabs as $tab) {
            $tabRouteParts   = explode('/', (string) $tab['url']);
            $tabRoute        = array_pop($tabRouteParts);
            $tabStateChanged = false;
            $tabUrl          = $tab['url'];

            if ($this->allowAccess($tab)) {
                $nodeId = $nav->add($nav->getConfig($this->navBar, $tab), true);
                $active = (int)($tab['url'] === $this->activeRoute
                    || $tabRoute === $this->activeRoute);
                if ((isset($tab['hidden']) && $tab['hidden'])
                    || (isset($tab['active']) && $tab['active'] !== $active)
                    || (!isset($tab['active']) && $active)
                ) {
                    if ($nodeId) {
                        $tab['hidden'] = false;
                        $tab['active'] = $active;
                        $nav->updateStateByNodeId($nodeId);
                    }
                }
            } else {
                $nodeId = $nav->add($nav->getConfig($this->navBar, $tab), false);
                if ((isset($tab['hidden']) && !$tab['hidden'])
                    || (isset($tab['active']) && $tab['active'])
                ) {
                    if ($nodeId) {
                        $tab['hidden'] = false;
                        $tab['active'] = 0;
                        $nav->updateStateByNodeId($nodeId);
                    }
                }
            }
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
        $this->setViewValue('allowMultiTenantAccess', $this->allowMultiTenantAccess);
        $this->setViewValue('routeSegment', '3p');
    }

    /**
     * Check and process passed in params as needed for further processing
     *
     * @param integer $clientID   Client ID
     * @param array   $initValues Contains any passed in params that may need some processing
     *
     * @throws \Exception Throws an exception if required parameters are not present
     *
     * @return void
     */
    private function processParams($clientID, $initValues)
    {
        if (empty($clientID)) {
            throw new \Exception('Missing Client ID in ThirdPartyMgtNavBar Controller');
        }

        $this->clientID = $clientID;
        $this->model    = new ThirdPartyMgtNavBarData($clientID);

        if (isset($initValues['route'])) {
            $this->activeRoute = $initValues['route'];
        }

        if (isset($initValues['params']) && isset($initValues['params']['id'])) {
            $this->tpID = $initValues['params']['id'];
        } elseif ($this->app->session->has('currentID.3p')) {
            $this->tpID = $this->app->session->get('currentID.3p');
        }
    }

    /**
     * Render the nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        $this->app->render($this->getTemplate(), $this->getViewValues());
    }
}
