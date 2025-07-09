<?php
/**
 * Model: assign service provider preferences
 *
 * @keywords Service, Provider, data
 */

namespace Models\TPM\Settings\ContentControl\ServiceProvider;

use Models\SP\Products;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ClientProfile;
use Lib\Database\MySqlPdo;
use Skinny\Skinny;
use Lib\Support\Xtra;
use Exception;
use PDOException;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class ServiceProviderData
{
    /**
     * @var MySqlPdo Intance of PDO class
     */
    private MySqlPdo $DB;

    /**
     * @var Skinny Instance of Skinny PHP framework
     */
    private Skinny $app;

    /**
     * @var ServiceProvider Instance of ServiceProvider model
     */
    public ServiceProvider $serviceProvider;

    /**
     * @var Cases instance of Cases model
     */
    public Cases $cases;

    /**
     * @var integer
     */
    public int $clientID;

    /**
     * @var string Name of global SP table
     */
    private string $spTbl = '';

    /**
     * @var string Name of global Product table
     */
    private string $productTbl = '';

    /**
     * @var string Name of global Country table
     */
    private string $countryTbl = '';

    /**
     * @var string Name of global client Map table
     */
    private string $clientMapTbl = '';

    /**
     * @var string Name of global product Country table
     */
    private string $productCountryTbl = '';

    /**
     * @var string Name of global Country by Region table
     */
    private string $countryByRegionTbl = '';

    /**
     * @var string Name of global Country Region table
     */
    private string $countryRegionTbl = '';

    /**
     * @var string Name of global client SP product map table
     */
    private string $clientSpProductMapTbl = '';

    /**
     * @var string Name of global client SP preference table
     */
    private string $clientSpPreferenceTbl = '';

    /**
     * @var string Name of global case type client table
     */
    private string $caseTypeClientTbl = '';



    /**
     * Constructor - initialization
     *
     * @param integer $clientID clientProfile.id
     *
     * @return void
     */
    public function __construct($clientID)
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->logger = $this->app->log;

        $this->clientID  = (int)$clientID;
        $clientDB = $this->DB->getClientDB($this->clientID);
        $this->serviceProvider       = new ServiceProvider(0);
        $this->cases                 = new Cases($this->clientID);
        $this->countryTbl            = $this->DB->isoDB . '.legacyCountries';
        $this->spTbl                 = $this->DB->spGlobalDB . '.investigatorProfile';
        $this->productTbl            = $this->DB->spGlobalDB . '.spProduct';
        $this->clientMapTbl          = $this->DB->spGlobalDB . '.spClientMap';
        $this->productCountryTbl     = $this->DB->spGlobalDB . '.spProductCountry';
        $this->countryByRegionTbl    = $this->DB->globalDB . '.g_countryByRegion';
        $this->countryRegionTbl      = $this->DB->globalDB . '.g_countryRegion';

        $this->clientSpProductMapTbl = $clientDB . '.clientSpProductMap';
        $this->clientSpPreferenceTbl = $clientDB . '.clientSpPreference';
        $this->caseTypeClientTbl     = $clientDB . '.caseTypeClient';
    }




    /**
     * Filter SP's by scope from the SP-Product map
     *
     * @param integer $scopeID          scope id
     * @param array   $serviceProviders array of service provider data
     *
     * @return array key value rows of spIDs to product id's
     */
    private function filterSPsByScope($scopeID, $serviceProviders)
    {
        $clientDB = $this->DB->getClientDB($this->clientID);
        $spIDs = array_map('intval', \Xtra::arrayColumn($serviceProviders, 'id'));

        $sql = "SELECT spID, product FROM $clientDB.clientSpProductMap\n"
            . "WHERE product > 0 AND clientID = :clientID AND scope = :scope\n"
            . "AND spID IN(" . implode(',', $spIDs) . ")";
        $filteredSPs = $this->DB->fetchAssocRows(
            $sql,
            [':clientID' => $this->clientID, ':scope' => (int)$scopeID]
        );
        foreach ($filteredSPs as $key => $val) {
            // Retrieve the SP Name and add to the filtered array
            foreach ($serviceProviders as $spKey => $spVal) {
                if ($val['spID'] == $spVal['id']) {
                    $filteredSPs[$key]['spName'] = $spVal['name'];
                    break;
                }
            }
        }
        return $filteredSPs;
    }




    /**
     * Retrieve number of assigned/unassigned countries per service provider scopes
     *
     * @return array of service provider scopes and their number of countries where available
     */
    public function mapScopesToCountries()
    {
        $rtn = [];
        $scopesSPs = $this->getScopesAndSPs();
        $scopes = $scopesSPs['scopes'];
        $serviceProviders = $scopesSPs['serviceProviders'];
        $idx = 0;
        foreach ($scopes as $scopeID => $scopeName) {
            $rtn['scopes'][$idx]['id'] = $scopeID;
            $rtn['scopes'][$idx]['name'] = $scopeName;
            $scopeSPs = [];
            $spIdx = $missing = 0;
            $filteredSPs = $this->filterSPsByScope($scopeID, $serviceProviders);
            if (!empty($filteredSPs)) {
                $filteredSpIDs = array_map('intval', \Xtra::arrayColumn($filteredSPs, 'spID'));
                $sql = "SELECT COUNT(c.legacyCountryCode) AS missing FROM $this->countryTbl AS c\n"
                    . "LEFT JOIN clientSpPreference AS p ON (p.country = c.legacyCountryCode AND clientID = :clientID\n"
                    . "AND p.scope = :scope AND p.spID IN(" . implode(',', $filteredSpIDs) . "))\n"
                    . "WHERE c.legacyCountryCode <> 'OO' AND p.country IS NULL";
                $rtn['scopes'][$idx]['missing'] = $this->DB->fetchValue(
                    $sql,
                    [':clientID' => $this->clientID, ':scope' => $scopeID]
                );
            }
            foreach ($filteredSPs as $spKey => $spVal) {
                $scopeSPs[$spKey]['spID'] = $spID = $spVal['spID'];
                $scopeSPs[$spKey]['spName'] = $spName = $spVal['spName'];
                $scopeSPs[$spKey]['product'] = $product = $spVal['product'];
                $tmpMissing = $scopes = '';

                $products = new Products($spID);
                if ($available = count($products->getAvailableProductCountries($product))) {
                    $scopeSPs[$spKey]['available'] = $available;

                    $sql = "SELECT COUNT(pc.country) AS assigned\n"
                        . "FROM $this->productCountryTbl AS pc\n"
                        . "LEFT JOIN clientSpPreference AS p ON\n"
                        . "(p.country = pc.country AND p.clientID = :clientID\n"
                        . "AND p.scope = :scope AND p.spID IN(" . implode(',', $filteredSpIDs) . "))\n"
                        . "WHERE pc.product = :product AND pc.serviceType = 'due_diligence'\n"
                        . "AND p.country IS NOT NULL";
                    $params = array(
                        ':clientID' => $this->clientID,
                        ':scope' => $scopeID,
                        ':product' => $product
                    );
                    $scopeSPs[$spKey]['assigned'] = $assigned = $this->DB->fetchValue($sql, $params);
                    $missing = ($missing + ($available - $assigned));
                }
            }
            $rtn['scopes'][$idx]['missing'] = $missing;
            $rtn['scopes'][$idx]['SPs'] = $scopeSPs;
            $idx++;
        }
        return $rtn;
    }



    /**
     * Retrieve scopes and products of service providers
     *
     * @return Array Return service provider scopes and provider data
     */
    public function mapScopesToProducts()
    {
        $this->cleanupClientSpProductMap();
        $scopesSPs = $this->getScopesAndSPs();
        $clientProfile = (new ClientProfile(['clientID' => $this->clientID]))->findById($this->clientID);
        $rtn = [
            'scopes' => $scopesSPs['scopes'],
            'serviceProviders' => $scopesSPs['serviceProviders'],
            'products' => [],
            'productsMap' => [],
            'clientName' => $clientProfile->get('companyShortName'),
        ];
        foreach ($rtn['serviceProviders'] as $key => $sp) {
            $products = new Products($sp['id']);
            $rtn['products'][$sp['id']] = $products->getAllSpProductsAbbridged($this->clientID);
            $rtn['productMap'][$sp['id']] = $products->getClientSpProductMap($this->clientID);
        }
        return $rtn;
    }





    /**
     * Retrieve regions with assigned countries data for a given SP-scope-product combo
     *
     * @param integer $scopeID   Scope ID
     * @param integer $spID      Service Provider ID
     * @param integer $productID Product ID
     *
     * @return Array of regions with related countries data
     */
    public function getAssignedCountries($scopeID, $spID, $productID)
    {
        $scopeID = (int)$scopeID;
        $spID = (int)$spID;
        $productID = (int)$productID;

        $rtn = [
            'scopeID' => $scopeID,
            'spID' => $spID,
            'productID' => $productID,
            'regions' => [],
            'scopeName' => '',
            'spName' => '',
            'isAssigned' => true
        ];

        $scopesSPs = $this->getScopesAndSPs();
        $rtn['scopeName'] = $scopesSPs['scopes'][$scopeID];
        $validatedSP = $this->validateSP($spID);

        if (!$validatedSP['pass']) {
            throw new \Exception($this->app->trans->codeKey('invalid_SP'));
        }
        $rtn['spName'] = $validatedSP['name'];
        $products = new Products($spID);
        if ($available = count($products->getAvailableProductCountries($productID))) {
            $sql = "SELECT pc.country AS iso, IF(p.spID = :spID, 1, 0) AS available\n"
                . "FROM $this->productCountryTbl AS pc\n"
                . "LEFT JOIN clientSpPreference AS p ON (p.country = pc.country\n"
                . "AND p.clientID = :clientID AND p.scope = :scope)\n"
                . "WHERE pc.spID = :spID2 AND pc.serviceType = 'due_diligence' AND pc.product = :product";
            $params = array(
                ':spID' => $spID,
                ':clientID' => $this->clientID,
                ':scope' => $scopeID,
                ':spID2' => $spID,
                ':product' => $productID
            );
            if ($assignedCountries = $this->DB->fetchKeyValueRows($sql, $params)) {
                $rtn['regions'] = $this->getCountryRegionPreferences($assignedCountries, true);
            }
        }
        return $rtn;
    }




    /**
     * Collect multi-dimensional array of regions, the regions' countries, and country preferences
     *
     * @param array   $countries  array of countries with ISO code keys
     * @param boolean $isAssigned if true, limit the countries to those mapped to the SP
     *
     * @return array regions/countries array
     */
    private function getCountryRegionPreferences($countries = [], $isAssigned = false)
    {
        $rtn = [];
        $sql = "SELECT id, shortName AS name FROM $this->countryRegionTbl\n"
            . "WHERE name <> 'Unclassified' ORDER BY sequence ASC, shortName ASC";
        $regions = $this->DB->fetchKeyValueRows($sql);
        $isoCodes = array_keys($countries);

        $isGeo2 = Xtra::usingGeography2();
        foreach ($regions as $id => $name) {
            $rtn[$id]['name'] = $name;
            $rtn[$id]['countries'] = [];
            $sql = "SELECT cr.country iso, ";
            
            if ($isGeo2) {
                $sql .= "IFNULL(c.displayAs, c.legacyName) AS legacyName\n";
            } else {
                $sql .= "IFNULL(c.legacyName, cr.country) AS legacyName\n";
            }
            $sql .= "FROM " . $this->countryByRegionTbl . " cr\n"
                . "LEFT JOIN " . $this->countryTbl . " c ON c.legacyCountryCode = cr.country\n"
                . "WHERE region = :region AND cr.country IN('" . implode("','", $isoCodes) . "')\n"
                . "ORDER BY legacyName";
            if ($regionCountries = $this->DB->fetchAssocRows($sql, [':region' => $id])) {
                foreach ($regionCountries as $key => $val) {
                    foreach ($countries as $iso => $available) {
                        if ($val['iso'] == $iso) {
                            if ($isAssigned) {
                                $regionCountries[$key]['assigned'] = $available;
                            } else {
                                $regionCountries[$key]['available'] = $available;
                            }
                            break;
                        }
                    }
                }
                $rtn[$id]['countries'] = $regionCountries;
            }
        }
        return $rtn;
    }



    /**
     * Retrieve case scopes and available service providers
     *
     * @return Array of service providers properties
     */
    private function getScopesAndSPs()
    {
        $scopes = $this->cases->getScopes();
        unset($scopes[Cases::DUE_DILIGENCE_INTERNAL]);
        if (empty($scopes)
            || !($serviceProviders = $this->serviceProvider->availableServiceProviders($this->clientID))
        ) {
            throw new \Exception($this->app->trans->codeKey('no_ddq_scopes'));
        }
        return ['scopes' => $scopes, 'serviceProviders' => $serviceProviders];
    }



    /**
     * Retrieve regions with unassigned countries data for a given scope
     *
     * @param integer $scopeID Scope ID
     *
     * @return array of regions with related countries data
     */
    public function getUnassignedCountries($scopeID, $spProductArr = [])
    {
        $rtn = ['regions' => [], 'scopeName' => '', 'isAssigned' => false];
        $scopeID = (int)$scopeID;
        $scopesSPs = $this->getScopesAndSPs();
        $rtn['scopeName'] = $scopesSPs['scopes'][$scopeID];

        $clientDB = $this->DB->getClientDB($this->clientID);
        // Extract all productIDs and spIDs from spProductArr
        $productIDs = [];
        $spIDs = [];
        if (!empty($spProductArr) && is_array($spProductArr)) {
            foreach ($spProductArr as $pair) {
                if (!empty($pair['productID']) && !empty($pair['spID'])) {
                    $productIDs[] = (int)$pair['productID'];
                    $spIDs[] = (int)$pair['spID'];
                }
            }
        }
        $productIDs = array_unique($productIDs);
        $spIDs = array_unique($spIDs);
        if (empty($productIDs) || empty($spIDs)) {
            return $rtn;
        }
        $productIDList = implode(',', $productIDs);
        $spIDList = implode(',', $spIDs);
        $sql = "SELECT DISTINCT c.legacyCountryCode AS iso,\n"
            . "CASE WHEN pc.country IS NOT NULL THEN 1 ELSE 0 END AS available\n"
            . "FROM $this->countryTbl AS c\n"
            . "LEFT JOIN $clientDB.clientSpPreference p\n"
            . "  ON (p.country = c.legacyCountryCode AND p.clientID = :clientID AND p.scope = :scope AND p.spID IN ($spIDList))\n"
            . "LEFT JOIN $this->productCountryTbl pc\n"
            . "  ON pc.country = c.legacyCountryCode AND pc.product IN ($productIDList) AND pc.spID IN ($spIDList)\n"
            . "INNER JOIN $this->countryByRegionTbl cr\n"
            . "  ON cr.country = c.legacyCountryCode\n"
            . "WHERE c.legacyCountryCode <> 'OO'\n"
            . "  AND p.country IS NULL\n"
            . "  AND cr.region <> 9";
        $params = array(
            ':clientID'      => $this->clientID,
            ':scope'         => $scopeID
        );
        if ($unassignedCountries = $this->DB->fetchKeyValueRows($sql, $params)) {
            $rtn['regions'] = $this->getCountryRegionPreferences($unassignedCountries);
        }
        return $rtn;
    }


    /**
     * Save assigned/not assigned countries for service provider/scope/product combo
     *
     * @param integer $scopeID    Scope ID
     * @param integer $spID       Service Provider ID
     * @param integer $productID  Product ID
     * @param array   $countryMap Map of countries and user-assignment state
     *
     * @return void
     */
    public function saveAssignedCountries($scopeID, $spID, $productID, $countryMap)
    {
        $scopeID = (int)$scopeID;
        $spID = (int)$spID;
        $productID = (int)$productID;
        $validatedSP = $this->validateSP($spID);
        if (empty($scopeID) || empty($spID) || empty($productID) || !$validatedSP['pass']) {
            throw new \Exception($this->app->trans->codeKey('invalid_SP'));
        }

        foreach ($countryMap as $iso => $assigned) {
            // Always operate on the specific SP/country/scope
            $params = [
                ':clientID' => $this->clientID,
                ':scope' => $scopeID,
                ':country' => $iso,
                ':spID' => $spID
            ];
            $sql = "SELECT * FROM $this->clientSpPreferenceTbl\n"
                . "WHERE clientID = :clientID AND scope = :scope AND country = :country AND spID = :spID";
            $preference = $this->DB->fetchObjectRow($sql, $params);
            if ($assigned) {
                if (!$preference) {
                    // Insert only if not already assigned for this SP
                    $subSql = "INSERT INTO $this->clientSpPreferenceTbl SET\n"
                        . "clientID = :clientID, scope = :scope, country = :country, spID = :spID";
                    $this->DB->query($subSql, $params);
                }
            } else {
                if ($preference) {
                    // Delete only for this SP/country/scope
                    $subSql = "DELETE FROM $this->clientSpPreferenceTbl WHERE clientID = :clientID\n"
                        . "AND scope = :scope AND country = :country AND spID = :spID LIMIT 1";
                    $this->DB->query($subSql, $params);
                }
            }
        }
    }


    /**
     * Save scope/product mapping for service providers
     *
     * @param array $newMapping array map of SP's, products and scopes
     *
     * @return void
     */
    public function saveMapScopeProducts($newMapping)
    {
        if (!empty($newMapping)) {
            foreach ($newMapping as $idx => $map) {
                $spID = $map['spID'];
                $scopeID = $map['scopeID'];
                $productID = $map['productID'];
                $validatedSP = $this->validateSP($spID);
                if (!$validatedSP['pass'] || (int)$productID === 0) {
                    continue;
                }
                $this->updateClientSpProductMap($spID, $scopeID, $productID);
            }
        }
    }


    /**
     * Update client service provider product and return successful product data
     *
     * @param Integer $spID      Service Provider ID
     * @param Integer $scopeID   scope ID
     * @param Integer $productID service provider product ID
     *
     * @return void
     */
    public function updateClientSpProductMap($spID, $scopeID, $productID)
    {
        $sql = "SELECT * FROM $this->clientSpProductMapTbl WHERE clientID = :clientID "
                . "AND scope = :scope AND spID = :spID LIMIT 1";
        $params = [':clientID' => $this->clientID, ':scope' => $scopeID, ':spID' => $spID];
        if ($rec = $this->DB->fetchObjectRow($sql, $params)) {
            if ($rec->product != $productID) {
                $sql = "UPDATE $this->clientSpProductMapTbl SET\n"
                    . "product = :product\n"
                    . "WHERE clientID = :clientID AND scope = :scope AND spID = :spID LIMIT 1";
                $params = [
                    ':product' => $productID,
                    ':clientID' => $this->clientID,
                    ':scope' => $scopeID,
                    ':spID' => $spID
                ];
                $this->DB->query($sql, $params);
            }
        } else {
            $sql = "INSERT INTO $this->clientSpProductMapTbl SET "
                . "clientID = :clientID, "
                . "scope = :scope, "
                . "spID = :spID, "
                . "product = :product";
            $params = [
                ':clientID' => $this->clientID,
                ':scope' => $scopeID,
                ':spID' => $spID,
                ':product' => $productID
            ];
            $this->DB->query($sql, $params);
        }
    }


    /**
     * Determines if provided Service Provider is valid or not
     *
     * @param integer $spID Service Provider id
     *
     * @return array pass boolean and name string items
     */
    private function validateSP($spID)
    {
        $spID = (int)$spID;
        $scopesSPs = $this->getScopesAndSPs();
        $rtn = ['pass' => false, 'name' => ''];
        foreach ($scopesSPs['serviceProviders'] as $idx => $sp) {
            if ($sp['id'] == $spID) {
                $rtn = ['pass' => true, 'name' => $sp['name']];
                break;
            }
        }
        return $rtn;
    }

    /**
     * Remove any duplicate records
     *
     * @return void
     */
    private function cleanupClientSpProductMap()
    {
        try {
            // Remove duplicate entries
            $this->DB->setSessionSyncWait(true);
            $sql = "SELECT scope, product, spID, COUNT(*) records FROM $this->clientSpProductMapTbl\n"
                . "WHERE clientID = :cid GROUP BY clientID, scope, product, spID HAVING records > 1";
            if ($duplicates = $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID])) {
                foreach ($duplicates as $record) {
                    $sql = "DELETE FROM $this->clientSpProductMapTbl\n"
                        . "WHERE clientID = :cid AND scope = :scope AND product = :product AND spID = :spID\n"
                        . "LIMIT :limit";
                    $params = [
                        ':cid' => $this->clientID,
                        ':limit' => $record['records'] - 1,
                        ':scope' => $record['scope'],
                        ':product' => $record['product'],
                        ':spID' => $record['spID'],
                    ];
                }
            }
            // Get valid list
            $sql = "SELECT GROUP_CONCAT(m.id) mapped FROM $this->clientSpProductMapTbl m\n"
                . "INNER JOIN $this->productTbl p ON p.id = m.product AND p.spID = m.spID\n"
                . "INNER JOIN $this->clientMapTbl sc ON sc.spID = m.spID AND sc.clientID = m.clientID\n"
                . "WHERE m.clientID = :cid";
            if ($validRecords = $this->DB->fetchValue($sql, [':cid' => $this->clientID])) {
                // Remove ny extraneous records for this client
                // CSV list doesn't require using it as parameter - won't work in IN clause with quites
                $sql = "DELETE FROM $this->clientSpProductMapTbl WHERE clientID = :cid AND id NOT IN($validRecords)";
                $this->DB->query($sql, [':cid' => $this->clientID]);
            }
        } catch (PDOException | Exception $e) {
            Xtra::track([
                'sql' => $sql ?? '(none)',
                'error' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);
        } finally {
            $this->DB->setSessionSyncWait(false);
        }
    }
}
