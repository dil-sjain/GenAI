<?php
/**
 * Model for TPM Fields/Lists - 3P Compliance Factors
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\LogData;
use Lib\Validation\ValidateFuncs;

/**
 * Class handles all Fields/Lists model requirements for 3P Compliance Factors.
 *
 * @keywords compliance factors, tpm, fields lists, model, content control, settings
 */
#[\AllowDynamicProperties]
class TpCompFactorsData
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
     * @var object LogData instance
     */
    protected $LogData = null;

    /**
     * @var boolean Whether current tenant has Risk Access
     */
    protected $hasRisk = false;

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
     * @throws InvalidArgumentException Thrown if tenantID or userID are <= 0;
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
        $this->setupTableNames();
        $this->hasRiskFeature();
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
     * Method to set translation text values needed for this class.
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

    /**
     * Setup required table names in the tbl object.
     *
     * @return void
     */
    protected function setupTableNames()
    {
        $clientDB  = $this->DB->getClientDB($this->tenantID);
        $this->tbl->clProfile     = $clientDB .'.clientProfile';
        $this->tbl->riskTier      = $clientDB .'.riskTier';
        $this->tbl->riskModel     = $clientDB .'.riskModel';
        $this->tbl->riskModelTier = $clientDB .'.riskModelTier';
        $this->tbl->tmpVary       = $clientDB .'.tmpVary';
        $this->tbl->tpComply      = $clientDB .'.tpComply';
        $this->tbl->tpComplyFact  = $clientDB .'.tpComplyFactor';
        $this->tbl->tpComplyGrp   = $clientDB .'.tpComplyGroup';
        $this->tbl->tpComplyOvr   = $clientDB .'.tpComplyOverride';
        $this->tbl->tpType        = $clientDB .'.tpType';
        $this->tbl->tpTypeCat     = $clientDB .'.tpTypeCategory';
    }

    /**
     * Public method to return value of hasRisk
     *
     * @return boolean True is tenant hasRisk, else false
     */
    public function hasRiskAccess()
    {
        return $this->hasRisk;
    }

    /**
     * Public method to get all default compliance factors.
     * Not getting overrides, or by some whacky combination.
     * Just the default ones, no tiers, groups, or anything else.
     *
     * @return array [weight: total weight of all records, recs: array of DB object rows]
     */
    public function getFactors()
    {
        return $this->fetchDefaultFactors();
    }

    /**
     * Public method to get a specified factor
     *
     * @param integer $id tpComplyFactor.id
     *
     * @return object DB fetchObjectRow result (no total weight, row only)
     */
    public function getFactorByID($id)
    {
        $row = $this->fetchDefaultFactors($id);
        return $row['recs'][0];
    }

    /**
     * Ensure client has compliance groups, else throw an error;
     *
     * @return boolean Return 1 if they have compliance groups, else 0; (true/false)
     */
    public function hasComplianceGroups()
    {
        return $this->DB->fetchValue(
            "SELECT count(id) FROM {$this->tbl->tpComplyGrp} WHERE clientID = :clientID LIMIT 1",
            [':clientID' => $this->tenantID]
        );
    }
    /**
     * Get default factor(s)
     *
     * @param integer $id tpComplyFactor.id (Optional. An empty $id returns all, else returns single record.)
     *
     * @return array [weight: total weight of all records, recs: array of DB object rows]
     */
    protected function fetchDefaultFactors($id = 0)
    {
        $sql = "SELECT f.id, f.name, f.description, f.hide, f.weight, f.sequence AS seq, "
            ."0 AS pct, f.grp, g.name AS grpName, IF( \n"
                ."(IF(\n"
                    ."(SELECT COUNT(tcf.id) FROM {$this->tbl->tpComplyFact} AS tcf "
                    ."WHERE tcf.id = f.id AND tcf.clientID = f.clientID LIMIT 1)\n"
                ."> 0, 0, 1)) \n"
                ."+ (SELECT COUNT(tc.factorID) FROM {$this->tbl->tpComply} AS tc WHERE tc.factorID = f.id LIMIT 1)\n"
            ." > 0, 0, 1) AS canDel "
            ."FROM {$this->tbl->tpComplyFact} AS f \n"
            ."LEFT JOIN {$this->tbl->tpComplyGrp} AS g ON (g.id = f.grp)"
            ."WHERE f.clientID = :tenantID ". ($id ? 'AND f.id = :id' : '') ."\n"
            ."ORDER BY g.sequence ASC, g.name ASC, f.sequence ASC, f.name ASC";
        $params = [':tenantID' => $this->tenantID];
        if ($id) {
            $params[':id'] = $id;
        }
        $rows = $this->DB->fetchObjectRows($sql, $params);
        $data = $this->organizeDefaultVariance($rows);
        return [
            'weight' => $data['totalWeight'],
            'recs' => $data['rows'],
        ];
    }

    /**
     * Get all risk tiers
     *
     * @return array Array of Objects  (db result)
     */
    public function getTiers()
    {
        if (!$this->hasRisk) {
            return null;
        }
        $sql = "SELECT DISTINCT(rmt.tier) AS id, rt.tierName FROM {$this->tbl->riskModelTier} AS rmt\n"
            ."LEFT JOIN {$this->tbl->riskModel} AS rm ON (rmt.model = rm.id)\n"
            ."LEFT JOIN {$this->tbl->riskTier} AS rt ON (rmt.tier = rt.id)\n"
            ."WHERE rmt.clientID = :tenantID AND rm.id IS NOT NULL AND rm.status = 'complete' AND rt.id IS NOT NULL\n"
            ."ORDER BY rt.tierName ASC";
        return $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID]);
    }

    /**
     * Get all 3P Types
     *
     * @return array Array of Objects  (db result)
     */
    public function getTypes()
    {
        $sql = "SELECT id, name FROM {$this->tbl->tpType} WHERE clientID = :tenantID ORDER BY name ASC";
        return $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID]);
    }

    /**
     * Get 3P Compliance Groups
     *
     * @return array DB object array.
     */
    public function getGroups()
    {
        return $this->DB->fetchObjectRows(
            "SELECT id, name FROM {$this->tbl->tpComplyGrp} WHERE clientID = :tenantID ORDER BY sequence ASC, name ASC",
            [':tenantID' => $this->tenantID]
        );
    }

    /**
     * Grabs tenant companyName for PDF use.
     * No sense in loading client model just for this.
     *
     * @return object clientProfile.clientName
     */
    public function getTenantInfo()
    {
        return $this->DB->fetchObjectRow(
            "SELECT clientName, logoFileName FROM {$this->tbl->clProfile} WHERE id = :tid LIMIT 1",
            [':tid' => $this->tenantID]
        );
    }

    /**
     * Get a variance
     *
     * @param integer $tierID Optional, but must pass at least one of tier or type ID.
     * @param integer $typeID Optional, but must pass at least one of tier or type ID.
     * @param integer $catID  Optional, but must pass at least one of tier or type ID.
     *
     * @return array Array of db object rows
     */
    public function getVariance($tierID = 0, $typeID = 0, $catID = 0)
    {
        if (!$this->hasRisk) {
            $tierID = 0;
        }
        if (!$tierID && !$typeID) {
            return [];
        }
        $sql = "SELECT f.id, f.name, f.description, f.hide, f.weight, f.sequence AS seq, 0 AS pct, f.grp, "
            ."g.name AS grpName, o.factorID AS oID, o.overrideWeight AS oWeight, o.overrideHide AS oHide, 0 AS oPct, "
            . "IF("
                ."o.factorID IS NOT NULL AND (f.weight <> o.overrideWeight OR f.hide <> o.overrideHide ), 1, 0"
            .") AS overridden \n"
            ."FROM {$this->tbl->tpComplyFact} AS f \n"
            . "LEFT JOIN {$this->tbl->tpComplyOvr} AS o ON ("
                ."o.factorID = f.id AND o.tpType = :typeID AND o.tpTypeCategory = :catID AND o.tierID = :tierID"
            .") \n"
            ."LEFT JOIN {$this->tbl->tpComplyGrp} AS g ON (g.id = f.grp)"
            ."WHERE f.clientID = :tenantID \n"
            ."ORDER BY g.sequence ASC, g.name ASC, f.sequence ASC, f.name ASC";
        $params = [':typeID' => $typeID, ':catID' => $catID, ':tierID' => $tierID, ':tenantID' => $this->tenantID];
        $rows = $this->DB->fetchObjectRows($sql, $params);
        $data = $this->organizeCustomVariance($rows);
        return [
            'weight' => $data['totalWeight'],
            'active' => $data['totalActive'],
            'recs' => $data['rows'],
        ];
    }

    /**
     * Get info for variance mapping
     *
     * @param boolean $noFormat Pass true if only data object is desired.
     *
     * @return mixed Array if formatting data, else data object from getVarianceMapData;
     */
    public function getVarianceMapping($noFormat = false)
    {
        $map = $this->getVarianceMapData();
        if ($noFormat == true) {
            return $map;
        }
        return $this->createVarianceMap($map);
    }

    /**
     * Quickly grab the current compliance threshold
     *
     * @return integer
     */
    public function getThreshold()
    {
        return $this->DB->fetchValue(
            "SELECT complThreshold FROM {$this->tbl->clProfile} WHERE id = :tenantID",
            [':tenantID' => $this->tenantID]
        );
    }

    /**
     * Update the compliance threshold
     *
     * @param integer $val    New threshold value (valid values are 1 to 99)
     * @param integer $oldVal Old threshold value
     *
     * @return boolean True on success, else false
     */
    public function updateThreshold($val, $oldVal)
    {
        $val = (int)$val;
        $oldVal = (int)$oldVal;
        if ($val == $oldVal) {
            return true;
        }
        $upd = $this->DB->query(
            "UPDATE {$this->tbl->clProfile} SET complThreshold = :val WHERE id = :tenantID",
            [':val' => $val, ':tenantID' => $this->tenantID]
        );
        if ($upd->rowCount() < 1) {
            return false;
        }
        return true;
    }

    /**
     * grab cats for the specified type
     *
     * @param integer $typeID tpType.id
     *
     * @return array Array of db object rows
     */
    public function getCatsByType($typeID)
    {
        $sql = "SELECT id, name FROM {$this->tbl->tpTypeCat} WHERE clientID = :tenantID AND tpType = :typeID ";
        return $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID, ':typeID' => $typeID]);
    }

    /**
     * Save factor
     *
     * @param array $vals Array of tpComplyFactor values. ** denotes required [
     *                      id          => .id (pass as 0 to add instead of update)
     *                      grp         => .grp (compliance factor group ID, may be 0 for none)
     *                      grpName     => Group name as selected from list (for logging, saving a query)
     *                      name**      => .name (name for the factor)
     *                      weight      => .weight (weight for the new factor. defaults to 50 if not between 1-100)
     *                      seq         => .sequence (presentation order)
     *                      hide        => .hide (Set to 1 for inactive to hide, else 0 to show/active. default 0)
     *                      description => .description (description of factor. )
     *                    ]
     * @param array $old  Array structured as $new, but storing the previous values if applicable. (Optional)
     *
     * @return boolean True on success, else false
     */
    public function saveFactor($vals, $old = [])
    {
        $validateFuncs = new ValidateFuncs();
        if (empty($vals['name'])) {
            return false;
        }
        //validate name and description
        if (!$validateFuncs->checkInputSafety($vals['name'])) {
            throw new \InvalidArgumentException('Name contains unsafe content such as HTML tags, JavaScript, or other unsafe content.');
        }
        if (!empty($vals['description']) && !$validateFuncs->checkInputSafety($vals['description'])) {
            throw new \InvalidArgumentException('Description contains unsafe content such as HTML tags, JavaScript, or other unsafe content.');
        }
        $id = (!empty($vals['id']) ? (int)$vals['id'] : 0);
        $params = [':tenantID' => $this->tenantID];
        if ($id > 0) {
            $sql = "UPDATE {$this->tbl->tpComplyFact} SET ";
            $where = "WHERE id = :id AND clientID = :tenantID LIMIT 1";
            $params[':id'] = $id;
        } else {
            $sql = "INSERT INTO {$this->tbl->tpComplyFact} SET clientID = :tenantID, ";
            $where = '';
        }
        $sql .= 'name = :name, ';
        $params[':name'] = $vals['name'];
        if (!empty($vals['description'])) {
            $sql .= 'description = :description, ';
            $params[':description'] = $vals['description'];
        }
        $vals['weight'] = (int)$vals['weight'];
        $params[':weight'] = (($vals['weight'] < 1 || $vals['weight'] > 100) ? 50 : $vals['weight']);
        $sql .= 'weight = :weight, ';

        $vals['hide'] = (int)$vals['hide'];
        $params[':hide'] = (($vals['hide'] == 1 ) ? 1 : 0);
        $sql .= 'hide = :hide, ';

        $vals['seq'] = (int)$vals['seq'];
        $params[':seq'] = (($vals['seq'] > 0 ) ? $vals['seq'] : 0);
        $sql .= 'sequence = :seq, ';

        $vals['grp'] = (int)$vals['grp'];
        $params[':grp'] = (($vals['grp'] > 0 ) ? $vals['grp'] : 0);
        $sql .= 'grp = :grp ';
        if (!empty($where)) {
            $sql .= $where;
        }
        $save = $this->DB->query($sql, $params);
        if (!$save->rowCount()) {
            return false;
        }
        // initiate recalc and then log (66 = edit, 65 = add)
        $this->recalculateTps();
        $logMsg = [];
        foreach ($vals as $k => $v) {
            if ($k == 'id' || $k == 'grpName') {
                continue;
            }
            if ($k == 'grp') {
                $k = 'grpName';
                $v = $vals['grpName'];
            }
            if ($id > 0 && !empty($old[$k])) {
                $logMsg[] = $k .': `'. $old[$k] .'` => `'. $v .'`';
            } else {
                $logMsg[] = $k .': `'. $v .'`';
            }
        }
        $logMsg = implode(', ', $logMsg);
        $this->LogData->saveLogEntry(($id > 0 ? 66:65), $logMsg);
        return true;
    }

    /**
     * Remove a compliance factor
     *
     * @param integer $id tpComplyFactor.id
     *
     * @return boolean True on success, else false
     */
    public function removeFactor($id)
    {
        $id = (int)$id;
        if ($id < 1) {
            return false;
        }
        $rec = $this->getFactorByID($id);
        if (!$rec) {
            return false;
        }
        if ($rec->canDel != 1) {
            return false;
        }
        $sql = "DELETE FROM {$this->tbl->tpComplyFact} WHERE id = :id AND clientID = :tenantID LIMIT 1";
        $params = [':id' => $id, ':tenantID' => $this->tenantID];
        $res = $this->DB->query($sql, $params);
        if ($res->rowCount() < 1) {
            return false;
        }
        $this->recalculateTps();
        $logMsg = "name: `{$rec->name}`";
        $this->LogData->saveLogEntry(67, $logMsg);
        // Delete any overrides (not logged since factor delete is logged.)
        $this->DB->query("DELETE FROM {$this->tbl->tpComplyOvr} WHERE factorID = :id LIMIT 1", [':id' => $id]);
        return true;
    }

    /**
     * Update a variance override
     *
     * @param array $data   Array [
     *                        id      => tpComplyFactor.id
     *                        oID     => tpComplyOverride.oID
     *                        weight  => desired weight value
     *                        hide    => hide value (0 = no, 1 = yes)
     *                        oldVals => array of previous row values
     *                      ]
     * @param array $filter Array [
     *                        tier => [ id => risk tier id, name => risk tier name ],
     *                        type => [ id => 3P type id, name => 3P type name ],
     *                        cat  => [ id => 3P type category id, name => 3P type category name ]
     *                      ]
     *
     * @return boolean true if rows affected (update/insert), else false
     */
    public function updateVarianceOverride($data, $filter)
    {
        // nothing changed...
        if ($data['weight'] == $data['oldVals']['oWeight'] && $data['hide'] == $data['oldVals']['oHide']) {
            return true;
        }
        // values submitted match default? should be restoring to default (shouldn't need this, but just in case)
        if ($data['weight'] == $data['oldVals']['weight'] && $data['hide'] == $data['oldVals']['hide']) {
            if ($data['oID'] > 0) {
                return $this->removeVarianceOverride($filter, $data['oID']);
            }
        }
        $logChanges = [];
        if ($data['oID'] > 0) {
            $sql = "UPDATE {$this->tbl->tpComplyOvr} SET overrideWeight = :weight, overrideHide = :hide "
                ."WHERE factorID = :fID AND tpType = :type AND tpTypeCategory = :cat AND tierID = :tier";
            if ($data['weight'] != $data['oldVals']['oWeight']) {
                $logChanges[] = 'weight: '. $data['oldVals']['oWeight'] .' => `'. $data['weight'] .'`';
            }
            if ($data['hide'] != $data['oldVals']['oHide']) {
                $logChanges[] = 'hide: '. $data['oldVals']['oHide'] .' => `'. $data['hide'] .'`';
            }
        } else {
            $sql = "INSERT INTO {$this->tbl->tpComplyOvr} SET factorID = :fID, tpType = :type, "
                ."tpTypeCategory = :cat, overrideWeight = :weight, overrideHide = :hide, tierID = :tier";
            $logChanges[] = 'weight: '. $data['oldVals']['weight'] .' => `'. $data['weight'] .'`';
            $logChanges[] = 'hide: '. $data['oldVals']['hide'] .' => `'. $data['hide'] .'`';
        }
        $params = [
            ':weight' => $data['weight'],
            ':hide' => $data['hide'],
            ':fID' => (!empty($data['oID']) ? $data['oID'] : $data['id']),
            ':type' => $filter['type']['id'],
            ':cat' => $filter['cat']['id'],
            ':tier' => $filter['tier']['id'],
        ];
        $upd = $this->DB->query($sql, $params);
        if (!$upd->rowCount()) {
            return false;
        }
        $this->recalculateTps();
        $logMsg = [];
        if ($filter['tier']['id'] > 0) {
            $logMsg[] = 'Risk Tier: `'. $filter['tier']['name'] .'`';
        }
        if ($filter['type']['id'] > 0) {
            $logMsg[] = '3P Type: `'. $filter['type']['name'] .'`';
        }
        if ($filter['cat']['id'] > 0) {
            $logMsg[] = '3P Category: `'. $filter['cat']['name'] .'`';
        }
        $logMsg = implode(', ', $logMsg);
        $logChanges = implode(', ', $logChanges);
        $this->LogData->saveLogEntry(68, $logMsg .' -- '. $logChanges);
        return true;
    }

    /**
     * Remove all variance overrides, or a single override ID factorID is present.
     *
     * @param array   $filter   Array [
     *                            tier => [ id => risk tier id, name => risk tier name ],
     *                            type => [ id => 3P type id, name => 3P type name ],
     *                            cat  => [ id => 3P type category id, name => 3P type category name ]
     *                          ]
     * @param integer $factorID tpComplyOverride factor.id (optional)
     * @param string  $cstmMsg  Custom deletion message for logging purposes, if applicable.
     *
     * @return boolean True on success, else false
     */
    public function removeVarianceOverride($filter, $factorID = 0, $cstmMsg = '')
    {
        $factorID = (int)$factorID;
        $params = [
            ':typeID' => $filter['type']['id'],
            ':catID' => $filter['cat']['id'],
            ':tierID' => $filter['tier']['id'],
        ];
        $sql = "DELETE FROM {$this->tbl->tpComplyOvr} WHERE ";
        if ($factorID > 0) {
            $sql .= "factorID = :factorID AND ";
            $params[':factorID'] = $factorID;
        }
        $sql .= "tpType = :typeID AND tpTypeCategory = :catID AND tierID = :tierID";
        $del = $this->DB->query($sql, $params);
        if ($del->rowCount() < 1) {
            return false;
        }
        $this->recalculateTps();
        if (!empty($cstmMsg)) {
            $logMsg = $cstmMsg;
        } else {
            $logMsg = [];
            if ($filter['tier']['id'] > 0) {
                $logMsg[] = 'Risk Tier: `'. $filter['tier']['name'] .'`';
            }
            if ($filter['type']['id'] > 0) {
                $logMsg[] = '3P Type: `'. $filter['type']['name'] .'`';
            }
            if ($filter['cat']['id'] > 0) {
                $logMsg[] = '3P Category: `'. $filter['cat']['name'] .'`';
            }
            $logMsg = implode(', ', $logMsg);
            $logMsg .= ' -- Remove custom override definition.';
        }
        $this->LogData->saveLogEntry(68, $logMsg);
        return true;
    }

    /**
     * Get the variance mapping data
     *
     * @return object
     */
    private function getVarianceMapData()
    {
        $comp = new \Models\TPM\Compliance($this->tenantID);
        $rtn = (object)null;
        $rtn->UseTier = ($comp->useTier) ? 1 : 0;
        $rtn->FactorNames = $comp->factorNames();
        if ($comp->useTier) {
            $rtn->TierMap = $comp->tierMap();
        }
        $rtn->TypeMap = $comp->typeMap();
        $rtn->CatMap = $comp->catMap();
        $comp->createLookupTable();
        $vsigs = $comp->sortedVariances();
        $map = [];
        foreach ($vsigs as $vsig) {
            $data = (object)null;
            $variDef = $comp->fetchVarianceDef($vsig);
            $vdef = (object)null;
            [$vdef->r, $vdef->t, $vdef->c] = explode('|', (string) $vsig);
            $data->vdef = $vdef;
            $data->total = $variDef->total;
            $data->weights = $variDef->weights;
            $sql = "SELECT tpSig FROM {$this->tbl->tmpVary} WHERE varySig = :vsig ORDER BY seq ASC";
            $tpSigs = $this->DB->fetchValueArray($sql, [':vsig' => $vsig]);
            $tmp = [];
            foreach ($tpSigs as $sig) {
                $p = (object)null;
                [$p->r, $p->t, $p->c] = explode('|', (string) $sig);
                $tmp[] = $p;
            }
            $data->pcnt = count($tpSigs);
            $data->profiles = $tmp;
            $map[$vsig] = $data;
        }
        $rtn->Map = $map;
        return $rtn;
    }

    /**
     * Create a usable format for the variance map (this was all done in JS in legacy)
     *
     * @param object $mapData Output from getVarianceMapData()
     *
     * @return array Sorted/compiled array of map data
     */
    private function createVarianceMap($mapData)
    {
        $map = [];
        $i = 0;
        foreach ($mapData->Map as $vsig => $vobj) {
            $map[$i]['useTier'] = $mapData->UseTier;
            $map[$i]['profsClass'] = ($mapData->UseTier == 1) ? 'tpcfvTier' : 'tpcfvNoTier';
            if ($vsig == '0|0|0') {
                $map[$i]['title'] = $this->txt['fl_variance_srv_map_title_default'];
            } else {
                $title = $this->txt['fl_variance_srv_map_title'];
                if ($mapData->UseTier) {
                    if ($vobj->vdef->r == 0) {
                        $tier = $this->txt['select_multi_all'];
                    } elseif (!isset($mapData->TierMap[$vobj->vdef->r])) {
                        $this->removeVarianceMapClutter($vobj->vdef->r, $vobj->vdef->t, $vobj->vdef->c);
                        continue;
                    } else {
                        $tier = $mapData->TierMap[$vobj->vdef->r];
                    }
                    $title = str_replace('{tier}', $tier, (string) $title);
                } else {
                    $title = str_replace('Risk Tier: {tier}, ', '', (string) $title);
                }
                if ($vobj->vdef->t == 0) {
                    $tier = $this->txt['select_multi_all'];
                } elseif (!isset($mapData->TypeMap[$vobj->vdef->t])) {
                    $this->removeVarianceMapClutter($vobj->vdef->r, $vobj->vdef->t, $vobj->vdef->c);
                    continue;
                } else {
                    $type = $mapData->TypeMap[$vobj->vdef->t];
                }
                if ($vobj->vdef->c == 0) {
                    $tier = $this->txt['select_multi_all'];
                } elseif (!isset($mapData->CatMap[$vobj->vdef->c])) {
                    $this->removeVarianceMapClutter($vobj->vdef->r, $vobj->vdef->t, $vobj->vdef->c);
                    continue;
                } else {
                    $tier = $mapData->CatMap[$vobj->vdef->c];
                }
                $type = ($vobj->vdef->t == 0) ? $this->txt['select_multi_all'] : $mapData->TypeMap[$vobj->vdef->t];
                $cat  = ($vobj->vdef->c == 0) ? $this->txt['select_multi_all'] : $mapData->CatMap[$vobj->vdef->c];
                $map[$i]['title'] = str_replace(['{type}', '{cat}'], [$type, $cat], $title);
            }
            $map[$i]['numFacts'] = $vobj->total;
            if ($vobj->total == 0) {
                $map[$i]['noFacts'] = $this->txt['fl_variance_map_srv_no_factors_apply'];
                $map[$i]['facts'] = '';
            } else {
                $map[$i]['noFacts'] = '';
                foreach ($vobj->weights as $fid => $weight) {
                    $map[$i]['facts'][] = ['weight' => $weight, 'name' => $mapData->FactorNames[$fid]];
                }
            }
            $map[$i]['numProfs'] = $vobj->pcnt;
            if ($vobj->pcnt == 0) {
                if ($vsig == '0|0|0') {
                    $map[$i]['noProfs'] = $this->txt['fl_variance_map_srv_all_profiles_match'];
                } else {
                    $map[$i]['noProfs'] = $this->txt['fl_variance_map_srv_no_profiles_can_map'];
                }
                $map[$i]['profs'] = '';
            } else {
                $map[$i]['noProfs'] = '';
                foreach ($vobj->profiles as $pid => $p) {
                    $profs = [];
                    if ($mapData->UseTier) {
                        $profs['tier'] = ($p->r == 0) ? $this->txt['select_multi_none'] : $mapData->TierMap[$p->r];
                    }
                    $profs['type'] = ($p->t == 0) ? $this->txt['three_question_marks'] : $mapData->TypeMap[$p->t];
                    $profs['cat'] = ($p->c == 0) ? $this->txt['three_question_marks'] : $mapData->CatMap[$p->c];
                    $map[$i]['profs'][] = $profs;
                }
            }
            $i++;
        }
        return $map;
    }

    /**
     * Remove a defunct variance combination that is no longer applicable.
     *
     * From time to time, "clutter" may creep into a custom variance (tpComplyOverride) where the tier/type/cat
     * combination for which it was targeted no longer exists. For example a tier or type gets deleted, then the
     * variance no longer would apply as one (or more) of its targeted credentials no longer exist.
     * These don't hurt sitting in the DB (the conditions to check them would never show up) under normal usage,
     * but when trying to create a mapping of all variances, they can cause an issue. So when a client view
     * something like the complete variance map, we'll clean up any clutter that may be found.
     *
     * @param integer $tier risk tier ID
     * @param integer $type tp type ID
     * @param integer $cat  tp category ID
     *
     * @return void
     */
    private function removeVarianceMapClutter($tier, $type, $cat)
    {
        $tier = (int)$tier;
        $type = (int)$type;
        $cat  = (int)$cat;
        if ($tier == 0 && $type == 0 && $cat == 0) {
            return;
        }
        $varianceFilter = [
            'tier' => ['id' => $tier],
            'type' => ['id' => $type],
            'cat'  => ['id' => $cat],
        ];
        $msg = 'Risk Tier: `'. $tier .'`, 3P Type: `'. $type .'`, 3P Type Category: `'. $cat .'` -- '
            .'Risk Tier - 3P Type - 3P Type Category combination no longer exists, and was automatically removed.';
        $this->removeVarianceOverride($varianceFilter, 0, $msg);
    }

    /**
     * Evaluate and organize the default variance rows into usable data format.
     * To include calculating percentages, when/if new group starts, original
     * value data, etc. for the default variance display and comparison chart.
     *
     * @param array $rows Array of object rows to be calculated (DB fetchObjectRows, for example)
     *
     * @return array Array
     */
    private function organizeDefaultVariance($rows)
    {
        $activeRows  = [];
        $totalWeight = 0;
        $maxWeight   = 0;
        $groupName   = '';
        foreach ($rows as $key => $r) {
            if (!$r->hide) {
                $totalWeight += $r->weight;
                $activeRows[$key] = $r->weight;
                if ($r->weight > $maxWeight) {
                    $maxWeight = $r->weight;
                }
            } else {
                $rows[$key]->pct = 0;
                $rows[$key]->pctHS = 0;
            }
            if ($r->grpName && $r->grpName != $groupName) {
                $groupName = $r->grpName;
                $rows[$key]->newGroup = 1;
            } else {
                $rows[$key]->newGroup = 0;
            }
        }
        foreach ($activeRows as $key => $weight) {
            $rows[$key]->pct = $this->calcWeightPct($weight, $totalWeight);
            $rows[$key]->pctHS = $this->calcWeightPct($weight, $maxWeight);
        }
        return ['totalWeight' => $totalWeight, 'rows' => $rows];
    }

    /**
     * Evaluate and organize the variance rows into usable data format when showing overrides.
     * To include calculating percentages, original value data, number active, etc. for the
     * variance override form and comparison chart.
     *
     * @param array $rows Array of object rows to be calculated (DB fetchObjectRows, for example)
     *
     * @return array Array
     */
    private function organizeCustomVariance($rows)
    {
        $activeRows = [];
        $defWeight  = 0;
        $ovrWeight  = 0;
        $defActive  = 0;
        $ovrActive  = 0;
        $groupName  = '';
        $maxDefWeight = 0;
        $maxOvrWeight = 0;
        foreach ($rows as $key => $r) {
            $oWeight = ($r->weight == $r->oWeight || !$r->oWeight) ? $r->weight : $r->oWeight;
            if (!$r->hide && !$r->oHide) {
                $defWeight += $r->weight;
                $ovrWeight += $oWeight;
                $activeRows[$key] = ['def' => $r->weight, 'ovr' => $oWeight];
                if ($r->weight > $maxDefWeight) {
                    $maxDefWeight = $r->weight;
                }
                if ($oWeight > $maxOvrWeight) {
                    $maxOvrWeight = $oWeight;
                }
            } else {
                $rows[$key]->pct = 0;
                $rows[$key]->oPct = 0;
                $rows[$key]->pctHS = 0;
                $rows[$key]->oPctHS = 0;
            }
            if ($r->grpName && $r->grpName != $groupName) {
                $groupName = $r->grpName;
                $rows[$key]->newGroup = 1;
            } else {
                $rows[$key]->newGroup = 0;
            }
            if (!$r->overridden) {
                $r->oID = 0;
                $r->oWeight = $r->weight;
                $r->oHide = $r->hide;
            }
            if (!$r->hide) {
                $defActive++;
            }
            if (!$r->oHide) {
                $ovrActive++;
            }
            $rows[$key]->dataRow = (object) [
                'id' => $r->id,
                'oID' => $r->oID,
                'weight' => $r->weight,
                'oWeight' => $r->oWeight,
                'hide' => $r->hide,
                'oHide' => $r->oHide,
            ];
        }
        foreach ($activeRows as $key => $weight) {
            $rows[$key]->pct = $this->calcWeightPct($weight['def'], $defWeight);
            $rows[$key]->oPct = $this->calcWeightPct($weight['ovr'], $ovrWeight);
            $rows[$key]->pctHS = $this->calcWeightPct($weight['def'], $maxDefWeight);
            $rows[$key]->oPctHS = $this->calcWeightPct($weight['ovr'], $maxOvrWeight);
        }

        return [
            'totalWeight' => ['def' => $defWeight, 'ovr' => $ovrWeight],
            'totalActive'   => ['def' => $defActive, 'ovr' => $ovrActive],
            'rows' => $rows,
        ];
    }

    /**
     * Calculate percentage value based on weights
     *
     * @param integer $weight      Weight value of current variance record
     * @param integer $totalWeight Total weight of all variance records
     *
     * @return array Array with totalWeight and rows keys. rows is original array(object, object) format.
     */
    private function calcWeightPct($weight, $totalWeight)
    {
            $pct = ($weight/$totalWeight);
            $pct =  (($pct * 1000) / 10);
            $pct = round($pct, 2, PHP_ROUND_HALF_DOWN);
            $pct = round($pct, 1, PHP_ROUND_HALF_UP);
            return $pct;
    }

    /**
     * Instantiate compliance class, and initiate recalculation
     * of all 3P's Compliance Completed Percentage via a batch process.
     *
     * @return void
     */
    private function recalculateTps()
    {
        $comp = new \Models\TPM\Compliance($this->tenantID);
        $comp->initTpRecalc($this->userID);
    }

    /**
     * Set value for Risk Access
     *
     * @return void
     */
    private function hasRiskFeature()
    {
        $this->hasRisk = ($this->app->ftr->has(\Feature::TENANT_TPM_RISK) ? true : false);
    }
}
