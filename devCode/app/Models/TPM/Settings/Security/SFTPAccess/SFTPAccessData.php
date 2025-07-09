<?php
/**
 * Provides data needed for SFTP Access management.
 *
 * @keywords SFTP, SFTP Access
 */

namespace Models\TPM\Settings\Security\SFTPAccess;

use Lib\Database\MySqlPdo;
use Lib\Support\Xtra;
use Models\LogData;
use PDOException;
use Exception;
use RuntimeException;
use SimpleSAML\Module\core\Stats\Output\Log;

/**
 * Provides data needed for SFTP Access management.
 */
#[\AllowDynamicProperties]
class SFTPAccessData
{
    /**
     * @var int client id
     */
    private $clientID;

    /**
     * @var string Client database
     */
    private $clientDB;

    /**
     * @var int user id
     */
    private $userID;

    /**
     * @var MySqlPdo Class instance
     */
    private MySqlPdo $DB;

    /**
     * @var string Table name
     */
    private string $accessTable = 'sftpAccess';

    /**
     * @var string Base directory for client space on CrushFTP
     */
    private string $subscriberBaseDir;

    /**
     * @var LogData Class instance to add audit log entries
     */
    private LogData $auditLog;

    /**
     * Constructor - initialization
     *
     * @param int $clientID TPM tenant If not provided, value is read from session
     */
    public function __construct($clientID = 0)
    {
        $app = Xtra::app();
        $this->DB = $app->DB;
        if (empty($clientID)) {
            $this->clientID = $app->session->get('clientID');
        } else {
            $this->clientID = (int)$clientID;
        }
        $this->userID = $app->session->get('authUserID');
        $this->clientDB = $this->DB->getClientDB($this->clientID);
        $this->accessTable = "$this->clientDB.$this->accessTable";
        if ($baseDir = getenv('SFTP_BaseString')) {
            $this->subscriberBaseDir = rtrim($baseDir, '/') . "/subscribers/";
        } else {
            throw new RuntimeException("Missing 'SFTP_BaseString' in environment.");
        }
        $this->auditLog = new LogData($this->clientID, $this->userID);
    }

    /**
     * Check to see if the user is allowed access to SFTP.
     *
     * @return bool true if allowed access otherwise false
     */
    public function allowedAccess()
    {
        $sql = "SELECT id FROM $this->accessTable "
        . "WHERE contactUserID = :uid AND clientID = :cid LIMIT 1";
        $params = [':uid' => $this->userID, ':cid' => $this->clientID];
        return !empty($this->DB->fetchValue($sql, $params));
    }

    /**
     * List rows of users with access to SFTP
     *
     * @return array|false of sftp access data objects if any were found or false otherwise
     */
    public function listAccess()
    {
        $sql = "SELECT locationName, id FROM $this->accessTable "
        . "WHERE contactUserID=:uid "
        . "AND clientID=:cid";
        $params = [':uid' => $this->userID, ':cid' => $this->clientID];
        $rows = $this->DB->fetchObjectRows($sql, $params);

        return $rows ? $rows : false;
    }

    /**
     * Grab a user's password form the global users table. Authenticated by the controller
     *
     * @return string|null encrypted password for the user wanting access
     */
    public function authUserID()
    {
        $sql = "SELECT userpw FROM {$this->DB->authDB}.users WHERE id = :uid LIMIT 1";
        $params = [':uid' => $this->userID];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Returns the sftp credentials for a specific user (user must have passed authentication)
     *
     * @param int $rowid sftpAccess.id (the primary key for the row of credentials)
     *
     * @return object|false of serialized credentials to the user with sftp access
     */
    public function getSftpCreds($rowid)
    {
        $sql = "SELECT locationName, AES_DECRYPT(unamePass,'#pwKey#') AS encCred "
            . "FROM $this->accessTable "
            . "WHERE id=:rid AND contactUserID=:uid AND clientID=:cid "
            . "LIMIT 1";
        $params = [':rid' => $rowid, ':uid' => $this->userID, ':cid' => $this->clientID];
        return $this->DB->fetchObjectRow($sql, $params, true);
    }

    /**
     * Get displayable information from sftpAccess table for access under /subscribers/
     *
     * @return array
     */
    public function getSftpAccessRecords()
    {
        $sql = "SELECT a.id, a.clientID, AES_DECRYPT(a.unamePass, '#pwKey#') as serializedObj, "
            . "a.clientDir, u.id `pocID`, u.userName pocName, u.status pocStatus "
            . "FROM $this->accessTable a "
            . "INNER JOIN {$this->DB->authDB}.users u ON u.id = a.contactUserID "
            . "WHERE a.clientID = :clientID ";
        $params = [':clientID' => $this->clientID];
        $userDirMap = [];
        try {
            if ($rows = $this->DB->fetchAssocRows($sql, $params, true)) {
                foreach ($rows as $r) {
                    if ($obj = unserialize($r['serializedObj'])) {
                        $user = $obj->username;
                        if (strpos($r['clientDir'], $this->subscriberBaseDir) !== false) {
                            $sftpDir = '(missing)';
                            if ($dir = trim(substr($r['clientDir'], strlen($this->subscriberBaseDir)), '/')) {
                                $sftpDir = $dir;
                            }
                            $userDirMap[] = [
                                'sftpAccessID' => $r['id'],
                                'clientID' => $r['clientID'],
                                'sftpUser' => $user,
                                'sftpDir' => $sftpDir,
                                'fullDir' => $r['clientDir'],
                                'pocID' => $r['pocID'],
                                'pocName' => $r['pocName'],
                                'pocStatus' => $r['pocStatus'],
                            ];
                        }
                    }
                }
            }
        } catch (PDOException | Exception $e) {
            Xtra::track($e->getMessage() . "\nSQL: " . $this->DB->mockFinishedSql($sql, $params));
        }
        return $userDirMap;
    }

    /**
     * Insert or update SFTP access record. Sync other users, if present in sftpAccess.
     *
     * @param int    $clientAdmin   Designated Client Admin - POC for SFTP credentials
     * @param string $sftpDirectory case-sensitive directory name under /subscribers/ on CrushFPT
     * @param string $sftpUser      SFTP username
     * @param string $sftpPassword  SFTP password
     *
     * @return void
     */
    public function upsertAccessRecord(
        int $clientAdmin,
        string $sftpDirectory,
        string $sftpUser,
        string $sftpPassword
    ): array {
        $result = [
            'inserted' => 0,
            'updated' => 0,
            'assignments' => [],
            'error' => '',
        ];
        // get existing records
        $clientAdminHasRecord = 0;
        $clientAdminName = '';
        if ($existing = $this->getSftpAccessRecords()) {
            foreach ($existing as $record) {
                if ($clientAdmin === $record['pocID']) {
                    $clientAdminHasRecord = $record['sftpAccessID'];
                    $clientAdminName = $record['pocName'];
                    break;
                }
            }
        }
        $serializedObj = serialize((object)['username' => $sftpUser, 'password' => $sftpPassword]);
        $params = $commonParams = [
            ':loc' => $sftpDirectory,
            ':dir' => $this->subscriberBaseDir . $sftpDirectory,
            ':unamePass' => $serializedObj,
        ];
        $updateSql = "UPDATE $this->accessTable SET "
            . "locationName = :loc, "
            . "locationDescription = 'Secure Data Transfer', "
            . "clientDir = :dir, "
            . "unamePass = AES_ENCRYPT(:unamePass, '#pwKey#') "
            . "WHERE id = :recordID LIMIT 1";
        if ($clientAdminHasRecord) {
            // update
            $sql = $updateSql;
            $params[':recordID'] = $clientAdminHasRecord;
            $logMessage = "Update SFTP credetials for `$clientAdminName`";
        } else {
            // insert
            $sql = "INSERT INTO $this->accessTable SET "
                . "clientID = :cid, "
                . "processID = 0, "
                . "locationName = :loc, "
                . "locationDescription = 'Secure Data Transfer', "
                . "contactUserID = :poc, "
                . "clientDir = :dir, "
                . "unamePass = AES_ENCRYPT(:unamePass, '#pwKey#')";
            $params[':cid'] = $this->clientID;
            $params[':poc'] = $clientAdmin;
            $userName = $this->getUserName($clientAdmin);
            $logMessage = "Added SFTP credentials for `$userName`";
        }
        try {
            // Upsert
            $this->DB->setSessionSyncWait(true);
            if ($response = $this->DB->query($sql, $params, true)) {
                if ($response->rowCount()) {
                    if ($clientAdminHasRecord) {
                        $result['updated']++;
                    } else {
                        $result['inserted']++;
                    }
                    $this->addAuditLog($logMessage);
                }
            }

            // Are there other records to sync?
            if ($existing) {
                foreach ($existing as $record) {
                    if ($record['sftpAccessID'] !== $clientAdminHasRecord) {
                        $params = $commonParams;
                        $params[':recordID'] = $record['sftpAccessID'];
                        if ($response = $this->DB->query($updateSql, $params, true)) {
                            if ($response->rowCount()) {
                                $result['updated']++;
                                $this->addAuditLog("Synchronized SFTP credentials for `{$record['pocName']}`");
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage() . "\n" . $this->DB->mockFinishedSql($sql, $params);
            $result['error'] = $error;
            Xtra::track($error);
        } finally {
            $result['assignments'] = $this->getSftpAccessRecords();
            $this->DB->setSessionSyncWait(false);
        }

        return $result;
    }

    /**
     * Remove an sftpAccess record by id
     *
     * @param int $recordID sftoAccess.id
     *
     * @return array
     */
    public function deleteAccessRecord(int $recordID): array
    {
        $result = [
            'deleted' => 0,
            'assignments' => [],
            'error' => '',
        ];
        try {
            $sql = "DELETE FROM $this->accessTable WHERE id = :id AND clientID = :cid LIMIT 1";
            $params = [':id' => $recordID, ':cid' => $this->clientID];
            $this->DB->setSessionSyncWait(true);
            $userName = $this->getUserNameFromAccessId($recordID);
            if ($response = $this->DB->query($sql, $params)) {
                if ($response->rowCount()) {
                    $result['deleted']++;
                    $this->addAuditLog("Removed SFTP credentials from `$userName`");
                }
            }
        } catch (PDOException | Exception $e) {
            $error = $e->getMessage() . "\n" . $this->DB->mockFinishedSql($sql, $params);
            $result['error'] = $error;
            Xtra::track($error);
        } finally {
            $result['assignments'] = $this->getSftpAccessRecords();
            $this->DB->setSessionSyncWait(false);
        }

        return $result;
    }

    /**
     * Add an Audit Log record
     *
     * @param string $message Log message
     *
     * @return void
     */
    private function addAuditLog(string $message): void
    {
        // 152 | Update Tenant-Facing Setting Value
        $this->auditLog->saveLogEntry(152, $message);
    }

    /**
     * Get name of user for log message
     *
     * @param int $userID cms.users.id
     *
     * @return string
     */
    private function getUserName(int $userID): string
    {
        $sql = "SELECT userName FROM {$this->DB->authDB}.users WHERE id = :id LIMIT 1";
        if (!($userName = $this->DB->fetchValue($sql, [':id' => $userID]))) {
            $userName = '(unknown)';
        }
        return $userName;
    }

    /**
     * Get userName from sftpAccess.id
     *
     * @param int $recordID sftpAccess.id
     *
     * @return string
     */
    private function getUserNameFromAccessId(int $recordID): string
    {
        $sql = "SELECT u.userName FROM $this->accessTable a "
            . "INNER JOIN {$this->DB->authDB}.users u ON u.id = a.contactUserID "
            . "WHERE a.id = :id LIMIT 1";
        if (!($userName = $this->DB->fetchValue($sql, [':id' => $recordID]))) {
            $userName = '(unknown)';
        }
        return $userName;
    }
}
