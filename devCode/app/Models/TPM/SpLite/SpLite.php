<?php
/**
 * Lite Service Provider provides access for an external (non-subscribing)
 * service provider to access and complete a single case
 *
 * @see      /cms/includes/php/class_list_sp.php
 * @keywords LiteSp, SpLite, service provider lite
 */

namespace Models\TPM\SpLite;

use Controllers\Notifications;
use Lib\Database\MySqlPdo;
use Lib\GlobalCaseIndex;
use Lib\Legacy\UserType;
use Lib\UpDnLoadFile;
use Lib\Legacy\CaseStage;
use Lib\Legacy\NoteCategory;
use Lib\Legacy\SysEmail;
use Lib\Support\YamlUtil;
use Lib\Support\AuthDownload;
use Lib\Traits\EmailHelpers;
use Models\LogData;
use Models\SP\ServiceProvider;
use Lib\Crypt\Crypt64;
use Models\Globals\Geography;
use Models\SP\RedFlags;

/**
 * Class to facilitate interaction with SP Lite interface
 */
#[\AllowDynamicProperties]
class SpLite
{
    use EmailHelpers;

    public const AUTH_KEY     = 'hQNqLzgX66uK0BTm';
    public const PWAUTH_HOURS = 4;

    /**
     * @var MySqlPdo|null Class instance
     */
    private $DB = null;

    /**
     * @var int Defined cases.caseStage
     */
    private $stageRejected = CaseStage::UNASSIGNED;                // 2, case rejected by investigator

    /**
     * @var int Defined cases.caseStage
     */
    private $stageApproved = CaseStage::BUDGET_APPROVED;           // 6, fixed-cost case ready for investigation

    /**
     * @var int Defined cases.caseStage
     */
    private $stageAccepted = CaseStage::ACCEPTED_BY_INVESTIGATOR;  // 7, case accepted by investigator

    /**
     * @var int Defined cases.caseStage
     */
    private $stageCompleted = CaseStage::COMPLETED_BY_INVESTIGATOR; // 8, investigation completed

    /**
     * @var string|null Client database name
     */
    private $clientDB = '';

    /**
     * @var false|object Case authorization row
     */
    private $authRow = false;

    /**
     * @var false|object Case row
     */
    private $caseRow = false;

    /**
     * @var string|null SP Lite case authorization table name
     */
    private $spLiteAuthTbl  = null;

    /**
     * @var string|null Service Provider table name
     */

    /**
     * @var string|null Name of Service Provider table
     */
    private $spTbl = null;

    /**
     * @var string|null Name of Service Provicer database
     */
    private $spDB = null;

    /**
     * @var string|null Name of user authorization database
     */
    private $authDB = null;

    /**
     * @var string Error title
     */
    private $errTitle = '';

    /**
     * @var string Error message
     */
    private $errMsg = '';

    /**
     * @var string Response title
     */
    private $resTitle = '';

    /**
     * @var string Response message
     */
    private $resMsg = '';

    /**
     * @var string XX_XX language code
     */
    private $langCode;

    /**
     * @var Geography Class instance
     */
    private $geo;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $app = \Xtra::app();
        $this->session       = $app->session;
        $this->DB            = $app->DB;
        $this->spLiteAuthTbl = $app->DB->globalDB . '.g_spLiteAuth';
        $this->spTbl         = $app->DB->spGlobalDB . '.investigatorProfile';
        $this->spDB          = $app->DB->spGlobalDB;
        $this->authDB        = $app->DB->authDB;

        $this->langCode = $this->session->languageCode ?? 'EN_US';
        $clientID = $this->session->get('splCaseData.clientID');
        if ($clientID) {
            $this->geo = Geography::getVersionInstance(null, (int)$clientID);
            $this->clientDB = $this->DB->getClientDB($clientID);
            $this->DB->setClientDB($clientID);
        } else {
            $this->geo = Geography::getVersionInstance();
        }
        $this->checkPageAccess($app->environment);
    }



    /**
     * Returns g_spLiteAuth record data for Add Case (used in converting case draft to investigation).
     *
     * @param int $tenantID TPM tenant ID
     * @param int $caseID   cases.id
     *
     * @return array
     */
    public function spLiteAuth($tenantID, $caseID)
    {
        return $this->DB->fetchAssocRow(
            "SELECT id, caseKey FROM {$this->spLiteAuthTbl} WHERE clientID = :tenantID AND caseID = :caseID",
            [
                ':tenantID' => $tenantID,
                ':caseID'   => $caseID,
            ]
        );
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
        $result = '';
        switch ($propertyName) {
            case 'authRow':
                $result = $this->authRow;
                break;
            case 'caseRow':
                $result = $this->caseRow;
                break;
            case 'clientDB':
                $result = $this->clientDB;
                break;
            case 'spLiteAuthTbl':
                $result = $this->spLiteAuthTbl;
                break;
            case 'errTitle':
                $result = $this->errTitle;
                break;
            case 'errMsg':
                $result = $this->errMsg;
                break;
            case 'resTitle':
                $result = $this->resTitle;
                break;
            case 'resMsg':
                $result = $this->resMsg;
                break;
            case 'redflagDefined':
                $redFlags = new RedFlags($this->authRow->spID, $this->authRow->clientID);
                $result = (count($redFlags->adjustedRedFlags())) ? 1 : 0;
                break;
            case 'env':
                $result = $this->_envr;
                break;
            default:
                throw new \Exception("Unknown property: `$propertyName`");
        }
        return $result;
    }

    /**
     * Validates access by caseKey.
     *
     * On success, sets ::authRow and ::caseRow objects, if appropriate
     *
     * @param string $caseKey access authorization key in URL
     *
     * @return boolean Returns true on valid caseKey. Throws Exception on failure
     *
     * @throws \Exception
     */
    public function isAuthorized($caseKey)
    {
        // double check caseKey since this is a public method.
        if (!preg_match('/^[a-f0-9]{40}$/', $caseKey)) {
            throw new \Exception('(a) Invalid access.');
        }

        $params = [':caseKey' => $caseKey];
        $authSql = "SELECT * FROM {$this->spLiteAuthTbl} WHERE caseKey = :caseKey LIMIT 1";
        if (!($authRow = $this->DB->fetchObjectRow($authSql, $params))) {
            throw new \Exception('(b) Invalid access.');
        }
        $this->authRow = $authRow;
        if ($authRow->rejected || $authRow->completed) {
            // caseRow not needed
            return true;
        }
        $caseID   = $authRow->caseID;
        $clientID = $authRow->clientID;
        $clientDB = $this->DB->getClientDB($clientID);
        $this->DB->setClientDB($clientID);

        $caseSql = "SELECT * FROM $clientDB.cases WHERE id = :caseID "
            . "AND clientID = :clientID AND caseAssignedAgent = :caseAssignedAgent "
            . "AND (caseStage = :stageApproved "
            . "OR caseStage = :stageAccepted "
            . "OR caseStage = :stageCompleted) LIMIT 1";
        $params = [
            ':caseID'           => $caseID,
            ':clientID'         => $clientID,
            ':caseAssignedAgent' => $authRow->spID,
            ':stageApproved'    => $this->stageApproved,
            ':stageAccepted'    => $this->stageAccepted,
            ':stageCompleted'   => $this->stageCompleted,
        ];
        if (!($caseRow = $this->DB->fetchObjectRow($caseSql, $params))) {
            throw new \Exception('(c) Invalid access.');
        }
        $this->caseRow = $caseRow;
        $this->clientDB = $clientDB;
        return true;
    }

    /**
     * Validates access by $_GET vars.
     *
     * Calls ::isAuthorized with caseKey
     *
     * @param string $key access authorization key in URL
     *
     * @return boolean Returns true on valid caseKey. Throws Exception on failure
     *
     * @throws \Exception
     */
    public function authByUrl($key)
    {
        // want SHA1 hash
        if (empty($key) || !preg_match('/^[a-f0-9]{40}$/', $key)) {
            throw new \Exception('(a) Invalid access.');
        }
        return $this->isAuthorized($key);
    }

    /**
     * Returns minimal subject information for top of interface.
     *
     * @return object Returns info object with the following properties
     *        -> subjectName
     *        -> location
     *        -> scopeName
     */
    public function getSubjectInfo()
    {
        $info = new \stdClass();
        $info->subjectName = $this->authRow->subjectName;
        $info->location    = '';
        $info->scopeName   = '';

        $authDB = $this->authDB;
        $sp_globaldb = $this->spDB;
        $clientDB = $this->clientDB;
        $country  = $this->authRow->country;
        $scope    = $this->authRow->scope;
        $clientID = $this->authRow->clientID;
        $spID     = $this->authRow->spID;

        if (!is_object($this->caseRow)) {
            return $info;
        }
        $countryName = html_entity_decode($this->geo->getCountryNameTranslated($country, $this->langCode));
        $stateAbbrev = $this->caseRow->caseState;
        $stateName = trim(html_entity_decode(
            $this->geo->getStateNameTranslated($country, $stateAbbrev, $this->langCode)
        ));
        $info->location  = $stateName . $countryName;

        $productSql = "SELECT product FROM $clientDB.clientSpProductMap WHERE clientID = :clientID "
            . "AND scope = :scope AND spID = :spID";
        $params = [':clientID' => $clientID, ':scope' => $scope, ':spID' => $spID];
        $spProductID = $this->DB->fetchValue($productSql, $params);

        $scopeSql = "SELECT name FROM $sp_globaldb.spProduct WHERE id = :productID "
            . "AND spID = :spID AND serviceType = 'due_diligence'";
        $params = [':productID' => $spProductID, ':spID' => $spID];
        $info->scopeName = $this->DB->fetchValue($scopeSql, $params);
        return $info;
    }

    /**
     * Notify requester. Temporarily overrides (and restores) some session variables.
     *
     * @param integer $emType   Constant identifying email type
     * @param string  $fileName File name of an uploaded doc
     * @param string  $fileDesc File description for uploaded doc
     *
     * @return void
     */
    private function notifyRequester($emType, $fileName = '', $fileDesc = '')
    {
        $emTypes = [SysEmail::EMAIL_ACCEPTED_BY_INVESTIGATOR, SysEmail::EMAIL_UNASSIGNED_NOTICE, SysEmail::EMAIL_DOCUPLOAD_BY_INVESTIGATOR, SysEmail::EMAIL_COMPLETED_BY_INVESTIGATOR];
        if (!in_array($emType, $emTypes)) {
            return;
        }

        // Get caseTypeList
        $sql = "SELECT id, name FROM caseType WHERE investigationType = 'due_diligence' ORDER BY id ASC";
        $ctRows = $this->DB->fetchKeyValueRows($sql);
        $sql = "SELECT caseTypeID, name FROM caseTypeClient "
            . "WHERE clientID = :clientID "
            . "AND investigationType = 'due_diligence' "
            . "ORDER BY id ASC";
        $params = [':clientID' => $this->authRow->clientID ];
        if ($ctClientRows = $this->DB->fetchKeyValueRows($sql, $params)) {
            foreach ($ctClientRows as $k => $v) {
                $ctRows[$k] = $v;
            }
        }

        $sql = "SELECT userEmail, userName, BNincomingEMail FROM {$this->authDB}.users"
            . " WHERE userid = :userid LIMIT 1";
        $params = [':userid' => $this->DB->esc($this->caseRow->requestor)];
        $client = $this->DB->fetchObjectRow($sql, $params);

        $sql = "SELECT investigatorName FROM $this->spTbl"
            . " WHERE id = :id LIMIT 1";
        $params = [':id' => intval($this->authRow->spID)];
        $spCompany = $this->DB->fetchValue($sql, $params);

        $notify = new Notifications($this->authRow->clientID);

        if (!($clientEmail = $notify->getClientEmail($emType, 'EN_US'))) {
            $clientEmail = $notify->getEmptyClientEmail($emType);
            $clientEmail = $this->defaultNotice($emType, $clientEmail);
        }

        $caseType = !empty($ctRows[$this->caseRow->caseType]) ? $ctRows[$this->caseRow->caseType] : '';

        $tokens = [
            '{toName}'   => $client->userName,                   // Client's user name
            '{toEmail}'  => $client->userEmail,                  // Client's user email
            '{iCompany}' => $spCompany,                          // Investigating Co. (ie; Ernst and Young)
            '{iName}'    => $this->authRow->iName,              // Investigator Name (ie; John Smith)
            '{caseNum}'  => $this->caseRow->userCaseNum,        // Case Number       (ie; AG01-1005)
            '{caseName}' => $this->caseRow->caseName,           // Case Name         (ie; TL Co., Ltd.)
            '{caseType}' => $caseType,                           // Case Type         (ie; Open Source Investigation)
            '{reason}'   => $this->authRow->note,               // Case Note         (ie; Case rejected because...)
            '{fileName}' => $fileName,                           // File Name         (for uploaded doc)
            '{fileDesc}' => $fileDesc,                           // File Description  (for uploaded doc)
            '{link}'     => $notify->buildLinkUrl(
                $this->authRow->caseID,
                $this->authRow->clientID,
                true,
                $clientEmail['bHTML']
            ),
        ];

        $clientEmail['EMbody']    = str_replace(array_keys($tokens), array_values($tokens), (string) $clientEmail['EMbody']);
        $clientEmail['EMsubject'] = str_replace(array_keys($tokens), array_values($tokens), (string) $clientEmail['EMsubject']);

        $content = ['subj' => $clientEmail['EMsubject'], 'msg' => $clientEmail['EMbody']];
        $address = ['to' => $client->userEmail, 'cc' => explode(',', (string) $client->BNincomingEMail)];

        $notify->sendNotification($content, $address, $emType, $clientEmail['id'], true, $clientEmail['bHTML']);
    }

    /**
     * Finishes the file upload process.
     *
     * @param array $upload Contains info about uploaded file.
     *
     * @return array result containing status/error information,
     */
    public function docFinishUpload($upload)
    {
        $uploadFileCls = new UpDnLoadFile(['type' => 'upLoad']);

        $err = [];
        $caseKey = $upload['callbackParams']['caseKey'];

        try {
            $this->isAuthorized($caseKey);
            $caseID = $this->authRow->caseID;
            $cid = $this->authRow->clientID;
            $clientDB = $this->clientDB;
        } catch (\Exception $e) {
            $err[] = $e->getMessage();
        }

        $uploadFile = $upload['uploadFile'];
        $fileSize = filesize($uploadFile);
        // validate file type here
        $filename = $upload['filename'];
        $category = $upload['category'];
        $describe = $upload['description'];
        $contents   = file_get_contents($uploadFile);
        $validUpload = $uploadFileCls->validateUpload($filename, $uploadFile, 'spnl');
        if ($validUpload !== true) {
            if (file_exists($uploadFile)) {
                unlink($uploadFile);
            }
            $err[] = $validUpload;
        } else {
            $fileType = AuthDownload::mimeType($uploadFile);
            // insert new document
            $params = [':caseID' => $caseID, ':descr' => $describe, ':fname' => $filename,
                ':ftype' => $fileType, ':fSize' => $fileSize, ':contents' => $contents,
                ':catID' => $category];
            $sql = "INSERT INTO $clientDB.iAttachments "
                . "( caseID, description, filename, fileType, fileSize, contents, ownerID, sp_catID ) "
                . "VALUES "
                . "(:caseID, :descr, :fname, :ftype, :fSize, :contents, 'to-be-supplied', :catID)";
            ignore_user_abort(true);
            $result = $this->DB->query($sql, $params);
            if ($result->rowCount()) {
                // store in filesystem, or delete db record on failure
                $recID = $this->DB->lastInsertId();
                if ($uploadFileCls->moveUploadedClientFile($uploadFile, 'iAttachments', $cid, $recID)) {
                    // log and notify
                    if ($error = $this->docUploaded($filename, $describe)) {
                        $err[] = $error;
                    }
                } else {
                    // delete uploaded file
                    if (file_exists($uploadFile)) {
                        unlink($uploadFile);
                    }
                    $params = [':id' => $recID];
                    $sql = "DELETE FROM $clientDB.iAttachments WHERE id = :id LIMIT 1";
                    $this->DB->query($sql, $params);
                    $err[] = 'Failed storing upload in filesystem.';
                }
            }
        }
        return $err;
    }

    /**
     * Log and notify on doc upload
     *
     * @param string $filename Filename of uploaded file
     * @param string $describe Description of uploaded file
     *
     * @return string Return either an error string or null
     */
    public function docUploaded($filename, $describe)
    {
        if (!is_object($this->authRow) || !is_object($this->caseRow)) {
            return 'Invalid case.';
        }
        $logMsg = "`$filename` added to case folder by investigator, description: `$describe`";

        $logData = new LogData($this->authRow->clientID, -1, UserType::VENDOR_USER);
        $logData->saveLogEntry(27, $logMsg, $this->authRow->caseID, 0, $this->authRow->clientID);

        $this->notifyRequester(SysEmail::EMAIL_DOCUPLOAD_BY_INVESTIGATOR, $filename, $describe);
        return null;
    }

    /**
     * Changes case stage to Rejected by Investigator  and notifies requester
     *
     * @return boolean Success or failure
     */
    public function rejectCase()
    {
        $key = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'ky');
        $this->authenticate($key);

        $rtn = false;
        if (!is_object($this->authRow)
            || !is_object($this->caseRow)
            || $this->caseRow->caseStage != $this->stageApproved
        ) {
            return $rtn;
        }

        $logData = new LogData($this->authRow->clientID, -1, UserType::VENDOR_USER);

        $this->updateAuthValues(__METHOD__);
        // change case stage
        $clientID = $this->authRow->clientID;
        $caseID   = $this->authRow->caseID;
        $clientDB = $this->clientDB;
        $oldStage = $this->caseRow->caseStage;
        $newStage = $this->stageRejected;
        $caseSql  = "UPDATE $clientDB.cases SET caseStage = :stageRejected "
                  . "WHERE id = :caseID AND clientID = :clientID "
                  . "AND caseStage = :stageApproved LIMIT 1";
        $params = [':stageRejected' => $this->stageRejected,
            ':caseID' => $caseID,
            ':clientID' => $clientID,
            ':stageApproved' => $this->stageApproved
            ];
        $result = $this->DB->query($caseSql, $params);
        if ($rtn = $result->rowCount()) {
            // sync global index
            $globalIdx = new GlobalCaseIndex($clientID);
            $globalIdx->syncByCaseData($caseID);

            $this->updateAuthTimestamp('rejected');
            $stageNames = $this->DB->fetchKeyValueRows(
                "SELECT id, name FROM $clientDB.caseStage WHERE id IN($oldStage, $newStage)"
            );
            $logMsg = 'stage: `' . $stageNames[$oldStage]
                . '` => `' . $stageNames[$newStage] . '`';
            $logData->saveLogEntry(77, $logMsg, $this->authRow->caseID, 0, $this->authRow->clientID);

            // create note category if needed
            $cat = NoteCategory::SPLITE_NOTE_CAT;
            $noteCatTbl = $clientDB . '.noteCategory';
            $sql = "SELECT id FROM $noteCatTbl WHERE id = :category LIMIT 1";
            $params = [':category' => $cat];
            if ($this->DB->fetchValue($sql, $params) != $cat) {
                $noteSql = "INSERT INTO $noteCatTbl SET id = :category, name = 'Investigator Comments'";
                $params = [':category' => $cat];
                $this->DB->query($noteSql, $params);
            }
            // save reject reason as case note
            $subj   = 'Case Rejection by ' . $this->DB->esc($this->authRow->iName);
            $e_note = $this->authRow->note; // already escaped
            $caseNoteSql = "INSERT INTO $clientDB.caseNote SET "
                . "noteCatID = :category, "
                . "clientID  = :clientID, "
                . "caseID    = :caseID, "
                . "subject   = :subject, "
                . "note      = :note, "
                . "ownerID   = '-1', " // n/a
                . "bInvestigator = '0', "
                . "bInvestigatorCanSee = '1', "
                . "created = NOW()";
            $params = [':category' => $cat,
                ':clientID' => $clientID,
                ':caseID'   => $caseID,
                ':subject'  => $subj,
                ':note'     => $e_note
            ];
            $this->DB->query($caseNoteSql, $params);
            if ($result->rowCount() == 1) {
                $logData->saveLogEntry(29, 'Subject: ' . $subj, $caseID);
            }

            $this->notifyRequester(SysEmail::EMAIL_UNASSIGNED_NOTICE);
        }
        return $rtn;
    }

    /**
     * Changes case stage to Accepted by Investigator and sends case details
     *
     * @return boolean Success or failure
     */
    public function acceptCase()
    {
        $rtn = false;
        if (!is_object($this->authRow)
            || !is_object($this->caseRow)
            || $this->caseRow->caseStage != $this->stageApproved
        ) {
            return $rtn;
        }

        $logData = new LogData($this->authRow->clientID, -1, UserType::VENDOR_USER);

        // change case stage
        $clientID = $this->authRow->clientID;
        $caseID   = $this->authRow->caseID;
        $clientDB = $this->clientDB;
        $oldStage = $this->caseRow->caseStage;
        $newStage = $this->stageAccepted;
        $caseSql  = "UPDATE $clientDB.cases SET caseStage = :stageAccepted, "
                  . "caseAcceptedByInvestigator = CURRENT_TIMESTAMP "
                  . "WHERE id = :caseID AND clientID = :clientID "
                  . "AND caseStage = :stageApproved LIMIT 1";
        $params = [':stageAccepted' => $this->stageAccepted,
            ':caseID'        => $caseID,
            ':clientID'      => $clientID,
            ':stageApproved' => $this->stageApproved
            ];
        $result = $this->DB->query($caseSql, $params);
        if ($rtn = $result->rowCount()) {
            // sync global index
            $globalIdx = new GlobalCaseIndex($clientID);
            $globalIdx->syncByCaseData($caseID);

            $this->updateAuthTimestamp('accepted');
            $stageNames = $this->DB->fetchKeyValueRows(
                "SELECT id, name FROM $clientDB.caseStage WHERE id IN($oldStage, $newStage)"
            );
            $logMsg = 'stage: `' . $stageNames[$oldStage]
                . '` => `' . $stageNames[$newStage] . '`';
            $logData->saveLogEntry(77, $logMsg, $this->authRow->caseID, 0, $this->authRow->clientID);
            // notify requester
            $this->notifyRequester(SysEmail::EMAIL_ACCEPTED_BY_INVESTIGATOR);
        }
        return $rtn;
    }

    /**
     * Changes case stage to Completed by Investigator, records Red Flags,
     * and notifies requester
     *
     * @return boolean Success or failure
     */
    public function submitCase()
    {
        $key = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'ky');
        $this->authenticate($key);

        $logData = new LogData($this->authRow->clientID, -1, UserType::VENDOR_USER);

        $rtn = false;
        $this->updateAuthValues(__METHOD__);
        // change case stage
        $clientID = $this->authRow->clientID;
        $spID     = $this->authRow->spID;
        $caseID   = $this->authRow->caseID;
        $clientDB = $this->clientDB;
        $oldStage = $this->caseRow->caseStage;
        $newStage = $this->stageCompleted;
        $e_investigatorID = $this->session->get('authUserID');
        $caseSql  = "UPDATE $clientDB.cases SET caseStage = :stageCompleted, "
                  . "caseCompletedByInvestigator = CURRENT_TIMESTAMP, "
                  . "caseCompletedByInvestigatorID = :investigatorID "
                  . "WHERE id = :caseID AND clientID = :clientID "
                  . "AND caseStage = :stageAccepted LIMIT 1";
        $params = [':stageCompleted' => $this->stageCompleted,
            ':investigatorID' => $e_investigatorID,
            ':caseID' => $caseID,
            ':clientID' => $clientID,
            ':stageAccepted' => $this->stageAccepted
        ];
        $result = $this->DB->query($caseSql, $params);
        if ($rtn = $result->rowCount()) {
            // sync global index
            $globalIdx = new GlobalCaseIndex($clientID);
            $globalIdx->syncByCaseData($caseID);

            $this->updateAuthTimestamp('completed');

            // set legacy red flags indicator
            $icRedFlags = ($this->authRow->redFlags) ? 'Yes' : 'No';
            $icName     = $this->authRow->iName;
            $icTbl      = $clientDB . '.iCompleteInfo';
            $icSetFlds  = "reportingIname = :reportingIname, redFlags = :redFlags";
            $icLookSql  = "SELECT id FROM $icTbl WHERE caseID = :caseID LIMIT 1";
            $params = [':caseID' => $caseID];
            if ($id = $this->DB->fetchValue($icLookSql, $params)) {
                $sql = "UPDATE $icTbl SET $icSetFlds WHERE id = :id "
                    . "AND caseID = :caseID LIMIT 1";
                $params = [':reportingIname' => $icName,
                    ':redFlags' => $icRedFlags,
                    ':id'       => $id,
                    ':caseID'   => $caseID
                ];
            } else {
                $sql = "INSERT INTO $icTbl SET id = NULL, caseID = :caseID, $icSetFlds";
                $params = [':caseID'  => $caseID,
                    ':reportingIname' => $icName,
                    ':redFlags'       => $icRedFlags
                ];
            }
            $this->DB->query($sql, $params);

            // get red flag lookup for logging
            $redFlags = new RedFlags($spID, $clientID);
            $rfLookup = $redFlags->adjustedRedFlagLookup();
            // get red flags already marked
            $rfcTbl = $clientDB . '.redFlagCase';
            $sql = "SELECT DISTINCT redFlagID FROM $rfcTbl WHERE caseID = :caseID "
                . "AND clientID = :clientID AND spID = :spID ORDER BY redFlagID ASC";
            $params = [':caseID' => $caseID, ':clientID' => $clientID, ':spID' => $spID];
            $rfCurrent = $this->DB->fetchValueArray($sql, $params);
            // update client-defined red flags
            $rfIDs = [];
            // Only add red flags if redFlags is Yes (equals 1)
            if ($this->authRow->redFlags && $this->authRow->redFlagList) {
                // Add new ones and log a duplicate/confirmation
                $pat = '/^\d+$/';
                $pat2 = '/^rfid_\d+$/';
                $rfIDs = explode(',', (string) $this->authRow->redFlagList);
                foreach ($rfIDs as $rf) {
                    if (strpos($rf, ':')) {
                        [$rfid, $howMany] = explode(':', $rf);
                        if (!preg_match($pat, $howMany)) {
                            $howMany = 1;
                        }
                        if (preg_match($pat2, $rfid)) {
                            $rfid = substr($rfid, 5);
                        }
                    } else {
                        $rfid = $rf;
                        $howMany = 1;
                    }
                    if (!preg_match($pat, $rfid)) {
                        continue; // bad rfid
                    }
                    if (in_array($rfid, $rfCurrent)) {
                        // confirm red flag already marked
                        $sql = "UPDATE $rfcTbl SET howMany = :howMany "
                            . "WHERE caseID = :caseID AND clientID  = :clientID "
                            . "AND redFlagID = :redFlagID AND spID = :spID LIMIT 1";
                        $params = [
                            ':howMany'   => $howMany,
                            ':caseID'    => $caseID,
                            ':clientID'  => $clientID,
                            ':redFlagID' => $rfid,
                            ':spID'      => $spID
                        ];
                        if ($this->DB->query($sql, $params)) {
                            $logData->saveLogEntry(
                                107,
                                $rfLookup[$rfid],
                                $this->authRow->caseID,
                                0,
                                $this->authRow->clientID
                            );
                        }
                    } else {
                        // insert red flag
                        $sql = "INSERT INTO $rfcTbl SET "
                            . "clientID  = :clientID, "
                            . "caseID    = :caseID, "
                            . "spID      = :spID, "
                            . "howMany   = :howMany, "
                            . "redFlagID = :redFlagID";
                        $params = [
                            ':clientID'  => $clientID,
                            ':caseID'    => $caseID,
                            ':spID'      => $spID,
                            ':howMany'   => $howMany,
                            ':redFlagID' => $rfid
                        ];
                        if ($this->DB->query($sql, $params)) {
                            $logData->saveLogEntry(
                                105,
                                $rfLookup[$rfid],
                                $this->authRow->caseID,
                                0,
                                $this->authRow->clientID
                            );
                        }
                    }
                }
            }
            // remove red flags not selected in this submission
            foreach ($rfCurrent as $rf) {
                if (!in_array($rf, $rfIDs)) {
                    // remove red flag
                    $sql = "DELETE FROM $rfcTbl WHERE caseID = :caseID AND spID = :spID "
                        . "AND redFlagID = :redFlagID AND clientID = :clientID";
                    $params = [
                        ':caseID'    => $caseID,
                        ':spID'      => $spID,
                        ':redFlagID' => $rf,
                        ':clientID'  => $clientID
                    ];
                    if ($this->DB->query($sql, $params)) {
                        $logData->saveLogEntry(
                            106,
                            $rfLookup[$rf],
                            $this->authRow->caseID,
                            0,
                            $this->authRow->clientID
                        );
                    }
                }
            }

            // log case submission
            $stageNames = $this->DB->fetchKeyValueRows(
                "SELECT id, name FROM $clientDB.caseStage WHERE id IN($oldStage, $newStage)"
            );
            $logMsg = 'stage: `' . $stageNames[$oldStage]
                . '` => `' . $stageNames[$newStage] . '`';
            $logData->saveLogEntry(77, $logMsg, $this->authRow->caseID, 0, $this->authRow->clientID);

            // create note category if needed
            $cat = NoteCategory::SPLITE_NOTE_CAT;
            $noteCatTbl = $clientDB . '.noteCategory';
            $sql = "SELECT id FROM $noteCatTbl WHERE id = :category LIMIT 1";
            $params = [':category' => $cat];
            if ($this->DB->fetchValue($sql, $params) != $cat) {
                $noteSql = "INSERT INTO $noteCatTbl SET id = :category, name = 'Investigator Comments'";
                $params = [':category' => $cat];
                $this->DB->query($noteSql, $params);
            }
            // save comments as case note
            $subj   = 'Comments from ' . $this->DB->esc($this->authRow->iName);
            $e_note = $this->authRow->note; // already escaped
            $caseNoteSql = "INSERT INTO $clientDB.caseNote SET "
                . "noteCatID = :noteCatID, "
                . "clientID  = :clientID, "
                . "caseID    = :caseID, "
                . "subject   = :subject, "
                . "note      = :note, "
                . "ownerID   = '-1', " // n/a
                . "bInvestigator = '0', "
                . "bInvestigatorCanSee = '1', "
                . "created = NOW()";
            $params = [
                ':noteCatID' => $cat,
                ':clientID'  => $clientID,
                ':caseID'    => $caseID,
                ':subject'   => $subj,
                ':note'      => $e_note
            ];
            $result = $this->DB->query($caseNoteSql, $params);
            if ($result->rowCount() == 1) {
                $logData->saveLogEntry(29, 'Subject: ' . $subj, $caseID);
            }

            $this->notifyRequester(SysEmail::EMAIL_COMPLETED_BY_INVESTIGATOR);
        }
        return $rtn;
    }

    /**
     * Updates auth record with values from the form
     *
     * @return boolean Status of update
     */
    public function saveInProgress()
    {
        $key = \Xtra::arrayGet(\Xtra::app()->clean_POST, 'ky');

        $this->authenticate($key);
        $updated = $this->updateAuthValues(__METHOD__);
        if ($updated || !empty($this->resTitle)) {
            return true;
        }

        return false;
    }

    /**
     * Updates auth record with values from interface
     *
     * @param string $caller rejectCase, submitCase, or saveInProgress.
     *
     * @return boolean True if changed, false if not, throws exception on error
     *
     * @throws \Exception Throws exception if caller is not found
     */
    private function updateAuthValues($caller)
    {
        $this->resTitle = '';
        $this->resMsg   = '';
        $rtn = false;
        $flds = ['co', 'nm', 'ph', 'em', 'nt', 'rfl'];
        switch ($caller) {
            case 'Models\TPM\SpLite\SpLite::submitCase':
                $resultTitle = 'Submit Completed Case';
                break;
            case 'Models\TPM\SpLite\SpLite::saveInProgress':
                $resultTitle = 'Save - In Progress';
                break;
            case 'Models\TPM\SpLite\SpLite::rejectCase':
                $flds = ['co', 'nm', 'ph', 'em', 'nt'];
                $resultTitle = 'Reject Case';
                break;
            default:
                $this->errTitle = 'Invalid Call';
                $this->errMsg   = '(a) Unknown origin.';
                throw new \Exception('(a) Unknown origin.');
        }
        $vals = $this->validateAuthValues($caller);
        foreach ($flds as $k) {
            $vn = 'e_' . $k;
            ${$vn} = $vals->$k;
        }
        $caseKey = $this->authRow->caseKey;
        if ($caller != 'Models\TPM\SpLite\SpLite::rejectCase') {
            $sql = "UPDATE {$this->spLiteAuthTbl} SET "
                 . "iCompany    = :iCompany, "
                 . "iName       = :iName, "
                 . "iPhone      = :iPhone, "
                 . "iEmail      = :iEmail, "
                 . "redFlags    = :redFlags, "
                 . "redFlagList = :redFlagList, "
                 . "note        = :note "
                 . "WHERE caseKey = :caseKey LIMIT 1";
            $params = [
                ':iCompany'    => $e_co,
                ':iName'       => $e_nm,
                ':iPhone'      => $e_ph,
                ':iEmail'      => $e_em,
                ':redFlags'    => $vals->rf,
                ':redFlagList' => $vals->rfl,
                ':note'        => $e_nt,
                ':caseKey'     => $caseKey
            ];
        } else {
            $sql = "UPDATE {$this->spLiteAuthTbl} SET "
                . "iCompany    = :iCompany, "
                . "iName       = :iName, "
                . "iPhone      = :iPhone, "
                . "iEmail      = :iEmail, "
                . "note        = :note "
                . "WHERE caseKey = :caseKey LIMIT 1";
            $params = [
                ':iCompany'    => $e_co,
                ':iName'       => $e_nm,
                ':iPhone'      => $e_ph,
                ':iEmail'      => $e_em,
                ':note'        => $e_nt,
                ':caseKey'     => $caseKey
            ];
        }
        if ($result = $this->DB->query($sql, $params)) {
            if ($result->rowCount()) {
                if ($caller != 'Models\TPM\SpLite\SpLite::saveInProgress') {
                    // used during submit
                    $this->authRow->iName = $vals->nm;
                    $this->authRow->note  = $e_nt; // retain escaped value
                    if ($caller != 'Models\TPM\SpLite\SpLite::rejectCase') {
                        $this->authRow->redFlags    = $vals->rf;
                        $this->authRow->redFlagList = $vals->rfl;
                    }
                }
                $this->resTitle = $resultTitle;
                $this->resMsg   = 'Your changes were saved.';
                $rtn = true;
            } else {
                $this->resTitle = $resultTitle;
                $this->resMsg   = 'Nothing changed.';
            }
        } else {
            $this->errTitle = 'Operation Failed';
            $this->errMsg = 'Your changes were NOT saved.';
            throw new \Exception('Database error');
        }
        return $rtn;
    }


    /**
     * Validate POSTed form fields
     *
     * @param string $caller Calling method name
     *
     * @return object On success returns inputs as object. Otherwise, sets ::errTitle
     *                and ::errMsg and throws exception
     *
     * @throws \Exception Throws exception if not validated
     */
    private function validateAuthValues($caller)
    {
        // clear error
        $this->errTitle = '';
        $this->errMsg   = '';

        if (!is_object($this->authRow) || !is_object($this->caseRow)) {
            $this->errTitle = 'Operation Failed';
            $this->errMsg   = 'Not authenticated';
            throw new \Exception('Validation failed');
        }

        $post = [ 'co' => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'co'),
            'nm'  => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'nm'),
            'ph'  => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'ph'),
            'em'  => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'em'),
            'rf'  => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'rf'),
            'nt'  => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'nt'),
            'rfl' => \Xtra::arrayGet(\Xtra::app()->clean_POST, 'rfl')
        ];

        if ($caller != 'Models\TPM\SpLite\SpLite::rejectCase') {
            $haveAllFields = (!is_null($post['co'])
                && !is_null($post['nm'])
                && !is_null($post['ph'])
                && !is_null($post['em'])
                && !is_null($post['rf'])
                && !is_null($post['nt'])
                && !is_null($post['rfl'])
            );
        } else {
            $haveAllFields = (!is_null($post['co'])
                && !is_null($post['nm'])
                && !is_null($post['ph'])
                && !is_null($post['em'])
                && !is_null($post['nt'])
            );
        }
        if (!$haveAllFields) {
            $this->errTitle = 'Operation Failed';
            $this->errMsg   = 'Invalid request';
            throw new \Exception('Validation failed');
        }
        $err      = [];
        $vals     = new \stdClass();
        $vals->co = trim((string) $post['co']);
        $vals->nm = trim((string) $post['nm']);
        $vals->ph = trim((string) $post['ph']);
        $vals->em = trim((string) $post['em']);
        if ($caller != 'Models\TPM\SpLite\SpLite::rejectCase') {
            $vals->rf = ((intval($post['rf'])) ? 1 : 0 );
            $vals->rfl = ''; // default no red flag list
            $rfl = trim((string) $post['rfl']);
            if ($rfl != '') {
                if (preg_match('/^rfid_\d+:\d+(,rfid_\d+:\d+)*$/', $rfl)) {
                    $vals->rfl = $rfl;
                }
            }
        }
        // normalize line endings and constrain length of comments
        $srch = ["\r\n", "\r"];
        $rplc = ["\n", "\n"];
        $note = str_replace($srch, $rplc, trim((string) $post['nt']));
        $vals->nt = mb_substr($note, 0, 2000);

        if ($caller == 'Models\TPM\SpLite\SpLite::submitCase') {
            $vals->reports = $this->countReports();
            if ($vals->reports < 1) {
                $err[] = "At least one report must be uploaded.";
            }
        }
        if ($caller != 'Models\TPM\SpLite\SpLite::saveInProgress') {
            if (!$vals->co) {
                $err[] = 'Missing name of <b>Company</b> performing investigation.';
            }
            if (!$vals->nm) {
                $err[] = 'Missing <b>Investigator Name</b>.';
            }
            if (!$vals->ph) {
                $err[] = "Missing investigator's <b>Telephone</b> number.";
            }
            if ($caller != 'Models\TPM\SpLite\SpLite::rejectCase') {
                if ($vals->rf == 1) {
                    // make sure at least one sp-defined red flag is selected, but
                    // only if some are defined.
                    $redFlags = new RedFlags($this->authRow->spID, $this->authRow->clientID);
                    if ($hasFlags = count($redFlags->adjustedRedFlags())) {
                        if ($vals->rfl == '') {
                            $err[] = "At least one Red Flag Condition must be selected from the "
                                . "popup list of red flags when choosing 'Yes' for "
                                . "'Red Flags Identified?'. (Click 'Yes' to display the list.)";
                        }
                    }
                }
            }
        }
        if ($caller != 'Models\TPM\SpLite\SpLite::saveInProgress' && !$vals->em) {
            $err[] = "Missing investigator's <b>Email</b> address.";
        } elseif ($vals->em && !$this->validEmailPattern($vals->em)) {
            $err[] = "Investigator's <b>Email</b> address is not a valid format "
                . "for this application.";
        }

        if (count($err)) {
            // format error(s)
            // grh: probably should return array and let frontend format
            $suffix = (1 == count($err)) ? '' : 's';
            $this->errTitle = 'Input Error' . $suffix;
            $errTxt = 'The following error' . $suffix . " occurred:<ul>\n";
            foreach ($err as $e) {
                $errTxt .= '<li>' . $e . "</li>\n";
            }
            $errTxt .= "</ul>\n";
            $this->errMsg = $errTxt;
            throw new \Exception('Validation failed');
        }
        return $vals;
    }

    /**
     * Count number of upload investigator reports/files for case
     *   Includes client docs < stage Completed by Investigator
     *   Includes ddq docs
     *
     * @return integer Number of accessible attachments for this case
     */
    public function countReports()
    {
        $rtn = 0;
        if ($this->authRow && $this->caseRow) {
            $caseID    = $this->authRow->caseID;
            $clientID  = $this->authRow->clientID;
            $clientDB  = $this->clientDB;
            $stopStage = CaseStage::COMPLETED_BY_INVESTIGATOR;
            $sql = "SELECT COUNT(*) FROM $clientDB.iAttachments "
                 . "WHERE caseID = :caseID";
            $params = [':caseID' => $caseID];
            if ($rows = $this->DB->fetchValue($sql, $params)) {
                $rtn += intval($rows);
            }
            $sql = "SELECT COUNT(*) FROM $clientDB.subInfoAttach "
                 . "WHERE caseID = :caseID AND caseStage < :stopStage";
            $params = [':caseID' => $caseID, ':stopStage' => $stopStage];
            if ($rows = $this->DB->fetchValue($sql, $params)) {
                $rtn += intval($rows);
            }
            $sql = "SELECT id FROM $clientDB.ddq "
                . "WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
            $params = [':caseID' => $caseID, ':clientID' => $clientID];
            if ($ddqID = $this->DB->fetchValue($sql, $params)) {
                $sql = "SELECT COUNT(*) FROM $clientDB.ddqAttach "
                     . "WHERE ddqID = :ddqID AND clientID < :clientID";
                $params = [':ddqID' => $ddqID, ':clientID' => $clientID];
                if ($rows = $this->DB->fetchValue($sql, $params)) {
                    $rtn += intval($rows);
                }
            }
        }
        return $rtn;
    }


    /**
     * Update spLitePasswordAuth field for the specified SP record
     *
     * @param integer $spID ID of the SP record to modify
     *
     * @return array
     *
     * @throws \Exception
     */
    public function setPwAuth($spID)
    {
        if (empty($spID)) {
            throw new \Exception('Invalid arguments for setSpLitePwAuth');
        }
        $timestamp = date('Y-m-d H:i:s');
        $sql = "UPDATE {$this->spTbl} SET spLitePasswordAuth = :auth\n"
            . "WHERE id = :id AND bFullSp = 0 AND status = 'active'";
        $this->DB->query($sql, [':id' => $spID, ':auth' => $timestamp]);
        $hours = self::PWAUTH_HOURS;
        $expiredTime = strtotime("$timestamp + $hours hours");
        $expiration = date('Y-m-d H:i:s T', $expiredTime);
        return [
            'expiration' => $expiration,
            'authKey' => sha1('' . $spID . $timestamp . self::AUTH_KEY)
        ];
    }


    /**
     * Updates auth record timestamp field
     *
     * @param string $fld field to update
     *
     * @return void
     */
    private function updateAuthTimestamp($fld)
    {
        $tstamps = ['accepted', 'rejected', 'followup', 'completed'];
        if (!$this->authRow || !in_array($fld, $tstamps)) {
            return;
        }
        $caseKey  = $this->authRow->caseKey;
        $authSql = "UPDATE {$this->spLiteAuthTbl} SET $fld = CURRENT_TIMESTAMP "
                 . "WHERE caseKey = :caseKey LIMIT 1";
        $params = [':caseKey' => $caseKey];
        $this->DB->query($authSql, $params);
    }

    /**
     * Set spLitePasswordAuth back to null for all expired pw reset auth
     *
     * @todo not refactored yet.
     *
     * @return void
     */
    public function clearExpiredPwAuth()
    {
        $sql = "UPDATE $this->spTbl SET spLitePasswordAuth = NULL "
            . "WHERE bFullSp = 0 AND spLitePasswordAuth IS NOT NULL "
            . "AND DATE_ADD("
            . "spLitePasswordAuth, INTERVAL " . self::PWAUTH_HOURS . " HOUR"
            . ") < NOW()";
        $this->DB->query($sql);
    }

    // ---------- New methods added to spLite as part of the refactor follow below ----------

    /**
     * Validate the case 'key' passed in as a Url param, and validate the passcode if passed in (from the
     * login screen).
     *
     * @param string $key      Contains the case key from the Url param ky.
     * @param string $passCode Contains the password entered by the user from the login screen.
     *
     * @return boolean sets jsObj
     */
    public function authenticate($key, $passCode = '')
    {
        $authPassCode = true;
        $authUrl = $this->authByUrl($key);

        // Do not circumvent the next two conditional checks when running in the 'Development' environment
        if (!empty($passCode)) {
            $authPassCode = $this->validatePasscode($passCode, $this->authRow);
        }

        // Require SP Lite Company Passcode
        if ($this->session->get('spLiteCoPw') != $this->authRow->spID
            && !($this->authRow->rejected || $this->authRow->completed)
        ) {
            $authPassCode = false;
        }

        return($authUrl && $authPassCode);
    }

    /**
     * Checks the environment to ensure that the page is being run from our site and is also not being run
     * from the command line
     *
     * @param array $environment The environment for our page.
     *
     * @return void
     */
    private function checkPageAccess($environment)
    {
        // prevent direct access
        if (isset($environment['SCRIPT_FILENAME']) && realpath($environment['SCRIPT_FILENAME']) == __FILE__) {
            exit("No direct access");
        }

        // Mandatory _SERVER input check
        $tmp = ['PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME'];
        foreach ($tmp as $k) {
            if (isset($environment[$k])
                && !empty($environment[$k])
                && !preg_match('#^[-a-z0-9_. /]+$#i', (string) $environment[$k])
            ) {
                echo "key = " . $k . ", env = " . print_r($environment[$k], true);
                if (\Xtra::isCli()) {
                    exit("STOP!\n");
                } else {
                    exit("<html><body><h1 style=\"font-size:72px\">STOP!</h1></body></html>\n");
                }
            }
        }
    }

    /**
     * Construct default notice from dummy record
     *
     * @param integer $noticeType  System email message type to generate
     * @param array   $clientEmail Blank clientEmail record
     *
     * @return array  $clientEmail Client email array with subject and body elements added
     */
    private function defaultNotice($noticeType, $clientEmail)
    {
        $noticesText = __DIR__ . '/SpLiteNotices.yml';

        $yamlUtil = new YamlUtil();
        $notices = $yamlUtil->parseFile($noticesText);

        $noticeType = (int) $noticeType;
        $clientEmail = match ($noticeType) {
            SysEmail::EMAIL_ACCEPTED_BY_INVESTIGATOR => array_merge($clientEmail, $notices['accepted']),
            SysEmail::EMAIL_UNASSIGNED_NOTICE => array_merge($clientEmail, $notices['unassigned']),
            SysEmail::EMAIL_DOCUPLOAD_BY_INVESTIGATOR => array_merge($clientEmail, $notices['docUploaded']),
            SysEmail::EMAIL_COMPLETED_BY_INVESTIGATOR => array_merge($clientEmail, $notices['completed']),
            default => $clientEmail,
        };
        return $clientEmail;
    }

    /**
     * Delete a file reference from the requisite database table, then remove
     * the file from disk storage.
     *
     * @param integer $fileID   file record id.
     * @param integer $clientID client id.
     * @param integer $caseID   case record id.
     * @param string  $accepted indicates if case has been accepted.
     *
     * @return boolean true if file removed, false otherwise
     */
    public function deleteSpDoc($fileID, $clientID, $caseID, $accepted)
    {
        $uploadFile = new UpDnLoadFile(['type' => 'upLoad']);

        if (intval($fileID)) {
            $tbl = 'iAttachments';
            $stopTm = $accepted;
            $spDocSql = "SELECT filename, description "
                . "FROM $tbl WHERE id = :fileID AND caseID = :caseID "
                . "AND creationStamp > :creationStamp LIMIT 1";
            $params = [
                ':fileID'        => $fileID,
                ':caseID'        => $caseID,
                ':creationStamp' => $stopTm
            ];
            if ($row = $this->DB->fetchObjectRow($spDocSql, $params)) {
                $sql = "DELETE FROM $tbl WHERE id = :fileID AND caseID = :caseID LIMIT 1";
                $params = [
                    ':fileID'        => $fileID,
                    ':caseID'        => $caseID,
                ];
                $result = $this->DB->query($sql, $params);
                if ($result->rowCount()) {
                    $uploadFile->removeClientFile($tbl, $clientID, $fileID);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Generate a PDF of the case.
     *
     * @param string $key String key associated with case (passed in from the URL)
     *
     * @return object $rtn Object containing all the case PDF info or status/error information.
     *
     * @see This is a refactor of legacy splCaseDetails.sec
     */
    public function generateCasePdf($key)
    {
        $rtn = (object)['success'  => false, 'errTitle' => 'Operation Failed', 'errMsg'   => ''];

        $showErrors = false;

        if (\Xtra::app()->mode == "Development") {
            $showErrors = true;
        }

        try {
            $this->authByUrl($key);
            $authRow = $this->authRow;
            $caseRow = $this->caseRow;
            if (!$caseRow || $caseRow->caseStage != CaseStage::ACCEPTED_BY_INVESTIGATOR) {
                if ($showErrors) {
                    throw new \Exception("Wrong case stage");
                }
            }
        } catch (\Exception $e) {
            if ($showErrors) {
                $rtn->errMsg = $e->getMessage();
            }
            return $rtn;
        }

        $splPdf = new SpLitePdf($authRow->clientID);
        $spDocs = $this->getSpDocs($authRow->clientID, $authRow->spID, $authRow->caseID, date('Y-m-d H:i:s'), true);
        $rtn = $splPdf->getCasePdfInfo($authRow, $caseRow, $spDocs);

        return $rtn;
    }

    /**
     * Using the case contained in the passed in object, build up the overall case status used in the main
     * template to determine what items should/should not be displayed, etc.
     *
     * @param object $spl contains the case object
     *
     * @return array Returns various case status information.
     */
    public function getCaseStatus($spl)
    {
        $app = \Xtra::app();
        $authRow = $spl->authRow;
        $caseRow = $spl->caseRow;

        $invalidAccess    = false;
        $badAccessMsg     = 'Invalid access.';
        $spStatusMsg      = false;
        $askRejectReason  = false;
        $hideCaseInfo     = true;
        $completeReject   = false;

        $stageApproved   = $this->stageApproved;   // 6, fixed-cost case ready for investigation
        $stageAccepted   = $this->stageAccepted;   // 7, case accepted by investigator
        $stageCompleted  = $this->stageCompleted;  // 8, case completed by investigator

        $languageCode = ($this->session->has('languageCode')) ? $this->session->get('languageCode') : 'EN_US';

        if ($authRow->rejected) {
            $spStatusMsg = 'Case was rejected: ' . $authRow->rejected;
            $completeReject = true;
        } elseif ($authRow->completed || ($caseRow && $caseRow->caseStage >= $stageCompleted)) {
            $spStatusMsg = 'Case was completed: ' . $authRow->completed;
            $completeReject = true;
        } elseif ($caseRow->caseStage == $stageAccepted) {
            // delay page prep until all conditions are tested and initial actions are processed
            $hideCaseInfo = false;
        } elseif ($caseRow->caseStage == $stageApproved) {
            // Test for accept or reject link? Can be _GET or _POST
            // _POST if following company passcode authentication
            // _GET if already logged in (another case) and coming from link in email
            if (array_key_exists('a', $app->clean_GET)) {
                $accept = \Xtra::arrayGet($app->clean_GET, 'a');
            } else {
                $accept = \Xtra::arrayGet($app->clean_POST, 'a');
            }
            if (array_key_exists('r', $app->clean_GET)) {
                $reject = \Xtra::arrayGet($app->clean_GET, 'r');
            } else {
                $reject = \Xtra::arrayGet($app->clean_POST, 'r');
            }
            if (intval($accept) === 1) {
                // accept
                if ($this->acceptCase()) {
                    $spStatusMsg = 'You have accepted this case. Notice has been '
                        . 'sent to the requester.';
                    $caseRow->caseStage = $stageAccepted; // track acceptance
                } else {
                    $spStatusMsg = '<b>NOTICE:</b> An error occured '
                        . 'while attempting to accept this case.';
                }
                $hideCaseInfo = false;
            } elseif (intval($reject) === 1) {
                $askRejectReason = true;
            } else {
                $invalidAccess = true;
                $badAccessMsg  = '(d) Invalid access.'; // how did we get here?
            }
        } else {
            $invalidAccess = true;
            $badAccessMsg  = '(e) Invalid access.'; // unknown
        }

        return [
            'askRejectReason'   => $askRejectReason,
            'badAccessMsg'      => $badAccessMsg,
            'completeReject'    => $completeReject,
            'invalidAccess'     => $invalidAccess,
            'hideCaseInfo'      => $hideCaseInfo,
            'languageCode'      => $languageCode,
            'js_allRedflags'    => '{}',
            'js_redflagPick'    => '{}',
            'redflagDefined'    => 0,
            'redflagPickCnt'    => 0,
            'reportCnt'         => 0,
            'showPrintPage'     => false,
            'showFullForm'      => false,
            'spStatusMsg'       => $spStatusMsg,
            'useRedflagNumbers' => 0
        ];
    }

    /**
     * Process the download file request
     *
     * @param integer $fileId     file database row ID.
     * @param string  $fileAttach Type of file attachment subsystem.
     * @param string  $caseKey    Case key.
     * @param boolean $showErrors Flag indicating if errors should be displayed.
     *
     * @return string File name Database row containing file info.
     */
    public function getDnLoadInfo($fileId, $fileAttach, $caseKey, $showErrors)
    {
        try {
            $this->authByUrl($caseKey);
            $authRow = $this->authRow;
            $caseRow = $this->caseRow;
            if (!$caseRow || $caseRow->caseStage != CaseStage::ACCEPTED_BY_INVESTIGATOR) {
                if ($showErrors) {
                    echo 'Wrong case stage';
                    exit;
                }
            }
            $caseID = $authRow->caseID;
            $clientID = $authRow->clientID;
            $clientDB = $this->DB->getClientDB($clientID);
            $this->DB->open($clientDB); // set correct default db for clientID
        } catch (\Exception $e) {
            if ($showErrors) {
                echo $e->getMessage();
            }
            exit;
        }

        $recid = intval($fileId);
        switch ($fileAttach) {
            case 'i':
                $tbl = 'iAttachments';
                $sql = "SELECT filename, fileType, fileSize, caseID "
                . "FROM $tbl WHERE id = :id AND caseID = :caseID LIMIT 1";
                $params = [':id' => $recid, ':caseID' => $caseID];
                break;
            case 'c':
                $tbl = 'subInfoAttach';
                $sql = "SELECT filename, fileType, fileSize, caseID "
                . "FROM $tbl WHERE id = :id AND caseID = :caseID LIMIT 1";
                $params = [':id' => $recid, ':caseID' => $caseID];
                break;
            case 'd':
                $tbl = 'ddqAttach';
                $sql = "SELECT id FROM ddq WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
                $params = [':caseID' => $caseID, ':clientID' => $clientID];
                if ($ddqID = $this->DB->fetchValue($sql, $params)) {
                    $sql = "SELECT filename, fileType, fileSize, ddqID "
                    . "FROM $tbl WHERE id = :id AND ddqID = :ddqID LIMIT 1";
                    $params = [':id' => $recid, ':ddqID' => $ddqID];
                } else {
                    exit;
                }
                break;
        }

        $file = $this->DB->fetchObjectRow($sql, $params);
        $file->table = $tbl ?? '';

        return $file;
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
     * @return array Returns an array containing the total count of docs (files) found, the the details for each doc.
     */
    public function getSpDocs($clientID, $spID, $caseID, $accepted, $pdf = false)
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
            $userUnion = "LEFT JOIN {$this->authDB}.users AS u ON u.userid = a.ownerID ";

            $ddqflds_  = str_replace('u.lastName', 'u.subByName', $flds_);
            $ddqUnion  = "LEFT JOIN {$this->clientDB}.ddq AS u ON u.id = a.ddqID ";
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
        $iRows = $this->DB->fetchObjectRows($iRowsSql, $params);

        $flds = sprintf($flds_, 'Client', "'0'", 'c.name');
        $cRowsSql = "SELECT $flds FROM subInfoAttach AS a "
            . $userUnion
            . "LEFT JOIN docCategory AS c ON c.id = a.catID "
            . "WHERE a.caseID = :caseID ORDER BY $orderBy $sortDir";
        $params = [':caseID' => $caseID];
        $cRows = $this->DB->fetchObjectRows($cRowsSql, $params);

        $dRows = [];
        $sql = "SELECT id FROM ddq WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
        $params = [':caseID' => $caseID, ':clientID' => $clientID];
        if ($ddqID = $this->DB->fetchValue($sql, $params)) {
            $flds = sprintf($ddqflds_, 'DDQ', "'0'", "'Intake Form'");
            $dRowsSql = "SELECT $flds FROM ddqAttach AS a "
                . $ddqUnion
                . "WHERE ddqID = :ddqID ORDER BY $orderBy $sortDir";
            $params = [':ddqID' => $ddqID];
            $dRows = $this->DB->fetchObjectRows($dRowsSql, $params);
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
                $rows[$i]->ftype = $this->getFileTypeDescriptionByFileName($rows[$i]->fname);
                $rows[$i]->del = '&nbsp;';
            }
        }

        return ['docs' => $rows, 'total' => $cnt];
    }

    /**
     * Build up details for the case (items such as the investigator info, red flag info, etc). This information
     * is primarily used by the main template.
     *
     * @param object $spl        contains the case object.
     * @param array  $caseStatus contains basic case status.
     *
     * @return array Returns detailed case information.
     */
    public function prepareCase($spl, $caseStatus)
    {
        // prepare page for full display
        $authRow = $spl->authRow;
        $caseRow = $spl->caseRow;

        $caseStatus['commentLabel'] = '';
        if (is_object($caseRow) && ($caseRow->caseStage == $this->stageAccepted || $caseStatus['askRejectReason'])) {
            $caseStatus['hideCaseInfo'] = false;
            $caseStatus['showFullForm'] = true;
            if ($caseStatus['askRejectReason']) {
                $caseStatus['iiCompany']      = '';
                $caseStatus['iiPhone']        = '';
                $caseStatus['iiEmail']        = '';
                $caseStatus['iiComment']      = '';
                $caseStatus['iiInvestigator'] = '';
                $caseStatus['commentLabel']   = 'Reason for Rejecting Case:';
            } else {
                // if already accepted, ignore 'a' or 'r' in $_GET
                $caseStatus['showPrintPage'] = true;
                $caseStatus['reportCnt'] = $this->countReports();
                if ($authRow->redFlags) {
                    $caseStatus['rfY'] = ' checked="checked"';
                    $caseStatus['rfN'] = '';
                } else {
                    $caseStatus['rfN'] = ' checked="checked"';
                    $caseStatus['rfY'] = '';
                }
                $caseStatus['iiCompany']      = $authRow->iCompany;
                $caseStatus['iiPhone']        = $authRow->iPhone;
                $caseStatus['iiEmail']        = $authRow->iEmail;
                $caseStatus['iiComment']      = $authRow->note;
                $caseStatus['commentLabel']   = 'Comments to Requester:';
                $caseStatus['iiInvestigator'] = $authRow->iName;

                $SP = new ServiceProvider($authRow->spID);
                $caseStatus['js_allRedflags'] = '{}';
                $caseStatus['js_redflagPick'] = '{}';
                $caseStatus['redflagPickCnt'] = 0;
                $caseStatus['useRedflagNumbers'] = intval($SP->testSpOption('UseRedFlagNumbers'));

                $redFlags = new RedFlags($authRow->spID, $authRow->clientID);
                $allRedflags = $redFlags->adjustedRedFlags();
                $caseStatus['redflagDefined']  = (count($allRedflags)) ? 1 : 0;
                if ($caseStatus['redflagDefined']) {
                    // make a js version for tracking
                    $tmp = [];
                    foreach ($allRedflags as $rf) {
                        $tmp[] = '"rfid_' . $rf->redFlagID . '": "' . $rf->name . '"';
                    }
                    $caseStatus['js_allRedflags'] = '{' . join(', ', $tmp) . '}';
                }
                if ($authRow->redFlagList) {
                    $cnt = 0;
                    $tmp = explode(',', (string) $authRow->redFlagList);
                    $tmp2 = [];
                    $pat = '/^\d+$/';
                    $pat2 = '/^rfid_\d+$/';
                    foreach ($tmp as $p) {
                        if (strpos($p, ':')) {
                            [$id, $num] = explode(':', $p);
                        } else {
                            $id = $p;
                            $num = 1;
                        }
                        if (preg_match($pat, $id) && preg_match($pat, $num)) {
                            // 3:2
                            $tmp2[] = '"rfid_' . $id . '": "' . $num . '"';
                            $cnt++;
                        } elseif (preg_match($pat2, $id) && preg_match($pat, $num)) {
                            // rfid_3:2
                            $tmp2[] = '"' . $id . '": "' . $num . '"';
                            $cnt++;
                        }
                        $caseStatus['js_redflagPick'] = '{' . join(',', $tmp2) .  '}';
                        $caseStatus['redflagPickCnt'] = $cnt;
                    }
                }
            }
        }
        if (!$caseStatus['hideCaseInfo']) {
            $caseStatus['subjectInfo'] = (array) $spl->getSubjectInfo();
        }

        return $caseStatus;
    }

    /**
     * Validates that the passcode matches what is associated with the case.
     *
     * @param string $passcode Case passcode
     * @param string $authRow  Basic case information.
     *
     * @return integer Service provider ID on valid caseKey.
     */
    public function validatePasscode($passcode, $authRow)
    {
        // Validate SP Lite company passcode
        $spID = 0;
        $pc = trim($passcode);
        $hash = sha1('' . $authRow->spID . self::AUTH_KEY . $pc);
        $sql = "SELECT spLitePassword FROM {$this->spTbl} WHERE id = :spID AND bFullSp = 0 "
            . "AND status = 'active'";
        $params = [':spID' => $authRow->spID];
        if ($this->DB->fetchValue($sql, $params) == $hash) {
            $spID = $authRow->spID;
            $this->session->set('spLiteCoPw', $authRow->spID);
        }
        return (int)$spID;
    }

    /**
     * Gets an array of service provider document categories, including client-specific
     * overrides, if any.
     *
     * @param integer $spID     spDocCategoryEx.spID or spDocCategory.spID
     * @param integer $clientID spDocCategoryEx.clientID
     * @param boolean $all      if true return inactive records, too
     *
     * @todo need to move this to a more general purpose class (from funcs_clientfiles.php)
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
            . "FROM " . $this->spDB . ".spDocCategory AS c\n"
            . "LEFT JOIN " . $this->spDB . ".spDocCategoryEx AS x\n"
            . "ON (x.docCatID = c.id AND x.clientID = :clientID\n"
            . "  $xCond)\n"
            . "WHERE c.spID = :spID\n"
            . "AND (x.docCatID IS NOT NULL\n"
            . "  OR $cCond)\n"
            . "ORDER BY `name` ASC";
        $params = [':clientID' => $clientID, ':spID' => $spID];
        if (!($data = $this->DB->fetchAssocRows($sql, $params))) {
            $defName = 'General';
            $spDocSql = "INSERT INTO ' . $this->spDB . '.spDocCategory (spID,name,active) "
                . "VALUES(':spID',':defName',1)";
            $params = [':spID' => $spID,':defName' => $defName];
            if ($this->DB->query($spDocSql, $params)) {
                $id = $this->DB->insert_id();
                $data[] = ['id' => $id, 'name' => $defName];
            }
        }
        return $data;
    }

    /**
     * Returns a key-value array of extensions useful for file upload functionality.
     *
     * @param string $fileName File name to find file type description.
     *
     * @todo need to move this to a more general purpose class (from funcs_clientfiles.php)
     *
     * @return string $rtn Type of file.
     */
    public function getFileTypeDescriptionByFileName($fileName)
    {
        $fileNameArr = explode(".", strtolower($fileName));
        $fileExtension =  end($fileNameArr);
        switch ($fileExtension) {
            case 'bmp':
                $rtn = 'BMP Image';
                break;
            case 'csv':
                $rtn = 'CSV';
                break;
            case 'txt':
                $rtn = 'Text';
                break;
            case ($fileExtension == 'doc' || $fileExtension == 'docm'
            || $fileExtension == 'docx'):
                $rtn = 'Word DOC';
                break;
            case 'rtf':
                $rtn = 'RTF DOC';
                break;
            case 'gif':
                $rtn = 'GIF Image';
                break;
            case ($fileExtension == 'jpg' || $fileExtension == 'jpeg'):
                $rtn = 'JPEG Image';
                break;
            case 'odp':
                $rtn = 'OpenDocument Presentation';
                break;
            case 'ods':
                $rtn = 'OpenDocument Spreadsheet';
                break;
            case 'odt':
                $rtn = 'OpenDocument Text';
                break;
            case 'png':
                $rtn = 'PNG Image';
                break;
            case 'pdf':
                $rtn = 'PDF';
                break;
            case ($fileExtension == 'ppt' || $fileExtension == 'pptm'
            || $fileExtension == 'pptx'):
                $rtn = 'PowerPoint DOC';
                break;
            case ($fileExtension == 'tif' || $fileExtension == 'tiff'):
                $rtn = 'TIFF Image';
                break;
            case 'vcf':
                $rtn = 'VCF File';
                break;
            case ($fileExtension == 'xls' || $fileExtension == 'xlsb'
            || $fileExtension == 'xlsm' || $fileExtension == 'xlsx'):
                $rtn = 'Excel DOC';
                break;
            case 'xml':
                $rtn = 'XML DOC';
                break;
            case ($fileExtension == 'zip' || $fileExtension == 'xps'):
                $rtn = 'ZIP Archive';
                break;
            default:
                $rtn = 'File';
                break;
        }
        return $rtn;
    }

    /**
     * validates a SPGlobal.g_spLiteAuth record before insertion
     *
     * @param array $record g_spLiteAuth data to be inserted
     *
     * @refactor reviewConfirm_pt.php line 649
     *
     * @return boolean true if valid
     */
    public function validateSpLiteAuth($record)
    {
        $valid      = (is_array($record));
        $required   = [
            'spID',
            'tenantID',
            'caseID',
            'scope',
            'spProduct',
            'sentTo',
            'subjectName',
            'addlPrincipals',
            'country',
            'region',
            'cost'
        ];

        if ($valid) {
            foreach ($required as $key) {
                if (!isset($record[$key])) {
                    $valid = false;
                    break;
                }
                switch ($key) {
                    case 'spID':
                    case 'tenantID':
                    case 'caseID':
                    case 'scope':
                    case 'spProduct':
                    case 'region':
                    case 'cost':
                    case 'addlPricipals':
                        if (!is_numeric($record[$key])) {
                            $valid = false;
                        }
                        break;
                    case 'subjectName':
                    case 'sentTo':
                    case 'country':
                        if (empty($record[$key])) {
                            $valid = false;
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        return $valid;
    }

    /**
     * Inserts a SPGlobal.g_spLiteAuth record.
     *
     * @param array $record g_spLiteAuth data to be inserted
     *
     * @refactor reviewConfirm_pt.php line 649
     *
     * @return void
     *
     * @throws \Exception
     */
    public function insertSpLiteAuth($record)
    {
        $this->DB->query(implode(
            PHP_EOL,
            [
                "INSERT INTO {$this->spLiteAuthTbl} SET ",
                'subjectName = :subjectName,',
                'addlCompanies = 0,',
                'addlPrincipals = :addlPrincipals,',
                'token = :token,',
                'spID = :spID,',
                'caseID = :caseID,',
                'clientID = :clientID,',
                'scope = :scope,',
                'spProduct = :spProduct,',
                'sentTo = :sentTo,',
                'country = :country,',
                'region = :region,',
                'cost = :cost,',
                'caseKey = SHA1(CONCAT(token, spID, caseID, scope, addlCompanies,',
                'country, region, cost, clientID, CURRENT_TIMESTAMP()))',
            ]
        ), [
                ':subjectName'      =>  $record['subjectName'],
                ':addlPrincipals'   =>  $record['addlPrincipals'],
                ':token'            =>  Crypt64::randString(8),
                ':spID'             =>  $record['spID'],
                ':caseID'           =>  $record['caseID'],
                ':clientID'         =>  $record['tenantID'],
                ':scope'            =>  $record['scope'],
                ':spProduct'        =>  $record['spProduct'],
                ':sentTo'           =>  $record['sentTo'],
                ':country'          =>  $record['country'],
                ':region'           =>  $record['region'],
                ':cost'             =>  $record['cost'],
            ]);
    }
}
