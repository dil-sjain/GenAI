<?php
/**
* Interface for Dashboard Widgets
*
* @keywords dashboard, widget
*/

namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\DashboardData;

/**
* Interface DashInterface
*
* @package Controllers\TPM\Dashboard\Subs
*/
interface DashInterface
{
    /**
    * returns widget specific data
    *
    * @return mixed
    */
    public function getDashboardSubCtrlData();

    /**
    * returns widget specific files
    *
    * @return mixed
    */
    public function getFilesList();

    /**
    * persist widget state; updates the state column on the dashboardWidget table
    *
    * @param DashboardData/null $model uses updateStateColumn() on DashboardData model.
    * @param null               $data  data to persist.
    *
    * @return null
    */
    public function persistWidgetState(DashboardData $model = null, $data = null);
}
