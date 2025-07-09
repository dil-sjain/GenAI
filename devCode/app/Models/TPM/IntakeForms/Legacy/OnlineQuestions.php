<?php
/**
 * Provides basic read/write access to ddqName table
 */

namespace Models\TPM\IntakeForms\Legacy;

use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Lib\Traits\SplitDdqLegacyID;
use Models\BaseLite\RequireClientID;
use Models\Ddq;
use Models\Globals\AclScopes;
use Models\Globals\Geography;
use Models\Logging\LogRunTimeDetails;

/**
 * Basic CRUD access to onlineQuestions,  requiring clientID
 *
 * @keywords onlineQuestions, online questions, intake forms, intake form online questions, ddq
 */
#[\AllowDynamicProperties]
class OnlineQuestions extends RequireClientID
{
    use SplitDdqLegacyID;

    /**
     * @var Required by base class
     */
    protected $tbl = 'onlineQuestions';

    /**
     * @var boolean tabled is in a client database
     */
    protected $tableInClientDB = true;

    /**
     * @var integer tabled is in a client database
     */
    private $authUserID = 0;

    /**
     * Input NOT required by the user
     */
    public const ONLINE_Q_OPTIONAL = 0;

    /**
     * Input is REQUIRED by the user
     */
    public const ONLINE_Q_REQUIRED = 1;

    /**
     * Question is currently hidden from the user
     */
    public const ONLINE_Q_HIDDEN = 2;

    /**
     * Input field treated as Optional but control is ReadOnly
     * Not implemented for all control types
     */
    public const ONLINE_Q_READONLY = 3;

    /**
     * Input field treated as Required but control is ReadOnly
     * Field filled by outside source and not implemented for all control types
     */
    public const ONLINE_Q_READONLY_REQ = 4;

    /**
     * Tenant ID
     *
     * @var integer
     */
    protected $clientID = null;

    /**
     * Determines whether or not this was instantiated via the REST API
     *
     * @var boolean
     */
    protected $isAPI = false;

    /**
     * Case constructor requires clientID
     *
     * @param integer $clientID clientProfile.id
     * @param array   $params   configuration
     */
    public function __construct($clientID, $params = [])
    {
        parent::__construct($clientID, $params);
        $this->clientID = $clientID;
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : \Xtra::app()->session->get('authUserID');
    }

    /**
     * Determine whether or not a response to the "accept" field
     * is necessary for DDQ submission.
     *
     * @param integer $intakeFormTypeID  ddq.caseType
     * @param string  $languageCode      Language code
     * @param integer $intakeFormVersion ddq.ddqQuestionVer
     * @param integer $intakeFormID      ddq.id
     *
     * @return boolean
     */
    private function acceptResponseRequired(
        $intakeFormTypeID,
        $languageCode,
        $intakeFormVersion = '',
        $intakeFormID = 0
    ) {
        $intakeFormTypeID = (int)$intakeFormTypeID;
        $intakeFormID = (int)$intakeFormID;
        $questionKey = 'CMD_RM_ACCEPTBOX';
        if (!empty($intakeFormID)) { // Validation at the time of submission
            $questions = $this->getOnlineQuestions(
                $languageCode,
                $intakeFormTypeID,
                'Authorization',
                $intakeFormID,
                $intakeFormVersion
            );
            $exempted = (!empty($this->extractQuestion($questions, $questionKey)));
        } else { // Intake form configuration when retrieving the questions
            $clientDB = $this->DB->getClientDB($this->clientID);
            $sql = "SELECT * FROM {$clientDB}.onlineQuestions\n"
                . "WHERE clientID = :clientID "
                . "AND caseType = :intakeFormTypeID "
                . "AND ddqQuestionVer = :intakeFormVersion "
                . "AND languageCode = :languageCode "
                . "AND questionID = :questionID";
            $params = [
                ':clientID' => $this->clientID,
                ':intakeFormTypeID' => $intakeFormTypeID,
                ':intakeFormVersion' => $intakeFormVersion,
                ':languageCode' => $languageCode,
                ':questionID' => $questionKey
            ];
            $exempted = (!empty($this->DB->fetchAssocRows($sql, $params)));
        }
        return ($exempted === false);
    }

    /**
     * Detects whether or not a client's intake form has been configured for a given language.
     *
     * @param string $languageCode Language code
     * @param string $type         ddq.caseType
     * @param string $version      ddq.ddqQuestionVer
     *
     * @return boolean if exists true, else false
     */
    public function configuredForLanguage($languageCode, $type, $version = '')
    {
        $rtn = false;
        $type = (int)$type;
        if (!empty($languageCode) && !empty($type)) {
            $row = $this->getOQrec($languageCode, $type, $version, '', false);
            $rtn = (!empty($row));
        }
        return $rtn;
    }

    /**
     * Return key-value pairs of questionID-labelText rows from array of onlineQuestions rows
     *
     * @param array $questions   onlineQuestions rows to search
     * @param array $questionIDs onlineQuestions.questionID's
     *
     * @return array questionID-labelText key-value pairs
     */
    public function extractLabelTextFromQuestionIDs($questions = [], $questionIDs = [])
    {
        $labels = [];
        if (!empty($questions) && !empty($questionIDs)) {
            foreach ($questions as $question) {
                if (in_array($question['questionID'], $questionIDs)
                    && !array_key_exists($question['questionID'], $labels)
                ) {
                    $labels[$question['questionID']] = $question['labelText'];
                }
            }
        }
        return $labels;
    }

    /**
     * Extract onlineQuestions row from array of onlineQuestions rows via questionID
     *
     * @param array  $questions  onlineQuestions rows to search
     * @param string $questionID onlineQuestions.questionID
     *
     * @return array single onlineQuestions row
     */
    public function extractQuestion($questions, $questionID)
    {
        $question = [];
        foreach ($questions as $questionRow) {
            if ($questionRow['questionID'] == $questionID) {
                $question = $questionRow;
                break;
            }
        }
        return $question;
    }

    /**
     * Get ddq column name from onlineQuestions row dataMapping
     *
     * @param string $dataMapping onlineQuestions row dataMapping
     *
     * @return string ddq column name
     */
    protected function getColumn($dataMapping)
    {
        $key = str_replace('ddq.', '', $dataMapping);
        if ($key == 'regNum') {
            $key = 'regNumber';
        }
        return $key;
    }

    /**
     * Return configured response to the "accept" field in Authorization section.
     * Defaults to "Accept".
     *
     * @param integer $intakeFormTypeID  ddq.caseType
     * @param string  $languageCode      Language code
     * @param integer $intakeFormVersion ddq.ddqQuestionVer
     *
     * @return boolean
     */
    public function getConfiguredAcceptResponse($intakeFormTypeID, $languageCode = 'EN_US', $intakeFormVersion = '')
    {
        $rtn = 'Accept';
        $intakeFormTypeID = (int)$intakeFormTypeID;
        $questionKey = 'TEXT_ACCEPTQ_STRING';
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT labelText FROM {$clientDB}.onlineQuestions\n"
            . "WHERE caseType = :intakeFormTypeID "
            . "AND ddqQuestionVer = :intakeFormVersion "
            . "AND languageCode = :languageCode "
            . "AND questionID = :questionID\n"
            . "ORDER BY id LIMIT 1";
        $params = [
            ':intakeFormTypeID' => $intakeFormTypeID,
            ':intakeFormVersion' => $intakeFormVersion,
            ':languageCode' => $languageCode,
            ':questionID' => $questionKey
        ];
        if ($response = $this->DB->fetchValue($sql, $params)) {
            $rtn = $response;
        }
        return $rtn;
    }

    /**
     * Returns key-value array map of onlineQuestions.id as the key and value from customData.
     *
     * NOTE: ddq.formClass must equal 'internal'.
     * key is integer from onlineQuestions.id
     * values can be either customData.value or customData.listItemID
     *
     * @param integer $ddqID ddq.id
     *
     * @return array key-value array of onlineQuestions IDs mapped to custom 3P data
     */
    private function getCustom3PData($ddqID)
    {
        $ddqID = (int)$ddqID;
        $custom3PData = [];
        if ($ddqID <= 0) {
            return $custom3PData;
        }
        $sql = "SELECT d.clientID, d.caseType, c.tpID, d.caseID, d.ddqQuestionVer, d.formClass\n"
            . "FROM ddq AS d\n"
            . "INNER JOIN cases AS c ON (c.id = d.caseID)\n"
            . "WHERE d.id = :ddqID";
        if (!($row = $this->DB->fetchObjectRow($sql, [':ddqID' => $ddqID])) || $row->formClass != 'internal') {
            return $custom3PData;
        }
        $tpID = (int)$row->tpID;
        $clientDB = $this->DB->getClientDB($row->clientID);

        $sql = "SELECT o.id as oqID, c.listItemID, c.value FROM {$clientDB}.onlineQuestions as o\n"
            . "INNER JOIN {$clientDB}.customData as c "
            . "    ON (o.tpCustomFieldID = c.fieldDefID AND o.clientID = c.clientID)\n"
            . "WHERE c.tpID = :tpID AND c.tpID != 0";
        $rows = $this->DB->fetchObjectRows($sql, [':tpID' => $tpID]);
        foreach ($rows as $row) {
            $custom3PData[$row->oqID] = (!empty($row->listItemID)) ? $row->listItemID : $row->value;
        }
        return $custom3PData;
    }

    /**
     * Get the answer to an onlineQuestion record from ddq data, this replaces Legacy's foqPrintEchoLabel method
     *
     * @param array   $onlineQuestionRow Associative array of onlineQuestion row
     * @param object  $ddq               Ddq object
     * @param string  $languageCode      Language code
     * @param boolean $translateValue    (optional) Attempt to translate the value
     *
     * @note This is a refactor of the foqPrintEchoLabel function in public_html/cms/includes/php/ddq_funcs.php
     *
     * @return string
     */
    public function getInFormRspnsValue($onlineQuestionRow, $ddq, $languageCode, $translateValue = false)
    {
        $acceptableControlTypes = ['text', 'StateRegionList', 'StateRegionListAJ', 'DateDDL', 'textarea', 'tarbYes', 'checkBox', 'textEmailField', 'PopDate', 'tfAccept', 'CompanyDivisionAJ', 'radioYesNo', 'CountryOCList', 'CountryOCListAJ', 'CountryList', 'DDLfromDB'];
        if (empty($onlineQuestionRow) || !isset($onlineQuestionRow['controlType'])
            || !in_array($onlineQuestionRow['controlType'], $acceptableControlTypes) || !is_object($ddq)
        ) {
            return '';
        }
        switch ($onlineQuestionRow['controlType']) {
            case 'text':              // Text Control
            case 'StateRegionList':   // The State list coupled w/a country list w/the On Change resubmit
            case 'StateRegionListAJ': // The State list coupled w/a country list w/the On Change AJAX
            case 'DateDDL':           // Date DropDownList
            case 'textarea':          // Text Area Control
            case 'tarbYes':           // Text Area Control following a Yes RB
            case 'checkBox':          // checkbox value or opposite value
            case 'textEmailField':    // Text Email Field
            case 'PopDate':           // Pop Date control data
            case 'tfAccept':          // Accept Control
                return $ddq->get($this->getColumn($onlineQuestionRow['dataMapping']));
            case 'CompanyDivisionAJ':
                $divID    = intval($ddq->get('companyDivision'));
                $subDivID = intval($ddq->get('companySubDivision'));
                if ($divID) {
                    $dbName = $this->DB->getClientDB($this->clientID);
                    $tblName = (!empty($dbName) ? '.' : '') . 'companyDivision';
                    $sql = "SELECT name FROM {$tblName} WHERE id = :id AND clientID = :clientID LIMIT 1";
                    $params = [':id' => $divID, ':clientID' => $this->clientID];
                    $value = $this->DB->fetchValue($sql, $params);
                    if (!empty($value)) {
                        if ($subDivID) {
                            $tblName = (!empty($dbName) ? '.' : '') . 'companySubDivision';
                            $sql = "SELECT name FROM {$tblName} WHERE id = :id AND clientID = :clientID LIMIT 1";
                            $params = [':id' => $subDivID, ':clientID' => $this->clientID];
                            $subDivTitle = $this->DB->fetchValue($sql, $params);
                            if (!empty($subDivTitle)) {
                                $value .= ' - ' . $subDivTitle;
                            }
                        }
                    } else {
                        $value = ''; // reset it from null to default empty string value
                    }
                }
                return $value;
            case 'radioYesNo':        // The Radio Button pair Yes/No
                $value = $ddq->get($this->getColumn($onlineQuestionRow['dataMapping']));
                if ($translateValue) {
                    $transValue = $value;

                    if ($value == 'Yes') {
                        $onlineQuestionRow = $this->getOQrec(
                            $languageCode,
                            $ddq->get('caseType'),
                            $ddq->get('ddqQuestionVer'),
                            "TEXT_YES_TAG"
                        );
                        $transValue = $onlineQuestionRow['labelText'];
                    } elseif ($value == 'No') {
                        $onlineQuestionRow = $this->getOQrec(
                            $languageCode,
                            $ddq->get('caseType'),
                            $ddq->get('ddqQuestionVer'),
                            "TEXT_NO_TAG"
                        );
                        $transValue = $onlineQuestionRow['labelText'];
                    }

                    // In case no translation is found
                    if (!strlen((string) $transValue)) {
                        $transValue = $value;
                    }
                }

                return ($translateValue ? $transValue : $value);
            case 'CountryOCList':               // The Country list w/the On Change resubmit
            case 'CountryOCListAJ':             // The Country list w/the On Change AJAX
            case 'CountryList':                 // The basic Country list
                $value = $ddq->get($this->getColumn($onlineQuestionRow['dataMapping']));
                return (Geography::getVersionInstance())->getCountryNameTranslated($value);
            case 'DDLfromDB':               // DropDownList from Database table
                $displayName = '';

                $parts = explode(",", (string) $onlineQuestionRow['generalInfo']);
                $value = '';
                if (isset($parts[0])) {
                    $value = $parts[0];
                }
                $DDLdbName = trim($value);

                $value = $ddq->get($this->getColumn($onlineQuestionRow['dataMapping']));
                if ($translateValue && \Xtra::app()->session->get('languageCode') != 'EN_US') {
                    $clientDB = $this->DB->getClientDB($this->clientID);
                    $sql = "SELECT nameTranslation FROM {$clientDB}.langDDLnameTrans
                    WHERE clientID = :clientID AND languageCode = :languageCode
                    AND tableName = :tableName AND primaryID = :primaryID";
                    $params = [':clientID' => $this->clientID, ':languageCode' => \Xtra::app()->session->get('languageCode'), ':tableName' => $DDLdbName, ':primaryID' => $value];
                    $displayName = $this->DB->fetchValue($sql, $params);
                } else {
                    $sql = "SELECT name FROM ".$DDLdbName." WHERE id = :id";
                    $params = [':id' => $value];
                    $displayName = $this->DB->fetchValue($sql, $params);
                }
                return $displayName;
        }
        return '';
    }

    /**
     * Get the label of an online question record, this replaces Legacy's foqPrintEchoLabel method.
     *
     * @param array $onlineQuestionRow Associative array of onlineQuestion row
     * @param Ddq   $ddq               Ddq object
     *
     * @note This is a refactor of the foqPrintEchoLabel function in public_html/cms/includes/php/ddq_funcs.php
     * @note This may be a redundant method now. Remove it if it's unused.
     * @todo Remove &nbsp; and create a css class for the view to use.
     *
     * @return string label
     */
    public function getLabel($onlineQuestionRow, $ddq)
    {
        $acceptableControlTypes = ['text', 'StateRegionList', 'DateDDL', 'textarea', 'tarbYes', 'checkBox', 'radioYesNo', 'textEmailField', 'textEmailField', 'CountryOCList', 'CountryList', 'DDLfromDB', 'CountryOCListAJ', 'StateRegionListAJ', 'None', 'ddqAttachment', 'PopDate', 'tfAccept', 'CompanyDivisionAJ', ''];
        if (empty($onlineQuestionRow) || $onlineQuestionRow['qStatus'] == IntakeFormTypes::ONLINE_Q_HIDDEN
            || !isset($onlineQuestionRow['controlType'])
            || !in_array($onlineQuestionRow['controlType'], $acceptableControlTypes)
        ) {
            return '';
        }
        return match ($onlineQuestionRow['controlType']) {
            'text', 'StateRegionList', 'DateDDL', 'textarea', 'tarbYes', 'checkBox', 'radioYesNo', 'textEmailField', 'CountryOCList', 'CountryList', 'DDLfromDB', 'CountryOCListAJ', 'StateRegionListAJ', 'None', 'ddqAttachment', 'PopDate', 'tfAccept', 'CompanyDivisionAJ', '' => $onlineQuestionRow['labelText'],
            default => '',
        };
    }

    /**
     * Return a key/value array of questionID/labelText rows
     *
     * @param array $questionIDs Array of onlineQuestions.questionID values
     *
     * @return array
     */
    public function getLabels($questionIDs)
    {
        $rtn = [];
        if (is_array($questionIDs) && !empty($questionIDs)) {
            $clientDB = $this->DB->getClientDB($this->clientID);
            $sql = "SELECT questionID, labelText FROM {$clientDB}.onlineQuestions\n"
                . "WHERE clientID = :clientID AND questionID IN('" . implode("', '", $questionIDs) . "')";
            $rtn = $this->DB->fetchKeyValueRows($sql, [':clientID' => $this->clientID]);
        }
        return $rtn;
    }

    /**
     * Loads questions for a given tab of DDQ (A.K.A. foqLoadCompletePage from ddq_funcs.php)
     * A question can be hidden if it is linked to customField row hidden with a row in customFieldExclude
     *
     * 1. If no onlineQuestions recs, display warning text with SQL in non-Production versions
     * 2. If there are onlineQuestion recs and the pageTab is "Company Details", make a call to
     *    Models\TPM\IntakeForms\Legacy\InFormLoginData->getBaseText(), A.K.A.
     *    loadBaseDDQText in ddq_funcs.php folowed by a redirect to an intake form (previously ddq2.php).
     *
     * @param string  $languageCode   Language of the questions to retrieve
     * @param integer $caseType       case type of the questions to retrieve
     * @param string  $pageTab        Page Tab (Page) of questions to retrieve
     * @param integer $ddqID          ddq.id
     * @param string  $ddqQuestionVer ddq.ddqQuestionVer
     * @param boolean $languageStrict If true, don't seek default if no questions for the languge, just fail.
     *
     * @see Refactoring Note: the following non-Model concerns have been excluded for the caller to deal with:
     *
     * 1. If no onlineQuestions recs, display warning text with SQL in non-Production versions
     * 2. If there are onlineQuestion recs and the pageTab is "Company Details", make a call to
     *    Models\TPM\IntakeForms\Legacy\InFormLoginData->getBaseText(), A.K.A.
     *    loadBaseDDQText in ddq_funcs.php folowed by a redirect to an intake form (previously ddq2.php).
     *
     * @see Refactoring Note: the following non-Model concerns have been excluded for the caller to deal with:
     *
     * @return array Questions for entire page tab
     */
    public function getOnlineQuestions(
        $languageCode,
        $caseType,
        $pageTab,
        $ddqID = 0,
        $ddqQuestionVer = '',
        $languageStrict = false
    ) {
        $rtn = [];
        $caseType     = (int)$caseType;
        $ddqID        = (int)$ddqID;
        $exclusions   = $this->getOnlineQuestionsExclusions($ddqID);
        $custom3PData = $this->getCustom3PData($ddqID);
        $idx          = 0;
        $ddqLog = new LogRunTimeDetails($this->clientID, 'ddq');
        $logMsg = "$languageCode, $caseType, $pageTab, $ddqQuestionVer";
        $ddqLog->logDetails(LogRunTimeDetails::LOG_VERBOSE, $logMsg);
        $recs = $this->getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer);

        if ($languageStrict && empty($recs)) {
            return $rtn;
        }

        if (empty($recs)) {
            // No questions? Get defaults (shouldn't have any customField links)
            $recs = $this->getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer, true);

            if (!$recs && $languageCode != 'EN_US') {
                // temp fix: fall back to $subInLang or EN_US if no questions for selected language
                if ($ddqID > 0) {
                    $ddqParams = (!empty($this->authUserID)) ? ['authUserID' => $this->authUserID] : [];
                    $ddq = (new Ddq($this->clientID, $ddqParams))->findById($ddqID);
                    $subInLang = $ddq->get('subInLang');
                    $languageCode = ($subInLang && ($languageCode != $subInLang)) ? $subInLang : 'EN_US';
                    $recs = $this->getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer);
                }
                if (!$recs) {
                    // No questions? Get defaults (shouldn't have any customField links)
                    $recs = $this->getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer, true);
                    if (!$recs && $languageCode != 'EN_US') {
                        $languageCode = 'EN_US';
                        $recs = $this->getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer);
                        if (!$recs) {
                            // No questions? Get defaults (shouldn't have any customField links)
                            $recs = $this->getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer, true);
                        }
                    }
                }
            }
        }
        // Load all of the questions for this page into an array
        foreach ($recs as $key => $row) {
            if (!in_array($row['id'], $exclusions)) { // skip if excluded
                $rtn[] = $row;
                if (isset($custom3PData[$row['id']])) {
                    $rtn[$idx]['customData3P'] = $custom3PData[$row['id']];
                }
                $idx++;
            }
        }
        return $rtn;
    }

    /**
     * Returns list of onlineQuestion.id that should be excluded from DDQ form and reports,
     * when onlineQuestion linked to customField that is specified in customFieldExclude
     * for a 3P category
     *
     * @param integer $ddqID ddq.id
     *
     * @return array list of ids to exclude
     */
    private function getOnlineQuestionsExclusions($ddqID)
    {
        $ddqID = (int)$ddqID;
        if (empty($ddqID)) {
            return [];
        }
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT oq.id FROM {$clientDB}.onlineQuestions as oq\n"
            . "INNER JOIN {$clientDB}.customField as cf "
            . "ON (cf.id = oq.tpCustomFieldID AND cf.clientID = oq.clientID)\n"
            . "INNER JOIN {$clientDB}.customFieldExclude as cfe "
            . "ON (cfe.cuFldID = cf.id AND cfe.clientID = cf.clientID)\n"
            . "INNER JOIN {$clientDB}.thirdPartyProfile as tpp "
            . "ON (cfe.tpCatID = tpp.tpTypeCategory AND tpp.clientID = cf.clientID)\n"
            . "INNER JOIN {$clientDB}.cases ON (cases.tpID = tpp.id)\n"
            . "INNER JOIN {$clientDB}.ddq ON (ddq.caseID = cases.id)\n"
            . "WHERE cf.clientID = :clientID AND ddq.id = :ddqID AND cf.scope = 'thirdparty'";
        return $this->DB->fetchValueArray($sql, [':clientID' => $this->clientID, ':ddqID' => $ddqID]);
    }

    /**
     * Get mapping of OnlineQuestions to CustomFields.
     *
     * @param integer $clientID       ddq.clientID
     * @param integer $caseType       ddq.caseType
     * @param integer $ddqQuestionVer ddq.ddqQuestionVer
     *
     * @see Refactored from ddq_funcs.php -> foqOnlineQuestionsLinkedToCustomFieldsObjRows
     *
     * @return array
     */
    public function getOnlineQuestionsLinkedToCustomFields($clientID, $caseType, $ddqQuestionVer)
    {
        $return = [];
        $clientDB = $this->DB->getClientDB($clientID);
        $sql = "SELECT\n"
            . " oq.id as 'onlineQuestions_id',\n"
            . " oq.controlType as 'onlineQuestions_controlType',\n"
            . " oq.generalInfo as 'onlineQuestions_generalInfo',\n"
            . " oq.questionID as onlineQuestions_questionID,\n"
            . " oq.languageCode as onlineQuestions_languageCode,\n"
            . " -- oq.clientID, dn.legacyID,\n"
            . " (CASE\n"
            . " WHEN LEFT(TRIM(oq.generalInfo), 16) ='customSelectList'\n"
            . "     THEN TRIM( SUBSTRING_INDEX(oq.generalInfo, ',', -1) )\n"
            . "     ELSE  TRIM( SUBSTRING_INDEX(oq.generalInfo, ',', 1) )\n"
            . " END) as oq_parsed_listName,\n"
            . " cf.id as 'customField_id',\n"
            . " cf.type as 'customField_type',\n"
            . " cf.listName as 'customField_listName',\n"
            . " cf.scope as 'customField_scope',\n"
            . " (CASE\n"
            . "     WHEN oq.controlType='DDLfromDB'\n"
            . "             AND cf.Type !='select'  AND cf.Type !='check'  AND cf.Type !='radio' THEN 1\n"
            . "     /* assure that if using a custom list, both use the same source */\n"
            . "     WHEN oq.controlType='DDLfromDB'\n"
            . "         AND (cf.type='select' OR cf.type='radio' OR cf.type='check')\n"
            . "         AND oq.controlType='DDLfromDB'\n"
            . "         AND TRIM(SUBSTRING_INDEX(oq.generalInfo, ',', -1)) != TRIM(cf.listName) THEN 2\n"
            . "     WHEN cf.type='simple' AND oq.controlType !='text' AND oq.controlType != 'textEmailField' THEN 3\n"
            . "     WHEN cf.type='numeric' AND oq.controlType !='text'  THEN 4\n"
            . "     WHEN oq.controlType = 'PopDate' AND cf.type !='date'  THEN 5\n"
            . "     WHEN oq.controlType = 'DateDDL' AND cf.type !='date' THEN 6\n"
            . "     WHEN cf.type='multiline'\n"
            . "         AND oq.controlType !='textarea'\n"
            . "         AND oq.controlType !='tarbYes' THEN 7\n"
            . "     WHEN oq.controlType = 'checkBox' AND cf.type !='select' THEN 8\n"
            . "     WHEN cf.type ='section' THEN 9\n"
            . "     WHEN cf.type ='radio' AND oq.controlType !='DDLfromDB' THEN 10\n"
            . "     WHEN cf.type ='check' AND oq.controlType !='DDLfromDB' THEN 11\n"
            . "     WHEN cf.type ='select' AND oq.controlType !='DDLfromDB' THEN 12\n"
            . "     WHEN cf.type ='date' AND oq.controlType !='DateDDL'\n"
            . "         AND oq.controlType !='PopDate' THEN 13\n"
            . "     /* trigger error for unknown/unmapped controlTypes */\n"
            . "     WHEN  (oq.controlType !='text'\n"
            . "         AND oq.controlType !='textEmailField'\n"
            . "         AND oq.controlType !='textarea'\n"
            . "         AND oq.controlType !='PopDate'\n"
            . "         AND oq.controlType !='DateDDL'\n"
            . "         AND oq.controlType !='tarbYes'\n"
            . "         AND oq.controlType !='PopDate'\n"
            . "         AND oq.controlType !='DDLfromDB') THEN 99\n"
            . "     ELSE 0 /* no error detected */\n"
            . "     END\n"
            . " ) as error_num\n"
            . " FROM {$clientDB}.onlineQuestions as oq\n"
            . " INNER JOIN {$clientDB}.customField as cf\n"
            . "     ON (cf.id = oq.tpCustomFieldID\n"
            . "     AND cf.clientID = oq.clientID)\n"
            . " INNER JOIN {$clientDB}.ddqName AS dn\n"
            . "     ON (dn.legacyID = CONCAT('L-', oq.caseType, oq.ddqQuestionVer)\n"
            . "     AND dn.clientID = oq.clientID)\n"
            . " WHERE oq.clientID = :clientID\n"
            . " AND dn.legacyID = :lID ;";

        $params = [
            ':clientID' => $clientID,
            ':lID'      => 'L-' . $caseType . (trim($ddqQuestionVer))
        ];
        $rows = $this->DB->fetchObjectRows($sql, $params);

        foreach ($rows as $row) {
            $row->error_msg = match ((int) $row->error_num) {
                1 => "customField.type must be 'select','check' or 'radio' when "
                . " onlineQuestions.controlType = 'DDLfromDB' ",
                2 => "onlineQuestion and customField do not use "
                . " the same source data (" . $row->oq_parsed_listName
                . " != " . $row->customField_listName .") "
                . "You may need to create a new customSelectList with the name "
                . $row->oq_parsed_listName .". ",
                3 => "when customField.type = 'simple' "
                . " onlineQuestion.controlType must be 'text' or 'textEmailField' ",
                4 => "when customField.type = 'numeric' "
                . " onlineQuestion.controlType must be 'text' ",
                5 => "when onlineQuestion.controlType = 'PopDate' "
                . "  customField.type must be 'date' ",
                6 => "when onlineQuestion.controlType = 'DateDDL' "
                . "  customField.type must be 'date' ",
                7 => "when customField.type = 'multiline' "
                . " onlineQuestion.controlType must be 'textarea' or 'tarbYes' ",
                8 => "when onlineQuestion.controlType = 'checkBox' "
                . "  customField.type must be 'select' ",
                9 => "when customField.type = 'section' "
                . " no value for onlineQuestion.controlType is legal ",
                10 => "when customField.type = 'radio' "
                . " onlineQuestion.controlType must be 'DDLfromDB' ",
                11 => "when customField.type = 'check' "
                . " onlineQuestion.controlType must be 'DDLfromDB' ",
                12 => "when customField.type = 'select' "
                . " onlineQuestion.controlType must be 'DDLfromDB' ",
                13 => "when customField.type = 'date' "
                . " onlineQuestion.controlType must be 'DateDDL' or 'PopDate' ",
                99 => " onlineQuestion.controlType does not have a defined map "
                . " to customField.type",
                default => '',
            };
            $return[] = $row;
        }
        return $return;
    }

    /**
     * Returns the onlineQuestions.id value for a given onlineQuestions "mate"
     * Typically this is for countries and states select lists.
     *
     * @param integer $intakeFormTypeID   onlineQuestions.caseType
     * @param string  $intakeFormVersion  onlineQuestions.ddqQuestionVer
     * @param string  $languageCode       onlineQuestions.languageCode
     * @param string  $mateQuestionKey    onlineQuestions.questionID stored in onlineQuestions.generalInfo
     * @param string  $currentQuestionKey current onlineQuestions.questionID
     *
     * @return integer
     */
    public function getOnlineQuestionsMateID(
        $intakeFormTypeID,
        $intakeFormVersion,
        $languageCode,
        $mateQuestionKey,
        $currentQuestionKey = ''
    ) {
        $rtn = 0;
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT id FROM {$clientDB}.onlineQuestions\n"
            . "WHERE clientID = :clientID "
            . "AND caseType = :intakeFormTypeID "
            . "AND ddqQuestionVer = :intakeFormVersion "
            . "AND languageCode = :languageCode "
            . "AND questionID = :mateQuestionKey";
        $params = [
            ':clientID' => $this->clientID,
            ':intakeFormTypeID' => $intakeFormTypeID,
            ':intakeFormVersion' => $intakeFormVersion,
            ':languageCode' => $languageCode,
            ':mateQuestionKey' => $mateQuestionKey
        ];
        if (!empty($currentQuestionKey)) {
            $sql .= " AND generalInfo = :currentQuestionKey";
            $params[':currentQuestionKey'] = $currentQuestionKey;
        }
        if (($questionID = $this->DB->fetchValue($sql, $params)) && !empty($questionID)) {
            $rtn = $questionID;
        }
        return $rtn;
    }

    /**
     * Gets an onlineQuestions record
     *
     * @param string  $langCode       onlineQuestions.languageCode
     * @param integer $caseType       onlineQuestions.caseType
     * @param string  $ddqQuestionVer onlineQuestions.ddqQuestionVer
     * @param string  $questionID     onlineQuestions.questionID
     * @param boolean $chkDflt        If true, checks with clientID = 0 when no records found
     *
     * @return array onlineQuestions record
     */
    public function getOQrec($langCode, $caseType, $ddqQuestionVer = '', $questionID = '', $chkDflt = true)
    {
        $OQrec = [];
        $caseType = intval($caseType);
        if (empty($langCode) || $caseType <= 0) {
            return $OQrec;
        }
        $attributes = [
            'clientID' => $this->clientID,
            'languageCode' => $langCode,
            'caseType' => $caseType,
            'ddqQuestionVer' => $ddqQuestionVer,
        ];
        if (!empty($questionID)) {
            $attributes['questionID'] = $questionID;
        }
        // TPM-1097, TPM-1140 add ORDER BY id
        if (!($OQrec = $this->selectOne([], $attributes, 'ORDER BY id')) && $chkDflt) {
            $attributes['clientID'] = 0;
            $OQrec = $this->selectOne([], $attributes, 'ORDER BY id');
        }
        return $OQrec ?: [];
    }

    /**
     * Gets array of onlineQuestions recs
     *
     * @param string  $languageCode   Language of the questions to retrieve
     * @param integer $caseType       case type of the questions to retrieve
     * @param string  $pageTab        Page Tab (Page) of questions to retrieve
     * @param string  $ddqQuestionVer ddq.ddqQuestionVer
     * @param boolean $default        If true, then change query to not be specific to client
     *
     * @return array onlineQuestions recs
     */
    private function getOQrecs($languageCode, $caseType, $pageTab, $ddqQuestionVer = '', $default = false)
    {
        // TPM-1097, TPM-1140 add id to ORDER BY
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sqlHead = "SELECT * FROM {$clientDB}.onlineQuestions\n"
            . "WHERE clientID = :clientID AND languageCode = :languageCode AND caseType = :caseType "
            . "AND ddqQuestionVer = :ddqQuestionVer AND pageTab LIKE :pageTab\n";
        $sqlTail = "ORDER BY tabOrder, id";
        $params = [
            ':languageCode' => $languageCode,
            ':caseType' => $caseType,
            ':ddqQuestionVer' => $ddqQuestionVer,
            ':pageTab' => $pageTab,
        ];
        if ($default) {
            $sql = $sqlHead . "AND qStatus != :qStatus\n" . $sqlTail;
            $params[':clientID'] = 0;
            $params[':qStatus'] = self::ONLINE_Q_HIDDEN;
        } else {
            $sql = $sqlHead . $sqlTail;
            $params[':clientID'] = $this->clientID;
        }
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Returns an array of questionID values whose qStatus value is set to 1
     *
     * @param string  $languageCode Language of the questions to retrieve
     * @param integer $caseType     ddq.caseType
     * @param integer $ddqID        ddq.id
     * @param string  $version      ddq.ddqQuestionVer
     *
     * @return array
     */
    public function getRequiredQuestions($languageCode, $caseType, $ddqID, $version)
    {
        $rtn = [];
        $ddqID = (int)$ddqID;
        $caseType = (int)$caseType;
        if (empty($languageCode) || empty($ddqID) || empty($caseType)) {
            return $rtn;
        }
        $sections = [
            'Additional Information',
            'Authorization',
            'Business Practices',
            'Company Details',
            'Personnel',
            'Relationships'
        ];
        foreach ($sections as $section) {
            $questions = $this->getOnlineQuestions($languageCode, $caseType, $section, $ddqID, $version);
            foreach ($questions as $question) {
                if ((int)$question['qStatus'] == 1) {
                    $rtn[] = $question['questionID'];
                }
            }
        }
        return $rtn;
    }

    /**
     * This should be filled in eventually.
     *
     * @param integer $ddqID   DDQ ID
     * @param integer $modelID Risk Model ID
     *
     * @return array|null
     */
    public function getRiskFactorQuestions($ddqID, $modelID)
    {
        if (empty($ddqID) || empty($modelID)) {
            return null;
        }
        $isLegacy = (str_starts_with($ddqID, 'L-')) ? true : false;
        $questions = [];

        if ($isLegacy) {
            $caseType = 0;
            try {
                $nameParts = $this->splitDdqLegacyID($ddqID);
                $caseType  = intval($nameParts['caseType']);
                $ver       = $this->DB->esc($nameParts['ddqQuestionVer']);
            } catch (Exception) {
                throw new \Exception("Invalid Input");
            }

            // only required questions
            $clientDB = $this->DB->getClientDB($this->clientID);
            if ($other
                = $this->DB->fetchObjectRows(
                    "SELECT questionID, sectionName, "
                    . "controlType, labelText, generalInfo FROM {$clientDB}.onlineQuestions "
                    . "WHERE clientID=:clientID AND languageCode='EN_US' "
                    . "AND caseType=:caseType AND ddqQuestionVer=:version "
                    . "AND qStatus='1' AND controlType IN('radioYesNo','DDLfromDB') "
                    . "ORDER BY sectionName ASC, tabOrder ASC, id ASC",
                    [':clientID' => $this->clientID, ':caseType' => $caseType, ':version' => $ver]
                )
            ) {
                for ($i = 0; $i < count($other); $i++) {
                    $ques = $other[$i];
                    $ques->labelText = strip_tags(trim(str_replace('&nbsp;', ' ', (string) $ques->labelText)));
                    // get list items for DDLfromDB
                    if ($ques->controlType == 'DDLfromDB') {
                        $genInfo = explode(',', str_replace(' ', '', (string) $ques->generalInfo));
                        $tbl = $genInfo[0];
                        $bUseClientID = (count($genInfo) > 1) ? intval($genInfo[1]) : 0;

                        $listRows = [];
                        $params = [];
                        if ($tbl == 'customSelectList') {
                            $list_name = $this->DB->esc($genInfo[2]);
                            $sql = "SELECT id, name FROM {$clientDB}.$tbl "
                                . "WHERE clientID=:clientID AND listName=:listName "
                                . "ORDER BY sequence ASC, name ASC";
                            $params = [':clientID' => $this->clientID, ':listName' => $list_name];
                        } else {
                            if ($bUseClientID) {
                                $sql = "SELECT id, name FROM {$clientDB}.$tbl "
                                    . "WHERE clientID=:clientID ORDER BY name ASC";
                                $params[':clientID'] = $this->clientID;
                            } else {
                                $sql = "SELECT id, name FROM {$clientDB}.$tbl WHERE 1 ORDER BY name ASC";
                            }
                        }
                        $listRows = $this->DB->fetchKeyValueRows($sql, $params);
                        if (!$listRows && $bUseClientID) {
                            $listRows = $this->DB->fetchKeyValueRows(
                                "SELECT id, name FROM {$clientDB}.$tbl WHERE clientID='0' ORDER BY name ASC"
                            );
                        }
                        if ($listRows) {
                            $ques->listItems = $listRows;
                        } else {
                            $ques->listItems = [];
                        }
                    } else {
                        unset($ques->generalInfo);
                        $ques->listItems = [];
                    }
                    $other[$i] = $ques;
                    $questions[] = $ques;
                }
            }
        }

        return (count($questions) > 0 ? $questions : null);
    }

    /**
     * Sets authUserID property
     *
     * @param integer $authUserID users.id
     *
     * @return void
     */
    public function setAuthUserID($authUserID)
    {
        $this->authUserID = (int)$authUserID;
    }

    /**
     * Split ddqName.legacyID into parts
     *
     * @param string $legacyID ddqName.legacyID
     *
     * @return array associative array with prefix, caseType, and ddqQuestionVer keys
     *
     * @throws standard Exception on missing or invalid legacyID
     */
    public function splitDdqLegacyID($legacyID)
    {
        if (!isset($legacyID)) {
            throw new \Exception('Missing legacyID value');
        }
        $parts = [];
        if (preg_match('/^(L-)(\d+)([a-zA-Z]*)$/', $legacyID, $match)) {
            [$na, $prefix, $caseType, $ddqQuestionVer] = $match;
            $ddqQuestionVer = trim($ddqQuestionVer);
            $parts = ['prefix' => $prefix, 'caseType' => intval($caseType), 'ddqQuestionVer' => (!empty($ddqQuestionVer)) ? $ddqQuestionVer : ''];
        } else {
            throw new \Exception("Invalid legacyID value `$legacyID`");
        }
        return $parts;
    }

    /**
     * Validate the DDQ's accept response for an intake form.
     *
     * @param integer $intakeFormID      ddq.id
     * @param integer $intakeFormTypeID  ddq.caseType
     * @param integer $intakeFormVersion ddq.ddqQuestionVer
     * @param string  $languageCode      Language code
     * @param string  $response          Accept response in Authorization section of intake form
     *
     * @return boolean
     */
    public function validateAcceptResponse(
        $intakeFormID,
        $intakeFormTypeID,
        $intakeFormVersion,
        $languageCode,
        $response
    ) {
        $rtn = false;
        $intakeFormTypeID = (int)$intakeFormTypeID;
        $intakeFormID = (int)$intakeFormID;
        $languageCode = (empty($languageCode)) ? 'EN_US' : $languageCode;
        if (!empty($intakeFormID) && !empty($languageCode) && !empty($response)) {
            $required = $this->acceptResponseRequired(
                $intakeFormTypeID,
                $languageCode,
                $intakeFormVersion,
                $intakeFormID
            );
            if ($required) {
                $response = strtolower($response);
                $questions = $this->getOnlineQuestions(
                    $languageCode,
                    $intakeFormTypeID,
                    'Authorization',
                    $intakeFormID,
                    $intakeFormVersion
                );
                if (($expectedResponse = ($this->extractQuestion($questions, 'TEXT_ACCEPTQ_STRING')))
                    && !empty($expectedResponse) && !empty($expectedResponse['labelText'])
                ) {
                    $expectedResponse = strtolower((string) $expectedResponse['labelText']);
                } else {
                    $expectedResponse = 'accept'; // Default response if not configured in DB
                }
                $rtn = ($expectedResponse == $response);
            } else {
                $rtn = true;
            }
        }
        return $rtn;
    }

    /**
     * Validate questionID exists in clientDB.onlineQuestions
     *
     * @param string $questionID onlineQuestion.id
     *
     * @return array
     */
    public function validateQuestionID($questionID)
    {
        $rtn = [];
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT id FROM {$clientDB}.onlineQuestions WHERE questionID = :questionID";
        $id = $this->DB->fetchValue($sql, [":questionID" => $questionID]);
        if (empty($id)) {
            $rtn[] = "questionID does not exist";
        }
        return $rtn;
    }

    /**
     * Get questionID from onlineQuestions
     *
     * @param integer $id onlineQuestions.id
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getQuestionID($id)
    {
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT questionID FROM {$clientDB}.onlineQuestions WHERE id = :id";
        $questionID = $this->DB->fetchValue($sql, [":id" => $id]);
        if (empty($questionID)) {
            return 0;
        }
        return $questionID;
    }
}
