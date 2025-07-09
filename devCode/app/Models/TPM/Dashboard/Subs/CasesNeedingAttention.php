<?php
/**
 * Cases Needing Attention Widget
 *
 * @keywords dashboard, widget
 */

namespace Models\TPM\Dashboard\Subs;

use Lib\Legacy\CaseStage;
use Models\ThirdPartyManagement\Cases;
use Models\Globals\Department;
use Models\Globals\Region;

/**
 * Class CasesNeedingAttention
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class CasesNeedingAttention
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
     * @var int tenantID
     */
    private $tenantID = null;

    /**
     * Init class constructor
     *
     * @param int $tenantID Current tenant ID
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->tenantID = $tenantID;
    }

    /**
     * Get widget specific data.
     *
     * @param array   $caseTypeClientList List of case types used by client
     * @param integer $sessReg            Current user's current region
     * @param boolean $noTitle            Simplify wrapper for returned data; for the dashing map scenario
     *
     * @return array|null
     */
    public function getData($caseTypeClientList, $sessReg, $noTitle = false)
    {
        \Xtra::requireInt($sessReg);
        $link = "/cms/case/casehome.sec?tname=casefolder&id=";
        $cases = [];

        // verify user has access to case management feature
        if (!$this->app->ftr->has(\Feature::CASE_MANAGEMENT)) {
            return null;
        }

        [$casesNeedingAttention, $casesNeedingAttentionCnt] = $this->getCaseData($caseTypeClientList, $sessReg);

        foreach ($casesNeedingAttention as $case) {
            $href = $link . $case->dbid;
            array_push($cases, [
                'caseNum'   => $case->casenum,
                'caseName'  => mb_substr((string) $case->casename, 0, 40),
                'caseStage' => substr((string) $case->stage, 0, 30),
                'iso2'      => $case->iso2,
                'caseLink'      => $href
                ]);
        }

        $title = "There are $casesNeedingAttentionCnt cases that need attention.";
        $title .= ($casesNeedingAttentionCnt == 0) ? '' : ' Click to View';

        if ($noTitle) {
            return $cases;
        }

        $jsData = [[
            'title' => $title,
            'cases' => $cases,
            'description' => null
        ]];

        return $jsData;
    }

    /**
     * Get the case data
     *
     * @param array   $caseTypeClientList As in Case folder data
     * @param integer $sessRegion         Current user's current region
     *
     * @return array
     */
    private function getCaseData($caseTypeClientList, $sessRegion)
    {

        $attnStages = [CaseStage::QUALIFICATION, CaseStage::REQUESTED_DRAFT, CaseStage::UNASSIGNED, CaseStage::AWAITING_BUDGET_APPROVAL, CaseStage::COMPLETED_BY_INVESTIGATOR];

        $stageFilter = 'c.caseStage IN (' . join(',', $attnStages) . ')';
        $casesNeedingAttentionCnt = $this->getCases(
            $this->tenantID,
            $caseTypeClientList,
            $sessRegion,
            '',
            true,
            $stageFilter
        );
        $casesNeedingAttention = $this->getCases(
            $this->tenantID,
            $caseTypeClientList,
            $sessRegion,
            "ORDER BY c.caseName ASC",
            false,
            $stageFilter
        );

        return [$casesNeedingAttention, $casesNeedingAttentionCnt];
    }

    /**
     * Get specified cases for the client and logged-in user, region; add
     * peripheral options for data format and scope of results.
     *
     * @param integer $clientId           Client ID
     * @param array   $caseTypeClientList As in Case folder data
     * @param integer $sessRegion         User region for this session of app use
     * @param string  $orderLimit         SQL order clause to be tacked on
     * @param bool    $returnCnt          whether to return just a count
     * @param null    $filter             Extra filter to tack on
     *
     * @return array $rtn Case data
     */
    private function getCases(
        $clientId,
        $caseTypeClientList,
        $sessRegion,
        $orderLimit = '',
        $returnCnt = false,
        $filter = null
    ) {
        $dbCls = $this->DB;

        // Assign constants to vars for convenience
        $qualificationStage = CaseStage::QUALIFICATION;
        $deletedStage = CaseStage::DELETED;

        // limit to third party id
        $flds = $this->getFields($returnCnt);

        // Get Cases
        $sql = "SELECT $flds FROM cases AS c "
            . "LEFT JOIN region AS r ON r.id = c.region "
            . "LEFT JOIN {$this->DB->authDB}.users AS u ON u.userid = c.requestor ";

        if (!$returnCnt) {
            if (\Xtra::requireInt($sessRegion)) {
                $countryOn = '(cn.legacyCountryCode = c.caseCountry '
                    . 'OR cn.codeVariant = c.caseCountry OR cn.codeVariant2 = c.caseCountry) '
                    . 'AND (cn.countryCodeID > 0 OR cn.deferCodeTo IS NULL)';
            } else {
                $countryOn = 'cn.legacyCountryCode = c.caseCountry';
            }
            $tmpTbl = "tmpCaseType";
            $this->buildTmpTblStr('tmpCaseType', $caseTypeClientList);
            $sql .= "LEFT JOIN caseStage AS stg ON stg.id = c.caseStage "
                . "LEFT JOIN {$this->DB->isoDB}.legacyCountries AS cn ON $countryOn "
                . "LEFT JOIN $tmpTbl AS typ ON typ.id = c.caseType ";
        }

        $sql .= "WHERE c.clientID = '$clientId' "
            . "AND c.caseStage <> $deletedStage AND c.caseStage < "
            . Cases::TRAINING_LOWER_BOUND . " ";

        if ($filter) {
            $sql .= "AND $filter";
        }

        if ($this->app->ftr->isLegacyClientManager()) {
            $Region = new Region($this->app->ftr->tenant);
            if (!$Region->hasAllRegions($this->app->ftr->user, $this->app->ftr->role)) {
                $regions = $Region->getUserRegions($this->app->ftr->user, $this->app->ftr->role);
                $userRegions = \Xtra::extractByKey('id', $regions);
                $csv  = implode(',', $userRegions);
                $sql .= " AND c.region IN({$csv})";
            }

            $Department = new Department($this->app->ftr->tenant);
            if (!$Department->hasAllDepartments($this->app->ftr->user, $this->app->ftr->role)) {
                $departments = $Department->getUserDepartments($this->app->ftr->user, $this->app->ftr->role);
                $userDepartments = array_map(
                    fn($el) => $el['id'],
                    $departments
                );
                $csv  = empty($userDepartments) ? '0' : '0,' . implode(',', $userDepartments);
                $sql .= " AND c.dept IN({$csv})";
            }
        } elseif ($this->app->ftr->isLegacyClientUser()) {
            $sql .= " AND (u.id = {$this->app->ftr->user} "
                 .  " OR (c.region = '{$sessRegion}' AND c.caseStage = $qualificationStage AND c.requestor = '')) ";
        }

        if ($orderLimit) {
            $sql .= ' ' . $orderLimit;
        }

        if ($returnCnt) {
            $rtn = $dbCls->fetchValue($sql);
        } else {
            $rtn = $dbCls->fetchObjectRows($sql);
        }
        return $rtn;
    }

    /**
     * Build temporary table for the getCases query.
     *
     * @param string $tmpTbl             Name of temp table to create
     * @param array  $caseTypeClientList As in Case folder data
     *
     * @return string $tblSql Query string
     */
    private function buildTmpTblStr($tmpTbl, $caseTypeClientList)
    {

        $tblSql = "CREATE TEMPORARY TABLE IF NOT EXISTS $tmpTbl ("
            . "id int NOT NULL DEFAULT '0',"
            . "name varchar(50) NOT NULL DEFAULT ''"
            . ")";
        $this->DB->query($tblSql);
        $this->DB->query("TRUNCATE TABLE $tmpTbl");

        foreach ($caseTypeClientList as $k => $v) { //rid session reference
            //Is more security needed here? Also, get rid of
            //the session reference.
            $this->DB->query("INSERT INTO $tmpTbl SET id='"
                . $k . "', name='" . $v . "'");
        }

        return $tblSql;
    }

    /**
     * Get the fields portion for the getCases query.
     *
     * @param boolean $returnCnt Type of query; count-only or not
     *
     * @return string
     */
    private function getFields($returnCnt)
    {

        if ($returnCnt) {
            $flds = 'COUNT(c.id) AS cnt';
        } else {
            if (\Xtra::requireInt($sessRegion)) {
                $countryField = 'IFNULL(cn.displayAs, cn.legacyName)';
            } else {
                $countryField = 'cn.legacyName';
            }
            $flds = 'c.id AS dbid, '
                . 'c.userCaseNum AS casenum, '
                . 'c.caseName AS casename, '
                . 'typ.name AS casetype, '
                . 'stg.name AS stage, '
                . 'u.userName AS requester, '
                . 'r.name AS region, '
                . 'c.caseCountry AS iso2, '
                . $countryField . ' AS country, '
                . 'c.tpID';
        }
        return $flds;
    }
}
