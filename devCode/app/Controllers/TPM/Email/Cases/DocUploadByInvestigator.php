<?php
/**
 * Format and send Document Uploaded by Investigator notice
 *
 * @keywords intake form, ddq, case, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;

/**
 * Class DocUploadedByInvestigator
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class DocUploadByInvestigator extends CaseChangeNotice
{

    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int $clientID ID client sending email
     * @param int $refID    ID of main object needed to send email
     */
    public function __construct($clientID, $refID)
    {
        $this->setEmType(SysEmail::EMAIL_DOCUPLOAD_BY_INVESTIGATOR);

        parent::__construct($clientID, $refID);
    }

    /**
     * Load email subject and body.
     *
     * @return EmailBaseModel
     */
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('Document Attached - ' . 'Case Number: ' . $this->case->get('userCaseNum'));

        $body  = 'Dear ' . $this->user->get('userName') . ",\n\n";
        $body .= "A new document has been attached to your case:\n";
        $body .= '  Investigation Company: ' . $this->vendor->spName() . "\n";
        $body .= '  Case Number: ' . $this->case->get('userCaseNum') . "\n";
        $body .= '  Case Name: ' . $this->case->get('caseName') . "\n";
        $body .= '  Case Type: ' . $this->case->getCaseScopeName() . "\n";
        $body .= '  Link: ' . $this->buildLinkUrl($this->caseID, $this->getTenantId(), true) . "\n";

        $email->setBody($body);

        return $email;
    }
}
