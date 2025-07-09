<?php
/**
 * Add Profile Dialog
 *
 * @category AddProfile_Dialog
 * @package  Models\TPM\AddProfile
 * @keywords SEC-873 & SEC-2844
 */

namespace Models\TPM\AddProfile;

use Lib\Database\MySqlPdo;
use Models\ThirdPartyManagement\ThirdParty;
use Models\TPM\MultiAddr\TpAddrs;
use Models\Globals\Region;
use Models\Globals\Department;
use Lib\Legacy\Search\Search3pData;
use Lib\Legacy\UserType;

/**
 * Class AddProfile
 */
#[\AllowDynamicProperties]
class AddProfile
{
    /**
     * @var object Instance of app
     */
    private $app;

    /**
     * @var int clientID
     */
    private $clientID;

    /**
     * @var int Users id
     */
    private $userID;

    /**
     * @var MySqlPdo database instance
     */
    private $clientDB;

    /**
     * @var MySqlPdo address model instance
     */
    private $addrMdl;

    /**
     * AddProfile constructor.
     *
     * @param int $clientID logged in client ID
     * @param int $userID   logged in user ID
     */
    public function __construct($clientID, $userID)
    {
        $this->app      = \Xtra::app();
        $this->clientID = (int)$clientID;
        $this->userID   = (int)$userID;
        $this->clientDB = $this->app->DB->getClientDB($this->clientID);
        $this->addrMdl  = new TpAddrs($this->clientID);
    }

    /**
     * Queries for a profile type list, creating a default profile type if none exist
     *
     * @return array indexed array of valid profile types for a given client.
     */
    public function getProfileTypesList()
    {
        $sql = "SELECT id, name FROM {$this->clientDB}.tpType "
            . "WHERE clientID = :clientID ORDER BY name ASC";
        $bind = [':clientID' => $this->clientID];
        $typeMap = $this->app->DB->fetchKeyValueRows($sql, $bind);

        if (is_array($typeMap) && count($typeMap) == 0) {
            $this->insertDefaultProfileType();
            $typeMap = $this->app->DB->fetchKeyValueRows($sql, $bind);
        }

        return $typeMap;
    }

    /**
     * If no profile types exist, this method creates a default profile type
     *
     * @note refactored logic from tp-ws.php case 'np-init'
     * @note the idea of compensating for empty, valid sql selects is not done in Delta's GdcBatchData->getTypeMap()
     * @note this seems really out of place here.
     *
     * @return void
     */
    private function insertDefaultProfileType()
    {
        $this->app->DB->query(
            "INSERT INTO {$this->clientDB}.tpType SET name = 'Intermediary', clientID = :clientID, active = :active ",
            [
                ':clientID' => $this->clientID,
                ':active'   => '1'
            ]
        );
    }

    /**
     * Queries for a list of categories matching a given profile type
     *
     * @param string $profileType profile type
     *
     * @return array indexed array of valid category types for a given profile type
     */
    public function npCategories($profileType)
    {
        $sql = "SELECT id, name FROM {$this->clientDB}.tpTypeCategory WHERE clientID = :clientID \n"
            . "AND tpType= :profileType ORDER BY name ASC";
        $bind = [
            ':clientID' => $this->clientID,
            ':profileType' => $profileType,
        ];
        $categoryMap = $this->app->DB->fetchKeyValueRows($sql, $bind);

        if (is_array($categoryMap) && count($categoryMap) == 0) {
            $this->insertDefaultCategoryType($profileType);
            $categoryMap = $this->app->DB->fetchKeyValueRows($sql, $bind);
        }

        return $categoryMap;
    }

    /**
     * If no category types exist, this method creates a default category for the given profile type
     *
     * @param string $profileType profile type
     *
     * @note not a safe query to insert unless the profileType has been validated
     * @note even validated, the insert seems really out of place.
     *
     * @return void
     */
    private function insertDefaultCategoryType($profileType)
    {
        $profileTypes = $this->getProfileTypesList();

        if (isset($profileTypes[$profileType])) {
            $this->app->DB->query(
                "INSERT INTO {$this->clientDB}.tpTypeCategory \n" .
                "SET name = 'Agent', clientID = :clientID, tpType = :profileType, active = :active",
                [
                    ':clientID'    => $this->clientID,
                    ':profileType' => $profileType,
                    ':active'      => '1'
                ]
            );
        }
    }

    /**
     * Queries for a list of user regions accessible to the user
     *
     * @return array indexed array of user regions
     */
    public function getUserRegions()
    {
        $regions = [];
        foreach ((new Region($this->clientID))->getUserRegions($this->userID) as $key => $row) {
            $regions[$row['id']] = $row['name'];
        }

        return $regions;
    }

    /**
     * Queries for a list of user departments accessible to the user
     *
     * @return array indexed array of user departments, sorted by key (sorting values alphabetically)
     */
    public function getUserDepartments()
    {
        $indexed = [];
        foreach ((new Department($this->clientID))->getUserDepartments($this->userID) as $department) {
            $indexed[$department['id']] = $department['name'];
        }
        if (is_array($indexed)) {
            asort($indexed);
        }

        return $indexed;
    }

    /**
     * Queries thirdPartyProfiles on a global search for possible profile matches
     *
     * @param string $companyName thirdPartyProfile user input name to search
     *
     * @return object formatted results of a global record search
     */
    public function returnMatches($companyName)
    {
        $ordered = [];
        $lookup  = [];
        $return  = [];
        $matches = (new Search3pData())->getRecordsGlobalSearch($companyName);
        $count   = count($matches);

        if ($count > 50) {
            $matches = $this->returnExactMatches($companyName, $matches);
        }

        foreach ($matches as $match) {
            $ordered[$match->dbid] = $match->coname;
            $lookup[$match->dbid]  = (array)$match;
        }
        asort($ordered);

        foreach ($ordered as $dbid => $name) {
            $return[] = [
                'id'      => $dbid,
                'name'    => $name,
                'dbaname' => $lookup[$dbid]['dbaname'],
            ];
        }

        $returnObject          = new \stdClass();
        $returnObject->Matches = $return;
        $returnObject->Count   = $count;
        $returnObject->TooMany = ($count > 20);

        return $returnObject;
    }

    /**
     * Compares a list of possible globally searched match hits against a given company name search string
     * returning a filtered list of exact matches for either thirdPartyProfile.legalName or thirdPartyProfile.DBAName
     *
     * @param string $companyName the new thirdPartyProfile legalName the user inputted
     * @param array  $matches     an array of globally searched partial matches for the user inputted company name
     *
     * @return array of filtered globally searched matches
     */
    private function returnExactMatches($companyName, $matches)
    {
        foreach ($matches as $key => $record) {
            if ((strtolower($companyName) == strtolower((string) $record->coname))
                || (strtolower($companyName) == strtolower((string) $record->dbaname))
            ) {
                continue;
            }
            unset($matches[$key]);
        }
        return $matches;
    }

    /**
     * Returns user access level and dialog content for a thirdPartyProfile record.
     *
     * @param int $recordID thirdPartyProfile.id
     *
     * @return object information about a thirdPartyProfile record
     */
    public function restrictedProfileAccess($recordID)
    {
        return (new Search3pData())->checkProfileAccessGlobalSearch($recordID);
    }

    /**
     * Given sanitized and validated input, creates a new thirdPartyProfile record.
     *
     * @param array $inputs thirdPartyProfile values for a new record
     *
     * @return mixed|string thirdPartyProfile.id on success, string on failure
     */
    public function addNewThirdParty($inputs)
    {
        $tpp = new ThirdParty($this->clientID);
        $ownerID = ($this->app->ftr->legacyUserType > UserType::CLIENT_ADMIN)
            ? $tpp->getDefaultOwnerID()
            : $this->userID;

        $attributes = [
            'clientID' => $this->clientID,
            'legalName' => $inputs['npCompany'],
            'recordType' => $inputs['npRecordType'],
            'DBAname' => $inputs['npDBAname'],
            'addr1' => $inputs['npAddr1'],
            'addr2' => $inputs['npAddr2'],
            'city' => $inputs['npCity'],
            'country' => $inputs['npCountry'],
            'state' => $inputs['npState'],
            'postcode' => $inputs['npPostCode'],
            'region' => $inputs['npRegion'],
            'POCname' => $inputs['npPoc'],
            'POCphone1' => $inputs['npPhone1'],
            'POCphone2' => $inputs['npPhone2'],
            'POCemail' => $inputs['npEmail'],
            'userTpNum' => '', // to be provided
            'ownerID' => $ownerID,
            'tpCreated' => date('Y-m-d H:i:s'),
            'createdBy' => $this->userID,
            'department' => $inputs['npDepartment'],
            'tpType' => $inputs['npType'],
            'tpTypeCategory' => $inputs['npCategory'],
            'internalCode' => $inputs['npInternalCode'],
        ];

        // Insert new profile and sync embedded address
        if ($tpp->setAttributes($attributes) && $tpp->save()) {
            return $tpp->getId();
        }
        return 'Unable to create new Third Party Profile';
    }

    /**
     * Searches user inputted company name for an exact match on thirdPartyProfile.legalName
     *
     * Matches Legacy function public_html/cms/thirdparty/tp-ws.php
     *
     * @param string $companyName user inputted thirdPartyProfile.legalName
     *
     * @return mixed id of thirdPartyProfile if exact match found, null if not found
     */
    public function isExactMatch($companyName)
    {
        return $this->app->DB->fetchValue(
            "SELECT id FROM {$this->clientDB}.thirdPartyProfile WHERE legalName = :companyName \n" .
            "AND clientID = :clientID LIMIT 1",
            [
                ':companyName' => $companyName,
                ':clientID'    => $this->clientID,
            ]
        );
    }


    /**
     * If the `Add Profile` dialog is opened with a valid thirdPartyProfile.id (e.g., through Add Case association)
     * then this method will return thirdPartyProfile data to populate the opening `Add Profile` dialog.
     *
     * The Delta Add 3P Dialog has a global tenant-level scope while Legacy's is user-oriented.
     * If it does not make sense to abandon the restricted profile access for associations here
     * then we need to relay a good message to the user about why they can search for the profile
     * inside the 3P form, but they can't associate the same profile from Add Case.
     * $recordInfo = $this->restrictedProfileAccess($tppID);
     *
     * @param string $tppID thirdPartyProfile.id
     *
     * @return array of entry data with valid profile access or null
     */
    public function getTPPEntryData($tppID)
    {
        return (new ThirdParty($this->clientID))->getTPPEntryData($tppID);
    }
}
