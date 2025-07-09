<?php
/**
 * Format and send notification of TP internal owner change
 *
 * @keywords TP, email
 */

namespace Controllers\TPM\Email\TP;

use Controllers\TPM\Email\Observers\EmailLoggingInterface;
use Controllers\TPM\Email\Observers\Logging;
use Controllers\TPM\Email\SystemEmail;
use Lib\Legacy\IntakeFormTypes;
use Lib\Legacy\SysEmail;
use Lib\Traits\EmailHelpers;
use Models\Globals\EmailBaseModel;
use Models\ThirdPartyManagement\ThirdParty;
use Models\User;

/**
 * Class Invitation (???)
 *
 * @package Controllers\TPM\Email\TP
 */
#[\AllowDynamicProperties]
class ReassignThirdPartyInternalOwner extends SystemEmail implements EmailLoggingInterface
{
    use EmailHelpers;

    /**
     * @var int ID of the currently logged in user or user to act as currently logged in
     */
    protected $userID;

    /**
     * @var \Models\User
     */
    protected $user = null;

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
     * @param integer $clientID   ID client sending email
     * @param integer $refID      ID of main object needed to send email
     * @param integer $newOwnerID ID of new TP owner
     * @param integer $oldOwnerID ID of the old TP owner
     * @param integer $userID     ID of currently logged in user.
     * @param boolean $isAPI      If true, this was called via the API
     *
     * @return void
     */
    public function __construct(
        $clientID,
        $refID,
        protected $newOwnerID,
        protected $oldOwnerID,
        $userID = 0, /**
         * Determines whether or not this was instantiated via the REST API
         */
        protected $isAPI = false
    ) {
        $this->setEmType(SysEmail::EMAIL_REASSIGN_3P_INTERNAL_OWNER);
        if (empty($userID) && !$this->isAPI) {
            $userID = \Xtra::app()->session->get('authUserID');
        }
        $this->userID     = $userID;

        $this->attach(new Logging());
        parent::__construct($clientID, $refID);
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
    protected function initialize()
    {
        $params = ($this->isAPI) ? ['authUserID' => $this->userID, 'isAPI' => true] : [];
        $this->thirdPartyProfile = new ThirdParty($this->tenantID, $params);
        $this->thirdPartyProfile = $this->thirdPartyProfile->findById($this->refID);
        if (!is_object($this->thirdPartyProfile)) {
            throw new \Exception('No Third Party associated with Case.');
        }

        $this->user = new User();
        $this->user = $this->user->findById($this->userID);
        if (!is_object($this->user)) {
            throw new \Exception('Unable to find user with id: ' . $this->userID);
        }

        $this->newOwner = new User();
        $this->newOwner = $this->newOwner->findById($this->newOwnerID);
        if (!is_object($this->newOwner)) {
            throw new \Exception('Unable to find user with id: ' . $this->newOwnerID);
        }

        $this->oldOwner = new User();
        $this->oldOwner = $this->oldOwner->findById($this->oldOwnerID);
        if (!is_object($this->oldOwner)) {
            throw new \Exception('Unable to find user with id: ' . $this->oldOwnerID);
        }

        // Set email tokens that can be used for this email type.
        $this->addEmailTokens();
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

        $subject  = 'Third Party Profile Internal Owner Change: ';
        $subject .= '<thirdPartyProfile.userTpNum> <thirdPartyProfile.legalName>';
        $email->setSubject($subject);

        $body  = 'The responsibility for Third Party Profile <thirdPartyProfile.legalName> ';
        $body .= '(<thirdPartyProfile.userTpNum>), previously owned by <thirdPartyProfile.oldOwner>, has ';
        $body .= 'been reassigned to <thirdPartyProfile.newOwner>.' . "\n\n";
        $body .= '<users.userName>';

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
        $this->addTokenData('thirdPartyProfile.userTpNum', $this->thirdPartyProfile->get('userTpNum'));
        $this->addTokenData('thirdPartyProfile.legalName', $this->thirdPartyProfile->get('legalName'));
        $this->addTokenData('thirdPartyProfile.oldOwner', $this->oldOwner->get('userName'));
        $this->addTokenData('thirdPartyProfile.newOwner', $this->newOwner->get('userName'));
        $this->addTokenData('users.userName', $this->user->get('userName'));
        $this->setLegacyTokens([
            'thirdPartyProfile.userTpNum',
            'thirdPartyProfile.legalName',
            'thirdPartyProfile.oldOwner',
            'thirdPartyProfile.newOwner',
            'users.userName'
            ]);
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
        // We are using the FauxCc to send a second copy of the email to the new owner
        $fauxCc = true;

        $email = $this->getEmail($this->getLanguageCode(), IntakeFormTypes::DUE_DILIGENCE_SBI);

        $email->addTo($this->oldOwner->get('userEmail'));
        $email->addCc($this->newOwner->get('userEmail'));

        parent::loadEmail($email, $fauxCc);
    }

    /**
     * Prepare subject field for sending.
     *
     * @return void
     */
    #[\Override]
    protected function prepareSubject()
    {
        return;
    }


    /**
     * Prepare body field for sending.
     *
     * @return void
     */
    #[\Override]
    protected function prepareBody()
    {
        return;
    }

    /**
     * Get the ID for use when logging emails
     *
     * @return int
     */
    #[\Override]
    public function getRefId()
    {
        return $this->refID;
    }

    /**
     * Retrieve the multiple ID's associated with email that will be set in email log table (e.g. caseID and tpID)
     *
     * @return array
     */
    #[\Override]
    public function getRefIds()
    {
        return []; // Not used
    }
}
