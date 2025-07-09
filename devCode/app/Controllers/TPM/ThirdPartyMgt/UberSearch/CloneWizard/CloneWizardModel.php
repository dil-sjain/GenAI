<?php
/**
 * Model to copy 3P parties, case folders and related information
 *
 * @keywords clone wizard, 3p, clone, third party management, multi tenant
 */

namespace Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard;

use Controllers\TPM\CaseMgt\CaseFolder\CasePdf as CasePDF;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\ClientFileManagement;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\TrainingTrait;
use Lib\Database\ChunkResults;
use Lib\Legacy\Search\Search3pData;
use Lib\Legacy\UserType;
use Lib\Support\Test\FileTestDir;
use Models\Globals\Region;
use Models\Globals\Department;
use Models\LogData;
use Models\TPM\AddProfile\AddProfile as AddProfile;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ClientProfile;
use Models\ThirdPartyManagement\GdcMonitor;
use Models\ThirdPartyManagement\ThirdParty;
use Models\TPM\InsertUniqueUserNumRecord;
use SimpleSAML\Error\Exception;

/**
 * Model to copy 3P parties, case folders and related information
 *
 */
#[\AllowDynamicProperties]
class CloneWizardModel
{
    use TrainingTrait; // Used for gathering information on trainings as well as cloning trainings and attachments

    /**
     * @var array used to contain case details, profile details, etc. for cloning
     */
    public $tables;

    /**
     * CloneWizardModel constructor.
     *
     * @param int    $tenantID      TenantID of logged in user
     * @param int    $cloneTenantID TenantID of clone record
     * @param string $cloneType     'Case' || 'TpProfile'
     * @param int    $uniqueID      Unique Identifier to clone record is userCaseNum or userTpNum
     * @param object $ftr           FeatureACL instance
     */
    public function __construct($tenantID, $cloneTenantID, $cloneType, $uniqueID, \Lib\FeatureACL $ftr = null)
    {
        $this->app           = \Xtra::app();
        $this->ftr           = $ftr ?? \Xtra::app()->ftr;
        $this->userID        = $this->ftr->user;
        $this->tenantID      = (int)$tenantID;
        $this->cloneTenantID = (int)$cloneTenantID;
        $this->tenantDB      = $this->app->DB->getClientDB($this->tenantID);
        $this->cloneTenantDB = $this->app->DB->getClientDB($this->cloneTenantID);
        $this->FileManager   = new ClientFileManagement();
        $this->spTbl         = $this->app->DB->spGlobalDB . '.investigatorProfile';
        $this->spDb          = $this->app->DB->spGlobalDB;

        $this->cloneType     = $cloneType;
        $this->tables       = [
            'original' => [],
            'clone'    => [],
        ];

        $this->_uniqueID = $uniqueID;
        if ($this->cloneType == 'Case') {
            $this->record  = $this->getCase();
            $this->tables['clone']['case']              = $this->record;
        } else {
            $this->record  = $this->getThirdPartyProfile($uniqueID);
            $this->tables['clone']['thirdPartyProfile'] = $this->record;
        }
    }


    /**
     * Clones a case from one tenantDB to another using $this->input and existing case data
     *
     * @param array $cloneCase    original case data row
     * @param array $caseData     $this->input->cases['id'] user input
     * @param mixed $associateTPP (boolean) if an integer is given (Tpm.Case) it is the case.tpID
     *
     * @return mixed cases.id or ['error' => 'information']
     *
     * @throws \Exception
     */
    public function cloneCase($cloneCase, $caseData, mixed $associateTPP = false)
    {
        $cloneCase = (array_key_exists('userCaseNum', $cloneCase)) ? $cloneCase : $cloneCase[0];

        $sql  = "INSERT INTO {$this->tenantDB}.cases SET\n";
        $sql .= "clientID                       = :clientID\n,";
        $sql .= "creatorUID                     = :creatorUID\n,";
        $sql .= "region                         = :region\n,";
        $sql .= "dept                           = :dept\n,";

        $userID = $this->getUserID();

        $bind = [
            ':clientID'                     =>  $this->tenantID,
            ':creatorUID'                   =>  $userID,
            ':region'                       =>  $caseData['csRg'],
            ':dept'                         =>  $caseData['csDep'],
        ];

        // For the sake of space this array of cases.columns is being iterated below:
        $copyColumns = [
                'caseName',                 'caseDescription',              'caseType',
                'casePriority',             'caseDueDate',                  'caseStage',
                'caseCreated',              'caseAssignedDate',             'caseCompletedByInvestigator',
                'caseAssignedAgent',        'caseAcceptedByInvestigator',   'caseInvestigatorUserID',
                'caseAcceptedByRequestor',  'caseClosed',                   'budgetType',
                'budgetAmount',             'budgetDescription',            'acceptingInvestigatorID',
                'rejectReason',             'rejectDescription',            'numOfBusDays',
                'invoiceNum',               'reassignDate',                 'passORfail',
                'passFailReason',           'raTstamp',                     'approveDDQ',
                'internalDueDate',          'modified',                     'prevCaseStage',
        ];

        // Bind columns & populate array for validation
        foreach ($copyColumns as $column) {
            $sql                .= "{$column} = :{$column}\n,";
            // If column is invoiceNum prepend "Cloned" for accounting purposes
            $bind[':' . $column]  = ($column != 'invoiceNum') ? $cloneCase[$column] : "Cloned" . $cloneCase[$column];
        }

        // Validate clone case data
        if (!$this->validateCase($bind)) {
            throw new \Exception('Invalid case details provided');
            return ['error' => 'Unable to validate Case details.'];
        }

        // Tpm.Case clone
        if ($associateTPP !== false) {
            $sql           .= "tpID = :tpID,\n";
            $bind[':tpID']  = $associateTPP;
        }
        $tempCaseData = [];
        foreach ($bind as $key => $value) {
            $key = str_replace(':', '', $key);
            $tempCaseData[$key] = $value;
        }
        $bind = $tempCaseData;
        $insUniq = new InsertUniqueUserNumRecord($this->tenantID);
        $insRes = $insUniq->insertUniqueCase($bind);

        if (empty($insRes)) {
            return ['error' => 'Unable to Clone cases.'];
        }
        $newCaseID = $insRes['caseID'];

        // Associate Element
        if ($associateTPP !== false) {
            $ThirdParty = new ThirdParty($this->tenantID);
            $ThirdParty->associateElement('cases', $newCaseID, $associateTPP);
        }

        // Clone Case Attachments i.e. iAttachments, ddqAttach, caseNote, subInfoAttach
        $cloneSubjectInfoDD = (array)$this->getSubjectInfoDD($cloneCase['id']);
        $this->cloneSubInfoAttach($this->getSubInfoAttach($cloneCase['id']), $newCaseID);
        $this->cloneIAttachments($this->getIAttachments($cloneCase['id']), $newCaseID);
        $this->getDDQAttachChunk($cloneCase['id'], $newCaseID);
        $this->cloneCaseNote($this->getCaseNote($cloneCase['id']), $newCaseID);

        $sql  = "INSERT INTO {$this->tenantDB}.subjectInfoDD SET\n";
        $sql .= "clientID       = :clientID\n,";
        $sql .= "pointOfContact = :pointOfContact\n,";
        $sql .= "POCposition    = :POCposition\n,";
        $sql .= "phone          = :phone\n,";
        $sql .= "subStat        = :subStat\n,";

        $bind = [
            ':clientID'         => $this->tenantID,
            ':pointOfContact'   => $caseData['csPocNa'] ?? '',
            ':POCposition'      => $caseData['csPocPos'] ?? '',
            ':phone'            => $caseData['csPocTel'] ?? '',
            ':subStat'          => $caseData['csStat'] ?? '',
        ];

        // For the sake of space this array of subjectInfoDD.columns is being iterated below:
        $copyColumns = [
            'name',                     'street',                       'modified',
            'bAwareInvestigation',      'reasonInvestigating',          'addInfo',
            'addr2',                    'postCode',                     'principal1',
            'principal2',               'principal3',                   'principal4',
            'SBIonPrincipals',          'bp1Owner',                     'p1OwnPercent',
            'bp1KeyMgr',                'bp1BoardMem',                  'bp1KeyConsult',
            'bp1Unknown',               'bp2Owner',                     'p2OwnPercent',
            'bp2KeyMgr',                'bp2BoardMem',                  'bp2KeyConsult',
            'bp2Unknown',               'bp3Owner',                     'p3OwnPercent',
            'bp3KeyMgr',                'bp3BoardMem',                  'bp3KeyConsult',
            'bp3Unknown',               'bp4Owner',                     'p4OwnPercent',
            'bp4KeyMgr',                'bp4BoardMem',                  'bp4KeyConsult',
            'bp4Unknown',               'DBAname',                      'p1phone',
            'p1email',                  'p2phone',                      'p2email',
            'p3phone',                  'p3email',                      'p4phone',
            'p4email',                  'principal5',                   'pRelationship5',
            'bp5Owner',                 'p5OwnPercent',                 'bp5KeyMgr',
            'bp5BoardMem',              'bp5KeyConsult',                'bp5Unknown',
            'p5phone',                  'p5email',                      'principal6',
            'pRelationship6',           'bp6Owner',                     'p6OwnPercent',
            'bp6KeyMgr',                'bp6BoardMem',                  'bp6KeyConsult',
            'bp6Unknown',               'p6phone',                      'p6email',
            'principal7',               'pRelationship7',               'bp7Owner',
            'p7OwnPercent',             'bp7KeyMgr',                    'bp7BoardMem',
            'bp7KeyConsult',            'bp7Unknown',                   'p7phone',
            'p7email',                  'principal8',                   'pRelationship8',
            'bp8Owner',                 'p8OwnPercent',                 'bp8KeyMgr',
            'bp8BoardMem',              'bp8KeyConsult',                'bp8Unknown',
            'p8phone',                  'p8email',                      'principal9',
            'pRelationship9',           'bp9Owner',                     'p9OwnPercent',
            'bp9KeyMgr',                'bp9BoardMem',                  'bp9KeyConsult',
            'bp9Unknown',               'p9phone',                      'p9email',
            'principal10',              'pRelationship10',              'bp10Owner',
            'p10OwnPercent',            'bp10KeyMgr',                   'bp10BoardMem',
            'bp10KeyConsult',           'bp10Unknown',                  'p10phone',
            'p10email',                 'city',                         'state',
            'country',
        ];

        foreach ($copyColumns as $column) {
            $sql .= "{$column} = :{$column}\n,";
            $bind[':' . $column] = $cloneSubjectInfoDD[$column];
        }

        $sql            .= 'caseID         = :caseID';
        $bind[':caseID'] = $newCaseID;

        if (!$this->app->DB->query($sql, $bind)) {
            return ['error' => 'Unable to clone Case dependent data.'];
        }

        try {
            // Clone Record Mapping
            $this->mapCloneRecords((object)$cloneCase, $newCaseID);

            // Create Case PDF for attachments
            $this->generateCasePdf($cloneCase, $newCaseID);

            // Create audit log entry
            $auditLog = new LogData($this->tenantID, $this->userID);
            $eventID = 160; // Cloned Case
            $origTenant = $this->getOriginalTenant();
            $logMsg = "Cloned Case from $origTenant";
            $auditLog->saveLogEntry($eventID, $logMsg, $newCaseID);

            return $newCaseID;
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: ' . $e->getMessage());
            return ['error' => 'Unable to Clone cases.'];
        }
    }


    /**
     * Clone a third party profile using $this->input (user inputs from wizard) and an existing third party profile
     *
     * @param object $input $this->input user inputs from wizard
     *
     * @return  mixed
     *
     * @throws \Exception
     */
    public function cloneTPP($input)
    {
        $clone     = $this->getThirdPartyProfile($input->userTpNum);
        $TPP       = new ThirdParty($this->tenantID);
        $ownerID   = ($this->ftr->legacyUserType > UserType::CLIENT_ADMIN)
            ? $TPP->getDefaultOwnerID()
            : $this->userID;

        $attributes = [
            'approvalReasons' => $clone->approvalReasons,
            'internalCode'    => $input->sources['tpprofile']['profile']['tpIC'],
            'tpType'          => $input->sources['tpprofile']['profile']['tpPT'],
            'tpTypeCategory'  => $input->sources['tpprofile']['profile']['tpCat'],
            'region'          => $input->sources['tpprofile']['profile']['tpRg'],
            'department'      => $input->sources['tpprofile']['profile']['tpDp'],
            'POCname'         => $input->sources['tpprofile']['profile']['tpPcNm'],
            'POCposi'         => $input->sources['tpprofile']['profile']['tpPcPos'],
            'POCemail'        => $input->sources['tpprofile']['profile']['tpPcEm'],
            'POCphone1'       => $input->sources['tpprofile']['profile']['tpPcPh1'],
            'POCphone2'       => $input->sources['tpprofile']['profile']['tpPcPh2'],
            'POCmobile'       => $input->sources['tpprofile']['profile']['tpPcMb'],
            'POCfax'          => $input->sources['tpprofile']['profile']['tpPcFx'],
            'legalForm'       => $input->sources['tpprofile']['profile']['tpLF'],
            'clientID'        => $this->tenantID,
            'ownerID'         => $ownerID,
            'userTpNum'       => '', // Determined inside INSERT transaction
            'createdBy'       => $this->userID,
            'tpCreated'       => date('Y-m-d H:i:s'),
            'legalName'       => $clone->legalName,
            'DBAname'         => $clone->DBAname,
            'addr1'           => $clone->addr1,
            'addr2'           => $clone->addr2,
            'city'            => $clone->city,
            'country'         => $clone->country,
            'state'           => $clone->state,
            'postcode'        => $clone->postcode,
            'website'         => $clone->website,
            'bPublicTrade'    => $clone->bPublicTrade,
            'stockExchange'   => $clone->stockExchange,
            'tickerSymbol'    => $clone->tickerSymbol,
            'yearsInBusiness' => $clone->yearsInBusiness,
            'regCountry'      => $clone->regCountry,
            'regNumber'       => $clone->regNumber,
            'regDate'         => $clone->regDate,
            'status'          => $clone->status,
            'approvalStatus'  => $clone->approvalStatus,
            'recordType'      => $clone->recordType,
        ];

        // Validate third party profile data
        if (!$this->validateTPP($attributes, $TPP)) {
            throw new \Exception('Invalid profile details provided');
        }

        // Use a transaction to insert 3P profile
        $originalRecord = $clone; // it's an object from fetchObjectRow()
        try {
            $insUniq = new InsertUniqueUserNumRecord($this->tenantID);
            $insUniq->debug = false;
            $insRes = $insUniq->insertUnique3pProfile($attributes);
        } catch (\PDOException | \Exception) {
            $insRes = ['userTpNum' => '', 'tpID' => 0];
        }

        if ($recordID = $insRes['tpID']) {
            // Not sure why another instance is needed, or why it has to lie about isAPI
            $ThirdParty = new ThirdParty($this->tenantID, ['isAPI' => true]);

            //   Clone Record Mapping
            $this->mapCloneRecords($originalRecord, $recordID);

            //   Clone Notes
            if (isset($input->sources['tpprofile']['notes'])) {
                $this->cloneNotes($input, $recordID);
            }

            //   Clone Corporate Docs
            if (isset($input->sources['tpprofile']['corporatedocs'])) {
                $this->cloneTpAttach($input, $recordID);
            }

            //   Clone Training Docs
            if (isset($input->sources['tpprofile']['trainingdocs'])) {
                foreach ($input->sources['tpprofile']['trainingdocs'] as $key => $value) {
                    $this->performTrainingClones($value['id'], $recordID);
                }
            }

            //   Clone Cases
            if (isset($input->sources['cases'])) {
                foreach ($this->getCases() as $cloneRecord) {
                    if (isset($input->sources['cases'][$cloneRecord['id']])) {
                        if (!isset($input->sources['cases'][$cloneRecord['id']]['csRg'])
                            ||  !isset($input->sources['cases'][$cloneRecord['id']]['csDep'])
                            ||  !isset($input->sources['cases'][$cloneRecord['id']]['csStat'])
                            ||  !isset($input->sources['cases'][$cloneRecord['id']]['csPocNa'])
                            ||  !isset($input->sources['cases'][$cloneRecord['id']]['csPocPos'])
                            ||  !isset($input->sources['cases'][$cloneRecord['id']]['csPocTel'])
                        ) {
                            continue;
                        }
                        try {
                            // Clone the case
                            $this->cloneCase(
                                (array)$cloneRecord,
                                $input->sources['cases'][$cloneRecord['id']],
                                $recordID
                            );
                        } catch (\Exception $e) {
                            $this->app->log->error($e->getMessage());
                        }
                    }
                }
            }

            // Create audit log entry
            $auditLog = new LogData($this->tenantID, $this->userID);
            $eventID = 159; // Cloned Third Party
            $origTenant = $this->getOriginalTenant();
            $logMsg = "Third Party cloned from $origTenant ";
            $auditLog->save3pLogEntry($eventID, $logMsg, $recordID);

            // Trigger Risk Assessment
            if ($this->ftr->tenantHas(\Feature::TENANT_TPM_RISK) && isset($recordID)) {
                $ThirdParty->updateCurrentRiskAssessment($recordID);
            }

            // Run GDC screening
            if ($this->ftr->tenantHas(\Feature::TENANT_GDC_BASIC) && isset($recordID)) {
                $monitor = new GdcMonitor($this->tenantID, $this->userID);
                $monitor->run3pGdc($recordID);
            }

            return $recordID;
        } else {
            return ['error' => 'Unable to clone Third Party Profile'];
        }
    }


    /**
     * Get the name of the original record's tenant
     *
     * @return string name of original tenant
     */
    public function getOriginalTenant()
    {
        $sql = "SELECT name FROM {$this->app->DB->globalDB}.g_tenants WHERE id = {$this->cloneTenantID}";
        return $this->app->DB->fetchValue($sql);
    }


    /**
     * Get the users.userid of a given user
     *
     * @return mixed string or false
     */
    public function getUserID()
    {
        $sql = "SELECT userid FROM {$this->app->DB->authDB}.users WHERE id = {$this->userID}";
        return $this->app->DB->fetchValue($sql);
    }


    /**
     * Clones notes to be used with new Third Party Profile
     *
     * @param array   $input $this->input->sources['tpprofile']['notes'] user input for notes from wizard
     * @param integer $tppID tenantDB.thirdPartyProfile.id
     *
     * @return boolean
     */
    public function cloneNotes($input, $tppID)
    {
        //  Index all available clone notes by `id`
        $index = [];
        foreach ($this->getTpNote() as $key => $record) {
            $index[$record['id']] = $record;
        }

        //  Find each available clone note by `id`
        $clone = [];
        foreach ($input->sources['tpprofile']['notes'] as $id => $array) {
            if (isset($index[$id])) {
                $clone[$id] = $index[$id];
            }
        }

        $noteCategoryID = $input->sources['tpprofile']['profile']['noteCat'];

        foreach ($clone as $note) {
            $sql  = "INSERT INTO {$this->tenantDB}.tpNote SET\n";
            $sql .= "clientID      = :clientID\n,";
            $sql .= "tpID          = :tpID\n,";
            $sql .= "noteCatID     = :noteCatID\n,";
            $sql .= "ownerID       = :ownerID\n,";
            $sql .= "created       = :created\n,";
            $sql .= "subject       = :subject\n,";
            $sql .= "note          = :note";

            $bind = [
                ':clientID'      =>  $this->tenantID,
                ':tpID'          =>  $tppID,
                ':noteCatID'     =>  $noteCategoryID,
                ':ownerID'       =>  $this->userID,
                ':created'       =>  (string)date('Y-m-d H:i:s'),
                ':subject'       =>  $note['subject'],
                ':note'          =>  $note['note'],
            ];

            $this->app->DB->query($sql, $bind);
        }
        return true;
    }


    /**
     * Clones tpAttach for use with the new clone Third Party Profile
     *
     * @param array   $input $this->input, user input from wizard for tpAttach e.g. category
     * @param integer $tppID thirdPartyProfile.id
     *
     * @return boolean
     *
     * @throws \Exception
     */
    private function cloneTpAttach($input, $tppID)
    {
        //  Index all available clone notes by `id`
        $index = [];
        foreach ($this->getTpAttach() as $key => $record) {
            $index[$record['id']] = $record;
        }

        //  Find each available clone note by `id`
        $clone = [];
        foreach ($input->sources['tpprofile']['corporatedocs'] as $id => $array) {
            if (isset($index[$id])) {
                $clone[$id] = $index[$id];
            }
        }

        $docCategoryID = $input->sources['tpprofile']['profile']['docCat'];

        foreach ($clone as $document) {
            $sql  = "INSERT INTO {$this->tenantDB}.tpAttach SET\n";
            $sql .= "clientID      = :clientID\n,";
            $sql .= "tpID          = :tpID\n,";
            $sql .= "description   = :description\n,";
            $sql .= "filename      = :filename\n,";
            $sql .= "fileType      = :fileType\n,";
            $sql .= "fileSize      = :fileSize\n,";
            $sql .= "contents      = :contents\n,";
            $sql .= "creationStamp = :creationStamp\n,";
            $sql .= "ownerID       = :ownerID\n,";
            $sql .= "emptied       = :emptied\n,";
            $sql .= "catID         = :catID";

            $bind = [
                ':clientID'      =>  $this->tenantID,
                ':tpID'          =>  $tppID,
                ':description'   =>  $document['description'],
                ':filename'      =>  $document['filename'],
                ':fileType'      =>  $document['fileType'],
                ':fileSize'      =>  $document['fileSize'],
                ':contents'      =>  $document['contents'],
                ':creationStamp' =>  $document['creationStamp'],
                ':ownerID'       =>  $this->userID,
                ':emptied'       =>  $document['emptied'],
                ':catID'         =>  $docCategoryID,
            ];

            try {
                $copy = $this->app->DB->query($sql, $bind);
                if ($copy->rowCount() > 0) {
                    $this->FileManager->cloneFileAttachment(
                        'tpAttach',
                        $this->cloneTenantID,
                        $this->tenantID,
                        $document['id'],
                        $this->app->DB->lastInsertId()
                    );
                } else {
                    throw new \Exception('Unable to clone Third Party attachment.');
                }
            } catch (\Exception $e) {
                $this->app->log->error($e->getMessage());
                return false;
            }
        }
        return true;
    }


    /**
     * Clones subInfoAttach for use with the new clone case
     *
     * @param array   $attachments array of attachment documents
     * @param integer $caseID      cases.id of the new case
     * @param boolean $move        do you want to clone the actual file? use false for casepdfgen
     *
     * @return boolean
     *
     * @throws \Exception
     */
    private function cloneSubInfoAttach($attachments, $caseID, $move = true)
    {
        $userID = $this->getUserID();
        foreach ($attachments as $document) {
            $sql  = "INSERT INTO {$this->tenantDB}.subInfoAttach SET\n";
            $sql .= "caseID          = :caseID\n,";
            $sql .= "description     = :description\n,";
            $sql .= "filename        = :filename\n,";
            $sql .= "fileType        = :fileType\n,";
            $sql .= "fileSize        = :fileSize\n,";
            $sql .= "contents        = :contents\n,";
            $sql .= "creationStamp   = :creationStamp\n,";
            $sql .= "ownerID         = :ownerID\n,";
            $sql .= "caseStage       = :caseStage\n,";
            $sql .= "emptied         = :emptied\n,";
            $sql .= "catID           = :catID\n,";
            $sql .= "clientID        = :clientID";

            $bind = [
                ':caseID'        =>  (int)$caseID,
                ':description'   =>  $document['description'],
                ':filename'      =>  $document['filename'],
                ':fileType'      =>  $document['fileType'],
                ':fileSize'      =>  $document['fileSize'],
                ':contents'      =>  $document['contents'],
                ':creationStamp' =>  $document['creationStamp'],
                ':ownerID'       =>  $userID,
                ':caseStage'     =>  $document['caseStage'],
                ':emptied'       =>  $document['emptied'],
                ':catID'         =>  $document['catID'],
                ':clientID'      =>  $this->tenantID
            ];

            try {
                $copy = $this->app->DB->query($sql, $bind);

                $newAttachID = (int)$this->app->DB->lastInsertId();
                if (!array_key_exists('id', $document)) {
                    $document['id'] = $newAttachID;
                }

                if ($move) {
                    if ($copy->rowCount() > 0) {
                        $this->FileManager->cloneFileAttachment(
                            'subInfoAttach',
                            $this->cloneTenantID,
                            $this->tenantID,
                            $document['id'],
                            $newAttachID
                        );
                    } else {
                        throw new \Exception('Unable to clone Case folder attachment');
                    }
                }
                if ($newAttachID) {
                    $this->FileManager->putClientInfoFile('subInfoAttach', $this->tenantID, $newAttachID);
                }
            } catch (\Exception $e) {
                $this->app->log->error($e->getMessage());
                return false;
            }
        }
        return true;
    }


    /**
     * Clones iAttachments for use with the new clone case
     *
     * @param array   $attachments array of attachment documents
     * @param integer $caseID      cases.id of the new case
     *
     * @return boolean
     *
     * @throws \Exception
     */
    private function cloneIAttachments($attachments, $caseID)
    {
        $userID = $this->getUserID();
        foreach ($attachments as $document) {
            $sql  = "INSERT INTO {$this->tenantDB}.iAttachments SET\n";
            $sql .= "caseID          = :caseID\n,";
            $sql .= "description     = :description\n,";
            $sql .= "filename        = :filename\n,";
            $sql .= "fileType        = :fileType\n,";
            $sql .= "fileSize        = :fileSize\n,";
            $sql .= "contents        = :contents\n,";
            $sql .= "creationStamp   = :creationStamp\n,";
            $sql .= "ownerID         = :ownerID\n,";
            $sql .= "emptied         = :emptied\n,";
            $sql .= "sp_catID       = :sp_catID\n";

            $bind = [
                ':caseID'          =>  (int)$caseID,
                ':description'   =>  $document['description'],
                ':filename'      =>  $document['filename'],
                ':fileType'      =>  $document['fileType'],
                ':fileSize'      =>  $document['fileSize'],
                ':contents'      =>  $document['contents'],
                ':creationStamp' =>  $document['creationStamp'],
                ':ownerID'       =>  $userID,
                ':emptied'       =>  $document['emptied'],
                ':sp_catID'      =>  0
            ];

            try {
                $copy = $this->app->DB->query($sql, $bind);
                if ($copy->rowCount() > 0) {
                    $this->FileManager->cloneFileAttachment(
                        'iAttachments',
                        $this->cloneTenantID,
                        $this->tenantID,
                        $document['id'],
                        $this->app->DB->lastInsertId()
                    );
                }
            } catch (\Exception $e) {
                $this->app->log->error('Caught exception: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }


    /**
     * Clones ddqAttach for use with the new clone case
     *
     * @param array $attachments array of attachment documents
     * @param int   $caseID      cases.id of original case
     * @param bool  $move        do you want to clone the actual file? use false for casepdfgen
     *
     * @return boolean
     *
     * @throws \Exception
     */
    private function cloneDDQAttach($attachments, $caseID, $move = true)
    {
        $userID = $this->getUserID();
        foreach ($attachments as $document) {
            $sql  = "INSERT INTO {$this->tenantDB}.subInfoAttach SET\n";
            $sql .= "caseID          = :caseID\n,";
            $sql .= "description     = :description\n,";
            $sql .= "filename        = :filename\n,";
            $sql .= "fileType        = :fileType\n,";
            $sql .= "fileSize        = :fileSize\n,";
            $sql .= "contents        = :contents\n,";
            $sql .= "creationStamp   = :creationStamp\n,";
            $sql .= "ownerID         = :ownerID\n,";
            $sql .= "caseStage       = :caseStage\n,";
            $sql .= "emptied         = :emptied\n,";
            $sql .= "catID           = :catID\n,";
            $sql .= "clientID        = :clientID";

            $bind = [
                ':caseID'        =>  (int)$caseID,
                ':description'   =>  $document['description'],
                ':filename'      =>  $document['filename'],
                ':fileType'      =>  $document['fileType'],
                ':fileSize'      =>  $document['fileSize'],
                ':contents'      =>  $document['contents'],
                ':creationStamp' =>  $document['creationStamp'],
                ':ownerID'       =>  $userID,
                ':caseStage'     =>  9,
                ':emptied'       =>  $document['emptied'],
                ':catID'         =>  0,
                ':clientID'      =>  $this->tenantID
            ];

            try {
                $copy = $this->app->DB->query($sql, $bind);
                $newAttachID = (int)$this->app->DB->lastInsertId();
                if (!array_key_exists('id', $document)) {
                    $document['id'] = $newAttachID;
                }

                if ($move) {
                    if ($copy->rowCount() > 0) {
                        $this->FileManager->cloneFileAttachment(
                            'ddqAttach',
                            $this->cloneTenantID,
                            $this->tenantID,
                            $document['id'],
                            $newAttachID,
                            'subInfoAttach'
                        );
                    } else {
                        throw new \Exception('Unable to clone Case folder attachment');
                    }
                }
                if ($newAttachID) {
                    $this->FileManager->putClientInfoFile('subInfoAttach', $this->tenantID, $newAttachID);
                }
            } catch (\Exception $e) {
                $this->app->log->error($e->getMessage());
                return false;
            }
        }
        return true;
    }


    /**
     * Clones caseNote for use with the new clone case
     *
     * @param array   $notes  array of case notes
     * @param integer $caseID cases.id of the new case
     *
     * @see TrainingTrait::getTrainingTypeID()
     *
     * @return  boolean
     *
     * @throws \Exception
     */
    private function cloneCaseNote($notes, $caseID)
    {
        // Get the "cloned note" category id for this tenant
        $noteCategoryID = $this->getCloneCaseNoteCategory();

        foreach ($notes as $note) {
            $sql = "INSERT INTO {$this->tenantDB}.caseNote SET\n";
            $sql .= "clientID            = :clientID\n,";
            $sql .= "caseID              = :caseID\n,";
            $sql .= "noteCatID           = :noteCatID\n,";
            $sql .= "ownerID             = :ownerID\n,";
            $sql .= "created             = :created\n,";
            $sql .= "subject             = :subject\n,";
            $sql .= "note                = :note";

            $bind = [
                ':clientID'             => $this->tenantID,
                ':caseID'               => $caseID,
                ':noteCatID'            => $noteCategoryID,
                ':ownerID'              => $this->userID,
                ':created'              => (string)date('Y-m-d H:i:s'),
                ':subject'              => $note['subject'],
                ':note'                 => $note['note'],
            ];

            try {
                $this->app->DB->query($sql, $bind);
            } catch (\Exception $e) {
                $this->app->log->error('Caught exception: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }


    /**
     * Get subInfoAttach associated with a case
     *
     * @param integer $caseID cases.id
     *
     * @return mixed array of rows or false
     */
    private function getSubInfoAttach($caseID)
    {
        $sql = "SELECT * FROM {$this->cloneTenantDB}.subInfoAttach WHERE caseID = :caseID";

        $bind = [
            ':caseID'    => (int)$caseID,
        ];

        return $this->app->DB->fetchAssocRows($sql, $bind);
    }


    /**
     * Get iAttachments associated with a case
     *
     * @param integer $caseID cases.id
     *
     * @return mixed array of rows or false
     */
    private function getIAttachments($caseID)
    {
        $sql = "SELECT * FROM {$this->cloneTenantDB}.iAttachments WHERE caseID = :caseID";

        $bind = [
            ':caseID'    => (int)$caseID,
        ];

        return $this->app->DB->fetchAssocRows($sql, $bind);
    }


    /**
     * Get DDQAttach associated with a case
     *
     * @param integer $caseID cases.id
     *
     * @return mixed array of rows or false
     */
    private function getDDQAttach($caseID)
    {
        $sql = "SELECT id FROM {$this->cloneTenantDB}.ddq WHERE caseID = :caseID";

        $bind = [
            ':caseID'    => (int)$caseID,
        ];

        $ddqid = $this->app->DB->fetchValue($sql, $bind);

        $sql = "SELECT * FROM ddqAttach WHERE ddqID = :ddqID";

        $bind = [
            ':ddqID'    => $ddqid
        ];

        try {
            return $this->app->DB->fetchAssocRows($sql, $bind);
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Get DDQAttach associated with a case as chunks (avoids exhausted allocated memory error)
     *
     * @param integer $caseID    cases.id of the original case
     * @param integer $newCaseID cases.id of the new 'clone' case
     *
     * @return mixed array of rows or false
     */
    private function getDDQAttachChunk($caseID, $newCaseID)
    {
        $sql = "SELECT id FROM {$this->cloneTenantDB}.ddq WHERE caseID = :caseID";

        $bind = [
            ':caseID'    => (int)$caseID,
        ];

        $ddqid = $this->app->DB->fetchValue($sql, $bind);

        $sql = "SELECT * FROM ddqAttach WHERE id > :uniqueID AND ddqID = :ddqID ORDER BY id ASC";

        $bind = [
            ':ddqID'    => $ddqid
        ];

        try {
            $chunker = new ChunkResults($this->app->DB, $sql, $bind);
            while ($attach = $chunker->getRecord()) {
                $this->cloneDDQAttach([$attach], $newCaseID);
            }
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: ' . $e->getMessage());
            return false;
        }
        return true;
    }


    /**
     * Get caseNotes associated with a case
     *
     * @param int $caseID cases.id
     *
     * @return array
     */
    public function getCaseNote($caseID)
    {
        $sql = "SELECT * FROM {$this->cloneTenantDB}.caseNote WHERE caseID = :caseID";

        $bind = [
            ':caseID'    => (int)$caseID,
        ];

        return $this->app->DB->fetchAssocRows($sql, $bind);
    }


    /**
     * Gets the "cloned note" noteCategory for caseNote(s), if that category does not exist for the cloning tenant,
     * it creates the entry and returns the id of the new entry
     *
     * @return mixed
     */
    private function getCloneCaseNoteCategory()
    {
        $sql  = [
            'select' => "SELECT id FROM {$this->tenantDB}.noteCategory WHERE clientID = :clientID AND name = :name",
            'insert' => "INSERT INTO {$this->tenantDB}.noteCategory SET clientID = :clientID, name = :name",
        ];
        $bind = [
            ':clientID' =>  $this->tenantID,
            ':name'     =>  'Cloned Note',
        ];
        $id   = $this->app->DB->fetchValue($sql['select'], $bind);

        if (empty($id)) {
            $this->app->DB->query($sql['insert'], $bind);
            $id = $this->app->DB->lastInsertId();
        }

        return $id;
    }


    /**
     * Generate a PDF of the case.
     *
     * @param array   $caseRow   cases row from db with id, clientid, etc.
     * @param integer $newCaseID cases.id of new case
     *
     * @see    SpLite::generateCasePdf() returns object containing all the info to generate a PDF
     *
     * @return boolean
     *
     * @throws \Exception
     */
    public function generateCasePdf($caseRow, $newCaseID)
    {
        $pdfAttach = [
            'caseID'        =>  (int)$caseRow['id'],
            'description'   =>  'PDF record of original case folder - generated by clone wizard',
            'filename'      =>  'PDF of ' . $caseRow['userCaseNum'],
            'fileType'      =>  'application/pdf',
            'fileSize'      =>  '',
            'contents'      =>  '',
            'creationStamp' =>  date('Y-m-d H:i:s'),
            'ownerID'       =>  $this->userID,
            'caseStage'     =>  $caseRow['caseStage'],
            'emptied'       =>  '',
            'catID'         =>  0,
        ];

        try {
            // insert the pdf attach into subInfoAttach so that the ID can later be referenced for movement
            $this->cloneSubInfoAttach([$pdfAttach], $newCaseID, false); // must be the new case (the clone's) id
            $pdfAttachID = (int)$this->app->DB->lastInsertId();

            $authRow = (object) [
                'caseID'   => $caseRow['id'],
                'clientID' => $this->cloneTenantID
            ];


            $this->PDF = new CasePDF($this->cloneTenantID);
            $caseDocs = $this->getCaseDocs(
                $this->cloneTenantID,
                $caseRow['caseAssignedAgent'],
                $caseRow['id'],
                date('Y-m-d H:i:s'),
                true
            );
            $casePDF = $this->PDF->makeCasePDF($authRow, $caseRow, $caseDocs);

            if (isset($pdfAttachID)) {
                // file movement logic
                $pdfPath = $this->FileManager->clientFiles . '/' . 'subInfoAttach' . '/' . $this->tenantID . '/'
                    . $pdfAttachID;
                $pdfPath = FileTestDir::getPath($pdfPath);
                // generate the case pdf, update the subinfoattach row with the filesize
                $q = "UPDATE {$this->tenantDB}.subInfoAttach SET fileSize = :fileSize WHERE id = :id";
                $this->app->DB->query($q, [':fileSize' => filesize($casePDF), ':id' => $pdfAttachID]);
                // move the newly generated pdf file to the correct path, add the -info file, remove the excess files
                $this->FileManager->moveFile($casePDF, $pdfPath);
                $this->FileManager->putClientInfoFile('subInfoAttach', $this->tenantID, $pdfAttachID);
                $this->FileManager->removeClientFiles($casePDF);
            } else {
                throw new \Exception('Failed to generate original Case PDF.');
            }
        } catch (\Exception $e) {
            $this->app->log->error($e->getMessage());
            return false;
        }
        return true;
    }


    /**
     * Get a list of all the documents uploaded to the case, and build up the requisite information associated
     * with each file.
     *
     * @param string  $clientID case client ID
     * @param string  $spID     case service provider ID
     * @param string  $caseID   case ID
     * @param string  $accepted if case accepted then this contains the date of acceptance
     * @param boolean $pdf      boolean indicator if this is being called in the context of generating a PDF
     *
     * @see   SpLite::getSpDocs() get the spdocs associated with the case
     *
     * @return array Returns an array containing the total count of docs (files) found, the the details for each doc.
     */
    public function getCaseDocs($clientID, $spID, $caseID, $accepted, $pdf = false)
    {
        $orderBy   = 'fdesc';
        $sortDir   = 'ASC';
        $userUnion = '';

        $tmp = $this->getVendorDocumentCategories($spID, $clientID, true);
        $spCats = [];
        foreach ($tmp as $info) {
            $spCats[$info['id']] = $info['name'];
        }

        $spflds_ = "a.`id` AS dbid, "
            . "a.`description` AS fdesc, "
            . "a.`filename` AS fname, "
            . "a.`fileSize` AS fsize, "
            . "a.`fileType` AS ftype, "
            . "a.`sp_catID` AS fcat, "
            . "'%1\$s' AS fsrc, "
            . "%2\$s AS candel ";

        $flds_ = "a.`id` AS dbid, "
            . "a.`description` AS fdesc, "
            . "a.`filename` AS fname, "
            . "a.`fileType` AS ftype, "
            . "a.`fileSize` AS fsize, "
            . "%3\$s AS fcat, "
            . "'%1\$s' AS fsrc, "
            . "%2\$s AS candel ";

        if ($pdf) {
            $spflds_  .= ", LEFT(a.creationStamp, 10) AS fdate, u.lastName AS owner ";
            $flds_    .= ", LEFT(a.creationStamp, 10) AS fdate, u.lastName AS owner ";
            $userUnion = "LEFT JOIN {$this->app->DB->authDB}.users AS u ON u.userid = a.ownerID ";

            $ddqflds_  = str_replace('u.lastName', 'u.subByName', $flds_);
            $ddqUnion  = "LEFT JOIN {$this->cloneTenantDB}.ddq AS u ON u.id = a.ddqID ";
        } else {
            $ddqflds_ = $flds_;
            $ddqUnion = $userUnion;
        }

        $stopTm = $accepted;
        $spflds_ = sprintf($spflds_, 'Analyst', "IF(creationStamp > '$stopTm', 1, 0)");
        $iRowsSql = "SELECT $spflds_ FROM iAttachments AS a "
            . $userUnion
            . "WHERE a.caseID = :caseID ORDER BY $orderBy $sortDir";
        $params = [':caseID' => $caseID];
        $iRows = $this->app->DB->fetchObjectRows($iRowsSql, $params);

        $flds = sprintf($flds_, 'Client', "'0'", 'c.name');
        $cRowsSql = "SELECT $flds FROM subInfoAttach AS a "
            . $userUnion
            . "LEFT JOIN docCategory AS c ON c.id = a.catID "
            . "WHERE a.caseID = :caseID ORDER BY $orderBy $sortDir";
        $params = [':caseID' => $caseID];
        $cRows = $this->app->DB->fetchObjectRows($cRowsSql, $params);

        $dRows = [];
        $sql = "SELECT id FROM ddq WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
        $params = [':caseID' => $caseID, ':clientID' => $clientID];
        if ($ddqID = $this->app->DB->fetchValue($sql, $params)) {
            $flds = sprintf($ddqflds_, 'DDQ', "'0'", "'Intake Form'");
            $dRowsSql = "SELECT $flds FROM ddqAttach AS a "
                . $ddqUnion
                . "WHERE ddqID = :ddqID ORDER BY $orderBy $sortDir";
            $params = [':ddqID' => $ddqID];
            $dRows = $this->app->DB->fetchObjectRows($dRowsSql, $params);
        }

        $cnt = $limit = count($iRows) + count($cRows) + count($dRows);
        $rows = [];

        if ($cnt) {
            $rows = [];
            for ($i = 0; $i < count($iRows); $i++) {
                $iRows[$i]->fcat = (array_key_exists($iRows[$i]->fcat, $spCats))
                    ? $spCats[$iRows[$i]->fcat]
                    : '';
                $rows[] = $iRows[$i];
            }
            for ($i = 0; $i < count($cRows); $i++) {
                $rows[] = $cRows[$i];
            }
            for ($i = 0; $i < count($dRows); $i++) {
                $rows[] = $dRows[$i];
            }
            for ($i = 0; $i < $limit; $i++) {
                $rows[$i]->ftype = $this->FileManager->getFileTypeDescriptionByFileName($rows[$i]->fname);
                $rows[$i]->del = '&nbsp;';
            }
        }

        return ['docs' => $rows, 'total' => $cnt];
    }


    /**
     * Gets an array of service provider document categories, including client-specific
     * overrides, if any.
     *
     * @param integer $spID     spDocCategoryEx.spID or spDocCategory.spID
     * @param integer $clientID spDocCategoryEx.clientID
     * @param boolean $all      if true return inactive records, too
     *
     * @return array id/name list of categories,
     */
    public function getVendorDocumentCategories($spID, $clientID, $all = false)
    {
        $spID = intval($spID);
        $clientID = intval($clientID);

        if (!$all) {
            $xCond = 'AND x.active <> 0';
            $cCond = 'c.active <> 0';
        } else {
            $cCond = '1';
            $xCond = '';
        }

        $sql = "SELECT c.id, IF(x.docCatID IS NOT NULL, x.altName, c.name) AS `name`\n"
            . "FROM " . $this->spDb . ".spDocCategory AS c\n"
            . "LEFT JOIN " . $this->spDb . ".spDocCategoryEx AS x\n"
            . "ON (x.docCatID = c.id AND x.clientID = :clientID\n"
            . "  $xCond)\n"
            . "WHERE c.spID = :spID\n"
            . "AND (x.docCatID IS NOT NULL\n"
            . "  OR $cCond)\n"
            . "ORDER BY `name` ASC";
        $params = [':clientID' => $clientID, ':spID' => $spID];
        if (!($data = $this->app->DB->fetchAssocRows($sql, $params))) {
            $defName = 'General';
            $spDocSql = "INSERT INTO {$this->spDb}.spDocCategory (spID,name,active) "
                . "VALUES(':spID',':defName',1)";
            $params = [':spID' => $spID,':defName' => $defName];
            if ($this->app->DB->query($spDocSql, $params)) {
                $id = $this->app->DB->lastInsertId();
                $data[] = ['id' => $id, 'name' => $defName];
            }
        }
        return $data;
    }


    /**
     * Creates cloneRecordMaps and determining setIDs and responsibility
     *
     * @param object $originalRecord the original record which this was cloned from, could be tpprofile or case
     * @param int    $cloneRecordID  the id of the newly created clone record, could be tpprofile.id or case.id
     *
     * @return void
     */
    private function mapCloneRecords($originalRecord, $cloneRecordID)
    {
        // get original records clone record map
        $prior = $this->getOriginalCloneRecordMap($originalRecord);

        // set the type of record for the cloneRecordMap
        $clType = (property_exists($originalRecord, 'userCaseNum')) ? 'case' : 'tpprofile';

        // if this record is an original with no clones create a cloneRecMap entry for it
        if ($prior == null || $prior === false) {
            // get the next setID
            $setID = $this->getNextCloneSetID();
            // create the record for the 'original'
            $originalInsert = $this->createCloneRecordMapEntry([
                'uniqueID'      => (int)$originalRecord->id,
                'responsible'   => 1,
                'setID'         => (int)$setID,
                'userID'        => $this->userID,
                'tenantID'      => (int)$originalRecord->clientID,
                'cloneType'     => $clType
                ]);
            // use that newly created id in cloneRecordMap to update the profile record
            $this->setCloneRecID($clType, $originalRecord->id, $originalInsert['id'], true);
        }
        // relies on either the newly inserted 'original' cloneRecordMap or the $prior
        $set = (isset($originalInsert)) ? $originalInsert['setID'] : $prior['setID'];
        $clonedFrom = (isset($originalInsert)) ? $originalInsert['id'] : $prior['id'];

        // create the record for the clone
        $cloneRecMap = $this->createCloneRecordMapEntry([
            'uniqueID'      => (int)$originalRecord->id,
            'responsible'   => 0,
            'setID'         => (int)$set,
            'userID'        => $this->userID,
            'tenantID'      => $this->tenantID,
            'clonedFrom'    => (int)$clonedFrom,
            'cloneType'     => $clType
            ]);
        // use that newly created id in cloneRecordMap to update the profile record
        $this->setCloneRecID($clType, $cloneRecordID, $cloneRecMap['id']);
    }


    /**
     * Check to see if a record has been cloned previously, if not create the original cloneRecordMap entry
     * then create an entry for the new clone record in cloneRecordMap
     *
     * @param object $origin original record from which this was cloned
     *
     * @return mixed array or false depending on whether the record had previously been cloned
     */
    private function getOriginalCloneRecordMap($origin)
    {
        $tbl = (property_exists($origin, 'userCaseNum')) ? 'cases' : 'thirdPartyProfile';
        // reverted in sec-2595
        $oSql = "SELECT cloneRecID FROM {$this->cloneTenantDB}.{$tbl} WHERE id = :uniqueID";
        $original = $this->app->DB->fetchValue($oSql, [
            ':uniqueID' => (int)$origin->id
        ]);

        if (isset($original)) {
            $rmSql = "SELECT * FROM {$this->app->DB->globalDB}.g_cloneRecordMap WHERE id = :original";
            return $this->app->DB->fetchAssocRow($rmSql, [
                ':original' => (int)$original
            ]);
        }

        return false;
    }


    /**
     * Create an entry in the global cloneRecordMap table
     *
     * @param array $params tenantID, userID, responsible, clonedFrom, etc.
     *
     * @return mixed array or false
     */
    private function createCloneRecordMapEntry($params)
    {
        $field = ($params['cloneType'] === 'case') ? 'caseID' : 'thirdPartyProfileID' ;

        // insert a new record into the global cloneRecordMap table
        $sql = "INSERT INTO {$this->app->DB->globalDB}.g_cloneRecordMap SET\n";
        $sql .= "tenantID    = :tenantID\n,";
        $sql .= "userID      = :userID\n,";
        $sql .= "{$field}    = :dynamicField\n,";
        $sql .= "setID       = :setID\n,";
        $sql .= "responsible = :responsible\n,";
        $sql .= "clonedFrom  = :clonedFrom\n,";
        $sql .= "created     = :created\n";

        $user = $params['userID'] ?? null;
        $tenant = $params['tenantID'] ?? $this->tenantID;
        $uniqueID = $params['uniqueID'];
        $responsible = $params['responsible'] ?? 0;
        $setID = $params['setID'];
        $clonedFrom = $params['clonedFrom'] ?? null;


        $bind = [
            ':tenantID'             =>  (int)$tenant,           // tenantID of the user who cloned the record
            ':userID'               =>  $user,                  // users.id of the user who cloned the record
            ':dynamicField'         =>  (int)$uniqueID,         // thirdPartyProfile.id or case.id of original record
            ':setID'                =>  (int)$setID,            // setID of original record or next highest setID
            ':responsible'          =>  (int)$responsible,      // 0 or 1 if this is the original
            ':clonedFrom'           =>  $clonedFrom,            // cloneRecordMap.id or null if original
            ':created'              =>  (string)date('Y-m-d H:i:s'),
        ];

        try {
            $this->app->DB->query($sql, $bind);
            $newID = (int)$this->app->DB->lastInsertId();

            $sql = "SELECT * FROM {$this->app->DB->globalDB}.g_cloneRecordMap WHERE id = :newID";

            return $this->app->DB->fetchAssocRow($sql, [
                ':newID' => (int)$newID
                ]);
        } catch (\Exception $e) {
            $this->app->log->error($e->getMessage());
            return false;
        }
    }


    /**
     * Get the next available setID for cloneRecordMap
     *
     * @return int next available setID
     */
    private function getNextCloneSetID()
    {
        $sql = "SELECT MAX(setID)+1 FROM {$this->app->DB->globalDB}.g_cloneRecordMap";
        return $this->app->DB->fetchValue($sql);
    }


    /**
     * Sets the cloneRecID after record insertion
     *
     * @param string  $clType     'case' or 'tpprofile'
     * @param int     $originalID thirdPartyProfile.id or cases.id
     * @param int     $cloneRecID cloneRecordMap.id
     * @param boolean $original   false if record is a clone, true if record is an original
     *
     * @return boolean
     */
    private function setCloneRecID($clType, $originalID, $cloneRecID, $original = false)
    {
        $db = ($original == false) ? $this->tenantDB : $this->cloneTenantDB;
        $tbl = ($clType === 'case') ? 'cases' : 'thirdPartyProfile';

        $update = "UPDATE {$db}.{$tbl} SET cloneRecID = :cloneRecID WHERE id = :uniqueID";
        return $this->app->DB->query($update, [
            ':cloneRecID' => (int)$cloneRecID,
            ':uniqueID'   => (int)$originalID
            ]);
    }


    /**
     * Get case row from db.cases
     *
     * @todo: Currently we only allow caseTypes of 11,12,13,28,29 these may need to be expanded in the future to allow
     * for custom user caseTypes
     *
     * @return array cloneTenantDB.cases record by cases.userCaseNum
     */
    public function getCase()
    {
        $sql = "SELECT {$this->cloneTenantDB}.cases.*,\n"
        .      "{$this->cloneTenantDB}.caseType.name AS caseTypeLabel,\n"
        .      "IF({$this->cloneTenantDB}.ddq.id, 'DDQ', 'Manual Creation') AS origin,\n"
        .      "{$this->app->DB->globalDB}.g_tenants.shortName AS OpcoName\n"
        .      "FROM {$this->cloneTenantDB}.cases\n"
        .      "INNER JOIN {$this->app->DB->globalDB}.g_tenants\n"
        .      "ON {$this->cloneTenantDB}.cases.clientID = {$this->app->DB->globalDB}.g_tenants.legacyClientID\n"
        .      "INNER JOIN {$this->cloneTenantDB}.caseType\n"
        .      "ON {$this->cloneTenantDB}.cases.caseType = {$this->cloneTenantDB}.caseType.id\n"
        .      "LEFT JOIN {$this->cloneTenantDB}.ddq\n"
        .      "ON {$this->cloneTenantDB}.cases.id = {$this->cloneTenantDB}.ddq.caseID\n"
        .      "WHERE {$this->cloneTenantDB}.cases.clientID = :clientID "
        .      "AND {$this->cloneTenantDB}.cases.userCaseNum = :userCaseNum "
        .      "AND {$this->cloneTenantDB}.cases.caseType IN (0,11,12,13,28,29) "
        .      "AND {$this->cloneTenantDB}.cases.caseStage IN (9,10,11,12,14)";

        $bind = [
            ':clientID'    => $this->cloneTenantID,
            ':userCaseNum' => $this->_uniqueID,
        ];

        $case = $this->app->DB->fetchAssocRow($sql, $bind);

        return [$case];
    }


    /**
     * Returns all of the cases matching an external cloneTenantDB.thirdPartyProfile.id (case.tpID)
     *
     * @todo: Currently we only allow caseTypes of 11,12,13,28,29 these may need to be expanded in the future to allow
     * for custom user caseTypes
     *
     * @return mixed
     */
    public function getCases()
    {
        $sql = "SELECT {$this->cloneTenantDB}.cases.*,\n"
            . "{$this->cloneTenantDB}.caseType.name AS caseTypeLabel,\n"
            . "IF({$this->cloneTenantDB}.ddq.id, 'DDQ', 'Manual Creation') AS origin,\n"
            . "{$this->app->DB->globalDB}.g_tenants.shortName AS OpcoName\n"
            . "FROM {$this->cloneTenantDB}.cases\n"
            . "INNER JOIN {$this->app->DB->globalDB}.g_tenants\n"
            . "ON {$this->cloneTenantDB}.cases.clientID = {$this->app->DB->globalDB}.g_tenants.legacyClientID\n"
            . "INNER JOIN {$this->cloneTenantDB}.caseType\n"
            . "ON {$this->cloneTenantDB}.cases.caseType = {$this->cloneTenantDB}.caseType.id\n"
            . "LEFT JOIN {$this->cloneTenantDB}.ddq\n"
            . "ON {$this->cloneTenantDB}.cases.id = {$this->cloneTenantDB}.ddq.caseID\n"
            . "WHERE {$this->cloneTenantDB}.cases.clientID = :clientID "
            . "AND {$this->cloneTenantDB}.cases.tpID = :tppID "
            . "AND {$this->cloneTenantDB}.cases.caseType IN (0,11,12,13,28,29) "
            . "AND {$this->cloneTenantDB}.cases.caseStage IN (9,10,11,12,14)";

        $bind = [
            ':clientID' => (int)$this->cloneTenantID,
            ':tppID'    => (int)$this->tables['clone']['thirdPartyProfile']->id,//$this->record->id,
        ];

        $cases = $this->app->DB->fetchAssocRows($sql, $bind);

        $this->tables['clone']['cases'] = $cases;
        return $this->tables['clone']['cases'];
    }


    /**
     * Returns the subjectInfoDD records associated with a case
     *
     * @param integer $id cases.id
     *
     * @return (object) `subjectInfoDD` table record to clone
     */
    public function getSubjectInfoDD($id)
    {
        $sql  = "SELECT * FROM {$this->cloneTenantDB}.subjectInfoDD WHERE caseID = :caseID AND clientID = :clientID";

        $bind = [
            ':caseID'   => $id,
            ':clientID' => $this->cloneTenantID,
        ];

        return $this->app->DB->fetchObjectRow($sql, $bind);
    }


    /**
     * Returns an external record
     *
     * @param string $uniqueID cases.userCaseNum
     *
     * @return mixed
     */
    public function getThirdPartyProfile($uniqueID)
    {
        $sql  = "SELECT * FROM {$this->cloneTenantDB}.thirdPartyProfile\n"
        .       "WHERE clientID = :clientID AND userTpNum = :profileNumber";

        $bind = [
                ':clientID'      => (int)   $this->cloneTenantID,
                ':profileNumber' => (string)$uniqueID,
        ];

        return $this->app->DB->fetchObjectRow($sql, $bind);
    }


    /**
     * Gets the notes associated with a Third Party Profile
     * called by CloneWizardView::returnSourceDetailsTemplate
     *
     * @return mixed
     */
    public function getTpNote()
    {
        $sql  = "SELECT * FROM {$this->cloneTenantDB}.tpNote WHERE clientID = :clientID AND tpID = :tppID";
        $bind = [
            ':clientID' => $this->cloneTenantID,
            ':tppID'    => $this->tables['clone']['thirdPartyProfile']->id,
        ];

        $this->tables['clone']['tpNote'] = $this->app->DB->fetchAssocRows($sql, $bind);

        return $this->tables['clone']['tpNote'];
    }


    /**
     * Gets the tpAttach associated with a Third Party Profile
     *
     * @return mixed
     */
    public function getTpAttach()
    {
        if (!isset($this->tables['clone']['tpAttach'])) {
            try {
                $sql = "SELECT * FROM {$this->cloneTenantDB}.tpAttach WHERE clientID = :clientID AND tpID = :tppID";

                $bind = [
                    ':clientID' => (int)$this->cloneTenantID,
                    ':tppID' => (int)$this->tables['clone']['thirdPartyProfile']->id,
                ];

                $this->tables['clone']['tpAttach'] = $this->app->DB->fetchAssocRows($sql, $bind);
            } catch (\Exception $e) {
                $this->app->log->error($e->getMessage());
                return false;
            }
        }

        return $this->tables['clone']['tpAttach'];
    }


    /**
     * Get regions associated with a user
     *
     * @return array
     */
    public function getUserRegions()
    {
        $regions = [];
        foreach ((new Region($this->tenantID))->getUserRegions($this->userID) as $key => $object) {
            $regions[$object['id']] = $object['name'];
        }

        return $regions;
    }


    /**
     * Get departments associated with a user
     *
     * @return array
     */
    public function getUserDepartments()
    {
        $indexed = [];
        foreach ((new Department($this->tenantID))->getUserDepartments($this->userID) as $department) {
            $indexed[$department['id']] = $department['name'];
        }
        ksort($indexed);

        return $indexed;
    }


    /**
     * Get available company legal forms
     *
     * @return mixed
     */
    public function getCompanyLegalFormList()
    {
        try {
            $list = $this->app->DB->fetchKeyValueRows(
                "SELECT id, name FROM {$this->cloneTenantDB}.companyLegalForm WHERE clientID = :clientID",
                [
                ':clientID' => $this->cloneTenantID,
                ]
            );

            if (empty($list)) {
                $list = $this->app->DB->fetchKeyValueRows(
                    "SELECT id, name FROM {$this->cloneTenantDB}.companyLegalForm WHERE clientID = 0"
                );
            }

            $this->app->log->error($list);
            return $list;
        } catch (Exception $e) {
            $this->app->log->error($e->getMessage());
            return false;
        }
    }


    /**
     * Returns note categories for cloning notes
     *
     * @return array
     */
    public function getNotesForm()
    {
        return $this->app->DB->fetchKeyValueRows(
            "SELECT id, name FROM {$this->tenantDB}.noteCategory\n"
            .   "WHERE clientID = :clientID ORDER BY id ASC",
            [
            ':clientID'      => (int)$this->tenantID,
            ]
        );
    }


    /**
     * Get "corporate docs" 3pdocs form
     *
     * @return array
     */
    public function getCorporateDocsForm()
    {
        $records = $this->app->DB->fetchAssocRows(
            "SELECT id, name FROM {$this->tenantDB}.docCategory\n"
            .   "WHERE clientID = :clientID AND active = 1 ORDER BY id ASC",
            [
            ':clientID'      => (int)$this->tenantID,
            ]
        );

        $organizedThroughMySQLPdo = [];
        foreach ($records as $record) {
            $organizedThroughMySQLPdo[$record['id']] = $record['name'];
        }

        return [
            'docCategoryID' => $organizedThroughMySQLPdo,
        ];
    }


    /**
     * Get the categories associated with a Third Party Profile type
     *
     * @param integer $profileType thirdPartyProfile.tpType
     *
     * @return array
     */
    public function getCategories($profileType)
    {
        return (new AddProfile($this->tenantID, $this->userID))->npCategories($profileType);
    }


    /**
     * Formats case inputs for use with cloneCase
     *
     * @param array $case $this->input->sources['cases']['id']
     *
     * @return mixed
     */
    public function formatCaseInputs($case)
    {
        $regSQL = "SELECT name FROM {$this->tenantDB}.region WHERE id = :regID AND clientID = :tenantID";
        $case['regionLabel'] = $this->app->DB->fetchValue($regSQL, [
            ':regID' => (int)$case['csRg'],
            ':tenantID' => $this->tenantID
            ]);

        $depSQL = "SELECT name FROM {$this->tenantDB}.department WHERE id = :depID";
        $case['departmentLabel'] = $this->app->DB->fetchValue($depSQL, [
            ':depID' => (int)$case['csDep']
            ]);

        return $case;
    }


    /**
     * Get the profile types associated with this tenant & user
     *
     * @return array
     */
    public function getProfileTypesList()
    {
        return (new AddProfile($this->tenantID, $this->userID))->getProfileTypesList();
    }


    /**
     * Queries thirdPartyProfiles on a global search for possible profile matches
     *
     * @param string $companyName thirdPartyProfile user input name to search
     *
     * @return object formatted results of a global record search
     *
     * @throws \Lib\Legacy\Search\Helpers\SearchException
     */
    public function returnMatches($companyName)
    {
        $matches = (new Search3pData())->getRecordsGlobalSearch($companyName);
        $count   = count($matches);

        if ($count > 50) {
            $matches = $this->returnExactMatches($companyName, $matches);
        }

        foreach ($matches as $key => $match) {
            $matches[$key] = [
                'id'        => $match->dbid,
                'name'      => $match->coname,
                'dbaname'   => $match->dbaname,
            ];
        }

        $returnObject          = new \stdClass();
        $returnObject->Matches = $matches;
        $returnObject->Count   = $count;
        $returnObject->TooMany = ($count > 20);

        return $returnObject;
    }


    /**
     * Compares a list of possible globally searched match hits against a given company name search string
     * returning a filtered list of exact matches for either thirdPartyProfile.legalName or thirdPartyProfile.DBAName
     *
     * @param string $companyName the new thirdPartyProfile legalName the user inputted
     * @param array  $matches     an array of globally searched partial matches for the user inputted company name
     *
     * @return array of filtered globally searched matches
     */
    private function returnExactMatches($companyName, $matches)
    {
        foreach ($matches as $key => $record) {
            if ((strtolower($companyName) == strtolower((string) $record->coname))
                || (strtolower($companyName) == strtolower((string) $record->dbaname))
            ) {
                continue;
            }
            unset($matches[$key]);
        }
        return $matches;
    }


    /**
     * Validates Tpm.Cas (The Tpp attached for case clone)
     *
     * @param integer $tppID thirdPartyProfile.id
     *
     * @return mixed
     */
    public function validateTppIDLink($tppID)
    {
        return $this->app->DB->fetchValue(
            "SELECT count(id) FROM {$this->tenantDB}.thirdPartyProfile WHERE id = :tppID",
            [
            ':tppID'    =>  $tppID,
            ]
        );
    }


    /**
     * Validate case attributes
     *
     * @param array $caseData case cols and values
     *
     * @return boolean
     */
    protected function validateCase($caseData)
    {
        $data = $this->stripBinding($caseData);
        $Case = new Cases($this->tenantID);
        return $Case->validateAttributes($data);
    }


    /**
     * Validate third party profile attributes
     *
     * @param array           $profileData Third party profile cols and values
     * @param ThirdParty|null $tppCls      Intance of ThirdParty model
     *
     * @return bool
     */
    protected function validateTPP(array $profileData, ThirdParty $tppCls = null): bool
    {
        if (!($tppCls instanceof ThirdParty)) {
            $tppCls = new ThirdParty($this->tenantID);
        }
        return $tppCls->validateAttributes($profileData);
    }

    /**
     * Removes bindings on parameters for use with validateAttributes
     *
     * @param array $bind array of column names and values
     *
     * @return array
     */
    private function stripBinding($bind)
    {
        $attributes = [];
        foreach ($bind as $k => $v) {
            $pos = strpos($k, ':');
            if ($pos !== false) {
                $key = substr_replace($k, '', $pos, strlen(':'));
                $attributes[$key] = $v;
            }
        }
        return $attributes;
    }
}
