<?php
/**
 * Model for the Training Types Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class TpTrngTypesData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords training, training types, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class TpTrngTypesData extends TpmListsData
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
        $this->tbl->trnType = $clientDB .'.trainingType';
        $this->tbl->trn     = $clientDB .'.training';
    }

    /**
     * Public wrapper to grab all records
     *
     * @return object DB object, else error. (object->Result will be set and have a 0 val on error)
     */
    public function getTypes()
    {
        return $this->fetchTypes();
    }

    /**
     * Public wrapper to grab a single record by ID
     *
     * @param integer $id trainingType.id
     *
     * @return object DB object, else error. (object->Result will be set and have a 0 val on error)
     */
    public function getType($id)
    {
        return $this->fetchTypes($id);
    }

    /**
     * Get all records, or single record if $id is present
     *
     * @param integer $id trainingType.id [optional]
     *
     * @return object DB object
     */
    protected function fetchTypes($id = 0)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name, a.description AS descr, IF(\n"
                ."(SELECT COUNT(id) FROM {$this->tbl->trn} WHERE clientID = :clientID1 AND trainingType = a.id) \n"
            ."> 0, 0, 1) as canDel, a.active AS ckBox \n"
            ."FROM {$this->tbl->trnType} AS a WHERE a.clientID = :clientID2 "; // LEAVE trailing space.
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
        ];
        if (!empty($id)) {
            $sql .= 'AND a.id = :id LIMIT 1';
            $params[':id'] = $id;
            return $this->DB->fetchObjectRow($sql, $params);
        }
        $sql .= 'ORDER BY a.active DESC, a.name ASC';
        return $this->DB->fetchObjectRows($sql, $params);
    }

    /**
     * Add a new record
     *
     * @param array $vals Expects: Array(
     *                              'name' => trainingType.name,
     *                              'descr' => trainingType.description
     *                              'ckBox' => trainingType.active
     *                          )
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
        //check for alphanumeric name with spaces, dashes, and underscores
        if (!$validateFuncs->checkInputSafety($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($this->nameExists($vals['name'], $this->tbl->trnType)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        if (!empty($vals['descr']) && !$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => 'Invalid Description',
                'ErrMsg' => 'Description contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        $sql = "INSERT INTO {$this->tbl->trnType} "
            . "SET clientID = :clientID, name = :name, "
            . "description = :descr, active = :active ";
        $params = [
            ':clientID' => $this->tenantID,
            ':name' => $vals['name'],
            ':descr' => $vals['descr'],
            ':active' => (!empty($vals['ckBox']) ? 1 : 0),
        ];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $logMsg = 'name: `' . $vals['name']
                . '`' . (!empty($vals['descr']) ? ', descr: `' . $vals['descr'] . '`':'')
                . '`, active: `' . $vals['ckBox'] . '`';
            $this->LogData->saveLogEntry(35, $logMsg);
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
     * @param array $vals    Expects: Array(
     *                         'id' => trainingType.id,
     *                         'name' => trainingType.name,
     *                         'descr' => trainingType.description,
     *                         'ckBox' => trainingType.active
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
        //check for alphanumeric name with spaces, dashes, and underscores
        if (!$validateFuncs->checkInputSafety($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($vals['name'] == $oldVals['name'] && $vals['descr'] == $oldVals['descr'] && $vals['ckBox'] == $oldVals['ckBox']) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg' => $this->txt['data_no_changes_same_value'],
            ];
        } elseif ($vals['name'] != $oldVals['name'] && $this->nameExists($vals['name'], $this->tbl->trnType)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        if (!empty($vals['descr']) && !$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => 'Invalid Description',
                'ErrMsg' => 'Description contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($this->ensureBaselineRecs() === false && !$vals['ckBox']) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => 'Must have at least 1 active record.',
            ];
        }
        $sql = "UPDATE {$this->tbl->trnType} SET "
            . "name = :name, description = :descr, active = :active "
            . "WHERE id = :id AND clientID = :clientID ";
        $params = [
            ':name' => $vals['name'],
            ':descr' => $vals['descr'],
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
        // log update and return result = 1
        $logMsg = '';
        if ($vals['name'] != $oldVals['name']) {
            $logMsg .= 'name: `' . $oldVals['name'] . '` => `' . $vals['name'] . '`';
        }
        if ($vals['descr'] != $oldVals['descr']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') . 'descr: `' . $oldVals['descr'] . '` => `' . $vals['descr'] . '`';
        }
        if ($vals['ckBox'] != $oldVals['ckBox']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') . 'status: `';
            if ($vals['ckBox'] == 1) {
                $logMsg .= $this->txt['user_status_inactive'] . '` => `' . $this->txt['user_status_active'] . '`';
            } else {
                $logMsg .= $this->txt['user_status_active'] . '` => `' . $this->txt['user_status_inactive'] . '`';
            }
        }
        $this->LogData->saveLogEntry(36, $logMsg);
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array('id' => trainingType.id, 'name' => trainingType.name)
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
        $sql = "DELETE FROM {$this->tbl->trnType} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1 ";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(37, "name: `" . $vals['name'] . "`");
        return ['Result' => 1];
    }

    /**
     * Can this be deleted?
     *
     * @param integer $id trainingType.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($id)
    {
        $cat = $this->getType($id);
        return ($cat->canDel == 1) ? true : false;
    }

    /**
     * Make sure they have at least 1 active record
     *
     * @return boolean Return true if they have at least 1 active record, else false.
     */
    private function ensureBaselineRecs()
    {
        $sql = "SELECT COUNT(id) FROM {$this->tbl->trnType} \n"
            . "WHERE clientID = :tenantID AND active = :active";
        $params = [
            ':tenantID' => $this->tenantID,
            ':active'   => 1,
        ];
        return $this->DB->fetchValue($sql, $params) < 1 ? false : true;
    }
}
