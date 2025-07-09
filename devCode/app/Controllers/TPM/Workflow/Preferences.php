<?php
/**
 * Controller: Workflow/Preferences Controller
 */

namespace Controllers\TPM\Workflow;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\DataIntegration\TokenData;

/**
 * Handles requests and responses for Workflow UI elements
 */
class Preferences extends Base
{
    use AjaxDispatcher;

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var string Root for tpls
     */
    protected $tplRoot = 'TPM/Workflow/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Preferences.tpl';

    /**
     * Constructor gets model instance and initializes other properties
     *
     * @param integer $tenantID   clientProfile.id
     * @param array   $initValues flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
    }

    /**
     * Method for initial view load
     *
     * @throws \Exception
     *
     * @return void
     */
    public function initialize()
    {
        if (!isset($_COOKIE['token'])) {
            (new TokenData())->setUserJWT();
        }
        $this->setViewValue('token', $_COOKIE['token']);
        $this->setViewValue('ospreyPath', $_ENV['ospreyISS']);
        $this->setViewValue('ospreyTarget', $_ENV['ospreyISStarget']);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }
}
