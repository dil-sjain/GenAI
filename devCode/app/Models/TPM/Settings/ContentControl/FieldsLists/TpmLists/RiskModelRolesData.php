<?php
/**
 * Data handler for the Risk Areas Fields/Lists operations.
 *
 * This class was specifically written for Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\RiskModelRoles.
 * Its public methods may not be suitable for re-use in other contexts. If they are
 * re-used the caller must assume responsibility for argument validation and formatting.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use Models\ThirdPartyManagement\Admin\TP\Risk\UpdateScoresData;
use Exception;
use Lib\Validation\ValidateFuncs;

/**
 * Class RiskModelRolesData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords risk area, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class RiskModelRolesData extends TpmListsData
{
    /**
     * @var integer The next order difference number
     */
    protected int $orderGap = 10;

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $userID     Current userID
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $userID, $initValues = array())
    {
        parent::__construct($tenantID, $userID, $initValues);
    }

    /**
     * Setup required table names in the tbl object. This overwrites parent stub method.
     *
     * @return void
     */
    protected function setupTableNames(): void
    {
        $clientDB  = $this->DB->getClientDB($this->tenantID);
        $this->tbl->riskModelRoleTbl = $clientDB . '.riskModelRole';
        $this->tbl->riskModels = $clientDB . '.riskModel';
    }

    /**
     * Public wrapper to grab all records
     *
     * @return array DB object array, else error
     */
    public function getRiskModelRoles(): array
    {
        $rows = [];
        try {
            $sql = $this->getRiskModelRolesSQL();
            $params = [
                ':clientID1' => $this->tenantID,
                ':clientID2' => $this->tenantID,
                ':clientID3' => $this->tenantID,
                ':clientID4' => $this->tenantID,
            ];
            $sql .= 'ORDER BY orderNum, role.name ASC';
            $rows = $this->DB->fetchObjectRows($sql, $params);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        return $rows;
    }

    /**
     * Public wrapper to grab a single record by id
     *
     * @param integer $id riskModelRole.id
     *
     * @return \stdClass DB object- array, else error.
     */
    public function getRiskModelRole(int $id): \stdClass
    {
        $rows = (object) [];
        try {
            $sql = $this->getRiskModelRolesSQL();
            $sql .= " AND role.id = :id";
            $params = [
                ':clientID1' => $this->tenantID,
                ':clientID2' => $this->tenantID,
                ':clientID3' => $this->tenantID,
                ':clientID4' => $this->tenantID,
                ':id' => $id
            ];
            $rows = $this->DB->fetchObjectRow($sql, $params);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        return $rows;
    }

    /**
     * Sql to get records with canDelete/canDeativate Status
     *
     * @return string SQL query
     */
    protected function getRiskModelRolesSQL(): string
    {
        $sql = "SELECT role.id, role.name, role.orderNum AS numFld, role.active AS ckBox,
                IF(
                    (IF(
                        (SELECT COUNT(id) FROM {$this->tbl->riskModelRoleTbl}
                            WHERE clientID = :clientID1 AND active = 1) < 2,
                        (IF(role.active = 0, 0, 1)), 0
                    )  + (SELECT COUNT(id) FROM {$this->tbl->riskModels}
                            WHERE clientID = :clientID2 AND riskModelRole = role.id)
                        ) > 0, 0, 1
                ) as canDel,
                IF(
                    (SELECT COUNT(id) FROM {$this->tbl->riskModelRoleTbl}
                        WHERE clientID = :clientID3 AND active = 1
                ) < 2, 0, 1) as canDeactive 
                FROM {$this->tbl->riskModelRoleTbl} AS role WHERE role.clientID = :clientID4 ";
            return $sql;
    }

    /**
     * Add a new record
     *
     * @param array $vals Expects: in key -> value array
     *
     * @return array Return array with result status and error info if applicable.
     */
    public function add(array $vals): array
    {
        $validateFuncs = new ValidateFuncs();
        $roleName = isset($vals['name']) ? $vals['name'] : '';
        $result = [
            'Result' => 0,
            'ErrTitle' => $this->txt['one_error'],
            'ErrMsg' => $roleName . " risk area couldn't be created, please report this error to support.",
        ];

        try {
            if (!isset($vals['numFld'])
                || !isset($vals['ckBox']) || empty($roleName)
                || empty($vals['numFld'])
            ) {
                $result['ErrTitle'] = $result['ErrMsg'] = "Required input is missing!";
            } elseif ($this->roleNameExists($roleName, $this->tbl->riskModelRoleTbl)) {
                $result['ErrTitle'] = $this->txt['duplicate_entry'];
                $result['ErrMsg'] = 'A risk area with same ' . $roleName . ' name already exist, please try with different name or update existing one.';
            } elseif ($this->orderExists($vals['numFld'])) {
                $result['ErrTitle'] = $this->txt['duplicate_entry'];
                $result['ErrMsg'] = 'Order cannot be duplicate. Please provide unique number for the order.';
            } elseif (!$validateFuncs->checkInputSafety($roleName)) {
                $result['ErrTitle'] = $this->txt['fl_error_invalid_name'];
                $result['ErrMsg'] = 'Name consists of unsafe HTML, javascript or other unsafe content.';
            } else {
                $sql = "INSERT INTO {$this->tbl->riskModelRoleTbl} SET\n"
                    . "createdAt = NOW(), clientID = :clientID,\n"
                    . "name = :name, orderNum = :orderNum, active = :active";
                $params = [
                    ':clientID' => $this->tenantID,
                    ':name' => $roleName,
                    ':orderNum' => intval($vals['numFld']),
                    ':active' => (!empty($vals['ckBox']) ? 1 : 0)
                ];
                $add = $this->DB->query($sql, $params);
                if ($add->rowCount() == 1) {
                    $newID = $this->DB->lastInsertId();
                    $this->LogData->saveLogEntry(
                        216,
                        'name: `' . $roleName . '`,
                        order: `' . $vals['numFld'] . '`,
                        active: `' . $vals['ckBox'] . '`'
                    );
                    $result = [
                        'Result' => 1,
                        'id' => $newID,
                        'customMessage'   => $roleName . ' risk area created successfully!'
                    ];
                }
            }
        } catch (Exception $e) {
            $result['ErrMsg'] = $e->getMessage();
        }
        return $result;
    }

    /**
     * Update a record
     *
     * @param array $vals    Array with new $values
     *
     * @param array $oldVals Array with same keys as $vals, but with the pre-update values.
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function update(array $vals, array $oldVals): array
    {
        $validateFuncs = new ValidateFuncs();
        $result = [
            'Result' => 0,
            'ErrTitle' => $this->txt['error_processing_request'],
            'ErrMsg' => $oldVals['name'] . " risk area couldn't be updated, please report this error to support.",
        ];
        try {
            if (!isset($vals['name']) || !isset($vals['numFld'])
                || !isset($vals['ckBox']) || !isset($oldVals['name'])
                || !isset($oldVals['numFld']) || !isset($oldVals['ckBox'])
                || empty($vals['name']) || empty($vals['numFld'])
            ) {
                $result['ErrTitle'] = $result['ErrMsg'] = "Required input is missing!";
            } elseif ($vals['name'] == $oldVals['name']
                && $vals['ckBox'] == $oldVals['ckBox']
                && $vals['numFld'] == $oldVals['numFld']
            ) {
                $result['ErrTitle'] = $result['ErrMsg'] = $this->txt['data_no_changes_same_value'];
            } elseif ($this->roleNameExists($vals['name'], $this->tbl->riskModelRoleTbl, $vals['id'])) {
                $result['ErrTitle'] = $this->txt['duplicate_entry'];
                $result['ErrMsg'] = 'A risk area with same ' . $vals['name'] . ' name already exist, please try with different name or update existing one.';
            } elseif ($vals['numFld'] != $oldVals['numFld']
                && $this->orderExists($vals['numFld'])
            ) {
                $result['ErrTitle'] = $this->txt['duplicate_entry'];
                $result['ErrMsg'] =  "Order cannot be duplicate. Please provide unique number for the order.";
            } elseif (!$validateFuncs->checkInputSafety($vals['name'])) {
                $result['ErrTitle'] = $this->txt['fl_error_invalid_name'];
                $result['ErrMsg'] = 'Name consists of unsafe HTML, javascript or other unsafe content.';
            } else {
                // If going to deactivate risk area name, then check is it last active risk area?
                if (isset($vals['ckBox'])
                    && $vals['ckBox'] != $oldVals['ckBox']
                    && $vals['ckBox'] != 1
                    && !$this->canDeactivate(intval($vals['id']))
                ) {
                    $result['ErrMsg'] =  "Last active risk area can't be disabled.";
                } else {
                    $sql = "UPDATE {$this->tbl->riskModelRoleTbl} SET
                        name = :name, orderNum = :orderNum, active = :active
                        WHERE id = :id AND clientID = :clientID LIMIT 1";
                    $params = [
                        ':name' => $vals['name'],
                        ':orderNum' => $vals['numFld'],
                        ':active' => (!empty($vals['ckBox']) ? 1 : 0),
                        ':id' => $vals['id'],
                        ':clientID' => $this->tenantID,
                    ];

                    $upd = $this->DB->query($sql, $params);
                    if (!$upd->rowCount()) {
                        $result['ErrTitle'] = $this->txt['data_nothing_changed_title'];
                        $result['ErrTitle'] = $oldVals['name'] . " risk area couldn't be updated, please report this error to support.";
                    } else {
                        // log list name update and result result = 1
                        $logMsg = '';
                        $customMessage = '';

                        if ($vals['name'] != $oldVals['name']) {
                            $logMsg .= 'name: `' . $oldVals['name'] . '` => `' . $vals['name'] . '`';
                        }
                        if ($vals['numFld'] != $oldVals['numFld']) {
                            // If we can delete this, means risk model does not exist for this risk area
                            // In this case no need to call cli
                            $status = $this->canDelete($vals['id']);
                            // Can not delete, call cli
                            if (!$status['Result']) {
                                // Function will get jobId and call cron script to re-calculate the risk ratings.
                                (new UpdateScoresData($this->tenantID))->update();
                            }
                            $logMsg .= 'order: `' . $oldVals['numFld'] . '` => `' . $vals['numFld'] . '`';
                        }
                        if ($vals['ckBox'] != $oldVals['ckBox']) {
                            $logMsg .= (!empty($logMsg) ? ', ' : '') . 'status: `';
                            if ($vals['ckBox'] == 1) {
                                $logMsg .= $this->txt['user_status_inactive'] . '` => `' . $this->txt['user_status_active'] . '`';

                                $customMessage = $vals['name'] . ' risk area enabled successfully!';
                            } else {
                                $logMsg .= $this->txt['user_status_active'] . '` => `' . $this->txt['user_status_inactive'] . '`';

                                $customMessage = $vals['name'] . ' risk area disabled successfully!';
                            }
                        } else {
                            $customMessage = $vals['name'] . ' risk area updated successfully!';
                        }
                        // eventID 217 - Update risk area name
                        $this->LogData->saveLogEntry(217, $logMsg);
                        $result = [
                            'Result' => 1,
                            'customMessage'   => $customMessage
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            $result['ErrMsg'] = $e->getMessage();
        }
        return $result;
    }

    /**
     * Remove a record
     *
     * @param array $vals Expects: key -> value pair
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function remove(array $vals = []): array
    {
        try {
            $result = [
                'Result' => 0,
                'ErrTitle' => $this->txt['error_processing_request'],
                'ErrMsg' => $this->txt['msg_no_changes_were_made'],
            ];

            if (isset($vals['id']) && !empty($vals['id'])) {
                $id = (int)$vals['id'];
                $deleteCheck = $this->canDelete($id);
                if ($id <= 0 ||
                    !$deleteCheck['Result']
                ) {
                    $errorMessage = isset($deleteCheck['ErrMsg'])
                    ? $deleteCheck['ErrMsg']
                    : $this->txt['error_invalid_deletion_msg'];

                    $result['ErrMsg'] = str_replace('<RoleName>', $vals['name'], $errorMessage);
                } else {
                    $sql = "DELETE FROM {$this->tbl->riskModelRoleTbl} WHERE id = :id
                            AND clientID = :clientID AND name = :name LIMIT 1";
                    $params = [':id' => $id, ':clientID' => $this->tenantID, ':name' => $vals['name']];
                    $del = $this->DB->query($sql, $params);
                    if ($del->rowCount()) {
                        $this->LogData->saveLogEntry(218, "name: `" . $vals['name'] . "`");
                        $result = [
                            'Result' => 1,
                            'customMessage' => $vals['name'] . ' risk area deleted successfully!'
                        ];
                    }
                }
            } else {
                $result['ErrTitle'] = $result['ErrMsg'] = "Required input is missing!";
            }
        } catch (Exception $e) {
            $result['ErrMsg'] = $e->getMessage();
        }
        return $result;
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id riskModelRole.id
     *
     * @return array Result index true if can be deleted, else false if cannot.
     */
    protected function canDelete(int $id): array
    {
        $result = [
            'Result' => false,
            'ErrMsg' => $this->txt['error_invalid_deletion_msg']
        ];

        $id = (int)$id;
        $sql = "SELECT  role.active, (SELECT COUNT(id) FROM {$this->tbl->riskModelRoleTbl}
                WHERE clientID = :clientID1 AND active = 1) as activeRoleCount,
                (
                    SELECT COUNT(id) FROM {$this->tbl->riskModels}
                    WHERE clientID = :clientID2 AND riskModelRole =  role.id
                ) as usedRoleCount
                FROM {$this->tbl->riskModelRoleTbl} AS role
                WHERE role.clientID = :clientID3 AND role.id = :id LIMIT 1";
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
            ':clientID3' => $this->tenantID,
            ':id' => $id,
        ];
        $row = $this->DB->fetchObjectRow($sql, $params);
        if ($row) {
            if ($row->activeRoleCount <= 1 && $row->active == 1) {
                // If this is the last active risk area and it's active, can't delete it
                $result['ErrMsg'] = "Last active risk area can't be deleted.";
            } elseif ($row->usedRoleCount > 0) {
                // If this risk area is used in risk model, can't delete it
                $result['ErrMsg'] = "<RoleName> risk area couldn't be deleted as its being used by Risk Model. You can only disable this risk area now.";
            } else {
                // You can delete
                $result = [
                    'Result' => true,
                    'ErrMsg' => ''
                ];
            }
        }
        return $result;
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id riskModelRole.id
     *
     * @return boolean True if can be deactivated, else false if cannot.
     */
    private function canDeactivate(int $id): bool
    {
        $role = $this->getRiskModelRole(intval($id));
        return ($role->canDeactive === 1) ? true : false;
    }

    /**
     * Check if order already exists. No dups allowed.
     *
     * @param integer $num orderNum to lookup
     *
     * @return boolean True if order exists, else false.
     */
    private function orderExists(int $num): bool
    {
        $status = false;
        $sql = "SELECT COUNT(`id`) FROM {$this->tbl->riskModelRoleTbl}
        WHERE clientID = :clientID AND orderNum = :orderNum";
        $params = [':clientID' => $this->tenantID, ':orderNum' => $num];
        if ($this->DB->fetchValue($sql, $params)) {
            $status = true;
        }
        return $status;
    }

    /**
     * Check if active name already exists. No dups allowed.
     *
     * @param string $name Name to lookup
     * @param string $tbl  Table to use for lookup
     * @param int    $id   risk area id to use for lookup
     *
     * @return boolean True if name exists, else false.
     */
    protected function roleNameExists(string $name, string $tbl, int $id = 0): bool
    {
        $status = false;
        $tbl = $this->DB->esc($tbl);
        $sql = "SELECT COUNT(*) FROM {$tbl}
            WHERE clientID= :clientID AND name= :name AND active = 1 AND id != :id";
        $params = [':clientID' => $this->tenantID, ':name' => $name, ':id' => $id];
        if ($this->DB->fetchValue($sql, $params)) {
            $status = true;
        }
        return $status;
    }

    /**
     * Get max order num and calculate next order for same client.
     *
     * @return integer Next order
     */
    public function getMaxUsedOrderNumber(): int
    {
        $nextOrder = $this->orderGap;
        $sql = "SELECT MAX(`orderNum`) FROM {$this->tbl->riskModelRoleTbl}
            WHERE clientID = :clientID";
        $params = [':clientID' => $this->tenantID];
        $valRow = $this->DB->fetchValue($sql, $params);
        if ($valRow) {
            // Order should be pre-populated with number with increment of 10, i.e., 10, 20, 30, 40, etc.
            $nextOrder = ceil(($valRow + 1) / $this->orderGap) * $this->orderGap;
        }
        return $nextOrder;
    }
}
