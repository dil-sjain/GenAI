<?php
/**
 * Trait for cloning trainings, training attachments for use with clone wizard
 *
 * @keywords clone wizard, 3p, clone, third party management, multi tenant, trainings, training attach
 */
namespace Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard;

use Models\TPM\Admin\ThirdPartyInit\ThirdPartyInitData;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\ClientFileManagement;

/**
 * Trait Training
 *
 * @package Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard
 */
trait TrainingTrait
{

    private $cloneTrainingID;       //  Unique identifier, `training`.id of record to clone
    private $newTrainingID;         //  new `training`.id of record copy made from clone
    private $newTrainingDataID;     //  new `trainingData`.id of record copy made from clone
    private $ThirdPartyInitData;    //  Class needed to generate both `userTpNum` and `userTaNum`
    private $newTppID;              //  `thirdPartyProfile`.id of new profile
    private $FileManager;           //  Class needed to manipulate client files

    /**
     * Clones the trainings
     *
     * @param integer $trainingID `training`.id of record to clone
     * @param integer $tppID      `thirdPartyProfile`.id of new profile
     *
     * @return int
     */
    public function performTrainingClones($trainingID, $tppID)
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $this->FileManager        = new ClientFileManagement();
        $this->cloneTrainingID    = $trainingID;
        $this->newTppID           = $tppID;
        $this->ThirdPartyInitData = new ThirdPartyInitData();
        $this->newTrainingID      = $this->cloneTraining();     //  clones record    in `training`       table
        $this->newTrainingDataID  = $this->cloneTrainingData(); //  clones record(s) in `trainingData`   table
        $this->cloneTrainingAttachments();                      //  clones record(s) in `trainingAttach` table

        return $this->newTrainingID;                            //  new `training`.id
    }


    /**
     * Gets the `training` table record(s) filtered by thirdPartyProfile.id & clientID
     * table  `training`
     *
     * used:    Used by CloneWizardTPP to display trainings to user in Source Details form
     *
     * @return array
     */
    public function getTrainings()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        return $this->app->DB->fetchAssocRows(
            "SELECT * FROM {$this->cloneTenantDB}.training WHERE tpID = :tpID AND clientID = :clientID",
            [
            ':tpID'      =>  $this->tables['clone']['thirdPartyProfile']->id,
            ':clientID'  =>  $this->cloneTenantID,
            ]
        );
    }


    /**
     * Gets a `training` table record filtered by thirdPartyProfile.id & clientID
     * table   `training`
     *
     * used: The $trainingID comes from $_POST on the review page. It is used to select the training record
     *          so that it can be cloned.
     *
     * @return  array
     */
    public function getTrainingByID()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $sql = "SELECT * FROM {$this->cloneTenantDB}.training WHERE tpID = :tpID AND clientID = :clientID AND id = :id";

        $bind = [
            ':tpID'     =>  $this->tables['clone']['thirdPartyProfile']->id,
            ':clientID' =>  $this->cloneTenantID,
            ':id'       =>  $this->cloneTrainingID,
        ];

        return $this->app->DB->fetchAssocRow($sql, $bind);
    }


    /**
     * Clones the 'training' associated with a third party profile
     * table  `training`
     *
     * used: Clones the `training` record.
     *
     * @return mixed
     */
    public function cloneTraining()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $trainingRecord = $this->getTrainingByID();

        $sql = "INSERT INTO {$this->tenantDB}.training SET\n"
            .   "clientID       = :clientID ,\n"
            .   "tpID           = :tpID ,\n"
            .   "name           = :name ,\n"
            .   "hide           = :hide ,\n"
            .   "provider       = :provider ,\n"
            .   "providerType   = :providerType ,\n"
            .   "linkToMaterial = :linkToMaterial ,\n"
            .   "trainingType   = :trainingType ,\n"
            .   "description    = :description ,\n"
            .   "created        = :created";

        $bind = [
            ':clientID'       => $this->tenantID,
            ':tpID'           => $this->newTppID,
            ':name'           => $trainingRecord['name'],
            ':hide'           => $trainingRecord['hide'],
            ':provider'       => $trainingRecord['provider'],
            ':providerType'   => $trainingRecord['providerType'],
            ':linkToMaterial' => $trainingRecord['linkToMaterial'],
            ':trainingType'   => $this->getTrainingTypeID(),
            ':description'    => $trainingRecord['description'],
            ':created'        => date('Y-m-d H:i:s'),
        ];

        try {
            $this->app->DB->query($sql, $bind);
            return $this->app->DB->lastInsertId();
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: '.$e->getMessage());
            return false;
        }
    }


    /**
     * Gets the training data
     * // db.trainingData
     * used: `trainingData`.trainingID == `training`.id
     *
     * @return  mixed
     */
    public function getTrainingData()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $sql = "SELECT * FROM {$this->cloneTenantDB}.trainingData WHERE\n"
        .      "clientID = :clientID AND tpID = :tpID AND trainingID = :trainingID";

        $bind = [
            ':clientID'     => $this->cloneTenantID,
            ':tpID'         => $this->tables['clone']['thirdPartyProfile']->id,
            ':trainingID'   => $this->cloneTrainingID,
        ];

        return $this->app->DB->fetchAssocRow($sql, $bind);
    }


    /**
     * Clones trainingData
     * table: db.`trainingData`
     *
     * used: Clones the `trainingData` record associated to the `training` record.
     *
     * @return  mixed (int) `trainingData`.id of new record
     */
    public function cloneTrainingData()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $record = $this->getTrainingData();

        $sql  = "INSERT INTO {$this->cloneTenantDB}.trainingData SET\n";
        $sql .= "clientID     = :clientID,\n";
        $sql .= "tpID         = :tpID,\n";
        $sql .= "trainingID   = :trainingID,\n";
        $sql .= "userTrNum    = :userTrNum,\n";
        $sql .= "dateProvided = :dateProvided,\n";
        $sql .= "location     = :location";

        $bind = [
            ':clientID'     => $this->tenantID,
            ':tpID'         => $this->newTppID,
            ':trainingID'   => $this->newTrainingID,
            ':userTrNum'    => $this->ThirdPartyInitData->nextThirdPartyUserNum('trainingData'),
            ':dateProvided' => $record['dateProvided'],
            ':location'     => $record['location'],
        ];

        try {
            if (!$this->app->DB->query($sql, $bind)) {
                return false;
            } else {
                return $this->app->DB->lastInsertId();
            }
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: '.$e->getMessage());
            return false;
        }
    }


    /**
     * Gets the trainingAttach records associated with a third party profile
     * table: db.trainingAttach
     * used: Retrieves the `trainingAttach` records to be cloned.
     *
     * @return  array
     */
    public function getTrainingAttachments()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $sql  = "SELECT * FROM {$this->cloneTenantDB}.trainingAttach WHERE clientID = :clientID\n";
        $sql .= "AND tpID = :tppID AND trainingID = :trainingID";

        $bind = [
            ':clientID'   => (int)$this->cloneTenantID,
            ':tppID'      => (int)$this->tables['clone']['thirdPartyProfile']->id,
            ':trainingID' => (int)$this->cloneTrainingID,
        ];

        return $this->app->DB->fetchAssocRows($sql, $bind);
    }


    /**
     * Clones training attachments
     * table: db.trainingAttach
     *
     * used: Clones the `trainingAttach` records
     *
     * @return  boolean
     */
    public function cloneTrainingAttachments()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $sql  = "INSERT INTO {$this->tenantDB}.trainingAttach SET\n";
        $sql .= "clientID           = :clientID\n,";
        $sql .= "tpID               = :tpID\n,";
        $sql .= "trainingID         = :trainingID\n,";
        $sql .= "trainingAttachType = :trainingAttachType\n,";
        $sql .= "creationStamp      = :creationStamp\n,";
        $sql .= "ownerID            = :ownerID\n,";
        $sql .= "trainingDataID     = :trainingDataID,";
        $sql .= "userTaNum          = :userTaNum\n,";
        $sql .= "description        = :description\n,";
        $sql .= "filename           = :filename\n,";
        $sql .= "fileType           = :fileType\n,";
        $sql .= "fileSize           = :fileSize\n,";
        $sql .= "contents           = :contents\n,";
        $sql .= "emptied            = :emptied";

        $bind = [
                ':clientID'           =>  $this->tenantID,
                ':tpID'               =>  $this->newTppID,
                ':trainingID'         =>  $this->newTrainingID,
                ':trainingAttachType' =>  $this->getTrainingAttachTypeID(),
                ':creationStamp'      =>  date('Y-m-d H:i:s'),
                ':ownerID'            =>  $this->userID,
                ':trainingDataID'     =>  $this->newTrainingDataID,
        ];

        foreach ($this->getTrainingAttachments() as $record) {
            $bind[':userTaNum']   = $this->ThirdPartyInitData->nextThirdPartyUserNum('trainingAttach');
            $bind[':description'] = $record['description'];
            $bind[':filename']    = $record['filename'];
            $bind[':fileType']    = $record['fileType'];
            $bind[':fileSize']    = $record['fileSize'];
            $bind[':contents']    = $record['contents'];
            $bind[':emptied']     = $record['emptied'];

            try {
                $clone = $this->app->DB->query($sql, $bind);
                if ($clone->rowCount() > 0) {
                    $newRecID = $this->app->DB->lastInsertId();

                    $this->FileManager->cloneFileAttachment(
                        'trainingAttach',
                        $this->cloneTenantID,
                        $this->tenantID,
                        $record['id'],
                        $newRecID
                    );
                    $this->FileManager->putClientInfoFile('trainingAttach', $this->tenantID, $newRecID);
                } else {
                    return false;
                }
            } catch (\Exception $e) {
                $this->app->log->error('Caught exception: '.$e->getMessage());
                return false;
            }
        }
        return true;
    }


    /**
     * Gets the training type id for Cloned Training, if it does not exist the function inserts a new row
     * table: db.trainingType
     *
     * @return (int) `trainingType`.id (`training`.trainingType)
     */
    private function getTrainingTypeID()
    {
        $sql  = [
            'select' => "SELECT id FROM {$this->tenantDB}.trainingType WHERE clientID = :clientID AND name = :name AND active = :active",
            'insert' => "INSERT INTO {$this->tenantDB}.trainingType SET clientID = :clientID, name = :name, active = :active ",
            'update' => "UPDATE {$this->tenantDB}.trainingType SET active = :active WHERE clientID = :clientID AND name = :name ",
        ];
        $bind = [
            ':clientID' =>  $this->tenantID,
            ':name'     =>  'Cloned Training',
            ':active'   =>  '1',
        ];
        $id   = $this->app->DB->fetchValue($sql['select'], $bind);

        if (empty($id)) {
            $checkSql  = "SELECT id FROM {$this->tenantDB}.trainingType WHERE clientID = :clientID AND name = :name ";
            $checkBind = [
                ':clientID' =>  $this->tenantID,
                ':name'     =>  'Cloned Training',
            ];
            $checkId   = $this->app->DB->fetchValue($checkSql['select'], $checkBind);

            if (empty($checkId)) {
                $this->app->DB->query($sql['insert'], $bind);
                $id = $this->app->DB->lastInsertId();
            } else {
                $this->app->DB->query($sql['update'], $bind);
                $id = $checkId;
            }
        }

        return $id;
    }


    /**
     * Returns the trainingAttach.trainingAttachType specific to cloning attachments
     * table `trainingAttachType`
     *
     * @return  mixed
     */
    public function getTrainingAttachTypeID()
    {
        if (!$this->canAccessTrainings()) {
            return false;
        }
        $sql  = [
            'select' => "SELECT id FROM {$this->tenantDB}.trainingAttachType WHERE clientID = :clientID "
            . "AND name = :name",
            'insert' => "INSERT INTO {$this->tenantDB}.trainingAttachType SET clientID = :clientID, name = :name",
        ];
        $bind = [
            ':clientID' =>  $this->tenantID,
            ':name'     =>  'Cloned Training',
        ];
        $id   = $this->app->DB->fetchValue($sql['select'], $bind);

        if (empty($id)) {
            $this->app->DB->query($sql['insert'], $bind);
            $id = $this->app->DB->lastInsertId();
        }

        return $id;
    }

    /**
     * Determines if a user can access trainings - users that cannot should not see / be able to clone trainings
     *
     * @return boolean
     */
    private function canAccessTrainings()
    {
        if ($this->app->ftr->has(\Feature::TENANT_TPM_TRAINING) && $this->app->ftr->has(\Feature::TP_TRAINING_ADD)
            || $this->app->ftr->has(\Feature::TENANT_TPM_TRAINING) && $this->app->ftr->has(\Feature::TP_TRAINING_ADD)
        ) {
            return true;
        }
        return false;
    }
}
