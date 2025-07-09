<?php
/**
 * Format and send Case Unassigned notice
 *
 * @keywords intake form, ddq, case, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;

/**
 * Class UnassignedNotice
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class UnassignedNotice extends CaseChangeNotice
{
    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int    $clientID ID client sending email
     * @param int    $caseID   ID of main object needed to send email case.id
     * @param string $reason   The reason the case is being rejected/unassigned
     */
    public function __construct($clientID, $caseID, private $reason)
    {
        $this->setEmType(SysEmail::EMAIL_UNASSIGNED_NOTICE);

        parent::__construct($clientID, $caseID);
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

        $email->setSubject('Investigation Declined - ' . 'Case Number: ' . $this->case->get('userCaseNum'));

        $body  = 'Dear ' . $this->user->get('userName') . ",\n\n";
        $body .= "The following investigation has been DECLINED:\n";
        $body .= '  Investigation Company: ' . $this->vendor->spName() . "\n";
        if (is_object($this->caseAssigningPM)) {
            $body .= '  Investigator: ' . $this->caseAssigningPM->get('lastName') . "\n";
        }
        $body .= '  Case Number: ' . $this->case->get('userCaseNum') . "\n";
        $body .= '  Case Name: ' . $this->case->get('caseName') . "\n";
        $body .= '  Case Type: ' . $this->case->getCaseScopeName() . "\n";
        $body .= '  Link: ' . $this->buildLinkUrl($this->caseID, $this->getTenantId(), true) . "\n";
        $body .= "\nReason for Declining:\n" . $this->reason . "\n";

        $email->setBody($body);

        $pmID = $this->case->get('assigningProjectMgrID');
        $inID = $this->case->get('caseInvestigatorUserID');
        if (is_object($this->caseAssigningPM)) {
            $email->addCc($this->caseAssigningPM->get('userEmail'));

            // If PM is not investigator include both in Cc
            if ($pmID != $inID) {
                if (is_object($this->caseInvestigator)) {
                    $email->addCc($this->caseInvestigator->get('userEmail'));
                }
            }
        }


        return $email;
    }
}
