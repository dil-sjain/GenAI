<?php
/**
 * Add Case Dialog Entry
 *
 * @category  AddCase_Dialog
 * @package   Controllers\TPM\AddCase
 * @@keywords SEC-873 & SEC-2844 & SEC-2790
 */

namespace Controllers\TPM\AddCase\Subs;

use Models\ThirdPartyManagement\Cases;
use Lib\Legacy\IntakeFormTypes as IFT;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;
use Models\ThirdPartyManagement\RiskModel;
use Models\ThirdPartyManagement\ThirdParty;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Subscriber;
use Models\Ddq;

/**
 * Class Entry
 */
#[\AllowDynamicProperties]
class Entry extends AddCaseBase
{
    /**
     * @var int thirdPartyProfile.ID
     */
    protected $tppID;

    /**
     * Entry constructor.
     *
     * @param int $tenantID tenant ID
     * @param int $caseID   cases.id
     * @param int $tppID    associated thirdPartyProfile.id
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $caseID, protected $tppID = null)
    {
        $this->callback = 'appNS.addCase.addCaseOpen';
        $this->template = 'Entry.tpl';

        parent::__construct($tenantID, $caseID);
    }


    /**
     * Returns the translated dialog title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->app->trans->codeKey('create_new_case_folder');
    }


    /**
     * Returns an initial array of data for the dialog, including code keys for buttons.
     *
     * @return array
     */
    #[\Override]
    public function returnInitialData()
    {
        return
            [
                'caseTypeData'  =>
                [
                    ($this->app->trans->codeKey('scope_online_research')) =>
                        array_flip(
                            [
                            IFT::DUE_DILIGENCE_HCPDI,
                            IFT::DUE_DILIGENCE_IBI,
                            IFT::DUE_DILIGENCE_OSISIP,
                            IFT::DUE_DILIGENCE_OSIAIC,
                            IFT::DUE_DILIGENCE_OSISV,
                            IFT::DUE_DILIGENCE_OSIRC,
                            IFT::DUE_DILIGENCE_HCPVR,
                            IFT::DUE_DILIGENCE_SDD,
                            IFT::DUE_DILIGENCE_SDD_CA,
                            ]
                        ),
                    ($this->app->trans->codeKey('scope_field_investigation')) =>
                        array_flip(
                            [
                            IFT::DUE_DILIGENCE_HCPEI,
                            IFT::DUE_DILIGENCE_SBI,
                            IFT::DUE_DILIGENCE_ESDD,
                            IFT::DUE_DILIGENCE_ESDD_CA,
                            ]
                        ),
                    ($this->app->trans->codeKey('define_your_scope')) =>
                        array_flip(
                            [
                            IFT::DUE_DILIGENCE_HCPAI,
                            IFT::DUE_DILIGENCE_ABI,
                            IFT::SPECIAL_PROJECT,
                            ]
                        ),
                ],
                'trText' => $this->app->trans->group('add_case_dialog')
            ];
    }


    /**
     * Returns view values for Entry.tpl
     *
     * @return array
     */
    public function getViewValues()
    {
        $regionData = (new Subscriber($this->tenantID))->getRegionAndDepartment();
        $trText    = $this->app->trans->group('add_case_dialog');
        $caseTypes = $this->Cases->getScopes();
        $caseTypes = (count($caseTypes) > 1)
            ?   [0 => $trText['select_default']] + $caseTypes
            :   $caseTypes;

        $region = $regionData['regionTitle'] ?? $trText['region'];
        $department = $regionData['departmentTitle'] ?? $trText['region'];

        return array_merge(
            $trText,
            $this->getBillingUnitViewValues(),
            [
                'pageName'       => 'Entry',
                'sitePath'       => $this->app->sitePath,
                'hasTpm'         => $this->hasTPM,
                'stateList'      => [],
                'caseTypeList'   => $caseTypes,
                'countryList'    => $this->Model->returnCountryList(),
                'regionList'     => $this->Model->returnRegionList(),
                'departmentList' => $this->Model->returnDepartmentList(),
                'region'         => $region,
                'department'     => $department,
            ]
        );
    }


    /**
     * Given a billingUnit.id, returns an array of related billing unit purchase orders alphabetized by value.
     *
     * @param integer $billingUnit billingUnit.id
     *
     * @return array of billing unit purchase orders possibly with a trText default.
     */
    public function getBillingUnitPOs($billingUnit)
    {
        $poList = [];

        if ($billingUnit && is_numeric($billingUnit)) {
            foreach ((new BillingUnitPO($this->tenantID))->getPOsByBillingUnit($billingUnit) as $key => $po) {
                $poList[$po['id']] = $po['name'];
            }
            asort($poList);
        }

        return (count($poList) > 1)
            ?   [0 => $this->app->trans->codeKey('select_default')] + $poList
            :   $poList;
    }


    /**
     * Returns an array of billing units alphabetized by value.
     *
     * @return array of billing unit purchase orders possibly with a trText default.
     */
    public function getBillingUnits()
    {
        $billingUnitList = [];
        foreach ((new BillingUnit($this->tenantID))->getActiveBillingUnits() as $key => $bu) {
            $billingUnitList[$bu['id']] = $bu['name'];
        }
        asort($billingUnitList);

        return $billingUnitList;
    }


    /**
     * Returns view values related to billing unit and billing unit po (purchase order).
     *
     * @return array
     */
    private function getBillingUnitViewValues()
    {
        $billingUnitList     = $this->getBillingUnits();
        $billingUnitPOList   = [];
        $activeBillingUnit   = null;
        $activeBillingUnitPO = null;

        if (count($billingUnitList) == 1) {
            $activeBillingUnit = key($billingUnitList);
            $billingUnitPOList = (new BillingUnitPO($this->tenantID))->getPOsByBillingUnit($activeBillingUnit);

            if (count($billingUnitPOList) == 1) {
                $activeBillingUnitPO = $billingUnitPOList[0]['id'];
            }
        }

        return [
            'billingUnitList'     => $billingUnitList,
            'billingUnitPOList'   => $billingUnitPOList,
            'activeBillingUnit'   => $activeBillingUnit,
            'activeBillingUnitPO' => $activeBillingUnitPO,
            'textFieldPORequired' => (new BillingUnit($this->tenantID))->displayPOTextField($activeBillingUnit),
        ];
    }


    /**
     * Returns an array of user input fields
     *
     * @return array of user input fields
     */
    public function getUserInput()
    {
        return [
            'caseName',
            'caseType',
            'caseDescription',
            'region',
            'caseCountry',
            'caseState',
            'dept',
            'billingUnit',
            'billingUnitPO',
            'billingUnitPOTF',
            'tpID',
        ];
    }


    /**
     * Validates the Entry form.
     *
     * @return array of errors, empty on successful validation
     *
     * @throws Exception if no Service Provider is found
     */
    #[\Override]
    public function validate()
    {
        $this->setUserInput($this->getUserInput());

        $trText = $this->app->trans->group('add_case_dialog');
        $caseTypes = $this->Cases->getScopes();
        $regions   = $this->Model->returnRegionList();
        $countries = $this->Model->returnCountryList();
        $billingUnitList = (new BillingUnit($this->tenantID))->getActiveBillingUnits();
        $error = [];

        if (empty($this->inputs['caseName'])) {
            $error[] = $trText['invalid_case_name'];
        }
        if (empty($this->inputs['caseType']) || !isset($caseTypes[$this->inputs['caseType']])) {
            $error[] = $trText['invalid_case_type'];
        }
        if (!empty($this->inputs['tpID']) && !empty($this->inputs['caseType'])
            && empty($this->inputs['caseDescription'])
        ) {
            $Args = $this->associateProfile($this->inputs['tpID']);
            if (!empty($Args['scopeID']) && $Args['scopeID'] != $this->inputs['caseType']) {
                $error[] = $trText['invalid_case_description'];
            }
        }
        if (empty($this->inputs['region']) || !isset($regions[$this->inputs['region']])) {
            $error[] = $trText['invalid_region'];
        }
        if (empty($this->inputs['caseCountry']) || !isset($countries[$this->inputs['caseCountry']])) {
            $error[] = $trText['invalid_country'];
        }
        if (empty($this->inputs['tpID']) && $this->hasTPM) {
            $error[] = $trText['invalid_tpp_link'];
        }
        if ($billingUnitList && empty($this->inputs['billingUnit'])) {
            $error[] = $trText['error_invalid_billingunit'];
        }

        $needsTextField = (new BillingUnit($this->tenantID))->displayPOTextField($this->inputs['billingUnit']);
        $unit = ($needsTextField)
            ?   $this->inputs['billingUnitPOTF']
            :   $this->inputs['billingUnitPO'];

        if (empty($unit)) {
            if ($needsTextField || !empty($this->getBillingUnitPOs($this->inputs['billingUnit']))) {
                $error[] = $trText['error_invalid_billingunitPO'];
            }
        }

        if (empty($this->inputs['caseState'])) {
            $this->inputs['caseState'] = '';    // Over-written as empty string for database.
        }

        // Throws an Exception `No preferred service provider configured for scope.
        if (empty($error) && (new Ddq($this->tenantID))->findByAttributes(['caseID' => $this->caseID])) {
            // the cost time country value = cases country
            (new ServiceProvider())->getServiceProviderPreference(
                $this->tenantID,
                $this->inputs['caseType'],
                $this->inputs['caseCountry']
            );
        }

        return $error;
    }


    /**
     * If CASE_BU_REQUIRE_TEXT_PO text input field was used then verify the billingUnitPO entered is an existing
     * record in our database. If there is not existing purchase order of that kind for the billing unit, then
     * create it. This is the value stored in cases.billingUnitPO.
     *
     * @see sec-2790
     * @see sec-873
     *
     * @return void
     */
    private function verifyPO()
    {
        if ((new BillingUnit($this->tenantID))->displayPOTextField($this->inputs['billingUnit'])) {
            $billingUnitPO = (new BillingUnitPO($this->tenantID))->getBillingUnitPOID(
                $this->inputs['billingUnit'],
                $this->inputs['billingUnitPOTF']
            );

            if (!$billingUnitPO) {
                (new BillingUnitPO($this->tenantID))->insertBillingUnitPOTF(
                    $this->inputs['billingUnit'],
                    $this->inputs['billingUnitPOTF']
                );

                $billingUnitPO = (new BillingUnitPO($this->tenantID))->getBillingUnitPOID(
                    $this->inputs['billingUnit'],
                    $this->inputs['billingUnitPOTF']
                );
            }

            $this->inputs['billingUnitPO'] = $billingUnitPO;
        }
    }


    /**
     * Verifies PO & insert/updates cases record.
     *
     * @return integer cases.ID
     *
     * @throws \Exception is caught in AddCase->ajaxTemplate()
     */
    public function store()
    {
            $this->verifyPO();

            empty($this->caseID) ? $this->insert() : $this->update();

            return $this->caseID;
    }


    /**
     * Called as a result of a thirdPartyProfile.id association,
     * this is given a thirdPartyProfile.id and returns data
     * for presentation in the Add Case `Entry` template.
     *
     * @param int $tppID associated thirdPartyProfile.id
     *
     * @return array
     */
    public function associateProfile($tppID)
    {
        $TPP        = (new ThirdParty($this->tenantID))->findById($tppID);
        $RiskModel  = new RiskModel($TPP->get('riskModel'), $this->tenantID);
        $Assessment = $RiskModel->getCurrentAssessment($tppID);
        $riskTierID = $Assessment->tier;
        $modelID    = $Assessment->model;
        $RiskModel  = new RiskModel($modelID);
        $tierName   = $RiskModel->selectTierName($riskTierID);
        $scope      = $RiskModel->selectScope($riskTierID);
        $scopeID    = (is_array($scope) && isset($scope['id'])) ? $scope['id'] : null;
        $scopeName  = (is_array($scope) && isset($scope['name'])) ? $scope['name'] : null;

        return [
            'tppID'     => $tppID,
            'tppName'   => html_entity_decode((string) $TPP->get('legalName')),
            'scopeID'   => $scopeID,
            'scopeName' => $scopeName,
            'tierName'  => $tierName,
            'countryID' => $TPP->get('country'),
            'dept'      => $TPP->get('department'),
            'regionID'  => $TPP->get('region'),
            'stateID'   => $TPP->get('state'),
        ];
    }


    /**
     * Updates the case. Performed when user goes back to Entry template and resubmits.
     *
     * @return void
     *
     * @throws \Exception
     */
    private function update()
    {
        $attributes = [
            'caseName'        => $this->inputs['caseName'],
            'region'          => $this->inputs['region'],
            'caseCountry'     => $this->inputs['caseCountry'],
            'caseDescription' => $this->inputs['caseDescription'],
            'caseType'        => $this->inputs['caseType'],
            'dept'            => $this->inputs['dept'],
            'caseState'       => $this->inputs['caseState'],
            'billingUnit'     => $this->inputs['billingUnit'],
            'billingUnitPO'   => $this->inputs['billingUnitPO'],
            'requestor'       => $this->app->session->get('authUserLoginID'),
        ];

        if (!$this->CasesObject->setAttributes($attributes)) {
            throw new \Exception('ERROR: ' . $this->CasesObject->getErrors());
        }
        if (!$this->CasesObject->save()) {
            throw new \Exception('ERROR: ' . $this->CasesObject->getErrors());
        }

        // thirdPartyProfile.id association is made here
        if ($this->hasTPM
            &&  !empty($this->CasesRecord['tpID'])
            &&  $this->CasesRecord['tpID'] != $this->inputs['tpID']
        ) {
            $this->Cases->change3PLink($this->inputs['tpID'], $this->caseID);
        }
    }


    /**
     * Inserts the new case record. Performed when user first continues to next template.
     *
     * @dev Remove updateCaseCreatedToNow() when CRUD uses MySQL NOW().
     *
     * @return void
     */
    private function insert()
    {
        $values = [
            'cases' =>
                [
                    'clientID'          => $this->tenantID,
                    'caseName'          => $this->inputs['caseName'],
                    'region'            => $this->inputs['region'],
                    'caseCountry'       => $this->inputs['caseCountry'],
                    'caseStage'         => Cases::REQUESTED_DRAFT,
                    'creatorUID'        => $this->app->session->get('authUserLoginID'),
                    'requestor'         => $this->app->session->get('authUserLoginID'),
                    'caseDescription'   => $this->inputs['caseDescription'],
                    'caseType'          => $this->inputs['caseType'],
                    'dept'              => $this->inputs['dept'],
                    'caseState'         => $this->inputs['caseState'],
                    'caseCreated'       => null, // Over-written with PHP date() out of sync w/MySQL NOW().
                    'userCaseNum'       => 0,
                    'billingUnit'       => $this->inputs['billingUnit'],
                    'billingUnitPO'     => $this->inputs['billingUnitPO'],
                ]
        ];

        if ($this->hasTPM) {
            $this->tppID = $values['cases']['tpID'] = $this->inputs['tpID'];
        }

        $this->caseID = $this->Cases->createNewCase($values);
        $this->Cases->updateCaseCreatedToNow($this->caseID);

        if ($this->hasTPM && $this->caseID) {
            $this->updateCasesRiskModel();
        }
    }


    /**
     * Updates the Cases Risk Model.
     *
     * Refactor of Legacy public_html/cms/case/addcase.php 293:309
     *
     * Attempts to be consistent with Legacy 'NULL' column value. Passing string 'NULL' defaults to 0000 timestamp.
     *
     * @return void
     */
    private function updateCasesRiskModel()
    {
        $TPP        = (new ThirdParty($this->tenantID))->findById($this->tppID);
        $RiskModel  = new RiskModel($TPP->get('riskModel'), $this->tenantID);
        $Assessment = $RiskModel->getCurrentAssessment($this->tppID);
        $riskTierID = $Assessment->tier;
        $scope      = $RiskModel->selectScope($riskTierID);

        if (!$scope) {
            $this->Cases->updateCasesWithNoScope($this->caseID);
        } else {
            $rmID = $TPP->get('riskModel');
            $this->Cases->updateCasesWithScope(
                $this->caseID,
                $rmID,
                $RiskModel->selectRiskTime($rmID, $this->tppID)
            );
        }
    }
}
