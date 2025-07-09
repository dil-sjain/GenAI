<?php
/**
 * Dashboard Model
 *
 * @keywords dashboard, widget
 */

namespace Models\TPM\Dashboard;

use Controllers\TPM\Dashboard\Subs\DashboardSubCtrlFactory as DashboardFactory;
use Controllers\TPM\Dashboard\Subs\DashWidgetBase;
use Models\TPM\Dashboard\Subs\DataRibbon;

/**
 * Class DashboardData.
 *
 * @keywords tpm, dashboard, model, settings
 */
#[\AllowDynamicProperties]
class DashboardData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var DB instance
     */
    private $DB = null;

    /**
     * @var string  authDB User's db, via "client"
     */
    private $authDB = null;

    /**
     * @var string  globalDB Name of global db
     */
    private $globalDB = null;

    /**
     * @var integer The current roleID
     */
    protected $roleID = 0;

    /**
     * @var integer The current userID
     */
    protected $userID = 0;

    /**
     * @var int Current tenant ID
     */
    protected $tenantID = 0;

    /**
     * @var string $tbl Tables holding user specific data for widgets
     */
    protected $tbl = "g_dashboardWidgets";

    /**
     * @var string $tblDefault Table holding default values to use for widgets
     */
    protected $tblDefault = 'g_dashboardWidgetsAvailable';

    /**
     * @var string Base path to js files
     */
    private $jsPath = '/assets/js/TPM/Dashboard/Subs/';

    /**
     * @var string Base path to js files
     */
    private $cssPath = '/assets/css/TPM/Dashboard/Subs/';

    /**
     * @var string Base path to js files
     */
    private $jsTplPath = '/assets/js/views/TPM/dashboard/subs/';

    /**
     * @var string Name of data ribbon tile
     */
    private $dataRibbonNm = 'DataRibbon';

    /**
     * @var array Keys + txt that need translation
     */
    private $trans = [
        'onlyLoggedInUser' => 'This function works only for the currently logged in user.',
        'invWidgetClassName' => '1 or more invalid widget class names',
        'recordNotFound' => "record not found",

    ];

    /**
     * DashboardData constructor.
     *
     * @param int $roleID Current roleID
     * @param int $userID Current userID
     */
    public function __construct($roleID, $userID)
    {
        \Xtra::requireInt($roleID);
        \Xtra::requireInt($userID);
        $app = \Xtra::app();
        $this->app = $app;
        $this->log = $app->log;
        $this->DB = $app->DB;
        $this->authDB = $this->DB->authDB;
        $this->authUserID = (int)$userID;
        $this->tenantID = $this->app->ftr->tenant;
        $this->clientDB = $this->DB->getClientDB($this->tenantID);
        $this->globalDB = $this->DB->globalDB;
        $this->roleID   = intval($roleID);
        $this->userID   = intval($userID);
    }

    /**
     * Return all widgets for a specific user
     *
     * @param boolean $activeOnly Whether to return only active widgets
     *
     * @return mixed
     */
    private function getDashboardWidgetsData($activeOnly = true)
    {
        // pdo data bindings
        $bindData = [
            ':roleID' => $this->roleID,
            ':userID' => $this->userID
        ];

        $activeFilter = '';
        if ($activeOnly) {
            $activeFilter = "AND (w.active = 1 AND wa.enabled = 1) \n";
        }

        $sql = "SELECT wa.ctrlClass \n"
            . "       ,wa.name \n"
            . "       ,w.state \n"
            . "       ,w.active \n"
            . "       ,w.sequence \n"
            . "       ,w.id \n"
            . "   FROM $this->globalDB.$this->tbl AS w \n"
            . "       JOIN $this->globalDB.$this->tblDefault AS wa ON w.widgetID = wa.id \n"
            . "  WHERE w.userID = :userID \n"
            . "    AND w.roleID = :roleID \n"
            . $activeFilter
            . "  ORDER BY w.sequence asc "
        ;

        $widgets = $this->DB->fetchObjectRows($sql, $bindData);

        return $widgets;
    }

    /**
     * Create default widget data for current user in their current role
     *
     * @return boolean if successfully created initial data
     */
    private function initDefaultWidgetData()
    {
        // Get all active widgets and their default settings
        $sql = "SELECT * FROM {$this->globalDB}.{$this->tblDefault} \n"
            . "WHERE `enabled` = 1 \n"
            . "ORDER BY `defaultSequence`;";
        $available = $this->DB->fetchAssocRows($sql);

        $params = [
            ':roleID' => $this->roleID,
            ':userID' => $this->userID,
            ':state'  => '{"expanded":true}',
        ];
        $sql = "INSERT INTO {$this->globalDB}.{$this->tbl} \n"
            . "(`roleID`, `userID`, `widgetID`, `state`, `active`, `sequence`) \n"
            . "VALUES (:roleID, :userID, :widgetID, :state, :active, :sequence);";

        foreach ($available as $widgets) {
            $params[':widgetID']  = $widgets['id'];
            $params[':state']     = $widgets['defaultState'];
            $params[':sequence']  = $widgets['defaultSequence'];
            $params[':active']    = $widgets['active'];

            // Catch result here for debugging
            $result = $this->DB->query($sql, $params);
        }
        if (!empty($result)) {
            return true;
        }
        return false;
    }

    /**
     * get files for a specific widgets
     *
     * @param string $ctrlClass contains ctrlClass property corresponding to dashboardWidgets db field
     *
     * @throws \Exception
     * @return array
     *
     * @return array|bool
     */
    private function getDashboardWidgetFilesData($ctrlClass)
    {
        /**
         * @var DashWidgetBase $widgetInstance
         */
        $widgetInstance = (new DashboardFactory($ctrlClass, $this->tenantID))->getBuiltClass();

        if ($widgetInstance->noObstacles()) {
            $widgetFiles = $widgetInstance->getFilesList();

            if (!$widgetFiles) {// data expected, but not found . . .
                throw new \Exception("$ctrlClass->ctrlClass did not have any associated files.");
            }

            $this->concatFileLocation($widgetFiles, $ctrlClass);

            return $widgetFiles;
        } else {
            // There were obstacles, return false.
            return false;
        }
    }

    /**
     * Concat files per how rxLoader .js method prefer.
     *
     * @param array  &$widgetFiles in/out
     * @param string $ctrlClass    contains the ctrlClass
     *
     * @return void
     */
    private function concatFileLocation(&$widgetFiles, $ctrlClass)
    {
        $denotesDashboardWidget = "dash";
        $rxLoader_loadFiles_convention = "||";

        foreach ($widgetFiles as $i => $file) {
            $tmpFile = $file;

            $parts = explode(".", (string) $tmpFile);
            if ($parts[1] === 'js') {
                $tmpFile = $this->jsPath . $tmpFile;
            } elseif ($parts[1] === 'css') {
                $tmpFile = $this->cssPath . $tmpFile;
            } elseif ($parts[1] === 'html') {
                if (strtolower($parts[0]) === strtolower($ctrlClass)) {
                    $tplName = $denotesDashboardWidget . $ctrlClass;
                } else {
                    $tplName = $parts[0];
                }

                $tmpFile = $this->jsTplPath
                    . $parts[0]
                    . $rxLoader_loadFiles_convention
                    . $tplName
                    . "." . $parts[1];
            }

            $widgetFiles[$i] = $tmpFile;
        }
    }

    /**
     * Get all widgets and files for a specific user (active and inactive widgets)
     *
     * @throws \Exception
     * @return mixed
     */
    public function getUserWidgets()
    {
        $widgets = $this->getDashboardWidgetsData(false);
        return $widgets;
    }

    /**
     * Get all widget names for logged in user (gets active and inactive widgets)
     *
     * @throws \Exception
     * @return mixed
     */
    public function getUserClasses()
    {
        return $this->mapWidgetNames($this->getDashboardWidgetsData(false));
    }

    /**
     * Get a simple array of widget names from the array data about widgets
     *
     * @param array $widgets Array / widget data
     *
     * @return array
     */
    private function mapWidgetNames($widgets)
    {
        if (gettype($widgets) === 'object') {
            $widgets = [$widgets];
        }

        return array_map(
            function ($elem) {
                $arr = (array)$elem;
                return $arr['ctrlClass'];
            },
            $widgets
        );
    }

    /**
     * Get all widgets and data for a specific user
     *
     * @throws \Exception
     * @return mixed
     */
    public function allWidgetData()
    {
        $widgets = $this->fetchWidgets('all', true);

        $widgetNames = $this->mapWidgetNames($widgets);

        $files = $this->getWidgetFiles($widgetNames);

        $widgets = $this->filesIntoWidgetsArray($files, $widgets);

        return $widgets;
    }

    /**
     * Place the files array into the widgets array as expected by
     * the client in data structure
     *
     * @param array $files   Array of file data
     * @param array $widgets Array of widget data
     *
     * @return array
     */
    private function filesIntoWidgetsArray($files, $widgets)
    {
        $packedWidgets = [];
        if (is_object($widgets)) {
            $widgets = [$widgets];
        }

        foreach ($widgets as $w) {
            $ctrlClass = $w->ctrlClass;
            if (!empty($files[$ctrlClass])) {
                $widget = $w;
                $widget->files = $files[$ctrlClass];

                $packedWidgets[] = $widget;
            }
        }

        return $packedWidgets;
    }

    /**
     * Get Widget files data
     *
     * @param array $widgets Widgets for which to retrieve file data
     *
     * @return array
     */
    private function getWidgetFiles($widgets)
    {
        $ret = [];
        foreach ($widgets as $ctrlClass) {
            $files = $this->getDashboardWidgetFilesData($ctrlClass);
            $ret[$ctrlClass] = $files;
        }
        return $ret;
    }

    /**
     * Validates widget names and that each exists for the logged in user.
     *
     * @param array $nms Supposed widget names to validate
     *
     * @return bool
     */
    private function validateWidgetNamesForUser($nms)
    {
        $intersect = array_intersect(
            $this->getUserClasses(),
            $nms
        );

        if (is_array($intersect) && is_array($nms) &&  count($intersect) == count($nms)) {
            return true;
        }
        return false;
    }

    /**
     * Get widget data, active widgets only
     *
     * @param array $widgetClassNames Array of widget class names (ie; DataRibbon)
     *
     * @return array
     */
    public function getEnabledWidgets($widgetClassNames)
    {
        $widgets = $this->fetchWidgets($widgetClassNames, true);
        if (!is_array($widgets)) {
            $widgets = [$widgets];
        }

        $widgetClassNames = $this->mapWidgetNames($widgets);
        $files = $this->getWidgetFiles($widgetClassNames);
        if ($files === false) {
            return false;
        }
        $widgetsWithFiles = $this->filesIntoWidgetsArray($files, $widgets);
        return $widgetsWithFiles;
    }

    /**
     * Get widgets by ctrlClass
     *
     * @param string $widgetClassNames ctrlClass attributes of widgets
     *
     * @throws \Exception
     * @return mixed
     *
     */
    public function getWidgets($widgetClassNames)
    {
        $widgets = $this->fetchWidgets($widgetClassNames, false);

        if (is_object($widgets)) {
            $widgets = [$widgets];
        }

        $widgetClassNames = $this->mapWidgetNames($widgets);
        $files = $this->getWidgetFiles($widgetClassNames);

        $widgetsWithFiles = $this->filesIntoWidgetsArray($files, $widgets);

        return $widgetsWithFiles;
    }

    /**
     * Fetch widget metadata (needed for fetching) from db.
     *
     * @param array $widgetClassNames Names of widgets to fetch
     * @param bool  $reqEnabled       Whether to require enabled
     *
     * @return array
     */
    private function fetchWidgets($widgetClassNames, $reqEnabled = false)
    {
        $widgetNameFilter = "    AND wa.ctrlClass IN (:wcn)";
        $bindData         = [
            ':roleID' => $this->roleID,
            ':userID' => $this->userID
        ];

        if ($widgetClassNames ==='all') {
            $widgetNameFilter = "";
        } else {
            if (is_string($widgetClassNames)) {
                $widgetClassNames = [$widgetClassNames];
            }

            if (!$this->validateWidgetNamesForUser($widgetClassNames)) {
                return ['error' => $this->trans['invWidgetClassName']];
            }

            if (is_array($widgetClassNames) && count($widgetClassNames) > 1) {
                $bindData[':wcn'] = implode("','", $widgetClassNames);
                $bindData[':wcn'] = "'{$bindData[':wcn']}'";
            } else {
                $bindData[':wcn'] = $widgetClassNames[0];
            }
        }

        $sql = "SELECT wa.ctrlClass \n"
            . "       ,wa.name \n"
            . "       ,IF(wa.enabled = 1, IF(w.active, w.active, 0), 0) AS active \n" // Top level can override widget
            . "       ,w.sequence \n"
            . "       ,w.id \n"
            . "       ,w.state \n"
            . "   FROM $this->globalDB.$this->tbl AS w \n"
            . "       JOIN $this->globalDB.$this->tblDefault AS wa ON w.widgetID = wa.id \n"
            . "  WHERE w.userID   = :userID \n"
            . "    AND w.roleID   = :roleID \n"
            . "    AND wa.enabled = 1 \n"
            . $widgetNameFilter;

        $widgets = $this->DB->fetchObjectRows($sql, $bindData);

        if (empty($widgets)) {
            // No widget data found, need to initialize default widget data for user in this role
            if ($this->initDefaultWidgetData()) { // prevent possible inf loop
                // Call fetchWidgets again to retrieve newly created defaults
                $widgets = $this->fetchWidgets($widgetClassNames, $reqEnabled);
            } else {
                // failure to create initial data
                $dummy = 0; // silence phpcs
            }
        }

        if (is_array($widgets) && count($widgets) == 1 && is_object($widgets[0])) {
            $widgets = \Xtra::head($widgets);
        } else {
            // If we are limiting to enabled, check retrieved widget settings and remove inactive.
            if ($reqEnabled) {
                foreach ($widgets as $key => $widget) {
                    if ($widget->active == 0) {
                        unset($widgets[$key]);
                    }
                }
            }
        }

        return $widgets;
    }

    /**
     * Get widgets by ctrlClass
     *
     * @param string $widgetClassName ctrlClass
     *
     * @throws \Exception
     * @return mixed
     *
     */
    public function getWidgetByClassName($widgetClassName)
    {
        return $this->getWidgets([$widgetClassName])[0];
    }

    /**
     * Get widgets by seq
     *
     * @param int $widgetSequence seq of widget
     *
     * @return mixed
     */
    public function getWidgetBySequence($widgetSequence)
    {
        $widgetSequence = intval($widgetSequence);

        // pdo data bindings
        $bindData = [
            ':roleID'  => $this->roleID,
            ':userID'  => $this->userID,
            ':widgetSequence'  => $widgetSequence,
        ];

        $sql = "SELECT wa.ctrlClass \n"
            . "       ,wa.name \n"
            . "       ,w.active \n"
            . "       ,w.sequence \n"
            . "       ,w.id \n"
            . "   FROM $this->globalDB.$this->tbl AS w \n"
            . "       JOIN $this->globalDB.$this->tblDefault AS wa ON w.widgetID = wa.id"
            . "  WHERE w.userID   = :userID \n"
            . "    AND w.roleID   = :roleID \n"
            . "    AND w.sequence = :widgetSequence \n"
            . "    AND w.active = 1 AND wa.enabled = 1 \n"
            . "  LIMIT 1 "
        ;

        $widget = $this->DB->fetchObjectRow($sql, $bindData);

        return $widget;
    }

    /**
     * Update widget seq
     * Note that "active" must be 1, not 0.
     *
     * @param string $widgetClassName ctrlClass
     * @param int    $widgetSequence  seq
     *
     * @return void
     */
    public function updateWidgetSequence($widgetClassName, $widgetSequence)
    {
        // pdo data bindings
        $bindData = [
            ':roleID'  => $this->roleID,
            ':userID'  => $this->userID,
            ':widgetSequence'  => intval($widgetSequence),
            ':widgetClassName' => $widgetClassName
        ];

        $sql = "UPDATE $this->globalDB.$this->tbl AS w \n"
            . "    JOIN $this->globalDB.$this->tblDefault AS wa ON w.widgetID = wa.id"
            . "    SET w.sequence = :widgetSequence \n"
            . "  WHERE w.userID     = :userID \n"
            . "    AND w.roleID     = :roleID \n"
            . "    AND wa.ctrlClass = :widgetClassName \n"
            . "    AND w.active     = 1 \n"
        ;

        $this->DB->query($sql, $bindData);
    }

    /**
     * - update state column on the dashboardWidgets table.
     * - this column is used to persist widget state changes.
     *
     * @param string $widgetClassName ctrlClass name.
     * @param string $stateChange     a JSON string containing widget state.
     *
     * @return null|\PDOStatement
     */
    public function updateStateColumn($widgetClassName, $stateChange)
    {
        // pdo data bindings
        $bindData = [
            ':roleID'          => $this->roleID,
            ':userID'          => $this->userID,
            ':widgetClassName' => $widgetClassName,
            ':state'           => $stateChange,
        ];

        $sql = "UPDATE $this->globalDB.$this->tbl AS w \n"
            . "    JOIN $this->globalDB.$this->tblDefault AS wa ON w.widgetID = wa.id \n"
            . "    SET w.state = :state \n"
            . "  WHERE w.userID     = :userID \n"
            . "    AND w.roleID     = :roleID \n"
            . "    AND wa.ctrlClass = :widgetClassName \n";

        return $this->DB->query($sql, $bindData);
    }

    /**
     * - update state column on the dashboardWidgets table.
     * - this column is used to persist widget state changes.
     *
     * @param string $postedData ctrlClass name.
     *
     * @return array
     */
    public function saveUserSettings($postedData)
    {
        $widgDat = $postedData['widgetSettings'];

        if (isset($postedData['dataTiles'])) {
            $this->saveDataRibbonTiles($postedData['dataTiles']);
        }

        // pdo data bindings
        $bindData = [
            ':roleID' => $this->roleID,
            ':userID' => $this->userID
        ];

        $sql = "UPDATE $this->globalDB.$this->tbl AS w \n"
            . "JOIN $this->globalDB.$this->tblDefault AS wa ON w.widgetID = wa.id \n"
            . "SET w.active = :active \n"
            . "WHERE w.userID    = :userID \n"
            . " AND w.roleID     = :roleID \n"
            . " AND wa.ctrlClass = :ctrlClass \n";

        foreach ($widgDat as $dat) {
            $input = $dat['active'] ? '1' : '0';

            $allBindings = array_merge(
                $bindData,
                [
                ':active'    => $input,
                ':ctrlClass' => $dat['ctrlClass']
                ]
            );

            $results[$dat['ctrlClass']]
                = $this->DB->query($sql, $allBindings);
        }

        return $results;
    }

    /**
     * Save data ribbon tile
     *
     * @param array $dataTiles Data tiles info
     *
     * @throws Exception on Invalid tile class
     *
     * @return object   Tiles have been saved; this is a retreival
     *                  of recently saved tiles for possible verification
     */
    private function saveDataRibbonTiles($dataTiles)
    {
        $fetchRibbonState = function () {
            $dat1 = $this->getWidgetByClassName($this->dataRibbonNm);
            return $dat1->state;
        };

        $validClassNames = array_keys((new DataRibbon($this->tenantID))->getTiles());

        $requireValidClass = function ($tileName) use ($validClassNames) {

            if (!in_array($tileName, $validClassNames)) {
                throw new \Exception('Invalid tile class');
            }
        };

        $inHash = [];
        $dbHash = [];

        foreach ($dataTiles as $inTile) {
            $requireValidClass($inTile['tile']);
            $inHash[$inTile['tile']] = $inTile['active'];
        }

        $origRibbon = json_decode((string) $fetchRibbonState());
        $widgState = $origRibbon->tiles;

        foreach ($widgState as $fetched) {
            $requireValidClass($fetched->class);
            if (isset($fetched->active)) { //match "active' to dynamic prop from the js.
                $dbHash[$fetched->class] = $fetched->active;
            } else {
                $dbHash[$fetched->class] = false;
            }
        }

        $newDat = array_merge($dbHash, $inHash);
        $merged = $this->mergeOldNewTileState($widgState, $newDat);

        $origRibbon->tiles = $merged;
        $this->updateStateColumn($this->dataRibbonNm, json_encode($origRibbon));
        $dat2 = $this->getWidgetByClassName($this->dataRibbonNm);

        $tiles = json_decode((string) $dat2->state)->tiles;

        // return saved tile state; ribbon widget will refresh and this will
        // trigger reissuance of the ribbon if they differ from prior list
        // (which should always be the case if we've reached this code).

        return $tiles;
    }

    /**
     * Merge new tile state into old
     *
     * @param array $orig Original tile state
     * @param array $new  New tile state
     *
     * @return array
     */
    private function mergeOldNewTileState($orig, $new)
    {
        //initialize a return obj ($returnState)
        $returnState = (object)[];

        //go through input of original, gather each class name.
        foreach ($orig as $itm) {
            if (!isset($itm->active)) {
                // for default, set it to true
                $itm->active = true;
            }
            $returnState->{$itm->class} = $itm;
        }

        //$returnState now is object with a prop of each class name.

        $getRefInOrig = function ($cl) use ($returnState) {
            //get a reference for the tile, making a new one
            // if necessary.
            if (!isset($returnState->$cl)) {
                $returnState->$cl = (object)[
                    'class' => $cl
                ];
            }
            return $returnState->$cl;
        };

        //for each tile in the db, set active
        foreach ((new DataRibbon($this->tenantID))->getTiles() as $k => $v) {
            //if the user input is set...
            if (isset($new[$v['class']])) {
                //. . . get reference to proper address in
                // the returnable object.

                $refOrig = $getRefInOrig($v['class']);
                //set return value to input value

                $refOrig->active = $new[$v['class']] ? '1' : '0';
            }
        }

        $returnState = array_values((array)$returnState);
        return $returnState;
    }
}
