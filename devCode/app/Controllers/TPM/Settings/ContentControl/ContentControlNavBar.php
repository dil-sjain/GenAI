<?php
/**
 * Construct the "Content Control" sub-tabs
 */

namespace Controllers\TPM\Settings\ContentControl;

use Controllers\TPM\Settings\SettingsNavBar;
use Controllers\TPM\Settings\SettingsNavBarTabs;
use Lib\Navigation\Navigation;

/**
 * Class ContentControlNavBar controls display of the Content Control sub-tabs
 *
 * @keywords content control, content control tab, content control navigation
 */
class ContentControlNavBar extends SettingsNavBar
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'ContentControl.tpl';

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
     * Create the Content Control navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        parent::createNavBar();

        $navBarNodeName = 'ContentCtrl';
        $navBars        = ['parent' => $this->navBar, 'current' => $navBarNodeName];
        $nav            = new Navigation($navBars);
        $this->navBar   = $nav->navBar;
        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL2', json_encode($tabs));
        $this->setViewValue('tabsDataL2Header', 'Manage Application Content');
    }

    /**
     * Create the navigation (tabs) for the Content Control nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        foreach (ContentControlNavBarTabs::$tabs as $tab) {
            $nav->add($nav->getConfig($this->navBar, $tab));
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
    }

    /**
     * Render the Content Control nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        echo $this->app->render($this->getTemplate(), $this->getViewValues());
    }
}
