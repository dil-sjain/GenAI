<?php
/**
 * Data access for client-facing Sponsor Email mapping tool
 */

namespace Models\TPM\Settings\SponsorEmail;

use Skinny\Skinny;
use Lib\Database\MySqlPdo;
use Lib\Traits\SplitDdqLegacyID;
use Models\LogData;
use Lib\Support\Xtra;
use Exception;
use PDOException;

#[\AllowDynamicProperties]
class SponsorEmail
{
    use SplitDdqLegacyID;

    /**
     * @const Log Event ID
     */
    protected const GENERAL_DATA_CHANGE = 214;

    /**
     * @var Skinny Class instance
     */
    protected Skinny $app;

    /**
     * @var MySqlPdo Class instance
     */
    protected MySqlPdo $DB;

    /**
     * @var string Client's database
     */
    protected string $clientDB;

    /**
     * @var array Table names in globalDB
     */
    protected array $mapTables = [
        'chain'  => 'g_ddqSponsorEmailMapChain',
        'config' => 'g_ddqSponsorEmailMapConfig',
        'form'   => 'g_ddqSponsorEmailForm',
        'maps'   => 'g_ddqSponsorEmailMaps',
    ];

    /**
     * @var bool $canAccess Allow/deny access to this tool
     */
    protected bool $canAccess = false;

    /**
     * Instantiate class and initialize properties
     *
     * @param int $clientID TPM tenant ID
     *
     * @throws Exception
     */
    public function __construct(protected int $clientID)
    {
        $this->app = Xtra::app();
        $this->DB = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
        $this->mapTables = array_map(fn($table) => $this->DB->globalDB . '.' . $table, $this->mapTables);
    }

    /**
     * Get a map chain record by id
     *
     * @param int $chainID g_ddqSponsorEmailMapChain.id
     *
     * @return array
     */
    public function getMapChain(int $chainID): array
    {
        $record = $this->DB->fetchAssocRow(
            "SELECT c.*, mCfg.toColumn mapToColumn, cCfg.toColumn chainToToColumn\n"
            . "FROM {$this->mapTables['chain']} c\n"
            . "INNER JOIN {$this->mapTables['config']} mCfg ON mCfg.id = c.mapConfigID\n"
            . "LEFT JOIN {$this->mapTables['config']} cCfg ON cCfg.id = c.chainToConfigID\n"
            . "WHERE c.id = :id AND c.clientID = :cid LIMIT 1",
            [':id' => $chainID, ':cid' => $this->clientID]
        );
        return $record ?: [];
    }

    /**
     * Get data for Existing Mappings
     *
     * @return array
     */
    public function getMapChains(): array
    {
        $sql = "SELECT ch.id, ch.chainName `Mapping`, SUBSTRING(c1.fromColumn, 18) `Custom List`,\n"
            . "c1.mapName `Maps From`, IF(c2.id IS NOT NULL, c2.mapName, '') `Chains To`\n"
            . "FROM {$this->mapTables['chain']} ch\n"
            . "INNER JOIN {$this->mapTables['config']} c1 on c1.id = ch.mapConfigID\n"
            . "LEFT JOIN {$this->mapTables['config']} c2 ON c2.id = ch.chainToConfigID\n"
            . "WHERE ch.clientID = :cid ORDER BY ch.id";
        if ($data = $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID])) {
            $formatted = $this->formatForToolTable($data);
        } else {
            // Tool required headers, even if there is no data
            $formatted = [
                'headings' => ['Mapping', 'Custom List', 'Maps From', 'Chains To'],
                'rows' => [],
            ];
        }
        return $formatted;
    }

    /**
     * Configure query for indirect mapping
     *
     * @param array  $chain       g_ddqSponsorEmailMapChain record
     * @param string $listTable   client's customSelectList table
     * @param string $deptTable   client's department table
     * @param string $regionTable client's region table
     *
     * @return array
     */
    private function indirectMappingDetails(
        array $chain,
        string $listTable,
        string $deptTable,
        string $regionTable
    ): array {
        if ($chain['mapToCol'] === 'department.id') {
            $colID = 'Dept ID';
            $colName = 'Dept Name';
            $col2ID = 'Region ID';
            $col2Name = 'Region Name';
            $fld1 = "d.id `$colID`";
            $fld2 = "d.name `$colName`";
            $fld3 = "r.id `$col2ID`";
            $fld4 = "r.name `$col2Name`";
            $join1 = $this->mapTables['maps'];
            $join2 = "$deptTable d ON d.id = frM.toID";
            $join3 = $this->mapTables['maps'];
            $join4 = "$regionTable r ON r.id = toM.toID";
            $orderField = 'r.name';
        } else {
            $colID = 'Region ID';
            $colName = 'Region Name';
            $col2ID = 'Dept ID';
            $col2Name = 'Dept Name';
            $fld1 = "r.id `$colID`";
            $fld2 = "r.name `$colName`";
            $fld3 = "d.id `$col2ID`";
            $fld4 = "d.name `$col2Name`";
            $join1 = $this->mapTables['maps'];
            $join2 = "$regionTable r ON r.id = frM.toID";
            $join3 = $this->mapTables['maps'];
            $join4 = "$deptTable d ON d.id = toM.toID";
            $orderField = 'd.name';
        }
        $sql = "SELECT l.id, l.id `Item ID`, l.name `Item Name`,\n"
            . "$fld1, $fld2, $fld3, $fld4, toM.email `Sponsor Email`\n"
            . "FROM $listTable l \n"
            . "LEFT JOIN $join1 frM ON frM.fromID = l.id AND frM.mapConfigID = :fromMap\n"
            . "LEFT JOIN $join2\n"
            . "LEFT JOIN $join3 toM ON toM.fromID = frM.toID AND toM.mapConfigID = :toMap\n"
            . "LEFT JOIN $join4\n"
            . "WHERE l.clientID = :cid AND l.listName = :list AND frM.id IS NOT NULL\n"
            . "ORDER BY $orderField, l.sequence, l.id";
        $params = [
            ':cid' => $this->clientID,
            ':list' => $chain['listName'],
            ':fromMap' => $chain['mapConfigID'],
            ':toMap' => $chain['chainToConfigID'],
        ];
        $defaultHeadings = [
            'Item ID',
            'Item Name',
            $colID,
            $colName,
            $col2ID,
            $col2Name,
            'Sponsor Email',
        ];
        return [$sql, $params, $defaultHeadings];
    }

    /**
     * Configure query for custom list to department to email mapping
     *
     * @param array  $chain       g_ddqSponsorEmailMapChain record
     * @param string $listTable   client's customSelectList table
     * @param string $deptTable   client's department table
     * @param string $regionTable client's region table
     *
     * @return array
     */
    private function directMappingDetails(
        array $chain,
        string $listTable,
        string $deptTable,
        string $regionTable
    ): array {
        if ($chain['mapToCol'] === 'department.id') {
            $colID = 'Dept ID';
            $colName = 'Dept Name';
            $fld1 = "d.id `$colID`";
            $fld2 = "d.name `$colName`";
            $join1 = $this->mapTables['maps'];
            $join2 = "$deptTable d ON d.id = frM.toID";
        } else {
            $colID = 'Region ID';
            $colName = 'Region Name';
            $fld1 = "r.id `$colID`";
            $fld2 = "r.name `$colName`";
            $join1 = $this->mapTables['maps'];
            $join2 = "$regionTable r ON r.id = frM.toID";
        }
        $orderField = 'frM.email';

        $sql = "SELECT l.id, l.id `Item ID`, l.name `Item Name`,\n"
            . "$fld1, $fld2, frM.email `Sponsor Email`\n"
            . "FROM $listTable l \n"
            . "LEFT JOIN $join1 frM ON frM.fromID = l.id AND frM.mapConfigID = :fromMap\n"
            . "LEFT JOIN $join2\n"
            . "WHERE l.clientID = :cid AND l.listName = :list AND frM.id IS NOT NULL\n"
            . "ORDER BY $orderField";
        $params = [
            ':cid' => $this->clientID,
            ':list' => $chain['listName'],
            ':fromMap' => $chain['mapConfigID'],
        ];

        $defaultHeadings = ['Item ID', 'Item Name', '$colID', '$colName', 'Sponsor Email'];
        return [$sql, $params, $defaultHeadings];
    }

    /**
     * Get all necessary data to show full details of a mapChain. Must work for direct and indirect mapping
     *
     * @param int $chainID TPM tenant ID
     *
     * @return array All the details for a single mapChain
     */
    public function getChainDetails(int $chainID): array
    {
        $regionTable = $this->clientDB . '.region';
        $deptTable = $this->clientDB . '.department';
        $listTable = $this->clientDB . '.customSelectList';

        $sql = "SELECT ch.*, SUBSTRING(mapCfg.fromColumn, 18) `listName`,\n"
            . "mapCfg.toColumn `mapToCol`, chainCfg.toColumn `chainToCol`\n"
            . "FROM {$this->mapTables['chain']} ch\n"
            . "INNER JOIN {$this->mapTables['config']} mapCfg ON mapCfg.id = ch.mapConfigID\n"
            . "LEFT JOIN {$this->mapTables['config']} chainCfg ON chainCfg.id = ch.chainToConfigID\n"
            . "WHERE ch.id = :chainID AND ch.clientID = :cid LIMIT 1";
        $params = [':cid' => $this->clientID, ':chainID' => $chainID];
        $chain = $this->DB->fetchAssocRow($sql, $params);

        $skipQuery = false;
        if ($chain && $chain['mapConfigID'] && $chain['chainToConfigID']) {
            // Indirect mapping
            [$sql, $params, $defaultHeadings]
                = $this->indirectMappingDetails($chain, $listTable, $deptTable, $regionTable);
        } elseif ($chain && $chain['mapConfigID']) {
            // Direct mapping
            [$sql, $params, $defaultHeadings]
                = $this->directMappingDetails($chain, $listTable, $deptTable, $regionTable);
        } else {
            $skipQuery = true;
            $defaultHeadings = ['Unrecognized Mapping'];
        }

        // Prepare default output
        $formatted = [
            'headings' => $defaultHeadings,
            'rows' => [],
            'unMappedItems' => [],
            'mapName' => 'Unknown',
        ];
        if (!$skipQuery) {
            // Override default with query result
            if ($data = $this->DB->fetchAssocRows($sql, $params)) {
                $formatted = $this->formatForToolTable($data);
                $formatted['chainName'] = $chain['chainName'];
                if (!empty($chain['mapConfigID']) && !empty($chain['listName'])) {
                    $formatted['unMappedItems'] = $this->getUntrackedItems($chain['mapConfigID'], $chain['listName']);
                    $formatted['listName'] = $chain['listName'];
                }
            }
        }
        return $formatted;
    }

    /**
     * Get list of mapped intake forms
     *
     * @return array
     */
    public function getMappedForms(): array
    {
        $ddqNameTable = $this->clientDB . '.ddqName';
        $questionsTable = $this->clientDB . '.onlineQuestions';
        $params = [':cid' => $this->clientID, ':cid2' => $this->clientID];
        $sql = "SELECT f.id, f.legacyID `Form ID`, d.name `Name`, d.formClass `Type`,\n"
            . "SUBSTRING(q.generalInfo, 20) `Custom List`,\n"
            . "IF(d.status, 'ACTIVE', 'inactive') `Status`, IF(d.formBuilderVer, 'v2.0', 'legacy') `Builder`,\n"
            . "ch.chainName `Mapping`\n"
            . "FROM {$this->mapTables['form']} f\n"
            . "INNER JOIN {$this->mapTables['chain']} ch ON ch.id = f.mapChainID\n"
            . "INNER JOIN $questionsTable q ON q.id = f.onlineQuestionID\n"
            . "INNER JOIN $ddqNameTable d ON d.legacyID = f.legacyID\n"
            . "WHERE f.clientID = :cid AND d.clientID = :cid2 ORDER BY d.legacyID";
        if ($data = $this->DB->fetchAssocRows($sql, $params)) {
            $formatted = $this->formatForToolTable($data);
        } else {
            $formatted = [
                'headings' => ['Form ID', 'Name', 'Type', 'Custom List', 'Status', 'Builder', 'Mapping'],
                'rows' => [],
            ];
        }
        return $formatted;
    }

    /**
     * Get list of unmapped intake forms
     *
     * @return array
     */
    public function getUnmappedForms(): array
    {
        $ddqNameTable = $this->clientDB . '.ddqName';
        $questionsTable = $this->clientDB . '.onlineQuestions';

        // Get a list of mapped customSelectList.listName values
        $returnLists = true;
        $sql = "SELECT DISTINCT SUBSTRING(fromColumn, 18)\n"
            . "FROM {$this->mapTables['config']}\n"
            . "WHERE clientID = :cid AND fromColumn LIKE 'customSelectList:%'";
        if (!($listNames = $this->DB->fetchValueArray($sql, [':cid' => $this->clientID]))) {
            $listNames = ['XzYyZxAcBbCa']; // prevent empty IN() in SQL
            $returnLists = false; // don't report this fake list name.
        }
        $listNameValues = implode(', ', array_map(function ($listName) {
            return "'customSelectList,1,$listName'"; // must match onlineQuestion.generalInfo
        }, $listNames));

        $params = [':cid' => $this->clientID];
        $sql = "SELECT d.id, d.legacyID `Form ID`, d.name `Name`, d.formClass `Type`,\n"
            . "SUBSTRING(q.generalInfo, 20) `Custom List`,\n"
            . "IF(d.status, 'ACTIVE', 'inactive') `Status`, IF(d.formBuilderVer, 'v2.0', 'legacy') `Builder`\n"
            . "FROM $ddqNameTable d\n"
            . "INNER JOIN $questionsTable q ON q.clientID = d.clientID AND q.languageCode = 'EN_US'\n"
            . "  AND q.qStatus = 1 AND CONCAT('L-', q.caseType, q.ddqQuestionVer) = d.legacyID\n"
            . "LEFT JOIN {$this->mapTables['form']} f ON f.legacyID = d.legacyID AND f.clientID = d.clientID\n"
            . "WHERE d.clientID = :cid\n"
            . "  AND q.generalInfo IN($listNameValues)\n"
            . "  AND f.id IS NULL ORDER BY d.legacyID";
        if ($data = $this->DB->fetchAssocRows($sql, $params)) {
            $formatted = $this->formatForToolTable($data);
        } else {
            $formatted = [
                'headings' => ['Form ID', 'Name', 'Type', 'Custom List', 'Status', 'Builder'],
                'rows' => [],
            ];
        }
        $formatted['customLists'] = $returnLists ? $listNames : [];
        return $formatted;
    }

    /**
     * Separate the raw query data into headings and values,
     * leaving out the first heading as per the RenderTable component's expectation
     *
     * @param array $rawData Data from fetchAssocRows containing key/value elements
     *
     * @return array
     */
    private function formatForToolTable(array $rawData): array
    {
        return [
            'headings' => array_slice(array_keys($rawData[0]), 1),
            'rows' => array_map(fn($row) => array_values($row), array_values($rawData)),
        ];
    }

    /**
     * Get Custom List item id/name
     *
     * @param string $listName        customSelectList.listName
     * @param bool   $includeInactive If true, include inactive items
     * @param string $error           (reference) capture error message for caller
     *
     * @return array
     */
    public function getCustomSelectListItems(
        string $listName,
        bool $includeInactive = true,
        string &$error = ''
    ): array {
        $result = [];
        $listTable = $this->clientDB . '.customSelectList';
        $onlyActive = $includeInactive ? '' : ' AND active = 1';
        $sql = "SELECT id, name\n"
            . "FROM $listTable\n"
            . "WHERE clientID = :cid AND listName = :list{$onlyActive}\n"
            . "ORDER BY sequence, name";
        $params = [':cid' => $this->clientID, ':list' => $listName];
        try {
            if ($listItems = $this->DB->fetchAssocRows($sql, $params)) {
                $result = $listItems;
            }
        } catch (PDOException | Exception $e) {
            Xtra::track($e->getMessage());
            $error = "FAILED get items for list `$listName`";
        }
        return $result;
    }

    /**
     * Get Custom List item id/name
     *
     * @param array $listNames customSelectList.listNames used by all map chains
     *
     * @return array
     */
    public function getCustomListData(array $listNames): array
    {
        $listTable = $this->clientDB . '.customSelectList';
        $sql = "SELECT id, name\n"
            . "FROM $listTable\n"
            . "WHERE clientID = :cid AND listName = :list\n"
            . "ORDER BY sequence, id";
        $listItems = [];
        foreach ($listNames as $listName) {
            $params = [':cid' => $this->clientID, ':list' => $listName];
            $listItems[$listName] = $this->DB->fetchIndexedRows($sql, $params);
        }
        return $listItems;
    }

    /**
     * Get distinct list for customSelectList names
     *
     * @param string $error Error message to display if error occurs
     *
     * @return array
     */
    public function getActiveCustomSelectListNames(string &$error = ''): array
    {
        $result = [];
        $table = $this->clientDB . '.customSelectList';
        $sql = "SELECT listName, count(*) records FROM $table\n"
            . "WHERE clientID = :cid AND active = 1\n"
            . "GROUP BY listName Having records > 0 ORDER BY listName";
        try {
            if ($names = $this->DB->fetchValueArray($sql, [':cid' => $this->clientID])) {
                $result = $names;
            }
        } catch (PDOException | Exception $e) {
            $error = 'Failed to get custom list names.';
            Xtra::track($e->getMessage());
        }
        return $result;
    }

    /**
     * Get unmapped customSelectList items (if any) for configured list
     *
     * @param int    $mapConfigID g_ddqSponsorEmailMapConfig.id
     * @param string $listName    customSelectList.listName
     *
     * @return array
     */
    private function getUntrackedItems(int $mapConfigID, string $listName): array
    {
        $sql = "SELECT l.id, l.name FROM $this->clientDB.customSelectList l\n"
            . "LEFT JOIN {$this->mapTables['maps']} m ON m.fromID = l.id AND m.mapConfigID = :configID\n"
            . "WHERE l.listName = :list AND l.clientID = :cid AND m.id IS NULL ORDER BY l.name";
        $params = [':cid' => $this->clientID, ':list' => $listName, ':configID' => $mapConfigID];
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Get a list of mapping that use the specified Custom List
     *
     * @param string $listName customSelectList.listName
     *
     * @return array
     */
    public function getMappingsForList(string $listName): array
    {
        $maps = [];
        $sql = "SELECT ch.id, ch.chainName \n"
            . "FROM {$this->mapTables['chain']} ch\n"
            . "INNER JOIN {$this->mapTables['config']} c ON c.id = ch.mapConfigID\n"
            . "WHERE ch.clientID = :cid AND SUBSTRING(c.fromColumn, 18) = :list\n"
            . "ORDER BY ch.id DESC";
        $params = [':cid' => $this->clientID, ':list' => $listName];
        if ($rows = $this->DB->fetchAssocRows($sql, $params)) {
            $maps = $rows;
        }
        return $maps;
    }

    /**
     * Get the mapChainID for a mapped form
     *
     * @param int $mapFormID g_ddqSponsorEmailForm.id
     *
     * @return int
     */
    public function getMappedFormChainID(int $mapFormID): int
    {
        $sql = "SELECT mapChainID FROM {$this->mapTables['form']} WHERE id = :formID LIMIT 1";
        return (int)$this->DB->fetchValue($sql, [':formID' => $mapFormID]);
    }

    /**
     * Assign mapping (chain) to unmapped form by legacyID
     *
     * @param int    $ddqNameID ddqName.id
     * @param int    $chainID   g_ddqSponsorEmailMapChain.id
     * @param string $listName  customSelectList.listName
     * @param string $error     (reference) set error, if any
     *
     * @return void
     */
    public function assignMappingToUnmappedForm(
        int $ddqNameID,
        int $chainID,
        string $listName,
        string &$error = ''
    ): void {
        if (empty($listName)) {
            $error = 'Missing name of Custom List';
            return;
        }

        // Find the form in ddqName
        $sql = "SELECT legacyID, formClass, name from $this->clientDB.ddqName\n"
            . " WHERE clientID = :cid AND id = :id LIMIT 1";
        $nameRow = $this->DB->fetchAssocRow($sql, [':id' => $ddqNameID, ':cid' => $this->clientID]);
        if (empty($nameRow)) {
            $error = 'Invalid ddqName reference.';
            return;
        }
        $legacyID = $nameRow['legacyID'];
        $formClass = $nameRow['formClass'];
        $formName = $nameRow['name'];

        // Split legacyID to get caseType and ddqQuestionVer
        $parts = $this->splitLegacyID($legacyID);
        if (empty($parts['ddqType'])) {
            $error = 'Unrecognized form reference.';
            return;
        }
        $ddqType = $parts['ddqType'];
        $ddqVersion = $parts['ddqVersion'];

        // Get the questionID from onlineQuestions using list name - must be a required drop-down selector
        $sql = "SELECT id FROM $this->clientDB.onlineQuestions\n"
            . "WHERE clientID = :cid AND caseType = :type AND ddqQuestionVer = :ver\n"
            . "AND controlType = 'DDLfromDB' AND qStatus = 1\n"
            . "AND languageCode = 'EN_US' AND generalInfo REGEXP :info LIMIT 1";
        $params = [
            ':cid' => $this->clientID,
            ':type' => $ddqType,
            ':ver' => $ddqVersion,
            ':info' => "customSelectList *, *1 *, *$listName",
        ];
        $questionID = $this->DB->fetchValue($sql, $params);
        if ($questionID <= 0) {
            $error = "Form does not configure '$listName' as a required drop-down selector.";
            return;
        }

        // Use search results to insert new record in g_ddqSponsorEmailForm
        $sql = "INSERT INTO {$this->mapTables['form']} SET\n"
            . "clientID = :cid,\n"
            . "legacyID = :legacyID,\n"
            . "ddqCaseType = :type,\n"
            . "ddqQuestionVer = :ver,\n"
            . "ddqFormClass = :class,\n"
            . "onlineQuestionID = :questionID,\n"
            . "mapChainID = :chain,\n"
            . "locked = 1";
        $params = [
            ':cid' => $this->clientID,
            ':legacyID' => $legacyID,
            ':type' => $ddqType,
            ':ver' => $ddqVersion,
            ':class' => $formClass,
            ':questionID' => $questionID,
            ':chain' => $chainID,
        ];
        try {
            if (($result = $this->DB->query($sql, $params)) && $result->rowCount()) {
                // Audit log for change
                $sql = "SELECT chainName FROM {$this->mapTables['chain']} WHERE id = :chain LIMIT 1";
                $mapName = $this->DB->fetchValue($sql, [':chain' => $chainID]);
                $details = "Sponsor Email: assigned mapping `$mapName` to form $legacyID";
                $log = new LogData($this->clientID, $this->app->session->get('authUserID'));
                $log->saveLogEntry(self::GENERAL_DATA_CHANGE, $details);
            } else {
                $error = 'Mapping was not assigned.';
            }
        } catch (PDOException | Exception $e) {
            $error = 'An unexpected database error occurred.';
            Xtra::track([
                'error' => $e->getMessage(),
                'mock' => $this->DB->mockFinishedSql($sql, $params),
            ]);
        }
    }

    /**
     * Assign mapping (chain) to unmapped form by legacyID
     *
     * @param int    $mappedFormID ddqName.id
     * @param int    $chainID      g_ddqSponsorEmailMapChain.id
     * @param string $error        (reference) set error, if any
     *
     * @return void
     */
    public function reassignMappingToMappedForm(int $mappedFormID, int $chainID, string &$error = ''): void
    {
        // get the mapped form
        $sql = "SELECT * FROM {$this->mapTables['form']} WHERE id = :id LIMIT 1";
        $formRecord = $this->DB->fetchAssocRow($sql, [':id' => $mappedFormID]);
        if (empty($formRecord)) {
            $error = 'Invalid mapped form reference.';
            return;
        } elseif ($chainID === $formRecord['mapChainID']) {
            $error = 'Form is already linked to this mapping.';
            return;
        }
        $chainNameSql = "SELECT chainName FROM {$this->mapTables['chain']} WHERE id = :id LIMIT 1";

        // Get old mapping name
        $oldChainName = $this->DB->fetchValue($chainNameSql, [':id' => $formRecord['mapChainID']]);
        if ($chainID === 0) {
            // Remove mapping
            $sql = "DELETE FROM {$this->mapTables['form']} WHERE id = :id LIMIT 1";
            $params = [':id' => $mappedFormID];
            $logMessage = "Sponsor Email:  Removed mapping from form {$formRecord['legacyID']}";
            $errorMessage = "Mapping was not removed from form.";
        } else {
            // Get new mapping name
            $newChainName = $this->DB->fetchValue($chainNameSql, [':id' => $chainID]);
            // Set new mapping
            $sql = "UPDATE {$this->mapTables['form']} SET mapChainID = :chain WHERE id = :id LIMIT 1";
            $params = [':id' => $mappedFormID, ':chain' => $chainID];
            $logMessage = "Sponsor Email:  Change mapping for form {$formRecord['legacyID']} "
                 . "from `$oldChainName` to `$newChainName`";
            $errorMessage = "Mapping was not updated.";
        }
        try {
            // Attempt the update/delete
            if (($result = $this->DB->query($sql, $params)) && $result->rowCount()) {
                $log = new LogData($this->clientID, $this->app->session->get('authUserID'));
                $log->saveLogEntry(self::GENERAL_DATA_CHANGE, $logMessage);
            } else {
                $error = $errorMessage;
            }
        } catch (PDOException | Exception $e) {
            $error = 'An unexpected database error occurred.';
            Xtra::track([
                'error' => $e->getMessage(),
                'mock' => $this->DB->mockFinishedSql($sql, $params),
            ]);
        }
    }

    /**
     * Update email addresses for specified mapping
     *
     * @param array  $chainRecord    g_ddqSponsorEmailMapChain record
     * @param array  $emailsToUpdate 'recID' and 'email' keys in each element
     * @param string $mappingType    'region' or 'department;
     * @param string $targetName     region name or department name, as per client's label
     *
     * @return array
     */
    public function updateEmailAddresses(
        array $chainRecord,
        array $emailsToUpdate,
        string $mappingType,
        string $targetName
    ): array {
        $directMapping = false;
        if (!empty($chainRecord['chainToConfigID'])) {
            // Indirect mapping
            $result = $this->updateIndirectAddresses(
                $chainRecord['chainToConfigID'],
                $chainRecord['mapConfigID'],
                $emailsToUpdate
            );
        } else {
            // Direct mapping
            $directMapping = true;
            $result =  $this->updateDirectAddresses($chainRecord['mapConfigID'], $emailsToUpdate);
        }
        if (empty($result['error']) && !empty($result['updated'])) {
            // Log the updates
            $logMessage = "Sponsor Email - Update address(es) in {$chainRecord['chainName']}: ";
            $changeList = [];
            foreach ($result['updated'] as $update) {
                $howMany = $update['changes'] === 1 ? 'time' : 'times';
                $change = "set `{$update['email']}` {$update['changes']} $howMany";
                if (!$directMapping) {
                    $change .= " in $targetName #{$update['recordID']}";
                }
                $changeList[] = $change;
            }
            $logMessage .= implode('; ', $changeList);
            $log = new LogData($this->clientID, $this->app->session->get('authUserID'));
            $log->saveLogEntry(self::GENERAL_DATA_CHANGE, $logMessage);
        }
        return $result;
    }

    /**
     * Update addresses for dept/region mapping
     *
     * @param int   $chainToConfigID g_ddqSponsorEmailMapChain.chainToConfigID
     * @param int   $mapConfigID     g_ddqSponsorEmailMapChain.mapConfigID
     * @param array $emailsToUpdate  'recID' and 'email' elements
     *
     * @return array
     */
    private function updateIndirectAddresses(
        int $chainToConfigID,
        int $mapConfigID,
        array $emailsToUpdate
    ): array {
        $updated = [];
        $error = '';
        foreach ($emailsToUpdate as $request) {
            $recordID = $request['recID'];
            $email = $request['email'];
            $sql = "UPDATE {$this->mapTables['maps']} r\n"
                . "INNER JOIN {$this->mapTables['maps']} d ON d.toID = r.fromID AND d.mapConfigID = :mapConfig\n"
                . "SET r.email = :email, d.email = :email2\n"
                . "WHERE r.clientID = :cid AND r.mapConfigID = :chainToConfig\n"
                . "AND r.toID = :record";
            $params = [
                ':cid' => $this->clientID,
                ':chainToConfig' => $chainToConfigID,
                ':mapConfig' => $mapConfigID,
                ':record' => $recordID,
                ':email' => $email,
                ':email2' => $email,
            ];

            try {
                if (($result = $this->DB->query($sql, $params)) && ($changed = $result->rowCount())) {
                    $updated[] = ['recordID' => $recordID, 'email' => $email,  'changes' => $changed];
                }
            } catch (PDOException | Exception $e) {
                $error = 'An unexpected database error occurred.';
                Xtra::track([
                    'error' => $e->getMessage(),
                    'mock' => $this->DB->mockFinishedSql($sql, $params),
                ]);
            }
        }
        return compact('updated', 'error');
    }

    /**
     * Update email address for direct mapping
     *
     * @param int   $mapConfigID    g_ddqSponsorEmailMapChain.mapConfigID
     * @param array $emailsToUpdate 'recID' and 'email' elements, 'recID' is original email
     *
     * @return array
     */
    private function updateDirectAddresses(int $mapConfigID, array $emailsToUpdate): array
    {
        $updated = [];
        $error = '';
        foreach ($emailsToUpdate as $request) {
            $email = $request['email'];
            $sql = "UPDATE {$this->mapTables['maps']}\n"
                . "SET email = :newValue\n"
                . "WHERE clientID = :cid AND mapConfigID = :config\n"
                . "AND email = :origValue";
            $params = [
                ':cid' => $this->clientID,
                ':config' => $mapConfigID,
                ':origValue' => $request['recID'],
                ':newValue' => $request['email'],
            ];
            try {
                if (($result = $this->DB->query($sql, $params)) && ($changed = $result->rowCount())) {
                    $updated[] = ['email' => $request['email'], 'changes' => $changed];
                }
            } catch (PDOException | Exception $e) {
                $error = 'An unexpected database error occurred.';
                Xtra::track([
                    'error' => $e->getMessage(),
                    'mock' => $this->DB->mockFinishedSql($sql, $params),
                ]);
            }
        }
        return compact('updated', 'error');
    }

    /**
     * Get client regions and departments, including inactive records
     *
     * @param string $error (reference) Capture error message for caller
     *
     * @return array [regions, departments]
     */
    public function getArchitecture(string &$error = ''): array
    {
        $result = [[], []];
        $tables = [
            $regionTbl = $this->clientDB . '.region',
            $departmentTbl = $this->clientDB . '.department',
        ];
        $baseSql = 'SELECT id, name, is_active `active` FROM {{table}} WHERE clientID = :cid ORDER BY name';
        try {
            foreach ($tables as $index => $table) {
                $sql = str_replace('{{table}}', $table, $baseSql);
                if ($records = $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID])) {
                    $result[$index] = $records;
                }
            }
        } catch (PDOException | Exception $e) {
            Xtra::track($e->getMessage());
            $error = $table;
        }
        return $result;
    }

    /**
     * If name already exits add and increment until it is - up to 1000 new names per day
     *
     * @param string $stub   Starting name without increment
     * @param string $column Column name
     * @param string $table  Table name
     * @param string $error  (reference) Return error message to caller
     *
     * @return string
     */
    public function getUniqueName(string $stub, string $column, string $table, string &$error = ''): string
    {
        $limit = 1000;
        $name = '';
        $increment = 1;
        $nameToCheck = $stub;
        $globalDB = $this->DB->globalDB;
        if (!$this->DB->columnExists($column, $table, $globalDB)) {
            $error = "$globalDB.$table.$column does not exist";
        } elseif (empty($stub)) {
            $error = "No name provided for $globalDB.$table.$column.";
        }
        if (empty($error)) {
            $attempt = $stub;
            $sql = "SELECT id FROM $globalDB.$table WHERE clientID = :cid AND $column = :attempt LIMIt 1";
            do {
                try {
                    if (!$this->DB->fetchValue($sql, [':cid' => $this->clientID, ':attempt' => $attempt])) {
                        $name = $attempt;
                        break;
                    }
                    $attempt .= '-' . $increment;
                    $increment++;
                } catch (PDOException | Exception $e) {
                    Xtra::track($e->getMessage());
                    $error = "Unique mapping name determination failed.";
                    break;
                }
            } while ($increment <= $limit);
            if ($increment >= $limit) {
                $error = "No unique name found in $limit attempts";
            }
        }
        return $name;
    }

    /**
     * Save new indirect mapping for validated user inputs
     *
     * @param array  $listAssignments  Assignments to Custom List and chain-to records (g_ddqSponsorEmailMaps)
     * @param array  $emailAssignments Email assignments for g_ddqSponsorEmailMaps.email
     * @param string $mapName          g_ddqSponsorEmailMapConfig.mapName
     * @param string $fromColumn       g_ddqSponsorEmailMapConfig.fromColumn
     * @param string $toColumn         g_ddqSponsorEmailMapConfig.toColumn
     * @param string $chainToMapName   g_ddqSponsorEmailMapConfig.mapName
     * @param string $fromColumn2      g_ddqSponsorEmailMapConfig.fromColumn
     * @param string $toColumn2        g_ddqSponsorEmailMapConfig.toColumn
     * @param string $chainName        g_ddqSponsorEmailMapChain.chainName
     * @param string $error            (reference) Provide error to caller
     *
     * @return void
     */
    public function saveIndirectMapping(
        array $listAssignments,
        array $emailAssignments,
        string $mapName,
        string $fromColumn,
        string $toColumn,
        string $chainToMapName,
        string $fromColumn2,
        string $toColumn2,
        string $chainName,
        string &$error = ''
    ): void {
        try {
            $this->DB->beginTransaction(); // all or nothing

            // Insert mapping
            $table = $this->mapTables['config'];
            $sql = "INSERT INTO $table (clientID, mapName, fromColumn, toColumn, locked) VALUES\n"
                . "(:cid, :name, :from, :to, 1)";
            $params = [':cid' => $this->clientID, ':name' => $mapName, ':from' => $fromColumn, ':to' => $toColumn];
            $this->DB->query($sql, $params);
            $mapConfigID = $this->DB->lastInsertId();

            // Insert chain-to configuration (same SQL)
            $params = [
                ':cid' => $this->clientID,
                ':name' => $chainToMapName,
                ':from' => $fromColumn2,
                ':to' => $toColumn2,
            ];
            $this->DB->query($sql, $params);
            $chainToConfigID = $this->DB->lastInsertId();

            // Insert chain-to configuration
            $table = $this->mapTables['chain'];
            $sql = "INSERT INTO $table (clientID, chainName, mapConfigID, chainToConfigID, locked) VALUES\n"
                . "(:cid, :name, :mapConfig, :chainToConfig, 1)";
            $params = [
                ':cid' => $this->clientID,
                ':name' => $chainName,
                ':mapConfig' => $mapConfigID,
                ':chainToConfig' => $chainToConfigID
            ];
            $this->DB->query($sql, $params);
            $chainID = $this->DB->lastInsertId();

            // Make email lookup by id
            $lookupEmail = [];
            foreach ($emailAssignments as $element) {
                $lookupEmail[$element['id']] = $element['email'];
            }

            // Insert mapping records
            $table = $this->mapTables['maps'];
            $sql = "INSERT INTO $table (clientID, mapConfigID, fromID, toID, email, locked) VALUES\n"
                . "(:cid, :config, :from, :to, :email, 1)";
            foreach ($listAssignments as $element) {
                // map config records
                $params = [
                    ':cid' => $this->clientID,
                    ':config' => $mapConfigID,
                    ':from' => $element['itemID'],
                    ':to' => $element['matchID'],
                    ':email' => '',
                ];
                $this->DB->query($sql, $params);

                // chain-to config records
                $params = [
                    ':cid' => $this->clientID,
                    ':config' => $chainToConfigID,
                    ':from' => $element['matchID'],
                    ':to' => $element['secondaryID'],
                    ':email' => $lookupEmail[$element['secondaryID']],
                ];
                $this->DB->query($sql, $params);
            }

            // Fill in missing email addresses for map config records
            $sql = "UPDATE $table m\n"
                . "INNER JOIN $table m2 ON m2.fromID = m.toID\n"
                . "SET m.email = m2.email\n"
                . "WHERE m.mapConfigID = :mapConfig AND m2.mapConfigID = :chainToConfig\n"
                . " AND m.clientID = :cid AND m2.clientID = :cid2";
            $params = [
                ':cid' => $this->clientID,
                ':cid2' => $this->clientDB,
                ':mapConfig' => $mapConfigID,
                ':chainToConfig' => $chainToConfigID,
            ];
            $this->DB->query($sql, $params);

            $this->DB->commit(); // commit all

            // Log it
            $logMessage = "Add new indirect sponsor email mapping: $chainName";
            $log = new LogData($this->clientID, $this->app->session->get('authUserID'));
            $log->saveLogEntry(self::GENERAL_DATA_CHANGE, $logMessage);
        } catch (PDOException | Exception $e) {
            Xtra::track($e->getMessage());
            $error = "Failed while inserting records for new indirect mapping.";
            $this->DB->rollback(); // undo all
        }
    }

    /**
     * Save new direct mapping for validated user inputs
     *
     * @param array  $listAssignments Assignments to Custom List and chain-to records (g_ddqSponsorEmailMaps)
     * @param string $mapName         g_ddqSponsorEmailMapConfig.mapName
     * @param string $fromColumn      g_ddqSponsorEmailMapConfig.fromColumn
     * @param string $toColumn        g_ddqSponsorEmailMapConfig.toColumn
     * @param string $chainName       g_ddqSponsorEmailMapChain.chainName
     * @param string $error           (reference) Provide error to caller
     *
     * @return void
     */
    public function saveDirectMapping(
        array $listAssignments,
        string $mapName,
        string $fromColumn,
        string $toColumn,
        string $chainName,
        string &$error = ''
    ): void {
        try {
            $this->DB->beginTransaction(); // all or nothing

            // Insert mapping
            $table = $this->mapTables['config'];
            $sql = "INSERT INTO $table (clientID, mapName, fromColumn, toColumn, locked) VALUES\n"
                . "(:cid, :name, :from, :to, 1)";
            $params = [':cid' => $this->clientID, ':name' => $mapName, ':from' => $fromColumn, ':to' => $toColumn];
            $this->DB->query($sql, $params);
            $mapConfigID = $this->DB->lastInsertId();

            $chainToConfigID = 0;

            // Insert chain configuration
            $table = $this->mapTables['chain'];
            $sql = "INSERT INTO $table (clientID, chainName, mapConfigID, chainToConfigID, locked) VALUES\n"
                . "(:cid, :name, :mapConfig, :chainToConfig, 1)";
            $params = [
                ':cid' => $this->clientID,
                ':name' => $chainName,
                ':mapConfig' => $mapConfigID,
                ':chainToConfig' => $chainToConfigID
            ];
            $this->DB->query($sql, $params);
            $chainID = $this->DB->lastInsertId();

            // Insert mapping records
            $table = $this->mapTables['maps'];
            $sql = "INSERT INTO $table (clientID, mapConfigID, fromID, toID, email, locked) VALUES\n"
                . "(:cid, :config, :from, :to, :email, 1)";
            foreach ($listAssignments as $element) {
                // map config records
                $params = [
                    ':cid' => $this->clientID,
                    ':config' => $mapConfigID,
                    ':from' => $element['itemID'],
                    ':to' => $element['matchID'],
                    ':email' => $element['email'],
                ];
                $this->DB->query($sql, $params);
            }

            $this->DB->commit(); // commit all

            // Log it
            $logMessage = "Add new direct sponsor email mapping: $chainName";
            $log = new LogData($this->clientID, $this->app->session->get('authUserID'));
            $log->saveLogEntry(self::GENERAL_DATA_CHANGE, $logMessage);
        } catch (PDOException | Exception $e) {
            Xtra::track($e->getMessage());
            $error = "Failed while inserting records for new indirect mapping.";
            $this->DB->rollback(); // undo all
        }
    }
}
