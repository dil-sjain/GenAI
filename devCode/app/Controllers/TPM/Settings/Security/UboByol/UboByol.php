<?php
/**
 * UboByol controller
 *
 * @keywords UBO, UboByol, security, access, user settings
 */

namespace Controllers\TPM\Settings\Security\UboByol;

use Models\TPM\Settings\Security\UboByol\UboByolData;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Lib\Support\Xtra;
use Lib\SettingACL;
use Skinny\Skinny;

/**
 * Class enabling certain users to view their Ubo Bring your own license access credentials
 *
 */
#[\AllowDynamicProperties]
class UboByol extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/Security/UboByol/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'UboByol.tpl';

    /**
     * @var Skinny Application instance
     */
    private Skinny $app;

    /**
     * @var UboByolData Class instance for data access
     */
    protected UboByolData $model;

    /**
     * Sets up UboByol Access control
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = array())
    {
        parent::__construct($clientID, $initValues);
        $this->app  =  Xtra::app();
        $this->model = new UboByolData($clientID);
    }

    /**
     * ajaxInitialize - ajax set initial values for the view
     *
     * @return void
     */
    private function ajaxInitialize()
    {
        $accessDenied = $this->checkAccessDenied();
        if ($accessDenied) {
            $this->jsObj->ErrTitle = 'Invalid Access';
            $this->jsObj->ErrMsg = 'Your role does not have access to this feature.';
        } else {
            $uboByloCredentials = $this->model->getCredentials();
            if ($uboByloCredentials) {
                $uboByloCredentials['apiUserName'] = str_repeat('*', strlen($uboByloCredentials['apiUserName']));
                $uboByloCredentials['apiPassword'] = str_repeat('*', strlen($uboByloCredentials['apiPassword']));
            }
            $this->setViewValue('uboByloCredentials', $uboByloCredentials);
            $html = $this->app->view->fetch(
                $this->getTemplate(),
                $this->getViewValues()
            );
            $this->jsObj->Args   = ['html' => $html];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * ajaxInitUboByol - ajax validate the user is allowed to save/update the credentials.
     *
     * @return void
     */
    private function ajaxSaveUboByol()
    {
        $accessDenied = $this->checkAccessDenied();
        if ($accessDenied) {
            $this->jsObj->ErrTitle = 'Invalid Access';
            $this->jsObj->ErrMsg = 'Your role does not have access to this feature.';
        } else {
            $key = $this->app->clean_POST['key'];
            $secret = $this->app->clean_POST['secret'];
            if (!empty($key) && !empty($secret)) {
                $uboByol = $this->model->upsertCredentials($key, $secret);
                if ($uboByol > 0) {
                    $this->jsObj->Result = 1;
                } else {
                    $this->jsObj->ErrTitle = 'Error';
                    $this->jsObj->ErrMsg = 'Error while saving data.';
                }
            } else {
                $this->jsObj->ErrTitle = 'Error';
                $this->jsObj->ErrMsg = "`key` and `secret` fields are required.";
            }
        }
    }

    /**
     * Check if the user have access to UboByol and userType is Admin OR Client Admin
     *
     * @return boolean
     */
    private function checkAccessDenied()
    {
        $status = false;
        $byolValue = (new SettingACL($this->app->session->get('clientID')))->get(
            SettingACL::UBO_BRING_YOUR_OWN_LICENSE
        );
        $has3pUboByol = isset($byolValue['value']) && $byolValue['value'] == 1;
        if (!$has3pUboByol || !$this->app->ftr->isLegacyFullClientAdmin()) {
            $status = true;
        }
        return $status;
    }
}
