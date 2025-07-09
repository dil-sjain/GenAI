<?php
/**
 * Model for g_intakeFormSecurityCodes
 *
 * @note code generated with \Models\Cli\ModelRules\ModelRulesGenerator.php
 */

namespace Models\TPM\IntakeForms;

/**
 * Model for g_intakeFormSecurityCodes CRUD
 */
#[\AllowDynamicProperties]
class IntakeFormSecurityCodes extends \Models\BaseModel
{
    /**
     * @var string name of table
     */
    protected $table = 'g_intakeFormSecurityCodes';
 
    /**
     * constructor
     *
     * @param array $params options
     *
     * @return void
     */
    public function __construct(array $params = null)
    {
        parent::__construct($params);
        $this->table = $this->DB->globalDB . '.' . $this->table;
    }

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
            'id' => 'db_int_unsigned|required',
            'secCode' => 'max_len,50|required',
            'domainName' => 'max_len,255|required', // domain name associated w/this sec Code
            'clientID' => 'db_int|required', // clientProfile.id
            'ddqCode' => 'max_len,50|required', // code in link for PIQs
            'ddqLink' => 'max_len,255|required', // URL for DDQ acces
            'caseType' => 'db_int|required', // used to set caseType in the DDQs
            'origin' => 'max_len,10|contains, open_url invitation',
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
            'secCode' => '0',
            'caseType' => '12',
        ];
        
        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }
}
