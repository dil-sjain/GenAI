<?php
/**
 * Reject Case controller
 */
namespace Controllers\TPM\CaseMgt\CaseFolder\RejectCase;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\CaseMgt\CaseFolder\RejectCase\RejectCaseData;

/**
 * RejectCase controller
 *
 * @keywords reject/close, reject case
 */
#[\AllowDynamicProperties]
class RejectCase extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/CaseMgt/CaseFolder/RejectCase/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'RejectCase.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object Data Model
     */
    protected $model = null;

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($tenantID, $initValues = [])
    {
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
        $userID = $this->session->get('authUserID');
        $caseID = 0;
        if ($this->app->session->has('currentID.case')) {
            $caseID = $this->app->session->get('currentID.case');
        }
        $this->model = new RejectCaseData($this->tenantID, $caseID, $userID);
        if (!$this->model->hasAccess()) {
            $this->app->redirect('accessDenied');
        }
    }

    /**
     * Sets required properties to display the Reject Case dialog.
     *
     * @return void Sets jsObj
     */
    private function ajaxRejectCase()
    {
        $this->jsObj->Result = 0;
        $txtTr = $this->app->trans->codeKeys(
            [
            'select_default',
            'reassign_case_elements'
            ]
        );
        $this->setViewValue('txtTr', $txtTr);
        $this->setViewValue('case', $this->model->getCase());
        $this->setViewValue('rejectStatus', $this->model->getRejectStatus());
        $this->setViewValue('rejectCaseCodes', $this->model->getRejectCaseCodes());

        $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());
        $this->jsObj->Args = [
            'title' => '', // explicitly set empty string or it will default to "Dialog"
            'html' => $html
        ];
        $this->jsObj->Result = 1;
    }

    /**
     * Submit the Reject/Close Case form/dialog.
     *
     * @return void Performs form submission and sets jsObj accordingly.
     */
    private function ajaxSubmit()
    {
        $this->jsObj->Result = 0;
        $rejectReason = \Xtra::arrayGet($this->app->clean_POST, 'rejectReason', '');
        $rejectDescription = \Xtra::arrayGet($this->app->clean_POST, 'rejectDescription', '');
        if ($rejectReason !== '') {
            $rejectReason = intval($rejectReason);
            try {
                $this->model->setRejectCaseCode($rejectReason);
                $this->model->validateData(); // should validate set data while checking it?
                /* If rejectCaseCode.returnStatus is 'deleted', must check if case is linked.
                 *  - If at top of chain, remove linkage from case and ddq.
                 *  - If something has linked to it, deletion is not allowed.
                 */
                if (!$this->model->hasReturnStatus('deleted') || !$this->model->isLinkedTo()) {
                    if ($this->model->isTopLink()) {
                        $this->model->removeLinkedRecords();
                    }
                    $this->model->updateCase($rejectReason, $rejectDescription);
                    $this->model->updateDdq();
                    $this->model->sendEmail();
                    $this->jsObj->Result = 1;
                } else {
                    $this->jsObj->ErrMsg = $this->app->trans->codeKey('linked_case_cannot_del');
                }
            } catch (\Exception $ex) {
                $this->jsObj->ErrMsg = $ex->getMessage();
            }
        } else {
            $this->jsObj->ErrMsg = $this->app->trans->codeKey('reject_reason_required');
        }
    }
}
