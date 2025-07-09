<?php
/**
 * file contains the class that handles data operations with respect
 * to changing, retreiving, etc, company names
 *
 * @keywords company, identity, settings, name, legal
 */
namespace Models\TPM\Settings\CompanyIdentity;

use Lib\Validation\ValidateFuncs;

/**
 * handles data operations with respect
 * to changing, retreiving, etc, company names
 *
 */
#[\AllowDynamicProperties]
class NamesSubmodel
{
    /**
     * @var object DB object
     */
    private $DB;

    /**
     * @var string dbName.table to use
     */
    private $table;

    /**
     * @var db column name for the abbreviated tenant name
     */
    private $shortNameCol;

    /**
     * @var string name of the db col to use for full co name (aka legal name)
     */
    private $companyNameCol;

    /**
     * @var array keys enabled for updating in db as columnar data
     */
    private $updateableInputKeys;


    /**
     * set up instance
     *
     * @param integer $tenantID   tenantID
     * @param string  $properties submodel properties
     */
    public function __construct(private $tenantID, $properties)
    {
        $this->DB = \Xtra::app()->DB;
        $this->table          = $properties['table'];
        $this->companyNameCol = $properties['fields']['name'];
        $this->shortNameCol   = "companyShortName";

        $this->updateableInputKeys = [
            $this->shortNameCol => 'shortName',
            $this->companyNameCol => 'legalName',
        ];
    }

    /**
     * Get name data from db
     *
     * @return array with keys legalName, shortName
     */
    public function getNames()
    {
        $this->validateTenantID($this->tenantID);

        $bindings = [':tenantID' => $this->tenantID];
        $sql = "SELECT "
            ."$this->shortNameCol AS shortName, $this->companyNameCol AS legalName "
            ."FROM $this->table "
            ."WHERE id = :tenantID "
            ."LIMIT 1";

        return $this->DB->fetchAssocRow($sql, $bindings);
    }

    /**
     * validate name data input
     *
     * @param array $in Keys need to match $this->updateableInputKeys
     *
     * @return type
     */
    public function validateInput($in)
    {
        $validateFuncs = new ValidateFuncs();
        $msg = [];
        foreach ($in as $inputKey => $input) {
            if (!in_array($inputKey, $this->updateableInputKeys)) {
                $msg[] = "invalid input key\"$inputKey\"";
            }
            if (!$input || !is_string($input)) {
                $msg[] = "invalid input \"$input\" for param or key \"$inputKey\"";
            }
            if (!$validateFuncs->checkInputSafety($input)) {
                $msg[] = "invalid input \"$input\" for param or key \"$inputKey\" - html tags, javascript, or other unsafe content detected";
            }
        }
        if (count($msg) > 0) {
            return [false, $msg];
        }
        return [true, []];
    }

    /**
     * update company legal and short names in the db
     *
     * @param array $in             input - new names to save
     * @param type  $skipValidation whether to skip validation (e.g., controller did it)
     *
     * @return boolean
     */
    public function updateNames($in, $skipValidation = false)
    {
        if ($skipValidation === false && self::validateInput($in)[0] === false) {
            return;
        }
        $updateClause = "UPDATE $this->table";
        [$setClause, $bindings] = $this->buildSetClause($in);
        $whereClause = "WHERE id = :tenantID";
        $bindings[':tenantID'] = $this->tenantID;
        $sql = "$updateClause\n$setClause\n$whereClause;\n";
        $this->DB->query($sql, $bindings);
        return true;
    }

    /**
     * private function that builds a set clause as part of an
     * sql statement
     *
     * @param array $in name data to save for tenant
     *
     * @return string sql clause
     */
    private function buildSetClause($in)
    {
        $pairings = [];
        $bindings = [];
        foreach ($in as $param => $datum) {
            $col = array_search($param, $this->updateableInputKeys);
            $pairings[] = "$col = :$param";
            $bindings[":$param"] = $datum;
        }
        return ["SET " . implode(', ', $pairings), $bindings];
    }

    /**
     * throws an error if the specified id is not valid
     *
     * @param integer $id an id to validate
     *
     * @return boolean true
     *
     * @throws \Exception if the datum is not int
     */
    private function validateTenantID($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            throw new \Exception("id is required ($id)");
        }
        return true;
    }
}
