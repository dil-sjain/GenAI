<?php
/**
 * Add Case Dialog Attachments
 *
 * @category AddCase_Dialog
 * @package  Controllers\TPM\AddCase
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddCase\Subs;

use Models\ThirdPartyManagement\Cases;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\ClientFileManagement;
use Models\User;
use Lib\SettingACL;
use Models\TPM\CaseMgt\CaseFolder\SubInfoAttach;
use Models\Globals\DocCategory;

/**
 * Class Attachments
 */
#[\AllowDynamicProperties]
class Attachments extends AddCaseBase
{
    protected $CFM;

    /**
     * Attachments constructor.
     *
     * @param int $tenantID tenant ID
     * @param int $caseID   cases.id
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $caseID)
    {
        $this->callback = 'appNS.addCase.handler';
        $this->template = 'Attachments.tpl';
        $this->CFM      = new ClientFileManagement();

        parent::__construct($tenantID, $caseID);
    }


    /**
     * Returns the translated dialog title
     *
     * @return string
     */
    public function getTitle()
    {
        return "{$this->app->trans->codeKey('intro_head')} - {$this->CasesRecord['caseName']}";
    }


    /**
     *  Returns view values for Entry.tpl
     *
     * @return array
     */
    public function getViewValues()
    {
        return [
            'pageTitle' => $this->app->trans->codeKey('create_attach'),
            'pageName'  => 'Attachments',
            'sitePath'  => $this->app->sitePath,
        ];
    }


    /**
     * Based on Legacy handling of public_html/cms/includes/php/funcs_clientfiles.php validateUpload().
     *
     * @return array with a string value of an invalid extension message.
     */
    #[\Override]
    public function validate()
    {
        $file = explode('.', strtolower((string) \Xtra::arrayGet($this->app->clean_POST, 'filename', '')));
        $uploadFile = \Xtra::arrayGet($this->app->clean_POST, 'uploadFile', 0);
        $extension = end($file);
        $error = [];
        $check = [
            'csv',
            'doc',
            'docx',
            'eml',
            'et',
            'hwp',
            'mht',
            'msg',
            'ods',
            'odt',
            'odf',
            'oft',
            'pdf',
            'pmd',
            'pps',
            'ppsx',
            'ppt',
            'pptx',
            'psd',
            'pub',
            'rtf',
            'thmx',
            'txt',
            'vcf',
            'vsd',
            'wps',
            'xls',
            'xlsb',
            'xlsx',
            'xml',
            'gif',
            'jpeg',
            'jpg',
            'png',
            'bmp',
            'tif',
            'tiff',
        ];
        if (!in_array($extension, $check)) {
            $acceptable = implode(', ', $check);
            $trText = $this->app->trans->codeKeys(['filefilter_noaccept', 'filefilter_accept']);
            $error[] = $trText['filefilter_noaccept']."($extension). {$trText['filefilter_accept']} $acceptable.";

            if (is_file($uploadFile)) {
                unlink($uploadFile);
            }
        }
        return $error;
    }


    /**
     * Attaches a document by inserting a new record into the subInfoAttach
     * and moving the file upload to its appropriate directory
     *
     * @return bool
     * @throws \Exception
     */
    public function store()
    {
        $fileName    = \Xtra::arrayGet($this->app->clean_POST, 'filename', null);
        $description = \Xtra::arrayGet($this->app->clean_POST, 'description', null);
        $catID       = \Xtra::arrayGet($this->app->clean_POST, 'category', 0);
        $uploadFile  = \Xtra::arrayGet($this->app->clean_POST, 'uploadFile', 0);
        $clientFilePath = $this->CFM->getClientFilePath();
        $fileType       = $this->CFM->getFileTypeDescriptionByFileName($fileName);

        $data = [
            'caseID'      => $this->caseID,
            'description' => substr((string) $description, 0, 255),
            'filename'    => $fileName,
            'fileType'    => $fileType,
            'fileSize'    => filesize($uploadFile),
            'contents'    => '',
            'ownerID'     => (new User())->findByAttributes(['id' => $this->userID])->get('userEmail'),
            'caseStage'   => Cases::REQUESTED_DRAFT,
            'catID'       => $catID,
        ];

        $SubInfoAttach = new SubInfoAttach($this->tenantID);
        if (!$SubInfoAttach->validate($data)) {
            throw new \Exception($this->app->trans->codeKey('update_record_failed'));
        }
        $table_id = $SubInfoAttach->store($data);

        if ($this->CFM->createClientFileDir('subInfoAttach', $this->tenantID)) {
            $destination = $clientFilePath . '/' . 'subInfoAttach' . '/' . $this->tenantID . '/' .$table_id;
            $this->CFM->moveFile($source = $uploadFile, $destination);
            $this->CFM->putClientInfoFile('subInfoAttach', $this->tenantID, $table_id);
        }
        return true;
    }


    /**
     * Deletes attachments the same as Legacy. The file, the -info file and the table row are all removed.
     *
     * In the future would probably be a good idea to consider archiving this data instead and moving to a separate
     * server.
     *
     * @param int $id unique identifier of attachment.
     *
     * @return array of all attachments to update the data table.
     */
    public function deleteAttachment($id)
    {
        if (!empty((int)$id)) {
            $clientFilePath = $this->CFM->getClientFilePath();
            $destination    = $clientFilePath . '/' . 'subInfoAttach' . '/' . $this->tenantID . '/' .$id;
            $infoFile       = $destination.'-info';

            if (is_file($destination)) {
                unlink($destination);
            }
            if (is_file($infoFile)) {
                unlink($infoFile);
            }
            (new SubInfoAttach($this->tenantID))->delete($id, $this->caseID);
        }

        return (new SubInfoAttach($this->tenantID))->getAttachments($this->caseID);
    }


    /**
     * Get "corporate docs" 3pdocs forms
     *
     * @return array
     */
    #[\Override]
    public function returnInitialData()
    {
        $setting = (new SettingACL($this->tenantID))->get(
            SettingACL::MAX_UPLOAD_FILESIZE,
            ['lookupOptions' => 'disabled']
        );
        $uplMax = ($setting) ? $setting['value'] : 10; // Default to 10MB
        return [
            'uplMax'      => $uplMax,
            'categories'  => (new DocCategory($this->tenantID))->returnDocumentCategories(),
            'attachments' => (new SubInfoAttach($this->tenantID))->getAttachments($this->caseID),
            'trText'      => $this->app->trans->group('questionnaire_attach'),
        ];
    }
}
