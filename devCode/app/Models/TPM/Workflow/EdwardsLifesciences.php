<?php
/**
 * Controller: Edwards Lifesciences Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\IntakeFormTypes;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/Edwards Lifesciences.php provides Workflow
 * functionality for Edwards Lifesciences (TenantID = 277)
 */
#[\AllowDynamicProperties]
class EdwardsLifesciences extends TenantWorkflow
{
    /**
     * EdwardsLifesciences constructor.
     * @param int $tenantID
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow() || ($this->tenantID != 277)) {
            throw new \Exception("Not Edwards Lifesciences or Edwards Lifesciences doesn't have Workflow "
                ."Feature/Setting enabled.");
        }
    }

    /**
     * Starts the Edwards Lifesciences Third Party Profile approval workflow
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
            $sql = "SELECT * FROM thirdPartyProfile WHERE id = :id AND clientID = :clientID";
            $thirdParty = $this->app->DB->fetchAssocRow($sql, [':id' => $tpID, ':clientID' => $this->tenantID]);
            $ddqID = $this->app->DB->fetchValue("SELECT id FROM ddq WHERE caseID = :id", [':id' => $caseID]);
            $wfExists = $this->workflowExists($this->tenantID, $tpID);
            if (!empty($thirdParty) && !$wfExists) {
                // Create workflow initiated entry.
                $this->createWorkflowExistsEntry($this->tenantID, $tpID);
            }
            // When a case is linked to the profile, automatically send the DDQ.
            $caseType = $this->app->DB->fetchValue("SELECT caseType from ddq WHERE id = :id", [':id' => (int)$ddqID]);

            // Prevent unwanted DDQ types from launching IT Questionnaire DDQ
            if ($caseType != 95) {
                return;
            }

            // Look up third party profile from the DDQ
            $profileID = $this->app->DB->fetchValue("SELECT tpID FROM cases WHERE id = :id", [':id' => (int)$caseID]);

            $fields = [
                "profileID" => $profileID,
                "ddqID"     => $ddqID,
                "caseID"    => $caseID,
                "answer"    => "false"
            ];
            // Update workflow for DDQSubmitted.
            $result = $this->ivy->sendRequest($this->tenantID, $fields, "/ThirdParty/ddqSubmitted", "POST", false);

            if ($profileID) {
//                $thirdParty = $this->app->DB->fetchAssocRow(
//                "SELECT * FROM thirdPartyProfile WHERE id = :id",
//                    [':id' => (int)$profileID]
//                );

                // parent::autoSendDDQ($thirdParty, IntakeFormTypes::DDQ_SHORTFORM_2PAGE_RENEWAL);
            }
        } catch (\Exception) {
            // Failed to connect to start Third Party Profile Workflow.
            $msg = "Failed to start Third Party Profile Workflow for {$tpID}.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
            $this->dbCon = false;
        }
    }
}
