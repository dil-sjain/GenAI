<?php
/**
 * Contains the class for showing the Dashing Map
 *
 * @keywords map, widget, dashboard
 */
namespace Controllers\TPM\Dashboard\Subs;

use Lib\Traits\AjaxDispatcher;
use Models\TPM\Dashboard\Subs\DashingMapData as MapModel;

/**
 * Class DashingMap
 *
 * Logic for the requests for the map display in the dashboard.
 */

#[\AllowDynamicProperties]
class DashingMap extends DashWidgetBase
{
    use AjaxDispatcher;

    /**
     * Tooltip codekey
     *
     * @var string translation codekey for tooltip
     */
    protected $ttTrans = 'map';

    /**
     * @var array Widget dependencies to load
     */
    protected $files = [
        'DashingMap.css',
        'DashingMap.js',
        'dashingMap.html',
        'dashingMap-ttLink.html',
        'dashingMap-heatMaker.html',
        'dashingMap-patterns.html',
    ];

    /**
     * DashingMap constructor.
     *
     * @param integer $tenantID Client ID
     *
     * @return null
     */
    public function __construct($tenantID)
    {
        $this->ajaxExceptionLogging = true;
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $app = \Xtra::app();
        $this->app = $app;
        $this->log = $app->log;
        $this->tenantID = (int)$tenantID;
        $this->userId = $this->app->session->get('authUserID');
        $this->baseData = new MapModel($tenantID, $this->userId);
        $this->authUserID = $this->app->session->get('authUserID');
    }

    /**
     * Check if there are any reasons that this widget should not be loaded.
     *
     * @return bool
     */
    #[\Override]
    public function noObstacles()
    {
        // This widget uses SVG and cannot be loaded in IE<=8
        if (preg_match('/(?i)msie [1-8]\./i', (string) $this->app->request->getUserAgent())) {
            return false;
        }

        return parent::noObstacles();
    }

    /**
     * All data for the map (except the country
     * json, which is stored as a file).
     *
     * @todo: regularize this abnormal controller functionality, and same
     * for model (as compared to other widgets).
     *
     * @return array
     */
    #[\Override]
    public function getDashboardSubCtrlData()
    {
        $MapModel = new MapModel($this->tenantID, $this->authUserID);

        $caseCounts  = $MapModel->getData();
        $countryData = $MapModel->casesNeedingAttentionGroupByCountry();
        $casesNeedingAttention = [];

        foreach ($countryData as $case) {
            $casesNeedingAttention[$case['iso2']] = $case['total'];
        }

        $return                   = new \stdClass();
        $return->caseCounts       = $caseCounts;
        $return->casesNeedingAttn = $casesNeedingAttention;
        $return->sitePath         = $this->app->sitePath;
        $return->description      = $this->desc;

        return [$return];
    }

    /**
     * ajax for building countries
     *
     * @return void
     */
    public function ajaxGetCountryData()
    {
        $iso   = \Xtra::arrayGet($this->app->clean_POST, 'isoCode', '');
        $link  = "/cms/case/casehome.sec?tname=casefolder&id=";
        $cases = [];

        $MapModel = new MapModel($this->tenantID, $this->authUserID);
        $data     = $MapModel->casesNeedingAttentionByCountry($iso);

        foreach ($data as $case) {
            $cases[] = [
                    'caseNum'   => $case['casenum'],
                    'caseName'  => mb_substr((string) $case['casename'], 0, 40),
                    'caseStage' => substr((string) $case['stage'], 0, 30),
                    'iso2'      => $case['iso2'],
                    'caseLink'  => $link . $case['dbid'],
            ];
        }

        $this->jsObj->Result = 1;
        $this->jsObj->Args   = $cases;
        $this->jsObj->Name   = $MapModel->selectCountryName($iso);
    }
}
