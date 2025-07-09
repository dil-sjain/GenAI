<?php
/**
 * Contains code to route resource center-related requests for the dashboard widget
 *
 * @keywords dashboard, widget, resource center
 */
namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\ResourceCenter as mResourceCenter;
use Models\TPM\Dashboard\DashboardData;

/**
 * Class ResourceCenter
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ResourceCenter extends DashWidgetBase
{
    /**
     * Tooltip codekey
     *
     * @var string translation codekey for tooltip
     */
    protected $ttTrans = 'resource_cntr';

    /**
     * @var null
     */
    protected $dsh_m = null;


    /**
     * ResourceCenter constructor.
     *
     * @param int $tenantID Current client ID
     *
     * @return null
     */
    public function __construct($tenantID)
    {

        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $this->sitePath = \Xtra::conf('cms.sitePath');

        $this->m     = new mResourceCenter($tenantID);
        $this->files = [
            'resourceCenter.html',
            'ResourceCenter.css',
            'ResourceCenter.js',
        ];
    }

    /**
     * Get data for widget
     *
     * @return mixed
     */
    public function getDashboardSubCtrlData()
    {
        $dat = $this->m->getData();
        $dat['description'] = $this->desc;
        return $dat;
    }

    /**
     * persist widget state; updates the state column on the dashboardWidget table
     *
     * @param DashboardData/null $model uses updateStateColumn() on DashboardData model.
     * @param null               $data  data to persist.
     *
     * @return null
     */
    public function persistWidgetState(DashboardData $model = null, $data = null)
    {
        $jsData = json_encode(['expanded' => $data->expanded]);

        $model->updateStateColumn($data->subCtrlClassName, $jsData);
    }
}
