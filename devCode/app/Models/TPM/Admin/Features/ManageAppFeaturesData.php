<?php
/**
 * Model: admin tool ManageAppFeatures
 */

namespace Models\TPM\Admin\Features;

use Models\Globals\Features;
use Models\Globals\Applications;
use Models\Globals\ApplicationFeatures;
use Models\Globals\FeaturesGroup;

/**
 * Provides data acces for admin tool ManageAppFeatures
 */
#[\AllowDynamicProperties]
class ManageAppFeaturesData
{

    /**
     * @var object Database connection
     */
    protected $DB = null;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->DB = \Xtra::app()->DB;
        $this->AjaxExceptionLogging = true;
    }

    /**
     * Disable feature and remove from all applications
     *
     * @param int $ftrID g_features.id
     *
     * @return simple array of featureID, error message
     */
    public function disableFeature($ftrID)
    {
        $ftrID = (int)$ftrID;
        $err = false;
        if ($ftrID > 0) {
            $ftrMdl = new Features();
            if (!$ftrMdl->selectValueByID($ftrID, 'id')) {
                $err = 'Invalid feature reference.';
            } else {
                // values to set with upsert
                if ($ftrMdl->updateByID($ftrID, ['active' => 0]) === false) {
                    $err = 'Failed to disable feature.';
                } else {
                    // Remove feature from all applications
                    $appFtrsMdl = new ApplicationFeatures();
                    $appFtrsMdl->delete(['featureID' => $ftrID]);
                }
            }
        }
        return [$ftrID, $err];
    }

    /**
     * Insert or update feature and application features
     *
     * @param int    $ftrID   g_features.id
     * @param int    $grpID   g_features.groupID
     * @param string $ftrName g_features.name
     * @param string $codeKey g_features.codeKey
     * @param string $ftrDesc g_features.description
     * @param string $inApps  CSV list of appID:0|1 values indicating which application feature is in
     *
     * @return simple array of featureID, error message
     */
    public function upsertFeature($ftrID, $grpID, $ftrName, $codeKey, $ftrDesc, $inApps)
    {
        $rtnID = $ftrID = (int)$ftrID;
        $grpID = (int)$grpID;
        $err   = [];
        // codeKey 50
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,48}[A-Z0-9]$/', $codeKey)) {
            $err[] = 'Code Key must be 3 to 50 characters consisting of A-Z, 0-9, and underscore. '
                . 'It must begin with A-Z and must not end with an underscore.';
        }
        // name 64
        if (!preg_match('#^[-a-z0-9 /:,_]{3,64}$#i', $ftrName)) {
            $err[] = 'Name must be 3 to 64 characters consisting of letters, '
               . ' numbers, space, dash, underscore, slash, colon, and comma.';
        }
        // description 255
        $descLen = strlen($ftrDesc);
        if ($descLen < 3 || $descLen > 255) {
            $err[] = 'Description must be at least 3 characters and no more than 255.';
        }
        // Group ID must be >= 0
        if ($grpID < 0) {
            $err[] = 'Invalid group identifier.';
        }
        // inApps csv of appID/checked(1|0)
        if (!preg_match('/^\d:[10](,\d:[10])*$/', $inApps)) {
            $err[] = 'Invalid format for application indicators.';
        }
        if (empty($err)) {
            $ftrMdl = new Features();
            // values to set with upsert
            $setValues = [
                'codeKey' => $codeKey,
                'name' => $ftrName,
                'description' => $ftrDesc,
                'groupID' => $grpID,
            ];
            // validate unique values
            if ($ftrID > 0) {
                if (!$ftrMdl->selectValueByID($ftrID, 'id')) {
                    $err[] = 'Invalid feature reference.';
                }
                // name or codekey used in another feature?
                $tbl = $this->DB->globalDB . '.g_features';
                $sql = "SELECT id FROM $tbl WHERE codeKey = :codeKey && id <> :ftrID LIMIT 1";
                if ($this->DB->fetchValue($sql, [':codeKey' => $codeKey, ':ftrID' => $ftrID])) {
                    $err[] = 'Code key already in use.';
                }
                $sql = "SELECT id FROM $tbl WHERE name = :name AND id <> :ftrID LIMIT 1";
                if ($this->DB->fetchValue($sql, [':name' => $ftrName, ':ftrID' => $ftrID])) {
                    $err[] = 'Name already in use.';
                }
                if (empty($err)) {
                    // Do the update
                    if ($ftrMdl->updateByID($ftrID, $setValues) === false) {
                        $err[] = 'Feature update failed.';
                    }
                }
            } elseif ($ftrID == 0) {
                if (($row = $ftrMdl->selectOne(['id'], ['codeKey' => $codeKey]))) {
                    $err[] = 'Code key already in use.';
                }
                if (($row = $ftrMdl->selectOne(['id'], ['name' => $ftrName]))) {
                    $err[] = 'Name already in use.';
                }
                if (empty($err)) {
                    // Do the insert
                    $rtnID = (int)$ftrMdl->insert($setValues);
                    if (!$rtnID) {
                        $err[] = 'Addition of new feature failed.';
                    }
                }
            } else {
                $err[] = 'Invalid feature reference.';
            }
            unset($ftrMdl);

            // Update application feature lists
            if (empty($err) && $rtnID) {
                $appsMdl = new Applications();
                $appFtrsMdl = new ApplicationFeatures();
                $appSet = explode(',', $inApps);
                $apps = $appsMdl->selectMultiple(['id']);
                foreach ($apps as $app) {
                    $appID = $app['id'];
                    if (in_array('' . $appID . ':1', $appSet)) {
                        // set if not present
                        if (!$appFtrsMdl->selectValue('featureID', ['featureID' => $rtnID, 'appID' => $appID])) {
                            $appFtrsMdl->insert(['appID' => $appID, 'featureID' => $rtnID]);
                        }
                    } else {
                        // clear
                        $appFtrsMdl->delete(['appID' => $appID, 'featureID' => $rtnID]);
                    }
                }
            }
        }
        return [$rtnID, $err];
    }

    /**
     * Get feature detail for configuration
     *
     * @param int $featureID g_feaatures.id
     *
     * @return array mixed values needed for configuration dialog
     */
    public function getFeatureDetail($featureID)
    {
        $ftrID = (int)$featureID;
        $rtn = [
            'rec' => null,
            'apps' => [],
        ];
        if ($ftrID <= 0) {
            return $rtn;
        }
        $ftrMdl = new Features();
        $cols = ['codeKey', 'name', 'description', 'groupID'];
        $rec = $ftrMdl->selectByID($ftrID, $cols);
        if (!empty($rec)) {
            $rtn['rec'] = $rec;
            $inApps = [];
            $appFtrMdl = new ApplicationFeatures();
            $apps = $appFtrMdl->selectMultiple(['appID'], ['featureID' => $ftrID]);
            foreach ($apps as $app) {
                $inApps[] = $app['appID'];
            }
            $rtn['apps'] = $inApps;
        }
        return $rtn;
    }

    /**
     * Get list of features
     *
     * @param int $appID g_applications.id
     * @param int $grpID g_featuresGroup.id
     *
     * @return array of assoc rows for features for DataTable
     */
    public function getFeatureList($appID, $grpID)
    {
        // Filter by application and group selections
        $appID = (int)$appID;
        $grpID = (int)$grpID;

        $ftrTbl      = $this->DB->globalDB . '.g_features';
        $appTbl      = $this->DB->globalDB . '.g_applications';
        $ftrAppTbl   = $this->DB->globalDB . '.g_applicationFeatures';
        $grpTbl      = $this->DB->globalDB . '.g_featuresGroup';
        $gateTbl     = $this->DB->globalDB . '.g_gate';
        $settingsTbl = $this->DB->globalDB . '.g_settings';

        $sql = "SELECT f.id, f.codeKey, f.name, grp.name AS 'grpName',\n"
            . "gate.gate AS 'gateName', settings.name AS 'cfgName'\n"
            . "FROM $ftrTbl AS f\n"
            . "LEFT JOIN $grpTbl AS grp ON grp.id = f.groupID\n"
            . "LEFT JOIN $gateTbl AS gate ON gate.id = f.legacyGateID\n"
            . "LEFT JOIN $settingsTbl AS settings ON settings.featureID = f.id\n";
        $params = [];
        $where  = "WHERE f.active <> 0\n";

        if ($appID == 0) {
            // Features assigned to no applications
            $sql .= "LEFT JOIN $ftrAppTbl AS af ON af.featureID = f.id\n";
            $where .= "AND af.featureID IS NULL\n";
        } elseif ($appID > 0) {
            // Features assigned to a specific application
            $sql .= "LEFT JOIN $ftrAppTbl AS af ON af.featureID = f.id\n";
            $where .= "AND af.appID = :appID\n";
            $params[':appID'] = $appID;
        }

        // Also filter on group
        if ($grpID > 0) {
            $where .= "AND f.groupID = :grpID\n";
            $params[':grpID'] = $grpID;
        }
        $sql .= $where . "ORDER BY f.id";
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Get list id/name list of applications
     *
     * @return array rows of id/name elements
     */
    public function getAppsList()
    {
        $apps = new Applications();
        return $apps->selectMultiple(['id', 'name'], ['active', 1], 'ORDER BY name');
    }

    /**
     * Get list id/name list of features groups
     *
     * @return array rows of id/name elements
     */
    public function getGroupList()
    {
        $grps = new FeaturesGroup();
        return $grps->selectMultiple(['id', 'name'], null, 'ORDER BY name');
    }

    /**
     * Insert or update applications group
     *
     * @param integer $grpID   g_featuresGroup.id
     * @param string  $grpName g_featuresGroup.name
     *
     * @return array simple array of rtnID and error message elements
     */
    public function upsertGroup($grpID, $grpName)
    {
        $err = false;
        $rtnID = 0;
        $grps = new FeaturesGroup();
        if (!$grps->validName($grpName)) {
            $err = $grps->nameSpec();
        } else {
            if ($grpID == -1) {
                if ($grps->selectOne([], ['name' => $grpName])) {
                    $err = 'Group name already exists.';
                } elseif (!($rtnID = $grps->insert(['name' => $grpName]))) {
                    $err = 'New group creation failed.';
                }
            } elseif ($grpID > 0) {
                if (!($rec = $grps->selectByID($grpID))) {
                    $err = 'Invalid identifier';
                } elseif ($rec = $grps->selectOne([], ['name' => $grpName]) && $rec['id'] != $grpID) {
                    $err = 'Group name already exists.';
                } elseif ($grps->updateByID($grpID, ['name' => $grpName]) === false) {
                    $err = 'Group update failed.';
                } else {
                    $rtnID = $grpID;
                }
            } else {
                $err = 'Invalid identifier';
            }
        }
        return [$rtnID, $err];
    }

    /**
     * Delete applications group. Removes references in g_features
     *
     * @param integer $grpID   g_featuresGroup.id
     * @param string  $grpName (ignored, but common call adds it)
     *
     * @return simple array of rtnID and error message elements
     */
    public function deleteGroup($grpID, $grpName)
    {
        $dummy = $grpName; // satisfy phpcs
        $err = false;
        $rtnID = 0;
        $grps = new FeaturesGroup();
        if ($grpID <= 0
            || !($rec = $grps->selectByID($grpID, ['id']))
            || $rec['id'] != $grpID
        ) {
            $err = 'Group does not exist.';
        } elseif (!$grps->deleteByID($grpID)) {
            $err = 'Group deletion failed.';
        } else {
            $rtnID = $grpID;
            // clear deleted groupID from features
            $ftrMdl = new Features();
            $ftrMdl->update(['groupID' => 0], ['groupID' => $grpID]);
        }
        return [$rtnID, $err];
    }
}
