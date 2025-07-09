<?php
/**
 * Controller: modal housing CountriesByRegion widget for selecting countries associated with the intake form.
 *
 * @keywords intake, form, countries,
 */

namespace Controllers\TPM\IntakeForms\Legacy\Countries;

use Controllers\TPM\IntakeForms\Legacy\Layout\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\IntakeForms\Response\InFormRspnsCountries;

/**
 * IntakeFormCountries controller
 */
class IntakeFormCountries extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View (see: Base::getTemplate())
     */
    protected $tplRoot = 'TPM/IntakeForms/Legacy/Countries/';

    /**
     * @var string Base template for View (Can also be an array. see: Base::getTemplate())
     */
    protected $tpl = 'IntakeFormCountries.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object InFormRspnsCountries instance
     */
    protected $m = null;

    /**
     * @var object \Xtra::app()->log instance
     */
    private $logger = null;

    /**
     * @var boolean Identifies this as intake forms for the Ajax Dispatcher
     */
    protected $isIntakeForms = true;

    /**
     * Constructor - requires same signature as Base
     *
     * @param integer $tenantID Tenant ID
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $this->app = \Xtra::app();
        $this->app->trans->tenantID = $tenantID;
        $this->logger = $this->app->log;
        $this->m = new InFormRspnsCountries($tenantID);
    }


    /**
     * Set vars on page load
     *
     * @param array $defaultCountries any countries that should be selected by default.
     *
     * @return void
     */
    public function initialize($defaultCountries = [])
    {
        $baseText = $this->app->session->get('baseText');
        $this->setViewValue('callerPath', '/cms/ddq/ddq4.php');
        $this->setViewValue('modalBtnSaveTxt', $baseText['NavButton']['Save']);
        $this->setViewValue('modalBtnCancelTxt', $baseText['NavButton']['Cancel']);
        $this->setViewValue('legacyModal', true);
        $this->setViewValue('modalHeader', $this->app->trans->codeKey('head_select_country'));
        $this->setViewValue('defaultCountries', $defaultCountries);
        $this->renderBaseView();
    }


    /**
     * AJAX method that saves the intake form countries
     *
     * @return void
     */
    private function ajaxSave()
    {
        if (!$this->loggedIn) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [['notLoggedIn' => true]];
        } else {
            $orig = \Xtra::arrayGet($this->app->clean_POST, 'origCountries', []);
            $new = \Xtra::arrayGet($this->app->clean_POST, 'newCountries', []);
            $unchanged = (($orig === array_intersect($orig, $new)) && ($new === array_intersect($new, $orig)));
            $inFormRspnsID = $this->app->session->get('inFormRspnsID'); // A.K.A. ddq.id
            $exceptionMsg = null;
            if ($unchanged) {
                $this->jsObj->Result = 1;
            } else {
                try {
                    $this->m->updateCountries($inFormRspnsID, $new);
                } catch (\Exception $e) {
                    $exceptionMsg = $e->getMessage();
                }
                if ($exceptionMsg !== null) {
                    $this->jsObj->Result = 0;
                    $this->jsObj->ErrTitle = $this->app->trans->codeKey('error_inFormRspnsCountries_save');
                    $this->jsObj->ErrMsg = $exceptionMsg;
                } else {
                    $this->jsObj->Result = 1;
                }
            }
        }
    }
}
