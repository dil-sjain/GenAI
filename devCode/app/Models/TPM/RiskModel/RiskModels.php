<?php
/**
 * RiskModel records in general
 *
 * @see Models/ThirdPartyManagement/RiskModel for a specific risk model
 */

namespace Models\TPM\RiskModel;

use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpProfile;
use Models\TPM\RiskModel\RiskModelRole;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\Globals\Features\TenantFeatures;

/**
 * Query risk models and related records
 *
 * @keywords risk model, risk
 */
#[\AllowDynamicProperties]
class RiskModels extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name
     */
    protected $tbl = 'riskModel';

    /**
     * @var boolean tabled is in a client database
     */
    protected $tableInClientDB = true;

    /**
     * @var array key/value tpType lookup
     */
    protected $tpTypes = [];

    /**
     * @var array key/value risk model roles lookup
     */
    protected $riskModelRoles = [];

    /**
     * @var array key/value tpCategories lookup
     */
    protected $tpCategories = [];

    /**
     * Load tpTypes lookup
     *
     * @return void
     */
    private function loadTpTypes()
    {
        if (!empty($this->tpTypes)) {
            return;
        }
        $typeMdl = new TpType($this->clientID);
        $this->tpTypes = $typeMdl->selectKeyValues(['id', 'name'], [], 'ORDER BY name');
    }

    /**
     * Load tpCategories lookup
     *
     * @return void
     */
    private function loadTpCategories()
    {
        if (!empty($this->tpCategories)) {
            return;
        }
        $catMdl = new TpTypeCategory($this->clientID);
        $this->tpCategories = $catMdl->selectKeyValues(['id', 'name'], [], 'ORDER BY name');
    }

    /**
     * Load active risk model roles
     *
     * @param int $active optional, get all if not 1
     *
     * @return void
     */
    private function loadRiskModelRoles(int $active = 0)
    {
        try {
            $conditionArray = [];
            if ($active == 1) {
                $conditionArray = ['active' => 1];
            }
            $roleMdl = new RiskModelRole($this->clientID);
            $this->riskModelRoles = $roleMdl->selectKeyValues(['id', 'name'], $conditionArray, 'ORDER BY orderNum');
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    /**
     * Count risk models with setup status
     *
     * @return int number of records with setup status
     */
    public function countSetup()
    {
        return $this->selectValue('COUNT(*)', ['status' => 'setup']);
    }

    /**
     * Count risk models with complete status
     *
     * @return int number of records with complete status
     */
    public function countPublished()
    {
        return $this->selectValue('COUNT(*)', ['status' => ['complete','disabled']]);
    }

    /**
     * Get risk model list for display on intro page
     *
     * @param mixed $status riskModel.status ('setup', 'complete', 'disabled')
     *
     * @return array riskModel rows
     */
    private function getRiskModelList($status)
    {
        if (empty($status)) {
            return [];
        }
        $models = [];
        $this->loadTpTypes();

        // Calling function to get roles
        $this->loadRiskModelRoles();

        // Calling function to get categories
        $this->loadTpCategories();
        $cols = ['id', 'name', 'tpType AS type', 'categories', 'riskModelRole', 'status', 'updated AS tstamp'];
        $rows = $this->selectMultiple($cols, ['status' => $status], 'ORDER BY updated DESC');
        // post process is ok for small result set
        foreach ($rows as $row) {
            $row['type'] = \Xtra::arrayGet($this->tpTypes, $row['type'], '');

            // Getting categories name based on Id
            $catNames = [];
            $categories = explode(',', $row['categories']);
            foreach ($categories as $key => $categoryId) {
                $catName = \Xtra::arrayGet($this->tpCategories, $categoryId);
                if (!empty($catName)) {
                    $catNames[] = $catName;
                }
            }
            $row['categoriesNames'] = implode(',', $catNames);

            // Getting Role Name
            $row['roleName'] = \Xtra::arrayGet($this->riskModelRoles, $row['riskModelRole'], '');
            $models[] = $row;
        }
        return $models;
    }

    /**
     * Get list of risk models with status of setup
     *
     * @return array risk model elements
     */
    public function getSetupList()
    {
        return $this->getRiskModelList(['setup']);
    }

    /**
     * Get list of risk models with status of completed (published)
     *
     * @return array risk model elements
     */
    public function getPublishedList()
    {
        return $this->getRiskModelList(['complete','disabled']);
    }

    /**
     * Get a map of models assigned to profiles by type and category
     *
     * @return mixed mulitidiminsional array of components to constgruct published risk model map
     */
    public function publishedMap()
    {
        $this->loadTpTypes();
        $this->loadRiskModelRoles();
        $ftr = new TenantFeatures($this->clientID);
        $hasMRM = $ftr->tenantHasFeature(
            \Feature::MULTIPLE_RISK_MODEL,
            \Feature::APP_TPM
        );
        $catMdl  = new TpTypeCategory($this->clientID);
        $mapMdl  = new RiskModelMap($this->clientID);
        $proMdl  = new TpProfile($this->clientID);
        $roleMdl = new RiskModelRole($this->clientID);
        $roleOrder = $roleMdl->selectKeyValues(['id', 'orderNum'], [], 'ORDER BY orderNum');
        $models  = $this->selectKeyValues(['id', 'name'], ['status' => 'complete']);
        $types   = $this->tpTypes;
        $roles   = $this->riskModelRoles;
        $cats    = [];
        foreach ($types as $tid => $name) {
            // Getting categories name for all type
            $cats[$tid] = $catMdl->selectKeyValues(['id', 'name'], ['tpType' => $tid], 'ORDER BY name');
        }
        $map     = [];
        $mapRows = $mapMdl->selectMultiple(
            ['tpType', 'tpCategory', 'riskModel', 'riskModelRole'],
            [],
            'ORDER BY tpType, tpCategory'
        );
        $tid = 0;
        foreach ($mapRows as $m) {
            if ($m['tpType'] == 0) {
                continue; // data integrity issue
            }
            // Comparing with last type id, if it does not match creating array() for new type.
            if ($m['tpType'] != $tid) {
                $tid = $m['tpType'];
                $map[$tid] = [];
            }
            $map[$tid][$m['tpCategory']][] = array('model' => $m['riskModel'],
                                                    'role' => $m['riskModelRole'],
                                                    'orderNum' => $roleOrder[$m['riskModelRole']]);
        }
        foreach ($map as $tid => $catMap) {
            foreach ($catMap as $cid => $rows) {
                usort($map[$tid][$cid], function ($a, $b) {
                    return $a['orderNum'] - $b['orderNum'];
                });
            }
        }
        $profiles = [];
        $proRows = $proMdl->selectMultiple(
            ['tpType', 'tpTypeCategory', 'COUNT(*) AS profiles'],
            ['status' => 'active'],
            'GROUP BY tpType, tpTypeCategory ORDER BY tpType, tpTypeCategory'
        );
        $tid = 0;
        foreach ($proRows as $p) {
            if ($p['tpType'] == 0) {
                continue; // data integrity issue
            }
            if ($p['tpType'] != $tid) {
                $tid = $p['tpType'];
                $profiles[$tid] = [];
            }
            $profiles[$tid][$p['tpTypeCategory']] = $p['profiles'];
        }
        // Build a human readable map
        $tid = 0;
        $pubMap = [];
        foreach ($types as $tid => $tname) {
            $pubMap[] = [
                'type' => 't',
                'name' => $tname,
                'id'   => $tid,
            ];
            foreach ($cats[$tid] as $cid => $cname) {
                $cnt = 0;
                if (!empty($profiles[$tid][$cid])) {
                    $cnt = $profiles[$tid][$cid];
                }
                $modelCount = isset($map[$tid][$cid]) ? count($map[$tid][$cid]) : 1;
                $mapArray = [
                    'type' => 'c',
                    'name' => $cname,
                    'model' => '',
                    'role' => '',
                    'cnt' => number_format($cnt, 0),
                    'id' => $cid,
                    'modelCount' => $modelCount,
                ];
                if (isset($map[$tid][$cid])) {
                    $i = 0;
                    foreach ($map[$tid][$cid] as $key => $row) {
                        $mname = '';
                        if (!empty($row['model'])) {
                            $mname = $models[$row['model']] ?? '';
                        }
                        $roleName = '';
                        if (!empty($row['role']) && !empty($roles)) {
                            $roleName = $roles[$row['role']] ?? '';
                        }
                        if ($i > 0) {
                            $mapArray['type'] = 'm';
                        }
                        if ($key == 0 && $hasMRM) {
                            $mname = $mname . ' [Primary]';
                        }
                        $mapArray['model'] = $mname;
                        $mapArray['role'] = $roleName;
                        $mapArray['orderNum'] = $row['orderNum'];
                        $pubMap[] = $mapArray;
                        $i++;
                    }
                } else {
                    $pubMap[] = $mapArray;
                }
            }
        }
        return $pubMap;
    }

    /**
     * Get list of tpTypes
     *
     * @return array - elements are id name associative arrays
     */
    public function getTpTypes()
    {
        $this->loadTpTypes();
        $types = [];
        foreach ($this->tpTypes as $id => $name) {
            $types[] = ['id' => $id, 'name' => $name];
        }
        return $types;
    }

    /**
     * Get list of active risk model roles
     *
     * @return array - elements are id name associative arrays
     */
    public function getRiskModelRoles(): array
    {
        $this->loadRiskModelRoles(1);
        $roles = [];
        if (!empty($this->riskModelRoles)) {
            foreach ($this->riskModelRoles as $id => $name) {
                $roles[] = ['id' => $id, 'name' => $name];
            }
        }
        return $roles;
    }

    /**
     * Get count of roles for the same tpType and category combination
     *
     * @param integer $tpType        riskModel.tpType
     * @param string  $categories    riskModel.categories
     * @param integer $riskModelRole riskModel.riskModelRole
     *
     * @return integer count of extra roles
     */
    public function getRoleCount(int $tpType, string $categories, int $riskModelRole): int
    {
        $cats = explode(',', $categories);
        $extraRole = 0;
        foreach ($cats as $cat) {
            $sql = "SELECT COUNT(riskModelRole) FROM riskModelMap
                    WHERE tpType = :tpType
                        AND tpCategory = :tpCategory
                        AND riskModelRole <> :riskModelRole";
            $params = [
                ':tpType' => $tpType,
                ':tpCategory' => $cat,
                ':riskModelRole' => $riskModelRole
            ];
            try {
                $result = $this->DB->fetchValue($sql, $params);
                if ($result >= getenv('riskModelRoleCount')) {
                    $extraRole = $result;
                    break;
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }
        return $extraRole;
    }

    /**
     * Get one risk model record and unserialize weights
     *
     * @param integer $id riskModel.id
     *
     * @return mixed false or requested riskModel record with weights unserialized
     */
    public function getRecord($id)
    {
        if (empty($id)) {
            return false;
        }
        if ($modelRec = $this->selectByID($id)) {
            if (!empty($modelRec['weights'])) {
                $modelRec['weights'] = unserialize($modelRec['weights']);
            } else {
                $modelRec['weights'] = [];
            }
        }
        return $modelRec;
    }

    /**
     * Get existing risk model record for the same tpType, categories, role and status
     *
     * @param integer $tpType     riskModel.tpType
     * @param string  $categories riskModel.categories
     * @param integer $role       riskModel.riskModelRole
     * @param string  $status     riskModel.status
     *
     * @return mixed false or riskModel.id
     */
    public function getExistingRoleModel($tpType, $categories, $role, $status)
    {
        return $this->selectValue('id', ['tpType' => $tpType, 'categories' => $categories,
            'riskModelRole' => $role, 'status' => $status]);
    }

    /**
     * Disable an existing risk model
     *
     * @param integer $id riskModel.id
     *
     * @return void
     */
    public function disableExistingRiskModel($id)
    {
        $this->update(['status' => 'disabled'], ['id' => $id]);
    }

    /**
     * Get primary risk model of a third party profile
     *
     * @param array $tpRec third party profile
     *
     * @return mixed riskModel.id or 0
     */
    public function getPrimaryRiskModel($tpRec)
    {
        $sql = "SELECT rm.id FROM riskModel AS rm
                INNER JOIN riskModelRole AS rmr ON rmr.id = rm.riskModelRole
                WHERE rm.tpType = :tpType AND FIND_IN_SET(:category, rm.categories)
                AND rm.status = 'complete'
                ORDER BY rmr.orderNum ASC, rm.id DESC LIMIT 1";
        $params = [
            ':tpType' => $tpRec['tpType'],
            ':category' => $tpRec['tpTypeCategory']
        ];
        $primaryModel = 0;
        try {
            $primaryModel = $this->DB->fetchValue($sql, $params);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
        return $primaryModel;
    }
}
