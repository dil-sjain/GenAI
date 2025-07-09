<?php
/**
 *  IntakeFormInviteData
 *
 * @keywords Media, Monitor, GDC, thirdParty, monitor, refine, refinement
 *
 */

namespace Models\TPM\IntakeForms;

use Lib\SettingACL;
use Lib\FeatureACL;
use Controllers\TPM\IntakeForms\InviteMenu\IntakeFormInvite;

/**
 * Code for accessing data related to the IntakeFormInvite From field;
 */
#[\AllowDynamicProperties]
class IntakeFormInviteData
{
    /**
     * Reference to a passed in DB class object
     *
     * @var object
     */
    private $DB;

    /**
     * Client ID
     *
     * @var integer
     */
    private $tenantID = 0;

    /**
     * Name of tenant cid DB
     *
     * @var string
     */
    private $clientDB;

    /**
     * Name of table
     *
     * @var string
     */
    private $tblName =  null;

    
    /**
     * Build instance
     *
     * @param integer $tenantID Tenant ID
     */
    public function __construct($tenantID)
    {
        $app            = \Xtra::app();
        $this->DB       = $app->DB;
        $this->tenantID = (int)$tenantID;
        $this->clientDB = $this->DB->getClientDB($this->tenantID);
        $this->tblName  = "`"  . $this->clientDB . "`.`intakeFormInviteList`";
    }


    /**
     * Populates Fields/Liists with list of optional From addresses and IDs
     *
     * @param integer $clientID clientProfile.id
     *
     * @return array
     */
    public function getFlEmailList($clientID)
    {
        $clientID = (int)$clientID;
        if ($clientID < 1) {
            return false;
        }
        $sql = "SELECT id AS value, name FROM " . $this->tblName
            . "WHERE clientID = :cid";
        $params  = [':cid' => $clientID];
        return $this->DB->fetchObjectRows($sql, $params);
    }


    /**
     * Populates dropdown with list of optional From addresses and IDs
     *
     * @param integer $clientID clientProfile.id
     *
     * @return array
     */
    public function getEmailList($clientID)
    {
        $clientID = (int)$clientID;
        if ($clientID < 1) {
            return false;
        }
        $sql = "SELECT id AS v, name AS t FROM " . $this->tblName
            . "WHERE clientID = :cid";
        $params  = [':cid' => $clientID];
        return $this->DB->fetchAssocRows($sql, $params);
    }


    /**
     * Sets an override for From address in the DDQ record
     *
     * @param integer $emailID intakeFormInviteList.id
     * @param integer $ddqID   ddq.id
     *
     * @return bool
     */
    public function setFromByDDQ($emailID, $ddqID)
    {
        // clean input
        $emailID = (int)$emailID;
        $ddqID   = (int)$ddqID;
        if ($emailID < 1 || $ddqID < 1) {
            return false;
        }

        // verify ddq belongs to client
        $chk1 = "SELECT clientID FROM {$this->tblName} WHERE id = :emailID";
        $chk1Param =  [':emailID' => $emailID];
        $c1 =  $this->DB->fetchValue($chk1, $chk1Param);

        $chk2 = "SELECT clientID FROM ddq WHERE id = :ddqID";
        $chk2Param = [':ddqID' => $ddqID];
        $c2 =  $this->DB->fetchValue($chk2, $chk2Param);

        if ($c1 != $c2 || $c1 != $this->tenantID) {
            return false;
        }

        // update ddq record with new From address ID
        $sql = "UPDATE `" . $this->clientDB . "`.`ddq` SET inviteEmailID = :ieid WHERE id = :ddqid";
        $params = [':ieid' => $emailID, ':ddqid' => $ddqID];

        return $this->DB->query($sql, $params);
    }


    /**
     * Gets an email address based on g_IntakeFormInviteList.id
     *
     * @param integer $clientID clientProfile.id
     * @param integer $emailID  intakeFormInviteList.id
     *
     * @return string
     */
    public function getEmailByID($clientID, $emailID)
    {
        // clean input
        $emailID  = (int)$emailID;
        $clientID = (int)$clientID;
        if ($emailID < 1 || $clientID < 1) {
            return false;
        }

        // retrieve email data by emailID (and clientID)
        $sql = "SELECT name FROM " . $this->tblName
            . "WHERE clientID = :cid "
            . "AND id = :eid "
            . "LIMIT 1";
        $params = [':cid' => $clientID, ':eid' => $emailID];

        $res = $this->DB->fetchValue($sql, $params);

        return $res;
    }


    /**
     * Gets an email address based on g_IntakeFormInviteList.id
     *
     * @param integer $clientID clientProfile.id
     * @param integer $emailID  intakeFormInviteList.id
     *
     * @return object data object used by Fields/Lists
     */
    public function getFlEmailByID($clientID, $emailID)
    {
        // clean input
        $emailID  = (int)$emailID;
        $clientID = (int)$clientID;
        if ($emailID < 1 || $clientID < 1) {
            return false;
        }

        // retrieve email data by emailID (and clientID)
        $sql = "SELECT id, name, description AS descr, '1' AS canDel FROM " . $this->tblName
            . "WHERE clientID = :cid "
            . "AND id = :eid "
            . "LIMIT 1";
        $params = [':cid' => $clientID, ':eid' => $emailID];

        $res = $this->DB->fetchObjectRow($sql, $params);

        return $res;
    }


    /**
     * Fields/Lists assist
     *
     * @param string  $email    intakeFormInviteList.name
     * @param integer $clientID clientProfile.id
     *
     * @return bool
     */
    public function addEmail($email, $clientID)
    {
        $clientID = (int)$clientID;
        $controller = new IntakeFormInvite($clientID);
        if (!$controller->isValidEmail($email)) {
            return false;
        }

        $sql = "INSERT INTO {$this->tblName} SET name = :email , clientID = :cid";
        $params = [':email' => $email, ':cid' => $clientID];

        $this->query($sql, $params);
        return true;
    }


    /**
     * Fields/Lists assist
     *
     * @param integer $emailID  intakeFormInviteList.id
     * @param integer $clientID clientProfile.id
     *
     * @return bool
     */
    public function removeEmail($emailID, $clientID)
    {
        $emailID = (int)$emailID;
        $clientID = (int)$clientID;

        $sql = "DELETE FROM {$this->tblName} WHERE id = :emailID AND clientID = :cid LIMIT 1";
        $params = [':emailID' => $emailID, ':cid' => $clientID];

        $this->DB->query($sql, $params);

        return true;
    }
}
