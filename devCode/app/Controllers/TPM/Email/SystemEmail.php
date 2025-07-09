<?php
/**
 * Base class for handling emails sent out using templates and data from the systemEmails table.
 *
 * @keywords email
 */

namespace Controllers\TPM\Email;

use Controllers\Globals\Email\EmailBase;
use Lib\Legacy\IntakeFormTypes;
use Models\Globals\EmailBaseModel;
use Models\TPM\SystemEmails;
use Models\User;
use Models\ThirdPartyManagement\Cases;
use Lib\Legacy\SysEmail;

/**
 * Class SystemEmail
 *
 * NOTE: the extended class requires a leading \.
 * See class_alias in bootstrap/config.php
 * @package Controllers\TPM\Email
 */
#[\AllowDynamicProperties]
abstract class SystemEmail extends EmailBase
{
    /**
     * Load email template to use
     *
     * @param string $languageCode First language choice for email. If not found defaults to EN_US
     * @param int    $caseType     Type of Case
     *
     * @return EmailBaseModel
     *
     * @throws \Exception Exception can bubble up from call below
     */
    #[\Override]
    protected function getEmail($languageCode = 'EN_US', $caseType = IntakeFormTypes::DUE_DILIGENCE_SBI)
    {
        $email = new SystemEmails($this->tenantID);
        $email = $email->findEmail($languageCode, $this->getEmailType(), $caseType);

        if (!empty($email)) {
            // An email was found in the DB, we will be working with it
            $emailBase = new EmailBaseModel();

            $to      = $email->get('EMrecipient');
            $subject = $email->get('EMsubject');
            $body    = $email->get('EMbody');
            $cc      = trim((string) $email->get('EMcc'));

            if (strlen($cc) > 0 && (substr_count($cc, '3pcf:') > 0
                    || substr_count($cc, 'casecf:') > 0
                    || substr_count($cc, 'DDQ:') > 0
                    || substr_count($cc, 'REQUESTER:') > 0
                    || substr_count($cc, '3POWNER:') > 0
                ) && in_array($this->refType, ['caseID'])
            ) {
                if (isset($this->user) && is_object($this->user)) {
                    $userID = $this->user->get('id');
                } else {
                    $userID = (new User())->getDefaultUserForTenant($this->tenantID);
                }

                $cases = new Cases($this->tenantID, ['authUserID' => $userID]);
                $tpID = $cases->getCaseField($this->refID, 'tpID');

                $systemEmails = new SysEmail($this->tenantID);
                $systemEmails->validateAndSetEmailList($this->refID, $tpID, $cc);
            }

            $emailBase->addTo($to);
            if (isset($cc)) {
                $emailBase->addCc($cc);
            }
        } else {
            // No email found in the DB, we will be using the default email
            $emailBase = $this->getDefaultEmail();
            $subject   = $emailBase->getSubject();
            $body      = $emailBase->getBody();
            $cc        = $emailBase->getCc();
        }

        if ($cc == 'SENDER') {
            $sender = (new User())->findByAttributes(['id' => \Xtra::app()->session->get('authUserID')]);
            if (is_object($sender) && $this->validEmailPattern($sender->get('userEmail'))) {
                $cc = $sender->get('userEmail');
            }
            unset($sender);
        }

        $emailBase->setSubject($subject);
        $emailBase->setBody($body);

        if (isset($cc) && !empty($cc)) {
            $emailBase->addCc($cc);
        }

        return $emailBase;
    }

    /**
     * Token data is loaded in each email type for SystemEmail
     *
     * @return void
     */
    #[\Override]
    protected function loadTokenData()
    {
        return;
    }
}
