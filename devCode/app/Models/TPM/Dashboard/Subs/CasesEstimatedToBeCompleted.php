<?php
/**
 * Active Cases by Status Widget
 *
 * @keywords dashboard, widget, fGetCasesClosingWithin, fNumCasesClosingWithin
 */
namespace Models\TPM\Dashboard\Subs;

use Models\Globals\Region;
use Models\ThirdPartyManagement\Cases;

/**
 * Class CasesEstimatedToBeCompleted
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class CasesEstimatedToBeCompleted
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
     * @var region model instance
     */
    private $regionModel;

    /**
     * @var array should be converted into code keys
     */
    private $trans = [
        'title'         => 'Cases Estimated to be Completed In',
        'regionalTitle' => 'Region',
    ];


    /**
     * Init class constructor
     *
     * @param int $tenantID Current tenantID
     * @param int $userId   Current $userId
     *
     * @return void
     */
    public function __construct($tenantID, $userId)
    {
        \Xtra::requireInt($tenantID);
        \Xtra::requireInt($userId);
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->tenantID = $tenantID;
        $this->regionModel = new Region($tenantID);
        $this->userId = $userId;
    }

    /**
     * Return data for filling out the widget's template. The main
     * data component getCasesClosingWithin estimates case closing
     * dates by means of a defined estimate for how long a case
     * will take.
     *
     * @return array
     */
    public function getData()
    {
        $estimatedCases = $this->getCasesClosingWithin();
        $regionalTitle  = $this->app->session->get('customLabels.region');

        $jsData = [[
            'title'         => $this->trans['title'],
            'regionalTitle' => (!empty($regionalTitle)) ? $regionalTitle : $this->trans['regionalTitle'],
            'cases'         => $estimatedCases,
            'description' => null,
        ]];

        return $jsData;
    }

    /**
     * Refator of legacy @see public_html/cms/includes/php/dashboard_funcs.php - fGetCasesClosingWithin
     * Get cases that will possibly close soon according to configured date
     * estimates.
     *
     * @return array|bool
     */
    private function getCasesClosingWithin()
    {
        $regionResult = $this->regionModel->getUserRegions($this->userId);
        $retVar = [];

        if (!is_array($regionResult)) {
            return false;
        }

        foreach ($regionResult as $regionRow) {
            // Get the number of cases for each date
            $Num7Day = \Xtra::head($this->numCasesClosingWithin(7, $regionRow['id'], 0));
            $Num14Day = \Xtra::head($this->numCasesClosingWithin(14, $regionRow['id'], 8));
            $Num21Day = \Xtra::head($this->numCasesClosingWithin(21, $regionRow['id'], 15));

            if ($Num7Day['caseCount'] || $Num14Day['caseCount'] || $Num21Day['caseCount']) {
                $datRow = [];
                $datRow['caseRegion']   = $regionRow['name'];
                $datRow['days7']  = $Num7Day['caseCount'];
                $datRow['days14'] = $Num14Day['caseCount'];
                $datRow['days21'] = $Num21Day['caseCount'];

                $retVar[] = $datRow;
            }
        }
        return $retVar;
    }

    /**
     *  Refator of legacy @see public_html/cms/includes/php/dashboard_funcs.php - fNumCasesClosingWithin
     *
     * Get cases that will presumably close within the specified period.
     * The projection is based on the status of the case; must be "between"
     * stageApproved and
     *
     * @param integer $NumOfDays      Days from now: end of specified period
     * @param integer $regionID       Region for which to get cases
     * @param integer $beginNumOfDays Days from now: beginning of specified period
     *
     * @return mixed
     */
    private function numCasesClosingWithin($NumOfDays, $regionID, $beginNumOfDays)
    {
        $szCurDate = date("Y-m-d");
        $dtCurDate = date_create($szCurDate);
        $dtEndDate = $dtCurDate;

        date_modify($dtEndDate, "+$NumOfDays day");

        $szEndDate = date_format($dtEndDate, "Y-m-d");

        $szCurDate = date("Y-m-d");
        $dtCurDate = date_create($szCurDate);

        $dtBeginDate = $dtCurDate;
        date_modify($dtBeginDate, "+$beginNumOfDays day");
        $szBeginDate = date_format($dtBeginDate, "Y-m-d");

        $stageBudgetApproved = Cases::BUDGET_APPROVED;
        $stageAcceptedByInvestigator = Cases::ACCEPTED_BY_INVESTIGATOR;

        //SQL - - - -
        $select = "SELECT COUNT(*) as caseCount FROM cases";
        $wheres
            = [
            'caseStage' => "WHERE (caseStage BETWEEN '$stageBudgetApproved' AND '$stageAcceptedByInvestigator')",
            'regionID' => "AND `region`='$regionID'",
            'dueDate' => "AND (caseDueDate BETWEEN '$szBeginDate' AND '$szEndDate')",
            'clientID' => "AND clientID='{$this->tenantID}'"
            ];

        $result = $this->DB->fetchAssocRows(
            $select . PHP_EOL . implode(PHP_EOL, $wheres)
        );

        return $result;
    }
}
