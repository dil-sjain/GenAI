<?php
/**
 * Model for the Purchase Order (Billing Unit) Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class PurchaseOrderData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords purchase order, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class PurchaseOrderData extends TpmListsData
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
        $this->tbl->bUnitPO = $clientDB .'.billingUnitPO';
        $this->tbl->bUnit   = $clientDB .'.billingUnit';
        $this->tbl->cases   = $clientDB .'.cases';
    }

    /**
     * Public wrapper to grab all records for a specified parent
     *
     * @param integer $unitID billingUnitPO.buID
     *
     * @return object DB object, else error.
     */
    public function getAllByUnit($unitID)
    {
        return $this->fetch($unitID);
    }

    /**
     * Public wrapper to grab a single record by ID
     *
     * @param integer $unitID billingUnitPO.buID
     * @param integer $id     billingUnitPO.id
     *
     * @return object DB object
     */
    public function getSingle($unitID, $id)
    {
        return $this->fetch($unitID, $id);
    }

    /**
     * Get all records for specified parent, or single record if $id is present
     *
     * @param integer $unitID billingUnitPO.buID
     * @param integer $id     billingUnitPO.id
     *
     * @return object DB object
     */
    protected function fetch($unitID, $id = 0)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name, a.description AS descr, a.hide AS ckBox, IF(\n"
            ."(SELECT COUNT(id) FROM {$this->tbl->cases} "
                ."WHERE clientID = :clientID1 AND billingUnitPO = a.id) > 0, 0, 1) as canDel \n"
            ."FROM {$this->tbl->bUnitPO} AS a \n"
            ."WHERE a.clientID = :clientID2 AND a.buID = :buID "; // LEAVE trailing space.
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':buID'      => $unitID,
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
     * @param array $vals Expects: Array(
     *                      'name' => billingUnitPO.name,
     *                      'descr' => billingUnitPO.description,
     *                      'ckBox' => billingUnitPO.hide
     *                    )
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
        if (!empty($vals['descr']) && !$validateFuncs->checkInputSafety($vals['descr'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Description contains unsafe content such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($this->nameExists($vals, $this->tbl->bUnitPO)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "INSERT INTO {$this->tbl->bUnitPO} "
            ."SET buID = :buID, clientID = :clientID, name = :name, description = :descr, hide = :hide";
        $params = [
            ':buID' => $vals['subList']['val'],
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
            $this->LogData->saveLogEntry(134, $logMsg);
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
     *                                    'id' => billingUnitPO.id,
     *                                    'name' => billingUnitPO.name,
     *                                    'descr' => billingUnitPO.description,
     *                                    'ckBox' => billingUnitPO.hide
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
        if (!empty($vals['descr']) && !$validateFuncs->checkInputSafety($vals['descr'])) {
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
        } elseif ($vals['name'] != $oldVals['name'] && $this->nameExists($vals, $this->tbl->bUnitPO)) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['duplicate_entry'],
                'ErrMsg' => $this->txt['fl_error_name_already_exists'],
            ];
        }
        $sql = "UPDATE {$this->tbl->bUnitPO} SET "
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
        $this->LogData->saveLogEntry(135, $logMsg);
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array(
     *                      'id' => billingUnitPO.id,
     *                      'name' => billingUnitPO.name,
     *                      'subList' => [val => billingUnitPO.buID]
     *                    )
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove($vals)
    {
        $id = (int)$vals['id'];
        if ($id <= 0 || !$this->canDelete($vals['subList']['val'], $id)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => str_replace('{name}', $vals['name'], (string) $this->txt['error_invalid_deletion_msg']),
            ];
        }
        $sql = "DELETE FROM {$this->tbl->bUnitPO} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(136, "name: `". $vals['name'] ."`");
        return ['Result' => 1];
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $buID billingUnitPO.buID
     * @param integer $id   billingUnitPO.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($buID, $id)
    {
        $single = $this->getSingle($buID, $id);
        return ($single->canDel == 1) ? true : false;
    }

    /**
     * Check if name already exists. No dups allowed. Overrides basic TpmListsData method as
     * names can be duplicated, but not within the same parent.
     *
     * @param string $vals Vals array (name, subList keys expected)
     * @param string $tbl  Table to use for lookup
     *
     * @return boolean True if name exists, else false.
     */
    #[\Override]
    protected function nameExists($vals, $tbl)
    {
        $tbl = $this->DB->esc($tbl);
        $sql = "SELECT COUNT(*) FROM {$tbl} WHERE buID = :buID AND clientID= :clientID AND name= :name";
        $params = [':buID' => $vals['subList']['val'], ':clientID' => $this->tenantID, ':name' => $vals['name']];
        if ($this->DB->fetchValue($sql, $params)) {
            return true;
        }
        return false;
    }
}
