<?php
/**
 * Format and send Master Login Lockout Notices.
 *
 * @keywords master, login, lockout, email
 */

namespace Controllers\TPM\Email\Login;

use Controllers\TPM\Email\SystemEmail;
use Lib\Legacy\ConfReader;
use Lib\Traits\EmailHelpers;
use Models\Globals\EmailBaseModel;

/**
 * Class LoginLockout
 */
#[\AllowDynamicProperties]
class MasterLoginLockout extends SystemEmail
{
    use EmailHelpers;
    private $app = null;
    protected $tenantID = null;
    private $lockedDataType = null;
    private $lockedDataValue = null;
    private $adminAlertEmail = null;
    private $sysFromEmail = null;

    /**
     * Initialize class for sending a brute force notice after a failed login.
     *
     * @param int    $tenantID ID of tenant sending email
     * @param string $type     either loginid, pw or ip
     * @param string $value    value for the given type
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $type, $value)
    {
        $tenantID = (int)$tenantID;
        if (($tenantID <= 0) || empty($type) || !in_array($type, ['loginid', 'pw', 'ip', 'totp']) || empty($value)) {
            throw new \Exception('Unable to email intake form login lockout notice!');
        }
        $this->app = \Xtra::app();
        $this->tenantID = $tenantID;
        $this->setEmType('intakeFormLoginLockout');
        $this->lockedDataType = $type;
        $this->lockedDataValue = $value;
        $confReader = new ConfReader();
        $this->adminAlertEmail = $confReader->get('email.admin_alert');
        $this->sysFromEmail = $confReader->get('email.sys_from');
        $this->forceSystemEmail = true;
        $this->updateMailSetting('addHistory', false);
        parent::__construct($tenantID, 0);
    }

    /**
     * Explicitly clean up created objects
     */
    #[\Override]
    public function __destruct()
    {
        unset(
            $this->app,
            $this->tenantID,
            $this->lockedDataType,
            $this->lockedDataValue,
            $this->adminAlertEmail,
            $this->sysFromEmail,
            $this->forceSystemEmail
        );
        parent::__destruct();
    }

    /**
     * Initialize data for email
     *
     * @throws \Exception
     * @return void
     */
    #[\Override]
    protected function initialize()
    {
        switch ($this->lockedDataType) {
            case 'loginid':
            case 'totp':
                $this->addTokenData($this->lockedDataType, $this->fixEmailAddr($this->lockedDataValue));
                break;
            case 'pw':
                $this->addTokenData($this->lockedDataType, substr($this->lockedDataValue, 0, 4) . '...');
                break;
            case 'ip':
                $this->addTokenData($this->lockedDataType, $this->lockedDataValue);
                break;
        }
        $this->setTokens($this->lockedDataType);
    }

    /**
     * Setup a default email for this email type
     *
     * @throws \Exception
     *
     * @return EmailBaseModel
     */
    #[\Override]
    public function getDefaultEmail()
    {
        if (empty($this->lockedDataType) || !in_array($this->lockedDataType, ['loginid', 'pw', 'ip', 'totp'])
            || empty($this->lockedDataValue)
        ) {
            throw new \Exception('Unable to email intake form login lockout notice!');
        }

        $email = new EmailBaseModel();
        switch ($this->lockedDataType) {
            case 'loginid':
                $email->addTo($this->lockedDataValue);
                $email->setFrom($this->sysFromEmail);
                $email->addBcc($this->adminAlertEmail);
                $txtTr = $this->app->trans->codeKeys(
                    [
                    'user_login_id',
                    'login_idlock_subject',
                    'login_idlock_msg1',
                    'ddq_idlock_msg3a',
                    'ddq_idlock_msg3b'
                    ]
                );
                $body = wordwrap((string) \Xtra::normalizeLF(
                    $txtTr['login_idlock_msg1'] . "\n\n" . $txtTr['ddq_idlock_msg3a']
                    . ' https://www.diligent.com/support ' . $txtTr['ddq_idlock_msg3b'] . "\n"
                ), 75);
                $subject = $txtTr['user_login_id'] . ': {' . $this->lockedDataType . '} '
                    . $txtTr['login_idlock_subject'];
                break;
            case 'totp':
                $sql = "SELECT userName FROM {$this->app->DB->authDB}.users WHERE userid = :userid";
                $userData = $this->app->DB->fetchAssocRow($sql, [':userid' => $this->lockedDataValue]);
                if ($userData) {
                    $email->addTo($this->lockedDataValue);
                    $email->setFrom($this->sysFromEmail);
                    $email->addBcc($this->adminAlertEmail);
                    $txtTr = $this->app->trans->codeKeys(
                        [
                        'totp_lock_msg1',
                        'ddq_idlock_msg3b',
                        'forgot_pw_email_hello',
                        'totp_lock_msg3a',
                        'totp_lock_subject'
                        ]
                    );
                    $body = \Xtra::normalizeLF(
                        $txtTr['forgot_pw_email_hello'] . " " . $userData["userName"] . ", \n\n" . $txtTr['totp_lock_msg1'] . "\n\n"
                        . $txtTr['totp_lock_msg3a'] . ' https://www.diligent.com/support ' . $txtTr['ddq_idlock_msg3b'] . "\n\nRegards,\nThe Diligent Team"
                    );
                    $subject = $txtTr['totp_lock_subject'];
                }
                break;
            case 'pw':
                $email->addTo($this->adminAlertEmail);
                $txtTr = $this->app->trans->codeKeys(
                    [
                    'login_locked_subject1',
                    'ddq_locked_subject2a',
                    'login_pwlock_msg1',
                    'ddq_pwlock_msg2',
                    ]
                );
                $subject = $txtTr['login_locked_subject1'] . ': {' . $this->lockedDataType . '} - '
                    . $txtTr['ddq_locked_subject2a'];
                $body = $txtTr['login_pwlock_msg1'] . ' ({' . $this->lockedDataType . '}) '
                    . $txtTr['ddq_pwlock_msg2'] . "\r\n";
                $body = preg_replace("#(?<!\r)\n#si", "\r\n", $body);
                $body = wordwrap((string) $body, 75);
                break;
            case 'ip':
                $email->addTo($this->adminAlertEmail);
                $txtTr = $this->app->trans->codeKeys(
                    [
                    'login_locked_subject1',
                    'ddq_locked_subject2b',
                    'login_iplock_msg1',
                    'ddq_iplock_msg2',
                    ]
                );
                $subject = $txtTr['login_locked_subject1'] . ': {' . $this->lockedDataType . '} - '
                    . $txtTr['ddq_locked_subject2b'];
                $body = $txtTr['login_iplock_msg1'] . ' ({' . $this->lockedDataType . '}) '
                    . $txtTr['ddq_iplock_msg2'] . "\r\n";
                $body = preg_replace("#(?<!\r)\n#si", "\r\n", $body);
                $body = wordwrap((string) $body, 75);
                break;
        }
        $email->setSubject($subject);
        $email->setBody($body);
        return $email;
    }


    /**
     * Prepare email body.
     *
     * @return void
     */
    #[\Override]
    public function prepareBody()
    {
        return;
    }

    /**
     * Prepare the email subject.
     *
     * @return void
     */
    #[\Override]
    public function prepareSubject()
    {
        return;
    }
}
