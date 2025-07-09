<?php
/**
 * Construct the "Case Folder" sub-tabs
 */

namespace Controllers\TPM\CaseMgt\CaseFolder;

use Controllers\TPM\CaseMgt\CaseMgtNavBar;
use Lib\Legacy\CaseStage;
use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Lib\Navigation\Navigation;
use Models\ThirdPartyManagement\Cases;
use Models\TPM\CaseMgt\CaseFolder\CaseFolderNavBarData;
use Models\TPM\CaseMgt\CaseFolder\CaseFolderData;
use Models\TPM\CaseMgt\PassFailReason;

/**
 * Class CaseFolderNavBar controls display of the Case Folder sub-tabs
 *
 * @keywords case folder, case folder tab, case folder navigation
 */
class CaseFolderNavBar extends CaseMgtNavBar
{
    /**
     * Application instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * Contains the case ID
     *
     * @var integer
     */
    private $caseID = null;

    /**
     * Cases instance object for client
     *
     * @var object
     */
    private $cases = null;

    /**
     * Client ID
     *
     * @var integer
     */
    protected $clientID = null;

    /**
     * Instance of our data model for this class
     *
     * @var object
     */
    private $model = null;

    /**
     * Base directory for View
     *
     * @var string
     */
    protected $tplRoot = 'TPM/CaseMgt/CaseFolder/';

    /**
     * Base template for View
     *
     * @var string
     */
    protected $tpl = 'CaseFolder.tpl';

    /**
     * Contains the nav (tab) bar configuration (name, parent reference, etc.)
     *
     * @var array
     */
    protected $navBar = null;

    /**
     * Contains all HP client ID's, used for tab access checks and label text
     *
     * @var array
     */
    private $hpClientIds = ClientIds::HP_ALL;

    /**
     * Contains the intake forms to test against for tab text
     *
     * @var array
     */
    private $text = [
        'company' => [
            IntakeFormTypes::DUE_DILIGENCE_HCPDI,
            IntakeFormTypes::DUE_DILIGENCE_HCPDI_RENEWAL,
        ],
        'bizPract' => [
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL,
        ],
    ];

    /**
     * Associative array by organized by tab that contains the intake
     * forms to test against for tab access (beyond feature checking)
     *
     * @var array
     *
     */
    private $access = [
        'company' => [
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGEA,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL,
        ],
        'bizPract' => [
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGEA,
            IntakeFormTypes::DDQ_SHORTFORM_5PAGENOBP,
            IntakeFormTypes::DDQ_5PAGENOBP_FORM2,
            IntakeFormTypes::DDQ_5PAGENOBP_FORM3,
            IntakeFormTypes::DDQ_4PAGE_FORM1,
            IntakeFormTypes::DDQ_4PAGE_FORM2,
            IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL1,
            IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL2,
        ],
        'relation' => [
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGEA,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM,
            IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL1,
            IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL2,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL,
        ],
        'relationHP' => [
            IntakeFormTypes::DUE_DILIGENCE_SBI,
            IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL,
            IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL_2,
            IntakeFormTypes::DDQ_SBI_FORM2,
            IntakeFormTypes::DDQ_SBI_FORM4,
        ],
        'reviewer' => [
            CaseStage::COMPLETED_BY_INVESTIGATOR,
            CaseStage:: ACCEPTED_BY_REQUESTOR,
        ],
    ];

    /**
     * Tab types to check for custom text
     *
     * @var array
     */
    private $tabTypes = [
        'Personnel'         => 'TEXT_PERSONNEL_TAB',
        'BusinessPractices' => 'TEXT_BUSPRACT_TAB',
        'Relationship'      => 'TEXT_RELATION_TAB',
        'Company'           => 'TEXT_PROFINFO_TAB',
        'AdditionalInfo'    => 'TEXT_ADDINFO_TAB',
        'Auth'              => 'TEXT_AUTH_TAB',
    ];

    /**
     * Init class constructor
     *
     * @param int   $clientID   Current client ID
     * @param array $initValues Any additional parameters that need to be passed in, such as route and case ID
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app = \Xtra::app();
        $this->processParams($clientID, $initValues);
        parent::__construct($clientID, $initValues);
    }

    /**
     * Check if access is allowed to a navigation element (tab) based upon factors other than user permissions handled
     * by 'Features' as specified in each tabs configuration. For example, certain tabs are not displayed for
     * the 'short form' of the DDQ.
     *
     * @param array  $tab    Contains the tab configuration
     * @param object $ddqRow Contains the DDQ information
     *
     * @return boolean True/false indicator if access is allowed or not
     */
    private function allowAccess($tab, $ddqRow)
    {
        $caseType = $ddqRow ? $ddqRow->get('caseType') : null; // [107]

        switch ($tab['me']) {
            case 'Company':
                $allowAccess = (!$ddqRow || (!in_array($caseType, $this->access['company'])));
                break;
            case 'CustomFields':
                $tpID = $this->cases->getCaseField($this->caseID, 'tpID');
                $allowAccess = $this->model->allowCustomFieldAccess($this->clientID, $tpID);
                break;
            case 'BusinessPractices':
                $allowAccess = ($ddqRow && !in_array($caseType, $this->access['bizPract']));
                break;
            case 'Relationship':
                $allowAccess = ($ddqRow && !in_array($caseType, $this->access['relation']) &&
                (!in_array($this->clientID, $this->hpClientIds) && in_array($caseType, $this->access['relationHP'])));
                break;
            case 'Reviewer':
                $caseStage = $this->cases->getCaseField($this->caseID, 'caseStage');
                $allowAccess = (bool)($this->app->session->get('authUserClass') != 'vendor'
                && (($ddqRow && $ddqRow->get('status') == 'submitted')
                    || in_array($caseStage, $this->access['reviewer'])
                )
                );
                break;
            default:
                $allowAccess = true;
        }
        return $allowAccess;
    }

    /**
     * Create the Case Folder navigation bar
     *
     * @return void
     */
    protected function createNavBar()
    {
        parent::createNavBar();

        $navBarNodeName = 'CaseFolder';
        $navBars        = ['parent' => $this->navBar, 'current' => $navBarNodeName];
        $nav            = new Navigation($navBars);
        $this->navBar   = $nav->navBar;

        $this->createNavBarTabs($nav);
        $tabs = $nav->getNavBar();
        $this->setViewValue('tabsDataL2', json_encode($tabs));
        $this->setViewValue('tabsDataL2HeaderTpl', 'TPM/CaseMgt/CaseFolder/TabsL2Header.tpl');

        $caseData = [];
        $txtTr = $this->app->trans->codeKeys(
            [
            'fld_Batch_Number',
            'linked_case',
            'case_scope',
            'case_status',
            'case_stage',
            'show_password',
            'tp_risk_rating',
            'dup_cases',
            'view_list',
            'view_dup_cases',
            'ddq_invite_creds'
            ]
        );

        if (!empty($this->clientID) && !empty($this->caseID)) {
            $caseFolderData = new CaseFolderData($this->clientID, $this->caseID);

            $linkedToData = $caseFolderData->getLinkedToCaseLinkData();
            if (!empty($linkedToData)) {
                $linkedToData['label'] = $txtTr['linked_case'];
                $caseData['linkedTo'] = $linkedToData;
            }
            $linkedFromData = $caseFolderData->getLinkedFromCaseLinkData();
            if (!empty($linkedFromData)) {
                $linkedFromData['label'] = $txtTr['linked_case'];
                $caseData['linkedFrom'] = $linkedFromData;
            }
            $caseData['scope'] = ['label' => $txtTr['case_scope'], 'text' => $caseFolderData->getCaseTypeClientName()];
            $caseData['stage'] = ['label' => $txtTr['case_stage'], 'text' => $caseFolderData->getCaseStage()];

            $dupCaseRecords = $caseFolderData->getDuplicateCases();
            if ($dupCaseRecords) {
                $caseData['dupCases'] = ['label' => $txtTr['dup_cases'], 'text' => $txtTr['view_list'] . ' (' . count($dupCaseRecords) . ')', 'title' => $txtTr['view_dup_cases']];
            }
            $caseRow = $caseFolderData->getCaseRow();
            $caseData['caseRow'] = $caseRow;
            $tpRow = $caseFolderData->getThirdPartyProfileRow();
            if (!empty($tpRow) && isset($tpRow->risk)) {
                $caseData['tpRowRisk'] = ['label' => $txtTr['tp_risk_rating'], 'text' => $tpRow->risk];
            }
        }

        $iconOrder = $caseFolderData->getIconOrder();
        $this->setViewValue('iconOrder', $iconOrder);

        $cfIcons = $caseFolderData->getIconData();
        $this->setViewValue('cfIcons', $cfIcons);

        if (isset($cfIcons['accept_investigation'])) {
            $reasons = (new PassFailReason($this->tenantID))->getPassFailReasons($caseRow['caseType']);
            $this->setViewValue('reasons', $reasons);

            $accInvTxtTr = $this->app->trans->codeKeys([
                'input_missing_hdr',
                'sel_inv_outcome_err_msg',
                'sel_inv_pass_fail_err_msg'
            ]);
            $this->setViewValue('accInvTxtTr', $accInvTxtTr);
        }

        $caseData['cfHeadRowspan'] = $caseFolderData->getHeaderRowspan();
        $batchNumber = $caseFolderData->getBatchNumber();
        if ($batchNumber) {
            $caseData['batchNumber'] = ['label' => $txtTr['fld_Batch_Number'], 'text' => $caseFolderData->getBatchNumber()];
        }
        $caseData['region'] = ['label' => $this->app->session->get('customLabels.region'), 'text' => $caseFolderData->getRegionName()];
        $caseData['department'] = ['label' => $this->app->session->get('customLabels.department'), 'text' => $caseFolderData->getDepartmentName()];
        $caseData['status'] = ['label' => $txtTr['case_status'], 'text' => $caseFolderData->getDynStatus()];
        $showInvitePw = $caseFolderData->showInvitePw();
        if ($showInvitePw) {
            $caseData['showInvitePw'] = ['text' => '(' . $txtTr['show_password'] . ')', 'title' => $txtTr['ddq_invite_creds']];
        }
        //$caseData['triggerGdc'] = $caseFolderData->gdcTriggered();
        $this->setViewValue('caseData', $caseData);
    }

    /**
     * Create the navigation (tabs) for the Case Folder nav bar
     *
     * @param object $nav Object instance to the navigation class
     *
     * @return void
     */
    private function createNavBarTabs($nav)
    {
        $ddq       = new \Models\Ddq($this->clientID);
        $ddqRow    = $ddq->findByAttributes(['caseID' => $this->caseID]);
        $tabLabels = $this->getNavBarLabels($ddqRow);

        foreach (CaseFolderNavBarTabs::$tabs as $key => $tab) {
            if ($tabLabels && isset($tabLabels[$key]) && $tabLabels[$key] != '') {
                $tab['label'] = $tabLabels[$key];
            }

            if ($this->allowAccess($tab, $ddqRow)) {
                $nav->add($nav->getConfig($this->navBar, $tab));
            }
        }

        $nav->updateStateByNodeId($nav->navBar['id']);
    }

    /**
     * Get the label text for the Case Folder sub-tabs
     *
     * @param object $ddqRow Contains the DDQ information
     *
     * @return array $labels Contains an associative array of text labels for the nav bar
     */
    private function getNavBarLabels($ddqRow)
    {
        $labels = [
            'Personnel'         => '',
            'BusinessPractices' => '',
            'Relationship'      => '',
            'Company'           => '',
            'AdditionalInfo'    => '',
            'Auth'              => ''
        ];
        $trText = $this->app->trans->group('tabs_case');

        if ($ddqRow) {
            $caseType = $ddqRow->get('caseType');         // [107]
            $qVer     = $ddqRow->get('ddqQuestionVer');   // [126]

            foreach ($this->tabTypes as $tab => $tabType) {
                $labels[$tab] = $this->model->getLabel($this->clientID, $tabType, 'EN_US', $caseType, $qVer);
            }

            if (empty($labels['Company']) && in_array($caseType, $this->text['company'])) {
                $labels['Company'] = $trText['tab_Professional_Information'];
            }

            if (empty($labels['BusinessPractices'])
                && (in_array($caseType, $this->text['bizPract'])
                || in_array($this->clientID, $this->hpClientIds))
            ) {
                $labels['BusinessPractices'] = $trText['tab_Questionnaire'];
            }

            if ($caseType == IntakeFormTypes::DUE_DILIGENCE_SHORTFORM) {
                $labels['AdditionalInfo'] = !empty($labels['Auth']) ? $labels['Auth'] : $trText['submit'];
            }
        }

        if (in_array($this->clientID, $this->hpClientIds)) {
            $labels['AdditionalInfo'] = !empty($labels['Auth']) ? $labels['Auth'] : $trText['submit'];
        }

        $customFlds = $this->app->session->get('customLabels.caseCustomFields');
        $labels['CustomFields'] = !empty($customFlds) ? $customFlds : '';

        return $labels;
    }

    /**
     * Check and process passed in params as needed for further processing
     *
     * @param integer $clientID   Client ID
     * @param array   $initValues Contains any passed in params that may need some processing
     *
     * @throws \Exception Throws an exception if required parameters are not present
     *
     * @return void
     */
    private function processParams($clientID, $initValues)
    {
        if (empty($clientID)) {
            throw new \Exception('Missing Client ID in CaseFolderNavBar Controller');
        }

        $this->clientID = $clientID;
        $this->model    = new CaseFolderNavBarData($clientID);
        $this->cases    = new Cases($clientID);

        // For the Case Folder tab make sure we have a valid Case ID
        if (isset($initValues['params']) && isset($initValues['params']['id'])) {
            $caseID = $initValues['params']['id'];
            if (is_numeric($caseID)) {
                $this->caseID = $caseID;
                $this->app->session->set('currentID.case', $caseID);
            }
        } elseif ($this->app->session->has('currentID.case')) {
            $this->caseID = $this->app->session->get('currentID.case');
        }
        if (is_null($this->caseID)) {
            throw new \Exception('Missing Case ID in CaseFolderNavBar Controller');
        }
    }

    /**
     * Render the Case Folder nav bar
     *
     * @return void
     */
    public function renderNavBar()
    {
        $this->createNavBar();
        echo $this->app->view->render($this->getTemplate(), $this->getViewValues());
    }
}
