<?php
/**
 * Base class for handling emails sent out using templates and data from the clientEmails table.
 *
 * @keywords email
 */

namespace Controllers\TPM\Email;

use Controllers\Globals\Email\EmailBase;
use Models\TPM\ClientEmails;
use Models\User;

/**
 * Class ClientEmail
 *
 * NOTE: the extended class requires a leading \.
 * See class_alias in bootstrap/config.php
 *
 * @package Controllers\TPM\Email
 */
#[\AllowDynamicProperties]
abstract class ClientEmail extends \EmailBase
{

    /**
     * Load email template to use
     *
     * @param string $languageCode First language choice for email. If not found defaults to EN_US
     *
     * @throws \Exception Exception can bubble up from call below
     * @return void
     */
    protected function loadEmailTemplate($languageCode = 'EN_US')
    {
        $email = new ClientEmails($this->tenantID);
        $email = $email->findEmail($languageCode, $this->getEmailType());
        if (empty($email)) {
            $email = $this->getDefaultEmailTemplate();
        }

        $this->emailTo = $email->get('EMrecipient');
        $this->subject = $email->get('EMsubject');
        $this->body    = $email->get('EMbody');

        if ($email->get('EMcc') == 'SENDER' && $this->validEmailPattern($email->get('EMcc'))) {
            $sender = (new User())->findByAttributes(['id' => \Xtra::app()->session->get('authUserID')]);
            if (is_object($sender) && $this->validEmailPattern($sender->get('userEmail'))) {
                $this->addCcRecipient($sender->get('userEmail'));
            }
            unset($sender);
        } else {
            $this->addCcRecipient(trim((string) $email->get('EMcc')));
        }

        $this->isHTML = (preg_match('#<br ?/>#i', (string) $this->body) == 1);

        if (isset($this->client) && $this->client !== null && str_contains((string) $this->subject, '{clientProfile.')) {
            $this->subject = $this->replaceTableTokens('clientProfile', $this->client, $this->subject);
            $this->body    = $this->replaceTableTokens('clientProfile', $this->client, $this->body);
        }
        if (isset($this->case) && $this->case !== null && str_contains((string) $this->subject, '{cases.')) {
            $this->subject = $this->replaceTableTokens('cases', $this->case, $this->subject);
            $this->body    = $this->replaceTableTokens('cases', $this->case, $this->body);
        }
        if (isset($this->intakeForm) && $this->intakeForm !== null && str_contains((string) $this->subject, '{ddq.')) {
            $this->subject = $this->replaceTableTokens('ddq', $this->intakeForm, $this->subject);
            $this->body    = $this->replaceTableTokens('ddq', $this->intakeForm, $this->body);
        }
        if (isset($this->thirdPartyProfile) && $this->thirdPartyProfile !== null
            && str_contains((string) $this->subject, '{thirdPartyProfile.')
        ) {
            $this->subject = $this->replaceTableTokens('thirdPartyProfile', $this->thirdPartyProfile, $this->subject);
            $this->body    = $this->replaceTableTokens('thirdPartyProfile', $this->thirdPartyProfile, $this->body);
        }
    }

    /**
     * Token data is loaded in each email type for ClientEmails
     *
     * @return void
     */
    protected function loadTokenData()
    {
        return;
    }
}
