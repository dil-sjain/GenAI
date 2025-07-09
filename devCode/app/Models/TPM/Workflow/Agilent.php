<?php
/**
 * Model: Agilent Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Lib\Legacy\CaseStage;
use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Lib\Services\AppMailer;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/Agilent.php provides Workflow functionality for
 * Agilent (TenantID = 125)
 */
#[\AllowDynamicProperties]
class Agilent extends TenantWorkflow
{
    /**
     * Agilent constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow()
            || (!in_array($this->tenantID, [ClientIds::AGILENT_CLIENTID, ClientIds::AGILENTQC_CLIENTID]))
        ) {
            throw new \Exception("Not Agilent or Agilent doesn't have Workflow Feature/Setting enabled.");
        }
    }

    /**
     * Starts the Agilent Third Party Profile approval workflow
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
        // Check if the task for starting workflows is present
        if ($this->tenantHasEvent(self::START_WORKFLOW)) {
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
                $wfExists = $this->workflowExists($this->tenantID, $tpID);
                if (!empty($thirdParty) && !$wfExists && isset($ddqID)) {
                    // Create workflow initiated entry.
                    $this->createWorkflowExistsEntry($this->tenantID, $tpID);
                    if (!empty($thirdParty['POCemail'])) {
                        // Automatically send DDQ Invitation with no Case.
                        $this->autoSendDDQ($thirdParty, IntakeFormTypes::DUE_DILIGENCE_SBI, 0, $ddqID);
                    }
                    return true;
                }
            } catch (\Exception) {
                // Failed to connect to start Third Party Profile Workflow.
                $msg = "Failed to start Third Party Profile Workflow for {$tpID}.";
                $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
            }
        }
        return 'true';
    }

    /**
     * Progresses the Agilent Third Party Profile approval workflow when a DDQ is submitted by the Third Party
     *
     * @param int    $caseID   Case folder id - cases.id
     * @param int    $ddqID    DDQ id - ddq.id
     * @param string $question the column name for the DDQ question to select a value from
     * @param bool   $renewal  indicates whether the DDQ is part of a renewal
     * @param bool   $delay    determines whether a delay should be provided for asynchronous calls - useful for CLI
     *
     * @note Change data type of $result returned from sendRequest
     *
     * @return void
     */
    #[\Override]
    public function ddqSubmitted($caseID, $ddqID, $question, $renewal = false, $delay = false)
    {
        $caseType = $this->app->DB->fetchValue(
            "SELECT caseType FROM {$this->app->DB->clientDB}.ddq WHERE id = :id",
            [":id" => $ddqID]
        );
        // If Renewal has not been explicitly passed as true check if the DDQ is a renewal, required for the CLI.
        if (!$renewal) {
            $renewal = in_array(
                $caseType,
                [IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL, IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL]
            );
        }
        parent::ddqSubmitted($caseID, $ddqID, 'genQuest122', $renewal);
    }

    /**
     * Automates the process of sending a DDQ and creating a Case Folder.
     *
     * @param array $thirdParty       third party profile record
     * @param int   $formType         form type for the DDQ e.g. L-95 would be the int 95, likewise L-95b would be 95
     * @param int   $caseType         Case type which is really the DDQ type just submitted
     * @param int   $ddqID            Current DDQ id
     * @param bool  $includeUser      whether the auth cookie should include the session user.id, in instances such
     *                                as submitting a DDQ the user.id will not be available and so false should be
     *                                provided
     * @param bool  $renewal          Indicates whether or not this DDQ is part of the renewal process
     * @param array $ddqStatus        Prior DDQ status to check for
     *
     * @throws \Exception cannot send email
     * @return bool
     */
    #[\Override]
    public function autoSendDDQ(
        $thirdParty,
        $formType = IntakeFormTypes::DUE_DILIGENCE_SBI,
        $caseType = 0,
        $ddqID = null,
        $includeUser = true,
        $renewal = false,
        $ddqStatus = []
    ) {
        $sent   = false;
        $manual = false;

        // See if the Third Party is eligible for automatic DDQ sending.
        $autoSend = ($this->autoSendDDQEligible($thirdParty, $caseType)
            && !$this->getPastDdqs(0, $thirdParty['id'], $ddqID, null, $ddqStatus));

        ($autoSend) ? $sent = parent::autoSendDDQ($thirdParty, $formType) : $manual = true;
    }

    /**
     * Sends notification to the Compliance Specialist
     *
     * @param int $profileID thirdPartyProfile.id
     * @param int $ddqID     ddq.id
     *
     * @return void
     */
    #[\Override]
    public function manualSendDDQ($profileID, $ddqID)
    {
        // @TODO: intentionally left commented out per Agilent's request
        // Notify the Channel Partner Manager that the DDQ was sent.
        // $this->sendNotifyCPM($profileID, $ddqID, 'sent');
        return;
    }

    /**
     * Determine if third party profile eligible for automatic DDQ sending
     *
     * @param array $thirdParty Third Party Profile details
     * @param int   $formType   Form type for the DDQ e.g. L-95 would be the int 95, likewise L-95b would be 95
     *
     * @return bool
     */
    public function autoSendDDQEligible($thirdParty, $formType)
    {
        // List taken from Agilent requirements, you can find these in clientDB.tpTypeCategory.
        $autoSendList = [
            92 => ['new' => true, 'renewal' => true],  // Agent
            93 => ['new' => true, 'renewal' => true],  // CSD Distributor
            35 => ['new' => true, 'renewal' => true],  // Distributor
            51 => ['new' => true, 'renewal' => true],  // Distributor (FPN)
            89 => ['new' => true, 'renewal' => true],  // Federal Reseller
            33 => ['new' => true, 'renewal' => true],  // International Designated Reseller
            94 => ['new' => true, 'renewal' => false], // Japan Pathology Dealer
            88 => ['new' => true, 'renewal' => false], // Kai B Distributor
            38 => ['new' => true, 'renewal' => true],  // Manufacturer Representative
            34 => ['new' => true, 'renewal' => true],  // National Designated Reseller
            91 => ['new' => true, 'renewal' => true],  // Pathology Partner
            46 => ['new' => true, 'renewal' => true],  // Reseller
            90 => ['new' => true, 'renewal' => true],  // Reseller Plus
            40 => ['new' => true, 'renewal' => true],  // Systems Integrator
            36 => ['new' => true, 'renewal' => true],  // Value Added Reseller
            37 => ['new' => true, 'renewal' => true],  // Value Added Distributor
        ];

        if (isset($thirdParty['tpTypeCategory']) && array_key_exists($thirdParty['tpTypeCategory'], $autoSendList)) {
            if (in_array(
                $formType,
                [IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL, IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL]
            )
            ) {
                return $autoSendList[$thirdParty['tpTypeCategory']]['renewal'];
            }
            return true;
        }
        return false;
    }

    /**
     * Updates the case folder review step of workflow
     *
     * @param int    $profileID     thirdPartyProfile.id
     * @param string $action        'approve' or 'reject'
     * @param int    $caseID        cases.id
     * @param string $explain       desired note; the reason for approval or rejection
     * @param int    $determination outcome of the review
     *
     * @return mixed
     */
    #[\Override]
    public function caseFolderReview($profileID, $action, $caseID, $explain, $determination)
    {
        $result = parent::caseFolderReview($profileID, $action, $caseID, $explain, $determination);
        $this->notifyCompliance($caseID);
        return $result;
    }

    /**
     * Notify compliance that regulatory has performed their review
     *
     * @param int $caseID cases.id
     *
     * @return void
     */
    public function notifyCompliance($caseID)
    {
        // Get the profile id.
        $sql = "SELECT c.tpID, d.id FROM cases c INNER JOIN ddq d ON(d.caseID = c.id) WHERE c.id = :id";
        $details = $this->app->DB->fetchAssocRow($sql, [':id' => $caseID]);
        // Send notification to Compliance Specialist.
        $this->sendNotifySpecialist($caseID, $details['id']);
    }

    /**
     * Process only Scorecard (internal) ddq forms. Current Agilent Scorecard (internal) form types are:
     *
     *      Scorecard Intake Form - Renew Channel Partner (L-136d) - DDQ_SHORTFORM_FORM3_RENEWAL (136)
     *      Scorecard Intake Form - Renew Sales Agent-MR  (L-190d) - DUE_DILIGENCE_SHORTFORM_RENEWAL (190)
     *
     * Conditions for processing are:
     *
     *     - Channel Partner Renew question (genQuest177) == 'Yes'
     *     - Update the 3P Profile POC with POC info from the Scorecard
     *     - Based upon Risk Tier and most recent DDQ/Attestation determine if a DDQ or Attestation invite
     *       should be sent
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
            // if it's a renewal scorecard (internal ddq and not OpenURL) do the post processing
            if (!empty($profileID)
                && $this->tenantHasEvent(self::SCORECARD_AUTOMATION)
                && in_array(
                    $caseType,
                    [IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL, IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL]
                )
            ) {
                $answers = $this->getRenewalAnswers($ddqID);
                if ($answers['renew']) {
                    // Now update the 3P POC with info from the Scorecard
                    $this->updateTpProfile($profileID, $answers['poc']);
                    $ddq = $this->determineDdqType($profileID, $ddqID);
                    if ($ddq) {
                        $profile = $this->getTpProfile($profileID);
                        if ($profile) {
                            $this->autoSendDDQ($profile, $ddq, $caseType, $ddqID, false, true, ['submitted']);
                        }
                    }
                }
            }
        } catch (\Exception) {
            $msg = "Failed to process Agilent Scorecard Workflow for DDQ {$ddqID} for profile ID: {$profileID}.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " errror = $msg");
        }
    }

    /**
     * For a renewal scorecard (internal DDQ) that has just been submitted, determine if a DDQ or an Attestation
     * invite should be sent based upon the following logic:
     *
     *      RiskTier:
     *          - High:
     *              - Always send DDQ to POC
     *          - Medium:
     *              - If prior submission is a DDQ within the past year send Attestation invite
     *              - Else prior submission must have been an Attestation, send DDQ invite
     *          - Low:
     *              - If prior submission is a DDQ within the past 2 years send Attestation invite
     *              - Else prior submission must have been an Attestation, send DDQ invite
     *
     * Current DDQ form types are:
     *
     *      Partner Renewal (L-22d)             - DUE_DILIGENCE_SBI_RENEWAL (22)
     *      Channel Partner Attestation (L-96d) - DDQ_SHORTFORM_2PAGEA (96)
     *
     * @param integer $profileID 3P Profile ID
     * @param integer $ddqID     DDQ ID
     *
     * @return integer DDQ form type (integer) or zero if not found
     */
    private function determineDdqType($profileID, $ddqID)
    {
        $ddqType = 0;
        $riskTier = $this->getRiskTier($profileID);

        switch ($riskTier) {
            case 'High Risk':
                $ddqType = IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL;
                break;
            case 'Medium Risk':
                // check for any DDQ's in the current year regardless of 'submit' status
                $statusToCheck = ['active', 'submitted', 'closed'];
                $pastDdqs = $this->getPastDdqs(0, $profileID, $ddqID, null, $statusToCheck);

                if (empty($pastDdqs)) {
                    // no DDQ's submitted for the current year so get the prior years DDQ's
                    $pastDdqs = $this->getPastDdqs(20, $profileID, $ddqID, null, $statusToCheck);
                    $ddqType = $this->getDdqTypeToSend($pastDdqs);
                }
                break;
            default: // low/no risk
                $ddqType = 0;
                break;
        }

        return $ddqType;
    }

    /**
     * Loop through the passed in list of DDQ's looking for a prior DDQ (not Attestation), if DDQ found
     * return Attestation type else return Renewal DDQ type
     *
     * @param array $pastDdqs Array of DDQ's (both DDQ and Attestations)
     *
     * @return int DDQ Type
     */
    private function getDdqTypeToSend($pastDdqs)
    {
        foreach ($pastDdqs as $ddq) {
            switch ($ddq['caseType']) {
                case IntakeFormTypes::DUE_DILIGENCE_SBI:
                case IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL:
                    return IntakeFormTypes::DDQ_SHORTFORM_2PAGEA; // Attestation
                case IntakeFormTypes::DDQ_SHORTFORM_2PAGEA:
                    return IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL; // Renewal DDQ
            }
        }
        return IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL; // Renewal DDQ
    }

    /**
     * Get only prior DDQ's and Attestations (ignore all other ddq's) based upon how far in the past to search.
     * Also don't look at the ddQuestionVer as it may have changed in the current cycle, and we are not looking
     * at the DDQ language. Since this is a custom field it should not matter.
     *
     * @param integer $yearsBack          Number of years to look back
     * @param integer $profileID          3P Profile ID
     * @param integer $ddqID              Current Scorecard DDQ ID
     * @param integer $pastScoredcardType IF specified, this is signaling to check and see if any prior scorecards
     *                                    of the type specified have already been submitted in the current cycle
     * @param array   $statuses           Array of desired statuses
     *
     * @return mixed Array of past DDQ's if found else return false
     */
    private function getPastDdqs($yearsBack, $profileID, $ddqID, $pastScoredcardType = null, $statuses = ['submitted'])
    {
        if ($pastScoredcardType) {
            $ddqTypes = $pastScoredcardType;
        } else {
            $ddqTypes = implode(',', [IntakeFormTypes::DUE_DILIGENCE_SBI,
                IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL,
                IntakeFormTypes::DDQ_SHORTFORM_2PAGEA,
                IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL,
                IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL]);
        }

        $pastDate = (new \DateTime('now'))->modify("-$yearsBack year")->format('Y') . '-01-01 00:00:00';
        $dateClause = "d.creationStamp";
        $statusClause = "";
        $statusesLen = count($statuses);

        if (is_array($statuses) && $statusesLen > 0) {
            $statusClause = "AND d.status IN (";

            for ($i = 0; $i < $statusesLen; $i++) {
                $statusClause .= ($i != $statusesLen - 1) ? "'{$statuses[$i]}'," : "'{$statuses[$i]}')\n";
            }

            // the reason why we change the column for the SQL is in the case of DDQ's that have not been
            // submitted, the subByDate will be all zero's. Only if the DDQ has been submitted can we use
            // the subByDate column.
            if (($statusesLen == 1) && ($statuses[0] == 'submitted')) {
                $dateClause = "d.subByDate";
            }
        }

        $sql = "SELECT d.id, d.caseType, d.subByDate, d.creationStamp FROM ddq AS d\n"
            . "LEFT JOIN cases AS c ON c.id = d.caseID\n"
            . "WHERE c.id IS NOT NULL\n"
            . "AND c.tpID = :tpID\n"
            . "AND c.caseStage <> " . CaseStage::DELETED . "\n"
            . "AND d.id <> :ddqID\n"
            . "AND d.clientID = :clientID\n"
            . "AND d.caseType IN ({$ddqTypes})\n"
            . "{$statusClause}"
            . "AND {$dateClause} BETWEEN :pastDate AND NOW()\n"
            . "ORDER BY {$dateClause} DESC";

        $params = [
            ':tpID'     => $profileID,
            ':ddqID'    => $ddqID,
            ':clientID' => $this->tenantID,
            ':pastDate' => $pastDate
        ];

        if ($result = $this->app->DB->fetchAssocRows($sql, $params)) {
            return $result;
        }
        return false;
    }

    /**
     *  Get the Scorecard (internal DDQ) POC answers and the answer if the 3P is to be renewed (genQuest177 -
     *  question #3 on the first tab of the form). The field genQuest177 is a custom field so we need to dig
     *  out the real answer from Custom Fields.
     *
     * @param integer $ddqID Scorecard DDQ ID
     *
     * @return array Contains the POC answers and the renewal answer from the Scorecard
     */
    private function getRenewalAnswers($ddqID)
    {
        $answers['renew'] = false;

        // get Channel Partner Renew questions answers
        $questions = 'genQuest177 AS renew, POCname, POCphone AS POCphone1, POCemail, POCposi';
        $sql = "SELECT {$questions} FROM ddq WHERE id = :id";
        $result = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$ddqID]);

        if (!empty($result)) {
            $answers['renew'] = $result['renew'];
            unset($result['renew']);
            $answers['poc'] = $result;

            // The above genQuest177 field is a Custom Field, so now go fetch the real answer from Custom Fields
            $sql = "SELECT `name` FROM customSelectList WHERE id = :id";
            $renew = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$answers['renew']]);
            if (!empty($renew) && $renew['name'] == "Yes") {
                $answers['renew'] = true;
            } else {
                $answers['renew'] = false;
            }
        }

        return $answers;
    }

    /**
     * Get the current Risk Tier for the 3P
     *
     * @param integer $profileID 3P Profile ID
     *
     * @return string Risk Tier name
     */
    private function getRiskTier($profileID)
    {
        $riskTier = '';

        $sql = "SELECT riskModel FROM thirdPartyProfile WHERE id = :profileID";
        $riskModel = $this->app->DB->fetchValue($sql, [':profileID' => $profileID]);

        if ($riskModel) {
            $sql = "SELECT rt.tierName FROM riskAssessment AS ra"
                . " LEFT JOIN riskTier AS rt ON rt.id = ra.tier"
                . " WHERE ra.tpID = :profileID AND ra.status='current'"
                . " AND ra.model = :riskModel AND ra.clientID = :tenantID LIMIT 1";
            $params = [':profileID' => $profileID, ':riskModel' => $riskModel, ':tenantID' => $this->tenantID];

            return $this->app->DB->fetchValue($sql, $params);
        }

        return $riskTier;
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

        if (!empty($profile)) {
            return $profile;
        }

        return false;
    }

    /**
     * Update thirdPartyProfile POC fields.
     *
     * @param integer $profileID thirdPartyProfile.id
     * @param array   $data      thirdPartyProfile data to update
     *
     * @return void
     */
    private function updateTpProfile($profileID, $data)
    {
        $profileID = (int)$profileID;
        $params = [':id' => $profileID, ':clientID' => $this->tenantID];
        $sets = [];
        $pocElements = ['POCname', 'POCemail', 'POCphone1', 'POCposi'];

        foreach ($pocElements as $poc) {
            if ($data[$poc]) {
                $sets[$poc] = "$poc = :$poc";
                $params[":$poc"] = $data[$poc];
            }
        }

        if (count($sets) > 0) {
            $sql = "UPDATE thirdPartyProfile SET\n"
                . implode(', ', $sets) . "\n"
                . "WHERE id = :id AND clientID = :clientID LIMIT 1";
            $this->app->DB->query($sql, $params);
        }
    }

    /**
     * Get the Channel Partner Manager for a given third party profile
     *
     * @param int  $profileID thirdPartyProfile.id
     * @param bool $renewal   indicates if the DDQ was sent as a renewal of the third party
     *
     * @return mixed
     */
    private function getChannelPartnerManager($profileID, $renewal = false)
    {
        $renewalClause = ($renewal) ? "AND d.caseType = 36" : "AND (d.caseType = 136 OR d.caseType = 190)";

        $sql = "SELECT d.subByName, d.subByEmail FROM ddq d INNER JOIN cases c ON (d.caseID = c.id) "
            . "WHERE c.tpID = :profileID {$renewalClause} AND c.caseStage IN (0,1,2,3,4,5,6,7,8,9,11,12,14) "
            . "AND d.subByDate != '0000-00-00 00:00:00' AND d.status = 'submitted' AND d.returnStatus = '' "
            . "ORDER BY d.id DESC";

        return $this->app->DB->fetchAssocRow($sql, ['profileID' => $profileID]);
    }

    /**
     * Get the Compliance Specialist for a given DDQ
     *
     * @param int $ddqID ddq.id
     *
     * @return mixed
     */
    private function getComplianceSpecialist($ddqID)
    {
        $sql = "SELECT tpp.legalName, d.caseID, u.userid, u.userName FROM ddq d "
            . "INNER JOIN cases c ON (d.caseID = c.id) LEFT JOIN thirdPartyProfile tpp ON (c.tpID = tpp.id) "
            . "LEFT JOIN cms.users u ON (tpp.ownerID = u.id) "
            . "WHERE d.id = :ddqID AND d.clientID = :tenantID";

        return $this->app->DB->fetchAssocRow($sql, [':tenantID' => $this->tenantID, ':ddqID' => $ddqID]);
    }

    /**
     * Send a notification to the Compliance Specialist that the Regulatory Specialist has reviewed the case folder
     *
     * @note this notification was previously used when the regulatory specialist had reviewed the case folder through
     *       AI workflow review functionality for cases. It is no longer in use
     *
     * @note CPM = individual who submitted the scorecard
     * @note regional compliance specialist = tpp.ownerID
     *
     * @param int $caseID cases.id
     * @param int $ddqID  ddq.id
     *
     * @return boolean
     */
    private function sendNotifySpecialist($caseID, $ddqID)
    {
        // Get Compliance Specialist details.
        $spclstDetails = $this->getComplianceSpecialist($ddqID);

        if (is_array($spclstDetails)) {
            $baseURL = "https://cms.securimate.com/";
            $to = $spclstDetails['userid'];
            $subject = "Regulatory Review of DDQ for {$spclstDetails['legalName']} Complete";
            $EMbody = <<<EOT
                Dear Compliance Specialist, 
                
                The Regulatory review of DDQ for {$spclstDetails['legalName']} is complete, and the decision is 
                recorded in the linked document below.
                
                {$baseURL}cms/case/casehome.sec?id={$caseID}&cid={$this->tenantID}&tname=casefolder&rd=1
EOT;
            AppMailer::mail(
                0,
                $to,
                $subject,
                $EMbody,
                ['addHistory' => true, 'forceSystemEmail' => true]
            );
        }
    }

    /**
     * Send a notification to the Channel Partner Manager
     *
     * @note CPM = individual who submitted the scorecard
     * @note regional compliance specialist = tpp.ownerID
     *
     * @param int    $profileID thirdPartyProfile.id
     * @param int    $ddqID     ddq.id
     * @param string $reason    either 'sent' or 'submitted' to indicate which email to send
     * @param bool   $renewal   indicates if the DDQ was sent as a renewal of the third party
     *
     * @return boolean
     */
    private function sendNotifyCPM($profileID, $ddqID, $reason, $renewal = false)
    {
        // Get Compliance Specialist details.
        $spclstDetails = $this->getComplianceSpecialist($ddqID);
        // Get Channel Partner Manager (CPM) details.
        $cpmDetails = $this->getChannelPartnerManager($profileID, $renewal);
        // Send notification to Compliance Specialist.
        if (is_array($spclstDetails) && is_array($cpmDetails)) {
            $to = $cpmDetails['subByEmail'];
            if ($reason == "sent") {
                $subject = "DDQ Invitation Sent to {$spclstDetails['legalName']}";
                $EMbody = $this->ddqSentEmailBody($spclstDetails);
            } else {
                $subject = "DDQ Submitted by {$spclstDetails['legalName']}";
                $EMbody = $this->ddqSubmittedEmailBody($spclstDetails, $cpmDetails);
            }
            AppMailer::mail(
                0,
                $to,
                $subject,
                $EMbody,
                ['addHistory' => true, 'forceSystemEmail' => true]
            );
        }
    }

    /**
     * Builds the emaill body for the DDQ Sent notification given the Compliance Specialists details
     *
     * @param array $spclstDetails Compliance Specialists details I.E. userName and userid
     *
     * @return string
     */
    private function ddqSentEmailBody($spclstDetails)
    {
        return <<<EOT
            Dear Agilent Channel Partner Manager,\n"

            Agilent’s Channel Partner Due Diligence Questionnaire” email invitation has been sent to your Partner’s 
            Authoritative Email Contact recipient (identified in the Scorecard you submitted).
            
            Please encourage your partner contact to respond quickly and completely to the questionnaire (note:  due
            diligence can take several weeks even after a fully completed and issue free review, and longer in other
            cases)
            
            o   If the partner hasn’t responded in one week, you will receive a similar notice (and again at week 
            two)

            If the partner has questions you are unable to answer, or if you have a particular concern, please 
            contact your Regional Business Compliance Specialist {$spclstDetails['userName']} at 
            {$spclstDetails['userid']}·
            Agilent’s Business Compliance team is pleased to support you in your Channel Compliance Process 
            completion!
EOT;
    }

    /**
     * Builds the emaill body for the DDQ Submitted notification given the Compliance Specialist and Channel Partner
     * Manager details
     *
     * @param array $spclstDetails Compliance Specialists details I.E. userName and userid
     * @param array $cpmDetails    Channel Partner Manager details I.E.
     *
     * @return string
     */
    private function ddqSubmittedEmailBody($spclstDetails, $cpmDetails)
    {
        return <<<EOT
            Dear Agilent CPM {$cpmDetails['subByName']}, 
            
            {$spclstDetails['legalName']} has submitted the “Agilent Channel Partner Due Diligence Questionnaire”.
            If there is a need for clarification or further questions, either the internal Compliance Specialist or 
            Legal or Sales Management will contact you. The DDQ is now in qualification stage and we will review it as 
            soon as possible. Please remember that the due diligence process can take several weeks even after a fully 
            completed and issue free review, and longer in other cases.
            Note:  Partner Compliance Training may also be required (sent to training participants identified in the 
            Scorecard you submitted).  
            Agilent’s Business Compliance team is pleased to support you in your Channel Compliance Process completion!
            If you have a particular concern, please contact your internal contact, your Agilent Compliance Specialist 
            {$spclstDetails['userName']} at {$spclstDetails['userid']}.
            
            Thank you for your support of our Channel Partner Business Compliance process!
EOT;
    }
}
