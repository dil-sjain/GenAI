<?php
/**
 * Data Ribbon Widget
 *
 * @keywords dashboard, widget
 */
namespace Models\TPM\Dashboard\Subs;

use Lib\FeatureACL;

/**
 * Class ActiveCasesByStatus
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class DataRibbon
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var \MySqlPdo DB instance
     */
    private $DB = null;

    /**
     * @var int $tenantID
     */
    private $tenantID = null;

    /**
     * @var string $tbl Tables holding user specific data for widgets
     */
    protected $tbl = "g_dashboardWidgets";

    /**
     * @var string  authDB User's db, via "client"
     */
    private $authDB = null;

    /**
     * @var string  globalDB Name of global db
     */
    private $globalDB = null;

    /**
     * @var array Data tiles with class name and title to be displayed
     */
    private $tilesWithTitles = [
        [
            'id'          => 1,
            'class'       => 'TpPendingApproval',
            'title'       => 'Third Parties: Pending Approval',
            'description' => 'Approval status is pending on active profiles',
            'active'      => 1,
            'featureReq'  => [FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM],
        ],
        [
            'id'          => 2,
            'class'       => 'TpReadyForRenewal',
            'title'       => 'Third Parties: Ready for Renewal',
            'description' => 'If using Third Party Risk Management - Compliance renewal functionality, this data tile shows the third party
            profiles available for renewal (Third Party Profile -> Due Diligence Tab) Note: If not using the
            Third Party Risk Management - Compliance renewal functionality, our recommendation is to turn this data tile off in settings.',
            'active'      => 0, // Turned off for now, as tenants use too many different ways to determine this
            'featureReq'  => [FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM],
        ],
        [
            'id'          => 3,
            'class'       => 'CaseIntakeFormNeedsSent',
            'title'       => 'Third Parties: Intake Form Not Sent',
            'description' => 'No DDQ assigned, third party status is active, third party\'s approval pending',
            'active'      => 1,
            'featureReq'  => [FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM],
        ],
        [
            'id'          => 4,
            'class'       => 'CaseGdcFlagsToAdjudicate',
            'title'       => 'Third Parties: Undetermined GDC hits',
            'description' => 'Third party profiles with un-reviewed GDC hits',
            'active'      => 1,
            'featureReq'  => [
                FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM,
                FeatureACL::TENANT_GDC_BASIC, FeatureACL::TENANT_GDC_PREMIUM
            ],
        ],
        [
            'id'          => 12,
            'class'       => 'MediaMonitorHits',
            'title'       => 'Third Parties: Undetermined Media Monitor Hits',
            'description' => 'Third Parties with undetermined Media Monitor hits',
            'active'      => 1,
            'featureReq'  => [FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM, FeatureACL::TENANT_MEDIA_MONITOR],
        ],
        [
            'id'          => 5,
            'class'       => 'TpHighRiskCompanies',
            'title'       => 'Third Parties: High Risk',
            'description' => 'Status is active, and their risk tier defaults to an EDD recommendation',
            'active'      => 1,
            'featureReq'  => [FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM, FeatureACL::TENANT_TPM_RISK],
        ],
        [
            'id'          => 6,
            'class'       => 'TpActive3rdParties',
            'title'       => 'Third Parties: Active',
            'description' => 'Number of active third parties in the system',
            'active'      => 1,
            'featureReq'  => [FeatureACL::THIRD_PARTIES, FeatureACL::TENANT_TPM],
        ],
        [
            'id'          => 7,
            'class'       => 'ActiveOSI',
            'title'       => 'Cases: Active OSIs',
            'description' => 'Budget is approved, awaiting action by the investigator',
            'active'      => 1,
            'featureReq'  => [FeatureACL::CASE_MANAGEMENT],
        ],
        [
            'id'          => 8,
            'class'       => 'ActiveEdd',
            'title'       => 'Cases: Active EDDs',
            'description' => 'Budget is approved, awaiting action by the investigator',
            'active'      => 1,
            'featureReq'  => [FeatureACL::CASE_MANAGEMENT],
        ],
        [
            'id'          => 9,
            'class'       => 'CasesInQualification',
            'title'       => 'Cases: In Qualification',
            'description' => 'Includes both active and inactive third parties',
            'active'      => 1,
            'featureReq'  => [FeatureACL::CASE_MANAGEMENT],
        ],
        [
            'id'          => 10,
            'class'       => 'BudgetSubmittedEdd',
            'title'       => 'Cases: Budget Submitted for ADD',
            'description' => 'ADD is waiting for budget approval',
            'active'      => 1,
            'featureReq'  => [FeatureACL::CASE_MANAGEMENT],
        ],
        [
            'id'          => 11,
            'class'       => 'CasesInDraft',
            'title'       => 'Cases: In Draft',
            'description' => 'Investigations created manually that have not been ordered',
            'active'      => 1,
            'featureReq'  => [FeatureACL::CASE_MANAGEMENT],
        ],
        /*
        [
            'class'       => 'AvgDaysToCompleteDdq',
            'title'       => 'Avg. Days to Complete DDQ',
            'description' => ''
        ],
        */
    ];

    /**
     * Init class constructor
     *
     * @param integer $tenantID Current tenantID
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->authDB   = $this->DB->authDB;
        $this->globalDB = $this->DB->globalDB;
        $this->tenantID = $tenantID;
    }

    /**
     * Get list of active tiles by default. Allow inactive tiles to be returned if FALSE is supplied as a param
     *
     * @param bool $onlyActive Set to FALSE if you want to also retrieve inactive tiles
     *
     * @return array
     */
    public function getTiles($onlyActive = true)
    {
        if ($onlyActive) {
            $tiles = array_filter($this->tilesWithTitles, fn($v) => ($v['active'] == 1) && $this->app->ftr->hasAllOf($v['featureReq']));
        } else {
            $tiles = $this->tilesWithTitles;
        }

        // Make sure we are using a non-associative array
        return array_values($tiles);
    }

    /**
     * Retrieve current data in `state` column for user Data Ribbon
     *
     * @return object
     */
    public function getCurrentState()
    {
        $sql = "SELECT `state` FROM {$this->globalDB}.{$this->tbl} \n"
            . "WHERE roleID = :roleID AND userID = :userID AND widgetID = :widgetID;";
        $params = [
            ':roleID'   => $this->app->ftr->role,
            ':userID'   => $this->app->ftr->user,
            ':widgetID' => 7
        ];

        $state = $this->DB->fetchValue($sql, $params);

        return json_decode((string) $state);
    }

    /**
     * Get tile configs specified by user
     *
     * @return array
     */
    protected function getUserTiles()
    {
        $state = $this->getCurrentState();
        $tiles = $state->tiles;

        $userTiles = [];
        foreach ($tiles as $tile) {
            $userTiles[$tile->class] = $tile->active;
        }

        $tiles = array_filter($this->tilesWithTitles, function ($v) use ($userTiles) {
            if (isset($userTiles[$v['class']])) {
                $active = $v['active'] == 1 && $userTiles[$v['class']] == 1;
            } else {
                $active = $v['active'] == 1;
            }

            return ($active && $this->app->ftr->hasAllOf($v['featureReq']));
        });

        return array_values($tiles);
    }

    /**
     * Get widget specific data
     *
     * @return array
     */
    public function getData()
    {
        $tiles = [];
        foreach ($this->getUserTiles() as $k => $tile) {
            $className = '\\Models\\TPM\\Dashboard\\Subs\\DataTiles\\' . $tile['class'];

            if (!class_exists($className)) {
                throw new \RuntimeException('Unable to find class: ' . $tile['class']);
            }

            $currentTile = new $className($this->tenantID);
            $tileData = [
                'id' => $k,
                'tileTitle' => $tile['title']
            ];
            $tiles[$k] = array_merge($tileData, $currentTile->getData());
        }

        unset($currentTile);

        $jsData = [[
            'title'         => 'Data Ribbon',
            'tiles'         => $tiles,
            'description'   => null
        ]];

        return $jsData;
    }
}
