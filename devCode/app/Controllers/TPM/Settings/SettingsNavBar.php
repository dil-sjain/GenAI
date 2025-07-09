<?php
/**
 * Construct the "Settings" sub-tabs
 */

namespace Controllers\TPM\Settings;

use Controllers\ThirdPartyManagement\Base;
use Lib\Navigation\Navigation;

/**
 * Class Settings controls display of the Settings sub-tabs
 *
 * @keywords settings, settings tab, settings navigation
 */
class SettingsNavBar extends Base
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object Session variable
     */
    protected $session = null;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Home.tpl';

    /**
     * @var array Contains the nav (tab) bar configuration (name, parent reference, etc.)
     */
    protected $navBar = null;

    /**
     * @var string name of this node
     */
    private $navBarNodeName = 'Settings';

    /**
     * Init class constructor
     *
     * @param int   $clientID   Current client ID
     * @param array $initValues Any additional parameters that need to be passed in
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app     = \Xtra::app();
        if (false) {
            $this->app->log->debug('debug test Delta');
            $this->app->log->info('info test Delta');
        }
        $this->session = $this->app->session;

        // Top tab must be set before instantiating parent constructor
        $this->session->set('navSync.Top', $this->navBarNodeName);

        parent::__construct($clientID, $initValues);
    }

    /**
     * Create the Settings navigation bar
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
     * Create the navigation (tabs) for the Settings nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        foreach (SettingsNavBarTabs::$tabs as $tab) {
            $nav->add($nav->getConfig($this->navBar, $tab));
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
    }

    /**
     * Render the Settings nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        echo $this->app->view->fetch($this->getTemplate(), $this->getViewValues());
    }
}
