<?php
/**
 * Model for the Cases Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;

/**
 * Class RejectCaseData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords tpm, Case reject codes, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class RejectCaseData extends TpmListsData
{
    /**
     * Application instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * DB instance
     *
     * @var object
     */
    protected $DB = null;

    /**
     * The current tenantID
     *
     * @var integer
     */
    protected $tenantID = 0;

    /**
     * The current userID
     *
     * @var integer
     */
    protected $userID = 0;

    /**
     * Tables used by the model.
     *
     * @var object
     */
    protected $tbl = null;

    /**
     * Translation text array
     *
     * @var array
     */
    protected $txt = [];


    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $userID     Current userID
     * @param integer $listTypeID Current listTypeID
     * @param array   $initValues Any additional parameters that need to be passed in
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($tenantID, $userID, $listTypeID, $initValues = [])
    {
        $listTypeID = 10;
        parent::__construct($tenantID, $userID, $initValues);
        if (!$listTypeID || ($listTypeID != 10 && $listTypeID != 130)) {
            throw new \InvalidArgumentException("The listTypeID must be a positive integer value of 10 or 130.");
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
        $this->tbl->rejectCaseCode = $clientDB . '.rejectCaseCode';
        $this->tbl->cases = $clientDB . '.cases';
    }

    /**
     * Public wrapper to grab all records
     *
     * @return object DB result object
     */
    public function getFields()
    {
        return $this->fetchFields();
    }

    /**
     * Public wrapper to grab a single record
     *
     * @param integer $id id
     *
     * @return object DB result object
     */
    public function getSingleField($id)
    {
        return $this->fetchFields($id);
    }

    /**
     * Save a record
     *
     * @param array $vals Values to be saved
     *
     * @return array ['Result' => 1/0, (sets ErrTitle/Msg if validation fails)]
     */
    public function save($vals)
    {
        $oldVals = [];
        if (!empty($vals['id'])) {
            // get current values if record exists (validation will puke if record is bad)
            $oldVals = (array)$this->fetchFields($vals['id']);
        }
        $r = $this->saveRecord($vals, $oldVals);
        if (!$r['Result']) {
            return $r;
        }
        if (!empty($vals['id'])) {
            $msg = [];
            foreach ($vals as $k => $v) {
                if ($v != $oldVals[$k]) {
                    // only set if vals[k] is present and changed
                    $msg[] = $k . ': `' . $oldVals[$k] . '` => `' . $v . '`';
                } else {
                    $msg[] = $k . ': `' . $v . '`';
                }
            }
            $msg = implode(', ', $msg);
            $event = 203;
        } else {
            $msg = 'name: `' . $vals['name'] . '`';
            $event = 200;
        }
        if (strlen($msg) > 0) {
            $this->LogData->saveLogEntry($event, $msg);
        }
        return ['Result' => 1, 'FieldID' => $r['id']];
    }

    /**
     * Remove a record
     *
     * @param array $vals Post values ['id' => id, 'name' => name]
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove($vals)
    {
        if ($this->canDelete($vals['id'])) {
            $del = $this->DB->query(
                "DELETE FROM {$this->tbl->rejectCaseCode} WHERE id = :id AND clientID = :tenantID",
                [':id' => $vals['id'], ':tenantID' => $this->tenantID]
            );
            if (!$del->rowCount()) {
                return [
                    'Result' => 0,
                    'ErrTitle' => $this->txt['unexpected_error'],
                    'ErrMsg'   => $this->txt['status_Tryagain'],
                ];
            }
            $this->LogData->saveLogEntry(206, 'field: `' . $vals['name'] . '`');
            return ['Result' => 1];
        }
        return [
            'Result' => 0,
            'ErrTitle' => $this->txt['unexpected_error'],
            'ErrMsg'   => $this->txt['status_Tryagain'],
        ];
    }

    /**
     * Get rows OR a row by ID
     *
     * @param integer $id id
     *
     * @return array    Return array with result status, and error info if applicable.
     */
    private function fetchFields($id = 0)
    {
        $id = (int)$id;
        $sql = "SELECT a.id, a.name,a.description,a.returnStatus,a.returnStatus as type,
        a.forCaseOrig,a.forCaseOrig as size,a.hide, IF(\n"
        . "(SELECT COUNT(id) FROM {$this->tbl->cases} WHERE clientID IN (:clientID1) AND rejectReason = a.id) \n"
        . "> 0, 0, 1) as canDel \n"
        . "FROM {$this->tbl->rejectCaseCode} AS a WHERE a.clientID IN (:clientID) ";
        $params = [
            ':clientID' => $this->tenantID,
            ':clientID1' => $this->tenantID
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
     * Get rows by name
     *
     * @param integer $id   id
     * @param string  $name name
     *
     * @return array Return array with result.
     */
    private function fetchFieldsByName($id = 0, $name = '')
    {
        $sql = "SELECT a.id,a.name FROM {$this->tbl->rejectCaseCode} AS a WHERE a.clientID IN (:clientID) ";
        $params = [
            ':clientID' => $this->tenantID
        ];
        if ($id && $id > 0) {
            $sql .= 'AND a.id != :id';
            $params[':id'] = $id;
        }
        $sql .= ' AND a.name = :name LIMIT 1';
        $params[':name'] = $name;
        return $this->DB->fetchObjectRows($sql, $params);
    }

    /**
     * Save a record
     *
     * @param array $vals    Array of key/value pairs to be saved. (all fields on insert, else changed fields and id)
     * @param array $oldVals Array of key/value pairs of current record (optional, only if updating)
     *
     * @return Array ['Result' => 1/0, (sets ErrTitle/Msg if validation fails)]
     */
    private function saveRecord($vals, $oldVals = [])
    {
        $addSqlFields = [
            'clientID' => $this->tenantID,
        ];
        $updt = (!empty($vals['id']) && $vals['id'] > 0) ? true : false;
        $curID = 0;
        $sql = 'INSERT INTO ';
        $ins = ", clientID = :tenantID";
        $where = '';
        $params = [':tenantID' => $this->tenantID];
        $validate = $this->validateInput($vals, $oldVals);
        if ($validate['Result'] == 0) {
            return $validate;
        }

        if ($updt) {
            $sql = 'UPDATE ';
            $where = 'WHERE id = :id AND clientID = :tenantID';
            $ins = " \n";
            $curID = $params[':id'] = $vals['id'];
            unset($vals['id']);
        }

        $sql .= $this->tbl->rejectCaseCode . " SET \n";
        $rows = [];
        foreach ($vals as $k => $v) {
            $rows[] = '`' . $k . '` = :' . $k;
            $params[':' . $k] = $v;
        }
        $sql .= (implode(", \n", $rows)) . $ins . $where;
        $r = $this->DB->query($sql, $params);
        if (!$r->rowCount()) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['unexpected_error'],
                'ErrMsg'   => $this->txt['status_Tryagain'],
            ];
        }
        return ['Result' => 1, 'id' => (($updt) ? $curID : $this->DB->lastInsertId())];
    }

    /**
     * Validate the input data
     *
     * @param array $vals    new values
     * @param array $oldVals old values
     *
     * @return Array ['Result' => 1/0, (sets ErrTitle/Msg if validation fails)]
     */
    private function validateInput($vals, $oldVals = [])
    {
        if (empty($vals['name'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_name_characters'],
            ];
        }
        if (!empty($vals['name'])) {
            $nameArray = explode('-', (string) $vals['name']);
            if (count($nameArray) < 2
                || (isset($nameArray[0])
                    && (!is_numeric(trim($nameArray[0]))
                        || (int)$nameArray[0] <= '0')
                )
            ) {
                return [
                    'Result'   => 0,
                    'ErrTitle' => $this->txt['error_invalid_input'],
                    'ErrMsg'   => 'Invalid Case Reject Code: Please Check Requested Format.',
                ];
            }

            $recRow = $this->fetchFieldsByName(((isset($vals['id']) && $vals['id'] > 0)
            ? $vals['id'] : '0'), trim((string) $vals['name']));
            if ($recRow) {
                return [
                    'Result'   => 0,
                    'ErrTitle' => $this->txt['error_invalid_input'],
                    'ErrMsg'   => 'Invalid Case Reject Code: Name Already Exist.',
                ];
            }
        }
        $rtn = ['Result' => 1, 'vals' => $vals];
        return $rtn;
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id id
     *
     * @return boolean True if $id can be deleted, else false
     */
    private function canDelete($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        $rec = $this->fetchFields($id);
        if ($rec && $rec->canDel == 1) {
            return true;
        }
        return false;
    }
}
