<?php
/**
 * Provide data for case review
 */

namespace Models\TPM\CaseMgt;

use Lib\FeatureACL;
use Lib\Legacy\CaseStage;
use Lib\Legacy\UserType;
use Lib\Services\BBTags;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Cases;
use Models\TPM\CaseMgt\CaseReviewCmp;
use Models\User;
use Models\SP\RedFlags;

/**
 * Encapsulate data retrieval for case review so that it can be used
 * for reviewer tab and review panel
 */
#[\AllowDynamicProperties]
class CaseReview
{
    protected $app = null;
    protected $DB = null;
    protected $ftr = null;
    protected $clientDB = '';
    protected $userClass = 'client';

    protected $caseID = 0;
    protected $clientID = 0;

    protected $caseRow = null;

    protected $ddqID = 0;
    protected $prevDdqID = null;

    /**
     * users.id
     *
     * @var integer
     */
    protected $authUserID = null;

    /**
     * users.userType
     *
     * @var integer
     */
    protected $legacyUserType = null;

    /**
     * g_roles.legacyAccessID
     *
     * @var integer
     */
    protected $legacyAccessLevel = null;

    // intake form control types for comparison
    protected $controlTypes = [
        'radioYesNo',
        'PopDate',
        'DateDDL',
        'text',
        'textarea',
        'tarbYes',
        'textEmailField',
        'CountryOCListAJ',
        'CountryListAJ',
        'CountryList',
        'CountryOCList',
        'StateRegionList',
        'StateRegionListAJ',
        'CompanySivisionAJ',
        'DDLfromDB',
        'checkBox'
    ];

    /**
     * Get instance for specific case
     *
     * @param integer $clientID Client ID
     * @param integer $caseID   Case ID
     * @param object  $ftr      Instance of FeatureACL
     * @param array   $params   Optional params
     *
     * @return void
     */
    public function __construct(
        $clientID,
        $caseID,
        $ftr = null, /**
         * API login configuration
         */
        protected $params = []
    ) {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->ftr = (!empty($ftr) ? $ftr : \Xtra::app()->ftr);
        $this->clientID = intval($clientID);
        $this->caseID = intval($caseID);
        $this->clientDB =  $this->DB->getClientDB($this->clientID);
        $this->legacyAccessLevel = $this->ftr->legacyAccessLevel;
        if (empty($this->params)) {
            $cases = new Cases($this->clientID);
            $this->userClass = $this->app->session->get('authUserClass');
        } else {
            $cases = new Cases($this->clientID, $this->params);
            $this->isAPI = (!empty($this->params['isAPI']));
            if (!empty($this->params['authUserID'])) {
                $this->authUserID = $this->params['authUserID'];
                if (($user = (new User())->findById($this->params['authUserID'])) && $user->getAttributes()) {
                    $this->legacyUserType = $user->get('userType');
                    $this->userClass = (new UserType())->getUserClass(
                        $this->legacyAccessLevel,
                        $clientID,
                        $user->get('vendorID')
                    );
                }
            }
        }
        $this->caseRow = $cases->getCaseRow($this->caseID);
    }

    /**
     * Deviation of selected scope of risk model's recommended scope of due diligence
     *
     * @return object results
     */
    public function getScopeDeviation()
    {
        $txtTr = $this->app->trans->codeKeys([
            'no_recommendation',
            'due_diligence_not_completed',
            'unknown'
        ]);

        $rtn = new \stdClass();
        $rtn->applies = 0; // true if due diligence and there is a recomendation
        $rtn->completed = 0;
        $rtn->differs = 0;
        $rtn->recommended = '(' . $txtTr['no_recommendation'] . ')';
        $rtn->selected = '(' . $txtTr['due_diligence_not_completed'] . ')';
        $rtn->convertedBy = '(' . $txtTr['unknown'] . ')';
        $rtn->explain = '';

        $iCompDate = $this->caseRow['caseCompletedByInvestigator'];
        $completed = ($iCompDate && $iCompDate != '0000-00-00 00:00:00');
        if ($this->caseID && $completed && $this->caseRow['caseStage'] >= CaseStage::COMPLETED_BY_INVESTIGATOR) {
            $rtn->completed = 1;
            $rtn->selected = $this->getScopeName($this->caseRow['caseType']);
        }
        if (!$this->ftr->has(FeatureACL::TENANT_TPM) || !$this->ftr->has(FeatureACL::TENANT_TPM_RISK)
            || !$this->caseRow['rmID'] || !$this->caseRow['raTstamp']
        ) {
            return $rtn;
        }

        $recScope = $this->getRiskModelScope();
        if ($recScope) {
            $rtn->recommended = $this->getScopeName($recScope);
            $rtn->applies = intval($completed);
        }
        $rtn->differs = intval($rtn->applies && ($recScope != $this->caseRow['caseType']));
        if ($rtn->differs) {
            $userName = $this->getUserName();
            if ($userName) {
                $rtn->convertedBy = $userName;
            }
            $rtn->explain = $this->caseRow['caseDescription'];
        }
        return $rtn;
    }

    /**
     * Lookup riskModelTier scope
     *
     * @return int riskModelTier.scope
     */
    private function getRiskModelScope()
    {
        $params = [
            ':tpID' => $this->caseRow['tpID'],
            ':clientID' => $this->clientID,
            ':rmID1' => $this->caseRow['rmID'],
            ':rmID2' => $this->caseRow['rmID']
        ];
        $raTstamp = $this->caseRow['raTstamp']; //SQL timestamp
        if ($this->app->mode == 'Development') {
            $tstampCond = "(ra.tstamp = :raTstamp "
                . " OR ra.tstamp = DATE_ADD('$raTstamp', INTERVAL 5 HOUR) "
                . " OR ra.tstamp = DATE_ADD('$raTstamp', INTERVAL 6 HOUR))";
            $params[':raTstamp'] = $raTstamp;
        } else {
            $tstampCond = "ra.tstamp = :raTstamp";
            $params[':raTstamp'] = $raTstamp;
        }
        $sql = "SELECT rmt.scope FROM riskAssessment AS ra "
            . "LEFT JOIN riskModelTier AS rmt ON rmt.tier = ra.tier AND rmt.model = :rmID1 "
            . "WHERE ra.tpID = :tpID AND $tstampCond AND ra.model = :rmID2 AND ra.clientID = :clientID LIMIT 1";
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Lookup scope name from caseType
     *
     * @param integer $caseType cases.caseType
     *
     * @return string scope name
     */
    private function getScopeName($caseType)
    {
        $caseType = intval($caseType);
        $sql = "SELECT name FROM caseTypeClient WHERE clientID = :clientID AND caseTypeID = :caseType LIMIT 1";
        $params = [':clientID' => $this->clientID, ':caseType' => $caseType];
        $scopeName = $this->DB->fetchValue($sql, $params);
        if (!$scopeName) {
            $scopeName = "Scope #" . $caseType;
        }
        return $scopeName;
    }

    /**
     * Lookup user name from userLog
     *
     * @return string user name
     */
    private function getUserName()
    {
        $sql = "SELECT u.userName FROM userLog AS l "
            . "LEFT JOIN {$this->DB->authDB}.users AS u ON u.id = l.userID "
            . "WHERE l.caseID = :caseID AND l.eventID = 24 "
            . "AND l.clientID = :clientID ORDER BY l.id DESC LIMIT 1";
        $params = [':caseID' => $this->caseID, ':clientID' => $this->clientID];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Potential red flags indicated during investigation
     *
     * @return object results
     */
    public function getRedFlags()
    {
        $scopeInfo = $this->getScopeDeviation();
        $rtn = new \stdClass();
        $rtn->rfYesNo = '';
        $rtn->exFlags = [];
        $rtn->showNumbers = false; // client setting

        if (!$scopeInfo->completed) {
            return $rtn;
        }
        $rtn->rfYesNo = $this->getRedFlagYesNo();
        // any subscriber-defined red flags?
        $spID = intval($this->caseRow['caseAssignedAgent']);
        $sql = "SELECT redFlagID, howMany FROM {$this->clientDB}.redFlagCase WHERE caseID = :caseID "
            . "AND clientID = :clientID AND spID = :spID ";
        $params = [':caseID' => $this->caseID, ':clientID' => $this->clientID, ':spID' => $spID];
        if ($rflags = $this->DB->fetchKeyValueRows($sql, $params)) {
            $SP = new ServiceProvider($spID, $this->params);
            $useRedflagNumbers  = false;
            $showRedflagNumbers = false;
            try {
                if ($useRedflagNumbers = $SP->testSpOption('UseRedFlagNumbers')) {
                    if ($this->userClass == 'vendor') {
                        $showRedflagNumbers = true;
                    } else {
                        $showRedflagNumbers = $SP->testMappedSpFlag(
                            $this->clientID,
                            'ShowRedFlagNumbers'
                        );
                    }
                }
            } catch (\Exception) {
                // oh well, make a sensible guess
                $showRedflagNumbers = $useRedflagNumbers;
            }
            $rtn->showNumbers = $showRedflagNumbers;
            $redFlags = new RedFlags($spID, $this->clientID);
            $rfLookup = $redFlags->adjustedRedFlagLookup();
            foreach ($rfLookup as $rfID => $rfName) {
                if (array_key_exists($rfID, $rflags)) {
                    $obj = new \stdClass();
                    $obj->id = $rfID;
                    $obj->name = $rfName;
                    $obj->howMany = $rflags[$rfID];
                    $rtn->exFlags[] = $obj;
                }
            }
        }
        return $rtn;
    }

    /**
     * Get iCompleteInfo.redFlags - should be Yes, No or empty string
     *
     * @return string Yes, No, or empty string
     */
    private function getRedFlagYesNo()
    {
        $sql = "SELECT redFlags FROM iCompleteInfo WHERE caseID = :caseID LIMIT 1";
        $params = [':caseID' => $this->caseID];
        $value = strtolower((string) $this->DB->fetchValue($sql, $params));
        return ($value === 'yes' ? 'Yes' : 'No');
    }

    /**
     * Retype intake form version info
     *
     * @param integer $ddqID ddq.id
     *
     * @return array caseType and ddqQuestionVer
     */
    private function getDdqVersion($ddqID)
    {
        $ddqID = intval($ddqID);
        $sql = "SELECT caseType, ddqQuestionVer FROM ddq WHERE id = :ddqID AND clientID = :clientID";
        $params = [':ddqID' => $ddqID, ':clientID' => $this->clientID];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * DDQ answers that differ from expected answer
     *
     * @return object results
     */
    public function getUnexpectedResponses()
    {
        $rtn = new \stdClass();
        $rtn->hasDDQ = false;
        $rtn->qualifiedYN = false;
        $rtn->found = 0;
        $rtn->pgTabs = [];

        if ($this->ddqID === null) {
            $this->ddqID = $this->hasDdq($this->caseID);
        }
        if (!$this->ddqID) {
            return $rtn;
        }
        if (!($row = $this->getDdqVersion($this->ddqID))) {
            return $rtn;
        }
        // Establish pageTab order in return object
        // This allows picking and choosing by pageTab as needed on Case Folder
        // Differs for getDdqDiff in that getDdqDiff does not pick by pageTab
        foreach ($this->ddqTabs as $pg => $abbr) {
            $rtn->pgTabs[$pg] = ['abbr' => $abbr, 'unexpected' => []];
        }
        $found = 0;

        $ver = $this->db->esc($row['ddqQuestionVer']);
        $ddqType = intval($row['caseType']);
        $rtn->hasDDQ = true;
        $lang = 'EN_US';
        $sql = "SELECT questionID, labelText, generalInfo AS expected, pageTab, reviewerContext, dataIndex "
            . "FROM onlineQuestions WHERE clientID = :clientID "
            . "AND languageCode = :lang AND controlType = 'radioYesNo' AND qStatus = 1 "
            . "AND caseType = :ddqType AND ddqQuestionVer = :ver "
            . "AND FIND_IN_SET(generalInfo, 'Yes,No') "
            . "ORDER BY pageTab ASC, tabOrder ASC";
        $params = [':clientID' => $this->clientID, ':lang' => $lang, ':ddqType' => $ddqType, ':ver' => $ver];
        if (!($YNs = $this->DB->fetchObjectRows($sql, $params))) {
            return $rtn;
        }
        $rtn->qualifiedYN = true;
        $sql = "SELECT * FROM ddq WHERE id = :ddqID";
        $ddqRow = $this->DB->fetchAssocRow($sql, [':ddqID' => $this->ddqID]);
        // find the mismatches
        foreach ($YNs as $yn) {
            if (array_key_exists($yn->questionID, $ddqRow) && $ddqRow[$yn->questionID] <> $yn->expected) {
                $yn->response = $ddqRow[$yn->questionID];
                if (!array_key_exists($yn->pageTab, $this->ddqTabs)) {
                    $yn->pageTab = $this->unknownSection;
                }
                // clean it
                $yn->labelText = strip_tags(str_replace('&nbsp;', ' ', (string) $yn->labelText));
                $pg = $yn->pageTab;
                $qID = $yn->questionID;
                preg_match('/^(\d+),/', (string) $yn->reviewerContext, $match);
                if (isset($match[1]) && $match[1] > 0) {
                    $yn->helpWidth = $match[1];
                    $yn->reviewerContext = substr((string) $yn->reviewerContext, strlen($match[1]) + 1);
                    if ($yn->helpWidth < 50) {
                        $yn->helpWidth = 50;
                    }
                } else {
                    $yn->helpWidth = '400';
                }
                $yn->reviewerContext = $this->prepareContext($yn->reviewerContext);
                unset($yn->pageTab, $yn->questionID);
                $rtn->pgTabs[$pg]['unexpected'][$qID] = $yn;
                $found++;
            }
        }
        $rtn->found = $found;
        return $rtn;
    }

    /**
     * Return DDQ record id if case has submitted ddq
     *
     * @param integer $caseID cases.id
     *
     * @return mixed ddq.id on success, else false
     */
    private function hasDdq($caseID)
    {
        $caseID = intval($caseID);
        $sql = "SELECT id FROM ddq WHERE caseID = :caseID "
            . "AND clientID = :clientID AND status = 'submitted' AND caseType <> 0 LIMIT 1";
        $params = [':caseID' => $caseID, ':clientID' => $this->clientID];
        return intval($this->db->fetchValue($sql, $params));
    }

    /**
     * Prepare reviewerContect to be saved as JS string
     *
     * @param string $ctx reviewer help context
     *
     * @return string modified context
     */
    public function prepareContext($ctx)
    {
        $rtn = $ctx = trim($ctx);
        if ($ctx === '') {
            return $ctx;
        }
        $ctx = str_replace('&nbsp;', ' ', $ctx);
        $ctx = htmlspecialchars($ctx, ENT_QUOTES, 'UTF-8', true);
        $BBTags = new BBTags();
        try {
            $BBTags->validate($ctx);
            $rtn = $BBTags->tagsToStyle($ctx);
        } catch (\Exception) {
            $txtTr = $this->app->trans->codeKeys(['format_error']);
            $rtn = '<strong>' . $txtTr['format_error'] . ':</strong> &ensp; ' . $ctx;
        }
        return substr((str_replace('\/', '/', json_encode($rtn))), 1, -1);
    }

    /**
     * Prepare potential compliance issues list
     *
     * @return array
     */
    public function getCaseReviewDetails()
    {
        $rtn = [];
        $noteLink = '';
        $statusLink = '';
        if ($this->ftr->has(FeatureACL::TENANT_TPM_RISK)) {
            $scopeDeviation = $this->getScopeDeviation();
            $recommendedScopeDeviation = null;
            if ($scopeDeviation->differs) {
                $recommendedScopeDeviation = [
                    "recommendedScope"     => $scopeDeviation->recommended,
                    "changedTo"       => $scopeDeviation->selected,
                    "userExplanation" => $scopeDeviation->explain
                ];
            } else {
                $recommendedScopeDeviation = 'Nothing to review in regard to scope of due diligence';
                if (!$scopeDeviation->completed) {
                    $recommendedScopeDeviation = 'Due diligence not completed';
                } elseif (!$scopeDeviation->applies) {
                    $recommendedScopeDeviation = 'Recommendation not available';
                } elseif ($scopeDeviation->applies && !$scopeDeviation->differs) {
                    $recommendedScopeDeviation = 'No deviation; nothing to review';
                }
            }
            $rtn["recommendedScopeDeviation"] = $recommendedScopeDeviation;
        }

        // R3 - Unexpected Answers
        $YNres = $this->getUnexpectedResponses();
        $unexpectedIntakeFormResponse = 'Response expectations not configured';
        if ($YNres->hasDDQ) {
            $unexpectedIntakeFormResponse = 'No due diligence intake form has been submitted.';
        } elseif ($YNres->qualifiedYN) {
            $unexpectedIntakeFormResponse = 'Intake form has no qualified questions for comparison';
        } elseif ($YNres->found) {
            $unexpectedIntakeFormResponse = 'No differences; nothing to review';
        } else {
            if (is_array($YNres) && count($YNres)) {
                foreach ($YNres->pgTabs as $tabName => $tabInfo) {
                    if (!count($tabInfo->unexpected)) {
                        continue;
                    }
                    foreach ($tabInfo->unexpected as $qID => $yn) {
                        if ($yn['response'] == '') {
                            continue;
                        }
                    }
                }
                $unexpectedIntakeFormResponse = $YNres->pgTabs;
            }
        }
        $rtn["unexpectedIntakeFormResponse"] = $unexpectedIntakeFormResponse;

        // R2 - Red Flags
        $potentialRedFlagsIdentified = null;
        $listItems = false;
        if (!$scopeDeviation->completed) {
            $potentialRedFlagsIdentified = 'Red Flags not found';
        } else {
            $redFlags = $this->getRedFlags();
            if (is_array($redFlags->exFlags) && count($redFlags->exFlags)) {
                $listItems = true;
            } elseif ($redFlags->rfYesNo == 'Yes') {
                $listItems = true;
                $redFlags->exFlags[] = (object)[
                    'id' => 'Yes',
                    'name' => 'Yes, potential red flags are indicated.',
                    'howMany' => 0
                ];
            } else {
                $potentialRedFlagsIdentified = 'None';
            }
        }
        if ($listItems) {
            $redFlagArray = '';
            foreach ($redFlags->exFlags as $key => $flag) {
                $name = $flag->name;
                if ($redFlags->showNumbers && $flag->howMany >= 1) {
                    $name .= " ($flag->howMany)";
                }
                $potentialRedFlagsIdentified[] = $name;
            }
        }
        $rtn["potentialRedFlagsIdentified"] = $potentialRedFlagsIdentified;
        return $rtn;
    }
}
