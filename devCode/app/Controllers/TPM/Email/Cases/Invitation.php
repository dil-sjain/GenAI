<?php
/**
 * Format and send Intake Form (DDQ) Invitations.
 *
 * @keywords intake form, ddq, email
 */

namespace Controllers\TPM\Email\Cases;

use Controllers\TPM\Email\Observers\EmailLoggingInterface;
use Controllers\TPM\Email\Observers\Logging;
use Controllers\TPM\Email\SystemEmail;
use Lib\Legacy\SysEmail;
use Lib\Services\AppMailer;
use Lib\Traits\EmailHelpers;
use Models\Globals\Geography;
use Models\Ddq;
use Models\Globals\EmailBaseModel;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ClientProfile;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\SystemEmails;
use Models\User;

/**
 * Class Invitation
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class Invitation extends SystemEmail implements EmailLoggingInterface
{
    use EmailHelpers;

    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param integer $clientID     ID client sending email
     * @param integer $intakeFormID ID of the intake form (ddq.id)
     * @param integer $userID       ID of currently logged in user. Will try to retrieve from session if not provided
     * @param boolean $isAPI        If true, this was called via the API
     *
     * @return array If isAPI is true, return an array if an error
     */
    public function __construct(
        $clientID,
        $intakeFormID,
        $userID = 0, /**
         * Determines whether or not this was instantiated via the REST API
         */
        protected $isAPI = false
    ) {
        $tenantID = (int)$clientID;
        $intakeFormID = (int)$intakeFormID;
        $userID = (int)$userID;
        $this->tenantID = 0;
        $this->caseID = 0;
        $modelParams = ['authUserID' => $userID, 'isAPI' => $this->isAPI];
        if (empty($tenantID)) {
            throw new \Exception('Invitations Email has insufficient parameters: missing tenantID.');
        } else {
            $this->client = (new ClientProfile(['clientID' => $tenantID]))->findById($tenantID);
            $this->client->getAttributes();
            if (!is_object($this->client)) {
                throw new \Exception("Invitations Email: invalid clientID #{$tenantID}");
            } else {
                $this->tenantID = $tenantID;
            }
        }

        if ((int)$userID === 0) {
            if (!$isAPI) {
                $userID = \Xtra::app()->session->get('authUserID');
            }
            if ((int)$userID === 0) {
                throw new \Exception('Invitations Email has insufficient parameters: missing userID.');
            }
        } else {
            $this->user = (new User())->findById($userID);
            $this->user->getAttributes();
            if (!is_object($this->user)) {
                if (!$this->isAPI) {
                    if (!is_null(\Xtra::app()->session->get('authUserID'))) {
                        $this->userID = \Xtra::app()->session->get('authUserID');
                    } else {
                        throw new \Exception(
                            "Invitations Email has insufficient parameters: invalid userID #{$userID}."
                        );
                    }
                }
            } else {
                $this->userID = $userID;
            }
        }

        if (empty($intakeFormID)) {
            throw new \Exception('Invitations Email has insufficient parameters: missing intakeFormID.');
        } else {
            $this->intakeForm = (new Ddq($this->tenantID, $modelParams))->findById($intakeFormID);
            $this->intakeForm->getAttributes();
            if (!is_object($this->intakeForm)) {
                throw new \Exception("Invitations Email: invalid intakeFormID #{$intakeFormID}");
            } else {
                $caseID = $this->intakeForm->get('caseID');
                $this->case = (new Cases($this->tenantID, $modelParams))->findById($this->intakeForm->get('caseID'));
                $this->case->getAttributes();
                if (!is_object($this->case)) {
                    throw new \Exception("Invitations Email: invalid caseID #" . $this->intakeForm->get('caseID'));
                } else {
                    $this->caseID = $caseID;
                }
            }
        }
        $this->setEmType(SysEmail::EMAIL_SEND_DDQ_INVITATION);
        $this->attach(new Logging());
        parent::__construct($this->tenantID, $this->caseID, ['refType' => 'caseID']);
    }

    /**
     * Explicitly clean up created objects
     */
    #[\Override]
    public function __destruct()
    {
        unset($this->intakeForm, $this->user, $this->case, $this->tenantID);
        parent::__destruct();
    }

    /**
     * Initialize variables needed for sending email
     *
     * @return void
     *
     * @throws \Exception
     */
    #[\Override]
    protected function initialize()
    {
        // Set email tokens that can be used for this email type.
        $this->addEmailTokens();
    }

    /**
     * Prepare subject for sending
     *
     * @return void
     *
     * @throws \Exception
     */
    #[\Override]
    public function prepareSubject()
    {
        if (is_array($this->emails) && !empty($this->emails)) {
            /*
             * @var EmailBaseModel $email
             */

            $cnt = 0;
            foreach ($this->emails as &$email) {
                if ($cnt > 0) {
                    // Add CC to Email subject
                    $subject = 'CC: ' . $email->getSubject();
                    $email->setSubject($subject);
                }
                $cnt++;
            }
        }
    }

    /**
     * Prepare body for sending
     *
     * @return void
     *
     * @throws \Exception
     */
    #[\Override]
    public function prepareBody()
    {
        if (is_array($this->emails) && !empty($this->emails)) {
            /*
             * @var EmailBaseModel $email
             */

            $cnt = 0;
            foreach ($this->emails as &$email) {
                if ($cnt > 0) {
                    $body = "Case Number: " . $this->case->get('userCaseNum') . "<br>" . $email->getBody();
                    $body = str_replace('<Password>', 'NOT DISPLAYED', $body);
                    $email->setBody($body);
                    if ($email->isHtml()) {
                        $email->setHtmlBody($email->getHtmlBody());
                    } else {
                        $email->setBody($email->getBody());
                    }
                }
                $cnt++;
            }
        }
    }

    /**
     * Return the case ID when logging these emails instead of the ref (Intake Form) id.
     * This will not be used by this class, as it was only supplied as required by the EmailLoggingInterface.
     *
     * @return integer
     */
    #[\Override]
    public function getRefId()
    {
        return 0; // Not used
    }

    /**
     * Retrieve the multiple ID's associated with email that will be set in email log table (e.g. caseID and tpID)
     *
     * @return array
     */
    #[\Override]
    public function getRefIds()
    {
        return ['caseID' => $this->case->get('id'), 'tpID' => $this->case->get('tpID')];
    }

    /**
     * Return default email subject and body.
     *
     * @return EmailBaseModel
     */
    #[\Override]
    public function getDefaultEmail()
    {
        return $this->intakeForm->getDefaultEmail(SysEmail::EMAIL_SEND_DDQ_INVITATION);
    }

    /**
     * Add replaceable email tokens
     *
     * @return void
     */
    private function addEmailTokens()
    {
        // Build Legacy ID so we can retrieve name from the ddqName table.
        $legacyId = 'L-' . $this->intakeForm->get('caseType') . $this->intakeForm->get('ddqQuestionVer');
        $ddqName  = new DdqName($this->tenantID);
        $ddqName  = $ddqName->selectValue('name', ['clientID' => $this->tenantID, 'legacyID' => $legacyId]);
        $this->addTokenData('ddqName.name', $ddqName);
        $this->addTokenData('CLIENT_NAME', $this->client->get('clientName'));
        $this->addTokenData('ddqTitle', $this->client->get('ddqTitle'));
        $this->addTokenData('ddq.loginEmail', $this->intakeForm->get('loginEmail'));
        $this->addTokenData('ddq.POCname', $this->intakeForm->get('POCname'));
        $this->addTokenData('ddq.name', $this->intakeForm->get('name'));
        $this->addTokenData('Password', $this->intakeForm->get('passWord'));
        $this->addTokenData('ddqName.name', $ddqName);
        $this->addTokenData('DDQ_LINK', $this->intakeForm->getLink());
        $this->addTokenData('cases.region', $this->case->get('region'));
        $this->addTokenData('cases.userCaseNum', $this->case->get('userCaseNum'));
        $this->addTokenData(
            'cases.caseCountry',
            (Geography::getVersionInstance())->getCountryNameTranslated(
                $this->case->get('caseCountry'),
                $this->intakeForm->get('subInLang')
            )
        );
        $this->setLegacyTokens([
            'ddqName.name',
            'CLIENT_NAME',
            'ddqTitle',
            'ddq.loginEmail',
            'ddq.POCname',
            'ddq.name',
            'Password',
            'ddqName.name',
            'DDQ_LINK',
            'cases.region',
            'cases.userCaseNum',
            'cases.caseCountry',
        ]);
    }

    /**
     * Load email for sending
     *
     * @param EmailBaseModel $email  Instance of EmailBaseModel containing data for sending the current email type
     * @param Bool           $fauxCc This email requires a faux CC email be sent along with the main email.
     *
     * @return void
     *
     * @throws \Exception
     */
    #[\Override]
    protected function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        // Always use fauxCc for these emails
        $fauxCc = true;
        if (is_null($email)) {
            $email = $this->getEmail($this->intakeForm->get('subInLang'), $this->intakeForm->get('caseType'));
        }

        $this->setEmailFrom($email);
        $this->addTokenData('users.userEmail', $email->getFrom());
        $this->addTokenData('users.userName', $email->getFromName());
        $this->setLegacyTokens([
            'users.userEmail',
            'users.userName',
        ]);
        // Set recipient
        $recipient = $this->intakeForm->getInviteRecipient();
        if (isset($recipient['name'])) {
            $email->addTo($recipient['email'], $recipient['name']);
        } else {
            $email->addTo($recipient['email']);
        }
        parent::loadEmail($email, $fauxCc);
    }

    /**
     * Set Email From values
     *
     * @param EmailBaseModel $email Instance of the email to update
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function setEmailFrom(&$email)
    {
        /*
         * @var int $requesterID
         */

        $requesterID = $this->case->get('requestor');
        if (!empty($requesterID) && $requesterID !== $this->user->getId()) {
            /*
             * @var $requester \Models\User
             */

            $requester = $this->user->findByAttributes(['userid' => $requesterID]);
            if (is_object($requester)) {
                $userEmail = $requester->get('userEmail');
                if ($this->validEmailPattern($userEmail)) {
                    $email->setFrom($userEmail);
                    if ($userName = $requester->get('userName')) {
                        $email->setFromName($userName);
                    }

                    // If set requestor is always set as a CC address. If the requestor has a BNoutgoingEMail set then
                    // that gets set a CC recipient as well.
                    $email->addCc($userEmail);
                    $email->addCc($requester->get('BNoutgoingEMail'));
                }
            } else {
                throw new \Exception('Invalid case requester ID.');
            }
        } else {
            // There was no requestor set on the case, so we check if the user initiating this action has
            // a BNoutgoingEMail address set and add that as a CC recipient.
            $email->setFrom($this->user->get('userEmail'));
            $email->setFromName($this->user->get('userName'));
            $bnOutgoingEmail = $this->user->get('BNoutgoingEMail');
            if ($this->validEmailPattern($bnOutgoingEmail)) {
                $email->addCc($bnOutgoingEmail);
            }
        }
    }

    /**
     * Attempt to retrieve language code. If not found fall back to parent method finding language code.
     *
     * @return string
     */
    #[\Override]
    protected function getLanguageCode()
    {
        $rtn = parent::getLanguageCode();
        if (($languageCode = $this->intakeForm->get('subInLang')) && !empty($languageCode)) {
            $rtn = $languageCode;
        }
        return $rtn;
    }
}
