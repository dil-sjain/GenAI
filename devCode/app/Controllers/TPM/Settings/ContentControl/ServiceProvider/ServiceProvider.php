<?php
/**
 * Site Service Provider controller
 */

namespace Controllers\TPM\Settings\ContentControl\ServiceProvider;

use Models\TPM\Settings\ContentControl\ServiceProvider\ServiceProviderData as ServiceProviderModel;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;

/**
 * Service Provider controller
 *
 * @keywords site, Service, Provider, settings
 */
#[\AllowDynamicProperties]
class ServiceProvider extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/ServiceProvider/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'ServiceProvider.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var boolean Environment variable for AWS
     */
    protected $awsEnabled = false;

    /**
     * Sets ServiceProvider template to view
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        $clientID = (int)$clientID;
        if ($clientID <= 0) {
            throw new \Exception('Invalid clientID');
        }
        parent::__construct($clientID, $initValues);
        $this->app  = \Xtra::app();
        $this->logger = $this->app->log;
        $this->spData = new ServiceProviderModel($clientID);
        $this->awsEnabled = filter_var(getenv('AWS_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    }


    /**
     * initialize the page
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('trTxt', $this->app->trans->group('contentCtrlSP'));
        $this->setViewValue('awsEnabled', $this->awsEnabled, true);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }



    /**
     * Return regions with assigned countries data for service provider-scope-product combo
     *
     * @return void
     */
    private function ajaxGetAssignedCountries()
    {
        $scopeID = (int)\Xtra::arrayGet($this->app->clean_POST, 'scopeID', 0);
        $spID = (int)\Xtra::arrayGet($this->app->clean_POST, 'spID', 0);
        $productID = (int)\Xtra::arrayGet($this->app->clean_POST, 'productID', 0);
        $this->jsObj->Result = 0;
        $assignedCountries = [];
        $exceptionMsg = null;
        try {
            $assignedCountries = $this->spData->getAssignedCountries($scopeID, $spID, $productID);
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        if ($exceptionMsg !== null) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } elseif (empty($assignedCountries)) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $this->app->trans->codeKey('settingsSp_spConfigErrorMsg');
        } else {
            $this->jsObj->Args = [$assignedCountries];
            $this->jsObj->Result = 1;
        }
    }



    /**
     * Return regions with unassigned countries data for a given scope
     *
     * @return void
     */
    private function ajaxGetUnassignedCountries()
    {
        $scopeID = (int)\Xtra::arrayGet($this->app->clean_POST, 'scopeID', 0);
        $spProductArr = \Xtra::arrayGet($this->app->clean_POST, 'spProductArr', []);
        // If it's a JSON string, decode it
        if (is_string($spProductArr)) {
            $spProductArr = json_decode($spProductArr, true);
        }   
        $this->jsObj->Result = 0;
        $unassignedCountries = [];
        $exceptionMsg = null;
        try {
            $unassignedCountries = $this->spData->getUnassignedCountries($scopeID, $spProductArr);
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        if ($exceptionMsg !== null) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } elseif (empty($unassignedCountries)) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $this->app->trans->codeKey('settingsSp_spConfigErrorMsg');
        } else {
            $this->jsObj->Args = [$unassignedCountries];
            $this->jsObj->Result = 1;
        }
    }



    /**
     * Get data mapping service provider scopes to countries
     *
     * @return void
     */
    private function ajaxMapScopesToCountries()
    {
        $this->jsObj->Result = 0;
        $map = [];
        $exceptionMsg = null;
        try {
            $map = $this->spData->mapScopesToCountries();
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        if ($exceptionMsg !== null) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } elseif (empty($map)) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $this->app->trans->codeKey('settingsSp_spConfigErrorMsg');
        } else {
            $this->jsObj->FuncName = 'appNS.contentCtrlSP.mapScopesToCountriesHndl';
            $this->jsObj->Args = [$map];
            $this->jsObj->Result = 1;
        }
    }



    /**
     * Get data mapping service provider scopes to products
     *
     * @return void
     */
    private function ajaxMapScopesToProducts()
    {
        $this->jsObj->Result = 0;
        $exceptionMsg = null;
        $map = [];
        try {
            $map = $this->spData->mapScopesToProducts();
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        if ($exceptionMsg !== null) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } elseif (empty($map)) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('scope_err');
            $this->jsObj->ErrMsg = $this->app->trans->codeKey('settingsSp_spConfigErrorMsg');
        } else {
            $this->jsObj->Args = [$map];
            $this->jsObj->Result = 1;
        }
    }





    /**
     * Save assigned countries for service provider/scope/product combo
     *
     * @return void
     */
    private function ajaxSaveAssignedCountries()
    {
        $scopeID = (int)\Xtra::arrayGet($this->app->clean_POST, 'scopeID', 0);
        $spID = (int)\Xtra::arrayGet($this->app->clean_POST, 'spID', 0);
        $productID = (int)\Xtra::arrayGet($this->app->clean_POST, 'productID', 0);
        $countryMap = json_decode(base64_decode((string) \Xtra::arrayGet($this->app->clean_POST, 'countryMap', '')), true);
        $countryMap = (empty($countryMap)) ? [] : $countryMap;
        $this->jsObj->Result = 0;
        $exceptionMsg = null;
        try {
            $this->spData->saveAssignedCountries(
                $scopeID,
                $spID,
                $productID,
                $countryMap
            );
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        if ($exceptionMsg !== null) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('upd_failed');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } else {
            $this->jsObj->Result = 1;
        }
    }


    /**
     * Save scope/product mapping for service providers
     *
     * @return void
     */
    private function ajaxSaveMapScopeProducts()
    {
        $newMapping = \Xtra::arrayGet($this->app->clean_POST, 'newMapping', []);
        $this->jsObj->Result = 0;
        $exceptionMsg = null;
        try {
            $this->spData->saveMapScopeProducts($newMapping);
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        if ($exceptionMsg !== null) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('upd_failed');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } else {
            $this->jsObj->Result = 1;
        }
    }
}
