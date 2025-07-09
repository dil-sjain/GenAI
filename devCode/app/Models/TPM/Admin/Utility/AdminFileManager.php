<?php
/**
 * Admin File Management class
 *
 * @keywords admin, file management
 */

namespace Models\TPM\Admin\Utility;

use Models\Globals\AdminFiles;
use Lib\Support\AuthDownload;

/**
 * Class AdminFileManager based on class_adminFileManager.php from Legacy code
 *
 * @keywords admin files, data files, bulk upload, bulk process
 */
class AdminFileManager extends AdminFiles
{
    /**
     * Instance of app->session
     *
     * @var object
     */
    private $sess = null;

    /**
     * Class constructor. Init class variables here.
     */
    public function __construct()
    {
        parent::__construct(); // AdminFiles
        $this->sess = \Xtra::app()->session;
    }

    /**
     * Get main path to the admin files
     *
     * @param string $subDir Subdirectory for files
     *
     * @return string
     */
    #[\Override]
    public function getAdminFilePath($subDir = '')
    {
        if ($subDir === '') {
            return $this->adminFilePath;
        }
        return $this->adminFilePath . '/' . $subDir;
    }

    /**
     * Callback for array_filter to get subdirectoreis
     *
     * @param string $path Provide by array_filter iterator
     *
     * @return bool
     */
    private function validateSubDirs($path)
    {
        // exclude reserved subdirs
        return is_dir($path) && !$this->isReserved($path);
    }

    /**
     * Get current subdirectories
     *
     * @return array List of subdirectories
     */
    public function getAdminSubDirs()
    {
        $dirs = array_filter(glob($afp = $this->adminFilePath . '/*'), $this->validateSubDirs(...));
        $return = [];
        foreach ($dirs as $path) {
            $path_array = explode('/', $path);
            $return[] = $path_array[count($path_array) - 1];
        }
        return $return;
    }

    /**
     * Get user directory for specific user id
     *
     * @param int $id Users id
     *
     * @return string User directory. uid + authUserID  (e.g. uid42)
     */
    public function getAdminUserDir($id = 0)
    {
        if ($id === 0) {
            $id = $this->authUserID;
        }
        return 'uid' . $id;
    }

    /**
     * Delete Record of langDDLnameTrans table
     *
     * @param int $rowID langDDLnameTrans.id
     *
     * @return void
     */
    public function deleteTransRec($rowID)
    {
        $sql = "DELETE FROM langDDLnameTrans WHERE id = :rowID";
        $params = [':rowID' => $rowID];
        return $this->DB->query($sql, $params);
    }

    /**
     * Update Record of langDDLnameTrans table
     *
     * @param array $posted (posted values from form)
     *
     * @return void
     */
    public function updateTransRec($posted)
    {
        $sql = "UPDATE langDDLnameTrans SET englishName = :engName, nameTranslation = :nameTrans WHERE id = :rowID";
        $params = [
            ':engName' => $posted['input0'],
            ':nameTrans' => $posted['input1'],
            ':rowID' => $posted['rowId']
        ];
        return $this->DB->query($sql, $params);
    }

    /**
     * Get list of translations from the table
     *
     * @param integer $clientID current Client ID
     *
     * @return array (id | languageCode | tableName | nameTranslation | englishName)
     */
    public function getlangDDLnameTrans($clientID)
    {
        $sql = "SELECT id, languageCode, tableName, nameTranslation, englishName FROM langDDLnameTrans
        WHERE clientID = :clientID";
        $params = [':clientID' => $clientID];
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Get translation row from the table
     *
     * @param integer $id langDDLnameTrans.id
     *
     * @return array (id | languageCode | tableName | nameTranslation | englishName)
     */
    public function getlangDDLnameTransRow($id)
    {
        $sql = "SELECT id, languageCode, tableName, nameTranslation, englishName FROM langDDLnameTrans
        WHERE id = :rowID";
        $params = [':rowID' => $id];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Get user files in admin upload area
     *
     * @param string $subDir Limit results to g_adminFiles.subDirectory
     *
     * @return array (id | subdirectory | filename | dateUploaded | processed)
     */
    public function getAdminFiles($subDir = '')
    {
        $where = ['userID' => $this->authUserID];
        if (!empty($subDir)) {
            $where['subdirectory'] = $subDir;
        }
        $orderBy = 'ORDER BY subdirectory, filename';
        $files = $this->selectMultiple([], $where, $orderBy);
        $result = [];
        foreach ($files as $f) {
            if ($path = $this->getPathFromID($f['id'])) {
                $f['md5'] = md5_file($path);
                $result[] = $f;
            }
        }
        return $result;
    }

    /**
     * Store a file uploaded to tmp into the appropriate subdir, and create db entry
     *
     * @param string $tmpPath  The path where the tmp file was uploaded
     * @param string $subDir   The subdirectory where the file should be stored
     * @param string $filename The filename to store the tmp file as
     *
     * @return array (bool 'replace', 'removeOld', 'moved', 'unlinked', 'stored', string 'message', int 'fid')
     */
    public function storeUploadedFile($tmpPath, $subDir, $filename)
    {
        $returnArray = ['replace'   => false, 'removeOld' => false, 'moved'     => false, 'unlinked'  => false, 'stored'    => false, 'fid'       => 0, 'message'   => 'Unknown error.'];

        if (file_exists($tmpPath)) {
            $pathToSubDir = $this->adminFilePath . '/' . $subDir;
            $targetDir    = $pathToSubDir . '/' . $this->getAdminUserDir();
            $dest         = $targetDir . '/' . $filename;
            // Check destination in case weird filename or subdir
            if (!$this->isValidPath($dest)) {
                $returnArray['message'] = 'Invalid destination path.';
                return $returnArray;
            }

            // Check if subDir exists yet - it shouldn't but let's be sure
            if (!is_dir($targetDir)) {
                // Check if admin user dir exits
                if (!is_dir($pathToSubDir)) {
                    mkdir($pathToSubDir);
                }
                mkdir($targetDir);
            }

            // The stored values can be used for debugging/feedback
            $returnArray['replace']  = file_exists($dest); // are we replacing existing file?
            $returnArray['moved']    = copy($tmpPath, $dest);
            $returnArray['unlinked'] = unlink($tmpPath);

            // If we replaced a file, update the existing DB record
            if ($returnArray['replace']) {
                $fInfo = $this->getFileInfoByPath($filename, $subDir);
                $fid = 0;
                if ($fInfo) {
                    $returnArray['stored'] = $this->addAdminFileToDBByPath($dest, $fid, $fInfo['id']);
                } else {
                    $returnArray['stored'] = $this->addAdminFileToDBByPath($dest, $fid);
                }
            } else {
                $returnArray['stored'] = $this->addAdminFileToDBByPath($dest, $fid);
            }

            if ($returnArray['moved'] && $returnArray['stored']) {
                $returnArray['message'] = 'File uploaded successfully.';
                $returnArray['fid']     = $fid;
            }
            return $returnArray;
        }
        $returnArray['message'] = "Could not find uploaded file in filesystem.";

        return $returnArray;
    }

    /**
     * Insert a file entry in the DB
     *
     * @param string $path  The path to the file (must include at least the subdir and filename)
     * @param int    $fid   Return recID, provide means to return lastInsertID on inserts
     * @param int    $recID If non-zero, signals update of existing record.
     *
     * @return mised fasle or count of DB affected rows
     */
    private function addAdminFileToDBByPath($path, &$fid, $recID = 0)
    {
        if (!$this->isValidPath($path)) {
            return false;
        }
        $size = filesize($path);
        $mime = AuthDownload::mimeType($path);
        $pathArray = explode('/', $path);
        $elements  = count($pathArray);

        // Check user has permission
        $fileUserId = $pathArray[$elements - 2];
        if ($fileUserId !== $this->getAdminUserDir($this->authUserID)) {
            return false;
        }
        $recID = (int)$recID;

        // path string is path/subdir/uid(#id)/filename
        $filename = $pathArray[$elements - 1];
        $subdirectory = $pathArray[$elements - 3];
        $values = [
            'dateUploaded' => date('Y-m-d H:i:s'),
            'filename' => $filename,
            'subdirectory' => $subdirectory,
            'fileSize' => $size,
            'fileType' => $mime,
            'userID' => $this->authUserID,
        ];
        if ($recID) {
            $fid = $recID;
            $rtn = $this->updateByID($recID, $values);
            if ($rtn === false) {
                $fid = false;
            }
        } else {
            if ($fid = $this->insert($values)) {
                $rtn = 1;
            } else {
                $fid = false;
                $rtn = false;
            }
        }
        return $rtn;
    }

    /**
     * Rename single user file by ID
     *
     * @param int    $fileID  Database ID of file to be renamed
     * @param string $newName New name for the file
     *
     * @return bool|int
     *
     * @throws \Exception
     */
    public function renameAdminFileByID($fileID, $newName)
    {
        $path = $this->getPathFromID($fileID);
        if (strlen($path) < 1 || ! $this->isValidPath($path)) {
            throw new \Exception('Path to specified file is invalid.');
        }
        if (!file_exists($path)) {
            throw new \Exception('Unable to find specified file.');
        }

        // File exists so let's rename the physical file
        $pathInfo = pathinfo($path);
        $newPath  = $pathInfo['dirname'] . '/' . $newName;
        if ($path != $newPath && file_exists($newPath)) {
            // Do not rename to existing file in same location
            throw new \Exception('A file with that name already exists.');
        }

        $renamed = false;
        if (rename($path, $newPath)) {
            $renamed = $this->updateByID($fileID, ['filename' => $newName]);
        }
        return $renamed;
    }

    /**
     * Delete a single user file in admin upload area
     *
     * @param int $fileID Database ID of file to be deleted
     *
     * @return bool
     */
    public function deleteFileByID($fileID)
    {
        $path = $this->getPathFromID($fileID);
        if (!file_exists($path)) {
            return $this->deleteFile($path);
        }
        if (strlen($path) < 1 || !$this->isValidPath($path)) {
            return false;
        }
        return $this->deleteFile($path);
    }

    /**
     * Construct a path for a file ID
     *
     * @param int $fileID Database ID of file to find
     *
     * @return string Path to the file
     */
    public function getPathFromID($fileID)
    {
        $fileInfo = $this->getFileInfoByID($fileID);
        $path     = $this->adminFilePath
            . '/' . $fileInfo['subdirectory']
            . '/' . $this->getAdminUserDir()
            . '/' . $fileInfo['filename'];
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
            return $this->deleteAdminFileFromDBByPath($path);
        }
        if (!$this->isValidPath($path)) {
            return false;
        }

        // File exists, time to delete
        $deleted = false;
        if (@unlink($path)) {
            $deleted = $this->deleteAdminFileFromDBByPath($path);
        }

        return $deleted;
    }

    /**
     * Delete a file entry in the DB
     *
     * @param string $path The path to the file
     *
     * @return bool Return count of DB rows affected
     */
    private function deleteAdminFileFromDBByPath($path)
    {
        if (file_exists($path) && !$this->isValidPath($path)) {
            return false;
        }
        $pathArray = explode('/', $path);
        $elements  = count($pathArray);

        //  Check if user has permission
        $fileUserID = $pathArray[$elements - 2];
        if ($fileUserID !== $this->getAdminUserDir($this->authUserID)) {
            return false;
        }

        $filename = $pathArray[$elements - 1];
        $subdirectory = $pathArray[$elements - 3];
        $where = [
            'subdirectory' => $subdirectory,
            'filename' => $filename,
            'userID' => $this->authUserID,
        ];
        return $this->delete($where);
    }

    /**
     * Set processed flag on a file entry in the DB
     *
     * @param int $id g_adminFiles.id
     *
     * @return bool
     */
    public function setProcessedById($id)
    {
        $id = (int)$id;
        return $this->updateByID($id, ['processed' => 1]);
    }

    /**
     * Set processed flag on a file entry in the DB
     *
     * @param string $path  The path to the file
     * @param int    $value To set processed $value = 1, to unset $value = 0
     *
     * @return bool
     */
    public function setProcessedByPath($path, $value = 1)
    {
        if (!$this->isValidPath($path)) {
            return false;
        }

        // ANYTHING other than 1 will be treated as an unset flag
        if (intval($value) !== 1) {
            $value = 0;
        }
        $pathArray = explode('/', $path);
        $elements  = count($pathArray);

        // Check user has permission
        $fileUserID = $pathArray[$elements - 2];
        if ($fileUserID !== $this->getAdminUserDir($this->authUserID)) {
            return false;
        }
        $filename     = $pathArray[$elements - 1];
        $subdirectory = $pathArray[$elements - 3];
        $where = [
            'processed' => $value,
            'subdirectory' => $subdirectory,
            'filename' => $filename,
            'userID' => $this->authUserID,
        ];
        return $this->update();
    }

    /**
     * Get single user file DB data by ID
     *
     * @param int $fileID Database ID of file to be retrieved
     *
     * @return array
     */
    public function getFileInfoByID($fileID)
    {
        return $this->selectByID($fileID);
    }

    /**
     * Get single user file DB data by file name and subdirectory
     *
     * @param string $fileName     Name of the file
     * @param string $subDirectory Directory under the admin file storage directory
     *
     * @return array
     */
    public function getFileInfoByPath($fileName, $subDirectory)
    {
        $where = [
            'userID' => $this->authUserID,
            'filename' => $fileName,
            'subdirectory' => $subDirectory,
        ];
        return $this->selectOne([], $where);
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
    
    /**
     * Get data from Content Control tables
     *
     * @param integer $clientID  tableName.clientID
     * @param string  $tableName tableName
     *
     * @return array
     */
    public function getContentControlTableData($clientID, $tableName)
    {
        $sql = "SELECT id, name, listName FROM $tableName
        WHERE clientID = :clientID ORDER BY listName ASC";
        $params = [':clientID' => $clientID];
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Get list of sponsors' email from the table
     *
     * @param int $clientID regionCountryTrans.clientID
     * @param bool $csvData If true, return only primaryID, regionName, countryName, CountryISO, sponsorEmail
     *
     * @return array array
     *
     */
    public function getRegionCountryTrans($clientID, $csvData = false)
    {
        $selectColumns = 'id, regionID, clientID, regionName, countryName, countryISO, sponsorEmail';
        if ($csvData) {
            $selectColumns = 'id AS primaryID, regionName, countryName, CountryISO, sponsorEmail';
        }

        $sql = "SELECT " . $selectColumns . " FROM regionCountryTrans WHERE clientID = :clientID";
        $params = [':clientID' => $clientID];
        return $this->DB->fetchAssocRows($sql, $params);
    }
}
