<?php
/**
 * Controller: General Mills Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

use Controllers\TPM\Email\Cases\Invitation;
use Lib\InvitationControl;
use Lib\Legacy\IntakeFormTypes;
use Lib\Legacy\SysEmail;
use Models\Ddq;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/GeneralMills.php provides Workflow functionality for
 * the General Mills client (TenantID = 124 or 9124)
 */
#[\AllowDynamicProperties]
class GeneralMills extends TenantWorkflow
{
    /**
     * GeneralMills constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow() || ($this->tenantID != 124 && $this->tenantID != 9124)) {
            throw new \Exception(
                "Not General Mills tenant or General Mills tenant doesn't have Workflow Feature/Setting enabled."
            );
        }
    }

    /**
     * Starts the General Mills Third Party Profile approval workflow.
     *
     * @param int     $tpID     Third Party Profile id - thirdPartyProfile.id
     * @param int     $caseID   Case Folder id of the intake form - cases.id
     * @param boolean $isUpload Flag to indicate if method is being called from the 3P Upload process or not
     *
     * @return void
     */
    public function startProfileWorkflow($tpID, $caseID = null, $isUpload = false)
    {
        try {
            // Get the profile record and determine if a workflow exists for this profile.
            $sql = "SELECT * FROM thirdPartyProfile WHERE id = :id AND clientID = :tenantID";
            $profile = $this->app->DB->fetchAssocRow(
                $sql,
                [':id' => $tpID, ':tenantID' => $this->tenantID]
            );
            // Start the workflow if the profile exists
            if (!empty($profile)) {
                // Send (current) DDQ else send (prospective DDQ)
                if (!empty($profile['POCemail'])) {
                    if ($this->okToSendDDQ($profile['id'])) {
                        // if no internalCode send Prospecitve DDQ else send Current DDQ
                        $ddq = (empty($profile['internalCode']))
                            ? IntakeFormTypes::DDQ_SBI_FORM2
                            : IntakeFormTypes::DDQ_SBI_FORM5;
                        if ($isUpload) {
                            $this->autoSendDDQ($profile, $ddq);
                        } else {
                            parent::autoSendDDQ($profile, $ddq);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Failed to connect to the Axon Ivy Database, check ENV and connectivity between servers.
            \Xtra::track([
                'error' => $e->getMessage(),
                'location' => ($e->getFile() . ':' . $e->getLine()),
                'trace' => $e->getTraceAsString(),
            ]);
        }
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
     * Determine if a DDQ should be sent. In this case if the Risk Tier is 'High' send the DDQ
     *
     * @param integer $profileID Profile ID
     *
     * @return bool True if ok to send else false
     */
    private function okToSendDDQ($profileID)
    {
        $riskTier = $this->getRiskTier($profileID);
        return $riskTier == 'High';
    }

    /**
     * Automates the process of sending a DDQ and creating a Case Folder.
     *
     * @param array $thirdParty third party profile record
     * @param int   $formType   form type for the DDQ e.g. L-95 would be the int 95, likewise L-95b would be 95
     *
     * @return bool
     */
    #[\Override]
    public function autoSendDDQ($thirdParty, $formType = IntakeFormTypes::DUE_DILIGENCE_SBI)
    {
        if (!empty($thirdParty['POCemail'])) {
            $tpID = $thirdParty['id'];
            $ownerID = $thirdParty['ownerID'];
            $sql = "SELECT userid FROM {$this->app->DB->authDB}.users WHERE id = :id";
            $requester = $this->app->DB->fetchValue($sql, [':id' => $ownerID]);
            $Ddq = new Ddq($this->tenantID, ['authUserID' => $ownerID]);
            $invitationControl = new InvitationControl($this->tenantID, ['due-diligence', 'internal'], true, true);
            $inviteControl = $invitationControl->getInviteControl($tpID);
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
                    'requestor' => $requester,
                    'creatorUID' => $requester,
                    'tpID' => $tpID,
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
            $ddq = (new Ddq($this->tenantID, ['authUserID' => $ownerID]))->findByAttributes(['caseID' => $caseID]);
            $ddqID = $ddq->getId();
            (new Invitation($this->tenantID, $ddqID, $ownerID))->send();

            // Ensure records are linked
            $this->ensureRecordLinkage($tpID, $caseID, $ddqID);
        }
        return true;
    }

    /**
     * Make sure related records are connected.
     * This shouldn't be necessary, but fails from g_batchScan
     *
     * @param int $tpID   thirdPartyProfile.id
     * @param int $caseID cases.id
     * @param int $ddqID  ddq.id
     *
     * @return void
     */
    private function ensureRecordLinkage($tpID, $caseID, $ddqID)
    {
        if ($tpID <= 0 || $caseID <= 0) {
            return;
        }
        $DB = $this->app->DB;
        $clientDB = $DB->getClientDB($this->tenantID);
        $caseTbl = $clientDB . '.cases';
        $ddqTbl = $clientDB . '.ddq';

        // associate profile with case
        $sql = "UPDATE $caseTbl SET tpID = :tpID WHERE id = :caseID LIMIT 1";
        $DB->query($sql, [':tpID' => $tpID, ':caseID' => $caseID]);
        $this->insert3pElement($tpID, 'cases', $caseID);

        // associate case with ddq
        if ($ddqID > 0) {
            $sql = "UPDATE $ddqTbl SET caseID = :caseID WHERE id = :ddqID LIMIT 1";
            $DB->query($sql, [':ddqID' => $ddqID, ':caseID' => $caseID]);
            $this->insert3pElement($tpID, 'ddq', $ddqID);
        }
    }

    /**
     * Add missing thirdPartyElements record
     *
     * @param int    $tpID      thirdPartyProfile.id
     * @param string $tableName 'ddq' or 'cases'
     * @param int    $elementID thiredPartyElements.primaryID
     *
     * @return bool
     */
    private function insert3pElement($tpID, $tableName, $elementID)
    {
        $rtn = false;
        $DB = $this->app->DB;
        $clientDB = $DB->getClientDB($this->tenantID);
        $eleTbl = $clientDB . '.thirdPartyElements';
        $sql = "SELECT id FROM $eleTbl\n"
            . " WHERE thirdPartyID = :tpID AND clientID = :cli AND tableName = :tbl AND primaryID = :id LIMIT 1";
        $params = [':cli' => $this->tenantID, ':tpID' => $tpID, ':tbl' => $tableName, ':id' => $elementID];
        if (!$DB->fetchValue($sql, $params)) {
            $sql = "INSERT INTO $eleTbl SET clientID = :cli, thirdPartyID = :tpID, tablename = :tbl, primaryID = :id";
            if (($res = $DB->query($sql, $params)) && $res->rowCount()) {
                 $rtn = true;
            }
        }
        return $rtn;
    }
}
