<?php
/**
 * Created by PhpStorm.
 * User: Rich Jones
 * Date: 4/28/16
 * Time: 10:17 AM
 */

namespace Models\TPM\Report\Excel;

use Models\TPM\Report\Excel\CaseVolumeReportExcel;

/**
 * below class is used to expose internal members for testing only purposes.
 *
 * Class CaseVolumeReportExcelExposed
 *
 * @keywords caseVolumeReport, cvr, analytics, report, bi, business intelligence
 *
 * @package Models\TPM\Report\Excel
 */
#[\AllowDynamicProperties]
class CaseVolumeReportExcelExposed extends CaseVolumeReportExcel
{

    public $authUserEmail;
    public $authUserID;
    public $startDate;
    public $endDate;
    public $period;
    public $arrCid;
}
