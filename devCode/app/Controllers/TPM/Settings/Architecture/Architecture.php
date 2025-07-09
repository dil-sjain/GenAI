<?php
/**
 * Architecture tab (under Settings tab)
 *
 * @keywords architecture, settings, datatable, data, table, region, department
 */

namespace Controllers\TPM\Settings\Architecture;

use Models\TPM\Settings\Architecture\ArchitectureData;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Controllers\Widgets\TableConfig;
use Controllers\Widgets;
use Lib\DevTools\DevDebug;
use Models\LogData;
use Lib\FeatureACL;
use Lib\IO;

/**
 * Architecture tab (under Settings tab)
 */
class Architecture extends Base
{
    use AjaxDispatcher;

    /**
     * Class model
     *
     * @var object $m
     */
    private $m = null;

    /**
     * Class model
     *
     * @var object $DevDebug
     */
    private $DevDebug = null;

    private $LogData = null;

    protected $tpl        = 'Architecture.tpl';
    protected $tplRoot    = 'TPM/Settings/Architecture/';
    protected $app        = null;
    protected $sess       = null;
    protected $clientID   = null;
    protected $userID     = null;
    protected $DB = null; // temporary debug stuff

    /**
     * Constructor
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        $this->clientID    = $clientID = intval($clientID);
        parent::__construct($clientID, $initValues);
        $this->DevDebug    = new DevDebug;
        $this->app         = \Xtra::app();
        $this->DB          = $this->app->DB; // used for its properties, not its methods
        $this->sess        = $this->app->session;
        $this->userID      = $this->sess->get('authUserID');
        $this->m           = new ArchitectureData;
        $this->LogData     = new LogData($clientID, $this->userID, $this->sess->get('authUserType'));
        $this->canAccess   = $this->app->ftr->has(FeatureACL::SETTINGS_ARCHITECTURE);
    }


    /**
     * Initialize
     *
     * @return void
     */
    public function initialize()
    {
        $this->setViewValue('canAccess', false);
        if ($this->canAccess) {
            $this->setViewValue('canAccess', true);
        } else {
            session_write_close();
            header("Location: /tpm/cfg/usrPrfl");
            exit;
        }

        $this->setViewValue('resultMsg', '');

        $this->setViewValue('clientID', $this->clientID);
        $returnPg = $this->sess->get('sysAdminReturnPg');
        $this->setViewValue('sysAdminReturnPg', $returnPg);

        $jsDeptData = '';

        // Get the Number of records for this client regions and departments
        $NumOfRegions = $this->m->numRegions($this->clientID);
        $this->setViewValue('NumOfRegions', $NumOfRegions);

        $NumOfDepts = $this->m->numDepts($this->clientID);
        $this->setViewValue('NumOfDepts', $NumOfDepts);

        // Get client-defined Region and Department terms
        $regionLabel = 'Region';
        $deptLabel = 'Department';
        $regionLabelTextboxInput = $deptLabelTextboxInput = '';
        if (($rdLbls = $this->m->regionDeptLabels($this->clientID)) !== false
        ) {
            $regionLabel = $rdLbls[0];
            $deptLabel = $rdLbls[1];
            if ($regionLabel != 'Region') {
                $regionLabelTextboxInput = $regionLabel;
            }
            if ($deptLabel != 'Department') {
                $deptLabelTextboxInput = $deptLabel;
            }
        }

        if (empty($regionLabel)) {
            $regionLabel = 'Region';
            $regionLabelTextboxInput = '';
        }
        if (empty($deptLabel)) {
            $deptLabel = 'Department';
            $deptLabelTextboxInput = '';
        }

        $this->setViewValue('regionLabel', $regionLabel);
        $this->setViewValue('regionLabelTextboxInput', $regionLabelTextboxInput);

        $this->setViewValue('deptLabel', $deptLabel);
        $this->setViewValue('deptLabelTextboxInput', $deptLabelTextboxInput);

        $this->addFileDependency('/assets/jq/jqx/jqwidgets/jqxwindow.js');

        $colorScheme = $this->getViewValue('colorScheme') ?: 0;
        $this->addFileDependency('/assets/jq/plugin/dt/css/dtyui{$colorScheme}.css');

        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }



    /**
     * Gets Table data for regions or departments
     *
     * @return void
     */
    public function getTable()
    {
        $op = \Xtra::arrayGet($this->app->clean_POST, 'op', '');

        echo IO::jsonEncodeResponse($this->getArchJsData($op, $this->clientID));
    }



    /**
     * Ajax handler for upserting Region/Department custom label
     *
     * @return void
     */
    private function ajaxUpsertRegDept()
    {
        $control      = \Xtra::arrayGet($this->app->clean_POST, 'control', '');
        $recid        = \Xtra::arrayGet($this->app->clean_POST, 'recid', 0);
        $tfName       = \Xtra::arrayGet($this->app->clean_POST, 'tfName', '');
        $taDesc       = \Xtra::arrayGet($this->app->clean_POST, 'taDesc', '');
        $isActive     = \Xtra::arrayGet($this->app->clean_POST, 'isActive', '');
        $logMsg       = '';


        $regex = '/^[-a-z0-9._ \050\051\133\135]+$/i';
        if ($tfName == '') {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = 'Errors found';
            $this->jsObj->ErrMsg = 'Please provide a name.<br />';
            return;
        }
        $tfName = html_entity_decode((string) $tfName, ENT_QUOTES, 'UTF-8');
        if (!preg_match($regex, $tfName)) {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = 'Errors found';
                $this->jsObj->ErrMsg = "Name must consist of one or more valid "
                    . "characters,<br />\nincluding letters (a-z and A-Z), digits "
                    . "(0-9), dash, space,<br />\nunderscore, period, square brackets "
                    . "([]), and parentheses.";
                return;
        }

        if ($taDesc != '') {
            $taDesc = html_entity_decode($taDesc, ENT_QUOTES, 'UTF-8');
            if (!preg_match($regex, $taDesc)) {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = 'Errors found';
                $this->jsObj->ErrMsg = "Description must consist of one or more valid "
                    . "characters,<br />\nincluding letters (a-z and A-Z), digits "
                    . "(0-9), dash, space,<br />\nunderscore, period, square brackets "
                    . "([]), and parentheses.";
                return;
            }
        }

        $res = $this->m->upsertCustomRegDept($control, $this->clientID, $tfName, $taDesc, $isActive, $recid);

        $Args['NumOfRegions'] = $this->m->numRegions($this->clientID);

        $Args['NumOfDepts'] = $this->m->numDepts($this->clientID);

        if ($res->rowCount > 0) {
            if (!$recid) {
                $recid = $res->lastId;
            }
            $logMsg .= ", rec #{$recid}";
            $this->LogData->save3pLogEntry($res->eventID, $logMsg);
            $Args['recid'] = $recid;
        } else {
            if ($res->errorCode === '00000') { // Query was successful, but nothing was changed as a result
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = 'Errors found';
                $this->jsObj->ErrMsg = 'Nothing changed. Record was not ' . $res->ttResult;
                $this->jsObj->Args = [];
                return;
            } else if ($res->errorCode === '000000') {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = 'Operation Failed';
                $this->jsObj->ErrMsg = $res->ErrMsg;
                $this->jsObj->Args = [];
                return;
            } else {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = 'Data Error';
                $this->jsObj->ErrMsg = 'A database error occurred: (5)';
                $this->jsObj->Args = [];
                return;
            }
        }

        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.arch.handleUpsertRegDept';
        $this->jsObj->Args = [$Args];
    }


    /**
     * Ajax handler for updating custom 'Region' or 'Department' label
     *
     * @return void
     */
    private function ajaxRegDeptSubmit()
    {
        $this->jsObj->Result = 0;
        $regName = \Xtra::arrayGet($this->app->clean_POST, 'regName', '');
        $deptName = \Xtra::arrayGet($this->app->clean_POST, 'deptName', '');
        $changeRegion = \Xtra::arrayGet($this->app->clean_POST, 'changeRegion', false);
        $changeDept   = \Xtra::arrayGet($this->app->clean_POST, 'changeDept', false);
        $Args = [];
        $Args['regName'] = '';
        $Args['deptName'] = '';

        // Update client-defiend Region or Department terms
        $resultMsg = '';
        if ($changeRegion || $changeDept) {
            $newRegName = $newDeptName = '';
            if (trim((string) $regName) != '') {
                if (!preg_match('/^[-a-z0-9_ ]{1,15}$/i', (string) $regName)) {
                    $resultMsg .= 'Region title contains invalid characters.<br />';
                } else {
                    $newRegName = $regName;
                }
            }

            if (trim((string) $deptName) != '') {
                if (!preg_match('/^[-a-z0-9_ ]{1,15}$/i', (string) $deptName)) {
                    $resultMsg .= 'Department title contains invalid characters.<br />';
                } else {
                    $newDeptName = $deptName;
                }
            }

            if ($resultMsg) {
                $this->jsObj->ErrTitle = 'Data Error';
                $resultMsg .= "<br />\nCustom Region and Department titles may contain only letter "
                    . "(a-z, A-Z), digit (0-9), dash, space, and underscore characters.";
                $this->jsObj->ErrMsg = $resultMsg;
            }
            $sql = '';
            if ($newRegName) {
                $sql .= ", regionTitle = :newRegName";
                $bindVals[':newRegName'] = $newRegName;
                $Args['regName'] = $newRegName;
            }
            if ($newDeptName) {
                $sql .= ", departmentTitle = :newDeptName";
                $bindVals[':newDeptName'] = $newDeptName;
                $Args['deptName'] = $newDeptName;
            }
            if ($sql) {
                if (($rdLbls = $this->m->regionDeptLabels($this->clientID)) !== false) {
                    $oldRegName = $rdLbls[0];
                    $oldDeptName = $rdLbls[1];
                } else {
                    $oldRegName = 'Region';
                    $oldDeptName = 'Department';
                }
                if ($this->m->updateClientProfile($this->clientID, $sql, $bindVals) > 0) {
                    $this->jsObj->Result = 1; // success
                    if ($newRegName && $oldRegName != $newRegName) {
                        $this->sess->set('customLabels.region', $newRegName);
                        $this->LogData->save3pLogEntry(3, "`$oldRegName` => `$newRegName`");
                    }
                    if ($oldDeptName && $oldDeptName != $newDeptName) {
                        $this->sess->set('customLabels.department', $newDeptName);
                        $this->LogData->save3pLogEntry(4, "`$oldDeptName` => `$newDeptName`");
                    }
                }
            }
        }

        if ($this->jsObj->Result == 1) {
            $this->jsObj->ErrTitle = '';
            $this->jsObj->ErrMsg = '';
        }

        $this->jsObj->FuncName = 'appNS.arch.handleRegDeptSubmit';
        $this->jsObj->Args = [$Args];
    }


    /**
     * Ajax handler for deleting Region/Department custom label based on ID and table name
     *
     * @return void
     */
    private function ajaxDeleteRegDept()
    {

        $tbl  = \Xtra::arrayGet($this->app->clean_POST, 'tbl', '');
        $recid  = intval(\Xtra::arrayGet($this->app->clean_POST, 'recid', 0));

        if ($recid == 0) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = 'Errors found';
            $this->jsObj->ErrMsg = 'Missing record number.<br />';
            return;
        }

        $unused = $this->getArchUnused($tbl, $this->clientID);
        if (!in_array($recid, $unused)) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = 'Operation Not Allowed';
            $this->jsObj->ErrMsg = 'Record is in use. Deletion operation aborted.<br />';
            return;
        }

        $delTask = $this->m->deleteCustomRegDept($tbl, $this->clientID, $recid);

        if (isset($delTask->logMsg)) {
            $this->LogData->save3pLogEntry($delTask->event, $delTask->logMsg);
        } elseif (isset($delTask->Result) && $delTask->Result == 0) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $delTask->ErrTitle;
            $this->jsObj->ErrMsg = $delTask->ErrMsg;
            return;
        }




        $Args = [];
        $Args['tbl'] = $tbl;
        $Args['NumOfRegions'] = $this->m->numRegions($this->clientID);
        $Args['NumOfDepts'] = $this->m->numDepts($this->clientID);
        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.arch.handleDelRec';
        $this->jsObj->Args = [$Args];
    }

    /**
     * Ajax handler for getting Region/Department custom label from ID and Table name
     *
     * @return void
     */
    private function ajaxLookupRegDept()
    {

        $control  = \Xtra::arrayGet($this->app->clean_POST, 'control', '');
        $recid  = \Xtra::arrayGet($this->app->clean_POST, 'recid', '');


        $desc = $this->DB->fetchValue(
            "SELECT description FROM `$control` "
            . "WHERE id=:recid AND clientID = :cid LIMIT 1",
            [':recid' => $recid, ':cid' => $this->clientID]
        );
        if ($desc === false) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = "Data Error";
            $this->jsObj->ErrMsg = "Data lookup error: (4)";
            return;
        }

        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.userprofile.handleUpdateRegionDD';
        $this->jsObj->Args = [$desc];
    }



    /**
     * Returns array of regions and departments that are not in use (eligible for deletion)
     *
     * @param string  $tableName 'region' or 'department'
     * @param integer $clientID  clientID
     *
     * @return void
     */
    private function getArchUnused($tableName, $clientID)
    {
        $clientID = intval($clientID);
        $unused = [];
        if ($tableName == 'region') {
            $mgrFld   = 'mgrRegions';
            $usersFld = 'userRegion';
            $caseFld  = $tpFld = 'region';
        } elseif ($tableName == 'department') {
            $mgrFld   = 'mgrDepartments';
            $usersFld = 'userDept';
            $caseFld  = 'dept';
            $tpFld    = 'department';
        } else {
            // invalid table
            return $unused;
        }
        // Find unused records

        // Get all record id's in temp table
        $tmpTbl = 'tmp' . $tableName;
        $bindCid = [];
        $bindCid[':cid'] = $clientID;


        $sql = "DROP TABLE IF EXISTS $tmpTbl";
        $this->DB->query($sql, []);
        $sql = "CREATE TEMPORARY TABLE $tmpTbl ("
            . "id int NOT NULL default '0', "
            . "PRIMARY KEY (id) )";
        $this->DB->query($sql, []);
        $sql = "INSERT INTO $tmpTbl (id) SELECT id FROM $tableName WHERE clientID=:cid";
        $this->DB->query($sql, $bindCid);

        // Delete all referenced record in users
        // @todo check these DELETE queries, they look incorrect (hkatz 12/16/17)
        $t = $tmpTbl;
        $sql = "DELETE $t "
             . "FROM {$this->DB->authDB}.users AS u "
             . "LEFT JOIN $tmpTbl  ON $t.id = u.{$usersFld} "
             . "WHERE u.clientID = :cid "
             . "AND $t.id = u.{$usersFld}";
        $this->DB->query($sql, $bindCid);

        // Delete all referenced records in case
        $sql = "DELETE $t "
             . "FROM cases AS c "
             . "LEFT JOIN $tmpTbl  ON $t.id = c.{$caseFld} "
             . "WHERE c.clientID = :cid "
             . "AND $t.id = c.{$caseFld}";
        $this->DB->query($sql, $bindCid);

            // Delete all referenced records in thirdPartyProfile
        $sql = "DELETE $t "
             . "FROM thirdPartyProfile AS tp "
             . "LEFT JOIN $tmpTbl  ON $t.id = tp.{$tpFld} "
             . "WHERE tp.clientID = :cid "
             . "AND $t.id = tp.{$tpFld}";
        $this->DB->query($sql, $bindCid);

        // Delete all referenced records in mgr field
        $refs = $this->DB->fetchIndexedRows(
            "SELECT $mgrFld FROM {$this->DB->authDB}.users "
            . "WHERE clientID=:cid AND $mgrFld <> ''",
            $bindCid
        );
        if ($refs && count($refs)) {
            foreach ($refs as $r) {
                $r = str_replace("'", '', stripslashes((string) $r[0]));
                $r = str_replace(' ', '', $r);
                if (trim($r) != '') {
                    $sql = "DELETE FROM $tmpTbl WHERE id IN($r)";
                    $this->DB->query($sql, []);
                }
            }
        }

        // Construct an array from the unused records that are left
        $rows = $this->DB->fetchIndexedRows("SELECT id FROM $tmpTbl WHERE 1 ORDER BY id ASC", []);
        if ($rows && count($rows)) {
            foreach ($rows as $r) {
                $unused[] = $r[0];
            }
        }
        return $unused;
    } // getArchUnused()


    /**
     * Obtain list of regions or departments for use with DataTables on architecture page
     *
     * @param string  $tblName  'region' or 'department'
     * @param integer $clientID clientID
     *
     * @return void
     */
    private function getArchJsData($tblName, $clientID)
    {
        $clientID = intval($clientID);
        $jsData = new \stdClass();
        switch ($tblName) {
            case 'region':
            case 'department':
                break;
            default:
                // invalid table
                return '[]';
        }
        $unused = $this->getArchUnused($tblName, $clientID);
        $rows = $this->DB->fetchObjectRows(
            "SELECT id, name, description, is_active FROM $tblName "
            . "WHERE `clientID`=:cid "
            . "ORDER BY name ASC",
            [':cid' => $clientID]
        );
        if ($rows && count($rows)) {
            $rowcnt = 0;
            $tmpJsData = [];
            foreach ($rows as $row) {
                $nm = str_replace("\"", "\\\"", (string) $row->name);
                $del = in_array($row->id, $unused) ? 1 : 0;

                $localData = new \stdClass();
                $localData->recid = $row->id;
                $localData->name  = $nm;
                $localData->del   = $del;
                $localData->desc  = $row->description;
                $localData->is_act  = $row->is_active ? 'Yes' : 'No';
                $tmpJsData[] = $localData;
                unset($localData);
                $rowcnt++;
            }

            $draw = intval(\Xtra::arrayGet($this->app->clean_POST, 'draw', 0));

            $jsData->draw = ($draw + 1);
            $jsData->recordsTotal = $rowcnt;
            $jsData->recordsFiltered = $rowcnt;
            $jsData->data = $tmpJsData;
        }
        return ($jsData);
    } // getArchJsData

    /**
     *  Input: comma-delimited list or arch records (id) from $tbl
     *  Expects db connection to default db
     *  $tbl must be 'region' or 'department'
     *  Return: comma-delimited list of arch names.
     *
     * @param string  $recList Records List
     * @param string  $tbl     'region' or 'department'
     * @param integer $cid     clientID
     *
     * @return array of region or department data
     */
    private function archListToNames($recList, $tbl, $cid = 0)
    {
        $cid = intval($cid);
        $rtn = '';
        if (!preg_match('/^[0-9,]+$/', $recList)) {
            return $rtn;
        }
        if ($cid) {
            $db = $this->DB->getClientDB($cid);
            $tbl = $db . '.' . $tbl;
        } else {
            $cid = $this->clientID;
        }
        $names = $this->DB->fetchObjectRows(
            "SELECT name FROM $tbl "
            . "WHERE clientID='$cid' "
            . "AND id IN($recList) "
            . "ORDER BY name ASC",
            []
        );
        if ($names === false || (is_array($names) && count($names) == 0)) {
            $names = $this->DB->fetchObjectRows(
                "SELECT name FROM $tbl "
                . "WHERE clientID='0' "
                . "AND id IN($recList) "
                . "ORDER BY name ASC",
                []
            );
        }
        if (is_array($names) && count($names)) {
            $res = [];
            foreach ($names as $n) {
                $res[] = $n->name;
            }
            $rtn = implode(',', $res);
        }
        return $rtn;
    }
}
