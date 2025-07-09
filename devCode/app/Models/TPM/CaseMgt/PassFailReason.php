<?php
/**
 * Work with PassFailReason table
 *
 * @keywords pass, fail, case, model
 */

namespace Models\TPM\CaseMgt;

use Models\Base\ReadWrite\TenantIdStrict;

/**
 * TenantIdStrict model for PassFailReason CRUD
 */
class PassFailReason extends TenantIdStrict
{
    /**
     * @var string name of table
     */
    protected $table = 'passFailReason';

    /**
     * @var string field name of the tenant ID
     */
    protected $tenantIdField = 'clientID';

    /**
     * Return array gettable/settable attributes w/validation rules
     *
     * @param string $context allows for conditional rules (in future implementations)
     *
     * @return array
     */
    public static function rulesArray($context = '')
    {
        return [
            'id'       => 'db_int',
            'clientID' => 'db_int|required',
            'pfType'   => "max_len,7|contains,'pass' 'fail' 'neither'",
            'caseType' => 'db_int',
            'reason'   => 'max_len,255',
        ];
    }

    /**
     * Configure other rules for attributes
     *
     * @param string $context allows for conditional rules
     *
     * @return void
     */
    protected function loadRulesAdditional($context = '')
    {
        $this->rulesDefault = [
            'caseType' => '0',
            'reason' => '',
        ];

        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }

    /**
     * Get passFailReason records
     *
     * @param integer $caseType cases.caseType
     *
     * @return array of passFailReason row objects
     */
    public function getPassFailReasons($caseType = 0)
    {
        $sql = "SELECT id, reason, (pfType + 0) AS enumIdx FROM passFailReason "
            ."WHERE clientID=:clientID AND caseType=:caseType ORDER BY enumIdx ASC";
        $params = [':clientID' => $this->tenantIdValue, ':caseType' => $caseType];
        return $this->DB->fetchObjectRows($sql, $params);
    }
}
