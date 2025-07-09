<?php
/**
 * Provide essential processing common to pages in Main mode
 */

namespace Controllers\TPM\IntakeForms\Legacy\Layout;

use Controllers\AppBase;
use Models\TPM\IntakeForms\Legacy\AppLayout;
use Lib\LoginAuth;
use Lib\Navigation\Navigation;

/**
 * Supports the app in Main mode. Other Main pages and components should
 * extend this class. Note that each application will (or should) have its own
 * Base controller (extending AppBase). There may be similar methods between Bases
 * where alternate logic is used to arrive at the result.
 */
class Base extends AppBase
{
    /**
     * Any class vars common between apps should be in AppBase.
     * Set through AppBase:
     * protected $session      = null;
     * protected $initValues   = array();
     * protected $resources    = null;
     * private   $viewValues   = array(); // private is NOT a typo. this is intentional. (SEC-657)
     * private   $baseViewKeys = array(); // view values initially set, but allowed to be overridden w/o force
     * private   $viewValsSet  = false; // have initial view vals been set?
     */
    protected $tenantID = 0;
    protected $canAccess = true;
    protected $authorized = false;
    private $viewValAllow = ['pgTitle']; // initially set view vals allowed to be overridden w/o force;
    protected $navBar = null;
    protected $navLoaded = false;
    protected $loggedIn = false;


    /**
     * Constructor
     *
     * @param integer $tenantID   Delta tenantID
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;

        $this->validateLogin();

        if ($this->loggedIn) {
            /*
             * If we load base as an object vice extention, we MUST pass in all
             * class properties from the calling class which are expected to be
             * available as if it were being extended. (Passed as initValues['vars'])
             * Ex: $tpl, $tplRoot, etc. All vars to make base/appBase work.
             *
             * Note: All vars end up public since they are being set on the fly.
             */
            if (isset($initValues['objInit']) && $initValues['objInit'] == true) {
                foreach ($initValues['vars'] as $k => $v) {
                    $this->$k = $v;
                }
            }
            parent::__construct($tenantID, $initValues); // will verify a positive tenantID integer;
            $this->viewValuesInit();
        }
    }




    /**
     * Renders the view
     *
     * @return void
     */
    protected function renderBaseView()
    {
        $this->weedForBareSkel();
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }



    /**
     * Validates that a proper login has occured
     *
     * @todo Eventually stop bypassing this for PHPUNIT, and establish proper session data in WebTestClient.
     *
     * @return void
     */
    private function validateLogin()
    {
        $this->loggedIn = (!empty($GLOBALS['PHPUNIT'])) ? true : (new LoginAuth())->loggedInToIntakeForm();
        if (!\Xtra::app()->request->isAjax() && !$this->loggedIn) {
            \Xtra::app()->redirect('/intake/legacy/');
        }
    }


    /**
     * Set common view values for display the full Main layout.
     * Values are provided for app header, sidebar and footer
     *
     * @return void
     */
    private function viewValuesInit()
    {
        $this->setViewValueAllowedOverwrites($this->viewValAllow);
        $app = \Xtra::app();
        $trText = $app->trans->group('app_layout');

        $config = ['appPrefix' => 'inForm', 'legacySitePath' => \Xtra::conf('cms.legacySitePath'), 'sitePath' => $app->sitePath, 'rxCacheKey' => $app->rxCacheKey, 'clean_GET' => $app->clean_GET, 'clean_POST' => $app->clean_POST, 'pgAuthToken' => $this->session->getToken(), 'isSuperAdmin' => $app->auth->isSuperAdmin, 'isSP' => $app->auth->isSP, 'colorScheme' => $this->session->get('siteColorScheme', 0), 'isQcEnv' => false, 'featurePeek' => $app->ftr->has(\Feature::FEATURE_PEEK), 'trText' => $trText];

        $dataStore = new AppLayout(
            $this->tenantID,
            $this->isAjax
        );
        $skelInfo = $dataStore->getSubscriberSkelInfo();

        $tabs = [];
        $viewValues = array_merge($config, $tabs, $skelInfo);

        $viewValues['antiClickjack'] = $this->generateAntiClickjack();
        $viewValues['viewDependencyFiles'] = [];
        $viewValues['viewResources'] = $this->resources;

        // setup initial view values
        $this->createViewValues($viewValues);
    }




    /**
     * Configure top level tabs for main app
     *
     * @return array Tab info
     */
    private function getMainTabs()
    {
    }
}
