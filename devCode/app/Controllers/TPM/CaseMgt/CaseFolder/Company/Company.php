<?php
/**
 * Case Managment - Company sub-tab
 *
 * @keywords case management, company tab, case management company
 */

namespace Controllers\TPM\CaseMgt\CaseFolder\Company;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\CaseMgt\CaseFolder\Company\CompanyData;
use Models\Ddq;
use Models\ThirdPartyManagement\Cases;
use Lib\Legacy\CaseStage;

/**
 * Handles requests and responses for Case Management - Company sub-tab
 */
#[\AllowDynamicProperties]
class Company extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/CaseMgt/CaseFolder/Company/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Company.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var int Authenticated User ID
     */
    protected $userID;

    /**
     * @var \Models\TPM\CaseMgt\CaseFolder\Company\CompanyData
     */
    protected $model = null;

    /**
     * @var integer Case ID
     */
    protected $caseID = null;

    /**
     * Constructor
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app = \Xtra::app();
        $this->userID = $this->app->session->authUserID;
        $this->processParams($initValues);
        parent::__construct($clientID, $initValues);
    }

    /**
     * Check and process passed in params as needed for further processing
     *
     * @param array $initValues Contains any passed in params that may need some processing
     *
     * @return void
     */
    private function processParams($initValues)
    {
        if (isset($initValues['params']) && isset($initValues['params']['id'])) {
            $this->caseID = $initValues['params']['id'];
        } elseif ($this->app->session->has('currentID.case')) {
            $this->caseID = $this->app->session->get('currentID.case');
        }
    }

    /**
     * Load required Models.
     */
    public function initialize()
    {
        $this->model = new CompanyData($this->clientID, $this->caseID);
        $this->setViewValues();
    }

    /**
     * Set vars on page load
     *
     * @note $companyData['cd_pi_sections'] and $companyData['poc_sections'] use DdqSupport->getVal() which
     * now returns an array instead of a string.
     *
     * @return void
     */
    public function setViewValues()
    {
        $this->setViewValue('data', $this->model->getData());

        $this->setViewValue('investigatorOrder', $this->model->getInvestigatorOrder());
        $this->setViewValue('investigatorData', $this->model->getInvestigatorData());

        $this->setViewValue('caseOrder', $this->model->getCaseOrder());
        $this->setViewValue('caseData', $this->model->getCaseData());

        $this->setViewValue('companyOrder', $this->model->getCompanyOrder());
        $this->setViewValue('companyData', $this->model->getCompanyData());

        $case = (new Cases($this->tenantID))->findById($this->caseID);
        $ddq = (new Ddq($this->tenantID))->findByAttributes(['caseID' => $this->caseID]);
        $showReview = (!($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser())
            && !$this->app->ftr->has(\Feature::TENANT_OMIT_REVIEW_PANEL)
            && (($ddq && $ddq->get('status') == 'submitted')
                || in_array($case->get('caseStage'), [CaseStage::COMPLETED_BY_INVESTIGATOR, CaseStage::ACCEPTED_BY_REQUESTOR])));
        if ($showReview) {
            if (!$this->app->ftr->has(\Feature::TENANT_REVIEW_TAB)) {
                $this->setViewValue('showReviewPanel', true);
            }
        }

        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }
}
