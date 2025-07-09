<?php
/**
 * Dashboard Widget - Data Ribbon controller
 *
 */

namespace Controllers\TPM\Dashboard\Subs;

use \Models\TPM\Dashboard\Subs\DataRibbon as DataRibbonModel;
use \Models\TPM\Dashboard\DashboardData;

/**
 * Class DataRibbon
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class DataRibbon extends DashWidgetBase
{
    /**
     * tooltip codekey
     *
     * @var string translation codekey for tooltip
     */
    protected $ttTrans = 'ribbon';

    /**
     * DataRibbon constructor.
     *
     * @param int $tenantID ID of the current client
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);

        $this->m     = new DataRibbonModel($tenantID);
        $this->files = [
            'dataRibbon.html',
            'DataRibbon.css',
            'DataRibbon.js'
            ];
    }

    /**
     * Return widget data
     *
     * @return array
     */
    public function getDashboardSubCtrlData()
    {

        $dat = $this->m->getData();
        $dat['description'] = $this->desc;
        return $dat;
    }

    /**
     * This method sends state data passed in from the widget to be persisted in the DB. It can be overridden by the
     * widget controller if additional data needs to be stored or data needs to be modified before being stored. It will
     * take the
     *
     * @param DashboardData|null $model uses updateStateColumn() on DashboardData model.
     * @param object             $data  data to persist.
     *
     * @return bool|string True if successful, otherwise an error string.
     * @throws \RuntimeException
     */
    public function persistWidgetState(DashboardData $model = null, $data = null)
    {
        $state = $this->m->getCurrentState();

        if (is_object($data)) {
            $state->expanded = $data->expanded;
            $jsData = json_encode($state);
        } else {
            throw new \RuntimeException('Unable to update widget data.');
        }

        $model->updateStateColumn($data->subCtrlClassName, $jsData);

        return true;
    }
}
