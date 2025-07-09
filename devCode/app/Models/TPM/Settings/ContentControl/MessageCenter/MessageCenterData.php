<?php
/**
 * Model: check any messages are available in message center for subscriber
 *
 * @keywords MessageCenterData, message center, data, messages
 */

namespace Models\TPM\Settings\ContentControl\MessageCenter;

use Models\Globals\TxtTr;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class MessageCenterData
{
    /**
     * @var object reference to the app db object
     */
    protected $DB = null;
    /**
     * @var object Reference to global app
     */
    protected $app = null;
    /**
     * @var string Reference to profile table
     */
    protected $tbl = '';


    /**
     * Constructor - initialization
     *
     * @param integer $tenantID Tenant ID
     *
     * @return void
     */
    public function __construct(protected $tenantID)
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->tbl = $this->DB->getClientDB($this->tenantID) .'.clientProfile';
    }

    /**
     * Fetch any MessageCenter Messages
     *
     * @return Array of MessageCenter data
     */
    public function getMessageCenterData()
    {
        $ErrorMsg = $Error = $query = $msgCenterContent = '';

        $query = "SELECT messageCenter FROM $this->tbl WHERE id = :id LIMIT 1";
        $params = [':id' => $this->tenantID];
        try {
            $msgCenterContent = $this->DB->fetchObjectRow($query, $params);
        } catch (\PDOException) {
            $trMsgCtr = $this->translateMessageCenterStatus();
            $msg = $trMsgCtr['msgctr_ajax_Read_Fail'];
            $msg.= $trMsgCtr['tryagain'];
            $Error = $trMsgCtr['opffailed'];
            $ErrorMsg = $msg;
        }

        return ['msgCenterContent' => $msgCenterContent->messageCenter, 'ErrorMsg' => $ErrorMsg, 'Error' => $Error];
    }

    /**
     * Change Message center data
     *
     * @param string $sanitizedHtml the sanitized html content of the message center
     *
     * @return Array of message center data
     */
    public function changeMessageCenterData($sanitizedHtml)
    {
        $ErrorMsg = $Error = $query = $msg = '';

        $query = "UPDATE $this->tbl SET messageCenter = :sanitizedHTML WHERE id = :id LIMIT 1";
        $params = [':sanitizedHTML' => $sanitizedHtml, ':id' => $this->tenantID];

        $trMsgCtr = $this->translateMessageCenterStatus();

        try {
            $msgCenterContent = $this->DB->query($query, $params);
            $count = (int) $msgCenterContent->rowCount();
            $msg = ($count > 0) ? $trMsgCtr['msgctr_ajax_Success'] : $trMsgCtr['msgctr_ajax_Nochange'];
        } catch (\PDOException) {
            $msg = $trMsgCtr['msgctr_ajax_Update_Fail'];
            $msg.= $trMsgCtr['tryagain'];
            $Error = $trMsgCtr['opffailed'];
            $ErrorMsg = $msg;
        }

        return ['msg' => $msg, 'html' => $sanitizedHtml, 'ErrorMsg' => $ErrorMsg, 'Error' => $Error];
    }

    /**
     * Retrieves pertinent message center error and success codes for translation
     *
     * @return Array full of translatable status codes
     */
    private function translateMessageCenterStatus()
    {
        $return = $this->app->trans->group('msgctr_ajax');
        $return['tryagain'] = $this->app->trans->codeKey('status_Tryagain');
        $return['opfailed'] = $this->app->trans->codeKey('title_operation_failed');
        return $return;
    }
}
