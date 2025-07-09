<?php
/**
 *  IntakeFormQuestions
 *
 * @keywords intake, form, questions
 *
 */

namespace Models\TPM\IntakeForms;

use Models\API\Endpoints\IntakeFormQuestions as IntakeFormQuestionsApiData;
use Models\Ddq;
use Models\TPM\IntakeForms\IntakeFormResponses;

/**
 * Intake form questions (previously onlineQuestions)
 */
#[\AllowDynamicProperties]
class IntakeFormQuestions
{
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
    }

    /**
     * Get all intakeFormQuestions rows by clientID and intakeFormCfgID.
     * Providing $instancesID will include responses.
     *
     * @param integer $intakeFormCfgID intakeFormQuestions.intakeFormCfgID
     * @param string  $languageCode    intakeFormQuestions.languageCode
     * @param integer $instancesID     intakeFormResponses.instancesID
     *
     * @return array
     */
    public function getAllQuestions($intakeFormCfgID, $languageCode = 'EN_US', $instancesID = null)
    {
        $whereConditions = ['q.intakeFormCfgID = :intakeFormCfgID', 'q.sectionsID = :sectionID'];
        $params = [':intakeFormCfgID' => $intakeFormCfgID];
        return $this->select($intakeFormCfgID, $languageCode, $instancesID, $whereConditions, $params, true, true);
    }

    /**
     * Returns a key-value list of intake form question element id's/names
     *
     * @return array
     */
    public function getElements()
    {
        return IntakeFormQuestionsApiData::ELEMENTS;
    }

    /**
     * Get intakeFormQuestions row by id. Providing $instancesID will include responses.
     *
     * @param integer $id              intakeFormQuestions.id
     * @param integer $intakeFormCfgID intakeFormQuestions.intakeFormCfgID
     * @param integer $languageCode    intakeFormQuestions.languageCode
     * @param integer $instancesID     intakeFormResponses.instancesID
     *
     * @return array
     */
    public function getExtension($id, $intakeFormCfgID, $languageCode = 'EN_US', $instancesID = null)
    {
        $rtn = [];
        $id = (int)$id;
        $intakeFormCfgID = (int)$intakeFormCfgID;
        if (!empty($id) && !empty($intakeFormCfgID)) {
            $whereConditions = ['q.id = :id', 'q.responseTable = :responseTable'];
            $params = [':id' => $id, ':responseTable' => 'intakeFormResponses'];
            $rtn = $this->select($intakeFormCfgID, $languageCode, $instancesID, $whereConditions, $params);
        }
        return $rtn;
    }

    /**
     * Get intakeFormQuestions extension rows by clientID and intakeFormCfgID.
     * Providing $instancesID will include responses.
     *
     * @param integer $intakeFormCfgID intakeFormQuestions.intakeFormCfgID
     * @param string  $languageCode    intakeFormQuestions.languageCode
     * @param integer $instancesID     intakeFormResponses.instancesID
     *
     * @return array
     */
    public function getExtensions($intakeFormCfgID, $languageCode = 'EN_US', $instancesID = null)
    {
        $whereConditions = [
            'q.intakeFormCfgID = :intakeFormCfgID',
            'q.responseTable = :responseTable',
            'q.sectionsID = :sectionID'
        ];
        $params = [':intakeFormCfgID' => $intakeFormCfgID, ':responseTable' => 'intakeFormResponses'];
        return $this->select($intakeFormCfgID, $languageCode, $instancesID, $whereConditions, $params, true, true);
    }

    /**
     * Get intakeFormQuestions extension rows by clientID and intakeFormCfgID.
     * Providing $instancesID will include responses.
     *
     * @param integer $sectionID       intakeFormQuestions.sectionID
     * @param integer $intakeFormCfgID intakeFormQuestions.intakeFormCfgID
     * @param string  $languageCode    intakeFormQuestions.languageCode
     * @param integer $instancesID     intakeFormResponses.instancesID
     *
     * @return array
     */
    public function getExtensionsBySectionID($sectionID, $intakeFormCfgID, $languageCode = 'EN_US', $instancesID = null)
    {
        $rtn = [];
        $sectionID = (int)$sectionID;
        $intakeFormCfgID = (int)$intakeFormCfgID;
        if (!empty($sectionID) && !empty($intakeFormCfgID)) {
            $whereConditions = [
                'q.intakeFormCfgID = :intakeFormCfgID',
                'q.responseTable = :responseTable',
                'q.sectionsID = :sectionID'
            ];
            $params = [
                ':intakeFormCfgID' => $intakeFormCfgID,
                ':responseTable' => 'intakeFormResponses',
                ':sectionID' => $sectionID
            ];
            $rtn = $this->select($intakeFormCfgID, $languageCode, $instancesID, $whereConditions, $params, true);
        }
        return $rtn;
    }

    /**
     * Returns a key-value list of intake form question section id's/names
     *
     * @return array
     */
    public function getSections()
    {
        return IntakeFormQuestionsApiData::SECTIONS;
    }

    /**
     * Returns a key-value list of intake form question section id's/names
     *
     * @return array
     */
    public function getSelectElementSources()
    {
        return IntakeFormQuestionsApiData::SELECT_ELEMENT_SOURCES;
    }

    /**
     * Returns a key-value list of intake form question statuses id's/names
     *
     * @return array
     */
    public function getStatuses()
    {
        return IntakeFormQuestionsApiData::STATUSES;
    }

    /**
     * Get specified values for an intakeFormQuestions record by id
     *
     * @param integer $id intakeFormQuestions.id
     *
     * @return array
     */
    public function getQuestionByID($id)
    {
        $rtn = [];
        $id = (int)$id;
        if (!empty($id)) {
            $sql = "SELECT id, intakeFormCfgID, sectionsID, elementsID, statusesID, `sequence`, cfg, active\n"
                . "FROM {$this->clientDB}.intakeFormQuestions WHERE id = :id";
            if ($row = $this->DB->fetchAssocRow($sql, [':id' => $id])) {
                $element = IntakeFormQuestionsApiData::ELEMENTS[$row['elementsID']];
                $labels = $this->getQuestionTextConfig($id, $element);
                if ($element == 'paragraph') {
                    $row['cfg'] = $labels;
                } else {
                    $row['elementLabels'] = $labels;
                    if (!empty($row['cfg'])) {
                        $row['cfg'] = $this->unpackQuestionCfg($id, $element, $row['cfg']);
                    }
                }
                $rtn = $row;
            }
        }
        return $rtn;
    }

    /**
     * Returns question text config
     *
     * @param integer $intakeFormQuestionsID intakeFormQuestions.id
     * @param string  $element               Element name
     * @param string  $elementAttribute      If not null, either acceptResponse, checkboxLabel or optionValue
     *
     * @return array
     */
    public function getQuestionTextConfig($intakeFormQuestionsID, $element, $elementAttribute = null)
    {
        $rtn = [];
        $intakeFormQuestionsID = (int)$intakeFormQuestionsID;
        $elements = ['paragraph', 'checkbox', 'textarea', 'select', 'radio', 'textbox'];
        $sql = "SELECT * FROM {$this->clientDB}.intakeFormQuestionsText\n"
            . "WHERE intakeFormQuestionsID = :intakeFormQuestionsID";
        $params = [':intakeFormQuestionsID' => $intakeFormQuestionsID];
        if (!empty($intakeFormQuestionsID) && in_array($element, $elements)
            && ($rows = $this->DB->fetchAssocRows($sql, $params))
        ) {
            foreach ($rows as $row) {
                if ($element == 'textbox' && $elementAttribute == 'acceptResponse') {
                    $rtn[] = ['languageCode' => $row['languageCode'], 'text' => $row['acceptResponse']];
                } elseif ($element == 'checkbox' && $elementAttribute == 'checkboxLabel') {
                    $rtn[] = ['languageCode' => $row['languageCode'], 'text' => $row['checkboxLabel']];
                } elseif ($element == 'radio' && $elementAttribute == 'optionValue' && !is_null($row['optionValue'])) {
                    $rtn[] = [
                        'value' => $row['optionValue'],
                        'languageCode' => $row['languageCode'],
                        'text' => $row['text']
                    ];
                } elseif (empty($elementAttribute) && is_null($row['optionValue'])) {
                    $rtn[] = ['languageCode' => $row['languageCode'], 'text' => $row['text']];
                }
            }
        }
        return $rtn;
    }

    /**
     * Insert intakeFormQuestions record
     *
     * @param array $cfg intakeFormQuestions configuration
     *
     * @return id
     */
    public function insert($cfg)
    {
        $rtn = 0;
        $potentiallyUnconfiguredElements = ['file', 'textarea', 'textbox'];
        $unlabeledElements = ['paragraph'];
        if (!empty($cfg)
            && isset($cfg['active'])
            && isset($cfg['intakeFormCfgID'])
            && isset($cfg['sectionsID'])
            && isset($cfg['elementsID'])
            && isset($cfg['statusesID'])
            && isset($cfg['sequence'])
            && ($element = IntakeFormQuestionsApiData::ELEMENTS[$cfg['elementsID']])
            && (in_array($element, $potentiallyUnconfiguredElements) || isset($cfg['cfg']))
            && (in_array($element, $unlabeledElements) || isset($cfg['elementLabels']))
        ) {
            $sql = "INSERT INTO {$this->clientDB}.intakeFormQuestions SET\n"
                . "intakeFormCfgID = :intakeFormCfgID, "
                . "sectionsID = :sectionsID, "
                . "elementsID = :elementsID, "
                . "statusesID = :statusesID, "
                . "sequence = :sequence, "
                . "cfg = :cfg, "
                . "active = :active, "
                . "created = :created";
            $params = [
                ':intakeFormCfgID' => $cfg['intakeFormCfgID'],
                ':sectionsID' => $cfg['sectionsID'],
                ':elementsID' => $cfg['elementsID'],
                ':statusesID' => $cfg['statusesID'],
                ':sequence' => $cfg['sequence'],
                ':cfg' => null,
                ':active' => $cfg['active'],
                ':created' => date('Y-m-d H:i:s')
            ];
            if (!in_array($element, ['paragraph', 'file', 'textarea']) && !empty($cfg['cfg'])) {
                $params[':cfg'] = $this->packUpQuestionConfig($element, $cfg);
            }
            $this->DB->query($sql, $params);
            $newQuestionID = $this->DB->lastInsertId();
            $this->insertQuestionText($this->setQuestionTextConfig($newQuestionID, $element, $cfg));
            $rtn = $newQuestionID;
        }
        return $rtn;
    }

    /**
     * Insert intakeFormQuestionsText record(s)
     *
     * @param array   $cfg                   intakeFormQuestions configuration
     * @param boolean $wipeRecsForQuestionID If true, delete all intakeFormQuestionsText recs prior to the INSERT
     *
     * @return void
     */
    public function insertQuestionText($cfg, $wipeRecsForQuestionID = false)
    {
        if (!empty($cfg) && !empty($cfg['intakeFormQuestionsID']) && !empty($cfg['text'])) {
            if ($wipeRecsForQuestionID) {
                $sql = "DELETE FROM {$this->clientDB}.intakeFormQuestionsText\n"
                    . "WHERE intakeFormQuestionsID = :intakeFormQuestionsID";
                $this->DB->query($sql, [':intakeFormQuestionsID' => $cfg['intakeFormQuestionsID']]);
            }
            $sql = "INSERT INTO {$this->clientDB}.intakeFormQuestionsText SET\n"
                . "intakeFormQuestionsID = :intakeFormQuestionsID, "
                . "optionValue = :optionValue, "
                . "acceptResponse = :acceptResponse, "
                . "checkboxLabel = :checkboxLabel, "
                . "text = :text, "
                . "languageCode = :languageCode";
            $params = [
                ':intakeFormQuestionsID' => $cfg['intakeFormQuestionsID'],
                ':optionValue' => null,
                ':acceptResponse' => null,
                ':checkboxLabel' => null,
                ':text' => null,
                ':languageCode' => null
            ];
            foreach ($cfg['text'] as $row) {
                $params[':text'] = $row['text'];
                $params[':languageCode'] = $row['languageCode'];
                if (isset($row['optionValue'])) {
                    $params[':optionValue'] = $row['optionValue'];
                }
                if (isset($row['acceptResponse'])) {
                    $params[':acceptResponse'] = $row['acceptResponse'];
                }
                if (isset($row['checkboxLabel'])) {
                    $params[':checkboxLabel'] = $row['checkboxLabel'];
                }
                $this->DB->query($sql, $params);
            }
        }
    }

    /**
     * Returns a sanitized and JSON-encoded question config
     *
     * @param string $element Element name
     * @param array  $cfg     intakeFormQuestions config
     *
     * @return string
     */
    public function packUpQuestionConfig($element, $cfg)
    {
        if ($element == 'textbox' && !empty($cfg['cfg'][0]['format'])
            && $cfg['cfg'][0]['format'] == 'accept'
            && !empty($cfg['cfg'][0]['acceptResponses'])
        ) {
            unset($cfg['cfg'][0]['acceptResponses']);
        } elseif ($element == 'radio') {
            unset($cfg['cfg'][0]['optionsLabels']);
        } elseif ($element == 'checkbox' && isset($cfg['cfg'][0]['checkboxLabels'])) {
            unset($cfg['cfg'][0]['checkboxLabels']);
        }
        return json_encode($cfg['cfg']);
    }

    /**
     * Determines whether or not an existing question is reconfigurable
     *
     * @param string $element          Element name
     * @param array  $cfg              intakeFormQuestions config
     * @param array  $originalQuestion Original question config
     *
     * @return boolean
     */
    private function questionIsReconfigurable($element, $cfg, $originalQuestion)
    {
        $rtn = false;
        if (in_array($element, ['radio']) && isset($cfg['cfg'][0]['options'])
            && ($newOptions = $cfg['cfg'][0]['options'])
        ) {
            // Only radio elements are reconfigurable, so long as the new config retains original values
            // and contains at least one new value.
            if (isset($originalQuestion['cfg'][0]['options'])
                && ($originalOptions = $originalQuestion['cfg'][0]['options'])
            ) {
                $anyNewValues = false;
                $anyValuesNotRetained = false;
                foreach ($originalOptions as $originalOption) {
                    $valueRetained = false;
                    foreach ($newOptions as $newOption) {
                        if ($originalOption['value'] === $newOption['value']) {
                            $valueRetained = true;
                        }
                    }
                    if ($valueRetained === false) {
                        $anyValuesNotRetained = true;
                        break;
                    }
                }
                foreach ($newOptions as $newOption) {
                    $newValueExists = false;
                    foreach ($originalOptions as $originalOption) {
                        if ($originalOption['value'] === $newOption['value']) {
                            $newValueExists = true;
                        }
                    }
                    if ($newValueExists === false) {
                        $anyNewValues = true;
                        break;
                    }
                }
                if ($anyValuesNotRetained === false && $anyNewValues === true) {
                    $rtn = true;
                }
            }
        }
        return $rtn;
    }

    /**
     * Select intake form questions
     *
     * @param integer $intakeFormCfgID    intakeFormQuestions.intakeFormCfgID
     * @param integer $languageCode       intakeFormQuestions.languageCode
     * @param integer $instancesID        intakeFormResponses.instancesID
     * @param array   $whereConditions    Additional where clauses to append to standard where clause
     * @param array   $params             Parameterized values for query
     * @param boolean $multipleRows       If true, return multiple rows else a single row
     * @param boolean $groupRowsBySection If true, group rows by section else don't
     *
     * @return array
     */
    private function select(
        $intakeFormCfgID,
        $languageCode,
        $instancesID,
        $whereConditions,
        $params,
        $multipleRows = false,
        $groupRowsBySection = false
    ) {
        $rtn = [];
        $responseClause = '';
        $intakeFormCfgID = (int)$intakeFormCfgID;
        if (!empty($intakeFormCfgID)) {
            $legacyID = $this->DB->fetchValue(
                "SELECT legacyID FROM {$this->clientDB}.ddqName WHERE id = :id",
                [':id' => $intakeFormCfgID]
            );
            if ($legacyID) {
                $ddq = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
                $splitLegacyID = $ddq->splitDdqLegacyID($legacyID);
                $intakeFormTypeID = $splitLegacyID['caseType'];
                $versionClause = (!empty($splitLegacyID['ddqQuestionVer']))
                    ? $splitLegacyID['ddqQuestionVer'] . ' AS intakeFormVersion, '
                    : '';
                $where = array_merge(['q.active = 1', 'qt.languageCode = :languageCode'], $whereConditions);
                $params[':languageCode'] = $languageCode;
                $responseClause = '';
                if (!is_null($instancesID) && !empty($instancesID)) {
                    $responseClause = ", "
                        . "(SELECT id FROM  {$this->clientDB}.intakeFormResponses "
                        . "WHERE questionsID = q.id AND instancesID = :instancesID1) AS responseID, "
                        . "(SELECT response FROM  {$this->clientDB}.intakeFormResponses "
                        . "WHERE questionsID = q.id AND instancesID = :instancesID2) AS response";
                    $params[':instancesID1'] = $instancesID;
                    $params[':instancesID2'] = $instancesID;
                }
                $sql = "SELECT q.id, {$intakeFormTypeID} AS intakeFormTypeID, q.intakeFormCfgID, {$versionClause}"
                    . "q.elementsID, q.sectionsID, q.responseTable, q.responseTableColumn, qt.text AS elementLabel, "
                    . "qt.acceptResponse, qt.checkboxLabel, qt.languageCode, q.statusesID, q.sequence, q.cfg, "
                    . "q.onlineQuestionsID{$responseClause}\n"
                    . "FROM {$this->clientDB}.intakeFormQuestions AS q\n"
                    . "LEFT JOIN {$this->clientDB}.ddqName AS d ON d.id = q.intakeFormCfgID\n"
                    . "LEFT JOIN {$this->clientDB}.intakeFormQuestionsText AS qt ON qt.intakeFormQuestionsID = q.id\n"
                    . "WHERE " . implode(' AND ', $where) . "\n"
                    . "GROUP BY q.id ORDER BY q.sectionsID, q.sequence ASC";
                if ($multipleRows) {
                    if ($groupRowsBySection) {
                        foreach (IntakeFormQuestionsApiData::SECTIONS as $id => $name) {
                            $params[':sectionID'] = $id;
                            if ($sectionRows = $this->DB->fetchAssocRows($sql, $params)) {
                                foreach ($sectionRows as $row) {
                                    $row['element'] = IntakeFormQuestionsApiData::ELEMENTS[$row['elementsID']];
                                    $rtn[$name][$row['id']] = $row;
                                }
                            }
                        }
                    } elseif ($rows = $this->DB->fetchAssocRows($sql, $params)) {
                        foreach ($rows as $row) {
                            $row['element'] = IntakeFormQuestionsApiData::ELEMENTS[$row['elementsID']];
                            $rtn[] = $row;
                        }
                    }
                } elseif ($row = $this->DB->fetchAssocRow($sql, $params)) {
                    $row['element'] = IntakeFormQuestionsApiData::ELEMENTS[$row['elementsID']];
                    $rtn = $row;
                }
            }
        }
        return $rtn;
    }

    /**
     * Returns a config array for the insertion of a intakeFormQuestionsText record
     *
     * @param integer $intakeFormQuestionsID intakeFormQuestionsText.intakeFormQuestionsID
     * @param string  $element               Element name
     * @param array   $cfg                   intakeFormQuestions config
     *
     * @return array
     */
    private function setQuestionTextConfig($intakeFormQuestionsID, $element, $cfg)
    {
        $rtn = [
            'intakeFormQuestionsID' => $intakeFormQuestionsID,
            'text' => (($element == 'paragraph') ? $cfg['cfg'] : $cfg['elementLabels'])
        ];
        if ($element == 'textbox' && !empty($cfg['cfg'][0]['format'])
            && $cfg['cfg'][0]['format'] == 'accept'
            && !empty($cfg['cfg'][0]['acceptResponses'])
        ) {
            // Prior validation ensures that acceptResponses and elementLabels have matching languageCodes
            foreach ($cfg['cfg'][0]['acceptResponses'] as $acceptResponse) {
                foreach ($rtn['text'] as $idx => $text) {
                    if ($text['languageCode'] == $acceptResponse['languageCode']) {
                        $rtn['text'][$idx]['acceptResponse'] = $acceptResponse['text'];
                    }
                }
            }
        } elseif ($element == 'radio') {
            // Prior validation ensures that optionsLabels and elementLabels have matching languageCodes,
            // options and optionsLabels have matching values and options should contain one selected value.
            foreach ($cfg['cfg'][0]['optionsLabels'] as $optionsLabel) {
                $rtn['text'][] = [
                    'text' => $optionsLabel['text'],
                    'languageCode' => $optionsLabel['languageCode'],
                    'optionValue' => $optionsLabel['value']
                ];
            }
        } elseif ($element == 'checkbox' && isset($cfg['cfg'][0]['checkboxLabels'])) {
            // Prior validation ensures that checkboxLabels and elementLabels have matching languageCodes.
            foreach ($cfg['cfg'][0]['checkboxLabels'] as $checkboxLabel) {
                foreach ($rtn['text'] as $idx => $text) {
                    if ($text['languageCode'] == $checkboxLabel['languageCode']) {
                        $rtn['text'][$idx]['checkboxLabel'] = $checkboxLabel['text'];
                    }
                }
            }
        }
        return $rtn;
    }

    /**
     * Returns an unpacked question config array
     *
     * @param integer $id      intakeFormQuestions.id
     * @param string  $element Element name
     * @param string  $json    intakeFormQuestions.cfg
     *
     * @return string
     */
    private function unpackQuestionCfg($id, $element, $json)
    {
        $rtn = [];
        if (($cfg = json_decode($json, true)) && !empty($cfg)) {
            $rtn = $cfg;
            if ($element == 'textbox' && !empty($rtn[0]['format']) && $rtn[0]['format'] == 'accept'
                && ($acceptResponses = $this->getQuestionTextConfig($id, $element, 'acceptResponse'))
            ) {
                $rtn[0]['acceptResponses'] = $acceptResponses;
            } elseif ($element == 'radio' && ($lbls = $this->getQuestionTextConfig($id, $element, 'optionValue'))) {
                $rtn[0]['optionsLabels'] = $lbls;
            } elseif ($element == 'checkbox'
               && ($lbls = $this->getQuestionTextConfig($id, $element, 'checkboxLabel'))
            ) {
                $rtn[0]['checkboxLabels'] = $lbls;
            }
        }
        return $rtn;
    }

    /**
     * Update a intakeFormQuestions record
     *
     * @param array $cfg intakeFormQuestions configuration
     *
     * @return integer
     */
    public function update($cfg)
    {
        $rtn = 0;
        $acceptableCols = ['active', 'sectionsID', 'statusesID', 'sequence', 'elementLabels', 'cfg'];
        if (!empty($cfg) && isset($cfg['id']) && isset($cfg['element'])
            && array_intersect($acceptableCols, array_keys($cfg))
        ) {
            $id = $cfg['id'];
            $element = $cfg['element'];
            $fields = '';
            $params = [':id' => $id];
            $originalQuestion = $this->getQuestionByID($id);
            foreach ($cfg as $column => $value) {
                if (in_array($column, ['id', 'element', 'cfg', 'elementLabels'])) {
                    continue;
                }
                $fields .= ((!empty($fields)) ? ', ' : '') . "{$column} = :{$column}";
                $params[":{$column}"] = $value;
            }
            if ($this->questionIsReconfigurable($element, $cfg, $originalQuestion)) {
                $fields .= ((!empty($fields)) ? ', ' : '') . "cfg = :cfg";
                $params[':cfg'] = $this->packUpQuestionConfig($element, $cfg);
            }
            if (!empty($fields)) {
                $this->DB->query("UPDATE {$this->clientDB}.intakeFormQuestions SET {$fields} WHERE id = :id", $params);
                $rtn = 1;
            }
            if ($element == 'paragraph' && !empty($cfg['cfg'])
                || ($element != 'paragraph' && (!empty($cfg['cfg']) || !empty($cfg['elementLabels'])))
            ) {
                if ($element != 'paragraph') {
                    if (empty($cfg['cfg']) && !empty($originalQuestion['cfg'])) { // Retain existing cfg
                        $cfg['cfg'] = $originalQuestion['cfg'];
                    }
                    if (empty($cfg['elementLabels'])) { // Retains existing elementLabels
                        $cfg['elementLabels'] = $originalQuestion['elementLabels'];
                    }
                }
                $this->insertQuestionText($this->setQuestionTextConfig($id, $element, $cfg), true);
                $rtn = 1;
            }
        }
        return $rtn;
    }

    /**
     * Get available intake form question presets for a given intake form configuration.
     * Presets are questions with very specific ddq mappings for responses and elements (textbox, checkbox, radio)
     *
     * @param integer $intakeFormConfigID ddqName.id
     *
     * @return array
     */
    public function getPresets($intakeFormConfigID)
    {
        $rtn = [];
        $intakeFormConfigID = (int)$intakeFormConfigID;
        $presets = $this->DB->fetchAssocRows(
            "SELECT * FROM {$this->DB->globalDB}.g_intakeFormQuestionPresets WHERE isGeneric = 0"
        );
        if (empty($intakeFormConfigID)) {
            $rtn = ['error' => 'Invalid intakeFormCfgID.'];
        } elseif (empty($presets)) {
            $rtn = ['error' => 'There is a misconfiguration of ddq presets data.'];
        } else {
            $legacyID = $this->DB->fetchValue(
                "SELECT legacyID FROM {$this->clientDB}.ddqName WHERE id = :id",
                [':id' => $intakeFormConfigID]
            );
            $ddq = new Ddq($this->clientID, ['isAPI' => $this->isAPI, 'authUserID' => $this->authUserID]);
            $splitLegacyID = $ddq->splitDdqLegacyID($legacyID);
            $params = [':intakeFormTypeID' => $splitLegacyID['caseType'], ':clientID' => $this->clientID];
            $versionClause = '';
            if (!empty($splitLegacyID['ddqQuestionVer'])) {
                $versionClause = ' AND ddqQuestionVer = :version';
                $params[':version'] = $splitLegacyID['ddqQuestionVer'];
            }
            $sql = "SELECT questionID FROM {$this->clientDB}.onlineQuestions WHERE caseType = :intakeFormTypeID AND clientID = :clientID{$versionClause}";
            if ($questionKeys = $this->DB->fetchValueArray($sql, $params)) {
                foreach ($presets as $preset) {
                    $elementID = (int)$preset['elementID'];
                    if (!in_array($preset['ddqColumn'], $questionKeys)
                        && isset(IntakeFormQuestionsApiData::ELEMENTS[$elementID])
                    ) {
                        $element = IntakeFormQuestionsApiData::ELEMENTS[$elementID];
                        $presetCfg = [
                            'id' => $preset['id'],
                            'name' => $preset['name'],
                            'element' => $element
                        ];
                        if (!empty($preset['cfg']) && ($cfg = json_decode((string) $preset['cfg'], true))) {
                            if ($element == 'select' && isset($cfg['selectElementSourceID'])) {
                                $presetCfg['selectElementSource'] = IntakeFormQuestionsApiData::SELECT_ELEMENT_SOURCES[$cfg['selectElementSourceID']];
                            } elseif ($element == 'radio' && isset($cfg['options']) && $cfg['options'] == 'yesNo') {
                                $presetCfg['options'] = ['Yes', 'No'];
                            } elseif ($element == 'textbox' && isset($cfg['format'])) {
                                $presetCfg['format'] = $cfg['format'];
                            }
                        }
                        $rtn[] = $presetCfg;
                    }
                }
            } else {
                $rtn = ['error' => 'Invalid intake form configuration.'];
            }
        }
        return $rtn;
    }
}
