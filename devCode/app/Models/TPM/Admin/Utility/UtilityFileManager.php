<?php
/**
 * Admin File Management class
 *
 * @keywords admin, file management
 */

namespace Models\TPM\Admin\Utility;

use Models\Globals\UtilityFiles;
use Lib\Support\AuthDownload;

/**
 * Class UtilityFileManager based on class_adminFileManager.php from Legacy code
 *
 * @keywords admin files, data files, bulk upload, bulk process
 */
class UtilityFileManager extends UtilityFiles
{
    /**
     * Instance of app->session
     *
     * @var object
     */
    private $sess = null;

    /**
     * Class constructor. Init class variables here.
     *
     * @param int $clientID client ID
     */
    public function __construct(private $clientID)
    {
        parent::__construct();
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
    public function getUtilityFilePath($subDir = '')
    {
        if ($subDir === '') {
            return $this->absFilePath;
        }
        return $this->absFilePath[$subDir];
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
        return is_dir($path);
    }

    /**
     * Get current subdirectories
     *
     * @return array List of subdirectories
     */
    public function getUtilitySubDirs()
    {
        $dirs = array_filter(glob($afp = $this->utilityFilePath . '/*'), $this->validateSubDirs(...));
        $return = [];
        foreach ($dirs as $path) {
            $path_array = explode('/', $path);
            $return[] = $path_array[count($path_array) - 1];
        }
        return $return;
    }

    /**
     * Get client directory
     *
     * @return string Client directory.
     */
    public function getUtilityClientDir()
    {
        return (string)$this->clientID;
    }

    /**
     * Get user files in admin upload area
     *
     * @param string $subDir Limit results to g_adminFiles.subDirectory
     *
     * @return array (id | subdirectory | filename | dateUploaded | processed)
     */
    public function getUtilityFiles($subDir = '')
    {
        $result = [];
        $sitePath = \Xtra::conf('cms.sitePath');
        $siteURL = str_replace('cms/', '', (string) $sitePath);
        $clientDir = $this->getUtilityClientDir();
        $ddqPath = $this->getUtilityFilePath('ddq') . '/' . $clientDir;
        $emailPath = $this->getUtilityFilePath('email') . '/' . $clientDir;
        $ddqFiles = is_dir($ddqPath) ? scandir($ddqPath) : [];
        $emailFiles = is_dir($emailPath) ? scandir($emailPath) : [];
        $i = 1;
        foreach ($ddqFiles as $file) {
            $filepath = $ddqPath . '/' . $file;
            if (in_array($file, ['.','..']) || is_dir($filepath)) {
                continue;
            }
            $result[] = [
                'id' => $i++,
                'subdirectory' => 'ddq',
                'filename' => $file,
                'filelink' => $siteURL . $this->relFilePath['ddq'] . '/' . $clientDir . '/' . $file,
                'md5' => md5_file($filepath),
                'dateUploaded' => date("Y-m-d", filemtime($filepath)),
                'processed' => true,
            ];
        }
        foreach ($emailFiles as $file) {
            $filepath = $emailPath . '/' . $file;
            if (in_array($file, ['.','..']) || is_dir($filepath)) {
                continue;
            }
            $result[] = [
                'id' => $i++,
                'subdirectory' => 'email',
                'filename' => $file,
                'filelink' => $siteURL . $this->relFilePath['email'] . '/' . $clientDir . '/' . $file,
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
     * @param string $subDir   The subdirectory where the file should be stored
     * @param string $filename The filename to store the tmp file as
     *
     * @return array (bool 'replace', 'removeOld', 'moved', 'unlinked', 'stored', string 'message', int 'fid')
     */
    public function storeUploadedFile($tmpPath, $subDir, $filename)
    {
        $returnArray = ['replace'   => false, 'removeOld' => false, 'moved'     => false, 'unlinked'  => false, 'stored'    => false, 'fid'       => 0, 'message'   => 'Unknown error.'];

        if (file_exists($tmpPath)) {
            $pathToSubDir = $this->absFilePath[$subDir];
            $targetDir    = $pathToSubDir . '/' . $this->getUtilityClientDir();
            $dest         = $targetDir . '/' . $filename;
            // Check destination in case weird filename or subdir
            if ($this->isValidPath($dest)) {
                $returnArray['message'] = 'File already exists.';
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
            $returnArray['stored']   = $returnArray['moved'];
            if ($returnArray['moved']) {
                $returnArray['message'] = 'File uploaded successfully.';
            }
            return $returnArray;
        }
        $returnArray['message'] = "Could not find uploaded file in filesystem.";
        return $returnArray;
    }

    /**
     * Rename single user file by ID
     *
     * @param int    $fname   File name
     * @param string $cat     category ddq/email
     * @param string $newName New name for the file
     *
     * @return bool|int
     *
     * @throws \Exception
     */
    public function renameUtilityFileByID($fname, $cat, $newName)
    {
        $path = $this->getPathFromID($fname, $cat);
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
            throw new \Exception("A file with name '{$newName}' already exist.");
        }

        $renamed = false;
        if (rename($path, $newPath)) {
            $renamed = true;
        }
        return $renamed;
    }

    /**
     * Delete a single user file in admin upload area
     *
     * @param int    $fname File name
     * @param string $cat   category ddq/email
     *
     * @return bool
     */
    public function deleteFileByID($fname, $cat)
    {
        $path = $this->getPathFromID($fname, $cat);
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
     * @param int    $fname File name
     * @param string $cat   category ddq/email
     *
     * @return string Path to the file
     */
    public function getPathFromID($fname, $cat)
    {
        if (!in_array($cat, array_keys($this->absFilePath))) {
            return false;
        }
        $path     = $this->absFilePath[$cat]
            . '/' . $this->getUtilityClientDir()
            . '/' . $fname;
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
