<?php
/**
 * Active Cases by Status Widget
 *
 * @keywords dashboard, widget, ActiveCasesByRegion, fGetCaseTypeClient
 * @keywords fActiveCaseBarGraph, fBarGraphActiveCases
 */
namespace Models\TPM\Dashboard\Subs;

use Lib\Legacy\IntakeFormTypes;
use Lib\Legacy\CaseStage;
use Models\Globals\Region;

/**
 * Class ActiveCasesByRegion
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ActiveCasesByRegion
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    private $DB = null;

    /**
     * @var int $tenantID
     */
    private $tenantID = null;

    /**
     * @var string $clientDB Client db name
     */
    private $clientDB = null;

    /**
     * @var array Text needing translation tokens defined
     */
    private $trans = [];

    /**
     * @var string Description for tooltip
     */
    private $desc = null;
    /**
     * Init class constructor
     *
     * @param integer $tenantID Current tenantID
     * @param string  $regTitle Current region title
     * @param integer $userId   Current user id
     */
    public function __construct($tenantID, $regTitle, $userId)
    {
        \Xtra::requireInt($tenantID);

        $this->app           = \Xtra::app();
        $this->DB            = $this->app->DB;

        $this->tenantID      = (int)$tenantID;
        $this->clientDB      = $this->app->DB->getClientDB($this->tenantID);
        $this->userID        = $userId;
        $this->trans['regionTitle'] = "Active Cases By $regTitle and Type";
    }


    /**
     * Returns widget data
     *
     * @param integer $legacyCliId Legacy client id
     *
     * @return array
     */
    public function getData($legacyCliId)
    {
        $barGraphData     = $this->returnBarGraphData();
        $jqxChartSettings = $this->setJQXChartSettings(
            count($barGraphData['chartData']),
            $barGraphData['maxRegionLen']
        );

        // Make sure IBI and SBI case type labels exist
        $ibiRow = $this->getCaseTypeClient(IntakeFormTypes::DUE_DILIGENCE_IBI, $legacyCliId);
        $sbiRow = $this->getCaseTypeClient(IntakeFormTypes::DUE_DILIGENCE_SBI, $legacyCliId);
        if ($ibiRow) {
            $ibiRow = ['id' => IntakeFormTypes::DUE_DILIGENCE_IBI] + $ibiRow;
        } else {
            $ibiRow = [];
        }
        if ($sbiRow) {
            $sbiRow = ['id' => IntakeFormTypes::DUE_DILIGENCE_SBI] + $sbiRow;
        } else {
            $sbiRow = [];
        }

        $jsData = [[
            'title'             => null, //needs to be added by controller; includes session var
            'description'       => null,
            'ibiRow'            => $ibiRow,
            'sbiRow'            => $sbiRow,
            'barGraphData'      => $barGraphData['chartData'],
            'yAxisHeight'       => $barGraphData['yAxisHeight'],
            'yAxisInterval'     => $barGraphData['yAxisInterval'],
            'chartWidth'        => $jqxChartSettings['chartWidth'],
            'textRotationAngle' => $jqxChartSettings['textRotationAngle'],
            'sitePath'          => \Xtra::conf('cms.sitePath'),
            'noData'            => (count($barGraphData['chartData']) == 0),
        ]];

        return $jsData;
    }

    /**
     * Returns Bar Graph Data for active cases sorted by region and type
     *
     * @note: removing the in_array($caseTypeRow['id'], ...) queries case types < ACCEPTED_BY_REQUESTOR
     *
     * @return array
     */
    private function returnBarGraphData()
    {
        $barGraphData  = $this->activeCaseBarGraph();
        $chartData     = [];
        $yAxisHeight   = 10;
        $yAxisInterval = 2;
        $maxRegionLen  = 0;

        if (isset($barGraphData['regionList'])) {
            foreach ($barGraphData['regionList'] as $regionRow) {
                foreach ($barGraphData['typeList'] as $caseTypeRow) {
                    if (in_array(
                        $caseTypeRow['id'],
                        [IntakeFormTypes::DUE_DILIGENCE_IBI, IntakeFormTypes::DUE_DILIGENCE_SBI]
                    )
                    ) {
                        $count         = $this->barGraphActiveCases($regionRow['id'], $caseTypeRow['id']);
                        $yAxisHeight   = ($count > $yAxisHeight) ? $count : $yAxisHeight;
                        $yAxisInterval = (($yAxisHeight / $yAxisInterval) <= 5)
                            ? $yAxisInterval : floor($yAxisHeight / 5);

                        $chartData[$regionRow['name']][$caseTypeRow['id']] = $count;

                        $tempLength = strlen((string) $regionRow['name']);
                        $maxRegionLen = ($tempLength > $maxRegionLen) ? $tempLength : $maxRegionLen;
                    }
                }
            }
        }

        return [
            'chartData'     => $chartData,
            'yAxisHeight'   => ($yAxisHeight % 2 == 0) ? $yAxisHeight : $yAxisHeight + 1,
            'yAxisInterval' => ($yAxisInterval % 2 == 0) ? $yAxisInterval : $yAxisInterval + 1,
            'maxRegionLen'  => $maxRegionLen,
        ];
    }

    /**
     * Refactor of Legacy @see public_html/cms/includes/php/dashboard_funcs.php fActiveCaseBarGraph()
     *
     * Given manager/user regions (region.id), the region name & id are returned based on the user's type
     *
     * @return multi-dimensional array of active case data
     */
    public function activeCaseBarGraph()
    {
        if ($this->app->ftr->isLegacySuperAdmin() || $this->app->ftr->isLegacyClientAdmin()) {
            $sql  = "SELECT id, name FROM {$this->clientDB}.region\n".
                    "WHERE clientID = :clientID ORDER BY name ASC";
            $bind = [':clientID' => $this->tenantID];
            $rows = $this->app->DB->fetchAssocRows($sql, $bind);

            if (empty($rows)) {
                $bind[':clientID'] = 0;
                $rows = $this->app->DB->fetchAssocRows($sql, $bind);
            }
        } elseif ($this->app->ftr->isLegacyClientManager()) {
            $regions = $this->getRegions();
            if (!empty($regions)) {
                $sql  = "SELECT id, name FROM {$this->clientDB}.region\n".
                        "WHERE clientID = :clientID AND id IN ({$regions})".
                        "ORDER BY name ASC";
                $bind = [':clientID' => $this->tenantID];
                $rows = $this->app->DB->fetchAssocRows($sql, $bind);

                if (empty($rows)) {
                    $bind[':clientID'] = 0;
                    $rows = $this->app->DB->fetchAssocRows($sql, $bind);
                }
            }
        } elseif ($this->app->ftr->isLegacyClientUser()) {
            $region = $this->getRegions();
            if (!empty($region)) {
                $sql = "SELECT id, name FROM {$this->clientDB}.region\n"
                       . "WHERE id = :region ORDER BY name ASC";
                $bind = [':region' => $region];
                $rows = $this->app->DB->fetchAssocRows($sql, $bind);
            }
        }

        return (isset($rows) && !empty($rows))
            ? [
            'regionList' => $rows,
            'typeList'   => $this->caseTypeList(),
            ]: [];
    }

    /**
     * Refactor of Legacy @see public_html/cms/includes/php/dashboard_funcs.php fBarGraphActiveCases()
     *
     * @param integer $regionID   regionID to search
     * @param integer $caseTypeID caseType to search
     *
     * @return integer      Number of cases found
     */
    public function barGraphActiveCases($regionID, $caseTypeID)
    {
        \Xtra::requireInt($regionID);
        \Xtra::requireInt($caseTypeID);
        if (empty($regionID) || empty($caseTypeID)) {
            return 0;
        }


        if ($this->app->ftr->isLegacyClientUser()) {
            $count = $this->app->DB->fetchValue(
                "SELECT count(id)\n".
                "FROM {$this->clientDB}.cases\n"
                . "WHERE region  = :regionID\n"
                . "AND requestor = :userID\n"
                . "AND caseStage < :caseStage\n"
                . "AND caseType  = :caseTypeID\n"
                . "AND clientID  = :clientID\n",
                [
                ':regionID'   => $regionID,
                ':userID'     => $this->userID,
                ':caseStage'  => CaseStage::ACCEPTED_BY_REQUESTOR,
                ':caseTypeID' => $caseTypeID,
                ':clientID'   => $this->tenantID,
                ]
            );
        } else {
            $count = $this->app->DB->fetchValue(
                "SELECT count(id)\n"
                . "FROM {$this->clientDB}.cases\n"
                . "WHERE region  = :regionID\n"
                . "AND caseStage < :caseStage\n"
                . "AND caseType  = :caseTypeID\n"
                . "AND clientID  = :clientID\n",
                [
                ':regionID'   => $regionID,
                ':caseStage'  => CaseStage::ACCEPTED_BY_REQUESTOR,
                ':caseTypeID' => $caseTypeID,
                ':clientID'   => $this->tenantID,
                ]
            );
        }

        return $count;
    }

    /**
     * Refactor of Legacy @see public_html/cms/includes/php/dashboard_funcs.php fActiveCaseBarGraph()
     *
     * @return array caseType ids & names
     */
    public function caseTypeList()
    {
        $typeList = $this->app->DB->fetchAssocRows(
            "SELECT id, name\n"
            . "FROM {$this->clientDB}.caseType\n"
            . "ORDER BY name ASC"
        );

        return (!empty($typeList)) ? $typeList : [];
    }


    /**
     * Trying to avoid horizontal scroll in jqxChart as long as possible
     *
     * @param integer $columnCount  Number of columns displayed in jqxChart
     *
     * @param integer $maxRegionLen length of the longest x-axis label to display
     *
     * @return array of display information to pass to jqxChart settings
     */
    private function setJQXChartSettings($columnCount, $maxRegionLen)
    {
        $containerWidth = 784;
        $charWidth      = 6;
        $labelLength    = ($maxRegionLen * $charWidth);
        $xAxisLength    = ($labelLength * $columnCount);

        if ($xAxisLength < $containerWidth) {
            $textRotationAngle = 0;
        } elseif (($xAxisLength * .75) < $containerWidth) {
            $textRotationAngle = 45;
        } else {
            if ($labelLength > 180) {
                $textRotationAngle = 0;
            } else {
                $textRotationAngle = 90;
                $xAxisLength       = ($columnCount * 40);
            }
        }

        return [
            'chartWidth'        => ($xAxisLength > $containerWidth) ? $xAxisLength : $containerWidth,
            'textRotationAngle' => $textRotationAngle,
        ];
    }


    /**
     * Queries the Region class by userID
     *
     * @return string comma separated list of region ids
     */
    public function getRegions()
    {
        $regionClass = new Region($this->tenantID);
        $regionsList = $regionClass->getUserRegions($this->userID);

        $temp = [];
        foreach ($regionsList as $region) {
            $temp[] = $region['id'];
        }
        $regions = implode(',', $temp);

        return $regions;
    }


    /**
     * Refactor of Legacy {@see public_html/cms/includes/php/funcs.php fGetCaseTypeClient()}
     * Given a case type id, returns its name & abbreviation
     *
     * @param integer $caseTypeID Case type
     * @param integer $clientId   clientProfile.id
     *
     * @return array
     */
    public function getCaseTypeClient($caseTypeID, $clientId)
    {
        \Xtra::requireInt($caseTypeID);
        \Xtra::requireInt($clientId);

        if (empty($caseTypeID)) {
            return 0;
        }

        $row = $this->app->DB->fetchAssocRow(
            "SELECT name, abbrev\n"
            . "FROM {$this->clientDB}.caseTypeClient\n"
            . "WHERE caseTypeID = :caseTypeID AND clientID = :clientID",
            [
            ':caseTypeID' => $caseTypeID,
            ':clientID'   => $clientId,
            ]
        );
        if (empty($row)) {
            $row = $this->app->DB->fetchAssocRow(
                "SELECT name, abbrev\n"
                . "FROM {$this->clientDB}.caseType\n"
                . "WHERE id = :caseTypeID",
                [
                ':caseTypeID' => $caseTypeID,
                ]
            );
        }

        return $row;
    }
}
