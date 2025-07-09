<?php
/**
 * Model for the main Fields/Lists data operations for Custom Lists.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;

/**
 * Class CustListsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class CustListsData extends TpmListsData
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
        $this->tbl->csList  = $clientDB .'.customSelectList';
        $this->tbl->cstmFld = $clientDB .'.customField';
        $this->tbl->olQuest = $clientDB .'.onlineQuestions';
    }

    /**
     * Public wrapper to grab all records
     *
     * @return object DB result object
     */
    public function getLists()
    {
        return $this->fetchLists();
    }

    /**
     * Public wrapper to grab a single record
     *
     * @param string $listName customList.name
     *
     * @return object DB result object
     */
    public function getSingleList($listName)
    {
        return $this->fetchLists($listName);
    }

    /**
     * Add a new record
     *
     * @param array $vals Array('name' => customSelectList.name)
     *
     * @return array Return array with result status and error info if applicable.
     */
    public function add($vals)
    {
        $name = $vals['name'];
        $v = $this->validName($name);
        if (!$v['Result']) {
            return $v;
        }
        $sql = "INSERT INTO {$this->tbl->csList} SET clientID = :clientID, listName = :name, name = 'Item 1'";
        $params = [':clientID' => $this->tenantID, ':name' => $name];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $this->LogData->saveLogEntry(53, "name: `$name`");
            $this->LogData->saveLogEntry(56, "list: `$name`, item: `Item 1`");
            return ['Result' => 1];
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
     * @param array $vals    Array('name' => customSelectList.name)
     * @param array $oldVals Array with same keys as $vals, but with the pre-update values.
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function update($vals, $oldVals)
    {
        $newName = $vals['name'];
        $oldName = $oldVals['name'];
        $v = $this->validName($newName, $oldName);
        if (!$v['Result']) {
            return $v;
        }
        $changes = 0;
        $sql = "UPDATE {$this->tbl->csList} SET listName = :new WHERE clientID = :clientID AND listName = :old";
        $params = [':new' => $newName, ':clientID' => $this->tenantID, ':old' => $oldName];
        $csl = $this->DB->query($sql, $params);
        $changes += $csl->rowCount();

        $sql = "UPDATE {$this->tbl->cstmFld} SET listName = :new WHERE clientID = :clientID AND listName = :old";
        $cf = $this->DB->query($sql, $params);
        $changes += $cf->rowCount();

        $genInfo = 'customSelectList,1,' . $newName;
        $oldGenInfo = 'customSelectList,1,' . $oldName;
        $sql = "UPDATE {$this->tbl->olQuest} SET generalInfo = :genInfo "
            ."WHERE clientID = :clientID AND controlType = 'DDLfromDB' AND generalInfo = :oldGenInfo";
        $params = [':genInfo' => $genInfo, ':clientID' => $this->tenantID, ':oldGenInfo' => $oldGenInfo];
        $olq = $this->DB->query($sql, $params);
        $changes += $olq->rowCount();

        if (!$changes) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg'   => $this->txt['data_no_changes_same_value'],
            ];
        }
        // log list name update and result result = 1
        $this->LogData->saveLogEntry(54, "name: `$oldName` => `$newName`");
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Array('name' => customSelectList.name)
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove($vals)
    {
        $name = $vals['name'];
        $v = $this->validName($name, '', true);
        if (!$v['Result']) {
            return $v;
        }
        if (!$this->canDelete($name)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['fl_error_name_in_use'],
            ];
        }
        $sql = "SELECT id, name FROM {$this->tbl->csList} WHERE clientID = :clientID AND listName = :name";
        $params = [':clientID' => $this->tenantID, ':name' => $name];
        $itemRows = $this->DB->fetchObjectRows($sql, $params);
        if (!$itemRows) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['fl_error_name_not_found'],
            ];
        }

        $sql = "DELETE FROM {$this->tbl->csList} WHERE id = :id AND clientID = :clientID AND listName = :name LIMIT 1";
        $params = [':id' => 0, ':clientID' => $this->tenantID, ':name' => $name];
        foreach ($itemRows as $row) {
            $params[':id'] = $row->id;
            $d = $this->DB->query($sql, $params);
            if ($d->rowCount()) {
                $this->LogData->saveLogEntry(58, "list: `$name`, item: `$row->name`");
            }
        }
        $this->LogData->saveLogEntry(55, "name: `$name`");
        return ['Result' => 1];
    }

    /**
     * Grab all records or single record.
     *
     * @param string $listName Optional, customList.name
     *
     * @return object DB result object
     */
    private function fetchLists($listName = '')
    {
        $params = [
            ':tenantID' => (int)$this->tenantID,
        ];
        $where = "WHERE cl.clientID = :tenantID ";

        if (!empty($listName)) {
            $where .= "AND cl.listName = :listName LIMIT 1";
            $params[':listName'] = $listName;
        }
        $sql = "SELECT DISTINCT(cl.listName) AS name,\n"
            ."IF(\n"
                ."(\n"
                    ."SELECT oq.id FROM {$this->tbl->olQuest} AS oq \n"
                    ."WHERE oq.controlType ='DDLfromDB' "
                        ."AND oq.generalInfo = CONCAT('customSelectList,1,',cl.listName) \n"
                        ."LIMIT 1 \n"
                ."), 0, (\n"
                    ."IF((\n"
                        ."SELECT cf.id FROM {$this->tbl->cstmFld} AS cf \n"
                        ."WHERE cf.listName = cl.listName AND cf.clientID = cl.clientID \n"
                        ."LIMIT 1 \n"
                    ."), 0, 1) \n"
                .") \n"
            .") AS canDel \n"
            ."FROM {$this->tbl->csList} AS cl \n"
            .$where ." \n";
        if (!$listName) {
            $sql .= "ORDER BY cl.listName ASC \n";
        }
        $names = $this->DB->fetchObjectRows($sql, $params);
        $i = 0;
        foreach ($names as $n) {
            $names[$i]->id = $i;
            $i++;
        }
        return $names;
    }

    /**
     * Can this record be deleted?
     *
     * @param string $listName customList.name
     * @param object $listRow  object, single result/node from this->fetchLists
     *
     * @return boolean Return true if can delete, else false
     */
    private function canDelete($listName, $listRow = null)
    {
        if (!$listRow) {
            $listRow = $this->fetchLists($listName);
            $listRow = $listRow[0];
        }
        if ($listRow && isset($listRow->canDel) && $listName == $listRow->name) {
            return (($listRow->canDel == 1) ? true : false);
        }
        return false;
    }

    /**
     * Check name matches allowed pattern and length.
     *
     * @param string  $newName  Name to be checked
     * @param string  $oldName  Old name to be checked for dups or matching new name, if applicable.
     * @param boolean $isDelete Set true is validating for a delete request.
     *
     * @return array Return array with result status, and error info if applicable.
     */
    private function validName($newName, $oldName = '', $isDelete = false)
    {
        if (empty($newName)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => $this->txt['error_missing_input'],
            ];
        }
        if ($oldName && $oldName == $newName) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg'   => $this->txt['fl_error_name_already_exists'],
            ];
        }
        if ($oldName && !$this->fetchLists($oldName)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_list'],
                'ErrMsg' => $this->txt['fl_error_update_list_not_exist'],
            ];
        }
        if (!preg_match('/^[0-9a-zA-Z_-]+$/', $newName)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_name_characters'],
            ];
        }
        if (strlen($newName) > 20) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => str_replace('{#}', '20', (string) $this->txt['invalid_MaxLength']),
            ];
        }
        if ($this->fetchLists($newName) && !$isDelete) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg'   => $this->txt['fl_error_name_already_exists'],
            ];
        }
        return ['Result' => 1];
    }
}
