<?php
/**
 * Contains the class that handles data operations with respect
 * to changing the logo of a company
 * or vendor
 *
 * @keywords company, identity, settings, logo
 */

namespace Models\TPM\Settings\CompanyIdentity;

use Lib\Legacy\UserType;

/**
 * Handles data operations with respect
 * to changing the logo of a company
 * or vendor
 */
#[\AllowDynamicProperties]
class LogoSubmodel
{
    /**
     * Dir for temporary logo previewss
     *
     * @var string
     */
    public const TEMPORARY_DIR = '/tmp';

    /**
     * Logos subdir name
     *
     * @var string
     */
    public const LOGOS_SUBDIR = 'clientlogos';

    /**
     * Filename prefix for uploaded image preview
     *
     * @var string
     */
    public const LEGACY_PREV_NAME_BASE = 'upl_logopreview_';

    /**
     * Regex to verify filename for uploaded image preview
     *
     * @var string
     */
    public const LPNB_REGX = '^upl\_logopreview\_';

    /**
     * What it sounds and looks like - valid image extensions
     *
     * @var array
     */
    public $validPreviewExtensions = ['gif', 'jpg', 'png'];

    /**
     * Reference to the app db object
     *
     * @var object
     */
    private $DB;

    /**
     * Db name
     *
     * @var string
     */
    private $identityDB;

    /**
     * Table name to use within identity db
     *
     * @var string
     */
    private $table;

    /**
     * Preview name prefix for making filename
     *
     * @var string
     */
    private $prevNamePrefix;

    /**
     * Regular expression to match the prefix file name
     *
     * @var string
     */
    private $prevNameRegex;

    /**
     * Construct instance ; initial data setup
     *
     * @param type $tenantID   tenantID
     * @param type $properties Array of data properties
     *
     * @return void
     */
    public function __construct(/**
         * TPM tenantID
         */
        private $tenantID,
        $properties
    ) {
        $this->DB = \Xtra::app()->DB;
        $this->table    = $properties['table'];
        $this->fileTag  = $properties['fileTag'];

        $this->prevNamePrefix = self::LEGACY_PREV_NAME_BASE;
        $this->prevNameRegex = self::LPNB_REGX;
    }

    /**
     * Return whether the pertinent tenant has a logo preview to show
     *
     * @return void
     */
    public function initHasLogo()
    {
        return ['hasLogoPrev' => $this->previewIsPresent()];
    }

    /**
     * Rename the uploaded file with something predictable
     * with respect to this specified company.
     *
     * This file overwrites the previously uploaded logo
     * preview if it exists.
     *
     * @param string $uploadedFilename  Name of uploaded file
     * @param string $uploadedExtension Extension of uploaded file
     *
     * @return boolean True if no show-stopping problems are encountered
     *
     * @throws \Exception If invalid file extension is passed
     */
    public function publishLogoPreview($uploadedFilename, $uploadedExtension)
    {
        if (!is_string($uploadedFilename) || $uploadedFilename === '') {
            throw new \Exception("Filename is required.");
        }

        if (isset($GLOBALS['PHPUNIT']) && !empty($GLOBALS['PHPUNIT'])) {
            $fullUploadName = $uploadedFilename;
        } else {
            $fullUploadName = self::TEMPORARY_DIR . '/' . $uploadedFilename;
        }

        $this->validatePreviewExtension($uploadedExtension);

        $newPreviewFilePath = $this->buildPreviewFilePath($uploadedExtension);
        rename($fullUploadName, $newPreviewFilePath);

        return true;
    }

    /**
     * Delete any logo previews for this client
     *
     * @return array deleted file names
     */
    public function unlinkLogoPreviews()
    {
        $dir = self::TEMPORARY_DIR;
        $files = $this->getPreviewNames();
        $deleted = [];
        foreach ($files as $file) {
            $fullpath = "$dir/$file";
            if (is_file($fullpath)) {
                unlink($fullpath);
                $deleted[] = $file;
            }
        }
        return $deleted;
    }

    /**
     * Return names of any logo previews for this client
     *
     * @param boolean $wholePath whether to return the whole path or just
     *                           the filename
     *
     * @return array file names of previews
     */
    public function getPreviewNames($wholePath = false)
    {
        $files = scandir(
            self::TEMPORARY_DIR
        );
        $prevs = [];
        $exts = implode('|', $this->validPreviewExtensions);

        //Match our filename, ignoring extension
        $filenameRegex = '/' . $this->prevNameRegex . '(cid|vid)[0-9]{1,4}\.[a-zA-Z0-9]+/';

        //Case insensitively match our acceptable extensions
        $extensionsRegex = '/.+\.(' . $exts . ')/i';

        foreach ($files as $file) {
            if (preg_match($filenameRegex, $file) && preg_match($extensionsRegex, $file)) {
                    $prevs[] = $wholePath ? (self::TEMPORARY_DIR . '/' . $file) : $file;
            }
        }
        return $prevs;
    }

    /**
     * Return whether this tenant has a preview logo image
     *
     * @return boolean
     */
    public function previewIsPresent()
    {
        return count($this->getPreviewNames()) > 0;
    }

    /**
     * Return the full file paths for any previews associated with
     * current pertinent tenant
     *
     * @return array Strings; file paths
     */
    public function getPreviewPaths()
    {
        return $this->getPreviewNames(true);
    }

    /**
    * Moves the preview to the client specific locale in which it will
    * be shown as the actual logo.
    *
    * Updates the database with this information.
    * Sets JS Args in response to indicate success or failure.
    *
    * @param type $fullLogoPath     full path of logo prev to apply
    * @param type $relativeLogoPath relative path of logo prev to apply
    *
    * @return mixed $moved New filename and path if no apparent probs,
    *                      otherwise null
    */
    public function applyPreview($fullLogoPath, $relativeLogoPath)
    {
        if (!$relativeLogoPath) {
            throw new \Exception("valid relative locale is required");
        }

        if (!$this->validatePath($fullLogoPath) || !str_contains($fullLogoPath, (string) $relativeLogoPath)) {
            throw new \Exception("valid locale is required");
        }

        $oldLogoFullPath = "$fullLogoPath/" . $this->logoFileName();
        $prevName = \Xtra::head($this->getPreviewNames());

        if (empty($prevName)) {
            throw new \Exception("no preview found");
        }
        $ext = strtolower(pathinfo((string) $prevName)['extension']);
        $this->validatePreviewExtension($ext);

        $filename = $this->movePreviewToClientlogos($fullLogoPath, $ext);
        $moved = "$fullLogoPath/$filename";
        $isValidPath = is_file($moved);

        if ($isValidPath) {
            $dbUpdate = $this->applyLogoInDB($moved, $ext, $relativeLogoPath);
            if (is_file($oldLogoFullPath) && strtolower($oldLogoFullPath) != strtolower($moved)) {
                unlink($oldLogoFullPath);
            }
            return ($moved && $dbUpdate) ? $filename : null;
        }
    }

    /**
     * Set the preview file prefix
     *
     * @param string $prefix prefix to use
     *
     * @return void
     */
    public function setFilePrefix($prefix)
    {
        if ($prefix !== self::LEGACY_PREV_NAME_BASE && $prefix !== 'test_logopreview_') {
            throw new \Exception('invalid filename prefix');
        }
        $this->prevNamePrefix = $prefix;
    }

    /**
     * Set the regex string by which the preview filename
     * is validated and found
     *
     * @param string $regex regular expression
     *
     * @return void
     */
    public function setFileRegex($regex)
    {
        if ($regex !== self::LPNB_REGX && $regex !== '^test\_logopreview\_') {
            throw new \Exception('invalid regex value');
        }
        $this->prevNameRegex = $regex;
    }

    /**
     * Build the system-recognized img preview file name with the
     * specified extension, including whole file path
     *
     * @param string $ext extension to add at end of path
     *
     * @return string
     */
    private function buildPreviewFilePath($ext)
    {
        return self::TEMPORARY_DIR .'/'. $this->prevNamePrefix . $this->fileTag . '.'. $ext;
    }

    /**
     * As part of applying the preview as the actual new tenant logo,
     * move the image file to the appropriate file system locale.
     *
     * Renames file itself to appropriate (and cache-busting) system-
     * recognized logo filename
     *
     * @param string $logoPath location to which to move
     * @param string $ext      extension for the filename
     *
     * @return type
     * @throws \Exception
     */
    private function movePreviewToClientlogos($logoPath, $ext)
    {
        $prevFile = \Xtra::head($this->getPreviewNames());
        $fullPrevPath = self::TEMPORARY_DIR . '/' . $prevFile;
        if (empty($prevFile)) {
            throw new \Exception('No preview file found');
        }
        if (!is_file($fullPrevPath)) {
            throw new \Exception('File system partially unavailable');
        }
        $applicableFileName = $this->buildApplicableFileName($ext);
        $applicableFilePath = "$logoPath/$applicableFileName";
        rename($fullPrevPath, $applicableFilePath);
        return $applicableFileName;
    }

    /**
     * Throw error if specified extension is not present
     * in the array of allowed extensions
     *
     * @param type $ext extension to be validated
     *
     * @throws \Exception
     *
     * @return void
     */
    private function validatePreviewExtension($ext)
    {
        if (!in_array($ext, $this->validPreviewExtensions)) {
            throw new \Exception(
                "The file must have one of the following extensions: "
                . implode(',', $this->validPreviewExtensions)
                . "."
            );
        }
    }

    /**
     * Move preview image from the preview state to the applied state in
     * the filesystem and in the db.
     *
     * @param string $fullDestinationPath     ultimate file destination
     * @param string $ext                     file extension
     * @param string $relativeDestinationPath relative ultimate file destination
     *
     * @return boolean true if apparently all went successfully
     */
    private function applyLogoInDB($fullDestinationPath, $ext, $relativeDestinationPath)
    {
        $getFileInfo = function () use ($fullDestinationPath) {
            $size = (int)filesize($fullDestinationPath);
            $name = (string)pathinfo($fullDestinationPath)['filename'];

            return compact(['size','name']);
        };

        if (strtolower($ext) == 'jpg') {
            $fileType = 'image/jpeg';
        } else {
            $fileType = "image/$ext";
        }

        extract($getFileInfo());

        if (!$name) {
            return;
        }

        $sql = "UPDATE $this->table SET\n"
            ."logoFileName = :fileName, \n"
            ."logoFileSize = :size, \n"
            ."logoFileType = :fileType, \n"
            ."logoPath = :path \n"
            ."WHERE id = :tenantID \n"
            ."LIMIT 1";

        $bindings = [
            ':fileName' => "$name.$ext",
            ':size' => $size,
            ':fileType' => $fileType,
            ':path' => $relativeDestinationPath . "/$name.$ext",
            ':tenantID' => $this->tenantID
        ];
        $this->DB->query($sql, $bindings);
        return true;
    }

    /**
     * Obtain logo filename from db
     *
     * @return string
     */
    private function logoFileName()
    {
        $sql = "SELECT logoFileName FROM $this->table WHERE id = :tenantID LIMIT 1";
        return $this->DB->fetchValue($sql, [':tenantID' => $this->tenantID]);
    }

    /**
     * Validate the specified (alleged) filesystem path
     *
     * @param string $path to be validated
     *
     * @return boolean
     */
    private function validatePath($path)
    {
        return is_dir($path);
    }

    /**
     * Build system-recognizable logo filename
     * includes unique identifying date-derived
     * string
     *
     * @param string $ext file extension to add
     *
     * @return string filename only, no path
     */
    private function buildApplicableFileName($ext)
    {
        $tenantTag = $this->fileTag;
        $date      = date_timestamp_get(date_create());
        $fileName  = "logo-$date-$tenantTag.$ext";
        return $fileName;
    }
}
