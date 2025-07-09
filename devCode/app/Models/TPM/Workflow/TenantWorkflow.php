<?php
/**
 * Base Tenant Workflow class
 */

namespace Models\TPM\Workflow;

use Controllers\TPM\Email\Cases\Invitation;
use Lib\FeatureACL;
use Lib\InvitationControl;
use Lib\Legacy\IntakeFormTypes;
use Lib\Legacy\SysEmail;
use Models\Ddq;
use Models\Globals\Languages;
use Models\Globals\Features\TenantFeatures;
use Models\User;

/**
 * Class TenantWorkflow
 *
 * @package Models\TPM\Workflow
 */
#[\AllowDynamicProperties]
class TenantWorkflow
{
    /**
     * @var array contains the genQuest ids and unexpected answers for ddqSubmitted
     */
    protected $flaggedQuestions = [];

    /**
     * @var array contains the event ids which require tasks to be present to advance a Tenant's workflow
     */
    public $requiredTasks = [];

    /**
     * These constants represent the Tenant Workflow events which are supported within the application.
     * Please keep these in alphabetical order.
     * Please DO NOT REMOVE these constants. Removing any of these constants will lead to instability of workflow.
     */
    public const BATCH_PROFILE_REVIEW       = 7;  // Perform a batch of review on the Third Party Profile
    public const CASE_FOLDER_REVIEW         = 6;  // Performing reviews on the Case Folder
    public const DDQ_SUBMITTED              = 3;  // Submission of DDQ by the Third Party
    public const CASE_LINK_CREATED_PROFILE  = 10; // Case linked to a newly created Third Party Profile
    public const CASE_LINK_EXISTING_PROFILE = 9;  // Case linked to an existing Third Party Profile
    public const ENGAGEMENT_CREATED         = 16; // Engagement created
    public const MANUAL_SEND_DDQ            = 11; // DDQ sent to Third Party because of user action
    public const PREVIEW_PROFILE_WORKFLOW   = 12; // Preview the Third Party workflow
    public const PROFILE_APPROVAL           = 5;  // Final approval of the Third Party Profile
    public const PROFILE_CREATED            = 1;  // Third Party Profile created
    public const PROFILE_REVIEW             = 4;  // Performing reviews on the Third Party Profile
    public const SEND_DDQ                   = 2;  // DDQ sent to Third Party automatically
    public const SCORECARD_AUTOMATION       = 13; // Automatically process Scorecard and send associated DDQ
    public const SCORECARD_EMAIL            = 15; // Based upon DDQ answers change the submit recipient email address
    public const START_WORKFLOW             = 14; // Provides a means to enable and disable Workflows via g_workflowEvent

    /**
     * TenantWorkflow constructor.
     *
     * @param int         $tenantID g_tenants.id
     * @param \FeatureACL $ftr      Instance of FeatureACL for use with CLI
     */
    public function __construct(protected $tenantID, $ftr = null)
    {
        $this->app = \Xtra::app();
        $this->app->DB->clientDB = $this->app->DB->getClientDB($this->tenantID);
        $this->flaggedQuestions = $this->getFlaggedQuestions();
        $this->requiredTasks = $this->getRequiredTasks();
    }

    /**
     * Determine if the tenant has the appropriate features enabled to utilize workflow
     *
     * @todo Once we have a queue system not having $this->dbCon as true should fallback to the queue
     *
     * @return mixed
     */
    public function tenantHasWorkflow()
    {
        $ftr = new TenantFeatures($this->tenantID);
        // Legacy Workflow (Axon Ivy).
        $lgcFeatures = $ftr->tenantHasFeatures(
            [\Feature::TENANT_API_INTERNAL, \Feature::TENANT_WORKFLOW],
            \Feature::APP_TPM
        );
        // Workflow 2.0 (Osprey).
        $feature = $ftr->tenantHasFeature(\Feature::TENANT_WORKFLOW_OSP, \Feature::APP_TPM);
        return (($lgcFeatures[\Feature::TENANT_API_INTERNAL] && $lgcFeatures[\Feature::TENANT_WORKFLOW]) || $feature);
    }

    /**
     * Get the appropriate workflow class for the current tenant
     *
     * @return mixed
     */
    public function getTenantWorkflowClass()
    {
        $rtn = '';
        try {
            // Look for the appropriate class name
            $className = "Models\\TPM\\Workflow\\" . $this->getTenantWorkflowClassName($this->tenantID);
            if (class_exists($className)) { // If class exists, instantiate and pass back class instance.
                $rtn = (new $className($this->tenantID));
            }
        } catch (\Exception $e) {
            \Xtra::app()->log->info(['TenantWorkflow->getTenantWorkflowClass() error' => $e->getMessage()]);
        }
        return $rtn;
    }

    /**
     * Get the class name assigned to the tenant
     *
     * @param int $tenantID Tenant ID
     *
     * @return string name of class
     */
    private function getTenantWorkflowClassName($tenantID)
    {
        $sql = "SELECT className FROM {$this->app->DB->globalDB}.g_workflowClass"
            . " WHERE tenantID = :tenantID";
        $bind = [
            ':tenantID' => $tenantID
        ];
        return $this->app->DB->fetchValue($sql, $bind);
    }

    /**
     * See if a tenant has a specific wfEvent
     *
     * @param int $eventID id for the workflow event, constant in AxonIvyData
     *                     and record in g_workflowEvent.eventID
     *
     * @return mixed
     */
    public function tenantHasEvent($eventID)
    {
        $sql = "SELECT COUNT(*) FROM {$this->app->DB->globalDB}.g_workflowEvent "
            . "WHERE tenantID = :tenantID AND eventID = :eventID";
        $bind = [
            ':tenantID' => $this->tenantID,
            ':eventID'  => $eventID
        ];
        return $this->app->DB->fetchValue($sql, $bind);
    }

    /**
     * See if a tenant has all of the specified workflow events
     *
     * @param array $events array of g_workflowEvent.eventIDs
     *
     * @return boolean
     */
    public function tenantHasEvents($events)
    {
        if (is_array($events) && count($events) > 0) {
            foreach ($events as $event) {
                if (!$this->tenantHasEvent($event)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Automates the process of sending a DDQ and creating a Case Folder.
     *
     * @param array $thirdParty third party profile record
     * @param int   $formType   form type for the DDQ e.g. L-95 would be the int 95, likewise L-95b would be 95
     *
     * @throws \Exception cannot send email
     * @return bool
     */
    public function autoSendDDQ($thirdParty, $formType = IntakeFormTypes::DUE_DILIGENCE_SBI)
    {
        if (!empty($thirdParty['POCemail'])) {
            // if we are running in the context of a DDQ submit action a user will not be present, so fetch the
            // appropriate user (ownerID) from the ThirdParty Profile
            if (empty($this->app->ftr->user)) {
                $sql = "SELECT ownerID AS userID FROM thirdPartyProfile WHERE id = :tpID";
                $user = $this->app->DB->fetchAssocRow($sql, [':tpID' => (int)$thirdParty['id']]);
                $userID = $user['userID'];
                \Xtra::app()->session->set('authUserID', $userID); // need this to construct the email correctly
            } else {
                $userID = $this->app->ftr->user;
            }

            $user = (new User())->findById($userID);

            $Ddq = new Ddq($this->tenantID);
            $invitationControl = new InvitationControl(
                $this->tenantID,
                ['due-diligence', 'internal'],
                $this->app->ftr->has(FeatureACL::SEND_INVITE),
                $this->app->ftr->has(FeatureACL::RESEND_INVITE)
            );
            $inviteControl = $invitationControl->getInviteControl($thirdParty['id']);
            $nameParts = $Ddq->splitDdqLegacyID($inviteControl['formList'][$formType]['legacyID']);

            $langCode = $this->getInviteEmailLang(
                $this->tenantID,
                $thirdParty,
                $nameParts['caseType'],
                SysEmail::EMAIL_SEND_DDQ_INVITATION
            );

            $ddqInviteVals = [
                'ddq' => [
                    'loginEmail' => $thirdParty['POCemail'],
                    'name' => $thirdParty['legalName'],
                    'country' => $thirdParty['country'],
                    'state' => $thirdParty['state'],
                    'POCname' => $thirdParty['POCname'],
                    'POCposi' => $thirdParty['POCposi'],
                    'POCphone' => $thirdParty['POCphone1'],
                    'POCemail' => $thirdParty['POCemail'],
                    'caseType' => $nameParts['caseType'],
                    'street' => $thirdParty['addr1'],
                    'addr2' => $thirdParty['addr2'],
                    'city' => $thirdParty['city'],
                    'postCode' => $thirdParty['postcode'],
                    'DBAname' => $thirdParty['DBAname'],
                    'companyPhone' => substr((string) $thirdParty['POCphone1'], 0, 40),
                    'stockExchange' => $thirdParty['stockExchange'],
                    'tickerSymbol' => $thirdParty['tickerSymbol'],
                    'ddqQuestionVer' => $nameParts['ddqQuestionVer'],
                    'subInLang' => $langCode,
                    'formClass' => 'due-diligence',
                    'id' => 0,
                    'logLegacy' => ", Intake Form: `{$thirdParty['legalName']} ([$formType]['legacyID'])`",
                ],
                'cases' => [
                    'caseName' => $thirdParty['legalName'],
                    'region' => (int)$thirdParty['region'],
                    'dept' => (int)$thirdParty['department'],
                    'caseCountry' => $thirdParty['country'],
                    'caseState' => $thirdParty['state'],
                    'requestor' => $user->get('userid'),
                    'creatorUID' => $user->get('userid'),
                    'tpID' => (int)$thirdParty['id'],
                ],
                'subjectInfoDD' => [
                    'name' => $thirdParty['legalName'],
                    'country' => $thirdParty['country'],
                    'subStat' => '',
                    'pointOfContact' => $thirdParty['POCname'],
                    'POCposition' => $thirdParty['POCposi'],
                    'phone' => $thirdParty['POCphone1'],
                    'emailAddr' => $thirdParty['POCemail'],
                    'street' => $thirdParty['addr1'],
                    'addr2' => $thirdParty['addr2'],
                    'city' => $thirdParty['city'],
                    'postCode' => $thirdParty['postcode'],
                    'DBAname' => $thirdParty['DBAname'],
                ]
            ];

            $caseID = $Ddq->createInvitation($ddqInviteVals);
            $ddq = (new Ddq($this->tenantID))->findByAttributes(['caseID' => $caseID]);
            (new Invitation($this->tenantID, $ddq->getID()))->send();
        }
        return true;
    }

    /**
     * Handles a user manually sending a DDQ
     *
     * @param int $profileID thirdPartyProfile.id
     * @param int $ddqID     ddq.id
     *
     * @Note simply here to prevent error if child class does not implement manualSendDDQ
     *
     * @return void
     */
    public function manualSendDDQ($profileID, $ddqID)
    {
        return;
    }

    /**
     * Handles the DDQ_SUBMITTED Workflow event
     *
     * @param int    $caseID   Case folder id - cases.id
     * @param int    $ddqID    DDQ id - ddq.id
     * @param string $question the column name for the DDQ question to select a value from
     * @param bool   $renewal  indicates whether the DDQ is part of a renewal
     * @param bool   $delay    determines whether a delay should be provided for asynchronous calls - useful for CLI
     *
     * @return void
     */
    public function ddqSubmitted($caseID, $ddqID, $question, $renewal = false, $delay = false)
    {
        return;
    }

    /**
     * Handles the CASE_FOLDER_REVIEW Workflow event
     *
     * @param int    $profileID     thirdPartyProfile.id
     * @param string $action        'approve' or 'reject'
     * @param int    $caseID        cases.id
     * @param string $explain       desired note; the reason for approval or rejection
     * @param int    $determination outcome of the review
     *
     * @return mixed
     */
    public function caseFolderReview($profileID, $action, $caseID, $explain, $determination)
    {
        return false;
    }

    /**
     * Handles the PROFILE_APPROVAL Workflow event
     *
     * @param int    $profileID     thirdPartyProfile.id
     * @param string $action        'approve' or 'reject'
     * @param string $explain       desired note; the reason for approval or rejection
     * @param int    $determination outcome of the review
     *
     * @return mixed
     */
    public function profileApproval($profileID, $action, $explain, $determination)
    {
        return false;
    }

    /**
     * Determines if there are reviews in progress for a given record
     *
     * @param int    $tenantID   g_tenants.id
     * @param int    $recordID   thirdPartyProfile.id or cases.id, desired record id
     * @param string $recordType the type of record: 'profile' or 'case'
     *
     * @return bool
     */
    public function reviewsInProgress($tenantID, $recordID, $recordType = 'profile')
    {
        return false;
    }

    /**
     * Get the language code for the specified email type and case type
     *
     * @param integer $clientID Client ID
     * @param array   $profile  3P Profile record
     * @param integer $caseType Case Type
     * @param integer $EMtype   Email Type
     *
     * @return string
     */
    protected function getInviteEmailLang($clientID, $profile, $caseType, $EMtype)
    {
        $langs = [];
        $lang = 'EN_US';
        $defaultCaseType = '12';

        $sql = "SELECT DISTINCT languageCode FROM {$this->app->DB->clientDB}.systemEmails"
            . " WHERE clientID = :clientID AND EMtype = :EMtype AND caseType = :caseType";

        if ($caseType) {
            $params = [':clientID' => $clientID, ':EMtype' => $EMtype, ':caseType' => $caseType];
            $langs = $this->app->DB->fetchValueArray($sql, $params);
        }

        if (!$langs) { // try default caseType (12)
            $params = [':clientID' => $clientID, ':EMtype' => $EMtype, ':caseType' => $defaultCaseType];
            $langs = $this->app->DB->fetchValueArray($sql, $params);
        }

        if (!$langs) { // try default caseType (12) and clientID 0
            $params = [':clientID' => 0, ':EMtype' => $EMtype, ':caseType' => $defaultCaseType];
            $langs = $this->app->DB->fetchValueArray($sql, $params);
        }

        if ($langs) {
            $ftr = new TenantFeatures($clientID);
            if ($ftr->tenantHasFeature(\Feature::TENANT_USE_COUNTRY_LANG, \Feature::APP_TPM)
                && !empty($profile['country'])
            ) {
                $lang = (new Languages())->getCountryLanguage($clientID, $profile['country'], $langs);
            }
        }

        return $lang;
    }

    /**
     * Determines if the current user can launch Workflow review batches
     *
     * @param int $recordID the record id such as thirdPartyProfile.id, case.id, ddq.id
     *
     * @return mixed
     */
    public function userCanLaunchBatchReview($recordID)
    {
        return false;
    }

    /**
     * Determines if a review batch launch is available for the given record
     *
     * @param int    $tenantID   g_tenants.id
     * @param int    $recordID   the record id such as thirdPartyProfile.id, case.id, ddq.id
     * @param string $recordType the type of record such as profile, case, or ddq
     *
     * @return mixed
     */
    public function reviewBatchLaunchAvailable($tenantID, $recordID, $recordType = "profile")
    {
        return false;
    }

    /**
     * Determines whether this is the initial review batch being launched
     *
     * @param int    $tenantID   g_tenants.id
     * @param int    $recordID   the record id such as thirdPartyProfile.id, case.id, ddq.id
     * @param string $recordType the type of record such as profile, case, or ddq
     *
     * @return bool
     */
    public function initialReviewBatchLaunch($tenantID, $recordID, $recordType = "profile")
    {
        // If a workflow does not exist, this is the initial batch launch.
        return false;
    }

    /**
     * Get the risk level of a profile.
     *
     * @param int $profileID thirdPartyProfile.id
     *
     * @return string
     */
    public function getRiskLevel($profileID)
    {
        $sql = "SELECT rt.tierName AS risk FROM {$this->app->DB->clientDB}.thirdPartyProfile tp "
            . "LEFT JOIN {$this->app->DB->clientDB}.riskAssessment AS ra "
            . "ON (ra.tpID = tp.id AND ra.model = tp.riskModel AND ra.status = 'current') "
            . "LEFT JOIN {$this->app->DB->clientDB}.riskTier AS rt ON rt.id = ra.tier "
            . "LEFT JOIN {$this->app->DB->clientDB}.riskModelTier AS rmt "
            . "ON rmt.tier = ra.tier AND rmt.model = tp.riskModel "
            . "WHERE tp.id = :id";
        return $this->app->DB->fetchValue($sql, [':id' => $profileID]);
    }

    /**
     * Get the flagged questions for the current tenant
     *
     * @return array Returns an array of questions that are being flagged
     */
    public function getFlaggedQuestions()
    {
        $sql = "SELECT genQuestID, flaggedValue FROM {$this->app->DB->globalDB}.g_workflowFlaggedQuestions "
            . "WHERE tenantID = :tenantID";
        $questions = $this->app->DB->fetchAssocRows($sql, [':tenantID' => $this->tenantID]);
        if (is_array($questions) && count($questions) > 0) {
            foreach ($questions as $question) {
                $this->flaggedQuestions[$question['genQuestID']] = $question['flaggedValue'];
            }
        }

        return $this->flaggedQuestions;
    }

    /**
     * Checks if any of the Tenant's flagged questions have "unexpected answers"
     *
     * @param int $ddqID ddq.id
     *
     * @return bool
     */
    public function checkFlaggedQuestions($ddqID)
    {
        if (count($this->flaggedQuestions) > 0) {
            $keys = array_keys($this->flaggedQuestions);
            $sql = "SELECT ";
            for ($i = 0; $i < count($keys); $i++) {
                // Append to SQL query, avoid additional comma if last entry in field list.
                $sql .= "genQuest{$keys[$i]} AS '{$keys[$i]}'";
                $sql .= ($i != count($keys) - 1) ? ", " : " ";
            }
            $sql .= "FROM {$this->app->DB->clientDB}.ddq WHERE id = :id";
            $answers = $this->app->DB->fetchAssocRow($sql, [":id" => $ddqID]);

            // Compares arrays via keys and vals to determine if any flagged questions had answers we are interested in.
            return (is_array($answers) && count(array_intersect_assoc($this->flaggedQuestions, $answers)));
        }

        return false;
    }

    /**
     * Get the details for a workflow notification based on the country ISO code
     *
     * @param string $iso 2 character ISO code E.G. US for United States
     *
     * @return mixed
     */
    public function getWorkflowNotificationDetails($iso)
    {
        $rtn = false;
        if (!empty($iso)) {
            $sql = "SELECT w.*, u.userType, u.mgrDepartments, u.mgrRegions\n"
                . "FROM {$this->app->DB->globalDB}.g_workflowNotifications AS w\n"
                . "LEFT JOIN {$this->app->DB->authDB}.users AS u on u.id = w.userID\n"
                . "WHERE iso = :iso AND tenantID = :tenantID";
            $rtn = $this->app->DB->fetchAssocRow($sql, [':iso' => $iso, ':tenantID' => $this->tenantID]);
        }
        return $rtn;
    }

    /**
     * Get the eventIDs for events which require tasks to be present advance the current Tenant's workflow
     *
     * @return array
     */
    public function getRequiredTasks()
    {
        $required = [];
        $sql = "SELECT eventID FROM {$this->app->DB->globalDB}.g_workflowEvent "
            . "WHERE taskRequired = 1 AND tenantID = :tenantID";
        $events = $this->app->DB->fetchAssocRows($sql, [':tenantID' => $this->tenantID]);

        if (is_array($events) && count($events) > 0) {
            foreach ($events as $event) {
                $required[] = $event['eventID'];
            }
        }

        return $required;
    }

    /**
     * Determines if there is an existing Workflow for a third party profile
     *
     * @param int $tenantID g_tenants.id
     * @param int $recordID thirdPartyProfile.id
     *
     * @return mixed
     */
    public function workflowExists($tenantID, $recordID)
    {
        $sql = "SELECT COUNT(*) FROM {$this->app->DB->globalDB}.g_workflowInitiated "
            . "WHERE tenantID = :tenantID AND profileID = :profileID";
        $binds = [':tenantID' => $tenantID, ':profileID' => $recordID];
        return $this->app->DB->fetchValue($sql, $binds);
    }

    /**
     * Creates a record in g_workflowInitiated to indicate that a workflow for a third party profile exists
     *
     * @param int $tenantID g_tenants.id
     * @param int $recordID thirdPartyProfile.id
     *
     * @return mixed
     */
    public function createWorkflowExistsEntry($tenantID, $recordID)
    {
        $sql = "INSERT INTO {$this->app->DB->globalDB}.g_workflowInitiated (tenantID, profileID) "
            . "VALUES (:tenantID, :profileID)";
        $binds = [':tenantID' => $tenantID, ':profileID' => $recordID];
        return $this->app->DB->query($sql, $binds);
    }
}
