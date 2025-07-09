<?php
/**
 * Controller: GdcFilters
 */

namespace Controllers\TPM\Settings\ContentControl\GdcFilters;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Lib\Legacy\UserType;
use Lib\SettingACL;
use Models\Globals\Settings\SaveSettings;
use Models\ThirdPartyManagement\Gdc;
use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\TPM\Gdc\GdcListExcl;
use Models\LogData;
use Lib\Traits\CommonHelpers;

/**
 * Configuration of GDC Filters for country and source lists
 */
#[\AllowDynamicProperties]
class GdcFilters extends Base
{
    use AjaxDispatcher;
    use CommonHelpers;

    /**
     * Application instance
     *
     * @var object
     */
    private $app = null;

    /**
     * Country Filter Model instance
     *
     * @var object
     */
    private $mCountry = null;

    /**
     * Source List Filter model instance
     *
     * @var object
     */
    private $mList = null;

    /**
     * Gdc model instance
     *
     * @var object
     */
    private $mGdc = null;

    /**
     * Base directory for View
     *
     * @var string
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/GdcFilters/';

    /**
     * Base template for View
     *
     * @var string
     */
    protected $tpl = 'GdcFilters.tpl';

    /**
     * User's ID
     *
     * @var null
     */
    private $userID = null;

    /**
     * GdcListExcl class instance
     *
     * @var null
     */
    private $gdcListExcl = null;

    /**
     * Client DB
     *
     * @var string
     */
    private $clientDB = null;

    /**
     * SaveSettings instance
     *
     * @var object
     */
    private $settingACL = null;


    /**
     * Constructor gets model instance to interact with JIRA API
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app      = \Xtra::app();
        $this->clientDB = $this->app->DB->getClientDB($this->clientID);
        $this->settingACL = new SaveSettings($clientID, SaveSettings::APPCONTEXT_GDC_FILTERS);
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        // It would be nice to simplify this set of permissions into one feature
        $userType = $this->app->ftr->legacyUserType;
        $accessLevel = $this->app->session->secureValue('accessLevel');
        $has3p = $this->app->ftr->has(\Feature::TENANT_TPM);
        $gdcSettings = (new SettingACL($this->clientID))->getGdcSettings();
        $monPaid = $gdcSettings['gdcPremium'];
        $monBase = $gdcSettings['gdcBasic'];

        if (!$has3p
            || (!$monPaid && !$monBase)
            || ($userType != UserType::CLIENT_ADMIN && $userType != UserType::SUPER_ADMIN)
            
        ) {
            $this->app->redirect('/accessDenied');
        }

        // In future, perhaps allow gdc on non-3p
        if ($has3p) {
            $mTpType = new TpType($this->clientID);
            $tpTypeList = $mTpType->selectMultiple(['id', 'name'], [], 'ORDER BY name ASC');
            $msg = 'Mark items to be excluded during GDC searches '
                . 'on profiles matching selected type and category.';
            $this->setViewValue('gdcSearchMsg', $msg);
            $this->setViewValue('defaultTypeCatOption', '(All)');
            $srchOpts = $gdcSettings['rawSrchOpts'];
            if (strlen((string) $srchOpts) === 4) {
                // Adding soe and rights data if enabled for client
                $srchOpts .= $gdcSettings['search']['mex'] .
                            ($gdcSettings['paidSoe'] ? $gdcSettings['search']['soe'] : '') .
                            ($gdcSettings['allowRights'] ? $gdcSettings['search']['rights'] : '') .
                            $gdcSettings['search']['col']; // add defaults for 'mex', 'soe', 'rights', and 'col'
            } elseif (strlen((string) $srchOpts) === 6) {
                // Adding rights data if enabled for client
                $srchOpts .= ($gdcSettings['allowRights'] ? $gdcSettings['search']['rights'] : '') .
                            $gdcSettings['search']['col']; // add default for 'rights', and 'col'
            } elseif (strlen((string) $srchOpts) === 7) {
                $srchOpts .= $gdcSettings['search']['col']; // add default for 'col'
            } else {
                // Removing soe and rights data if not enabled for client
                if (!$gdcSettings['paidSoe']) {
                    $srchOpts = substr_replace((string) $srchOpts, '', 5, 1);
                }
                if (!$gdcSettings['allowRights']) {
                    $index = 5 + ($gdcSettings['paidSoe'] ? 1 : 0);
                    $srchOpts = substr_replace((string) $srchOpts, '', $index, 1);
                }
            }
            $this->setViewValue('currentScope', $srchOpts);
        } else {
            $tpTypeList = [];
            $msg = 'Mark items to be excluded during GDC searches.';
            $this->setViewValue('gdcSearchMsg', $msg);
            $this->setViewValue('defaultTypeCatOption', 'n/a');
        }
        $this->setViewValue('has3p', $has3p);
        $this->setViewValue('tpTypeList', $tpTypeList);
        $this->setViewValue('GdcFiltersCanAccess', true);
        $this->setViewValue('pgTitle', 'GDC Filters');

        $processExists = $this->returnProcessExists();
        $this->setViewValue('processExists', ($processExists ? 1 : 0));
        $this->setViewValue('paidSoe', $gdcSettings['paidSoe']);
        $this->setViewValue('allowRights', $gdcSettings['allowRights']);
        $this->setViewValue('gdcSearchOptions', $gdcSettings['search']);

        $this->app->trans->tenantID = $this->clientID;
        $trGroup = $this->app->trans->group('tab_gdc_filters');

        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * Get 3P categories for a type
     *
     * @return void Sets jsObj
     */
    private function ajaxGetTpCats()
    {
        $cats = [];
        $typeID = (int)\Xtra::arrayGet($this->app->clean_POST, 't', 0);
        if ($typeID) {
            $mTpCat = new TpTypeCategory($this->clientID);
            $cats = $mTpCat->getCleanCategoriesByType($typeID);
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.gdcfilt.putCats';
        $this->jsObj->Args = [$cats, $this->returnProcessExists()];
    }

    /**
     * Returns instance of GdcListExcl.
     *
     * @return GdcListExcl|null instance of GdcListExcl
     */
    private function getGdcListExcl()
    {
        if (!($this->gdcListExcl instanceof GdcListExcl)) {
            $this->gdcListExcl = new GdcListExcl($this->clientID);
        }
        return $this->gdcListExcl;
    }

    /**
     * Get count of excluded source lists for tpType and tpCat
     *
     * @param integer $tpType 3P profile type
     * @param integer $tpCat  3P profile category
     *
     * @return integer record count
     */
    private function getListExclCnt($tpType, $tpCat)
    {
        $this->gdcListExcl = $this->getGdcListExcl();
        return $this->gdcListExcl->countRecords($tpType, $tpCat);
    }

    /**
     * Checks for end of batch screening
     */
    private function ajaxCheckProcessExists()
    {
        $this->jsObj->Result = 1;
        $this->jsObj->Args   = [$this->returnProcessExists()];
    }

    /**
     * Save changes to list filter
     *
     * @param integer $tpType 3P profile type
     * @param integer $tpCat  3P profile category
     * @param string  $cks    csv list of check items
     *
     * @return integer record count
     */
    private function saveListChanges($tpType, $tpCat, $cks)
    {
        $gdcListExcl = $this->getGdcListExcl();
        return $gdcListExcl->updateFilter($tpType, $tpCat, $cks);
    }

    /**
     * Get count of excluded filter items for the posted conditions
     *
     * @return void sets jsObject
     */
    private function ajaxGetExclCnt()
    {
        $tpType = (int)$this->getPostVar('t', 0);
        $tpCat = (int)$this->getPostVar('c', 0);
        $cnt = $this->getListExclCnt($tpType, $tpCat);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.gdcfilt.putExcludedCount';
        $this->jsObj->Args = [$cnt];
    }

    /**
     * Split A - C  or  T into first/last letter of alphabetical range
     *
     * @param string $grp The range designator
     *
     * @return array indexed array of first and last characters in range
     */
    private function splitAlphaGrp($grp)
    {
        $grp = preg_replace('/ /', '', $grp);
        $parts = explode('-', (string) $grp);
        if (count($parts) == 2) {
            [$first, $last] = $parts;
        } else {
            $first = $last = $parts[0];
        }
        return [$first, $last];
    }

    /**
     * Get source list item whose authorities fall within the alphabetic range
     *
     * @param string $alphaGrp Alphabetic range of authority names
     *
     * @return array of id/name pairs
     */
    private function getListItems($alphaGrp)
    {
        $gdc = new Gdc($this->clientID);
        [$first, $last] = $this->splitAlphaGrp($alphaGrp);
        return $gdc->getListAlphaRange($first, $last);
    }

    /**
     * Get array of excluded listID values
     *
     * @param integer $tpType  3P profile type
     * @param integer $tpCat   3P profile category
     * @param string  $limitTo csv list of IDs to search for
     *
     * @return array listID values
     */
    private function getFilterListIDs($tpType, $tpCat, $limitTo = '')
    {
        $this->gdcListExcl = $this->getGdcListExcl();
        return $this->gdcListExcl->getFilterIDs($tpType, $tpCat, $limitTo);
    }

    /**
     * Get filterable items from info4c.master_country_list or info4c.master_source_list
     *
     * @return void sets jsObj
     */
    private function ajaxGetItems()
    {
        $alphaGrp = $this->getPostVar('ag', '');
        $alreadyLoaded = (int)$this->getPostVar('ld', 0);
        $pat1 = '/([A-Z] - [A-Z])/';
        $pat2 = '/([A-Z])/';
        if (!preg_match($pat1, (string) $alphaGrp) && !preg_match($pat2, (string) $alphaGrp)) {
            $this->jsObj->ErrTitle = 'Invalid Alphabetical Range';
            $this->jsObj->ErrMsg = 'Unable to select items alphabetically.';
            return;
        }

        $tpType = (int)$this->getPostVar('t', 0);
        $tpCat = (int)$this->getPostVar('c', 0);

        // get items to filter
        $items = [];
        if (!$alreadyLoaded) {
            $items = $this->getListItems($alphaGrp);
        }

        // get count of all excluded items for this type/cat and alphaGrp
        $ids = [];
        foreach ($items as $item) {
            foreach ($item['lists'] as $li) {
                $ids[] = $li['id'];
            }
        }
        $idList = implode(',', $ids);
        $grpExcluded = $this->getFilterListIDs($tpType, $tpCat, $idList);

        // get count of all excluded items for this type/cat
        $ttlExcluded = $this->getListExclCnt($tpType, $tpCat);

        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.gdcfilt.putListItems';
        $this->jsObj->Args = [$items, $grpExcluded, $ttlExcluded];
    }

    /**
     * Save list exclusion changes
     *
     * @return void
     */
    private function ajaxSaveItemChanges()
    {
        $cks = $this->getPostVar('cks', '');
        $log = [];

        $codeKeys = $this->app->trans->codeKeys([
            'gdc_filters_listing_agencies_added',
            'gdc_filters_listing_agencies_removed',
        ]);

        // Handle list exclusions
        if (preg_match('/^(\d+:(0|1))(,\d+:(0|1))*$/', (string) $cks)) {
            $tpType = (int)$this->getPostVar('t', 0);
            $tpCat  = (int)$this->getPostVar('c', 0);

            //  Query for previous filters
            $gdcListExcl = $this->getGdcListExcl();
            $oldFilters = $gdcListExcl->getRecordsByTypeAndCategory($tpType, $tpCat);

            //  Save changes
            $cnt = $this->saveListChanges($tpType, $tpCat, $cks);

            //  Query for updated filters & find filter changes
            $newFilters     = $gdcListExcl->getRecordsByTypeAndCategory($tpType, $tpCat);
            $updatedFilters = array_diff($newFilters, $oldFilters);
            $deletedFilters = array_diff($oldFilters, $newFilters);

            if (count($updatedFilters)) {
                $log[] = $codeKeys['gdc_filters_listing_agencies_added'] . ' (' . implode(',', $updatedFilters) . ')';
            }
            if (count($deletedFilters)) {
                $log[] = $codeKeys['gdc_filters_listing_agencies_removed'] . ' (' . implode(',', $deletedFilters) . ')';
            }
        } elseif (empty($cks)) {
            $cnt = 'noUpdate';
        } else {
            $this->jsObj->ErrTitle = 'Invalid Request Data';
            $this->jsObj->ErrMsg   = 'Unable to save changes.';
            return;
        }

        // Log the changes
        if (!empty($log)) {
            $logs = rtrim(implode('; ', $log));
            (new LogData($this->clientID, $this->app->ftr->user))->saveLogEntry(
                152, // update tenant-facing setting
                $logs
            );
        }

        $this->jsObj->Result    = 1;
        $this->jsObj->FuncName  = 'appNS.gdcfilt.handleSaveItems';
        $this->jsObj->AppNotice = ['Updated exclusions.'];
        $this->jsObj->Args      = [$cnt, $this->returnProcessExists()];
    }

    /**
     * Save search scope changes
     *
     * @return void
     */
    private function ajaxSaveScopeChanges()
    {
        $gdcSearch    = $this->getPostVar('gdc_search', []);
        $gdcSearchCnt = $this->getPostVar('gdc_search_showing', 0);
        $log          = [];

        $searchTablesChanged = false;
        $setAcl = new SettingACL($this->clientID);
        $gdcSettings = $setAcl->getGdcSettings();
        $currentValue = $gdcSettings['rawSrchOpts'];
        if (strlen((string) $currentValue) === 4) {
            // extend old 4-char value
            $currentValue .= '0110'; // add defaults for 'mex', 'soe', 'rights and col'
        } elseif (strlen((string) $currentValue) === 6) {
            $currentValue .= '10'; // add default for 'rights', and 'col'
        } elseif (strlen((string) $currentValue) === 7) {
            $currentValue .= '0'; // add default for 'col'
        }
        if ($gdcSearchCnt > 0) {
            // Did search options (gdc tables) change?
            // THIS ORDER MUST MATCH GDC_SEARRCH_OPTIONS
            $gdcScopes = $this->getGdcScopes();
            $tblOrder = $logListNames = [];
            foreach ($gdcScopes as $key => $gdcScope) {
                $tblOrder[$key] = $gdcScope->code;
                $logListNames[$gdcScope->code] = $gdcScope->name;
            }
            $searchTables = ''; // build new 7-char value string
            foreach ($tblOrder as $pos => $tbl) {
                if ($tbl === 'soe') {
                    // This is a paid feature. Client can control only if feature is enabled.
                    if ($gdcSettings['paidSoe']) {
                        $searchTables .= $gdcSearch[$tbl];
                    } else {
                        // Use last known value from soe position
                        $searchTables .= $currentValue[$pos];
                    }
                } elseif ($tbl === 'rights') {
                    if ($gdcSettings['allowRights']) {
                        $searchTables .= $gdcSearch[$tbl];
                    } else {
                        // Use last known value from rights position
                        $searchTables .= $currentValue[$pos];
                    }
                } else {
                    $searchTables .= $gdcSearch[$tbl];
                }
                if ($searchTables[$pos] !== $currentValue[$pos]) {
                    $searchTablesChanged = true;
                    $log[] = $logListNames[$tbl] . ': '
                        . ($currentValue[$pos] === '1' ? 'on' : 'off')
                        . ' =&gt; '
                        . ($searchTables[$pos] === '1' ? 'on' : 'off');
                }
            }
        }

        // Save the new GDC_SEARCH_OPTIONS setting after list filter is updated so new exclusions
        // will be in effect for the new screening
        $optionsObj = null;
        if ($s = $this->settingACL->get(SettingACL::GDC_SEARCH_OPTIONS)) {
            $optionsObj = $s['setting'];
        }

        if ($searchTablesChanged) {
            $saved = $this->settingACL->save(['setting' => $optionsObj, 'newValue' => $searchTables]);
            if (is_array($saved) && !empty($saved)) {
                // We have some errors. Puke 'em out.
                $this->jsObj->ErrTitle = 'Unable to save changes';
                $this->jsObj->ErrMsg = implode(', ', $saved);
                return;
            } else {
                $currentValue = $searchTables;
            }
        }

        // Log the changes
        if (is_object($optionsObj) && !empty($log)) {
            $logs = rtrim(implode('; ', $log));
            $logged = $this->settingACL->logManually($optionsObj, $logs);
        }

        $this->jsObj->Result    = 1;
        $this->jsObj->FuncName  = 'appNS.gdcfilt.handleSaveScope';
        $this->jsObj->AppNotice = ['Changes saved.'];
        $this->jsObj->Args      = [$currentValue, $this->returnProcessExists()];
    }

    /**
     * Queries and returns a boolean if a scheduled or running process exists
     *
     * @return bool If process(es) exist, panama papers checkbox not shown
     */
    private function returnProcessExists()
    {
        $processID = $this->app->DB->fetchValue(
            "SELECT id\n".
            "FROM {$this->app->DB->globalDB}.g_requiredProcessDef\n".
            "WHERE  appKey = 'automatedGdcScreening'"
        );

        $scheduledProcess = $this->app->DB->fetchValue(
            "SELECT count(id)\n".
            "FROM {$this->app->DB->globalDB}.g_requiredProcess\n".
            "WHERE  clientID = :clientID\n".
            "AND    processDefID = :processID\n".
            "AND    deleted IS NULL",
            [':clientID' => $this->clientID, ':processID' => $processID]
        );

        $runningProcess = $this->app->DB->fetchValue(
            "SELECT count(id)\n".
            "FROM   {$this->app->DB->globalDB}.g_bgProcess\n".
            "WHERE  clientID = :clientID\n".
            "AND    jobType = 'automatedGdcScreening'\n".
            "AND status = 'running'\n".
            "LIMIT 1",
            [':clientID' => $this->clientID]
        );

        return (empty($scheduledProcess) && empty($runningProcess)) ? false : true;
    }

    /**
     * Validate input & return true if there is an updated value, false if invalid input or checkbox is the same
     *
     * @param string  $checkboxVal Either 'false', 'true', or 'hidden'
     * @param boolean $currentVal  Setting's current value in the DB
     *
     * @return boolean
     */
    private function checkboxUpdated($checkboxVal, $currentVal)
    {
        if ($checkboxVal == 'hidden' || !in_array($checkboxVal, ['true', 'false'])) {
            return false;
        }
        $checkboxBool = filter_var($checkboxVal, FILTER_VALIDATE_BOOLEAN);
        return ($checkboxBool !== $currentVal);
    }
}
