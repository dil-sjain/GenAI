<?php
/**
 * Format and send Notify Creator Pass/Fail.
 *
 * @keywords pass, fail
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Lib\Traits\EmailHelpers;
use Models\Globals\EmailBaseModel;
use Models\ThirdPartyManagement\ClientProfile;
use Models\User;
use Lib\Legacy\NoteCategory;
use Models\TPM\CaseMgt\CaseNote;

/**
 * Class NotifyCreator
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class NotifyCreator extends CaseEmail
{
    use EmailHelpers;

    protected $app = null;

    /**
     * Initialize class for sending a Notify Creator pass/fail email
     *
     * @param int $tenantID ID client sending email
     * @param int $caseID   ID of the case (cases.id)
     * @param int $userID   ID of currently logged in user. Will try to retrieve from session if not provided
     */
    public function __construct($tenantID, $caseID, $userID = 0)
    {
        \Xtra::requireInt($tenantID);
        \Xtra::requireInt($caseID);
        $this->app = \Xtra::app();
        $this->setEmType(SysEmail::EMAIL_NOTIFY_CREATOR_PASS_FAIL);
        if ($userID == 0) {
            $userID = $this->app->ftr->user;
        }
        $this->userID = $userID;

        parent::__construct($tenantID, $caseID);
    }

    /**
     * Initialize variables needed for sending email
     *
     * @throws \Exception
     * @return void
     */
    protected function initialize()
    {
        // Load client object
        $this->client = new ClientProfile();
        $this->client = $this->client->findById($this->tenantID);
        if (!is_object($this->client)) {
            throw new \Exception('Unable to find client with id: ' . $this->tenantID);
        }

        $this->user = new User();
        $this->user = $this->user->findById($this->userID);
        if (!is_object($this->user)) {
            throw new \Exception('Unable to find user with id: ' . $this->userID);
        }

        parent::initialize();

        // Set email tokens that can be used for this email type.
        $this->addEmailTokens();
    }

    /**
     * Return default email subject and body.
     *
     * @return EmailBaseModel
     */
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('Screening of <cases.caseName>');

        $body = "The Third Party <cases.caseName> (Case # <cases.userCaseNum>) "
            ."has had a Due Diligence screening performed and has <cases.passORfail>ed "
            ."with the following explanation:";
        $body .= "\n\n"."<caseNote.note>"."\n\n"."Thank You,"."\n"."<users.userName>";

        $email->setBody($body);

        return $email;
    }

    /**
     * Add replaceable email tokens
     *
     * @return void
     */
    private function addEmailTokens()
    {
        $caseNote = (new CaseNote($this->tenantID))->findByAttributes(
            [
            'clientID' => $this->tenantID,
            'noteCatID' => NoteCategory::APF_NOTE_CAT,
            'caseID' => $this->caseID
            ]
        );

        $this->addTokenData('cases.userCaseNum', $this->case->get('userCaseNum'));
        $this->addTokenData('cases.caseName', $this->case->get('caseName'));
        $this->addTokenData('cases.passORfail', $this->case->get('passORfail'));
        $this->addTokenData('caseNote.note', (!empty($caseNote) ? $caseNote->get('note') : ''));
        $this->addTokenData('users.userName', $this->user->get('userName'));

        $this->setLegacyTokens(
            [
            'cases.userCaseNum',
            'cases.caseName',
            'cases.passORfail',
            'caseNote.note',
            'users.userName'
            ]
        );
    }

    /**
     * Load email for sending
     *
     * @param EmailBaseModel $email  Instance of EmailBaseModel containing data for sending the current email type
     * @param Bool           $fauxCc This email requires a faux CC email be sent along with the main email.
     *
     * @throws \Exception
     * @return void
     */
    protected function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        // Always use fauxCc for these emails
        $fauxCc = true;

        if (is_null($email)) {
            $email = $this->getEmail();
        }

        $this->setEmailFrom($email);

        // Set recipient
        $creator = (new User())->findByAttributes(['userid' => $this->case->get('creatorUID')]);
        $email->addTo($creator->get('userEmail'), $creator->get('userName'));

        parent::loadEmail($email, $fauxCc);
    }

    /**
     * Set Email From values
     *
     * @param object &$email Instance of the EmailBaseModel to update
     *
     * @throws \Exception
     * @return void
     */
    protected function setEmailFrom(&$email)
    {
        $email->setFrom($this->user->get('userEmail'));
        $email->setFromName($this->user->get('userName'));
        $bnOutgoingEmail = $this->user->get('BNoutgoingEMail');
        if ($this->validEmailPattern($bnOutgoingEmail)) {
            $email->addCc($bnOutgoingEmail);
        }
    }
}
