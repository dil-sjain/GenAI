<?php
/**
 * TenantIdStrict model for RegionCountryTrans
 *
 * @note code generated with \Models\Cli\ModelRules\ModelRulesGenerator.php
 */

namespace Models\TPM;

use Models\Base\ReadWrite\TenantIdStrict;

/**
 * TenantIdStrict model for RegionCountryTrans CRUD
 */
#[\AllowDynamicProperties]
class RegionCountryTrans extends TenantIdStrict
{
    /**
     * @var string name of table
     */
    protected $table = 'regionCountryTrans';

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
            'regionID'     => 'db_int|required', // region.id
            'clientID'     => 'db_int|required', // clientProfile.id
            'regionName'   => 'max_len,255', // name of region...only here for debug
            'countryName'  => 'max_len,255', // only here for debug
            'countryISO'   => 'max_len,5', // iso code for country
            'sponsorEmail' => 'max_len,65535',
        ];
    }

    /**
     * Case constructor requires clientID
     *
     * @param integer $clientID clientProfile.id
     * @param array   $params   configuration
     */
    public function __construct($clientID, $params = [])
    {
        parent::__construct($clientID, $params);
        $this->table = $this->DB->prependDbName($this->DB->getClientDB($clientID), $this->table);
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
            'regionName'   => '',
            'countryName'  => '',
            'countryISO'   => '',
            'sponsorEmail' => '',
        ];

        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }
}
