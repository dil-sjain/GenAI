<?php
/**
 * Controller: modal for adding/modifying companies associated with the intake form.
 *
 * @keywords intake, form, companies
 */

namespace Controllers\TPM\IntakeForms\Legacy\Companies;

use Controllers\TPM\IntakeForms\Legacy\Layout\Base;
use Lib\SettingACL;
use Lib\Traits\AjaxDispatcher;
use Models\Globals\Geography;
use Models\TPM\IntakeForms\Response\InFormRspnsCompanies;

/**
 * IntakeFormCountries controller
 */
#[\AllowDynamicProperties]
class IntakeFormCompanies extends Base
{
    use AjaxDispatcher;

    /**
     * Base directory for View (see: Base::getTemplate())
     *
     * @var string
     */
    protected $tplRoot = 'TPM/IntakeForms/Legacy/Companies/';

    /**
     * Base template for View (Can also be an array. see: Base::getTemplate())
     *
     * @var string
     */
    protected $tpl = 'IntakeFormCompanies.tpl';

    /**
     * Application instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * InFormRspnsCompanies instance
     *
     * @var object
     */
    protected $m = null;

    /**
     * \Xtra::app()->log instance
     *
     * @var object
     */
    private $logger = null;

    /**
     * Identifies this as intake forms for the Ajax Dispatcher
     *
     * @var boolean
     */
    protected $isIntakeForms = true;

    /**
     * Standard fields shown for all layout configurations
     *
     * @var array
     */
    private $standardFields = ['name', 'relationship', 'address'];

    /**
     * Non-standard fields shown for layout configuration #1
     *
     * @var array
     */
    private $layout1Fields = [
        'regCountry', 'regNum', 'contactName', 'phone', 'percentOwnership', 'additionalInfo'
    ];

    /**
     * Non-standard fields shown for layout configuration #2
     *
     * @var array
     */
    private $layout2Fields = ['regNum', 'percentOwnership', 'stateOwned'];

    /**
     * Non-standard fields shown for layout configuration #3
     *
     * @var array
     */
    private $layout3Fields = ['additionalInfo'];

    /**
     * Non-standard fields shown for layout configuration #4
     *
     * @var array
     */
    private $layout4Fields = [
        'regCountry', 'regNum', 'contactName', 'phone', 'percentOwnership', 'stateOwned', 'additionalInfo'
    ];


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
        $this->logger = $this->app->log;
        $this->m = new InFormRspnsCompanies($tenantID);
    }


    /**
     * Set vars on page load
     *
     * @param integer $inFormRspnsCompanyID inFormRspnsCompanies.id
     *
     * @return void
     */
    public function initialize($inFormRspnsCompanyID = 0)
    {
        $inFormRspnsCompanyID = (int)$inFormRspnsCompanyID;
        $companyData = (!empty($inFormRspnsCompanyID)) ? $this->m->getCompanyDataByID($inFormRspnsCompanyID) : [];
        $this->setViewValue('companyID', $inFormRspnsCompanyID);

        $baseText = $this->app->session->get('baseText');
        $this->setViewValue('callerPath', '/cms/ddq/ddq6.php');
        $this->setViewValue('yes', $baseText['YesTag']);
        $this->setViewValue('no', $baseText['NoTag']);
        $this->setViewValue('modalBtnSaveTxt', $baseText['NavButton']['Save']);
        $this->setViewValue('modalBtnCancelTxt', $baseText['NavButton']['Cancel']);
        if (!empty($companyData)) {
            $this->setViewValue('modalBtnDeleteTxt', $baseText['NavButton']['Delete']);
        }
        $labels = $this->m->getLabels($this->app->session->get('aOnlineQuestions'));

        $questionIDs = [
            'TEXT_ADDR_ADDCOMP' => 'address',
            'TEXT_COMPNAME_ADDCOMP' => 'name',
            'TEXT_CONTACT_ADDCOMP' => 'contact',
            'TEXT_COFREG_ADDCOMP' => 'regCountry',
            'TEXT_NAME_ADDCOMP' => 'name',
            'TEXT_PERCENT_ADDCOMP' => 'percentOwnership',
            'TEXT_REGNUM_ADDCOMP' => 'regNum',
            'TEXT_RELATIONSHIP_ADDCOMP' => 'relationship',
            'TEXT_PHONE_ADDCOMP' => 'phone',
            'TEXT_ADDINFO_ADDCOMP' => 'additionalInfo',
            'TEXT_STATEOWNED_ADDCOMP' => 'stateOwned',
        ];
        foreach ($questionIDs as $questionID => $label) {
            if (!empty($labels[$questionID])) {
                $this->setViewValue($label, $labels[$questionID]);
            }
            if (in_array($label, ['address', 'name', 'relationship', 'percentOwnership'])) {
                $this->session->set("inFormCompaniesValidation.$label", $labels[$questionID]);
            }
        }
        $this->setViewValue('modalHeader', $labels['TEXT_COMPNAME_ADDCOMP']);
        $this->setViewValue('modalNotifs', true);
        $this->setViewValue('errorMsg', $this->app->trans->codeKey('missing_or_invalid_input'));
        $this->setViewValue('legacyModal', true);
        $this->setViewValue('isRTL', \Xtra::isRTL($this->session->get('languageCode')));
        $this->setLayout($companyData);
        $this->renderBaseView();
    }



    /**
     * Get tenant-specific layout of specific intake form fields.
     *
     * @param array $companyData inFormRspnsCompanies row
     *
     * @return void
     */
    private function setLayout($companyData)
    {
        $fields = $codeKeys = [];
        $setting = (new SettingACL($this->tenantID))->get(
            SettingACL::INTKFRM_COMPANY_MODAL_LAYOUT,
            ['lookupOptions' => 'disabled']
        );
        $layout = ($setting) ? $setting['value'] : 0;
        switch ($layout) {
            case 2:
                $fields = array_merge($this->standardFields, $this->layout2Fields);
                $codeKeys = ['percent_requirements'];
                break;
            case 3:
                $fields = array_merge($this->standardFields, $this->layout3Fields);
                break;
            case 4:
                $fields = array_merge($this->standardFields, $this->layout4Fields);
                $codeKeys = ['percent_requirements', 'select_default'];
                break;
            default:
                $fields = array_merge($this->standardFields, $this->layout1Fields);
                $codeKeys = ['percent_requirements', 'select_default'];
                break;
        }
        if (!empty($codeKeys)) {
            $translations = $this->app->trans->codeKeys($codeKeys);
            if (in_array('percent_requirements', $codeKeys)) {
                $percentData = $this->m->getPercentData();
                $this->setViewValue('percentDefault', $percentData['defaultValue']);
                $this->setViewValue('percentSize', $percentData['size']);
                $this->setViewValue('percentTitle', $translations['percent_requirements']);
            }
            if (in_array('select_default', $codeKeys)) {
                $countriesList = (Geography::getVersionInstance(null, $this->tenantID))
                    ->countryList('', $this->session->get('languageCode', 'EN_US'));
                $this->setViewValue('countriesList', $countriesList);
                $this->setViewValue('selectDefault', $translations['select_default']);
            }
        }
        $this->setViewValue('layout', $layout);
        if (!empty($companyData)) {
            foreach ($fields as $field) {
                if (!empty($companyData[$field])) {
                    $this->setViewValue($field . "Val", $companyData[$field]);
                }
            }
        }
    }

    /**
     * AJAX method that deletes the intake form company
     *
     * @return void
     */
    private function ajaxDelete()
    {
        $inFormRspnsCompanyID = (int)\Xtra::arrayGet($this->app->clean_POST, 'companyID', 0);
        if (!empty($inFormRspnsCompanyID) && ($deleted = $this->m->removeCompany($inFormRspnsCompanyID))) {
            $this->jsObj->Result = 1;
        } else {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('error_inFormRspnsCompany_delete');
            $this->jsObj->ErrMsg = $exceptionMsg;
        }
    }

    /**
     * AJAX method that saves the intake form company
     *
     * @return void
     */
    private function ajaxSave()
    {
        $inFormRspnsID = $this->app->session->get('inFormRspnsID'); // A.K.A. ddq.id
        $inFormRspnsCompanyID = (int)\Xtra::arrayGet($this->app->clean_POST, 'companyID', 0);
        $layout = \Xtra::arrayGet($this->app->clean_POST, 'layout', 0);
        $layouts = [1, 2, 3, 4];
        $name = $relationship = $address = '';
        $regCountry = $regNum = $contactName = $phone = $additionalInfo = $stateOwned = '';
        $percentData = $this->m->getPercentData();
        $percentOwnership = $percentData['defaultValue'];
        $flds = $badFlds = [];
        $result = false;

        if (!empty($layout) && in_array($layout, $layouts)) {
            switch ($layout) {
                case 1:
                    $flds = array_merge($this->standardFields, $this->layout1Fields);
                    break;
                case 2:
                    $flds = array_merge($this->standardFields, $this->layout2Fields);
                    break;
                case 3:
                    $flds = array_merge($this->standardFields, $this->layout3Fields);
                    break;
                case 4:
                    $flds = array_merge($this->standardFields, $this->layout4Fields);
                    break;
            }

            // Put posted values in local variables
            foreach ($flds as $fld) {
                $defaultValue = ($fld == 'percentOwnership') ? $percentOwnership : '';
                ${$fld} = \Xtra::arrayGet($this->app->clean_POST, $fld, $defaultValue);
            }

            // Back-up validation in case the front-end validation somehow fails.
            if (empty($name)) {
                $badFlds[] = $this->session->get('inFormCompaniesValidation.name');
            }
            if (empty($relationship)) {
                $badFlds[] = $this->session->get('inFormCompaniesValidation.relationship');
            }
            if (empty($address)) {
                $badFlds[] = $this->session->get('inFormCompaniesValidation.address');
            }
            if (!is_numeric($percentOwnership) || $percentOwnership < 0 || $percentOwnership > 100) {
                $badFlds[] = $this->session->get('inFormCompaniesValidation.percentOwnership');
            }

            if (!empty($badFlds)) {
                // Missing required fields or misformatted percent field.
                $result = true;
            } elseif (!empty($inFormRspnsCompanyID)) {
                // Determine if any of the values changed before attempting an update
                if ($origRec = $this->m->getCompanyDataByID($inFormRspnsCompanyID)) {
                    $changed = 0;
                    foreach ($flds as $fld) {
                        if (${$fld} != $origRec[$fld]) {
                            $changed++;
                        }
                    }
                    if (!empty($changed)) {
                        // Changes were made. Update the company's data.
                        $result = $this->m->updateSingle(
                            $inFormRspnsCompanyID,
                            $name,
                            $relationship,
                            $address,
                            $regCountry,
                            $regNum,
                            $contactName,
                            $phone,
                            $percentOwnership,
                            $additionalInfo,
                            $stateOwned
                        );
                    } else {
                        // No values changed. Mark result as being true to get outa here.
                        $result = true;
                    }
                }
            } else {
                $result = $this->m->createSingle(
                    $inFormRspnsID,
                    $name,
                    $relationship,
                    $address,
                    $regCountry,
                    $regNum,
                    $contactName,
                    $phone,
                    $percentOwnership,
                    $additionalInfo,
                    $stateOwned
                );
            }
        }

        if (!$result) {
            // Something went wrong with the save.
            $errTrans = $this->app->trans->codeKeys(['error_record_invalid_title', 'error_inFormRspnsCompany_save']);
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $errTrans['error_record_invalid_title'];
            $this->jsObj->ErrMsg = $errTrans['error_inFormRspnsCompany_save'];
        } else {
            // Validation errors or a successful save
            if (!empty($badFlds)) {
                $badFlds = implode(', ', $badFlds);
                $this->jsObj->Args = [['badFields' => $badFlds]];
            }
            $this->jsObj->Result = 1;
        }
    }
}
