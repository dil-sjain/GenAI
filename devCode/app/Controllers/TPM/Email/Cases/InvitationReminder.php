<?php
/**
 * Format and send Intake Form (DDQ) Invitation Reminders.
 *
 * @keywords intake form, ddq, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;

/**
 * Class Invitation
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class InvitationReminder extends Invitation
{

    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int $clientID ID client sending email
     * @param int $refID    ID of main object needed to send email
     * @param int $userID   ID of currently logged in user. Will try to retrieve from session if not provided
     * @param int $reminder Number of current reminder being sent (1 - 5)
     */
    public function __construct($clientID, $refID, $userID = 0, $reminder = 1)
    {
        $reminder = 'EMAIL_DDQ_INVITE_REMINDER' . (string)$reminder;
        if (constant('\Lib\Legacy\SysEmail::' . $reminder)) {
            $this->setEmType(constant('\Lib\Legacy\SysEmail::' . $reminder));
        } else {
            $this->setEmType(SysEmail::EMAIL_DDQ_INVITE_REMINDER1);
        }

        parent::__construct($clientID, $refID, $userID);
    }
}
