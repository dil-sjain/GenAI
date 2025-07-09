<?php
/**
 * Parent class for case notices
 *
 * @keywords intake form, ddq, case, email
 */

namespace Controllers\TPM\Email\Cases;

use Models\Globals\EmailBaseModel;
use Models\SP\ServiceProvider;

/**
 * Class CaseChangeNotice
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
abstract class CaseChangeNotice extends CaseEmail
{
    /**
     * @var ServiceProvider
     */
    protected $vendor = null;

    /**
     * Retrieve email to be sent.
     *
     * @param EmailBaseModel $email  Instance of EmailBaseModel containing data for sending the current email type
     * @param Bool           $fauxCc This email requires a faux CC email be sent along with the main email.
     *
     * @throws \Exception
     * @return void
     */
    protected function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        // override fauxCc to always be true for these email types
        $fauxCc = true;

        $email = $this->getEmail($this->getLanguageCode(), $this->getEmailType());
        $email->addTo($this->user->get('userEmail'));
        $cc = $this->user->get('BNincomingEMail');
        if (!empty($cc)) {
            $email->addCc($cc);
        }

        parent::loadEmail($email, $fauxCc);
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

        $this->vendor = new ServiceProvider($this->case->get('caseAssignedAgent'));
    }
}
