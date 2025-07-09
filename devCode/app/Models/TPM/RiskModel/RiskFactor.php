<?php
/**
 * Provide acess to riskFactor records
 */

namespace Models\TPM\RiskModel;

/**
 * Read/write access to riskFactor
 *
 * @keywords risk, risk factor
 */
#[\AllowDynamicProperties]
class RiskFactor extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'riskFactor';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;
}
