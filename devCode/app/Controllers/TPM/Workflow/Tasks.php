<?php
/**
 * Controller: Workflow/Tasks Controller
 */

namespace Controllers\TPM\Workflow;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\DataIntegration\TokenData;
use Models\Logging\LogRunTimeDetails;

/**
 * Handles requests and responses for Workflow UI elements
 */
class Tasks extends Base
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
     * @var string Base tempate for View
     */
    protected $tpl = 'Tasks.tpl';

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
     * method for initial view load
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
        $logArray = [
            'userid' => $_SESSION['userid'],
            'token' => $_COOKIE['token'],
            'ospreyPath' => $_ENV['ospreyISS'],
            'ospreyTarget' => $_ENV['ospreyISStarget']
        ];
        (new LogRunTimeDetails($_SESSION['clientID'], 'Workflow'))->logDetails(LogRunTimeDetails::LOG_BASIC, $logArray);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }
}
