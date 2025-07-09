<?php
/**
 * Model for the 3P Type Categories Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class TpTypeCatsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords third party categories, type categories, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class TpTypeCatsData extends TpmListsData
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
        $this->tbl->tpTypeCat  = $clientDB .'.tpTypeCategory';
        $this->tbl->tpType     = $clientDB .'.tpType';
        $this->tbl->tpProfile  = $clientDB .'.thirdPartyProfile';
        $this->tbl->tpCompFact = $clientDB .'.tpComplyFactor';
        $this->tbl->tpCompOvr  = $clientDB .'.tpComplyOverride';
    }

    /**
     * Public wrapper to grab all records for a specified parent type
     *
     * @param integer $tpTypeID tpTypeCategory.tpType
     *
     * @return object DB object, else error.
     */
    public function getAllByGroup($tpTypeID)
    {
        return $this->fetch($tpTypeID);
    }

    /**
     * Public wrapper to grab a single record by ID
     *
     * @param integer $tpTypeID tpTypeCategory.tpType
     * @param integer $id       tpTypeCategory.id
     *
     * @return object DB object
     */
    public function getSingle($tpTypeID, $id)
    {
        return $this->fetch($tpTypeID, $id);
    }

    /**
     * Get all records for specified parent, or single record if $id is present
     *
     * @param integer $tpTypeID tpTypeCategory.tpType
     * @param integer $id       tpTypeCategory.id
     *
     * @return object DB object
     */
    protected function fetch($tpTypeID, $id = 0)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name, a.description AS descr, a.private AS ckBox, IF(\n"
            . "(\n"
                . "IF(\n"
                    . "(\n"
                    . "IF((SELECT COUNT(id) FROM {$this->tbl->tpType} WHERE clientID = :clientID1) < 2, 1, 0) \n"
                    . "+ IF((SELECT COUNT(id) FROM {$this->tbl->tpTypeCat} WHERE clientID = :clientID2) < 2, 1, 0) \n"
                . ") = 2, 1, 0)\n"
                . "+ (SELECT COUNT(id) FROM {$this->tbl->tpProfile} "
                    . "WHERE clientID = :clientID3 AND tpTypeCategory = a.id) \n"
                . "+ (SELECT COUNT(ovr.factorID) FROM {$this->tbl->tpCompFact} AS cf \n"
                    . "LEFT JOIN {$this->tbl->tpCompOvr} AS ovr ON (cf.id = ovr.factorID) \n"
                    . "WHERE ovr.factorID IS NOT NULL AND cf.clientID = :clientID4 AND ovr.tpTypeCategory = a.id) \n"
            . ") > 0, 0, 1) as canDel, a.active as ckBox1 \n"
            . "FROM {$this->tbl->tpTypeCat} AS a \n"
            . "WHERE a.clientID = :clientID5 AND a.tpType = :tpTypeID "; // LEAVE trailing space.
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':clientID3' => $this->tenantID,
            ':clientID4' => $this->tenantID,
            ':clientID5' => $this->tenantID,
            ':tpTypeID'  => $tpTypeID,
        ];
        if ($id && $id > 0) {
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
     *                      'name'   => tpTypeCategory.name,
     *                      'descr'  => tpTypeCategory.description,
     *                      'ckBox'  => tpTypeCategory.private,
     *                      'ckBox1' => tpTypeCategory.tpType
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
                'ErrMsg' => 'Name contains unsafe contents such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($this->nameExists($vals, $this->tbl->tpTypeCat)) {
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
                'ErrMsg' => 'Description contains unsafe contents such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        $sql = "INSERT INTO {$this->tbl->tpTypeCat} "
            . "SET tpType = :tpTypeID, clientID = :clientID, name = :name, "
            . "description = :descr, private = :private, active = :active ";
        $params = [
            ':tpTypeID' => $vals['subList']['val'],
            ':clientID' => $this->tenantID,
            ':name'     => $vals['name'],
            ':descr'    => $vals['descr'],
            ':private'  => (!empty($vals['ckBox']) ? 1 : 0),
            ':active'   => (!empty($vals['ckBox1']) ? 1 : 0)
        ];
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            $newID = $this->DB->lastInsertId();
            $logMsg = 'name: `'. $vals['name'] .'`';
            if (!empty($vals['descr'])) {
                $logMsg .= ', descr: `'. $vals['descr'] .'`';
            }
            $logMsg .= ', private: `'
                . (!empty($vals['ckBox']) ? $this->txt['yes'] : $this->txt['no'])
                . '`';
            $logMsg .= ', status: `'
                . (!empty($vals['ckBox1']) ? $this->txt['user_status_active'] : $this->txt['user_status_inactive'])
                . '`';
            $this->LogData->saveLogEntry(44, $logMsg);
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
     *                                    'id' => tpTypeCategory.id,
     *                                    'name' => tpTypeCategory.name,
     *                                    'descr' => tpTypeCategory.description,
     *                                    'ckBox' => tpTypeCategory.private,
     *                                    'ckBox1' => tpTypeCategory.tpType
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
                'ErrMsg' => 'Name contains unsafe contents such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($vals['name'] == $oldVals['name'] && $vals['descr'] == $oldVals['descr']
            && $vals['ckBox'] == $oldVals['ckBox'] && $vals['ckBox1'] == $oldVals['ckBox1']
        ) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['data_nothing_changed_title'],
                'ErrMsg' => $this->txt['data_no_changes_same_value'],
            ];
        } elseif ($vals['name'] != $oldVals['name'] && $this->nameExists($vals, $this->tbl->tpTypeCat)) {
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
                'ErrMsg' => 'Description contains unsafe contents such as HTML tags, JavaScript, or other unsafe content.',
            ];
        }
        if ($this->ensureBaselineRecs($vals['id']) === false && !$vals['ckBox1']) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => 'Must have at least 1 active record.',
            ];
        }
        $sql = "UPDATE {$this->tbl->tpTypeCat} SET "
            . "name = :name, description = :descr, private = :private, active = :active "
            . "WHERE id = :id AND clientID = :clientID";
        $params = [
            ':name'     => $vals['name'],
            ':descr'    => $vals['descr'],
            ':private'  => (!empty($vals['ckBox']) ? 1 : 0),
            ':active'   => (!empty($vals['ckBox1']) ? 1 : 0),
            ':id'       => $vals['id'],
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
            $logMsg .= 'name: `' . $oldVals['name'] . '` => `' . $vals['name'] .'`';
        }
        if ($vals['descr'] != $oldVals['descr']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') .'descr: `' . $oldVals['descr'] . '` => `' . $vals['descr'] .'`';
        }
        if ($vals['ckBox'] != $oldVals['ckBox']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') . 'private: `';
            if ($vals['ckBox'] == 0) {
                $logMsg .= $this->txt['yes'] . '` => `' . $this->txt['no'] . '`';
            } else {
                $logMsg .= $this->txt['no'] . '` => `' . $this->txt['yes'] . '`';
            }
        }
        if ($vals['ckBox1'] != $oldVals['ckBox1']) {
            $logMsg .= (!empty($logMsg) ? ', ' : '') .'status: `';
            if ($vals['ckBox1'] == 1) {
                $logMsg .= $this->txt['user_status_inactive'] . '` => `' . $this->txt['user_status_active'] . '`';
            } else {
                $logMsg .= $this->txt['user_status_active'] . '` => `' . $this->txt['user_status_inactive'] . '`';
            }
        }
        $this->LogData->saveLogEntry(45, $logMsg);
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: Array(
     *                      'id' => tpTypeCategory.id,
     *                      'name' => tpTypeCategory.name,
     *                      'subList' => [val => tpTypeCategory.tpType]
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
        $sql = "DELETE FROM {$this->tbl->tpTypeCat} WHERE id = :id AND clientID = :clientID AND name = :name LIMIT 1";
        $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
        $del = $this->DB->query($sql, $params);
        if (!$del->rowCount()) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg'   => $this->txt['msg_no_changes_were_made'],
            ];
        }
        $this->LogData->saveLogEntry(46, "name: `" . $vals['name'] . "`");
        return ['Result' => 1];
    }

    /**
     * Can this be deleted?
     *
     * @param integer $tpTypeID tpTypeCategory.tpType
     * @param integer $id       tpTypeCategory.id
     *
     * @return boolean True if can be deleted, else false if cannot.
     */
    private function canDelete($tpTypeID, $id)
    {
        $single = $this->getSingle($tpTypeID, $id);
        return ($single->canDel == 1) ? true : false;
    }

    /**
     * Check if name already exists. No dups allowed. Overrides basic TpmListsData method as
     * names can be duplicated, but not within the same parent Type.
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
        $sql = "SELECT COUNT(*) FROM {$tbl} WHERE tpType = :tpTypeID AND clientID= :clientID AND name= :name";
        $params = [':tpTypeID' => $vals['subList']['val'], ':clientID' => $this->tenantID, ':name' => $vals['name']];
        if ($this->DB->fetchValue($sql, $params)) {
            return true;
        }
        return false;
    }

    /**
     * Make sure they have at least 1 active record
     *
     * @param integer $id tpTypeCategory.id
     *
     * @return boolean Return true if they have at least 1 active record, else false.
     */
    private function ensureBaselineRecs($id)
    {
        $sql = "SELECT tpType FROM {$this->tbl->tpTypeCat} \n"
            . "WHERE clientID = :tenantID AND id = :id";
        $params = [
            ':tenantID' => $this->tenantID,
            ':id' => $id
        ];
        $tpType = $this->DB->fetchValue($sql, $params);
        $sql = "SELECT COUNT(id) FROM {$this->tbl->tpTypeCat} \n"
            . "WHERE clientID = :tenantID AND active = :active AND tpType = :tpType";
        $params = [
            ':tenantID' => $this->tenantID,
            ':tpType'      => $tpType,
            ':active'   => 1,
        ];
        return $this->DB->fetchValue($sql, $params) < 1 ? false : true;
    }
}
