<?php
/**
 * Handle Gift Special Event email
 *
 * @keywords gift, email
 */

namespace Controllers\TPM\Email\TP;

use Lib\Legacy\SysEmail;
use Models\Globals\EmailBaseModel;

/**
 * Class GethSpecialEvent
 *
 * @package Controllers\TPM\Email\TP
 */
#[\AllowDynamicProperties]
class GethSpecialEvent extends GethEmail
{
    /**
     * GethSpecialEvent constructor.
     *
     * @param int    $clientID   The client ID
     * @param int    $refID      ID of the Gift we are working with
     * @param string $appDeny    Approve/Deny status
     * @param string $preAppDeny Approve/Deny status
     */
    public function __construct($clientID, $refID, protected $appDeny = 'approved or denied', protected $preAppDeny = 'approved or denied')
    {
        $this->setEmType(SysEmail::EMAIL_GETH_SPECIAL_EVENT);
        parent::__construct($clientID, $refID);
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
            'date',
            'type',
            'action',
            'amount',
            'desc',
            'purpose',
            'appdeny',
            'preappdeny']);
        $this->addTokenData('appdeny', $this->appDeny);
        $this->addTokenData('preapdeny', $this->preAppDeny);
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

        $email->setSubject('A Gift Record that was previously <preappdeny> has been marked as <appdeny>');

        $body = 'Hello <name>,' . PHP_EOL . PHP_EOL;
        $body .= "\t" . 'A Gifts and Entertainment record that was Pending has been modified. ';
        $body .= 'Details are as follows:' . PHP_EOL . PHP_EOL;
        $body .= "\t" . 'Type: <type>' . PHP_EOL;
        $body .= "\t" . 'Date: <date>' . PHP_EOL;
        $body .= "\t" . 'Action: <action>' . PHP_EOL;
        $body .= "\t" . 'Amount: <amount>' . PHP_EOL;
        $body .= "\t" . 'Description: <desc>' . PHP_EOL;
        $body .= "\t" . 'Purpose: <purpose>' . PHP_EOL;

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
        $legacyData = $this->gift->whoTo('msg_Updated', $this->refID);

        foreach ($legacyData as $emailData) {
            $currentEmail = clone $email;

            $currentEmail->addTo($emailData['email']);
            $currentEmail->addToken('name', $emailData['name']);

            array_push($this->emails, $currentEmail);
        }
    }
}
