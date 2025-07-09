<?php
/**
 * Controller: Workflow/WorkflowHome Controller
 */

namespace Controllers\TPM\Workflow;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;

/**
 * Handles requests and responses for Workflow UI elements
 */
class WorkflowHome extends Base
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
    protected $tpl = 'Home.tpl';

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
     * @return void
     */
    public function initialize()
    {
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }
}
