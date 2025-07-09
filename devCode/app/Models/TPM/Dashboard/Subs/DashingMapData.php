<?php
/**
 * Contains class that provides DB data needed for displaying the Dashboard map widget
 *
 * @keywords map, dashboard, widget
 */

namespace Models\TPM\Dashboard\Subs;

use Lib\Legacy\CaseStage;
use Models\Globals\Department;
use Models\Globals\Geography;
use Models\Globals\Region;

/**
 * Provides DB data needed for displaying the Dashboard map widget
 *
 *
 */

#[\AllowDynamicProperties]
class DashingMapData
{
    /**
     *
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    private $DB = null;

    /**
     * @var integer $tenantID Current tenantID
     */
    private $tenantID = null;

    /**
     * @var integer $userID Current userID
     */
    private $userID = null;

    /**
     * @var string Directory for dashingMap
     */
    public const DASHING_MAP_DIR = "public_html/assets/js/TPM/Dashboard/Subs";

    /**
     * @var string Translation key for desc for tooltip
     */
    private $desc = null;

    /**
     * @var object Department model
     */
    private $Department = null;

    /**
     * @var object Region model
     */
    private $Region = null;

    /**
     * @var string XX_XX language code
     */
    protected $langCode;

    /**
     * @var Geography Class instance
     */
    protected $geo;

    /**
     * Init class constructor
     *
     * @param integer $tenantID Current tenantID
     * @param integer $userID   Current userID
     */
    public function __construct($tenantID, $userID)
    {
        \Xtra::requireInt($tenantID);

        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;
        $this->desc = $this->app->trans->groups(['dashboard_widget_tt'])['map'];
        $this->tenantID = $tenantID;
        $this->userID = (int)$userID;
        $this->Region = new Region($this->tenantID);
        $this->Department = new Department($this->tenantID);
        $this->langCode = $this->app->sesssion->languageCode ?? 'EN_US';
        $this->geo = Geography::getVersionInstance(null, (int)$tenantID);
    }

    /**
     * Return data for initial payload
     *
     * @return array, all cases the user can see
     */
    public function getData()
    {
        // verify user has access to case management feature
        if (!$this->app->ftr->has(\Feature::CASE_MANAGEMENT)) {
            return [];
        }

        $dbName = $this->DB->getClientDB($this->tenantID);
        $bind   = [':clientID' => $this->tenantID];
        $sql    = [];
        $sql[]  = 'SELECT COUNT(c.id) as cnt,';
        $sql[]  = 'c.caseCountry';
        $sql[]  = "FROM {$dbName}.cases AS c";
        $sql[]  = "LEFT JOIN {$this->DB->authDB}.users AS u ON u.userid = c.requestor";
        $sql[]  = 'WHERE c.clientID = :clientID';
        $sql[]  = 'AND c.caseStage <> ' . CaseStage::DELETED . ' AND c.caseStage < ' . CaseStage::TRAINING_INVITE;

        $isLegacyClientManager = $this->app->ftr->isLegacyClientManager();
        $isLegacyClientUser    = $this->app->ftr->isLegacyClientUser();

        if ($isLegacyClientManager || $isLegacyClientUser) {
            if (!$this->Region->hasAllRegions($this->userID, $this->app->ftr->role)) {
                $sql[] = $this->returnRegionFilter($isLegacyClientManager);
            }
            if ($isLegacyClientManager) {
                if (!$this->Department->hasAllDepartments($this->userID, $this->app->ftr->role)) {
                    $sql[] = $this->returnDepartmentFilter();
                }
            }
        }

        $sql[] = 'GROUP BY c.caseCountry';

        return $this->DB->fetchAssocRows(implode(PHP_EOL, $sql), $bind);
    }

    /**
     * Cases data grouped by country
     *
     * @return array, count(*) of all cases needing attention the user can see
     */
    public function casesNeedingAttentionGroupByCountry()
    {
        // verify user has access to case management feature
        if (!$this->app->ftr->has(\Feature::CASE_MANAGEMENT)) {
            return [];
        }

        $attnStages = $this->returnCaseStagesNeedingAttention();

        $bind  = [':clientID' => $this->tenantID];
        $sql   = [];
        $sql[] = 'SELECT count(c.id) as total,';
        $sql[] = 'c.caseCountry AS iso2';
        $sql[] = 'FROM cases AS c';
        $sql[] = "LEFT JOIN {$this->DB->authDB}.users AS u ON u.userid = c.requestor";
        $sql[] = 'WHERE c.clientID = :clientID';
        $sql[] = "AND c.caseStage <> " . CaseStage::DELETED . " AND c.caseStage < " . CaseStage::TRAINING_INVITE;
        $sql[] = 'AND c.caseStage IN (' . join(',', $attnStages) . ')';

        $isLegacyClientManager = $this->app->ftr->isLegacyClientManager();
        $isLegacyClientUser    = $this->app->ftr->isLegacyClientUser();

        if ($isLegacyClientManager || $isLegacyClientUser) {
            if (!$this->Region->hasAllRegions($this->userID, $this->app->ftr->role)) {
                $sql[] = $this->returnRegionFilter($isLegacyClientManager);
            }
            if ($isLegacyClientManager) {
                if (!$this->Department->hasAllDepartments($this->userID, $this->app->ftr->role)) {
                    $sql[] = $this->returnDepartmentFilter();
                }
            }
        }
        $sql[] = 'GROUP BY c.caseCountry';

        return $this->DB->fetchAssocRows(implode(PHP_EOL, $sql), $bind);
    }


    /**
     * Return cases needing attention grouped by country.
     *
     * @param string $isoCode 2-letter ISO country code
     *
     * @return mixed, all cases needing attention the user can see by isoCode
     */
    public function casesNeedingAttentionByCountry($isoCode = null)
    {
        // verify user has access to case management feature
        if (!$this->app->ftr->has(\Feature::CASE_MANAGEMENT)) {
            return [];
        }

        $attnStages = $this->returnCaseStagesNeedingAttention();

        $bind  = [':clientID' => $this->tenantID];
        if (\Xtra::usingGeography2()) {
            $countryField = 'IFNULL(cn.displayAs, cn.legacyName)';
            $countryOn = '(cn.legacyCountryCode = c.caseCountry '
                . 'OR cn.codeVariant = c.caseCountry OR cn.codeVariant2 = c.caseCountry) '
                . 'AND (cn.countryCodeID > 0 OR cn.deferCodeTo IS NULL)';
        } else {
            $countryField = 'cn.legacyName';
            $countryOn = 'cn.legacyCountryCode = c.caseCountry';
        }
        $sql   = [];
        $sql[] = 'SELECT c.id AS dbid,';
        $sql[] = 'c.userCaseNum AS casenum,';
        $sql[] = 'c.caseName AS casename,';
        $sql[] = 'stg.name AS stage,';
        $sql[] = 'u.userName AS requester,';
        $sql[] = 'r.name AS region,';
        $sql[] = 'c.caseCountry AS iso2,';
        $sql[] = $countryField . ' AS country,';
        $sql[] = 'c.tpID';
        $sql[] = 'FROM cases AS c';
        $sql[] = "LEFT JOIN region AS r ON r.id = c.region ";
        $sql[] = "LEFT JOIN caseStage AS stg ON stg.id = c.caseStage";
        $sql[] = "LEFT JOIN {$this->DB->isoDB}.legacyCountries AS cn ON $countryOn";
        $sql[] = "LEFT JOIN {$this->DB->authDB}.users AS u ON u.userid = c.requestor";
        $sql[] = 'WHERE c.clientID = :clientID';
        $sql[] = "AND c.caseStage <> " . CaseStage::DELETED . " AND c.caseStage < " . CaseStage::TRAINING_INVITE;
        $sql[] = 'AND c.caseStage IN (' . join(',', $attnStages) . ')';

        if (!empty($isoCode)) {
            $sql[]            = "AND c.caseCountry = :isoCode";
            $bind[':isoCode'] = $isoCode;
        }

        $isLegacyClientManager = $this->app->ftr->isLegacyClientManager();
        $isLegacyClientUser    = $this->app->ftr->isLegacyClientUser();

        if ($isLegacyClientManager || $isLegacyClientUser) {
            if (!$this->Region->hasAllRegions($this->userID, $this->app->ftr->role)) {
                $sql[] = $this->returnRegionFilter($isLegacyClientManager);
            }
            if ($isLegacyClientManager) {
                if (!$this->Department->hasAllDepartments($this->userID, $this->app->ftr->role)) {
                    $sql[] = $this->returnDepartmentFilter();
                }
            }
        }

        $sql[] = "ORDER BY c.id ASC";

        return $this->DB->fetchAssocRows(implode(PHP_EOL, $sql), $bind);
    }

    /**
     * get the country name
     *
     * @param string $isoCode 2-character ISO code
     *
     * @return string country name given an iso code
     */
    public function selectCountryName($isoCode)
    {
        return $this->geo->getCountryNameTranslated($isoCode, $this->LangCod);
    }

    /**
     * get the users regions
     *
     * @return array of user regions
     */
    private function returnUserRegions()
    {
        $regions = $this->Region->getUserRegions($this->userID, $this->app->ftr->role);
        return \Xtra::extractbyKey('id', $regions);
    }

    /**
     * get the users departments
     *
     * @return array of user departments
     */
    private function returnUserDepartments()
    {
        $departments = $this->Department->getUserDepartments($this->userID, $this->app->ftr->role);
        return \Xtra::extractbyKey('id', $departments);
    }

    /**
     * get the filter for regions
     *
     * @param bool $isClientManager if the logged in user is a client manager, assumes else is a client user
     *
     * @return string SQL ready AND filter of valid regions
     */
    private function returnRegionFilter($isClientManager = true)
    {
        $userRegions  = $this->returnUserRegions();
        $userRegions  = implode(',', $userRegions);

        if ($isClientManager) {
            $regionString = "AND c.region IN({$userRegions})";
        } else {
            // @todo: There are 1-2 clients who do not want this... need to find in Legacy code
            $regionString = "AND (u.id = {$this->userID} "
            . "OR (c.region IN ({$userRegions}) AND c.caseStage = "
            . CaseStage::QUALIFICATION . PHP_EOL . " AND c.requestor = ''))";
        }

        return $regionString;
    }

    /**
     * get the filter for departments
     *
     * @return string SQL ready AND filter of valid departments
     */
    private function returnDepartmentFilter()
    {
        $userDepartments = $this->returnUserDepartments();
        $csv             = empty($userDepartments) ? '0' : '0,' . implode(',', $userDepartments);

        return "AND c.dept IN({$csv})";
    }

    /**
     * return the defined list of case status that need attention
     *
     * @return array, case stages needing attention
     */
    private function returnCaseStagesNeedingAttention()
    {
        return [
            CaseStage::QUALIFICATION,
            CaseStage::REQUESTED_DRAFT,
            CaseStage::UNASSIGNED,
            CaseStage::AWAITING_BUDGET_APPROVAL,
            CaseStage::COMPLETED_BY_INVESTIGATOR
        ];
    }

    /**
     * Return description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->desc;
    }
}
