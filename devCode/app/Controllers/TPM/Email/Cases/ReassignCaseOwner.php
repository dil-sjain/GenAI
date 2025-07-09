<?php
/**
 * Format and send Reassign Case Owner notice
 *
 * @keywords intake form, ddq, case, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;
use Models\User;

/**
 * Class AcceptedByInvestigator
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class ReassignCaseOwner extends CaseEmail
{
    /**
     * @var \Models\User
     */
    protected $oldOwner = null;

    /**
     * @var \Models\User
     */
    protected $newOwner = null;

    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int    $clientID      ID client sending email
     * @param int    $caseID        ID of case that is being reassigned
     * @param int    $newOwnerID    ID of new case owner
     * @param int    $oldOwnerID    ID of the old case owner
     * @param string $changeMessage ID of the old case owner
     * @param int    $userID        ID of currently logged in user. Will try to retrieve from session if not provided
     */
    public function __construct($clientID, $caseID, protected $newOwnerID, protected $oldOwnerID, $changeMessage, $userID = 0)
    {
        $this->setEmType(SysEmail::EMAIL_REASSIGN_CASE_OWNER);
        $this->addTokenData('changeMsg', $changeMessage);

        if ($userID == 0) {
            $userID = \Xtra::app()->session->get('authUserID');
        }

        $this->userID     = $userID;

        parent::__construct($clientID, $caseID);
    }

    /**
     * Explicitly clean up created objects
     */
    #[\Override]
    public function __destruct()
    {
        unset($this->user, $this->oldOwner, $this->newOwner);

        parent::__destruct();
    }

    /**
     * Initialize variables needed for sending email
     *
     * @throws \Exception
     * @return void
     */
    #[\Override]
    public function initialize()
    {
        $this->user = (new User())->findById($this->userID);
        if (!is_object($this->user)) {
            throw new \Exception('Unable to find user with id: ' . $this->userID);
        }

        $this->newOwner = (new User())->findById($this->newOwnerID);
        if (!is_object($this->newOwner)) {
            throw new \Exception('Unable to find user with id: ' . $this->newOwnerID);
        }

        $this->oldOwner = (new User())->findById($this->oldOwnerID);
        if (!is_object($this->oldOwner)) {
            throw new \Exception('Unable to find user with id: ' . $this->oldOwnerID);
        }

        parent::initialize();
    }

    /**
     * Return default email subject and body.
     *
     * @return EmailBaseModel
     */
    #[\Override]
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('Reassignment of Case <userCaseNum>');

        $body = 'One or more elements of the following case have been reassigned.' . "\n";
        $body .= 'Case Number <userCaseNum>, <caseName>' . "\n";
        $body .= '<changeMsg>' . "\n";
        $body .= '<senderName>';

        $email->setBody($body);

        return $email;
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
    #[\Override]
    protected function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        // Send second email to old owner using FauxCc
        $fauxCc = true;

        if (is_null($email)) {
            $email = $this->getEmail($this->getLanguageCode(), $this->case->get('caseType'));
        }

        $this->addTokenData('LOGIN_URL', $this->buildLinkUrl($this->caseID, $this->tenantID, true));
        $this->addTokenData('userCaseNum', $this->case->get('userCaseNum'));
        $this->addTokenData('caseName', $this->case->get('caseName'));
        $this->addTokenData('senderName', $this->user->get('userName'));
        $this->setLegacyTokens([
            'LOGIN_URL',
            'userCaseNum',
            'caseName',
            'senderName',
            'changeMsg']);

        $new    = $this->newOwner->get('userEmail');
        $old    = $this->oldOwner->get('userEmail');
        $sender = $this->user->get('userEmail');
        $email->addTo($new);
        $email->addCc($old);

        // If requester/sender is not one of the email recipients, add them as a Bcc as well
        if ($sender !== $new && $sender != $old) {
            $email->addBcc($sender);
        }

        parent::loadEmail($email, $fauxCc);
    }

    /**
     * Override CaseEmail prepareBody method, we are sending an exact copy for this email.
     *
     * @throws \Exception
     * @return void
     */
    #[\Override]
    public function prepareBody()
    {
        return;
    }
}
