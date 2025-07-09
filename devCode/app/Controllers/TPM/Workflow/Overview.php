<?php
/**
 * Controller: Workflow/Overview Controller
 */

namespace Controllers\TPM\Workflow;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;

/**
 * Handles requests and responses for Workflow UI elements
 */
class Overview extends Base
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
    protected $tpl = 'Overview.tpl';

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
        $this->setViewValue('token', $_COOKIE['token']);
        $this->setViewValue('ospreyPath', $_ENV['ospreyISS']);
        $this->setViewValue('ospreyTarget', $_ENV['ospreyISStarget']);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }
}
