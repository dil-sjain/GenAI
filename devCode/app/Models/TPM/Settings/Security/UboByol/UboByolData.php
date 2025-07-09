<?php

/**
 * Provides data needed for UboByol Access management.
 *
 * @keywords UBO, UboByol Access
 */

namespace Models\TPM\Settings\Security\UboByol;

use Exception;
use Lib\Support\Xtra as Xtra;
use Skinny\Skinny;
use Lib\Database\MySqlPdo;

/**
 * Provides data needed for UboByol Access management.
 */
#[\AllowDynamicProperties]
class UboByolData
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
    private $table = 'g_uboClientApiCredentials';

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
     * Get the UboByol access credentials
     *
     * @return array
     */
    public function getCredentials()
    {
        try {
            $sql = "SELECT id, AES_DECRYPT(FROM_BASE64(apiUserName), '#pwKey#') as apiUserName,
               AES_DECRYPT(FROM_BASE64(apiPassword), '#pwKey#') as apiPassword FROM $this->table 
               WHERE clientID = :clientID LIMIT 1";
            return $this->DB->fetchAssocRow($sql, [':clientID' => $this->clientID], true);
        } catch (Exception $e) {
            throw new Exception('Error in reading UBO license credentials. Please contact system administrator.');
        }
    }

    /**
     * Save OR update UboByol access credentials
     *
     * @param string $apiUserName UboByol apiUserName
     * @param string $apiPassword UboByol apiPassword
     *
     * @return int
     *
     * @throws Exception If error occurs during database query
     */
    public function upsertCredentials(string $apiUserName, string $apiPassword)
    {
        $uboByol = 0;
        $uboByloCredentials = $this->getCredentials();
        if (!$uboByloCredentials) {
            $uboByol = $this->saveCredentials($apiUserName, $apiPassword);
        } else {
            $uboByol = $this->updateCredentials($apiUserName, $apiPassword);
        }
        return $uboByol;
    }

    /**
     * Save the UboByol access credentials
     *
     * @param string $apiUserName UboByol apiUserName
     * @param string $apiPassword UboByol apiPassword
     *
     * @return int Last insert ID
     *
     * @throws Exception If error occurs during database query
     */
    private function saveCredentials(string $apiUserName, string $apiPassword)
    {
        try {
            $sql = "INSERT INTO $this->table SET clientID= :cid, "
            . "apiUserName=TO_BASE64(AES_ENCRYPT(:apiName, '#pwKey#')), "
            . "apiPassword=TO_BASE64(AES_ENCRYPT(:apiPass, '#pwKey#')) ";
            $values = array(
                ':cid' => $this->clientID,
                ':apiName' => $apiUserName,
                ':apiPass' => $apiPassword,
            );
            $this->DB->query($sql, $values, true);
            return $this->DB->lastInsertId();
        } catch (Exception $e) {
            throw new Exception('Error saving UBO license credentials. Please contact system administrator.');
        }
    }

    /**
     * Update the UboByol access credentials
     *
     * @param string $apiUserName apiUserName
     * @param string $apiPassword apiPassword
     *
     * @return boolean
     */
    private function updateCredentials(string $apiUserName, string $apiPassword)
    {
        try {
            $return = 0;
            $sql = "UPDATE $this->table SET "
            . "apiUserName=TO_BASE64(AES_ENCRYPT(:apiName, '#pwKey#')), "
            . "apiPassword=TO_BASE64(AES_ENCRYPT(:apiPass, '#pwKey#')) WHERE clientID = :cid LIMIT 1";
            $values = array(
                ':cid' => $this->clientID,
                ':apiName' => $apiUserName,
                ':apiPass' => $apiPassword,
            );

            if ($this->DB->query($sql, $values, true)) {
                $return = 1;
            }
            return $return;
        } catch (Exception $e) {
            throw new Exception('Error saving UBO license credentials. Please contact system administrator.');
        }
    }
}
