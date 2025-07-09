<?php
/**
 * Compliance Model
 *
 * @see This model is a partial refactor of the Compliance class (ComplianceCls) from Legacy class_comply.php
 */

namespace Models\TPM;

/**
 * Class Compliance to deal with compliance factors
 *
 * @keywords Compliance, compliance factors
 */
#[\AllowDynamicProperties]
class Compliance
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var string Client database name
     */
    private $clientDB = null;

    /**
     * @var object Database instance
     */
    protected $DB = null;

    /**
     * @var array Factor info contains: name, description, group, weight, hide
     */
    private $factorInfo = [];

    /**
     * @var boolean Indicates if client has Compliance enabled
     */
    private $hasCompliance = false;

    /**
     * @var boolean Indicates if client has Compliance factors
     */
    private $hasFactors = false;

    /**
     * @var boolean Indicates if client has a risk tier
     */
    private $useTier = false;

    /**
     * @var array Holds array of tier names
     */
    private $tierNames = [];

    /**
     * @var array Holds array of type names
     */
    private $typeNames = [];

    /**
     * @var array Holds array of cat names
     */
    private $catNames = [];


    /**
     * Init class constructor
     *
     * @param integer $clientID Client ID
     */
    public function __construct(private $clientID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
        $features       = $this->app->ftr;
        $params         = [':clientID' => $this->clientID];

        if ($features->has(\Feature::TENANT_TPM)) {
            $this->hasCompliance = $features->has(\Feature::TENANT_TPM_COMPLIANCE);

            $sql = "SELECT COUNT(*) FROM {$this->clientDB}.tpComplyFactor WHERE clientID = :clientID";
            $this->hasFactors = ($this->DB->fetchValue($sql, $params) > 0);

            $sql = "SELECT COUNT(*) FROM {$this->clientDB}.riskTier WHERE clientID = :clientID";
            $riskTier = ($this->DB->fetchValue($sql, $params) > 0);

            $this->useTier = ($this->hasCompliance && $features->has(\Feature::TENANT_TPM_RISK) && $riskTier);
        }
    }

    /**
     * Allow reading of selected properties
     *
     * @param string $prop Property to read
     *
     * @return mixed value of property or null
     */
    public function __get($prop)
    {
        $rtn = match ($prop) {
            'useTier', 'hasCompliance', 'hasFactors' => $this->$prop,
            default => null,
        };
        return $rtn;
    }

    /**
     * Get tier lookup table
     *
     * @return array id/name pairs
     */
    public function tierMap()
    {
        $this->loadNameMaps();
        return $this->tierNames;
    }

    /**
     * Get 3P type lookup table
     *
     * @return array id/name pairs
     */
    public function typeMap()
    {
        $this->loadNameMaps();
        return $this->typeNames;
    }

    /**
     * Get 3P category lookup table
     *
     * @return array id/name pairs
     */
    public function catMap()
    {
        $this->loadNameMaps();
        return $this->catNames;
    }

    /**
     * Get lookup arrays for tiers, types and categories
     *
     * @return void
     */
    private function loadNameMaps()
    {
        if (!empty($this->typeNames)) {
            return;
        }
        $sql = "SELECT id, name FROM {$this->clientDB}.tpType WHERE clientID = :clientID ORDER BY name ASC";
        $this->typeNames = $this->DB->fetchKeyValueRows($sql, [':clientID' => $this->clientID]);
        $sql = "SELECT c.id, c.name FROM {$this->clientDB}.tpTypeCategory AS c\n"
            . "LEFT JOIN {$this->clientDB}.tpType AS t ON t.id = c.tpType AND t.clientID = :cID1\n"
            . "WHERE c.clientID = :cID2 AND t.id IS NOT NULL ORDER BY c.tpType ASC, c.name ASC";
        $this->catNames = $this->DB->fetchKeyValueRows($sql, [':cID1' => $this->clientID, ':cID2' => $this->clientID]);
        if ($this->useTier) {
            $sql = "SELECT id, tierName FROM {$this->clientDB}.riskTier\n"
                . "WHERE clientID = :clientID ORDER BY tierName ASC";
            $this->tierNames = $this->DB->fetchKeyValueRows($sql, [':clientID' => $this->clientID]);
        }
    }

    /**
     * Translate variance signature into component names
     *
     * @param string $sig 'tier|type|cat'
     *
     * @return object Corresponding names
     */
    public function translateSig($sig)
    {
        //make sure these are in place
        $this->loadNameMaps();
        [$tierID, $typeID, $catID] = explode('|', $sig);
        $rtn = (object)[
            'sig' => '$sig',
            'tier' => '',
            'type' => '',
            'cat' => '',
        ];
        if (isset($this->tierNames[$tierID])) {
            $rtn->tier = $this->tierNames[$tierID];
        }
        if (isset($this->typeNames[$typeID])) {
            $rtn->type = $this->typeNames[$typeID];
        }
        if (isset($this->catNames[$catID])) {
            $rtn->cat = $this->catNames[$catID];
        }
        return $rtn;
    }

    /**
     * Get a sorted list of variance signatures
     *
     * @return array Sorted string signatures
     */
    public function sortedVariances()
    {
        $sql = "SELECT DISTINCT CONCAT( o.tierID, '|', o.tpType, '|', o.tpTypeCategory ) AS sig\n"
            . "FROM {$this->clientDB}.tpComplyOverride AS o\n"
            . "LEFT JOIN {$this->clientDB}.tpComplyFactor AS f ON f.id = o.factorID\n"
            . "LEFT JOIN {$this->clientDB}.tpType AS t ON t.id = o.tpType\n"
            . "LEFT JOIN {$this->clientDB}.tpTypeCategory AS c ON c.id = o.tpTypeCategory\n"
            . "LEFT JOIN {$this->clientDB}.riskTier AS r ON r.id = o.tierID\n"
            . "WHERE f.clientID = :clientID\n"
            . "ORDER BY r.tierName DESC, t.name DESC, c.name DESC";
        $sigs = $this->DB->fetchValueArray($sql, [':clientID' => $this->clientID]);
        $sigs[] = '0|0|0';
        return $sigs;
    }

    /**
     * Get lookup table for factor names
     *
     * @return array id/name pairs
     */
    public function factorNames()
    {
        $this->loadFactorInfo();
        $tmp = [];
        foreach ($this->factorInfo as $fid => $info) {
            $tmp[$fid] = $info->name;
        }
        return $tmp;
    }

    /**
     * Create temp table to sort variance signatures
     *
     * @return void
     */
    public function createLookupTable()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $sql = "CREATE TEMPORARY TABLE {$this->clientDB}.tmpVary ("
            . "  tpSig varchar(32) NOT NULL default '', "
            . "  varySig varchar(32) NOT NULL default '', "
            . "  idList text NOT NULL, "
            . "  seq int NOT NULL AUTO_INCREMENT, "
            . "  PRIMARY KEY (seq), "
            . "  KEY tpSig (tpSig), "
            . "  KEY varySig (varySig)"
            . ")";
        $this->DB->query($sql);

        $sql = "SELECT c.tpType, c.id AS tpCat\n"
            . "FROM {$this->clientDB}.tpTypeCategory AS c\n"
            . "LEFT JOIN {$this->clientDB}.tpType AS t ON t.id = c.tpType AND t.clientID = :clientID1\n"
            . "WHERE c.clientID = :clientID2 AND t.id IS NOT NULL ORDER BY t.name ASC, c.name ASC";
        $tcs = $this->DB->fetchObjectRows($sql, [':clientID1' => $this->clientID, ':clientID2' => $this->clientID]);

        $varySql = "INSERT INTO {$this->clientDB}.tmpVary SET tpSig = :tpS, varySig = :vS, idList = :idL";
        if ($this->useTier) {
            $sql = "SELECT id FROM {$this->clientDB}.riskTier WHERE clientID = :clientID ORDER BY tierName ASC";
            $tiers = $this->DB->fetchValueArray($sql, [':clientID' => $this->clientID]);
            foreach ($tiers as $tier) {
                foreach ($tcs as $tc) {
                    $tpType = (int)$tc->tpType;
                    $tpCat = (int)$tc->tpCat;
                    $def = $this->matchVarianceDef($tier, $tpType, $tpCat);
                    $this->DB->query($varySql, [':tpS' => $def->tpSig, ':vS' => $def->varySig, ':idL' => $def->idList]);
                }
            }
        }
        $tier = 0;
        foreach ($tcs as $tc) {
            $tpType = $tc->tpType;
            $tpCat = $tc->tpCat;
            $def = $this->matchVarianceDef($tier, $tpType, $tpCat);
            $this->DB->query($varySql, [':tpS' => $def->tpSig, ':vS' => $def->varySig, ':idL' => $def->idList]);
        }
    }

    /**
     * Initiates conditions that will notify a batch process to kick off recalc3PScores method.
     *
     * @param int $userID User ID
     *
     * @return void
     */
    public function initTpRecalc($userID)
    {
        $sql = "SELECT id FROM {$this->DB->globalDB}.g_requiredProcessDef WHERE appKey = 'complianceFactorRecalc'";
        $processDefID = $this->DB->fetchValue($sql);

        // Set the flag in clientProfile.
        // This will disable the Status button and the Compliance tab for the client's 3P Profiles.
        $sql = "UPDATE {$this->clientDB}.clientProfile SET complianceRecalc = 1 WHERE id = :clientID LIMIT 1";
        $this->DB->query($sql, [':clientID' => $this->clientID]);

        // Upsert a g_requiredProcess record for the batch process to pick up.
        $sql = "SELECT id FROM {$this->DB->globalDB}.g_requiredProcess\n"
           . "WHERE clientID = :clientID AND processDefID = :processDefID AND deleted IS NULL";
        $params = [':clientID' => $this->clientID, ':processDefID' => $processDefID];
        if ($processID = $this->DB->fetchValue($sql, $params)) {
            // Existing record. Slap an extra 4 minutes on top of its existing runAt timestamp.
            $sql = "UPDATE {$this->DB->globalDB}.g_requiredProcess SET\n"
                . "userID = :userID, runAt = (current_timestamp + INTERVAL 4 MINUTE)\n"
                . "WHERE id = :processID LIMIT 1";
            $params = [':userID' => $userID, ':processID' => $processID];
            $this->DB->query($sql, $params);
        } else {
            // New record. Set its runAt time 4 minutes from now.
            $sql = "INSERT INTO {$this->DB->globalDB}.g_requiredProcess SET\n"
                ."processDefID = :processDefID, userID = :userID, clientID = :clientID, "
                ."runAt = (current_timestamp + INTERVAL 4 MINUTE), created = current_timestamp";
            $params = [':processDefID' => $processDefID, ':userID' => $userID, ':clientID' => $this->clientID];
            $this->DB->query($sql, $params);
        }
    }

    /**
     * Recalculates compliance factor scores for supplied third parties.
     *
     * @param int $tpID Third Party Profile ID
     *
     * @return void
     */
    public function recalc3PScores($tpID)
    {
        if ($tpID <= 0) {
            return;
        }
        // Recalculate the 3P's compliance factors.
        $sql = "SELECT tpType, tpTypeCategory, riskModel FROM {$this->clientDB}.thirdPartyProfile "
            ."WHERE id = :tpID AND clientID = :clientID LIMIT 1";
        $params = [':tpID' => $tpID, ':clientID' => $this->clientID];
        [$tpType, $tpTypeCat, $riskModel] = $this->DB->fetchIndexedRow($sql, $params);

        // get applicable variance definitions
        $tpTier = 0;
        if ($this->useTier && $riskModel > 0) {
            $sql = "SELECT tier FROM {$this->clientDB}.riskAssessment\n"
                . "WHERE tpID = :tpID AND status ='current' AND model = :riskModel AND clientID = :clientID LIMIT 1";
            $params = [':tpID' => $tpID, ':riskModel' => $riskModel, ':clientID' => $this->clientID];
            if ($tpTier = $this->DB->fetchValue($sql, $params)) {
                $variDef = $this->matchVarianceDef($tpTier, $tpType, $tpTypeCat);
            } else {
                $tpTier = 0;
                $variDef = $this->matchVarianceDef(0, $tpType, $tpTypeCat);
            }
        } else {
            // no tier
            $variDef = $this->matchVarianceDef(0, $tpType, $tpTypeCat);
        }
        $sql = "SELECT factorID, compliance, LEFT(tstamp,10) AS `tstamp` FROM {$this->clientDB}.tpComply\n"
            . "WHERE tpID = :tpID AND FIND_IN_SET(factorID, :defList)";
        $params = [':tpID' => $tpID, ':defList' => $variDef->idList];
        $compRows = $this->DB->fetchObjectRows($sql, $params);
        $compScore = $this->calcScore($variDef->variance, $compRows, false, true);
        // Update the thirdPartyProfile's complianceComplete and complianceRecalc values.
        $sql = "UPDATE {$this->clientDB}.thirdPartyProfile SET\n"
            . "complianceComplete = :score, complianceRecalc = current_timestamp WHERE id = :tpID LIMIT 1";
        $params = [':score' => $compScore->percent, ':tpID' => $tpID];
        $this->DB->query($sql, $params);
    }

    /**
     * Calculate compliance completion based on inputs
     *
     * @param object  $varyDef       varianceDef from matchVarianceDef()
     * @param array   $complyRecs    Factor completion records
     * @param boolean $withInfo      Include factorInfo if true
     * @param boolean $noPercentSign Omit '%' if true
     *
     * @return object Result object
     */
    public function calcScore($varyDef, $complyRecs, $withInfo = false, $noPercentSign = false)
    {
        $this->loadFactorInfo();
        $lastUpdate = '';
        $result = [];
        $info = [];
        $updated = [];
        foreach (array_keys($this->factorInfo) as $k) {
            $result[$k] = 'n/a';
        }
        foreach (array_keys($varyDef->weights) as $k) {
            $result[$k] = 'none';
            $updated[$k] = '(never)';
            if ($withInfo) {
                $info[$k] = $this->factorInfo[$k];
            }
        }
        $sum = 0;
        foreach ($complyRecs as $rec) {
            $id = $rec->factorID;
            $comp = $rec->compliance;
            switch ($comp) {
                case 'full':
                    $mul = 1;
                    break;
                case 'half':
                    $mul = 0.5;
                    break;
                default:
                    $comp = 'none';
                    $mul = 0;
            }
            $result[$id] = $comp;
            $updated[$id] = $rec->tstamp;
            if ($rec->tstamp > $lastUpdate) {
                $lastUpdate = $rec->tstamp;
            }
            $sum += $varyDef->weights[$id] * $mul;
        }
        if ($varyDef->total > 0) {
            $percent = round(($sum * 100)/$varyDef->total, 0);
        } else {
            $percent = '0';
        }
        $percent .= ($noPercentSign ? '' : '%');
        $rtn = new \stdClass();
        $rtn->sum = $sum;
        $rtn->percent = $percent;
        $rtn->updated = $updated;
        $rtn->lastUpdate = $lastUpdate;
        if ($withInfo) {
            $rtn->info = $info;
        }
        $rtn->result = $result;
        return $rtn;
    }

    /**
     * Get compliance variance by signature
     *
     * @param string $varySig 'tier|type|cat' variance signature
     *
     * @return object varianceDef
     */
    public function fetchVarianceDef($varySig)
    {
        $this->loadFactorInfo();
        if ($varySig == '0|0|0') {
            $rows = [];
        } else {
            [$tier, $type, $cat] = explode('|', $varySig);
            $tier = (int)$tier;
            $type = (int)$type;
            $cat  = (int)$cat;
            $sql = "SELECT factorID, overrideWeight AS weight, overrideHide AS hide \n"
                . "FROM {$this->clientDB}.tpComplyOverride "
                . "WHERE tpType = :type AND tpTypeCategory = :category AND tierID = :tier";
            $params = [':type' => $type, ':category' => $cat, ':tier' => $tier];
            $rows = $this->DB->fetchObjectRows($sql, $params);
        }
        $varyRows = [];
        // prepare variance rows
        foreach ($rows as $row) {
            $factorID = $row->factorID;
            unset($row->factorID);
            $varyRows[(string)$factorID] = $row;
        }
        $variance = (object)[
            'total' => 0,
            'idList' => '',
            'weights' => [],
        ];
        $ids = [];
        // iterate through factors in order
        foreach ($this->factorInfo as $fid => $info) {
            if (!array_key_exists($fid, $varyRows)) {
                if ($info->hide == 1) {
                    continue;
                }
                $ids[] = $fid;
                $variance->total += $info->weight;
                $variance->weights[$fid] = $info->weight;
            } else {
                $vary = $varyRows[$fid];
                if ($vary->hide == 1) {
                    continue;
                }
                $ids[] = $fid;
                $variance->total += $vary->weight;
                $variance->weights[$fid] = $vary->weight;
            }
        }
        $variance->idList = implode(',', $ids);
        return $variance;
    }

    /**
     * Load factorInfo property
     *
     * @return void
     */
    private function loadFactorInfo()
    {
        if (!$this->hasFactors || !empty($this->factorInfo)) {
            return;  // already done or nothing to do
        }

        $sql = "SELECT f.id, f.weight, f.hide, f.name, f.description, f.grp "
            . "FROM {$this->clientDB}.tpComplyFactor AS f "
            . "LEFT JOIN {$this->clientDB}.tpComplyGroup AS g ON g.id = f.grp "
            . "WHERE f.clientID = :clientID "
            . "ORDER BY g.sequence ASC, g.name ASC, f.sequence ASC, f.name ASC";
        $params = [':clientID' => $this->clientID];
        $rows = $this->DB->fetchObjectRows($sql, $params);

        foreach ($rows as $row) {
            $this->factorInfo[(string)$row->id] = (object)[
                'name'        => $row->name,
                'description' => $row->description,
                'grp'         => $row->grp,
                'weight'      => $row->weight,
                'hide'        => $row->hide
            ];
        }
    }

    /**
     * Match 3P to appropriate variance definition
     *
     * @param integer $tierID riskTier.id
     * @param integer $tpType tpType.id
     * @param integer $tpCat  tpTypeCategory.id
     *
     * @return object variance definition for specified 3P profile
     */
    public function matchVarianceDef($tierID, $tpType, $tpCat)
    {
        $sig = '0|0|0';
        if ($this->useTier) {
            $tpSig = $tierID . '|' . $tpType . '|' . $tpCat;
            if ($this->varianceExists($tierID, $tpType, $tpCat)) {
                $sig = $tierID . '|' . $tpType . '|' . $tpCat;
            } elseif ($this->varianceExists($tierID, $tpType, 0)) {
                $sig = $tierID . '|' . $tpType . '|0';
            } elseif ($this->varianceExists($tierID, 0, 0)) {
                $sig = $tierID . '|0|0';
            }
        } else {
            $tpSig = '0|' . $tpType . '|' . $tpCat;
            if ($this->varianceExists(0, $tpType, $tpCat)) {
                $sig = '0|' . $tpType . '|' . $tpCat;
            } elseif ($this->varianceExists(0, $tpType, 0)) {
                $sig = '0|' . $tpType . '|0';
            }
        }
        $varianceDef = $this->fetchVarianceDef($sig);
        $rtn = new \stdClass();
        $rtn->tpSig    = $tpSig;
        $rtn->varySig  = $sig;
        $rtn->idList   = $varianceDef->idList;
        $rtn->variance = $varianceDef;
        return $rtn;
    }

    /**
     * Determine a variance exists with a given signature
     *
     * @param integer $tier riskTier.id
     * @param integer $type tpType.id
     * @param integer $cat  tpTypeCategory.id
     *
     * @return boolean
     */
    private function varianceExists($tier, $type, $cat)
    {
        $tier = (int)$tier;
        $type = (int)$type;
        $cat  = (int)$cat;

        $sql = "SELECT factorID FROM {$this->clientDB}.tpComplyOverride WHERE tpType = :tpType\n"
            . "AND tpTypeCategory = :category AND tierID = :tier LIMIT 1";
        $params = [':tpType' => $type, ':category' => $cat, ':tier' => $tier];
        return ($this->DB->fetchValue($sql, $params) > 0);
    }
}
