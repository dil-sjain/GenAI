<?php
/**
 * Admin File Management class
 *
 * @keywords admin, file management
 */

namespace Models\TPM\Admin\Utility;

use Models\Globals\EmailFiles;
use Lib\Support\AuthDownload;

/**
 * Class EmailFileManager based on class_adminFileManager.php from Legacy code
 *
 * @keywords email files, data files, bulk upload, bulk process
 */
class EmailFileManager extends EmailFiles
{
    /**
     * Instance of app->session
     *
     * @var object
     */
    private $sess = null;

    /**
     * @var array supported client IDs
     */
    private $clientIDs = null;

    /**
     * @var mixed client DB
     */
    private $clientDB = null;

    /**
     * @var array Language codes
     */
    private $langCode = null;
    
    /**
     * @var array Supported header columns
     */
    private $supportedHeaderCols = [
        'System' => [
            'id',
            'clientID',
            'languageCode',
            'EMtype',
            'caseType',
            'EMsubject',
            'EMbody',
            'EMrecipient',
            'EMcc'
        ],
        'Client' => [
            'id',
            'clientID',
            'invokedBy',
            'languageCode',
            'EMtype',
            'emailDescription',
            'EMrecipient',
            'EMsubject',
            'EMbody',
            'EMfrom',
            'EMcc',
            'EMbcc',
            'bHTML'
        ]
    ];

    /**
     * Class constructor. Init class variables here.
     *
     * @param integer $clientID Client/Tenant ID.
     *
     * @return void
     */
    public function __construct(private $clientID)
    {
        parent::__construct();
        $this->sess = \Xtra::app()->session;
        $sql = "SELECT DBname FROM {$this->DB->authDB}.clientDBlist WHERE status = 'active' AND clientID = :clientID";
        $this->clientDB = $this->DB->fetchValue($sql, [':clientID' => $this->clientID]);
    }
    
    /**
     * Get user files in admin upload area
     *
     * @return array (id | subdirectory | filename | dateUploaded | processed)
     */
    public function getEmailFiles()
    {
        $result = [];
        $clientID = \Xtra::app()->session->get('clientID');
        $emailPath = $this->emailFilePath . '/' . $clientID . '/';
        $emailFiles = is_dir($emailPath) ? scandir($emailPath) : [];
        $i=1;
        foreach ($emailFiles as $file) {
            if (in_array($file, ['.','..'])) {
                continue;
            }
            $filepath = $emailPath . '/' . $file;
            $result[] = [
                'id' => $i++,
                'subdirectory' => 'email',
                'filename' => $file,
                'md5' => md5_file($filepath),
                'dateUploaded' => date("Y-m-d", filemtime($filepath)),
                'processed' => true,
            ];
        }
        return $result;
    }

    /**
     * Store a file uploaded to tmp into the appropriate subdir, and create db entry
     *
     * @param string $category System/Client
     * @param string $tmpPath  The path where the tmp file was uploaded
     * @param string $filename The filename to store the tmp file as
     *
     * @return array (bool 'replace', 'removeOld', 'moved', 'unlinked', 'stored', string 'message', int 'fid')
     */
    public function storeUploadedFile($category, $tmpPath, $filename)
    {
        $returnArray = ['replace'   => false, 'removeOld' => false, 'moved'     => false, 'unlinked'  => false, 'stored'    => false, 'fid'       => 0, 'message'   => 'Unknown error.'];

        if (file_exists($tmpPath)) {
            $tenantID = \Xtra::app()->session->get('clientID');
            $rootDir = $this->emailFilePath;
            $clientDir = $rootDir . '/' . $tenantID;
            $dest      = $clientDir . '/' . $filename;

            // Check destination in case weird filename or subdir
            if (file_exists($dest)) {
                $returnArray['error'] = 'File already exists.';
                return $returnArray;
            }
            // Check if the file exist under a different name
            foreach ($this->getEmailFiles() as $existingFile) {
                $emailType  = strtolower(substr((string) $existingFile['filename'], 0, 6));
                if (strtolower($category) === $emailType) {
                    if (md5_file($tmpPath) === $existingFile['md5']) {
                        $returnArray['error'] = 'File already exists with a different name.';
                        return $returnArray;
                    }
                }
            }

            // Check if subDir exists yet - it shouldn't but let's be sure
            if (!is_dir($rootDir)) {
                mkdir($rootDir);
            }
            if (!is_dir($clientDir)) {
                mkdir($clientDir);
            }

            // The stored values can be used for debugging/feedback
            $returnArray['replace']  = file_exists($dest); // are we replacing existing file?
            if (!$returnArray['replace']) {
                $handle         = fopen($tmpPath, 'rb');
                $err            = [];
                $header         = [];
                $headerColCount = 0;
                $move           = false;
                $i = 0;
                while (($data = fgetcsv($handle)) !== false) {
                    $i++;
                    if ($i === 1) {
                        $header = $data;
                        $data = preg_replace('/[[:^print:]]/', '', $data);
                        sort($data);
                        sort($this->supportedHeaderCols[$category]);
                        if (implode(',', $data) !== implode(',', $this->supportedHeaderCols[$category])) {
                            $err[$i] = 'Invalid header';
                            break;
                        }
                        $headerColCount = count($header);
                        $this->setRequiredData();
                        continue;
                    }
                    if ($headerColCount !== count($data) && count($data) !== 0) {
                        $err[$i] = 'Mismatch in number of columns & header on row no. ' . $i;
                        continue;
                    }
                    $row = [];
                    for ($j = 0, $j_count = $headerColCount; $j < $j_count; $j++) {
                        $row[$header[$j]] = $data[$j];
                    }
                    if (($tmpErr = $this->validateEmailData($category, $i, $row)) && $tmpErr !== true) {
                        $err[$i] = $tmpErr;
                    } else {
                        $this->saveEmailDataInTable($category, $row);
                        $move = true;
                    }
                }
                fclose($handle);
                if (empty($err)) {
                    $returnArray['message'] = 'File uploaded successfully.';
                } else {
                    $returnArray['error'] = '<li>' . implode('</li><li>', $err) . '</li>';
                }
                $returnArray['moved'] = $move ? copy($tmpPath, $dest) : false;
            } else {
                $returnArray['moved'] = false;
            }
            $returnArray['unlinked'] = unlink($tmpPath);
            $returnArray['stored']   = $returnArray['moved'];
            return $returnArray;
        }
        $returnArray['message'] = "Could not find uploaded file in filesystem.";
        return $returnArray;
    }

    /**
     * Save email data from CSV row
     *
     * @param string $category System/Client
     * @param array  $row      email data.
     *
     * @return void
     */
    private function saveEmailDataInTable($category, $row)
    {
        $sql = null;
        $params = [];
        if (!empty($row['id']) && is_numeric($row['id'])) {
            $sql = 'UPDATE ';
            $params = [':id' => $row['id']];
        } else {
            $sql = 'INSERT INTO ';
        }
        switch ($category) {
            case 'System':
                $sql .= "{$this->clientDB}.systemEmails SET\n"
                    . "clientID = :clientID,\n"
                    . "languageCode = :languageCode,\n"
                    . "EMtype = :EMtype,\n"
                    . "caseType = :caseType,\n"
                    . "EMsubject = :EMsubject,\n"
                    . "EMbody = :EMbody,\n"
                    . "EMrecipient = :EMrecipient,\n"
                    . "EMcc = :EMcc\n";
                $params = array_merge(
                    $params,
                    [
                        ':clientID' => $row['clientID'],
                        ':languageCode' => $row['languageCode'],
                        ':EMtype' => $row['EMtype'],
                        ':caseType' => $row['caseType'],
                        ':EMsubject' => $row['EMsubject'],
                        ':EMbody' => $row['EMbody'],
                        ':EMrecipient' => $row['EMrecipient'],
                        ':EMcc' => $row['EMcc'],
                    ]
                );
                break;
            case 'Client':
                $sql .= "{$this->clientDB}.clientEmails SET\n"
                . "clientID = :clientID,\n"
                . "invokedBy = :invokedBy,\n"
                . "languageCode = :languageCode,\n"
                . "EMtype = :EMtype,\n"
                . "emailDescription = :emailDescription,\n"
                . "EMrecipient = :EMrecipient,\n"
                . "EMsubject = :EMsubject,\n"
                . "EMbody = :EMbody,\n"
                . "EMfrom = :EMfrom,\n"
                . "EMcc = :EMcc,\n"
                . "EMbcc = :EMbcc,\n"
                . "bHTML = :bHTML\n";
                $params = array_merge(
                    $params,
                    [
                        ':clientID' => $row['clientID'],
                        ':invokedBy' => $row['invokedBy'],
                        ':languageCode' => $row['languageCode'],
                        ':EMtype' => $row['EMtype'],
                        ':emailDescription' => $row['emailDescription'],
                        ':EMrecipient' => $row['EMrecipient'],
                        ':EMsubject' => $row['EMsubject'],
                        ':EMbody' => $row['EMbody'],
                        ':EMfrom' => $row['EMfrom'],
                        ':EMcc' => $row['EMcc'],
                        ':EMbcc' => $row['EMbcc'],
                        ':bHTML' => $row['bHTML'],
                    ]
                );
                break;
        }
        if (!empty($row['id']) && is_numeric($row['id'])) {
            $sql .= 'WHERE id = :id';
        }
        $this->DB->query($sql, $params);
    }

    /**
     * Validate email CSV row
     *
     * @param string  $category System/Client
     * @param integer $i        row number of a CSV file.
     * @param array   $row      email data.
     *
     * @return mixed
     */
    private function validateEmailData($category, $i, $row)
    {
        $rtn = true;
        if (!in_array($row['languageCode'], $this->langCode)) {
            $rtn = 'Language code '.$row['languageCode'].' does not exists on row no. ' . $i;
        } else if (!in_array($row['clientID'], $this->clientIDs)) {
            $rtn = "Invalid Client ID {$row['clientID']} on row no. $i";
        } else if (isset($row['id']) && $row['id'] > 0 && !$this->emailExist($category, $row['id'])) {
            $rtn = $category." Email with id {$row['id']} at row no. {$i} does not exist.";
        }
        return $rtn;
    }


    /**
     * Process email CSV row
     *
     * @param string  $category System/Client
     * @param integer $id       primary key of systemEmails/clientEmails
     *
     * @return boolean
     */
    private function emailExist($category, $id)
    {
        $rtn = false;
        $sql = null;
        switch ($category) {
            case 'System':
                $sql = "SELECT count(1) FROM {$this->clientDB}.systemEmails\n"
                    . "WHERE id = :id";
                break;
            case 'Client':
                $sql = "SELECT count(1) FROM {$this->clientDB}.clientEmails\n"
                    . "WHERE id = :id";
                break;
        }
        if ($sql && $this->DB->fetchValue($sql, [':id' => $id])) {
            $rtn = true;
        }
        return $rtn;
    }

    /**
     * Set client IDs who share same database.
     *
     * @return void
     */
    private function setRequiredData()
    {
        $sql = "SELECT langCode FROM {$this->clientDB}.languages";
        $this->langCode = array_column($this->DB->fetchAssocRows($sql, []), 'langCode');
        $sql = "SELECT clientID FROM {$this->DB->authDB}.clientDBlist\n"
            . "WHERE status = 'active' AND DBname = :DBname";
        $this->clientIDs = array_column($this->DB->fetchAssocRows($sql, [':DBname' => $this->clientDB]), 'clientID');
    }

    /**
     * Delete a single user file in admin upload area
     *
     * @param string $filename File name
     *
     * @return string
     */
    public function deleteFileByID($filename)
    {
        $rtn = '';
        $path = $this->getPathFromID($filename);
        $clientDirPath = $this->emailFilePath . '/' . $this->clientID . '/' . $filename;
        $path = (!file_exists($path)) ? $clientDirPath : $path;
        if (!file_exists($path)) {
            $rtn = 'File not found.';
        } elseif (!$this->isValidPath($path)) {
            $rtn = 'Invalid file path.';
        } elseif (!$this->deleteFile($path)) {
            $rtn = 'Unable to delete file.';
        }
        return $rtn;
    }

    /**
     * Construct absolute path for a file.
     *
     * @param string $filename File name
     *
     * @return string Absolute path of the file
     */
    public function getPathFromID($filename)
    {
        return $this->emailFilePath . '/' . $filename;
    }

    /**
     * Delete a file from the file system
     *
     * @param string $path Path of the file
     *
     * @return bool
     */
    public function deleteFile($path)
    {
        // File does not exist at specified path
        if (!file_exists($path)) {
            return false;
        }
        // File exists, time to delete
        if (@unlink($path)) {
            return true;
        }
        return false;
    }
}
