<?php
/**
 * Osprey Form Builder config controller
 *
 * @keywords osprey form builder
 */

namespace Controllers\TPM\Settings\ContentControl\FormBuilder;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\DataIntegration\TokenData;

/**
 * Class allowing users to manage Osprey Form Builder for client
 *
 */
#[\AllowDynamicProperties]
class FormBuilder
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/FormBuilder/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'FormBuilder.tpl';

    /**
     * @var \Skinny\Skinny Application instance
     */
    protected $app = null;

    /**
     * @var Base Base controller instance
     */
    protected $baseCtrl = null;

    /**
     * @var int Delta tenantID
     */
    protected $tenantID = 0;

    /**
     * @var string The route for ajax calls in the js namespace.
     */
    protected $ajaxRoute = '/tpm/cfg/cntCtrl/frmBldr';

    /**
     * Initialize FormBuilder settings controller
     *
     * @param integer $tenantID   Delta tenantID
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->tenantID        = (int)$tenantID;
        $initValues['objInit'] = true;
        $initValues['vars']    = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $this->baseCtrl  = new Base($this->tenantID, $initValues);
        $this->app       = \Xtra::app();
        $this->fullPerms = ($this->app->ftr->isLegacyFullClientAdmin() || $this->app->ftr->isSuperAdmin());
    }

    /**
     * Sets form builder view and template values
     *
     * @return void
     */
    public function initialize()
    {
        $this->baseCtrl->setViewValue('fullPerms', $this->fullPerms);
        $this->baseCtrl->setViewValue('canAccess', true);
        if (!isset($_COOKIE['token'])) {
            (new TokenData())->setUserJWT();
        }
        $this->baseCtrl->setViewValue('token', $_COOKIE['token']);
        $this->baseCtrl->setViewValue('ospreyPath', $_ENV['ospreyISS']);
        $this->baseCtrl->setViewValue('ospreyTarget', $_ENV['ospreyISStarget']);
        $this->baseCtrl->setViewValue('requestURL', "/formsmanager/index");
        $this->baseCtrl->setViewValue('isEmbedded', "true");
        $this->baseCtrl->setViewValue('target', "osprey-integration-frame");
        $this->app->view->display($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());
    }
}
