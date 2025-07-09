<?php
/**
 * Controller: PepsiCo Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Models\TPM\ClientEmails;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/PepsiCo.php provides Workflow functionality for
 * PepsiCo (TenantID = 272)
 */
#[\AllowDynamicProperties]
class PepsiCo extends TenantWorkflow
{
    /**
     * PepsiCo constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow() || ($this->tenantID != ClientIds::PEPSICO_CLIENTID)) {
            throw new \Exception("Not PepsiCo or PepsiCo doesn't have Workflow "
                ."Feature/Setting enabled.");
        }
    }

    /**
     * Process only Scorecard (internal) ddq forms. Current Scorecard (internal) form types are:
     *
     *      Scorecard Intake Form - TPDD PROFILE FORM  (L-90) - DUE_DILIGENCE_SHORTFORM
     *
     * Conditions for processing are:
     *
     *     - Sector and Region of the Business Sponsor (genQuest118) == 'AMEANA'
     *
     * @param integer $ddqID     DDQ ID
     * @param integer $profileID 3P Profile ID
     * @param integer $caseType  Case type
     *
     * @return void
     */
    public function scorecardSubmitted($ddqID, $profileID, $caseType)
    {
        try {
            // Prevent unwanted DDQ types from being processed
            if ($caseType != DUE_DILIGENCE_SHORTFORM) {
                return;
            }

            if (!$profileID) {
                $answer = $this->getDdqAsnwer($ddqID);
                // Only send notification for Sector and Region of the Business Sponsor == AMENA (185
                if (!empty($answer) && $answer == '185') {
                    $this->getComplianceEmail($this->tenantID, 0);
                    if (!empty($this->complianceEmail)) {
                        $sql = "SELECT caseID FROM ddq WHERE id = :id AND clientID = :clientID";
                        $caseID = $this->app->DB->fetchValue($sql, [':id' => $ddqID, ':clientID' => $this->tenantID]);
                        $sql = "SELECT id, userType, mgrDepartments, mgrRegions FROM {$this->app->DB->authDB}.users\n"
                            . "WHERE userName = :userName AND userEmail = :userEmail ORDER BY id ASC LIMIT 1";
                        $params = [
                            ':userName' => $this->complianceEmail['toName'],
                            ':userEmail' => $this->complianceEmail['toEmail']
                        ];
                        $user = $this->app->DB->fetchAssocRow($sql, $params);
                        (new ClientEmails($this->tenantID))->send(
                            $user['id'],
                            $user['userType'],
                            $user['mgrDepartments'],
                            $user['mgrRegions'],
                            0,
                            $caseID,
                            'workflowComplianceEmail',
                            'EN_US',
                            702,
                            $this->complianceEmail
                        );
                    }
                }
            }
        } catch (\Exception) {
            // Failed to connect to start Third Party Profile Workflow.
            $msg = "Failed to send email to PepsiCoBusiness Sponsor for OpenURL DDQ {$ddqID}.";
            $this->app->log(__FILE__ . ":" . __LINE__ . " errror = $msg");
            $this->dbCon = false;
        }
    }

    /**
     *  Get DDQ answer to see if other actions need to be triggered.
     *
     * @param integer $ddqID DDQ ID
     *
     * @return string Answers text
     */
    private function getDdqAsnwer($ddqID)
    {
        // get DDQ answer to question
        $sql = "SELECT genQuest118 FROM ddq WHERE id = :id";
        return $this->app->DB->fetchValue($sql, [':id' => (int)$ddqID]);
    }

    /**
     * Get the email info for the Region GC (General Council) to send a notification to
     *
     * @param integer $clientID Client ID
     * @param integer $regionID Region ID
     *
     * @return void
     */
    private function getComplianceEmail($clientID, $regionID)
    {
        if (empty($this->complianceEmail)) {
            $sql = "SELECT toEmail, toName FROM {$this->app->DB->globalDB}.g_regionEmailMap"
                . " WHERE clientID = :clientID AND regionID = :regionID LIMIT 1";
            $emailInfo = $this->app->DB->fetchAssocRow($sql, [':clientID' => $clientID, ':regionID' => $regionID]);

            if ($emailInfo) {
                $this->complianceEmail = $emailInfo;
            }
        }
    }
}
