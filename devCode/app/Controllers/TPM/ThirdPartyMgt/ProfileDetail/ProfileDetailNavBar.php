<?php
/**
 * Construct the "Profile Detail" sub-tabs
 */

namespace Controllers\TPM\ThirdPartyMgt\ProfileDetail;

use Controllers\TPM\ThirdPartyMgt\ThirdPartyMgtNavBar;
use Lib\Navigation\Navigation;
use Models\TPM\ThirdPartyMgt\ProfileDetail\ProfileDetailNavBarData;

/**
 * Class ProfileDetailNavBar controls display of the Profile Detail sub-tabs
 *
 * @keywords profile detail, profile detail tab, profile detail navigation
 */
class ProfileDetailNavBar extends ThirdPartyMgtNavBar
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
    protected $tplRoot = 'TPM/ThirdPartyMgt/ProfileDetail/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'ProfileDetail.tpl';

    /**
     * Init class constructor
     *
     * @param int   $clientID   Current client ID
     * @param array $initValues Any additional parameters that need to be passed in
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app = \Xtra::app();
        $this->processParams($clientID, $initValues);
        parent::__construct($clientID, $initValues);
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
            case 'Training':
                $allowAccess = $this->model->allowTrainingAccess();
                break;
            case 'Compliance':
                $allowAccess = $this->model->allowComplianceAccess($this->tpID);
                break;
            case 'Connections':
                $features = [
                \Feature::TENANT_TPM_RELATION,
                \Feature::TP_RELATE
                ];
                $allowAccess = $this->app->ftr->hasAllOf($features);
                break;
            case 'CustomFields':
                $allowAccess = $this->model->allowCustomFieldsAccess();
                break;
            case 'Gifts':
                $features = [
                \Feature::TENANT_TPM_COMPLIANCE,
                \Feature::TENANT_TPM_RELATION,
                \Feature::TENANT_TPM_GIFTS
                ];
                $allowAccess = $this->app->ftr->hasAllOf($features);
                break;
            default:
                $allowAccess = true;
        }
        return $allowAccess;
    }

    /**
     * Create the Profile Detail navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        parent::createNavBar();

        $navBarNodeName = 'ProfileDetail';
        $navBars        = ['parent' => $this->navBar, 'current' => $navBarNodeName];
        $nav            = new Navigation($navBars);
        $this->navBar   = $nav->navBar;

        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL2', json_encode($tabs));
        $this->setViewValue('tabsDataL2Header', 'Profile Detail');
    }

    /**
     * Create the navigation (tabs) for the Profile Detail nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        $tabLabels = $this->getNavBarLabels();

        foreach (ProfileDetailNavBarTabs::$tabs as $key => $tab) {
            if ($tabLabels && isset($tabLabels[$key]) && $tabLabels[$key] != '') {
                $tab['label'] = $tabLabels[$key];
            }

            if ($this->allowAccess($tab)) {
                $nav->add($nav->getConfig($this->navBar, $tab));
            }
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
    }

    /**
     * Get the label text for the Profile Details sub-tabs
     *
     * @return array $labels Contains an associative array of text labels for the nav bar
     */
    private function getNavBarLabels()
    {
        $labels = [];

        $label = $this->app->session->get('customLabels.tpCompliance');
        $labels['Compliance'] = !empty($label) ? $label : '';

        $label = $this->app->session->get('customLabels.tpCustomFields');
        $labels['CustomFields'] = !empty($label) ? $label : '';

        $label = $this->app->session->get('customLabels.tpNotes');
        $labels['Notes'] = !empty($label) ? $label : '';

        return $labels;
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
            throw new \Exception('Missing Client ID in ProfileDetailNavBar Controller');
        }

        $this->clientID = $clientID;
        $this->model    = new ProfileDetailNavBarData($clientID);

        // For the Profile Details tab make sure we have a valid 3P ID
        if (isset($initValues['params']) && isset($initValues['params']['id'])) {
            $tpID = $initValues['params']['id'];

            if (is_numeric($tpID)) {
                $this->tpID = $tpID;
                $this->app->session->set('currentID.3p', $tpID);
                return;
            }
        } elseif ($this->app->session->has('currentID.3p')) {
            $this->tpID = $this->app->session->get('currentID.3p');
            return;
        }

        throw new \Exception('Missing Third Party Profile ID in ProfileDetails Controller');
    }

    /**
     * Render the Profile Detail nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        echo $this->app->view->render($this->getTemplate(), $this->getViewValues());
    }
}
