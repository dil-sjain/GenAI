<?php
/**
 * Model for the main Fields/Lists data operations for Intake Form Identification (Name).
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;

/**
 * Class IntakeFormNamesData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * @keywords intake form, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class IntakeFormNamesData extends TpmListsData
{
    public const MAX_LENGTH = 50;
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
     * @var array General translation text array (set through parent method)
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
        $this->tbl->ddqName = $clientDB .'.ddqName';
        $this->tbl->olQuest = $clientDB .'.onlineQuestions';
    }

    /**
     * Public wrapper to grab all records (resets main array keys to 0-based count.)
     *
     * @return object DB result object
     */
    public function getAll()
    {
        return $this->fetch();
    }

    /**
     * Get all records.
     *
     * @return array Array of object rows from fetchObjectRows
     */
    private function fetch()
    {
        $sql = "SELECT DISTINCT o.caseType, o.ddqQuestionVer, n.name, n.status, n.legacyID \n"
            ."FROM {$this->tbl->olQuest} AS o \n"
            ."LEFT JOIN {$this->tbl->ddqName} AS n ON (\n"
                ."n.legacyID=CONCAT('L-',o.caseType,TRIM(o.ddqQuestionVer)) "
                ." AND n.clientID = :clientID1 \n"
            .") WHERE o.clientID = :clientID2 AND o.languageCode='EN_US' AND o.dataIndex > 0 AND o.qStatus <> 2 \n"
            ."ORDER BY o.caseType ASC, o.ddqQuestionVer ASC";
        $params = [
            ':clientID1' => $this->tenantID,
            ':clientID2' => $this->tenantID,
        ];
        $recs = $this->DB->fetchObjectRows($sql, $params);
        foreach ($recs as $key => $rec) {
            if (empty($rec->name)) {
                $recs[$key]->name = 'L-' . $rec->caseType . $rec->ddqQuestionVer;
            }
        }
        return $recs;
    }

    /**
     * Update a record
     *
     * @param array $vals Array of all allowed labels. (See: this->getValues() for array keys passed back.)
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function update($vals)
    {
        $err = [];
        foreach ($vals as $v) {
            $v['status'] = (int)$v['status'];
            $v['oldStatus'] = (int)$v['oldStatus'];
            $v['name'] = html_entity_decode($v['name'], ENT_QUOTES, 'UTF-8');
            $v['oldName'] = html_entity_decode($v['oldName'], ENT_QUOTES, 'UTF-8');

            if (!empty($v['name']) && !$this->validateName($v['name'])) {
                $err[] = str_replace(
                    ['{name}', '{maxLength}'],
                    [$v['name'], self::MAX_LENGTH],
                    (string) $this->txt['fl_error_invalid_intake_name']
                );
                continue;
            }

            if ((empty($v['name']) || $v['name'] == $v['oldName']) && $v['status'] == $v['oldStatus']) {
                continue;
            }

            $sql = "SELECT clientID from {$this->tbl->ddqName} "
                ."WHERE clientID = :tenantID AND legacyID = :legID AND name = :oldName LIMIT 1";
            $params = [':tenantID' => $this->tenantID, ':legID' => $v['legacyID'], ':oldName' => $v['oldName']];
            if ($this->DB->fetchValue($sql, $params)) {
                if (empty($v['name'])) {
                    $v['name'] = $v['oldName'];
                }
                $sql = "UPDATE {$this->tbl->ddqName} SET name = :name, status = :status "
                    ."WHERE clientID = :tenantID AND legacyID = :legID AND name = :oldName LIMIT 1";
                $params = [
                    ':name' => $v['name'],
                    ':status' => $v['status'],
                    ':tenantID' => $this->tenantID,
                    ':legID' => $v['legacyID'],
                    ':oldName' => $v['oldName'],
                ];
            } else {
                $sql = "INSERT INTO {$this->tbl->ddqName} SET "
                    ."name = :name, legacyID = :legID, clientID = :tenantID, status = :status";
                $params = [
                    ':name' => $v['name'],
                    ':legID' => $v['legacyID'],
                    ':tenantID' => $this->tenantID,
                    ':legID' => $v['legacyID'],
                ];
            }
            $success = $this->DB->query($sql, $params);
            if ($success->rowCount()) {
                $logMsg = [];
                $logMsg[] = $v['legacyID'] .' ::';
                if (!empty($v['name']) && $v['name'] != $v['oldName']) {
                    $v['oldName'] = (empty($v['oldName'])) ? $this->txt['not_set'] : $v['oldName'];
                    $logMsg[] =  'name `'. $v['oldName'] .'` => `'. $v['name'] .'`';
                }
                if ($v['status'] != $v['oldStatus']) {
                    $oldStatus = ($v['oldStatus'] == 1) ? 'Active' : 'InActive';
                    $status = ($v['status'] == 1) ? 'Active' : 'InActive';
                    $logMsg[] =  'status `'. $oldStatus .'` => `'. $status .'`';
                }
                $logMsg = implode(' ', $logMsg);
                // Log message
                $this->LogData->saveLogEntry(101, $logMsg);
            } else {
                $err[] = str_replace(
                    ['{oldName}', '{newName}'],
                    [$v['oldName'], $v['newName']],
                    (string) $this->txt['error_unable_to_update_intake_name']
                );
            }
        }
        if ($err) {
            return [
                'Result'   => 0,
                'ErrTitle' => $this->txt['error_warning'],
                'ErrMsg'   => implode('<br />', $err),
                'Recs'     => $this->fetch(),
            ];
        }
        return ['Result' => 1, 'Recs' => $this->fetch()];
    }

    /**
     * Validate label value
     *
     * @param string $name desired value for ddqName.name
     *
     * @return boolean True if valid, else false
     */
    private function validateName($name)
    {
        return (!preg_match('/^[-a-z0-9_() ]{1,'. self::MAX_LENGTH .'}$/i', $name) ? false:true);
    }
}
