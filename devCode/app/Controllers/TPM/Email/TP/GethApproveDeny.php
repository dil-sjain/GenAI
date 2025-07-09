<?php
/**
 * Handle Gift Records approve/deny email
 *
 * @keywords gift, email
 */

namespace Controllers\TPM\Email\TP;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;

/**
 * Class GethNewRecord
 *
 * @package Controllers\TPM\Email\TP
 */
#[\AllowDynamicProperties]
class GethApproveDeny extends GethEmail
{
    /**
     * GethNewRecord constructor.
     *
     * @param int    $tenantID The client ID
     * @param int    $refID    ID of the Gift we are working with
     * @param string $appDeny  Approve/Deny status
     */
    public function __construct($tenantID, $refID, protected $appDeny = 'approved or denied')
    {
        $this->setEmType(SysEmail::EMAIL_GETH_APPROVE_DENY);
        parent::__construct($tenantID, $refID);
    }

    /**
     * Initialize data for email
     *
     * @throws \Exception
     * @return void
     */
    #[\Override]
    public function initialize()
    {
        parent::initialize();

        $this->setLegacyTokens([
            'appdeny',
            'date',
            'type',
            'action',
            'amount',
            'desc',
            'purpose']);
        $this->addTokenData('appdeny', $this->appDeny);
        $this->addTokenData('date', substr((string) $this->gift->get('giftDate'), 0, 10));
        $this->addTokenData('type', $this->gift->get('category'));
        $this->addTokenData('action', $this->gift->get('action'));
        $this->addTokenData('amount', '$' . number_format($this->gift->get('amount')));
        $this->addTokenData('desc', $this->gift->get('description'));
        $this->addTokenData('purpose', $this->gift->get('businessPurpose'));
    }

    /**
     * Setup a default email for this email type
     *
     * @return EmailBaseModel
     */
    #[\Override]
    public function getDefaultEmail()
    {
        $email = new EmailBaseModel();

        $email->setSubject('A Gift Record has been <appdeny>');

        $body  = 'Hello <name>,' . PHP_EOL . PHP_EOL;
        $body .= "\t" . 'A Gifts and Entertainment record has been <appdeny> by an administrator.' . PHP_EOL;

        $email->setBody($body);

        return $email;
    }

    /**
     * Load and configure emails as needed
     *
     * @param EmailBaseModel $email  The email contents
     * @param bool           $fauxCc Is there a fauxCc version being sent?
     *
     * @return void
     */
    #[\Override]
    public function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        $email = $this->getEmail($this->getLanguageCode());

        // Get email data that was originally retrieved in legacy.
        // TODO: refactor this when Gifts are refactored
        /*
        $legacyData = array(
            array(
                'id'    => '',
                'name'  => '',
                'email' => '',
                'subj'  => '',
                'merge' => array('')
            )
        );
        */
        $legacyData = $this->gift->whoTo('msg_ApproveDeny', $this->refID);

        foreach ($legacyData as $emailData) {
            $currentEmail = clone $email;

            $currentEmail->addTo($emailData['email']);
            $currentEmail->addToken('name', $emailData['name']);

            array_push($this->emails, $currentEmail);
        }
    }
}
