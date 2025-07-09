<?php
/**
 * Commonly Used Analytics Reports Widget
 *
 * @keywords dashboard, widget
 */
namespace Models\TPM\Dashboard\Subs;

/**
 * Class CommonlyUsedAnalyticsReports
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class CommonlyUsedAnalyticsReports
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    private $DB = null;

    /**
     * @var int tenantID
     */
    private $tenantID = null;

    /**
     * Init class constructor
     *
     * @param int $tenantID Current tenant ID
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;
        $this->tenantID = $tenantID;
    }

    /**
     * get widget specific data
     *
     * @param string  $regTitle     Title of regions
     * @param integer $authUserType Current user's type
     *
     * @return array|boolean
     */
    public function getData($regTitle, $authUserType)
    {
        $title = $regTitle;

        $permissions = [];

        // determine if user has access to 'Commonly Used Analytics Reports'
        if (! $this->app->ftr->has(\Feature::ANALYTICS)) {
            return false;
        }

        // determine which reports will be displayed
        if ($this->app->ftr->has(\Feature::CASE_TRACK_RPTS)) {
            array_push($permissions, [
                'name' => "List Cases by $title",
                'uri'  => '/tpReport/CaseListByRegion'
                ]);
        }
        if ($this->app->ftr->has(\Feature::CASE_FIN_RPTS)) {
            array_push($permissions, [
                'name' => "Investigation Cost by $title",
                'uri'  => '/tpReport/CaseCostByRegion'
                ]);
            array_push($permissions, [
                'name' => "Time and Expense Detail",
                'uri'  => '/tpReport/CaseTimeAndExpense'
                ]);
        }
        if ($this->app->ftr->has(\Feature::CASE_STATS_RPTS)) {
            array_push($permissions, [
                'name' => "Quantity of Cases by $title",
                'uri'  => '/tpReport/CaseQtyByRegion'
                ]);
            array_push($permissions, [
                'name' => "Quantity of Cases by Type",
                'uri'  => '/tpReport/CaseQtyByCaseType'
                ]);
        }
        if ($this->app->ftr->isLegacyClientAdmin() || $this->app->ftr->isLegacySuperAdmin()) {
            array_push($permissions, [
                'name' => "Custom Report Builder",
                'uri'  => '/tpm/rpt/adhoc'
                ]);
            array_push($permissions, [
                'name' => "Intake Form Report Builder",
                'uri'  => '/tpm/rpt/ddq'
                ]);
        }

        $title = "Commonly Used Analytics Reports";

        $jsData = [[
            'title' => $title,
            'permissions' => $permissions,
            'description' => null
        ]];

        return $jsData;
    }
}
