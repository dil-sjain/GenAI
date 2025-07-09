<?php
/**
 * Model for the 3P Training Templates Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;

/**
 * Class TpTrngTplsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords third party training, training templates, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class TpTrngTplsData extends TpmListsData
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
     * @var array Array of table columns for validation
     */
    private $validCols = [];


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
        $this->tbl->trn     = $clientDB .'.training';
        $this->tbl->trnAtt  = $clientDB .'.trainingAttach';
        $this->tbl->trnData = $clientDB .'.trainingData';
        $this->tbl->trnType = $clientDB .'.trainingType';

        $this->tbl->trnAttType = $clientDB .'.trainingAttachType';
    }

    /**
     * Get all active training types for use in a select list
     *
     * @return array Return array of [value => row.id, name => row.name]
     */
    public function getTypes()
    {
        return $this->DB->fetchAssocRows(
            "SELECT id AS value, name, active FROM {$this->tbl->trnType} "
                . "WHERE clientID = :tenantID ORDER BY name ASC",
            [':tenantID' => $this->tenantID]
        );
    }

    /**
     * Get training by specified type
     *
     * @param integer $typeID trainingType.id
     *
     * @return array Array of DB result object rows
     */
    public function getTrainingByType($typeID)
    {
        return $this->getTraining($typeID);
    }

    /**
     * Get single training record by specified training ID
     *
     * @param integer $trnID training.id
     *
     * @return object DB result object
     */
    public function getTrainingByID($trnID)
    {
        return $this->getTraining(0, $trnID);
    }

    /**
     * Check for a valid training attachment number
     *
     * @param string $taNum trainingAttach.userTaNum
     *
     * @return bool Return true if valid, else false
     */
    public function checkUserTaNum($taNum)
    {
        if (!preg_match('/^[A-Z]+TA-[0-9]{5}$/', $taNum)) {
            return 0;
        }
        return $this->DB->fetchValue(
            "SELECT COUNT(id) FROM {$this->tbl->trnAtt} WHERE clientID = :tenantID AND userTaNum = :taNum LIMIT 1",
            [':tenantID' => $this->tenantID, ':taNum' => $taNum]
        );
    }

    /**
     * Public wrapper to save a record
     *
     * @params array $data Data to be saved
     *
     * @return array See: saveRecord for array format
     */
    public function saveData($data)
    {
        return $this->saveRecord($data);
    }

    /**
     * Public wrapper to remove a record
     *
     * @param array $data ['rec' => training.id, 'type' => trainingType.id]
     *
     * @return array See: deleteRecord for array format
     */
    public function remove($data)
    {
        return $this->removeRecord($data);
    }

    /**
     * Get training templates not tied to any specific third party. Pass no optional parameters to get
     * all training for the client.
     *
     * @param integer $trnTypeID [Optional] Pass trainingType.id) to get all training for that type
     * @param integer $trnID     [Optional] Pass training.id to get one specific record
     *
     * @return object DB object results
     */
    private function getTraining($trnTypeID = 0, $trnID = 0)
    {
        $trnTypeID = (int)$trnTypeID;
        $trnID = (int)$trnID;
        $addTypeName = '';
        $join = '';
        $where = 'WHERE t1.clientID = :clientID AND t1.tpID = :tpID';
        $orderBy = "ORDER BY t1.name ASC \n";
        $params = [':clientID' => $this->tenantID, ':tpID' => 0];

        if ($trnTypeID) {
            $where .= " AND t1.trainingType = :trnTypeIDt1";
            $params[':trnTypeIDt1'] = $trnTypeID;
        } elseif ($trnID) {
            $where .= ' AND t1.id = :trnIDt1';
            $params[':trnIDt1'] = $trnID;
        } else { // default to get all records for client that are not specific to any third party
            $addTypeName = "ty1.name AS trainingName, \n";
            $join = "LEFT JOIN {$this->tbl->trnType} AS ty ON (ta1.trainingType = ty1.id) \n";
            $orderBy = "ORDER BY ty1.name ASC, t1.name ASC \n";
        }
        $where .= "\n";

        $sql = "SELECT t1.id, t1.name, t1.description, t1.hide, t1.provider, t1.providerType, \n"
            . "t1.linkToMaterial, t1.trainingType, t1.tpID, \n". $addTypeName
            . "IF(\n"
                . "(SELECT(\n"
                    . "("
                        . "SELECT COUNT(td1.id) FROM {$this->tbl->trnData} AS td1 "
                        . "WHERE td1.trainingID = t1.id AND td1.clientID = t1.clientID"
                    . ")\n"
                    . "+ ("
                        . "SELECT COUNT(ta2.id) FROM {$this->tbl->trnAtt} AS ta2 "
                        . "WHERE ta2.trainingID = t1.id AND ta2.clientID = t1.clientID"
                    . ")\n"
                . ")\n"
            . ") > 0, 0, 1) AS canDel \n"
            . "FROM {$this->tbl->trn} AS t1 \n"
            . $join . $where . $orderBy;
        // single row for a single record request
        if ($trnID) {
            return $this->DB->fetchObjectRow($sql, $params);
        }
        return $this->DB->fetchObjectRows($sql, $params);
    }

    /**
     * Save record to database
     *
     * @param array $data Array of data to be saved
     *
     * @return array [
     *                 subResult => 1/0,
     *                 records   => updated records from getTrainingByType(),
     *                 errors    => [ set only on error, contains multiple nodes based on validation ]
     *               ]
     */
    private function saveRecord($data)
    {
        $updt = (!empty($data['id']) && intval($data['id'] > 0) ? true:false);
        $saveData = [];
        $params = [];
        $errors = [];
        // validate the data:
        foreach ($data as $key => $val) {
            // we don't allow these values to be passed in. (tpID doesn't come into play here, and will always be 0)
            if (in_array($key, ['clientID', 'tpID', 'created'])) {
                continue;
            }
            if ($key == 'hide') {
                $saveData[$key] = ':'. $key;
                $params[':'. $key] = (($val == 1) ? 1:0);
                continue;
            }
            if ($key == 'providerType') {
                $saveData[$key] = ':'. $key;
                $params[':'. $key] = (($val == 'external') ? 'external':'internal');
                continue;
            }
            $errCk = $this->validateData($key, $val);
            if (!$errCk['result']) {
                $errors[] = $errCk['error'];
            } else {
                $saveData[$key] = ':'. $key;
                $params[':'. $key] = $val;
            }
        }
        if (count($errors) > 0) {
            return [
                'subResult' => 0,
                'errors'    => $errors,
            ];
        }
        $sql = "INSERT INTO ";
        $where = '';
        if ($updt) {
            if (!$saveData['id']) {
                return [
                    'subResult' => 0,
                    'errors'    => [
                        'title' => $this->txt['error_record_invalid_title'],
                        'msg'   => $this->txt['error_record_invalid_status'],
                    ],
                ];
            }
            $sql = "UPDATE ";
            $where = " WHERE id = :id AND clientID = :clientID";
            $params[':clientID'] = $this->tenantID;
            $oldRec = $this->getTraining(0, $params[':id']);
            unset($saveData['id']);
        } else {
            $params[':clientID'] = $this->tenantID;
            $params[':tpID'] = 0;
            $saveData['clientID'] = ':clientID';
            $saveData['tpID'] = ':tpID';
            $oldRec = (object)null;
        }
        $sql .= "{$this->tbl->trn} SET \n";
        $keyList = [];
        foreach ($saveData as $key => $binding) {
            $fields[] = $key ." = ". $binding;
        }
        $sql .= implode(", \n", $fields) . $where;
        $save = $this->DB->query($sql, $params);
        $curRecID = (($updt) ? (int)$params[':id'] : $this->DB->lastInsertId());
        if (!$save->rowCount()) {
            return [
                'subResult' => 0,
                'errors'    => [
                    'title' => $this->txt['title_operation_failed'],
                    'msg'   => $this->txt[(!$updt ? 'add_record_failed' : 'update_record_failed')],
                ],
            ];
        }
        $this->logSaveData($params, $oldRec, $updt);
        return [
            'subResult' => 1,
            'records' => $this->getTrainingByType($params[':trainingType']),
            'curRecID' => $curRecID,
        ];
    }

    /**
     * Remove a record
     *
     * @param array $data ['rec' => training.id, 'type' => trainingType.id]
     *
     * @return array ['Result' => boolean, (on error) 'title' => string, 'msg' => string]
     */
    private function removeRecord($data)
    {
        $rtn = ['Result' => 0];
        $rec = $this->getTraining(0, $data['rec']);
        if (!$rec || !$rec->canDel) {
            $rtn['ErrTitle'] = $this->txt['one_error'];
            $rtn['ErrMsg'] = $this->txt['del_failed_has_data_no_perm'];
            return $rtn;
        }
        $del = $this->DB->query(
            "DELETE FROM {$this->tbl->trn} WHERE id = :id AND trainingType = :type LIMIT 1",
            [':id' => $data['rec'], ':type' => $data['type']]
        );
        if (!$del->rowCount()) {
            $rtn['ErrTitle'] = $this->txt['one_error'];
            $rtn['ErrMsg'] = str_replace('{name}', $rec['name'], (string) $this->txt['error_invalid_deletion_msg']);
            return $rtn;
        }
        $typeName = '';
        $type = $this->getTypes();
        foreach ($type as $t) {
            if ($t['value'] == $rec->trainingType) {
                $typeName = 'type: `'. $t['name'] .'`, ';
                break;
            }
        }
        $logMsg = $typeName . 'name: `'. $rec->name .'`';
        $this->LogData->saveLogEntry(73, $logMsg);
        $rtn['Result'] = 1;
        $rtn['Records'] = $this->getTrainingByType($rec->trainingType);
        return $rtn;
    }

    /**
     * Log data save
     *
     * @param array   $params Param array from the db query with all saved values
     * @param object  $oldRec If update, the original record data prior to updating
     * @param boolean $updt   True on update, else false (set in saveRecord)
     *
     * @return void
     */
    private function logSaveData($params, $oldRec, $updt)
    {
        $trTypeName = $this->DB->fetchValue(
            "SELECT name FROM {$this->tbl->trnType} WHERE id = :id AND clientID = :tenantID LIMIT 1",
            [':id' => $params[':trainingType'], ':tenantID' => $this->tenantID]
        );
        $logMsg = [];
        $logMsg[] = 'type: `'. $trTypeName .'`';
        if (!$updt) {
            $logID = 71;
            $logMsg[] = 'name: `'. $params[':name'] .'`';
        } else {
            $logID = 72;
            if ($params[':name'] != $oldRec->name) {
                $logMsg[] = 'name: `'. $oldRec->name .'` => `'. $params[':name'] .'`';
            } else {
                $logMsg[] = 'name: `'. $params[':name'] .'`';
            }
            if ($params[':provider'] != $oldRec->provider) {
                $logMsg[] = 'provider: `'. $oldRec->provider .'` => `'. $params[':provider'] .'`';
            }
            if ($params[':providerType'] != $oldRec->providerType) {
                $logMsg[] = 'providerType: `'. $oldRec->providerType .'` => `'. $params[':providerType'] .'`';
            }
            if ($params[':hide'] != $oldRec->hide) {
                $logMsg[] = 'hide: `'. $oldRec->hide .'` => `'. $params[':hide'] .'`';
            }
            if ($params[':linkToMaterial'] != $oldRec->linkToMaterial) {
                $logMsg[] = 'linkToMaterial: `'. $oldRec->linkToMaterial .'` => `'. $params[':linkToMaterial'] .'`';
            }
            if ($params[':description'] != $oldRec->description) {
                $logMsg[] = 'description: `'. $oldRec->description .'` => `'. $params[':description'] .'`';
            }
        }
        $logMsg = implode(', ', $logMsg);
        $this->LogData->saveLogEntry($logID, $logMsg);
    }

    /**
     * Validate data
     *
     * @param string $key training.[columnName] to be validated
     * @param mixed  $val value to be checked
     *
     * @return array ['result' => boolean] on fail, add 'error' => ['title' => string, 'msg' => string]
     */
    private function validateData($key, mixed $val)
    {
        $rtn = ['result' => 1];
        switch ($key) {
            case 'id':
                $ckVal = (int)$val;
                if ($ckVal <= 0) {
                    $rtn['result'] = 0;
                    $rtn['error'] = [
                    'title' => $this->txt['error_record_invalid_title'],
                    'msg'   => $this->txt['error_record_invalid_status'],
                    ];
                }
                break;
            case 'trainingType':
                $types = $this->getTypes();
                $hasType = false;
                foreach ($types as $t) {
                    if ($t['value'] == $val) {
                        $hasType = true;
                        break;
                    }
                }
                if (!$hasType) {
                    $rtn['result'] = 0;
                    $rtn['error']  = [
                    'title' => $this->txt['error_invalid_input'],
                    'msg'   => $this->txt['fl_trntpl_invalid_training_type'],
                    ];
                }
                break;
            case 'name':
            case 'provider':
            case 'description':
                if (!$val && $key != 'description') {
                    $rtn['result'] = 0;
                    $rtn['error']  = [
                    'title' => $this->txt['error_missing_input'],
                    'msg'   => $this->txt['fl_trntpl_invalid_training_'. (($key == 'name') ? 'name':'provider')],
                    ];
                } elseif (strlen((string) $val) > 50) {
                    $msgKey = (($key == 'name') ? 'form_training_name':'form_provider');
                    if ($key == 'description') {
                        if (strlen((string) $val) <= 250) {
                            return $rtn;
                        }
                        $msgKey = 'form_description';
                    }
                    $length = (($key == 'description') ? 250 : 50);
                    $rtn['result'] = 0;
                    $rtn['error']  = [
                    'title'  => $this->txt[$msgKey],
                    'msg'    => str_replace('{#}', $length, (string) $this->txt['invalid_maxLength']),
                    ];
                }
                break;
            case 'linkToMaterial':
                // if linkToMaterial provided, must be training attach userTaNum or URL
                $expr = '/(^[A-Z]+TA-[0-9]{5}$)|(^https*:\/\/([a-zA-Z0-9](([-.]?[a-zA-Z0-9]+)'
                .'|([a-zA-Z0-9]*))*\.[a-zA-Z]{2,4})(\/[-_.%?&=a-zA-Z\/0-9]+)?$)/';
                if (!empty($val) && !preg_match($expr, (string) $val)) {
                    $rtn['result'] = 0;
                    $rtn['error']  = [
                    'title' => $this->txt['error_invalid_input'],
                    'msg'   => $this->txt['fl_trntpl_invalid_link_to_materials'],
                    ];
                    // if linkToMaterial is Training Attach record, must be a valid one
                } elseif (!empty($val) && preg_match('/^[A-Z]+TA-[0-9]{5}$/', (string) $val)) {
                    if (!$this->DB->checkUserTaNum($val)) {
                        $rtn['result'] = 0;
                        $rtn['error']  = [
                        'title' => $this->txt['error_invalid_input'],
                        'msg'   => $this->txt['fl_trntpl_invalid_training_attach_num'],
                        ];
                    }
                }
                break;
            default:
                // the column trying to be validated doesn't exist??? No tomfoolery here. bail.
                if (!in_array($key, $this->getValidColumns())) {
                    $rtn['result'] = 0;
                    $rtn['error']  = [
                    'title' => $this->txt['one_error'],
                    'msg'   => $this->txt['error_op_not_allowed'],
                    ];
                }
                break;
        }
        return $rtn;
    }

    /**
     * Get table columns for validation. (Could hard code, but this will save having to update on changes)
     *
     * @return array [all => [all_columns], validate => [only_cols_to_validate]]
     */
    private function getValidColumns()
    {
        if (count($this->validCols) > 0) {
            return $this->validCols;
        }
        $cols = $this->DB->fetchObjectRow(
            "SELECT * FROM {$this->tbl->trn} WHERE clientID = :clientID LIMIT 1",
            [':clientID' => $this->tenantID]
        );
        $this->validCols = [];
        foreach ($cols as $key => $val) {
            if ($key == 'tpID' || $key == 'clientID' || $key == 'created') {
                continue;
            }
            $this->validCols[] = $key;
        }
        return $this->validCols;
    }

    /**
     * Get all training types for use in a select list
     *
     * @return array
     */
    public function getTemplates()
    {
        $clientDB  = $this->DB->getClientDB($this->tenantID);
        $params = [':clientID' => $this->tenantID];
        $sql = "SELECT t.id, t.name, t.provider, t.providerType, t.linkToMaterial, t.trainingType as trainingTypeID, "
            . "tt.name as trainingTypeName, t.description\n"
            . "FROM {$clientDB}.training t\n"
            . "INNER JOIN {$clientDB}.trainingType tt ON t.providerType = tt.id AND tt.active = '1' \n"
            . "WHERE t.clientID = :clientID AND t.tpID = 0\n"
            . "ORDER BY id ASC";
        return $this->DB->fetchAssocRows($sql, $params);
    }
}
