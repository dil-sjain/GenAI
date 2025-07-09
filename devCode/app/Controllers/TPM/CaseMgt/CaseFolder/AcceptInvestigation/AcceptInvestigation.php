<?php
/**
 * Accept Investigation controller
 */
namespace Controllers\TPM\CaseMgt\CaseFolder\AcceptInvestigation;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Lib\GlobalCaseIndex;
use Models\LogData;
use Models\ThirdPartyManagement\Cases;
use Lib\Legacy\CaseStage;
use Models\Globals\CaseStages;
use Lib\Legacy\NoteCategory as NoteCategoryLib;
use Controllers\SP\Email\AcceptedByRequestor;
use Controllers\TPM\Email\Cases\NotifyCreator;
use Models\TPM\CaseMgt\CaseNote;
use Models\TPM\CaseMgt\PassFailReason;
use Models\Globals\NoteCategory;

/**
 * AcceptInvestigation controller
 *
 * @keywords accept investigation, investigation
 */
class AcceptInvestigation extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/CaseMgt/CaseFolder/AcceptInvestigation/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'AcceptInvestigation.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var integer users.id
     */
    protected $userID;

    /**
     * @var integer cases.id
     */
    protected $caseID = 0;

    /**
     * Constructor gets model instance to interact with JIRA API
     *
     * @param integer $tenantID   Delta tenantID (aka: clientProfile.id)
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
        $this->userID = $this->session->get('authUserID');
        if ($this->app->session->has('currentID.case')) {
            $this->caseID = $this->app->session->get('currentID.case');
        }

        $case = (new Cases($this->tenantID))->findByAttributes(
            [
            'id' => $this->caseID,
            'caseStage' => CaseStage::COMPLETED_BY_INVESTIGATOR
            ]
        );
        if (empty($case)) {
            $this->app->redirect('accessDenied');
        }
    }

    /**
     * Sets required properties to display the Accept Investigation dialog.
     *
     * @return void Sets jsObj
     */
    private function ajaxAcceptInvestigation()
    {
        $this->jsObj->Result = 0;
        if ($this->app->ftr->hasAllOf([\Feature::ACCEPT_COMPLETED_INVESTIGATION, \Feature::TENANT_ACCEPT_PASS_FAIL])) {
            $txtTr = $this->app->trans->codeKeys(
                [
                'investigation_outcome',
                'lbl_pass',
                'lbl_fail',
                'lbl_optional_comment',
                'select_default',
                'accept_investigation'
                ]
            );
            $this->setViewValue('txtTr', $txtTr);

            $case = (new Cases($this->tenantID))->findById($this->caseID);
            $this->setViewValue('case', $case);

            $reasons = (new PassFailReason($this->tenantID))->getPassFailReasons($case->get('caseType'));
            if (!empty($reasons)) {
                $this->setViewValue('reasons', $reasons);
            }

            $html = $this->app->view->fetch(
                $this->getTemplate(),
                $this->getViewValues()
            );

            $this->jsObj->Args = [
                'title' => $txtTr['accept_investigation'],
                'html'  => $html
            ];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Submit the accept investigation popup.
     *
     * @return void
     */
    private function ajaxSubmitAcceptInvestigation()
    {
        $this->jsObj->Result = 0;
        $this->jsObj->DoNewCSRF = 1;
        if ($this->app->ftr->hasAllOf([\Feature::ACCEPT_COMPLETED_INVESTIGATION, \Feature::TENANT_ACCEPT_PASS_FAIL])) {
            $txtTr = $this->app->trans->codeKeys(
                [
                'input_missing_hdr',
                'sel_inv_pass_fail_err_msg'
                ]
            );
            // modal has dual operation mode:
            //         apf = 'pass' or 'fail'  (no passFailReason records)
            //    Or,  rid = passFailReason.id (has passFailReason records)
            $passfail = \Xtra::arrayGet($this->app->clean_POST, 'apf', '');
            $reasonID = intval(\Xtra::arrayGet($this->app->clean_POST, 'rid', 0));
            $comment = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'com', ''));

            if ($reasonID) {
                $case = (new Cases($this->tenantID))->findById($this->caseID);
                $reason = (new PassFailReason($this->tenantID))->findByAttributes(
                    [
                    'id' => $reasonID,
                    'clientID' => $this->tenantID,
                    'caseType' => $case->get('caseType')
                    ]
                );
                if (!empty($reason)) {
                    $passfail = $reason->get('pfType');
                } else {
                    $passfail = ''; // abort
                    $reasonID = 0;
                }
            } else { // $reasonID == 0
                // client side validates this case but legacy didn't have an error here
            }
            if (!empty($passfail) && in_array($passfail, ['pass', 'fail', 'neither'])) {
                $stage = CaseStage::COMPLETED_BY_INVESTIGATOR;
                $prevCaseStage = (new CaseStages())->selectByID($stage, ['name']);

                $case = (new Cases($this->tenantID))->findByAttributes(
                    [
                    'id' => $this->caseID,
                    'caseStage' => $stage
                    ]
                );
                if (!empty($case)) {
                    $newStage = CaseStage::ACCEPTED_BY_REQUESTOR;
                    $case->setAttributes(
                        [
                        'caseStage' => $newStage,
                        'caseAcceptedByRequestor' => date("Y-m-d"),
                        'passORfail' => $passfail,
                        'passFailReason' => $reasonID
                        ]
                    );
                    if ($case->save()) {
                        $globalIdx = new GlobalCaseIndex($this->tenantID);
                        $globalIdx->syncByCaseData($this->caseID);

                        $caseStage = (new CaseStages())->selectByID($newStage, ['name']);
                        $logData = new LogData($this->tenantID, $this->userID);
                        $logData->saveLogEntry(
                            25,
                            "stage: `".$prevCaseStage['name']."` => `".$caseStage['name']."` ("
                            . ($reasonID == 0
                            ? strtoupper((string) $passfail)
                            : $reason->get('reason')) . ")",
                            $this->caseID
                        );

                        // create internal note category, if needed
                        $categoryID = NoteCategoryLib::APF_NOTE_CAT;
                        $noteCategory = (new NoteCategory($this->tenantID))->findById($categoryID);
                        if (empty($noteCategory)) { // rather than comparing != NoteCategoryLib::APF_NOTE_CAT
                            $categoryName = 'Accept Completed Investigation';
                            $category = (new NoteCategory($this->tenantID));
                            $category->setAttributes(
                                [
                                'id' => $categoryID,
                                'name' => $categoryName
                                ]
                            );
                            $category->save();
                            $logData->saveLogEntry(31, "name: `$categoryName`");
                        }

                        // save the note
                        if (!empty($comment)) {
                            if ($reasonID) {
                                $subject = 'Investigation: ' . $reason->get('reason');
                            } else {
                                $subject = 'Investigation: ' . strtoupper((string) $passfail);
                            }
                            $caseNote = new CaseNote($this->tenantID);
                            $caseNote->setAttributes(
                                [
                                'clientID'  => $this->tenantID,
                                'caseID'    => $this->caseID,
                                'noteCatID' => $categoryID,
                                'ownerID'   => $this->userID,
                                'created'   => date('Y-m-d H:i:s'),
                                'subject'   => $subject,
                                'note'      => $comment
                                ]
                            );
                            $caseNote->save();
                            $logData->saveLogEntry(29, "Subject: $subject", $this->caseID);
                        }

                        // send email to notify requestor investigation complete
                        $acceptedByRequestorEmail = new AcceptedByRequestor($this->tenantID, $this->caseID);
                        $acceptedByRequestorEmail->send();
                        $notifyCreatorEmail = new NotifyCreator($this->tenantID, $this->caseID, $this->userID);
                        $notifyCreatorEmail->send();
                    }

                    // on success, these 2 lines redirect to Case List
                    // This is an AJAX handler so the normal header/Location thing won't work here.
                    $this->app->session->remove('currentID.case');
                    $this->jsObj->Result = 1; // indicates success, redirects to Case List
                }
            } else {
                $this->jsObj->ErrTitle = $txtTr['input_missing_hdr'];
                $this->jsObj->ErrMsg = $txtTr['sel_inv_pass_fail_err_msg'];
            }
        }
    }
}
