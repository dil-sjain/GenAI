<?php
/**
 * Format and send Intake Form (DDQ) Invitations.
 *
 * @keywords intake form, ddq, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;

/**
 * Class Invitation
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class NotifyPartnerDdqReturned extends Invitation
{
    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int $clientID ID client sending email
     * @param int $refID    ID of main object needed to send email
     * @param int $userID   ID of currently logged in user. Will try to retrieve from session if not provided
     */
    public function __construct($clientID, $refID, $userID = 0)
    {
        $this->setEmType(SysEmail::EMAIL_NOTIFY_PARTNER_DDQ_RETURNED);

        parent::__construct($clientID, $refID, $userID);
    }

    /**
     * Return default email subject and body.
     *
     * @return EmailBaseModel
     */
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('Additional Information required on the questionnaire you completed for <CLIENT_NAME>');


        $body = 'Dear <ddq.POCname>' . "\n\n";
        $body .= 'We have reviewed the questionnaire you submitted to <CLIENT_NAME> and are unable to process your ';
        $body .= 'submission further due to incomplete information. ';
        $body .= 'Please review, update, and resubmit appropriately.' . "\n\n";
        $body .= 'The following information is required in order to complete ';
        $body .= 'the processing of your questionnaire:' . "\n\n";
        $body .= '<cases.rejectDescription>' . "\n\n";
        $body .= 'Please click on <DDQ_LINK> and log in using the email <ddq.loginEmail> and the password this ';
        $body .= 'questionnaire originally utilized.  This will allow you to edit the previously submitted ';
        $body .= 'questionnaire in order to update as requested.  You can submit in the same manner as you did ';
        $body .= 'before and we will review your information accordingly.' . "\n\n\n\n";
        $body .= '<DDQ_LINK>' . "\n\n\n";
        $body .= 'Sincerely,' . "\n\n";
        $body .= '<users.userName>' . "\n";
        $body .= '<users.userEmail>' . "\n";
        $body .= '<CLIENT_NAME>' . "\n";

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
    protected function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        $email = $this->getEmail($this->intakeForm->get('subInLang'), $this->intakeForm->get('caseType'));

        $this->setEmailFrom($email);

        $this->addTokenData('users.userEmail', $email->getFrom());
        $this->addTokenData('users.userName', $email->getFromName());
        $this->setLegacyTokens([
            'users.userEmail',
            'users.userName',
            ]);

        // getInviteRecipients overrides email with POCemail (if valid) by default.
        // SEC-543: only if not notifying on return of 'internal' formClass
        if ($this->intakeForm->get('formClass') == 'internal') {
            $recipient['email'] = $this->intakeForm->get('loginEmail');
        } else {
            $recipient = $this->intakeForm->getInviteRecipient();
        }
        if (isset($recipient['name'])) {
            $email->addTo($recipient['email'], $recipient['name']);
        } else {
            $email->addTo($recipient['email']);
        }

        parent::loadEmail($email, $fauxCc);
    }
}
