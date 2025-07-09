<?php
/**
 * Active Cases by Status Widget
 *
 * @keywords dashboard, widget, fGetActiveCases, fNumOfActiveCases
 */
namespace Models\TPM\Dashboard\Subs;

use Models\Globals\Region;
use Models\ThirdPartyManagement\Cases;

/**
 * Class ActiveCasesByStatus
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ActiveCasesByStatus
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
     * @var $tenantID
     */
    private $tenantID = null;

    /**
     * @var $clientDB
     */
    private $clientDB = null;

    /**
     * Init class constructor
     *
     * @param integer $tenantID Current tenantID
     * @param integer $userID users.id
     */
    public function __construct($tenantID, private $userID)
    {
        \Xtra::requireInt($tenantID);

        $this->app           = \Xtra::app();
        $this->DB            = $this->app->DB;
        $this->tenantID      = (int)$tenantID;
        $this->clientDB      = $this->app->DB->getClientDB($this->tenantID);
        $this->cases = new Cases($tenantID);
    }

    /**
     * Returns widget specific data
     *
     * @param string $title       Title for widget
     * @param string $regionTitle Tenant's specific region title
     * @param string $legacyCliId Client id
     *
     * @return array
     */
    public function getData($title, $regionTitle, $legacyCliId)
    {
        \Xtra::requireInt($legacyCliId);
        $jsData  = [[
            'title'         => $title,
            'regionalTitle' => $regionTitle,
            'cases'         => $this->getActiveCases($legacyCliId),
            'description' => null,
        ]];

        return $jsData;
    }


    /**
     * Refactor of Legacy @see public_html/cms/includes/php/dashboard_funcs.php fGetActiveCases()
     *
     * @param integer $legacyCliId Legacy client id
     *
     * @return array of active cases parse-able by jqxDataTable
     */
    private function getActiveCases($legacyCliId)
    {
        return $this->cases->getActiveCases($this->getRegionList($legacyCliId), $this->clientDB);
    }

    /**
     * Refactor of Legacy @see  public_html/cms/includes/php/dashboard_funcs.php fNumOfActiveCases()
     *
     * Returns number of cases found by stage and region
     *
     * @param integer $stageRange Stages SQL clause to search
     * @param integer $regionID   Region to search
     *
     * @return integer      Number of Cases found
     */
    public function numOfActiveCases($stageRange, $regionID)
    {
        return $this->cases->numOfActiveCases($stageRange, $regionID, $this->clientDB);
    }


    /**
     * Refactor of Legacy @see public_html/cms/includes/php/dashboard_funcs.php fGetActiveCases()
     *
     * @param integer $legacyCliId clientID
     *
     * @return array list of Regions (id, name) for a user
     */
    protected function getRegionList($legacyCliId)
    {
        $regionObj = new Region($legacyCliId);
        return $regionObj->getUserRegions($this->userID);
    }

    /**
     * Queries the Region class by userID
     *
     * @return string comma separated list of region ids
     */
    public function getRegions()
    {
        $regionClass = new Region($this->tenantID);
        $regionsList = $regionClass->getUserRegions($this->userID);

        $temp = [];
        foreach ($regionsList as $region) {
            $temp[] = $region['id'];
        }
        $regions = implode(',', $temp);

        return $regions;
    }
}
