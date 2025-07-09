<?php
/**
 * Provide access to Risk Model Role
 */

namespace Models\TPM\RiskModel;

/**
 * Read/write access to riskModelRole
 */
#[\AllowDynamicProperties]
class RiskModelRole extends \Models\BaseLite\RequireClientID
{
    /**
     * Table name (required by base class)
     *
     * @var string
     */
    protected $tbl = 'riskModelRole';

    /**
     * @var boolean flag table in clientDB
     */
    protected $tableInClientDB = true;
}
