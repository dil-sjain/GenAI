<?php
/**
 * subInfoAttach operations
 */

namespace Models\TPM\CaseMgt;

use Lib\UpDnLoadFile;
use Models\LogData;

/**
 * subInfoAttach operations
 */
#[\AllowDynamicProperties]
class SubInfoAttach extends \Models\BaseLite\RequireClientID
{
    /**
     * Name of the database table
     *
     * @var string
     */
    protected $tbl = 'subInfoAttach';

    /**
     * If true, set client database by clientID
     *
     * @var boolean
     */
    protected $tableInClientDB = true;

    /**
     * Map field names
     *
     * @var array
     */
    protected $fieldMap = [
        'catID' => 'categoryID',
        'filename' => 'filename',
        'description' => 'description',
    ];

    /**
     * users.id
     *
     * @var integer
     */
    protected $authUserID = null;

    /**
     * Was this instantiated by the API?
     *
     * @var boolean
     */
    protected $isAPI = false;

    public $debug = true;

    /**
     * Case constructor requires clientID
     *
     * @param integer $clientID clientProfile.id
     * @param array   $params   Configuration
     */
    public function __construct($clientID, $params = [])
    {
        parent::__construct($clientID);
        $app = \Xtra::app();
        $this->clientID = $clientID;
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : $app->session->get('authUserID');
    }

    /**
     * Get case folder documents.
     *
     * @param integer $caseID   Case record id
     *
     * @return array
     */
    public function getDocumentReferences($caseID)
    {
        $caseID = (int)$caseID;
        $rtn = [];
        if (!empty($caseID)) {
            $clientDB = $this->DB->getClientDB($this->clientID);
            $sql = "SELECT id, filename AS name FROM {$clientDB}.subInfoAttach WHERE caseID = :caseID";
            $rtn = $this->DB->fetchAssocRows($sql, [':caseID'   => $caseID]);
        }
        return $rtn;
    }


    /**
     * Store uploaded file and create a subInfoAttach record for it.
     *
     * @param integer $caseID      cases.id
     * @param integer $caseStage   cases.caseStage
     * @param array   $uplInfo     From $_FILES
     * @param integer $categoryID  docCategory.id
     * @param string  $description File upload description
     * @param integer $userID      Logging users.id
     *
     * @return mixed Array with subInfoAttach data if success, else false boolean
     */
    public function processUpload($caseID, $caseStage, $uplInfo, $categoryID, $description, $userID)
    {
        $caseID = (int)$caseID;
        $categoryID = (int)$categoryID;
        $userID = (int)$userID;
        $usersTbl = "{$this->DB->authDB}.users";
        $ownerID = $this->DB->fetchValue("SELECT userid FROM {$usersTbl} WHERE id = :id", [':id' => $userID]);
        $rtn = false;
        if (!empty($caseID) && isset($caseStage) && !empty($ownerID) && !empty($uplInfo) && !empty($description)) {
            // create subInfoAttach record
            $sets = [
                'caseID' => $caseID,
                'caseStage' => $caseStage,
                'description' => $description,
                'filename' => $uplInfo['name'],
                'fileType' => $uplInfo['type'],
                'fileSize' => $uplInfo['size'],
                'ownerID' => $ownerID,
                'catID' => $categoryID,
                'emptied' => 1
            ];
            if ($newID = $this->insert($sets)) {
                // store in file system
                $initVal = ($this->isAPI) ? 'api' : '';
                (new UpDnLoadFile($initVal))->moveUploadedClientFile(
                    $uplInfo['tmp_name'],
                    'subInfoAttach',
                    $this->clientID,
                    $newID
                );
                // log the event
                $eventID = 27; // Add Document
                $logMsg = "`" . $uplInfo['name'] . "` to case folder, description: `$description`";
                (new LogData($this->clientID, $userID))->saveLogEntry($eventID, $logMsg, $caseID);
                $cols = [
                    'id',
                    'filename AS `name`',
                    'fileType AS `type`',
                    'description',
                    'fileSize AS `size`',
                    'ownerID',
                    'catID AS `categoryID`',
                    'creationStamp AS `uploaded`',
                ];
                $rtn = $this->selectByID($newID, $cols);
            }
        }
        return $rtn;
    }

    /**
     * Update document meta data: filename (but not extension), category and description
     *
     * @param integer $caseID   cases.id
     * @param integer $fileID subInfoAttach.id
     * @param array   $inputs Can contain filename, catID, and description
     * @param integer $userID Logging users.id
     *
     * @return integer 0 = nothing change, 1 = updated
     */
    public function updateDocMeta($caseID, $fileID, $inputs, $userID)
    {
        // Control what can be updated
        $allow = ['filename', 'catID', 'description'];
        $sets = [];
        foreach ($inputs as $col => $v) {
            if (in_array($col, $allow)) {
                $sets[$col] = $v;
            }
        }
        $cmpCols = array_keys($sets);
        $recWas = $this->selectByID($fileID, $cmpCols);
        $rtn = $this->updateByID($fileID, $sets);
        if ($rtn == 1) {
            // compare before/after and log changes
            $recIs = $this->selectByID($fileID, $cmpCols);
            $diffs = [];
            foreach ($recIs as $col => $v) {
                if ($recWas[$col] != $v) {
                    $field = ($this->isAPI) ? $this->fieldMap[$col] : $col;
                    $diffs[] = $field . ": `" . $recWas[$col] . "` => `$v`";
                }
            }
            // log the event
            $auditLog = new LogData($this->clientID, $userID);
            $eventID = 143; // Update File Info
            $logMsg = "subInfoAttach record #{$fileID}: " . implode(', ', $diffs);
            $auditLog->saveLogEntry($eventID, $logMsg, $caseID);
        }
        return $rtn;
    }
}
