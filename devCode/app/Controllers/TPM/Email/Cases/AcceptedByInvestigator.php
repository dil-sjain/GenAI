<?php
/**
 * Format and send Accepted by Investigator notice
 *
 * @keywords intake form, ddq, case, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;
use Models\User;

/**
 * Class AcceptedByInvestigator
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class AcceptedByInvestigator extends CaseChangeNotice
{
    /**
     * @var User
     */
    protected $investigator = null;

    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int $clientID ID client sending email
     * @param int $refID    ID of main object needed to send email
     */
    public function __construct($clientID, $refID)
    {
        $this->setEmType(SysEmail::EMAIL_ACCEPTED_BY_INVESTIGATOR);

        parent::__construct($clientID, $refID);
    }

    /**
     * Initialize variables needed for sending email
     *
     * @throws \Exception
     * @return void
     */
    public function initialize()
    {
        parent::initialize();

        $this->investigator = new User();
        $this->investigator = $this->investigator->findById(\Xtra::app()->session->get('authUserID'));
        if (!is_object($this->investigator)) {
            throw new \Exception('Unable to find investigator with id: ' . \Xtra::app()->session->get('authUserId'));
        }
    }

    /**
     * Returns an array with the default email values. Each email type must define a default email.
     *
     * @return EmailBaseModel
     */
    protected function getDefaultEmail()
    {
        $email = new EmailBaseModel();
        $email->setSubject('Investigation Accepted - ' . 'Case Number: ' . $this->case->get('userCaseNum'));

        $body  = 'Dear ' . $this->user->get('userName') . ",\n\n";
        $body .= "The following investigation has been Accepted:\n";
        $body .= '  Investigation Company: ' . $this->vendor->spName() . "\n";
        $body .= '  Investigator: ' . $this->investigator->get('userName') . "\n";
        $body .= '  Case Number: ' . $this->case->get('userCaseNum') . "\n";
        $body .= '  Case Name: ' . $this->case->get('caseName') . "\n";
        $body .= '  Case Type: ' . $this->case->getCaseScopeName() . "\n";
        $body .= '  Link: ' . $this->buildLinkUrl($this->caseID, $this->getTenantId(), true) . "\n";
        $email->setBody($body);

        return $email;
    }
}
