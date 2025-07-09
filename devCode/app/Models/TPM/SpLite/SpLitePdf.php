<?php
/**
 * SpLitePdf creates the PDF file for a case
 *
 * @keywords SpLitePdf, SpLite Pdf, service provider lite PDF
 * @see This class consolodates all the separate PDF files in public_html/cms/sp/inc into a single class
 */

namespace Models\TPM\SpLite;

use Lib\DdqAcl;
use Lib\DdqSupport;
use Lib\Legacy\CaseStage;
use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Lib\Legacy\Misc;
use Lib\Legacy\Security;
use Lib\Legacy\UserType;
use Models\CustomData;
use Models\Globals\AclScopes;
use Models\Globals\Features\TenantFeatures;
use Models\Globals\Geography;
use Models\Logging\AuditLogSql;

/**
 * Class to facilitate generating a SP Lite PDF file
 */
#[\AllowDynamicProperties]
class SpLitePdf
{
    /**
     * Database class instance
     *
     * @var object
     */
    private $DB = null;

    /**
     * Client database name
     *
     * @var object
     */
    private $authDB = null;

    /**
     * Service Provider database name
     *
     * @var object
     */
    private $spDb = null;

    /**
     * Skinny Instance
     *
     * @var object
     */
    private $app = null;

    /**
     * @var Geography Class instance
     */
    private Geography $geo;

    /**
     * Class constructor
     *
     * @param integer $clientID Client ID
     */
    public function __construct($clientID)
    {
        $app = \Xtra::app();
        $this->app = $app;
        $this->DB  = $app->DB;
        $clientID = (int)$clientID;
        $this->DB->setClientDB($clientID);

        $this->authDB  = $this->DB->authDB;
        $this->spDb    = $app->DB->spGlobalDB;
        $this->session  = $app->session;
        $this->sitePath = $app->sitePath;
        $this->geo = Geography::getVersionInstance(null, $clientID);
    }

    /**
     * Return various property values
     *
     * @param string $propertyName Name of property for which to return value
     *
     * @return mixed The value of the specified property
     *
     * @throws \Exception Throws an exception if a property is not found
     */
    public function __get($propertyName)
    {
        $result = match ($propertyName) {
            'authDB' => $this->authDB,
            'spDb' => $this->spDb,
            default => throw new \Exception("Unknown property: `$propertyName`"),
        };
        return $result;
    }

    /**
     * Generate the 'Additional Information' page of the PDF.
     *
     * @param object  $ddqRow         Contains all the auth information
     * @param array   $ddqRowVals     Contains all the auth information
     * @param integer $clientID       Contains all the case information
     * @param object  $subjectInfoRow Contains all the case information
     * @param object  $langCode       Contains all the case information
     *
     * @return object $rtn Object containing status/error information.
     *
     * @see This is a refactor of legacy addlinfo.sec
     */
    private function getAddlInfo($ddqRow, $ddqRowVals, $clientID, $subjectInfoRow, $langCode)
    {
        $aOnlineQuestions = [];
        if ($ddqRow && ($ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM
            && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM2
            && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM3
            && $ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL
            && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL
            && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL)
        ) {
            // We need to load the  page questions from onlineQuestions
            $aOnlineQuestions = $this->ddq->getQuestionsForPage(
                $clientID,
                $langCode,
                $ddqRow->caseType,
                'Additional Information',
                $ddqRow->ddqQuestionVer
            );
        }

        // If this is a HCP DDQ we need to display the Additional Information questions from the
        // questionnaire instead of
        // standard Add Info Text Area
        $data['type'] = '';
        if (($ddqRow) && ($ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_HCPDI
            || $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_HCPDI_RENEWAL)
        ) {
            $data['type'] = 'hcpDdq';
            foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
                // Find  ADDITIONAL INFORMATION Section elements
                if ($aOnlineQuestionsRow->sectionName == "ADDITIONAL INFORMATION") {
                    $data['hcpDdq'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                    $data['hcpDdq'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 100);
                }
            }
        } elseif (!$ddqRow || $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_SBI
            || $ddqRow->caseType == IntakeFormTypes::DDQ_SBI_FORM2
            || $ddqRow->caseType == IntakeFormTypes::DDQ_SBI_FORM3
            || $ddqRow->caseType == IntakeFormTypes::DDQ_SBI_FORM4
            || $ddqRow->caseType == IntakeFormTypes::DDQ_SBI_FORM5
            || $ddqRow->caseType == IntakeFormTypes::DDQ_SBI_FORM6
            || $ddqRow->caseType == IntakeFormTypes::DDQ_SBI_FORM7
        ) {
            $data['type'] = 'sbiForm';
            // This is a Generic Question Section, it won't even show up unless questions
            // have been added for it in onlineQuestions
            if (isset($aOnlineQuestions)) {
                foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
                    // Find KEY PERSON GENQUEST Section elements
                    if ($aOnlineQuestionsRow->sectionName == "ADDINFO GENQUEST1") {
                        $data['genericQ1'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                        $data['genericQ1'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, "58%");
                    }
                }
            }
            $data['subjectInfo'] = $subjectInfoRow->addInfo;
        }

        if (isset($aOnlineQuestions)) {
            // This is a Generic Question Section, it won't even show up unless questions
            // have been added for it in onlineQuestions
            foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
                // Find KEY PERSON GENQUEST Section elements
                if ($aOnlineQuestionsRow->sectionName == "ADDINFO GENQUEST2") {
                    $data['genericQ2'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                    $data['genericQ2'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, "58%");
                }
            }
        }

        if ($ddqRow) {
            $data['authorizTxt'] = 'AUTHORIZATION AND USE OF THIS FORM';
            $onlineQuestionRow = $this->ddq->getQuestionsById(
                $clientID,
                'TEXT_AUTHANDUSE_SECTIONHEAD',
                $langCode,
                $ddqRow->caseType,
                $ddqRow->ddqQuestionVer
            );
            if ($onlineQuestionRow) {
                $data['authorizTxt'] = $onlineQuestionRow->labelText;
            }

            $onlineQuestionRow = $this->ddq->getQuestionsById(
                $clientID,
                'TEXT_AUTH_DESC',
                $langCode,
                $ddqRow->caseType,
                $ddqRow->ddqQuestionVer
            );
            $data['authorizLabel'] = $onlineQuestionRow->labelText;

            $params = (!empty($ddqRow->id)) ? ['ddqID' => $ddqRow->id] : [];
            $ddqAcl = new DdqAcl($params);

            // Initialize $curDate for some ACLs
            $curDate = substr((string) $ddqRow->subByDate, 0, 10);
            $aclID = (int)$ddqRow->aclID;
            if ($aclID > 0) {
                $aclRes = $ddqAcl->loadAclById($clientID, $aclID, $curDate);
            } else {
                $aclScopes = new AclScopes();
                // If this is an HCP we need the ACL from the ddq_hcp
                if ($ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_HCPDI
                    || $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_HCPDI_RENEWAL
                ) {
                    $aclRes = $ddqAcl->loadAcl(
                        $clientID,
                        $aclScopes->getAclScopeID($ddqRow->caseType),
                        '',
                        $ddqRow->bPreviousPartner,
                        'hcp'
                    );
                } else {
                    $aclRes = $ddqAcl->loadAcl(
                        $clientID,
                        $aclScopes->getAclScopeID($ddqRow->caseType),
                        '',
                        $ddqRow->bPreviousPartner
                    );
                }
            }
            if (strlen((string) $aclRes['content']) > 0) {
                $data['aclResContent'] = $aclRes['content'];
            }
        }

        return $data;
    }

    /**
     * Generate the 'Attachments Information' page of the PDF.
     *
     * @param array   $ddqRow   Contains all the auth information
     * @param integer $clientID Contains all the case information
     * @param object  $caseID   Contains all the case information
     * @param array   $spDocs   Array of attachments (uploaded docs)
     *
     * @return array  $data Array containing the attachments and message info..
     *
     * @see This is a refactor of legacy dt-attachments.sec
     */
    private function getAttachmentsInfo($ddqRow, $clientID, $caseID, $spDocs)
    {
        if ($ddqRow) {
            $data['ddqAttachedMsg'] = false;
        } else {
            $data['ddqAttachedMsg'] = true;
            $sql = "SELECT bInfoQuestnrAttach FROM subjectInfoDD "
                . "WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
            $params = [':caseID' => $caseID, ':clientID' => $clientID];
            $attached = $this->DB->fetchValue($sql, $params);
            if ($attached != 'Yes') {
                $attached = 'No';
            }
            $data['ddqAttached'] = $attached;
        }

        $data['spDocs'] = $spDocs;

        return $data;
    }

    /**
     * Generate the 'Business Practices' page of the PDF.
     *
     * @param object  $ddqRow     Contains all the auth information
     * @param array   $ddqRowVals Contains all the auth information
     * @param integer $clientID   Client ID
     * @param string  $langCode   Language code
     *
     * @return array  $data Contains Business Practice data needed for PDF.
     *
     * @see This is a refactor of legacy bizprac.sec
     */
    private function getBizPracInfo($ddqRow, $ddqRowVals, $clientID, $langCode)
    {
        $countriesList = '';
        if ($ddqRow) {
            $sql = "SELECT iso_code FROM inFormRspnsCountries \n"
                . "WHERE inFormRspnsID = :inFormRspnsID AND tenantID = :tenantID";
            $params = [':inFormRspnsID' => $ddqRow->id, ':tenantID' => $clientID];
            if ($isoCodes = $this->DB->fetchValueArray($sql, $params)) {
                if ($countryNames = $this->geo->getCountryNames($isoCodes)) {
                    $countriesList = join(', ', $countryNames);
                }
            }
        }
        $data['countriesList'] = $countriesList;

        // load Business Practices page questions
        $aOnlineQuestions = $this->ddq->getQuestionsForPage(
            $clientID,
            $langCode,
            $ddqRow->caseType,
            'Business Practices',
            $ddqRow->ddqQuestionVer
        );

        $data['notHpClient'] = false;
        if (!in_array($clientID, ClientIds::HP_ALL)) {
            $data['notHpClient'] = true;
        }

        // Load INDUSTRY Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find all of the INDUSTRY Section elements
            if ($aOnlineQuestionsRow->sectionName == "INDUSTRY") {
                $data['industry'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                $data['industry'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 500);
            }
        }
        // Load PAST BUSINESS CONDUCT Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find all of the PAST BUSINESS CONDUCT Section elements
            if ($aOnlineQuestionsRow->sectionName == "PAST BUSINESS CONDUCT") {
                if ($aOnlineQuestionsRow->controlType == 'tarbYes') {
                    $data['pastBusConduct'][$i]['controlType'] = true;
                    $data['pastBusConduct'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                    $data['pastBusConduct'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 500);
                } else {
                    $data['pastBusConduct'][$i]['controlType'] = false;
                    $data['pastBusConduct'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                    $data['pastBusConduct'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, "30%");
                }
            }
        }

        // Load EXPLAIN CONDUCT Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find all of the EXPLAIN CONDUCT Section elements
            if ($aOnlineQuestionsRow->sectionName == "EXPLAIN CONDUCT") {
                $data['explainConduct'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                $data['explainConduct'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 500);
            }
        }

        return $data;
    }

    /**
     * Generate the 'Case/Audit Log' page of the PDF.
     *
     * @param integer $clientID Client ID
     * @param integer $caseID   Case ID
     * @param integer $userType Contains the user type constant
     *
     * @return array  $data Contains Case/Audit log data needed for PDF.
     *
     * @see This is a refactor of legacy caselog-ws.sec
     */
    private function getCaseLogInfo($clientID, $caseID, $userType)
    {
        $auditLogSql = new AuditLogSql($clientID);
        $data = [];
        $e_clientID = intval($clientID);
        $e_caseID = intval($caseID);
        $toPDF = true;

        $logTbl = 'userLog';
        $logTblDb = 'ul';
        $userRole = $this->getUserRole($userType);
        $userRoleID = (array_key_exists('id', $userRole) ? $userRole['id'] : 0);
        $userRoleName = (array_key_exists('name', $userRole) ? $userRole['name'] : 'client');
        $contextID = $this->getContextID('caseFolder'); // returns id:1
        $baseWhere = $auditLogSql->baseWhere($contextID, $userRoleID);
        $params = $baseWhere['params'];
        $params[':caseID'] = $e_caseID;
        $params[':clientID'] = $e_clientID;
        $where = "WHERE {$logTblDb}.caseID= :caseID AND {$logTblDb}.batchID IS NULL "
            . "AND {$logTblDb}.clientID = :clientID AND "
            . $baseWhere['sql'] . "\n";
        $joins = $auditLogSql->baseJoins($logTblDb);

        if (!isset($si)) {
            $si = '';
        }
        if (!isset($sortAlias)) {
            $sortAlias = '';
        }
        if (!isset($pg)) {
            $pg = '';
        }

        $sql = "SELECT COUNT(1) AS cnt FROM {$logTbl} AS {$logTblDb} " . $joins . $where;
        $cnt = $this->DB->fetchValue($sql, $params);

        if (!isset($pp)) {
            $pp = '';
        }
        if (!$cnt) {
            $cnt = '0';
        }

        $sortDir = 'ASC';

        $rows = [];
        if ($cnt > 0) {
            $clientDB = $this->DB->getClientDB($e_clientID);
            $sqlConfig = ['toPDF' => $toPDF, 'limit' => " LIMIT $si, $pp", 'sortCol' => $this->getSortCol($sortAlias), 'sortDir' => $sortDir, 'logTbl' => $logTbl, 'logTblDb' => $logTblDb, 'clientID' => $e_clientID, 'clientDB' => $clientDB, 'caseID' => $e_caseID, 'contextID' => $contextID, 'userRoleID' => $userRoleID, 'userRoleName' => $userRoleName];
            $sql = $auditLogSql->caseFldrAuditLogSQL($sqlConfig);
            $rows = $this->DB->fetchAssocRows($sql['sql'], $sql['params']);
        }

        if (is_array($rows)) {
            $data = $rows;
        }

        return $data;
    }

    /**
     * Generate the 'Case Notes' page of the PDF.
     *
     * @param integer $clientID  Client ID
     * @param integer $caseID    Case ID
     * @param string  $userClass Contains the class of the user (ie; 'vendor')
     * @param string  $globalDB  Global database name from legacy
     *
     * @return array  $data Contains case notes data needed for PDF.
     *
     * @see This is a refactor of legacy casenotes-ws.sec
     */
    private function getCaseNotesInfo($clientID, $caseID, $userClass, $globalDB)
    {
        $toPDF = true;
        $sortDir = 'DESC';
        $e_caseID = intval($caseID);
        $e_clientID = intval($clientID);
        $defOrderBy = 'subj';
        $allowOrderBy = ['subj' => 'n.subject', 'ndate' => 'n.created', 'owner' => 'u.lastName', 'cat' => 'nc.name'];
        $orderBy = '';
        $notesData = '[]';

        if (!array_key_exists($orderBy, $allowOrderBy)) {
            $orderBy = $defOrderBy;
        }

        // Get row counts
        $cntWhere = "WHERE caseID = :caseID AND qID='' AND clientID = :clientID";
        switch ($userClass) {
            case 'admin':
                break;
            case 'client':
                $cntWhere .= ' AND bInvestigator=0';
                break;
            case 'vendor':
                $cntWhere .= ' AND (bInvestigator = 1 OR bInvestigatorCanSee = 1)';
                break;
            default:
                $cntWhere .= ' AND 0';
        }
        $sql = "SELECT COUNT(*) AS cnt FROM caseNote $cntWhere";
        $params = [':caseID' => $e_caseID, ':clientID' => $e_clientID];
        $cnt = $this->DB->fetchValue($sql, $params);

        if ($cnt) {
            if (!$toPDF) {
                $orderLimit = "ORDER BY " . $allowOrderBy[$orderBy] . " $sortDir LIMIT $si, $pp";
            } else {
                $orderLimit = 'ORDER BY n.subject ASC';
            }

            $flds = "n.id AS dbid, "
                . "nc.name AS cat, "
                . "IF(n.ownerID = -1, 'n/a', u.lastName) AS owner, "
                . "LEFT(n.created,10) AS ndate, "
                . "n.subject AS subj, "
                . "n.note";

            $from = "FROM caseNote AS n "
                . "LEFT JOIN noteCategory AS nc ON nc.id = n.noteCatID "
                . "LEFT JOIN {$globalDB}.users AS u ON u.id = n.ownerID";

            $where = "WHERE n.caseID = :caseID AND n.qID='' AND n.clientID = :clientID";
            switch ($userClass) {
                case 'admin':
                    break;
                case 'client':
                    $where .= ' AND n.bInvestigator=0';
                    break;
                case 'vendor':
                    $where .= ' AND (n.bInvestigator = 1 OR n.bInvestigatorCanSee = 1)';
                    break;
                default:
                    $where .= ' AND 0';
            }

            $sql = "SELECT $flds $from $where $orderLimit";
            $params = [':caseID' => $e_caseID, ':clientID' => $e_clientID];
            $rows = $this->DB->fetchAssocRows($sql, $params);

            if (is_array($rows)) {
                $notesData = $rows;
            }
        }

        return $notesData;
    }

    /**
     * Get the case type list for the client.
     * It also adds at the end the DUE_DILIGENCE_INTERNAL
     *
     * @param integer $clientID -> the ID of the Client
     *
     * @return array $caseTypeClientList
     *
     * @see public_html/cms/includes/php/func_sesslists.php
     * @note This is a refactor of setCaseTypeClientList located in funcs_sesslists.php
     */
    private function getCaseTypeClientList($clientID)
    {
        $sql = "SELECT caseTypeID, name FROM caseTypeClient WHERE clientID = :clientID "
            . "AND investigationType = 'due_diligence' AND displayOption = 'all'";
        $params = [':clientID' => $clientID];
        if (!($caseTypeClientList = $this->DB->fetchKeyValueRows($sql, $params))) {
            $params = [':clientID' => 0];
            $caseTypeClientList = $this->DB->fetchKeyValueRows($sql, $params);
        }

        $caseTypeClientList[IntakeFormTypes::DUE_DILIGENCE_INTERNAL] = "Internal Review";

        return $caseTypeClientList;
    }

    /**
     * Generate a PDF of the case.
     *
     * @param object $authRow Contains all the auth information
     * @param object $caseRow Contains all the case information
     * @param array  $spDocs  An array of all the documents (attachments) that have been uploaded
     *
     * @return object $rtn Object containing all the information to generate a PDF of the case.
     *
     * @see This is a refactor of legacy splCaseDetails.sec
     */
    public function getCasePdfInfo($authRow, $caseRow, $spDocs)
    {
        $this->ddq = new DdqSupport($this->DB, $this->authDB);
        $caseID = $authRow->caseID;
        $clientID = $authRow->clientID;
        $globalDB = $this->authDB;

        $userType = UserType::VENDOR_ADMIN;
        $userClass = 'vendor';
        $tenantFeatures = (new TenantFeatures($clientID))->tenantHasFeatures(
            [\Feature::TENANT_TPM, \Feature::TENANT_TPM_RISK, \Feature::TENANT_APPROVE_DDQ],
            \Feature::APP_TPM
        );
        $tpFtrOn = $tenantFeatures[\Feature::TENANT_TPM];
        $riskFtrOn = $tenantFeatures[\Feature::TENANT_TPM_RISK];
        $intkApprvlFtrOn = $tenantFeatures[\Feature::TENANT_APPROVE_DDQ];
        $sql = "SELECT logoFileName FROM clientProfile WHERE id = :clientID LIMIT 1";
        $logoFileName = $this->DB->fetchValue($sql, [':clientID' => $clientID]);
        $caseTypeClientList = $this->getCaseTypeClientList($clientID);
        $tpID = $caseRow->tpID;

        $recScopeName = '';
        $scopeName = $caseTypeClientList[$caseRow->caseType];
        $riskRating = false;
        if ($tpFtrOn && $tpID) {
            $riskRating = $this->getRiskRating($tpID, $clientID, $riskFtrOn);
            // is caseType a variance from what was recommended when caseType was selected for this case?
            if ($caseRow->rmID > 0 && $caseRow->raTstamp) {
                $rmID = $caseRow->rmID;
                $raTstamp = $caseRow->raTstamp;
                $sql = "SELECT rmt.scope "
                    . "FROM riskAssessment AS ra "
                    . "LEFT JOIN riskModelTier AS rmt ON rmt.tier = ra.tier AND rmt.model = :modelID "
                    . "WHERE ra.tpID = :tpID "
                    . "AND ra.tstamp = :tStamp "
                    . "AND ra.model = :modelID2 "
                    . "AND ra.clientID = :clientID "
                    . "LIMIT 1";
                $params = [
                    ':modelID'  => $rmID,
                    ':tpID'     => $tpID,
                    ':tStamp'   => $raTstamp,
                    ':modelID2' => $rmID,
                    ':clientID' => $clientID
                ];
                if (($recScope = $this->DB->fetchValue($sql, $params)) && ($recScope != $caseRow->caseType)) {
                    $recScopeName = $caseTypeClientList[$recScope] ?? '';
                }
            }
        }

        // Load DDQ record
        $sql = "SELECT * FROM ddq WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
        $params = [':caseID' => $caseID, ':clientID' => $clientID];
        $ddqRow = $this->DB->fetchObjectRow($sql, $params);
        $ddqRowVals = $this->DB->fetchIndexedRow($sql, $params);

        $langCode = ($ddqRow && $ddqRow->subInLang) ? $ddqRow->subInLang : 'EN_US';
        $e_langCode = $this->DB->esc($langCode);

        $sql = "SELECT langNameEng FROM $globalDB.languages WHERE langCode = :langCode";
        $params = [':langCode' => $e_langCode];
        $langName = $this->DB->fetchValue($sql, $params);

        $allowCF = [ClientIds::BAXTER_CLIENTID]; // allow to see custom fields
        $allowAL = [ClientIds::BAXTER_CLIENTID]; // allow to see audit log
        $allowCN = [ClientIds::BAXTER_CLIENTID]; // allow to see case notes

        $sql = "SELECT COUNT(*) FROM customData WHERE clientID = :clientID AND caseID = :caseID";
        $params = [':clientID' => $clientID, ':caseID' => $caseID];
        $showCustomData = (($userClass != 'vendor' || in_array($clientID, $allowCF))
            && $this->DB->fetchValue($sql, $params) > 0
        );

        $sql = "SELECT COUNT(*) FROM userLog WHERE clientID = :clientID AND caseID = :caseID";
        $params = [':clientID' => $clientID, ':caseID' => $caseID];
        $showAuditLog = (($userClass != 'vendor' || in_array($clientID, $allowAL))
            && $this->DB->fetchValue($sql, $params) > 0
        );

        $sql = "SELECT COUNT(*) FROM caseNote WHERE clientID = :clientID AND caseID = :caseID";
        $params = [':clientID' => $clientID, ':caseID' => $caseID];
        $showNotes = (($userClass != 'vendor' || in_array($clientID, $allowCN))
            && $this->DB->fetchValue($sql, $params) > 0
        );

        // load Case Stages lookup
        $caseStageList = $this->DB->fetchKeyValueRows("SELECT id, name FROM caseStage ORDER BY id ASC");

        //Get the client name
        $sql = "SELECT clientName, regionTitle, departmentTitle FROM clientProfile WHERE id = :clientID";
        $params = [':clientID' => $clientID];
        [$clientName, $regionTitle, $departmentTitle]  = $this->DB->fetchIndexedRow($sql, $params);

        $sql = "SELECT name FROM region WHERE id = :region LIMIT 1";
        $params = [':region' => $caseRow->region];
        if (!($regionName = $this->DB->fetchValue($sql, $params))) {
            $regionName = 'None';
        }

        $sql = "SELECT name FROM department WHERE id = :dept LIMIT 1";
        $params = [':dept' => $caseRow->dept];
        if (!($deptName = $this->DB->fetchValue($sql, $params))) {
            $deptName = 'None';
        }

        // Get Investigator Company Information
        $sql = "SELECT userName, userEmail, userPhone FROM $globalDB.users WHERE id = :userID LIMIT 1";
        $params = [':userID' => $caseRow->caseInvestigatorUserID];
        [$investigatorName, $investigatorEmail, $ivestigatorPhone] = $this->DB->fetchIndexedRow($sql, $params);

        // Get investigator Complete Info
        $sql = "SELECT * FROM iCompleteInfo WHERE caseID = :caseID LIMIT 1";
        $params = [':caseID' => $caseID];
        $iCompleteInfoRow = $this->DB->fetchObjectRow($sql, $params);

        $sql = "SELECT * FROM subjectInfoDD WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
        $params = [':caseID' => $caseID, ':clientID' => $clientID];
        $subjectInfoRow = $this->DB->fetchObjectRow($sql, $params);


        // Get the Subject Info Row
        $subStat = $subjectInfoRow->subStat;
        $sql = "SELECT name FROM relationshipType WHERE id = :subType";
        $params = [':subType' => $subjectInfoRow->subType];
        $subType = $this->DB->fetchValue($sql, $params);

        $sql = "SELECT name FROM companyLegalForm WHERE id = :legalForm";
        $params = [':legalForm' => $subjectInfoRow->legalForm];
        $legalForm = $this->DB->fetchValue($sql, $params);

        $principals = [];
        $prinRelationships = [];
        $prinOwner  = [];
        $prinOwnPercent = [];
        $prinKeyMgr = [];
        $prinBoardMem = [];
        $prinKeyConsult = [];
        $prinUnknown = [];
        for ($i = 1; $i <= Misc::SUBINFO_PRINCIPAL_LIMIT; $i++) {
            $prinvar = 'principal' . $i;
            $relvar  = 'pRelationship' . $i;
            $ownVar  = 'bp' . $i . 'Owner';
            $keyMgrVar  = 'bp' . $i . 'KeyMgr';
            $ownPercentVar = 'p' . $i . 'OwnPercent';
            $boardMemVar = 'bp' . $i . 'BoardMem';
            $keyConsultVar = 'bp' . $i . 'KeyConsult';
            $keyUnknownVar = 'bp' . $i . 'Unknown';
            if ($subjectInfoRow->$prinvar != '') {
                $principals[] = $subjectInfoRow->$prinvar;
                $prinRelationships[] = $subjectInfoRow->$relvar;
                $prinOwner[] = $subjectInfoRow->$ownVar;
                $prinOwnPercent[] = $subjectInfoRow->$ownPercentVar;
                $prinKeyMgr[] = $subjectInfoRow->$keyMgrVar;
                $prinBoardMem[] = $subjectInfoRow->$keyMgrVar;
                $prinKeyConsult[] = $subjectInfoRow->$keyConsultVar;
                $prinUnknown[] = $subjectInfoRow->$keyUnknownVar;
            }
        }

        // Get Investigator User Information
        $sql = "SELECT userName FROM $globalDB.users WHERE id = :investigatorID";
        $params = [':investigatorID' => $caseRow->acceptingInvestigatorID];
        $acceptingInvestigatorName = $this->DB->fetchValue($sql, $params);

        // Get Requestor User Name
        $sql = "SELECT userName FROM $globalDB.users WHERE userid = :requestor LIMIT 1";
        $params = [':requestor' => $caseRow->requestor];
        if (!($requestorName = $this->DB->fetchValue($sql, $params))) {
            $requestorName = $caseRow->requestor; // fall back to login id
        }

        if (!($stateName = $this->geo->getLegacyStateName($subjectInfoRow->state, $subjectInfoRow->country))) {
            $stateName = 'None';
        }

        $sql = "SELECT legacyName FROM {$this->DB->isoDB}.legacyCountries "
            . "WHERE legacyCountryCode = :isoCode LIMIT 1";
        $params = [':isoCode' => $subjectInfoRow->country];
        if (!($countryName = $this->DB->fetchValue($sql, $params))) {
            $countryName = $subjectInfoRow->country;
        }

        $pageTitle = $caseRow->userCaseNum;

        date_default_timezone_set('UTC');
        $caseDate = substr((string) $caseRow->caseAssignedDate, 0, 10);
        $tm = time();
        $pdfMakeTime = date("h:i:s", $tm);
        $pdfMakeDate = date("D M d Y", $tm);

        $sql = "SELECT clientName FROM clientProfile WHERE id = :clientID";
        $params = [':clientID' => $clientID];
        $companyName = $this->DB->fetchValue($sql, $params);

        $msg = "This document is confidential material of $companyName "
            . "and may not be copied or shared without permission.";
        $footer = ['top' => $msg, 'left' => "Case Assign Date $caseDate", 'middle' => "PDF created on $pdfMakeDate at $pdfMakeTime"];

        $showPersonnel = $showBizPrac = $showRelationships = false;

        if ($ddqRow) {
            if ($ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM2
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM3
                && $ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_4PAGE
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL
            ) {
                $showPersonnel = true;
            }

            $showBizPrac = true;

            if ($ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM2
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM3
                && $ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_4PAGE
                && $ddqRow->caseType != IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL
            ) {
                $showRelationships = true;
            }
        }

        $pdfData = [
            'success'       => true,
            'sitePath'      => $this->sitePath,
            'langName'      => $langName,
            'pdfTitle'      => $pdfTitle = 'Case.Folder.' . str_replace(" ", "_", (string) $caseRow->userCaseNum) . '.pdf',
            'pageTitle'     => $pageTitle,
            'header'        => '',
            'footer'        => $footer,
            'logo'          => $logoFileName,
            'regionTitle'   => $regionTitle,
            'regionName'    => $regionName,
            'deptTitle'     => $departmentTitle,
            'deptName'      => $deptName,
            'scopeName'     => $scopeName,
            'recScopeName'  => $recScopeName,
            'stage'         => $caseStageList[$caseRow->caseStage],
            'requestorName' => $requestorName,
            'requester'     => $requestorName,
            'riskRating'    => $riskRating,
            'ddqRow'        => $ddqRow,
            'subject'       => ['stat' => $subStat, 'type' => $subType, 'legalForm' => $legalForm,
                                'state' => $stateName, 'country' => $countryName,
                                'allInfo' => $subjectInfoRow],
            'investigator'  => ['name' => $investigatorName, 'email' => $investigatorEmail,
                                'phone' => $ivestigatorPhone, 'userNname' => $acceptingInvestigatorName,
                                'allInfo' => $iCompleteInfoRow],
            'client'        => ['name' => $clientName, 'region' => $regionTitle, 'dept' => $departmentTitle],
            'clientProfile' => ['tpSettingOn' => $tpFtrOn, 'riskSettingOn' => $riskFtrOn,
                                'intkApprvlSettingOn' => $intkApprvlFtrOn],
            'case'          => ['userNum' => $caseRow->userCaseNum, 'name' => $caseRow->caseName,
                                'description' => $caseRow->caseDescription, 'dueDate' => $caseRow->caseDueDate,
                                'approveDDQ' => $caseRow->approveDDQ]
        ];

        $pdfData['company'] = $this->getCompanyInfo(
            $langName,
            $langCode,
            $caseRow,
            $authRow,
            $ddqRow,
            $ddqRowVals,
            $clientID,
            $caseTypeClientList,
            $intkApprvlFtrOn
        );

        if ($showPersonnel) {
            $pdfData['personnel'] = $this->getPersonalInfo(
                $ddqRow,
                $ddqRowVals,
                $clientID,
                $clientName,
                $langCode,
                $caseTypeClientList,
                $subjectInfoRow,
                $principals,
                $prinRelationships,
                $prinOwner,
                $prinOwnPercent,
                $prinKeyMgr,
                $prinBoardMem,
                $prinKeyConsult,
                $prinUnknown
            );
        }

        if ($showBizPrac) {
            $pdfData['bizPrac'] = $this->getBizPracInfo($ddqRow, $ddqRowVals, $clientID, $langCode);
        }

        if ($showRelationships) {
            $pdfData['relationships'] = $this->getRelationshipsInfo(
                $ddqRow,
                $ddqRowVals,
                $clientID,
                $clientName,
                $langCode
            );
        }

        $pdfData['addlInfo'] = $this->getAddlInfo($ddqRow, $ddqRowVals, $clientID, $subjectInfoRow, $langCode);
        $pdfData['attachments'] = $this->getAttachmentsInfo($ddqRow, $clientID, $caseID, $spDocs);

        if ($showCustomData) {
            $pdfData['customData'] = $this->getCustomDataInfo($clientID, $caseID, true, false);
        }

        if ($showNotes) {
            $pdfData['caseNotes'] = $this->getCaseNotesInfo($clientID, $caseID, $userClass, $globalDB);
        }

        if ($showAuditLog) {
            $pdfData['caseLog'] = $this->getCaseLogInfo($clientID, $caseID, $userType);
        }

        return (object)$pdfData;
    }

    /**
     * Generate the 'Company' page of the PDF.
     *
     * @param string  $langName           Language string name in use
     * @param string  $langCode           Language string name in abbreviated format
     * @param object  $caseRow            Contains all the case information
     * @param object  $authRow            Contains all the case information
     * @param object  $ddqRow             Contains all DDQ info for the case
     * @param array   $ddqRowVals         Contains all DDQ info for the case
     * @param integer $clientID           Contains all the case information
     * @param array   $caseTypeClientList Contains all the case information
     * @param boolean $intkApprvlFtrOn    \Feature::TENANT_APPROVE_DDQ for tenant
     *
     * @return array  $data Contains Company data needed for PDF.
     *
     * @see This is a refactor of legacy company.sec
     */
    private function getCompanyInfo(
        $langName,
        $langCode,
        $caseRow,
        $authRow,
        $ddqRow,
        $ddqRowVals,
        $clientID,
        $caseTypeClientList,
        $intkApprvlFtrOn
    ) {
        $ddqConstants = [
            IntakeFormTypes::DUE_DILIGENCE_SBI,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2,
            IntakeFormTypes::DUE_DILIGENCE_SHORTFORM_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM2_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3,
            IntakeFormTypes::DDQ_SHORTFORM_FORM3_RENEWAL,
            IntakeFormTypes::DDQ_SBI_FORM2,
            IntakeFormTypes::DDQ_SBI_FORM3,
            IntakeFormTypes::DDQ_SBI_FORM4,
            IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL,
            IntakeFormTypes::DDQ_SBI_FORM2_RENEWAL,
            IntakeFormTypes::DDQ_SBI_FORM3_RENEWAL,
            IntakeFormTypes::DUE_DILIGENCE_SBI_RENEWAL_2,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL,
            // Constants below taken from Legacy cms/case/caseFolderPages/company.sec
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGEA,
            IntakeFormTypes::DDQ_SHORTFORM_5PAGENOBP,
            IntakeFormTypes::DDQ_5PAGENOBP_FORM2,
            IntakeFormTypes::DDQ_5PAGENOBP_FORM3,
            IntakeFormTypes::DDQ_4PAGE_FORM1,
            IntakeFormTypes::DDQ_4PAGE_FORM2,
            IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL1,
            IntakeFormTypes::DDQ_SHORTFORM_3PAGECDKPACL2,
            IntakeFormTypes::DDQ_SBI_FORM4_RENEWAL,
            IntakeFormTypes::DDQ_SBI_FORM5,
            IntakeFormTypes::DDQ_SBI_FORM5_RENEWAL,
            IntakeFormTypes::DDQ_SBI_FORM6,
            IntakeFormTypes::DDQ_SBI_FORM6_RENEWAL,
            IntakeFormTypes::DDQ_SBI_FORM7,
            IntakeFormTypes::DDQ_SBI_FORM7_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE,
            IntakeFormTypes::DDQ_SHORTFORM_4PAGE_RENEWAL,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1601,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1602,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1603,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1604,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1605,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1606,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1607,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1608,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1609,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1610,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1611,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1612,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1613,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1614,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1615,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1616,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1617,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1618,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1619,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1620,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1701,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1702,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1703,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1704,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1705,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1706,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1707,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1708,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1709,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1710,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1711,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1712,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1713,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1714,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1715,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1716,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1717,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1718,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1719,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1720,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1721,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1722,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1723,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1724,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1725,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1726,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1727,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1728,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1729,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1730,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1731,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1732,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1733,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1734,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1735,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1736,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1737,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1738,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1739,
            IntakeFormTypes::DDQ_SHORTFORM_2PAGE_1740,
        ];
        $globalDB = $this->authDB;
        $data = [];
        $iCompany = '';
        $iProfileRow = false;


        if ($spID = intval($caseRow->caseAssignedAgent)) {
            $iSql = "SELECT * FROM " . $this->spDb . ".investigatorProfile WHERE id = :spID LIMIT 1";
            $params = [':spID' => $spID];
            if ($iProfileRow = $this->DB->fetchObjectRow($iSql, $params)) {
                $iCompany = $iProfileRow->investigatorName;
                $isSpLite = ($iProfileRow->bFullSp == 0);
            }
        }

        $caseTypeClientName = '';
        if (array_key_exists($caseRow->caseType, $caseTypeClientList)) {
            $caseTypeClientName = $caseTypeClientList[$caseRow->caseType];
        }

        // Investigator Information
        $iName = $iEmail = $iPhone = '';
        $iName = '(not provided)';
        if ($iProfileRow) {
            $iEmail = $iProfileRow->investigatorEmail;
            $iPhone = $iProfileRow->investigatorPhone;
        }
        // Override values, if available
        if ($authRow->iCompany) {
            $iCompany = $authRow->iCompany;
        }
        if ($authRow->iName) {
            $iName = $authRow->iName;
        }
        if ($authRow->iEmail) {
            $iEmail = $authRow->iEmail;
        }
        if ($authRow->iPhone) {
            $iPhone = $authRow->iPhone;
        }

        // If the case was rejected, what was the rejection code
        $rejectCaseCode = '';
        $rejectedStages = [
            CaseStage::CASE_CANCELED,
            CaseStage::CLOSED,
            CaseStage::CLOSED_HELD,
            CaseStage::CLOSED_INTERNAL
        ];
        if (in_array($caseRow->caseStage, $rejectedStages)) {
            $sql = "SELECT name FROM rejectCaseCode WHERE id = :reason LIMIT 1";
            $params = [':reason' => $caseRow->rejectReason];
            $rejectCaseCode = $this->DB->fetchValue($sql, $params);
        }

        $budgetAmount = $caseRow->budgetAmount;
        $origin = ($ddqRow)
            ? "Due Diligence Questionnaire in " . $langName
            : 'Manual Creation';

        $apprDdqLabel = '';
        $apprDdqDate = '';
        if ($intkApprvlFtrOn && $caseRow->approveDDQ) {
            $apprDdqLabel = 'Approved DDQ:';
            $apprDdqDate = substr((string) $caseRow->approveDDQ, 0, 10);
        }

        $data['iCompany']           = $iCompany;
        $data['iName']              = $iName;
        $data['iEmail']             = $iEmail;
        $data['origin']             = $origin;
        $data['caseTypeClientName'] = $caseTypeClientName;
        $data['budgetAmount']       = $budgetAmount;
        $data['apprDdqLabel']       = $apprDdqLabel;
        $data['apprDdqDate']        = $apprDdqDate;
        $data['rejectCaseCode']     = $rejectCaseCode;

        if (!$ddqRow) {
            $data['countryOfRegistration'] = $this->geo->getLegacyCountryName($caseRow->caseCountry);
        } else {
            // Case originated by DDQ
            if (in_array($ddqRow->caseType, $ddqConstants)) {
                // We need to load the Company Details page questions from onlineQuestions
                $aOnlineQuestions = $this->ddq->getQuestionsForPage(
                    $clientID,
                    $langCode,
                    $ddqRow->caseType,
                    'Company Details',
                    $ddqRow->ddqQuestionVer
                );
            } else {
                // We need to load the  page questions from onlineQuestions
                $aOnlineQuestions = $this->ddq->getQuestionsForPage(
                    $clientID,
                    $langCode,
                    $ddqRow->caseType,
                    'Professional Information',
                    $ddqRow->ddqQuestionVer
                );
            }

            if (in_array($ddqRow->caseType, $ddqConstants)) {
                $data['pageBrk'] = false;
            } else {
                $data['pageBrk'] = true;
            }

            if (in_array($ddqRow->caseType, $ddqConstants)) {
                $aQuestionRow = $this->ddq->getQuestionFromPage($aOnlineQuestions, 'TEXT_COMPANYDETAILS_SECTIONHEAD');
            } else {
                $aQuestionRow = $this->ddq->getQuestionFromPage($aOnlineQuestions, 'TEXT_PROFINFO_SECTIONHEAD');
            }


            // Legacy uses a blank array when $aQuestionRow is not found
            // Delta will create an object with blank proerties used below
            if (!isset($aQuestionRow) || !is_object($aQuestionRow) || $aQuestionRow == false) {
                $aQuestionRow = new \stdClass();
            }
            if (!isset($aQuestionRow->labelText)) {
                $aQuestionRow->labelText = '';
            }


            $data['sectionLabel'] = $aQuestionRow->labelText;

            if (in_array($ddqRow->caseType, $ddqConstants)) {
                // Load COMPANY DETAILS Section
                foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
                    // Find all of the COMPANY DETAIL Section elements
                    if ($aOnlineQuestionsRow->sectionName == "COMPANY DETAILS") {
                        $data['coDetails'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                        $data['coDetails'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 340);
                    }
                }
            } else {
                // Load PROFESSIONAL INFORMATION Section
                foreach ($_SESSION['aOnlineQuestions'] as $i => $aOnlineQuestionsRow) {
                    // Find all PROFESSIONAL INFORMATION Section elements
                    if ($aOnlineQuestionsRow->sectionName == "PROFESSIONAL INFORMATION") {
                        $data['profInfo'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                        $data['profInfo'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 340);
                    }
                }
            }

            if (in_array($ddqRow->caseType, $ddqConstants)) {
                $aQuestionsRow = $this->ddq->getQuestionFromPage($aOnlineQuestions, 'TEXT_POC_SECTIONHEAD');
                if ($aQuestionsRow) {
                    $data['pocSection'] = $aQuestionsRow->labelText;
                } else {
                    $data['pocSection'] = 'MAIN POINT OF CONTACT';
                }

                // Load MAIN POINT OF CONTACT Section
                foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
                    // Find all of the MAIN POINT OF CONTACT Section elements
                    if ($aOnlineQuestionsRow->sectionName == "MAIN POINT OF CONTACT") {
                        $data['poc'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                        $data['poc'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 340);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Generate the 'Custom Data' page of the PDF.
     *
     * @param integer $clientID   Client ID
     * @param integer $caseID     Case ID
     * @param boolean $toPDF      Boolean indicator if being called in PDF context
     * @param boolean $inCaseHome Boolean indicate if being called in Case home context
     *
     * @return array $data Array of custom data elements
     *
     * @see This is a partial refactor of legacy customdata.sec
     */
    private function getCustomDataInfo($clientID, $caseID, $toPDF, $inCaseHome)
    {
        $showCsCd = ($clientID == ClientIds::BAXTER_CLIENTID);
        $showTpCd = false;
        $editTpCd = false;
        $editCsCd = false;

        $cscdInitCancelCls = '';
        $cscdInitDiv = '';
        $tpcdInitCancelCls = '';
        $tpcdInitDiv = '';

        // $caseID already set
        $tpID = 0;
        $cdCls = new CustomData($tpID, $caseID, $clientID, $toPDF, $inCaseHome);

        $cdFormAdjust['cscd'] = [];
        $cdFormAdjust['tpcd'] = [];
        $cdFormAdjust['d-cscd'] = [];
        $cdFormAdjust['d-tpcd'] = [];

        // get IDs and record counts
        $cdStats = $cdCls->stats();
        if (!$tpID && $cdStats->tpID) {
            $tpID = $cdStats->tpID; // update if available;
        }

        $userSecLevel = Security::READ_ONLY;

        if ($showTpCd && $inCaseHome && (!$tpID || ($userSecLevel == Security::READ_ONLY))
        ) {
            $showTpCd = false;
            $editTpCd = false;
        }
        if ($toPDF || $userSecLevel == Security::READ_ONLY) {
            $editCsCd = false;
        }

        if ($toPDF || $userSecLevel == Security::READ_ONLY) {
            $editTpCd = false;
        }
        // Don't allow edit if not showing
        if (!$showCsCd) {
            $editCsCd = false;
        }
        if (!$showTpCd) {
            $editTpCd = false;
        }

        $cscd_id_list = [];
        $tpcd_id_list = [];

        // Decide what to show in custom case section
        if ($showCsCd && $cdStats->csVisibleCnt) {
            // fetch the objects
            $cscdFields = $cdCls->getFields('case');
            $cscdData = $cdCls->getData('case');

            // Make list of IDs for javascript to check on form submit
            foreach ($cscdFields->fields as $fld) {
                $fld_id = 'cscd' . '-id' . $fld->id;
                $comment = trim((string) $fld->comment);
                if (trim($comment)) {
                    $styledMsg = $cdCls->formatMultilineText($comment);
                    $styledMsg = str_replace('"', '\"', str_replace("\n", "\\n", $styledMsg));
                    $helpData[] = "{id: \"cscd_{$fld->id}\", width: 400, "
                        . "title: '', msg: \"$styledMsg\"}";
                }
                if ($fld->type == 'check' || $fld->type == 'radio') {
                    if (is_array($cscdFields->lists[$fld->listName])) {
                        foreach ($cscdFields->lists[$fld->listName] as $lrec) {
                            $cscd_id_list[$fld_id . '-lid' . $lrec->id] = $fld->type;
                        }
                    }
                } else {
                    $cscd_id_list[$fld_id] = $fld->type;
                }
            }
            if (is_array($cscdData) && count($cscdData)) {
                $cscdInitCancelCls = 'v-visible';
                $cscdInitDiv = 'data';
            } else {
                if ($editCsCd) {
                    $cscdInitCancelCls = 'v-hidden';
                    $cscdInitDiv = 'form';
                } else {
                    $showCsCd = false;
                }
            }
        } else {
            $showCsCd = false;
            $editCsCd = false;
            $cscdData = false;
            $cscdFields = false;
        }

        // Decide what to show in custom third party section
        if ($showTpCd && $cdStats->tpVisibleCnt) {
            // fetch the objects
            $tpcdFields = $cdCls->getFields('thirdparty');
            $tpcdData = $cdCls->getData('thirdparty');
            // Make list of IDs for javascript to check on form submit
            foreach ($tpcdFields->fields as $fld) {
                $fld_id = 'tpcd' . '-id' . $fld->id;
                $comment = trim((string) $fld->comment);
                if (trim($comment)) {
                    $styledMsg = $cdCls->formatMultilineText($comment);
                    $styledMsg = str_replace("\n", "\\n", $styledMsg);
                    $helpData[] = "{id: 'tpcd_{$fld->id}', width: 400, title: '', msg: '$styledMsg'}";
                }
                if ($fld->type == 'check' || $fld->type == 'radio') {
                    if (is_array($tpcdFields->lists[$fld->listName])) {
                        foreach ($tpcdFields->lists[$fld->listName] as $lrec) {
                            $tpcd_id_list[$fld_id . '-lid' . $lrec->id] = $fld->type;
                        }
                    }
                } else {
                    $tpcd_id_list[$fld_id] = $fld->type;
                }
            }
            if (is_array($tpcdData) && count($tpcdData)) {
                $tpcdInitCancelCls = 'v-visible';
                $tpcdInitDiv = 'data';
            } else {
                if ($editTpCd) {
                    $tpcdInitCancelCls = 'v-hidden';
                    $tpcdInitDiv = 'form';
                } else {
                    $showTpCd = false;
                }
            }
        } else {
            $showTpCd = false;
            $editTpCd = false;
            $tpcdData = false;
            $tpcdFields = false;
        }

        $data = ['cdStats'      => $cdStats,
            'showCsCd'          => $showCsCd,
            'editCsCd'          => $editCsCd,
            'csVisibleCnt'      => $cdStats->csVisibleCnt,
            'csDataCnt'         => $cdStats->csDataCnt,
            'cscdFields'        => $cscdFields,
            'cscdData'          => $cscdData,
            'cscdInitCancelCls' => $cscdInitCancelCls,
            '$cscdInitDiv'      => $cscdInitDiv,
            'cscd_id_list'      => $cscd_id_list,
            'showTpCd'          => $showTpCd,
            'editTpCd'          => $editTpCd,
            'tpVisibleCnt'      => $cdStats->tpVisibleCnt,
            'tpDataCnt'         => $cdStats->tpDataCnt,
            'tpcdFields'        => $tpcdFields,
            'tpcdData'          => $tpcdData,
            'tpcdInitCancelCls' => $tpcdInitCancelCls,
            '$tpcdInitDiv'      => $tpcdInitDiv,
            'tpcd_id_list'      => $tpcd_id_list
        ];

        return $data;
    }

    /**
     * Generate the 'Personal' page of the PDF.
     *
     * @param object  $ddqRow             Contains all DDQ info for the case
     * @param array   $ddqRowVals         Contains all DDQ info for the case
     * @param integer $clientID           Client ID
     * @param string  $clientName         Client name
     * @param string  $langCode           Language code
     * @param array   $caseTypeClientList List of case types
     * @param object  $subjectInfoRow     Contains subject info (name, address, and other stats/data
     * @param array   $principals         List of principals by name (full name)
     * @param array   $prinRelationships  List of principals by title (chairman, VP...)
     * @param array   $prinOwner          List of principals with ownership
     * @param array   $prinOwnPercent     List of principals by ownership %
     * @param array   $prinKeyMgr         List of key managers
     * @param array   $prinBoardMem       List of board members
     * @param array   $prinKeyConsult     List of key consultants
     * @param array   $prinUnknown        List of principals with unkown type
     *
     * @return array $data Contains Personnel data needed for PDF.
     *
     * @see This is a refactor of legacy personal.sec
     */
    private function getPersonalInfo(
        $ddqRow,
        $ddqRowVals,
        $clientID,
        $clientName,
        $langCode,
        $caseTypeClientList,
        $subjectInfoRow,
        $principals,
        $prinRelationships,
        $prinOwner,
        $prinOwnPercent,
        $prinKeyMgr,
        $prinBoardMem,
        $prinKeyConsult,
        $prinUnknown
    ) {
        // Case type is unknown for SP Lite
        $prinCaseTypeName = 'n/a';

        // Check for the principal and then build a row to display the information
        $principalsList = [];
        for ($i = 0; $i < count($principals); $i++) {
            $roles = [];
            if ($prinOwner[$i]) {
                $percent = ($prinOwnPercent[$i]) ? ($prinOwnPercent[$i] . '%') : 'Unverified%';
                $roles[] = 'Owner with ' . $percent;
            }
            if ($prinKeyMgr[$i]) {
                $roles[] = 'Key Manager';
            }
            if ($prinBoardMem[$i]) {
                $roles[] = 'Board Member';
            }
            if ($prinKeyConsult[$i]) {
                $roles[] = 'Key Consultant';
            }
            if ($prinUnknown[$i]) {
                $roles[] = 'Unknown';
            }
            $roleList = join(', ', $roles);

            $principalsList[$i]['name']     = $principals[$i];         // Name
            $principalsList[$i]['relation'] = $prinRelationships[$i];  // Position
            $principalsList[$i]['type']     = $prinCaseTypeName;       // Type of Investigation
            $principalsList[$i]['role']     = $roleList;               // Role(s) in company
        }
        $data['principals'] = $principalsList;

        if ($ddqRow) {
            // Load page questions
            $aOnlineQuestions = [];
            if ($ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM) {
                $aOnlineQuestions = $this->ddq->getQuestionsForPage(
                    $clientID,
                    $langCode,
                    $ddqRow->caseType,
                    'Personnel',
                    $ddqRow->ddqQuestionVer
                );
            }

            // load key persons and count asterisks
            $data['keyPersons'] = false;
            $data['hasAsterisks'] = 0;
            $sql = "SELECT * FROM ddqKeyPerson WHERE ddqID = :ddqID AND clientID = :clientID ORDER BY id ASC";
            $params = [':ddqID' => $ddqRow->id, ':clientID' => $clientID];
            if ($keyPersons = $this->DB->fetchObjectRows($sql, $params)) {
                $data['keyPersons'] = true;
                foreach ($keyPersons as $kp) {
                    if (str_ends_with((string) $kp->kpName, '*')) {
                        $data['hasAsterisks']++;
                    }
                }

                if (in_array($clientID, ClientIds::HP_ALL)
                    && $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_SBI
                ) {
                    $cols[] = 'Name';
                    $cols[] = 'Position';
                    $cols[] = 'Address';
                    $cols[] = 'Phone';
                    $cols[] = 'Ownership';
                } else {
                    $cols[] = 'Name';
                    $cols[] = 'Position';
                    if ($clientID != ClientIds::CISCO_CLIENTID) {
                        $cols[] = ($clientID == ClientIds::GP_CLIENTID) ? "Email Address" : "Nationality";
                        if ($clientID != ClientIds::COKE_CLIENTID
                            && $clientID != ClientIds::GP_CLIENTID
                            && $clientID != ClientIds::DJO_CLIENTID
                            && $this->ddq->isClientDanaher($clientID)
                        ) {
                            $cols[] = 'ID Type/No.';
                        }
                    } else {
                        $cols[] = 'Name (local language)';
                    }
                    $cols[] = 'Ownership';
                    if ($clientID != ClientIds::CISCO_CLIENTID && $clientID != ClientIds::GP_CLIENTID) {
                        if ($clientID != ClientIds::DJO_CLIENTID && $clientID != ClientIds::BAXTER_CLIENTID) {
                            $cols[] = 'Embargoed Resident';
                        }
                        if ($clientID != ClientIds::DOW_CLIENTID
                            && $clientID != ClientIds::LIFETECH_CLIENTID
                        ) {
                            $cols[] = 'Proposed Role';
                        }
                    }
                }
                $data['owners']['keyPerson'] = $this->ddq->getKeyPerson('Owner', $keyPersons, $clientID, $ddqRow);
                $data['owners']['cols'] = $cols;
                $cols = [];

                if ($clientID != ClientIds::CISCO_CLIENTID
                    && $clientID != ClientIds::DOW_CLIENTID
                    && $clientID != ClientIds::LIFETECH_CLIENTID
                ) {
                    if (in_array($clientID, ClientIds::HP_ALL)
                        && $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_SBI
                    ) {
                        $cols[] = 'Name';
                        $cols[] = 'Position';
                        $cols[] = 'Address';
                        $cols[] = 'Phone';
                    } else {
                        $cols[] = 'Name';
                        $cols[] = 'Position';
                        $cols[] = 'Address';
                        $cols[] = ($clientID == ClientIds::GP_CLIENTID) ? "Email Address" : "Nationality";
                        if ($clientID != ClientIds::COKE_CLIENTID
                            && $clientID != ClientIds::GP_CLIENTID
                            && $clientID != ClientIds::DJO_CLIENTID
                            && !$this->ddq->isClientDanaher($clientID)
                        ) {
                            $cols[] = 'ID Type/No.';
                        }
                        if ($clientID != ClientIds::GP_CLIENTID) {
                            if ($clientID != ClientIds::DJO_CLIENTID
                                && $clientID != ClientIds::BAXTER_CLIENTID
                            ) {
                                $cols[] = 'Embargoed Resident';
                            }
                            $cols[] = 'Proposed Role';
                        }
                    }
                    $data['bod']['keyPerson'] = $this->ddq->getKeyPerson('BoardMem', $keyPersons, $clientID, $ddqRow);
                    $data['bod']['cols'] = $cols;
                    $cols = [];
                }

                $data['gpClientId'] = true;
                if ($clientID != ClientIds::GP_CLIENTID) {
                    $data['gpClientId'] = false;
                    $data['clientName'] = $clientName;
                    if (in_array($clientID, ClientIds::HP_ALL)
                        && $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_SBI
                    ) {
                        $cols[] = 'Name';
                        $cols[] = 'Position';
                        $cols[] = 'Address';
                        $cols[] = 'Phone';
                    } else {
                        $cols[] = 'Name';
                        $cols[] = 'Position';
                        if ($clientID != ClientIds::CISCO_CLIENTID) {
                            $cols[] = 'Nationality';
                            if ($clientID != ClientIds::COKE_CLIENTID && $clientID != ClientIds::DJO_CLIENTID
                                && !$this->ddq->isClientDanaher($clientID)
                            ) {
                                $cols[] = 'ID Type/No.';
                            }
                            if ($clientID != ClientIds::DJO_CLIENTID
                                && $clientID != ClientIds::BAXTER_CLIENTID
                            ) {
                                $cols[] = 'Embargoed Resident';
                            }
                            if ($clientID != ClientIds::DOW_CLIENTID
                                && $clientID != ClientIds::LIFETECH_CLIENTID
                            ) {
                                $cols[] = 'Proposed Role';
                            }
                        } else {
                            $cols[] = 'Name (local language)';
                        }
                    }
                    $data['mgr']['keyPerson'] = $this->ddq->getKeyPerson('KeyMgr', $keyPersons, $clientID, $ddqRow);
                    $data['mgr']['cols'] = $cols;
                    $cols = [];

                    if ($clientID != ClientIds::DJO_CLIENTID
                        && $clientID != ClientIds::BAXTER_CLIENTID
                        && $clientID != ClientIds::LIFETECH_CLIENTID
                    ) {
                        $data['consult']['title'] = ($clientID == ClientIds::SMITH_NEPHEW_CLIENTID)
                                    ? "SALES REPRESENTATIVE(S)"
                                    : "KEY CONSULTANT(S)";

                        if (in_array($clientID, ClientIds::HP_ALL)
                            && $ddqRow->caseType == IntakeFormTypes::DUE_DILIGENCE_SBI
                        ) {
                            $cols[] = 'Name';
                            $cols[] = 'Position';
                            $cols[] = 'Address';
                            $cols[] = 'Phone';
                        } else {
                            $cols[] = 'Name';
                            $cols[] = 'Position';
                            if ($clientID != ClientIds::CISCO_CLIENTID) {
                                $cols[] = 'Nationality';
                                if ($clientID != ClientIds::COKE_CLIENTID && !$this->ddq->isClientDanaher($clientID)) {
                                    $cols[] = 'ID Type/No.';
                                }
                                $cols[] = 'Embargoed Resident';
                                if ($clientID != ClientIds::DOW_CLIENTID) {
                                    $cols[] = 'Proposed Role';
                                }
                            } else {
                                $cols[] = 'Name (local language)';
                            }
                        }
                        $data['consult']['keyPerson'] = $this->ddq->getKeyPerson(
                            'KeyConsult',
                            $keyPersons,
                            $clientID,
                            $ddqRow
                        );
                        $data['consult']['cols'] = $cols;
                    }
                }
            } // has key persons

            if ($aOnlineQuestions && $ddqRow->caseType != IntakeFormTypes::DUE_DILIGENCE_SHORTFORM) {
                // This is a Generic Question Section, nothing displays unless questions
                // have been added for it in onlineQuestions

                // Load  KEY PERSON GENQUEST Section
                foreach ($aOnlineQuestions as $aOnlineQuestionsRow) {
                    // Find all KEY PERSON GENQUEST Section elements
                    if ($aOnlineQuestionsRow->sectionName == 'KEY PERSON GENQUEST') {
                        $temp = [];
                        $temp['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                        $temp['value'] = $this->ddq->getVal(
                            $ddqRowVals,
                            $aOnlineQuestionsRow,
                            '50%'
                        );
                        $data['genericQuestions'][] = $temp;
                    }
                }
            } // has online questions
        } // originated by DDQ

        return $data;
    }

    /**
     * Generate the 'Relationships' page of the PDF.
     *
     * @param object  $ddqRow     Contains all DDQ info for the case
     * @param array   $ddqRowVals Contains all DDQ info for the case
     * @param integer $clientID   Client ID
     * @param string  $clientName Client name
     * @param string  $langCode   Language code
     *
     * @return object $rtn Object containing status/error information.
     *
     * @see This is a refactor of legacy personal.sec
     */
    private function getRelationshipsInfo($ddqRow, $ddqRowVals, $clientID, $clientName, $langCode)
    {
        $globalDB = $this->authDB;
        $cols = [];
        if ($clientID == ClientIds::CISCO_CLIENTID) {
            $cols[] = 'URL';
        } else {
            $cols[] = 'Address';
            $cols[] = 'Country of Registration';
            $cols[] = 'Registration';
            $cols[] = 'Contact Name';
            $cols[] = 'Phone';
            $cols[] = 'Ownership';
        }
        $data['companyCols'] = $cols;
        $cols = [];
        if ($this->geo->isGeo2) {
            $countryField = 'IFNULL(c.displayAs, c.legacyName)';
            $countryOn = '(c.legacyCountryCode = ifrc.regCountry '
                . 'OR c.codeVariant = ifrc.regCountry OR c.codeVariant2 = ifrc.regCountry) '
                . 'AND (c.countryCodeID > 0 OR c.deferCodeTo IS NULL)';
        } else {
            $countryField = 'c.legacyName';
            $countryOn = 'c.legacyCountryCode = ifrc.regCountry';
        }
        $sql = "SELECT ifrc.*, $countryField AS countryName FROM inFormRspnsCompanies AS ifrc\n"
            . "LEFT JOIN " . $this->DB->isoDB . ".legacyCountries AS c ON $countryOn\n"
            . "WHERE tenantID = :tenantID AND inFormRspnsID = :inFormRspnsID";
        $params = [':tenantID' => $clientID, ':inFormRspnsID' => $ddqRow->id];
        if ($rows = $this->DB->fetchObjectRows($sql, $params)) {
            foreach ($rows as $row) {
                $cols[] = $row->name;    // name
                $cols[] = $row->relationship;   // relationship
                $cols[] = $row->address;        // address / url
                if ($clientID != ClientIds::CISCO_CLIENTID) {
                    $cols[] = $row->countryName;            // reg country
                    $cols[] = $row->regNum;                 // reg num
                    $cols[] = $row->contactName;            // contact
                    $cols[] = $row->phone;                  // phone
                    $cols[] = $row->percentOwnership . "%"; // ownership
                }
                $data['companyData'][] = $cols;
            }
        }

        // Load questions for this page
        $aOnlineQuestions = $this->ddq->getQuestionsForPage(
            $clientID,
            $langCode,
            $ddqRow->caseType,
            'Relationships',
            $ddqRow->ddqQuestionVer
        );

        if ($clientID != ClientIds::SMITH_NEPHEW_CLIENTID && ($clientID != ClientIds::CISCO_CLIENTID)) {
            // Load the RELATIONSHIPS WITH Section Heading from the DB
            // so we can fall back to default..it is a late addition and
            // may not have a custom addition
            $relTxt = 'Company Relationships with';
            $onlineQuestionRow = $this->ddq->getQuestionsById(
                $clientID,
                'TEXT_RELATIONWITH_SECTIONHEAD',
                $langCode,
                $ddqRow->caseType,
                $ddqRow->ddqQuestionVer
            );
            if ($onlineQuestionRow) {
                $relTxt = $onlineQuestionRow->labelText;
            }
            $data['sectionHead'] = $relTxt . ' ' . $clientName;
        }

        // Load RELATIONSHIPS WITH Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find RELATIONSHIPS WITH Section elements
            if ($aOnlineQuestionsRow->sectionName == "RELATIONSHIPS WITH") {
                $data['relWith'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                $data['relWith'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, "30%");
            }
        }

        // Load EXPLAIN RELATIONSHIPS Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find EXPLAIN RELATIONSHIPS Section elements
            if ($aOnlineQuestionsRow->sectionName == "EXPLAIN RELATIONSHIPS") {
                $data['relExplain'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                $data['relExplain'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 500);
            }
        }

        // Government Relationships Section Heading
        $aQuestionRow = $this->ddq->getQuestionFromPage($aOnlineQuestions, 'TEXT_GOVRELATION_SECTIONHEAD');


            // Legacy uses a blank array when $aQuestionRow is not found
            // Delta will create an object with blank proerties used below
        if (!isset($aQuestionRow) || !is_object($aQuestionRow) || $aQuestionRow == false) {
            $aQuestionRow = new \stdClass();
        }
        if (!isset($aQuestionRow->labelText)) {
            $aQuestionRow->labelText = '';
        }


        $data['govtHdg'] = $aQuestionRow->labelText;

        // Load GOVERNMENT RELATIONSHIPS Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find GOVERNMENT RELATIONSHIPS Section elements
            if ($aOnlineQuestionsRow->sectionName == "GOVERNMENT RELATIONSHIPS") {
                if ($aOnlineQuestionsRow->controlType == 'tarbYes') {
                    $data['govtRel'][$i]['controlType'] = true;
                    $data['govtRel'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                    $data['govtRel'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 500);
                } else {
                    $data['govtRel'][$i]['controlType'] = false;
                    $data['govtRel'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                    $data['govtRel'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, "30%");
                }
            }
        }

        // Load EXPLAIN GOVERNMENT RELATIONSHIPS Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find EXPLAIN GOVERNMENT RELATIONSHIPS Section elements
            if ($aOnlineQuestionsRow->sectionName == "EXPLAIN GOVERNMENT RELATIONSHIPS") {
                $data['govtExplain'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                $data['govtExplain'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 500);
            }
        }

        // Load REFERENCES DESC Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find REFERENCES DESC Section elements
            if ($aOnlineQuestionsRow->sectionName == "REFERENCES_DESC") {
                $data['referenceDesc'] = $aOnlineQuestionsRow->labelText;
            }
        }

        // Load up the REFERENCES Section
        foreach ($aOnlineQuestions as $i => $aOnlineQuestionsRow) {
            // Find REFERENCES Section elements
            if ($aOnlineQuestionsRow->sectionName == "REFERENCES") {
                $data['reference'][$i]['label'] = $this->ddq->getLabel($aOnlineQuestionsRow);
                $data['reference'][$i]['value'] = $this->ddq->getVal($ddqRowVals, $aOnlineQuestionsRow, 435);
            }
        }

        return $data;
    }

    /**
     * Get 3P Profile Risk Rating for Investigator
     *
     * @param integer $tpID      thirdPartyProfile.id
     * @param integer $clientID  clientProfile.id
     * @param integer $riskFtrOn \Feature::TENANT_TPM_RISK value for tenant
     *
     * @return mixed Profile object on success or false
     * @author grh
     */
    private function getRiskRating($tpID, $clientID, $riskFtrOn)
    {
        $riskRating = false;
        $tpID = intval($tpID);
        $clientID = intval($clientID);
        $clientDB = $this->DB->getClientDB($clientID);
        $globalDB = $this->authDB;
        if ($this->geo->isGeo2) {
            $countryField = 'IFNULL(c.displayAs, c.legacyName)';
            $regCountryField = 'IFNULL(creg.displayAs, creg.legacyName)';
            $stateField = 'IFNULL(s.displayAs, s.legacyName)';
            $countryOn = '(c.legacyCountryCode = tp.country '
                . 'OR c.codeVariant = tp.country OR c.codeVariant2 = tp.country) '
                . 'AND (c.countryCodeID > 0 OR c.deferCodeTo IS NULL)';
            $regCountryOn = '(creg.legacyCountryCode = tp.regCountry '
                . 'OR creg.codeVariant = tp.regCountry OR creg.codeVariant2 = tp.regCountry) '
                . 'AND (creg.countryCodeID > 0 OR creg.deferCodeTo IS NULL)';
            $stateOn = 's.legacyCountryCode = c.legacyCountryCode '
                . 'AND (s.legacyStateCode = tp.state OR s.codeVariant = tp.state)';
        } else {
            $countryField = 'c.legacyName';
            $regCountryField = 'creg.legacyName';
            $stateField = 's.legacyName';
            $countryOn = 'c.legacyCountryCode = tp.country';
            $regCountryOn = 'creg.legacyCountryCode = tp.regCountry';
            $stateOn = '(s.legacyStateCode = tp.state AND s.legacyCountryCode = tp.country)';
        }
        $sql = "SELECT tp.*, reg.name AS regionName, ptype.name AS typeName, "
            . "pcat.name AS categoryName, $countryField AS countryName, $regCountryField AS regCountryName, "
            . "$stateField AS stateName, lf.name AS legalFormName, own.userName AS owner, "
            . "orig.userName AS originator, DATE_FORMAT(tp.tpCreated,'%Y-%m-%d') as createDate, "
            . "dept.name AS deptName ";
        if ($riskFtrOn) {
            $sql .= ", ra.normalized AS riskrate, "
                . "ra.tstamp AS risktime, rmt.scope AS invScope, "
                . "rt.tierName AS risk, tp.riskModel ";
        } else {
            // property is expected, but is missing without Risk enabled
            $sql .= ", '0' AS invScope ";
        }
        $sql .= "FROM {$clientDB}.thirdPartyProfile AS tp "
            . "LEFT JOIN {$clientDB}.region AS reg ON reg.id = tp.region "
            . "LEFT JOIN {$clientDB}.department AS dept ON dept.id = tp.department "
            . "LEFT JOIN {$clientDB}.tpType AS ptype ON ptype.id = tp.tpType "
            . "LEFT JOIN {$clientDB}.tpTypeCategory AS pcat ON pcat.id = tp.tpTypeCategory "
            . "LEFT JOIN {$clientDB}.companyLegalForm AS lf ON lf.id = tp.legalForm "
            . "LEFT JOIN " . $this->DB->isoDB . ".legacyCountries AS c ON $countryField "
            . "LEFT JOIN " . $this->DB->isoDB . ".legacyCountries AS creg ON $regCountryOn "
            . "LEFT JOIN " . $this->DB->isoDB . ".legacyStates AS s ON $stateOn "
            . "LEFT JOIN {$globalDB}.users AS own ON own.id = tp.ownerID "
            . "LEFT JOIN {$globalDB}.users AS orig ON orig.id = tp.createdBy ";
        if ($riskFtrOn) {
            $sql .= "LEFT JOIN {$clientDB}.riskAssessment AS ra ON (ra.tpID = tp.id "
                . "AND ra.model = tp.riskModel AND ra.status = 'current') "
                . "LEFT JOIN {$clientDB}.riskTier AS rt ON rt.id = ra.tier "
                . "LEFT JOIN {$clientDB}.riskModelTier AS rmt ON rmt.tier = ra.tier AND rmt.model = tp.riskModel ";
        }
        $sql .= "WHERE tp.id = :tpID AND tp.clientID = :clientID AND tp.status <> 'deleted' LIMIT 1";
        $params = [':tpID' => $tpID, ':clientID' => $clientID];
        if ($riskFtrOn && $row = $this->DB->fetchObjectRow($sql, $params)) {
            $riskRating = $row->risk;
        }
        return $riskRating;
    }


    /**
     * Returns an array row from g_userLogEventContexts depending on the $contextKey
     *
     * @param string $contextKey caseFolder, profileDetail, fullLog
     *
     * @return int $contextID 0 if no results, otherwise a valid id
     */
    public function getContextID($contextKey)
    {
        $contextID = 0;
        if (!empty($contextKey)) {
            $e_contextKey = $this->DB->esc($contextKey);
            $sql = "SELECT id FROM " . $this->DB->globalDB . ".g_userLogEventContexts "
                . "WHERE context = :contextKey";
            $params = [':contextKey' => $e_contextKey];
            $contextID = intval($this->DB->fetchValue($sql, $params));
        }
        return $contextID;
    }

    /**
     * Returns an array containing user role id and name depending on the $userType value
     *
     * @param int $userType 0, 10, 30, 60, 70, 80, 100
     *
     * @return mixed $userRole either array if results, or empty string if no results.
     */
    public function getUserRole($userType = 0)
    {
        $globalDb = $this->DB->globalDB;
        $userRole = '';
        $e_userType = intval($userType);
        $sql = "SELECT DISTINCT utr.userRoleID AS id, ur.role AS name "
            . "FROM {$globalDb}.g_userTypeToRole AS utr\n"
            . "LEFT JOIN {$globalDb}.g_userRoles AS ur ON ur.id = utr.userRoleID\n"
            . "WHERE utr.legacyUserType = :userType";
        $params = [':userType' => $e_userType];
        if ($results = $this->DB->fetchAssocRow($sql, $params)) {
            $userRole = ['id' => intval($results['id']), 'name' => $results['name']];
        }
        return $userRole;
    }

    /**
     * Converts shorthand alias into a fuller name for building an ORDER BY clause for retrieval SQL.
     *
     * @param string $sortAlias Either dt, un, or ev
     *
     * @return string $sortCol Either 'userName', 'event', or 'timestamp'
     */
    private function getSortCol($sortAlias = 'dt')
    {
        $sortCol = match ($sortAlias) {
            'un' => 'userName',
            'ev' => 'event',
            default => 'timestamp',
        };
        return $sortCol;
    }
}
