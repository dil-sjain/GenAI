<?php
/**
 * Construct the "Pending Review" sub-tabs
 */

namespace Controllers\TPM\ThirdPartyMgt\PendingReview;

use Controllers\TPM\ThirdPartyMgt\ThirdPartyMgtNavBar;
use Lib\Navigation\Navigation;

/**
 * Class PendingReviewNavBar controls display of the Pending Review sub-tabs
 *
 * @keywords profile detail, profile detail tab, profile detail navigation
 */
class PendingReviewNavBar extends ThirdPartyMgtNavBar
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var integer Client ID
     */
    protected $clientID = null;

    /**
     * @var object Instance of the model for this controller
     */
    private $model = null;

    /**
     * @var array Contains the nav (tab) bar configuration (name, parent reference, etc.)
     */
    protected $navBar = null;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/ThirdPartyMgt/PendingReview/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'PendingReview.tpl';

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
     * Check if access is allowed to a navigation element (tab) based upon factors other than user permissions handled
     * by 'Features' as specified in each tabs configuration.
     *
     * @param array $tab Contains the tab configuration
     *
     * @return boolean True/false indicator if access is allowed or not
     */
    private function allowAccess($tab)
    {
        switch ($tab['me']) {
            case 'Gifts':
                $giftFeatures = [
                \Feature::TENANT_TPM_GIFTS,
                \Feature::TENANT_TPM_RELATION
                ];
            
                $allowAccess = $this->app->ftr->hasAllOf($giftFeatures);
                break;
            default:
                $allowAccess = true;
        }
        return $allowAccess;
    }

    /**
     * Create the Pending Review navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        parent::createNavBar();

        $navBarNodeName = 'PendingReview';
        $navBars        = ['parent' => $this->navBar, 'current' => $navBarNodeName];
        $nav            = new Navigation($navBars);
        $this->navBar   = $nav->navBar;

        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL2', json_encode($tabs));
        $this->setViewValue('tabsDataL2Header', 'PendingReview');
    }

    /**
     * Create the navigation (tabs) for the Pending Review nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        foreach (PendingReviewNavBarTabs::$tabs as $key => $tab) {
            if ($this->allowAccess($tab)) {
                $nav->add($nav->getConfig($this->navBar, $tab));
            }
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
    }

    /**
     * Render the Pending Review nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        echo $this->app->view->render($this->getTemplate(), $this->getViewValues());
    }
}
