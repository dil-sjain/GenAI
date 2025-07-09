<?php
/**
 * CaseNotes Controller
 *
 * @keywords cases, case management
 */

namespace Controllers\TPM\CaseMgt\CaseFolder\CaseNotes;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\ThirdPartyManagement\ClientProfile;
use Models\TPM\CaseNotes as CaseNotesModel;
use Models\TPM\NoteCategory;
use Lib\Legacy\UserType;
use Models\User;
use Lib\IO;
use Models\LogData;
use Models\ThirdPartyManagement\Cases;
use Lib\Services\AppMailer;

class CaseNotes extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/CaseMgt/CaseFolder/CaseNotes/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'CaseNotes.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object JSON response template
     */
    protected $jsObj = null;

    /**
     * @var int ID of the current case
     */
    protected $caseID;

    /**
     * @var boolean generate PDF
     */
    protected $toPDF = false;

    /**
     * @var boolean whether or not user is able to add case notes
     */
    protected $canAddNote = false;

    /**
     * @var string User Class
     */
    protected $userClass = null;

    /**
     * @var boolean whether or not the user class is 'vendor'
     */
    protected $isVendor = false;

    /**
     * Construct CaseNotes instance
     *
     * @param integer $tenantID   Tenant ID
     * @param array   $initValues Flexible construct to pass in values
     * @throws \Exception
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
        $this->userID = $this->app->ftr->user;

        if (isset($initValues['toPDF']) && $initValues['toPDF']) {
            $this->toPDF = true;
        }

        // TODO: Update to only use methods defined by tab parent
        if ($this->app->session->has('currentID.case')) {
            $this->caseID = $this->app->session->get('currentID.case');
        } elseif ($this->app->session->has('currentCaseID')) {
            $this->caseID = $this->app->session->get('currentCaseID');
        } elseif (isset($initValues['caseID'])) {
            $this->caseID = $initValues['caseID'];
        } elseif (isset($_SESSION['currentCaseID'])) {
            $this->caseID = $_SESSION['currentCaseID'];
        } else {
            throw new \Exception('Case not found');
        }
        $user = (new User())->findByAttributes(['id' => $this->app->ftr->user]);
        if (is_object($user) && !empty($user)) {
            $case = (new Cases($this->tenantID))->findByID($this->caseID);
            $this->canAddNote = (is_object($case) && !empty($case)
                && ($user->get('userSecLevel') > UserType::USER_SECLEVEL_RO)
                && !$this->toPDF && $this->app->ftr->has(\Feature::CASE_NOTES_ADD));
        }
        $this->userClass = ($this->app->session->has('authUserClass') ? $this->app->session->get('authUserClass') : '');
        $this->isVendor = ($this->userClass === 'vendor');
    }

    /**
     * Set Smarty template variables to use on page load
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('isVendor', $this->isVendor);
        $this->setViewValue('isPDF', $this->toPDF);
        $this->setViewValue('canAccess', $this->canAccess);
        $this->setViewValue('canAddNote', $this->canAddNote);
        $this->setViewValue('pgTitle', $this->app->trans->codeKey('case_notes_title'));

        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * Retrieves and echoes JSON data for the datatable.
     *
     * @return void
     */
    public function getData()
    {
        $data = new \stdClass();
        $data->draw = (intval(\Xtra::arrayGet($this->app->clean_POST, 'draw', 0)) + 1); // Track async data versions
        $data->recordsTotal = 0;
        $data->recordsFiltered = 0;
        $data->data = (new CaseNotesModel($this->tenantID))->getList($this->caseID);
        echo IO::jsonEncodeResponse($data);
    }

    /**
     * New Case Note Dialog.
     *
     * @note: Should I separate add and update or keep them as one save function?
     *
     * @return void Sets jsObj
     */
    private function ajaxNewCaseNote()
    {
        $this->jsObj->Result = 0;
        try {
            $this->tplRoot = 'TPM/CaseMgt/CaseFolder/CaseNotes/Dialogs/';
            $this->tpl = 'ModifyCaseNote.tpl';

            $this->setViewValue('isVendor', $this->isVendor);
            if (!$this->isVendor) {
                $this->setViewValue('categories', (new NoteCategory($this->tenantID))->getList());
            }
            $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());

            $this->jsObj->Args = [
                'title' => "Enter Your Note",
                'html' => $html
            ];
            $this->jsObj->Result = 1;
        } catch (\Exception $ex) {
            $this->jsObj->ErrMsg = $ex->getMessage();
        }
    }

    /**
     * View Case Note Dialog.
     *
     * @note: Should I separate add and update or keep them as one save function?
     *
     * @return void Sets jsObj
     */
    private function ajaxViewCaseNote()
    {
        $this->jsObj->Result = 0;
        try {
            $this->tplRoot = 'TPM/CaseMgt/CaseFolder/CaseNotes/Dialogs/';
            $this->tpl = 'ViewCaseNote.tpl';

            $id = intval(\Xtra::arrayGet($this->app->clean_POST, 'note', -1));
            if ($id >= 0) {
                $data = [
                    'id' => $id,
                    'clientID' => $this->tenantID,
                    'caseID' => $this->caseID
                ];
                if ($this->userClass == 'client') {
                    $data['bInvestigator'] = 0; // string?
                } elseif ($this->userClass == 'vendor') {
                    // @note: This is a work-around because our Active Record implementation doesn't support ORs
                    $count = $this->app->DB->fetchValue(
                        "SELECT COUNT(*) FROM caseNote WHERE id=:id AND clientID=:clientID AND caseID=:caseID"
                            ." AND (bInvestigator=1 OR bInvestigatorCanSee=1)",
                        [
                            ':id' => $id,
                            ':clientID' => $this->tenantID,
                            ':caseID' => $this->caseID
                        ]
                    );
                    if ($count <= 0) {
                        return; // don't have access to Case Note so don't display dialog
                    }
                } elseif ($this->userClass != 'admin') { // not client, vendor, or admin
                    throw new \Exception("Invalid User Class");
                }
                $caseNote = (new CaseNotesModel($this->tenantID))->findByAttributes($data);
                if (!empty($caseNote)) {
                    $this->setViewValue('isVendor', $this->isVendor);
                    $this->setViewValue('caseNote', $caseNote);
                    $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());

                    $this->jsObj->Args = [
                        'canModify' => $caseNote->canModify(),
                        'title' => "Case Note" . ($caseNote->get('bInvestigatorCanSee') ? " (shared)" : ""),
                        'html' => $html
                    ];
                    $this->jsObj->Result = 1;
                }
            }
        } catch (\Exception $ex) {
            $this->jsObj->ErrMsg = $ex->getMessage();
        }
    }

    /**
     * Edit Case Note Dialog.
     *
     * @note: Should I separate add and update or keep them as one save function?
     *
     * @return void Sets jsObj
     */
    private function ajaxEditCaseNote()
    {
        $this->jsObj->Result = 0;
        try {
            $this->tplRoot = 'TPM/CaseMgt/CaseFolder/CaseNotes/Dialogs/';
            $this->tpl = 'ModifyCaseNote.tpl';

            $id = \Xtra::arrayGet($this->app->clean_POST, 'note', -1);
            if ($id >= 0) {
                $caseNote = (new CaseNotesModel($this->tenantID))->findById($id);
                if (!empty($caseNote)) {
                    $this->setViewValue('isVendor', $this->isVendor);
                    $this->setViewValue('caseNote', $caseNote);
                    $this->setViewValue('categories', (new NoteCategory($this->tenantID))->getList());
                    $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());

                    $this->jsObj->Args = [
                        'title' => "Enter Your Note",
                        'html' => $html
                    ];
                    $this->jsObj->Result = 1;
                }
            }
        } catch (\Exception $ex) {
            $this->jsObj->ErrMsg = $ex->getMessage();
        }
    }

    /**
     * Saves a Case Note.
     *
     * @note: Should I separate add and update or keep them as one save function?
     *
     * @return void Sets jsObj
     */
    private function ajaxSaveCaseNote()
    {
        $this->jsObj->Result = 0;
        try {
            $id = \Xtra::arrayGet($this->app->clean_POST, 'note', -1);
            $caseNote = ($id >= 0
                ? (new CaseNotesModel($this->tenantID))->findById($id)
                : new CaseNotesModel($this->tenantID));
            if ($id < 0 || $caseNote->canModify()) {
                $category = \Xtra::arrayGet($this->app->clean_POST, 'category', 0);
                $subject = \Xtra::arrayGet($this->app->clean_POST, 'subject', '');
                $body = mb_substr((string) \Xtra::normalizeLF(\Xtra::arrayGet($this->app->clean_POST, 'body', '')), 0, 2000);
                $inves = (!$this->isVendor ? intval(\Xtra::arrayGet($this->app->clean_POST, 'inves', 0)) : 0);

                $data = [
                    'clientID' => $this->tenantID,
                    'caseID' => $this->caseID,
                    'subject' => $subject,
                    'noteCatID' => $category,
                    'ownerID' => $this->app->ftr->user,
                    'note' => $body,
                    'bInvestigatorCanSee' => $inves,
                    'created' => date("Y-m-d H:i:s")
                ];
                if ($id < 0) {
                    $data['bInvestigator'] = ($this->userClass == 'vendor' ? 1 : 0);
                }
                if ($this->isVendor) {
                    $data['noteCatID'] = 0;
                }

                if ($caseNote->setAttributes($data)) {
                    if ($caseNote->save()) {
                        (new LogData($this->tenantID, $this->app->ftr->user))->saveLogEntry(
                            ($id < 0 ? ($this->isVendor ? 90 : 29) : ($this->isVendor ? 91 : 34)),
                            'Subject: ' . $subject,
                            $this->caseID
                        );
                        $this->jsObj->Result = 1;
                        if ($id < 0 && $this->userClass !== 'vendor' && $inves) {
                            $case = (new Cases($this->tenantID))->findByAttributes([
                                'id' => $this->caseID,
                                'clientID' => $this->tenantID,
                                'caseStage' => Cases::ACCEPTED_BY_INVESTIGATOR
                            ]);
                            if (is_object($case) && !empty($case)) {
                                $investigator = (new User())->findById($case->get('caseInvestigatorUserID'));
                                if (is_object($investigator) && !empty($investigator->get('userEmail'))) {
                                    // Send new case note email to an accepted case's investigator
                                    $mailer = new AppMailer();
                                    if ($mailer->isValidAddress($investigator->get('userEmail'))) {
                                        $mailer->Subject = "Note Added - Case Number: {$case->get('userCaseNum')}";
                                        $caseTypeClientList = $this->app->session->get('caseTypeClientList');
                                        $companyShortName = (
                                            new ClientProfile()
                                        )->findById($this->tenantID)->get('companyShortName');
                                        $mailer->Body = "Dear {$investigator->get('userName')},\n\n"
                                            . "A new note has been added to your case:\n"
                                            . "Company: {$companyShortName}\n" // is there better way to get this value?
                                            . "Case Number: {$case->get('userCaseNum')}\n"
                                            . "Case Name: {$case->get('caseName')}\n"
                                            . "Case Type: {$caseTypeClientList[$case->get('caseType')]}\n"
                                            . "Link: {$this->app->sitePath}case/casehome.sec?id={$this->caseID}"
                                                ."&tname=casefolder&icli={$this->tenantID}&rd=1\n\n"
                                            . "Subject: {$caseNote->get('subject')}\n\n"
                                            . "Message: \n"
                                            . "{$caseNote->get('note')}";
                                        $mailer->setFrom(
                                            $_ENV['smtpNotifyAddress'],
                                            'Third Party Risk Management - Compliance Notification'
                                        );
                                        $mailer->addAddress(
                                            $investigator->get('userEmail'),
                                            $investigator->get('userName')
                                        );
                                        $mailer->sendMessage($this->tenantID, false);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $ex) {
            $this->jsObj->ErrMsg = $ex->getMessage();
        }
    }

    /**
     * Deletes a Case Note.
     *
     * @return void Sets jsObj
     */
    private function ajaxDeleteCaseNote()
    {
        $this->jsObj->Result = 0;
        try {
            $id = \Xtra::arrayGet($this->app->clean_POST, 'note', -1);
            if ($id >= 0) {
                $caseNote = (new CaseNotesModel($this->tenantID))->findById($id);
                if (!empty($caseNote) && $caseNote->canModify()) {
                    if ($caseNote->delete()) {
                        (new LogData($this->tenantID, $this->app->ftr->user))->saveLogEntry(
                            ($this->userClass == 'vendor' ? 92 : 30),
                            'Subject: ' . $caseNote->get('subject'),
                            $this->caseID
                        );
                        $this->jsObj->Result = 1;
                    }
                }
            }
        } catch (\Exception $ex) {
            $this->jsObj->ErrMsg = $ex->getMessage();
        }
    }
}
