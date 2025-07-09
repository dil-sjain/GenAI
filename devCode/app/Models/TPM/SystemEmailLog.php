<?php
/**
 * TenantIdStrict model for SystemEmailLog
 *
 * @note code generated with \Models\Cli\ModelRules\ModelRulesGenerator.php
 */

namespace Models\TPM;

use Models\Base\ReadWrite\TenantIdStrict;

/**
 * TenantIdStrict model for SystemEmailLog CRUD
 */
#[\AllowDynamicProperties]
class SystemEmailLog extends TenantIdStrict
{
    /**
     * @var string name of table
     */
    protected $table = 'systemEmailLog';

    /**
     * @var string field name of the tenant ID
     */
    protected $tenantIdField = 'clientID';


    /**
     * return array gettable/settable attributes w/validation rules
     *
     * comment out any attribute you wish to hide from both read & write access
     * keeping this list as small as possible will save some memory when retrieving rows
     *
     * @param string $context not functional, but would allow for future conditional attributes/validation
     *
     * @return array
     */
    public static function rulesArray($context = '')
    {
        return [
            'id'        => 'db_int',
            'sent'      => 'db_timestamp,blank-null', // when email was sent
            'sender'    => 'max_len,255|required',  // User that generated the email
            'recipient' => 'max_len,255|required',  // email sent to
            'EMtype'    => 'db_int|required',       // type of email sent
            'funcID'    => 'db_int',                // ID sent to Email function
            'caseID'    => 'db_int',                // csaes.id
            'tpID'      => 'db_int',                // thirdPartyProfile.id of linked case
            'clientID'  => 'db_int|required',       // clientProfile.id
            'subject'   => 'max_len,255',           // email subject
            'cc'        => 'max_len,255',           // CC address or address list
        ];
    }

    /**
     * configure other rules for attributes
     *
     * @param string $context allows for conditional rules (in future implementations)
     *
     * @return void
     */
    protected function loadRulesAdditional($context = '')
    {
        // set defaults, key--> column name, value--> value or functionName()
        $this->rulesDefault = [
            'funcID'  => '0',
            'EMtype'  => '0',
            'caseID'  => '0',
            'tpID'    => '0',
            'subject' => '',
            'cc'      => '',
        ];

        $this->rulesReadonly = [
            'sent',
        ];
        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }

    /**
     * Constructor
     *
     * @param integer $tenantID Unique identifier. Set to zero for only default emails.
     * @param array   $params   Configuration
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($tenantID, array $params = [])
    {
        parent::__construct($tenantID, $params);
        $this->table = $this->DB->getClientDB($tenantID) . '.systemEmailLog';
    }
}
