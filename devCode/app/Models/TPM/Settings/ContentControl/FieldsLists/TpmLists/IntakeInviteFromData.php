<?php
/**
 * Model for the Intake Invite From Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Controllers\TPM\IntakeForms\InviteMenu\IntakeFormInvite;
use Lib\Validation\ValidateFuncs;

/**
 * Class IntakeInviteFromData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords third party types, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class IntakeInviteFromData extends TpmListsData
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
     * @var object IntakeFormInvite Core Feature object
     */
    protected $IntakeFormInvite = null;



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
        $this->IntakeFormInvite = new IntakeFormInvite($tenantID);
        if (!$this->IntakeFormInvite->isClientFeatureEnabled($tenantID)) {
            __destruct();
            return false; // not allowed to request this class. How did they get this far?
        }
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
        $this->tbl->IntakeFormInviteList = "`" . $clientDB . "`.`intakeFormInviteList`";
    }

    /**
     * Public wrapper to grab all records
     *
     * @return array Assoc array of 'V T' arrays for use with dropdown libraries
     */
    public function getEmails()
    {
        return $this->IntakeFormInvite->getEmailList();
    }

    /**
     * Populates Fields/Lists with list of optional From addresses and IDs
     *
     * @return array
     */
    public function getFlEmailList($clientID)
    {
        $clientID = (int)$clientID;
        if ($clientID < 1) {
            return false;
        }
        $sql = "SELECT id, name, description AS descr, '1' AS canDel "
            . "FROM " . $this->tbl->IntakeFormInviteList . " "
            . "WHERE clientID = :cid";
        $params  = [':cid' => $clientID];
        return $this->DB->fetchObjectRows($sql, $params);
    }

    /**
     * Public wrapper to grab a single record by ID
     *
     * @param integer $id g_IntakeFormInviteList.id
     *
     * @return object DB object, else error. (object->Result will be set and have a 0 val on error)
     */
    public function getEmail($id)
    {
        return $this->IntakeFormInvite->getFlEmailByID($id);
    }

    /**
     * Get all records as a list of key(id) => value(name) pairs.
     *
     * @return array Array(0 => ['value' => id, 'name' => name], 1 => ['value' => id, 'name' => name], etc);
     */
    public function getKeyValList()
    {
        $list = $this->getFlEmailList($this->tenantID);
        //$this->app->log->debug($list);
        $rtn = [];
        foreach ($list as $l) {
            $rtn[] = ['value' => $l->id, 'name' => $l->name];
        }
        return $rtn;
    }

    /**
     * Add a new record
     *
     * @param array $vals ['name' => intakeFormInviteList.name, 'descr' => intakeFormInviteList.description]
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
        } else if (!$validateFuncs->checkInputSafety($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe contents like HTML tags, javascript, or other unsafe content.',
            ];
        }
        if (!$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Description contains unsafe contents like HTML tags, javascript, or other unsafe content.',
            ];
        }
        if (!$this->IntakeFormInvite->isValidEmail($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => $this->txt['error_missing_input'],
            ];
        }
        if ($this->nameExists($vals['name'], $this->tbl->IntakeFormInviteList)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "INSERT INTO {$this->tbl->IntakeFormInviteList} "
            . "SET clientID = :clientID, name = :email";
        $params = [
            ':clientID' => $this->tenantID,
            ':email' => $vals['name'],
        ];
        if (!empty($vals['descr'])) {
            $sql .= ", description = :description";
            $params[':description'] = $vals['descr'];
        }
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $logMsg = 'email: `'. $vals['name'] .'`'. (!empty($vals['descr']) ? ', descr: `'. $vals['descr'] .'`':'');
            $this->LogData->saveLogEntry(41, $logMsg);
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
     * @param array $vals    Expects: Array('id' => tpType.id, 'name' => tpType.name, 'descr' => tpType.description)
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
        } else if (!$validateFuncs->checkInputSafety($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Name contains unsafe contents like HTML tags, javascript, or other unsafe content.',
            ];
        }
        if (!$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg' => 'Description contains unsafe contents like HTML tags, javascript, or other unsafe content.',
            ];
        }
        if (!$this->IntakeFormInvite->isValidEmail($vals['name'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => $this->txt['error_missing_input'],
            ];
        }
        if ($vals['name'] == $oldVals['name'] && $vals['descr'] == $oldVals['descr']) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg' => $this->txt['data_no_changes_same_value'],
            ];
        } elseif ($vals['name'] != $oldVals['name'] && $this->nameExists($vals['name'], $this->tbl->IntakeFormInviteList)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "UPDATE {$this->tbl->IntakeFormInviteList} SET "
            ."name = :name, description = :descr "
            ."WHERE id = :id AND clientID = :clientID";
        $params = [
            ':name' => $vals['name'],
            ':descr' => $vals['descr'],
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
            $logMsg .= 'name: `'. $oldVals['name'] .'` => `'. $vals['name'] .'`';
        }
        if ($vals['descr'] != $oldVals['descr']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') .'descr: `'. $oldVals['descr'] .'` => `'. $vals['descr'] .'`';
        }
        $this->LogData->saveLogEntry(42, $logMsg);
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array('id' => tpType.id, 'name' => tpType.name)
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
        $sql = "DELETE FROM {$this->tbl->IntakeFormInviteList} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(43, "name: `". $vals['name'] ."`");
        return ['Result' => 1];
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id tpType.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($id)
    {
        $cat = $this->getEmail($id);
        return ($cat->canDel == 1) ? true : false;
    }
}
