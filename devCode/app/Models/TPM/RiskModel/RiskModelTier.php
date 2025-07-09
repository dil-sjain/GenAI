<?php
/**
 * Provide acess to riskModelTier records
 */

namespace Models\TPM\RiskModel;

/**
 * Read/write access to riskModelTier
 *
 * @keywords risk, risk model tier, risk model
 */
#[\AllowDynamicProperties]
class RiskModelTier extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'riskModelTier';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;

    /**
     * @var mixed indicate this table has no primaryID
     */
    protected $primaryID = null;
}
