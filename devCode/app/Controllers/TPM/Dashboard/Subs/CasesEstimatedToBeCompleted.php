<?php
/**
 * Active Cases by Status Widget
 *
 * @keywords dashboard, widget
 */
namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\CasesEstimatedToBeCompleted as CasesCompletedModel;

/**
 * Class CasesEstimatedToBeCompleted
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class CasesEstimatedToBeCompleted extends DashWidgetBase
{
    /**
     * Tooltip codekey
     *
     * @var string translation codekey for tooltip
     */
    protected $ttTrans = 'cases_est';

    /**
     *
     * @var array Widget file dependencies to load
     */
    protected $files = [
        'CasesEstimatedToBeCompleted.css',
        'CasesEstimatedToBeCompleted.js',
        'casesEstimatedToBeCompleted.html'
    ];

    /**
     * CasesEstimatedToBeCompleted constructor.
     *
     * @param integer $tenantID Client id
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);
        $app = \Xtra::app();
        $this->app = $app;
        $this->log = $app->log;
        $this->tenantID = (int)$tenantID;
        $this->baseData = new CasesCompletedModel($tenantID, $app->ftr->user);
    }

    /**
     * Return data for the the widget.
     *
     * @return object
     */
    public function getDashboardSubCtrlData()
    {
        $dat = $this->baseData->getData();

        return (object)[
            'title' => 'Cases Estimated to Be Completed In',
            'dat' => $dat,
            'description' => $this->desc
        ];
    }
}
