<?php
/**
 * Data Model for Architecture page and related functions
 *
 * @keywords architecture, region, department
 */

namespace Models\TPM\Settings\Architecture;

use Lib\DevTools\DevDebug;

#[\AllowDynamicProperties]
class ArchitectureData
{
    /**
     * @var $app object
     */
    private $app = null;

    /**
     * @var $DB object for Database API
     */
    private $DB = null;

    /**
     * @var $DevDebug object
     */
    private $DevDebug = null;

    /**
     * class constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;
        $this->DevDebug = new DevDebug();
    }

    /**
     * Retrieve number of Regions for given client
     *
     * @param integer $clientID clientProfile.id
     *
     * @return integer count of regions
     */
    public function numRegions($clientID)
    {
        $clientID = intval($clientID);
        return $this->DB->fetchValue(
            "SELECT COUNT(*) FROM region "
            . "WHERE clientID = :cid",
            [':cid' => $clientID]
        );
    }

    /**
     * Retrieve number of Departments for given client
     *
     * @param integer $clientID clientProfile.id
     *
     * @return integer count of departments
     */
    public function numDepts($clientID)
    {
        $clientID = intval($clientID);
        return $this->DB->fetchValue(
            "SELECT COUNT(*) FROM department "
            . "WHERE clientID = :cid",
            [':cid' => $clientID]
        );
    }


    /**
     * Retrieve custom region and department labels for given client
     *
     * @param integer $clientID clientProfile.id
     *
     * @return array of labels
     */
    public function regionDeptLabels($clientID)
    {
        return $this->DB->fetchIndexedRow(
            "SELECT regionTitle, departmentTitle FROM "
            . "clientProfile WHERE id=:cid LIMIT 1",
            [':cid' => $clientID]
        );
    }


    /**
     * Update client profile for region department labels
     *
     * @param integer $clientID clientProfile.id
     * @param string  $sql      sql formatted updates
     * @param array   $bindVals bound values for PDO queries
     *
     * @return integer count of updated records
     */
    public function updateClientProfile($clientID, $sql, $bindVals)
    {
        $bindVals[':cid'] = $clientID;
        $q = $this->DB->query(
            "UPDATE clientProfile SET " . substr($sql, 2) . " "
            . "WHERE id=:cid LIMIT 1",
            $bindVals
        );
        return intval($q->rowCount());
    }

    /**
     * Create or update a custom region or department definition
     *
     * @param string  $tbl      'region' or 'department'
     * @param integer $cid      clientProfile.id
     * @param string  $tfName   Name of region or department
     * @param string  $taDesc   Description of region or department
     * @param integer $isActive (optional) 1 or 0
     * @param integer $recid    (optional) recordID to update, or blank to create a new record
     *
     * @return object properties include status messages and various codes
     */
    public function upsertCustomRegDept($tbl, $cid, $tfName, $taDesc, $isActive = 1, $recid = 0)
    {
        $recid    = intval($recid); // Either numbers or a 0 (falsey value)
        $res      = new \stdClass();
        $ttResult = null;
        $logMsg   = null;
        $eventID  = null;

        if (!$recid) {
            $ttResult = 'inserted';
            if ($tbl == 'region') {
                $eventID = 9;
            } else {
                $eventID = 10;
            }
            $sql = "INSERT INTO $tbl (`clientID`, `name`, `description`, `is_active`) "
                . "VALUES(:cid, :tfName, :taDesc, :isActive)";
            $bindVals = [':cid'    => $cid, ':tfName' => $tfName, ':taDesc' => $taDesc, ':isActive' => $isActive];
            $logMsg = "name: `$tfName`, description: `$taDesc`, isActive: `$isActive`";
            $q = $this->DB->query($sql, $bindVals);
            $res->lastId = $this->DB->lastInsertId();
        } else {
            $ttResult = 'updated';
            if ($tbl == 'region') {
                $eventID = 5;
                // update redundant regionName in regionCountryTrans
                $sql = "UPDATE regionCountryTrans SET regionName=:tfName "
                    . "WHERE clientID=:cid AND regionID=:recid";
                $bindArr = [':cid'    => $cid, ':tfName' => $tfName, ':recid'  => $recid];
                $this->DB->query($sql, $bindArr);
            } else {
                $eventID = 6;
            }
            $where = "WHERE id=:recid AND clientID=:cid";
            $bindVals = [':recid' => $recid, ':cid'   => $cid];
            $oldRec = $this->DB->fetchObjectRow("SELECT name, description, is_active FROM $tbl $where", $bindVals);
            $logMsg = "";
            if ($oldRec->name != $tfName) {
                $logMsg .= "name: `$oldRec->name` => `$tfName`";
            }
            if ($oldRec->description != $taDesc) {
                if ($logMsg) {
                    $logMsg .= ', ';
                }
                $logMsg .= "description: `$oldRec->description` => `$taDesc`";
            }
            if ($oldRec->is_active != $isActive) {
                if ($logMsg) {
                    $logMsg .= ', ';
                }
                $logMsg .= "is_active: `$oldRec->is_active` => `$isActive`";
            }
            // now region/dept update
            $sqlLastUpadate = "SELECT COUNT(*) FROM $tbl WHERE clientID = :cid AND is_active = 1";
            $bindValss = [':cid' => $cid];
            $activeCount = $this->DB->fetchValue($sqlLastUpadate, $bindValss);

            /*
             * if active count is less than 2,
             * we should not be able to update the is_active of the last active region/department
             **/

            if ($activeCount < 2 && $oldRec->is_active == 1 && $isActive == 0) {
                $ttResult = 'Last Active Row cannot be deactivated';
                $res->$ttResult;
                $res->logMsg = "You cannot deactivate the last active row in $tbl.\n
                Please activate another row in $tbl before deactivating this one.";
                $res->rowCount = 0;
                $res->eventID = $eventID;
                $res->errorCode = 000000;
                $res->Result = 0;
                $res->ErrTitle = 'Operation Failed';
                $res->ErrMsg = "You cannot deactivate the last active row in $tbl.\n
                Please activate another row in $tbl before deactivating this one.";
                return $res;
            } else {
                $sql = "UPDATE $tbl SET `name`=:tfName, "
                    . "`description`=:taDesc, `is_active`=:isActive $where LIMIT 1";
                $bindVals[':tfName'] = $tfName;
                $bindVals[':taDesc'] = $taDesc;
                $bindVals[':isActive'] = $isActive;

                $q = $this->DB->query($sql, $bindVals);
                $res->lastId = $recid;
            }
        }

        $res->ttResult  = $ttResult;
        $res->logMsg    = $logMsg;
        $res->eventID   = $eventID;
        $res->rowCount  = intval($q->rowCount());
        $res->errorCode = $q->errorCode();

        return $res;
    }

    /**
     * deletes a custom region or department
     *
     * @param string  $tbl   'region' or 'department'
     * @param integer $cid   clientProfile.id
     * @param integer $recid recordID to update, or blank to create a new record
     *
     * @return object properties include status messages and various codes
     */
    public function deleteCustomRegDept($tbl, $cid, $recid)
    {
        $bindArr = [':cid' => $cid, ':recid' => $recid];
        $where = "FROM $tbl WHERE clientID=:cid AND id=:recid LIMIT 1";
        $del_name = $this->DB->fetchValue("SELECT name $where", $bindArr);
        if ($delQ = $this->DB->query("DELETE $where", $bindArr)) {
            $jsObj = new \stdClass();
            if ($delQ->rowCount() > 0) {
                // Remove explicit refs in regionCountryTrans to deleted region
                $eventID = 8; // a department was deleted
                if ($tbl == 'region') {
                    $eventID = 7; // a region was deleted
                    $this->DB->query(
                        "UPDATE regionCountryTrans SET regionID=0, regionName='' "
                        . "WHERE clientID=:cid AND regionID=:recid",
                        $bindArr
                    );
                }
                $jsObj->event = $eventID;
                $jsObj->logMsg = "name: `$del_name`, rec #{$recid}";
            } else {
                $jsObj->Result = 0;
                $jsObj->ErrTitle = 'Operation Failed';
                $jsObj->ErrMsg = 'Deletion attempt failed: (7)<br />';
            }
            return $jsObj;
        }
        return false;
    }
}
