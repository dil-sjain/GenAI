<?php
/**
 * Provide acess to riskTier records
 */

namespace Models\TPM\RiskModel;

/**
 * Read/write access to riskTier
 *
 * @keywords risk, risk tier
 */
#[\AllowDynamicProperties]
class RiskTier extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'riskTier';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;

    /**
     * Delete tiers that are not used in a risk model
     *
     * @return void
     */
    public function deleteUnused()
    {
        $rmt = new RiskModelTier($this->clientID);
        $rmtTbl = $rmt->getTableName();
        $sql =<<< EOT
DELETE rt FROM $this->tbl AS rt
LEFT JOIN $rmtTbl AS rmt ON rmt.tier = rt.id
WHERE rt.clientID = :clientID AND rmt.tier IS NULL
EOT;
        $params = [':clientID' => $this->clientID];
        $this->DB->query($sql, $params);
    }
}
