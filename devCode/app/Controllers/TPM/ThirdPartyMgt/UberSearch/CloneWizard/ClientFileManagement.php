<?php
/**
 * Manage client files for use with Clone Wizard, be cautious when using this class - no access validation for files
 * @keywords client files, clone wizard, clone attachments
 */

namespace Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard;

use Lib\Support\Test\FileTestDir;

#[\AllowDynamicProperties]
class ClientFileManagement
{
    /**
     * @var string base file path for client files
     */
    public $clientFiles;

    /**
     * ClientFileManagement constructor.
     *
     */
    public function __construct()
    {
        $app = \Xtra::app();
        $this->_dbCls = $app->DB;
        $this->setClientFilePath();
    }

    /**
     * Sets the client file path for use with cloning file attachments and storing case PDFs
     *
     * @return void
     */
    private function setClientFilePath()
    {
        if (!isset($this->clientFiles)) {
            // setup base path to client files.
            $clientFiles = \Xtra::conf('paths.sim_client_files');
            if (empty($clientFiles)) {
                $clientFiles = '/clientfiles/'. \Xtra::conf('cms.env');
            }
            $this->clientFiles = rtrim((string) $clientFiles, '/'); // trim slash just in case
        }
    }


    /**
     * Get the client file path, if it is not set - call setClientFilePath
     *
     * @return string
     */
    public function getClientFilePath()
    {
        if (isset($this->clientFiles)) {
            return $this->clientFiles;
        }
        $this->setClientFilePath();
        return $this->clientFiles;
    }

    /**
     * Writes info file from database table.
     * Assumes input validation handled by caller.
     * Returns number of bytes written or false.
     * see public_html/cms/includes/php/funcs_clientfiles.php
     *
     * @param string  $tableName Name of database attachment table
     * @param integer $tenantID  clientProfile.id
     * @param integer $recID     [$tableName].id
     * @param string  $basePath  base directory path
     *
     * @return integer Number of bytes written or false
     */
    public function putClientInfoFile($tableName, $tenantID, $recID, $basePath = '')
    {
        $basePath = (!empty($basePath)) ? $basePath : $this->clientFiles;
        $recID = intval($recID);
        $rtn  = false;
        $flds = $this->attachmentTableColumns($tableName);
        $fldList = join(', ', $flds);
        if ($data = $this->_dbCls->fetchAssocRow("SELECT $fldList FROM $tableName "
            . "WHERE id='$recID' LIMIT 1")) {
            $csvData = $this->mkCSV($flds, 'std', true);
            $csvData .= $this->mkCSV($data, 'std', true);
            $dest = $basePath . '/' . $tableName . '/' . $tenantID . '/' . $recID;
            $dest = FileTestDir::getPath($dest);
            $rtn = file_put_contents($dest . '-info', $csvData);
        }
        return $rtn;
    }


    /**
     * Clones files on the server (not to be confused with cloning over the table rows for a file)
     *
     * @param string  $tableName    Name of database attachment table
     * @param integer $srcTenantID  id of tenant to clone from
     * @param integer $destTenantID id of tenant to clone to
     * @param integer $recID        [$tableName].id
     * @param integer $clRecID      [$tableName].id of the new clone record
     * @param string  $destTblName  Optionally change the name of database attachment table, defaults to $tableName
     *
     * @return mixed Number of bytes on success or false on failure
     */
    public function cloneFileAttachment($tableName, $srcTenantID, $destTenantID, $recID, $clRecID, $destTblName = null)
    {
        if (!$recID || !$tableName || !$recID || !$clRecID) {
            return false;
        }

        $src = $this->clientFiles . '/' . $tableName . '/' . $srcTenantID . '/' . $recID;
        $src = FileTestDir::getPath($src);
        // If $destTblName is provided use that instead of the $tableName path
        if (isset($destTblName)) {
            $destDir = $this->clientFiles . '/' . $destTblName . '/' . $destTenantID;
        } else {
            $destDir = $this->clientFiles . '/' . $tableName . '/' . $destTenantID;
        }

        $destDir = FileTestDir::getPath($destDir);
        $destFile = $destDir . '/' . $clRecID;
        $destFile = FileTestDir::getPath($destFile);

        if (!is_file($src) || !is_readable($src) || !$this->createClientFileDir($tableName, $destTenantID)) {
            return false;
        }

        if (!is_writeable($destDir)) {
            return false;
        }

        if (!copy($src, $destFile)) {
            return false;
        }
        return true;
    }


    /**
     * Moves a file from the source to the given destination
     *
     * @param $src  string filepath for the source file to be moved
     * @param $dest string filepath for the intended destination of the file
     *
     * @return bool
     */
    public function moveFile($src, $dest)
    {
        if (!$src || !$dest) {
            return false;
        }

        // make the destination dir or rename will fail
        $destDir = explode('/', (string) $dest, -1);
        $destDir = implode('/', $destDir);

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (!is_file($src) || !is_readable($src)) {
            return false;
        }

        if (!is_writeable($destDir)) {
            return false;
        }

        if (!rename($src, $dest)) {
            return false;
        }

        return true;
    }


    /**
     * Returns a key-value array of extensions useful for file upload functionality.
     * see public_html/cms/includes/php/funcs_clientfiles.php
     *
     * @param  string $fileName File name to find file type description.
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
     * Deletes a client file from the filesystem by removing the .pdf extension and looking for files sharing basename
     * see public_html/cms/includes/php/funcs_clientfiles.php
     *
     * @param  string $src filepath for the file to be deleted
     *
     * @return boolean
     */
    public function removeClientFiles($src)
    {
        if (!$src) {
            return false;
        }

        $basename = substr($src, 0, (strlen($src)-4));

        $files = glob("$basename*");

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return true;
    }


    /**
     * zapTmpFiles function
     *
     * @param string $base base
     *
     * @see    Lib\Legacy\GenPDF::zapTmpFiles();
     * @return void
     */
    public function zapTmpFiles($base)
    {
        $allowedPaths = ['/tmp'];

        $exts = ['.html', '.pdf', ''];
        foreach ($exts as $ext) {
            $fil = $base . $ext;
            if (!file_exists($fil)) {
                continue;
            }

            $pathParts = pathinfo($base);
            if (!in_array($pathParts['dirname'], $allowedPaths)) {
                continue;
            }

            @chmod($fil, 0664);
            @unlink($fil);
        }
    }


    /**
     * Conditionally create client file directory. - directly lifted from funcs_clientfiles.php
     *
     * @param string  $tableName Name of database attachment table
     * @param integer $clientID  $clientID clientProfile.id
     * funcs_clientfiles.php
     *
     * @return boolean Indicates success or failure
     */
    public function createClientFileDir($tableName, $clientID)
    {
        if (!$tableName || !$clientID) {
            return false;
        }
        $target = $this->clientFiles . '/' . $tableName . '/' . $clientID;
        if (is_dir($target)) {
            return true;
        }
        return mkdir($target, 0755, true);
    }


    /**
     * Outputs delimited text file.
     * see public_html/cms/includes/php/funcs_clientfiles.php
     *
     * @param array   $dataArray A simple array of string and/or numeric values
     * @param string  $format    Either 'std or 'excel'
     * @param boolean $addLE     (optional) If true add line terminator based on $format value:
     *     'std' (LF) or 'excel' (LFCR). Default is false.
     *
     * @return string delimited values
     */
    public function mkCSV($dataArray, $format, $addLE = false)
    {
        $tmp = [];
        if ($format == 'std') {
            $sep = ',';
            $le = "\n";
        } else {
            $sep = "\t";
            $le = "\r\n";
        }
        foreach ($dataArray as $val) {
            $val = html_entity_decode((string) $val, ENT_QUOTES, 'UTF-8');
            if (str_contains($val, '"')) {
                $txt = '"' . str_replace('"', '""', $val) . '"';
            } elseif ((str_contains($val, $sep))
                || (strlen($val) && $val[0] == ' ')
                || (str_contains($val, "\n"))
                || (str_contains($val, "\r"))
                || (str_contains($val, "\x1A"))) {
                $txt = '"'. $val . '"';
            } else {
                $txt = $val;
            }
            $tmp[] = $txt;
        }
        if ($addLE) {
            return join($sep, $tmp) . $le;
        } else {
            return join($sep, $tmp);
        }
    }


    /**
     * Returns array of table field names, excluding `contents`
     *
     * @param  string $tableName datbase table name
     * funcs_clientfiles.php
     *
     * @return array column names
     */
    public function attachmentTableColumns($tableName)
    {
        $fields = [];
        if ($tableName && ($tmp = $this->_dbCls->fetchValueArray("SHOW COLUMNS FROM $tableName"))) {
            foreach ($tmp as $fld) {
                if ($fld != 'contents') {
                    $fields[] = $fld;
                }
            }
        }
        return $fields;
    }
}
