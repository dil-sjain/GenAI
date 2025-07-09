<?php
/**
 * Commonly Used Analytics Reports Widget
 *
 * @keywords dashboard, widget
 */

namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\CommonlyUsedAnalyticsReports as mCommonlyUsedAnalyticsReports;
use Models\TPM\Dashboard\DashboardData;

/**
 * Class CommonlyUsedAnalyticsReports
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class CommonlyUsedAnalyticsReports extends DashWidgetBase
{
    /**
     * @var string ttTrans tooltip codekey
     */
    protected $ttTrans = 'common_analytics';

    /**
     * CommonlyUsedAnalyticsReports constructor.
     *
     * @param int $tenantID Current tenant ID
     */
    public function __construct($tenantID)
    {

        \Xtra::requireInt($tenantID);

        parent::__construct($tenantID);
        $this->m     = new mCommonlyUsedAnalyticsReports($this->tenantID);
        $this->files = [
            'commonlyUsedAnalyticsReports.html',
            'CommonlyUsedAnalyticsReports.css',
            'CommonlyUsedAnalyticsReports.js'
            ];
    }

    /**
     * Get data for widget
     *
     * @return mixed
     */
    public function getDashboardSubCtrlData()
    {
        $sess = \Xtra::app()->session;
        $dat = $this->m->getData($sess->get('customLabels.region'), $sess->get('authUserType'));
        $dat['description'] = $this->desc;
        return $dat;
    }


    /**
     * persist widget state; updates the state column on the dashboardWidget table
     *
     * @param mixed $dashModel uses updateStateColumn() on DashboardData model.
     * @param null  $data      data to persist.
     *
     * @return null
     */
    public function persistWidgetState(DashboardData $dashModel = null, $data = null)
    {
        $jsData = json_encode(['expanded' => $data->expanded]);

        $dashModel->updateStateColumn($data->subCtrlClassName, $jsData);
    }
}
