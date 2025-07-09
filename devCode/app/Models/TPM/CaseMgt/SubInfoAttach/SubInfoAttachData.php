<?php
/**
 * Model: SubInfoAttachData
 */
namespace Models\TPM\CaseMgt\SubInfoAttach;

use Models\ThirdPartyManagement\Cases;
use Lib\UpDnLoadFile;
use Lib\Legacy\Security;
use Models\LogData;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\ClientFileManagement;

/**
 * Class SubInfoAttachData
 *
 * @keywords SubInfoAttach, SubInfoAttachForm
 */
#[\AllowDynamicProperties]
class SubInfoAttachData
{
    /**
     * @var object Skinny Application instance
     */
    protected $app = null;

    /**
     * @var object FeatureACL
     */
    protected $ftr = null;

    /**
     * @var object Database object for DB transactions
     */
    protected $DB  = null;

    /**
     * @var integer Tenant ID
     */
    protected $tenantID = null;

    /**
     * @var integer Case ID
     */
    protected $caseID   = null;

    /**
     * Constructor - initialization
     *
     * @param integer         $tenantID clientProfile.id from session
     * @param integer         $caseID   cases.id
     * @param \Lib\FeatureACL $ftr      FeatureACL
     *
     * @return void
     */
    public function __construct($tenantID, $caseID, $ftr = null)
    {
        \Xtra::requireInt($tenantID);
        \Xtra::requireInt($caseID);

        $this->app = \Xtra::app();
        $this->ftr = (!empty($ftr)
            ? $ftr
            : \Xtra::app()->ftr);
        $this->DB = $this->app->DB;

        $this->tenantID = $tenantID;
        $this->caseID   = $caseID;
    }

    /**
     * Get an array of attachments.
     *
     * @note Do we need to do a join if we are not selecting anything from docCategory?
     *
     * @return array An array of subInfoAttach object rows.
     */
    public function getAttachments()
    {
        $sql = "SELECT subinfo.id, subinfo.description, subinfo.filename, subinfo.fileType, "
            . "subinfo.fileSize, subinfo.caseStage, category.name FROM subInfoAttach "
            . "AS subinfo LEFT JOIN docCategory AS category on "
            . "subinfo.catID = category.id WHERE subinfo.caseID=:caseID ORDER BY `description`";
        $attachments = $this->DB->fetchObjectRows($sql, [':caseID' => $this->caseID]);
        $clientFileManagement = new ClientFileManagement();
        foreach ($attachments as $i => $attachment) {
            $fileType = $clientFileManagement->getFileTypeDescriptionByFileName($attachment->filename);
            $attachments[$i]->tooltip = "File: ".$attachment->filename.", Size: ".$attachment->fileSize." bytes, Type: "
                .$fileType;
        }
        return $attachments;
    }

    /**
     * Gets an array of document categories assigned to a tenantID.
     *
     * @param integer $tenantID   docCategory.clientID
     * @param boolean $activeOnly return only active categories if true (default)
     *
     * @note following best practices may dictate moving this function elsewhere.
     *
     * @return array An array of docCategory objects.
     */
    public function getDocumentCategories($tenantID = null, $activeOnly = true)
    {
        $tenantID = ($tenantID ?: $this->tenantID);
        $sql = "SELECT id, name FROM docCategory WHERE clientID=:clientID ";
        if ($activeOnly) {
            $sql .= "AND active <> 0 ";
        }
        $sql .= "ORDER BY name ASC";
        if (!($docCategories = $this->DB->fetchObjectRows($sql, [':clientID' => $tenantID]))) {
            /**
             * Prevent against empty Document Categories by inserting a record to client's empty
             * docCategory table. A more effective way to do this would be a client creation interface
             */
            if (isset($this->tenantID) && $this->tenantID == $tenantID && $tenantID > 0) {
                $this->DB->query(
                    "INSERT INTO docCategory (clientID, name, active) VALUES (:clientID, 'General', 1)",
                    [':clientID' => $tenantID]
                );
                $docCategories = $this->DB->fetchObjectRows($sql, [':clientID' => $tenantID]);
            }
        }
        return $docCategories;
    }

    /**
     * Delete a case subInfoAttach record, remove associated file and create userLog record.
     *
     * @param integer $id     subInfoAttach.id of the record to be deleted
     * @param integer $userID users.id of currently logged in user
     *
     * @note $subInfoAtt was named $subInfoAttach but caused line lengths to exceed maximum length.
     * @todo use the SubInfoAttach database model and move some logic to a delete function.
     * @todo confirm $this->caseID and $subInfoAtt->caseID are the same or different.
     *
     * @return boolean
     */
    public function deleteAttachment($id, $userID)
    {
        \Xtra::requireInt($id);
        \Xtra::requireInt($userID);
        $sql = "SELECT id, caseID, description, filename FROM subInfoAttach WHERE id=:id";
        $subInfoAtt = $this->DB->fetchObjectRow($sql, [':id' => $id]);
        if (!empty($subInfoAtt) && isset($subInfoAtt->caseID)) { // && !empty($subInfoAtt->caseID)?
            $case = (new Cases($this->tenantID))->findById($subInfoAtt->caseID); // $this->caseID?
            if (!empty($case) && $this->ftr->legacyAccessLevel > Security::READ_ONLY) {
                $sql = "DELETE FROM subInfoAttach WHERE id=:id AND caseID=:caseID AND caseStage>=:caseStage LIMIT 1";
                $params = [':id' => $id, ':caseID' => $subInfoAtt->caseID, ':caseStage' => $case->get('caseStage')];
                $result = $this->DB->query($sql, $params);
                if ($result->rowCount() > 0) {
                    (new UpDnLoadFile(['type' => 'delete']))->removeClientFile(
                        'subInfoAttach',
                        $this->tenantID,
                        $subInfoAtt->id
                    );
                    (new LogData($this->tenantID, $userID))->saveLogEntry(
                        28,
                        "`$subInfoAtt->filename` removed from case folder, "
                            . "description: `$subInfoAtt->description`",
                        $case->get('id')
                    );
                    return true;
                }
            }
        }
        return false;
    }
}
