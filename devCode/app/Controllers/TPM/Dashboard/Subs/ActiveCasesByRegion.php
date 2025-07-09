<?php
/**
 * Active Cases by Status Widget
 *
 * @keywords dashboard, widget, active cases by region and type
 */
namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\ActiveCasesByRegion as mActiveCasesByRegion;
use Models\TPM\Dashboard\DashboardData;
use Lib\Legacy\ClientIds;

/**
 * Class Active Cases By Region and Type
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ActiveCasesByRegion extends DashWidgetBase
{

    /**
     * @var string ttTrans tooltip codekey
     */
    protected $ttTrans = 'active_cases_by_region';

    /**
     * @var DashboardData model instance
     */
    protected $dsh_m = null;

    /**
     * Active Cases By Region and Type constructor.
     *
     * @param int $tenantID Current client ID
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $this->m = new mActiveCasesByRegion(
            $this->tenantID,
            \Xtra::app()->session->get('regionTitle'),
            \Xtra::app()->session->get('authUserID')
        );

        $this->files = [
            'activeCasesByRegion.html',
            'ActiveCasesByRegion.css',
            'ActiveCasesByRegion.js',
        ];
    }

    /**
     * Check if there are obstacles that should now allow this widget from loading.
     *
     * @return bool
     */
    public function noObstacles()
    {
        $disableAccess = [
            ClientIds::BAXTER_CLIENTID,
            ClientIds::BAXALTA_CLIENTID,
            ClientIds::BAXALTAQC_CLIENTID,
        ];

        if (in_array((int)$this->tenantID, $disableAccess)) {
            return false;
        }

        return true;
    }

    /**
     * Get data for widget
     *
     * @return mixed
     */
    public function getDashboardSubCtrlData()
    {
        $gotData = $this->m->getData($this->app->session->get('clientID'));
        $regForTitle = $this->app->session->get('customLabels.region');

        $gotData[0]['title'] = "Active Cases By $regForTitle and Type";
        $gotData[0]['description'] = $this->desc;
        return $gotData;
    }

    /**
     * persist widget state; updates the state column on the dashboardWidget table
     *
     * @param DashboardData $model uses updateStateColumn() on DashboardData model.
     * @param bool          $data  data to persist.
     *
     * @return void
     */
    public function persistWidgetState(DashboardData $model = null, $data = true)
    {
        $jsData = json_encode(['expanded' => $data->expanded]);

        $model->updateStateColumn($data->subCtrlClassName, $jsData);
    }
}
