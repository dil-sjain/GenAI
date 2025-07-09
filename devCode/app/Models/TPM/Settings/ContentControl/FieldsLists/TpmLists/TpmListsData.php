<?php
/**
 * Model for TPM Fields/Lists - Skeleton Base Class
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\LogData;

/**
 * Class handles generic Fields/Lists model requirements to prevent duplication of code.
 * This class is the base from which all other classes extend.
 *
 * @keywords tpm, fields lists, model, content control, settings
 */
#[\AllowDynamicProperties]
class TpmListsData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    protected $DB = null;

    /**
     * @var object LogData instance
     */
    protected $LogData = null;

    /**
     * @var integer The current tenantID
     */
    protected $tenantID = 0;

    /**
     * @var integer The current userID
     */
    protected $userID = 0;

    /**
     * @var object Tables used by the model.
     */
    protected $tbl = null;

    /**
     * @var array Translation text array
     */
    protected $txt = [];


    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $userID     Current userID
     * @param array   $initValues Any additional parameters that need to be passed in
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($tenantID, $userID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;
        $this->userID   = (int)$userID;
        if ($this->tenantID <= 0) {
            throw new \InvalidArgumentException("The tenantID must be a positive integer.");
        } elseif ($this->userID <= 0) {
            throw new \InvalidArgumentException("The userID must be a positive integer.");
        }

        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;
        $this->tbl = (object)null;
        $this->initValues();
        $this->setupTableNames();
        $this->LogData = new LogData($this->tenantID, $this->userID);
    }

    /**
     * Generic response method to ensure class is loaded.
     *
     * @return boolean true
     */
    public function isLoaded()
    {
        return true;
    }

    /**
     * initialize basic values
     *
     * @return void
     */
    private function initValues()
    {
        $this->selectParams[':clientID'] = $this->tenantID;
    }

    /**
     * Setup required table names in the tbl object. This is a stub method which extending classes overwrite.
     *
     * @return void
     */
    protected function setupTableNames()
    {
        return;
    }

    /**
     * Check if name already exists. No dups allowed.
     *
     * @param string $name Name to lookup
     * @param string $tbl  Table to use for lookup
     *
     * @return boolean True if name exists, else false.
     */
    protected function nameExists($name, $tbl)
    {
        $tbl = $this->DB->esc($tbl);
        $sql = "SELECT COUNT(*) FROM {$tbl} WHERE clientID= :clientID AND name= :name";
        $params = [':clientID' => $this->tenantID, ':name' => $name];
        if ($this->DB->fetchValue($sql, $params)) {
            return true;
        }
        return false;
    }

    /**
     * Method to set translation text values needed for this class.
     * Allows controller/caller to do a single call for translations
     * vice each file on its own.
     *
     * @param array $txt Array of translated text items.
     *
     * @return void
     */
    public function setTrText($txt)
    {
        if (is_array($txt) && !empty($txt)) {
            $this->txt = $txt;
        }
    }
}
