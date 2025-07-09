<?php
/**
 * Provide access to DnB API and UBO databases (UBO and UBO_Temp)
 * CAUTION: References to other databases cannot be assumed to be valid for this connection!
 *          TPM code must not assume the normal app->DB connection can connect to UBO databases!
 */

namespace Models\TPM;

use http\Env\Url;
use Lib\Database\MySqlPdo;
use Lib\Database\ChunkResults;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\TransferStats;
use Lib\SettingACL;
use Lib\Support\Xtra;
use Skinny\Log;
use Exception;
use RuntimeException;
use Controllers\ADMIN\Logs\CronLogger;

/**
 * Public methods:
 *   __constructor              - get an instance of this class
 *   getUboPdo                  - MySqlPdo instance for UBO and UBO_Temp databases access
 *   checkAllDunsForUboUpdates  - direct UBO update monitoring (aka, duct-tape-and-bailing-wire-monitoring)
 *   retryFailedUboUpdateChecks - re-try failed UBO update monitoring
 *   getInitialUboData          - get UBO data on assignment of D-U-N-S to 3P record
 *   entitySearchForDuns        - entity search for D-U-N-S number
 *   compareUboVersions         - show differences between 2 versions of UBO data
 *   getFullUboVersionList      - get all available UBO versions for D-U-N-S number
 *   latestUboVersion           - get latest UBO version for D-U-N-S number
 *   accessToken                - gets and saves token by clientID, so it use appropriate license credentials
 */
#[\AllowDynamicProperties]
class UboDnBApi
{
    /**
     * @var MySqlPdo|null PDO connection to UBO database
     */
    private ?MySqlPdo $uboPdo;

    /**
     * @var string UBO database name
     */
    private string $uboDbName;

    /**
     * @var string Database name for short-lived UBO tables
     */
    private string $uboTempDbName;

    /**
     * @var string Table name for tracking short-lived UBO tables
     */
    private string $listTable = 'tableList';

    /**
     * @var string Table to track last fetch UBO data for each assigned DUNS number
     */
    private string $queueTable = 'uboDirectMonitoringQueue';

    /**
     * @var string Table holding beneficial owners records
     */
    private string $ownersTable = 'dunsBeneficialOwners';

    /**
     * @var string Table to log DnB API requests
     */
    private string $logTable = 'dnbApiRequestLog';

    /**
     * @var string Table to track last fetch UBO data for each assigned DUNS number
     */
    private string $tokenTable = 'g_uboApiTokenStatus';

    /**
     * @var array Bearer token information for Diligent DnB license
     */
    private array $diligentTokenInfo = [
        'whenObtained' => 0,
        'token' => '',
        'lifetime' => 0,
    ];

    /**
     * @var array DnB API endpoints
     */
    private array $dnbEndpoints;

    /**
     * @var int Client ID use for DnB license/token, 0 - Diligent license
     */
    private int $licenseClientID;

    /**
     * @var string Default error message for failed API operations
     */
    private string $defaultError = 'Error in loading results. Please contact system administrator.';

    /**
     * @var int Chunk size for Show History tables. Defined here to make changing it easier to do.
     *          Be aware th payload could be 3 times this value, since applies to each table in Show History.
     */
    private int $diffUboChunk = 500;

    /**
     * @var int Chunk size for table on UBO sub-tab
     */
    private int $mainUboChunk = 1000;

    /**
     * Instantiate class and connect PDO to UBO databases
     */
    public function __construct()
    {
        $this->connectUboDatabases();
        $this->uboDbName = getenv('uboDbName') ?: 'UBO';
        $this->uboTempDbName = getenv('uboTempDbName') ?: 'UBO_Temp';
        $this->dnbEndpoints = [
            'token' => getenv('uboTokenApi') ?: 'https://plus.dnb.com/v3/token',
            'search' => getenv('uboSearchApi') ?: 'https://plus.dnb.com/v1/search/criteria',
            'ubo' => getenv('uboBeneficialownerApi') ?: 'https://plus.dnb.com/v1/beneficialowner',
        ];
        $this->ownersTable = "$this->uboDbName.$this->ownersTable";
        $this->queueTable = "$this->uboDbName.$this->queueTable";
    }

    /**
     * Getter for UBO PDO connection
     *
     * @return MySqlPdo|null
     */
    public function getUboPdo(): ?MySqlPdo
    {
        return $this->uboPdo;
    }

    /**
     * Cron handler for re-trying failed UBO update checks
     *
     * @return void
     *
     * @throws Exception
     */
    public function retryFailedUboUpdateChecks(): void
    {
        try {
            $logger = new CronLogger((new \ReflectionClass(__CLASS__))->getShortName() . '::' . __FUNCTION__);
            $logger->logStart();
            $this->checkAllDunsForUboUpdates(true);
            $logger->logSuccess();
        } catch (Exception $e) {
            $logger->logError($e);
        }
    }

    /**
     * Cron handler to check every assigned DUNS number for UBO updates.
     * Large DUNS list will not exceed PHP memory limit.
     *
     * @param bool $onlyFailed If true, only check updates for those that have failed
     *
     * @return void
     *
     * @throws Exception
     */
    public function checkAllDunsForUboUpdates(bool $onlyFailed = false): void
    {
        try {
            if (!$onlyFailed) {
                $logger = new CronLogger((new \ReflectionClass(__CLASS__))->getShortName() . '::' . __FUNCTION__);
                $logger->logStart();
            } else {
                $logger = CronLogger::getInstance();
            }
            if ($distinctDunsTable = $this->getDistinctDunsList($onlyFailed)) {
                $logger->logDebug("Distinct DUNS list table for UBO update check: $distinctDunsTable");
                $sql = "SELECT id, DUNS FROM $distinctDunsTable\n"
                    . "WHERE id > :uniqueID ORDER BY id ASC";
                $chunker = new ChunkResults($this->uboPdo, $sql, []);
                while ($record = $chunker->getRecord()) {
                    $lastChecked = null;
                    $logger->logDebug("Checking DUNS {$record['DUNS']} for UBO updates.");
                    $apiResult = $this->loadUboFromApi($this->accessToken(0), $record['DUNS']);
                    if ($apiResult['whereSaved']
                        && $apiResult['owners'] >= $apiResult['expectedOwners']
                        && $apiResult['pagesRead'] >= $apiResult['expectedPages']
                    ) {
                        // success
                        if ($this->hasUboChanged($record['DUNS'], $apiResult['whereSaved'])) {
                            // if the check completed normally (no errors)
                            $lastChecked = date('Y-m-d H:i:s');
                        }
                    }
                    // Mark DUNS in queue table
                    $queueSql = "UPDATE $this->queueTable SET uboLastChecked = :when\n"
                        . "WHERE DUNS = :duns LIMIT 1";
                    $this->uboPdo->query($queueSql, [':duns' => $record['DUNS'], ':when' => $lastChecked]);
                    // Drop the short-lived table holding api results for this DUNS number
                    $this->dropTempTable($apiResult['whereSaved']);
                }
                // $distinctDunsTable can be dropped now
                $this->dropTempTable($distinctDunsTable);
            } else {
                $logger->logDebug("FAILED to get distinct DUNS list for UBO update check.");
            }
            if (!$onlyFailed) {
                $logger->logSuccess();
            }
        } catch (Exception $e) {
            $logger->logError($e);
        }
    }

    /**
     * Get UBO on assignment of DUNS from appropriate license key
     *
     * @param string $duns     DnB entity identifier
     * @param int    $clientID TPM tenant ID
     *
     * @return bool
     *
     * @throws Exception
     */
    public function getInitialUboData(string $duns, int $clientID): bool
    {
        // Get UBO data from API with correct license
        $apiToken = $this->accessToken($clientID);
        // Get it from API, even if it is already stored. This will register it to BYOL, if defined for this client
        $apiResult = $this->loadUboFromApi($apiToken, $duns);
        $dropTable = $apiResult && $apiResult['whereSaved'];
        if ($apiResult && $apiResult['whereSaved']
            && $apiResult['owners'] >= $apiResult['expectedOwners']
            && $apiResult['pagesRead'] >= $apiResult['expectedPages']
        ) {
            // success
            $latest = $this->latestUboVersion($duns);
            if ($apiResult['owners'] > 0) {
                if (isset($latest['version']) && $latest['version'] === 0) {
                    $this->saveUboIfChanged($apiResult['whereSaved'], $latest);
                } else {
                    $this->hasUboChanged($duns, $apiResult['whereSaved']);
                }
            } else {
                // Nothing to save, but it needs to be registered in ubo table
                $this->registerDunsAssignmet($duns);
            }
        } else {
            if ($dropTable) {
                // Drop the short-lived table holding api results for this DUNS number
                $this->dropTempTable($apiResult['whereSaved']);
            }
            throw new Exception($this->defaultError);
        }
        if ($dropTable) {
            // Drop the short-lived table holding api results for this DUNS number
            $this->dropTempTable($apiResult['whereSaved']);
        }
        return true;
    }

    /**
     * Get latest UBO data in chunks of 5000 until all have been delivered.
     * Delivers owners in name order.
     *
     * @param string $duns             DnB entity identifier
     * @param array  $previousTracking Previous result, less 'records' and 'error elements
     *
     * @return array
     */
    public function chunkLatestUboRecords(string $duns, array $previousTracking = []): array
    {
        if (empty($previousTracking)) {
            $result = [
                'total' => 0,
                'remaining' => 0,
                'delivered' => 0,
                'idLastRead' => 0,
            ];
        } else {
            $result = $previousTracking;
        }
        $result['records'] = [];
        $result['error'] = '';

        if (!($latestVersion = $this->latestUboVersion($duns)) || $latestVersion['version'] === 0) {
            $result['error'] = $this->defaultError;
            return $result;
        }
        // Does the temp table exist?
        $tempTable = "uboTab_duns{$duns}_v{$latestVersion['version']}";
        $tabTable = "$this->uboTempDbName.$tempTable";
        $fieldList = "memberId, `name`, beneficiaryType, directOwnershipPercentage, "
            . "indirectOwnershipPercentage, beneficialOwnershipPercentage";
        try {
            if (!$this->uboPdo->tableExists($tempTable, $this->uboTempDbName)) {
                if (!$this->createTempOwnersTable($tempTable, 4)) {
                    $result['error'] = $this->defaultError;
                    return $result;
                }
                // Populate the temp table
                $sql = "INSERT INTO $tabTable ($fieldList)\n"
                    . "SELECT $fieldList FROM $this->ownersTable WHERE UBOid = :uboID ORDER BY `name`";

                if ($queryResult = $this->uboPdo->query($sql, [':uboID' => $latestVersion['UBOid']])) {
                    $result['remaining'] = $result['total'] = $queryResult->rowCount();
                }
            }
            if ($result['delivered'] === 0) {
                // Initialize result
                $sql = "SELECT COUNT(*) FROM $this->ownersTable WHERE UBOid = :uboID";
                $result['remaining'] = $result['total']
                    = $this->uboPdo->fetchValue($sql, [':uboID' => $latestVersion['UBOid']]);
            }
            if ($result['remaining']) {
                // array_unshift($fieldList, 'tmpID');
                $sql = "SELECT * FROM $tabTable\n"
                    . "WHERE tmpID > :lastID LIMIt $this->mainUboChunk"; // already in name order
                if ($records = $this->uboPdo->fetchAssocRows($sql, [':lastID' => $result['idLastRead']])) {
                    $delivered = count($records);
                    $result['records'] = $records;
                    $result['idLastRead'] = $records[$delivered - 1]['tmpID'];
                    $result['remaining'] -= $delivered;
                    $result['delivered'] += $delivered;
                }
            }
        } catch (Exception $e) {
            $result['error'] = $this->defaultError;
            $this->logException($e, __LINE__);
        }
        return $result;
    }

    /**
     * Compare 2 UBO versions
     *
     * @param string $duns             DnB entity identifier
     * @param int    $version1         ubo.version 1
     * @param int    $version2         ubo.version 2
     * @param array  $previousTracking Previous result, less 'records' and 'error' elements
     *
     * @return array full details on comparison
     */
    public function compareUboVersions(
        string $duns,
        int $version1,
        int $version2,
        array $previousTracking = []
    ): array {
        if (empty($previousTracking)) {
            $result = [
                'total' => [
                    'new' => 0,
                    'updated' => 0,
                    'removed' => 0,
                ],
                'delivered' => [
                    'new' => 0,
                    'updated' => 0,
                    'removed' => 0,
                ],
                'remaining' => [
                    'new' => 0,
                    'updated' => 0,
                    'removed' => 0,
                ],
                'idLastRead' => [
                    'new' => 0,
                    'updated' => 0,
                    'removed' => 0,
                ],
            ];
        } else {
            $result = $previousTracking;
        }
        $result['records'] = [
            'new' => [],
            'updated' => [],
            'removed' => [],
        ];
        $result['error'] = '';
        $this->dropStaleUboTempTables();

        $bothVersions = $this->getUboVersionsForCompare($duns, $version1, $version2);
        if (count($bothVersions) !== 2) {
            $result['error'] = "Invalid UBO versions for comparison.";
            return $result;
        }
        $newer = $bothVersions[0];
        $older = $bothVersions[1];

        // create short-lived compare table
        $tempTable = "uboDiff_duns{$duns}_v{$newer['version']}_v{$older['version']}";
        $compareTable = "$this->uboTempDbName.$tempTable";

        $initializationError = $this->defaultError;
        if (!$this->uboPdo->tableExists($tempTable, $this->uboTempDbName)) {
            if (!$this->createTempCompareTable($tempTable, 24)) {
                $result['error'] = $initializationError;
                return $result;
            }
            // Populate comparison table with record id values
            if (!$this->getDiffReferences($compareTable, $newer, $older)) {
                $result['error'] = $initializationError;
                return $result;
            }
        }

        // Get requested comparison details
        return $this->provideDiffDetails($compareTable, $result);
    }

    /**
     * Get all UBO version available for DUNS in descending date order
     *
     * @param string $duns DnB entity identifier
     *
     * @return array|null
     */
    public function getFullUboVersionList(string $duns): ?array
    {
        $versions = [];
        try {
            $sql = "SELECT DUNS duns, id UBOid, version, created_at versionDate,"
                . "CONCAT('v', version, ' (', DATE(created_at), ')') viewVersion\n"
                . "FROM $this->uboDbName.ubo\n"
                . "WHERE DUNS = :duns ORDER BY version DESC";
            if ($rows = $this->uboPdo->fetchAssocRows($sql, [':duns' => $duns])) {
                $versions = $rows;
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
            return null;
        }
        return $versions;
    }

    /**
     * Find latest UBO version for DUNS in ubo table
     *
     * @param string $duns DnB entity identifier
     *
     * @return array|null
     */
    public function latestUboVersion(string $duns): ?array
    {
        $latest = [
            'duns' => $duns,
            'UBOid' => 0,
            'version' => 0,
            'versionDate' => '',
            'viewVersion' => '',
            'entityName' => '',
            'entityAddress' => '',
        ];
        try {
            $sql = "SELECT DUNS duns, id UBOid, version, created_at versionDate,\n"
                . "CONCAT('v', version, ' (', DATE(created_at), ')') viewVersion,\n"
                . "entityName, entityAddress\n"
                . "FROM $this->uboDbName.ubo\n"
                . "WHERE DUNS = :duns ORDER BY version DESC LIMIT 1";
            if ($row = $this->uboPdo->fetchAssocRow($sql, [':duns' => $duns])) {
                $latest = $row;
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
            return null;
        }
        return $latest;
    }

    /**
     * Get first/next set of record details and report last id value read for each and how many are remaining
     *
     * @param string $compareTable Holds diff id references
     * @param array  $result       Details to return to caller
     *
     * @return array
     */
    private function provideDiffDetails(string $compareTable, array $result): array
    {
        try {
            // Report number of records of each diff type
            $sql = "SELECT cmp, COUNT(*) FROM $compareTable GROUP BY cmp";
            $counts = $this->uboPdo->fetchKeyValueRows($sql);
            $result['total'] = [
                'new' => $counts['new'] ?? 0,
                'updated' => $counts['updated'] ?? 0,
                'removed' => $counts['removed'] ?? 0,
            ];
            foreach ($result['total'] as $diffType => $available) {
                if ($available) {
                    $result['remaining'][$diffType] = $available - $result['delivered'][$diffType];
                }
            }

            // Get remaining details
            foreach (array_keys($result['total']) as $diffType) {
                $this->comparisonDetails($compareTable, $diffType, $result);
                if ($result['error']) {
                    break;
                }
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }

        return $result;
    }

    /**
     * Fill in details for the comparison return
     *
     * @param string $compareTable Short-lived table containing reference to owner records
     * @param string $diffType     'new', 'updated', 'removed'
     * @param array  $result       Fill in details returned to caller
     *
     * @return void
     */
    private function comparisonDetails(string $compareTable, string $diffType, array &$result): void
    {
        // Build SQL for each diff type
        $fieldList = "c.tmpID, o.memberId, o.`name`, o.beneficiaryType,\n"
            . "o.indirectOwnershipPercentage,\n"
            . "o.directOwnershipPercentage,\n"
            . "o.beneficialOwnershipPercentage";
        $uboTable = "$this->uboDbName.ubo";
        switch ($diffType) {
            case 'new':
                $recordSql = "SELECT $fieldList, CONCAT('v', u.version, ' (', DATE(u.created_at) , ')') `version`\n"
                    . "FROM $compareTable c\n"
                    . "INNER JOIN $this->ownersTable o ON o.id = c.id\n"
                    . "INNER JOIN $uboTable u ON u.id = c.UBOid\n"
                    . "WHERE c.cmp = 'new' and c.tmpID > :lastRead\n"
                    . "ORDER BY c.tmpID LIMIT $this->diffUboChunk";
                break;
            case 'updated':
                $name1 = "o.`name`";
                $name2 = "ob.`name`";
                $version1 = "CONCAT('v', u.version, ' (', DATE(u.created_at) , ')')";
                $version2 = "CONCAT('v', ub.version, ' (', DATE(ub.created_at) , ')')";
                $type1 = "o.beneficiaryType";
                $type2 = "ob.beneficiaryType";
                $indirect1 = "o.indirectOwnershipPercentage";
                $indirect2 = "ob.indirectOwnershipPercentage";
                $direct1 = "o.directOwnershipPercentage";
                $direct2 = "ob.directOwnershipPercentage";
                $beneficial1 = "o.beneficialOwnershipPercentage";
                $beneficial2 = "ob.beneficialOwnershipPercentage";
                $fieldList = "c.tmpID, o.memberId,\n"
                    . "CONCAT($name1, '<div class=\"diff\">', $name2, '</div>') `name`,\n"
                    . "CONCAT($type1, '<div class=\"diff\">', $type2, '</div>') `beneficiaryType`,\n"
                    . "CONCAT($version1, '<div class=\"diff\">', $version2, '</div>') `version`,"
                    . "CONCAT($indirect1, '<div class=\"diff\">', $indirect2, '</div>') "
                    . "`indirectOwnershipPercentage`,\n"
                    . "CONCAT($direct1, '<div class=\"diff\">', $direct2, '</div>') `directOwnershipPercentage`,\n"
                    . "CONCAT($beneficial1, '<div class=\"diff\">', $beneficial2, '</div>') "
                    . "`beneficialOwnershipPercentage`\n";
                $recordSql = "SELECT $fieldList\n"
                    . "FROM $compareTable c\n"
                    . "INNER JOIN $this->ownersTable o ON o.id = c.id\n"
                    . "INNER JOIN $this->ownersTable ob ON ob.id = c.b_id\n"
                    . "INNER JOIN $uboTable u ON u.id = c.UBOid\n"
                    . "INNER JOIN $uboTable ub ON ub.id = c.b_UBOid\n"
                    . "WHERE c.cmp = 'updated' AND c.tmpID > :lastRead\n"
                    . "ORDER BY c.tmpID LIMIT $this->diffUboChunk";
                break;
            case 'removed':
                $recordSql = "SELECT $fieldList, CONCAT('v', u.version, ' (', DATE(u.created_at) , ')') `version`\n"
                    . "FROM $compareTable c\n"
                    . "INNER JOIN $this->ownersTable o ON o.id = c.b_id\n"
                    . "INNER JOIN $uboTable u ON u.id = c.b_UBOid\n"
                    . "WHERE c.cmp = 'removed' AND c.tmpID > :lastRead\n"
                    . "ORDER BY c.tmpID LIMIT $this->diffUboChunk";
                break;
            default:
                $result['error'] = "Unrecognized comparison type.";
                return;
        }
        try {
            if ($result['remaining'][$diffType] > 0) {
                $recordParams = [':lastRead' => $result['idLastRead'][$diffType]];
                if ($records = $this->uboPdo->fetchAssocRows($recordSql, $recordParams)) {
                    $got = count($records);
                    $result['records'][$diffType] = $records;
                    $result['idLastRead'][$diffType] = $records[$got - 1]['tmpID'];
                    $result['remaining'][$diffType] -= $got;
                    $result['delivered'][$diffType] += $got;
                }
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }
    }

    /**
     * Get record reference for UBO comparison from dunsBeneficialOwners table
     *
     * @param string $compareTable Short-lived table to hold diff references
     * @param array  $newer        Newer UBO version
     * @param array  $older        Older UBO version
     *
     * @return bool
     */
    private function getDiffReferences(string $compareTable, array $newer, array $older): bool
    {
        $success = false;

        try {// Populate diff table with 'updated' and 'new' references
            $sql = "INSERT IGNORE INTO $compareTable (id, UBOid, b_id, b_UBOid, cmp)\n"
                . "SELECT a.id, a.UBOid, b.id, :v2_1, IF(b.id IS NULL, 'new', 'updated')\n"
                . "FROM $this->ownersTable a\n"
                . "LEFT JOIN $this->ownersTable b ON b.UBOid = :v2 AND b.memberId = a.memberId\n"
                . "WHERE a.UBOid = :v1 AND (b.id IS NULL OR (\n"
                . "  (a.`name` IS NULL AND b.`name` IS NOT NULL)\n"
                . "  OR (a.`name` IS NOT NULL AND b.`name` IS NULL)\n"
                . "  OR BINARY a.`name` <> BINARY b.`name`\n"
                . "  OR a.beneficiaryType <> b.beneficiaryType\n"
                . "  OR a.directOwnershipPercentage <> b.directOwnershipPercentage\n"
                . "  OR a.indirectOwnershipPercentage <> b.indirectOwnershipPercentage\n"
                . "  OR a.beneficialOwnershipPercentage <> b.beneficialOwnershipPercentage)\n"
                . ") ORDER BY a.`name`\n";
            $params = [':v1' => $newer['UBOid'], ':v2' => $older['UBOid'], ':v2_1' => $older['UBOid']];
            // Add 'removed' references
            $this->uboPdo->query($sql, $params);
            $sql = "INSERT IGNORE INTO $compareTable (b_id, b_UBOid, cmp)\n"
                . "SELECT b.id, b.UBOid, 'removed'\n"
                . "FROM $this->ownersTable b\n"
                . "LEFT JOIN $this->ownersTable a ON a.UBOid = :v1 AND a.memberId = b.memberId\n"
                . "WHERE b.UBOid = :v2 AND a.id IS NULL\n"
                . "ORDER BY b.`name`\n";
            $this->uboPdo->query($sql, [':v1' => $newer['UBOid'], ':v2' => $older['UBOid']]);
            $success = true;
        } catch (Exception $e) {
            $this->logRequest($e, __LINE__);
        }
        return $success;
    }

    /**
     * Define short-lived target table and load paginated UBO data into it from DnB API
     *
     * @param string $apiToken DnB API bearer token
     * @param string $duns     DnB entity identifier
     *
     * @return array Information to determine success or failure, and where to find records saved from API
     *
     * @throws RuntimeException
     */
    private function loadUboFromApi(string $apiToken, string $duns): array
    {
        $whereSaved = $error = '';
        $default = [
            'whereSaved' => $whereSaved,
            'expectedOwners' => 0,
            'owners' => 0,
            'expectedPages' => 0,
            'pagesRead' => 0,
            'error' => '',
        ];
        $this->dropStaleUboTempTables();
        $tempTable = "apiUbo_duns{$duns}_" . date('Ymd_Hi');
        if (!$this->createTempOwnersTable($tempTable)) {
            // Let caller know process failed
            $default['error'] = $this->defaultError;
            return $default;
        }
        $whereSaved = "$this->uboTempDbName.$tempTable";
        $page = 1;
        $totalPages = 1;
        $fetchedAll = true;
        $statusCode = 0;
        $inserted = $expectedOwners = 0;
        $pagesRead = 0;
        $json = $this->uboFromApi($apiToken, $duns, $page, $statusCode);
        if (in_array($statusCode, [200, 206]) && ($uboData = json_decode($json))) {
            $pagesRead = 1;
            $totalPages = $uboData->totalPageCount;
            $expectedOwners = $uboData->organization?->beneficialOwnershipSummary?->beneficialOwnersCount ?? 0;
            $inserted += $this->putOwnersFromApi($uboData->organization->beneficialOwnership, $whereSaved);
            if ($totalPages > 1) {
                // get data from additional pages
                $page++;
                while ($page <= $totalPages) {
                    $json = $this->uboFromApi($apiToken, $duns, $page, $statusCode);
                    if (in_array($statusCode, [200, 206]) && ($uboData = json_decode($json))) {
                        $pagesRead++;
                        $inserted += $this->putOwnersFromApi($uboData->organization->beneficialOwnership, $whereSaved);
                    } else {
                        $error = $this->defaultError;
                        break;
                    }
                    $page++;
                }
            }
        }
        return [
            'whereSaved' => $whereSaved,
            'expectedOwners' => $expectedOwners,
            'owners' => $inserted,
            'expectedPages' => $totalPages,
            'pagesRead' => $pagesRead,
            'error' => $error,
        ];
    }

    /**
     * Parse and store UBO data from API
     *
     * @param object $ownersObj   Owner data object from API JSON
     * @param string $whereToSave Short-lived table to hold records temporarily
     *
     * @return int
     */
    private function putOwnersFromApi(object $ownersObj, string $whereToSave): int
    {
        $inserted = 0;
        try {
            if ($whereToSave && $ownersObj->beneficialOwners) {
                foreach ($ownersObj->beneficialOwners as $owner) {
                    $sql = "INSERT IGNORE INTO $whereToSave (`memberId`, `name`, `beneficiaryType`,\n"
                        . "`directOwnershipPercentage`, `indirectOwnershipPercentage`,\n"
                        . "`beneficialOwnershipPercentage`)\n"
                        . "VALUES (:memberID, :name, :type, :direct, :indirect, :beneficial)\n";
                    $params = [
                        ':memberID' => $owner->memberID,
                        ':name' => $owner->name,
                        ':type' => $owner->beneficiaryType->description ?? '',
                        ':direct' => $owner->directOwnershipPercentage ?? 0.00,
                        ':indirect' => $owner->indirectOwnershipPercentage ?? 0.00,
                        ':beneficial' => $owner->beneficialOwnershipPercentage ?? 0.00,
                    ];
                    if ($result = $this->uboPdo->query($sql, $params)) {
                        if ($result->rowCount()) {
                            $inserted++;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }
        return $inserted;
    }

    /**
     * Get one page of UBO data from DnB API for D-U-N-S number using bearer token from appropriate license
     * Testing: DUNS that have large UBO response: 465759628, 690561923, 691237413
     *
     * @param string $token      DnB API bearer token
     * @param string $duns       DnB entity identifier
     * @param int    $page       Which page of paginated data to get
     * @param int    $statusCode (reference) HTTP response code
     *
     * @return string JSON data
     *
     * @throws RuntimeException
     */
    private function uboFromApi(string $token, string $duns, int $page, int &$statusCode): string
    {
        $query = [
            'duns' => $duns,
            'productId' => 'cmpbol',
            'versionId' => 'v1',
            'pageNumber' => $page,
            'returnPaginatedResults' => 'true'
        ];
        $logID = $this->logRequest('GET ' . $this->dnbEndpoints['ubo'], $query);
        $client = new HttpClient();
        $requestOptions = [
            'headers' => [
                "Authorization" => "Bearer $token",
            ],
            'query' => $query,
            'on_stats' => function (TransferStats $stats) use ($duns, $logID) {
                if (!$stats->hasResponse() || !in_array($stats->getResponse()->getStatusCode(), [200, 206])) {
                    $error = "Failed to get UBO data from DnB API for DUNS $duns.";
                    if ($stats->hasResponse()) {
                        $status = $stats->getResponse()->getStatusCode();
                        $error .= " Returned status " . $stats->getResponse()->getBody()->getContents();
                    } else {
                        $status = -1; // not known
                        $error .= " No response.";
                    }
                    $this->updateRequestLog($logID, $status, $error);
                    Xtra::track($error);
                    throw new RuntimeException('Error in loading UBO data. Please contact system administrator.');
                }
            }
        ];
        $response = $client->request('GET', $this->dnbEndpoints['ubo'], $requestOptions);
        $statusCode = $response->getStatusCode();
        if ($logID) {
            $this->updateRequestLog($logID, $statusCode);
        }
        return (string)$response->getBody()->getContents();
    }

    /**
     * Get distinct list of all assigned DUNS numbers
     *
     * @param bool $onlyFailed Check DUNS where update has failed or never been performed
     *
     * @return string Name of short-lived table with distinct DUNS number list
     */
    private function getDistinctDunsList(bool $onlyFailed = false): string
    {
        $distinctDunsTable = '';
        $this->dropStaleUboTempTables();
        // create the short-lived table
        $tempTable = 'dunsList_' . date('Ymd_Hi');
        $this->dropTempTable($tempTable);
        $sql = "CREATE TABLE IF NOT EXISTS $this->uboTempDbName.$tempTable (\n"
            . "  `id` int AUTO_INCREMENT,\n "
            . "  `DUNS` char(9) NOT NULL DEFAULT '',\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  KEY `DUNS` (`DUNS`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        if ($this->uboPdo->query($sql)) {
            // register it in tableList and set its lifetime
            $sql = "INSERT INTO $this->uboTempDbName.$this->listTable (`tableName`, `whenToDrop`)\n"
                . "VALUES (:table, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY))";
            if ($this->uboPdo->query($sql, [':table' => $tempTable])) {
                $distinctDunsTable = "$this->uboTempDbName.$tempTable";
                $sql = "INSERT INTO $this->uboTempDbName.$tempTable (DUNS)\n"
                    . "SELECT DISTINCT(DUNS) FROM $this->uboDbName.ubo";
                $this->uboPdo->query($sql);

                // guarantee all assigned DUNS have an entry in the queue
                $this->addMissingDunsToQueue($distinctDunsTable);
                // now get the distinct DUNS list of order of last
                $this->uboPdo->query("TRUNCATE TABLE $distinctDunsTable");
                // Don't let the size of this job grow without limit. Schedule cron more often if needed.
                $retryFailed = $onlyFailed ? "WHERE uboLastChecked IS NULL" : '';
                $sql = "INSERT INTO $distinctDunsTable (DUNS)\n"
                    . "SELECT DUNS FROM $this->queueTable $retryFailed\n"
                    . "ORDER BY uboLastChecked LIMIT 10000";
                $this->uboPdo->query($sql);
            }
        }
        return $distinctDunsTable; // fully qualified name of short-lived table containing distinct DUNS numbers
    }

    /**
     * Drop stale tables in UBO_Temp.tableList
     *
     * @return int Number of tables dropped
     */
    private function dropStaleUboTempTables(): int
    {
        $dropped = 0;
        if ($this->uboPdo->tableExists($this->listTable, $this->uboTempDbName)) {
            $sql = "SELECT tableName FROM $this->uboTempDbName.$this->listTable\n"
                . "WHERE whenToDrop <= CURRENT_TIMESTAMP";
            if ($tablesToDrop = $this->uboPdo->fetchValueArray($sql)) {
                foreach ($tablesToDrop as $tableName) {
                    // drop table if it exists
                    if ($this->dropTempTable($tableName)) {
                        $dropped++;
                    }
                }
            }
        }
        return $dropped;
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
        $result = false;
        if (strpos($tableName, '.') !== false) {
            list($dbName, $table) = explode('.', $tableName);
        } else {
            $dbName = $this->uboTempDbName;
            $table = $tableName;
        }
        try {
            $this->uboPdo->query("DROP TABLE IF EXISTS $dbName.$table");
            $sql = "DELETE FROM $this->uboTempDbName.$this->listTable WHERE tableName = :table LIMIT 1";
            $result = ($result = $this->uboPdo->query($sql, [':table' => $table])) && $result->rowCount();
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }
        return $result;
    }

    /**
     * Get a PDO connection to the UBO database
     * CAUTION: References to other databases cannot be assumed to be valid for this connection!
     *          TPM code must not assume the normal app->DB connection can access UBO databases!
     *
     * @return void
     */
    private function connectUboDatabases(): void
    {
        if (empty($this->uboPdo)) {
            $connection = [
                'dbHost' => getenv('uboHost') ?: getenv('dbHost'),
                'dbUser' => getenv('uboUser') ?: getenv('dbUser'),
                'dbPass' => getenv('uboPass') ?: getenv('dbPass'),
                'dbPort' => getenv('uboPort') ?: getenv('dbPort'),
                'dbName' => getenv('uboDbName') ?: 'UBO',
            ];

            try {
                $uboPdo = new MySqlPdo($connection);
            } catch (Exception $e) {
                $this->logException($e, __LINE__);
                $uboPdo = null;
            }
            $this->uboPdo = $uboPdo;
        }
    }

    /**
     * Make sure all assigned DUNS numbers are in the direct monitoring queue table
     *
     * @param string $dunsListTable Short-lived table holding distinct list of DUNS number from ubo table
     *
     * @return int
     */
    private function addMissingDunsToQueue(string $dunsListTable): int
    {
        $sql = "INSERT INTO $this->queueTable (DUNS)\n"
            . "SELECT t.DUNS FROM $dunsListTable t LEFT JOIN $this->queueTable q ON q.DUNS = t.DUNS\n"
            . "WHERE q.id IS NULL";
        $added = 0;
        if ($result = $this->uboPdo->query($sql)) {
            $added = $result->rowCount();
        }
        return $added;
    }

    /**
     * Create a short-lived table in UBO_Temp for temporary storage of UBO data
     *
     * @param string $tempTable   Short-lived table name
     * @param int    $hoursToLive Set number of hours before table will be dropped
     *
     * @return bool
     */
    private function createTempOwnersTable(string $tempTable, int $hoursToLive = 1): bool
    {
        $result = false;
        $this->dropTempTable($tempTable);
        $sql = "CREATE TABLE IF NOT EXISTS $this->uboTempDbName.$tempTable (\n"
            . "  `tmpID` int auto_increment,\n"
            . "  `memberId` bigint NOT NULL DEFAULT '0',\n"
            . "  `name` varchar(255) DEFAULT NULL,\n"
            . "  `beneficiaryType` varchar(255) DEFAULT NULL,\n"
            . "  `directOwnershipPercentage` decimal(5,2) NOT NULL DEFAULT '0.00',\n"
            . "  `indirectOwnershipPercentage` decimal(5,2) NOT NULL DEFAULT '0.00',\n"
            . "  `beneficialOwnershipPercentage` decimal(5,2) NOT NULL DEFAULT '0.00',\n"
            . "  `cmp` enum('same', 'updated', 'new') NOT NULL DEFAULT 'new',\n"
            . "  PRIMARY KEY (`tmpID`),\n"
            . "  UNIQUE KEY `memberId` (`memberId`),\n"
            . "  KEY `cmp` (`cmp`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        if ($this->uboPdo->query($sql)) {
            // register it in tableList and set its lifetime
            $sql = "INSERT INTO $this->uboTempDbName.$this->listTable (`tableName`, `whenToDrop`)\n"
                . "VALUES (:table, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :hours HOUR))";
            if ($this->uboPdo->query($sql, [':table' => $tempTable, ':hours' => $hoursToLive])) {
                $result = true;
            }
        }
        return $result;
    }

    /**
     * Create a short-lived table in UBO_Temp for temporary storage of UBO comparison
     *
     * @param string $tempTable   Short-lived table name
     * @param int    $hoursToLive Set number of hours before table will be dropped
     *
     * @return bool
     */
    private function createTempCompareTable(string $tempTable, int $hoursToLive = 24): bool
    {
        $result = false;
        $this->dropTempTable($tempTable);
        $sql = "CREATE TABLE IF NOT EXISTS $this->uboTempDbName.$tempTable (\n"
            . "  `tmpID` int auto_increment,\n"
            . "  `id` int DEFAULT NULL,\n"
            . "  `UBOid` int NOT NULL DEFAULT '0',\n"
            . "  `b_id` int DEFAULT NULL,\n"
            . "  `b_UBOid` int DEFAULT NULL,\n"
            . "  `cmp` enum('updated', 'new', 'removed') NOT NULL DEFAULT 'updated',\n"
            . "  PRIMARY KEY (`tmpID`),\n"
            . "  UNIQUE KEY `id` (`id`),\n"
            . "  KEY `cmp` (`cmp`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        if ($this->uboPdo->query($sql)) {
            // register it in tableList and set its lifetime
            $sql = "INSERT INTO $this->uboTempDbName.$this->listTable (`tableName`, `whenToDrop`)\n"
                . "VALUES (:table, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :hours HOUR))";
            if ($this->uboPdo->query($sql, [':table' => $tempTable, ':hours' => $hoursToLive])) {
                $result = true;
            }
        }
        return $result;
    }

    /**
     * Log DnB API requests
     *
     * @param string $endpoint DnB API Url
     * @param array  $params   Request parameters
     *
     * @return int
     */
    private function logRequest(string $endpoint, array $params = []): int
    {
        $query = '';
        if ($params) {
            $parts = [];
            foreach ($params as $key => $value) {
                $parts[] = "$key=" . urlencode($value);
            }
            $query = '?' . implode('&', $parts);
        }
        $request = $endpoint . $query;
        $id = 0;
        $sql = "INSERT INTO $this->uboDbName.$this->logTable SET request = :req, licenseClientID = :lic";
        try {
            if ($this->uboPdo->query($sql, [':req' => $request, ':lic' => $this->licenseClientID])) {
                $id = $this->uboPdo->lastInsertId();
            }
        } catch (Exception $e) {
            $this->logException($e, __LIEN__);
        }
        // Throttle requests to 5 RPQ (requests per second)
        usleep(200000);
        return $id;
    }

    /**
     * Update the API request after the response
     *
     * @param int         $logID  Which log record to update
     * @param int         $status HTTP response code
     * @param string|null $error  Error response, if any
     *
     * @return void
     */
    private function updateRequestLog(int $logID, int $status, ?string $error = null): void
    {
        try {
            $sql = "UPDATE $this->uboDbName.$this->logTable\n"
                . "SET responseStatus = :status, errorResponse = :error\n"
                . "WHERE id = :id LIMIT 1";
            $this->uboPdo->query($sql, [':id' => $logID, ':status' => $status, ':error' => $error]);
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }
    }

    /**
     * Log exception in application log
     *
     * @param Exception $e    The exception
     * @param int       $line Where the error occurred
     *
     * @return void
     */
    private function logException(Exception $e, int $line): void
    {
        $msg = "@" . self::class . ':' . $line . "\n" . $e->getMessage();
        Xtra::track($msg, Log::ERROR);
    }

    /**
     * Compare UBO from API with latest locally stored version in dunsBeneficialOwners
     *
     * @param string $duns       DnB entity identifier
     * @param string $whereSaved Short-lived table holding UBO records from API
     *
     * @return bool
     */
    private function hasUboChanged(string $duns, string $whereSaved): bool
    {
        $noError = false;
        if ($latestUbo = $this->latestUboVersion($duns)) {
            $latestUbo['duns'] = $duns;
            if ($latestUbo['UBOid']) {
                // Compare records from APi with latest UBO version records
                $sql = "UPDATE $whereSaved t\n"
                    . "INNER JOIN $this->ownersTable o ON o.memberId = t.memberId\n"
                    . "SET t.cmp = IF((BINARY t.name = BINARY o.name OR (t.name IS NULL AND o.name IS NULL))\n"
                    . "  AND t.beneficiaryType = o.beneficiaryType\n"
                    . "  AND t.directOwnershipPercentage = o.directOwnershipPercentage\n"
                    . "  AND t.indirectOwnershipPercentage = o.indirectOwnershipPercentage\n"
                    . "  AND t.beneficialOwnershipPercentage = o.beneficialOwnershipPercentage, 'same', 'updated')\n"
                    . "WHERE o.UBOid = :UBOid";
                try {
                    if ($this->uboPdo->query($sql, [':UBOid' => $latestUbo['UBOid']])) {
                        $noError = $this->saveUboIfChanged($whereSaved, $latestUbo);
                    }
                } catch (Exception $e) {
                    $this->logException($e, __LINE__);
                }
            } else {
                // No latest version, but no error so far
                $noError = $this->saveUboIfChanged($whereSaved, $latestUbo);
            }
        }
        return $noError;
    }

    /**
     * Act on results of comparison between latest UBO and records from API
     *
     * @param string $whereSaved File containing comparison results
     * @param array  $latestUbo  UPBid and version of latest stored UBO version
     *
     * @return bool True if operation competed without an error
     */
    private function saveUboIfChanged(string $whereSaved, array $latestUbo): bool
    {
        $noError = false;
        // Tally the result to make a decision
        $sql = "SELECT cmp, COUNT(*) records FROM $whereSaved\n"
            . "GROUP BY cmp";
        try {
            $tally = $this->uboPdo->fetchKeyValueRows($sql);
            if (($tally['new'] ?? 0) || ($tally['updated'] ?? 0)) {
                // Insert new UBO version record
                $newVersion = ((int)$latestUbo['version']) + 1;
                $failsafe = 10;
                $newUboID = 0;
                $sql = "INSERT IGNORE INTO $this->uboDbName.ubo SET DUNS = :duns, version = :ver";
                do {
                    $params = [':duns' => $latestUbo['duns'], ':ver' => $newVersion];
                    if (($result = $this->uboPdo->query($sql, $params)) && $result->rowCount()) {
                        $newUboID = $this->uboPdo->lastInsertId();
                        break;
                    }
                    $newVersion++;
                    $failsafe--;
                } while ($failsafe > 0);
                if ($newUboID) {
                    $sql = "INSERT INTO $this->ownersTable\n"
                        . "(`UBOid`, `memberId`, `name`, `beneficiaryType`, `directOwnershipPercentage`,\n"
                        . "`indirectOwnershipPercentage`, `beneficialOwnershipPercentage`)\n"
                        . "SELECT :UBOid, `memberId`, `name`, `beneficiaryType`, `directOwnershipPercentage`,\n"
                        . "`indirectOwnershipPercentage`, `beneficialOwnershipPercentage`\n"
                        . "FROM $whereSaved";
                }
                $noError = ($result = $this->uboPdo->query($sql, [':UBOid' => $newUboID])) && $result->rowCount();
                if ($noError && !empty($latest['duns'])) {
                    $this->registerDunsAssignmet($latestUbo['duns']);
                }
            }
            $noError = true;
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }
        return $noError;
    }

    /**
     * Find 2 UBO versions for DUNS from ubo table for UBO comparison.
     * Newest version is always first record.
     *
     * @param string $duns     DnB entity identifier
     * @param int    $version1 UBO version 1
     * @param int    $version2 UBO version 2
     *
     * @return array|null
     */
    private function getUboVersionsForCompare(string $duns, int $version1, int $version2): ?array
    {
        $versions = [];
        try {
            $sql = "SELECT id UBOid, version, created_at versionDate,\n"
                . "CONCAT('v', version, ' (', DATE(created_at), ')') viewVersion\n"
                . "FROM $this->uboDbName.ubo\n"
                . "WHERE DUNS = :duns AND version IN(:v1, :v2) ORDER BY version DESC";
            $params = [':duns' => $duns, ':v1' => $version1, ':v2' => $version2];
            if ($rows = $this->uboPdo->fetchAssocRows($sql, $params)) {
                $versions = $rows;
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
            return null;
        }
        return $versions;
    }


    /*
     * ==========================================================
     * === Methods to Obtain and Re-use DnB API Access Tokens ===
     * ==========================================================
     */

    /**
     * Get bearer token for DnB API - uses correct license credentials and save token for re-use
     *
     * @param int $clientID TPM tenant ID or 0 for Diligent DnB license
     *
     * @return string DnB Access Token information for API requests
     *
     * @throws Exception
     */
    public function accessToken(int $clientID = 0): string
    {
        // Use client license or Diligent license for token?
        if ($clientID !== 0) {
            $settings = (new SettingACL($clientID))
                ->getAll([SettingACL::UBO_BRING_YOUR_OWN_LICENSE, SettingACL::TENANT_3P_UBO_DATA]);
            if (!$settings[SettingACL::UBO_BRING_YOUR_OWN_LICENSE]['value']) {
                $clientID = 0; // Use Diligent license
            }
        }
        $this->licenseClientID = $clientID;

        // Re-use existing token if it is still valid and has at least 10 minutes lifetime remaining
        if ($savedToken = $this->getSavedToken($clientID)) {
            return $savedToken;
        }

        // Get client's DnB API credentials
        $token = '';
        if ($credentials = $this->getApiCredentials($clientID)) {
            $client = new HttpClient(['auth' => [$credentials['key'], $credentials['secret']]]);
            $logID = $this->logRequest('POST ' . $this->dnbEndpoints['token']);
            $response = $client->request('POST', $this->dnbEndpoints['token'], [
                'form_params' => ['grant_type' => 'client_credentials'],
                'on_stats' => function (TransferStats $stats) use ($clientID, $logID) {
                    if (!$stats->hasResponse() || ($stats->getResponse()->getStatusCode() !== 200)) {
                        $error = "Failed to get access token from DnB API for clientID $clientID.";
                        if ($stats->hasResponse()) {
                            $status = $stats->getResponse()->getStatusCode();
                            $error .= " Returned status " . $stats->getResponse()->getBody()->getContents();
                        } else {
                            $status = -1;
                            $error .= " No response.";
                        }
                        $this->updateRequestLog($logID, $status, $error);
                        Xtra::track($error);
                        throw new RuntimeException('Error in loading UBO data. Please contact system administrator.');
                    }
                }
            ]);
            if ($response->getStatusCode() === 200) {
                $this->updateRequestLog($logID, 200);
                $info = json_decode($response->getBody()->getContents());
                $token = $info->access_token;
                $lifetime = $info->expires_in;
                $this->saveToken($clientID, $token, $lifetime);
            }
        }
        return $token;
    }

    /**
     * Get last issued token if it is still valid for at least 10 minutes
     *
     * @param int $clientID TPM tenant ID or 0 for Diligetn license
     *
     * @return string
     */
    private function getSavedToken(int $clientID): string
    {
        $appPdo = Xtra::app()->DB;
        $sql = "SELECT AES_DECRYPT(FROM_BASE64(token), '#pwKey#') `token`\n"
            . "FROM $appPdo->globalDB.$this->tokenTable\n"
            . "WHERE clientID = :cid AND ((UNIX_TIMESTAMP(issued) + lifetime - 600) >= UNIX_TIMESTAMP())";
        return (string)$appPdo->fetchValue($sql, [':cid' => $clientID], true);
    }

    /**
     * Save DnB API token for re-use up to 10 minutes lifetime remaing
     *
     * @param int    $clientID TPM tenant ID or 0 for Diligent DnB license
     * @param string $token    Token issued for licence
     * @param int    $lifetime Number of seconds token is valid
     *
     * @return void
     */
    private function saveToken(int $clientID, string $token, int $lifetime): void
    {
        $appPdo = Xtra::app()->DB;
        $sql = "REPLACE INTO $appPdo->globalDB.$this->tokenTable (clientID, token, lifetime, issued) VALUES\n"
            . "(:cid, TO_BASE64(AES_ENCRYPT(:token, '#pwKey#')), :life, CURRENT_TIMESTAMP)";
        $params = [':cid' => $clientID, ':token' => $token, ':life' => $lifetime];
        $appPdo->query($sql, $params, true);
    }

    /**
     * Get credentials for BYOL or for Diligent
     *
     * @param int $clientID TPM clientID for BYOL or 0 for Diligent license
     *
     * @return array
     */
    private function getApiCredentials(int $clientID): array
    {
        $credentials = ['key' => '', 'secret' => ''];
        if ($clientID === 0) {
            $credentials = ['key' => getenv('uboApiUserName') ?: '', 'secret' => getenv('uboApiPassword') ?: '',];
        } else {
            $appPdo = Xtra::app()->DB;
            $sql = "SELECT AES_DECRYPT(FROM_BASE64(apiUserName), '#pwKey#') `key`,\n"
                . "AES_DECRYPT(FROM_BASE64(apiPassword), '#pwKey#') `secret`\n"
                . "FROM $appPdo->globalDB.g_uboClientApiCredentials\n"
                . "WHERE clientID = :cid LIMIT 1";
            if ($row = $appPdo->fetchAssocRow($sql, [':cid' => $clientID], true)) {
                $credentials = $row;
            }
        }
        return $credentials;
    }

    /**
     * Update or add entity name and entity address in UBO.ubo table
     *
     * @param string $duns    DnB entity identifier
     * @param string $name    Entity name
     * @param string $address Entity address
     *
     * @return bool
     */
    public function updateStoredEntity(string $duns, string $name, string $address): bool
    {
        $dbName = $this->uboDbName;
        $table = 'ubo';
        $sql = "UPDATE $dbName.$table SET entityName = :name, entityAddress = :addr\n"
            . "WHERE DUNS = :duns AND (entityName <> :name2 OR entityAddress <> :addr2)";
        try {
            $params
                = [':duns' => $duns, ':name' => $name, ':name2' => $name, ':addr' => $address, ':addr2' => $address];
            $this->uboPdo->query($sql, $params);
            $truth = true;
        } catch (Exception $e) {
            $truth = false;
            $this->logException($e, __LINE__);
        }
        return $truth;
    }

    /**
     * Obtain entity name and address for UBO.ubo table.
     *
     * @param $duns DnB entity identifier
     *
     * @return array|null
     */
    public function getStoredEntity($duns): ?array
    {
        $result = null;
        $sql = "SELECT entityName `name`, entityAddress `address` FROM $this->uboDbName.ubo\n"
            . "WHERE DUNS = :duns ORDER BY id DESC LIMIT 1";
        try {
            if (($row = $this->uboPdo->fetchAssocRow($sql, [':duns' => $duns])) && $row['name']) {
                $result = $row;
            }
        } catch (Exception $e) {
            $this->logException($e, __LINE__);
        }
        return $result;
    }

    /**
     * Register newly assigned DUNS if it is missing in ubo or queue tables
     *
     * @param string $duns DnB entity identifier
     *
     * @return bool
     */
    private function registerDunsAssignmet(string $duns): bool
    {
        $result = false;
        if ($duns) {
            $params = [':duns' => $duns];
            $sql = "INSERT IGNORE INTO $this->uboDbName.ubo SET DUNS = :duns, version = 1";
            $result = ($pdoResult = $this->uboPdo->query($sql, $params)) && $pdoResult->rowCount();
            $sql = "INSERT IGNORE INTO $this->queueTable SET DUNS = :duns, uboLastChecked = CURRENT_TIMESTAMP";
            $this->uboPdo->query($sql, $params);
        }
        return $result;
    }
}
