<?php
/**
 * SFTPAccess controller
 *
 * @keywords sftp, SFTPAccess, security, access, user settings
 */

namespace Controllers\TPM\Settings\Security\SFTPAccess;

use Models\TPM\Settings\Security\SFTPAccess\SFTPAccessData;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Lib\Crypt\Argon2Encrypt;

/**
 * Class enabling certain users to view their sftp access credentials
 * if they have certain rights.
 *
 */
#[\AllowDynamicProperties]
class SFTPAccess extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/Security/SFTPAccess/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'SFTPAccess.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var array Group of Relevant Translatable Text
     */
    private $trGrp = null;

    /**
     * Sets up SFTP Access control
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app  = \Xtra::app();
        $this->trGrp = $this->app->trans->group('sftp_access');
    }

    /**
     * ajaxInitialize - ajax set initial values for the view
     *
     * @return void
     */
    private function ajaxInitialize()
    {
        $sftpData = new SFTPAccessData();
        $sftpList = $sftpData->listAccess();
        $this->setViewValue('sftpList', $sftpList);
        $initTrTxt = ['not_fnd'  => $this->trGrp['msg_no_record'], 'ancr_ttl' => $this->trGrp['sftp_anchor_title'], 'ancr_txt' => $this->trGrp['sftp_msg_show_credentials'], 'titl_txt' => $this->trGrp['sftp_notice_title'], 'enter_pw' => $this->trGrp['sftp_enter_pw_status'], 'btn_show' => $this->trGrp['sftp_btn_show_creds'], 'btn_cncl' => $this->trGrp['cancel']];
        $this->setViewValue('initTxt', $initTrTxt);

        $inviteTrTxt = ['titl_txt' => $this->trGrp['sftp_notice_title'], 'host'     => $this->trGrp['access_host'], 'port'     => $this->trGrp['access_port'], 'url'      => $this->trGrp['access_url'], 'un'       => $this->trGrp['access_username'], 'pw'       => $this->trGrp['access_password'], 'ok'       => $this->trGrp['ok']];

        $this->setViewValue('inviteTxt', $inviteTrTxt);
        $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());
        $this->jsObj->Args   = ['html' => $html];
        $this->jsObj->Result = 1;
    }

    /**
     * ajaxInitInvitePw - ajax validate the user is allowed to view the credentials. Displays Credentials if valid user
     *
     * @return void
     */
    private function ajaxInitSftpAccessPw()
    {
        $data = [];
        $cleanPW = (string) $this->app->clean_POST['pw'];
        $rowid = $this->app->clean_POST['rowid'];
        $sftpData = new SFTPAccessData();
        $authPW = $sftpData->authUserID();
        $result = 0;
        $errtitle = $errmsg = '';

        if (md5($cleanPW) === $authPW || Argon2Encrypt::argonPasswordVerify($cleanPW, $authPW)) {
            if (!empty($rowid) && is_numeric($rowid)) {
                $creds = $sftpData->getSftpCreds($rowid);
                if (!empty($creds)) {
                    $result = 1;
                    $unamePass = unserialize($creds->encCred);

                    $host = $_ENV['SFTP_Host'] ?? 'sftp.securimate.com';
                    $data = [
                        'locationName' => $creds->locationName,
                        'username'     => $unamePass->username,
                        'password'     => $unamePass->password,
                        'host'         => $host,
                        'url'          => 'https://' . $host,
                        'port'         => $_ENV['SFTP_Port'] ?? '2222',
                    ];
                } else {
                    $errtitle = $this->trGrp['error_record_invalid_title'];
                    $errmsg = $this->trGrp['error_record_invalid_status'];
                }
            } else {
                $errtitle = $this->trGrp['msg_no_record'];
                $errmsg = $this->trGrp['error_rec_not_found_status'];
            }
        } else {
            $errtitle = $this->trGrp['error_incorrect_pw_title'];
            $errmsg = $this->trGrp['error_incorrect_pw_status'];
        }

        $this->jsObj->Result = $result;
        $this->jsObj->Args = [$data];
        if (!$result) {
            $this->jsObj->ErrTitle = $errtitle;
            $this->jsObj->ErrMsg = $errmsg;
        }
    }
}
