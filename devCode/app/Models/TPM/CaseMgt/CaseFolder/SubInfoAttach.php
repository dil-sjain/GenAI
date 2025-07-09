<?php
/**
 * Model for [tenantDB].subInfoAttach
 *
 * @refactor of Legacy Add Case modal.
 */

namespace Models\TPM\CaseMgt\CaseFolder;

/**
 * SubInfoAttach model, created for Attachments dialog in Add Case modal.
 *
 * @keywords add case dialog, subInfoAttach
 */
#[\AllowDynamicProperties]
class SubInfoAttach
{
    /**
     * Application instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * Client database name
     *
     * @var string
     */
    private $tenantDB = null;

    /**
     * Database instance
     *
     * @var object
     */
    protected $DB = null;

    /**
     * Tenant ID
     *
     * @var integer
     */
    protected $tenantID = null;

    /**
     * Init class constructor
     *
     * @param int $tenantID logged in tenantID
     */
    public function __construct($tenantID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->tenantDB = $this->DB->getClientDB($tenantID);
        $this->tenantID = $tenantID;
    }

    /**
     * Validates initial subInfoAttach data.
     *
     * @param array $data subInfoAttach record data to insert
     *
     * @return bool
     */
    public function validate($data)
    {
        $valid      = (is_array($data));
        $required   = [
            'caseID',
            'description',
            'filename',
            'fileType',
            'fileSize',
            'caseStage',
            'catID',
        ];

        if ($valid) {
            foreach ($required as $column) {
                if (!isset($data[$column])) {
                    $valid = false;
                    break;
                }
                switch ($column) {
                    case 'caseID':
                    case 'caseStage':
                    case 'fileSize':
                    case 'catID':
                        if (empty($data[$column]) || !is_numeric($data[$column])) {
                            $valid = false;
                        }
                        break;
                    case 'description':
                    case 'fileType':
                    case 'filename':
                        if (empty($data[$column])) {
                            $valid = false;
                        }
                        break;
                    default:
                        $valid = false;
                        break;
                }
            }
        }
        return $valid;
    }

    /**
     * Stores subInfoAttach data as a new record
     *
     * @param array $data subInfoAttach data
     *
     * @return mixed returns integer id of last inserted record.
     */
    public function store($data)
    {
        $this->app->DB->query(
            "INSERT INTO {$this->tenantDB}.subInfoAttach SET "
            .   "caseID = :caseID,"
            .   "description = :description,"
            .   "filename = :filename,"
            .   "fileType = :fileType,"
            .   "fileSize = :fileSize,"
            .   "contents = :contents,"
            .   "ownerID  = :ownerID,"
            .   "caseStage = :caseStage,"
            .   "catID = :catID,"
            .   "emptied = 1,"
            .   "clientID = :clientID",
            [
                ':caseID'        =>  $data['caseID'],
                ':description'   =>  substr((string) $data['description'], 0, 255),
                ':filename'      =>  $data['filename'],
                ':fileType'      =>  $data['fileType'],
                ':fileSize'      =>  $data['fileSize'],
                ':contents'      =>  '',
                ':ownerID'       =>  $data['ownerID'],
                ':caseStage'     =>  $data['caseStage'],
                ':catID'         =>  $data['catID'],
                ':clientID'      =>  $this->tenantID
            ]
        );

        return $this->app->DB->lastInsertId();
    }

    /**
     * Handles user modifications / edits to an attachment.
     *
     * @param int    $id          unique identifier of attachment subInfoAttach.id
     * @param string $description of attachment
     * @param int    $category    of attachment
     * @param int    $caseID      cases.ID
     *
     * @return array of all attachments to update the data table.
     */
    public function update($id, $description, $category, $caseID)
    {
        if (!empty($category) && !empty($description) && is_numeric($id) && is_numeric($caseID)) {
            $this->app->DB->query(
                "UPDATE {$this->tenantDB}.subInfoAttach SET description = :description,"
                .   " catID = :catID WHERE id = :id AND caseID = :caseID",
                [
                    ':description' => substr($description, 0, 255),
                    ':catID'       => (int)$category,
                    ':id'          => (int)$id,
                    ':caseID'      => (int)$caseID,
                ]
            );
        }
    }

    /**
     * Deletes a subInfoAttach record.:wq

     *
     * @param int $id     unique identifier of attachment subInfoAttach.id
     * @param int $caseID cases.id attached to subInfoAttach record
     *
     * @return void
     */
    public function delete($id, $caseID)
    {
        if ($id && $caseID && is_numeric($id) && is_numeric($caseID)) {
            $this->app->DB->query(
                "DELETE FROM {$this->tenantDB}.subInfoAttach WHERE id = :id AND caseID = :caseID",
                [
                    ":id"       =>  (int)$id,
                    ":caseID"   =>  (int)$caseID,
                ]
            );
        }
    }

    /**
     * Displays any attachments for the given caseID.
     *
     * @param int $caseID cases.id
     *
     * @return mixed array of attachments information or null
     */
    public function getAttachments($caseID)
    {
        return $this->app->DB->fetchAssocRows(
            "SELECT id, description, filename, fileType, catID, fileSize FROM {$this->tenantDB}.subInfoAttach\n"
            .   "WHERE caseID = :caseID",
            [
                ':caseID' => (int)$caseID,
            ]
        );
    }
}
