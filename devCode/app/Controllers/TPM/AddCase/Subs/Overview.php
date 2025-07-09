<?php
/**
 * Add Case Dialog Overview
 *
 * @category AddCase_Dialog
 * @package  Controllers\TPM\AddCase
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddCase\Subs;

use Models\SP\ServiceProvider;
use Lib\CaseCostTimeCalc;
use Lib\Legacy\CaseStage;
use Models\ThirdPartyManagement\RelationshipType;
use Models\Globals\Geography;
use Lib\Legacy\SysEmail;
use Lib\GlobalCaseIndex;
use Models\LogData;
use Models\ThirdPartyManagement\GdcMonitor;
use Lib\Services\AppMailer;
use Controllers\SP\Email\BudgetApproved;
use Controllers\SP\Email\CaseRequest;
use Controllers\SP\Email\AutoAssignedNotice;
use Controllers\SP\Email\AssignedNotice;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;
use Models\ThirdPartyManagement\Subscriber;
use Models\ThirdPartyManagement\PurchaseOrder;
use Models\SP\InvestigatorProfile;
use Models\TPM\TpProfile\TpPerson;
use Models\TPM\TpProfile\TpPersonMap;
use Models\TPM\SpLite\SpLite;
use Models\User;
use Models\Ddq;

/**
 * Class Overview
 */
#[\AllowDynamicProperties]
class Overview extends AddCaseBase
{
    /**
     * @var string Error message
     */
    public $error;

    /**
     * @var array User input
     */
    public $input;

    /**
     * @var object result of CaseCostTimeCalculation->getCostTime()
     */
    private $CostTime;

    /**
     * @var object CaseCostTimeCalculation class instance
     */
    private $CostTimeCalculation;

    /**
     * @var int Type of system email to deliver
     */
    private $sysEmail;

    /**
     * Overview constructor.
     *
     * @param int $tenantID logged in tenant ID
     * @param int $caseID   case ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $caseID)
    {
        $this->callback = 'appNS.addCase.handler';
        $this->template = 'Overview.tpl';

        parent::__construct($tenantID, $caseID);
    }

    /**
     * Returns the translated dialog title
     *
     * @return string AppDialog Title for template
     */
    public function getTitle()
    {
        return $this->app->trans->codeKey('investigation_order');
    }

    /**
     * Returns an array of user input fields
     *
     * @return array of user input fields
     */
    public function getUserInput()
    {
        return [
            'cb_acceptTerms',
            'serviceProvider',
        ];
    }

    /**
     * Returns an array of view values to smarty
     *
     * @return array of view values
     */
    public function getViewValues()
    {
        $langCode    = $this->app->session->languageCode ?? 'EN_US';
        $countries   = (Geography::getVersionInstance(null, $this->tenantID))->countryList('', $langCode);
        $country     = $this->returnCostTimeCountryValue();
        $country     = $countries[$country] ?? $country;
        $trText      = $this->app->trans->group('add_case_dialog');
        $preferredSP = $this->getServiceProviderPreference();
        $SPs         = $this->getServiceProvidersForScope($preferredSP);
        $preferredSP = (isset($SPs[$preferredSP])) ? $preferredSP : key($SPs);
        $subType     = !empty($this->SubInfoRecord['subType'])
            ?   (new RelationshipType($this->tenantID))->getRelationshipTypes()[$this->SubInfoRecord['subType']]
            :   '';

        $regionData = (new Subscriber($this->tenantID))->getRegionAndDepartment();
        $viewValues = [
            'value_serviceProviders'    => $SPs,
            'value_scopeOfDueDiligence' => $this->Cases->getScopes()[$this->CasesRecord['caseType']],
            'value_caseNumber'          => $this->CasesRecord['userCaseNum'],
            'value_caseName'            => $this->CasesRecord['caseName'],
            'value_relationshipStatus'  => $this->SubInfoRecord['subStat'],
            'value_relationshipType'    => $subType,
            'value_regionName'          => $this->Model->returnRegionList()[$this->CasesRecord['region']],
            'value_pocName'             => $this->SubInfoRecord['pointOfContact'],
            'value_pocPosition'         => $this->SubInfoRecord['POCposition'],
            'value_pocTelephone'        => $this->SubInfoRecord['phone'],
            'value_investigationFirm'   => $preferredSP,
            'value_companyName'         => $this->SubInfoRecord['name'],
            'value_street'              => $this->SubInfoRecord['street'],
            'value_city'                => $this->SubInfoRecord['city'],
            'value_stateProvince'       => $this->SubInfoRecord['state'],
            'value_country'             => $country,
            'value_postCode'            => $this->SubInfoRecord['postCode'],
            'value_principalsList'      => $this->principalsList(),
            'value_billingUnit'         => $this->returnBillingUnitValue(),
            'value_billingUnitPO'       => $this->returnBillingUnitPOValue(),
            'pageName'                  =>  'Overview',
            'sitePath'                  =>  $this->app->sitePath,
            'add_case_authorization'    =>  str_replace(
                ['[li]', '[/li]'],
                ['<li>', '</li>'],
                (string) $this->app->trans->codeKey('add_case_authorization')
            ),
            'region' => $regionData['regionTitle'] ?? $trText['region'],
        ];

        return array_merge(
            $trText,
            $this->returnCalculationViewValues($preferredSP),
            $viewValues
        );
    }

    /**
     * Returns validation
     *
     * @return array of errors
     */
    #[\Override]
    public function validate()
    {
        $this->setUserInput($this->getUserInput());

        $error = [];
        $SPs   = $this->getServiceProvidersForScope($this->getServiceProviderPreference());

        if ($this->inputs['cb_acceptTerms'] == 'false') {
            $error[] = $this->app->trans->codeKey('accept_add_case_auth');
        }

        if (empty($this->inputs['serviceProvider']) || !isset($SPs[$this->inputs['serviceProvider']])) {
            $error[] = $this->app->trans->codeKey('invalid_service_provider_selected');
        }

        return $error;
    }

    /**
     * Global Cases sync
     * Log the event
     * If client has 3P Monitor or 3P Monitor + 1 Free enabled, auto-run a GDC
     * ServiceProvider does not have bFullSP, treat as SpLite
     * deduct budget amount from the Purchase Order
     *
     * @dev: No email in Legacy clientEmails.invokedBy = 'stageChangeBudgetApproved'.
     *          It was not brought over in the refactor for this reason and implementing $invokedBy
     *          seems to extend all the way to EmailBase.
     *
     * @return bool
     *
     * @throws \Exception must account for all exceptions inside of the class as well as outside (e.g., SpEmail.php)
     */
    public function store()
    {
        $this->CostTimeCalculation = new CaseCostTimeCalc(
            $this->inputs['serviceProvider'],
            $this->tenantID,
            $this->CasesRecord['caseType'],
            $this->returnCostTimeCountryValue(),
            count($this->principalsList()),
            $this->SubInfoRecord['SBIonPrincipals'],
            [
                'deliveryID'
                    => (empty($this->CasesRecord['delivery']) ? null : $this->CasesRecord['delivery']),
                'bilingualRptID'
                    => (empty($this->CasesRecord['bilingualRptID']) ? 0 : $this->CasesRecord['bilingualRptID']),
            ]
        );
        $this->CostTime = $this->CostTimeCalculation->getCostTime();
        $isSpLite       = $this->isSPLite($this->inputs['serviceProvider']);
        $initialRow     = $this->getCaseBudgetInfo();
        $tppAssociated  = $this->app->ftr->has(\Feature::TENANT_TPM) && $this->CasesRecord['tpID'];

        if (empty($this->CostTime->budgetNegotiation)) {
            $caseStage = CaseStage::BUDGET_APPROVED;
            $this->setSystemEmail(SysEmail::EMAIL_BUDGET_APPROVED);
        } else {
            $caseStage = CaseStage::ASSIGNED;
            $this->setSystemEmail(SysEmail::EMAIL_ASSIGNED_NOTICE);
        }
        $investigatorID = $this->getCaseInvestigatorID();   // This may over-write the systemEmail per Legacy.

        $this->updateCaseRecord($caseStage, $investigatorID);
        // ** do not remove. adjusting the budget is based on this updated cases data. **
        $this->CasesRecord = $this->Cases->getCaseRow($this->caseID);

        (new GlobalCaseIndex($this->tenantID))->syncByCaseData($this->caseID);
        $this->logEvent($initialRow, $this->getCaseBudgetInfo());

        if ((new Ddq($this->tenantID))->findByAttributes(['caseID' => $this->caseID])) {
            //  reviewConfirm_pt2.php -> fixDdqStatus($ddqID, $clientID);
        }
        if ($caseStage == CaseStage::BUDGET_APPROVED && $tppAssociated) {
            $this->thirdPartyPersons();
            if ($this->app->ftr->hasAnyOf([\Feature::TENANT_GDC_PREMIUM, \Feature::TENANT_GDC_BASIC])) {
                (new GdcMonitor($this->tenantID, $this->userID))->run3pGdc($this->CasesRecord['tpID']);
            }
        }
        if ($isSpLite) {
            $this->insertSpLiteAuth();
            $this->setSystemEmail(SysEmail::EMAIL_CASE_REQUEST);
            try {
                $this->sendSystemEmail();
            } catch (\RuntimeException|\Exception $e) {
                $this->logError($e, __METHOD__, __LINE__);
            }
        } else {
            /*
             * @dev:
             * Removed if ($this->returnCaseInvestigatorUser($investigatorID)) {
             * check. The legacy behavior produced a bug and sent all emails. To keep consistent Delta
             * avoids this check. see sec-873.
             */

            try {
                $this->sendSystemEmail();
            } catch (\RuntimeException|\Exception $e) {
                $this->logError($e, __METHOD__, __LINE__);
            }
        }

        if ($this->CasesRecord['budgetAmount']) {
            (new PurchaseOrder($this->tenantID))->decrementPurchaseOrder($this->CasesRecord['budgetAmount']);
        }

        return true;
    }

    /**
     * Tests if case investigator user id exist in the Authoritative Users table.
     *
     * If the Service Provider is a full Service Provider and the cost time country is set,
     * then an attempt is made at auto-assignment of an investigator. If a found investigator is not inside
     * of our users table then this email is delivered as an error.
     *
     * @param int $caseInvestigatorUserID Investigator user ID
     *
     * @return void
     */
    private function attemptedSendNoticeFailure($caseInvestigatorUserID)
    {
        $file = __FILE__;
        $to = $_ENV['devopsEmailAddress'];
        $msg =  "Environment: {$this->app->mode} \n"
            . "userID: {$this->userID}\n"
            . "caseID: {$this->caseID}\n"
            . "clientID: {$this->tenantID}\n\n"
            . "The case investigator user ID, {$caseInvestigatorUserID}, "
            . "does not exist in the Authoritative Users table.\n\n"
            . "This occurred in file {$file}.\n";

        AppMailer::mail(
            0,
            $to,
            "FAILURE:  Attempted to send notification when adding a new case from the dashboard.",
            $msg,
            ['addHistory' => false, 'forceSystemEmail' => true]
        );
    }

    /**
     * Returns case budget & investigation information for a given caseID.
     *
     * @refactor reviewConfirm_pt2.php
     *
     * @return mixed
     */
    private function getCaseBudgetInfo()
    {
        return $this->Model->getCaseBudgetInfo($this->caseID);
    }

    /**
     * If Service Provider is a Full Service Provider check for auto-assignment of an investigator.
     *
     * @return int case investigator user id
     */
    private function getCaseInvestigatorID()
    {
        $caseInvestigatorUserID = ($this->inputs['serviceProvider'] == ServiceProvider::STEELE_INVESTIGATOR_PROFILE)
            ?   ServiceProvider::STEELE_VENDOR_ADMIN
            :   0;    // Note: if isSpLite, e.g. KPMG in Legacy, cases.caseInvestigatorUserID = 0 is existing behavior.

        if (!$this->isSPLite($this->inputs['serviceProvider'])) {
            if (!empty($this->inputs['serviceProvider']) && !empty($this->returnCostTimeCountryValue())) {
                $aaID = (new ServiceProvider($this->inputs['serviceProvider']))->caseAutoAssign(
                    $caseInvestigatorUserID,            //  Value of default investigator (users.id)
                    $this->inputs['serviceProvider'],   //  ID of the Service Provider (investigatorProfile.id)
                    $this->CostTime['spProductID'],     //  ID of the product being looked up. (spProduct.id)
                    $this->returnCostTimeCountryValue() //  The iso code of the country for the case.
                );

                if ($aaID != $caseInvestigatorUserID) {
                    $this->setSystemEmail(SysEmail::EMAIL_AUTO_ASSIGNED_NOTICE);
                }

                $caseInvestigatorUserID = $aaID;
            }

            if (!$this->returnCaseInvestigatorUser($caseInvestigatorUserID) && (int)$caseInvestigatorUserID !== 0) {
                // There is a known issue where legacy is not sending this email.
                $this->attemptedSendNoticeFailure($caseInvestigatorUserID);
            }
        }

        // SEC-3080 & SEC-3081
        if ($this->isCountrySanctioned()) {
            $caseInvestigatorUserID = $this->Model->getCountrySanctionedCaseInvestigatorUserID();
        }

        return $caseInvestigatorUserID;
    }

    /**
     * Returns the service provider preference
     *
     * @return int preferred service provider id
     *
     * @throws \Exception
     */
    private function getServiceProviderPreference()
    {
        return (new ServiceProvider())->getServiceProviderPreference(
            $this->tenantID,
            $this->CasesRecord['caseType'],
            $this->returnCostTimeCountryValue()
        );
    }

    /**
     * Returns an array of Service Providers given a case type (scope).
     *
     * @param integer $preferredSP Investigation Firm ID
     *
     * @return array
     *
     * @throws \Exception
     */
    private function getServiceProvidersForScope($preferredSP)
    {
        try {
            $SPs = (new ServiceProvider($preferredSP))->allSpForScope(
                $this->tenantID,
                $this->CasesRecord['caseType'],
                $this->returnCostTimeCountryValue()
            );
        } catch (\Exception $e) {
            if ($preferredSP && $preferredSP != ServiceProvider::STEELE_INVESTIGATOR_PROFILE) {
                $msg = 'No service provider products configured for scope. Error: ' . $e->getMessage();
                $this->app->log->debug($msg . __FILE__ . ' ' . __LINE__);
                throw new \Exception($e->getMessage());
            } else {
                $preferredSP = ServiceProvider::STEELE_INVESTIGATOR_PROFILE;
                $SPs = [$preferredSP => (new ServiceProvider($preferredSP))->spName()];
            }
        }
        return $SPs;
    }

    /**
     * Returns the system email type.
     *
     * @return string
     */
    private function getSystemEmail()
    {
        return $this->sysEmail;
    }

    /**
     * Given an array of Principal data, attempts to return the matching tpPerson record.
     *
     * @param array $principalData generated by returnPrincipalDataByIndex()
     *
     * @return mixed personID
     */
    private function getTpPersonIDByPrincipal($principalData)
    {
        return (new TpPerson($this->tenantID))->getTpPersonIDByPrincipal($principalData);
    }

    /**
     * Returns the id of the tpPersonMap for a given tpPerson.id.
     *
     * @param int $personID tpPerson.id
     *
     * @return int tpPersonMap.id
     */
    private function getTpPersonMapID($personID)
    {
        return (new TpPersonMap($this->tenantID))->getTpPersonMapID($personID, $this->CasesRecord['tpID']);
    }


    /**
     * Returns an id if the Service Provider is not full SP.
     *
     * @param int $spID Service Provider ID
     *
     * @refactor public_html/cms/includes/php/class_all_sp.php isSPLite()
     *
     * @return mixed
     */
    private function isSPLite($spID)
    {
        return (new InvestigatorProfile())->isSPLite($spID);
    }

    /**
     * Inserts Principal data off subjectInfoDD record into tpPerson.
     *
     * @param array $principalData generated by returnPrincipalDataByIndex()
     *
     * @return int tpPerson.id
     */
    private function insertTpPerson($principalData)
    {
        return (new TpPerson($this->tenantID))->insertTpPerson($principalData);
    }

    /**
     * Inserts a record into to tpPersonMap creating an association.
     *
     * @param array $principalData generated by returnPrincipalDataByIndex()
     * @param int   $personID      tpPerson.id
     *
     * @return void
     */
    private function insertTpPersonMap($principalData, $personID)
    {
        (new TpPersonMap($this->tenantID))->insertTpPersonMap(
            $principalData,
            $personID,
            $this->CasesRecord['tpID']
        );
    }

    /**
     * Inserts a SPGlobal.g_spLiteAuth record
     *
     * @refactor reviewConfirm_pt.php line 649
     *
     * @return void
     *
     * @throws \Exception
     */
    private function insertSpLiteAuth()
    {
        $cost       = number_format(floatval($this->CostTime['budgetAmount']), 2, '.', '');
        $productRow = $this->CostTimeCalculation->getInternals('productRow');
        $record     = [
            'subjectName'    => $this->SubInfoRecord['name'],
            'addlPrincipals' => count($this->principalsList()),
            'spID'           => $this->inputs['serviceProvider'],
            'caseID'         => $this->caseID,
            'tenantID'       => $this->tenantID,
            'scope'          => $this->CasesRecord['caseType'],
            'spProduct'      => $productRow->id,
            'sentTo'         => (new InvestigatorProfile())->getInvestigatorEmail($productRow->spID),
            'country'        => $this->returnCostTimeCountryValue(),
            'region'         => $this->CasesRecord['region'],
            'cost'           => (int)$cost,
        ];

        $SpLite = new SpLite();

        if (!$SpLite->validateSpLiteAuth($record)) {
            throw new \Exception($this->app->trans->codeKey('update_record_failed'));
        }
        $SpLite->insertSpLiteAuth($record);
    }

    /**
     * Logs changes to case budget information.
     *
     * @param array $initialRow the initial getCaseBudgetInfo record
     * @param array $updatedRow the updated getCaseBudgetInfo record
     *
     * @return void
     */
    private function logEvent($initialRow, $updatedRow)
    {
        $logMsg     = [];
        $diffRow    = array_diff($updatedRow, $initialRow);

        foreach ($diffRow as $column => $value) {
            $logMsg[] = "$column: `" . $initialRow[$column] . "` => `" . $value . '`';
        }

        $logData = new LogData($this->tenantID, $this->app->ftr->user);
        $logData->saveLogEntry(24, join(', ', $logMsg), $this->caseID, false, $this->tenantID);
    }

    /**
     * Returns formatted strings for non-empty Principals and relationships to be used in
     * legacy style option menu display.
     *
     * @return array list of Principals including relationships.
     */
    private function principalsList()
    {
        $principalValues = [];

        for ($i = 1; $i < 11; $i++) {
            if (!empty($this->SubInfoRecord["principal{$i}"])) {
                $p = (!empty($this->SubInfoRecord["pRelationship{$i}"]))
                    ?   ', ' . $this->SubInfoRecord["pRelationship{$i}"]
                    :   '';

                $principalValues[] = $this->SubInfoRecord["principal{$i}"] . $p;
            }
        }

        return $principalValues;
    }

    /**
     * Called via AJAX from the Add Case Controller as well as getViewValues(), returns cost and time calculations.
     *
     * @param int $spID Service Provider ID
     *
     * @return array
     */
    public function returnCalculationViewValues($spID)
    {
        $trText = $this->app->trans->codeKeys(
            [
                'est_completion_date_days',
                'unpublished',
                'label_AdditionalSubjects',
            ]
        );
        $Calculation = new CaseCostTimeCalc(
            $spID,
            $this->tenantID,
            $this->CasesRecord['caseType'],
            $this->returnCostTimeCountryValue(),
            count($this->principalsList()),
            $this->SubInfoRecord['SBIonPrincipals'],
            [
                'deliveryID' => $this->CasesRecord['delivery'],
                'bilingualRptID' => (!empty($this->CasesRecord['bilingualRptID'])
                    && (int)$this->CasesRecord['bilingualRptID'])
                    ? (int)$this->CasesRecord['bilingualRptID']
                    : 0
            ]
        );
        $CostTimeCalculation = $Calculation->getCostTime();
        $completionLabel     = str_replace(
            '{number}',
            $CostTimeCalculation['showCalDays'],
            (string) $this->app->trans->codeKey('est_completion_date_days')
        );

        foreach (['showDueDate', 'showBaseCost', 'showTotalCost'] as $label) {
            if ($CostTimeCalculation[$label] == '?') {
                $CostTimeCalculation[$label] = $trText['unpublished'];
            }
        }

        return [
            'label_estCompletionDate'  => $completionLabel,
            'label_AdditionalSubjects' => $trText['label_AdditionalSubjects']
                . ' ' . count($this->principalsList()) . ':',
            'value_estCompletionDate'  => $CostTimeCalculation['showDueDate'],
            'value_standardCost'       => $CostTimeCalculation['showBaseCost'],
            'value_showExtraSubCost'   => $CostTimeCalculation['showExtraSubCost'],
            'value_totalCost'          => $CostTimeCalculation['showTotalCost'],
            'hasPrincipals'            => !empty($this->principalsList()),
            'extraSubCharge'           => !empty($CostTimeCalculation['extraSubCharge']),
        ];
    }

    /**
     * Searches a given userID in the global users table returning if it is found or not.
     *
     * @param int $investigatorID users.id of investigator
     *
     * @return int $investigatorID if found or 0
     */
    private function returnCaseInvestigatorUser($investigatorID)
    {
        return ((new User())->findByAttributes(['id' => $investigatorID])) ? $investigatorID : 0;
    }

    /**
     * Returns the database value of the country associated to the case investigation.
     *
     * @return string country ISO code, used in Cost Time Calculation.
     */
    private function returnCostTimeCountryValue()
    {
        return ((new Ddq($this->tenantID))->findByAttributes(['caseID' => $this->caseID]))
            ?   $this->CasesRecord['caseCountry']
            :   $this->SubInfoRecord['country'];
    }

    /**
     * Returns an associative record of subjectionInfoDD data on a given Principal, $i
     *
     * @param int $i Principal index, 1 - 10
     *
     * @return array
     */
    private function returnPrincipalDataByIndex($i)
    {
        return $this->SubInfo->returnPrincipalDataByIndex($this->SubInfoRecord['id'], $i);
    }

    /**
     * Returns the view value for Billing Unit.
     *
     * @return string
     */
    private function returnBillingUnitValue()
    {
        $viewValue = '';

        if (!empty($this->CasesRecord['billingUnit'])) {
            foreach ((new BillingUnit($this->tenantID))->getActiveBillingUnits() as $billingUnit) {
                if ($billingUnit['id'] == $this->CasesRecord['billingUnit']) {
                    $viewValue = $billingUnit['name'];
                    break;
                }
            }
        }

        return $viewValue;
    }

    /**
     * Returns the view value for Billing Unit PO.
     *
     * @return string
     */
    private function returnBillingUnitPOValue()
    {
        $viewValue = '';

        if (!empty($this->CasesRecord['billingUnit'])) {
            $po = (new BillingUnitPO($this->tenantID))->getPOsByBillingUnit($this->CasesRecord['billingUnit']);
            foreach ($po as $billingUnitPO) {
                if ($billingUnitPO['id'] == $this->CasesRecord['billingUnitPO']) {
                    $viewValue = $billingUnitPO['name'];
                    break;
                }
            }
        }

        return $viewValue;
    }

    /**
     * Sets the system email type.
     *
     * @param const $systemEmail type of system email
     *
     * @return void
     */
    private function setSystemEmail($systemEmail)
    {
        $this->sysEmail = $systemEmail;
    }

    /**
     * Sends an email based on an system email type.
     *
     * @return void
     *
     * @throws \Exception
     */
    private function sendSystemEmail()
    {
        switch ($this->getSystemEmail()) {
            case SysEmail::EMAIL_BUDGET_APPROVED:
                (new BudgetApproved($this->tenantID, $this->caseID))->send();
                break;
            case SysEmail::EMAIL_ASSIGNED_NOTICE:
                (new AssignedNotice($this->tenantID, $this->caseID))->send();
                break;
            case SysEmail::EMAIL_AUTO_ASSIGNED_NOTICE:
                (new AutoAssignedNotice($this->tenantID, $this->caseID))->send();
                break;
            case SysEmail::EMAIL_CASE_REQUEST:
                (new CaseRequest($this->tenantID, $this->caseID))->send();
                break;
            default:
                break;
        }
    }

    /**
     * Pulls the Principal information from the new case/subjectInfoDD record.
     * An attempt is made to locate each non-empty Principal in the tpPerson table.
     * If the personID could not be found, insert the principal into the tpPerson table ($tp_persons_inserted).
     * Once the personID is found (SELECT or INSERT), make the association of the tpPerson to tpPersonMap.
     *
     * If either a tpPerson or tpPersonMap record was created in this process then return true.
     *
     * @return bool true if an insertion was made.
     */
    private function thirdPartyPersons()
    {
        $insert = false;

        for ($i = 1; $i < 11; $i++) {
            $principalData = $this->returnPrincipalDataByIndex($i);

            if (!empty($principalData['principal'])) {
                $personID = $this->getTpPersonIDByPrincipal($principalData);
                if (!$personID) {
                    $personID = $this->insertTpPerson($principalData);
                    $insert   = true;
                }
                if ($personID && !$this->getTpPersonMapID($personID)) {  // make the association
                    $this->insertTpPersonMap($principalData, $personID);
                    $insert = true;
                }
            }
        }

        return $insert;
    }

    /**
     * Updates the cases record with the cost time calculation, case stage, and case investigator user ID.
     *
     * @param int $caseStage              The cases.caseType
     * @param int $caseInvestigatorUserID The users.id
     *
     * @return bool
     *
     * @throws \Exception
     */
    private function updateCaseRecord($caseStage, $caseInvestigatorUserID)
    {
        if (!$this
            ->CasesObject->setAttributes(
                [
                    'budgetType'             =>  $this->CostTime['budgetType'],
                    'budgetAmount'           =>  $this->CostTime['budgetAmount'],
                    'caseDueDate'            =>  $this->CostTime['caseDueDate'],
                    'numOfBusDays'           =>  $this->CostTime['busDayTAT'],
                    'caseStage'              =>  $caseStage,
                    'spProduct'              =>  $this->CostTime['spProductID'],
                    'caseInvestigatorUserID' =>  $caseInvestigatorUserID,
                    'caseAssignedDate'       =>  '',
                    'caseAssignedAgent'      =>  $this->inputs['serviceProvider'],
                    'internalDueDate'        =>  $this->CostTime['caseDueDate'],
                ]
            )
        ) {
            throw new \Exception('ERROR: ' . $this->CasesObject->getErrors());
        } elseif (!$this->CasesObject->save()) {
            throw new \Exception('ERROR: ' . $this->CasesObject->getErrors());
        }

        // @dev: Remove when CRUD uses MySQL NOW().
        $this->Cases->updateCaseAssignedDateToNow($this->caseID);

        return true;
    }

    /**
     * Returns true if either selected country is sanctioned
     *
     * @return bool
     */
    private function isCountrySanctioned()
    {
        return (
            (isset($this->CasesRecord['caseCountry'])
            && $this->Model->isCountrySanctioned($this->CasesRecord['caseCountry']))
            ||  (isset($this->CasesRecord['country'])
            && $this->Model->isCountrySanctioned($this->CasesRecord['country']))
        );
    }

    /**
     * Log Error
     *
     * @param object  $e      \Exception object
     * @param string  $method Name of method
     * @param integer $line   Line number
     *
     * @return void
     */
    private function logError($e, $method = '', $line = 0)
    {
        $err = ($this->app->mode == 'Development')
            ? $method . ':' . $line . ' tenantID: ' . $this->tenantID . ' caseID: ' . $this->caseID
            : "{$e->getFile()}:{$e->getLine()} {$e->getMessage()} \n {$e->getTraceAsString()}";

        $this->app->log->error($err);
    }
}
