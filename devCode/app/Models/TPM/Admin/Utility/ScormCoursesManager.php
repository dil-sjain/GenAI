<?php
/**
 * Scorm Courses Management class
 *
 * @keywords admin, scorm, courses
 */

namespace Models\TPM\Admin\Utility;

use Models\Globals\ScormCourses;
use Lib\Support\AuthDownload;

/**
 * Class ScormCoursesManager based on class_adminFileManager.php from Legacy code
 *
 * @keywords admin files, data files, bulk upload, bulk process, scorm courses
 */
class ScormCoursesManager extends ScormCourses
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
        parent::__construct(); // DashboardResources
        $this->sess = \Xtra::app()->session;
    }

    /**
     * Get main path to the admin files
     *
     * @param string $subDir Subdirectory for files
     *
     * @return string
     */
    public function dashResourcesPath($subDir = '')
    {
        if ($subDir === '') {
            return $this->dashResourcesPath;
        }
        return $this->dashResourcesPath . '/' . $subDir;
    }

    /**
     * gets current languages
     *
     * @return array of objects
     *
     * @todo this should probably be centralized somewhere else
     */
    public function languages()
    {
        $langObjArr = [];
        $sql = "SELECT langCode, langNameEng "
            . "FROM {$this->DB->globalDB}.g_languages ORDER BY langNameEng";
        foreach ($this->DB->fetchObjectRows($sql) as $k => $v) {
            $langObjArr[$v->langCode] = $v->langNameEng;
        }
        return $langObjArr;
    }

    /**
     * Get user files in admin upload area
     *
     * @param string $subDir Limit results to g_adminFiles.subDirectory
     *
     * @return array (id | subdirectory | filename | dateUploaded | processed)
     */
    public function getScormCourses()
    {
        $where = ['clientID' => $this->clientID, 'context' => 'ddq'];
        $orderBy = 'ORDER BY id DESC';
        $files = $this->selectMultiple([], $where, $orderBy);
        $result = [];
        foreach ($files as $f) {
            $result[] = $f;
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
    public function storeUploadedFile($tmpPath, $filename, $category, $desc = '', $title = '')
    {
        $returnArray = ['replace'   => false, 'removeOld' => false, 'moved'     => false, 'unlinked'  => false, 'stored'    => false, 'fid'       => 0, 'message'   => 'Unknown error.'];

        if (file_exists($tmpPath)) {
            // Check if it's a valid ZIP file
            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) === true) {
                // Check if the ZIP file contains at least one HTML file
                $htmlFileFound = false;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filenameInZip = $zip->getNameIndex($i);
                    if (preg_match('/\.(html|htm)$/i', $filenameInZip)) {
                        $htmlFileFound = true;
                        break;
                    }
                }
                // Close the ZIP archive
                $zip->close();
                
                // If no HTML file found, discard the upload
                if (!$htmlFileFound) {
                    $returnArray['message'] = 'Error: Invalid format. Please upload at least one HTML file. 
                    Ensure that your file has a .html or .htm extension.';
                    unlink($tmpPath); // Delete the temporary uploaded file
                    return $returnArray;
                }
            } else {
                $returnArray['message'] = 'Please Upload ZIP file.';
                return $returnArray;
            }

            $destPath = $this->dashResourcesPath;
            $directory = str_replace(' ', '_', $desc) . '/' . $category . '/training-' . date('Ymd');
            $targetDir = $destPath . '/' . $directory;
            $dest = $targetDir . '/' . $filename;

            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0777, true)) {
                    $returnArray['message'] = 'Invalid destination path.';
                    return $returnArray;
                }
            }

            // The stored values can be used for debugging/feedback
            $returnArray['replace']  = file_exists($dest); // are we replacing existing file?
            $returnArray['moved']    = copy($tmpPath, $dest);
            $returnArray['unlinked'] = unlink($tmpPath);
            $zipFolderName = str_replace('.zip', '', $dest);
            $zip = new \ZipArchive();
            if ($zip->open($dest) === true) {
                $zip->extractTo($zipFolderName . '/');
                $zip->close();
                unlink($dest);
            }

            // If we replaced a file, update the existing DB record
            if ($returnArray['replace']) {
                $fInfo = $this->getFileInfoByPath($filename);
                $fid = 0;
                if ($fInfo) {
                    $returnArray['stored'] = $this->addAdminFileToDBByPath($zipFolderName, $fid, $directory, $fInfo['id'], $desc, $title);
                } else {
                    $returnArray['stored'] = $this->addAdminFileToDBByPath($zipFolderName, $fid, $directory, 0, $desc, $title);
                }
            } else {
                $returnArray['stored'] = $this->addAdminFileToDBByPath($zipFolderName, $fid, $directory, 0, $desc, $title);
            }

            if ($returnArray['moved'] && $returnArray['stored']) {
                $returnArray['message'] = 'File uploaded successfully.';
                $returnArray['fid']     = $fid;
            } else {
                $returnArray['message'] = 'File uploaded failed.';
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
     * @return false on failure or count of DB affected rows
     */
    private function addAdminFileToDBByPath($path, &$fid, $directory, $recID = 0, $desc = '', $title = '')
    {
        $resPath = $this->dashResourcesPath;
        $targetDir = $resPath . '/' . $directory;
        $recID = (int)$recID;
        if (!empty($title)) {
            $startPage = str_replace('.zip', '', $path) . "/" . $title;
        } else {
            $htmlFiles = glob(str_replace('.zip', '', $path) . "/*.{html,htm}", GLOB_BRACE);
            $startPage = $htmlFiles[0];
        }
        $values = [
            'courseName' => $desc,
            'clientID' => $this->clientID,
            'startPage' => str_replace(getenv('docRoot'), '', $startPage),
            'context' => 'ddq',
            'created' => date('Y-m-d h:i:s')
        ];
        if ($recID) {
            $fid = $recID;
            $rtn = $this->updateByID($recID, ['modified' => date('Y-m-d h:i:s')]);
            if ($rtn === false) {
                $fid = false;
            }
        } else {
            if ($fid = $this->insert($values)) {
                $values['context'] = 'test';
                $this->insert($values);
                $rtn = 1;
            } else {
                $fid = false;
                $rtn = false;
            }
        }
        return $rtn;
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
        $folderInfo = $this->selectByID($fileID);
        $courseDir = $this->dashResourcesPath . '/' . str_replace(' ', '_', (string) $folderInfo['courseName']);

        // Validate the course directory to ensure it's within a specific allowed path
        $allowedBaseDir = realpath($this->dashResourcesPath);
        $realCourseDir = realpath($courseDir);

        // Check if the real course directory path starts with the allowed base directory path
        if ($realCourseDir === false || strpos($realCourseDir, $allowedBaseDir) !== 0 || !$this->isValidPath($courseDir)) {
            return false;
        }
        
        if (file_exists($courseDir)) {
            $this->deleteFolderContents($courseDir);
            return $this->deleteAdminFileFromDBByPath($folderInfo['startPage']);
        } else {
            return false;
        }
    }


    /**
     * Delete folder and its contents
     *
     * @param string $folderName folderName of the course
     *
     * @return void
     */
    private function deleteFolderContents($folderName)
    {
        $files = array_diff(scandir($folderName), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$folderName/$file")) ? $this->deleteFolderContents("$folderName/$file") : unlink("$folderName/$file");
        }
        rmdir($folderName);
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
        $where = [
            'startPage' => $path,
            'clientID' => $this->clientID,
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
    public function getFileInfoByPath($fileName)
    {
        $where = ['courseName' => $fileName];
        return $this->selectOne([], $where);
    }
}
