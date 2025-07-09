<?php
/**
 * Model for the Document Categories Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class DocCatsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords document categories, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class DocCatsData extends TpmListsData
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
        $this->tbl->docCat        = $clientDB .'.docCategory';
        $this->tbl->ddqAttach     = $clientDB .'.ddqAttach';
        $this->tbl->subInfoAttach = $clientDB .'.subInfoAttach';
        $this->tbl->tpAttach      = $clientDB .'.tpAttach';
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
     * @param integer $id docCategory.id
     *
     * @return object DB object, else error.
     */
    public function getCategory($id)
    {
        return $this->fetchCats($id);
    }

    /**
     * Get all records, or single record if $id is present
     *
     * @param integer $id docCategory.id [optional]
     *
     * @return object DB object
     */
    protected function fetchCats($id = 0)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name, a.active AS ckBox, IF(\n"
            ."(\n"
                ."IF((SELECT COUNT(id) FROM {$this->tbl->docCat} WHERE clientID = :clientID1) < 2, 1, 0) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->subInfoAttach} WHERE clientID = :clientID2 AND catID = a.id) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->tpAttach} WHERE clientID = :clientID3 AND catID = a.id) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->ddqAttach} WHERE clientID = :clientID4 AND catID = a.id) \n"
            .") > 0, 0, 1) as canDel \n"
            ."FROM {$this->tbl->docCat} AS a WHERE a.clientID = :clientID5 "; // LEAVE trailing space.
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':clientID3' => $this->tenantID,
            ':clientID4' => $this->tenantID,
            ':clientID5' => $this->tenantID,
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
     * @param array $vals Expects: Array('name' => docCategory.name)
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
        if ($this->nameExists($vals['name'], $this->tbl->docCat)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_category_already_exists'],
            ];
        }
        $sql = "INSERT INTO {$this->tbl->docCat} SET clientID = :clientID, name = :name, active = :active";
        $params = [
            ':clientID' => $this->tenantID,
            ':name' => $vals['name'],
            ':active' => (!empty($vals['ckBox']) ? 1 : 0)
        ];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $this->LogData->saveLogEntry(128, 'name: `'. $vals['name'] .'`, active: `'. $vals['ckBox'] .'`');
            return ['Result' => 1, 'id' => $newID];
        }
        return [
            'Result'   => 0,
            'ErrTitle' => $this->txt['one_error'],
            'ErrMsg'   => $this->txt['fl_error_add_category_failed'],
        ];
    }

    /**
     * Update a record
     *
     * @param array $vals    Expects: Array(
     *                           'id' => docCategory.id, 'name' => docCategory.name, 'ckBox' => docCategory.active
     *                       )
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
        if ($vals['name'] == $oldVals['name'] && $vals['ckBox'] == $oldVals['ckBox']) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg' => $this->txt['data_no_changes_same_value'],
            ];
        } elseif ($vals['name'] != $oldVals['name'] && $this->nameExists($vals['name'], $this->tbl->docCat)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "UPDATE {$this->tbl->docCat} SET "
            ."name = :name, active = :active "
            ."WHERE id = :id AND clientID = :clientID";
        $params = [
            ':name' => $vals['name'],
            ':active' => (!empty($vals['ckBox']) ? 1 : 0),
            ':id' => $vals['id'],
            ':clientID' => $this->tenantID,
        ];

        $upd = $this->DB->query($sql, $params);
        if (!$upd->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg'   => $this->txt['data_no_changes_same_value'],
            ];
        }
        // log list name update and result result = 1
        $logMsg = '';
        if ($vals['name'] != $oldVals['name']) {
            $logMsg .= 'name: `'. $oldVals['name'] .'` => `'. $vals['name'] .'`';
        }
        if ($vals['ckBox'] != $oldVals['ckBox']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') .'status: `';
            if ($vals['ckBox'] == 1) {
                $logMsg .= $this->txt['user_status_inactive'] .'` => `'. $this->txt['user_status_active'] .'`';
            } else {
                $logMsg .= $this->txt['user_status_active'] .'` => `'. $this->txt['user_status_inactive'] .'`';
            }
        }
        $this->LogData->saveLogEntry(129, $logMsg);
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array('id' => docCategory.id, 'name' => docCategory.name)
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
        $sql = "DELETE FROM {$this->tbl->docCat} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(130, "name: `". $vals['name'] ."`");
        return ['Result' => 1];
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id docCategory.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($id)
    {
        $cat = $this->getCategory($id);
        return ($cat->canDel == 1) ? true : false;
    }
}
