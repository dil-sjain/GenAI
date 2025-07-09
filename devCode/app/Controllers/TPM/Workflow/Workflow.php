<?php
/**
 * Controller: Workflow - allows users to perform Workflow actions related to Axon Ivy
 */

namespace Controllers\TPM\Workflow;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\AxonIvy\Task;
use Models\TPM\Workflow\TenantWorkflow;
use Models\User;
use Models\LogData;

/**
 * Handles requests and responses for Workflow UI elements
 */
class Workflow extends Base
{
    use AjaxDispatcher;

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var object Model instance
     */
    private $m = null;

    /**
     * Constructor gets model instance and initializes other properties
     *
     * @param integer $tenantID   clientProfile.id
     * @param array   $initValues flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
        $this->m = (new TenantWorkflow($tenantID))->getTenantWorkflowClass();
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        // @Note: over rides parent but isn't currently necessary as UI is in legacy
    }

    /**
     * Save a note for a given task
     *
     * @return void
     */
    public function ajaxSaveNote()
    {
        try {
            $taskID = (int)\Xtra::arrayGet($this->app->clean_POST, 'task', 0);
            $note   = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'note', ''));
            if (!empty($note) && !empty($taskID)) {
                if ($this->m->ivy->saveNote($this->tenantID, $taskID, $note)) {
                    $this->handleAjaxResponse(1);
                } else {
                    $this->handleAjaxResponse(0, 'Error', 'Unable to save note.');
                }
            } else {
                $this->handleAjaxResponse(0, 'Error', 'Unable to save note.');
            }
        } catch (\Exception) {
            $this->handleAjaxResponse(0, 'Error', 'Unable to save note.');
        }
    }

    /**
     * Starts the Third Party Profile approval workflow or Launches a batch profile review signal to the Axon Ivy engine
     *
     * @return void
     */
    public function ajaxLaunchBatchProfileReview()
    {
        try {
            // Check if the Tenant has access to this feature.
            if (!$this->app->ftr->tenantHas(\Feature::TENANT_WORKFLOW_BATCH_PROFILE_REVIEW)) {
                $this->handleAjaxResponse(0, 'Access Denied', 'Tenant does not have access to this feature.');
            }
            if ($this->m->tenantHasEvent(TenantWorkflow::BATCH_PROFILE_REVIEW)) {
                // Get the POST variables and clean them.
                $recordID = (int)\Xtra::arrayGet($this->app->clean_POST, 'record', 0);
                $point = ($this->m->initialReviewBatchLaunch($this->tenantID, $recordID)) ?
                    'startApproval' : 'launchBatch';
                // Update Axon Ivy and launch the next batch of reviews.
                $result = $this->m->ivy->sendRequest(
                    $this->tenantID,
                    ['recordID' => $recordID],
                    "/ThirdParty/$point"
                );

                if ($result) {
                    $this->handleAjaxResponse(1);
                } else {
                    $this->handleAjaxResponse(0, 'Error', 'Unable to submit batch review.');
                }
            }
        } catch (\Exception) {
            $this->handleAjaxResponse(0, "Error", "Unable to submit batch review.");
        }
    }

    /**
     * Starts the Third Party Profile approval workflow or Launches a batch profile review signal to the Axon Ivy engine
     *
     * @return void
     */
    public function ajaxPreviewProfileWorkflow()
    {
        try {
            // Check if the Tenant has access to this feature.
            if (!$this->app->ftr->tenantHas(\Feature::TENANT_WORKFLOW_BATCH_PROFILE_REVIEW)) {
                $this->handleAjaxResponse(0, 'Access Denied', 'Tenant does not have access to this feature.');
            }
            if ($this->m->tenantHasEvent(TenantWorkflow::BATCH_PROFILE_REVIEW)) {
                // Get the POST variables and clean them.
                $recordID = (int)\Xtra::arrayGet($this->app->clean_POST, 'record', 0);
                // Update Axon Ivy and launch the next batch of reviews.
                $result = $this->m->ivy->sendRequest(
                    $this->tenantID,
                    ['recordID' => $recordID],
                    "/ThirdParty/previewApproval"
                );

                if ($result) {
                    $this->handleAjaxResponse(1);
                } else {
                    $this->handleAjaxResponse(0, 'Error', 'Unable to create workflow preview.');
                }
            }
        } catch (\Exception) {
            $this->handleAjaxResponse(0, "Error", "Unable to create workflow preview.");
        }
    }

    /**
     * Sends a users case review to Axon Ivy
     *
     * @return void
     */
    public function ajaxCaseReview()
    {
        try {
            if (!$this->app->ftr->tenantHas(\Feature::TENANT_WORKFLOW_CASE_REVIEW)) {
                $this->handleAjaxResponse(0, 'Access Denied', 'Tenant does not have access to this feature.');
            }
            // Performs Workflow Event Case Folder Review
            if ($this->m->tenantHasEvent(TenantWorkflow::CASE_FOLDER_REVIEW)) {
                $action        = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'action', ''));
                $profileID     = (int)\Xtra::arrayGet($this->app->clean_POST, 'tpid', 0);
                $caseID        = (int)\Xtra::arrayGet($this->app->clean_POST, 'csid', 0);
                $explain       = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'note', ''));
                $determination = (int)\Xtra::arrayGet($this->app->clean_POST, 'determination', 0);

                if ($action == 'complete') {
                    // Create audit log entry - "Case Folder Review" Completed.
                    $determinationText = Task::DETERMINATION_LIST[$determination];
                    $auditLog = new LogData($this->tenantID, $this->app->ftr->user);
                    $logMsg = "determination: `{$determinationText}`, reason: `{$explain}`";
                    $auditLog->saveLogentry(179, $logMsg);

                    $this->m->caseFolderReview(
                        $profileID,
                        $action,
                        $caseID,
                        $explain,
                        $determination
                    );
                    $this->handleAjaxResponse(1);
                } else {
                    $this->handleAjaxResponse(0, 'Error', 'Unable to perform case review.');
                }
            }
        } catch (\Exception) {
            $this->handleAjaxResponse(0, "Error", "Unable to perform case review..");
        }
    }

    /**
     * Sends a users profile review to Axon Ivy
     *
     * @return void
     */
    public function ajaxProfileReview()
    {
        try {
            $recordID      = (int)\Xtra::arrayGet($this->app->clean_POST, 'record', 0);
            $note          = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'note', ''));
            $determination = (int)\Xtra::arrayGet($this->app->clean_POST, 'determination', 0);
            $result        = null;

            // Check if the Tenant has access to this feature.
            if (!$this->app->ftr->tenantHas(\Feature::TENANT_WORKFLOW_PROFILE_REVIEW)) {
                $this->handleAjaxResponse(0, 'Access Denied', 'Tenant does not have access to this feature.');
            }

            if ($this->m->ivy->getTaskForUser($this->tenantID, $recordID, "profile", TenantWorkflow::PROFILE_REVIEW)) {
                // Get the Third Party Profile ID.
                $fields = [
                    'recordID'      => $recordID,
                    'note'          => $note,
                    'determination' => $determination
                ];
                // Update Axon Ivy with the review task.
                $result = $this->m->ivy->sendRequest(
                    $this->tenantID,
                    $fields,
                    "/ThirdParty/review"
                );

                if ($result) {
                    $result = json_decode((string) $result, true);
                    // Save the note associated with this Task.
                    $taskID = $result['data']['id'] ?? null;
                    if ($taskID) {
                        // Create audit log entry - Third Party "Profile Review" Task.
                        $determinationText = Task::DETERMINATION_LIST[$determination];
                        $auditLog = new LogData($this->tenantID, $this->app->ftr->user);
                        $logMsg = "determination: `{$determinationText}`, reason: `{$note}`";
                        $auditLog->save3pLogEntry(180, $logMsg, $recordID);

                        $this->handleAjaxResponse(1);
                    }
                } else {
                    $this->handleAjaxResponse(0, 'Error', 'Unable to submit profile review.');
                }
            }
        } catch (\Exception) {
            $this->handleAjaxResponse(0, "An error occurred", "Unable to submit profile review.");
        }
    }

    /**
     * Provide the data required to populate the users table for review reassignment
     *
     * @return void
     */
    public function ajaxPopulateUserTable()
    {
        $userList = [];
        $tenantUsers = (new User())->getUsersForCurrentTenant(true);
        foreach ($tenantUsers as $userObj) {
            $userList[] = get_object_vars($userObj);
        }

        if ($userList) {
            $this->handleAjaxResponse(1);
            $this->jsObj->data = $userList;
        } else {
            $this->handleAjaxResponse(0, 'Error', 'Unable to retrieve users');
        }
    }

    /**
     * Reassign a workflow task to a different user
     *
     * @return void
     */
    public function ajaxReassignTask()
    {
        $taskID   = (int)\Xtra::arrayGet($this->app->clean_POST, 'task', 0);
        $user     = (int)\Xtra::arrayGet($this->app->clean_POST, 'user', 0);
        $recordID = (int)\Xtra::arrayGet($this->app->clean_POST, 'record', 0);

        $task = $this->m->ivy->getTaskDetails($this->tenantID, $taskID);

        // Prevent users from reassigning a completed task.
        if (isset($task["state"])
            && ($task["state"] == "DESTROYED"
            || $task["state"] == "DONE"
            || $task["state"] == "READY_TO_JOIN")
        ) {
            $this->handleAjaxResponse(0, 'Error', 'Cannot reassign a completed task.');
            return;
        }

        // Prevent users from reassigning the task to the user which it is currently assigned to.
        if (isset($task["user"]) && $task["user"] == $user) {
            $this->handleAjaxResponse(0, 'Error', 'Cannot reassign task to the current assignee.');
            return;
        }

        // Update Axon Ivy with the review task.
        $result = $this->m->ivy->sendRequest(
            $this->tenantID,
            ['user' => $user],
            "/ThirdParty/{$recordID}/tasks/{$taskID}/reassign"
        );

        if ($result == "true") {
            $oldAssignee = (new User())->findById($task["user"])->get("userid");
            $newAssignee = (new User())->findById($user)->get("userid");

            // Create audit log entry - "Reassigned Workflow Task".
            $auditLog = new LogData($this->tenantID, $this->app->ftr->user);
            $logMsg = "{$oldAssignee} => {$newAssignee}";
            $auditLog->save3pLogEntry(181, $logMsg, $recordID);

            $this->handleAjaxResponse(1);
        } else {
            $this->handleAjaxResponse(0, 'Error', 'Unable to reassign review.');
        }
    }

    /**
     * Acts as a wrapper for setting up return of jsObj for title, message and result
     *
     * @param boolean $result 1 or 0 to indicate if action was successful
     * @param string  $text   body text of the message
     * @param string  $title  title of the message
     *
     * @return void
     */
    private function handleAjaxResponse($result, $title = '', $text = '')
    {
        $this->jsObj->Result   = $result;
        $this->jsObj->ErrTitle = $title;
        $this->jsObj->ErrMsg   = $text;
    }
}
