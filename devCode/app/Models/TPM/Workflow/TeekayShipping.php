<?php
/**
 * Controller: TeekayShipping Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Models\TPM\ClientEmails;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/TeekayShipping.php provides Workflow
 * functionality for TeekayShipping (TenantID = 338)
 */
#[\AllowDynamicProperties]
class TeekayShipping extends TenantWorkflow
{
    /**
     * TeekayShipping constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow() || ($this->tenantID != ClientIds::TEEKAYSHIPPING_CLIENTID)) {
            throw new \Exception("Not TeekayShipping or TeekayShipping doesn't have Workflow "
                ."Feature/Setting enabled.");
        }
    }

    /**
     * Starts the TeekayShipping Third Party Profile approval workflow
     *
     * @param int     $tpID     Third Party Profile id - thirdPartyProfile.id
     * @param int     $caseID   Case Folder id of the intake form - cases.id
     * @param boolean $isUpload Flag to indicate if method is being called from the 3P Upload process or not
     *
     * @note Change data type of $result returned from sendRequest
     *
     * @return mixed|void
     */
    public function startProfileWorkflow($tpID, $caseID = null, $isUpload = false)
    {
        /*
         * Disabled 12/17/2020 per client request
        try {
            $sql = "SELECT * FROM thirdPartyProfile WHERE id = :id AND clientID = :clientID";
             $thirdParty = $this->app->DB->fetchAssocRow($sql, [':id' => $tpID, ':clientID' => $this->tenantID]);
            $profileID = $thirdParty['id'];
             $ddqID = $this->app->DB->fetchValue("SELECT id FROM ddq WHERE caseID = :id", [':id' => $caseID]);
            // When a case is linked to the profile, automatically send the DDQ.
             $caseType = $this->app->DB->fetchValue("SELECT caseType from ddq WHERE id = :id", [':id' => (int)$ddqID]);

            // Prevent unwanted DDQ types from launching Questionnaire DDQ.
            if ($caseType != DDQ_SHORTFORM_2PAGE
                && !($caseType >= DDQ_SHORTFORM_2PAGE_1601 && $caseType <= DDQ_SHORTFORM_2PAGE_1620)
            ) {
                return;
            }

            if ($profileID && !empty($thirdParty['POCemail'])) {
                $answer = $this->app->DB->fetchValue("SELECT genQuest123 FROM ddq WHERE id = :id", [':id' => $ddqID]);

                if ($answer == 2) {
                    parent::autoSendDDQ($thirdParty, IntakeFormTypes::DDQ_SBI_FORM5);
                }

                }
            } catch (\Exception $ex) {
                // Failed to connect to start Third Party Profile Workflow.
                $msg = "Failed to start Profile Workflow for {$tpID}.";
                  $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
                $this->dbCon = false;
            }
        }
     */
    }

    /**
     * Handles workflow action for DDQ submitted
     *
     * @param int     $caseID   cases.id
     * @param int     $ddqID    ddq.id
     * @param string  $question genQuestxxx
     * @param boolean $renewal  indicates whether the DDQ is part of a renewal
     * @param boolean $delay    determines whether a delay should be provided for asynchronous calls - useful for CLI
     *
     * @return void
     */
    public function ddqSubmitted($caseID, $ddqID, $question, $renewal = false, $delay = false)
    {
        $sql = "SELECT caseType FROM {$this->app->DB->clientDB}.ddq WHERE id = :id";
        $caseType = $this->app->DB->fetchValue($sql, [':id' => $ddqID]);
        if ((int)$caseType == IntakeFormTypes::DDQ_SBI_FORM5 && $this->checkFlaggedQuestions($ddqID)
            && ($notificationDetails = $this->getWorkflowNotificationDetails("US"))
        ) {
            $sql = "SELECT userName AS toName, userEmail AS toEmail FROM {$this->app->DB->authDB}.users WHERE id = :id";
            $recipient = $this->app->DB->fetchAssocRow($sql, [':id' => $notificationDetails['userID']]);
            $profileID = 0;
            $sql = "SELECT tpID FROM {$this->app->DB->clientDB}.cases WHERE id = :id";
            if ($tpID = $this->app->DB->fetchValue($sql, [':id' => $caseID])) {
                $profileID = (int)$tpID;
            }
            (new ClientEmails($this->tenantID))->send(
                $notificationDetails['userID'],
                $notificationDetails['userType'],
                $notificationDetails['mgrDepartments'],
                $notificationDetails['mgrRegions'],
                $profileID,
                $caseID,
                'workflowComplianceEmail',
                'EN_US',
                4,
                $recipient
            );
        }
    }
}
