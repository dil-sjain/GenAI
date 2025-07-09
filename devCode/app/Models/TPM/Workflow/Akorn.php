<?php
/**
 * Model: Akorn Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Models\TPM\ClientEmails;

/**
 * Class Akorn -  Delta version of app/Models/TPM/Workflow/Akorn.php provides workflow functionality
 * for Akorn (TenantID 337)
 */
#[\AllowDynamicProperties]
class Akorn extends TenantWorkflow
{
    /**
     * Contains the email address and to name
     *
     * @var array $workflowEmail
     */
    private $workflowEmail = [];

    /**
     * Akorn constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow() || ($this->tenantID !=  ClientIds::AKORN_CLIENTID)) {
            throw new \Exception("Not Akorn or Akorn doesn't have Workflow Feature/Setting enabled.");
        }
    }

    /**
     * Starts the Akorn workflow
     *
     * @param int     $tpID     Third Party Profile id - thirdPartyProfile.id
     * @param int     $caseID   Case Folder id of the intake form - cases.id
     * @param boolean $isUpload Flag to indicate if method is being called from the 3P Upload process or not
     *
     * @note Change data type of $result returned from sendRequest
     *
     * @return mixed
     */
    public function startProfileWorkflow($tpID, $caseID = null, $isUpload = false)
    {
        try {
            if (!$caseID) {
                throw new \Exception("Workflow Case ID not specified for client Akorn, Case ID: $caseID");
            }
            $sql = "SELECT id, caseType FROM ddq WHERE caseID = :caseID AND clientID = :tenantID";
            $ddqRec = $this->app->DB->fetchAssocRow($sql, [':caseID' => $caseID, ':tenantID' => $this->tenantID]);
            if ($ddqRec) {
                $this->processScoreCard($ddqRec['id'], $tpID, $ddqRec['caseType']);
            } else {
                throw new \Exception("DDQ Record not found for client Akorn workflow, Case ID: $caseID");
            }
        } catch (\Exception) {
            $msg = "Failed to start Profile Workflow for {$tpID}.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
            $this->dbCon = false;
        }
    }

    /**
     *  Get specific Scorecard (internal DDQ) answers to see if other actions need to be triggered.
     *
     * @param integer $ddqID Scorecard DDQ ID
     *
     * @return bool Return true if unexpected answer found, else return false
     */
    private function getDdqAnswers($ddqID)
    {
        $questionSQL = '';
        $assessmentQs = [
            'genQuest123',
            'genQuest125',
            'genQuest131',
            'genQuest133',
            'genQuest136',
            'genQuest138',
            'genQuest147',
            'genQuest149',
            'genQuest151',
            'genQuest153',
            'genQuest155',
            'genQuest157',
            'genQuest159'
        ];

        foreach ($assessmentQs as $val) {
            $questionSQL .= " $val,";
        }
        $questionSQL = rtrim($questionSQL, ',');

        // get DDQ answers to questions
        $sql = "SELECT {$questionSQL} FROM ddq WHERE id = :id";
        $ddqResult = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$ddqID]);

        if (!empty($ddqResult)) {
            $ids = [];
            foreach ($ddqResult as $idx => $val) {
                if ($val) {
                    $ids[(int)$val] = $val;
                }
            }
            $cfIds = implode(',', $ids);
            $cfIds = rtrim($cfIds, ',');

            $sql = "SELECT id, `name` FROM customSelectList WHERE id IN ($cfIds)";
            $cfResult = $this->app->DB->fetchAssocRows($sql);

            $cf= [];
            foreach ($cfResult as $val) {
                $cf[$val['id']] = $val['name'];
            }

            // See if we have 'Yes' answered to any of the questions
            foreach ($ddqResult as $val) {
                if (!empty($cf[$val]) && ($cf[$val] == 'Yes')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the CaseID and subByEmail associated with the DDQ
     *
     * @param integer $ddqID DDQ ID
     *
     * @return mixed assoc array or false if not found
     */
    private function getDdqEmailAndCaseID($ddqID)
    {
        $sql = "SELECT caseID, subByEmail FROM ddq WHERE id = :id LIMIT 1";
        $ddqInfo = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$ddqID]);

        return $ddqInfo;
    }

    /**
     *  Unique to the Akorn DDQ configuration, only for "Internal" questionnaires, the DDQ subByEmail address is not
     *  necessarily stored in the ddq.subByEmail column. For the purposes of having the email address show up
     *  in Case Custom Fields, it may be stored in ddq.genQuest135 column (subByEmail will be empty)
     *
     * @param integer $ddqID Scorecard DDQ ID
     *
     * @return string Return email address
     */
    private function getDdqSubByEmail($ddqID)
    {
        $emailAddr = '';
        $sql = "SELECT subByEmail, genQuest135 FROM ddq WHERE id = :id";
        $ddqResult = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$ddqID]);

        if (!empty($ddqResult)) {
            $emailAddr = (!empty($ddqResult['subByEmail'])) ? $ddqResult['subByEmail'] : $ddqResult['genQuest135'];
        }

        return $emailAddr;
    }

    /**
     * Get the 3P Profile record associated with the DDQ
     *
     * @param integer $profileID Profile ID
     *
     * @return mixed 3P Profile assoc array or false if not found
     */
    private function getTpProfile($profileID)
    {
        $sql = "SELECT * FROM thirdPartyProfile WHERE id = :id LIMIT 1";
        $profile = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$profileID]);

        return $profile;
    }

    /**
     * Get the email info for Compliance, Vendor Master and DDQ subByEmail to send a notification to
     *
     * @param integer $clientID Client ID
     * @param integer $ddqID    DDQ ID
     * @param integer $regionID Region ID
     *
     * @return void
     */
    private function getWorkflowEmail($clientID, $ddqID, $regionID)
    {
        if (empty($this->workflowEmail)) {
            $sql = "SELECT toEmail, toName FROM {$this->app->DB->globalDB}.g_regionEmailMap"
                . " WHERE clientID = :clientID AND regionID = :regionID";
            $emailInfo = $this->app->DB->fetchAssocRows($sql, [':clientID' => $clientID, ':regionID' => $regionID]);

            if ($emailInfo) {
                $this->workflowEmail = ['toEmail' => '', 'toName' => ''];
                foreach ($emailInfo as $email) {
                    $this->workflowEmail['toEmail'] .= $email['toEmail'] . ',';
                    $this->workflowEmail['toName']  .= $email['toName'] . ',';
                }
                $this->workflowEmail['toEmail'] .= $this->getDdqSubByEmail($ddqID);

                $this->workflowEmail['toEmail'] = rtrim($this->workflowEmail['toEmail'], ',');
                $this->workflowEmail['toName']  = rtrim($this->workflowEmail['toName'], ',');
            }
        }
    }

    /**
     * Process only Scorecard (internal) ddq forms. Current Akorn Scorecard (internal) form types are:
     *
     *      TPI Business Justification Form (L-36) - DDQ_SHORTFORM_FORM3
     *
     * @param integer $ddqID     DDQ ID
     * @param integer $profileID 3P Profile ID
     * @param integer $caseType  Case type
     *
     * @return void
     */
    private function processScoreCard($ddqID, $profileID, $caseType)
    {
        try {
            // if it's a scorecard (internal ddq) do the post processing
            if (!empty($profileID)
                && $this->tenantHasEvent(self::SCORECARD_AUTOMATION)
                && in_array($caseType, [IntakeFormTypes::DDQ_SHORTFORM_FORM3])
            ) {
                $emType = 0;
                $yesAnswer = $this->getDdqAnswers($ddqID);
                if ($yesAnswer) { // at least one DDQ "Yes" answer
                    $emType = 1;
                } else { // all DDQ "No" answers
                    $riskTier = $this->getRiskLevel($profileID);
                    if (!empty($riskTier) && (str_contains(strtolower($riskTier), 'low'))) {
                        $emType = 7;
                    }
                }
                $profile = $this->getTpProfile($profileID);

                if ($profile && $emType) {
                    // if no $_SESSION['userid'] then set it from the 3P Profile Internal Owner ID
                    $userID = (empty($_SESSION['userid'])) ? $profile['ownerID'] : $_SESSION['id'];
                    $sql = "SELECT userid, userType, mgrDepartments, mgrRegions\n"
                        . "FROM {$this->app->DB->authDB}.users WHERE id = :id";
                    $user = $this->app->DB->fetchAssocRow($sql, [':id' => $userID]);
                    if (empty($_SESSION['userid'])) {
                        $_SESSION['userid'] = $user['userid'];
                    }
                    $ddqInfo = $this->getDdqEmailAndCaseID($ddqID);
                    if ($ddqInfo) {
                        // send the notification to additional recipients
                        $this->getWorkflowEmail($profile['clientID'], $ddqID, $emType);
                        if (!empty($this->workflowEmail)) {
                            (new ClientEmails($this->tenantID))->send(
                                $userID,
                                $user['userType'],
                                $user['mgrDepartments'],
                                $user['mgrRegions'],
                                $profileID,
                                $ddqInfo['caseID'],
                                '',
                                'EN_US',
                                $emType,
                                $this->workflowEmail,
                                true
                            );
                        }
                    }
                }
            }
        } catch (\Exception) {
            $msg = "Failed to process Scorecard Workflow for DDQ {$ddqID} for profile ID: {$profileID}.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
            $this->dbCon = false;
        }
    }

    /**
     * Process Scorecard (internal) ddq forms. Current Akorn Scorecard (internal) form types are:
     *
     *      TPI Business Justification Form (L-36) - DDQ_SHORTFORM_FORM3
     *
     * @param integer $ddqID     DDQ ID
     * @param integer $profileID 3P Profile ID
     * @param integer $caseType  Case type
     *
     * @return void
     */
    public function scorecardSubmitted($ddqID, $profileID, $caseType)
    {
        $this->processScoreCard($ddqID, $profileID, $caseType);
    }
}
