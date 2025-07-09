<?php
/**
 * Provides basic read/write access to inFormRspnsCompanies table
 */

namespace Models\TPM\IntakeForms\Response;

use Models\BaseLite\RequireClientID;
use Models\Ddq;
use Models\Globals\Geography;
use Models\TPM\IntakeForms\Legacy\OnlineQuestions;

/**
 * Basic CRUD access to inFormRspnsCompanies,  requiring tenantID
 *
 * @keywords tenants, settings, tenants settings
 */
#[\AllowDynamicProperties]
class InFormRspnsCompanies extends RequireClientID
{
    /**
     * @var string Table name
     */
    protected $tbl = 'inFormRspnsCompanies';

    /**
     * @var string Column name
     */
    protected $clientIdField = 'tenantID';

    /**
     * @var string Column name
     */
    protected $primaryID = 'id';

    /**
     * @var int 3PM tenant ID
     */
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
        'name' => 'name',
        'relationship' => 'relationship',
        'address' => 'address',
        'registrationNumber' => 'regNum',
        'registrationCountry' => 'regCountry',
        'contactName' => 'contactName',
        'phone' => 'phone',
        'percentOwnership' => 'percentOwnership',
        'additionalInformation' => 'additionalInfo',
        'stateOwned' => 'stateOwned'
    ];

    /**
     * Mapping of validation rules
     *
     * @var array
     */
    private $validationRules = [
        'id' => ['required' => false, 'validateBy' => 'value', 'value' => 'primaryID'],
        'name' => ['required' => true],
        'relationship' => ['required' => true],
        'address' => ['required' => true],
        'registrationNumber' => ['required' => false],
        'registrationCountry' => ['required' => false, 'validateBy' => 'value', 'values' => []],
        'contactName' => ['required' => false],
        'phone' => ['required' => false],
        'percentOwnership' => [
            'required' => false,
            'validateBy' => 'numberRange',
            'minimum' => 1,
            'maximum' => 100
        ],
        'additionalInformation' => ['required' => false],
        'stateOwned' => ['required' => false]
    ];

    /**
     * @var object OnlineQuestions instance
     */
    private $OQ = null;

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID inFormRspnsCompanies.tenantID
     * @param array   $params   Optional params to pass in
     *
     * @return void
     */
    public function __construct($tenantID, $params = [])
    {
        \Xtra::requireInt($tenantID);
        $this->tenantID = $tenantID;
        $this->logger = \Xtra::app()->log;
        parent::__construct($tenantID);
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : \Xtra::app()->session->get('authUserID');
        $this->OQ = new OnlineQuestions($this->tenantID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
        $langCode = $params['languageCode'] ?? 'EN_US';
        $countries = (Geography::getVersionInstance(null, $this->tenantID))->countryList('', $langCode);
        $this->validationRules['registrationCountry']['values'] = array_keys($countries);
    }

    /**
     * Creates inFormRspnsCompanies records for a given intake form
     *
     * @param integer $inFormRspnsID inFormRspnsCompanies.inFormRspnsID
     * @param array   $companies     inFormRspnsCompanies configurations
     *
     * @return boolean
     */
    public function createMultiple($inFormRspnsID, $companies)
    {
        $inFormRspnsID = (int)$inFormRspnsID;
        $ddqModel = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
        $inFormRspnsIdInvalid = (empty($inFormRspnsID) || !($ddq = $ddqModel->findById($inFormRspnsID)));
        $rtn = false;
        if ($inFormRspnsIdInvalid) {
            return $rtn;
        } else {
            foreach ($companies as $company) {
                $rtn = [];
                $registrationCountry = $registrationNumber = $contactName = '';
                $phone = $additionalInformation = $stateOwned = '';
                $percentOwnership = 0;
                foreach ($company as $key => $value) {
                    ${$key} = $value;
                }
                $created = $this->createSingle(
                    $inFormRspnsID,
                    $name,
                    $relationship,
                    $address,
                    $registrationCountry,
                    $registrationNumber,
                    $contactName,
                    $phone,
                    $percentOwnership,
                    $additionalInformation,
                    $stateOwned,
                    true
                );
                if ($created) {
                    $rtn = true;
                }
            }
        }
        return $rtn;
    }

    /**
     * Creates a company for a given intake form
     *
     * @param integer $inFormRspnsID         inFormRspnsCompanies.inFormRspnsID
     * @param string  $name                  inFormRspnsCompanies.name
     * @param string  $relationship          inFormRspnsCompanies.relationship
     * @param string  $address               inFormRspnsCompanies.address
     * @param string  $registrationCountry   inFormRspnsCompanies.regCountry
     * @param string  $registrationNumber    inFormRspnsCompanies.regNum
     * @param string  $contactName           inFormRspnsCompanies.contactName
     * @param string  $phone                 inFormRspnsCompanies.phone
     * @param string  $percentOwnership      inFormRspnsCompanies.percentOwnership
     * @param string  $additionalInformation inFormRspnsCompanies.additionalInfo
     * @param string  $stateOwned            inFormRspnsCompanies.stateOwned
     * @param boolean $partOfGroup           If true, called from createMultiple() which vets ddqID
     *
     * @return mixed If updated succeeded, then integer else false boolean
     */
    public function createSingle(
        $inFormRspnsID,
        $name,
        $relationship,
        $address,
        $registrationCountry = '',
        $registrationNumber = '',
        $contactName = '',
        $phone = '',
        $percentOwnership = 0.000,
        $additionalInformation = '',
        $stateOwned = '',
        $partOfGroup = false
    ) {
        $inFormRspnsIdInvalid = false;
        if (!$partOfGroup && $this->isAPI) {
            $inFormRspnsID = (int)$inFormRspnsID;
            $ddqModel = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
            $inFormRspnsIdInvalid = (empty($inFormRspnsID) || !($ddq = $ddqModel->findById($inFormRspnsID)));
        }
        // Full-scale validation: all conditions must be met or we simply return false.
        if ($this->isAPI && ($inFormRspnsIdInvalid || empty($name) || empty($relationship) || empty($address))) {
            return false;
        }
        $percentOwnership = $this->formatPercent($percentOwnership);
        $values = ['tenantID' => $this->tenantID, 'inFormRspnsID' => $inFormRspnsID, 'isLegacy' => 1];
        foreach ($this->apiParamToDbColumnMapping as $param => $column) {
            $values[$column] = ${$param};
        }
        return $this->insert($values);
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
     * Returns all rows of inFormRspnsCompanies data given the inFormRspnsID/tenantID combo
     *
     * @param integer $inFormRspnsID inFormRspnsCompanies.inFormRspnsID
     *
     * @return array
     */
    public function getCompaniesByInFormRspnsID($inFormRspnsID)
    {
        $rtn = [];
        $inFormRspnsID = (int)$inFormRspnsID;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        $sql = "SELECT id, `name`, `relationship`, `address`, regCountry AS `registrationCountry`, "
            . "regNum AS `registrationNumber`, `contactName`, phone, percentOwnership\n"
            . "FROM {$clientDB}.inFormRspnsCompanies\n"
            . "WHERE tenantID = :tenantID AND inFormRspnsID = :inFormRspnsID ORDER BY id ASC";
        $params = [':tenantID' => $this->tenantID, ':inFormRspnsID' => $inFormRspnsID];
        if (!empty($inFormRspnsID) && ($companiesData = $this->DB->fetchAssocRows($sql, $params))) {
            $rtn = $companiesData;
        }
        return $rtn;
    }

    /**
     * Returns a row of inFormRspnsCompanies data given the id
     *
     * @param array $inFormRspnsCompanyID inFormRspnsCompanies.id
     *
     * @return array
     */
    public function getCompanyDataByID($inFormRspnsCompanyID)
    {
        $rtn = [];
        $inFormRspnsCompanyID = (int)$inFormRspnsCompanyID;
        if (!empty($inFormRspnsCompanyID) && ($companyData = $this->selectByID($inFormRspnsCompanyID))) {
            $rtn = $companyData;
        }
        return $rtn;
    }

    /**
     * Returns key-val pairs of questionID's-labelText for company-specific questionID's
     *
     * @param array $onlineQuestions onlineQuestion records from the session
     *
     * @return void
     */
    public function getLabels($onlineQuestions)
    {
        $questionIDs = [
            'TEXT_ADDR_ADDCOMP',
            'TEXT_COMPNAME_ADDCOMP',
            'TEXT_CONTACT_ADDCOMP',
            'TEXT_COFREG_ADDCOMP',
            'TEXT_NAME_ADDCOMP',
            'TEXT_OWNERSHIP_ADDCOMP',
            'TEXT_PERCENT_ADDCOMP',
            'TEXT_REGNUM_ADDCOMP',
            'TEXT_RELATIONSHIP_ADDCOMP',
            'TEXT_PHONE_ADDCOMP',
            'TEXT_ADDINFO_ADDCOMP',
            'TEXT_STATEOWNED_ADDCOMP',
        ];
        return $this->OQ->extractLabelTextFromQuestionIDs($onlineQuestions, $questionIDs);
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
     * Gets companies tabular data
     *
     * @param integer $responseID inFormRspnsCompanies.inFormRspnsID
     * @param array   $colsMap    Mapping of DB columns to data table header names
     *
     * @return array
     */
    public function getTabularData($responseID, $colsMap)
    {
        $rtn = [];
        $responseID = (int)$responseID;
        $clientDB = $this->DB->getClientDB($this->tenantID);
        $sql = "SELECT id, `name`, `relationship`, `address`, regCountry AS `registrationCountry`, "
            . "regNum AS `registrationNumber`, `contactName`, phone, percentOwnership\n"
            . "FROM {$clientDB}.inFormRspnsCompanies\n"
            . "WHERE tenantID = :tenantID AND inFormRspnsID = :inFormRspnsID ORDER BY id ASC";
        $params = [':tenantID' => $this->tenantID, ':inFormRspnsID' => $responseID];
        if (!empty($responseID) && ($companiesData = $this->DB->fetchAssocRows($sql, $params))) {
            foreach ($companiesData as $companyData) {
                $row = ['id' => $companyData['id']];
                foreach ($colsMap as $key => $headerName) {
                    $row[$headerName] = $companyData[$key];
                }
                $rtn[] = $row;
            }
        }
        return $rtn;
    }

    /**
     * Deletes company for a given intake form
     *
     * @param integer $id inFormRspnsCompanies.id
     *
     * @return mixed If updated succeeded, then integer else false boolean
     */
    public function removeCompany($id)
    {
        \Xtra::requireInt($id);
        return $this->deleteByID($id);
    }

    /**
     * Deletes companies for a given inFormRspnsID (AKA ddqID) and tenantID
     *
     * @param integer $inFormRspnsID inFormRspnsCompanies.inFormRspnsID (AKA ddqID)
     *
     * @return boolean
     */
    public function removeByDdqID($inFormRspnsID)
    {
        $rtn = false;
        (int)$inFormRspnsID = $inFormRspnsID;
        $where = ['tenantID' => $this->tenantID, 'inFormRspnsID' => $inFormRspnsID];
        if (!empty($inFormRspnsID) && ($ids = $this->selectMultiple(['id'], $where))) {
            $companyIDs = [];
            foreach ($ids as $id) {
                $companyIDs[] = $id['id'];
            }
            $rtn = $this->removeByIDs($companyIDs);
        }
        return $rtn;
    }

    /**
     * Deletes companies by id values
     *
     * @param array $ids inFormRspnsCompanies.id value array
     *
     * @return boolean
     */
    public function removeByIDs($ids)
    {
        $rtn = false;
        if (is_array($ids) && !empty($ids)) {
            $clientDB = $this->DB->getClientDB($this->tenantID);
            $sql = "DELETE FROM {$clientDB}.inFormRspnsCompanies WHERE id IN(" . implode(', ', $ids) . ")";
            if ($this->DB->query($sql)) {
                $rtn = true;
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
     * Updates company for a given intake form
     *
     * @param integer $id                    inFormRspnsCompanies.id
     * @param string  $name                  inFormRspnsCompanies.companyName
     * @param string  $relationship          inFormRspnsCompanies.relationship
     * @param string  $address               inFormRspnsCompanies.address
     * @param string  $regCountry            inFormRspnsCompanies.cntryOfReg
     * @param string  $regNum                inFormRspnsCompanies.regNum
     * @param string  $contactName           inFormRspnsCompanies.contactName
     * @param string  $phone                 inFormRspnsCompanies.phone
     * @param string  $percentOwnership      inFormRspnsCompanies.percentOwnership
     * @param string  $additionalInformation inFormRspnsCompanies.additionalInfo
     * @param string  $stateOwned            inFormRspnsCompanies.bStateOwnedEnt
     *
     * @return mixed If updated succeeded, then integer else false boolean
     */
    public function updateSingle(
        $id,
        $name,
        $relationship,
        $address,
        $regCountry,
        $regNum,
        $contactName,
        $phone,
        $percentOwnership,
        $additionalInformation,
        $stateOwned
    ) {
        \Xtra::requireInt($id);
        $rtn = $this->updateByID(
            $id,
            [
            'name' => $name,
            'relationship' => $relationship,
            'address' => $address,
            'regCountry' => $regCountry,
            'regNum' => $regNum,
            'contactName' => $contactName,
            'phone' => $phone,
            'percentOwnership' => $percentOwnership,
            'additionalInfo' => $additionalInformation,
            'stateOwned' => $stateOwned,
            ]
        );
        return $rtn;
    }

    /**
     * Updates inFormRspnsCompanies records for a given intake form
     *
     * @param integer $inFormRspnsID inFormRspnsCompanies.inFormRspnsID
     * @param array   $companies     inFormRspnsCompanies configurations
     *
     * @return boolean
     */
    public function updateMultiple($inFormRspnsID, $companies)
    {
        $inFormRspnsID = (int)$inFormRspnsID;
        $ddqModel = new Ddq($this->tenantID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
        $inFormRspnsIdInvalid = (empty($inFormRspnsID) || !($ddq = $ddqModel->findById($inFormRspnsID)));
        $rtn = false;
        if ($inFormRspnsIdInvalid) {
            return $rtn;
        } else {
            foreach ($companies as $id => $company) {
                $values = [];
                if ($this->isAPI) {
                    foreach ($company as $key => $value) {
                        $values[$this->apiParamToDbColumnMapping[$key]] = $value;
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
     * Validate companies data (new and existing wholistically)
     *
     * @param array   $existing       Existing inFormRspns data
     * @param array   $new            New data to impact inFormRspns
     * @param integer $minimum        Minimum records required for a given intake form
     * @param integer $maximum        Maximum records permitted for a given intake form
     * @param boolean $postProcessing If true, this will only validate DB values
     *
     * @return array
     */
    public function validateCompanies($existing, $new, $minimum, $maximum, $postProcessing)
    {
        $rtn = ['errorType' => '', 'data' => []];
        $errors = $companiesToCreate = $companiesToUpdate = $companiesToRemove = [];
        $numberOfValidCompanies = $numberOfInvalidCompanies = 0;
        if (!empty($new)) {
            foreach ($new as $idx => $company) {
                $existingCompany = null;
                $toBeUpdated = (
                    !empty($company['id'])
                    && (!isset($company['remove']) || $company['remove'] !== true)
                );
                $toBeRemoved = (
                    !empty($company['id']) && isset($company['remove']) && $company['remove'] === true
                );
                if ($toBeUpdated || $toBeRemoved) {
                    foreach ($existing as $currentExistingCompany) {
                        if ($currentExistingCompany['id'] == $company['id']) {
                            $existingCompany = $currentExistingCompany;
                            break;
                        }
                    }
                }
                $validation = $this->validateCompany(
                    $existingCompany,
                    $company,
                    $toBeUpdated,
                    $toBeRemoved,
                    $postProcessing
                );
                $companyNumber = (!is_null($existingCompany)) ? $company['id'] : 'new company #' . ($idx + 1);
                if (empty($validation['invalid']) && empty($validation['missing']) && empty($validation['error(s)'])) {
                    $numberOfValidCompanies++;
                    if ($toBeRemoved) {
                        $companiesToRemove[] = $company;
                    } else {
                        $companiesToCreate[] = $company;
                    }
                } else {
                    $numberOfInvalidCompanies++;
                    $errors[$companyNumber] = $validation;
                }
                if ($toBeUpdated && !empty($validation['valid'])) {
                    $companiesToUpdate[] = array_merge(['id' => $company['id']], $validation['valid']);
                }
            }
        } elseif ($postProcessing) {
            foreach ($existing as $idx => $company) {
                $validation = $this->validateCompany($company);
                if (empty($validation['invalid']) && empty($validation['missing']) && empty($validation['error(s)'])) {
                    $numberOfValidCompanies++;
                } else {
                    $numberOfInvalidCompanies++;
                    $errors[$company['id']] = $validation;
                }
            }
        }
        $rtn['data']['companies']['totalCompanies'] = ($postProcessing) ? count($existing) : count($new);
        $rtn['data']['companies']['subtotalValidCompanies'] = $numberOfValidCompanies;
        $rtn['data']['companies']['subtotalInvalidCompanies'] = $numberOfInvalidCompanies;
        if (!empty($errors)) {
            $rtn['data']['companies']['invalidCompanies'] = $errors;
            $rtn['errorType'] = 'questionsWithInvalidElementsSet';
        } else {
            // No errors so far!
            // Tally up all companies (existing, new, and newly removed) and validate min/max thresholds
            $threshold = $this->validateThreshold(
                $existing,
                $companiesToRemove,
                $companiesToCreate,
                $minimum,
                $maximum
            );
            if ($threshold['minimumDifferential']) {
                $rtn = [
                    'errorType' => 'questionsWithElementsSetLessThanMinimum',
                    'data' => [
                        'questionID' => 'companies',
                        'minimum' => $minimum,
                        'count' => ($minimum - $threshold['minimumDifferential'])
                    ]
                ];
            } elseif ($threshold['maximumDifferential']) {
                $rtn = [
                    'errorType' => 'questionsWithElementsSetMoreThanMaximum',
                    'data' => [
                        'questionID' => 'companies',
                        'maximum' => $maximum,
                        'count' => ($maximum + $threshold['maximumDifferential'])
                    ]
                ];
            }
        }
        if (!$postProcessing && !empty($companiesToUpdate)) {
            $rtn['dataToUpdate'] = json_encode($companiesToUpdate);
        }
        return $rtn;
    }

    /**
     * Validate key person data
     *
     * @param array   $existing       The ddqKeyPerson rec if person already exists in the DB
     * @param array   $new            New data to impact ddqKeyPerson
     * @param boolean $toBeUpdated    If true, this is an existing person being updated
     * @param boolean $toBeRemoved    If true, this is an existing person being deleted
     * @param boolean $postProcessing If true, this will only validate DB values
     *
     * @return array
     */
    public function validateCompany(
        $existing,
        $new = [],
        $toBeUpdated = false,
        $toBeRemoved = false,
        $postProcessing = false
    ) {
        $rtn = ['valid' => [], 'missing' => [], 'invalid' => [], 'error(s)' => ''];
        foreach ($this->validationRules as $key => $rule) {
            if ($toBeRemoved && $key !== 'id') {
                continue;
            }
            $paramIsSet = (!empty($new) && isset($new[$key]));
            $inDB = (!is_null($existing) && !empty($existing[$key]));
            if ($rule['required'] === true && !$paramIsSet && !$inDB) {
                // Missing required param or missing a contingency param
                $rtn['missing'][] = $key;
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'value' && isset($rule['value'])
                && $rule['value'] == 'primaryID' && $paramIsSet && is_null($existing)
            ) {
                // Invalid value supplied to primaryID param (not used by post-processing)
                $rtn['invalid'][] = $key;
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' ' : '')
                    . $new[$key]
                    . ' is an invalid primaryID.';
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'value' && !empty($rule['values'])
                && (($paramIsSet && !in_array($new[$key], $rule['values']))
                || ((!$paramIsSet || $postProcessing) && $inDB && !in_array($existing[$key], $rule['values'])))
            ) {
                // Invalid value supplied to param limited to subset of values
                $rtn['invalid'][] = $key;
                $rtn['error(s)'] .= ((!empty($rtn['error(s)'])) ? ' "' : '"')
                    . (($paramIsSet) ? $new[$key] : $existing[$key])
                    . "\" is an invalid {$key} value.";
            } elseif (isset($rule['validateBy']) && $rule['validateBy'] == 'numberRange' && isset($rule['minimum'])
                && isset($rule['maximum']) && ($minimum = $rule['minimum']) && ($maximum = $rule['maximum'])
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
            } elseif ($paramIsSet && !in_array($key, ['id'])) { // Parameter is valid to be saved
                $rtn['valid'][$key] = $new[$key];
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
                if (!isset($this->validationRules[$key]) && $key !== 'remove') {
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
     * Determine whether the combination of supplied and existing companies meets both
     * the minumum and maximum threshold requirements for a given intake form question.
     *
     * @param array   $existing Companies data already in the inFormRspnsCompanies table
     * @param array   $toRemove Companies data set to be removed from inFormRspnsCompanies
     * @param array   $toCreate Companies data set to be inserted into inFormRspnsCompanies
     * @param integer $minimum  Minimum threshold of records for a given intake form
     * @param integer $maximum  Maximum threshold of records for a given intake form
     *
     * @return array
     */
    public function validateThreshold($existing, $toRemove, $toCreate, $minimum, $maximum)
    {
        $rtn = ['minimumDifferential' => 0, 'maximumDifferential' => 0];
        $tally = count($existing); // Start with what already exists
        $tally -= count($toRemove); // Deduct what is to be removed
        $tally += count($toCreate); // Add what is to be created
        return [
            'minimumDifferential' => ((!empty($minimum) && $tally < $minimum) ? ($minimum - $tally) : 0),
            'maximumDifferential' => ((!empty($maximum) && $tally > $maximum) ? ($tally - $maximum) : 0)
        ];
    }
}
