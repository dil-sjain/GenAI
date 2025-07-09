<?php
/**
 * Model: to initialize third party
 *
 * @keywords ThirdPartyInit, Third, Party, Initialize
 */

namespace Models\TPM\Admin\ThirdPartyInit;

use Lib\BumpTimeLimit;
use Lib\Database\ChunkResults;
use Lib\Database\MySqlPdo;
use Lib\GlobalCaseIndex;
use Lib\Legacy\CaseStage;
use Lib\Legacy\UserType;
use Lib\Legacy\Security;
use Lib\SettingACL;
use Models\Globals\Settings\SaveSettings;
use Models\Globals\Settings\SaveSettingsConfig;
use Models\TPM\MultiAddr\TpAddrs;
use Models\TPM\InsertUniqueUserNumRecord;
use PDOException;
use Exception;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class ThirdPartyInitData
{
    /**
     * @var MySqlPdo PDO database instance
     */
    private $DB = null;

    /**
     * @var \Skinny\Skinny Application framework instance
     */
    private $app = null;

    /**
     * Constructor - initialization
     *
     * @return void
     */
    public function __construct()
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->logger = $this->app->log;
        $this->tmLimit = BumpTimeLimit::getInstance();
    }

    /**
     * Return Client data from databases
     *
     * @param int $clientID Id to look up client data
     *
     * @return object $result contains clientName and tpAccess properties
     */
    public function returnClientInfo($clientID)
    {
        $sql = "SELECT `clientName` FROM `clientProfile` WHERE `id` = :id";
        $params = [':id' => $clientID];
        $result = $this->DB->fetchObjectRow($sql, $params);
        $result->tpAccess = ($this->app->ftr->tenantHas(\Feature::TENANT_TPM)) ? 1 : 0;
        return $result;
    }

    /**
     * Get default tpType and tpTypeCategory - creates them if needed
     *
     * @param int $clientID 3PM tenant ID
     *
     * @return array[int] [tpType, tpCategory]
     */
    public function defaultTypeCategory(int $clientID): array
    {
        $tpType = $tpCategory = 0;
        $catSql = "SELECT * FROM tpTypeCategory WHERE clientID = :cid ORDER BY id LIMIT 1";
        if (!($cat = $this->DB->fetchObjectRow($catSql, [':cid' => $clientID]))) {
            // Create default type and category
            $typeSql = "INSERT INTO tpType SET name = 'Intermediary', clientID = :cid, description = ''";
            $catParams = [':cid' => $clientID];
            if (($res = $this->DB->query($typeSql, $catParams)) && $res->rowCount()) {
                if ($tpType = $this->DB->lastInsertId()) {
                    $catParams[':type'] = $tpType;
                    $catSql = "INSERT INTO tpTypeCategory SET name = 'Agent', tpType = :type, clientID = :cid";
                    if (($res = $this->DB->query($catSql, $catParams)) && $res->rowCount()) {
                        $tpCategory = $this->DB->lastInsertId();
                    }
                }
            }
        } else {
            $tpType = $cat->tpType;
            $tpCategory = $cat->id;
        }
        return [$tpType, $tpCategory];
    }

    /**
     * Attempt to determine an appropriate thirdPartyProfile.ownerID
     *
     * @return integer Owner's users.id
     */
    public function defaultOwnerID()
    {
        $clientID = $this->app->session->get('clientID');

        $authDB = $this->DB->authDB;
        $cAdmin = UserType::CLIENT_ADMIN;
        $cMgr = UserType::CLIENT_MANAGER;
        $ro = Security::READ_ONLY;
        $sqlbase = "SELECT id FROM {$authDB}.users "
            . "WHERE clientID=:clientID AND status='active' "
            . "AND userSecLevel > '$ro' AND userType=";
        $sqltail = " ORDER BY id ASC LIMIT 1";
        $params = [':clientID' => $clientID];
        $ownerID = '';

        // is there an active client admin?
        if ($tmpID = $this->DB->fetchValue($sqlbase . "'$cAdmin'" . $sqltail, $params)) {
            $ownerID = $tmpID;
        } elseif ($tmpID = $this->DB->fetchValue($sqlbase . "'$cMgr' AND LENGTH(mgrRegions) = 0" . $sqltail, $params)) {
            $ownerID = $tmpID;
        }

        // last resort, use logged in user
        if (empty($ownerID)) {
            $ownerID = intval($this->app->ftr->user);
        }
        return $ownerID;
    }

    /**
     * Return third party data from databases
     *
     * @param integer $clientID Id to look up client data
     *
     * @return integer clientID
     *
     * @throws \Exception
     */
    public function initializeThirdParty($clientID)
    {
        $clientID = (int)$clientID;
        $authDB = $this->DB->authDB;

        // Bail if TPM is already enabled
        $info = $this->returnClientInfo($clientID);
        if ($info->tpAccess) {
            throw new \Exception('Third Party Management has already been initialized for this client.');
        }

        $caseDeletedStage = CaseStage::DELETED;
        $caseStageInvite = CaseStage::DDQ_INVITE;

        // Get all the cases for this Client
        $sql = "SELECT * FROM `cases` WHERE id > :uniqueID AND `clientID` = :clientID "
            . "AND `caseStage` <> :caseDeletedStage ORDER BY id ASC";
        $params = [':clientID' => $clientID, ':caseDeletedStage' => $caseDeletedStage];
        $chunker = new ChunkResults($this->DB, $sql, $params);

        // Get default 3P type and category
        [$tpType, $tpCategory] = $this->defaultTypeCategory($clientID);

        while ($rec = $chunker->getRecord()) {
            // Get matching subinfoDD Record
            $caseID = $rec['id'];
            $sql = "SELECT * FROM `subjectInfoDD` WHERE `caseID` = :caseID";
            $params = [':caseID' => $caseID];

            $subjectInfoDDRow = $this->DB->fetchAssocRow($sql, $params);
            if (!$subjectInfoDDRow) {
                continue;
            }

            // See if there is a DDQ record for this case
            $sql = "SELECT * FROM `ddq` WHERE `caseID` = :caseID";
            $params = [':caseID' => $caseID];

            $companyWebsite = '';
            $bPublicTrade = '';
            $stockExchange = '';
            $tickerSymbol = '';
            $yearsInBusiness = '';
            $regCountry = '';
            $regNumber = '';
            $regDate = '';
            $ddqRowID = '';

            $ddqRow = $this->DB->fetchAssocRows($sql, $params);
            if (count($ddqRow) > 0) {
                $ddqRowID = $ddqRow[0]['id'];
                $companyWebsite = $ddqRow[0]['companyWebsite'];
                $bPublicTrade = $ddqRow[0]['bPublicTrade'];
                $stockExchange = $ddqRow[0]['stockExchange'];
                $tickerSymbol = $ddqRow[0]['tickerSymbol'];
                $yearsInBusiness = $ddqRow[0]['yearsInBusiness'];
                $regCountry = $ddqRow[0]['regCountry'];
                $regNumber = $ddqRow[0]['regNumber'];
                $regDate = $ddqRow[0]['regDate'];
            }

            // Find the user.id for ownerID
            $userid = $rec['requestor'];
            $sql = "SELECT * FROM {$authDB}.users WHERE `userid` = :userid";
            $params = [':userid' => $userid];

            $ownerID = 0;
            if (($userRow = $this->DB->fetchAssocRow($sql, $params))
                && $userRow['userType'] <= UserType::CLIENT_ADMIN
                && $userRow['userType'] >= UserType::CLIENT_USER
            ) {
                $ownerID = $userRow['id'];
            } elseif (!empty($this->app->session)) {
                \Xtra::track("Invalid case requestor: {$rec['requestor']}", \Skinny\Log::DEBUG);
                $ownerID = $this->defaultOwnerID();
            }
            if (!$ownerID) {
                \Xtra::track("Unable to obtain ownerID for new 3P profile", \Skinny\Log::ERROR);
                continue;
            }

            $region = $rec['region'];
            $dept = $rec['dept'];
            // Build and Insert third party Profile Row
            $tpAttr = [
                'clientID' => $clientID,
                'legalName' => $subjectInfoDDRow['name'],
                'DBAname' => $subjectInfoDDRow['DBAname'],
                'addr1' => $subjectInfoDDRow['street'],
                'addr2' => $subjectInfoDDRow['addr2'],
                'city' => $subjectInfoDDRow['city'],
                'country' => $subjectInfoDDRow['country'],
                'state' => $subjectInfoDDRow['state'],
                'postcode' => $subjectInfoDDRow['postCode'],
                'region' => $region,
                'website' => $companyWebsite,
                'bPublicTrade' => $bPublicTrade,
                'stockExchange' => $stockExchange,
                'tickerSymbol' => $tickerSymbol,
                'legalForm' => $subjectInfoDDRow['legalForm'],
                'yearsInBusiness' => $yearsInBusiness,
                'regCountry' => $regCountry,
                'regNumber' => $regNumber,
                'regDate' => $regDate,
                'POCname' => $subjectInfoDDRow['pointOfContact'],
                'POCposi' => $subjectInfoDDRow['POCposition'],
                'POCphone1' => $subjectInfoDDRow['phone'],
                'POCemail' => trim((string) $subjectInfoDDRow['emailAddr']),
                'userTpNum' => '', // provided inside INSERT transaction
                'ownerID' => $ownerID,
                'tpCreated' => date('Y-m-d H:i:s'),
                'createdBy' => $ownerID,
                'department' => $dept,
                'tpType' => $tpType,
                'tpTypeCategory' => $tpCategory,
            ];

            // Insert thirdPartyProfile using a transaction
            try {
                $insUniq = new InsertUniqueUserNumRecord($clientID);
                if ($GLOBALS['PHPUNIT']) {
                    $insUniq->debug = true;
                }
                $insResult = $insUniq->insertUnique3pProfile($tpAttr);
            } catch (Exception | PDOException) {
                $insResult = ['tpID' => 0, 'userTpNum' => ''];
            }
            if (!($thirdPartyID = $insResult['tpID'])) {
                \Xtra::track("Failed to create 3P profile on case.id {$rec['id']}");
                continue;
            }

            $sql = "UPDATE `cases` SET `tpID`= :thirdPartyID WHERE `id` = :caseID";
            $params = [':caseID' => $caseID, ':thirdPartyID' => $thirdPartyID];

            $resultCaseUpdate = $this->DB->query($sql, $params);

            // sync global index
            $globalIdx = new GlobalCaseIndex($clientID);
            $globalIdx->syncByCaseData($caseID);

            // Insert Third Party Element Row for the Case
            $sql = "INSERT INTO thirdPartyElements SET "
                . "clientID = :clientID, "
                . "thirdPartyID = :thirdPartyID, "
                . "tableName = 'cases', "
                . "primaryID = :primaryID";
            $params = [':primaryID' => $caseID, ':thirdPartyID' => $thirdPartyID, ':clientID' => $clientID];

            $resultThirdPartyElement = $this->DB->query($sql, $params);

            // If there is a DDQ record for this third party insert a
            // Third Party Element for it also
            if (count($ddqRow) > 0) {
                $sql = "INSERT INTO thirdPartyElements SET "
                    . "clientID = :clientID, "
                    . "thirdPartyID = :thirdPartyID, "
                    . "tableName = :tableName, "
                    . "primaryID = :ddqRowID";
                $params = [
                    ':clientID' => $clientID,
                    ':thirdPartyID' => $thirdPartyID,
                    ':tableName' => 'ddq',
                    ':ddqRowID' => $ddqRowID
                ];

                $resultThirdPartyElement = $this->DB->query($sql, $params);
                $this->tmLimit->bump();
            }
        } // end for x (cases)

        // Activate 3P setting/feature for tenant
        $setACL = new SaveSettings($clientID, SaveSettingsConfig::APPCONTEXT_TP_INIT_DATA);
        $setACL->setAuthUserID($this->app->ftr->user);
        $setACL->setAuthUserType($this->app->ftr->legacyUserType);

        if (($setting = $setACL->get(SettingACL::TENANT_TPM))
            && ($saved = $setACL->save(['setting' => $setting['setting'], 'newValue' => 1]))
            && (is_array($saved) && !empty($saved))
        ) {
            // We have some errors. Puke 'em out.
            throw new \Exception("Errors: " . implode(', ', $saved));
        }
        // Made it out alive.
        return $clientID;
    }
}
