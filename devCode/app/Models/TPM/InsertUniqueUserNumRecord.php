<?php
/**
 * Provide transactions to insert records where a unique identifier (e.g., userTpNum or userCaseNum)
 * is needed.  These methods should be kept light.  In other words, they should do only what is
 * necessary to insert the record with the unique user number.  They should NOT insert related,
 * ancillary records.
 */

namespace Models\TPM;

use InvalidArgumentException;
use Lib\Database\MySqlPdo;
use Lib\Support\Xtra;
use Models\TPM\MultiAddr\TpAddrs;
use PDOException;
use Exception;
use Skinny\Log;

#[\AllowDynamicProperties]
final class InsertUniqueUserNumRecord
{
    /**
     * @var bool Set to true for log entries
     */
    public $debug = false;

    /**
     * @var MySqlPdo PDO database instance
     */
    private $DB;

    /**
     * @var string Client database
     */
    private $clientDB = '';

    /**
     * @var array Table defaults for missing columns and non-null-accepting columns
     */
    private $defaults = [];

    /**
     * @var array Table defaults for non-nullable columns having a null value
     */
    private $defaultsIfNull = [];

    /**
     * @var string Key for active userNum specification
     */
    private $specKey = '';

    /**
     * @var array[] Specification for each user num to be generated
     */
    private $tables = [
        'case' => [
            'tbl' => 'cases',
            'fld' => 'userCaseNum',
            'pfx' => '',
            'pad' => false,
            'idAlias' => 'caseID'
        ],
        'tpp' => [
            'tbl' => 'thirdPartyProfile',
            'fld' => 'userTpNum',
            'pfx' => '3P-',
            'pad' => true,
            'idAlias' => 'tpID'
        ],
        'trd' => [
            'tbl' => 'trainingData',
            'fld' => 'userTrNum',
            'pfx' => 'TR-',
            'pad' => true,
            'idAlias' => 'trainingID'
        ],
        'trc' => [
            'tbl' => 'cases',
            'fld' => 'userCaseNum',
            'pfx' => 'TR-',
            'pad' => false,
            'idAlias' => 'caseID'
        ],
        'tra' => [
            'tbl' => 'trainingAttach',
            'fld' => 'userTaNum',
            'pfx' => 'TA-',
            'pad' => true,
            'idAlias' => 'trainingAttachID'
        ],
        'gift' => [
            'tbl' => 'tpGifts',
            'fld' => 'userGethNum',
            'pfx' => 'G-',
            'pad' => true,
            'idAlias' => 'giftID'
        ],
        'engagement' => [
            'tbl' => 'thirdPartyProfile',
            'fld' => 'userTpNum',
            'pfx' => 'EG-',
            'pad' => true,
            'idAlias' => 'tpID'
        ]
    ];

    /**
     * Create class instance and set property values
     *
     * @param int $clientID 3PM tenant ID
     *
     * @throws Exception
     */
    public function __construct(private int $clientID)
    {
        $this->DB = Xtra::app()->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);

        // Derive user num prefixes
        $clientTable = $this->clientDB . '.clientProfile';
        $sql = "SELECT caseUserNumPrefix FROM $clientTable WHERE id = :cid LIMIT 1";
        $casePrefix = $this->DB->fetchValue($sql, [':cid' => $this->clientID]);
        $basePrefix = rtrim((string) $casePrefix, '0123456789-');
        foreach ($this->tables as $tblKey => &$spec) {
            $spec['pfx'] = $tblKey === 'case' ? $casePrefix : $basePrefix . $spec['pfx'];
        }
    }

    /**
     * Insert a new 3P profile with a transaction, guaranteeing a unique userTpNum
     *
     * @param array $profileAttributes Array of ColumnName => ValidatedValue elements
     *
     * @return array Keys 'userTpNum' and 'tpID'
     *
     * @throws PDOException|Exception
     */
    public function insertUnique3pProfile(array $profileAttributes): array
    {
        $result = $this->insertUserNumRecord($profileAttributes, 'tpp');
        if ($tpID = $result['tpID']) {
            try {
                (new TpAddrs($this->clientID))->syncTpAddrsFromEmbeddedAddress($tpID);
            } catch (PDOException | Exception $ex) {
                Xtra::track("Failed to sync addr for tpID $tpID - " . $ex->getMessage());
            }
        }
        return $result;
    }

    /**
     * Insert a new case record with a transaction, guaranteeing a unique userCaseNum
     *
     * @param array $caseAttributes Array of ColumnName => ValidatedValue elements
     *
     * @return array Keys 'userCaseNum' and 'caseID'
     *
     * @throws PDOException|Exception
     */
    public function insertUniqueCase(array $caseAttributes): array
    {
        return $this->insertUserNumRecord($caseAttributes, 'case');
    }

    /**
     * Insert a new trainingCase record with a transaction, guaranteeing a unique userTdNum
     *
     * @param array $trcAttributes Array of ColumnName => ValidatedValue elements
     *
     * @return array Keys 'userCaseNum' and 'caseID'
     *
     * @throws PDOException|Exception
     */
    public function insertUniqueTrainingCase(array $trcAttributes): array
    {
        return $this->insertUserNumRecord($trcAttributes, 'trc');
    }

    /**
     * Insert a new trainingData record with a transaction, guaranteeing a unique userTdNum
     *
     * @param array $trdAttributes Array of ColumnName => ValidatedValue elements
     *
     * @return array Keys 'userTrNum' and 'trainingID'
     *
     * @throws PDOException|Exception
     */
    public function insertUniqueTrainingData(array $trdAttributes): array
    {
        return $this->insertUserNumRecord($trdAttributes, 'trd');
    }

    /**
     * Insert a new trainingAttach record with a transaction, guaranteeing a unique userTaNum
     *
     * @param array $traAttributes Array of ColumnName => ValidatedValue elements
     *
     * @return array Keys 'userTaNum' and 'trainingAttachID'
     *
     * @throws PDOException|Exception
     */
    public function insertUniqueTrainingAttach(array $traAttributes): array
    {
        return $this->insertUserNumRecord($traAttributes, 'tra');
    }

    /**
     * Insert a new gift record with a transaction, guaranteeing a unique userGethNum
     *
     * @param array $giftAttributes Array of ColumnName => ValidatedValue elements
     *
     * @return array Keys 'userGethNum' and 'giftID'
     *
     * @throws PDOException|Exception
     */
    public function insertUniqueGift(array $giftAttributes): array
    {
        return $this->insertUserNumRecord($giftAttributes, 'gift');
    }

    /**
     * Insert a new engagement record with a transaction, guaranteeing a unique userEngageNum
     *
     * @param array $engagementAttributes Array of ColumnName => ValidatedValue elements.
     *                                    Must include isEngagement = 1 and engagementParentID > 0 for parent profile
     *
     * @return array Keys 'userTpNum' (altered for engagement) and 'tpID' (for engagement in thirdPartyProfile)
     *
     * @throws PDOException|Exception
     */
    public function insertUniqueEngagement(array $engagementAttributes): array
    {
        $result = $this->insertUserNumRecord($engagementAttributes, 'engagement');
        if ($tpID = $result['tpID']) {
            try {
                (new TpAddrs($this->clientID))->syncTpAddrsFromEmbeddedAddress($tpID);
            } catch (PDOException | Exception $ex) {
                Xtra::track("Failed to sync addr for tpID $tpID - " . $ex->getMessage());
            }
        }
        return $result;
    }

    /**
     * Derive PDO set values and params from array of attributes. Not table-specific without 3rd argument.
     *
     * @param array  $attributes  Array of column_name => validated_value elements
     * @param array  $ignore      Table column names to ignore
     * @param string $defaultsFor (optional) if provided, fill in missing default column values
     *
     * @return array
     */
    public function preparePdoAttributesForUpsert(array $attributes, array $ignore, string $defaultsFor = ''): array
    {
        // Provide defaults for columns that should not be null
        $onlyIfNull = [];
        if (!empty($defaultsFor) && ($defaults = $this->getTableDefaults($defaultsFor, $onlyIfNull))) {
            // Normalize attribute keys for case-insensitive comparison
            $lcKeyMap = [];
            foreach (array_keys($attributes) as $k) {
                $lcKeyMap[strtolower($k)] = $k;
            }
            // Assign defaults by case-insensitive key lookup
            foreach ($defaults as $defKey => $v) {
                $lcKey = strtolower($defKey);
                if (!array_key_exists($lcKey, $lcKeyMap)) {
                    if (!in_array($defKey, $onlyIfNull)) {
                        $attributes[$defKey] = $v; // only for columns with no define default
                    }
                } else {
                    $attrKey = $lcKeyMap[$lcKey];
                    if ($attributes[$attrKey] === null) {
                        if ($attrKey !== $defKey) {
                            unset($attributes[$attrKey]); // ditch the inexact field name
                        }
                        $attributes[$defKey] = $v; // always for non-nullable columns
                    }
                }
            }
        }

        // Prepare set values and params for PDO
        $setValues = $params = [];
        $lcIgnore = [];
        foreach ($ignore as $fld) {
            $lcIgnore[] = strtolower((string) $fld);
        }
        foreach ($attributes as $k => $v) {
            if (in_array(strtolower($k), $lcIgnore)) {
                continue;
            }
            $token = ':' . $k;
            $setValues[] = "$k = $token";
            $params[$token] = $v;
        }
        return [$setValues, $params];
    }

    /**
     * Get default values for table columns that do not accept null
     *
     * @param string $table      Table name, should include database name
     * @param array  $onlyIfNull Only use these defaults if a non-nullable columns has a null value
     *
     * @return array
     */
    private function getTableDefaults(string $table, array &$onlyIfNull = []): array
    {
        // If running a batch from the same instance, only hit the database once
        $defaults = $onlyIfNull = [];
        if (!empty($this->defaults[$table])) {
            $onlyIfNull = $this->defaultsIfNull[$table] ?? [];
            return $this->defaults[$table];
        }

        if ($this->DB->tableExists($table)) {
            try {
                $cols = $this->DB->fetchAssocRows("SHOW COLUMNS FROM $table");
                foreach ($cols as $c) {
                    if ($c['Key'] === 'PRI' || ($c['Default'] === null && $c['Null'] === 'YES')) {
                        continue;
                    } elseif ($c['Default'] !== null) {
                        // non-nullable column, but has defined default
                        $v = $c['Default'];
                        $t = $c['Type'];
                        if (str_contains((string) $t, 'int(')) {
                            $v = (int)$v;
                        } else {
                            $tm = time();
                            if (($t === 'datetime' || $t === 'timestamp') && $v === 'CURRENT_TIMESTAMP') {
                                $v = date('Y-m-d H:i:s', $tm);
                            } elseif ($t === 'date' && $v === 'CURRENT_DATE') {
                                $v = date('Y-m-d', $tm);
                            } elseif ($t === 'time') {
                                $v = date('H:i:s', $tm);
                            }
                        }
                        $onlyIfNull[] = $c['Field'];
                        $defaults[$c['Field']] = $v;
                    } else {
                        // non-nullable, no default defined
                        $v = '';
                        $t = $c['Type'];
                        if (str_contains((string) $t, 'int(') || str_contains((string) $t, 'enum(')) {
                            $v = 0;
                        } elseif ($t === 'datetime' || $t === 'timestamp') {
                            $v = '0000-00-00 00:00:00';
                        } elseif ($t === 'date') {
                            $v = '0000-00-00';
                        } elseif ($t === 'time') {
                            $v = '00:00:00';
                        }
                        $defaults[$c['Field']] = $v;
                    }
                }
                $this->defaultsIfNull[$table] = $onlyIfNull;
                $this->defaults[$table] = $defaults;
            } catch (PDOException | Exception) {
                $this->defaultsIfNull[$table] = $onlyIfNull;
                $this->defaults[$table] = $defaults;
            }
        }
        return $defaults;
    }

    /**
     * Insert a new user num record within a transaction, guaranteeing a unique user*Num
     *
     * @param array  $attributes Array of tableColumn => validatedValue elements
     * @param string $specKey    Key for userNum specification
     *
     * @return array Keys idAlias and userNum, as defined in $spec
     *
     * @throws PDOException|InvalidArgumentException|Exception
     */
    private function insertUserNumRecord(array $attributes, string $specKey): array
    {
        if ($this->debug) {
            Xtra::track('Using ' . self::class);
        }
        // Get the spec
        if (!array_key_exists($specKey, $this->tables)) {
            throw new InvalidArgumentException('Undefined user num key');
        }
        $this->specKey = $specKey;
        $spec = $this->tables[$specKey];

        // Statements to execute inside transaction
        $func = function ($db, $o, &$finish) {
            // Get next userNum and assign to placeholder in params
            if ($nextNum = $o->caller->getNextUserNum()) {
                $o->insParams[':userNum'] = $nextNum;
                $o->params[':userNum'] = $nextNum;
                // Perform the insert
                try {
                    // Duplicate userNum will throw exception only if it's a unique index
                    if (($res = $db->query($o->insSql, $o->insParams)) && $res->rowCount()) {
                        $o->rowID = $db->lastInsertId();
                        $o->userNum = $nextNum;
                        $finish = true;
                    }
                } catch (PDOException $dup) {
                    $code = (int)$dup->getCode();
                    if ($code === MysqlPdo::DUPLICATE_INDEX_VALUE_CODE
                        && str_contains($dup->getMessage(), (string) $o->userNumFld)
                    ) {
                        if ($o->debug) {
                            Xtra::track("Prevented failure from duplicate $o->userNumFld '$nextNum'");
                        }
                        $db->rollback(); // duplicate userTpNum, try again
                    } else {
                        // Rethrow any other exception
                        if ($o->debug) {
                            $err = $dup->getFile() . ':' . $dup->getLine()
                                . ' - ' . $dup->getMessage();
                            Xtra::track($err);
                        }
                        $db->rollback();
                        throw $dup;
                    }
                }
            }
            if ($finish) {
                $db->commit();
            } else {
                $db->rollback();
            }
        };

        try {
            $startTransact = microtime(true);
            // Prepare values and params
            $table = $this->clientDB . '.' . $spec['tbl'];
            $fld   = $spec['fld'];
            $default = [$fld => '', $spec['idAlias'] => 0];
            $ignore = ['id', 'clientID', $fld];
            $sets = $this->preparePdoAttributesForUpsert($attributes, $ignore, $table);
            [$setValues, $insParams] = $sets;
            $setValues[] = 'id = NULL';
            $setValues[] = "clientID = :clientID";
            $setValues[] = "$fld = :userNum";
            $insParams[':userNum'] = '';
            $insParams[":clientID"] = $this->clientID;

            // Build INSERT SQL
            $insSql = "INSERT INTO $table SET " . implode(', ', $setValues);

            $obj = (object)[
                'debug'      => $this->debug,
                'caller'     => $this,
                'insSql'     => $insSql,
                'insParams'  => $insParams,
                'userNumFld' => $fld,
                'userNum'    => '',
                'rowID'      => 0,
            ];
            $debugRef = $obj->debug ? $fld : null;
            $this->DB->setSessionSyncWait(true); // ensure replication occurs before read
            $this->DB->transact($func, $obj, $debugRef);
            $result = [$fld => $obj->userNum, $spec['idAlias'] => $obj->rowID];
            if ($obj->rowID) {
                // read waits on replication
                $this->DB->fetchValue("SELECT id FROM $table WHERE id = :id LIMIT 1", [':id' => $obj->rowID]);
            }
        } catch (PDOException | Exception $ex) {
            Xtra::track($ex->getMessage() . ' line: ' . $ex->getLine(), Log::ERROR);
            $result = $default;
        } finally {
            $this->DB->setSessionSyncWait(false); // always clear sync wait
            $endTransact = microtime(true);
        }
        if ($this->debug) {
            $elapsed = $endTransact - $startTransact;
            Xtra::track(compact('result', 'elapsed'));
        }
        return $result;
    }

    /**
     * Get next user*Num for INSERT
     *
     * @return string
     *
     * @throws Exception
     */
    private function getNextUserNum(): string
    {
        $this->bailIfNotInTransaction();
        $num = $this->nextInSequence($this->specKey);
        if ($this->specKey === 'trd') {
            $num2 = $this->nextInSequence('trc'); // sequence from other table
            $num = max($num, $num2);
        } elseif ($this->specKey === 'trc') {
            $num2 = $this->nextInSequence('trd'); // sequence from other table
            $num = max($num, $num2);
        }

        // TODO: remove clientProfile update if/when lastUserCaseNum column is removed from clientProfile
        if ($this->specKey === 'case') {
            $cliTbl = $this->clientDB . '.clientProfile';
            $sql = "UPDATE $cliTbl SET lastUserCaseNum = :num WHERE id = :cid LIMIT 1";
            $this->DB->query($sql, [':cid' => $this->clientID, ':num' => $num]);
        }

        $spec = $this->tables[$this->specKey];
        return $spec['pfx'] . ($spec['pad'] ? str_pad($num, 5, '0', STR_PAD_LEFT) : $num);
    }

    /**
     * Get next sequence number
     *
     * @param string $specKey Key for table specification
     *
     * @return int
     */
    private function nextInSequence(string $specKey): int
    {
        $spec  = $this->tables[$specKey];
        $table = $this->clientDB . '.' . $spec['tbl'];
        $fld   = $spec['fld'];
        $extraCondition = '';
        if ($specKey === 'tpp') {
            $extraCondition = " AND isEngagement = 0";
        } elseif ($specKey === 'engagement') {
            $extraCondition = " AND isEngagement = 1";
        }
        $sql = "SELECT $fld FROM $table\n"
            . "WHERE clientID = :clientID{$extraCondition} AND $fld LIKE :pfx\n"
            . "ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE";
        $params = [":clientID" => $this->clientID, ':pfx' => $spec['pfx'] . '%'];
        $lastNum = $this->DB->fetchValue($sql, $params);
        Xtra::track("SQL FOR 3P CHECK: $sql --- '$lastNum'");
        if (!($lastNum)) {
            $num = 0;
        } else {
            $numericTail = explode('-', (string) $lastNum)[1];
            $num = (int)ltrim($numericTail, '0') + 1;
        }
        if ($num < 1000) {
            $num = 1001;
        }
        return $num;
    }

    /**
     * Complain on attempt to use methods in this class outside a transaction.
     *
     * @return void
     *
     * @throws Exception
     */
    private function bailIfNotInTransaction(): void
    {
        if (!$this->DB->inTransaction()) {
            throw new Exception('Use ' . __METHOD__ . ' only inside a transaction.');
        }
    }
}
