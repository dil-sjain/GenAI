<?php
/**
 * Controller for sending Budget Proposed emails
 *
 * @keywords email, case
 */

namespace Controllers\TPM\Email\Cases;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;
use Models\User;

/**
 * Class BudgetProposed
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class BudgetProposed extends CaseEmail
{

    /**
     * BudgetProposed constructor.
     *
     * @param int $clientID The current client ID
     * @param int $caseID   ID of the current case
     */
    public function __construct($clientID, $caseID)
    {
        $this->setEmType(SysEmail::EMAIL_BUDGET_PROPOSED);
        parent::__construct($clientID, $caseID);
    }

    /**
     * Build the default email
     *
     * @return EmailBaseModel
     */
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('Investigation Budget Proposed - Case Number: ' . $this->case->get('userCaseNum'));

        $currentUser = (new User())->findByAttributes(['id' => \Xtra::app()->session->get('authUserID')]);

        $body = 'Dear ' . $this->user->get('userName') . ",\n\n";
        $body .= "A budget has been proposed and REQUIRES approval before "
            . $this->investigator->get('investigatorName') . ' can proceed:' . "\n "
            . '  Investigation Company: ' . $this->investigator->get('investigatorName'). "\n "
            . '  Investigator: ' . $currentUser->get('userName') . "\n"
            . '  Case Number: ' . $this->case->get('userCaseNum') . "\n"
            . '  Case Name: ' . $this->case->get('caseName') . "\n"
            . '  Case Type: ' . $this->case->getCaseScopeName() . "\n"
            . '  Link: ' . $this->buildLinkUrl($this->caseID, $this->tenantID, true) . "\n";

        $email->setBody($body);

        $email->addTo($this->user->get('userEmail'));

        return $email;
    }
}
