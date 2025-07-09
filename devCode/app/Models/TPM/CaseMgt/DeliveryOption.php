<?php
/**
 * Manage data for spDeliveryOption and related tables
 */

namespace Models\TPM\CaseMgt;

use Lib\Database\MySqlPdo;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Cases;
use Skinny\Skinny;

#[\AllowDynamicProperties]
class DeliveryOption
{
    /**
     * Legacy ID for Diligent Corporation
     */
    public const STEELE_CIS = 8;

    /**
     * Upsert return codes
     */
    public const UPSERT_NO_CHANGE  = 0;
    public const UPSERT_INSERTED   = 1;
    public const UPSERT_UPDATED    = 2;
    public const UPSERT_MISSING    = -1;
    public const UPSERT_DUPLICATE  = -2;
    public const UPSERT_UNEXPECTED = -3;
    public const UPSERT_CONFIG_ERR = -4;

    /**
     * @var null|Skinny Framework instance
     */
    protected $app = null;

    /**
     * @var null|MySqlPdo Class instance
     */
    protected $DB = null;

    /**
     * @var string Table name
     */
    protected $deliveryOptionTbl = 'spDeliveryOption';

    /**
     * @var string Table name
     */
    protected $deliveryOptionProductTbl = 'spDeliveryOptionProduct';

    /**
     * @var string Table name
     */
    protected $spProductTbl = 'spProduct';

    /**
     * @var string Table name
     */
    protected $clientDBlistTbl = 'clientDBlist';

    /**
     * @var string Table name
     */
    protected $investigatorProfileTbl = 'investigatorProfile';

    /**
     * @var string Table name
     */
    protected $usersTbl = 'users';

    /**
     * @var array Phantom delivery option - does not change cost or due date
     */
    protected $impliedStandardOption = ['id' => 0, 'name' => 'standard', 'abbrev' => 'std', 'sequence' => 0];

    /**
     * @var array Exclude products with conflicting, variable price multiplier or variable TAT
     */
    protected $excludedProducts = [
        Cases::DUE_DILIGENCE_OSRC, // Open Source Research China (bilingual option)
    ];

    /**
     * Set instance properties
     */
    public function __construct()
    {
        // The usual
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;

        // Add database to table names
        $this->deliveryOptionTbl = $this->DB->spGlobalDB . '.' . $this->deliveryOptionTbl;
        $this->deliveryOptionProductTbl = $this->DB->spGlobalDB . '.' . $this->deliveryOptionProductTbl;
        $this->spProductTbl = $this->DB->spGlobalDB . '.' . $this->spProductTbl;
        $this->investigatorProfileTbl = $this->DB->spGlobalDB . '.' . $this->investigatorProfileTbl;
        $this->clientDBlistTbl = $this->DB->authDB . '.' . $this->clientDBlistTbl;
        $this->usersTbl = $this->DB->authDB . '.' . $this->usersTbl;
    }

    /**
     * Get delivery options for service provider
     *
     * @param int  $spID            Legacy service provider ID
     * @param bool $includeStandard Add implied 'standard' delivery option
     *
     * @return array of assoc row data
     */
    public function deliveryOptions($spID = self::STEELE_CIS, $includeStandard = true)
    {
        $sql = "SELECT id, name, abbrev, sequence FROM $this->deliveryOptionTbl\n"
            . "WHERE spID = :spID ORDER BY sequence";
        $params = [':spID' => $spID];
        $rows = $this->DB->fetchAssocRows($sql, $params);
        if ($includeStandard) {
            array_unshift($rows, $this->impliedStandardOption);
        }
        return $rows;
    }

    /**
     * Get list of products that have a given delivery option defined
     *
     * @param int $deliveryOptionID spDeliveryOption.id
     *
     * @return array of assoc records.
     */
    public function deliveryOptionProductList($deliveryOptionID)
    {
        $sql = <<<EOT
SELECT DISTINCT(prod.id) AS 'id', prod.abbrev
FROM $this->deliveryOptionProductTbl AS dop
INNER JOIN $this->spProductTbl AS prod ON prod.id = dop.productID
WHERE dop.deliveryOptionID = :optID ORDER BY prod.sequence, prod.abbrev
EOT;
        return $this->DB->fetchAssocRows($sql, [':optID' => $deliveryOptionID]);
    }

    /**
     * Get data needed to calculate expedited case delivery
     *
     * @param int $productID        spProduct.id
     * @param int $deliveryOptionID spDeliveryOptionProduct.id
     * @param int $clientID         clientProfile.id, TPM tenant ID
     * @param int $dopID            spDeliveryOptionProduct.id, default -1 (ignore)
     *
     * @return false if there is no delivery option for the SP product, or
     *         assoc data for client override if there is one, or
     *         assoc data for default delivery option
     */
    public function deliveryData($productID, $deliveryOptionID, $clientID, $dopID = -1)
    {
        // Exclude products with conflicting price multipliers or variable TAT
        $excludeList = implode(',', $this->excludedProducts);

        $sql = <<<EOT
SELECT dop.id, p.spID, p.abbrev AS `product`, do.name AS `delivery`,
dop.productID, dop.deliveryOptionID, dop.clientID,
dop.increaseBy, dop.calcType, dop.TAT,
IF (dop.clientID = 0, 'default', 'override') AS `source`
FROM $this->deliveryOptionProductTbl AS dop
INNER JOIN ($this->deliveryOptionTbl AS do, $this->spProductTbl AS p)
    ON (do.id = dop.deliveryOptionID AND p.id = dop.productID)
WHERE ((dop.id > 0 AND dop.id = :dopID)
    OR (dop.productID = :productID
        AND dop.deliveryOptionID = :optID
        AND (dop.clientID = :cid OR dop.clientID = 0)
        AND dop.current AND dop.active
        AND do.spID = p.spID
    )) AND NOT FIND_IN_SET(dop.productID, :exclude)
ORDER BY dop.clientID DESC LIMIT 1
EOT;
        $params = [
            ':productID' => $productID,
            ':optID' => $deliveryOptionID,
            ':cid' => $clientID,
            ':dopID' => $dopID,
            ':exclude' => $excludeList,
        ];

        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Get default and client overrides for an spProduct with a given deliveryOption
     *
     * @param int  $productID        spProdict.id
     * @param int  $deliveryOptionID spDeliveryOption.id
     * @param bool $currentOnly      If true (default) return only current records
     * @param bool $activeOnly       if true (default) return only active records
     * @param int  $clientID         If greater than -1 (default) restruct result to clientID
     *
     * @return array of assoc records
     */
    public function deliveryOptionProductRecords(
        $productID,
        $deliveryOptionID,
        $currentOnly = true,
        $activeOnly = true,
        $clientID = -1
    ) {
        $clientID = (int)$clientID;
        $currentOnly = (bool)$currentOnly;
        $activeOnly = (bool)$activeOnly;
        $cond = ['dop.productID = :prodID', 'dop.deliveryOptionID = :optID'];
        $params = [':prodID' => $productID, ':optID' => $deliveryOptionID];
        if ($currentOnly) {
            $cond[] = 'dop.current';
        }
        if ($activeOnly) {
            $cond[] = 'dop.active';
        }
        if ($clientID > -1) {
            $cond[] = 'dop.clientID = :cli';
            $params[':cli'] = $clientID;
        }
        $where = implode(' AND ', $cond);
        $sql = <<<EOT
SELECT dop.id AS 'dopID', dop.productID, dop.deliveryOptionID AS 'optionID',
prod.abbrev AS 'product', dop.clientID, cli.clientName,
dop.increaseBy, dop.calcType, dop.TAT, dop.active
FROM $this->deliveryOptionProductTbl AS dop
INNER JOIN $this->spProductTbl AS prod ON prod.id = dop.productID
LEFT JOIN $this->clientDBlistTbl AS cli ON cli.clientID = dop.clientID
WHERE $where ORDER BY dop.clientID ASC, dop.id DESC
EOT;
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Return one delivery option record
     *
     * @param int $spID             Legacy service provider ID
     * @param int $deliveryOptionID spDeliveryOption.id
     *
     * @return array|false
     */
    public function getOptionRecord($spID, $deliveryOptionID)
    {
        $sql = "SELECT * FROM $this->deliveryOptionTbl\n"
            . "WHERE id = :optID AND spID = :spID";
        $params = [':spID' => $spID, ':optID' => $deliveryOptionID];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Return array of products that have not been defined for given delivery option
     *
     * @param int $spID             Legacy service provider ID
     * @param int $deliveryOptionID spDeliveryOption.id
     *
     * @return array
     */
    public function getEligibleProducts($spID, $deliveryOptionID)
    {
        // Exclude products with conflicting price multipliers or variable TAT
        $excludeList = implode(',', $this->excludedProducts);

        $sql = <<<EOT
SELECT prod.id, prod.abbrev
FROM $this->spProductTbl AS prod
LEFT JOIN $this->deliveryOptionProductTbl AS dop
  ON dop.productID = prod.id AND dop.deliveryOptionID = :optID
WHERE prod.spID = :spID AND prod.active
AND NOT FIND_IN_SET(prod.id, :exclude)
AND dop.id IS NULL
ORDER BY prod.sequence, prod.abbrev
EOT;
        $params = [
            ':spID' => $spID,
            ':optID' => $deliveryOptionID,
            ':exclude' => $excludeList,
        ];
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Get a delivery product settings record
     *
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $productID        spProdict.id
     * @param int $clientID         clientProfile.id
     * @param int $recID            spDeliveryOptionProduct.id
     *
     * @return array|bool assoc array row or false
     */
    public function getCurrentSettingsRecord($deliveryOptionID, $productID, $clientID, $recID)
    {
        $sql = <<<EOT
SELECT p.id AS recID, p.increaseBy, p.calcType, p.TAT, p.clientID, p.active,
IF(p.clientID = 0,
  '(default)',
  CONCAT('(', p.clientID ,') ', IF(c.id IS NOT NULL, c.clientName, '(unknown)'))
) AS `clientName`
FROM $this->deliveryOptionProductTbl AS p
LEFT JOIN $this->clientDBlistTbl AS c ON c.clientID = p.clientID
WHERE p.id = :recID AND p.productID = :prodID AND p.deliveryOptionID = :optID
AND p.clientID = :cid AND p.current
ORDER BY p.id DESC LIMIT 1
EOT;
        $params = [':optID' => $deliveryOptionID, ':prodID' => $productID, ':recID' => $recID, ':cid' => $clientID];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Get eligible clients for new delivery option product settings record
     *
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $productID        spProdict.id
     *
     * @return array|bool assoc array row or false
     */
    public function getEligibleClients($deliveryOptionID, $productID)
    {
        $hasDefault = false;
        $sql = "SELECT id FROM $this->deliveryOptionProductTbl\n"
            . "WHERE deliveryOptionID = :optID AND productID = :prodID\n"
            . "AND clientID = 0 AND current LIMIT 1";
        $params = [':optID' => $deliveryOptionID, ':prodID' => $productID];
        if ($this->DB->fetchValue($sql, $params)) {
            $hasDefault = true;
        }
        $sql = <<<EOT
SELECT c.clientID, CONCAT('(', c.clientID, ') ', c.clientName) AS `clientName`
FROM $this->clientDBlistTbl AS c
LEFT JOIN $this->deliveryOptionProductTbl AS p
  ON (p.productID = :prodID AND p.deliveryOptionID = :optID AND p.clientID = c.clientID)
WHERE c.tenantTypeID = 1 AND c.status = 'active' AND p.id IS NULL
ORDER BY c.clientID
EOT;
        $clients = $this->DB->fetchAssocRows($sql, $params);
        if (!$hasDefault) {
            array_unshift($clients, ['clientID' => 0, 'clientName' => '(default)']);
        }
        return $clients;
    }

    /**
     * Delete option record
     *
     * @param int $spID             Legacy service provider ID
     * @param int $deliveryOptionID spDeliveryOption.id
     *
     * @return bool
     */
    public function deleteOptionRecord($spID, $deliveryOptionID)
    {
        // Is it in use?
        $sql = <<<EOT
SELECT p.id
FROM $this->deliveryOptionProductTbl AS p
LEFT JOIN $this->deliveryOptionTbl AS o ON o.id = p.deliveryOptionID
WHERE p.deliveryOptionID = :optID AND o.spID = :spID LIMIT 1
EOT;
        $params = [':optID' => $deliveryOptionID, ':spID' => $spID];
        if ($this->DB->fetchValue($sql, $params)) {
            return false;
        }
        $sql = "DELETE FROM $this->deliveryOptionTbl WHERE id = :optID AND spID = :spID LIMIT 1";
        return (bool)$this->DB->query($sql, $params);
    }

    /**
     * Test if service provider is full SP
     *
     * @param int $spID investigatorProfile.id
     *
     * @return bool
     */
    public function isFullSp($spID)
    {
        $sql = "SELECT bFullSp FROM $this->investigatorProfileTbl WHERE id = :spID LIMIT 1";
        return (bool)$this->DB->fetchValue($sql, [':spID' => $spID]);
    }

    /**
     * Test if option exists
     *
     * @param int $spID             Legacy service provider ID
     * @param int $deliveryOptionID spDeliveryOption.id
     *
     * @return bool
     */
    public function optionExists($spID, $deliveryOptionID)
    {
        $sql = "SELECT id FROM $this->deliveryOptionTbl WHERE id = :optID AND spID = :spID LIMIT 1";
        return (bool)$this->DB->fetchValue($sql, [':optID' => $deliveryOptionID, ':spID' => $spID]);
    }

    /**
     * Test if SP product exists
     *
     * @param int $spID      Legacy service provider ID
     * @param int $productID spProduct.id
     *
     * @return bool
     */
    public function productExists($spID, $productID)
    {
        $productID = (int)$productID;
        $sql = "SELECT id FROM $this->spProductTbl WHERE id = :prodID AND spID = :spID AND active LIMIT 1";
        return ($this->DB->fetchValue($sql, [':prodID' => $productID, ':spID' => $spID]) === $productID);
    }

    /**
     * Test if delivery option product settings record exists
     *
     * @param int $recID            spDeliveryOptionProduct.id
     * @param int $productID        spProduct.id
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $clientID         clientProfile.id
     *
     * @return bool
     */
    public function settingsRecordExists($recID, $productID, $deliveryOptionID, $clientID)
    {
        $recID = (int)$recID;
        $sql = "SELECT id FROM $this->deliveryOptionProductTbl WHERE id = :recID\n"
            . "AND productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid LIMIT 1";
        $params = [
            ':recID' => $recID,
            ':prodID' => $productID,
            ':optID' => $deliveryOptionID,
            ':cid' => $clientID,
        ];
        return ($this->DB->fetchValue($sql, $params) === $recID);
    }

    /**
     * Delete a delivery option product settings record
     *
     * @param int $recID            spDeliveryOptionProduct.id
     * @param int $productID        spProduct.id
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $clientID         clientProfile.id
     *
     * @return int 1 = success, 0 = record not found, -1 = in use, -2 = not current
     */
    public function deleteSettingsRecord($recID, $productID, $deliveryOptionID, $clientID)
    {
        // Get current record
        $recID = (int)$recID;
        $sql = "SELECT id, current FROM $this->deliveryOptionProductTbl WHERE id = :recID\n"
            . "AND productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid LIMIT 1";
        $params = [
            ':recID' => $recID,
            ':prodID' => $productID,
            ':optID' => $deliveryOptionID,
            ':cid' => $clientID,
        ];
        $rec = $this->DB->fetchAssocRow($sql, $params);

        // Any need to go further?
        if (!$rec) {
            return 0;  // not found
        } elseif (empty($rec['current'])) {
            return -2; // not current
        } elseif ($this->settingsInUse($recID, $productID, $deliveryOptionID, $clientID)) {
            return -1; // in use
        }

        // Values for use inside transaction
        $rtn = null;
        $delSql = "DELETE FROM $this->deliveryOptionProductTbl WHERE id = :recID LIMIT 1";
        $delParams = [':recID' => $recID];
        $updtParams = [':prodID' => $productID, ':optID' => $deliveryOptionID, ':cid' => $clientID];
        $updtSql1 = "UPDATE $this->deliveryOptionProductTbl SET current = NULL\n"
            . "WHERE productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid";
        $updtSql2 = "UPDATE $this->deliveryOptionProductTbl SET current = 1\n"
            . "WHERE productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid\n"
            . "ORDER BY id DESC LIMIT 1";
        // Put values in an object
        $obj = (object)compact('rtn', 'delSql', 'delParams', 'updtParams', 'updtSql1', 'updtSql2');

        // Statements to execute inside transaction - may occur more than once if deadlocks occur
        $func = function ($db, $obj, &$finish) {
            $obj->rtn = null; // must reset on each iteration
            // Delete the record
            $db->query($obj->delSql, $obj->delParams);
            // Remove current from all matching records
            $db->query($obj->updtSql1, $obj->updtParams);
            // Mark most recent as current
            $db->query($obj->updtSql2, $obj->updtParams);
            $db->commit();
            $finish = true;
            $obj->rtn = 1;
        };

        // Execute transaction
        $this->DB->transact($func, $obj);
        return $obj->rtn;
    }

    /**
     * Test if SP product exists
     *
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $productID        spProduct.id
     * @param int $userID           users.id of logged-in user
     *
     * @return bool
     */
    public function addDefaultProductRecord($deliveryOptionID, $productID, $userID)
    {
        $params = [
            ':optID' => $deliveryOptionID,
            ':prodID' => $productID,
            ':userID' => $userID,
        ];
        $sql = <<<EOT
INSERT INTO $this->deliveryOptionProductTbl SET
id = NULL, deliveryOptionID = :optID,
productID = :prodID, createdBy = :userID
EOT;
        return (bool)$this->DB->query($sql, $params);
    }

    /**
     * Test if option is unique for given spID
     *
     * @param int    $spID             Legacy service provider ID
     * @param int    $deliveryOptionID spDeliveryOption.id
     * @param string $field            'name' or 'abbrev'
     * @param string $value            Field value to test
     *
     * @return bool
     */
    public function optionIsUnique($spID, $deliveryOptionID, $field, $value)
    {
        if ($field !== 'name' && $field !== 'abbrev') {
            throw new \InvalidArgumentException("$field must be `name` or `abbrev`");
        }
        $params = [':spID' => $spID, ':optID' => $deliveryOptionID, ':val' => $value];
        $sql = <<<EOT
SELECT id
FROM $this->deliveryOptionTbl
WHERE $field = :val AND spid = :spID AND id <> :optID LIMIT 1
EOT;
        return !(bool)$this->DB->fetchValue($sql, $params);
    }

    /**
     * Insert or update delivery option record
     *
     * @param array $data Contains validated 'spID', 'optID', 'name', 'abbrev' and 'sequence' key/value pairs
     *
     * @return bool
     */
    public function upsertOption($data)
    {
        $setVals = [];
        $params = [];
        $flds = ['spID', 'optID', 'sequence', 'name', 'abbrev'];
        foreach ($flds as $fld) {
            if (!array_key_exists($fld, $data)) {
                throw new \InvalidArgumentException("`$fld` is missing from data");
            }
            $parmKey = ':' . $fld;
            $parmVal = $data[$fld];
            if ($fld !== 'optID') {
                $setVals[] = "`$fld` = $parmKey";
                $params[$parmKey] = $parmVal;
            } else {
                $optID = $parmVal;
            }
        }
        $sql = " $this->deliveryOptionTbl SET " . implode(', ', $setVals);
        if (!empty($optID)) {
            $params[':optID'] = $optID;
            $sql = "UPDATE" . $sql . " WHERE id = :optID LIMIT 1";
        } else {
            $sql = "INSERT INTO" . $sql . ", id = NULL";
        }
        return (bool)$this->DB->query($sql, $params);
    }

    /**
     * Get versions of records for same product, option, and client
     *
     * @param int $productID        spProduct.id
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $clientID         clientProfile.id
     *
     * @return array of records
     */
    public function settingsVersions($productID, $deliveryOptionID, $clientID)
    {
        $sql = <<<EOT
SELECT p.id, p.increaseBy, p.calcType, p.TAT, p.active,
p.created, p.updated, cu.userName AS whoCreated,
uu.userName AS whoUpdated, p.current
FROM $this->deliveryOptionProductTbl p
LEFT JOIN $this->usersTbl AS cu ON cu.id = p.createdBy
LEFT JOIN $this->usersTbl AS uu ON uu.id = p.updatedBy
WHERE p.productID = :prodID AND p.deliveryOptionID = :optID AND p.clientID = :cid
ORDER BY p.id DESC
EOT;
        $params = [':cid' => $clientID, ':prodID' => $productID, ':optID' => $deliveryOptionID];
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Test if settings record is unique
     *
     * @param int $productID        spProduct.id
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $clientID         clientProfile.id
     *
     * @return bool True if no match, otherwise false
     */
    public function settingsRecordIsUnique($productID, $deliveryOptionID, $clientID)
    {
        $params = [':cid' => $clientID, ':prodID' => $productID, ':optID' => $deliveryOptionID];
        $sql = "SELECT id FROM $this->deliveryOptionProductTbl\n"
            . "WHERE productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid LIMIT 1";
        return $this->DB->fetchValue($sql, $params) ? false : true;
    }

    /**
     * Test if settings are in use
     *
     * @param int $recID            spDeliveryOptionProduct.id
     * @param int $productID        spProduct.id
     * @param int $deliveryOptionID spDeliveryOption.id
     * @param int $clientID         clientProfile.id
     *
     * @return bool|null|array Null if record does not exist, boolean for in use, or array of errors
     */
    public function settingsInUse($recID, $productID, $deliveryOptionID, $clientID)
    {
        // Does record exist?
        $errors = [];
        $clientID = (int)$clientID;
        $params = [':recID' => $recID, ':prodID' => $productID, ':optID' => $deliveryOptionID, ':cid' => $clientID];
        $sql = "SELECT id, current FROM $this->deliveryOptionProductTbl\n"
            . "WHERE id = :recID AND productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid LIMIT 1";
        $rec = $this->DB->fetchAssocRow($sql, $params);
        if (empty($rec)) {
            return null;
        }
        if (empty($rec['current'])) {
            return true; // non-current/history records are there because they've been used in 1 or more cases
        }

        // Look for use in cases
        $rtn = false; // assume not in use
        if ($clientID) {
            // Look in client database
            if ($dbName = $this->DB->getClientDB($clientID, true)) {
                try {
                    $sql = "SELECT id FROM $dbName.cases WHERE delivery = :recID LIMIT 1";
                    if ($this->DB->fetchValue($sql, [':recID' => $recID])) {
                        $rtn = true;
                    }
                } catch (\PDOException $ex) {
                    $errors['db - ' . $dbName] = $ex->getMessage();
                }
            }
        } else {
            // Look in all client databases (even inactive and QCs)
            $sql = "SELECT DISTINCT DBname FROM $this->clientDBlistTbl";
            $clientDatabases = $this->DB->fetchValueArray($sql);
            foreach ($clientDatabases as $dbName) {
                try {
                    $sql = "SELECT id FROM $dbName.cases WHERE delivery = :recID LIMIT 1";
                    if ($this->DB->fetchValue($sql, [':recID' => $recID])) {
                        $rtn = true;
                        break; // no need to check further
                    }
                } catch (\PDOException $ex) {
                    $errors['db - ' . $dbName] = $ex->getMessage();
                }
            }
        }
        if ($errors) {
            \Xtra::track($errors);
            $rtn = $errors;
        }
        return $rtn;
    }

    /**
     * Insert/update delivery option product record
     *
     * @param array $data Date needed for record update or insert
     *
     * @return int 1=inserted, 2=updated, 0=nothing changed,
     *             -1=not found, -2=not unique, -3=unexpected error, -4=database config error
     *
     * @throws \InvalidArgumentException if $data does not contain required keys
     */
    public function upsertSettings($data)
    {
        // like extract, but safer
        $dataKeys = [
            'recID',
            'productID',
            'deliveryOptionID',
            'clientID',
            'increaseBy',
            'calcType',
            'TAT',
            'active',
            'userID',
        ];
        $expectedData = []; // safe for extract in private methods
        foreach ($dataKeys as $k) {
            if (!array_key_exists($k, $data)) {
                throw new \InvalidArgumentException("data must contain `$k`");
            }
            ${$k} = $data[$k];
            $expectedData[$k] = $data[$k];
        }

        // force valid calcType
        if ($calcType !== 'fixed') {
            $calcType = 'percent';
            $data['calcType'] = $calcType;
        }

        $inUse = true;
        $unexpectedErr = false;
        if ($recID) {
            // update
            $inUse = $this->settingsInUse($recID, $productID, $deliveryOptionID, $clientID);
            if ($inUse === null) {
                return self::UPSERT_MISSING;
            } elseif (is_array($inUse)) {
                return self::UPSERT_CONFIG_ERR;
            }

            // Are key values different?
            $sql = "SELECT increaseBy, calcType, TAT, active FROM $this->deliveryOptionProductTbl\n"
                . "WHERE id = :recID LIMIT 1";
            if (!($rec = $this->DB->fetchAssocRow($sql, [':recID' => $recID]))) {
                return self::UPSERT_UNEXPECTED;
            } elseif ($rec['increaseBy'] === $increaseBy
                && $rec['calcType'] === $calcType
                && $rec['TAT'] === $TAT
                && $rec['active'] === $active
            ) {
                return self::UPSERT_NO_CHANGE0;
            }

            if ($inUse) {
                // replace current record with new one
                if ($this->replaceCurrentSettings($expectedData)) {
                    return self::UPSERT_UPDATED;
                } else {
                    return self::UPSERT_UNEXPECTED;
                }
            } else {
                // can update normally
                if ($this->updateSettings($expectedData)) {
                    return self::UPSERT_UPDATED;
                } else {
                    return self::UPSERT_UNEXPECTED;
                }
            }
        } else {
            // insert new default or client override
            if (!$this->settingsRecordIsUnique($productID, $deliveryOptionID, $clientID)) {
                return self::UPSERT_DUPLICATE; // not unique, already exists
            }
            if ($this->insertSettings($expectedData)) {
                return self::UPSERT_INSERTED;
            } else {
                return self::UPSERT_UNEXPECTED;
            }
        }
        return self::UPSERT_UNEXPECTED;
    }

    /**
     * Add new settings record instead of updating existing record
     *
     * @param array $data New record values
     *
     * @return bool
     */
    private function replaceCurrentSettings($data)
    {
        extract($data); // safe in private metnhod with known values

        // Transaction values
        $sql = "UPDATE $this->deliveryOptionProductTbl SET current = NULL\n"
            . "WHERE productID = :prodID AND deliveryOptionID = :optID AND clientID = :cid";
        $params = [
            ':prodID' => $productID,
            ':optID' => $deliveryOptionID,
            ':cid' => $clientID,
        ];
        $me = $this;
        $funcObj = (object)compact('sql', 'params', 'data');

        // Transaction Statements
        $func = function ($db, $o, &$finish) use ($me) {
            $db->query($o->sql, $o->params);
            $me->insertSettings($o->data);
            $db->commit();
            $finish = true;
        };

        // Execute transaction
        return $this->DB->transact($func, $funcObj);
    }

    /**
     * Update existing settings record
     *
     * @param array $data New record values
     *
     * @return bool
     */
    private function updateSettings($data)
    {
        // Transaction values
        extract($data); // safe in private metnhod with known values
        $sql = <<<EOT
UPDATE $this->deliveryOptionProductTbl SET
increaseBy = :inc,
calcType = :type,
TAT = :tat,
active = :act,
updatedBy = :uid,
updated = NOW()
WHERE id = :recID LIMIT 1;
EOT;
        $params = [
            ':inc' => $increaseBy,
            ':type' => $calcType,
            ':tat' => $TAT,
            ':act' => $active,
            ':uid' => $userID,
            ':recID' => $recID,
        ];
        $funcObj = (object)compact('sql', 'params');

        // Transaction statements
        $func = function ($db, $o, &$finish) {
            $db->query($o->sql, $o->params);
            $db->commit();
            $finish = true;
        };
        return $this->DB->transact($func, $funcObj);
    }

    /**
     * Insert new settings record
     *
     * @param array $data New record values
     *
     * @return bool
     */
    private function insertSettings($data)
    {
        extract($data); // safe in private metnhod with known values
        $sql = <<<EOT
INSERT INTO $this->deliveryOptionProductTbl SET
id = NULL,
productID = :prodID,
deliveryOptionID = :optID,
clientID = :cid,
increaseBy = :inc,
calcType = :type,
TAT = :tat,
active = :act,
createdBy = :uid,
current = 1
EOT;
        $params = [
            ':prodID' => $productID,
            ':optID' => $deliveryOptionID,
            ':cid' => $clientID,
            ':inc' => $increaseBy,
            ':type' => $calcType,
            ':tat' => $TAT,
            ':act' => $active,
            ':uid' => $userID,
        ];
        return (bool)$this->DB->query($sql, $params);
    }

    /**
     * Return list of delivery options for adjust case tool
     *
     * @param int    $spID     investigatorProfileID
     * @param int    $scope    cases.caseType
     * @param int    $clientID clientProfile.id
     * @param string $country  subectInfoDD.country, costTimeCountry
     *
     * @return array of delivery records or empty array
     */
    public function optionsForAdjustCase($spID, $scope, $clientID, $country)
    {
        $clientID = (int)$clientID;
        // assumes seelected database is client's DB
        $options = [];
        $productID = $this->getSpProduct($spID, $scope, $clientID, $country);
        if ($productID) {
            $sql = <<<EOT
SELECT dop.id, o.name, o.abbrev, dop.increaseBy, dop.calcType, dop.TAT , dop.current, dop.active, dop.created,
IF(dop.clientID = 0, 0, 1) AS `override`,
CONCAT('(', dop.id, ') ', o.name, ' [',
  IF(dop.clientID, 'override', 'default'),
  IF (dop.current, ' | current', ''),
  IF (dop.active, ' | active', ''),
  ']') AS `txt`
FROM $this->deliveryOptionProductTbl AS dop
LEFT JOIN $this->deliveryOptionTbl AS o ON o.id = dop.deliveryOptionID
WHERE dop.productID = :prodID AND dop.clientID IN(0, :cid)
ORDER BY dop.deliveryOptionID, dop.current DESC, dop.clientID DESC;
EOT;
            $params = [
                ':prodID' => $productID,
                ':cid' => $clientID,
            ];
            if ($options = $this->DB->fetchAssocRows($sql, $params)) {
                array_unshift(
                    $options,
                    [
                        'id' => '0',
                        'name' => 'standard',
                        'abbrev' => 'std',
                        'increaseBy' => '0',
                        'calcType' => 'fixed',
                        'TAT' => 'n/a',
                        'current' => '1',
                        'active' => '1',
                        'created' => 'n/a',
                        'override' => 0,
                        'txt' => '(0) standard (no effect on cost/time)',
                    ]
                );
            }
        }
        return $options;
    }

    /**
     * Lookup delivery option name
     *
     * @param null|int $dopID     spDeliveryOptionProduct.id
     * @param bool     $withRecID Append record number if true
     *
     * @return string spDeliveryOption.name
     */
    public function optionNameLookup($dopID, $withRecID = false)
    {
        if ($dopID === 0) {
            return 'standard' . ($withRecID ? ' (0)' : '');
        } elseif ($dopID === null) {
            return '';
        }
        $spDB = $this->DB->spGlobalDB;
        $optTbl = "$spDB.spDeliveryOption";
        $dopTbl = "$spDB.spDeliveryOptionProduct";
        $sql = "SELECT o.name FROM $dopTbl AS dop\n"
            . "LEFT JOIN $optTbl AS o ON o.id = dop.deliveryOptionID\n"
            . "WHERE dop.id = :dopID LIMIT 1";
        $name = trim((string) $this->DB->fetchValue($sql, [':dopID' => $dopID]));
        return $name . ($withRecID ? " ($dopID)" : '');
    }

    /**
     * Lookup delivery option by name
     *
     * @param int    $spID investigatorProfile.id
     * @param string $name spDeliveryOption.name or spDeliveryOption.abbrev
     *
     * @return null|int
     */
    public function optionIdByName($spID, $name)
    {
        $sql = "SELECT id FROM $this->deliveryOptionTbl\n"
            . "WHERE spID = :sp AND (name = :nm OR abbrev = :abbr) LIMIT 1";
        $params = [':sp' => $spID, ':nm' => $name, ':abbr' => $name];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Get spProductID from cases.caseType
     *
     * @param int    $spID     investigatorProfile.id
     * @param int    $scope    cases.caseType
     * @param int    $clientID TPM tenant ID
     * @param string $country  Cost/time country iso code
     *
     * @return int ID or 0 if not found
     */
    public function getSpProduct($spID, $scope, $clientID, $country)
    {
        try {
            $SP = new ServiceProvider();
            if ($prodRow = $SP->productForScope($spID, $clientID, $scope, $country)) {
                $productID = $prodRow->id;
            }
        } catch (\Exception $ex) {
            $productID = 0;
            \Xtra::track([
                'error' => $ex->getMessage(),
                'location' => $ex->getFile() . ':' . $ex->getLine(),
            ]);
        }
        return (int)$productID;
    }
}
