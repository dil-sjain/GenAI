<?php
/**
 * public_html/cms/includes/php/Models/TPM/Workflow/Teleflex.php
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\ClientIds;

/**
 * Class Teleflex - Legacy version of app/Models/TPM/Workflow/Teleflex.php provides workflow functionality
 * for Teleflex (TenantID 323)
 */
#[\AllowDynamicProperties]
class Teleflex extends LegacyTenantWorkflow
{
    /**
     * Teleflex constructor.
     * @param int $tenantID g_tenants.id
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow()
            || (!in_array($this->tenantID, [ClientIds::TELEFLEX_CLIENTID]))
        ) {
            throw new \Exception("Not Teleflex or Teleflex doesn't have Workflow Feature/Setting enabled.");
        }
    }

    /**
     * Starts the Teleflex Third Party Profile approval workflow
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
            $sql = "SELECT * FROM thirdPartyProfile WHERE id = :tpID AND clientID = :tenantID";
            $bind = [
                ':tpID'     => $tpID,
                ':tenantID' => $this->tenantID
            ];
            $thirdParty = $this->app->DB->fetchAssocRow($sql, $bind);
            $sql = "SELECT id FROM ddq WHERE caseID = :caseID AND clientID = :tenantID";
            $bind = [
                ':caseID'   => $caseID,
                ':tenantID' => $this->tenantID
            ];
            $ddqID = $this->app->DB->fetchValue($sql, $bind);
            $wfExists = $this->ivy->workflowExists($this->tenantID, $tpID);
            if (!empty($thirdParty) && !$wfExists && isset($ddqID)) {
                $result = false;

                // If this was an existing profile, create the workflow without sending the DDQ.
                $sql = "SELECT COUNT(*) FROM thirdPartyProfile WHERE id = :id AND DATE(tpCreated) != CURRENT_DATE";
                if ($this->app->DB->fetchValue($sql, [':id' => $tpID])) {
                    $fields = [
                        "recordID" => $tpID,
                        "ddqID"    => $ddqID,
                        "caseID"   => $caseID,
                        "auto"     => "true"
                    ];
                    return $this->ivy->sendRequest($this->tenantID, $fields, "/ThirdParty/profileLinked");
                }

                if (!empty($thirdParty['POCemail'])) {
                    $fields = [
                        "recordID" => $tpID,
                        "ddqID"    => $ddqID,
                        "caseID"   => $caseID,
                        "auto"     => "true"
                    ];
                    $this->ivy->sendRequest($this->tenantID, $fields, "/ThirdParty/profileLinked");
                    // Automatically send DDQ Invitation with no Case.
                    $result = $this->autoSendDDQ($thirdParty);
                }
                return $result;
            }
        } catch (\Exception) {
            // Failed to connect to start Third Party Profile Workflow.
            $msg = "Failed to start Third Party Profile Workflow for {$tpID}.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
            $this->dbCon = false;
        }
    }

    /**
     * Determines if the current user can launch Workflow review batches
     *
     * @param int $recordID the record id such as thirdPartyProfile.id, case.id, ddq.id
     *
     * @return mixed
     */
    #[\Override]
    public function userCanLaunchBatchReview($recordID)
    {
        // Check if the user has the feature or is the owner of this 3P.
        return ($this->app->ftr->has(\Feature::TENANT_WORKFLOW_PROFILE_REVIEW)
            || $this->app->ftr->user == $this->app->DB->fetchValue(
                "SELECT ownerID FROM thirdPartyProfile WHERE id = :id",
                [':id' => $recordID]
            ));
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
    #[\Override]
    public function reviewBatchLaunchAvailable($tenantID, $recordID, $recordType = "profile")
    {
        // Check if the ddq has been submitted.
        $task = $this->ivy->getTaskForEvent($tenantID, $recordID, $recordType, self::CASE_LINK_CREATED_PROFILE);
        $submitted = ($task && isset($task["state"]) && ($task["state"] == "DESTROYED" || $task["state"] == "DONE"));
        return ($submitted && parent::reviewBatchLaunchAvailable($tenantID, $recordID, $recordType));
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
    #[\Override]
    public function initialReviewBatchLaunch($tenantID, $recordID, $recordType = "profile")
    {
        // Determine if this is the initial review batch.
        $response = $this->ivy->sendRequest($tenantID, [], "/ThirdPartyProfile/{$recordID}/reviews", "GET");
        $taskData = json_decode((string) $response, true);

        // Check if there are review tasks in Axon Ivy for this Profile.
        if ($taskData && (is_array($taskData) && count($taskData) > 0) || isset($taskData["errorId"])) {
            return false;
        }

        return true;
    }
}
