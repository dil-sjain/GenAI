<?php
/**
 * Format and send Intake Form (DDQ) Lockout Notices.
 *
 * @keywords intake form login lockout, ddq, email
 */

namespace Controllers\TPM\Email\IntakeForms\Legacy;

use Controllers\TPM\Email\SystemEmail;
use Lib\Legacy\ConfReader;
use Lib\Traits\EmailHelpers;
use Models\Globals\EmailBaseModel;

/**
 * Class IntakeFormLoginLockout
 */
#[\AllowDynamicProperties]
class IntakeFormLoginLockout extends SystemEmail
{
    use EmailHelpers;
    private $app = null;
    protected $tenantID = null;
    private $lockedDataType = null;
    private $lockedDataValue = null;
    private $adminAlertEmail = null;
    private $sysFromEmail = null;

    /**
     * Initialize class for sending a brute force notice after a failed intake form login.
     *
     * @param int     $tenantID   ID of tenant sending email
     * @param string  $type       either loginid, pw or ip
     * @param string  $value      value for the given type
     * @param boolean $adminAlert If true, route email to email.admin_alert
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $type, $value, private $adminAlert = true)
    {
        $tenantID = (int)$tenantID;
        if (($tenantID <= 0) || empty($type) || !in_array($type, ['loginid', 'pw', 'ip']) || empty($value)) {
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
            $this->adminAlert,
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
        if (empty($this->lockedDataType) || !in_array($this->lockedDataType, ['loginid', 'pw', 'ip'])
            || empty($this->lockedDataValue)
        ) {
            throw new \Exception('Unable to email intake form login lockout notice!');
        }

        $email = new EmailBaseModel();
        switch ($this->lockedDataType) {
            case 'loginid':
                if ($this->adminAlert) {
                    $email->addTo($this->adminAlertEmail);
                    $email->setFrom($this->sysFromEmail);
                    $txtTr = $this->app->trans->codeKeys(
                        [
                        'user_login_id',
                        'ddq_idlock_subject',
                        'ddq_idlock_msg4a',
                        'ddq_idlock_msg4b',
                        'ddq_locked_errorCode',
                        ]
                    );
                    $body = wordwrap((string) \Xtra::normalizeLF(
                        $txtTr['ddq_idlock_msg4a'] . ' {' . $this->lockedDataType . '}.' . "\r\n"
                        . $txtTr['ddq_idlock_msg4b'] . "\r\n"
                        . $txtTr['ddq_locked_errorCode'] . ': 10-9364-' . $this->tenantID . ' ' . "\r\n"
                    ), 75);
                } else {
                    $email->addTo($this->lockedDataValue);
                    $txtTr = $this->app->trans->codeKeys(
                        [
                        'user_login_id',
                        'ddq_idlock_subject',
                        'ddq_idlock_msg1',
                        'ddq_idlock_msg2',
                        'ddq_idlock_msg3a',
                        'ddq_idlock_msg3b',
                        'ddq_locked_errorCode',
                        ]
                    );
                    $body = wordwrap((string) \Xtra::normalizeLF(
                        $txtTr['ddq_idlock_msg1'] . "\n" . $txtTr['ddq_idlock_msg2'] . "\n"
                        . $txtTr['ddq_idlock_msg3a'] . ' http://support.securimate.com '
                        . $txtTr['ddq_idlock_msg3b'] . "\n"
                    ), 75);
                }
                $subject = $txtTr['user_login_id'] . ': {' . $this->lockedDataType . '} '
                . $txtTr['ddq_idlock_subject'];
                break;
            case 'pw':
                $email->addTo($this->adminAlertEmail);
                $txtTr = $this->app->trans->codeKeys(
                    [
                    'ddq_locked_subject1',
                    'ddq_locked_subject2a',
                    'ddq_pwlock_msg1',
                    'ddq_pwlock_msg2',
                    'ddq_locked_errorCode',
                    ]
                );
                $subject = $txtTr['ddq_locked_subject1'] . ': {' . $this->lockedDataType . '} - '
                    . $txtTr['ddq_locked_subject2a'];
                $body = $txtTr['ddq_pwlock_msg1'] . ' ({' . $this->lockedDataType . '}) '
                    . $txtTr['ddq_pwlock_msg2'] . "\r\n" . $txtTr['ddq_locked_errorCode']
                    . ': 10-9364-' . $this->tenantID . ' ' . "\r\n";
                $body = preg_replace("#(?<!\r)\n#si", "\r\n", $body);
                $body = wordwrap((string) $body, 75);
                break;
            case 'ip':
                $email->addTo($this->adminAlertEmail);
                $txtTr = $this->app->trans->codeKeys(
                    [
                    'ddq_locked_subject1',
                    'ddq_locked_subject2b',
                    'ddq_iplock_msg1',
                    'ddq_iplock_msg2',
                    'ddq_locked_errorCode',
                    ]
                );
                $subject = $txtTr['ddq_locked_subject1'] . ': {' . $this->lockedDataType . '} - '
                    . $txtTr['ddq_locked_subject2b'];
                $body = $txtTr['ddq_iplock_msg1'] . ' ({' . $this->lockedDataType . '}) '
                    . $txtTr['ddq_iplock_msg2'] . "\r\n" . $txtTr['ddq_locked_errorCode']
                    . ': 10-9364-' . $this->tenantID . ' ' . "\r\n";
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
