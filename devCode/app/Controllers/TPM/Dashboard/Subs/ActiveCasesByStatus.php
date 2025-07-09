<?php
/**
 * Active Cases by Status Widget
 *
 * @keywords dashboard, widget
 */
namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\ActiveCasesByStatus as mActiveCasesByStatus;
use Models\TPM\Dashboard\DashboardData;

/**
 * Class ActiveCasesByStatus
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ActiveCasesByStatus extends DashWidgetBase
{
    /**
     * @var string ttTrans tooltip codekey
     */
    protected $ttTrans = 'active_cases_by_status';

    /**
     * @var null
     */
    protected $dsh_m = null;

    /**
     * ActiveCasesByStatus constructor.
     *
     * @param int $tenantID Current client ID
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);

        $this->m     = new mActiveCasesByStatus($tenantID, \Xtra::app()->session->get('authUserID'));
        $this->files = [
            'activeCasesByStatus.html',
            'ActiveCasesByStatus.css',
            'ActiveCasesByStatus.js'
        ];
    }

    /**
     * Get data for widget
     *
     * @return mixed
     */
    public function getDashboardSubCtrlData()
    {
        $title = 'Active Cases by Status';
        $sessRegTitle = \Xtra::app()->session->get('customLabels.region');
        $regionTitle = $sessRegTitle ?: 'Region';
        $dat = $this->m->getData($title, $regionTitle, $this->app->session->get('clientID'));
        $dat['description'] = $this->desc;
        return $dat;
    }


    /**
     * persist widget state; updates the state column on the dashboardWidget table
     *
     * @param DashboardData|null $model uses updateStateColumn() on DashboardData model.
     * @param null               $data  data to persist.
     *
     * @return void
     */
    public function persistWidgetState(DashboardData $model = null, $data = null)
    {
        $jsData = json_encode(['expanded' => $data->expanded]);

        $model->updateStateColumn($data->subCtrlClassName, $jsData);
    }
}
