<?php
/**
 * Model: admin tool create cases/invitations from a list of 3P Profile numbers
 *
 * @keywords admin, bulk upload, 3p, cases, invitations
 */

namespace Models\TPM\Admin\BulkUpload;

use Lib\CaseCostTimeCalc;
use Lib\GlobalCaseIndex;
use Lib\InvitationControl;
use Lib\Legacy\CaseStage;
use Lib\Legacy\SysEmail;
use Lib\Legacy\UserType;
use Models\Cli\BackgroundProcessData;
use Models\Ddq;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;
use Models\ThirdPartyManagement\Admin\BulkUpload\IBIUpload;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ClientProfile;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\SubjectInfoDD;
use Models\ThirdPartyManagement\ThirdParty;
use Models\User;

/**
 * Data for admin tool Mass Case Invitations Order from 3P List
 */
#[\AllowDynamicProperties]
class MassCaseInvitOrderData
{
    public const DEFAULT_SCOPE = 11;
    public const DEFAULT_INVESTIGATOR = ServiceProvider::STEELE_VENDOR_ADMIN;
    public const DEFAULT_LANGUAGE = 'EN_US';
    public const REC_LIMIT = 3000;

    /**
     * Current client ID
     *
     * @var integer
     */
    protected $clientID;

    /**
     * \Lib\Database\MySqlPdo
     *
     * @var object
     */
    protected $DB = null;

    /**
     * \Skinny\Skinny instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * Authenticated User ID
     *
     * @var integer
     */
    protected $userID;

    /**
     * Client Profile instance
     *
     * @var object
     */
    protected $client = null;

    /**
     * Cases instance
     *
     * @var object
     */
    protected $cases = null;

    /**
     * GlobalCaseIndex instance
     *
     * @var object
     */
    protected $globalCaseIndex = null;

    /**
     * IBIUpload instance
     *
     * @var object
     */
    protected $IBIUpload = null;

    /**
     * Invitation Control instance
     *
     * @var object
     */
    protected $inviteCtrl = null;

    /**
     * Intake Forms
     *
     * @var array
     */
    protected $intakeForms = null;

    /**
     * Preferred Language Defined
     *
     * @var integer
     */
    protected $preferredLanguageDefined = null;

    /**
     * Preferred Language Custom Field ID
     *
     * @var integer
     */
    protected $prefLangFieldID = 0;

    /**
     * BackgroundProcessData instance
     *
     * @var object
     */
    protected $bgProcessData = null;

    /**
     * Starting Fail Safe value for loops
     *
     * @var integer
     */
    protected $startFailSafe = null;

    /**
     * Constructor - initialization
     *
     * @param int $clientID Client ID
     */
    public function __construct($clientID)
    {
        \Xtra::requireInt($clientID, 'clientID must be an integer value');
        $this->clientID = $clientID;
        $this->app = \Xtra::app();
        $this->userID = $this->app->session->authUserID;
        $this->DB  = $this->app->DB;
        $this->bgProcessData = new BackgroundProcessData();
        $this->cases = new Cases($this->clientID);
        $this->IBIUpload = new IBIUpload($this->clientID);
        $this->globalCaseIndex = new GlobalCaseIndex($this->clientID);
        $this->startFailSafe = (int)(1000000 / self::REC_LIMIT);
        $this->client = (new ClientProfile())->findById($this->clientID);
        $this->inviteCtrl = new InvitationControl(
            $this->clientID,
            ['due-diligence', 'internal'],
            true,
            true
        );
        $this->setIntakeForms();
        $this->setPreferredLanguageDefined();
    }


    /**
     * Create background process record.
     *
     * @param integer $numRecs Number of records to process
     * @param integer $spID    investigatorProfile.id
     *
     * @return integer g_bgProcess.id
     */
    public function bgProcessCreate($numRecs, $spID = 0)
    {
        $data = ['clientID' => $this->clientID, 'userID'   => $this->userID, 'spID'     => (int)$spID, 'jobType'  => 'massOrderInvite', 'recordsToProcess' => $numRecs];
        if (!$batchID = $this->bgProcessData->createProcess($this->clientID, $this->userID, $data, true)) {
            $batchID = 0;
        }
        return $batchID;
    }




    /**
     * Update background process record.
     *
     * @param integer $batchID g_bgProcess.id
     * @param integer $checked Number of records processed
     *
     * @return void
     */
    public function bgProcessUpdate($batchID, $checked)
    {
        $batchID = (int)$batchID;
        $checked = (int)$checked;
        $endData = ['recordsCompleted' => $checked];
        $this->bgProcessData->toggleProcessStatus($batchID, $this->clientID, $this->userID, false, $endData);
    }

    /**
     * Get custom field language
     *
     * @param integer $tpID           thirdPartyProfile.id
     * @param integer $tpTypeCategory tpTypeCategory.id
     *
     * @return string Custom field language
     */
    public function getCustomFldLanguage($tpID, $tpTypeCategory)
    {
        $tpID = (int)$tpID;
        $tpTypeCategory = (int)$tpTypeCategory;

        $sql = "SELECT value FROM customData AS d\n"
            . "LEFT JOIN customFieldExclude AS e ON "
            . "(e.tpCatID = :tpCatID AND e.cuFldID = :cuFldID AND e.clientID = :cfExClientID)\n"
            . "WHERE d.tpID = :tpID AND d.clientID = :dataClientID AND d.fieldDefID = :fieldDefID\n"
            . "AND e.cuFldID IS NULL";
        $params = [':tpCatID' => $tpTypeCategory, ':cuFldID' => $this->prefLangFieldID, ':cfExClientID' => $this->clientID, ':tpID' => $tpID, ':dataClientID' => $this->clientID, ':fieldDefID' => $this->prefLangFieldID];
        return $this->DB->fetchValue($sql, $params);
    }



    /**
     * Given an id and an array, retrieve the name associated with the id.
     *
     * @param integer $id       id to reference
     * @param array   $srcArray contains values where the name will be retrieved
     *
     * @return string Name
     */
    public function getDataNameById($id = 0, $srcArray = [])
    {
        $id = (int)$id;
        $name = '';
        if ($id > 0 && !empty($srcArray)) {
            foreach ($srcArray as $idx => $item) {
                if ($item['id'] == $id) {
                    $name = $item['name'];
                    break;
                }
            }
        }
        return $name;
    }



    /**
     * Return a default value for a given type of data
     *
     * @param string $type Either investigator, language or scope
     *
     * @return mixed Either integer or string
     */
    public function getDefault($type)
    {
        $default = match ($type) {
            'investigator' => self::DEFAULT_INVESTIGATOR,
            'language' => self::DEFAULT_LANGUAGE,
            'scope' => self::DEFAULT_SCOPE,
            default => $default,
        };
        return $default;
    }

    /**
     * Getter method for intakeForms property
     *
     * @return array intakeForms property
     */
    public function getIntakeForms()
    {
        return $this->intakeForms;
    }

    /**
     * Getter method for intakeForms property
     *
     * @return array intakeForms property
     */
    public function getIntakeFormsConfig()
    {
        return $this->intakeFormsConfig;
    }

    /**
     * Assembles intakeForms options for use in select list
     *
     * @return array intakeForms
     */
    public function getIntakeFormsOptions()
    {
        $invitesEnabled = $this->app->ftr->tenantHas(\Feature::TENANT_DDQ_INVITE);
        $intakeForms = [];
        if (!$invitesEnabled) {
            $intakeForms[0] = '--- DDQ Invites Disabled ---';
        } else {
            // Get intake forms
            $intakeForms[0] = 'Do Not Send Invitations';
            foreach ($this->intakeForms as $id => $name) {
                $intakeForms[$id] = $name;
            }
        }
        return $intakeForms;
    }



    /**
     * Gets investigators for a given service provider
     *
     * @param integer $spID investigatorProfile.id
     *
     * @return array investigators
     */
    public function getInvestigators($spID)
    {
        // Investigators
        $spID = (int)$spID;
        $sql = "SELECT id, userName AS name FROM " . $this->DB->authDB . ".users\n"
            . "WHERE userType = :userType AND status <> 'inactive' AND status <> 'deleted' "
            . "AND vendorID = :spID ORDER BY userName ASC";
        $params = [
            ':userType' => UserType::VENDOR_ADMIN,
            ':spID'     => $spID
        ];
        if (!$investigators = $this->DB->fetchAssocRows($sql, $params)) {
            $investigators = [
                '0' => 'No Investigators Found'
            ];
        }
        return $investigators;
    }




    /**
     * Gets potential errors for a DDQ invitation.
     *
     * @param integer $tpID           thirdPartyProfile.id
     * @param integer $intakeFormType ddq.caseType
     * @param string  $action         either 'Invite' or 'Renew'
     *
     * @return array error messages
     */
    public function getInvitationExplanation($tpID, $intakeFormType, $action)
    {
        $tpID = (int)$tpID;
        $intakeFormType = (int)$intakeFormType;
        if (!$tpID || !$intakeFormType || !$action || ($action != 'Invite' && $action != 'Renew')) {
            throw new \Exception("Something went wrong when trying to get the invitation explanation.");
        }
        $this->inviteCtrl->getInvitationActions($tpID);
        return $this->inviteCtrl->explain($intakeFormType, $action);
    }



    /**
     * Get languages for a given intake form
     *
     * @param string $intakeForm intake form legacy ID (e.g. L-33a)
     *
     * @return array
     */
    public function getLanguages($intakeForm = '')
    {
        $rtn = [];
        if (!empty($intakeForm)) {
            $ddq = new Ddq($this->clientID);
            $intakeFormID = $ddq->splitDdqLegacyID($intakeForm);
            $scopeID = $intakeFormID['caseType'];
            $version = $intakeFormID['ddqQuestionVer'];
            $rtn = $ddq->getIntakeFormLanguages($scopeID, $version);
        }
        return $rtn;
    }



    /**
     * Get override data from the bulkIBIupload table
     *
     * @param string $userTpNum 3P Profile User Tp Number
     *
     * @return array Override data values
     */
    public function getOverrideData($userTpNum)
    {
        if (!$this->IBIUpload->usableOverrideTable()) {
            return false;
        }
        $overrideData = [];
        $IBIUpload = $this->IBIUpload->findByAttributes(
            [
            'p1email' => $userTpNum,
            'status' => 'pending',
            'clientID' => $this->clientID
            ]
        );
        if (!empty($IBIUpload)) {
            $overrideData = $IBIUpload->getAttributes();
        }
        return $overrideData;
    }


    /**
     * Get override requestor's ID
     *
     * @param string $requestor users.userid  (login username)
     *
     * @return integer users.id
     */
    public function getOverrideRequestorID($requestor = '')
    {
        $requestorID = 0;
        if (!empty($requestor)) {
            $sql = "SELECT id FROM " . $this->DB->prependDbName('auth', 'users') . "\n"
                . "WHERE userid = :requestor AND status <> 'inactive' AND status <> 'deleted'\n"
                . "AND (clientID = :clientID OR userType = :userType) LIMIT 1";
            $params = [
                ':requestor' => $requestor,
                ':clientID'  => $this->clientID,
                ':userType' => UserType::SUPER_ADMIN
            ];
            $requestorID = $this->DB->fetchValue($sql, $params);
        }
        return $requestorID;
    }



    /**
     * Return prefLangFieldID property
     *
     * @return bool
     */
    public function getPrefLangFieldID()
    {
        return $this->prefLangFieldID;
    }



    /**
     * Return preferredLanguageDefined property
     *
     * @return bool
     */
    public function getPreferredLanguageDefined()
    {
        return $this->preferredLanguageDefined;
    }

    /**
     * Returns dynamic sample of third party profile references
     *
     * @return string sample of tpProfileRefs
     */
    public function getSampleTpProfileRefs()
    {
        return $this->client->getSampleTpProfileRefs();
    }



    /**
     * Returns array of scopes (cases.caseType)
     *
     * @return array scopes
     */
    public function getScopes()
    {
        // Scope of Investigation
        $scopes = [];
        $caseScopes = $this->cases->getScopes('all', 0, false);
        // Remap array to make it conform to similar arrays' id/name formats.
        foreach ($caseScopes as $idx => $scope) {
            $scopes[$idx]['id'] = $scope['caseTypeID'];
            $scopes[$idx]['name'] = $scope['name'];
        }
        return $scopes;
    }

    /**
     * Getter for startFailSafe property
     *
     * @return integer startFailSafe property
     */
    public function getStartFailSafe()
    {
        return $this->startFailSafe;
    }





    /**
     * Returns array of third party profile ID's (thirdPartyProfile.id)
     *
     * @param array $profiles third party profile references (single entries or ranges)
     *
     * @return mixed either array of third party profiles, or false boolean
     */
    public function getThirdPartyIDs($profiles = [])
    {
        if (empty($profiles)) {
            return false;
        }

        $singleList = $rangeList = $profileExpression = '';
        if ($profiles['singles']) {
            $singleList = 'userTpNum IN("' . implode('","', $profiles['singles']) . '")';
        }
        if ($profiles['ranges']) {
            $tmp = [];
            $idx = 0;
            foreach ($profiles['ranges'] as $r) {
                $param1 = ':match_' . $idx . '_1';
                $param2 = ':match_' . $idx . '_2';
                $tmp[] = "userTpNum BETWEEN $param1 AND $param2";
                $params[$param1] = $r['match_1'];
                $params[$param2] = $r['match_2'];
                $idx++;
            }
            $rangeList = '(' . implode("\nOR ", $tmp) . ')';
        }
        if ($singleList && $rangeList) {
            $profileExpression = "($singleList\nOR $rangeList)";
        } elseif ($singleList) {
            $profileExpression = $singleList;
        } elseif ($rangeList) {
            $profileExpression = $rangeList;
        }

        // Count records
        $lastID = 0;
        $records = 0;
        $failSafe = $this->startFailSafe;

        // base SQL for efficient chunking through large number of records
        $sql = "SELECT id FROM thirdPartyProfile\n"
            . "WHERE clientID = :clientID AND status = 'active' AND id > :lastID AND $profileExpression\n"
            . "ORDER BY id ASC LIMIT :recLimit";
        $params[':clientID'] = $this->clientID;
        $params[':recLimit'] = self::REC_LIMIT;
        $tpIDs = [];

        do {
            $failSafe--; // prevent endless loop
            $params[':lastID'] = $lastID;
            if (!$IDs = $this->DB->fetchValueArray($sql, $params)) {
                break; // That's all folks!
            }
            $markTime = time();
            $tpIDs = array_merge($tpIDs, $IDs);
            foreach ($IDs as $tpID) {
                $tpID = (int)$tpID;
                $lastID = $tpID; // enables fast chunking through large dataset
                $records++;
            }
        } while ($failSafe > 0);

        if (count($tpIDs) <= 0 || $records == 0) {
            return false;
        } else {
            return $tpIDs;
        }
    }




    /**
     * Returns a third party profile record
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return array third party profile record
     */
    public function getThirdPartyRow($tpID)
    {
        $tpID = (int)$tpID;
        $thirdParty = (new ThirdParty($this->clientID))->findById($tpID);
        return $thirdParty->getAttributes();
    }


    /**
     * Check for open/unfinished cases record
     * Confine to due-diligence cases. (exclude training and internal form classes)
     *
     * Open case conditions
     *   1. case stage < Completed by Investigator with no ddq (manual case)
     *   2. case stage < Completed by Investigator and has due-diligence ddq
     *   3. case rejected (caseStage = 10) and has active due-diligence ddq
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return boolean true is has open case
     */
    public function hasOpenCase($tpID)
    {
        $sql = "SELECT e.id FROM thirdPartyElements AS e\n"
            . "LEFT JOIN cases AS c ON c.id = e.primaryID\n"
            . "LEFT JOIN ddq AS d ON d.caseID = c.id\n"
            . "WHERE e.thirdPartyID = :tpID AND e.tableName = 'cases' AND e.clientID = :clientID\n"
            . "AND c.id IS NOT NULL\n"
            . "AND (\n"
            . "  (c.caseStage < :caseCompleted\n"
            . "    AND (d.id IS NULL OR d.formClass = 'due-diligence'))"
            . "  OR (c.caseStage = :caseCanceled AND d.id IS NOT NULL\n"
            . "    AND d.formClass = 'due-diligence' AND d.status = 'active')\n"
            . ") LIMIT 1";
        $params = [':tpID' => (int)$tpID, ':clientID' => $this->clientID, ':caseCompleted' => CaseStage::COMPLETED_BY_INVESTIGATOR, ':caseCanceled' => CaseStage::CASE_CANCELED];
        return (bool)$this->DB->fetchValue($sql, $params);
    }



    /**
     * Check if there is a custom language field associated with a client, set property.
     *
     * @return void
     */
    private function setPreferredLanguageDefined()
    {
        $sql = "SELECT id FROM customField\n"
            . "WHERE clientID = :clientID AND scope = 'thirdparty' "
            . "AND hide = 0 AND name = 'preferred_language_code' LIMIT 1";
        $this->prefLangFieldID = (int)$this->DB->fetchValue($sql, [':clientID' => $this->clientID]);
        $this->preferredLanguageDefined = ($this->prefLangFieldID > 0) ? 1 : 0;
    }


    /**
     * Set intakeForms property.
     *
     * @return void
     */
    private function setIntakeForms()
    {
        $forms = (new Ddq($this->clientID))->getInvitationIntakeFormConfigs();
        foreach ($forms as $f) {
            $this->intakeForms[$f['legacyID']] = $f['name'];
            $this->intakeFormsConfig[$f['legacyID']] = $f;
        }
    }


    /**
     * Update new case with batch ID. Updates record data (for csv file) with new userCaseNum.
     * Also marks bulkIBIupload record (if any) as 'used' to prevent re-use.
     *
     * @param integer $caseID     cases.id
     * @param integer $batchID    cases.batchID
     * @param string  $profileNum userTpNum
     * @param array   $data       csv data for uotput to file
     *
     * @return void
     */
    public function updateBatchCase($caseID, $batchID, $profileNum, &$data)
    {
        // add batch ID to case record
        $case = $this->cases->findByAttributes(['id' => $caseID, 'clientID' => $this->clientID]);
        if (!empty($case)) {
            if (!$case->set('batchID', $batchID) || !$case->save()) {
                throw new \Exception('Not able to save Cases batchID ' . $this->getErrors());
            }
            // global index sync
            $this->globalCaseIndex->syncByCaseData($caseID);

            // update record csv data with userCaseNum
            $data['Case #'] = $case->get('userCaseNum');
        }

        // mark bulkIBIupload record (if any) as 'used'
        $IBIUpload = $this->IBIUpload->findByAttributes(
            ['p1email' => $profileNum, 'status' => 'pending', 'clientID' => $this->clientID]
        );
        if (!empty($IBIUpload)) {
            if (!$IBIUpload->set('status', 'used') || !$IBIUpload->save()) {
                throw new \Exception('Not able to save IBIUpload status: ' . $this->getErrors());
            }
        }
    }



    /**
     * Update thirdPartyProfile record's POCname and POCemail fields, syncs with spCases.
     *
     * @param integer $tpID           thirdPartyProfile.id
     * @param string  $updatePOCname  thirdPartyProfile.POCname
     * @param string  $updatePOCemail thirdPartyProfile.POCemail
     *
     * @return void
     */
    public function updateTpProfile($tpID, $updatePOCname, $updatePOCemail)
    {
        $tpID = (int)$tpID;
        $sets = $params = [];
        if ($updatePOCname) {
            $sets['POCname'] = 'POCname = :POCname';
            $params[':POCname'] = $updatePOCname;
        }
        if ($updatePOCemail) {
            $sets['POCemail'] = 'POCemail = :POCemail';
            $params[':POCemail'] = $updatePOCemail;
        }
        if (count($sets) > 0) {
            $sql = "UPDATE thirdPartyProfile SET\n"
                . implode(', ', $sets) . "\n"
                . "WHERE id = :id AND clientID = :clientID LIMIT 1";
            $params[':id'] = $tpID;
            $params[':clientID'] = $this->clientID;
            $this->DB->query($sql, $params);

            // sync with the global index - no longer applies
        }
    }
}
