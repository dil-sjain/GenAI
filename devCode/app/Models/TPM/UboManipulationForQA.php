<?php
/**
 * UBO version data set manipulation for QA testing
 * @code
 * ./skinnycli Models.TPM.UboManipulationForQA::usage
 * @endcode
 */

namespace Models\TPM;

use Lib\Database\MySqlPdo;
use Lib\ApplicationRegion;
use Models\TPM\ThirdPartyMgt\ProfileDetail\UboSubTabData;
use Lib\Support\Xtra;
use Exception;

#[\AllowDynamicProperties]
class UboManipulationForQA
{
    /**
     * @var MySqlPdo PDO connection to TPM databases
     */
    private MySqlPdo $appPdo;

    /**
     * @var MySqlPdo PDO connection to UBO databases
     */
    private MySqlPdo $uboPdo;

    /**
     * @var string Database name
     */
    private string $uboDatabase;

    /**
     * @var string Database name
     */
    private string $uboTempDatabase;

    /**
     * @var string Database name
     */
    private string $globalDatabase;

    /**
     * Alter UBO record set for current DUNS version
     *
     * @param string $duns DUNS number for which to alter it's current version UBO data set
     *
     * @return void
     *
     * @throws Exception
     */
    public function alterCurrent(string $duns = ''): void
    {
        $latest = (new UboDnBApi())->latestUboVersion($duns);
        if ($latest['UBOid'] === 0) {
            echo "Invalid DUNS", PHP_EOL;
            return;
        }
        $toDelete = random_int(0, 4);
        $toUpdate = random_int(1, 5);
        $ownersTable = $this->uboDatabase . '.dunsBeneficialOwners';
        $deleted = 0;

        // Remove records
        if ($toDelete) {
            $sql = "SELECT memberId FROM $ownersTable WHERE UBOid = :setID ORDER BY RAND() LIMIT :limit";
            $params = [':limit' => $toDelete, ':setID' => $latest['UBOid']];
            if ($records = $this->uboPdo->fetchValueArray($sql, $params)) {
                $got = count($records);
                $sql = "DELETE FROM $ownersTable WHERE UBOid = :setID AND FIND_IN_SET(memberId, :members) LIMIT :limit";
                $params = [
                    ':setID' => $latest['UBOid'],
                    ':members' => implode(',', $records),
                    ':limit' => $got,
                ];
                if (($pdoResult = $this->uboPdo->query($sql, $params)) && $pdoResult->rowCount() === $got) {
                    $deleted = $got;
                    echo "Removed from DUNS $duns (v{$latest['version']}) memberId records: ",
                      implode(', ', $records), PHP_EOL;
                }
            }
        }

        // Update records
        $updated = [];
        $sql = "SELECT memberId FROM $ownersTable WHERE UBOid = :setID ORDER BY RAND() LIMIT :limit";
        $params = [':limit' => $toUpdate, ':setID' => $latest['UBOid']];
        if ($records = $this->uboPdo->fetchValueArray($sql, $params)) {
            $sql = "UPDATE $ownersTable SET\n"
                . "name = UPPER(name),\n"
                . "directOwnershipPercentage = :direct,\n"
                . "indirectOwnershipPercentage = :indirect,\n"
                . "beneficialOwnershipPercentage = :beneficial\n"
                . "WHERE UBOid = :setID AND memberId = :member LIMIT 1";
            foreach ($records as $member) {
                $params = [
                    ':setID' => $latest['UBOid'],
                    ':member' => $member,
                    ':direct' => random_int(1, 1000) / 100,
                    ':indirect' => random_int(1, 1000) / 100,
                    ':beneficial' => random_int(1, 1000) / 100,
                ];
                if (($pdoResult = $this->uboPdo->query($sql, $params)) && $pdoResult->rowCount()) {
                    $updated[] = $member;
                }
            }
            if ($updated) {
                echo "Updated DUNS $duns (v{$latest['version']}) memberId records: ",
                implode(', ', $updated), PHP_EOL;
            }
        }

        // Remove affected short-lived tables;
        if ($deleted || $updated) {
            $sql = "SHOW TABLES FROM $this->uboTempDatabase LIKE '%duns{$duns}%v{$latest['version']}%'";
            if ($tables = $this->uboPdo->fetchValueArray($sql)) {
                foreach ($tables as $table) {
                    if ($this->dropTempTable($table)) {
                        echo "Dropped $this->uboTempDatabase.$table", PHP_EOL;
                    }
                }
            }
        }

        // Flag for update in queue table
        $queueTable = $this->uboDatabase . '.uboDirectMonitoringQueue';
        $sql = "REPLACE INTO $queueTable SET DUNS = :duns";
        $this->uboPdo->query($sql, [':duns' => $duns]);
    }

    /**
     * List DUNS, user, viewedVersion, type, userName
     *
     * @return void
     */
    public function userVersions(): void
    {
        $usersTable = $this->appPdo->authDB . ".users";
        $versionsTable = $this->globalDatabase . '.g_uboUserRelationship';
        $dunsUsers = $this->appPdo
            ->fetchKeyValueRows("SELECT DISTINCT DUNS, userID FROM $versionsTable ORDER BY DUNS");
        $latestVersions = [];
        $sql = "SELECT r.DUNS, r.userID, r.viewedVersion,\n"
            . "(CASE\n"
            . "  WHEN u.userType = 100 THEN 'Super Admin'\n"
            . "  WHEN u.userType = 80 THEN 'Client Admin'\n"
            . "  WHEN u.userType = 70 THEN 'Client Manager'\n"
            . "  WHEN u.userType = 60 THEN 'Client User'\n"
            . "  ELSE '??'\n"
            .  "END) `type`, u.userName\n"
            . "FROM $versionsTable r\n"
            . "INNER JOIN $usersTable u ON u.id = r.userID\n"
            . "WHERE r.DUNS = :duns AND r.userID = :user\n"
            . "ORDER BY r.id DESC LIMIT 1";
        foreach ($dunsUsers as $duns => $userID) {
            if ($row = $this->appPdo->fetchObjectRow($sql, [':duns' => $duns, ':user' => $userID])) {
                $latestVersions[] = $row;
            }
        }
        // Show user latest versions
        $divider = str_repeat('-', 75) . PHP_EOL;
        echo $divider,
          'DUNS       UserID      Version  User Type       Name', PHP_EOL,
          $divider;
        foreach ($latestVersions as $row) {
            echo "$row->DUNS  ",
              str_pad($row->userID, 12),
              str_pad($row->viewedVersion, 4, ' ', STR_PAD_LEFT),
              '     ', str_pad($row->type, 16),
              $row->userName,
              PHP_EOL;
        }
        echo $divider;
    }

    /**
     * Decrement user's last viewed version, but not less than 1
     *
     * @param string $duns   DUNS number
     * @param string $userID users.id
     *
     * @return void
     *
     * @throws Exception
     */
    public function decrementUserVersion(string $duns = '', string $userID = ''): void
    {
        $userID = (int)$userID;
        if (!$this->validUser($userID)) {
            echo "Invalid userID", PHP_EOL;
            return;
        }
        if (!$this->validDuns($duns)) {
            echo "Invalid DUNS", PHP_EOL;
            return;
        }
        $data = new UboSubTabData(79);
        if ($was = $data->getUserViewedVersion($duns, $userID)) {
            if ($was > 1) {
                $newVersion = $was - 1;
                if ($data->insertUserViewedVersion($userID, $duns, $newVersion)) {
                    echo "Viewed version changed from $was to $newVersion", PHP_EOL;
                } else {
                    echo "FAILED to decrement user viewed version", PHP_EOL;
                }
            } else {
                echo "User $userID has has only viewed version 1 of DUNS $duns", PHP_EOL;
            }
        } else {
            echo "User $userID has not viewed DUNS $duns", PHP_EOL;
        }
    }

    /**
     * List DUNS eligible for manipulation
     *
     * @return void
     */
    public function eligibleDuns(): void
    {
        $sql = "SELECT DISTINCT DUNS FROM $this->uboDatabase.ubo ORDER BY DUNS";
        $allDuns = $this->uboPdo->fetchValueArray($sql);
        $currentVersion = [];
        $eligible = [];
        foreach ($allDuns as $duns) {
            $sql = "SELECT u.id, u.DUNS, u.version, u.entityName\n"
                . "FROM $this->uboDatabase.ubo u\n"
                . "WHERE u.DUNS = :duns ORDER BY u.version DESC LIMIT 1";
            $params = [':duns' => $duns];
            $row = $this->uboPdo->fetchObjectRow($sql, $params);
            $sql = "SELECT COUNT(*) FROM $this->uboDatabase.dunsBeneficialOwners WHERE UBOid = :id";
            $records = $this->uboPdo->fetchValue($sql, [':id' => $row->id]);
            if ($records >= 10) {
                $row->records = $records;
                $eligible[] = $row;
            }
        }
        // Show eligible DUNS list
        $divider = str_repeat('-', 75) . PHP_EOL;
        echo $divider, "D-U-N-S    Version    Records    Entity Name", PHP_EOL, $divider;
        foreach ($eligible as $row) {
            echo $row->DUNS,
            str_pad($row->version, 9, ' ', STR_PAD_LEFT),
            str_pad(number_format($row->records), 11, ' ', STR_PAD_LEFT),
            '    ', $row->entityName, PHP_EOL;
        }
        echo $divider;
    }

    /**
     * Show UBO related tables in UBO, UBO_Temp and in TPM global database
     *
     * @return void
     */
    public function showTables(): void
    {
        // Get the data
        $uboTables = $this->uboPdo->fetchValueArray("SHOW TABLES FROM $this->uboDatabase");
        $tempTables =  $this->uboPdo->fetchValueArray("SHOW TABLES FROM $this->uboTempDatabase");
        $globalTables = $this->appPdo->fetchValueArray("SHOW TABLES FROM $this->globalDatabase LIKE 'g\_ubo%'");
        // Show the results
        $this->listTables($this->uboDatabase, $uboTables);
        $this->listTables($this->uboTempDatabase, $tempTables);
        $this->listTables($this->globalDatabase, $globalTables);
    }

    /**
     * Display table list from database
     *
     * @param string $dbName Name of source database
     * @param array  $tables List of table names
     *
     * @return void
     */
    private function listTables(string $dbName, array $tables): void
    {
        echo "Tables in $dbName", PHP_EOL;
        foreach ($tables as $table) {
            echo "  $table", PHP_EOL;
        }
        echo PHP_EOL;
    }

    /**
     * Show information about using this utility
     *
     * @return void
     */
    public function usage(): void
    {
        $baseCommand = './skinnycli Models.TPM.UboManipulationForQA';
        $output = "This utility manipulates UBO version data sets for QA testing.
        
  USAGE

    Alter values in current UBO data set for provided DUNS number 

      $baseCommand::alterCurrent DUNS
    
    Decrement a users' last viewed version for provided DUNS and userID
    
      $baseCommand::decrementUserVersion DUNS userID
    
    Show users with their viewed versions
    
      $baseCommand::userVersions
    
    Show DUNS numbers, versions and record counts in UBO data sets.  UBO version data sets
    in dunsBeneficialOwners must have at least 10 records to be eligible for manipulation. 

      $baseCommand::eligibleDuns
  
    Show all UBO-related tables

      $baseCommand::showTables
                
    Show this information

      $baseCommand::usage
     
";
        echo str_replace("\n", PHP_EOL, Xtra::normalizeLF($output));
    }

    /**
     * Validate DUNS input
     *
     * @param string $duns DUNS number
     *
     * @return bool
     */
    private function validDuns(string $duns): bool
    {
        $uboTable = $this->uboDatabase . '.ubo';
        $sql = "SELECT DUNS FROM $uboTable WHERE DUNS = :duns LIMIT 1";
        return $this->uboPdo->fetchValue($sql, [':duns' => $duns]) === $duns;
    }

    /**
     * Validate userID input
     *
     * @param int $userID users.id
     *
     * @return bool
     */
    private function validUser(int $userID): bool
    {
        $usersTable = $this->appPdo->authDB . '.users';
        $sql = "SELECT id FROM $usersTable\n"
            . "WHERE id = :user AND status = 'active' AND userType IN(100,80,70,60) LIMIT 1";
        return $this->appPdo->fetchValue($sql, [':user' => $userID]) === $userID;
    }

    /**
     * Drop and unregister a short-lived UBO table
     *
     * @param string $tableName Table to drop and unregister
     *
     * @return bool
     */
    private function dropTempTable(string $tableName): bool
    {
        // drop the short-lived table
        $dbName = $this->uboTempDatabase;
        $table = $tableName;
        if ($table === 'tableList') {
            return false;
        }
        $result = false;
        try {
            $this->uboPdo->query("DROP TABLE IF EXISTS $dbName.$table");
            $sql = "DELETE FROM $dbName.tableList WHERE tableName = :table LIMIT 1";
            $result = ($result = $this->uboPdo->query($sql, [':table' => $table])) && $result->rowCount();
        } catch (Exception $e) {
            echo $e->getMessage(), PHP_EOL;
        }
        return $result;
    }

    /**
     * Instantiate class and initialize instance properties
     */
    public function __construct()
    {
        // Allow execution only in lower environments
        if (!in_array(ApplicationRegion::getAppRegion(), ['Development', 'Staging'])) {
            exit("UBO manipulation is allowed only in Development or in Staging" . PHP_EOL);
        }
        setlocale(LC_ALL, "C.UTF-8");
        // TPM and UBO use distinct connections
        $this->appPdo = Xtra::app()->DB;
        $this->uboPdo = (new UboDnBApi())->getUboPdo();
        $this->uboDatabase = getenv('uboDbName');
        $this->uboTempDatabase = getenv('uboTempDbName');
        $this->globalDatabase = $this->appPdo->globalDB;
    }
}
