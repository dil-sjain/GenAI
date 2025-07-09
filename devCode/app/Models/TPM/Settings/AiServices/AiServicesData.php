<?php

/**
 * Provides data needed for AiServices Access management.
 *
 * @keywords AiServices, AiServices Access
 */

namespace Models\TPM\Settings\AiServices;

use Exception;
use Lib\Support\Xtra as Xtra;
use Skinny\Skinny;
use Lib\Database\MySqlPdo;

/**
 * Provides data needed for AiServices Access management.
 */
#[\AllowDynamicProperties]
class AiServicesData
{
    /**
    * @var Skinny Reference to static app instance
     */
    private Skinny $app;

    /**
     * @var integer client id
     */
    private $clientID;

    /**
     * @var string Table name
     */
    private $table = 'userFeatureEnablement';

    /**
     * @var MySqlPdo reference to data base instance
     */
    private MySqlPdo $DB;

    /**
     * Constructor - initialization
     */
    public function __construct()
    {
        $this->app      = Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientID = $this->app->session->get('clientID');
        $this->table    = $this->DB->globalDB . '.' . $this->table;
    }

    /**
     * Get Feature and consent enable disable by user and feature
     *
     * @param integer $userID    users.id
     * @param array   $featureID featureID
     *
     * @return array
     */
    public function getFeatureDetail($userID, $featureID)
    {
        try {
            $sql = "SELECT actionPerformed, featureEnabled FROM $this->table 
               WHERE userId = :userID AND featureId = :featureID LIMIT 1";
            return $this->DB->fetchAssocRow($sql, [':userID' => $userID, ':featureID' => $featureID]);
        } catch (Exception $e) {
            throw new Exception('Error! Please contact system administrator.');
        }
    }

    /**
     * Get AI_POPUP ID
     *
     * @return array
     */
    public function getAiPopupId()
    {
        try {
            $sql = "SELECT id FROM {$this->DB->globalDB}.notificationsType  
               WHERE name = :name LIMIT 1";
            return $this->DB->fetchValue($sql, [':name' => 'AI_POPUP']);
        } catch (Exception $e) {
            throw new Exception('Error! Please contact system administrator.');
        }
    }
}
