<?php
/**
 * Created by:  Rich Jones
 * Create Date: 2016-04-19
 *
 * Model: CaseVolumeReportExcel
 *  - This model produces the excel spreadsheet.
 */

namespace Models\TPM\Report\Excel;

use Lib\Support\Xtra;

use Models\ThirdPartyManagement\ClientProfile; // used to bring in constants.
use Lib\Support\Test\FileTestDir;

/**
 * below class is used to produce the Case Volume Report excel spreadsheet.
 *
 * Class CaseVolumeReportExcel
 *
 * @keywords caseVolumeReport, cvr, analytics, report, bi, business intelligence
 *
 * @package Models\TPM\Report\Excel
 */
#[\AllowDynamicProperties]
class CaseVolumeReportExcel
{
    /**
     * @var null
     */
    private $DB = null;

    /**
     * @var string
     */
    private $clientDB = '';

    /**
     * @var int
     */
    private $clientID = 0;

    /**
     * class Instance of application logger
     * @var null
     */
    private $log = null;

    /**
     * @var null
     */
    private $authDB = null;

    /**
     * @var null
     */
    private $globalDB = null;

    /**
     * server root dir for the case volume report.
     */
    public const FILE_PATH = "/var/local/bgProcess/";

    /**
     * server base file name for the case volume report.
     */
    public const REPORT_NAME = "caseVolumeReport";

    /**
     * directory separator.
     */
    public const DS = "/";

    /**
     *  24 hours in seconds
     */
    public const HOURS_IN_SECONDS_24 = 86400;

    /**
     * @var
     */
    private $excel;

    /**
     * @var
     */
    protected $authUserEmail;

    /**
     * @var
     */
    protected $authUserID;

    /**
     * @var
     */
    protected $startDate;

    /**
     * @var
     */
    protected $endDate;

    /**
     * @var
     */
    protected $period;

    /**
     * @var array
     */
    protected $arrCid = [];

    /**
     * @var
     */
    private $dateFormat;

    /**
     * @var
     */
    private $groupBy;

    /**
     * @var
     */
    private $client;

    /**
     * @var used to display date range.
     */
    private $dateRangeText;


    /**
     * caseVolumeReportExcel constructor.
     *
     * @param int $clientID client id.
     */
    public function __construct($clientID)
    {
        $app = Xtra::app();
        $this->log = $app->log;
        $this->DB = $app->DB;
        $this->authDB = $this->DB->authDB;
        $this->globalDB = $this->DB->globalDB;
        $this->clientID = (int)$clientID;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
    }

    /**
     * loads parameters locally.
     *
     * @param int    $authUserID    authorized user id
     * @param string $authUserEmail authorized users email.
     * @param object $params        parameters from .js client.
     *
     * @return void
     */
    private function loadParams($authUserID, $authUserEmail, $params)
    {
        $this->authUserID = $authUserID;
        $this->authUserEmail = $authUserEmail;

        $this->startDate = $params->startDate;
        $this->endDate = $params->endDate;
        $this->period = $params->period;
        $this->arrCid = json_decode((string) $params->arrCid);

        // sort the cid's...
        sort($this->arrCid);

        $this->validateParams();
    }

    /**
     * validate parameters
     *
     * @throws \Exception
     *
     * @return void
     */
    private function validateParams()
    {
        //validate dates
        if ($this->endDate <= $this->startDate) {
            throw new \Exception("End date is less than or equal to start date.");
        }

        //validate period
        if ($this->period != 'm' && $this->period != 'y') {
            throw new \Exception("Period must be 'y' or 'm'.");
        }

        //convert period for use by report.
        if ($this->period == 'm') {
            $this->dateFormat = '%Y-%m';
            $this->groupBy = 'month';
        } else {
            $this->dateFormat = '%Y';
            $this->groupBy = 'year';
        }

        // create where clause and display date range.
        $this->dateRangeText = "$this->startDate through $this->endDate";
    }

    /**
     * initialize report run.
     *
     * @param int    $authUserID    authorized user id
     * @param string $authUserEmail authorized users email.
     * @param object $params        parameters from .js client.
     *
     * @return void
     */
    public function initReport($authUserID, $authUserEmail, $params)
    {
        $this->loadParams($authUserID, $authUserEmail, $params);

        // PhpSpreadsheet does caching differently, default cache is in-memory: https://phpspreadsheet.readthedocs.io/en/latest/topics/memory_saving/
        // TODO: review cache settings if needed
        
        // $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_in_memory_gzip;
        // PHPExcel_Settings::setCacheStorageMethod($cacheMethod);

        //Create new PhpSpreadsheet object
        $this->excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Set properties

        // consolidate to getProperties() method.
        $prop = $this->excel->getProperties();

        $prop->setCreator("Third Party Risk Management - Compliance");
        $prop->setLastModifiedBy($this->authUserEmail);
        $prop->setTitle("Client Summary");
        $prop->setSubject("Volume Report");
        $prop->setDescription("Client summary totals.");
    }

    /**
     * create the excel spreadsheet
     *
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     *
     * @return void
     */
    private function buildReport()
    {
        // reference to the excel object.
        $excel = $this->excel;

        // container for all totals.
        $results = [];

        // globals.
        $GTCompanies = count($this->arrCid);
        $allPeriods = [];
        $wipSum = [];
        $caseType = [];
        $caseTypeAll = ['GDC','OSI','EDD','ADD'];

        // build results array by cid
        $this->buildResultByCid($results);

        // derive 'type' totals
        $this->deriveTypeTotals($results, $caseTypeAll, $caseType, $allPeriods);

        // derive 'wip' totals
        $this->deriveWipTotals($results, $caseTypeAll, $wipSum);

        // Setup the dynamic columns
        $totalPeriods = array_unique($allPeriods);
        sort($totalPeriods);


        // Sheet 0 /////////////////////////////////////////////////
        $excel->setActiveSheetIndex(0);

        // prepare the spreadsheet
        $excel->getActiveSheet()->getStyle('A1')
            ->getBorders()->getRight()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('A2')
            ->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $startCol = 1;
        $col = $startCol;
        foreach ($totalPeriods as $period) {
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 1, $period);
            $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $colNxt = $col + 1;
            $colStrNxt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNxt);
            $excel->setActiveSheetIndex()
                ->mergeCells($colStr .'1:' . $colStrNxt .'1');
            // set count and amount columns for periods
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 2, 'Count');
            $excel->getActiveSheet()->setCellValueByColumnAndRow($colNxt, 2, 'Amount');
            $col = $col + 2;
        }
        // WIP
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 1, 'WIP');
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $colNxt = $col + 1;
        $colStrNxt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNxt);
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 2, 'Count');
        $excel->getActiveSheet()->setCellValueByColumnAndRow($colNxt, 2, 'Amount');
        $excel->setActiveSheetIndex()
            ->mergeCells($colStr .'1:' . $colStrNxt .'1');
        $col = $col + 2;
        // Total
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 1, 'Total');
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $colNxt = $col + 1;
        $colStrNxt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNxt);
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 2, 'Count');
        $excel->getActiveSheet()->setCellValueByColumnAndRow($colNxt, 2, 'Amount');
        $excel->setActiveSheetIndex()
            ->mergeCells($colStr .'1:' . $colStrNxt .'1');
        // Date range and note
        $excel->getActiveSheet()->setCellValueByColumnAndRow(2, 12, 'Date Range: '
            . $this->dateRangeText);
        $excel->setActiveSheetIndex()->mergeCells('C12:J12');

        $excel->getActiveSheet()->getStyle('A1:' . $colStr . '1')
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $excel->getActiveSheet()->getStyle('A2:' . $colStrNxt . '2')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($startCol);
        $excel->getActiveSheet()->getStyle($colStr . '1:' . $colStrNxt . '1')
            ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle($colStr . '2:' . $colStrNxt . '2')->getBorders()
            ->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // case Type columns and Data loading
        $row = 3;
        $sumTotCases = [];
        $periodTotal = [];
        foreach ($caseTypeAll as $type) {
            $sumTotCases[$type] = ['Count' => 0, 'Summary' => 0];
            $col = 1;
            $excel->getActiveSheet()->setCellValueByColumnAndRow(0, $row, $type);
            foreach ($totalPeriods as $period) {
                if (!isset($periodTotal[$period])) {
                    $periodTotal[$period] = ['Count' => 0, 'Summary' => 0];
                }
                if (isset($caseType[$type][$period]['Count'])) {
                    $cnt = $caseType[$type][$period]['Count'];
                    $sumTotCases[$type]['Count'] = $sumTotCases[$type]['Count'] + $cnt;
                    $periodTotal[$period]['Count'] += $cnt;
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $cnt);
                } else {
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, 0);
                }
                $col++;
                if (isset($caseType[$type][$period]['Summary'])) {
                    $amt = $caseType[$type][$period]['Summary'];
                    $sumTotCases[$type]['Summary'] = $sumTotCases[$type]['Summary'] + $amt;
                    $periodTotal[$period]['Summary'] = $periodTotal[$period]['Summary'] + $amt;
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $amt);
                } else {
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, 0);
                }
                $col++;
            }
            $row++;
        }
        $col = 1;
        foreach ($periodTotal as $period => $total) {
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $total['Count']);
            $col++;
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $total['Summary']);
            $col++;
        }
        // wip loading
        $row = 3;
        $sumTotWipCount = 0;
        $sumTotWipSum = 0;

        foreach ($caseTypeAll as $type) {
            if (isset($wipSum[$type]['Count'])) {
                $countSum = $wipSum[$type]['Count'];
                $sumTotCases[$type]['Count'] += $countSum;
                $sumTotWipCount += $countSum;
                $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $countSum);
            } else {
                $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, 0);
            }
            $col++;
            if (isset($wipSum[$type]['Summary'])) {
                $amountSum = $wipSum[$type]['Summary'];
                $sumTotCases[$type]['Summary'] += $amountSum;
                $sumTotWipSum += $amountSum;
                $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $amountSum);
            } else {
                $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, 0);
            }
            $col--;
            $row++;
        }
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $sumTotWipCount);
        $col++;
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $sumTotWipSum);

        // Total Columns
        $row = 3;
        $col++;
        $sumTotalCount = 0;
        $sumTotalSum = 0;
        foreach ($sumTotCases as $key => $value) {
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $value['Count']);
            $sumTotalCount = $sumTotalCount + $value['Count'];
            $excel->getActiveSheet()
                ->setCellValueByColumnAndRow(($col + 1), $row, $value['Summary']);
            $sumTotalSum = $sumTotalSum + $value['Summary'];
            $row++;
        }
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $sumTotalCount);
        $excel->getActiveSheet()->setCellValueByColumnAndRow(($col + 1), $row, $sumTotalSum);

        // dollar formatting of columns
        $col = 2;
        $row = 3;
        foreach ($periodTotal as $period => $total) {
            $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col); // Period money format
            $excel->getActiveSheet()->getStyle($colStr . $row . ':' . $colStr . ($row+5))
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $col = $col + 2;
        }
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);  // Wip money format
        $excel->getActiveSheet()->getStyle($colStr . $row . ':' . $colStr . ($row+5))
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        $col = $col + 2;
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col); // Total money format
        $excel->getActiveSheet()->getStyle($colStr . $row . ':' . $colStr . ($row+5))
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        $col = $col + 2;

        $highestColumn = $excel->getActiveSheet()->getHighestColumn(); //e.g., G
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn); //e.g., 6

        for ($column =1; $column < $highestColumnIndex; $column++) {
            $excel->getActiveSheet()
                ->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        $excel->getActiveSheet()->getStyle('A7:' . $colStr . '7')
            ->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Rename Sheet
        $excel->getActiveSheet()->setTitle('Summary');

        // Sheet 1 /////////////////////////////////////////////////
        // Create a new worksheet, after the default sheet

        $excel->createSheet();
        $excel->setActiveSheetIndex(1);
        $excel->getActiveSheet()->setTitle('Client Detail');
        $excel->getActiveSheet()->SetCellValue('B1', 'ID');
        $row = 1;
        $col = 2;
        foreach ($totalPeriods as $period) {
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 1, $period);
            $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $colNxt = $col + 1;
            $colStrNxt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNxt);
            $excel->setActiveSheetIndex(1)->mergeCells($colStr .'1:' . $colStrNxt .'1');
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 2, 'Count');
            $excel->getActiveSheet()->setCellValueByColumnAndRow($colNxt, 2, 'Amount');
            $col = $col + 2;
        }
        // Wip
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 1, 'WIP');
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $colNxt = $col + 1;
        $colStrNxt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNxt);
        $excel->setActiveSheetIndex(1)->mergeCells($colStr .'1:' . $colStrNxt .'1');
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 2, 'Count');
        $excel->getActiveSheet()->setCellValueByColumnAndRow($colNxt, 2, 'Amount');
        $col = $col + 2;

        // Total
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 1, 'Total');
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $colNxt = $col + 1;
        $colStrNxt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNxt);
        $excel->setActiveSheetIndex(1)->mergeCells($colStr .'1:' . $colStrNxt .'1');
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, 2, 'Count');
        $excel->getActiveSheet()->setCellValueByColumnAndRow($colNxt, 2, 'Amount');
        $col = $col + 2;
        $highestCol = $excel->getActiveSheet()->getHighestColumn();
        $excel->getActiveSheet()->getStyle('A1' . ':' . $highestCol . 1)
            ->getFont()->setBold(true);

        $excel->getActiveSheet()->getStyle('A1:' . $colStr . '1')
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $excel->getActiveSheet()->getStyle('A2:' . $colStrNxt . '2')
            ->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $excel->getActiveSheet()->freezePane('A3');

        $excel->getActiveSheet()->getColumnDimension('A')->setWidth(30);
        $row = 3; // Starting input row for spreadsheet
        $col = 0;
        $totalClients = 0;
        $periodTotals = [];
        $wipCountTot = 0;
        $wipAmtTot = 0;
        $totCountTot = 0;
        $totAmtTot = 0;
        foreach ($results as $cid => $value) {
            $totalClients++;
            $col = 0;
            $clientID = $cid;
            $clientName = $value['name'];

            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $clientName);
            $excel->getActiveSheet()->setCellValueByColumnAndRow(($col + 1), $row, $clientID);
            $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $excel->getActiveSheet()
                ->getStyle($colStr . $row .':' . $highestCol . $row)->getBorders()
                ->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $row++;
            foreach ($caseTypeAll as $type) {
                $clientTotalCount = 0;
                $clientTotalAmt = 0;
                $col = 0;
                $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $type);
                $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $excel->getActiveSheet()->getStyle($colStr . $row)
                    ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $excel->getActiveSheet()
                    ->setCellValueByColumnAndRow(($col + 1), $row, $clientID);
                $col = 2;
                foreach ($totalPeriods as $period) {
                    $count = 0;
                    $amount = 0;
                    if (isset($results[$clientID]['type'][$type])) {
                        foreach ($results[$clientID]['type'][$type] as $key => $value) {
                            if ($value[$this->groupBy] == $period) {
                                if (!isset($periodTotals[$period])) {
                                    $periodTotals[$period] = [];
                                }
                                $count = $results[$clientID]['type'][$type][$key]['Count'];
                                $clientTotalCount = $clientTotalCount + $count;
                                $amount = $results[$clientID]['type'][$type][$key]['Summary'];
                                $clientTotalAmt = $clientTotalAmt + $amount;
                                if (isset($periodTotals[$period]['Count'])) {
                                    $periodTotals[$period]['Count']
                                        = $periodTotals[$period]['Count'] + $count;
                                } else {
                                    $periodTotals[$period]['Count'] = $count;
                                }
                                if (isset($periodTotals[$period]['Summary'])) {
                                    $periodTotals[$period]['Summary']
                                        = $periodTotals[$period]['Summary'] + $amount;
                                } else {
                                    $periodTotals[$period]['Summary'] = $amount;
                                }
                            }
                        }
                    }
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $count);
                    $col++;
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $amount);
                    $col++;
                }
                //Client WIP Summary
                if (isset($results[$clientID]['wip'][$type])) {
                    $wipCount = ($results[$clientID]['wip'][$type][0]['Count'] > 0) ?
                        $results[$clientID]['wip'][$type][0]['Count'] : 0;
                    $clientTotalCount = $clientTotalCount + $wipCount;
                    $wipAmount = ($results[$clientID]['wip'][$type][0]['Summary'] > 0) ?
                        $results[$clientID]['wip'][$type][0]['Summary'] : 0;
                    $clientTotalAmt = $clientTotalAmt + $wipAmount;
                    $wipCountTot = $wipCountTot + $wipCount;   // Spreadsheet totals
                    $wipAmtTot = $wipAmtTot + $wipAmount;
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $wipCount);
                    $col++;
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $wipAmount);
                    $col++;
                } else {
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, 0);
                    $col++;
                    $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, 0);
                    $col++;
                }
                //Client Totals for all periods
                $excel->getActiveSheet()
                    ->setCellValueByColumnAndRow($col, $row, $clientTotalCount);
                $col++;
                $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $clientTotalAmt);
                $row++;
                $totCountTot = $totCountTot + $clientTotalCount; // Overall totals
                $totAmtTot = $totAmtTot + $clientTotalAmt;
            }
        }
        $excel->getActiveSheet()
            ->setCellValueByColumnAndRow(0, $row, 'Total Companies: ' . $totalClients);
        $col = 2;
        foreach ($totalPeriods as $period) {
            $total = $periodTotals[$period];
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $total['Count']);
            $col++;
            $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $total['Summary']);
            $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $excel->getActiveSheet()->getStyle($colStr . 3 . ':' . $colStr . $row)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $col++;
        }
        // Bottom row wip total
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $wipCountTot);
        $col++;
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $wipAmtTot);
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $excel->getActiveSheet()->getStyle($colStr . 3 . ':' . $colStr . $row)
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        $col++;

        // Bottom row overall total
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $totCountTot);
        $col++;
        $excel->getActiveSheet()->setCellValueByColumnAndRow($col, $row, $totAmtTot);
        $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $excel->getActiveSheet()->getStyle($colStr . 3 . ':' . $colStr . $row)
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);

        $excel->getActiveSheet()->getStyle('A' . $row . ':' . $highestCol . $row)->getBorders()
            ->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('A' . $row . ':' . $highestCol . $row)->getBorders()
            ->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()
            ->getStyle('A' . $row . ':' . $highestCol . $row)->getFont()->setBold(true);

        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol); //e.g., 6
        for ($column =1; $column < $highestColumnIndex; $column++) {
            $excel->getActiveSheet()
                ->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }



        // Sheet 2 /////////////////////////////////////////////////
        $GTCountgdc = 0;
        $GTAmountgdc = 0;
        $GTCountosi = 0;
        $GTAmountosi = 0;
        $GTCountedd = 0;
        $GTAmountedd = 0;
        $GTCountadd = 0;
        $GTAmountadd = 0;
        $excel->createSheet();
        $excel->setActiveSheetIndex(2);
        $excel->getActiveSheet()->setTitle('Client Total');
        //Templated items
        $excel->getActiveSheet()->getStyle('A1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('B1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('C1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->SetCellValue('C1', 'Client');

        $excel->getActiveSheet()->SetCellValue('D1', 'GDC');
        $excel->setActiveSheetIndex(2)->mergeCells('D1:E1');
        $excel->getActiveSheet()->getStyle('E1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->SetCellValue('F1', 'OSI');
        $excel->setActiveSheetIndex(2)->mergeCells('F1:G1');
        $excel->getActiveSheet()->getStyle('G1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->SetCellValue('H1', 'EDD');
        $excel->setActiveSheetIndex(2)->mergeCells('H1:I1');
        $excel->getActiveSheet()->getStyle('I1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->SetCellValue('J1', 'ADD');
        $excel->setActiveSheetIndex(2)->mergeCells('J1:K1');
        $excel->getActiveSheet()->getStyle('K1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->SetCellValue('L1', 'Total');
        $excel->setActiveSheetIndex(2)->mergeCells('L1:M1');
        $excel->getActiveSheet()->getStyle('M1')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('D1:M1')
            ->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('A1:M1')->getFont()->setBold(true);

        $excel->getActiveSheet()->SetCellValue('A2', 'Active');
        $excel->getActiveSheet()->getStyle('A2')->getFont()->setBold(true);
        $excel->getActiveSheet()->getStyle('A2')->getBorders()->getRight()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('A2')->getBorders()->getBottom()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->SetCellValue('B2', 'Client');
        $excel->getActiveSheet()->SetCellValue('C2', 'ID');

        // Count Total Columns
        $excel->getActiveSheet()->SetCellValue('D2', 'Count');
        $excel->getActiveSheet()->SetCellValue('E2', 'Total');
        $excel->getActiveSheet()->SetCellValue('F2', 'Count');
        $excel->getActiveSheet()->SetCellValue('G2', 'Total');
        $excel->getActiveSheet()->SetCellValue('H2', 'Count');
        $excel->getActiveSheet()->SetCellValue('I2', 'Total');
        $excel->getActiveSheet()->SetCellValue('J2', 'Count');
        $excel->getActiveSheet()->SetCellValue('K2', 'Total');
        $excel->getActiveSheet()->SetCellValue('L2', 'Count');
        $excel->getActiveSheet()->SetCellValue('M2', 'Total');

        $excel->getActiveSheet()->getStyle('A1:M1')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $excel->getActiveSheet()->getStyle('A2:M2')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row = 3; // Starting input row for spreadsheet
        foreach ($results as $cid => $value) {
            $clientID = $cid;
            $clientName = $value['name'];
            $clientStatus = $value['status'];
            $clientFstatus = ($clientStatus == 'active' ? 'Y' : 'N');
            $clientTotalCount = 0;
            $clientTotalAmt = 0;
            if (!array_key_exists('GDC', $value['type'])) {
                //Set zero values for non case types
                $excel->getActiveSheet()->setCellValueByColumnAndRow(3, $row, 0);
                $excel->getActiveSheet()->setCellValueByColumnAndRow(4, $row, 0);
                $excel->getActiveSheet()->getStyleByColumnAndRow(4, $row)
                    ->getNumberFormat()
                    ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            }
            if (!array_key_exists('OSI', $value['type'])) {
                $excel->getActiveSheet()->setCellValueByColumnAndRow(5, $row, 0);
                $excel->getActiveSheet()->setCellValueByColumnAndRow(6, $row, 0);
                $excel->getActiveSheet()->getStyleByColumnAndRow(6, $row)
                    ->getNumberFormat()
                    ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            }
            if (!array_key_exists('EDD', $value['type'])) {
                $excel->getActiveSheet()->setCellValueByColumnAndRow(7, $row, 0);
                $excel->getActiveSheet()->setCellValueByColumnAndRow(8, $row, 0);
                $excel->getActiveSheet()->getStyleByColumnAndRow(8, $row)
                    ->getNumberFormat()
                    ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            }
            if (!array_key_exists('ADD', $value['type'])) {
                $excel->getActiveSheet()->setCellValueByColumnAndRow(9, $row, 0);
                $excel->getActiveSheet()->setCellValueByColumnAndRow(10, $row, 0);
                $excel->getActiveSheet()->getStyleByColumnAndRow(10, $row)
                    ->getNumberFormat()
                    ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            }

            foreach ($value['type'] as $key => $value) {  // case Types
                $totalAmt = 0;
                $totalCount = 0;
                foreach ($value as $key2 => $value2) {  // Add up yearly's
                    $totalAmt = $totalAmt + $value2['Summary'];
                    $totalCount = $totalCount + $value2['Count'];
                }
                $clientTotalCount = $clientTotalCount + $totalCount;
                $clientTotalAmt = $clientTotalAmt + $totalAmt;
                $caseType = $key;
                switch ($caseType) {
                    case 'GDC':
                        $GTCountgdc = $GTCountgdc + $totalCount;
                        $GTAmountgdc = $GTAmountgdc + $totalAmt;
                        $colCount = 3;
                        $colTot = 4;
                        break;
                    case 'OSI':
                        $GTCountosi = $GTCountosi + $totalCount;
                        $GTAmountosi = $GTAmountosi + $totalAmt;
                        $colCount = 5;
                        $colTot = 6;
                        break;
                    case 'EDD':
                        $GTCountedd = $GTCountedd + $totalCount;
                        $GTAmountedd = $GTAmountedd + $totalAmt;
                        $colCount = 7;
                        $colTot = 8;
                        break;
                    case 'ADD':
                        $GTCountadd = $GTCountadd + $totalCount;
                        $GTAmountadd = $GTAmountadd + $totalAmt;
                        $colCount = 9;
                        $colTot = 10;
                        break;
                    default:
                        $colCount = 0;
                        $colTot = 0;
                }
                if ($colCount > 0) {
                    $excel->getActiveSheet()
                        ->setCellValueByColumnAndRow($colCount, $row, $totalCount);
                    $excel->getActiveSheet()
                        ->setCellValueByColumnAndRow($colTot, $row, $totalAmt);
                    $excel->getActiveSheet()->getStyleByColumnAndRow($colTot, $row)
                        ->getNumberFormat()
                        ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
                }
            }
            $excel->getActiveSheet()
                ->setCellValueByColumnAndRow(11, $row, $clientTotalCount); // Overall count
            $excel->getActiveSheet()
                ->setCellValueByColumnAndRow(12, $row, $clientTotalAmt);   // Overall Amount
            $excel->getActiveSheet()->getStyleByColumnAndRow(12, $row)
                ->getNumberFormat()
                ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $excel->getActiveSheet()->setCellValueByColumnAndRow(0, $row, $clientFstatus);
            if ($clientFstatus == 'Y') {
                $color = '90ee90';
            } else {
                $color = 'b22222';
            }
            $excel->getActiveSheet()->getStyle('A' . $row)->getFill()
                ->getStartColor()->setARGB($color);
            $excel->getActiveSheet()->getStyle('A' . $row)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $excel->getActiveSheet()->getStyle('A' . $row)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $excel->getActiveSheet()->setCellValueByColumnAndRow(1, $row, $clientName);
            $excel->getActiveSheet()->setCellValueByColumnAndRow(2, $row, $clientID);
            $row++;
        }

        $highestRow = $excel->getActiveSheet()->getHighestRow();
        $highestColumn = $excel->getActiveSheet()->getHighestColumn(); //e.g., G
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn); //e.g., 6

        //Set Column Grand Totals
        $excel->getActiveSheet()
            ->setCellValueByColumnAndRow(1, $highestRow + 1, "Total Companies: $GTCompanies");
        $excel->getActiveSheet()
            ->setCellValueByColumnAndRow(3, $highestRow + 1, "$GTCountgdc");
        $excel->getActiveSheet()->setCellValueByColumnAndRow(4, $highestRow + 1, "$GTAmountgdc");
        $excel->getActiveSheet()->getStyleByColumnAndRow(4, $highestRow + 1)
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        $excel->getActiveSheet()->setCellValueByColumnAndRow(5, $highestRow + 1, "$GTCountosi");
        $excel->getActiveSheet()->setCellValueByColumnAndRow(6, $highestRow + 1, "$GTAmountosi");
        $excel->getActiveSheet()->getStyleByColumnAndRow(6, $highestRow + 1)
            ->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        $excel->getActiveSheet()->setCellValueByColumnAndRow(7, $highestRow + 1, "$GTCountedd");
        $excel->getActiveSheet()->setCellValueByColumnAndRow(8, $highestRow + 1, "$GTAmountedd");
        $excel->getActiveSheet()->getStyleByColumnAndRow(8, $highestRow + 1)
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        $excel->getActiveSheet()->setCellValueByColumnAndRow(9, $highestRow + 1, "$GTCountadd");
        $excel->getActiveSheet()
            ->setCellValueByColumnAndRow(10, $highestRow + 1, "$GTAmountadd");
        $excel->getActiveSheet()->getStyleByColumnAndRow(10, $highestRow + 1)
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
        // worksheet Grand total
        $wsGTCount = $GTCountgdc + $GTCountosi + $GTCountedd + $GTCountadd;
        $wsGTAmnt = $GTAmountgdc + $GTAmountosi + $GTAmountedd + $GTAmountadd;
        $excel->getActiveSheet()->setCellValueByColumnAndRow(11, $highestRow + 1, "$wsGTCount");
        $excel->getActiveSheet()->setCellValueByColumnAndRow(12, $highestRow + 1, "$wsGTAmnt");
        $excel->getActiveSheet()->getStyleByColumnAndRow(12, $highestRow + 1)
            ->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);

        $highRow = $highestRow + 1;
        $excel->getActiveSheet()->getStyle('A' . $highRow . ':M' . $highRow)
            ->getFont()->setBold(true);
        $excel->getActiveSheet()->getStyle('A' . $highRow . ':M' . $highRow)
            ->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $excel->getActiveSheet()->getStyle('A' . $highRow . ':M' . $highRow)
            ->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $excel->getActiveSheet()->duplicateStyle(
            $excel->getActiveSheet()->getStyle('A2'),
            'B2:' . $highestColumn . 2
        ); //copy style set in first column to the rest of the row

        for ($column =0; $column < $highestColumnIndex; $column++) {
            $excel->getActiveSheet()
                ->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        // Change the active sheet back to the first
        $excel->setActiveSheetIndex(0);
    }

    /**
     * save the report to disk.
     *
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     *
     * @return void
     */
    private function saveReport()
    {
        $objWriter = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($this->excel);

        // derive the Mode dir
        $cvrDir = static::deriveFilePath() . \Xtra::app()->mode;

        // if Mode dir does not exist, create it.
        if (!file_exists($cvrDir)) {
            mkdir($cvrDir);
        }

        // derive report dir
        $cvrDir .= CaseVolumeReportExcel::DS . 'caseVolumeReport';

        // if report dir does not exist, create it.
        if (!file_exists($cvrDir)) {
            mkdir($cvrDir);
        }

        // remove reports if older than 24 hours.
        $this->removePriorReports($cvrDir);

        // derive the file location.
        $fileLocation = $cvrDir .
                        CaseVolumeReportExcel::DS .
                        CaseVolumeReportExcel::REPORT_NAME . '-' . $this->authUserID . '.xlsx';

        // save the file.
        $objWriter->save($fileLocation);
    }

    /**
     * used as part of a PHPUNIT test.
     * - if $_GLOBALS['PHPUNIT'] is empty then the self::FILE_PATH will be used.
     * - if $_GLOBALS['PHPUNIT'] is NOT empty then a file will be placed in a testing
     *   directory within the users application directory.
     *   Example location: <Your PA Dir>/tmp_test_files/var/tmp/caseVolumeReport
     *
     * @return string
     */
    public static function deriveFilePath()
    {
        if (empty($GLOBALS['PHPUNIT'])) {
            $filePath = self::FILE_PATH;
        } else {
            $filePath = FileTestDir::getPath(self::FILE_PATH);
        }

        return $filePath;
    }

    /**
     * delete reports that are older than 24 hours.
     *
     * @param string $path contains dir where reports are located.
     *
     * @return void
     */
    private function removePriorReports($path)
    {
        if ($handle = opendir($path)) {
            while (false !== ($file = readdir($handle))) {
                if ($file !== '..' && $file !== '.') {
                    $file = $path . "/" . $file;
                    $mt = filemtime($file);
                    if ((time() - $mt) > CaseVolumeReportExcel::HOURS_IN_SECONDS_24) {
                        if (preg_match('.caseVolumeReport.', $file)) {
                            unlink($file);
                        }
                    }
                }
            }
        }
    }

    /**
     * wrapper method for buildReport and saveReport methods
     *
     * @return void
     */
    public function createReport()
    {
        $this->buildReport();
        $this->saveReport();
    }

    /**
     * getter that retrieves the physical file name of the excel spreadsheet.
     *
     * @return mixed
     *
     * @return void
     */
    public function getReportLocation()
    {
        return CaseVolumeReportExcel::REPORT_NAME;
    }

    /**
     * derives 'type' totals; used later during the buildReport() method
     *
     * @param array &$results    contains all reporting totals
     * @param array $caseTypeAll list of valid case types.
     * @param array &$caseType   array of case types to be used during the buildReport() method
     * @param array &$allPeriods array containing all periods to be reported.
     *
     * @return array $results
     * @return array $caseType
     * @return array $allPeriods
     */
    private function deriveTypeTotals(&$results, $caseTypeAll, &$caseType, &$allPeriods)
    {
        foreach ($results as $cid => $value) {
            foreach ($value['type'] as $key => $value) {
                if (in_array($key, $caseTypeAll)) {
                    foreach ($value as $key2 => $value2) { // yearly or monthly Totals
                        $p = $value2[$this->groupBy]; // period
                        $cnt = $value2['Count'];
                        $sum = $value2['Summary'];
                        if (isset($caseType[$key][$p]['Count'])) {
                            $caseType[$key][$p]['Count'] += $cnt;
                        } else {
                            $caseType[$key][$p]['Count'] = $cnt;
                        }
                        if (isset($caseType[$key][$p]['Summary'])) {
                            $caseType[$key][$p]['Summary'] += $sum;
                        } else {
                            $caseType[$key][$p]['Summary'] = $sum;
                        }
                        if (!in_array($p, $allPeriods)) {
                            $allPeriods[] = $p;
                        }
                    }
                }
            }
        }
    }

    /**
     * derives 'wip' totals; used later during the buildReport() method
     *
     * @param array &$results    contains all reporting totals
     * @param array $caseTypeAll list of valid case types.
     * @param array &$wipSum     array contains 'wip' counts and sums.
     *
     * @return array $results
     * @return array $wipSum
     */
    private function deriveWipTotals(&$results, $caseTypeAll, &$wipSum)
    {
        foreach ($results as $cid => $value) {
            foreach ($value['wip'] as $key => $value) {
                if (in_array($key, $caseTypeAll)) {
                    foreach ($value as $key2 => $value2) {
                        $cnt = $value2['Count'];
                        $sum = $value2['Summary'];

                        // WIP
                        if (isset($wipSum[$key]['Count'])) {
                            $wipSum[$key]['Count'] += $cnt;
                        } else {
                            $wipSum[$key]['Count'] = $cnt;
                        }
                        if (isset($wipSum[$key]['Summary'])) {
                            $wipSum[$key]['Summary'] += $sum;
                        } else {
                            $wipSum[$key]['Summary'] = $sum;
                        }
                    }
                }
            }
        }
    }

    /**
     * populate the results array with 'wip' sum data
     *
     * @param array &$results contains all reporting totals
     *
     * @throws \Exception
     *
     * @return $results
     */
    private function buildResultByCid(&$results)
    {
        foreach ($this->arrCid as $cid) {
            if ($this->sqlClientIsValid($cid)) {
                $this->sqlGetClient($cid);
                $caseStageComplete = $this->sqlGetCaseStageCompete($cid);
                $caseTypes = $this->getCaseTypes($cid);

                foreach ($caseTypes as $abbrev => $ids) {
                    $caseStageCompleteSum = $this->sqlGetCaseStageCompleteSum($cid, $ids, $caseStageComplete);
                    $results[$cid]['type'][$abbrev] = $caseStageCompleteSum;
                    $results[$cid]['name'] = $this->client->clientName;
                    $results[$cid]['status'] = $this->client->status;

                    $caseStageWIP = $this->sqlGetCaseStageWIP();
                    $caseStageWIPSum = $this->sqlGetCaseStageWIPSum($cid, $ids, $caseStageWIP);
                    $results[$cid]['wip'][$abbrev] = $caseStageWIPSum;
                }
            }
        }
    }

    /////////////////////////////////////////
    // helper routines.
    /////////////////////////////////////////

    /**
     * sql method determines if $cid exists in the g_caseVolumeAccess table.
     *
     * @param int $cid client id
     *
     * @throws \Exception
     *
     * @return bool
     */
    private function sqlClientIsValid($cid)
    {
        $cid = intval($cid);

        // pdo data bindings
        $bindData = [
            ':cid'  => $cid,
            ':auid' => $this->authUserID
        ];

        $sql = "SELECT clientID \n"
             . "  FROM $this->globalDB.g_caseVolumeAccess \n"
             . " WHERE userID = :auid \n"
             . "   AND clientID = :cid LIMIT 1 ";

        $row = $this->DB->fetchObjectRows($sql, $bindData);

        if (!$row) {// data expected, but not found...
            throw new \Exception("could not find client id. (a)");
        }

        if ($row[0]->clientID != $cid) {
            return false;
        }

        return true;
    }

    /**
     * sql method sets $this->client with status and client name, if client id exists in the .clientDBlist table.
     *
     * @param int $cid client id
     *
     * @throws \Exception
     *
     * @return void
     */
    private function sqlGetClient($cid)
    {
        $cid = intval($cid);

        // pdo data bindings
        $bindData = [
            ':cid' => $cid,
        ];

        $sql = "SELECT status, clientName \n"
             . "  FROM $this->authDB.clientDBlist \n"
             . " WHERE clientID = :cid LIMIT 1 ";

        $row = $this->DB->fetchObjectRows($sql, $bindData);

        if (!$row) {// data expected, but not found...
            throw new \Exception("could not find client id. (b)");
        }

        $this->client = $row[0];
    }

    /**
     * sql method returns caseStage id's, if found.
     *
     * @param int $cid client id
     *
     * @return string
     *
     * @throws \Exception
     */
    private function sqlGetCaseStageCompete($cid)
    {
        $caseStageComplete = '"Completed by Investigator", "Accepted by Requester",'
                           . '"Case Closed", "Case Archived"';

        $tmp = [];

        $cid = intval($cid);

        $tmpClientDB = $this->DB->getClientDB($cid);

        $sql = "SELECT id \n"
             . "  FROM $tmpClientDB.caseStage \n"
             . " WHERE name IN($caseStageComplete) ";

        $rows = $this->DB->fetchObjectRows($sql);

        if (!$rows) {// data expected, but not found...
            throw new \Exception("could not find case Stage rows.");
        }

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                $tmpKey = "$key$value";
                $tmp[$tmpKey] = $value;
            }
        }

        return $tmp;
    }

    /**
     * method for returning specific key value pairs by $cid
     *
     * @param int $cid client id
     *
     * @return array
     */
    private function getCaseTypes($cid)
    {
        if ($cid == ClientProfile::BIOMET_CLIENTID) {
            $caseTypes = ['GDC' => '55', 'OSI' => '11,14', 'EDD' => '12,15', 'ADD' => '13'];
        } else {
            $caseTypes = ['GDC' => '55', 'OSI' => '11', 'EDD' => '12', 'ADD' => '13'];
        }

        return $caseTypes;
    }

    /**
     * derives summed budgetAmount for a $cid, $ids, and $caseStageComplete
     *
     * @param int   $cid               client id
     * @param int   $ids               case type id
     * @param array $caseStageComplete case complete text
     *
     * @return mixed
     */
    private function sqlGetCaseStageCompleteSum($cid, $ids, array $caseStageComplete)
    {

        $cid = intval($cid);

        // get client db.
        $tmpClientDB = $this->DB->getClientDB($cid);

        // used in where clause
        $strCaseStageComplete = (string)null;
        $strIds = (string)null;

        // pdo data bindings
        $bindData = [
            ':startDate'  => $this->startDate,
            ':endDate'    => $this->endDate,
            ':cid'        => $cid,
        ];

        // derive $strCaseStageWIPs and pdo data bindings for $caseStageComplete
        $i=0;
        foreach ($caseStageComplete as $key => $value) {
            $tmpKey = ':1'.$key;
            $bindData[$tmpKey] = $value;
            if ($i>0) {
                $strCaseStageComplete .= ",";
            }
            $strCaseStageComplete .= $tmpKey;
            $i++;
        }

        // derive $strIds and pdo data bindings for $ids
        // this can have more than one value; thus, make it an array.
        $ids = explode(',', $ids);
        if (count($ids) > 1) {
            $i=0;
            foreach ($ids as $value) {
                $tmpKey = ':2id'.$value;
                $bindData[$tmpKey] = $value;
                if ($i>0) {
                    $strIds .= ",";
                }
                $strIds .= $tmpKey;
                $i++;
            }
        } else {
            $ids = intval($ids[0]);
            $tmpKey = ':2id'.$ids;
            $bindData[$tmpKey] = $ids;
            $strIds .= $tmpKey;
        }

        $sql = "SELECT count(id) AS Count, SUM(budgetAmount) AS Summary, \n"
            . "        DATE_FORMAT(caseCreated, '$this->dateFormat') AS `$this->groupBy`  \n"
            . "   FROM $tmpClientDB.cases \n"
            . "  WHERE caseType IN($strIds) \n"
            . "    AND caseCreated BETWEEN :startDate AND :endDate \n"
            . "    AND clientID = :cid \n"
            . "    AND caseStage IN($strCaseStageComplete) GROUP BY `$this->groupBy` \n"
            . "  ORDER BY `$this->groupBy` ASC ";

        $row = $this->DB->fetchObjectRows($sql, $bindData, MYSQLI_ASSOC);

        // convert stdClass to array.
        $row = json_decode(json_encode($row), true);

        return $row;
    }

    /**
     * get list of id's by $caseStageWIP
     *
     * @throws \Exception
     *
     * @return string
     */
    private function sqlGetCaseStageWIP()
    {
        $tmp = [];

        $caseStageWIP = '"Budget Approved", "Accepted by Investigator"';

        $sql = "SELECT id \n"
             . "  FROM $this->clientDB.caseStage \n"
             . " WHERE name IN($caseStageWIP)";

        $rows = $this->DB->fetchObjectRows($sql, MYSQLI_ASSOC);

        if (!$rows) {// data expected, but not found...
            throw new \Exception("could not find case Stage WIP rows.");
        }

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                $tmpKey = "$key$value";
                $tmp[$tmpKey] = $value;
            }
        }

        return $tmp;
    }

    /**
     * derives summed budgetAmount for a $cid, $ids, and $caseStageWIPs
     *
     * @param int   $cid           client id
     * @param int   $ids           case type id
     * @param array $caseStageWIPs case complete text
     *
     * @return array
     */
    private function sqlGetCaseStageWIPSum($cid, $ids, array $caseStageWIPs)
    {

        $cid = intval($cid);

        // get client db.
        $tmpClientDB = $this->DB->getClientDB($cid);

        // used in where clause
        $strCaseStageWIPs = (string)null;
        $strIds = (string)null;

        // pdo data bindings
        $bindData = [
            ':cid' => $cid,
        ];

        // derive $strCaseStageWIPs and pdo data bindings for $strCaseStageWIPs
        $i=0;
        foreach ($caseStageWIPs as $key => $value) {
            $tmpKey = ':1'.$key;
            $bindData[$tmpKey] = $value;
            if ($i>0) {
                $strCaseStageWIPs .= ",";
            }
            $strCaseStageWIPs .= $tmpKey;
            $i++;
        }

        // derive $strIds and pdo data bindings for $ids
        // this can have more than one value; thus, make it an array.
        $ids = explode(',', $ids);
        if (count($ids) > 1) {
            $i=0;
            foreach ($ids as $value) {
                $tmpKey = ':2id'.$value;
                $bindData[$tmpKey] = $value;
                if ($i>0) {
                    $strIds .= ",";
                }
                $strIds .= $tmpKey;
                $i++;
            }
        } else {
            $ids = intval($ids[0]);
            $tmpKey = ':2id'.$ids;
            $bindData[$tmpKey] = $ids;
            $strIds .= $tmpKey;
        }

        $sql = "SELECT count(id) as Count, SUM(budgetAmount) as Summary"
            . "   FROM $tmpClientDB.cases "
            . "  WHERE caseType IN($strIds)"
            . "    AND caseStage IN ($strCaseStageWIPs)"
            . "    AND clientID=:cid";


        $row = $this->DB->fetchObjectRows($sql, $bindData, MYSQLI_ASSOC);

        // convert stdClass to array.
        $row = json_decode(json_encode($row), true);

        return $row;
    }
}
