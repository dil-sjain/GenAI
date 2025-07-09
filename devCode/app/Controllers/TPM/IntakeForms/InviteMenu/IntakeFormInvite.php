<?php
/**
 * IntakeFormInvite allows a client to substitute a pre-defined email address for their own adress on DDQ invites
 *
 * this class will be used internally (included into code) AND as an AJAX wrapper for itself
 *
 */

namespace Controllers\TPM\IntakeForms\InviteMenu;

use Lib\Traits\AjaxDispatcher;
use Lib\Validation\Validate;
use Models\TPM\IntakeForms\IntakeFormInviteData;
use Models\LogData;

/**
 * IntakeFormInvite controller
 *
 *       isClientFeatureEnabled($clientID) bool - features wrapper
 *       isValidEmail(?,?) bool - field/list - validation wrapper
 *       getEmailList($clientID) array of emailIDs and "english" names - to populate dropdown
 *       setFromByDDQ($emailID, $ddqID) int - when submitting the form
 *       getEmailByID($clientID, $emailID) string - when sending mail
 *       addEmail($emailString, $clientID) bool - field/List
 *       removeEmail($emailID, $clientID) bool - field/list
 *       auditLog(stringMsg) bool - AuditLog wrapper
 *
 * @keywords intake form, login, ddq, invite, from
 */
#[\AllowDynamicProperties]
class IntakeFormInvite
{
    use AjaxDispatcher;

    /**
     * clientID
     *
     * @var object
     */
    private $clientID = null;


    /**
     * app() instance
     *
     * @var object
     */
    private $app = null;


    /**
     * model instance
     *
     * @var object
     */
    private $m = null;


    /**
     * JSON response template
     *
     * @var object
     */
    private $jsObj = null;


    /**
     * \Xtra::app()->log instance
     *
     * @var object
     */
    private $logger = null;

    /**
     * App Session instance
     *
     * @var object
     */
    protected $session = null;

    /**
     * LogData instance
     *
     * @var bool
     */
    private $logData = false;


    /**
     * Sets IntakeFormInvite
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->app = \Xtra::app();
        $this->clientID = (int)$clientID;
        $this->m = new IntakeFormInviteData($this->clientID);
        $this->session = $this->app->session;
        $this->logger = $this->app->log;
        if (!$this->isClientFeatureEnabled($this->clientID)) {
            __destruct();
            return false; // not allowed to request this class. How did they get this far?
        }
        $this->logData = new LogData(
            $this->clientID,
            $this->app->session->authUserID
        );
    }


    /**
     * Features wrapper
     *
     * @param integer $clientID clientProfile.id
     *
     * @return bool
     */
    public function isClientFeatureEnabled($clientID)
    {
        return ($this->app->ftr->tenantHas(\Feature::TENANT_INTAKE_INVITE_FROM)) ? true : false;
    }


    /**
     * Validation wrapper
     *
     * @param string $email Email address
     *
     * @return bool
     */
    public function isValidEmail($email)
    {
        $tests = new Validate(['name' => [[$email, 'Rules', 'required|valid_email']]]);
        if ($tests->failed) {
            return false;
        }
        return true;
    }




    /**
     * Populates dropdown with list of optional From addresses and IDs
     * AJAX
     *
     * [] =
     *     [0][v] = (int)
     *     [0][t] = (string)
     *
     * @return array
     */
    public function getEmailList()
    {
        return $this->m->getEmailList($this->clientID);
    }


    /**
     * AJAX wrapper
     *
     * @return array
     */
    public function ajaxGetEmailList()
    {
        return $this->getEmailList();
    }




    /**
     * Form handler to populate ddq.inviteEmailID optional email
     *
     * @param integer $emailID intakeFormInviteList.id
     * @param integer $ddqID   ddq.id
     *
     * @return int
     */
    public function setFromByDDQ($emailID, $ddqID)
    {
        return $this->m->setFromByDDQ($emailID, $ddqID);
    }


    /**
     * Form handler to populate ddq.inviteEmailID optional email
     * AJAX wrapper for above method
     *
     * @param integer $emailID intakeFormInviteList.id
     * @param integer $ddqID   ddq.id
     *
     * @return int
     */
    public function ajaxSetFromByDDQ($emailID, $ddqID)
    {
        return $this->setFromByDDQ($emailID, $ddqID);
    }




    /**
     * Gets an email address based on g_IntakeFormInviteList.id
     *
     * @param integer $emailID intakeFormInviteList.id
     *
     * @return string
     */
    public function getEmailByID($emailID)
    {
        return $this->m->getEmailByID($this->clientID, $emailID);
    }




    /**
     * Gets an email address based on g_IntakeFormInviteList.id returns Field/List data object
     *
     * @param integer $emailID intakeFormInviteList.id
     *
     * @return string
     */
    public function getFlEmailByID($emailID)
    {
        return $this->m->getFlEmailByID($this->clientID, $emailID);
    }




    /**
     * Fields/Lists assist
     *
     * @param string $emailString Email address
     *
     * @return bool
     */
    public function addEmail($emailString)
    {
        return $this->m->addEmail($emailString, $this->clientID);
    }




    /**
     * Fields/Lists assist
     *
     * @param integer $emailID intakeFormInviteList.id
     *
     * @return bool
     */
    public function removeEmail($emailID)
    {
        return $this->m->removeEmail($emailID, $this->clientID);
    }




    /**
     * AuditLog wrapper
     *
     * @param integer $eventID userLogEvents.id
     * @param string  $details Content to be used for audit log details
     *
     * @return bool
     */
    public function auditLog($eventID, $details)
    {
        $this->logData->save3pLogEntry($eventID, $details, $tpID = null, $isHtml = false, $cid = null);
    }
}
