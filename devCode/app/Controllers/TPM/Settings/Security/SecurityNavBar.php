<?php
/**
 * Construct the "Security" sub-tabs
 */

namespace Controllers\TPM\Settings\Security;

use Controllers\TPM\Settings\SettingsNavBar;
use Lib\Navigation\Navigation;

/**
 * Class SecurityNavBar controls display of the Security sub-tabs
 *
 * @keywords security, security tab, security navigation
 */
#[\AllowDynamicProperties]
class SecurityNavBar extends SettingsNavBar
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/Security/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Security.tpl';

    /**
     * @var array Contains the nav (tab) bar configuration (name, parent reference, etc.)
     */
    protected $navBar = null;

    /**
     * Init class constructor
     *
     * @param int   $clientID   Current client ID
     * @param array $initValues Any additional parameters that need to be passed in
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app = \Xtra::app();

        parent::__construct($clientID, $initValues);
    }

    /**
     * Create the Security navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        parent::createNavBar();

        $navBarNodeName = 'Security';
        $navBars        = ['parent' => $this->navBar, 'current' => $navBarNodeName];
        $nav            = new Navigation($navBars);
        $this->navBar   = $nav->navBar;

        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL2', json_encode($tabs));
        $this->setViewValue('tabsDataL2Header', 'Manage Security Settings');
    }

    /**
     * Create the navigation (tabs) for the Security nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        foreach (SecurityNavBarTabs::$tabs as $tab) {
            $nav->add($nav->getConfig($this->navBar, $tab));
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
    }

    /**
     * Render the Security nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        echo $this->app->view->render($this->getTemplate(), $this->getViewValues());
    }
}
