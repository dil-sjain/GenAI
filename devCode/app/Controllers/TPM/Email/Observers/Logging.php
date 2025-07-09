<?php
/**
 * Email observer to handling logging email activity
 *
 * @keywords email, logging
 */

namespace Controllers\TPM\Email\Observers;

use Controllers\Globals\Email\EmailBase;
use Lib\Legacy\SysEmail;
use Models\TPM\SystemEmailLog;

/**
 * Class Logging
 *
 * @package Controllers\TPM\Email\Observers
 */
#[\AllowDynamicProperties]
class Logging implements \SplObserver
{
    /**
     * Log email activity
     *
     * @param \SplSubject $subject Class object that is sending the email
     * @param int         $event   Event that fired this log
     *
     * @return void
     */
    public function update(\SplSubject $subject, $event = EmailBase::EVENT_ALL)
    {
        if ($event == EmailBase::EVENT_SEND_SUCCESS) {
            $this->logSentEmail($subject);
        }
    }

    /**
     * Log a successfully sent email
     *
     * @param \SplSubject $email Class handling the sending of an email
     *
     * @throws \Exception
     * @return void
     */
    private function logSentEmail(\SplSubject $email)
    {
        if ($email instanceof EmailLoggingInterface) {
            $includeCaseIdTpIdSubjectAndCc = [
                SysEmail::EMAIL_SEND_DDQ_INVITATION,
                SysEmail::EMAIL_NOTIFY_CLIENT_DDQ_SUBMIT
            ];

            $emailType = $email->getEmailType();
            $clientID  = $email->getTenantId();
            $caseID    = $email->getRefId();

            // Send values to systemEmailLog
            $emailLog = new SystemEmailLog($clientID);

            if (($recipients = $email->getRecipients())
                && is_array($recipients) && !empty($recipients)
            ) {
                $emailLog->set('recipient', implode(', ', $recipients));
            } else {
                return; // No recipients? Get outa here....
            }

            $emailLog->set('sender', $email->getSender());
            $emailLog->set('EMtype', $emailType);
            $emailLog->set('funcID', $caseID);
            $emailLog->set('clientID', $clientID);

            if (in_array($emailType, $includeCaseIdTpIdSubjectAndCc)) {
                $refIDs = $email->getRefIds();
                $caseID = (!empty((int)$refIDs['caseID'])) ? (int)$refIDs['caseID'] : 0;
                $tpID   = (!empty((int)$refIDs['tpID'])) ? (int)$refIDs['tpID'] : 0;
                if (($ccRecipients = $email->getCcRecipients())
                    && is_array($ccRecipients) && !empty($ccRecipients)
                ) {
                    $emailLog->set('cc', implode(', ', $ccRecipients));
                }
                $emailLog->set('funcID', $caseID);
                $emailLog->set('caseID', $caseID);
                $emailLog->set('tpID', $tpID);
                $emailLog->set('subject', $email->getSubject());
            }
            $emailLog->save();
        } else {
            throw new \Exception('Unable to log email transaction. Email type does not adhere to log interface.');
        }
    }
}
