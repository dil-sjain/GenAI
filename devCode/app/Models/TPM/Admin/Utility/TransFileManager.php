<?php
/**
 * Admin File Management class
 *
 * @keywords admin, file management
 */

namespace Models\TPM\Admin\Utility;

use Models\Globals\TransFiles;
use Lib\Support\AuthDownload;
use Models\LogData;

/**
 * Class TransFileManager based on class_adminFileManager.php from Legacy code
 *
 * @keywords admin files, data files, bulk upload, bulk process
 */
#[\AllowDynamicProperties]
class TransFileManager extends TransFiles
{
    /**
     * Instance of app->session
     *
     * @var object
     */
    private $sess = null;

    /**
     *
     * @var int client ID
     */
    private $clientID = null;

    /**
     *
     * @var mixed client DB
     */
    private $clientDB = null;

    /**
     *
     * @var array Supported header columns
     */
    private $supportedHeaderCols = [
        'languageCode',
        'tableName',
        'primaryID',
        'nameTranslation',
        'englishName'
    ];

    /**
     *
     * @var array Custom Select List id v/s clientID mapping
     *
     */
    private $customSelectList = [];

    /**
     * Class constructor. Init class variables here.
     *
     * @param int $clientID clientDBlist.clientID
     *
     * @return string
     */
    public function __construct($clientID)
    {
        parent::__construct($clientID); // TransFiles
        $this->clientID = $clientID;
        $this->sess = \Xtra::app()->session;
        $sql = "SELECT DBname FROM {$this->DB->authDB}.clientDBlist WHERE status = 'active' AND clientID = :clientID";
        $this->clientDB = $this->DB->fetchValue($sql, [':clientID' => $clientID]);
    }

    /**
     * Get user files in admin upload area
     *
     * @return array (id | subdirectory | filename | dateUploaded | processed)
     */
    public function getTransFiles()
    {
        $result = [];
        $transPath = $this->transFilePath . '/';
        $transFiles = is_dir($transPath) ? scandir($transPath) : [];
        $i = 1;
        foreach ($transFiles as $file) {
            if (in_array($file, ['.','..'])) {
                continue;
            }
            $filepath = $transPath . '/' . $file;
            $result[] = [
                'id' => $i++,
                'subdirectory' => 'trans',
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
     * @param string $tmpPath  The path where the tmp file was uploaded
     * @param string $fileName The filename to store the tmp file as
     * @param array  $allData  Like Post Data Or Other Data
     *
     * @return array (bool 'replace', 'removeOld', 'moved', 'unlinked', 'stored', string 'message', int 'fid')
     */
    public function storeUploadedFile($tmpPath, $fileName, $allData = [])
    {
        $returnArray = ['replace'   => false, 'removeOld' => false, 'moved'     => false, 'unlinked'  => false, 'stored'    => false, 'fid'       => 0, 'message'   => 'Unknown error.'];

        if (file_exists($tmpPath)) {
            $LogData = new LogData($this->clientID, $this->sess->get('authUserID'));
            $targetDir = $this->transFilePath;
            $dest      = $targetDir . '/' . $fileName;
            // Check destination in case weird filename or subdir
            if (file_exists($dest)) {
                $returnArray['error'] = 'File already exists.';
                return $returnArray;
            }
            // Check if subDir exists yet - it shouldn't but let's be sure
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            // The stored values can be used for debugging/feedback
            $returnArray['replace']  = file_exists($dest); // are we replacing existing file?
            if (!$returnArray['replace']) {
                $err            = [];
                $fileData = file_get_contents($tmpPath);
                if (!$fileData) {
                    $returnArray['error'] = 'Failed to read file contents';
                    return $returnArray;
                }
                $encoding = mb_detect_encoding($fileData);
                if (empty($encoding) || $encoding === false) {
                    $returnArray['error'] = 'Invalid File Encoding: ' . $encoding . '. It should be UTF-8.';
                    return $returnArray;
                }
                $fileData = $this->removeUtf8Bom($fileData);
                $utf8FileData = mb_convert_encoding($fileData, 'UTF-8', $encoding);

                $fileNameArray = explode('.', $fileName);
                $newFileName = $targetDir . '/' . $fileNameArray[0] . '_utf8.' . end($fileNameArray);
                $newFileSaveData = file_put_contents($newFileName, $utf8FileData);
                if (!$newFileSaveData) {
                    $returnArray['error'] = 'Error while saving the File.';
                    return $returnArray;
                }
                $newFileData = file_get_contents($newFileName);
                $newEncoding = mb_detect_encoding($newFileData, ['UTF-8'], true);
                if (empty($newEncoding) || $newEncoding !== 'UTF-8') {
                    unlink($newFileName);
                    $returnArray['error'] = 'Invalid File Encoding : ' . $newEncoding . '. It should be UTF-8.';
                    return $returnArray;
                }
                $handle         = fopen($newFileName, 'rb');
                $header         = [];
                $headerColCount = 0;
                $move           = false;
                $i = $fileLog = 0;
                while (($data = fgetcsv($handle)) !== false) {
                    $i++;
                    if ($i === 1) {
                        $header = $data;
                        sort($data);
                        sort($this->supportedHeaderCols);
                        if (implode(',', $data) !== implode(',', $this->supportedHeaderCols)) {
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
                    if ($tmpErr = $this->validateTransData($i, $row)) {
                        $err[$i] = $tmpErr;
                    } else {
                        $fileLog++;
                        if ($fileLog === 1) {
                            // Upload Bulk File Audit log
                            $eventID = 196;
                            $fileLogMsg = 'Task : Upload/Update Drop Down List Translations; Category : '
                            . $allData['category'] . '; FileName :' . $allData['filename']
                            . '; Description : ' . $allData['description'];
                            $LogData->saveLogEntry($eventID, $fileLogMsg);
                        }
                        if ($this->saveTransDataInTable($row)) {
                            $logMsg  = 'languageCode: `' . $row['languageCode'] . '`, ';
                            $logMsg .= 'tableName: `' . $row['tableName'] . '`, ';
                            $logMsg .= 'primaryID: `' . $row['primaryID'] . '`, ';
                            $logMsg .= 'englishName: `' . $row['englishName'] . '`, ';
                            $logMsg .= 'nameTranslation: `' . $row['nameTranslation'] . '`';
                            $LogData->saveLogEntry(209, $logMsg, null, false, $this->clientID);
                            $move = true;
                        }
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
            unlink($newFileName);
            $returnArray['unlinked'] = unlink($tmpPath);
            $returnArray['stored']   = $returnArray['moved'];
            return $returnArray;
        }
        $returnArray['message'] = "Could not find uploaded file in filesystem.";
        return $returnArray;
    }

    /**
     * Remove BOM parameters from CSV
     *
     * @param string $text string.
     *
     * @return string
     */
    private function removeUtf8Bom($text)
    {
        $bom = pack('H*', 'EFBBBF');
        $text = preg_replace("/^$bom/", '', $text);
        return $text;
    }

    /**
     * Process translation CSV row
     *
     * @param array $row translation data.
     *
     * @return void
     */
    private function saveTransDataInTable($row)
    {
        $sql = "INSERT INTO {$this->clientDB}.langDDLnameTrans SET\n"
            . "clientID = :clientID,\n"
            . "languageCode = :languageCode,\n"
            . "tableName = :tableName,\n"
            . "primaryID = :primaryID,\n"
            . "nameTranslation = :nameTranslation,\n"
            . "englishName = :englishName";
        $params = [
            ':clientID'        => $this->clientID,
            ':languageCode'    => $row['languageCode'],
            ':tableName'       => $row['tableName'],
            ':primaryID'       => $row['primaryID'],
            ':nameTranslation' => $row['nameTranslation'],
            ':englishName'     => $row['englishName']
        ];
        $this->DB->query($sql, $params);
        return $this->DB->lastInsertId();
    }

    /**
     * Process translation CSV row
     *
     * @param integer $i   row number of a CSV file.
     * @param array   $row translation data.
     *
     * @return mixed
     */
    private function validateTransData($i, $row)
    {
        $err = false;
        foreach ($this->supportedHeaderCols as $cols) {
            if (empty($row[$cols])) {
                $err = 'Invalid ' . $cols . ' on row no. ' . $i;
                break;
            }
        }
        if (!$err) {
            if (!in_array($row['languageCode'], $this->langCode)) {
                $err = 'Language code ' . $row['languageCode'] . ' does not exists on row no. ' . $i;
            } elseif (!in_array($row['primaryID'], array_keys($this->customSelectList))) {
                $err = 'Primary ID ' . $row['primaryID'] . ' does not exist in the database';
            } elseif ($this->clientID != $this->customSelectList[$row['primaryID']]) {
                $err = 'Client ID and Primary ID conflict on row no. ' . $i;
            } else {
                $sql = "SELECT id FROM {$this->clientDB}.langDDLnameTrans\n"
                    . "WHERE clientID = :clientID "
                    . "AND languageCode = :languageCode "
                    . "AND tableName = :tableName "
                    . "AND primaryID = :primaryID ";
                $params = [
                    ':clientID'     => $this->clientID,
                    ':languageCode' => $row['languageCode'],
                    ':tableName'    => $row['tableName'],
                    ':primaryID'    => $row['primaryID']
                ];
                if ($this->DB->fetchValue($sql, $params)) {
                    $err = 'Details corresponding to combination of clientID, languageCode, tableName, '
                        . 'primaryID at row no. ' . $i . ' already exist.';
                }
            }
        }
        return $err;
    }

    /**
     * Process translation CSV row
     *
     * @return void
     */
    private function setRequiredData()
    {
        $sql = "SELECT langCode FROM {$this->clientDB}.languages";
        $this->langCode = array_column($this->DB->fetchAssocRows($sql, []), 'langCode');
        $sql = "SELECT id, clientID FROM {$this->clientDB}.customSelectList";
        $this->customSelectList = array_column($this->DB->fetchAssocRows($sql, []), 'clientID', 'id');
    }

    /**
     * Delete a single user file in admin upload area
     *
     * @param int $filename File name
     *
     * @return bool
     */
    public function deleteFileByID($filename)
    {
        $path = $this->getPathFromID($filename);
        if (!file_exists($path)) {
            return true;
        }
        if (strlen($path) < 1) {
            return false;
        }
        return $this->deleteFile($path);
    }

    /**
     * Construct a path for a file ID
     *
     * @param int $filename File name
     *
     * @return string Path to the file
     */
    public function getPathFromID($filename)
    {
        $path = $this->transFilePath . '/' . $filename;
        return $path;
    }

    /**
     * Delete a file from the file system and DB
     *
     * @param string $path The path to the file (must include at least the subdirectory and filename)
     *
     * @return bool Returns count of rows affected on success
     */
    public function deleteFile($path)
    {
        // File does not exist at specified path, remove from DB
        if (!file_exists($path)) {
            return true;
        }
        if (!$this->isValidPath($path)) {
            return false;
        }
        // File exists, time to delete
        $deleted = false;
        if (@unlink($path)) {
            $deleted = true;
        }

        return $deleted;
    }

    /**
     * Set a parent utility's origin and location configuration for the File Manager
     *
     * @param string $origin   Parent utility's url of origin to return to
     * @param string $location Parent utility's default option to set the Location dropdown (optional)
     *
     * @return void
     */
    public function setParentConfig($origin, $location = '')
    {
        if (!empty($origin)) {
            $this->sess->set(
                'mngDataFilesConfig',
                ['origin' => $origin, 'location' => $location]
            );
        }
    }

    /**
     * Get a parent utility's configuration component (either origin or location) for the File Manager
     *
     * @param string $component Defaults to 'origin'
     *
     * @return mixed Either string value of the session var, or else false boolean
     */
    public function getParentConfig($component = 'origin')
    {
        $components = ['origin', 'location'];
        if ($this->sess->get('mngDataFilesConfig') && in_array($component, $components)
            && isset($this->sess->get('mngDataFilesConfig')[$component])
            && !empty($this->sess->get('mngDataFilesConfig')[$component])
        ) {
            return $this->sess->get('mngDataFilesConfig')[$component];
        }
        return false;
    }

    /**
     * Clear the parent utility's configuration
     *
     * @return void
     */
    public function clearParentConfig()
    {
        $this->sess->forget('mngDataFilesConfig');
    }
}
