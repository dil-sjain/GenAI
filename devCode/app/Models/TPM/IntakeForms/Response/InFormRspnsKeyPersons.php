<?php
/**
 * Provides basic read/write access to inFormRspnsCompanies table
 */

namespace Models\TPM\IntakeForms\Response;

use Lib\Validation\Validator\EmailAddress;
use Models\BaseLite\RequireClientID;
use Models\Ddq;
use Models\ThirdPartyManagement\SubjectInfoDD;

/**
 * Basic CRUD access to inFormRspnsCompanies,  requiring tenantID
 *
 * @keywords tenants, settings, tenants settings
 */
#[\AllowDynamicProperties]
class InFormRspnsKeyPersons extends RequireClientID
{

    public const KEY_PERSONS_LIMIT = 10;
    public const CATEGORY_OWNER = 1;
    public const CATEGORY_KEY_MANAGER = 2;
    public const CATEGORY_BOARD_MEMBER = 3;
    public const CATEGORY_KEY_CONSULTANT = 4;

    protected $tbl = 'ddqKeyPerson';
    protected $clientIdField = 'clientID';
    protected $primaryID = 'id';
    protected $tenantID = null;

    /**
     * @var integer number of decimal places used for percentage
     */
    protected $percentDecPlc = 3;

    /**
     * Determines whether or not this was instantiated via the REST API
     *
     * @var boolean
     */
    protected $isAPI = false;

    /**
     * Authorized userID
     *
     * @var integer
     */
    protected $authUserID = null;

    /**
     * Mapping of API parameters to DB columns
     *
     * @var array
     */
    private $apiParamToDbColumnMapping = [
        'name' => 'kpName',
        'email' => 'email',
        'position' => 'kpPosition',
        'nationality' => 'kpNationality',
        'address' => 'kpAddr',
        'idNumber' => 'kpIDnum',
        'owner' => 'bkpOwner',
        'ownerPercentage' => 'kpOwnPercent',
        'keyManager' => 'bkpKeyMgr',
        'boardMember' => 'bkpBoardMem',
        'keyConsultant' => 'bkpKeyConsult',
        'embargoedResidents' => 'bResOfEmbargo',
        'proposedRole' => 'proposedRole'
    ];

    /**
     * Mapping of ddqKeyPerson columns to default values
     */
    private $columnDefaults = [
        'kpPosition' => '',
        'kpNationality' => '',
        'kpAddr' => '',
        'kpIDnum' => '',
        'bkpOwner' => 0,
        'kpOwnPercent' => 0.000,
        'bkpKeyMgr' => 0,
        'bkpBoardMem' => 0,
        'bkpKeyConsult' => 0,
        'bResOfEmbargo' => '',
        'proposedRole' => '',
        'email' => ''
    ];

    /**
     * Mapping of validation rules
     *
     * @var array
     */
    private $validationRules = [
        'id' => ['required' => false, 'validateBy' => 'value', 'value' => 'primaryID'],
        'name' => ['required' => true],
        'email' => ['required' => true, 'validateBy' => 'format', 'format' => 'email'],
        'position' => ['required' => true],
        'nationality' => ['required' => true],
        'address' => ['required' => true],
        'idNumber' => ['required' => true],
        'personnelRolesIDs' => [
            'required' => true,
            'validateBy' => 'value',
            'values' => [
                self::CATEGORY_OWNER,
                self::CATEGORY_KEY_MANAGER,
                self::CATEGORY_BOARD_MEMBER,
                self::CATEGORY_KEY_CONSULTANT,
            ],
        ],
        'ownerPercentage' => [
            'required' => 'contingent',
            'contingency' => ['personnelRolesIDs' => self::CATEGORY_OWNER],
            'validateBy' => 'numberRange',
            'minimum' => 0.001,
            'maximum' => 100,
            'default' => 0.00
        ],
        'embargoedResidents' => ['required' => false, 'validateBy' => 'value', 'values' => ['Yes', 'No']],
        'proposedRole' => ['required' => true]
    ];

    /**
     * Set by an override subclass
     *
     * @var array
     */
    protected $overrides = [];

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID ddqKeyPerson.clientID
     * @param array   $params   Optional params to pass in
     *
     * @return void
     */
    public function __construct($tenantID, $params = [])
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $this->tenantID = $tenantID;
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : \Xtra::app()->session->get('authUserID');
        if (!empty($params['overrides']) && !empty($params['overrides']['keyPersons'])) {
            $this->overrides = $params['overrides']['keyPersons'];
            if (!empty($this->overrides['validationRules'])) {
                $this->validationRules = $this->overrides['validationRules'];
            }
        }
    }

    /**
     * Creates ddqKeyPerson records for a given intake form
     *
     * @param integer $ddqID  ddqKeyPerson.ddqID
     * @param array   $people ddqKeyPerson configurations
     *
     * @return boolean
     */
    public function createMultiple($ddqID, $people)
    {
        $ddqID = (int)$ddqID;
        $ddqModel = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
        $ddqIdInvalid = (empty($ddqID) || !($ddq = $ddqModel->findById($ddqID)));
        $rtn = false;
        if ($ddqIdInvalid) {
            return $rtn;
        } else {
            foreach ($people as $person) {
                $rtn = [];
                if (!empty($this->overrides)) {
                    $created = $this->createSingleFromOverride($ddqID, $person, true);
                } else {
                    $name = $address = $email = $nationality = $position = $proposedRole = $idNumber = '';
                    $owner = $keyManager = $boardMember = $keyConsultant = false;
                    $ownerPercentage = 0;
                    $embargoedResidents = 'No';
                    foreach ($person as $key => $value) {
                        if ($key == 'personnelRolesIDs') {
                            $owner = (in_array(self::CATEGORY_OWNER, $value));
                            $keyManager = (in_array(self::CATEGORY_KEY_MANAGER, $value));
                            $boardMember = (in_array(self::CATEGORY_BOARD_MEMBER, $value));
                            $keyConsultant = (in_array(self::CATEGORY_KEY_CONSULTANT, $value));
                        }
                        ${$key} = $value;
                    }
                    $created = $this->createSingle(
                        $ddqID,
                        $name,
                        $address,
                        $email,
                        $nationality,
                        $position,
                        $proposedRole,
                        $idNumber,
                        $owner,
                        $ownerPercentage,
                        $keyManager,
                        $boardMember,
                        $keyConsultant,
                        $embargoedResidents,
                        true
                    );
                }
                if ($created) {
                    $rtn = true;
                }
            }
        }
        return $rtn;
    }

    /**
     * Creates a ddqKeyPerson record for a given intake form
     *
     * @param integer $ddqID              ddqKeyPerson.ddqID
     * @param string  $name               ddqKeyPerson.kpName
     * @param string  $address            ddqKeyPerson.kpAddr
     * @param string  $email              ddqKeyPerson.email
     * @param string  $nationality        ddqKeyPerson.kpNationality
     * @param string  $position           ddqKeyPerson.kpPosition
     * @param string  $proposedRole       ddqKeyPerson.proposedRole
     * @param string  $idNumber           ddqKeyPerson.kpIDnum
     * @param boolean $owner              ddqKeyPerson.bkpOwner
     * @param string  $ownerPercentage    ddqKeyPerson.kpOwnPercent (decimal)
     * @param boolean $keyManager         ddqKeyPerson.bkpKeyMgr
     * @param boolean $boardMember        ddqKeyPerson.bkpBoardMem
     * @param boolean $keyConsultant      ddqKeyPerson.bkpKeyConsult
     * @param string  $embargoedResidents ddqKeyPerson.bResOfEmbargo (either Yes or No)
     * @param boolean $partOfGroup        If true, called from createMultiple() which vets ddqID
     *
     * @return mixed If updated succeeded, then integer else false boolean
     */
    public function createSingle(
        $ddqID,
        $name = '',
        $address = '',
        $email = '',
        $nationality = '',
        $position = '',
        $proposedRole = '',
        $idNumber = '',
        $owner = false,
        $ownerPercentage = 0,
        $keyManager = false,
        $boardMember = false,
        $keyConsultant = false,
        $embargoedResidents = 'No',
        $partOfGroup = false
    ) {
        $ownerPercentage = $this->formatPercent($ownerPercentage);
        if (!$partOfGroup) {
            $ddqID = (int)$ddqID;
            $ddqModel = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
            $ddqIdInvalid = (empty($ddqID) || !($ddq = $ddqModel->findById($ddqID)));
            if ($ddqIdInvalid
                || empty($name) || empty($address) || empty($nationality) || empty($position)|| empty($proposedRole)
                || empty($email) || !filter_var(html_entity_decode($email, ENT_QUOTES), FILTER_VALIDATE_EMAIL)
                || !is_bool($owner) || !is_bool($keyManager) || !is_bool($boardMember) || !is_bool($keyConsultant)
                || !in_array($embargoedResidents, ['Yes', 'No'])
                || ($owner && in_array($ownerPercentage, ['0', '0.00']))
            ) {
                // Basic validation (full-scale successful validation assumed if $partOfGroup)
                return false;
            }
        }
        $values = ['clientID' => $this->tenantID, 'ddqID' => $ddqID];
        foreach ($this->apiParamToDbColumnMapping as $key => $column) {
            $values[$column] = ${$key};
        }
        $rtn = $this->insert($values);
        return $rtn;
    }

    /**
     * Creates a ddqKeyPerson record for a given intake form
     *
     * @param integer $ddqID       ddqKeyPerson.ddqID
     * @param array   $person      Person configuration
     * @param boolean $partOfGroup If true, called from createMultiple() which vets ddqID
     *
     * @return mixed If updated succeeded, then integer else false boolean
     */
    public function createSingleFromOverride($ddqID, $person = [], $partOfGroup = false)
    {
        if (!$partOfGroup) {
            $ddqID = (int)$ddqID;
            $ddqModel = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
            $ddqIdInvalid = (empty($ddqID) || !($ddq = $ddqModel->findById($ddqID)));
            if ($ddqIdInvalid || empty($person)) {
                // Basic validation (full-scale successful validation assumed if $partOfGroup)
                return false;
            }
        }
        $overrideType = $person['overrideType'];
        unset($person['overrideType']);
        $legend = array_flip($this->overrides['legend']);
        $nameKey = $legend['name'];
        $ownerPercentageKey = $legend['ownerPercentage'];
        if (isset($person[$ownerPercentageKey])) {
            $person[$ownerPercentageKey] = $this->formatPercent($person[$ownerPercentageKey]);
        }
        $overrideFields = json_encode([$person]);
        $values = [
            'clientID' => $this->tenantID,
            'ddqID' => $ddqID,
            'kpName' => $person[$nameKey],
            'overrideLegend' => json_encode([$this->overrides['legend']]),
            'overrideType' => $overrideType,
            'overrideFields' => $overrideFields
        ];
        if (isset($person[$ownerPercentageKey])) {
            $values['bkpOwner'] = 1;
            $values['kpOwnPercent'] = $person[$ownerPercentageKey];
        }
        $rtn = $this->insert($values);
        return $rtn;
    }

    /**
     * Used for consistent formatting of percent input in both
     * backend validation, as well as displaying db-retrieved
     * values for editing.
     *
     * @param string $originalPercent Percent string to be formatted
     *
     * @return string
     */
    private function formatPercent($originalPercent = '0')
    {
        $decPlc = $this->percentDecPlc;
        $rtn = $lowNumber = bcadd('0', '0', $decPlc);
        $highNumber = bcadd('100', '0', $decPlc);
        $originalPercent = str_replace('%', '', trim($originalPercent));
        $originalPercent = ltrim($originalPercent, '+-');
        $originalPercent = str_replace(',', '.', $originalPercent);
        if (is_numeric($originalPercent) && $originalPercent != '0') {
            $originalPercent = $this->removeInsignificantDecimalPlaces($originalPercent);
            $rtn = bcadd($originalPercent, '0', $decPlc);
            if (intval($rtn) > intval($highNumber)) {
                $rtn = $highNumber;
            }
        }
        return $rtn;
    }

    /**
     * Check for overrides and format accordingly
     *
     * @param array $person Person configuration
     *
     * @return array
     */
    private function formatPerson($person)
    {
        if (!empty($this->overrides) && !empty($person['overrideFields']) && is_string($person['overrideFields'])) {
            $rtn = json_decode($person['overrideFields'], true)[0];
            $rtn['id'] = $person['id'];
            $rtn['overrideType'] = $person['overrideType'];
        } else {
            $person['personnelRolesIDs'] = [];
            if (!empty($person['owner'])) {
                $person['personnelRolesIDs'][] = self::CATEGORY_OWNER;
            }
            if (!empty($person['keyManager'])) {
                $person['personnelRolesIDs'][] = self::CATEGORY_KEY_MANAGER;
            }
            if (!empty($person['boardMember'])) {
                $person['personnelRolesIDs'][] = self::CATEGORY_BOARD_MEMBER;
            }
            if (!empty($person['keyConsultant'])) {
                $person['personnelRolesIDs'][] = self::CATEGORY_KEY_CONSULTANT;
            }
            $rtn = $person;
        }
        return $rtn;
    }

    /**
     * Return personnel categories
     * @todo Come up with a way to store client preferences for personnel categories,
     *       and revamp Lilly TPQ so that it adhere to this logic
     *
     * @return array
     */
    public function getCategories()
    {
        $rtn = [
            ["id" => self::CATEGORY_OWNER, "name" => "Owner"],
            ["id" => self::CATEGORY_KEY_MANAGER, "name" => "Key Manager"],
            ["id" => self::CATEGORY_BOARD_MEMBER, "name" => "Board Member"],
            ["id" => self::CATEGORY_KEY_CONSULTANT, "name" => "Key Consultant"]
        ];
        return $rtn;
    }

    /**
     * Given an array of ddqKeyPerson records, tally the ownerPercentage
     *
     * @param array $existing ddqKeyPerson records
     *
     * @return array
     */
    public function getOwnerPercentageTally($existing)
    {
        $rtn = ['total' => 0, 'owners' => []];
        if (!empty($existing)) {
            $nameKey = 'name';
            $ownerPercentageKey = 'ownerPercentage';
            if (!empty($this->overrides)) {
                $legend = array_flip($this->overrides['legend']);
                $nameKey = $legend['name'];
                $ownerPercentageKey = $legend['ownerPercentage'];
            }
            foreach ($existing as $person) {
                $criteria = (!empty($this->overrides))
                    ? isset($person[$ownerPercentageKey])
                    : !empty($person['owner']);
                if ($criteria) {
                    $rtn['owners'][] = [
                        'id' => $person['id'],
                        'owner' => $person[$nameKey],
                        '%' => $person[$ownerPercentageKey]
                    ];
                    $rtn['total'] += $person[$ownerPercentageKey];
                }
            }
        }
        return $rtn;
    }

    /**
     * Tally the ownerPercentage across all key people for a given ddqID
     *
     * @param integer $ddqID ddqKeyPerson.ddqID
     *
     * @return array
     */
    public function getOwnerPercentageTallyByDdqID($ddqID)
    {
        $people = (!empty($this->overrides))
            ? $this->getPeopleByDdqIDAndQuestionID($ddqID, 'keyPersons_owners')
            : $this->getPeopleByDdqIDAndQuestionID($ddqID);
        $rtn = (!empty($people))
            ? $this->getOwnerPercentageTally($people)
            : ['total' => 0, 'owners' => []];
        return $rtn;
    }

    /**
     * Returns a row of ddqKeyPerson data given the id
     *
     * @param array $inFormRspnsKeyPersonID ddqKeyPerson.id
     *
     * @return array
     */
    public function getPersonByID($inFormRspnsKeyPersonID)
    {
        $rtn = [];
        $inFormRspnsKeyPersonID = (int)$inFormRspnsKeyPersonID;
        if (!empty($inFormRspnsKeyPersonID) && ($personData = $this->selectByID($inFormRspnsKeyPersonID))) {
            $rtn = $personData;
        }
        return $rtn;
    }

    /**
     * Return key personnel on a case
     *
     * @param integer $caseID Cases ID
     *
     * @return array
     */
    public function getPeopleDataByCaseID($caseID)
    {
        $rtn = ['personnel' => [], 'percentOwnershipTally' => 0];
        $caseID = (int)$caseID;
        if (!empty($caseID)) {
            $clientDB = $this->DB->getClientDB($this->tenantID);
            $cols = "kp.id, kp.kpName AS name, kp.kpPosition AS position, kp.kpNationality AS nationality, "
                . "kp.kpAddr AS address, kp.kpIDnum AS idNumber, kp.bkpOwner AS owner, "
                . "kp.kpOwnPercent AS ownerPercentage, kp.bkpKeyMgr AS keyManager, kp.bkpBoardMem AS boardMember, "
                . "kp.bkpKeyConsult AS keyConsultant, kp.bResOfEmbargo AS embargoedResidents, "
                . "kp.proposedRole AS proposedRole";
            $sql = "SELECT {$cols} FROM {$clientDB}.ddqKeyPerson AS kp\n"
                . "LEFT JOIN {$clientDB}.ddq AS d ON d.id = kp.ddqID\n"
                . "WHERE kp.clientID = :clientID AND d.caseID = :caseID";
            $params = [':clientID' => $this->tenantID, ':caseID' => $caseID];
            if ($people = $this->DB->fetchAssocRows($sql, $params)) {
                // Tally up the percentOwnership
                $rtn['percentOwnershipTally'] = 0;
                foreach ($people as $person) {
                    $rtn['percentOwnershipTally'] += $person['ownerPercentage'];
                }
                $rtn['personnel'] = $people;
            }
        }
        return $rtn;
    }

    /**
     * Returns all rows of ddqKeyPerson data given the ddqID/clientID combo
     *
     * @param integer $ddqID        ddqKeyPerson.ddqID
     * @param boolean $uniqueNames  If true, filter records by unique names
     * @param boolean $groupByTypes If true and $uniqueNames is false, group records by types
     *
     * @return array
     */
    public function getPeopleByDdqID($ddqID, $uniqueNames = false, $groupByTypes = false)
    {
        $rtn = [];
        $ddqID = (int)$ddqID;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        if (!empty($ddqID) && !empty($this->overrides)) {
            $sql = "SELECT *, (LENGTH(kpName) != CHAR_LENGTH(kpName)) AS altScript, "
                . "((LENGTH(kpName) - LENGTH(REPLACE(kpName, ' ', ''))) = 1) AS canSplit\n"
                . "FROM {$clientDB}.ddqKeyPerson\n"
                . "WHERE clientID = :clientID AND ddqID = :ddqID";
            $params = [':clientID' => $this->tenantID, ':ddqID' => $ddqID];
            if ($people = $this->DB->fetchAssocRows($sql, $params)) {
                $namesCollected = [];
                $legend = array_flip($this->overrides['legend']);
                $nameKey = $legend['name'];
                $ownerPercentageKey = $legend['ownerPercentage'];
                if ($uniqueNames) { // Filter the names for uniqueness across different types
                    // First, collect any owners. These take precendence.
                    foreach ($people as $person) {
                        $formattedPerson = $this->formatPerson($person);
                        if (isset($formattedPerson[$ownerPercentageKey])) {
                            $namesCollected[] = $formattedPerson[$nameKey];
                            $rtn[] = $formattedPerson;
                        }
                    }
                    // Now collect the other unique names that may not be owners
                    foreach ($people as $person) {
                        $formattedPerson = $this->formatPerson($person);
                        if (!in_array($formattedPerson[$nameKey], $namesCollected)) {
                            $namesCollected[] = $formattedPerson[$nameKey];
                            $rtn[] = $formattedPerson;
                        }
                    }
                } else { // Return all people regardless of dupes across different types
                    foreach ($people as $person) {
                        $formattedPerson = $this->formatPerson($person);
                        if ($groupByTypes) {
                            $rtn[$formattedPerson['overrideType']][] = $formattedPerson;
                        } else {
                            $rtn[] = $formattedPerson;
                        }
                    }
                }
            }
        }
        return $rtn;
    }

    /**
     * Returns all rows of ddqKeyPerson data given the ddqID/clientID/questionID combo
     *
     * @param integer $ddqID      ddqKeyPerson.ddqID
     * @param mixed   $questionID questionID used to submit response for keyPersons elementsSet
     *
     * @return array
     */
    public function getPeopleByDdqIDAndQuestionID($ddqID, mixed $questionID = '')
    {
        $rtn = [];
        $ddqID = (int)$ddqID;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        if (empty($ddqID) || (!empty($this->overrides) && empty($questionID))) {
            return $rtn;
        }
        if (!empty($this->overrides)) {
            $overrideType = str_replace('keyPersons_', '', (string) $questionID);
            $sql = "SELECT id, overrideType, overrideFields FROM {$clientDB}.ddqKeyPerson\n"
                . "WHERE clientID = :clientID AND ddqID = :ddqID AND overrideType = :overrideType";
            $params = [':clientID' => $this->tenantID, ':ddqID' => $ddqID, ':overrideType' => $overrideType];
        } else {
            $sql = "SELECT id, kpName AS `name`, kpAddr AS `address`, email, `kpNationality` AS `nationality`, "
                . "kpPosition AS `position`, proposedRole, kpIDnum AS `idNumber`, bkpOwner AS `owner`, "
                . "kpOwnPercent AS `ownerPercentage`, bkpKeyMgr AS `keyManager`, bkpBoardMem AS `boardMember`, "
                . "bkpKeyConsult AS `keyConsultant`, bResOfEmbargo AS `embargoedResidents`\n"
                . "FROM {$clientDB}.ddqKeyPerson\n"
                . "WHERE clientID = :clientID AND ddqID = :ddqID ORDER BY id ASC";
            $params = [':clientID' => $this->tenantID, ':ddqID' => $ddqID];
        }
        if ($people = $this->DB->fetchAssocRows($sql, $params)) {
            foreach ($people as $person) {
                $rtn[] = $this->formatPerson($person);
            }
        }
        return $rtn;
    }

    /**
     * Returns count of all rows of ddqKeyPerson data given the ddqID/clientID combo
     *
     * @param integer $ddqID ddqKeyPerson.ddqID
     *
     * @return array
     */
    public function getPeopleCountByDdqID($ddqID)
    {
        $rtn = 0;
        $ddqID = (int)$ddqID;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        if (empty($ddqID)) {
            return $rtn;
        }
        if (!empty($this->overrides)) {
            if ($people = $this->getPeopleByDdqID($ddqID, true)) {
                $rtn = count($people);
            }
        } else {
            $sql = "SELECT COUNT(*) AS totalPeople FROM {$clientDB}.ddqKeyPerson\n"
                . "WHERE clientID = :clientID AND ddqID = :ddqID ORDER BY id ASC";
            $params = [':clientID' => $this->tenantID, ':ddqID' => $ddqID];
            $rtn = $this->DB->fetchValue($sql, $params);
        }
        return $rtn;
    }

    /**
     * Returns defaultValue and size for percent field
     *
     * @return array
     */
    public function getPercentData()
    {
        return [
            'defaultValue' => bcadd('0', '0', $this->percentDecPlc),
            'size' => (($this->percentDecPlc > 0) ? 4 + $this->percentDecPlc : 3)
        ];
    }

    /**
     * Gets key persons tabular data for a specific list type
     *
     * @param integer $ddqID   ddqKeyPerson.ddqID
     * @param string  $type    If no override, either owner, keyManager, boardMember or keyConsultant
     *                         If override, this value should match what's in the overrideType column
     * @param array   $colsMap Mapping of DB columns to data table header names
     *
     * @return array
     */
    public function getTabularData($ddqID, $type, $colsMap)
    {
        $rtn = [];
        $ddqID = (int)$ddqID;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        if (empty($ddqID)) {
            return $rtn;
        }
        if (!empty($this->overrides) && in_array($type, array_keys($this->overrides['tables']))) {
            $sql = "SELECT id, overrideType, overrideFields FROM {$clientDB}.ddqKeyPerson\n"
                . "WHERE clientID = :clientID AND ddqID = :ddqID AND overrideType = :type";
            $params = [':clientID' => $this->tenantID, ':ddqID' => $ddqID, ':type' => $type];
        } elseif (in_array($type, ['owner', 'keyManager', 'boardMember', 'keyConsultant'])) {
            if ($type == 'owner') {
                $typeCondition = 'bkpOwner = 1';
            } elseif ($type == 'keyManager') {
                $typeCondition = 'bkpKeyMgr = 1';
            } elseif ($type == 'boardMember') {
                $typeCondition = 'bkpBoardMem = 1';
            } elseif ($type == 'keyConsultant') {
                $typeCondition = 'bkpKeyConsult = 1';
            }
            $sql = "SELECT id, kpName AS `name`, kpAddr AS `address`, email, `kpNationality` AS `nationality`, "
                . "kpPosition AS `position`, proposedRole, kpIDnum AS `idNumber`, bkpOwner AS `owner`, "
                . "kpOwnPercent AS `ownerPercentage`, bkpKeyMgr AS `keyManager`, bkpBoardMem AS `boardMember`, "
                . "bkpKeyConsult AS `keyConsultant`, bResOfEmbargo AS `embargoedResidents`\n"
                . "FROM {$clientDB}.ddqKeyPerson\n"
                . "WHERE clientID = :clientID AND ddqID = :ddqID AND {$typeCondition} ORDER BY id ASC";
            $params = [':clientID' => $this->tenantID, ':ddqID' => $ddqID];
        }
        if ($peopleData = $this->DB->fetchAssocRows($sql, $params)) {
            foreach ($peopleData as $person) {
                $person = $this->formatPerson($person);
                $row = ['id' => $person['id']];
                foreach ($colsMap as $key => $headerName) {
                    $row[$headerName] = $person[$key] ?? '';
                }
                $rtn[] = $row;
            }
        }
        return $rtn;
    }

    /**
     * Determine whether or not the proposed name is a duplicate for the override type
     *
     * @param integer $ddqID        ddq.id
     * @param string  $personName   name for a key person being proposed
     * @param string  $overrideType ddqKeyPerson.overrideType
     * @param array   $existing     The ddqKeyPerson rec if person already exists in the DB
     *
     * @return boolean
     */
    private function isNameDuplicateForType($ddqID, $personName, $overrideType, $existing)
    {
        $rtn = false;
        $ddqID = (int)$ddqID;
        if (!empty($ddqID) && !empty($this->overrides)
            && ($legend = array_flip($this->overrides['legend']))
            && ($nameKey = $legend['name'])
            && (is_null($existing) || $existing[$nameKey] != $personName)
            && ($people = $this->getPeopleByDdqID($ddqID))
        ) {
            // Either this is a new person's name or else an existing person whose name changed
            foreach ($people as $person) {
                if ($person['overrideType'] == $overrideType && $person[$nameKey] == $personName) {
                    $rtn = true;
                    break;
                }
            }
        }
        return $rtn;
    }

    /**
     * Strips insignificant decimal places (i.e. trailing 0's and stray decimal points)
     *
     * @param string $number Number to remove insignificant decimals places from
     *
     * @return string
     */
    public function removeInsignificantDecimalPlaces($number)
    {
        (string)$rtn = $number;
        if (str_contains($rtn, '.')) {
            $rtn = rtrim($rtn, '0');
            $rtn = rtrim($rtn, '.');
        }
        return $rtn;
    }

    /**
     * Deletes key people for a given ddqID and clientID
     *
     * @param integer $ddqID ddqKeyPerson.ddqID
     *
     * @return boolean
     */
    public function removeByDdqID($ddqID)
    {
        $rtn = false;
        (int)$ddqID = $ddqID;
        $where = ['clientID' => $this->clientID, 'ddqID' => $ddqID];
        if (!empty($ddqID) && ($ids = $this->selectMultiple(['id'], $where))) {
            $peopleIDs = [];
            foreach ($ids as $id) {
                $peopleIDs[] = $id['id'];
            }
            $rtn = $this->removeByIDs($peopleIDs);
        }
        return $rtn;
    }

    /**
     * Deletes key people by id values
     *
     * @param array $ids ddqKeyPerson.id value array
     *
     * @return boolean
     */
    public function removeByIDs($ids)
    {
        $rtn = false;
        if (is_array($ids) && !empty($ids)) {
            $clientDB = $this->DB->getClientDB($this->clientID);
            if ($this->DB->query("DELETE FROM {$clientDB}.ddqKeyPerson WHERE id IN(" . implode(', ', $ids) . ")")) {
                $rtn = true;
            }
        }
        return $rtn;
    }

    /**
     * Deletes key person for a given intake form
     *
     * @param integer $id ddqKeyPerson.id
     *
     * @return mixed Whether or not deletion succeeded
     */
    public function removePerson($id)
    {
        $rtn = false;
        (int)$id = $id;
        if (!empty($id) && ($personData = $this->selectByID($id))) {
            $rtn = $this->deleteByID($id);
        }
        return $rtn;
    }

    /**
     * Tally up the combination of existing keyPersons and keyPersons flagged for addition/modification/deletion.
     *
     * @param integer $ddqID  ddq.id
     * @param array   $people Array of people-related changes to analyze
     *
     * @return integer
     */
    public function tallyUpPeople($ddqID, $people)
    {
        $rtn = 0;
        $ddqID = (int)$ddqID;
        if (!empty($ddqID) && !empty($people)) {
            $existing = $this->getPeopleByDdqID($ddqID, false, true);
            $namesCollection = [];
            $nameKey = 'name';
            if (!empty($this->overrides)) {
                $legend = array_flip($this->overrides['legend']);
                $nameKey = $legend['name'];
            }
            if ($existing) {
                // Tally up existing people in combo with additions/modifications/deletions
                foreach ($existing as $type => $existingPeople) {
                    if (isset($people[$type])) {
                        // Existing type flagged for changes.
                        // Tally up modifications/deletions for existing people.
                        $typePeople = $existingPeople;
                        foreach ($people[$type] as $cfgType => $cfgPeople) {
                            if (in_array($cfgType, ['toRemove', 'toUpdate'])) {
                                foreach ($existingPeople as $idx => $existingPerson) {
                                    if ($cfgType == 'toRemove') {
                                        foreach ($cfgPeople as $personToRemove) {
                                            if ($personToRemove['id'] == $existingPerson['id']) {
                                                unset($typePeople[$idx]);
                                            }
                                        }
                                    } elseif ($cfgType == 'toUpdate') {
                                        foreach ($cfgPeople as $personToUpdate) {
                                            if ($personToUpdate['id'] == $existingPerson['id']
                                                && !empty($personToUpdate[$nameKey])
                                            ) {
                                                $typePeople[$idx][$nameKey] = $personToUpdate[$nameKey];
                                            }
                                        }
                                    }
                                }
                            } elseif ($cfgType == 'toCreate') {
                                foreach ($cfgPeople as $newPerson) {
                                    $typePeople[] = $newPerson;
                                }
                            }
                        }
                        // Now, add any people whose names do not already appear in the main tally
                        foreach ($typePeople as $typePerson) {
                            if (!in_array($typePerson[$nameKey], $namesCollection)) {
                                $namesCollection[] = $typePerson[$nameKey];
                            }
                        }
                    } else {
                        // Existing type not flagged for changes.
                        // Add unflagged existing people whose names do not already appear in the main tally.
                        foreach ($existingPeople as $idx => $existingPerson) {
                            if (!in_array($existingPerson[$nameKey], $namesCollection)) {
                                $namesCollection[] = $existingPerson[$nameKey];
                            }
                        }
                    }
                }
            } else {
                // No existing people. Add all unique names marked for creation to collection.
                foreach ($people as $type => $cfg) {
                    foreach ($cfg as $cfgType => $cfgPeople) {
                        if ($cfgType == 'toCreate' && !empty($cfgPeople)) {
                            foreach ($cfgPeople as $newPerson) {
                                if (!in_array($newPerson[$nameKey], $namesCollection)) {
                                    $namesCollection[] = $newPerson[$nameKey];
                                }
                            }
                        }
                    }
                }
            }
            $rtn = count($namesCollection);
        }
        return $rtn;
    }

    /**
     * Updates ddqKeyPerson records for a given intake form
     *
     * @param integer $ddqID  ddqKeyPerson.ddqID
     * @param array   $people ddqKeyPerson configurations
     *
     * @return boolean
     */
    public function updateMultiple($ddqID, $people)
    {
        $ddqID = (int)$ddqID;
        $ddqModel = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
        $ddqIdInvalid = (empty($ddqID) || !($ddq = $ddqModel->findById($ddqID)));
        $roles = [
            'owner' => self::CATEGORY_OWNER,
            'keyManager' => self::CATEGORY_KEY_MANAGER,
            'boardMember' => self::CATEGORY_BOARD_MEMBER,
            'keyConsultant' => self::CATEGORY_KEY_CONSULTANT
        ];
        $rtn = false;
        if ($ddqIdInvalid) {
            return $rtn;
        } else {
            $ownerPercentageKey = 'ownerPercentage';
            if (!empty($this->overrides)) {
                $legend = array_flip($this->overrides['legend']);
                $ownerPercentageKey = $legend['ownerPercentage'];
                $nameKey = $legend['name'];
            }
            foreach ($people as $id => $person) {
                $values = [];
                if ($this->isAPI) {
                    if (!empty($this->overrides)) {
                        $existingPerson = $this->formatPerson($this->getPersonByID($id));
                        $overrideType = $person['overrideType'];
                        $overrideColumns = array_keys(
                            $this->overrides['tables'][$overrideType]['columns']
                        );
                        unset($person['overrideType']);
                        if (isset($person[$ownerPercentageKey])) {
                            $person[$ownerPercentageKey] = $this->formatPercent($person[$ownerPercentageKey]);
                        }
                        $newOverrideValues = [];
                        foreach ($overrideColumns as $column) {
                            if (isset($person[$column])) {
                                $newOverrideValues[$column] = $person[$column];
                            } elseif (isset($existingPerson[$column])) {
                                $newOverrideValues[$column] = $existingPerson[$column];
                            }
                        }
                        $values = ['overrideFields' => json_encode([$newOverrideValues])];
                        if ($person[$nameKey] != $existingPerson[$nameKey]) {
                            $values['kpName'] = $person[$nameKey];
                        }
                        if (isset($person[$ownerPercentageKey])
                            && $person[$ownerPercentageKey] != $existingPerson[$ownerPercentageKey]
                        ) {
                            $values['bkpOwner'] = 1;
                            $values['kpOwnPercent'] = $person[$ownerPercentageKey];
                        }
                    } else {
                        $id = 0;
                        foreach ($person as $key => $value) {
                            if ($key == 'id') {
                                $id = $value;
                                continue;
                            } elseif ($key == 'personnelRolesIDs') {
                                foreach ($roles as $roleKey => $roleValue) {
                                    $hasRole = (in_array($roleValue, $value));
                                    $values[$this->apiParamToDbColumnMapping[$roleKey]] = $hasRole;
                                }
                            } else {
                                $values[$this->apiParamToDbColumnMapping[$key]] = $value;
                            }
                        }
                    }
                }
                if ($updated = $this->updateByID($id, $values)) {
                    $rtn = true;
                }
            }
        }
        return $rtn;
    }

    /**
     * Validate key people data (new and existing wholistically)
     * @todo Procedural hell. Break into modular parts.
     *
     * @param integer $ddqID          ddq.id
     * @param mixed   $questionID     questionID used to submit response for keyPersons elementsSet
     * @param array   $existing       Existing ddqKeyPerson data
     * @param array   $new            New data to impact ddqKeyPerson
     * @param boolean $postProcessing If true, this will only validate DB values
     *
     * @return array
     */
    public function validatePeople($ddqID, mixed $questionID, $existing, $new, $postProcessing)
    {
        $rtn = ['errorType' => '', 'data' => []];
        $errors = $peopleToCreate = $peopleToUpdate = $peopleToRemove = [];
        $hasOwnerPercentage = true;
        $ownerPercentageKey = 'ownerPercentage';
        if (!empty($this->overrides)) {
            $overrideType = str_replace('keyPersons_', '', (string) $questionID);
            $legend = array_flip($this->overrides['legend']);
            $ownerPercentageKey = $legend['ownerPercentage'];
            $hasOwnerPercentage = (isset($this->overrides['tables'][$overrideType]['columns'][$ownerPercentageKey]));
        }
        $numberOfValidPeople = $numberOfInvalidPeople = 0;
        $ownerPercentageTally = (!empty($this->overrides))
            ? $this->overrides[$ownerPercentageKey]
            : $this->getOwnerPercentageTally($existing);
        if (!empty($new)) {
            foreach ($new as $idx => $keyPerson) {
                $existingPerson = null;
                $toBeUpdated = (
                    !empty($keyPerson['id'])
                    && (!isset($keyPerson['remove']) || $keyPerson['remove'] !== true)
                );
                $toBeRemoved = (
                    !empty($keyPerson['id']) && isset($keyPerson['remove']) && $keyPerson['remove'] === true
                );
                if ($toBeUpdated || $toBeRemoved) {
                    foreach ($existing as $person) {
                        if ($person['id'] == $keyPerson['id']) {
                            $existingPerson = $person;
                            break;
                        }
                    }
                }
                $validation = $this->validatePerson(
                    $ddqID,
                    $existingPerson,
                    $ownerPercentageTally,
                    $keyPerson,
                    $toBeUpdated,
                    $toBeRemoved,
                    $postProcessing
                );
                $keyPersonNumber = (!is_null($existingPerson)) ? $keyPerson['id'] : 'new person #' . ($idx + 1);
                if (empty($validation['invalid']) && empty($validation['missing']) && empty($validation['error(s)'])) {
                    $ownerPercentageTally = $validation['ownerPercentageTally'];
                    $numberOfValidPeople++;
                    if ($toBeUpdated && !empty($validation['valid'])) {
                        $peopleToUpdate[] = array_merge(['id' => $keyPerson['id']], $validation['valid']);
                    } elseif ($toBeRemoved) {
                        $peopleToRemove[] = $keyPerson;
                    } else {
                        $peopleToCreate[] = $keyPerson;
                    }
                } else {
                    $numberOfInvalidPeople++;
                    unset($validation['ownerPercentageTally']);
                    $errors[$keyPersonNumber] = $validation;
                }
            }
        } elseif ($postProcessing) {
            foreach ($existing as $idx => $person) {
                $validation = $this->validatePerson($ddqID, $person, $ownerPercentageTally, [], false, false, true);
                if (empty($validation['invalid']) && empty($validation['missing']) && empty($validation['error(s)'])) {
                    $ownerPercentageTally = $this->overrides[$ownerPercentageKey];
                    $numberOfValidPeople++;
                } else {
                    $numberOfInvalidPeople++;
                    unset($validation['ownerPercentageTally']);
                    $errors[$person['id']] = $validation;
                }
            }
        }
        if ($hasOwnerPercentage) {
            $rtn['data'][$questionID]['totalPeopleInRequest'] = ($postProcessing) ? count($existing) : count($new);
            $rtn['data'][$questionID]['validPeopleInRequest'] = $numberOfValidPeople;
            $rtn['data'][$questionID]['invalidPeopleInRequest'] = $numberOfInvalidPeople;
            $rtn['data'][$questionID]['ownerPercentageTally'] = $ownerPercentageTally;
            if ($ownerPercentageTally['total'] > 100) {
                $peopleToUpdate = []; // Permit no updates if the ownerPercentage exceeds 100%
                $inExcessOf = $ownerPercentageTally['total'] - 100;
                $rtn['data'][$questionID]['invalidOwnerPercentageTally'] = 'The ownerPercentage '
                    . "tally exceeds the maximum percentage of 100 in excess of {$inExcessOf}%.";
                $rtn['errorType'] = 'questionsWithInvalidElementsSet';
            }
        }
        $rtn['data'][$questionID]['peopleTally'] = [
            'toRemove' => $peopleToRemove,
            'toCreate' => $peopleToCreate,
            'toUpdate' => $peopleToUpdate
        ];
        if (!empty($errors)) {
            $rtn['data'][$questionID]['invalidPeople'] = $errors;
            $rtn['errorType'] = 'questionsWithInvalidElementsSet';
        }
        if (!$postProcessing && !empty($peopleToUpdate)) {
            $rtn['dataToUpdate'] = json_encode($peopleToUpdate);
        }
        return $rtn;
    }

    /**
     * Validate key person data
     * @todo Procedural hell. Break into modular parts.
     *
     * @param integer $ddqID                ddq.id
     * @param array   $existing             The ddqKeyPerson rec if person already exists in the DB
     * @param array   $ownerPercentageTally Includes total integer and owners array
     * @param array   $new                  New data to impact ddqKeyPerson
     * @param boolean $toBeUpdated          If true, this is an existing person being updated
     * @param boolean $toBeRemoved          If true, this is an existing person being deleted
     * @param boolean $postProcessing       If true, this will only validate DB values
     *
     * @return array
     */
    public function validatePerson(
        $ddqID,
        $existing,
        $ownerPercentageTally,
        $new = [],
        $toBeUpdated = false,
        $toBeRemoved = false,
        $postProcessing = false
    ) {
        $rtn = [
            'valid' => [],
            'missing' => [],
            'invalid' => [],
            'error(s)' => '',
            'ownerPercentageTally' => $ownerPercentageTally
        ];
        $nameKey = 'name';
        $ownerPercentageKey = 'ownerPercentage';
        $resetToDefault = [];
        if (!empty($this->overrides)) {
            $legend = array_flip($this->overrides['legend']);
            $nameKey = $legend['name'];
            $ownerPercentageKey = $legend['ownerPercentage'];
            if (!empty($existing) && ($toBeUpdated || $toBeRemoved || $postProcessing)) {
                $overrideType = $existing['overrideType'];
                unset($existing['overrideType']);
                $personName = (!empty($existing[$nameKey])) ? $existing[$nameKey] : '(Unnamed)';
            }
            if (!empty($new)) {
                $overrideType = $new['overrideType'];
                unset($new['overrideType']);
                $personName = (!empty($new[$nameKey])) ? $new[$nameKey] : '(Unnamed)';
            }
            if (!$toBeRemoved && $personName != '(Unnamed)'
                && $this->isNameDuplicateForType($ddqID, $personName, $overrideType, $existing)
            ) {
                $rtn['invalid'][] = $nameKey;
                $rtn['error(s)'] = "\"{$nameKey}\" is invalid, as its value \"{$personName}\" "
                    . "already exists for the {$overrideType} elementsSet";
            }
            $validationRules = $this->validationRules[$overrideType];
        } else {
            $validationRules = $this->validationRules;
            if (!empty($existing) && ($toBeUpdated || $toBeRemoved || $postProcessing)) {
                $personName = (!empty($existing[$nameKey])) ? $existing[$nameKey] : '(Unnamed)';
            }
            if (!empty($new)) {
                $personName = (!empty($new[$nameKey])) ? $new[$nameKey] : '(Unnamed)';
            }
        }
        foreach ($validationRules as $key => $rule) {
            if ($toBeRemoved && $key !== 'id') {
                continue;
            }
            if ($key == $nameKey && in_array($nameKey, $rtn['invalid'])) {
                continue;
            }
            $paramIsSet = (!empty($new) && isset($new[$key]));
            $inDB = (!is_null($existing) && isset($existing[$key]) && $existing[$key] !== '');
            $contingencyMet = true;
            if ($rule['required'] == 'contingent' && !empty($rule['contingency']) && is_array($rule['contingency'])
                && ($paramIsSet || $inDB)
            ) {
                $contingencyField = array_keys($rule['contingency'])[0];
                $expectedContingencyValue = (is_bool($rule['contingency'][$contingencyField]))
                    ? (($rule['contingency'][$contingencyField]) ? 'true' : 'false')
                    : $rule['contingency'][$contingencyField];
                $currentContingencyValue = '';
                if (isset($new[$contingencyField])) {
                    $currentContingencyValue = (is_bool($new[$contingencyField]))
                        ? (($new[$contingencyField]) ? 'true' : 'false')
                        : $new[$contingencyField];
                    if ($inDB && !$paramIsSet && isset($rule['default'])
                        && !in_array($expectedContingencyValue, $currentContingencyValue)
                    ) {
                        // No new param value set, contingency changing via param to longer be met:
                        // reset value to default
                        $resetToDefault[$key] = $rule['default'];
                    }
                } elseif (isset($existing[$contingencyField])) {
                    $currentContingencyValue = (is_bool($existing[$contingencyField]))
                        ? (($existing[$contingencyField]) ? 'true' : 'false')
                        : $existing[$contingencyField];
                }
                if (is_array($currentContingencyValue)) {
                    $contingencyMet = (in_array($expectedContingencyValue, $currentContingencyValue));
                } else {
                    $contingencyMet = ($currentContingencyValue === $expectedContingencyValue);
                }
            }
            if ($rule['required'] === true && (!$paramIsSet && !$inDB)
                || ($paramIsSet && empty($new[$key])) || ($inDB && empty($existing[$key]))
            ) {
                $rtn['missing'][] = $key;
            } elseif ($contingencyMet === false) {
                if (isset($resetToDefault[$key])) {
                    $rtn['missing'][] = $key;
                } else {
                    $rtn['invalid'][] = $key;
                    $errorMsgVerb = 'being set to';
                    $currentContVal = "\"{$currentContingencyValue}\"";
                    if (is_array($currentContingencyValue)) {
                        $errorMsgVerb = 'containing';
                        $currentContVal = 'the following values: "' . implode('", "', $currentContingencyValue) . '"';
                    }
                    $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' "' : '"')
                        . "{$key}\" is invalid: it was contingent upon "
                        . "\"{$contingencyField}\" {$errorMsgVerb} \"{$expectedContingencyValue}\" "
                        . "(it is currently "
                        . ((empty($currentContingencyValue)) ? 'missing' : "set to {$currentContVal}") . ').';
                }
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'format'
                && isset($rule['format']) && $rule['format'] == 'email'
                && (($paramIsSet && !filter_var(html_entity_decode((string) $new[$key], ENT_QUOTES), FILTER_VALIDATE_EMAIL))
                || ((!$paramIsSet || $postProcessing) && $inDB && !filter_var(html_entity_decode((string) $existing[$key], ENT_QUOTES), FILTER_VALIDATE_EMAIL)))
            ) {
                // Invalid email format
                $rtn['invalid'][] = $key;
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' "' : '"')
                    . (($paramIsSet) ? $new[$key] : $existing[$key])
                    . "\" is an invalidly formatted email for the {$key} parameter.";
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'value' && isset($rule['value'])
                && $rule['value'] == 'primaryID' && $paramIsSet && is_null($existing)
            ) {
                // Invalid value supplied to primaryID param (not used by post-processing)
                $rtn['invalid'][] = $key;
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' ' : '')
                    . $new[$key]
                    . ' is an invalid primaryID.';
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'value' && isset($rule['values'])
                && (($paramIsSet && !is_array($new[$key]) && !in_array($new[$key], $rule['values'], true))
                || ((!$paramIsSet || $postProcessing) && $inDB && !is_array($existing[$key])
                && !in_array($existing[$key], $rule['values'], true)))
            ) {
                // Invalid non-array value supplied to param limited to subset of values
                $rtn['invalid'][] = $key;
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' "' : '"')
                    . (($paramIsSet) ? $new[$key] : $existing[$key])
                    . "\" is an invalid {$key} value.";
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'value' && isset($rule['values'])
                && (($paramIsSet && is_array($new[$key]) && ($diffNew = array_diff($new[$key], $rule['values']))
                && !empty($diffNew))
                || ((!$paramIsSet || $postProcessing) && $inDB && is_array($existing[$key])
                && ($diffExisting = array_diff($existing[$key], $rule['values']))
                && !empty($diffExisting)))
            ) {
                // Invalid array values supplied to param limited to subset of values
                if ($paramIsSet) {
                    if (empty($new[$key])) {
                        $rtn['missing'][] = $key;
                    } elseif (!empty($diffNew)) {
                        $rtn['invalid'][] = $key;
                        $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' "' : '"')
                            . "{$key}\" contains the following invalid values: \"" . implode('", "', $diffNew) . "\". "
                            . "Only these values will be accepted: \"" . implode('", "', $rule['values']) . "\". ";
                    }
                } else {
                    if (empty($existing[$key])) {
                        $rtn['missing'][] = $key;
                    } elseif (!empty($diffExisting)) {
                        $rtn['invalid'][] = $key;
                        $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' "' : '"')
                            . "{$key}\" contains the following invalid values: \"" . implode('", "', $diffExisting) . "\". "
                            . "Only these values will be accepted: \"" . implode('", "', $rule['values']) . "\". ";
                    }
                }
            } elseif (isset($rule['validateBy']) && ($rule['validateBy'] === 'numberRange')
                && isset($rule['minimum']) && isset($rule['maximum'])
                && ($minimum = $rule['minimum']) && ($maximum = $rule['maximum'])
                && (($paramIsSet && (floatval($new[$key]) < $minimum || floatval($new[$key]) > $maximum))
                || ((!$paramIsSet || $postProcessing) && $inDB
                && (floatval($existing[$key]) < $minimum || floatval($existing[$key]) > $maximum)))
            ) {
                // Value does not fall within configured number range
                $number = ($paramIsSet) ? floatval($new[$key]) : floatval($existing[$key]);
                $rtn['invalid'][] = $key;
                $msg = ($number < $minimum)
                    ? "\"{$number}\" is less than the minimum {$key} value of \"{$minimum}\" required."
                    : "\"{$number}\" exceeds the maximum {$key} value of \"{$maximum}\" required.";
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' ' : '') . $msg;
            } elseif ($paramIsSet && !in_array($key, ['id', $ownerPercentageKey])) { // Parameter is valid to be saved
                $rtn['valid'][$key] = $new[$key];
            }
            if ($key == $ownerPercentageKey
                && (isset($new[$key]) || isset($resetToDefault[$key])
                || (isset($existing[$key]) && !empty(floatval($existing[$key]))))
                && !in_array($key, $rtn['invalid'])
            ) {
                // Valid!
                if ($paramIsSet) {
                    $rtn['valid'][$key] = $new[$key];
                } elseif (isset($resetToDefault[$key])) {
                    $rtn['valid'][$key] = $resetToDefault[$key];
                }
                // Continue tallying up the ownerPercentage.
                $ownerPercentageTotal = $ownerPercentageTally['total'];
                $owners = $ownerPercentageTally['owners'];
                $personID = 0;
                if ($toBeUpdated) {
                    // Remove old ownerPercentage value from tally
                    $ownerPercentageTotal -= $existing[$ownerPercentageKey];
                    $personID = $existing['id'];
                }
                // Tack on the new ownerPercentage value to the tally
                $ownerPercentage = $rtn['valid'][$key] ?? $existing[$key];
                $ownerPercentageTotal += $ownerPercentage;
                if (!empty($personID)) {
                    foreach ($owners as $idx => $owner) {
                        $rtn['ownerPercentageTally']['owners'][$idx]['%'] = ($owner['id'] == $personID)
                            ? $ownerPercentage
                            : $owner['%'];
                    }
                } else {
                    $newOwner = ['id' => '[REQUESTED]', 'owner' => $personName, '%' => "$ownerPercentage"];
                    if (!empty($this->overrides)) {
                        $newOwner['category'] = $overrideType;
                    }
                    $rtn['ownerPercentageTally']['owners'][] = $newOwner;
                }
                $rtn['ownerPercentageTally']['total'] = $ownerPercentageTotal;
            }
        }
        if (!empty($rtn['missing'])) {
            $rtn['error(s)'] = 'Required values are missing for the following parameters: '
                . implode(', ', $rtn['missing']) . '.'
                . ((!empty($rtn['error(s)'])) ? ' ' . $rtn['error(s)'] : '');
        }
        if (!empty($new)) {
            $invalidKeys = [];
            foreach ($new as $key => $value) {
                if (!isset($validationRules[$key]) && $key !== 'remove') {
                    $invalidKeys[] = $key;
                }
            }
            if (!empty($invalidKeys)) {
                $rtn['invalid'][] = 'invalid keys';
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' ' : '')
                    . 'The following parameters were invalid: ' . implode(', ', $invalidKeys) . '.';
            }
        }
        return $rtn;
    }

    /**
     * Validate key personnel on a case
     *
     * @param integer $caseID    Cases ID
     * @param array   $personnel Key personnel to validate
     *
     * @return array
     */
    public function validateCasePersonnel(int $caseID, array $personnel):array
    {
        $invalid = $valid = [];
        $personnelData = (!is_null($caseID)) ? $this->getPeopleDataByCaseID($caseID) : [];
        $existingCount = count($personnelData['personnel']);
        $percentOwnershipTally = $personnelData['percentOwnershipTally'] ?? 0;
        $prospectiveCount = count($personnel);
        $combinedCount = $existingCount + $prospectiveCount;
        if ($combinedCount > SubjectInfoDD::SUBINFO_PRINCIPAL_LIMIT) {
            $reduceBy = $combinedCount - SubjectInfoDD::SUBINFO_PRINCIPAL_LIMIT;
            $countMsg = SubjectInfoDD::SUBINFO_PRINCIPAL_LIMIT . " total personnel are allowed per case.";
            if (!is_null($caseID)) {
                $countMsg .= " {$existingCount} personnel already exist for caseID #{$caseID}, and";
            }
            $countMsg .= " {$prospectiveCount} new personnel have been submitted. ";
            if (empty($existingCount) || ($existingCount < SubjectInfoDD::SUBINFO_PRINCIPAL_LIMIT)) {
                $countMsg .= "Reduce at least {$reduceBy} personnel from your request.";
            } else {
                $countMsg .= "No more personnel can be created for this case.";
            }
            $invalid['Case Folder Personnel count'] = $countMsg;
        } else {
            $personnelErrors = [];
            $validParams = array_keys($this->apiParamToDbColumnMapping);
            foreach ($personnel as $idx => $person) {
                $personNumber = $idx + 1;
                $errorLabel = "New person #{$personNumber}";
                $invalidParams = [];
                if (empty($person['name'])) {
                    $personnelErrors[$errorLabel][] = 'Missing name.';
                }
                if (!empty($person['email']) && !(new EmailAddress($person['email'], 'email'))->isValid()) {
                    $personnelErrors[$errorLabel][] = 'Email invalidly formatted.';
                }
                if (empty($person['owner']) && empty($person['keyManager']) && empty($person['boardMember']) && empty($person['keyConsultant'])) {
                    $personnelErrors[$errorLabel][] = 'Not assigned a role.';
                }
                if (!empty($person['ownerPercentage']) && empty($person['owner'])) {
                    $personnelErrors[$errorLabel][] = 'Non-owner supplied percentage ownership.';
                } elseif (!empty($person['owner']) && empty($person['ownerPercentage'])) {
                    $personnelErrors[$errorLabel][] = 'Owner not supplied percentage ownership.';
                } elseif (!empty($person['owner']) && !empty($person['ownerPercentage'])) {
                    if (!is_numeric($person['ownerPercentage'])) {
                        $personnelErrors[$errorLabel][] = 'Misformatted percentage ownership supplied.';
                    } else {
                        $percentOwnershipTally += $person['ownerPercentage'];
                    }
                }
                foreach ($person as $key => $value) {
                    if (!in_array($key, $validParams)) {
                        $invalidParams[] = $key;
                    }
                }
                if (!empty($invalidParams)) {
                    $personnelErrors[$errorLabel][] = "The following params were invalid: "
                        . implode(', ', $invalidParams);
                }
            }
            if ($percentOwnershipTally > 100) {
                $reducePercentageBy = $percentOwnershipTally - 100;
                $personnelErrors["Ownership Percentage Tally"] = "The total ownership percentage is "
                    . "{$percentOwnershipTally}, which exceeds 100%. Reduce at least {$reducePercentageBy} "
                    . "percentage points for the newly supplied owners.";
            }
            if (!empty($personnelErrors)) {
                $invalid['Some personnel were invalidly formatted'] = $personnelErrors;
            }
        }
        if (empty($invalid)) {
            $valid = $personnel;
        }
        return [$invalid, $valid];
    }

    /**
     * Add personnel to an existing record by caseID
     *
     * @param integer $caseID    Case ID
     * @param array   $personnel Personnel to add to the record
     *
     * @return mixed Array if successful else false boolean
     */
    public function createPersonnelByCaseID($caseID, $personnel)
    {
        $caseID = (int)$caseID;
        $rtn = false;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        $sql = "SELECT id FROM {$clientDB}.ddq WHERE caseID = :caseID AND clientID = :clientID";
        if (!empty($caseID) && !empty($personnel)
            && ($ddqID = $this->DB->fetchValue($sql, [':caseID' => $caseID, ':clientID' => $this->clientID]))
        ) {
            $columns = array_values($this->apiParamToDbColumnMapping);
            $sql = "INSERT INTO {$clientDB}.ddqKeyPerson (ddqID, clientID, " . implode(', ', $columns) . ") VALUES";
            foreach ($personnel as $idx => $person) {
                $sql .= ((!empty($idx)) ? ', ' : '') . "(:ddqID{$idx}, :clientID{$idx}";
                $params[":ddqID{$idx}"] = $ddqID;
                $params[":clientID{$idx}"] = $this->tenantID;
                foreach ($columns as $column) {
                    $placeholder = ":{$column}{$idx}";
                    $sql .= ", {$placeholder}";
                    $params["{$placeholder}"] = $person[$column];
                }
                $sql .= ")";
            }
            $this->DB->query($sql, $params);
            // Now, return the id/name pairs of the new personnel added
            $sql = "SELECT id, kpName AS name FROM {$clientDB}.ddqKeyPerson WHERE ddqID = :ddqID\n"
                . "ORDER BY id DESC LIMIT " . count($personnel);
            if ($newPersonnel = $this->DB->fetchAssocRows($sql, [':ddqID' => $ddqID])) {
                $rtn = array_reverse($newPersonnel);
            }
        }
        return $rtn;
    }

    /**
     * Convert API input for personnel into key/value rows of ddqKeyPerson columns/values
     *
     * @param array $personnel Array of personnel to convert
     *
     * @return array
     */
    public function packUpPersonnel($personnel)
    {
        $rtn = [];
        foreach ($personnel as $idx => $person) {
            foreach ($this->apiParamToDbColumnMapping as $key => $dbColumn) {
                if (isset($person[$key])) {
                    $rtn[$idx][$dbColumn] = $person[$key];
                } elseif (isset($this->columnDefaults[$dbColumn])) {
                    $rtn[$idx][$dbColumn] = $this->columnDefaults[$dbColumn];
                }
            }
        }
        return $rtn;
    }
}
