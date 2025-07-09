<?php
/**
 * Model for the Billing Unit Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class BillingUnitData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order
 * to fulfill their own requirements.
 *
 * @keywords billing, billing unit, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class BillingUnitData extends TpmListsData
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
        $this->tbl->bUnit   = $clientDB .'.billingUnit';
        $this->tbl->bUnitPO = $clientDB .'.billingUnitPO';
        $this->tbl->cases   = $clientDB .'.cases';
    }

    /**
     * Public wrapper to grab all records
     *
     * @return object DB object, else error.
     */
    public function getAll()
    {
        return $this->fetch();
    }

    /**
     * Public wrapper to grab a single record by ID
     *
     * @param integer $id billingUnit.id
     *
     * @return object DB object, else error.
     */
    public function getSingle($id)
    {
        return $this->fetch($id);
    }

    /**
     * Get all records as a list of key(id) => value(name) pairs.
     *
     * @param boolean $noHidden Optional. Default to false, pass as true to exclude hidden billing units.
     *
     * @return array Array(0 => [value => id, name => name], 1 => [value => id, name => name], etc);
     */
    public function getKeyValList($noHidden = false)
    {
        $noHidden = ($noHidden ? true:false);
        $list = $this->fetch(0, $noHidden);
        $rtn = [];
        foreach ($list as $l) {
            $rtn[] = ['value' => $l->id, 'name' => $l->name];
        }
        return $rtn;
    }

    /**
     * Get all records, or single records if $id is present
     *
     * @param integer $id       billingUnit.id [optional]
     * @param boolean $noHidden Optional. Default to false, pass as true to exclude hidden billing units.
     *
     * @return object DB object
     */
    protected function fetch($id = 0, $noHidden = false)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name, a.description AS descr, a.hide AS ckBox, IF(\n"
            ."(\n"
                ."IF((SELECT COUNT(id) FROM {$this->tbl->bUnit} WHERE clientID = :clientID1) < 2, 1, 0) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->bUnitPO} WHERE clientID = :clientID2 AND buID = a.id) \n"
                ."+ (SELECT COUNT(id) FROM {$this->tbl->cases} WHERE clientID = :clientID3 AND billingUnit = a.id) \n"
            .") > 0, 0, 1) as canDel \n"
            ."FROM {$this->tbl->bUnit} AS a WHERE a.clientID = :clientID4 "; // LEAVE trailing space.
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':clientID3' => $this->tenantID,
            ':clientID4' => $this->tenantID,
        ];
        if ($noHidden) {
            $sql .= 'AND a.hide = :noHidden '; // LEAVE trailing space;
            $params[':noHidden'] = 0;
        }
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
     * @param array $vals Expects: Array('name' => billingUnit.name, 'descr' => billingUnit.description)
     *
     * @return array Return array with result status and error info if applicable.
     */
    public function add($vals)
    {
        $validateFuncs = new ValidateFuncs();
        if ($this->nameExists($vals['name'], $this->tbl->bUnit)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
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
        if (!$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg' => 'Description contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        $sql = "INSERT INTO {$this->tbl->bUnit} "
            ."SET clientID = :clientID, name = :name, description = :descr, hide = :hide";
        $params = [
            ':clientID' => $this->tenantID,
            ':name' => $vals['name'],
            ':descr' => $vals['descr'],
            ':hide' => (!empty($vals['ckBox']) ? 1 : 0),
        ];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $logMsg = 'name: `'. $vals['name'] .'`';
            if (!empty($vals['descr'])) {
                $logMsg .= ', descr: `'. $vals['descr'] .'`';
            }
            $logMsg .= ', hide: `'.(
                !empty($vals['ckBox']) ? $this->txt['user_status_inactive'] : $this->txt['user_status_active']
            ).'`';
            $this->LogData->saveLogEntry(131, $logMsg);
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
     *                                    'id' => billingUnit.id,
     *                                    'name' => billingUnit.name,
     *                                    'descr' => billingUnit.description,
     *                                    'ckBox' => billingUnit.hide
     *                                )
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
        if (!$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Description contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($vals['name'] == $oldVals['name'] && $vals['descr'] == $oldVals['descr']
            && $vals['ckBox'] == $oldVals['ckBox']
        ) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg' => $this->txt['data_no_changes_same_value'],
            ];
        } elseif ($vals['name'] != $oldVals['name'] && $this->nameExists($vals['name'], $this->tbl->bUnit)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "UPDATE {$this->tbl->bUnit} SET "
            ."name = :name, description = :descr, hide = :hide "
            ."WHERE id = :id AND clientID = :clientID";
        $params = [
            ':name' => $vals['name'],
            ':descr' => $vals['descr'],
            ':hide' => (!empty($vals['ckBox']) ? 1 : 0),
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
        if ($vals['ckBox'] != $oldVals['ckBox']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') .'hide: `';
            if ($vals['ckBox'] == 0) {
                $logMsg .= $this->txt['user_status_inactive'] .'` => `'. $this->txt['user_status_active'] .'`';
            } else {
                $logMsg .= $this->txt['user_status_active'] .'` => `'. $this->txt['user_status_inactive'] .'`';
            }
        }
        $this->LogData->saveLogEntry(132, $logMsg);
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array('id' => billingUnit.id, 'name' => billingUnit.name)
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
        $sql = "DELETE FROM {$this->tbl->bUnit} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(133, "name: `". $vals['name'] ."`");
        return ['Result' => 1];
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id billingUnit.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($id)
    {
        $cat = $this->getSingle($id);
        return ($cat->canDel == 1) ? true : false;
    }
}
