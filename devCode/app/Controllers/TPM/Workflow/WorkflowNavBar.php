<?php
/**
 * Construct the "Workflow" sub-tabs
 */

namespace Controllers\TPM\Workflow;

use Controllers\ThirdPartyManagement\Base;
use Lib\Navigation\Navigation;

/**
 * Class WorkflowNavBar controls display of the Workflow sub-tabs
 *
 * @keywords workflow, workflow tab, workflow navigation
 */
class WorkflowNavBar extends Base
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
     * @var object Session variable
     */
    protected $session = null;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Workflow/';

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
    private $navBarNodeName = 'Workflow';

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
        $this->session->set('navSync.Top', $this->navBarNodeName);
        parent::__construct($clientID, $initValues);
    }

    /**
     * Create the Workflow navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        $navBars = ['parent' => $this->navBar, 'current' => $this->navBarNodeName];
        $nav = new Navigation($navBars);
        $this->navBar = $nav->navBar;
        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL1', json_encode($tabs));
    }

    /**
     * Create the navigation (tabs) for the Workflow nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        foreach (WorkflowNavBarTabs::$tabs as $tab) {
            $nav->add($nav->getConfig($this->navBar, $tab), true);
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
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
            throw new \Exception('Missing Client ID in WorkflowNavBar Controller');
        }
        $this->clientID = $clientID;
        if (isset($initValues['route'])) {
            $this->activeRoute = $initValues['route'];
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
        echo $this->app->view->render($this->getTemplate(), $this->getViewValues());
    }
}
