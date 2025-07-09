<?php
/**
 *  IntakeFormResponses
 *
 * @keywords intake, form, instances
 *
 */

namespace Models\TPM\IntakeForms;

use Lib\UpDnLoadFile;
use Models\API\Endpoints\IntakeFormQuestions as IntakeFormQuestionsApiData;
use Models\Ddq;
use Models\TPM\IntakeForms\IntakeFormInstances;
use Models\TPM\IntakeForms\IntakeFormQuestions as IntakeFormQuestionsData;
use Models\TPM\IntakeForms\Legacy\OnlineQuestions;
use Models\TPM\IntakeForms\Response\InFormRspnsCompanies;
use Models\TPM\IntakeForms\Response\InFormRspnsKeyPersons;
use Models\TPM\IntakeForms\Response\InFormRspnsCountries;

/**
 * Intake form responses (previously the ddq table)
 */
#[\AllowDynamicProperties]
class IntakeFormResponses
{
    /**
     * Common errors encountered while validating intake form responses
     */
    public const VALIDATION_ERRORS = [
        'questionsInvalid',
        'questionsShouldNotGetAResponse',
        'questionsMissingResponse',
        'questionsWithInvalidResponse',
        'questionsWithInvalidResponseDateFormat',
        'questionsWithInvalidResponseDateTimeFormat',
        'questionsWithInvalidResponseEmailFormat',
        'questionsWithMissingParentResponse',
        'questionsWithInvalidParentResponse',
        'questionsWithIncompatibleParentResponse',
        'questionsWithIncompatibleChildResponse',
        'questionsWithInvalidElementsSet',
        'questionsWithElementsSetLessThanMinimum',
        'questionsWithElementsSetMoreThanMaximum',
        'questionsWithEmptyElementsSetCannotBeEmptied',
        'numberOfKeyPeopleExceeded'
    ];

    /**
     * Authorized userID
     *
     * @var integer
     */
    protected $authUserID = null;

    /**
     * Determines whether or not this was instantiated via the REST API
     *
     * @var boolean
     */
    public $isAPI = false;

    /**
     * Instance of IntakeFormQuestionsApiData
     *
     * @var object
     */
    protected $intakeFormQuestionsApiData = null;

    /**
     * Instance of IntakeFormQuestionsData
     *
     * @var object
     */
    protected $intakeFormQuestionsData = null;

    /**
     * Instance of OnlineQuestions
     *
     * @var object
     */
    protected $OQ = null;

    /**
     * Instance of Ddq
     *
     * @var object
     */
    protected $DDQ = null;

    /**
     * Instance of IntakeFormInstances
     *
     * @var object
     */
    protected $intakeFormInstances = null;

    /**
     * Whether or not validation has entered post-processing
     *
     * @var boolean
     */
    protected $postProcessing = false;

    /**
     * Set by an override subclass
     *
     * @var array
     */
    protected $overrides = [];

    /**
     * Case constructor requires clientID
     *
     * @param integer $clientID clientProfile.id
     * @param array   $params   configuration
     */
    public function __construct($clientID, $params = [])
    {
        $this->clientID = $clientID;
        $this->DB = \Xtra::app()->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : \Xtra::app()->ftr->user;
        $this->overrides = (!empty($params['overrides'])) ? $params['overrides'] : [];
        $this->intakeFormQuestionsApiData = new IntakeFormQuestionsApiData(
            $clientID,
            $this->authUserID,
            $this->overrides
        );
        $this->intakeFormQuestionsData = new IntakeFormQuestionsData(
            $clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]
        );
        $this->OQ = new OnlineQuestions(
            $clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]
        );
        $this->DDQ = new Ddq(
            $clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID, 'overrides' => $this->overrides]
        );
        $this->intakeFormInstances = new IntakeFormInstances(
            $clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]
        );
    }

    /**
     * Determines whether or not the supplied questionsID is affililated with any instance responses
     *
     * @param integer $questionsID intakeFormResponses.questionsID
     *
     * @return boolean
     */
    public function doesQuestionHaveAnyInstanceResponses($questionsID)
    {
        $questionsID = (int)$questionsID;
        $sql = "SELECT ifr.id FROM {$this->clientDB}.intakeFormResponses AS ifr\n"
            . "LEFT JOIN {$this->clientDB}.intakeFormInstances AS ifi ON ifi.id = ifr.instancesID\n"
            . "LEFT JOIN {$this->clientDB}.ddqName AS dn ON dn.id = ifi.intakeFormCfgID\n"
            . "WHERE ifr.questionsID = :questionsID AND dn.clientID = :clientID";
        $params = [':clientID' => $this->clientID, ':questionsID' => $questionsID];
        return (!empty($questionsID) && ($rows = $this->DB->fetchValueArray($sql, $params)));
    }

    /**
     * Get onlineQuestions.id/ddq value key/value pair for a given ddq.id and onlineQuestions.id
     *
     * @param integer $ddqID        ddq.id
     * @param integer $questionsID  onlineQuestions.id
     * @param string  $languageCode onlineQuestions.languageCode
     *
     * @return array
     */
    private function getDdqResponse($ddqID, $questionsID, $languageCode)
    {
        $rtn = ['id' => $questionsID, 'response' => ''];
        $ddqID = (int)$ddqID;
        $questionsID = (int)$questionsID;
        $questionConfig = ['id' => $questionsID];
        if (!empty($ddqID) && !empty($questionsID)
            && ($question = $this->intakeFormQuestionsApiData->getIntakeFormQuestion($questionConfig, [], [], true))
            && ($ddq = $this->DDQ->findById($ddqID))
        ) {
            $rtn['response'] = $ddq->get($question['questionKey']);
        }
        return $rtn;
    }

    /**
     * Returns an array of all possible elements sets for the ddq by type
     *
     * @param string $type Any elements set that has overrides (for now just keyPersons)
     *
     * @return array
     */
    public function getElementsSetsByType($type)
    {
        $rtn = [$type];
        if (!empty($this->overrides) && isset($this->overrides[$type])
            && !empty($this->overrides[$type]['elementsSets'])
        ) {
            foreach (array_keys($this->overrides[$type]['elementsSets']) as $elementsSetID) {
                $rtn[] = "{$type}_{$elementsSetID}";
            }
        }
        return $rtn;
    }

    /**
     * Get id, questionsID and response values for a given instancesID
     *
     * @param integer $instancesID intakeFormResponses.instancesID
     *
     * @return array
     */
    public function getInstanceResponses($instancesID)
    {
        $rtn = [];
        $instancesID = (int)$instancesID;
        $sql = "SELECT id, questionsID, response FROM {$this->clientDB}.intakeFormResponses\n"
            . "WHERE instancesID = :instancesID";
        $params = [':instancesID' => $instancesID];
        if (!empty($instancesID) && ($rows = $this->DB->fetchAssocRows($sql, $params))) {
            // Return array using questionsID as the index
            foreach ($rows as $row) {
                $rtn[$row['questionsID']] = $row;
            }
        }
        return $rtn;
    }

    /**
     * Get id, response and created values for a given intakeFormResponses record's instancesID and questionsID
     *
     * @param integer $instancesID intakeFormResponses.instancesID
     * @param integer $questionsID intakeFormResponses.questionsID
     *
     * @return array
     */
    public function getQuestionInstanceResponse($instancesID, $questionsID)
    {
        $rtn = [];
        $instancesID = (int)$instancesID;
        $questionsID = (int)$questionsID;
        if (!empty($instancesID) && !empty($questionsID)) {
            $sql = "SELECT id, response, created FROM {$this->clientDB}.intakeFormResponses\n"
                . "WHERE instancesID = :instancesID AND questionsID = :questionsID";
            $params = [':instancesID' => $instancesID, ':questionsID' => $questionsID];
            if ($row = $this->DB->fetchAssocRow($sql, $params)) {
                $rtn = $row;
            }
        }
        return $rtn;
    }

    /**
     * Get instancesID, response and created values for a given intakeFormResponses record's id
     *
     * @param integer $id intakeFormResponses.id
     *
     * @return array
     */
    public function getResponseByID($id)
    {
        $rtn = [];
        $id = (int)$id;
        if (!empty($id)) {
            $sql = "SELECT instancesID, response, created FROM {$this->clientDB}.intakeFormResponses WHERE id = :id";
            if ($row = $this->DB->fetchAssocRow($sql, [':id' => $id])) {
                $rtn = $row;
            }
        }
        return $rtn;
    }

    /**
     * Insert intakeFormResponses record
     *
     * @param integer $instancesID intakeFormResponses.instancesID
     * @param integer $questionsID intakeFormResponses.questionsID
     * @param mixed   $response    intakeFormResponses.response
     *
     * @return void
     */
    public function insert($instancesID, $questionsID, mixed $response)
    {
        $instancesID = (int)$instancesID;
        $questionsID = (int)$questionsID;
        if (!empty($instancesID) && !empty($questionsID) && isset($response)) {
            $sql = "INSERT INTO {$this->clientDB}.intakeFormResponses SET\n"
                . "instancesID = :instancesID, "
                . "questionsID = :questionsID, "
                . "response = :response, "
                . "created = :created";
            $params = [
                ':instancesID' => $instancesID,
                ':questionsID' => $questionsID,
                ':response' => $response,
                ':created' => date('Y-m-d H:i:s')
            ];
            $this->DB->query($sql, $params);
        }
    }

    /**
     * Process any errors collected during validation, and format appropriate messaging.
     *
     * @param array $rtn                         Validation results
     * @param array $errors                      Error array from validation
     * @param array $questionsWithResponsesSaved questionID's with responses being saved
     *
     * @return array
     */
    private function processErrors($rtn, $errors, $questionsWithResponsesSaved = [])
    {
        $proceedProcessingErrors = false;
        if (!empty($errors)) {
            foreach ($errors as $errorType => $errorData) {
                if (in_array($errorType, self::VALIDATION_ERRORS)) {
                    $proceedProcessingErrors = true;
                }
            }
        }
        if ($proceedProcessingErrors) {
            $errorFlagged = false;
            $rtn = [
                'responseIdsToWipe' => ($rtn['responseIdsToWipe'] ?? []),
                'data' => ($rtn['data'] ?? []),
                'errors' => $errors,
                'message' => '',
                'submittable' => false
            ];
            if (!empty($questionsWithResponsesSaved)) {
                $rtn['message'] = 'These questionID\'s responses have passed validation: '
                    . implode(', ', $questionsWithResponsesSaved) . '.';
            }
            if (!empty($errors['questionsInvalid'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'These questionID\'s are invalid: '
                    . implode(', ', $errors['questionsInvalid']) . '.';
            }
            if (!empty($errors['questionsMissingResponse'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'These questionID\'s were missing responses: '
                    . implode(', ', $errors['questionsMissingResponse']) . '.';
            }
            if (!empty($errors['questionsShouldNotGetAResponse'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'These questionID\'s do not permit responses being paragraph elements: '
                    . implode(', ', $errors['questionsShouldNotGetAResponse']) . '.';
            }
            if (!empty($errors['questionsWithInvalidResponse'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithInvalidResponse'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" is an invalid response for questionID #'
                        . $error['questionID'] . '.';
                }
            }
            if (!empty($errors['questionsWithInvalidResponseDateFormat'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithInvalidResponseDateFormat'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" is an invalidly formatted date response for questionID #'
                        . $error['questionID'] . '.';
                }
            }
            if (!empty($errors['questionsWithInvalidResponseDateTimeFormat'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithInvalidResponseDateTimeFormat'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" is an invalidly formatted datetime response for questionID #'
                        . $error['questionID'] . '.';
                }
            }
            if (!empty($errors['questionsWithInvalidResponseEmailFormat'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithInvalidResponseEmailFormat'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" is an invalidly formatted email response for questionID #'
                        . $error['questionID'] . '.';
                }
            }
            if (!empty($errors['questionsWithMissingParentResponse'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithMissingParentResponse'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" will not be recorded as a response for question #'
                        . $error['questionID'] . ', as its parent question #' . $error['parentQuestionID']
                        . ' has no response.';
                }
            }
            if (!empty($errors['questionsWithInvalidParentResponse'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithInvalidParentResponse'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" will not be recorded as a response for question #'
                        . $error['questionID'] . ', as its parent question #' . $error['parentQuestionID']
                        . ' has an invalid response.';
                }
            }
            if (!empty($errors['questionsWithIncompatibleChildResponse'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithIncompatibleChildResponse'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" will not be recorded as a response for question #'
                        . $error['questionID'] . ', as its value is incompatible with its child question #'
                        . $error['childQuestionID'] . ' whose response is "' . $error['childResponse'] . '".';
                }
            }
            if (!empty($errors['questionsWithIncompatibleParentResponse'])) {
                $errorFlagged = true;
                foreach ($errors['questionsWithIncompatibleParentResponse'] as $error) {
                    $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                        . '"' . $error['response'] . '" will not be recorded as a response for question #'
                        . $error['questionID'] . ', as its value is incompatible with its parent question #'
                        . $error['parentQuestionID'] . ' whose response is "' . $error['parentResponse'] . '".';
                }
            }
            if (!empty($errors['questionsWithEmptyElementsSetCannotBeEmptied'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'There was a failure to expunge element set data already empty for these questionID\'s: ';
                foreach ($errors['questionsWithEmptyElementsSetCannotBeEmptied'] as $idx => $error) {
                    $rtn['message'] .= ((!empty($idx)) ? ', ' : '') . $error['questionID'];
                }
            }
            if (!empty($errors['questionsWithInvalidElementsSet'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'The following element data sets were invalid for these questionID\'s: ';
                foreach ($errors['questionsWithInvalidElementsSet'] as $idx => $error) {
                    $questionID = array_keys($error)[0];
                    $rtn['message'] .= ((!empty($idx)) ? ', ' : '') . $questionID;
                    if ($questionID == 'countriesWhereBusinessPracticed') {
                        $rtn['message'] .= '(these countries improperly formatted: '
                            . $error[$questionID]['response'][0] . ')';
                    }
                }
            }
            if (!empty($errors['questionsWithElementsSetLessThanMinimum'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'The following questionID\'s contain element data sets with less than the minimum requirement: ';
                foreach ($errors['questionsWithElementsSetLessThanMinimum'] as $idx => $error) {
                    $rtn['message'] .= ((!empty($idx)) ? ', ' : '')
                        . $error['questionID']
                        . ' (minimum: ' . $error['minimum'] . ', '
                        . 'count: ' . $error['count'] . ')';
                }
            }
            if (!empty($errors['questionsWithElementsSetMoreThanMaximum'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . 'The following questionID\'s contain element data sets exceeding the maximum limit: ';
                foreach ($errors['questionsWithElementsSetMoreThanMaximum'] as $idx => $error) {
                    $rtn['message'] .= ((!empty($idx)) ? ', ' : '')
                        . $error['questionID']
                        . ' (maximum: ' . $error['maximum'] . ', '
                        . 'count: ' . $error['count'] . ')';
                }
            }
            if (!empty($errors['numberOfKeyPeopleExceeded'])) {
                $errorFlagged = true;
                $rtn['message'] .= ((!empty($rtn['message'])) ? ' ' : '')
                    . $errors['numberOfKeyPeopleExceeded'];
            }
            if (!$errorFlagged) {
                $rtn['errors'] = [];
            } else {
                // Trim any errors not assigned to any questionID's
                foreach ($rtn['errors'] as $error => $errorData) {
                    if (empty($errorData)) {
                        unset($rtn['errors'][$error]);
                    }
                }
            }
            if (empty($rtn['message']) && empty($questionsWithResponsesSaved)) {
                $rtn['message'] = 'No questionID\'s responses have been saved.';
            }
        }
        return $rtn;
    }

    /**
     * Process validation result for a question's response
     *
     * @param integer $instancesID          intakeFormInstances.id
     * @param array   $question             Question configuration
     * @param array   $questions            Section questions
     * @param array   $responses            Section responses
     * @param mixed   $result               Either true boolean if valid, else string if error
     * @param array   $resultData           Accompanying data for a parent or child question that failed validation
     * @param array   $dataToUpdate         Specific columns whose need to be updated with the specified values
     * @param string  $section              Section name
     * @param array   $sections             All sections cumulatively validated
     * @param array   $questionResponseData Successfully validated responses mapped to questions
     * @param array   $errors               All errors cumulatively collected
     *
     * @return array
     */
    private function processValidationResult(
        $instancesID,
        $question,
        $questions,
        $responses,
        mixed $result,
        $resultData,
        $dataToUpdate,
        $section,
        $sections,
        $questionResponseData,
        $errors
    ) {
        $errorKeysWithResponses = [
            'questionsWithInvalidResponse',
            'questionsWithInvalidResponseDateFormat',
            'questionsWithInvalidResponseDateTimeFormat',
            'questionsWithInvalidResponseEmailFormat'
        ];
        $errorKeysWithResultData = [
            'questionsWithMissingParentResponse',
            'questionsWithInvalidParentResponse',
            'questionsWithIncompatibleParentResponse',
            'questionsWithIncompatibleChildResponse',
            'questionsWithInvalidElementsSet',
            'questionsWithElementsSetLessThanMinimum',
            'questionsWithElementsSetMoreThanMaximum',
            'questionsWithEmptyElementsSetCannotBeEmptied'
        ];
        $rtn = ['errors' => $errors, 'sections' => $sections, 'questionResponseData' => $questionResponseData];
        $response = $responses[$question['id']];
        if (is_string($result) && in_array($result, self::VALIDATION_ERRORS)) {
            if (in_array($result, $errorKeysWithResponses)) {
                $rtn['errors'][$result][] = ['questionID' => $question['id'], 'response' => $response];
            } elseif (in_array($result, $errorKeysWithResultData)) {
                $rtn['errors'][$result][] = $resultData;
            } else {
                $rtn['errors'][$result][] = $question['id'];
            }
        } elseif ($result === true) {
            if (!in_array($section, $sections)) {
                $rtn['sections'][] = $section;
            }
            $rtn['questionResponseData'][$section][$question['id']] = [
                'question' => $question,
                'response' => $response
            ];
        }
        if ($result !== true && !empty($dataToUpdate)) {
            // Save the params that passed validation
            if (!in_array($section, $sections)) {
                $rtn['sections'][] = $section;
            }
            $rtn['questionResponseData'][$section][$question['id']] = [
                'question' => $question,
                'response' => $dataToUpdate
            ];
        }
        return $rtn;
    }

    /**
     * Remove intakeFormResponses records by id(s)
     *
     * @param array $ids Array of intakeFormResponses.id values
     *
     * @return void
     */
    public function removeByIds($ids)
    {
        if (!empty($ids)) {
            $sql = "DELETE FROM {$this->clientDB}.intakeFormResponses\n"
                . "WHERE id IN(" . implode(', ', $ids) . ")";
            $this->DB->query($sql);
        }
    }

    /**
     * Remove values from ddq columns configured for specified onlineQuestions.id's
     *
     * @param integer $ddqID ddq.id
     * @param array   $ids   onlineQuestions.id values
     *
     * @return void
     */
    public function removeDdqValsByOQids($ddqID, $ids)
    {
        $ddqID = (int)$ddqID;
        if (empty($ddqID) || empty($ids)) {
            return;
        }
        $sql = "SELECT questionID FROM {$this->clientDB}.onlineQuestions WHERE id IN(" . implode(', ', $ids) . ")";
        if ($ddqColumns = $this->DB->fetchValueArray($sql)) {
            $sql = "UPDATE {$this->clientDB}.ddq SET";
            foreach ($ddqColumns as $idx => $ddqColumn) {
                $sql .= ((!empty($idx)) ? 'AND' : '') . " {$ddqColumn} = ''";
            }
            $sql .= " WHERE id = :id";
            $this->DB->query($sql, [':id' => $ddqID]);
        }
    }

    /**
     * Determine whether or not supplied responses are validly formatted (source: onlineQuestions)
     *
     * @param array $responses Responses
     *
     * @return boolean
     */
    private function responsesAreValidlyFormatted($responses)
    {
        $rtn = false;
        if (is_array($responses)) {
            foreach ($responses as $response) {
                if (!array_key_exists('questionID', $response)
                    || empty($response['questionID'])
                    || !array_key_exists('response', $response)
                ) {
                    return $rtn;
                }
            }
            $rtn = true; // All rows have questionID key with a value and response key
        }
        return $rtn;
    }

    /**
     * Format a reponse to save as a part of multiple questions/responses validation
     *
     * @param integer $id         If isLegacy then ddq.id else intakeFormInstances.id
     * @param mixed   $questionID Unique question identifier
     * @param mixed   $response   Question response
     * @param boolean $isLegacy   True if using legacy schema
     *
     * @return mixed
     */
    private function responseToSaveFormat($id, mixed $questionID, mixed $response, $isLegacy)
    {
        $rtn = $response;
        if ($isLegacy) {
            $overrideType = null;
            $keyPersonsElementsSets = $this->getElementsSetsByType('keyPersons');
            $elementsSets = array_merge($keyPersonsElementsSets, ['companies']);
            if (!empty($this->overrides)) {
                $elementsSetType = array_keys($this->overrides)[0];
                $overrideType = str_replace("{$elementsSetType}_", '', (string) $questionID);
            }
            if (in_array($questionID, $elementsSets)) {
                if (!empty($response['response'])) {
                    if ($response['response'] == 'empty') {
                        // An 'empty' response assigned to these questionID's will expunge all relative recs for the ddq
                        $rtn = ['expunge' => true];
                    } else {
                        $rtn = ['create' => [], 'remove' => [], 'update' => []];
                        foreach ($response['response'] as $item) {
                            if (!is_null($overrideType)) {
                                $item['overrideType'] = $overrideType;
                            }
                            if (isset($item['id']) && !empty($item['id'])) {
                                $itemID = $item['id'];
                                if (isset($item['remove']) && $item['remove'] === true) {
                                    $rtn['remove'][] = $itemID;
                                } else {
                                    unset($item['id']);
                                    $rtn['update'][$itemID] = $item;
                                }
                            } else {
                                $rtn['create'][] = $item;
                            }
                        }
                        if (empty($rtn['create']) && empty($rtn['remove']) && empty($rtn['update'])) {
                            $rtn = false; // Nothing to save
                        }
                    }
                } else {
                    $rtn = false;
                }
            } elseif ($questionID === 'countriesWhereBusinessPracticed') {
                if ($response['response'] === 'empty') {
                    // An empty response will expunge values previously stored
                    $rtn = ['expungeCountriesWhereBusinessPracticed' => true];
                } else {
                    // countries will always be deleted before new values are saved
                    $countries = json_decode((string) $response['response'], true);
                    foreach ($countries as $country) {
                        $rtn['createCountriesWhereBusinessPracticed'][] = $country;
                    }
                }
            } else {
                $rtn = [$response['responseTableColumn'] => $response['response']];
            }
        }
        return $rtn;
    }

    /**
     * Accumulate a reponse to save as a part of multiple questions/responses validation
     *
     * @param array   $responsesToSave Responses to be saved so far
     * @param string  $section         Question section
     * @param array   $question        Question configuration
     * @param mixed   $response        Question response
     * @param boolean $isLegacy       True if using legacy schema
     *
     * @return array
     */
    private function responseToSaveAccumulation($responsesToSave, $section, $question, mixed $response, $isLegacy)
    {
        $rtn = $responsesToSave;
        if ($isLegacy) {
            $responseTable = 'ddq';
            $keyPersonsElementsSets = $this->getElementsSetsByType('keyPersons');
            $elementsSets = array_merge($keyPersonsElementsSets, ['companies']);
            if (in_array($question['id'], $elementsSets)) {
                $responseTable = (in_array($question['id'], $keyPersonsElementsSets))
                    ? 'ddqKeyPerson'
                    : 'inFormRspnsCompanies';
                $rtn[$section][$question['id']] = [
                    'response' => (($response !== 'empty') ? json_decode((string) $response, true) : 'empty'),
                    'responseTable' => $responseTable
                ];
            } elseif ($question['id'] == 'countriesWhereBusinessPracticed') {
                $rtn[$section][$question['id']] = [
                    'response' => (($response !== 'empty') ? json_encode($response) : 'empty'),
                    'responseTable' => 'inFormRspnsCountries'
                ];
            } else {
                $rtn[$section][$question['id']] = [
                    'response' => $response,
                    'responseTable' => 'ddq',
                    'responseTableColumn' => $question['dataSource'],
                ];
            }
        } else {
            $rtn[$section][$question['id']] = [
                'response' => $response,
                'responseTable' => $question['responseTable'],
                'responseTableColumn' => $question['responseTableColumn'],
            ];
            if ($question['id'] == 'countriesWhereBusinessPracticed') {
                $rtn[$section][$question['id']] = [
                    'response' => (($response !== 'empty') ? json_encode($response) : 'empty'),
                    'responseTable' => 'inFormRspnsCountries',
                    'responseTableColumn' => $question['responseTableColumn'],
                ];
            }
        }
        return $rtn;
    }

    /**
     * Creates a intakeFormResponses record and moves the file into place in the filesystem.
     *
     * @param integer $instancesID  intakeFormInstances.id
     * @param integer $questionsID  intakeFormQuestions.id
     * @param array   $uploadInfo   Includes tmp_name, name, type and size
     * @param string  $description  File description
     * @param integer $loginEmail   ddq.loginEmail
     * @param integer $oqQuestionID onlineQuestions.questionID
     *
     * @return mixed False if not saved else an array with data saved
     */
    public function saveFile($instancesID, $questionsID, $uploadInfo, $description, $loginEmail)
    {
        $instancesID = (int)$instancesID;
        $questionsID = (int)$questionsID;
        $rtn = false;
        if (!empty($instancesID) && !empty($questionsID) && !empty($uploadInfo)
            && !empty($description) && !empty($loginEmail)
        ) {
            $response = '[{'
                . '"description": "' . $description . '",'
                . '"filename": "' . $uploadInfo['name'] . '",'
                . '"fileType": "' . $uploadInfo['type'] . '",'
                . '"fileSize": "' . $uploadInfo['size'] . '",'
                . '"ownerID": "' . $loginEmail . '",'
                . '"emptied": 1,'
                . '"catID": 0'
                . '}]';
            $this->insert($instancesID, $questionsID, $response);
            $response = $this->getQuestionInstanceResponse($instancesID, $questionsID);
            // store in file system
            $initVals = ($this->isAPI) ? 'api' : '';
            (new UpDnLoadFile($initVals))->moveUploadedClientFile(
                $uploadInfo['tmp_name'],
                'intakeFormResponses',
                $this->clientID,
                $response['id']
            );
            $rtn = [
                'id' => $response['id'],
                'filename' => $uploadInfo['name'],
                'fileType' => $uploadInfo['type'],
                'fileSize' => $uploadInfo['size'],
                'description' => $description,
                'ownerID' => $loginEmail,
                'questionsID' => $questionsID,
                'created' => $response['created']
            ];
        }
        return $rtn;
    }

    /**
     * For a given intake form instance, attempt to save response values
     *
     * @param integer $id                    intakeFormInstance.id
     * @param array   $questionsAndResponses Associative array of questionID's and responses
     *
     * @return mixed String if an error message, else an integer (1 if changed, 0 if unchanged)
     */
    public function saveResponses($id, $questionsAndResponses)
    {
        $id = (int)$id;
        if (empty($id) || !($instance = $this->intakeFormInstances->getInstance($id))) {
            return (empty($id)) ? 'intakeFormInstanceID is missing' : "intakeFormInstanceID #{$id} is invalid";
        } elseif (!is_array($questionsAndResponses) || empty($questionsAndResponses)) {
            return (!is_array($questionsAndResponses)) ? 'responses are invalid' : "responses are missing";
        }
        $responses = $this->getInstanceResponses($id);
        // Get all existing intake form responses
        $valuesChanged = false;
        foreach ($questionsAndResponses as $questionID => $response) {
            if (array_key_exists($questionID, $responses)) {
                // Response already exists
                if ($responses[$questionID]['response'] != $response['response']) {
                    // Response has changed, so update it.
                    $valuesChanged = true;
                    $this->updateByID($responses[$questionID]['id'], $response['response']);
                }
            } else { // Response doesn't already exist, so create it.
                $valuesChanged = true;
                $this->insert($id, $questionID, $response['response']);
            }
        }
        return ($valuesChanged) ? 1 : 0;
    }

    /**
     * Toggle post-processing, and kick off any functionality or settings as needed.
     *
     * @param integer $id       If isLegacy then ddq.id else intakeFormInstances.id
     * @param boolean $isLegacy True if using legacy schema
     *
     * @return void
     */
    private function togglePostProcessing($id, $isLegacy = false)
    {
        if ($this->postProcessing) {
            $this->postProcessing = false;
        } else {
            $this->postProcessing = true;
            if (!empty($this->overrides)) {
                // Reset the ownerPercentage tally
                $keyPersons = new InFormRspnsKeyPersons(
                    $this->clientID,
                    ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID, 'overrides' => $this->overrides]
                );
                $this->overrides['keyPersons']['ownerPercentage'] = $keyPersons->getOwnerPercentageTallyByDdqID($id);
            }
        }
    }

    /**
     * Update response value by id
     *
     * @param integer $id       intakeFormResponses.id
     * @param integer $response intakeFormResponses.response
     *
     * @return void
     */
    public function updateByID($id, $response)
    {
        $id = (int)$id;
        if (!empty($id) && isset($response)) {
            $this->DB->query(
                "UPDATE {$this->clientDB}.intakeFormResponses SET response = :response WHERE id = :id",
                [':response' => $response, ':id' => $id]
            );
        }
    }

    /**
     * Updates a file's description in the response JSON for a intakeFormResponses record.
     *
     * @param integer $responsesID intakeFormResponses.id
     * @param string  $description File description
     *
     * @return void
     */
    public function updateFileByID($responsesID, $description)
    {
        $responsesID = (int)$responsesID;
        if (!empty($responsesID) && !empty($description)
            && ($response = $this->getResponseByID($responsesID))
        ) {
            $json = json_decode((string) $response['response'], true);
            $json[0]['description'] = $description;
            $newResponse = json_encode($json);
            $this->updateByID($responsesID, $newResponse);
        }
    }

    /**
     * Validate child question response
     *
     * @param integer $id               If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $childQuestion    Child question configuration
     * @param mixed   $childResponse    Response to child question
     * @param array   $responses        Section responses
     * @param mixed   $parentQuestionID Parent question ID
     * @param string  $parentQuestion   Parent question configuration
     * @param boolean $isLegacy         True if using legacy schema
     *
     * @return array
     */
    private function validateChildResponse(
        $id,
        $childQuestion,
        mixed $childResponse,
        $responses,
        mixed $parentQuestionID,
        $parentQuestion,
        $isLegacy
    ) {
        $rtn = ['errorType' => '', 'data' => []];
        if (isset($responses[$parentQuestionID])) {
            // Parent's response supplied in the responses param
            $parentResponse = $responses[$parentQuestionID];
            $parentResult = $this->validateResponse($id, $parentQuestion, $parentResponse, $isLegacy);
            if ($parentResult !== true) {
                // Parent response in responses param is invalid
                $rtn = [
                    'errorType' => 'questionsWithInvalidParentResponse',
                    'data' => [
                        'questionID' => $childQuestion['id'],
                        'response' => $childResponse,
                        'parentQuestionID' => $parentQuestionID,
                        'parentResponse' => $parentResponse
                    ]
                ];
            } else { // Validate child question with parent's response
                if ($isLegacy) {
                    $childQuestion = $this->intakeFormQuestionsApiData->formatOQ(
                        $childQuestion,
                        [$parentResponse],
                        true
                    );
                } else {
                    $childQuestion = $this->intakeFormQuestionsApiData->formatIntakeFormQuestion(
                        $childQuestion,
                        $parentResponse,
                        true
                    );
                }
                $childResult = $this->validateResponse(
                    $id,
                    $childQuestion,
                    $childResponse,
                    $isLegacy,
                    $parentResponse
                );
                if ($childResult !== true) {
                    $rtn = [
                        'errorType' => 'questionsWithIncompatibleParentResponse',
                        'data' => [
                            'questionID' => $childQuestion['id'],
                            'response' => $childResponse,
                            'parentQuestionID' => $parentQuestionID,
                            'parentResponse' => $parentResponse
                        ]
                    ];
                }
            }
        } else {
            // Validate child response with parent's response (or lack thereof) in DB
            $childResult = $this->validateResponse($id, $childQuestion, $childResponse, $isLegacy);
            if ($childResult !== true) {
                $rtn = [
                    'errorType' => $childResult,
                    'data' => [
                        'questionID' => $childQuestion['id'],
                        'response' => $childResponse
                    ]
                ];
                $parentQuestionIDErrs = ['questionsWithMissingParentResponse', 'questionsWithInvalidParentResponse'];
                if (in_array($childResult, $parentQuestionIDErrs)) {
                    $rtn['data']['parentQuestionID'] = $parentQuestionID;
                }
            }
        }
        return $rtn;
    }

    /**
     * Determines whether or not a child response is incompatible with a parent
     *
     * @param array $cfg Includes childQuestion, childResponse, parentResponse and isLegacy
     *
     * @return boolean
     */
    private function validateChildResponseIncompatibility($cfg)
    {
        foreach ($cfg as $key => $value) {
            ${$key} = $value; // childQuestion, childResponse, parentResponse and isLegacy
        }
        $childQuestion = ($isLegacy)
            ? $this->intakeFormQuestionsApiData->formatOQ($childQuestion, [$parentResponse], true)
            : $this->intakeFormQuestionsApiData->formatIntakeFormQuestion($childQuestion, $parentResponse, true);
        $acceptableResponses = [];
        if (in_array($childQuestion['element'], ['radio', 'select'])) {
            // For elements limited to a set of acceptable responses (radio and select),
            // check if the current response in the DB is compatible with the new parent response.
            foreach ($childQuestion['options'] as $option) {
                $acceptableResponses[] = $option['value'];
            }
        }
        return (!empty($acceptableResponses) && !in_array($childResponse, $acceptableResponses));
    }

    /**
     * Validate Companies elements set. It should look something like this:
     * {"questionID": "companies", "response":[
     *     Creates a new inFormRspnsCompanies record for People's Bank
     *     {
     *         "name": "People's Bank", (required)
     *         "relationship": "Subsidiary", (required)
     *         "address": "123 Fake St Faketown, OH 232122", (required)
     *         "registrationNumber": "1232231", (optional)
     *         "registrationCountry": "US", (optional)
     *         "contactName": "John Doe", (optional)
     *         "phone": "444-555-1234", (optional)
     *         "percentOwnership": 78, (optional)
     *         "additionalInformation": "Bank only open on Fridays" (optional)
     *     },
     *     { // Modifies a inFormRspnsCompanies rec where id = 2222, and matching tenantID/inFormRspnsID
     *         "id": 2222
     *         "name": "Harry's Canaries, Inc.",
     *         "relationship": "Partners",
     *         "address": "11 Nose St Whosetown, PA 19212",
     *         "registrationNumber": "434333",
     *         "registrationCountry": "RU",
     *         "contactName": "Jane Doe",
     *         "phone": "433-232-5857",
     *         "percentOwnership": 1,
     *         "additionalInformation": "The canaries all died in the coal mine"
     *     },
     *     { // Deletes a inFormRspnsCompanies rec where id = 4322, and matching tenantID/inFormRspnsID
     *         "id": 4322
     *         "remove": true,
     *     }
     * ]}
     *
     * Wipes out all inFormRspnsCompanies recs for the tenantID/inFormRspnsID combo:
     * {"questionID": "companies", "response": "empty"}
     *
     * @param integer $id       If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $question Question configuration
     * @param mixed   $response String/integer response supplied for question
     * @param boolean $isLegacy True if using legacy schema
     *
     * @return mixed Either true boolean if valid, else string if error
     */
    private function validateElementsSetCompanies($id, $question, mixed $response, $isLegacy)
    {
        $rtn = ['errorType' => '', 'data' => []];
        $companiesModel = new InFormRspnsCompanies(
            $this->clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID, 'languageCode' => $question['languageCode']]
        );
        $newCompanies = [];
        $existingCompanies = $companiesModel->getCompaniesByInFormRspnsID($id);
        if (!empty($response) && (!is_string($response) || ($response !== 'empty'
            && !($newCompanies = \Xtra::validateJSON($response))))
        ) {
            $rtn = [
                'errorType' => 'questionsWithInvalidElementsSet',
                'data' => [$question['id'] => 'malformed JSON']
            ];
        } elseif ($response === 'empty') {
            if (!$existingCompanies) {
                $rtn = [
                    'errorType' => 'questionsWithEmptyElementsSetCannotBeEmptied',
                    'data' => ['questionID' => $question['id']]
                ];
            }
        } else {
            $rtn = $companiesModel->validateCompanies(
                $existingCompanies,
                $newCompanies,
                $question['minimum'],
                $question['maximum'],
                $this->postProcessing
            );
        }
        return $rtn;
    }

    /**
     * Validate Countries elements set.
     *
     * being validated:
     * {"questionID": "countriesWhereBusinessPracticed", "response": ["US","RU","FR","DE"]}
     *
     * $question:
     *     {
     *   "id": "countriesWhereBusinessPracticed",
     *   "section": "Business Practices",
     *   "element": "elementsSet",
     *   "minimum": 1,
     *   "countries": [
     *       {
     *           "id": "AZ",
     *           "element": "checkbox",
     *           "label": "Azerbaijan",
     *           "showLabel": true,
     *           "checkedValue": "AZ",
     *           "uncheckedValue": "",
     *           "checked": false
     *       },
     *       {
     *           "id": "BD",
     *           "element": "checkbox",
     *           "label": "Bangladesh",
     *           "showLabel": true,
     *           "checkedValue": "BD",
     *           "uncheckedValue": "",
     *           "checked": false
     *       },
     *       ...
     *
     *
     * @param integer $id       If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $question Question configuration
     * @param mixed   $response String/integer response supplied for question
     * @param boolean $isLegacy True if using legacy schema
     *
     * @return mixed Either true boolean if valid, else string if error
     */
    private function validateElementsSetCountries($id, $question, mixed $response, $isLegacy)
    {
        $rtn = ['errorType' => '', 'data' => []];
        $countriesModel = new InFormRspnsCountries(
            $this->clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]
        );
        $countries = (is_string($response) && !empty($response)) ? json_decode($response) : $response;
        if ($response === 'empty' && !($validCountries = $countriesModel->getCountriesByQ($question))) {
            $rtn = [
                'errorType' => 'questionsWithEmptyElementsSetCannotBeEmptied',
                'data' => ['questionID' => $question['id']]
            ];
        } elseif (count($countries) < (int)$question['minimum']) {
            $rtn = [
                'errorType' => 'questionsWithElementsSetLessThanMinimum',
                'data' => ['questionID' => $question['id'], 'minimum' => (int)$question['minimum'], 'count' => count($countries)]
            ];
        } else {
            // Now, loop through each country and vet what was provided
            $errors = [];
            $validCountries = $countriesModel->getCountriesByQ($question);
            if (empty($countries)) {
                $countries = $countriesModel->getCountriesByDdqID($question['id'], $isLegacy);
            }
            $cnt = count($countries);
            for ($x = 0; $x < $cnt; $x++) {
                if (strlen((string) $countries[$x]) !== 2) {
                    $r = $countries[$x];
                    $rtn = [
                        'errorType' => 'questionsWithInvalidElementsSet',
                        'data' => ['questionID' => $question['id'], 'response' => [$r]]
                    ];
                }
                if (is_array($validCountries) && !in_array($countries[$x], $validCountries)) {
                    if (!empty($errors)) {
                        $rtn = [
                            'errorType' => 'questionsWithInvalidElementsSet',
                            'data' => $errors
                        ];
                    }
                }
            }
        }
        return $rtn;
    }


    /**
     * Validate Key Persons elements set. It should look something like this:
     * {"questionID": "keyPersons", "response":[
     *     Creates a new record for Joe Smith
     *     {
     *         "name": "Joe Smith", (required)
     *         "email": "joesmith@test.com", (required, in email format)
     *         "position": "CEO", (required)
     *         "nationality": "USA", (required)
     *         "address": "123 Fake Street Sillytown, CA 90211", (required)
     *         "id": 12423432, (required)
     *         "owner": true, (if false, this can be omitted)
     *         "ownerPercentage": 78, (if owner is false, this can be omitted)
     *         "keyManager": false, (if false, this can be omitted)
     *         "boardMember": false, (if false, this can be omitted)
     *         "keyConsultant": false, (if false, this can be omitted)
     *         "embargoedResidents": "No", (required)
     *         "proposedRole": "Partner" (required)
     *     },
     *     { // Modifies a ddqKeyPerson rec where id = 12323, and matching clientID/ddqID
     *         "id": 12323
     *         "name": "Jane Kline",
     *         "email": "janekline@test.com",
     *         "position": "VP of Marketing",
     *         "nationality": "USA",
     *         "address": "123 Fake Street Sillytown, CA 90211",
     *         "id": 12423433,
     *         "keyManager": true,
     *         "boardMember": true,
     *         "keyConsultant": false,
     *         "embargoedResidents": "Yes",
     *         "proposedRole": "Partner"
     *     },
     *     { // Deletes a ddqKeyPerson rec where id = 12324, and matching clientID/ddqID
     *         "id": 12324
     *         "remove": true,
     *     }
     * ]}
     *
     * Wipes out all ddqKeyPerson recs for the ddqID/clientID combo:
     * {"questionID": "keyPersons", "response": "empty"}
     *
     * @param integer $id       If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $question Question configuration
     * @param mixed   $response String/integer response supplied for question
     * @param boolean $isLegacy True if using legacy schema
     *
     * @return array
     */
    private function validateElementsSetKeyPersons($id, $question, mixed $response, $isLegacy)
    {
        $rtn = ['errorType' => '', 'data' => []];
        $keyPersonsModel = new InFormRspnsKeyPersons(
            $this->clientID,
            ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID, 'overrides' => $this->overrides]
        );
        $newPeople = [];
        $existingPeople = $keyPersonsModel->getPeopleByDdqIDAndQuestionID($id, $question['id']);
        if (!empty($response) && (!is_string($response) || ($response !== 'empty'
            && !($newPeople = \Xtra::validateJSON($response))))
        ) {
            $rtn = [
                'errorType' => 'questionsWithInvalidElementsSet',
                'data' => [$question['id'] => 'malformed JSON']
            ];
        } elseif ($response === 'empty') {
            if (!$existingPeople) {
                $rtn = [
                    'errorType' => 'questionsWithEmptyElementsSetCannotBeEmptied',
                    'data' => ['questionID' => $question['id']]
                ];
            }
        } else {
            if (!empty($this->overrides)) {
                $overrideType = str_replace('keyPersons_', '', (string) $question['id']);
                $newPeopleOverrides = [];
                foreach ($newPeople as $idx => $newPerson) {
                    $newPeopleOverrides[] = $newPerson + ['overrideType' => $overrideType];
                }
                $newPeople = $newPeopleOverrides;
            }
            $rtn = $keyPersonsModel->validatePeople(
                $id,
                $question['id'],
                $existingPeople,
                $newPeople,
                $this->postProcessing
            );
            if (!empty($this->overrides)) {
                $peopleTally = $rtn['data'][$question['id']]['peopleTally'];
                $this->overrides['keyPersons']['peopleTally'][$overrideType] = $peopleTally;
                unset($rtn['data'][$question['id']]['peopleTally']);
                if (isset($rtn['data'][$question['id']]['ownerPercentageTally'])) {
                    // Track ownerPercentage across all keyPersons elementsSets
                    $ownerPercentageTally = $rtn['data'][$question['id']]['ownerPercentageTally'];
                    $this->overrides['keyPersons']['ownerPercentage'] = $ownerPercentageTally;
                    unset($rtn['data'][$question['id']]['ownerPercentageTally']);
                }
            }
        }
        return $rtn;
    }

    /**
     * Validate parent question response
     *
     * @param integer $id              If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $parentQuestion  Parent question configuration
     * @param mixed   $parentResponse  Response to child question
     * @param array   $responses       Section responses
     * @param mixed   $childQuestionID Child question ID
     * @param string  $childQuestion   Child question configuration
     * @param boolean $isLegacy        True if using legacy schema
     *
     * @return array
     */
    private function validateParentResponse(
        $id,
        $parentQuestion,
        mixed $parentResponse,
        $responses,
        mixed $childQuestionID,
        $childQuestion,
        $isLegacy
    ) {
        $rtn = ['responseIdToWipe' => 0, 'errorType' => '', 'data' => []];
        $result = $this->validateResponse($id, $parentQuestion, $parentResponse, $isLegacy);
        if ($result === true) {
            $incompatibleCfg = [
                'childQuestion' => $childQuestion,
                'parentResponse' => $parentResponse,
                'isLegacy' => $isLegacy
            ];
            if (!isset($responses[$childQuestionID])) {
                // Child response not in responses param
                // Check DB for child response and flag if not compatible with parent
                $child = ($isLegacy)
                    ? $this->getDdqResponse($id, $childQuestionID, $parentQuestion['languageCode'])
                    : $this->getQuestionInstanceResponse($id, $childQuestionID);
                if ($child) {
                    $incompatibleCfg['childResponse'] = $child['response'];
                    if ($incompatible = $this->validateChildResponseIncompatibility($incompatibleCfg)) {
                        // Don't return error, and set the child's response to be wiped from the DB.
                        $rtn['responseIdToWipe'] = $child['id'];
                    }
                }
            }
        } else {
            $rtn['errorType'] = $result;
        }
        return $rtn;
    }

    /**
     * Validate that the response supplied for a question is not missing while required,
     * or else invalid for a select, checkbox or radio element.
     *
     * @param integer $id             If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $question       Question configuration
     * @param mixed   $response       String/integer response supplied for question
     * @param boolean $isLegacy       True if using legacy schema
     * @param mixed   $parentResponse String/integer response supplied for parent question in responses param
     *
     * @return mixed Either true boolean if valid, else string if error
     */
    public function validateResponse($id, $question, mixed $response, $isLegacy = false, mixed $parentResponse = null)
    {
        $cfg = [];
        if (isset($question['cfg']) && (is_string($question['cfg']) || is_array($question['cfg']))) {
            $cfg = (is_string($question['cfg'])) ? json_decode($question['cfg'], true) : $question['cfg'];
        }
        $languageCode = $question['languageCode'];
        if (!in_array($question['element'], ['tabularData', 'elementsSet'])) {
            $question = ($isLegacy)
                ? $this->intakeFormQuestionsApiData->formatOQ($question, [], true)
                : $this->intakeFormQuestionsApiData->formatIntakeFormQuestion($question, null, true);
        }
        $element = $question['element'];

        // Exempt Accept textbox from validation, as it's being handled in the submission.
        $isAcceptTextbox = (!empty($cfg) && isset($cfg['format']) && $cfg['format'] == 'accept');
        if (in_array($element, ['paragraph', 'tabularData'])) {
            $rtn = 'questionsShouldNotGetAResponse';
        } elseif ($element == 'elementsSet') {
            $keyPersonsElementsSets = $this->getElementsSetsByType('keyPersons');
            if ($question['id'] === 'countriesWhereBusinessPracticed') {
                $rtn = $this->validateElementsSetCountries($id, $question, $response, $isLegacy);
            } elseif ($question['id'] == 'companies') {
                $rtn = $this->validateElementsSetCompanies($id, $question, $response, $isLegacy);
            } elseif (in_array($question['id'], $keyPersonsElementsSets)) {
                $rtn = $this->validateElementsSetKeyPersons($id, $question, $response, $isLegacy);
            }
        } else {
            $required = (isset($question['required']) && $question['required'] === true);
            $questionsMissingResponse = (empty($response));
            $rtn = true;
            $acceptableResponses = [];
            $acceptableFormat = '';
            $needsParentResponse = false;
            $childWithInvalidParentResponse = false;
            if ($element == 'checkbox') {
                $acceptableResponses[] = $question['checkedValue'];
                $acceptableResponses[] = $question['uncheckedValue'];
            } elseif (in_array($element, ['radio', 'select'])) {
                if (!is_null($parentResponse) || (!empty($cfg) && !empty($cfg['parentQuestionID']))) {
                    if (is_null($parentResponse) && (!empty($cfg) && !empty($cfg['parentQuestionID']))) {
                        // Attempt to get parent response from DB
                        if ($isLegacy
                            && ($parent = $this->getDdqResponse($id, $cfg['parentQuestionID'], $languageCode))
                        ) {
                            $parentResponse = $parent['response'];
                        } elseif (!$isLegacy
                            && $parent = $this->getQuestionInstanceResponse($id, $cfg['parentQuestionID'])
                        ) {
                            $parentResponse = $parent['response'];
                        }
                    }
                    if ($parentResponse) {
                        // With parent response, reload the child element for new options
                        $question = ($isLegacy)
                            ? $this->intakeFormQuestionsApiData->formatOQ($question, [$parentResponse], true)
                            : $this->intakeFormQuestionsApiData->formatIntakeFormQuestion($question, $parentResponse, true);
                    } else {
                        $needsParentResponse = true;
                    }
                }
                if (!$needsParentResponse && empty($question['options'])) {
                    $childWithInvalidParentResponse = true;
                } else {
                    foreach ($question['options'] as $option) {
                        $acceptableResponses[] = $option['value'];
                    }
                }
            } elseif ($element == 'textbox') {
                if (isset($question['validateBy'])) {
                    if ($question['validateBy'] == 'format' && isset($question['validationFormat'])) {
                        $acceptableFormat = $question['validationFormat'];
                    } elseif ($question['validateBy'] == 'value' && isset($question['validationValue'])) {
                        $acceptableResponses[] = $question['validationValue'];
                    }
                }
            }
            if ($childWithInvalidParentResponse) {
                $rtn = 'questionsWithInvalidParentResponse';
            } elseif ($required && $questionsMissingResponse && !in_array('', $acceptableResponses)) {
                $rtn = 'questionsMissingResponse';
            } elseif (!empty($acceptableResponses) && !empty($response)
                && !in_array($response, $acceptableResponses) && !$isAcceptTextbox
            ) {
                $rtn = 'questionsWithInvalidResponse';
            } elseif ($needsParentResponse) {
                $rtn = 'questionsWithMissingParentResponse';
            } elseif (!empty($acceptableFormat)) {
                if ($acceptableFormat == 'date'
                    && !\DateTime::createFromFormat('Y-m-d', $response)
                ) {
                    $rtn = 'questionsWithInvalidResponseDateFormat';
                } elseif ($acceptableFormat == 'datetime'
                    && !\DateTime::createFromFormat('Y-m-d H:i', $response)
                    && !\DateTime::createFromFormat('Y-m-d H:i:s', $response)
                ) {
                    $rtn = 'questionsWithInvalidResponseDateTimeFormat';
                } elseif ($acceptableFormat == 'email'
                    && !filter_var(html_entity_decode((string) $response, ENT_QUOTES), FILTER_VALIDATE_EMAIL)
                ) {
                    $rtn = 'questionsWithInvalidResponseEmailFormat';
                }
            } else {
                $rtn = true;
            }
        }
        return $rtn;
    }

    /**
     * Validate that the supplied questions have been properly answered
     *
     * @param integer $id        If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $questions Section questions
     * @param array   $responses Section responses
     * @param boolean $isLegacy  True if using legacy schema
     *
     * @return array
     */
    public function validateResponses($id, $questions, $responses, $isLegacy = false)
    {
        if (!$questions) {
            return 'None of the supplied responses are paired with existing questionID\'s.';
        } elseif (empty($responses)) {
            return 'Supplied responses are missing.';
        } elseif (!$this->responsesAreValidlyFormatted($responses)) {
            return 'Supplied responses are invalid.';
        }
        $questionIDsResponses = $questionResponseData = [];
        $responseIdsToWipe = $responsesToSave = $responsesToWipe = $questionsWithResponsesSaved = [];
        $errors = $sections = $rtn = [];
        foreach (self::VALIDATION_ERRORS as $errorType) {
            $errors[$errorType] = [];
        }
        foreach ($responses as $response) { // Convert responses to a key-value array
            $questionIDsResponses[$response['questionID']] = $response['response'];
        }
        // Validate responses, and sort the valid from the invalid
        foreach ($questionIDsResponses as $questionID => $response) {
            $validQuestion = false;
            foreach ($questions as $section => $sectionQuestions) {
                if (isset($sectionQuestions[$questionID])) {
                    $validQuestion = true;
                    $parentQuestionID = $childQuestionID = null;
                    $question = $sectionQuestions[$questionID];
                    $resultData = $dataToUpdate = [];
                    if (isset($question['cfg']) && (is_string($question['cfg']) || is_array($question['cfg']))) {
                        $cfg = (is_string($question['cfg'])) ? json_decode($question['cfg'], true) : $question['cfg'];
                        if (!empty($cfg['parentQuestionID']) && isset($sectionQuestions[$cfg['parentQuestionID']])) {
                            // This question has been configured to be paired with a parent question.
                            $parentQuestionID = $cfg['parentQuestionID'];
                            $parentQuestion = $sectionQuestions[$parentQuestionID];
                            $childValidation = $this->validateChildResponse(
                                $id,
                                $question,
                                $response,
                                $questionIDsResponses,
                                $parentQuestionID,
                                $parentQuestion,
                                $isLegacy
                            );
                            $result = (!empty($childValidation['errorType'])) ? $childValidation['errorType'] : true;
                            $resultData = (!empty($childValidation['data'])) ? $childValidation['data'] : [];
                        } elseif (!empty($cfg['childQuestionID'])
                            && isset($sectionQuestions[$cfg['childQuestionID']])
                        ) {
                            // This question has been configured to be paired with a child question.
                            $childQuestionID = $cfg['childQuestionID'];
                            $childQuestion = $sectionQuestions[$childQuestionID];
                            $parentValidation = $this->validateParentResponse(
                                $id,
                                $question,
                                $response,
                                $questionIDsResponses,
                                $childQuestionID,
                                $childQuestion,
                                $isLegacy
                            );
                            $result = (!empty($parentValidation['errorType'])) ? $parentValidation['errorType'] : true;
                            $resultData = (!empty($parentValidation['data'])) ? $parentValidation['data'] : [];
                            if (!empty($parentValidation['responseIdToWipe'])) {
                                $responseIdsToWipe[] = $parentValidation['responseIdToWipe'];
                            }
                        }
                    }
                    if (is_null($parentQuestionID) && is_null($childQuestionID)) {
                        $result = $this->validateResponse($id, $question, $response, $isLegacy);
                        if (is_array($result) && isset($result['errorType'])) {
                            $dataToUpdate = $result['dataToUpdate'] ?? [];
                            $resultData = $result['data'] ?? [];
                            $result = (!empty($result['errorType'])) ? $result['errorType'] : true;
                        }
                    }
                    $processed = $this->processValidationResult(
                        $id,
                        $question,
                        $sectionQuestions,
                        $questionIDsResponses,
                        $result,
                        $resultData,
                        $dataToUpdate,
                        $section,
                        $sections,
                        $questionResponseData,
                        $errors
                    );
                    $errors = $processed['errors'];
                    $sections = $processed['sections'];
                    $questionResponseData = $processed['questionResponseData'];
                }
            }
            if (!$validQuestion) {
                $errors['questionsInvalid'][] = $questionID;
            }
        }
        if (!empty($this->overrides) && !empty($this->overrides['keyPersons'])
            && !empty($this->overrides['keyPersons']['peopleTally'])
        ) {
            $keyPersonsModel = new InFormRspnsKeyPersons(
                $this->clientID,
                ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID, 'overrides' => $this->overrides]
            );
            $tally = $keyPersonsModel->tallyUpPeople($id, $this->overrides['keyPersons']['peopleTally']);
            $limit = $keyPersonsModel::KEY_PERSONS_LIMIT;
            if ($tally > $keyPersonsModel::KEY_PERSONS_LIMIT) {
                $questionIDs = '';
                foreach ($this->overrides['keyPersons']['peopleTally'] as $overrideType => $people) {
                    $questionID = "keyPersons_{$overrideType}";
                    $errors['questionsInvalid'][] = $questionID;
                    $personnelSection = $this->intakeFormQuestionsApiData->getSectionNameByID(3);
                    unset($questionResponseData[$personnelSection][$questionID]);
                    $questionIDs .= ((!empty($questionIDs)) ? ', ' : '') . $questionID;
                }
                $errors['numberOfKeyPeopleExceeded'] = "The tally of {$tally} key people resulting from submitted "
                    . "changes exceeds the limit of {$limit}. No changes will be saved for the following "
                    . "questionID's: {$questionIDs}";
            }
        }
        foreach ($sections as $section) {
            foreach ($questionResponseData[$section] as $questionID => $questionResponse) {
                if (array_key_exists($section, $responsesToSave)
                    && array_key_exists($questionID, $responsesToSave[$section])
                ) {
                    // Redundant mapping of a question per section
                    return "Intake form responses cannot be processed due to a misconfiguration with "
                        . "multiple mappings of question #{$questionID} for "
                        . (($isLegacy) ? 'ddqID' : 'intakeFormInstanceID') . " #{$id}";
                } else {
                    $responsesToSave = $this->responseToSaveAccumulation(
                        $responsesToSave,
                        $section,
                        $questions[$section][$questionID],
                        $questionResponse['response'],
                        $isLegacy
                    );
                }
            }
        }
        if (!empty($responsesToSave)) {
            foreach ($responsesToSave as $section) {
                foreach ($section as $questionID => $response) {
                    if (array_key_exists('data', $rtn) && array_key_exists($questionID, $rtn['data'])) {
                        // Redundant mapping of a question across all sections
                        return "Intake form responses cannot be processed due to a misconfiguration with "
                            . "multiple mappings of question #{$questionID} for "
                            . (($isLegacy) ? 'ddqID' : 'intakeFormInstanceID') . " #{$id}";
                    }
                    if ($formattedResponse = $this->responseToSaveFormat($id, $questionID, $response, $isLegacy)) {
                        $questionsWithResponsesSaved[] = $questionID;
                        $rtn['data'][$questionID] = $formattedResponse;
                    }
                }
            }
        }
        if (!empty($responseIdsToWipe)) {
            $rtn['responseIdsToWipe'] = $responseIdsToWipe;
        }
        $rtn = $this->processErrors($rtn, $errors, $questionsWithResponsesSaved);
        return $rtn;
    }

    /**
     * Validate that a section's required questions have been properly answered
     *
     * @param integer $id        If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $questions Section questions
     * @param boolean $isLegacy  True if using legacy schema
     *
     * @return array
     */
    private function validateSectionResponses($id, $questions, $isLegacy = false)
    {
        $rtn = [];
        $id = (int)$id;
        $exclude = ['paragraph'];
        if (empty($id) || empty($questions)) { // Nothing to validate
            return $rtn;
        }
        foreach ($questions as $questionID => $question) {
            if (!in_array($question['element'], $exclude)) {
                $response = $question['response'] ?? '';
                if (($result = $this->validateResponse($id, $question, $response, $isLegacy))
                    && (((is_string($result) && ($errorType = $result))
                    || (is_array($result) && !empty($result['errorType']) && ($errorType = $result['errorType'])))
                    && in_array($errorType, self::VALIDATION_ERRORS))
                ) {
                    if (is_array($result) && !empty($result['data'])) {
                        $rtn[$errorType][] = $result['data'];
                    } else {
                        $rtn[$errorType][] = $questionID;
                    }
                }
            }
        }
        return $rtn;
    }

    /**
     * Validates whether or not the intake form is submittable
     *
     * @param integer $id        If isLegacy then ddq.id else intakeFormInstances.id
     * @param array   $questions Questions for an intake form with responses from the DB
     * @param boolean $isLegacy  True if using legacy schema
     */
    public function validateSubmittable($id, $questions, $isLegacy = false)
    {
        $this->togglePostProcessing($id);
        $errors = [];
        $submittable = true;
        foreach (IntakeFormQuestionsApiData::SECTIONS as $section) {
            if (!isset($questions[$section])) {
                continue; // Section not configured for intake form
            }
            $method = (!empty($methods['validation'])) ? $methods['validation'] : '';
            $errors[$section] = $this->validateSectionResponses($id, $questions[$section], $isLegacy);
            if (is_array($errors[$section]) && !empty($errors[$section])) {
                $submittable = false;
            }
        }
        $rtn = ['submittable' => $submittable];
        if (!$submittable) {
            $rtn['submissionBlockingErrors'] = $errors;
        }
        $this->togglePostProcessing($id);
        return $rtn;
    }
}
