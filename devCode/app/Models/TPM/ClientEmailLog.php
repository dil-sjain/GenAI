<?php
/**
 * TenantIdStrict model for ClientEmailLog
 *
 */

namespace Models\TPM;

use Models\Base\ReadWrite\TenantIdStrict;

/**
 * TenantIdStrict model for ClientEmailLog CRUD
 */
#[\AllowDynamicProperties]
class ClientEmailLog extends TenantIdStrict
{
    /**
     * @var string name of table
     */
    protected $table = 'clientEmailLog';

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
            'id'           => 'db_int',
            'clientID'     => 'db_int|required',        // clientProfile.id
            'sender'       => 'max_len,255|required',   // From address on email
            'recipient'    => 'max_len,255|required',   // To address on email
            'sent'         => 'db_timestamp,blank-null',           // When email was sent
            'invokedBy'    => 'max_len,50',             // Action (name) that caused email
            'languageCode' => 'max_len,10',
            'EMtype'       => 'db_int|required',        // Email Msg Type
            'subject'      => 'max_len,255|required',   // Email subject line
            'body'         => 'max_len,65535|required', // Email subject line
            'rc'           => 'db_int',                 // Return Code (Pass/Fail) from mail function
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
            'EMtype' => '0',
        ];

        $this->rulesReadonly = [
            'sent',
        ];
        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }
}
