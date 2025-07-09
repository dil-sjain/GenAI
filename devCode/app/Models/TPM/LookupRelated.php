<?php
/**
 * Lookup records related to cases and thirdPartyProfiles by key or by name.
 * Lookup arrays are populated only if accessed and then only once per instance.
 * Due to size, Fields 'state' and 'userid' are looked up on-the-fly, not from stored arrays.
 */

namespace Models\TPM;

use Models\Globals\Region;
use Models\Globals\Department;
use Models\API\Endpoints\CompanyLegalForm;
use Models\API\Endpoints\TpType;
use Models\API\Endpoints\TpTypeCategory;
use Models\API\Endpoints\TpApprovalReasons;
use Models\TPM\CaseMgt\DeliveryOption;
use Models\Globals\Geography;

/**
 * Lookup records related to cases and thirdPartyProfiles by key or by name
 *
 * @keywords lookup, foreign key, search, by name, by key, related records
 */
#[\AllowDynamicProperties]
class LookupRelated
{
    /**
     * Application instance
     *
     * @var \Skinny\Skinny
     */
    protected $app = null;

    /**
     * Database instance
     *
     * @var \Lib\Database\MySqlPdo
     */
    protected $DB  = null;

    /**
     * TPM tenant ID
     *
     * @var int
     */
    protected $clientID = 0;

    /**
     * Client database name
     *
     * @var string
     */
    protected $clientDB = '';

    /**
     * @var array retain in memory
     */
    protected $isoCountries = null;

    /**
     * @var array retain in memory
     */
    protected $regions      = null;

    /**
     * @var array retain in memory
     */
    protected $departments  = null;

    /**
     * @var array retain in memory
     */
    protected $legalForms   = null;

    /**
     * @var array retain in memory
     */
    protected $tpTypes      = null;

    /**
     * @var array retain in memory
     */
    protected $tpCats       = null;

    /**
     * @var array retain in memory
     */
    protected $approvalReasons = null;

    /**
     * @var string User table name
     */
    protected $usersTbl = null;

    /**
     * @var Geography class instance
     */
    protected $geoCls = null;

    /**
     * Init instance properties
     *
     * @param int $clientID TPM tenant id
     *
     * @throws \Exception
     */
    public function __construct($clientID)
    {
        \Xtra::requireInt($clientID);
        $this->clientID = $clientID;
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($clientID);
        $this->geoCls = Geography::getVersionInstance();
    }

    /**
     * Test if lookup by key is present for fieldName
     *
     * @param string $fieldName Name of database column
     *
     * @return book
     */
    public function canGetByKey($fieldName)
    {
        if ($fieldName === 'ownerID' || $fieldName === 'requestor') {
            $fieldName = 'user';
        } elseif ($fieldName === 'regCountry') {
            $fieldName = 'country';
        }
        return method_exists($this, $fieldName . 'ByKey');
    }

    /**
     * Test if lookup by key is present for fieldName
     *
     * @param string $fieldName Name of database column
     *
     * @return bool
     */
    public function canGetByName($fieldName)
    {
        if ($fieldName === 'ownerID' || $fieldName === 'requestor') {
            $fieldName = 'user';
        } elseif ($fieldName === 'regCountry') {
            $fieldName = 'country';
        }
        return method_exists($this, $fieldName . 'ByName');
    }

    /**
     * Lookup field value by name and get back its key
     *
     * @param string $fieldName Name of database column
     * @param string $name      Value to look for
     * @param mixed  $extra     Additional value needed by some lookups
     *
     * @return mixed Key value if found, false on no match, and null if lookup method does not exist
     */
    public function byName($fieldName, $name, mixed $extra = null)
    {
        if (!$this->canGetByName($fieldName)) {
            return null;
        }
        $meth = $fieldName . 'ByName';
        $key = match ($fieldName) {
            'state', 'tpTypeCategory', 'approvalReasons' => $this->$meth($name, $extra),
            'ownerID', 'requestor' => $this->userByName($name),
            'regCountry' => $this->countryByName($name),
            default => $this->$meth($name),
        };
        return $key;
    }

    /**
     * Lookup field value by key and get back its name
     *
     * @param string $fieldName Name of database column
     * @param string $key       Value to look for
     * @param mixed  $extra     Additional value needed by some lookups
     *
     * @return mixed Key value if found, false on no match, and null if lookup method does not exist
     */
    public function byKey($fieldName, $key, mixed $extra = null)
    {
        if (!$this->canGetByKey($fieldName)) {
            return null;
        }
        $meth = $fieldName . 'ByKey';
        $name = match ($fieldName) {
            'state', 'tpTypeCategory', 'approvalReasons' => $this->$meth($key, $extra),
            'ownerID', 'requestor' => $this->userByKey($key),
            'regCountry' => $this->countryByKey($key),
            default => $this->$meth($key),
        };
        return $name;
    }

    /**
     * Lookup country name by country code or other country name from iso database
     *
     * @param int|string $countryValue Country code or key from iso database
     *
     * @return mixed false or legacy country name
     */
    public function countryByKey($countryValue)
    {
        $rtn = false;
        if ($legacyName = $this->geoCls->getLegacyCountryName($countryValue)) {
            $rtn = $legacyName;
        }
        return $rtn;
    }

    /**
     * Lookup country code by country name or other country code from iso database
     *
     * @param int|string $countryValue Country name or code from iso database
     *
     * @return mixed false or legacy country code
     */
    public function countryByName($countryValue)
    {
        $rtn = false;
        if ($legacyCode = $this->geoCls->getLegacyCountryCode($countryValue)) {
            $rtn = $legacyCode;
        }
        return $rtn;
    }

    /**
     * Initialize region lookup array
     *
     * @return void
     */
    public function regionInit()
    {
        if (is_array($this->regions)) {
            return;
        }
        $this->regions = [];
        foreach ((new Region($this->clientID))->getTenantRegions() as $rec) {
            $this->regions[$rec['id']] = \Xtra::entityDecode($rec['name']);
        }
    }

    /**
     * Lookup region.id by region.name
     *
     * @param string $name region.name
     *
     * @return false|int false or region.id
     */
    public function regionByName($name)
    {
        $this->regionInit();
        return array_search($name, $this->regions);
    }

    /**
     * Lookup region.name by region.id
     *
     * @param int $key region.id
     *
     * @return false|string false or region.name
     */
    public function regionByKey($key)
    {
        $this->regionInit();
        if (array_key_exists($key, $this->regions)) {
            $name = $this->regions[$key];
        } elseif ($key == 0) {
            $name = '';
        } else {
            $name = false;
        }
        return $name;
    }

    /**
     * Initialize department lookup array
     *
     * @return void
     */
    public function departmentInit()
    {
        if (is_array($this->departments)) {
            return;
        }
        $this->departments = [];
        foreach ((new Department($this->clientID))->getTenantDepartments() as $rec) {
            $this->departments[$rec['id']] = \Xtra::entityDecode($rec['name']);
        }
    }

    /**
     * Lookup department.id by department.name
     *
     * @param string $name department.name
     *
     * @return false|int false or department.id
     */
    public function departmentByName($name)
    {
        $this->departmentInit();
        return array_search($name, $this->departments);
    }

    /**
     * Lookup department.name by department.id
     *
     * @param int $key department.id
     *
     * @return false|string false or department.name
     */
    public function departmentByKey($key)
    {
        $this->departmentInit();
        if (array_key_exists($key, $this->departments)) {
            $name = $this->departments[$key];
        } elseif ($key == 0) {
            $name = '';
        } else {
            $name = false;
        }
        return $name;
    }

    /**
     * Initialize legalForm lookup array
     *
     * @return void
     */
    public function legalFormInit()
    {
        if (is_array($this->legalForms)) {
            return;
        }
        $this->legalForms = [];
        foreach ((new CompanyLegalForm($this->clientID))->getRecords() as $rec) {
            $this->legalForms[$rec['id']] = \Xtra::entityDecode($rec['name']);
        }
    }

    /**
     * Lookup legalForm.id by legalForm.name
     *
     * @param string $name legalForm.name
     *
     * @return mixed false or legalForm.id
     */
    public function legalFormByName($name)
    {
        $this->legalFormInit();
        return array_search($name, $this->legalForms);
    }

    /**
     * Lookup legalForm.name by legalForm.id
     *
     * @param int $key legalForm.id
     *
     * @return mixed false or legalForm.name
     */
    public function legalFormByKey($key)
    {
        $this->legalFormInit();
        if (array_key_exists($key, $this->legalForms)) {
            $name = $this->legalForms[$key];
        } elseif ($key == 0) {
            $name = '';
        } else {
            $name = false;
        }
        return $name;
    }

    /**
     * Initialize tpType lookup array
     *
     * @return void
     */
    public function tpTypeInit()
    {
        if (is_array($this->tpTypes)) {
            return;
        }
        $this->tpTypes = [];
        foreach ((new TpType($this->clientID))->getRecords("AND active = 1") as $rec) {
            $this->tpTypes[$rec['id']] = \Xtra::entityDecode($rec['name']);
        }
    }

    /**
     * Lookup tpType.id by tpType.name
     *
     * @param string $name tpType.name
     *
     * @return mixed false or tpType.id
     */
    public function tpTypeByName($name)
    {
        $this->tpTypeInit();
        return array_search($name, $this->tpTypes);
    }

    /**
     * Lookup tpType.name by tpType.id
     *
     * @param int $key tpType.id
     *
     * @return mixed false or tpType.name
     */
    public function tpTypeByKey($key)
    {
        $this->tpTypeInit();
        if (array_key_exists($key, $this->tpTypes)) {
            $name = $this->tpTypes[$key];
        } elseif ($key == 0) {
            $name = '';
        } else {
            $name = false;
        }
        return $name;
    }

    /**
     * Initialize tpTypeCategory lookup array
     *
     * @return void
     */
    public function tpTypeCategoryInit()
    {
        if (is_array($this->tpCats)) {
            return;
        }
        $this->tpTypeInit();
        $this->tpCats = [];
        $catData = new TpTypeCategory($this->clientID);
        foreach ($this->tpTypes as $typeID => $name) {
            $this->tpCats[$typeID] = [];
            if ($cats = $catData->getCategoryActiveRecords($typeID)) {
                foreach ($cats as $cat) {
                    $this->tpCats[$typeID][$cat['id']] = \Xtra::entityDecode($cat['name']);
                }
            }
        }
    }

    /**
     * Lookup tpTypeCategory.id by tpTypeCategory.name
     *
     * @param string $name   tpTypeCategory.name
     * @param int    $typeID tpType.id
     *
     * @return mixed false or tpTypeCategory.id
     */
    public function tpTypeCategoryByName($name, $typeID)
    {
        $this->tpTypeCategoryInit();
        if (array_key_exists($typeID, $this->tpCats)) {
            $key = array_search($name, $this->tpCats[$typeID]);
        } else {
            $key = false;
        }
        return $key;
    }

    /**
     * Lookup tpTypeCategory.name by tpTypeCategory.id
     *
     * @param int $key    tpTypeCategory.id
     * @param int $typeID tpType.id
     *
     * @return mixed false or tpTypeCategory.name
     */
    public function tpTypeCategoryByKey($key, $typeID)
    {
        $this->tpTypeCategoryInit();
        if (array_key_exists($typeID, $this->tpCats)
            && array_key_exists($key, $this->tpCats[$typeID])
        ) {
            $name = $this->tpCats[$typeID][$key];
        } elseif ($key == 0) {
            $name = '';
        } else {
            $name = false;
        }
        return $name;
    }

    /**
     * Initialize approvalReasons lookup array
     *
     * @return void
     */
    public function approvalReasonsInit()
    {
        if (is_array($this->approvalReasons)) {
            return;
        }
        $this->approvalReasons['approved'] = [];
        $this->approvalReasons['denied'] = [];
        $this->approvalReasons['pending'] = [];
        if ($recs = (new TpApprovalReasons($this->clientID))->getRecords()) {
            $allow = ['approved', 'denied', 'pending'];
            foreach ($recs as $rec) {
                if ($rec['active'] && in_array($rec['approvalStatus'], $allow)) {
                    $this->approvalReasons[$rec['approvalStatus']][$rec['id']]
                        = \Xtra::entityDecode($rec['reason']);
                }
            }
        }
    }

    /**
     * Lookup tpApprovalReasons.id by tpApprovalReasons.reason
     *
     * @param string $name           tpApprovalReasons.reason
     * @param int    $approvalStatus tpType.id
     *
     * @return mixed false or tpApprovalReasons.id
     */
    public function approvalReasonsByName($name, $approvalStatus)
    {
        $this->approvalReasonsInit();
        if (array_key_exists($approvalStatus, $this->approvalReasons)) {
            $key = array_search($name, $this->approvalReasons[$approvalStatus]);
        } else {
            $key = false;
        }
        return $key;
    }

    /**
     * Lookup tpApprovalReasons.reason by tpApprovalReasons.id
     *
     * @param int $key            tpApprovalReasons.id
     * @param int $approvalStatus approval status
     *
     * @return mixed false or tpApprovalReasons.name
     */
    public function approvalReasonsByKey($key, $approvalStatus)
    {
        $this->approvalReasonsInit();
        if (array_key_exists($approvalStatus, $this->approvalReasons)
            && array_key_exists($key, $this->approvalReasons[$approvalStatus])
        ) {
            $name = $this->approvalReasons[$approvalStatus][$key];
        } elseif ($key == 0) {
            $name = '';
        } else {
            $name = false;
        }
        return $name;
    }

    /**
     * Lookup state legacy name by state code (or other name)
     *
     * @param string     $stateValue   State name (or code) from iso database
     * @param int|string $countryValue Country code or country name from iso database
     *
     * @return mixed State legacy name or false if not found
     */
    public function stateByKey($stateValue, $countryValue)
    {
        $rtn = false;
        if ($legacyName = $this->geoCls->getLegacyStateName($stateValue, $countryValue)) {
            $rtn = $legacyName;
        }
        return $rtn;
    }

    /**
     * Get legacyStateCode (abbrev) from iso data by state name or code
     *
     * @param string     $stateValue   State name (or code) from iso database
     * @param int|string $countryValue Country code or country name from iso database
     *
     * @return mixed State legacy code (abbrev) or false if not found
     */
    public function stateByName($stateValue, $countryValue)
    {
        $rtn = false;
        if ($legacyCode = $this->geoCls->getLegacyStateCode($stateValue, $countryValue)) {
            $rtn = $legacyCode;
        }
        return $rtn;
    }

    /**
     * Initialize users table name
     *
     * @return void
     */
    public function userInit()
    {
        if (is_null($this->usersTbl)) {
            $this->usersTbl = $this->DB->authDB . '.users';
        }
    }

    /**
     * Lookup login (userid) from userID
     *
     * @param int $userID users.id
     *
     * @return mixed string userid/login or false
     */
    public function userByKey($userID)
    {
        if ($userID == 0) {
            return '';
        }
        $this->userInit();
        $rtn = false;
        $sql = "SELECT userid FROM $this->usersTbl WHERE id = :id LIMIT 1";
        if ($login = $this->DB->fetchValue($sql, [':id' => $userID])) {
            $rtn = $login;
        }
        return $rtn;
    }

    /**
     * Lookup userID from login
     *
     * @param string $login users.userid
     *
     * @return mixed int users.id or false
     */
    public function userByName($login)
    {
        $this->userInit();
        $rtn = false;
        $sql = "SELECT id FROM $this->usersTbl WHERE userid = :login LIMIT 1";
        if ($id = $this->DB->fetchValue($sql, [':login' => $login])) {
            $rtn = $id;
        }
        return $rtn;
    }

    /**
     * Lookup spDeliveryOption.name from spDeliveryOptionProduct.id
     *
     * @param int $key spDeliveryOptionProduct.id
     *
     * @return string
     */
    public function deliveryOptionByKey($key)
    {
        if (empty($key)) {
            return '';
        }
        $spDB = $this->DB->spGlobalDB;
        $doTbl = "$spDB.spDeliveryOption";
        $dopTbl = "$spDB.spDeliveryOptionProduct";
        $sql = "SELECT do.`name` FROM $dopTbl AS dop INNER JOIN $doTbl as do ON do.id = dop.deliveryOptionID\n"
            . "WHERE dop.id = :key LIMIT 1";
        if ($name = $this->DB->fetchValue($sql, [':key' => $key])) {
            return $name;
        }
        return '';
    }

    /**
     * Lookup spDeliveryOption ID from name
     *
     * @param int    $spID     investigatorProfile.id
     * @param string $name     spDeliveryOption.name or spDeliveryOption.abbrev
     * @param int    $caseType cases.caseType
     *
     * @return array of assoc int values with keys: option, spProduct, delivery
     */
    public function deliveryOptionByName($spID, $name, $caseType)
    {
        $rtn = [
            'option' => null,     // spDeliveryOption.id
            'spProduct' => null,  // spProduct.id
            'delivery' => null,   // spDeliveryOptionProduct.id
        ];
        // get spDeliveryOption.id
        $dlvy = new DeliveryOption();
        // Get spDeliveryOption.id
        if ($optionID = $dlvy->optionIdByName($spID, $name)) {
            $rtn['option'] = $optionID;
            // Get spProduct.id
            $tbl = $this->clientDB . '.clientSpProductMap';
            $sql = "SELECT product FROM $tbl\n"
                . "WHERE spID = :sp AND scope = :scope AND clientID = :cid LIMIT 1";
            $params = [':sp' => $spID, ':scope' => $caseType, ':cid' => $this->clientID];
            if ($productID = $this->DB->fetchValue($sql, $params)) {
                $rtn['spProduct'] = $productID;
                // Get spDeliveryOptionProduct.id
                $tbl = $this->DB->spGlobalDB . '.spDeliveryOptionProduct';
                $sql = "SELECT id FROM $tbl\n"
                    . "WHERE productID = :product AND deliveryOptionID = :option AND clientID = :cid\n"
                    . "AND current = 1 AND active = 1 LIMIT 1";
                $params = [':product' => $productID, ':option' => $optionID, ':cid' => $this->clientID];
                if ($dopID = $this->DB->fetchValue($sql, $params)) {
                    $rtn['delivery'] = $dopID;
                } else {
                    // try clientID 0
                    $params[':cid'] = 0;
                    $rtn['delivery'] = $this->DB->fetchValue($sql, $params);
                }
            }
        }
        return $rtn;
    }

    /**
     * Get caseStage.id match caseStage.name or caseStage.constant
     *
     * @param string $nameOrConst Name or constant to match in client caseStage
     *
     * @return int|null caseStage.id or null
     */
    public function caseStageByName($nameOrConst)
    {
        $tbl = $this->clientDB . '.caseStage';
        $sql = "SELECT id FROM $tbl WHERE `constant` = :const OR `name` = :name LIMIT 1";
        $params = [':const' => $nameOrConst, ':name' => $nameOrConst];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Get customSelectList.id from name
     *
     * @param string $itemName  customSelectList.name
     * @param string $listName  customSelectList.listName
     * @param string $eItemName Match name with html entities
     *
     * @return null|int
     */
    public function customSelectListItemByName($itemName, $listName, $eItemName = '')
    {
        $tbl = $this->clientDB . '.customSelectList';
        $params = [
            ':cli' => $this->clientID,
            ':list' => $listName,
            ':item' => $itemName,
            ':active' => 1
        ];
        if ($eItemName) {
            $sql = "SELECT id FROM $tbl WHERE (`name` = :item OR `name` = :eitem) ";
            $params[':eitem'] = $eItemName;
        } else {
            $sql = "SELECT id FROM $tbl WHERE `name` = :item ";
        }
        $sql .= " AND clientID = :cli AND listName = :list AND active = :active LIMIT 1";
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Match caseTypeClient.name or caseTypeClient.abbrev to get caseTypeID
     *
     * @param string $nameOrAbbrev caseTypeClient.name or caseTypeClient.abbrev
     *
     * @return null|int
     */
    public function caseTypeByName($nameOrAbbrev)
    {
        $tbl = $this->clientDB . '.caseTypeClient';
        $sql = "SELECT caseTypeID FROM $tbl WHERE (clientID = :cli OR clientID = 0)\n"
            . "AND (`name` = :name OR `abbrev` = :abbrev) ORDER BY clientID DESC LIMIT 1";
        $params = [':name' => $nameOrAbbrev, ':abbrev' => $nameOrAbbrev, ':cli' => $this->clientID];
        if ($caseTypeID = $this->DB->fetchValue($sql, $params)) {
            return $caseTypeID;
        }
        return null;
    }

    /**
     * Get caseTypeClient.name from caseTypeClient.caseTypeID and clientID
     *
     * @param int $key caseTypeClient.caseTypeID
     *
     * @return string
     */
    public function caseTypeByKey($key)
    {
        if (empty($key)) {
            return '';
        }
        $tbl = "$this->clientDB.caseTypeClient";
        $sql = "SELECT `name` FROM $tbl\n"
            . "WHERE caseTypeID = :key AND (clientID = :cid OR clientID = 0) ORDER BY clientID DESC LIMIT 1";
        $params = [':key' => $key, ':cid' => $this->clientID];
        if ($name = $this->DB->fetchValue($sql, $params)) {
            return $name;
        }
        return '';
    }

    /**
     * Lookup cassRejectCode.id from rejectCaseCode.name
     *
     * @param string $name rejectCaseCode.name to match
     *
     * @return int|null
     */
    public function rejectReasonByName($name)
    {
        return $this->idByNameWithClient0Fallback($name, 'rejectCaseCode');
    }

    /**
     * Lookup rejectCaseCode.name from rejectCaseCode.id
     *
     * @param int $key rejectCaseCode.id
     *
     * @return string
     */
    public function rejectReasonByKey($key)
    {
        if (empty($key)) {
            return '';
        }
        $tbl = $this->clientDB . '.rejectCaseCode';
        $sql = "SELECT `name` FROM $tbl\n"
            . "WHERE id = :key AND (clientID = :cid OR clientID = 0) ORDER BY clientID DESC LIMIT 1";
        if ($name = $this->DB->fetchValue($sql, [':key' => $key, ':cid' => $this->clientID])) {
            return $name;
        }
        return '';
    }

    /**
     * Lookup relationshipType.id from relationshipType.name
     *
     * @param string $name relationshipType.name to match
     *
     * @return int|null
     */
    public function relationshipTypeByName($name)
    {
        return $this->idByNameWithClient0Fallback($name, 'relationshipType');
    }

    /**
     * Lookup relationshipType.name from relationshipType.id
     *
     * @param int $key relationshipType.id
     *
     * @return string
     */
    public function relationshipTypeByKey($key)
    {
        if (empty($key)) {
            return '';
        }
        $tbl = $this->clientDB . '.relationshipType';
        $sql = "SELECT `name` FROM $tbl\n"
            . "WHERE id = :key AND (clientID = :cid OR clientID = 0) ORDER BY clientID DESC LIMIT 1";
        if ($name = $this->DB->fetchValue($sql, [':key' => $key, ':cid' => $this->clientID])) {
            return $name;
        }
        return '';
    }

    /**
     * Lookup billingUnit.id from billingUnit.name
     *
     * @param string $name Name to match
     *
     * @return int|null
     */
    public function billingUnitByName($name)
    {
        return $this->idByNameWithClient0Fallback($name, 'billingUnit');
    }

    /**
     * Get bilingUnit.name from billUnit.id
     *
     * @param int $key billingUnit.id
     *
     * @return string
     */
    public function billingUnitByKey($key)
    {
        if (empty($key)) {
            return '';
        }
        $tbl = $this->clientDB . '.billingUnit';
        $sql = "SELECT `name` FROM $tbl WHERE id = :key AND clientID = :cid LIMIT 1";
        if ($name = $this->DB->fetchValue($sql, [':cid' => $this->clientID, ':key' => $key])) {
            return $name;
        }
        return '';
    }

    /**
     * Lookup billingUnitPO.id from billingUnitPO.name and
     *
     * @param string $name          billingUnitPO.name to match
     * @param int    $billingUnitID billingUnitPO.buID
     *
     * @return int|null
     */
    public function billingUnitPoByName($name, $billingUnitID)
    {
        $tbl = $this->clientDB . '.billingUnitPO';
        $sql = "SELECT id FROM $tbl WHERE name = :name AND clientID = :cid AND buID = :buID LIMIT 1";
        $params = [':name' => $name, ':cid' => $this->clientID, ':buID' => $billingUnitID];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Get bilingUnitPO.name from billUnitPO.id
     *
     * @param int $key           billingUnitPO.id
     * @param int $billingUnitID billingUnit.id
     *
     * @return string
     */
    public function billingUnitPoByKey($key, $billingUnitID)
    {
        if (empty($key)) {
            return '';
        }
        $tbl = $this->clientDB . '.billingUnitPO';
        $params = [':cid' => $this->clientID, ':key' => $key, ':buID' => $billingUnitID];
        $sql = "SELECT `name` FROM $tbl WHERE id = :key AND buID = :buID AND clientID = :cid LIMIT 1";
        if ($name = $this->DB->fetchValue($sql, $params)) {
            return $name;
        }
        return '';
    }

    /**
     * Generic id lookup by name in client table with client0 fallback
     *
     * @param string $nameToFind  Value to match to name column
     * @param string $tableName   Name of table in client database
     * @param string $nameCol     Name of name column to match
     * @param string $idCol       Name of id column to return
     * @param string $clientIdCol Name of clientID column
     *
     * @return int|null
     */
    private function idByNameWithClient0Fallback(
        $nameToFind,
        $tableName,
        $nameCol = 'name',
        $idCol = 'id',
        $clientIdCol = 'clientID'
    ) {
        static $goodCol = [];
        static $badCol = [];

        // Check column only once per process
        $fullIdCol = "`$this->clientDB`.`$tableName`.`$idCol`";
        $fullNameCol = "`$this->clientDB`.`$tableName`.`$nameCol`";
        $fullClientCol = "`$this->clientDB`.`$tableName`.`$clientIdCol`";

        // Validate columns names
        if (!in_array($fullIdCol, $goodCol) && !in_array($fullIdCol, $badCol)) {
            if (!$this->DB->columnExists($idCol, $tableName, $this->clientDB)) {
                \Xtra::track("`$this->clientDB`.`$tableName`.`$idCol` does not exist");
                $badCol[] = $fullIdCol;
                return null;
            } else {
                $goodCol[] = $fullIdCol;
            }
        }
        if (!in_array($fullNameCol, $goodCol) && !in_array($fullNameCol, $badCol)) {
            if (!$this->DB->columnExists($nameCol, $tableName, $this->clientDB)) {
                \Xtra::track("`$this->clientDB`.`$tableName`.`$nameCol` does not exist");
                $badCol[] = $fullNameCol;
                return null;
            } else {
                $goodCol[] = $fullNameCol;
            }
        }
        if (!in_array($fullClientCol, $goodCol) && !in_array($fullClientCol, $badCol)) {
            if (!$this->DB->columnExists($clientIdCol, $tableName, $this->clientDB)) {
                \Xtra::track("`$this->clientDB`.`$tableName`.`$clientIdCol` does not exist");
                $badCol[] = $fullClientCol;
                return null;
            } else {
                $goodCol[] = $fullClientCol;
            }
        }

        $sql = "SELECT `$idCol` "
            . "FROM `$this->clientDB`.`$tableName` "
            . "WHERE `$nameCol` = :name AND ";
        $params = $params0 = [':name' => $nameToFind];
        $params[':cid'] = $this->clientID;
        if ($idVal = $this->DB->fetchValue($sql . "`$clientIdCol` = :cid LIMIT 1", $params)) {
            return $idVal;
        }
        return $this->DB->fetchValue($sql . "`$clientIdCol` = 0 LIMIT 1", $params0);
    }
}
