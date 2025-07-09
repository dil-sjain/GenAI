<?php
/**
 * Model for the main Fields/Lists data operations for Custom List Items.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class CustListItemsData handles basic data modeling for fields/lists custom list items.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords tpm, fields lists, model, settings, custom lists, custom list items
 */
#[\AllowDynamicProperties]
class CustListItemsData extends TpmListsData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    protected $DB = null;

    /**
     * @var integer The current tenantID
     */
    protected $tenantID = 0;

    /**
     * @var integer The current userID
     */
    protected $userID = 0;

    /**
     * @var object Tables used by the model.
     */
    protected $tbl = null;

    /**
     * @var array Translation text array
     */
    protected $txt = [];


    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $userID     Current userID
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $userID, $initValues = [])
    {
        parent::__construct($tenantID, $userID, $initValues);
    }

    /**
     * Setup required table names in the tbl object. This overwrites parent stub method.
     *
     * @return void
     */
    #[\Override]
    protected function setupTableNames()
    {
        $clientDB  = $this->DB->getClientDB($this->tenantID);
        $this->tbl->csList   = $clientDB .'.customSelectList';
        $this->tbl->cstmFld  = $clientDB .'.customField';
        $this->tbl->cstmData = $clientDB .'.customData';
        $this->tbl->olQuest  = $clientDB .'.onlineQuestions';
    }

    /**
     * Public wrapper to get all records of specified parent
     *
     * @param string $list customSelectList.listName
     *
     * @return object DB result object
     */
    public function getListItems($list)
    {
        return $this->fetchListItems($list);
    }

    /**
     * Get all records for specified parent, or a single record if $id is present.
     *
     * @param string  $list   customSelectList.listName (required)
     * @param integer $itemID customSelectList.id (optional)
     *
     * @throws exception Throws exception if neither parameter is passed
     *
     * @return object DB result object
     */
    protected function fetchListItems($list, $itemID = 0)
    {
        $itemID = ((int)$itemID <= 0) ? 0 : $itemID;

        $params = [
            ':tenantID'  => $this->tenantID,
            ':listName2' => $list,
        ];
        $where = 'cl.listName = :listName2';
        if (!empty($itemID)) {
            $where = 'cl.id = :itemID AND cl.listName = :listName2';
            $params[':itemID'] = $itemID;
        }

        $sql = "SELECT cl.id, cl.name, cl.sequence AS numFld, 0 AS canDel, cl.active as ckBox \n"
            . "FROM {$this->tbl->csList} AS cl \n"
            . "WHERE $where AND cl.clientID = :tenantID \n"
            . "ORDER BY cl.active DESC, cl.sequence ASC, cl.name ASC";

        if (!empty($itemID)) {
            $rtn = $this->DB->fetchObjectRow($sql, $params);
        } else {
            $rtn = $this->DB->fetchObjectRows($sql, $params);
        }
        $cntRtn = is_array($rtn) ? count($rtn) : (is_object($rtn) ? 1 : 0);
        $params = [
            ':tenantID'  => $this->tenantID,
        ];
        for ($i=0; $i < $cntRtn; $i++) {
            $canDel = 1;
            $clid = is_array($rtn) ? $rtn[$i]->id : $rtn->id;
            $p1 = $s1 = $p2 = $s2 = $p3 = $s3 = $p4 = $s4 = null;

            $p1 = array_merge($params, [':clid' => $clid]);
            $s1 = "SELECT cd.id FROM {$this->tbl->cstmData} AS cd \n"
                ."WHERE cd.listItemID = :clid AND cd.clientID = :tenantID LIMIT 1";

            $p2 = array_merge($params, [':clid' => $clid, ':clid2' => $clid]);
            $s2 = "SELECT cd2.id FROM {$this->tbl->cstmData} AS cd2 \n"
                ."WHERE cd2.listItemID = :clid "
                ."AND CONCAT(',',cd2.value,',') LIKE(CONCAT('%,',:clid2,',%')) AND cd2.clientID = :tenantID \n"
                ."LIMIT 1";

            $p3 = array_merge($params, [':listName1' => $list]);
            $s3 = "SELECT oq.id FROM {$this->tbl->olQuest} AS oq \n"
                ."WHERE oq.controlType = 'DDLfromDB' "
                ."AND oq.clientID = :tenantID "
                ."AND oq.generalInfo = CONCAT('customSelectList,1,',:listName1) LIMIT 1";

            $p4 = array_merge($params, [':clid' => $clid]);
            $s4 = "SELECT cd.id FROM {$this->tbl->cstmData} AS cd \n"
                ."WHERE (cd.tpID != 0 OR cd.caseID !=0) AND cd.listItemID = :clid AND cd.clientID = :tenantID LIMIT 1";

            if ($this->DB->fetchValue($s1, $p1) > 0) {
                $canDel = 0;
            } elseif ($this->DB->fetchValue($s2, $p2) > 0) {
                $canDel = 0;
            } elseif ($this->DB->fetchValue($s3, $p3) > 0) {
                $canDel = 0;
            } elseif ($this->DB->fetchValue($s4, $p4) > 0) {
                $canDel = 0;
            }
            if (is_array($rtn)) {
                $rtn[$i]->canDel = $canDel;
            } else {
                $rtn->canDel = $canDel;
            }
        }

        return $rtn;
    }

    /**
     * Can this record be deleted?
     *
     * @param string  $list   customSelectList.listName
     * @param integer $itemID customSelectList.id
     *
     * @return boolean Return true if item can be deleted, else false.
     */
    public function canDelete($list, $itemID)
    {
        if (!empty($itemID)) {
            $item = $this->fetchListItems($list, $itemID);
            if ($item && $item->canDel == 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add a new record
     *
     * @param array $vals Array(
     *                      'name'    => customSelectList.name,
     *                      'ckBox'   => customSelectList.active,
     *                      'subList' => ['val' => `sub-selected list value`, 'text' => `sub-selected list text`],
     *                      'numFld'  => customSelectList.sequence
     *                    )
     *
     * @return array Return array with result status and error info if applicable.
     */
    public function add($vals)
    {
        $name = $vals['name'];
        $list = $vals['subList']['text'];
        $seq  = (int)$vals['numFld'];
        $v = $this->validInput($name, $list);
        if (!$v['Result']) {
            return $v;
        }
        $sql = "INSERT INTO {$this->tbl->csList} \n"
            . "SET clientID = :clientID, name = :name, listName = :listName, sequence = :seq, active = :active ";
        $params = [
            ':clientID' => $this->tenantID,
            ':name' => $name,
            ':listName' => $list,
            ':seq' => $seq,
            ':active' => (!empty($vals['ckBox']) ? 1 : 0)
        ];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $this->LogData->saveLogEntry(56, "list: `$list`, item: `$name`, active: `" . $vals['ckBox'] . "`");
            return ['Result' => 1, 'id' => $newID];
        }
        return [
            'Result'   => 0,
            'ErrTitle' => $this->txt['one_error'],
            'ErrMsg'   => $this->txt['fl_error_add_name_failed'],
        ];
    }

    /**
     * Update a record
     *
     * @param array $vals    Array(
     *                         'id'      => customSelectList.id
     *                         'name'    => customSelectList.name,
     *                         'ckBox'   => customSelectList.active,
     *                         'subList' => ['val' => `sub-selected list value`, 'text' => `sub-selected list text`],
     *                         'numFld'  => customSelectList.sequence
     *                       )
     * @param array $oldVals Array with same keys as $vals, but with the pre-update values.
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function update($vals, $oldVals)
    {
        if ($vals['name'] == $oldVals['name'] && $vals['numFld'] == $oldVals['numFld'] && $vals['ckBox'] == $oldVals['ckBox']) {
            return ['Result' => 1]; // nothing changed.
        }
        if ($vals['name'] != $oldVals['name']) {
            $v = $this->validInput($vals['name'], $vals['subList']['text']);
            if (!$v['Result']) {
                return $v;
            }
        }
        if ($this->ensureBaselineRecs($vals['subList']['text']) === false && !$vals['ckBox']) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => 'Must have at least 1 active record.',
            ];
        }
        $sql = "UPDATE {$this->tbl->csList} SET name = :name, sequence = :seq, active = :active "
            . "WHERE id = :id AND clientID = :clientID AND listName = :list";
        $params = [
            ':name'     => $vals['name'],
            ':seq'      => $vals['numFld'],
            ':active'   => (!empty($vals['ckBox']) ? 1 : 0),
            ':id'       => $vals['id'],
            ':clientID' => $this->tenantID,
            ':list'     => $vals['subList']['text'],
        ];
        $upd = $this->DB->query($sql, $params);
        if (!$upd) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['unexpected_error'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        } elseif ($upd->rowCount()) {
            $logMsg = 'list: `' . $vals['subList']['text'] . '`';
            $logMsg .= ($vals['name'] != $oldVals['name']) ?
                ', name: `' . $oldVals['name'] . '` => `' . $vals['name'] . '`' :
                ', name: `' . $vals['name'] . '`';

            $logMsg .= ($vals['numFld'] != $oldVals['numFld']) ?
                ', sequence: `' . $oldVals['numFld'] . '` => `' . $vals['numFld'] . '`' :
                ', sequence: `' . $vals['numFld'] . '`';

            if ($vals['ckBox'] != $oldVals['ckBox']) {
                $logMsg .= (!empty($logMsg) ? ', ' : '') . 'status: `';
                if ($vals['ckBox'] == 1) {
                    $logMsg .= $this->txt['user_status_inactive'] . '` => `' . $this->txt['user_status_active'] . '`';
                } else {
                    $logMsg .= $this->txt['user_status_active'] . '` => `' . $this->txt['user_status_inactive'] . '`';
                }
            }
            $this->LogData->saveLogEntry(57, $logMsg);
            return ['Result' => 1];
        }
        return [
            'Result'   => 0,
            'ErrTitle' => $this->txt['data_nothing_changed_title'],
            'ErrMsg'   => $this->txt['data_no_changes_same_value'],
        ];
    }

    /**
     * Remove a record
     *
     * @param array $vals Array(
     *                      'id'      => customSelectList.id
     *                      'name'    => customSelectList.name,
     *                      'subList' => ['val' => `sub-selected list value`, 'text' => `sub-selected list text`],
     *                    )
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove($vals)
    {
        if (!$vals['id'] || !$this->canDelete($vals['subList']['text'], $vals['id'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_deletion_title'],
                'ErrMsg'   => str_replace('{name}', $vals['name'], (string) $this->txt['error_invalid_deletion_msg']),
            ];
        }

        $sql = "DELETE FROM {$this->tbl->csList} WHERE id = :id AND clientID = :clientID AND listName = :name LIMIT 1";
        $params = [':id' => $vals['id'], ':clientID' => $this->tenantID, ':name' => $vals['subList']['text']];
        $d = $this->DB->query($sql, $params);
        if ($d->rowCount()) {
            $this->LogData->saveLogEntry(58, 'list: `'. $vals['subList']['text'] .'`, item: `'. $vals['name'] .'`');
            $sql = "SELECT COUNT(*) FROM {$this->tbl->csList} WHERE clientID = :clientID AND listName = :list";
            $params = [':clientID' => $this->tenantID, ':list' => $vals['subList']['text']];
            if ($this->DB->fetchValue($sql, $params) == 0) {
                // log removal of list
                $this->LogData->saveLogEntry(55, 'name: `'. $vals['name'] .'`');
            }
            return ['Result' => 1];
        }
        return [
            'Result'   => 0,
            'ErrTitle' => $this->txt['one_error'],
            'ErrMsg'   => str_replace('{name}', $vals['name'], (string) $this->txt['error_invalid_deletion_msg']),
        ];
    }

    /**
     * Check name is ok to add
     *
     * @param string $name customSelectList.name
     * @param string $list customSelectList.listName
     *
     * @return array Return array with result status, and error info if applicable.
     */
    private function validInput($name, $list)
    {
        $validateFuncs = new ValidateFuncs();
        if (empty($name)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_list'],
                'ErrMsg'   => $this->txt['invalid_NotEmpty'],
            ];
        }
        if (!$validateFuncs->checkInputSafety($name)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe content, such as HTML tags or JavaScript.',
            ];
        }

        $sql = "SELECT \n"
            ."(SELECT COUNT(listName) FROM {$this->tbl->csList} "
                ."WHERE clientID = :clientID1 AND listName = :listName1 LIMIT 1"
            .") AS numList, \n"
            ."(SELECT COUNT(id) FROM {$this->tbl->csList} "
                ."WHERE clientID = :clientID2 AND listName = :listName2 AND name = :name1"
            .") AS numName \n"
            ."FROM {$this->tbl->csList} WHERE clientID = :clientID3";
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':clientID3' => $this->tenantID,
            ':listName1' => $list,
            ':listName2' => $list,
            ':name1' => $name,
        ];
        $counts = $this->DB->fetchObjectRow($sql, $params);
        if (!$counts->numList) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_list'],
                'ErrMsg'   => str_replace('{listName}', $list, (string) $this->txt['fl_error_list_not_exist']),
            ];
        }
        if ($counts->numName) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg'   => $this->txt['fl_error_name_already_exists'],
            ];
        }
        if (str_contains($name, ':=:')) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg'   => $this->txt['fl_error_invalid_csliName_desc'],
            ];
        }
        return ['Result' => 1];
    }

    /**
     * Make sure they have at least 1 active record
     *
     * @param string $listName customSelectList.listName
     *
     * @return boolean Return true if they have at least 1 active record, else false.
     */
    private function ensureBaselineRecs($listName)
    {
        $sql = "SELECT COUNT(id) FROM {$this->tbl->csList} \n"
            . "WHERE clientID = :tenantID AND active = :active AND listName = :listName";
        $params = [
            ':tenantID' => $this->tenantID,
            ':listName' => $this->DB->esc($listName),
            ':active'   => 1,
        ];
        return $this->DB->fetchValue($sql, $params) < 1 ? false : true;
    }
}
