<?php
/**
 * Model for interacting with Workflow Transactions for Osprey Workflow
 */

namespace Models\TPM\Workflow;

/**
 * Class Transactions
 *
 * @package Models\TPM\Workflow
 */
#[\AllowDynamicProperties]
class Transactions
{
    /**
     * Table name
     * @var string
     */
    protected $table = 'WORKFLOW_SYNC_TRANSACTION';

    /**
     * Application framework instance
     * @var instance of \Skinny
     */
    protected $app = null;

    /**
     * Feature override
     * @var instance of FeatureACL
     */
    protected $ftr = null;

    /**
     * Database object
     * @var instance of \MySqlPDO
     */
    protected $DB = null;

    /**
     * Flag indicating log use
     * @var bool
     */
    protected $useLog = false;

    /**
     * Transactions constructor.
     *
     * @param mixed $ftr    Optional \Feature override for this class
     * @param bool  $useLog Enables logging if true
     */
    public function __construct(\Feature $ftr = null, $useLog = false)
    {
        $this->app    = \Xtra::app();
        $this->ftr    = $ftr ?? $this->app->ftr;
        $this->DB     = $this->app->DB;
        $this->table  = $this->DB->globalDB . '.' . $this->table;
        $this->useLog = (bool)$useLog;
    }

    /**
     * Create a record in the WORKFLOW_SYNC_TRANSACTION table
     *
     * @param int    $tenantID   g_tenants.id
     * @param string $op         describe the trigger I.E. 'Insert', 'Update', 'Delete'
     * @param string $trsType    code for the type of transaction E.G. 'UPD3P'
     * @param string $trsStatus  code for the status of the transaction E.G. 'PEND' for pending
     * @param int    $entID      the entity id associated with the transaction E.G. thirdPartyProfile.id of 3
     * @param string $entType    the type of the entity associated with the transaction I.E. "ThirdPartyProfile" or
     *                           "DDQ"
     * @param int    $trgEntID   the entity id associated with the transaction E.G. thirdPartyProfile.id of 3
     * @param string $trgEntType the name of the table associated with the transaction E.G. "ThirdPartyProfile"
     *
     * @note See the below explanation for each of the fields on the WORKFLOW_SYNC_TRANSACTION table
     *
     * CLIENT_ID              -- the database id of the Client associated with the transaction
     * OPERATION_CD           -- the code to describe the trigger/transaction operation. One of the follow (DELETE,
     *                           INSERT, UPDATE)
     * TRANSACTION_TYPE_CD    -- the code to describe the type of transaction. Example: UPD3P -> Third Party Updated.
     *                           For the renewal process we can use 3PRENEWAL. Additional codes can be added as needed.
     * TRANSACTION_STATUS_CD  -- the code to describe the status of the transaction. Should always be created with
     *                           "PEND" for pending
     * SYNC_ENTITY_ID         -- the database id of the entity driving the transaction. At the moment this will only be
     *                           one of two values, either the Third Party id or a submitted DDQ id. For renewals this
     *                           should be the Third Party id the renewal is associated to.
     * SYNC_ENTITY_TYPE_CD    -- the code to describe the type of entity for the SYNC_ENTITY_ID column. At the moment
     *                           this will be one of two values, either "ThirdPartyProfile" or "DDQ". For renewals this
     *                           should be "ThirdPartyProfile".
     * TRIGGER_ENTITY_ID      -- the database id of the entity that caused the transaction to be initiated.
     *                           Example: Custom Data associated to a Third Party changes, the TRIGGER_ENTITY_ID should
     *                           be the database id for the custom data that changed. For renewals this should be the
     *                           initiator's database id.
     * TRIGGER_ENTITY_TYPE_CD -- the code to describe the type of entity for the TRIGGER_ENTITY_ID. This in the name of
     *                           the database table for the TRIGGER_ENTITY_ID. For renewals this should be the
     *                           initiator's database table name.
     * REPROCESS_DT           -- the date used within the process to determine when the transaction should be
     *                           re-processed if the transaction was not ready to be processed. On creation this value
     *                           should be NULL.
     * RETRY_COUNT_NO         -- the number of times the process has retried the transaction. On creation this value is
     *                           defaulted to 0.
     * LOG_MSG_TX             -- used to log details from the transaction process. On creation this value should be
     *                           NULL.
     * CREATE_DT              -- the date the transaction was created. On creation this value is defaulted to the
     *                           current date and time.
     * PURGE_DT               -- a date to describe the transaction has been removed without a hard database delete.
     *                           On creation this value should be NULL.
     * UPDATE_DT              -- the date the transaction was updated.
     * UPDATE_USER_TX         -- the username of the user who updated the transaction.
     * LOCK_ID                -- a value to indicate the version of the entry within the database. Used as a means to
     *                           avoid race condition overwrites within the system. On creation this value should be
     *                           set to 1.
     *
     * @return mixed Result of MySqlPDO::query()
     */
    public function createTransactionRecord(
        $tenantID,
        $op,
        $trsType,
        $trsStatus,
        $entID,
        $entType,
        $trgEntID,
        $trgEntType
    ) {
        $sql = "INSERT INTO $this->table (CLIENT_ID, OPERATION_CD, TRANSACTION_TYPE_CD, "
            . "TRANSACTION_STATUS_CD, SYNC_ENTITY_ID, SYNC_ENTITY_TYPE_CD, TRIGGER_ENTITY_ID, TRIGGER_ENTITY_TYPE_CD) "
            . "VALUES (:tenantID, :op, :trsType, :trsStatus, :entID, :entType, :trgEntID, :trgEntType)";
        $params = [
            ':tenantID'   => $tenantID,
            ':op'         => $op,
            ':trsType'    => $trsType,
            ':trsStatus'  => $trsStatus,
            ':entID'      => $entID,
            ':entType'    => $entType,
            ':trgEntID'   => $trgEntID,
            ':trgEntType' => $trgEntType
        ];
        return $this->DB->query($sql, $params);
    }

    /**
     * Check for existing, actice workflow transaction
     *
     * @param int    $tenantID g_tenants.id
     * @param string $trnType  code for the type of transaction E.G. 'UPD3P'
     * @param int    $entID    the entity id associated with the transaction E.G. thirdPartyProfile.id of 3
     * @param string $entType  the type of the entity associated with the transaction I.E. "ThirdPartyProfile" or
     *
     * @return bool
     */
    public function hasActiveTransaction($tenantID, $trnType, $entID, $entType)
    {
        $sql = "SELECT ID FROM $this->table\n"
            . "WHERE CLIENT_ID = :cid AND SYNC_ENTITY_ID = :entID AND TRANSACTION_TYPE_CD = :trnType\n"
            . "AND TRANSACTION_STATUS_CD = 'PEND' AND SYNC_ENTITY_TYPE_CD = :entType LIMIT 1";
        $params = [
            ':cid'     => $tenantID,
            ':trnType' => $trnType,
            ':entID'   => $entID,
            ':entType' => $entType,
        ];
        return (bool)$this->DB->fetchValue($sql, $params);
    }
}
