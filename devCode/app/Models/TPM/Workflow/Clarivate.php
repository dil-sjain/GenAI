<?php
/**
 * Controller: Clarivate Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\ClientIds;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/Clarivate.php provides Workflow functionality for
 * Clarivate (TenantID = 320)
 */
#[\AllowDynamicProperties]
class Clarivate extends TenantWorkflow
{
    /**
     * Clarivate constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow()
            || (!in_array($this->tenantID, [ClientIds::CLARIVATE_CLIENTID]))
        ) {
            throw new \Exception("Not Clarivate or Clarivate doesn't have Workflow Feature/Setting enabled.");
        }
    }

    /**
     * Starts the Clarivate Third Party Profile approval workflow.
     *
     * @param int     $tpID     Third Party Profile id - thirdPartyProfile.id
     * @param int     $caseID   Case Folder id of the intake form - cases.id
     * @param boolean $isUpload Flag to indicate if method is being called from the 3P Upload process or not
     *
     * @return mixed
     */
    public function startProfileWorkflow($tpID, $caseID = null, $isUpload = false)
    {
        try {
            // Get the profile record and determine if a workflow exists for this profile.
            $sql = "SELECT * FROM thirdPartyProfile WHERE id = :id AND clientID = :tenantID";
            $profile = $this->app->DB->fetchAssocRow($sql, [':id' => $tpID, ':tenantID' => $this->tenantID]);
            $wfExists = $this->workflowExists($this->tenantID, $tpID);
            // Start the workflow if the profile exists and there is no existing workflow for this profile.
            if (!empty($profile) && !$wfExists) {
                // Get risk model.
                $riskLabel = parent::getRiskLevel($profile['id']);
                $riskLabel = (isset($riskLabel)) ? strtolower($riskLabel) : "";
                // Set the "conditional" property for the Clarivate workflow.
                $riskLevelMet = ($riskLabel == "high");
                // If the risk level is not low and the POC email is set send the DDQ invitation automatically.
                if ($riskLevelMet && !empty($profile['POCemail'])) {
                    // Automatically send DDQ Invitation with no Case.
                    parent::autoSendDDQ($profile);
                    // Create workflow initiated entry.
                    $this->createWorkflowExistsEntry($this->tenantID, $tpID);
                }
                return true;
            }
        } catch (\Exception) {
            // Failed to connect to start the profile workflow.
            $msg = "Failed to start profile workflow. Check connectivity to Axon Ivy Engine.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " error = $msg");
        }
    }
}
