<?php
/**
 * Model for the Fields/Lists 3P Approval Reasons data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Lib\Validation\ValidateFuncs;

/**
 * Class TpApprvRsnsData handles basic data modeling for the TPM application fields/lists.
 *
 * @keywords tpm, fields lists, model, settings, approval reasons
 */
#[\AllowDynamicProperties]
class TpApprvRsnsData extends TpmListsData
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
     * @var array Allowed appType values
     */
    private $typesAllowed = ['approved', 'denied', 'pending'];

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
        $this->tbl = $clientDB .'.tpApprovalReasons';
    }

    /**
     * Public wrapper to get all records of specified parent
     *
     * @param string $type tpApprovalReasons.appType
     *
     * @return array standard result array, with key 'Records' being DB result object
     */
    public function getReasons($type)
    {
        $setDefaultRecs = $this->ensureBaselineRecs();
        if ($setDefaultRecs['Result'] != 1) {
            return $setDefaultRecs;
        }
        return $this->fetchReasons($type);
    }

    /**
     * Get all records for specified parent, or a single record if $reasonID is present.
     *
     * @param string  $type     tpApprovalReasons.appType (required)
     * @param integer $reasonID tpApprovalReasons.id (optional)
     *
     * @return array standard result array, with key 'Records' being DB result object
     */
    protected function fetchReasons($type, $reasonID = 0)
    {
        $reasonID = ((int)$reasonID <= 0) ? 0 : $reasonID;
        if (!in_array($type, $this->typesAllowed)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['one_error'],
                'ErrMsg'   => $this->txt['fl_error_bad_approval_type'],
            ];
        }
        $params = [':tenantID' => $this->tenantID, ':type' => $type];
        $sql = "SELECT id, appType, reason, active, \"0\" AS canDel FROM {$this->tbl} \n"
            ."WHERE ";
        if ($reasonID > 0) {
            $sql .= "id = :id AND ";
            $params[':id'] = $reasonID;
        }
        $sql .= "clientID = :tenantID AND appType = :type \n"
            ."ORDER BY active DESC, reason ASC";

        if ($reasonID > 0) {
            return ['Result' => 1, 'Records' => $this->DB->fetchObjectRow($sql, $params)];
        }
        return ['Result' => 1, 'Records' => $this->DB->fetchObjectRows($sql, $params)];
    }

    /**
     * Save a record
     *
     * @param array $vals Array(
     *                      'id'    => customSelectList.name, [optional]
     *                      'appType'  => customSelectList.sequence
     *                      'reason'   => customSelectList.sequence
     *                      'active'   => customSelectList.sequence
     *                    )
     *
     * @return array Return array with result status and error info if applicable.
     */
    public function saveReason($vals)
    {
        $validateFuncs = new ValidateFuncs();
        // basic sanitization
        $isUpdt = (!empty($vals['id']) && intval($vals['id']) > 0) ? 1 : 0;
        if (empty($vals['appType']) || !in_array($vals['appType'], $this->typesAllowed)) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['one_error'],
                'ErrMsg'   => $this->txt['fl_error_bad_approval_type'],
            ];
        }
        if (empty($vals['reason'])) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['one_error'],
                'ErrMsg'   => $this->txt['fl_error_missing_reason'],
            ];
        } elseif (strlen((string) $vals['reason']) > 75) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['one_error'],
                'ErrMsg'   => str_replace('{#}', 75, (string) $this->txt['invalid_MaxLength']),
            ];
        } else if (!$validateFuncs->checkInputSafety($vals['reason'])) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['fl_error_invalid_name'],
                'ErrMsg' => 'Reason must be alphanumeric with spaces, dashes, underscores.',
            ];
        }
        $vals['active'] = (!empty($vals['active']) ? 1:0);
        $params = [
            ':tenantID' => $this->tenantID,
            ':type' => $vals['appType'],
            ':reason' => $vals['reason'],
            ':active' => $vals['active']
        ];
        $oldVals = (object)null;
        $sql = "INSERT INTO {$this->tbl} SET "
            ."clientID = :tenantID, appType = :type, reason = :reason, active = :active";
        if ($isUpdt) {
            $oldVals = $this->fetchReasons($vals['appType'], $vals['id']);
            $oldVals = $oldVals['Records'];
            unset($vals['clientID']); // not needed as an update value, just in the where clause
            if (($vals['reason'] == $oldVals->reason) && ($vals['appType'] == $oldVals->appType)
                && ($vals['active'] == $oldVals->active)) {
                // nothing changed at all, just return positive result
                $recs = $this->fetchReasons($vals['appType']);
                return ['Result' => 1, 'Records' => $recs['Records']];
            }
            $logID = 157;
            $params[':id'] = $vals['id'];
            $sql = "UPDATE {$this->tbl} SET reason = :reason, active = :active "
                ."WHERE id = :id AND clientID = :tenantID AND appType = :type";
        }
        $save = $this->DB->query($sql, $params);
        $itemID = ((!$isUpdt) ? $this->DB->lastInsertId() : (int)$vals['id']);
        if (!$save->rowCount()) {
            return [
                'Result' => 0,
                'errors'    => [
                    'title' => $this->txt['title_operation_failed'],
                    'msg'   => $this->txt[(!$isUpdt ? 'add_record_failed' : 'update_record_failed')],
                ],
            ];
        }
        $recID = ((!$isUpdt) ? $this->DB->lastInsertId() : $vals['id']);
        $this->logSaveData($params, $oldVals, $isUpdt);
        $recs = $this->fetchReasons($vals['appType']);
        return ['Result' => 1, 'ItemID' => $itemID, 'Records' => $recs['Records']];
    }

    /**
     * Make sure they have at least 1 active record for each type.
     *
     * @return array Return standard results array, with error title/message if applicable.
     */
    private function ensureBaselineRecs()
    {
        $sql = "SELECT COUNT(id) FROM {$this->tbl} \n"
            . "WHERE clientID = :tenantID AND appType = :appType AND active = :active";
        $params = [
            ':tenantID' => $this->tenantID,
            ':appType'  => '',
            ':active'   => 1,
        ];
        foreach ($this->typesAllowed as $type) {
            $params[':appType'] = $type;
            $numRecs = $this->DB->fetchValue($sql, $params);
            if ($numRecs < 1) {
                $save = $this->saveReason([
                    'appType' => $type,
                    'reason'  => ucfirst((string) $type),
                    'active'  => 1
                ]);
                if ($save['Result'] != 1) {
                    return $save;
                }
            }
        }
        return ['Result' => 1];
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
        $logMsg = [];
        $logMsg[] = 'type: `'. $params[':type'] .'`';
        if (!$updt) {
            $logID = 156;
            $logMsg[] = 'reason: `'. $params[':reason'] .'`, type: `'. $params[':type'] .'`';
        } else {
            $logID = 157;
            if ($params[':reason'] != $oldRec->reason) {
                $logMsg[] = 'reason: `'. $oldRec->reason .'` => `'. $params[':reason'] .'`';
            } else {
                $logMsg[] = 'reason: `'. $params[':reason'] .'`';
            }
            if ($params[':type'] != $oldRec->appType) {
                $logMsg[] = 'type: `'. $oldRec->appType .'` => `'. $params[':type'] .'`';
            }
            if ($params[':active'] != $oldRec->active) {
                $logMsg[] = 'active: `'. ($oldRec->active == 1 ? 'Yes':'No') .'` => `'.
                    ($params[':active'] == 1 ? 'Yes':'No') .'`';
            }
        }
        $logMsg = implode(', ', $logMsg);
        $this->LogData->saveLogEntry($logID, $logMsg);
    }
}
