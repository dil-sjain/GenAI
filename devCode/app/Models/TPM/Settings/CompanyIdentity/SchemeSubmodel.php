<?php
/**
 * file contains the class that handles data operations with respect
 * to changing, retreiving, etc, data about site and ddq color schemes.
 *
 * @keywords company, identity, settings, color, css
 */
namespace Models\TPM\Settings\CompanyIdentity;

use Models\LogData;

/**
 * file contains the class that handles data operations with respect
 * to changing, retreiving, etc, data about site and ddq color schemes.
 *
 * @keywords company, identity, settings, color, css
 */
#[\AllowDynamicProperties]
class SchemeSubmodel
{

    /**
     * @var integer number of schemes available
     */
    public const SCHEME_COUNT = 5;

    /**
     * @var integer event type for logging
     */
    public const EVENT_TYPE = 15;

    /**
     *
     * @var array valid scheme types
     */
    private $validSchemeTypes = ['site', 'ddq'];

    /**
     *
     * @var string Name of db to use for identity purposes
     */
    private $dbName;

    /**
     *
     * @var string Name of table to use in the identity db
     */
    private $table;

    /**
     *
     * @var object Instance of a data logger
     */
    private $dataLogger;

    /**
     *
     * @var type
     */
    private $tenantID;

    /**
     * @var string Profile table color scheme fields
     */
    protected $profileFields;

    /**
     * set up this submodel instance
     *
     * @param integer $tenantID   tenantID
     * @param array   $properties data needed for instantiation; key val pairs
     */
    public function __construct($tenantID, $properties)
    {
        $this->DB = \Xtra::app()->DB;
        $this->table = $properties['table'];
        $this->tenantID = $tenantID;
        $this->profileFields = $properties['fields']['scheme'];
        $this->dataLogger = new LogData($properties['loggingID'], $properties['userID']);
    }

    /**
     * Return scheme data from the database
     *
     * @return array scheme data
     */
    public function getSchemes()
    {
        $bindings = [':tenantID' => $this->tenantID];
        $sql = "SELECT $this->profileFields FROM $this->table WHERE id = :tenantID LIMIT 1";
        return $this->DB->fetchAssocRow($sql, $bindings);
    }

    /**
     * update a scheme
     *
     * @param string  $schemeType Which scheme to change site/ddq
     * @param integer $schemeVal  New scheme value to which to update
     *
     * @throws \Exception If invalid data
     *
     * @return void
     */
    public function updateScheme($schemeType, $schemeVal)
    {
        if (!$this->validateSchemeVal($schemeVal) || !$this->validateSchemeType($schemeType)) {
            throw new \Exception('Valid scheme value and type are required');
        }
        $oldSchemeVal = $this->fetchSchemeVal($schemeType);
        $sql = "UPDATE $this->table SET {$schemeType}ColorScheme = :schemeVal WHERE id = :tenantID LIMIT 1";
        $bindings = [':schemeVal' => $schemeVal, ':tenantID' => $this->tenantID];
        $this->DB->query($sql, $bindings);
        $this->dataLogger->saveLogEntry(
            self::EVENT_TYPE,
            "$schemeType scheme: `$oldSchemeVal` => `$schemeVal`"
        );
    }

    /**
     * get the current scheme of the specified type
     *
     * @param string $schemeVal site or ddq
     *
     * @return integer Scheme
     *
     * @throws \Exception
     */
    public function getSchemeVal($schemeVal)
    {
        if (!$this->validateSchemeType($schemeVal)) {
            throw new \Exception('Bad scheme type');
        }
        return $this->fetchSchemeVal($schemeType);
    }

    /**
     * validate scheme data
     *
     * @param string $schemeType scheme type to validate
     *
     * @return boolean
     */
    private function validateSchemeType($schemeType)
    {
        return in_array($schemeType, $this->validSchemeTypes);
    }

    /**
     * Get scheme data for specified type of scheme site / ddq
     *
     * @param string $schemeType site / ddq
     *
     * @return type
     */
    private function fetchSchemeVal($schemeType)
    {
        return $this->DB->fetchValue(
            "SELECT {$schemeType}ColorScheme FROM $this->table WHERE id = :tenantID LIMIT 1",
            [':tenantID' => $this->tenantID]
        );
    }

    /**
     * returns whether the passed scheme value is valid
     *
     * @param integer $schemeVal Value to validate
     *
     * @return boolean
     */
    public function validateSchemeVal($schemeVal)
    {
        return (
            (string)(int)$schemeVal == $schemeVal
            && (    $schemeVal >= 0
                    && $schemeVal < self::SCHEME_COUNT
            )
        );
    }
}
