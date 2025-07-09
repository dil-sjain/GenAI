<?php
/**
 * Model for the Note Categories Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class NoteCatsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords note categories, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class NoteCatsData extends TpmListsData
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
        $this->tbl->noteCat  = $clientDB .'.noteCategory';
        $this->tbl->caseNote = $clientDB .'.caseNote';
        $this->tbl->tpNote = $clientDB .'.tpNote';
    }

    /**
     * Public wrapper to grab all records
     *
     * @return object DB object, else error.
     */
    public function getCategories()
    {
        return $this->fetchCats();
    }

    /**
     * Public wrapper to grab a single record by id
     *
     * @param integer $id noteCategory.id
     *
     * @return object DB object
     */
    public function getCategory($id)
    {
        return $this->fetchCats($id);
    }

    /**
     * Get all records, or single record if $id is present
     *
     * @param integer $id noteCategory.id [optional]
     *
     * @return object DB object, else error.
     */
    protected function fetchCats($id = 0)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name, IF(\n"
            ."(\n"
                ."IF((SELECT COUNT(id) FROM {$this->tbl->noteCat} WHERE clientID = :clientID1) < 2, 1, 0) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->caseNote} WHERE clientID = :clientID2 AND noteCatID = a.id) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->tpNote} WHERE clientID = :clientID3 AND noteCatID = a.id) \n"
            .") > 0, 0, 1) as canDel \n"
            ."FROM {$this->tbl->noteCat} AS a WHERE a.clientID = :clientID4 "; // LEAVE trailing space.
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':clientID3' => $this->tenantID,
            ':clientID4' => $this->tenantID,
        ];
        if ($id && $id > 0) {
            $sql .= 'AND a.id = :id LIMIT 1';
            $params[':id'] = $id;
            return $this->DB->fetchObjectRow($sql, $params);
        }
        $sql .= 'ORDER BY a.name ASC';
        return $this->DB->fetchObjectRows($sql, $params);
    }

    /**
     * Add a new record
     *
     * @param array $vals Expects: Array('name' => noteCategory.name)
     *
     * @return array Return array with result status and error info if applicable.
     */
    public function add($vals)
    {
        $validateFuncs = new ValidateFuncs();
        if (empty($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => $this->txt['error_missing_input'],
            ];
        }
        if (!$validateFuncs->checkInputSafety($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($this->nameExists($vals['name'], $this->tbl->noteCat)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "INSERT INTO {$this->tbl->noteCat} SET clientID = :clientID, name = :name";
        $params = [':clientID' => $this->tenantID, ':name' => $vals['name']];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $this->LogData->saveLogEntry(31, "name: `". $vals['name'] ."`");
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
     * @param array $vals    Expects: Array('id' => int, 'name' => noteCategory.name)
     * @param array $oldVals Array with same keys as $vals, but with the pre-update values.
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function update($vals, $oldVals)
    {
        $validateFuncs = new ValidateFuncs();
        if (empty($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => $this->txt['error_missing_input'],
            ];
        }
        if (!$validateFuncs->checkInputSafety($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        $newName = $vals['name'];
        $oldName = $oldVals['name'];
        if ($this->nameExists($newName, $this->tbl->noteCat) || ($newName == $oldName)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }

        $sql = "UPDATE {$this->tbl->noteCat} SET name = :name WHERE id = :id AND clientID = :clientID";
        $params = [':name' => $newName, ':id' => $vals['id'], ':clientID' => $this->tenantID];
        $upd = $this->DB->query($sql, $params);
        if (!$upd->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg'   => $this->txt['data_no_changes_same_value'],
            ];
        }
        // log list name update and result result = 1
        $this->LogData->saveLogEntry(32, "name: `$oldName` => `$newName`");
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array('id' => int, 'name' => noteCategory.name)
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove($vals)
    {
        $id = (int)$vals['id'];
        if ($id <= 0 || !$this->canDelete($id)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => str_replace('{name}', $vals['name'], (string) $this->txt['error_invalid_deletion_msg']),
            ];
        }
        $sql = "DELETE FROM {$this->tbl->noteCat} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(33, "name: `". $vals['name'] ."`");
        return ['Result' => 1];
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id noteCategory.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($id)
    {
        $cat = $this->getCategory($id);
        return ($cat->canDel == 1) ? true : false;
    }
}
