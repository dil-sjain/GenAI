<?php
/**
 * This sends an email regarding a case task to the task assignee. (add/update/whatever)
 * this is a GP email case, and is used for all task communications.
 * expects message/info to be crafted where it's being called.
 * this just sets up the email information.
 *
 * @keywords case, task, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;

/**
 * Class CaseTask
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class CaseTask extends CaseEmail
{
    private $email = [];

    /**
     * CaseTask constructor.
     *
     * @param int    $clientID The client id
     * @param int    $caseID   ID of the case
     * @param string $subject  Email subject
     * @param string $msg      Email message
     * @param string $to       Email recipient
     * @param string $cc       Email cc
     */
    public function __construct($clientID, $caseID, $subject, $msg, $to, $cc = '')
    {
        $this->setEmType(SysEmail::EMAIL_CASE_TASK);

        $this->email = [
            'subject' => $subject,
            'body'    => $msg,
            'to'      => $to,
            'cc'      => $cc
        ];

        parent::__construct($clientID, $caseID);
    }

    /**
     * Initialize variables needed for sending email
     *
     * @note: Override parent initialize function to prevent exception.
     *
     * @throws \Exception
     * @return void
     */
    protected function initialize()
    {
    }

    /**
     * Generate a generic default email message. This email is supposed to be created by the caller so something must
     * have gone wrong if this default email is called.
     *
     * @return EmailBaseModel
     */
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('A case task was performed.');

        $body  = 'A case task was performed but there was no message provided.' . "\n\n";
        $body .= 'Please contact support to report this issue.';

        $email->setBody($body);

        return $email;
    }

    /**
     * Load the email for sending
     *
     * @param EmailBaseModel $email  The email object
     * @param bool           $fauxCc Should a fauxCc be sent
     *
     * @return void
     */
    public function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        $email = new EmailBaseModel();

        $email->setSubject($this->email['subject']);
        $email->setBody($this->email['body']);
        $email->addTo($this->email['to']);
        $email->addCc($this->email['cc']);

        parent::loadEmail($email, $fauxCc);
    }

    /**
     * Prepare body for sending
     *
     * @note: Override parent prepare body because it's doing unnecessary work.
     *
     * @throws \Exception
     * @return void
     */
    public function prepareBody()
    {
    }
}
