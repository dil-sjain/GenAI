<?php
/**
 * Model:
 */

namespace Models\TPM\CaseMgt\CaseFolder\Company;

use Lib\Legacy\ClientIds;
use Lib\Legacy\UserType;
use Models\Globals\Geography;
use Models\Ddq;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;
use Models\Globals\Department;
use Models\Globals\Languages;
use Models\Globals\Region;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\RelationshipType;
use Models\ThirdPartyManagement\SubjectInfoDD;
use Models\TPM\CaseMgt\CaseReview;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\IntakeForms\Legacy\OnlineQuestions;
use Lib\Legacy\IntakeFormTypes;
use Lib\FeatureACL;
use Lib\DdqSupport;
use Models\Globals\AclScopes;
use Models\ThirdPartyManagement\CompanyLegalForm;

/**
 * Class CompanyData
 *
 * @keywords
 */
#[\AllowDynamicProperties]
class CompanyData
{
    /**
     * @var object Skinny Application instance
     */
    protected $app = null;

    /**
     * @var object FeatureACL instance
     */
    protected $ftr = null;

    /**
     * @var object \Lib\Database\MySqlPdo instance
     */
    protected $DB = null;

    /**
     * @var integer Tenant ID (clientProfile.id)
     */
    protected $clientID = null;

    /**
     * @var integer Case ID (cases.id)
     */
    protected $caseID = null;

    /**
     * @var associative array of cases row
     */
    protected $caseRow = null;

    /**
     * @var boolean DDQ created
     */
    protected $bDDQcreated = false;

    /**
     * Constructor - initialization
     *
     * @param integer         $clientID Client ID
     * @param integer         $caseID   Case ID
     * @param \Lib\FeatureACL $ftr      FeatureACL
     * @param boolean         $toPDF    To PDF
     *
     * @return void
     */
    public function __construct($clientID, $caseID, $ftr = null, protected $toPDF = false)
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->ftr = (!empty($ftr) ? $ftr : \Xtra::app()->ftr);
        $this->clientID = intval($clientID);
        $this->caseID = intval($caseID);

        $this->bDDQcreated = (new Ddq($this->clientID))->count($this->caseID);

        $this->initialize();
        $this->setSessionVars();
    }

    /**
     * Sets class properties needed for Case Folder functions
     *
     * @return void
     */
    protected function initialize()
    {
        $this->setCaseRow();
    }

    /**
     * Set Case row class property to be used by other functions
     *
     * @return void
     */
    public function setCaseRow()
    {
        $this->caseRow = $this->getCaseRow();
    }

    /**
     * Get the Case row
     *
     * @param int|null $caseID Case ID
     *
     * @return array an associative array row from cases
     */
    public function getCaseRow($caseID = null)
    {
        $caseID = (!empty($caseID) ? $caseID : $this->caseID);
        $caseRow = (new Cases($this->clientID))->getCaseRow($caseID, \PDO::FETCH_ASSOC);
        return $caseRow;
    }

    /**
     * Sets the session variables
     *
     * @return void
     */
    protected function setSessionVars()
    {
        // If Case Stages and Description haven't already been loaded, lets do it
        if (!$this->app->session->has('caseStageList')) {
            $this->app->session->set('caseStageList', $this->getCaseStageList());
        }

        // If Case Type and Description haven't already been loaded, lets do it
        if (!$this->app->session->has('caseTypeList')) {
            $caseTypeList = $this->app->DB->fetchObjectRows("SELECT `id`, `name` FROM `caseType` ORDER BY `id`");
            $this->app->session->set('caseTypeList', $caseTypeList);
        }

        $caseReview = new CaseReview($this->clientID, $this->caseID, $this->ftr);
        $scopeDeviation = $caseReview->getScopeDeviation();
        $this->app->session->set('caseTypeClientName', ($scopeDeviation->completed ? $scopeDeviation->selected : ''));
    }

    /**
     * Get an associative array of caseStage names by id
     *
     * @note this function should be centralized and replaced
     *
     * @return array an associative array formatted [caseStage.id => caseStage.name]
     *
     * @throws \Exception when no caseStage records
     */
    protected function getCaseStageList()
    {
        $caseStages = $this->app->DB->fetchAssocRows("SELECT `id`, `name` FROM `caseStage` ORDER BY `id`");
        if (count($caseStages) == 0) {
            throw new \Exception("ERROR - No Data returned from the caseStage Table!!!");
        }

        $caseStageList = [];
        foreach ($caseStages as $caseStage) {
            $caseStageList[$caseStage['id']] = $caseStage['name'];
        }

        return $caseStageList;
    }

    /**
     * Get generic data
     *
     * @return associative array
     */
    public function getData()
    {
        return ['toPDF' => $this->toPDF, 'bDDQcreated' => $this->bDDQcreated];
    }

    /**
     * Get the order to display the case data
     *
     * @return indexed array
     */
    public function getCaseOrder()
    {
        return [
            0 => 'origin',
            1 => 'langName',
            2 => 'intakeFormVersion',
            3 => 'firstDdqAccess',
            4 => 'lastDdqAccess',
            5 => 'colspan',
            // custom hard coded line for spacing
            6 => 'requestor',
            7 => 'region',
            8 => 'department',
            9 => 'billingUnit',
            10 => 'purchaseOrder',
            11 => 'scopeDeviation',
            // special case
            12 => 'subStat',
            13 => 'subType',
            14 => 'szBudgetAmount',
            15 => 'approveDDQ',
            16 => 'caseDueDate',
            17 => 'bAwareInvestigation',
        ];
    }

    /**
     * Get case related data
     *
     * @return associative array of case data
     */
    public function getCaseData()
    {
        $txtTr = $this->app->trans->codeKeys([
            'case_info',
            'intake_form_source',
            'intakeFormsLegacy_title',
            'manual_creation',
            'intake_form_lang',
            'intake_form_vers',
            'intake_frm_await_comp',
            'intake_frm_closed_before_comp',
            'tp_completed_intake_frm',
            'brief_note',
            'thirdParty_first_login',
            'thirdParty_last_login',
            'requestor',
            'budget',
            'due_diligence_scope',
            'recommended',
            'deviated_from_rec_scope',
            'review_compliance_issues',
            'submitted_ddq_to_legal',
            'approved_ddq',
            'approval_or_reject_details',
            'rejection_details',
            'reason_code',
            'explanation',
            'compliance_findings',
            'potential_red_flags',
            'est_completion_date',
            'relationship_status',
            'sub_aware_inv',
            'yes',
            'no',
            'relationship_type'
        ]);
        $caseData = ['title' => strtoupper((string) $txtTr['case_info'])];

        $array = ['requestor' => 'requestor', 'casePriority' => '', 'caseStage' => '', 'caseAssignedDate' => '', 'caseDueDate' => 'est_completion_date', 'billingCode' => '', 'userCaseNum' => '', 'acceptingInvestigatorID' => '', 'rejectReason' => '', 'rejectDescription' => ''];
        foreach ($array as $key => $trans) {
            if (!empty($trans) && isset($txtTr[$trans])) {
                $caseData[$key]['label']['value'] = $txtTr[$trans];
            }
            $caseData[$key]['text']['value'] = $this->caseRow[$key];
        }
        if (strlen((string) $this->caseRow['caseDescription']) > 0) {
            $caseData['caseDescription']['label']['value'] = $txtTr['brief_note'];
            $caseData['caseDescription']['text']['value'] = $this->caseRow['caseDescription'];
        }
        $ddq = (new Ddq($this->clientID))->findByAttributes(['caseID' => $this->caseID]);

        if (isset($ddq) && !empty($ddq)) {
            $firstDdqAccess = $ddq->get('firstAccess');
            if (!empty($firstDdqAccess)) {
                $caseData['firstDdqAccess'] = ['label' => ['value' => $txtTr['thirdParty_first_login']], 'text' => ['value' => $firstDdqAccess]];
            }
            $lastDdqAccess = $ddq->get('lastAccess');
            if (!empty($lastDdqAccess)) {
                $caseData['lastDdqAccess'] = ['label' => ['value' => $txtTr['thirdParty_last_login']], 'text' => ['value' => $lastDdqAccess]];
            }
        }

        $caseData['origin']['label']['value'] = $txtTr['intake_form_source'];
        $caseData['origin']['text']['value'] = ($this->bDDQcreated
            ? $txtTr['intakeFormsLegacy_title']
            : $txtTr['manual_creation']);

        if ($this->bDDQcreated) {
            $caseData['langName']['label']['value'] = $txtTr['intake_form_lang'];
            $caseData['langName']['text']['value'] = (new Languages())->selectValue(
                'langNameEng',
                ['langCode' => $ddq->get('subInLang')]
            );

            if (isset($ddq) && !empty($ddq)) {
                $caseData['intakeFormVersion']['label']['value'] = $txtTr['intake_form_vers'];
                $ddqStatus = $ddq->get('status');
                switch ($ddqStatus) {
                    case 'active':
                        $caseData['intakeFormVersion']['text']['tip'] = $txtTr['intake_frm_await_comp'];
                        break;
                    case 'closed':
                        $caseData['intakeFormVersion']['text']['tip'] = $txtTr['intake_frm_closed_before_comp'];
                        break;
                    case 'submitted':
                        $caseData['intakeFormVersion']['text']['tip'] = $txtTr['tp_completed_intake_frm'];
                        break;
                }

                $ddqNameModel = new DdqName($this->clientID);
                $iFormName = 'L-' . $ddq->get('caseType') . $ddq->get('ddqQuestionVer');
                $legacyID = preg_replace("/[^-a-zA-Z0-9_\s]/", "", $iFormName);
                $ddqName = $ddqNameModel->selectValue('name', ['legacyID' => $legacyID, 'clientID' => $this->clientID]);
                if ($ddqName) {
                    $intakeFormName = $ddqName;
                } else {
                    $intakeFormName = $iFormName;
                }
                $caseData['intakeFormVersion']['text']['value'] = $intakeFormName . " (" . $ddqStatus . ")";
            }
        }

        $region = new Region($this->clientID);
        $caseData['region'] = ['label' => ['width' => 195, 'value' => $this->app->session->get('customLabels.region')], 'text' => ['width' => 195, 'value' => $region->getRegionName($this->caseRow['region'])]];

        $department = new Department($this->clientID);
        $caseData['department'] = ['label' => ['value' => $this->app->session->get('customLabels.department')], 'text' => ['value' => $department->getDepartmentName($this->caseRow['dept'])]];
        if (isset($this->caseRow['billingUnit']) && !empty($this->caseRow['billingUnit'])) {
            $billingUnit = new BillingUnit($this->clientID);
            $billingUnitRow = $billingUnit->findById($this->caseRow['billingUnit']);
            if (!empty($billingUnitRow)) {
                $caseData['billingUnit'] = ['label' => ['value' => $this->app->session->get('customLabels.billingUnit')], 'text' => ['value' => $billingUnitRow->get('name')]];
            }
        }
        if (isset($this->caseRow['billingUnitPO']) && !empty($this->caseRow['billingUnitPO'])) {
            $billingUnitPO = new BillingUnitPO($this->clientID);
            $billingUnitPORow = $billingUnitPO->findById($this->caseRow['billingUnitPO']);
            if (!empty($billingUnitPORow)) {
                $caseData['purchaseOrder'] = ['label' => ['value' => $this->app->session->get('customLabels.billingPO')], 'text' => ['value' => $billingUnitPORow->get('name')]];
            }
        }

        $caseData['szBudgetAmount']['label']['value'] = $txtTr['budget'];
        $caseData['szBudgetAmount']['text']['value'] = ($this->ftr->legacyUserType >= UserType::VENDOR_ADMIN
            ? sprintf("$ %0.0f (USD)", $this->caseRow['budgetAmount'])
            : "");

        $caseReview = new CaseReview($this->clientID, $this->caseID, $this->ftr);
        $scopeDeviation = $caseReview->getScopeDeviation();
        $redFlags = $caseReview->getRedFlags();

        /*
         * This is outside the scope of the current issue but will be needed in the future.
         */

        //$unexpectedResponses = $caseReview->getUnexpectedResponses();
        $caseData['caseType']['text']['value'] = $scopeDeviation->selected;
        $caseData['scopeDeviation'] = $scopeDeviation;
        $caseData['due_diligence_scope'] = $txtTr['due_diligence_scope'];
        $caseData['recommended'] = $txtTr['recommended'];
        $caseData['deviated_from_rec_scope'] = $txtTr['deviated_from_rec_scope'];
        $caseData['review_compliance_issues'] = $txtTr['review_compliance_issues'];
        if ($this->ftr->has(FeatureACL::TENANT_APPROVE_DDQ) && $this->caseRow['approveDDQ']) {
            $apprDdqLabel = $txtTr['approved_ddq'];
            $caseData['approveDDQ'] = ['label' => ['value' => $apprDdqLabel], 'text' => ['value' => substr((string) $this->caseRow['approveDDQ'], 0, 10)]];
        }

        if ($this->caseRow['caseStage'] >= Cases::COMPLETED_BY_INVESTIGATOR) {
            $hasCaseStage = in_array(
                $this->caseRow['caseStage'],
                [Cases::CLOSED, Cases::CLOSED_INTERNAL, Cases::CASE_CANCELED, Cases::CLOSED_HELD]
            );
            if ($hasCaseStage) {
                $sql = "SELECT name FROM rejectCaseCode WHERE id = :id";
                $params = [':id' => $this->caseRow['rejectReason']];
                $explain = $this->DB->fetchValue($sql, $params);
                $caseData['rejectionDetails'] = ['caseStageClosed' => true, 'header' => strtoupper((string) $txtTr['rejection_details']), 'explain' => ['label' => ['value' => $txtTr['reason_code'], 'width' => 100], 'text' => ['value' => ($explain ?: '')]], 'explain2' => ['label' => ['value' => $txtTr['explanation']], 'text' => ['value' => $this->caseRow['rejectDescription']]]];

                $ddqSupport = new DdqSupport($this->app->DB, $this->app->DB->getClientDB($this->clientID));
                if ($ddqSupport->isClientDanaher($this->clientID)) {
                    $caseData['rejectionDetails']['header'] = strtoupper((string) $txtTr['approval_or_reject_details']);
                }
            } else {
                $caseData['rejectionDetails']['caseStageClosed'] = false;
                $caseData['rejectionDetails']['header'] = strtoupper((string) $txtTr['compliance_findings']);
                $caseData['rejectionDetails']['explain']['label']['width'] = 125;
                $caseData['rejectionDetails']['explain']['label']['value'] = $txtTr['potential_red_flags'];
                if ($redFlags->rfYesNo == 'Yes') {
                    $caseData['rejectionDetails']['explain']['text']['value'] = $txtTr['yes'];
                    $caseData['rejectionDetails']['explain']['label']['classes'] = 'flex-fit redflag fw-normal';
                    $caseData['rejectionDetails']['explain']['text']['classes'] = 'redflag fw-normal';
                } else {
                    $caseData['rejectionDetails']['explain']['text']['value'] = $txtTr['no'];
                    $caseData['rejectionDetails']['explain']['label']['classes'] = 'flex-fit fw-normal';
                    $caseData['rejectionDetails']['explain']['text']['classes'] = 'fw-normal';
                }

                // any subscriber-defined red flags?
                if (count($redFlags->exFlags)) {
                    $caseData['rejectionDetails']['redflags'] = [];
                    foreach ($redFlags->exFlags as $rf) {
                        $redFlag = $rf->name . ($redFlags->showNumbers ? ' (' . $rf->howMany . ')' : '');
                        $caseData['rejectionDetails']['redflags'][] = $redFlag;
                    }
                }
            }
        }

        $subjectInfoDD = (new SubjectInfoDD($this->clientID))->findByAttributes(['caseID' => $this->caseID]);

        $caseData['bAwareInvestigation']['label']['value'] = $txtTr['sub_aware_inv'];
        $caseData['bAwareInvestigation']['text']['value'] = (
            !empty($subjectInfoDD) && $subjectInfoDD->get('bAwareInvestigation')
            ? $txtTr['yes']
            : $txtTr['no']);

        $caseData['subStat']['label']['value'] = $txtTr['relationship_status'];
        $caseData['subStat']['text']['value'] = (!empty($subjectInfoDD)
            ? $subjectInfoDD->get('subStat')
            : '');

        if (!empty($subjectInfoDD)) {
            $relType = (new RelationshipType($this->clientID))->findById($subjectInfoDD->get('subType'));
        }
        $caseData['subType']['label']['value'] = $txtTr['relationship_type'];
        $caseData['subType']['text']['value'] = (isset($relType) && !empty($relType)
            ? $relType->get('name')
            : '');
        return $caseData;
    }

    /**
     * Get the order to display the company data
     *
     * @return indexed array
     */
    public function getCompanyOrder()
    {
        return [
            'name',
            'DBAname',
            'empty',
            // custom
            'address',
            // title
            'street',
            'addr2',
            'city',
            'country',
            'state',
            'postCode',
            'legalForm',
            'registCountry',
            'mainPOCtitle',
            // custom
            'pointOfContact',
            'POCposition',
            'phone',
            'emailAddr',
        ];
    }

    /**
     * Get company related data
     *
     * @todo Refactor the OnlineQuestions to use DdqSupport.
     * @note For some reason, DdqSupport getVal() returns an array instead of a string now.
     *
     * @return associative array of company data
     */
    public function getCompanyData()
    {
        $txtTr = $this->app->trans->codeKeys([
            'tab_Compnay_Details',
            'legalForm',
            'profDetail_official_company_name',
            'addr1',
            'city',
            'state',
            'country',
            'lbl_Name',
            'telephone',
            'email_addr',
            'addr2',
            'postcode',
            'position',
            'profDetail_alt_trade_name_s',
            'lbl_Address',
            'regCountry',
            'main_point_of_contact',
            'select_none'
        ]);

        $companyData = [];

        $ddq = (new Ddq($this->clientID))->findByAttributes(['caseID' => $this->caseID]);
        if (!$this->bDDQcreated) {
            $companyData['title'] = strtoupper((string) $txtTr['tab_Compnay_Details']);
            $subjectInfoDD = (new SubjectInfoDD($this->clientID))->findByAttributes(['caseID' => $this->caseID]);
            $array = ['legalForm' => 'legalForm', 'name' => 'profDetail_official_company_name', 'street' => 'addr1', 'city' => 'city', 'state' => 'state', 'country' => 'country', 'pointOfContact' => 'lbl_Name', 'phone' => 'telephone', 'emailAddr' => 'email_addr', 'addr2' => 'addr2', 'postCode' => 'postcode', 'mailDDquestionnaire' => '', 'POCposition' => 'position', 'SBIonPrincipals' => '', 'DBAname' => 'profDetail_alt_trade_name_s'];
            foreach ($array as $key => $trans) {
                if (!empty($trans) && isset($txtTr[$trans])) {
                    $companyData[$key]['label']['value'] = $txtTr[$trans];
                }
                $companyData[$key]['text']['value'] = (!empty($subjectInfoDD) ? $subjectInfoDD->get($key) : '');
            }
            $companyLegalForm = new CompanyLegalForm();
            if (!empty($subjectInfoDD)) {
                $legalForm = $companyLegalForm->findById($subjectInfoDD->get('legalForm'));
            }
            $companyData['legalForm']['text']['value'] = (isset($legalForm) && !empty($legalForm)
                ? $legalForm->get('name')
                : '');
            $companyData['registCountry']['label']['value'] = $txtTr['regCountry'];
            $geography = Geography::getVersionInstance();
            $companyData['registCountry']['text']['value'] = (!empty($ddq)
                ? $geography->getLegacyCountryName($ddq->get('regCountry'))
                : '');
            $companyData['mainPOCtitle'] = strtoupper((string) $txtTr['main_point_of_contact']);
            $companyData['address']['label']['value'] = $txtTr['lbl_Address'];
            $companyData['address']['label']['classes'] = 'bold';
            $companyData['address']['text']['value'] = '&nbsp;';
            $companyData['country']['text']['value'] = (!empty($subjectInfoDD)
                ? $geography->getLegacyCountryName($subjectInfoDD->get('country'))
                : '');
            $stateName = (!empty($subjectInfoDD) ? $subjectInfoDD->get('state') : '');
            $companyData['state']['text']['value'] = (!empty($stateName)
                ? $stateName
                : $txtTr['select_none']);

            $companyData['empty'] = ['label' => ['width' => 277, 'value' => ''], 'text' => ['value' => '']];
            // width=277 on rows

            $indent = ['street', 'addr2', 'city', 'country', 'state', 'postCode'];
            foreach ($indent as $key) {
                $companyData[$key]['label']['classes'] = 'indent-row';
            }
        } else {
            // Case originated by DDQ
            $haveCompanyDetails = [
                IntakeFormTypes::DUE_DILIGENCE_SBI,
                IntakeFormTypes::DUE_DILIGENCE_SHORTFORM,
                IntakeFormTypes::DDQ_SBI_FORM2,
                IntakeFormTypes::DDQ_SBI_FORM3,
                IntakeFormTypes::DDQ_SBI_FORM4,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGEA,
                AclScopes::DDQ_SHORTFORM_2PAGE_RENEWAL,
                AclScopes::DDQ_SHORTFORM_2PAGEA_RENEWAL,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM2,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM2_COPY85,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM3,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM4,
                AclScopes::DDQ_SHORTFORM_2PAGEA_FORM2,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM2_RENEWAL,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM3_RENEWAL,
                AclScopes::DDQ_SHORTFORM_2PAGE_FORM4_RENEWAL,
                AclScopes::DDQ_SHORTFORM_2PAGEA_FORM2_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_5PAGENOBP,
                IntakeFormTypes::DDQ_5PAGENOBP_FORM2,
                IntakeFormTypes::DDQ_5PAGENOBP_FORM3,
                IntakeFormTypes::DDQ_4PAGE_FORM1,
                IntakeFormTypes::DDQ_4PAGE_FORM2,
                IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL1,
                IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL2,
                IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL,
                IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL_2,
                IntakeFormTypes::DDQ_SBI_FORM2_RENEWAL,
                IntakeFormTypes::DDQ_SBI_FORM3_RENEWAL,
                IntakeFormTypes::DDQ_SBI_FORM4_RENEWAL,
                IntakeFormTypes::DDQ_SBI_FORM5,
                IntakeFormTypes::DDQ_SBI_FORM5_RENEWAL,
                IntakeFormTypes::DDQ_SBI_FORM6,
                IntakeFormTypes::DDQ_SBI_FORM6_RENEWAL,
                IntakeFormTypes::DDQ_SBI_FORM7,
                IntakeFormTypes::DDQ_SBI_FORM7_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_FORM2,
                IntakeFormTypes::DDQ_SHORTFORM_FORM3,
                IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_4PAGE,
                IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1601,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1602,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1603,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1604,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1605,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1606,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1607,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1608,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1609,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1610,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1611,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1612,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1613,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1614,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1615,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1616,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1617,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1618,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1619,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1620,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1701,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1702,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1703,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1704,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1705,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1706,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1707,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1708,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1709,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1710,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1711,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1712,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1713,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1714,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1715,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1716,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1717,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1718,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1719,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1720,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1721,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1722,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1723,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1724,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1725,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1726,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1727,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1728,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1729,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1730,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1731,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1732,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1733,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1734,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1735,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1736,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1737,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1738,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1739,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1740,
            ];
            $in_array = in_array($ddq->get('caseType'), $haveCompanyDetails);
            $sql = "SELECT * FROM ddq WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
            $params = [':caseID' => $this->caseID, ':clientID' => $this->clientID];
            $ddqRow = $this->DB->fetchObjectRow($sql, $params);
            $ddqRowVals = $this->DB->fetchIndexedRow($sql, $params);

            $languageCode = ($ddqRow && $ddqRow->subInLang) ? $ddqRow->subInLang : 'EN_US';
            $keyToLoad = ($in_array ? 'Company Details' : 'Professional Information');

            /*
             * @todo: This OnlineQuestions refactor was refactored using hg Winter branch's version of OnlineQuestions
             * which was different. We shouldn't have to cast to an object and the idea of tdWidth as a parameter is
             * outdated.
             */

            $onlineQuestions = new OnlineQuestions($this->clientID);
            $OQs = $onlineQuestions->getOnlineQuestions(
                $languageCode,
                $ddq->get('caseType'),
                $keyToLoad,
                $this->app->session->get('ddqID'),
                $ddq->get('ddqQuestionVer'),
                true
            );

            if (!$in_array) {
                $companyData['pgbrk'] = true;
            }
            $keyToLoad = ($in_array ? 'TEXT_COMPANYDETAILS_SECTIONHEAD' : 'TEXT_PROFINFO_SECTIONHEAD');
            $row = $onlineQuestions->extractQuestion($OQs, $keyToLoad);
            $companyData['cd_pi_title'] = $row['labelText'];

            $ddqSupport = new DdqSupport($this->app->DB, $this->app->DB->getClientDB($this->clientID));
            if ($in_array) {
                $companyData['cd_pi_sections'] = [];
                foreach ($OQs as $onlineQuestionRow) {
                    if ($onlineQuestionRow['sectionName'] == "COMPANY DETAILS") {
                        if ($onlineQuestionRow['qStatus'] != IntakeFormTypes::ONLINE_Q_HIDDEN) {
                            $companyData['cd_pi_sections'][] = ['width' => 24, 'label' => $ddqSupport->getLabel((object)$onlineQuestionRow), 'text' => $ddqSupport->getVal($ddqRowVals, (object)$onlineQuestionRow, 100)];
                        }
                    }
                }
                $row = $onlineQuestions->extractQuestion($OQs, 'TEXT_POC_SECTIONHEAD');
                $companyData['poc_title'] = ($row['labelText'] ?? '');

                $companyData['poc_sections'] = [];
                foreach ($OQs as $onlineQuestionRow) {
                    if ($onlineQuestionRow['sectionName'] == "MAIN POINT OF CONTACT") {
                        if ($onlineQuestionRow['qStatus'] != IntakeFormTypes::ONLINE_Q_HIDDEN) {
                            $companyData['poc_sections'][] = ['width' => 24, 'label' => $ddqSupport->getLabel((object)$onlineQuestionRow), 'text' => $ddqSupport->getVal($ddqRowVals, (object)$onlineQuestionRow, 100)];
                        }
                    }
                }
            } else {
                foreach ($OQs as $onlineQuestionRow) {
                    if ($onlineQuestionRow['sectionName'] == "PROFESSIONAL INFORMATION") {
                        if ($onlineQuestionRow['qStatus'] != IntakeFormTypes::ONLINE_Q_HIDDEN) {
                            $companyData['cd_pi_sections'][] = ['width' => 1, 'label' => $ddqSupport->getLabel((object)$onlineQuestionRow), 'text' => $ddqSupport->getVal($ddqRowVals, (object)$onlineQuestionRow, 100)];
                        }
                    }
                }
            }
        }
        return $companyData;
    }

    /**
     * Get the order to display the investigator data
     *
     * @return indexed array
     */
    public function getInvestigatorOrder()
    {
        return [0 => 'company', 1 => 'name', 2 => 'email', 3 => 'internalDueDate'];
    }

    /**
     * Get investigator related data
     *
     * @todo Move SP specific code to app/Models/SP/CaseMgt/CaseFolder/Company/CompanyData.php
     *
     * @return associative array of investigator data
     */
    public function getInvestigatorData()
    {
        $txtTr = $this->app->trans->codeKeys([
            'investigator_info',
            'tab_Company',
            'investigator',
            'kp_lbl_email',
            'internal_due_date'
        ]);
        $investigatorData = ['title' => strtoupper((string) $txtTr['investigator_info']), 'company' => ['label' => ['width' => '110', 'value' => $txtTr['tab_Company']], 'text' => ['width' => '200', 'value' => '']], 'name' => ['id' => 'leadInvestigator', 'label' => ['value' => $txtTr['investigator']], 'text' => ['value' => '']], 'email' => ['id' => 'leadInvestigatorEmail', 'label' => ['value' => $txtTr['kp_lbl_email']], 'text' => ['value' => '']], 'phone' => ['text' => ['value' => '']]];

        $spID = intval($this->caseRow['caseAssignedAgent']);
        if (!$spID) {
            $profileRow = false;
            $isSpLite = false;
        } else {
            $sql = "SELECT investigatorName, bFullSp, investigatorEmail, investigatorPhone "
                . "FROM {$this->DB->spGlobalDB}.investigatorProfile "
                . "WHERE id = :spID LIMIT 1";
            $params = [':spID' => $spID];
            $profileRow = $this->DB->fetchObjectRow($sql, $params);
            $investigatorData['company']['text']['value'] = $profileRow->investigatorName;
            $isSpLite = ($profileRow->bFullSp == 0);
        }
        if (!$isSpLite) {
            if ($this->ftr->legacyUserType > UserType::VENDOR_ADMIN && $this->caseRow['assigningProjectMgrID']) {
                // show project manager to client, if available
                $userID = intval($this->caseRow['assigningProjectMgrID']);
            } else {
                // show actual investigator
                $userID = intval($this->caseRow['caseInvestigatorUserID']);
            }
            $sql = "SELECT userName, userEmail, firstName, lastName, userPhone "
                . "FROM {$this->DB->authDB}.users WHERE id = :userID LIMIT 1";
            $params = [':userID' => $userID];
            if ($user = $this->DB->fetchObjectRow($sql, $params)) {
                $investigatorData['name']['text']['value'] = ($this->app->session->get('authUserClass') == 'vendor'
                    ? $user->lastName . ', ' . $user->firstName
                    : $user->userName);
                $investigatorData['email']['text']['value'] = $user->userEmail;
                $investigatorData['phone']['text']['value'] = $user->userPhone;
            }
        } else {
            $investigatorData['name']['text']['value'] = '(not provided)';
            if ($profileRow) {
                $investigatorData['email']['text']['value'] = $profileRow->investigatorEmail;
                $investigatorData['phone']['text']['value'] = $profileRow->investigatorPhone;
            }
            $sql = "SELECT iCompany, iName, iPhone, iEmail "
                . "FROM {$this->DB->globalDB}.g_spLiteAuth WHERE caseID = :caseID "
                . "ORDER BY id DESC LIMIT 1";
            $params = [':caseID' => $this->caseID];
            if ($spAuthRow = $this->DB->fetchObjectRow($sql, $params)) {
                // Override values, if available
                if ($spAuthRow->iCompany) {
                    $investigatorData['company']['text']['value'] = $spAuthRow->iCompany;
                }
                if ($spAuthRow->iName) {
                    $investigatorData['name']['text']['value'] = $spAuthRow->iName;
                }
                if ($spAuthRow->iEmail) {
                    $investigatorData['email']['text']['value'] = $spAuthRow->iEmail;
                }
                if ($spAuthRow->iPhone) {
                    $investigatorData['phone']['text']['value'] = $spAuthRow->iPhone;
                }
            }
        }

        return $investigatorData;
    }
}
