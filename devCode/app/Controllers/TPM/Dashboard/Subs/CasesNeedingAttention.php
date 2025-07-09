<?php
/**
 * Management Tools Widget
 *
 * @keywords dashboard, widget
 */
namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\CasesNeedingAttention as mCasesNeedingAttention;
use Models\TPM\Dashboard\DashboardData;

/**
 * Class CasesNeedingAttention
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class CasesNeedingAttention extends DashWidgetBase
{
    /**
     * @var string ttTrans tooltip codekey
     */
    protected $ttTrans = 'cases_need_attn';

    /**
     * CasesNeedingAttention constructor.
     *
     * @param int $tenantID Current client ID
     */

    public function __construct($tenantID)
    {

        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $this->app      = \Xtra::app();
        $this->tenantID = $tenantID;
        $this->m        = new mCasesNeedingAttention($tenantID);
        $this->files    = [
            'CasesNeedingAttention.html',
            'CasesNeedingAttention.css',
            'CasesNeedingAttention.js'
        ];
    }

    /**
     * Get data for widget
     *
     * @return mixed
     *
     * @return object
     */
    public function getDashboardSubCtrlData()
    {
        $caseTypeClientList = $this->getCaseTypeClientList();

        $dat = $this->m->getData(
            $caseTypeClientList,
            $this->app->session->get('userRegion'),
            false
        );
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
