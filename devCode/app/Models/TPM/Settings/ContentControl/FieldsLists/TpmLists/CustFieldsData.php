<?php
/**
 * Model for the main Fields/Lists data operations for Custom Lists.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Models\TPM\CaseMgt\CustomRefData;
use Models\TPM\CaseMgt\FlaggedQuestions;
use Lib\SettingACL;
use Lib\Validation\ValidateFuncs;

/**
 * Class CustListsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords tpm, fields lists, model, settings, custom fields
 */
#[\AllowDynamicProperties]
class CustFieldsData extends TpmListsData
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
     * Custom Reference Fields model
     *
     * @var object
     */
    private $refData = null;

    /**
     * Flagged Questions Model
     *
     * @var object
     */
    private $flgData = null;

    /**
     * Current scope (type) of custom field. (Allowed: `case` or `thirdparty`)
     *
     * @var string
     */
    protected $scope = '';

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
     * Enable reference fields?
     *
     * @var boolean
     */
    protected $hasRef = false;

    /**
     * Enable flagged questions?
     *
     * @var boolean
     */
    protected $hasFlagged = false;

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
        parent::__construct($tenantID, $userID, $initValues);
        if (!$listTypeID || ($listTypeID != 10 && $listTypeID != 130)) {
            throw new \InvalidArgumentException("The listTypeID must be a postitive integer value of 10 or 130.");
        }
        $this->scope = ($listTypeID == 10) ? 'case' : 'thirdparty';
        if ($this->scope == 'case') {
            $settingACL = new SettingACL($this->tenantID);
            $this->hasRef = (($s = $settingACL->get(SettingACL::ENABLE_REFERENCE_FIELDS)) ? $s['value']:null);
            // this is the same as above, but may be spread to separate features in the future.
            // if feature constant changes, be sure to update the flagged questions model as well.
            $this->hasFlagged = (($s = $settingACL->get(SettingACL::ENABLE_REFERENCE_FIELDS)) ? $s['value']:null);
            if ($this->hasRef) {
                $this->refData = new CustomRefData($this->tenantID);
            }
            if ($this->hasFlagged) {
                $this->flgData = new FlaggedQuestions($this->tenantID);
            }
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
        $this->tbl->field     = $clientDB .'.customField';
        $this->tbl->exclude   = $clientDB .'.customFieldExclude';
        $this->tbl->flagged   = $clientDB .'.customFieldFlagged';
        $this->tbl->data      = $clientDB .'.customData';
        $this->tbl->tpType    = $clientDB .'.tpType';
        $this->tbl->tpTypeCat = $clientDB .'.tpTypeCategory';
        $this->tbl->listItems = $clientDB .'.customSelectList';
    }

    /**
     * Return current scope
     *
     * @return string (returns `case` or `thirdparty`)
     */
    public function getScope()
    {
        return $this->scope;
    }

    /**
     * Return value of $this->hasRef
     *
     * @return boolean
     */
    public function hasRefFields()
    {
        return $this->hasRef;
    }

    /**
     * Return value of $this->hasFlagged
     *
     * @return boolean
     */
    public function hasFlaggedQuestions()
    {
        return $this->hasFlagged;
    }

    /**
     * Get all 3P Types w/ categories
     *
     * @return array Array of Objects  (db result)
     */
    public function getTypeCats()
    {
        $sql = "SELECT t.id AS typeID, t.name AS typeName, c.id AS catID, c.name AS catName \n"
            ."FROM {$this->tbl->tpType} AS t \n"
            ."LEFT JOIN {$this->tbl->tpTypeCat} AS c ON ( t.id = c.tpType AND t.clientID = c.clientID ) \n"
            ."WHERE t.clientID = :tenantID \n"
            ."ORDER BY t.name ASC , c.name ASC";
        $recs = $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID]);

        // now we organize
        $list = [];
        $i = 0;
        $typeID = 0;
        $excludes = $this->getExcluded();
        foreach ($recs as $r) {
            if ($r->typeID != $typeID && !empty($typeID)) {
                $i++;
            }
            $typeID = $r->typeID;
            $exFields = (array_key_exists($r->catID, $excludes)) ? json_encode($excludes[$r->catID]) : '';
            $list[$i]['type'] = $r->typeName;
            $list[$i]['cats'][] = [
                'typeID' => $r->typeID,
                'catID'  => $r->catID,
                'catName' => $r->catName,
                'excl' => $exFields,
            ];
        }
        return $list;
    }

    /**
     * Get Custom Lists
     *
     * @return array
     */
    public function getLists()
    {
        $sql = "SELECT id, name, listName FROM {$this->tbl->listItems} \n"
                ."WHERE clientID = :tenantID ORDER BY sequence ASC, name ASC";
        $recs = $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID]);
        $lists = [];
        foreach ($recs as $r) {
            $lists[$r->listName]['name'] = $r->listName;
            $lists[$r->listName]['vals'][] = ['val' => $r->id, 'txt' => $r->name];
        }
        ksort($lists);
        return $lists;
    }

    /**
     * Get reference list values
     *
     * @return array
     */
    public function getRefListValues()
    {
        $list = [];
        if ($this->hasRef) {
            $refs = $this->refData->getRefOpts();
            foreach ($refs as $r) {
                $list[] = ['id' => $r['id'], 'name' => $r['name']];
            }
        }
        return $list;
    }

    /**
     * Get flagged question lists and values
     * Returned format is a multidimensional array. See model for specific array keys/info
     *
     * @return array
     */
    public function getFlaggedData()
    {
        return $this->flgData->getFlaggedQuestionLists();
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
     * @param integer $id customField.id
     *
     * @return object DB result object
     */
    public function getSingleField($id)
    {
        return $this->fetchFields($id);
    }

    /**
     * Create a list of chains for custom fields ("show to right of...")
     *
     * @param array $fields Array of DB object Rows (output of fetchFields)
     *
     * @return array Array where keys are the primary id, with value of sub array of all chained keys.
     *                 [ 'fieldID' => [1stChainID, 2ndChainID, 3rdChainID, etc] ]
     */
    public function makeFieldChains($fields)
    {
        $chains = [];
        foreach ($fields as $f) {
            if ($f->chainAfter > 0) {
                $chains[$f->chainAfter][] = $f->id;
            }
        }
        return $chains;
    }


    /**
     * Save custom field
     *
     * @param array $vals Values to be saved
     *
     * @return array ['Result' => 1/0, (sets ErrTitle/Msg if validation fails)]
     */
    public function save($vals)
    {
        $vals = $this->mapFields($vals);
        $oldVals = [];
        if (!empty($vals['id'])) {
            // get current values if record exists (validation will puke if record is bad)
            $oldVals = (array)$this->fetchFields($vals['id']);
            $oldVals = $this->mapFields($oldVals);
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
                    $msg[] = $k .': `'. $oldVals[$k] .'` => `'. $v .'`';
                } else {
                    $msg[] = $k .': `'. $v .'`';
                }
            }
            $msg = implode(', ', $msg);
            $event = (($this->scope == 'case') ? 60 : 63);
        } else {
            $msg = 'name: `'. $vals['name'] .'`';
            $event = (($this->scope == 'case') ? 59 : 62);
        }
        if (strlen($msg) > 0) {
            $this->LogData->saveLogEntry($event, $msg);
        }
        return ['Result' => 1, 'FieldID' => $r['id']];
    }

    /**
     * Save flagged question values
     *
     * @param array   $vals    [ ddqID => [qstnID => [vals]] ]
     * @param integer $fieldID customField.id
     *
     * @return array
     */
    public function saveFlagged($vals, $fieldID)
    {
        $fieldID = (int)$fieldID;
        if (!$vals || !is_array($vals) || $fieldID <= 0) {
            return;
        }
        $ttlNumQs  = 0;
        $numErrors = 0;
        foreach ($vals as $ddqID => $questions) {
            foreach ($questions as $qID => $q) {
                switch ($q['flag']) {
                    case 'add':
                        $ttlNumQs++;
                        if (!$this->flaggedQuestionAdd($fieldID, $q)) {
                            $numErrors++;
                        }
                        break;
                    case 'mod':
                        $ttlNumQs++;
                        if (!$this->flaggedQuestionUpdate($fieldID, $q)) {
                            $numErrors++;
                        }
                        break;
                    case 'del':
                        $ttlNumQs++;
                        if (!$this->flaggedQuestionRemove($fieldID, $q)) {
                            $numErrors++;
                        }
                        break;
                }
            }
        }
        if ($numErrors > 0) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['unexpected_error'],
                'ErrMsg'   => str_replace(
                    ['{fails}', '{ttl}'],
                    [$numErrors, $ttlNumQs],
                    (string) $this->txt['flagged_questions_save_error']
                ),
            ];
        }
        return ['Result' => 1];
    }

    /**
     * Remove a record
     *
     * @param array $vals Post values ['id' => customField.id, 'name' => customField.name]
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove($vals)
    {
        if ($this->canDelete($vals['id'])) {
            $del = $this->DB->query(
                "DELETE FROM {$this->tbl->field} WHERE id = :id AND clientID = :tenantID",
                [':id' => $vals['id'], ':tenantID' => $this->tenantID]
            );
            if (!$del->rowCount()) {
                return [
                    'Result' => 0,
                    'ErrTitle' => $this->txt['unexpected_error'],
                    'ErrMsg'   => $this->txt['status_Tryagain'],
                ];
            }
            $this->LogData->saveLogEntry(($this->scope == 'case' ? 61 : 64), 'field: `'. $vals['name'] .'`');
            return ['Result' => 1];
        }
        return [
            'Result' => 0,
            'ErrTitle' => $this->txt['unexpected_error'],
            'ErrMsg'   => $this->txt['status_Tryagain'],
        ];
    }


    /**
     * Public for removing all flagged questions when a field is completely removed.
     *
     * @param integer $fieldID customFields.id
     *
     * @return void
     */
    public function removeAllFlaggedByField($fieldID)
    {
        $this->flaggedQuestionsRemoveAllField($fieldID);
    }

    /**
     * Update custom field exclusions
     *
     * @param array $vals Indexed array of values [0 =>[cat => tpTypeCat.id, fld => customField.id, ]]
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function updateExcl($vals)
    {
        if (!$vals || count($vals) < 1) {
            return ['Result' => 1];
        }
        $addQ = "INSERT INTO {$this->tbl->exclude} SET clientID = :tenantID, tpCatID = :cat, cuFldID = :fld";
        $existsQ = "SELECT COUNT(clientID) FROM {$this->tbl->exclude} "
            ."WHERE clientID = :tenantID AND tpCatID = :cat AND cuFldID = :fld LIMIT 1";
        $removeQ = "DELETE FROM {$this->tbl->exclude} "
            ."WHERE clientID = :tenantID AND tpCatID = :cat AND cuFldID = :fld LIMIT 1";
        $err = [];
        foreach ($vals as $v) {
            if (!isset($v['checked'])) {
                continue;
            }
            $params = [
                ':tenantID' => $this->tenantID,
                ':cat' => $v['cat'],
                ':fld' => $v['fld'],
            ];
            $exists = ($this->DB->fetchValue($existsQ, $params));
            if ($v['checked'] && $exists) {
                $rem = $this->DB->query($removeQ, $params);
                if (!$rem->rowCount()) {
                    $err[] = ['cat' => $v['cat'], 'fld' => $v['fld']];
                }
            } elseif (!$v['checked'] && !$exists) {
                $add = $this->DB->query($addQ, $params);
                if (!$add->rowCount()) {
                    $err[] = ['cat' => $v['cat'], 'fld' => $v['fld']];
                }
            }
        }
        if (count($err) > 0) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['one_error'],
                'ErrMsg' => $this->txt['fl_error_field_exclusion_failed'],
                'Errors' => $err,
            ];
        }
        return ['Result' => 1];
    }

    /**
     * Grab all records or single record.
     *
     * @param integer $id (Optional) customField.id
     *
     * @return object DB result object
     */
    private function fetchFields($id = 0)
    {
        $params = [
            ':cfTenantID'  => (int)$this->tenantID,
            ':cfScope'     => $this->scope,
            ':cf2TenantID' => (int)$this->tenantID,
            ':cf2Scope'    => $this->scope,
            ':cdTenantID' => (int)$this->tenantID,
        ];

        $where = "WHERE cf.clientID = :cfTenantID AND scope = :cfScope";
        $id = (int)$id;
        if (!empty($id) && $id > 0) {
            $where .= " AND cf.id = :cfID LIMIT 1";
            $params[':cfID'] = $id;
        }
        $sql = "SELECT cf.id, cf.name, cf.sequence AS seq, cf.`comment` AS description, \n"
            ."cf.type, cf.size, cf.height, cf.required, cf.hide, cf.chainAfter, cf.prompt, \n"
            ."cf.listName, cf.`minValue`, cf.`maxValue`, cf.refID, cf.decimals, \n"
            ."(SELECT ch.id FROM {$this->tbl->field} AS ch WHERE ch.chainAfter = cf.id LIMIT 1) AS isChainedBy, \n"
            ."IF(\n"
                ."(SELECT(\n"
                    ."(\n"
                        ."SELECT COUNT(cf2.id) FROM {$this->tbl->field} AS cf2 \n"
                        ."WHERE cf2.clientID = :cf2TenantID AND cf2.chainAfter = cf.id AND cf2.scope = :cf2Scope \n"
                    .") + ( \n"
                        ."SELECT COUNT(cd.id) FROM {$this->tbl->data} AS cd \n"
                        ."WHERE cd.fieldDefID = cf.id AND cd.clientID = :cdTenantID \n"
                    .") \n"
                .") \n"
            .") > 0, 0, 1) AS canDel \n"
            ."FROM {$this->tbl->field} AS cf \n"
            .$where ." \n";
        if (!$id) {
            $sql .= "ORDER BY cf.sequence ASC, cf.name ASC \n";
        }
        $recs = $this->DB->fetchObjectRows($sql, $params);
        if ($id > 0 && isset($recs[0])) {
            return $recs[0];
        }
        return $recs;
    }

    /**
     * Save custom field to the database
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
            'scope' => $this->scope,
        ];
        $updt = (!empty($vals['id']) && $vals['id'] > 0) ? true : false;
        $curID = 0;
        $sql = 'INSERT INTO ';
        $ins = ", clientID = :tenantID, scope = :scope";
        $where = '';
        $params = [':tenantID' => $this->tenantID, ':scope' => $this->scope];

        $validate = $this->validateFields($vals, $oldVals);
        if ($validate['Result'] == 0 || isset($validate['id'])) {
            return $validate;
        }
        $vals = $validate['vals'];

        if ($updt) {
            $sql = 'UPDATE ';
            $where = 'WHERE id = :id AND clientID = :tenantID';
            $ins = " \n";
            $curID = $params[':id'] = $vals['id'];
            unset($vals['id'], $params[':scope']);
        }

        $sql .= $this->tbl->field ." SET \n";
        $rows = [];
        foreach ($vals as $k => $v) {
            $rows[] = '`'. $k .'` = :'. $k;
            $params[':'. $k] = $v;
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
     * Add new flagged question.
     *
     * @param integer $fieldID customField.id
     * @param array   $qstn    Array of values for save operation
     *
     * @return boolean
     */
    private function flaggedQuestionAdd($fieldID, $qstn)
    {
        $sql = "INSERT INTO {$this->tbl->flagged} SET "
            ."fieldID = :fID, "
            ."clientID = :cID, "
            ."caseType = :cType, "
            ."ddqQuestionVer = :qVer, "
            ."qID = :qID, "
            ."displayName = :name, "
            ."flaggedAnswer = :ans ";
        $params = [
            ':fID' => $fieldID,
            ':cID' => $this->tenantID,
            ':cType' => $qstn['caseType'],
            ':qVer'  => $qstn['ddqVer'],
            ':qID'   => $qstn['id'],
            ':name'  => $qstn['name'],
            ':ans'   => json_encode($qstn['val'])
        ];
        $r = $this->DB->query($sql, $params);
        if (!$r->rowCount()) {
            return false;
        }
        $this->LogData->saveLogEntry(
            164,
            'question: `'. $params[':name'] .' ('. $params[':qID'] .')`; '
                .'flagged answers: `'. $params[':ans'] .'`'
        );
        return true;
    }

    /**
     * Update flagged question.
     *
     * @param integer $fieldID customField.id
     * @param array   $qstn    Array of values for save operation
     *
     * @return boolean
     */
    private function flaggedQuestionUpdate($fieldID, $qstn)
    {
        $where = "WHERE fieldID = :fID AND clientID = :cID AND caseType = :cType "
            ."AND ddqQuestionVer = :qVer AND qID = :qID";
        $sql = "SELECT flaggedAnswer FROM {$this->tbl->flagged} {$where} LIMIT 1";
        $params = [
            ':fID' => $fieldID,
            ':cID' => $this->tenantID,
            ':cType' => $qstn['caseType'],
            ':qVer'  => $qstn['ddqVer'],
            ':qID'   => $qstn['id'],
        ];
        $curAnswers = $this->DB->fetchValue($sql, $params);
        $sql2 = "UPDATE {$this->tbl->flagged} SET flaggedAnswer = :ans {$where}";
        $params[':ans'] = json_encode($qstn['val']);
        $r = $this->DB->query($sql2, $params);
        if (!$r->rowCount()) {
            return false;
        }
        $this->LogData->saveLogEntry(
            165,
            'question: `'. $qstn['name'] .' ('. $params[':qID'] .')`; '
            .'flagged answers: `'. $curAnswers .'` updated to `'. $params[':ans'] .'`'
        );
        return true;
    }

    /**
     * Remove flagged question.
     *
     * @param integer $fieldID customField.id
     * @param array   $qstn    Array of values for save operation
     *
     * @return boolean
     */
    private function flaggedQuestionRemove($fieldID, $qstn)
    {
        $where = "WHERE fieldID = :fID AND clientID = :cID AND caseType = :cType "
            ."AND ddqQuestionVer = :qVer AND qID = :qID";
        $sql = "DELETE FROM {$this->tbl->flagged} {$where} LIMIT 1";
        $params = [
            ':fID' => $fieldID,
            ':cID' => $this->tenantID,
            ':cType' => $qstn['caseType'],
            ':qVer'  => $qstn['ddqVer'],
            ':qID'   => $qstn['id'],
        ];
        $r = $this->DB->query($sql, $params);
        if (!$r->rowCount()) {
            return false;
        }
        $this->LogData->saveLogEntry(
            166,
            'question: `'. $qstn['name'] .' ('. $params[':qID'] .')`; '
                .'flagged answers: `'. json_encode($qstn['val']) .'`'
        );
        return true;
    }


    /**
     * Remove all flagged questions when a field is completely removed
     * This removes ANY flagged question for the specified fieldID, and
     * NOT just the current ddq version. (If the the field is gone, then
     * you don't want orphanded questions laying around.
     *
     * @param integer $fieldID customField.id
     *
     * @return void
     */
    private function flaggedQuestionsRemoveAllField($fieldID)
    {
        $fieldID = (int)$fieldID;
        if ($fieldID <= 0) {
            return;
        }
        $this->DB->query(
            "DELETE FROM {$this->tbl->flagged} WHERE fieldID = :fID AND clientID = :cID",
            [':fID' => $fieldID, ':cID' => $this->tenantID]
        );
    }


    /**
     * Get excluded fields
     *
     * @return array sorted by cat and type
     */
    private function getExcluded()
    {
        $sql = "SELECT tpCatID, cuFldID FROM {$this->tbl->exclude} WHERE clientID = :tenantID";
        $params = [':tenantID' => $this->tenantID];
        $recs = $this->DB->fetchObjectRows($sql, $params);
        $rtn = [];
        foreach ($recs as $r) {
            $rtn[$r->tpCatID][] = $r->cuFldID;
        }
        return $rtn;
    }

    /**
     * Get reference info that tells us which table entry to look for
     *
     * @param int $id ID in refFields table
     *
     * @return mixed
     */
    public function getRefInfo($id = 0)
    {
        return $this->DB->fetchObjectRows("SELECT * FROM {$this->tbl->refFlds} WHERE id = :refID", [':refID' => $id]);
    }

    /**
     * Validate data input
     *
     * @param array $vals    Vals to be checked
     * @param array $oldVals Current record vals (optional, only if record exists)
     *
     * @return array Return array with result status, and validated data (or error info if applicable).
     */
    private function validateFields($vals, $oldVals = [])
    {
        $validateFuncs = new ValidateFuncs();
        if (!isset($vals['id'])) {
            $vals['id'] = 0;
        }
        if (isset($vals['seq'])) {
            $vals = $this->mapFields($vals);
        }
        //validation for prompt
        if (empty($vals['prompt'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => 'Prompt cannot be empty.',
            ];
        } else if (!$validateFuncs->checkInputSafety($vals['prompt'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => 'Prompt contains unsafe contents like html tags, javascript, or other unsafe characters.',
            ];
        }
         //validation for description
        if (!empty($vals['comment']) && !$validateFuncs->checkInputSafety($vals['comment'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => 'Description contains unsafe contents like html tags, javascript, or other unsafe characters.',
            ];
        }
        if (!$this->validType($vals['type'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_invalid_type_for_field'],
            ];
        } elseif (empty($vals['name']) || !preg_match('/^[0-9a-zA-Z_-]+$/', (string) $vals['name'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_name_characters'],
            ];
        }
        if ($this->DB->fetchValue(
            "SELECT id FROM {$this->tbl->field} \n"
            . "WHERE clientID = :clientID AND scope = :scope AND name = :name \n"
            . "AND id <> :id LIMIT 1",
            [
                ':clientID' => $this->tenantID,
                ':scope' => $this->scope,
                ':name' => $vals['name'],
                ':id' => $vals['id']
            ]
        )) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_name_already_exists'],
            ];
        }
        if (empty($vals['prompt'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_please_specify_prompt'],
            ];
        }
        if (empty($vals['name']) || !preg_match('/^[-0-9a-zA-Z_]+$/', (string) $vals['name'])) {
            $vals['name'] = $this->genNameFromPrompt($vals['prompt']);
        }
        if (!empty($vals['id']) && $vals['id'] !== 0) {
            $vals['id'] = (int)$vals['id'];
            if (empty($oldVals)) {
                return [
                    'Result'   => 0,
                    'ErrTitle' => $this->txt['error_record_invalid_title'],
                    'ErrMsg'   => $this->txt['error_record_invalid_status'],
                ];
            }
            if (!$this->valuesChanged($vals, $oldVals)) {
                return ['Result' => 1, 'id' => $vals['id']];
            }
        }
        if (in_array($vals['type'], ['radio', 'check', 'select'])) {
            $validList = $this->validateList($vals['type'], $vals['listName']);
            if (!$validList['Result']) {
                return $validList;
            }
        }
        if (!in_array($vals['type'], ['section', 'multiline']) && !empty($vals['chainAfter'])) {
            $validChain = $this->validateChain($vals['chainAfter']);
            if (!$validChain['Result']) {
                return $validChain;
            }
        }
        if ($vals['type'] == 'ref') {
            $vals['refID'] = (int)$vals['refID'];
            if ($vals['refID'] <= 0) {
                $vals['refID'] = 0;
            } elseif (!$this->refData->validRefField($vals['refID'])) {
                $vals['refID'] = 0;
            }
        }
        $vals['size'] = $this->validateSizeHeight($vals['size']);
        $vals['height'] = $this->validateSizeHeight($vals['height']);
        $vals = $this->validateNumerics($vals);

        $rtn = ['Result' => 1, 'vals' => $vals];
        if (!empty($oldVals)) {
            $rtn['oldVals'] = $oldVals;
        }
        return $rtn;
    }

    /**
     * Weed out any garbage from passed data to only allow valid fields, and map to correct db cols.
     *
     * @param array $vars Data array of values passed
     *
     * @return array Return array with only valid field names
     */
    private function mapFields($vars)
    {
        /**
         * k => v format
         * k = 'key used'
         * v = 'actual db column'
         */
        $fieldMap = [
            'id'   => 'id',
            'name' => 'name',
            'seq' => 'sequence',
            'type' => 'type',
            'size' => 'size',
            'height' => 'height',
            'hide' => 'hide',
            'chainAfter' => 'chainAfter',
            'prompt' => 'prompt',
            'listName' => 'listName',
            'description' => 'comment',
            'decimals' => 'decimals',
            'minValue' => 'minValue',
            'maxValue' => 'maxValue',
            'refID' => 'refID',
        ];
        $rtn = [];
        foreach ($vars as $k => $v) {
            if (!array_key_exists($k, $fieldMap)) {
                continue;
            }
            $rtn[$fieldMap[$k]] = $v;
        }
        return $rtn;
    }

    /**
     * Verify a valid type was passed
     *
     * @param string $type customField.type
     *
     * @return boolean True if valid, else false
     */
    private function validType($type)
    {
        $allowed = [
            'simple', 'numeric', 'date', 'multiline', 'radio', 'check',
            'select', 'section', 'ref', 'flagged'
        ];
        if (in_array($type, $allowed)) {
            return true;
        }
        return false;
    }

    /**
     * Validate/verify that the data posted will change current record values
     *
     * @param array $new Newly posted data array for updating a custom field
     * @param array $old Current record values for the custom field
     *
     * @return array Return true if any change is noted
     */
    private function valuesChanged($new, $old)
    {
        foreach ($new as $k => $v) {
            if (isset($old[$k]) && $new[$k] != $old[$k]) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verify list selected is valid, and has required number of entries to be used as a source for custom field
     *
     * @param string $type customField.type
     * @param string $name customField.listName (comes from customSelectList.listName)
     *
     * @return array Response => bool, and if fails will also set ErrTitle/Msg
     */
    private function validateList($type, $name)
    {
        $lists = $this->getLists();
        if (empty($name) || !array_key_exists($name, $lists)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_list_cannot_be_empty'],
            ];
        }
        $min = (($type == 'radio') ? 2 : 1);
        if (count($lists[$name]['vals']) < $min) {
            $minTxt = (($min == 1) ? $this->txt['one'] : $this->txt['two']);
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => str_replace('{_one|two_}', $minTxt, (string) $this->txt['fl_error_field_requires_list_items']),
            ];
        }
        return ['Result' => 1];
    }

    /**
     * Validate that custom field may be "chained" after the specified field
     *
     * @param integer $chainID customField.id
     *
     * @return array Response => bool, and if fails will also set ErrTitle/Msg
     */
    private function validateChain($chainID)
    {
        $rec = $this->fetchFields($chainID);
        if (!$rec) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_field_chain_not_exist'],
            ];
        }
        if ($rec->hide == 1) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_invalid_input'],
                'ErrMsg'   => $this->txt['fl_error_field_chain_cannot_be_hidden'],
            ];
        }
        return ['Result' => 1];
    }

    /**
     * Validate size/height field
     *
     * @param string $val Value of the size/height field
     *
     * @return string Return value if valid, else default value
     */
    private function validateSizeHeight($val)
    {
        $allowed = ['tiny', 'small', 'normal', 'medium', 'large', 'xlarge'];
        return ((in_array($val, $allowed)) ? $val : 'medium');
    }

    /**
     * Validate numeric and boolean fields in the data array
     *
     * @param array $vals Array of values to be sanitized
     *
     * @return array
     */
    private function validateNumerics($vals)
    {
        $nums  = ['sequence', 'chainAfter', 'decimals', 'minValue', 'maxValue', 'refID'];
        foreach ($nums as $k) {
            if (array_key_exists($k, $vals)) {
                $vals[$k] = (int)$vals[$k];
                if ($k != 'sequence') {
                    $vals[$k] = ($vals[$k] < 0) ? 0 : $vals[$k];
                }
            }
        }
        $vals['hide'] = ($vals['hide'] == 1 ? 1:0);
        return $vals;
    }

    /**
     * Generate a field name from the prompt.
     *
     * @param string $prompt customField.prompt/posted value for prompt
     *
     * @return string New name value
     */
    private function genNameFromPrompt($prompt)
    {
        $maxLength = 40;
        $newName   = '';
        $charCheck = '';
        $prompt    = strtolower($prompt);
        for ($i=0; $i<strlen($prompt); $i++) {
            $charCheck = substr($prompt, $i, 1);
            if (!preg_match('/[-_a-z0-9 ]/', $charCheck)) {
                continue;
            }
            if ($charCheck == ' ') {
                $charCheck = '_';
            }
            $newName .= $charCheck;
        }
        if (empty($newName)) {
            // complete precaution, but since this field can be updated in the UI,
            // seems logical to give this as a last resort.
            $newName = $this->genNameFromPrompt(substr(sha1($prompt), 0, 15));
        }
        return $newName;
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id customField.id
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
