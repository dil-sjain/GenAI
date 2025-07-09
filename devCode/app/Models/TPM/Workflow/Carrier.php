<?php
/**
 * public_html/cms/includes/php/Models/TPM/Workflow/Carrier.php
 */

namespace Models\TPM\Workflow;

use Controllers\TPM\Email\IntakeForms\Legacy\IntakeFormSubmission;
use Lib\Legacy\ClientIds;
use Models\Ddq;

/**
 * Class Carrier - Legacy version of app/Models/TPM/Workflow/Carrier.php provides workflow functionality
 * for Carrier (TenantID 310)
 */
#[\AllowDynamicProperties]
class Carrier extends TenantWorkflow
{
    /**
     * Carrier constructor.
     *
     * @param int $tenantID g_tenants.id
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow()
            || (!in_array($this->tenantID, [ClientIds::CARRIER_CLIENTID]))
        ) {
            throw new \Exception("Not Carrier or Carrier doesn't have Workflow Feature/Setting enabled.");
        }
    }

    /**
     * Starts the Carrier Third Party Profile approval workflow
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
            $wfExists = $this->workflowExists($this->tenantID, $tpID);
            if (!empty($thirdParty) && !$wfExists && isset($ddqID)) {
                $result = false;

                // Create workflow initiated entry.
                $this->createWorkflowExistsEntry($this->tenantID, $tpID);
                if (!empty($thirdParty['POCemail']) && $this->tenantHasEvent(self::SEND_DDQ)) {
                    // Automatically send DDQ Invitation with no Case.
                    $result = $this->autoSendDDQ($thirdParty);
                }
                return $result;
            }
        } catch (\Exception) {
            // Failed to connect to start Third Party Profile Workflow.
            $msg = "Failed to start Third Party Profile Workflow for {$tpID}.";
            $this->app->log->debug(__FILE__ . ":" . __LINE__ . " error = $msg");
            $this->dbCon = false;
        }
    }

    /**
     * Process only Scorecard (in this case they are form type 'due-diligence') ddq forms. Current Carrier
     * Scorecard form types are:
     *
     *     Internal Intake Form (L-36) - DDQ_SHORTFORM_FORM3
     *     Due Diligence Questionnaire (L-12) - DUE_DILIGENCE_SBI
     *
     * @param integer $ddqID     DDQ ID
     * @param integer $profileID 3P Profile ID
     * @param integer $caseType  Case type
     *
     * @return void
     */
    public function scorecardSubmitted($ddqID, $profileID, $caseType)
    {
        // if no profileID then it's an OpenURL ddq (no linked profile)
        if (empty($profileID)
            && $this->tenantHasEvent(self::SCORECARD_AUTOMATION)
            && in_array($caseType, [DDQ_SHORTFORM_FORM3])
        ) {
            $answers = $this->getScorecardAnswers($ddqID);
            if (!empty($answers['toEmail'])) {
                // $email = $this->getSubBuEmailInfo($answers); // leaving in case Carrier changes their mind again
                $ddq = (new Ddq($this->tenantID, ['authUserID' => 0]));
                $isResubmission = false;
                if ($ddq = $ddq->findById($ddqID)) {
                    $isResubmission = ($ddq->get('returnStatus') == 'pending');
                }
                $userID = $ddq->getDdqRequestorUserID($isResubmission, $answers['caseID']);
                (new IntakeFormSubmission($this->tenantID, $ddqID, $userID))->send();
            }
        }
    }

    /**
     * Based upon the business unit, get the associated email address out of g_workflowFieldMap for
     * notification purposes
     *
     * @param integer $data Contains relevant data for fetching email info
     *
     * @return integer DDQ form type (integer) or zero if not found
     */
    private function getSubBuEmailInfo($data)
    {
        $whereSql = '';
        $params = [':tenantID' => $this->tenantID, ':priID' => $data['subBU']];

        // if the SubBU is FSP - Asia (rowID 231) and the country is India (IN), get that persons
        // email info
        if ($data['subBU'] == 231) {
            $whereSql = 'AND secRowID = :secID';
            $params[':secID'] = ($data['country'] == 'IN') ? 'IN' : '';
        }

        $sql = "SELECT em.toEmail, em.toName, u.userName, u.userEmail"
            . " FROM {$this->app->DB->globalDB}.g_workflowFieldMap AS em"
            . " LEFT JOIN {$this->app->DB->authDB}.users AS u ON u.id = em.userID"
            . " WHERE tenantID = :tenantID AND priRowID = :priID {$whereSql} AND mapType = 'email' LIMIT 1";

        $results = $this->app->DB->fetchAssocRow($sql, $params);

        if ($results && !empty($results['userName'])) {
            $results['toName']  = $results['userName'];
            $results['toEmail'] = $results['userEmail'];
        }

        return $results;
    }

    /**
     *  Get the DDQ answers. The field genQuest132 is a custom list.
     *
     * @param integer $ddqID DDQ ID
     *
     * @return array Contains answer value and text
     */
    private function getScorecardAnswers($ddqID)
    {
        $result = [];

        // get questions country and genQuest132 answers
        $questions = 'caseID, country, genQuest132 AS subBU, subByEmail AS toEmail';
        $sql = "SELECT {$questions} FROM ddq WHERE id = :id";
        $answers = $this->app->DB->fetchAssocRow($sql, [':id' => (int)$ddqID]);

        if (!empty($answers)) {
            $result = $answers;
        }

        return $result;
    }

    /**
     * Get the 3P Profile record associated with the DDQ
     *
     * @param integer $profileID Profile ID
     *
     * @return mixed 3P Profile assoc array or false if not found
     */
    private function getProfileType($profileID)
    {
        $sql = "SELECT tpType FROM thirdPartyProfile WHERE id = :id LIMIT 1";
        return $this->app->DB->fetchValue($sql, [':id' => (int)$profileID]);
    }

    /**
     * Based upon the business unit, get the associated email address out of g_workflowEmailMap for
     * notification purposes
     *
     * @param integer $priID ID for primary reference table
     * @param integer $secID ID for secondary reference table
     *
     * @return integer DDQ form type (integer) or zero if not found
     */
    private function getBusinessUnitEmail($priID, $secID = null)
    {
        $whereSql = '';
        $params = [':clientID' => $this->tenantID, ':priID' => $priID];

        if ($secID) {
            $whereSql = 'AND secRowID = :secID';
            $params[':secID'] = $secID;
        }

        $sql = "SELECT em.priTable, em.secTable, em.userID, em.toEmail, em.toName"
            . " FROM {$this->app->DB->globalDB}.g_workflowEmailMap AS em"
            . " WHERE clientID = :clientID AND priRowID = :priID {$whereSql} LIMIT 1";

        $result = $this->app->DB->fetchAssocRow($sql, $params);

        return $result;
    }

    /**
     * Loop through the passed in list of DDQ's looking for a prior DDQ (not Attestation), if DDQ found
     * return Attestation type else return Renewal DDQ type
     *
     * @param array $pastDdqs Array of DDQ's (both DDQ and Attestations)
     *
     * @return int DDQ Type
     */
    private function geDdqTypeToSend($pastDdqs)
    {
        foreach ($pastDdqs as $ddq) {
            switch ($ddq['caseType']) {
                case DUE_DILIGENCE_SBI:
                case DUE_DILIGENCE_SBI_RENEWAL:
                    return DDQ_SHORTFORM_2PAGEA; // Attestation
            }
        }
        return DUE_DILIGENCE_SBI_RENEWAL; // Renewal DDQ
    }


    /**
     *  Get the DDQ answer. The field genQuest132 is a custom list so we need to dig out the real
     *  answer from Custom Fields (in this case the data is coming from 3P Types - tpType table)
     *
     * @param integer $ddqID DDQ ID
     *
     * @return array Contains answer value and text
     */
    private function getDDQAnswer($ddqID)
    {
        $result = [];

        // get Channel Partner question answers
        $question = 'genQuest132 AS subBU';
        $sql = "SELECT {$question} FROM ddq WHERE id = :id";
        $ddqVal = $this->app->DB->fetchValue($sql, [':id' => (int)$ddqID]);

        if (!empty($ddqVal)) {
            // The above genQuest132 field is a Custom List (controlType: DDLfromDB, generalInfo: tpType)
            // with the answer data coming from the tpType table
            $sql = "SELECT `name` FROM tpType WHERE id = :id";
            $answerTxt = $this->app->DB->fetchValue($sql, [':id' => (int)$ddqVal]);
            $result = [$ddqVal => $answerTxt];
        }

        return $result;
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
}
