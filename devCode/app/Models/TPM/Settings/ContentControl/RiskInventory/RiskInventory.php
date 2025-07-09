<?php
/**
 * Access data required to support Risk Inventory maintenance
 */

namespace Models\TPM\Settings\ContentControl\RiskInventory;

use Models\TPM\RiskModel\RiskModelMap;
use Models\TPM\RiskModel\RiskModels;
use Models\TPM\RiskModel\RiskTier;
use Models\TPM\RiskModel\RiskModelTier;
use Models\TPM\RiskModel\RiskFactor;
use Models\TPM\RiskModel\RiskAssessment;
use Models\TPM\RiskModel\CpiScore;
use Models\Globals\Geography;
use Models\TPM\CustomField;
use Models\TPM\CustomSelectList;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\IntakeForms\Legacy\OnlineQuestions;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\RiskModel;
use Models\TPM\CaseTypeClientBL;
use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\LogData; // audit log
use Lib\Validation\Validator\CsvIntList;
use Lib\CaseCostTimeCalc;
use Lib\Traits\SplitDdqLegacyID;
use Lib\Support\ForkProcess;
use Models\TPM\IntakeForms\Response\InFormRspnsCountries;
use Models\TPM\TpProfile\TpProfile;
use Models\ThirdPartyManagement\Admin\TP\Risk\UpdateScoresData;
use Models\TPM\RiskModel\RiskModelRole;

/**
 * Access data required to support Risk Inventory maintenance
 *
 * @keywords risk inventory, settings, risk model
 */
#[\AllowDynamicProperties]
class RiskInventory
{
    use SplitDdqLegacyID;

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var integer TPM tenant id
     */
    protected $tenantID = 0;

    /**
     * @var MySqlPdo instance
     */
    protected $DB = null;

    /**
     * @var string Directory name for pid file for bg process during test and publish
     */
    protected $tmpGenDir = '';

    /**
     * @var object Text translation object
     */
    protected $trans = null;

    /**
     * @var Geography Class instance
     */
    protected Geography $geo;

    /**
     * Initialize instance properties
     *
     * @param integer $tenantID TPM tenant id
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->tenantID = $tenantID;
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->trans = $this->app->trans;
        $this->tmpGenDir = sys_get_temp_dir() . '/rm-gen';
        if (!is_dir($this->tmpGenDir)) {
            mkdir($this->tmpGenDir, 0755);
        }
    }

    /**
     * Get defined tiers and avilable tiers
     *
     * @param integer $rmodelID     Risk model id
     * @param boolean $deleteUnused Remove risk tiers that are not used in any model for this tenant
     *
     * @return array defined tiers (tierDefs) and available tiers (riskTiers), excluding defined tiers
     */
    public function getTiers($rmodelID, $deleteUnused = false)
    {
        if (empty($rmodelID)) {
            return [];
        }
        // get all riskTiers
        $tMdl = new RiskTier($this->tenantID);
        if ($deleteUnused) {
            $tMdl->deleteUnused();
        }

        $map = $available = $used = [];
        $tCols = ['id', "tierName AS 'name'", "tierColor AS 'bg'", "tierTextColor AS 'fg'"];
        $recs = $tMdl->selectMultiple($tCols, [], 'ORDER BY tierName', true);
        foreach ($recs as $rec) {
            $map[$rec['id']] = $rec;
        }

        // get defined model tiers in descending order by score
        $mtMdl = new RiskModelTier($this->tenantID);
        $mtCols = ['tier', 'threshold', 'scope'];
        $recs = $mtMdl->selectMultiple($mtCols, ['model' => $rmodelID], 'ORDER BY threshold DESC');
        foreach ($recs as $rec) {
            if (array_key_exists($rec['tier'], $map)) {
                $used[] = [
                    'id' => $rec['tier'],
                    'name' => $map[$rec['tier']]['name'],
                    'bg' => $map[$rec['tier']]['bg'],
                    'fg' => $map[$rec['tier']]['fg'],
                    'thresh' => $rec['threshold'],
                    'scope' => $rec['scope'],
                ];
                unset($map[$rec['tier']]);
            }
        }

        // build available tiers
        foreach ($map as $id => $rec) {
            $available[] = [
                'id' => $rec['id'],
                'name' => $rec['name'],
                'bg' => $rec['bg'],
                'fg' => $rec['fg'],
            ];
        }

        // return definedTiers and availableTiers
        return [
            'tierDefs' => $used,
            'riskTiers' => $available,
        ];
    }

    /**
     * Upsert tier and model tier.  Assumes values have already been validated.
     *
     * @param integer $rmodelID   riskModels.id
     * @param array   $tierValues New tier form inputs
     *
     * @return array of errors or empty array if no errors
     */
    public function upsertTier($rmodelID, $tierValues)
    {
        if (empty($rmodelID)) {
            return null;
        }
        // $tierID, $tierName, $tierFg, $tierBg, $scopeID, $startAt
        extract($tierValues); // compacted in controller validateTierForm
        $tMdl = new RiskTier($this->tenantID);
        $html = '<div class="risktier" style="color:' . $tierFg
            . ';background-color:' . $tierBg . '">' . $tierName . '</div>';
        $setVals = [
            'tierName' => $tierName,
            'tierColor' => $tierBg,
            'tierTextColor' => $tierFg,
            'tierHTML' => $html,
        ];
        $isNew = false;
        if (empty($tierID)) {
            // insert tier - model sets clientID
            $tierID = $tMdl->insert($setVals);
            $isNew = true;
        } else {
            // update tier
            $rtn = $tMdl->updateByID($tierID, $setVals);
        }
        // insert or update model tier
        $mtMdl = new RiskModelTier($this->tenantID);
        if (!$isNew) {
             $isNew = ($tierID !== $mtMdl->selectValue('tier', ['model' => $rmodelID, 'tier' => $tierID]));
        }
        $setVals = [
            'threshold' => $startAt,
            'scope' => $scopeID,
        ];
        if ($isNew) {
            // insert model tier -  sets clientID
            $setVals['tier']  = $tierID;
            $setVals['model'] = $rmodelID;
            $mtMdl->insert($setVals);
        } else {
            // update
            $mtMdl->update($setVals, ['tier' => $tierID, 'model' => $rmodelID]);
        }
    }

    /**
     * Ensure bottom model tier threshold is 0.  Assumes rmodelID has been validated and is setup status.
     *
     * @param integer $rmodelID riskModels.id
     *
     * @return void
     */
    public function forceZeroTier($rmodelID)
    {
        if (empty($rmodelID)) {
            return;
        }
        $mtMdl = new RiskModelTier($this->tenantID);
        $tierRec = $mtMdl->selectOne([], ['model' => $rmodelID], 'ORDER BY threshold ASC');
        if ($tierRec['threshold'] !== 0) {
            $mtMdl->update(['threshold' => 0], ['model' => $tierRec['model'], 'tier' => $tierRec['tier']]);
        }
    }

    /**
     * Convert string integer values to integers in serialized factor data
     * It's possible this method could have more general application
     *
     * @param mixed   $factor Raw, serialied factor data from riskFactor record
     * @param boolean $isSer  DO NOT CHANGE. used to unserialize on entry to recursive method.
     *
     * @return mixed unserialized, converted data
     */
    private function convertFactorInts(mixed $factor, $isSer = true)
    {
        if ($isSer === true && is_string($factor)) {
            $factor = unserialize($factor);
        }
        if (is_object($factor)) {
            $newObj = new \stdClass();
            foreach ($factor as $prop => $value) {
                $newObj->{$prop} = $this->convertFactorInts($value, false);
            }
            return $newObj;
        } elseif (is_array($factor)) {
            $newAry = [];
            foreach ($factor as $key => $value) {
                $newAry[$key] = $this->convertFactorInts($value, false);
            }
            return $newAry;
        }
        if (is_string($factor) && preg_match('/^\d+$/', $factor)) {
            $factor = (int)$factor;
        }
        return $factor;
    }

    /**
     * Get sparse factor data for use in front end
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return array Sparse riskFactor data of specified type or []
     */
    public function getFactorData($rmodelID)
    {
        if (empty($rmodelID)) {
            return [];
        }
        $factorData = [];
        $cols = ['component', 'id', 'factor', 'ddqRef'];
        $where = ['model' => $rmodelID]; // get them all
        if ($data = (new RiskFactor($this->tenantID))->selectMultiple($cols, $where)) {
            foreach ($data as $dat) {
                // unserialize and convert string "integers" to real integers
                // This will leave the old decimal CPI values as strings
                $convertedFactor = $this->convertFactorInts($dat['factor']);
                if ($dat['component'] == 'ddq') {
                    // ddq factor can and often does have multiple riskFactor records
                    if (!array_key_exists('ddq', $factorData)) {
                        $factorData['ddq'] = [];
                    }
                    $factorData['ddq'][$dat['ddqRef']]
                        = ['id' => $dat['id'], 'factor' => $convertedFactor];
                } else {
                    $factorData[$dat['component']]
                        = ['id' => $dat['id'], 'factor' => $convertedFactor];
                }
            }
        }
        return $factorData;
    }

    /**
     * Insert or update riskFactor record
     *
     * @param integer $rmodelID  riskModel.id
     * @param string  $component riskFactor.component (type of factor)
     * @param mixed   $factor    Array or object of factor values to serialize
     * @param string  $ddqRef    LegacyID of scored questionnaire
     * @param array   $hrcs      Array of highest risk countries
     *
     * @return array sparse factorData
     */
    public function upsertFactor($rmodelID, $component, mixed $factor, $ddqRef = '', $hrcs = [])
    {
        if (empty($rmodelID) || empty($component) || empty($factor)) {
            return [];
        }
        if (!in_array($component, ['tpcat', 'cpi', 'ddq', 'cufld'])) {
            return $this->getFactorData($rmodelID);
        }
        $rfMdl = new RiskFactor($this->tenantID);
        $whereValues = ['model'     => $rmodelID, 'component' => $component, 'ddqRef'    => $ddqRef];
        if ($recID = $rfMdl->selectValue('id', $whereValues)) {
            $rfMdl->updateByID($recID, ['factor' => serialize($factor)]);
        } else {
            $setVals = [
                'model' => $rmodelID,
                'component' => $component,
                'ddqRef' => $ddqRef,
                'factor' => serialize($factor),
            ];
            $rfMdl->insert($setVals);
        }
        $values = [];
        // saving checkboxes values
        if ($component == 'cpi') {
            $values["countriesFrom3p"] = null;
            $values["countriesFrommodal"] = null;
            $values["countriesFromddq"] = null;
            $values["countriesFromcarc"] = null;
            foreach ($hrcs as $highestRiskCountry) {
                $values["countriesFrom$highestRiskCountry"] = true;
            }
        }
        $values['updated'] = date('Y-m-d H:i:s');
        // update updated column of riskModel
        (new RiskModels($this->tenantID))->updateByID($rmodelID, $values);

        return $this->getFactorData($rmodelID);
    }

    /**
     * Get alphabetical lookup list of iso/name country pairs
     *
     * @return array iso/name elements
     */
    public function getCountries()
    {
        if (empty($this->geo)) {
            $this->geo = Geography::getVersionInstance(null, $this->tenantID);
        }
        $app = \Xtra::app();
        $langCode = $app->session->languageCode ?? 'EN_US';
        // Kosovo is excluded by default in Geography2, so include it explicitly, because it is in CPI data
        return $this->geo->countrylist('KV', $langCode);
    }

    /**
     * Get lookup list of iso/score cPI countries
     *
     * @param integer $rmodelID riskModel.id, to look up cpiYear
     *
     * @return array iso/score elements
     */
    public function getCpiScores($rmodelID)
    {
        if (empty($rmodelID)) {
            return [];
        }
        // get cpiYear from riskModel
        $cpiYear = (new RiskModels($this->tenantID))->selectValueByID($rmodelID, 'cpiYear');

        if (!empty($this->geo)) {
            $isGeo2 = $this->geo->isGeo2;
        } else {
            $isGeo2 = \Xtra::usingGeography2();
        }
        if ($isGeo2) {
            $isoDB = $this->DB->isoDB;
            $globalDB = $this->DB->globalDB;
            // cpi country codes are checked against isoCode in countries lists, so the official codes
            // should be used in the cpiScores array
            $sql = "SELECT IFNULL(c.legacyCountryCode, s.isoCode) `isoCode`, CAST(s.score AS SIGNED)  `score`
                FROM $globalDB.g_cpiScore s 
                LEFT JOIN $isoDB.legacyCountries c ON 
                  (c.legacyCountryCode = s.isocode OR c.`codeVariant` = s.isocode OR c.codeVariant2 = s.isocode)
                  AND (c.countryCodeID > 0 OR c.deferCodeTo IS NULL)
                WHERE s.cpiYear = :cpiYear  ORDER BY s.score DESC, s.isoCode";
            return $this->DB->fetchKeyValueRows($sql, [':cpiYear' => $cpiYear]);
        } else {
            $mdl = new CpiScore();
            $orderBy = 'ORDER BY score DESC, isoCode ASC';
            return $mdl->selectKeyValues(['isoCode', 'CAST(score AS SIGNED)'], ['cpiYear' => $cpiYear], $orderBy);
        }
    }

    /**
     * Get custom fields and custom select list itmes  to build Custom Field factor input screen
     *
     * @return array fields and list items
     */
    public function getCuFldData()
    {
        $fields = (new CustomField($this->tenantID))->riskFactorEligibleFields();
        $listNames = [];
        foreach ($fields as $fld) {
            if (!empty($fld['listName']) && !in_array($fld['listName'], $listNames)) {
                $listNames[] = $fld['listName'];
            }
        }
        if (!empty($listNames)) {
            $liMdl = new CustomSelectList($this->tenantID);
            $listItems = [];
            foreach ($listNames as $lName) {
                $listItems[$lName] = $liMdl->customSelectListItems($lName);
            }
        }
        return [
            'fields' => $fields,
            'listItems' => $listItems,
        ];
    }

    /**
     * Get list of DDQs to populate not scored and scored select tabs
     *
     * @param integer $rmodelID   Risk model id
     * @param string  $chooseWord 'Choose...' Text for option tag when there are available options
     * @param string  $noneWord   'None' Text for option tag when there are no available optoins
     *
     * @return array 'scored' and unscored assoc array
     */
    public function getDdqSelects($rmodelID, $chooseWord = "Choose...", $noneWord = "None")
    {
        if (empty($rmodelID)) {
            return [];
        }
        $nameMdl = new DdqName($this->tenantID);
        $quesMdl = new OnlineQuestions($this->tenantID);
        $nameTbl = $nameMdl->getTableName();
        $quesTbl = $quesMdl->getTableName();

        // all ddq versions
        $sql = <<<EOT
SELECT DISTINCT o.caseType, o.ddqQuestionVer, n.name
FROM $quesTbl aS o
LEFT JOIN $nameTbl AS n ON (
    n.legacyID=CONCAT('L-',o.caseType,TRIM(o.ddqQuestionVer))
    AND n.clientID = :cid
)
WHERE o.clientID = :cid2
AND o.languageCode='EN_US'
AND o.qStatus = :required
AND o.controlType IN('radioYesNo','DDLfromDB')
AND (n.legacyID IS NULL OR n.formClass = 'due-diligence')
ORDER BY o.caseType ASC, o.ddqQuestionVer DESC
EOT;
        $params = [
            ':cid' => $this->tenantID,
            ':cid2' => $this->tenantID,
            ':required' => $quesMdl::ONLINE_Q_REQUIRED,
        ];
        $Lversions = $this->DB->fetchAssocRows($sql, $params);

        // Scored ddq
        $rfMdl = new RiskFactor($this->tenantID);
        $rfTbl = $rfMdl->getTableName();
        $sql = <<<EOT
SELECT ddqRef
FROM riskFactor
WHERE model = :rmodel
AND clientID = :cid
AND component='ddq'
AND (LEFT(ddqRef,2) = 'L-')
ORDER BY ddqRef DESC
EOT;
        $params = [':cid' => $this->tenantID, ':rmodel' => $rmodelID];
        $Lscored = $this->DB->fetchValueArray($sql, $params);
        $rtn = [
            'unscored' => [],
            'scored' => [],
        ];
        foreach ($Lversions as $ver) {
            $v = trim((string) $ver['ddqQuestionVer']);
            $Lver = 'L-' . $ver['caseType'] . $v;
            $name = $ver['name'];
            if ($name) {
                if ($v) {
                    $name .= " (v.$v)";
                }
            } else {
                $name = $Lver;
            }
            if (in_array($Lver, $Lscored)) {
                $rtn['scored'][] = ['ver' => $Lver, 'name' => $name];
            } else {
                $rtn['unscored'][] = ['ver' => $Lver, 'name' => $name];
            }
        }
        // add first option: Choose... or None
        if (empty($rtn['unscored'])) {
            $rtn['unscored'][] = ['ver' => '', 'name' => $noneWord];
        } else {
            array_unshift($rtn['unscored'], ['ver' => '', 'name' => $chooseWord]);
        }
        if (empty($rtn['scored'])) {
            $rtn['scored'][] = ['ver' => '', 'name' => $noneWord];
        } else {
            array_unshift($rtn['scored'], ['ver' => '', 'name' => $chooseWord]);
        }
        return $rtn;
    }

    /**
     * Get full path of test pid file
     *
     * @param integer $rmodelID   riskModel.id
     * @param boolean $publishing Mode setting; set true if publishing rather than testing.
     *
     * @return string pifFile path
     */
    public function getTestPidFile($rmodelID, $publishing = false)
    {
        if (empty($rmodelID)) {
            return null;
        }
        // Alter pid file name when publishing
        //   Testing pid indicates testing in progress
        //   Publishing pid indicates risk model map has been updated before assigning new assessments
        $tail = ($publishing === true) ? '_pub.pid' : '.pid';
        return $this->tmpGenDir . '/' . $this->tenantID . '_' . $rmodelID . $tail;
    }

    /**
     * Check the status of the process associated with testing this model
     * Note: Testing status of a pid should probably be in ForkProcess.
     *
     * @param integer $rmodelID   riskModel.id
     * @param boolean $publishing Mode setting; set true if publishing rather than testing.
     *
     * @return mixed null if not running, or integer process ID
     */
    public function checkTestPidStatus($rmodelID, $publishing = false)
    {
        if (empty($rmodelID)) {
            return;
        }
        $pid = null;
        $pidFile = $this->getTestPidFile($rmodelID, $publishing);
        if (is_readable($pidFile)) {
            $p = trim(file_get_contents($pidFile));
            $rawps = shell_exec("ps -p $p -o user,pid,%cpu,%mem,stat,start,etime,cmd");
            // check $rawps for controller name
            $ps = preg_replace('/ {2,}/', ' ', $rawps);
            $pslines = explode("\n", (string) $ps);

            if (count($pslines) > 2) {
                $psflds = explode(' ', $pslines[0]);
                $psvals = explode(' ', $pslines[1]);
                $stat = substr($psvals[4], 0, 1);
                if ($stat == 'Z' || $stat == 'X') {
                    unlink($pidFile); // no need to keep it if process died
                } else {
                    $pid = (int)$p; // running
                }
            }
        }
        return $pid;
    }

    /**
     * Get Model Test state
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return array of status data
     */
    public function getTestState($rmodelID)
    {
        if (empty($rmodelID)) {
            return [];
        }
        $profiles = $assessed = $elapsed = 0;
        $runStatus = null;
        $summary = [];
        if ($modelRec = (new RiskModels($this->tenantID))->getRecord($rmodelID)) {
            // do any active profiles match this risk model?
            // use safe/validated csv category list for mysql IN() function
            $catList = 0;
            if ((new CsvIntList($modelRec['categories']))->isValid()) {
                $catList = $modelRec['categories'];
            }
            $sql = <<<EOT
SELECT COUNT(*) FROM thirdPartyProfile
WHERE clientID = :cid AND tpType = :tpType
AND tpTypeCategory IN($catList) AND status = 'active'
EOT;



            $params = [':cid' => $this->tenantID, ':tpType' => $modelRec['tpType']];
            $profiles = $this->DB->fetchValue($sql, $params);

            // are there test assessments?
            $raModel = new RiskAssessment($this->tenantID);
            $raWhere = ['model' => $rmodelID, 'status' => 'test'];
            $assessed = $raModel->selectValue('COUNT(*)', $raWhere);

            // is a test currently running for this model?
            if ($pid = $this->checkTestPidStatus($rmodelID)) {
                $runStatus = 'running';
            } elseif ($assessed) {
                $runStatus = 'complete';
                $lastTstamp = $raModel->selectValue('UNIX_TIMESTAMP(tstamp)', $raWhere, 'ORDER BY tstamp ASC');
                $elapsed = time() - $lastTstamp;
                $summary = $this->getBudgetFromTest($rmodelID);
            } elseif ($profiles == 0 && $assessed == 0) {
                $runStatus = 'complete';
                $lastTstamp = $raModel->selectValue('UNIX_TIMESTAMP(tstamp)', $raWhere, 'ORDER BY tstamp ASC');
                if ($lastTstamp == 0) {
                    $elapsed = 1;
                } else {
                    $elapsed = time() - $lastTstamp;
                }
                $summary = $this->getBudgetFromTest($rmodelID);
            }
        }

        return [
            'profiles' => $profiles,
            'assessed' => $assessed,
            'runStatus' => $runStatus,
            'elapsed' => $elapsed,
            'summary' => $summary,
        ];
    }

    /**
     * Get estimated budget from test of model
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return array date for budget summary table rows
     */
    private function getBudgetFromTest($rmodelID)
    {
        if (empty($rmodelID)) {
            return;
        }

        // Get number of profiles in each tier
        $raMdl = new RiskAssessment($this->tenantID);
        $flds = ['tier', 'COUNT(*) AS `profiles`'];
        $where = ['model' => $rmodelID, 'status' => 'test'];
        $tierProfiles = $raMdl->selectKeyValues($flds, $where, 'GROUP BY tier');
        $tiers = [];
        foreach ($tierProfiles as $tier => $ttl) {
            $tiers[] = $tier;
            $tierProfiles[$tier] = number_format($ttl, 0);
        }

        $tierHTML = [];
        if (count($tiers)) {
            $tierList = join(',', $tiers);
            $sql = <<<EOT
SELECT t.id, t.tierHTML AS html
FROM riskTier AS t
LEFT JOIN riskModelTier AS mt ON mt.tier = t.id
WHERE t.clientID = :cid
AND t.id IN($tierList)
ORDER BY mt.threshold DESC
EOT;
            $tierHTML = $this->DB->fetchKeyValueRows($sql, [':cid' => $this->tenantID]);
        }

        // calc est. cost
        // needed: count for profiles grouped by tier, profile country
        $sql = <<<EOT
SELECT ra.tier, tp.country, COUNT(tp.id) AS records
FROM riskAssessment AS ra
LEFT JOIN thirdPartyProfile AS tp ON tp.id = ra.tpID
WHERE ra.model = :mid
AND ra.clientID = :cid
AND ra.status = 'test'
GROUP BY ra.tier, tp.country
ORDER BY ra.tier ASC
EOT;
        $rows = $this->DB->fetchObjectRows($sql, [':mid' => $rmodelID, ':cid' => $this->tenantID]);
        $costSum = [];
        $tier = -1;
        $rmtMdl = new RiskModelTier($this->tenantID);
        $spID = ServiceProvider::STEELE_INVESTIGATOR_PROFILE; // legacy call was all Steele anyway.
        foreach ($rows as $row) {
            if ($row->tier != $tier) {
                $tier = $row->tier;
                if (!array_key_exists($tier, $costSum)) {
                    $costSum[$tier] = 0;
                }
                $scope = $rmtMdl->selectValue('scope', ['tier' => $tier, 'model' => $rmodelID]);
            }
            $xtraPrincipals = 0;
            try {
                $calc = new CaseCostTimeCalc($spID, $this->tenantID, $scope, $row->country, $xtraPrincipals);
                $calcResult = $calc->getCostTime();
                $iCost = $calcResult['budgetAmount'];
                $costSum[$tier] += ($iCost * $row->records);
            } catch (\Exception $e) {
                \Xtra::track($e->getMessage());
            } finally {
                unset($calc);
            }
        }
        $tierEstimates = [];
        $negotiation = 0;
        // format Est. Cost
        $ttlbudget = 0;
        $tierCost = '';
        foreach ($costSum as $tier => $sum) {
            $ttlbudget += $sum;
            if ($sum > 0.0) {
                $tierCost = '$' . number_format($sum, 0);
            } else {
                $tierCost = $this->trans->codeKey('dd_cost_RequestBudget'); // Request Budget
                $negotiation = 1;
            }

            $tierEstimates[$tier] = $tierCost;
        }
        return [
            'tierProfiles' => $tierProfiles,
            'tierEstimates' => $tierEstimates,
            'total' => '$' . number_format($ttlbudget, 0),
            'totalMsg' => $this->trans->codeKey('dd_cost_estimate'),
            'negotiation' => (($negotiation) ? $this->trans->codeKey('dd_cost_exclusion') : ''),
        ];
    }

    /**
     * Get sample profiles from test assessments
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return array Randome profile object rows from each tier
     */
    public function sampleProfiles($rmodelID)
    {
        if (empty($rmodelID)) {
            return [];
        }

        // count assessment records
        $raMdl = new RiskAssessment($this->tenantID);
        $cols = [
            'tier',
            'COUNT(tpID) AS `profiles`',
        ];
        $where = [
            'model' => $rmodelID,
            'status' => 'test',
        ];
        $profiles = [];
        $tierCounts = $raMdl->selectKeyValues($cols, $where, 'GROUP BY tier ORDER BY tier');
        $records = 0;
        foreach ($tierCounts as $tier => $cnt) {
            $records += $cnt;
        }
        if ($records) {
            $raTbl = $raMdl->getTableName();
            foreach ($tierCounts as $tier => $cnt) {
                $limit = round(($cnt / $records) * 20, 0);
                if ($limit < 1) {
                    $limit = 1;
                }
                $sql = <<<EOT
SELECT ra.tpID, ra.normalized AS 'rating', p.legalName
FROM $raTbl AS ra
LEFT JOIN thirdPartyProfile AS p ON p.id = ra.tpID
WHERE p.id IS NOT NULL
AND ra.model = :model
AND ra.clientID = :cli
AND ra.tier = :tier
AND ra.status = 'test'
ORDER BY RAND() LIMIT $limit
EOT;
                $params = [
                    ':model' => $rmodelID,
                    ':tier' => $tier,
                    ':cli' => $this->tenantID,
                ];
                $profiles[$tier] = $this->DB->fetchAssocRows($sql, $params);
            }
        }
        return $profiles;
    }

    /**
     * Clone risk model
     *
     * @param integer $rmodelID riskModel.id
     * @param integer $userID   users.id of logged in user for Audit Log
     *
     * @return mixed error string (codeKey) or true on success
     */
    public function cloneRiskModel($rmodelID, $userID)
    {
        if (empty($rmodelID) || empty($userID)) {
            return false;
        }
        // Get risk model record to be cloned
        $rmodelID = (int)$rmodelID;
        $riskMdl = new RiskModels($this->tenantID);
        $riskRec = $riskMdl->selectByID($rmodelID);
        if (empty($riskRec) || ('complete' !== $riskRec['status'] && 'disabled' !== $riskRec['status'])) {
            return 'err_invalidRequest';
        }

        // Copy, then apply overrides, as needed
        $vals = $riskRec;
        $newName = '(Clone) ' . $riskRec['name'];
        $tstamp = date('Y-m-d H:i:s');
        $vals['id']        = null;
        $vals['name']      = $newName;
        $vals['status']    = 'setup';
        $vals['created']   = $tstamp;
        $vals['updated']   = $tstamp;
        $vals['prevModel'] = $rmodelID;

        // Insert the cloned record
        if (!($newID = $riskMdl->insert($vals))) {
            return 'error_unexpected_msg';
        }

        // Duplicate risk model tiers
        $tierMdl = new RiskModelTier($this->tenantID);
        $rows = $tierMdl->selectMultiple([], ['model' => $rmodelID]);
        foreach ($rows as $row) {
            $row['id'] = null;
            $row['model'] = $newID;
            $tierMdl->insert($row);
        }

        // Duplicate risk factors
        $factorMdl = new RiskFactor($this->tenantID);
        $rows = $factorMdl->selectMultiple([], ['model' => $rmodelID]);
        foreach ($rows as $row) {
            $row['id'] = null;
            $row['model'] = $newID;
            $factorMdl->insert($row);
        }

        // Log action -- 85, Clone Risk Model
        $msg = '(#' . $newID  . ') name: `' . $newName . '`';
        (new LogData($this->tenantID, $userID))->saveLogEntry(85, $msg);

        return true; // signal success
    }

    /**
     * Enable risk model
     *
     * @param integer $rmodelID riskModel.id
     * @param integer $userID   users.id of logged in user for Audit Log
     *
     * @return array Status with message
     */
    public function enableRiskModel(int $rmodelID, int $userID): array
    {
        $result = [
                    'status' => false,
                    'message' => 'Something is missing, please report this error to support.'
                ];

        try {
            if ($rmodelID && $userID) {
                $riskMdl = new RiskModels($this->tenantID);
                $riskRec = $riskMdl->selectByID($rmodelID);

                // If risk model not disabled
                if (empty($riskRec) || 'disabled' !== $riskRec['status']) {
                    $result['message'] = $this->trans->codeKey('err_invalidRequest');
                } else {
                    $reqStatus = 'complete';
                    $disableOldRoleModel = 0;
                    $roleLimit = getenv('riskModelRoleCount');
                    // Calling function to get risk model count for same type and categories
                    $extraRoleCount = $riskMdl->getRoleCount(
                        $riskRec['tpType'],
                        $riskRec['categories'],
                        $riskRec['riskModelRole']
                    );
                    if ($extraRoleCount > 0) {
                        $result['message'] = "You cannot enable more than {$roleLimit} risk model
                                            for any 3P type & Category.";
                    } else {
                        // Check if risk model for the same role, tpType and categories already exists
                        $existingModel = $riskMdl->getExistingRoleModel(
                            $riskRec['tpType'],
                            $riskRec['categories'],
                            $riskRec['riskModelRole'],
                            $reqStatus
                        );
                        // Enable the risk model
                        if (!($riskMdl->updateByID($rmodelID, ['status' => $reqStatus]))) {
                            $result['message'] = "{$riskRec['name']} can not be enabled,
                                                please report this error to support.";
                        } else {
                            if ($existingModel) {
                                // If exists, disable the risk model
                                $riskMdl->disableExistingRiskModel($existingModel);
                                $disableOldRoleModel++;
                            }
                            // Update the model map -- insert/delete -- for affected tpType & categories
                            $rmmMdl = new RiskModelMap($this->tenantID);
                            $cats = explode(',', $riskRec['categories']);
                            foreach ($cats as $cat) {
                                $vars = [
                                    'tpType' => $riskRec['tpType'],
                                    'tpCategory' => $cat,
                                    'riskModelRole' => $riskRec['riskModelRole']
                                ];
                                $rmmMdl->delete($vars);
                                $vars['riskModel'] = $rmodelID;
                                $rmmMdl->insert($vars);
                            }
                            // Calling function to re-calculate the risk ratings for the 3P profiles affected
                            (new UpdateScoresData($this->tenantID))->update();

                            $message = $disableOldRoleModel > 0
                                        ? "{$riskRec['name']} has been enabled successfully!
                                            <br> We have disabled another model under same risk area."
                                        : "{$riskRec['name']} has been enabled successfully!";

                            // Log action -- 219, Enable Risk Model
                            $msg = 'Enable (#' . $rmodelID  . ') name: `' . $riskRec['name'] . '`';
                            (new LogData($this->tenantID, $userID))->saveLogEntry(219, $msg);
                            $result['status'] = true;
                            $result['message'] = $message;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
        return $result;
    }

    /**
     * Disable risk model
     *
     * @param integer $rmodelID riskModel.id
     * @param integer $userID   users.id of logged in user for Audit Log
     *
     * @return array Status with message
     */
    public function disableRiskModel(int $rmodelID, int $userID): array
    {
        $result = [
                    'status' => false,
                    'message' => 'Something is missing, please report this error to support.'
                ];

        try {
            if ($rmodelID && $userID) {
                $riskMdl = new RiskModels($this->tenantID);
                $riskRec = $riskMdl->selectByID($rmodelID);

                // If risk model not published
                if (empty($riskRec) || 'complete' !== $riskRec['status']) {
                    $result['message'] = $this->trans->codeKey('err_invalidRequest');
                } else {
                    // Disable the risk model
                    if (!($riskMdl->updateByID($rmodelID, ['status' => 'disabled']))) {
                        $result['message'] = "{$riskRec['name']} can not be disabled,
                                            please report this error to support.";
                    } else {
                        // Update the model map -- insert/delete -- for affected tpType & categories
                        $rmmMdl = new RiskModelMap($this->tenantID);
                        $rmmMdl->delete(['riskModel' => $rmodelID]);

                        // Calling function to re-calculate the risk ratings for the 3P profiles affected
                        (new UpdateScoresData($this->tenantID))->update();

                        // Log action -- 220, Disable Risk Model
                        $msg = 'Disable (#' . $rmodelID  . ') name: `' . $riskRec['name'] . '`';
                        (new LogData($this->tenantID, $userID))->saveLogEntry(220, $msg);
                        $result['status'] = true;
                        $result['message'] = $riskRec['name'] . " has been disabled successfully!";
                    }
                }
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
        return $result;
    }

    /**
     * Publish a risk model in setup status
     *
     * @param integer $rmodelID riskModel.id
     * @param integer $userID   users.id of logged in user for Audit Log
     *
     * @return mixed string codeKey if error, otherwise null
     */
    public function publishRiskModel($rmodelID, $userID)
    {
        if (empty($rmodelID) || empty($userID)) {
            return 'riskinv_EmptyModelIDOruserID';
        }
        $err = null;
        do {
            // Validate setup
            $riskMdl = new RiskModels($this->tenantID);
            $modelRec = $riskMdl->selectByID($rmodelID);
            if (empty($modelRec)) {
                $err = 'msg_no_record';
                break;
            } elseif ('setup' !== $modelRec['status']) {
                $err = 'riskinv_WrongStatusToPublish';
                break;
            }
            // Has valid tpType
            $typeMdl = new TpType($this->tenantID);
            if ($modelRec['tpType'] !== $typeMdl->selectValueByID($modelRec['tpType'], 'id')) {
                $err = 'invalid_profile_type';
                break;
            }
            // Has valid risk model risk area
            if ($this->app->ftr->tenantHas(\Feature::MULTIPLE_RISK_MODEL)) {
                $roleMdl = new RiskModelRole($this->tenantID);
                if ($modelRec['riskModelRole'] !== $roleMdl->selectValueByID($modelRec['riskModelRole'], 'id')) {
                    $err = 'Invalid Risk Area';
                    break;
                }
            }
            // Has one or more tpTypeCategories
            $catMdl = new TpTypeCategory($this->tenantID);
            if (!$catMdl->validateCategoryList($modelRec['tpType'], $modelRec['categories'])) {
                $err = 'gdc_typecat_invalid_ref';
                break;
            }
            unset($typeMdl, $catMdl);
            //   Has one or more risk tiers
            $params = ['model' => $rmodelID];
            if ((new RiskModelTier($this->tenantID))->selectValue('COUNT(*)', $params) == 0) {
                $err = 'riskinv_no_tiers_defined';
                break;
            }
            //   Has one or more components
            $definedFactors = RiskModel::componentsToFactors($modelRec['components']);
            if (empty($definedFactors)) {
                $err = 'riskinv_requireOneFactor';
                break;
            }
            //   Components have corresponding factor records
            $factorMdl = new RiskFactor($this->tenantID);
            foreach ($definedFactors as $factor) {
                $params = ['model' => $rmodelID, 'component' => $factor];
                $cnt = $factorMdl->selectValue('COUNT(*)', $params);
                if ($factor == 'ddq') {
                    $res = ($cnt >= 1);
                } else {
                    $res = ($cnt == 1);
                }
                if (!$res) {
                    $err = 'riskinv_factorRecordsConflict';
                    break 2; // exit foreach and do loop
                }
            }
            // Remove factor records for unused components
            $factorList = implode(',', $definedFactors);
            $tbl = $factorMdl->getTableName();
            $sql = "DELETE FROM $tbl WHERE clientID = :clientID\n"
                . "AND model = :modelID AND NOT FIND_IN_SET(component, :factors)";
            $params = [
                ':clientID' => $this->tenantID,
                ':modelID' => $rmodelID,
                ':factors' => $factorList,
            ];
            $this->DB->query($sql, $params);

            // Check for pid record - another user already publishing this model?
            if ($this->checkTestPidStatus($rmodelID, true)) {
                break; // already being published; nothing to do
            }
            // // Start the bg process to create new 'current' assessments
            // $cmd = "Controllers.TPM.Settings.ContentControl.RiskInventory"
            //     . ".TestRiskModel::publish _c:{$this->tenantID} $rmodelID $userID";
            // if (!($pid = (int)(new ForkProcess())->launch($cmd))) {
            //     $err = 'bg_process_fail_launch'; // forked process died?
            //     break;
            // }
            $logFile = '/tmp/skinnycli_publish_' . uniqid() . '.log';
            $cmd = "/usr/bin/nohup /var/www/prod/skinnycli ";
            $cmd .= "Controllers.TPM.Settings.ContentControl.RiskInventory"
                . ".TestRiskModel::publish _c:{$this->tenantID} $rmodelID $userID";
            $cmd .= " > $logFile 2>&1 & echo $!"; // Background the process and return the PID

            $pid = shell_exec($cmd);
            $pid = trim($pid); // remove whitespace and newlines

            if (!$pid || !is_numeric($pid)) {
                $err = 'bg_process_fail_launch'; // background process failed
                // Optionally: log error or return failure
                return false;
            }

            // Wait for presence of pid file to indicate risk model map has been updated
            $pidFile = $this->getTestPidFile($rmodelID, true); // true alters file name for publishing
            $failsafe = time() + 10; // wait no more than 10 seconds
            $hitFailsafe = true;
            do {
                if (file_exists($pidFile)) {
                    $hitFailsafe = false;
                    break; // risk model map has been updated; okay for front-end to finish
                }
                usleep(250000); // wait 1/4 sec before re-checking
            } while ($failsafe > time());
            if ($hitFailsafe) {
                $err = 'error_unexpected_msg'; // forked process died?
                break;
            }

            // insert Audit Log record
            // 84 Publish Risk Model
            $msg = '(#' . $rmodelID  . ') name: `' . $modelRec['name'] . '`';
            (new LogData($this->tenantID, $userID))->saveLogEntry(84, $msg);
        } while (false);
        return $err;
    }

    /**
     * Delete a risk model, but only if it is in setup status
     *
     * @param integer $rmodelID riskModel.id
     * @param integer $userID   users.id of logged in user for Audit Log
     *
     * @return void
     */
    public function deleteRiskModel($rmodelID, $userID)
    {
        if (empty($rmodelID) || empty($userID)) {
            return;
        }
        // must be in setup status -- caller should check this, but let's be sure
        $rMdl = new RiskModels($this->tenantID);
        $riskRec = $rMdl->selectByID($rmodelID, ['name', 'status']);
        if (empty($riskRec) || 'setup' !== $riskRec['status']) {
            return false;
        }
        // remove any test records - 'test' should be the only status
        (new RiskAssessment($this->tenantID))->delete(['model' => $rmodelID, 'status' => 'test']);
        // remove risk factors
        (new RiskFactor($this->tenantID))->delete(['model' => $rmodelID]);
        // remove risk model tiers
        (new RiskModelTier($this->tenantID))->delete(['model' => $rmodelID]);
        // finally, remove risk model record
        $rMdl->deleteByID($rmodelID);
        // Audit Log
        // 83 Delete Risk Model Draft
        $msg = '(#' . $rmodelID  . ') name: `' . $riskRec['name'] . '`';
        (new LogData($this->tenantID, $userID))->saveLogEntry(83, $msg);
    }

    /**
     * Checks risk model in setup mode for latest CPI year and forces it if it is not.
     * Applies only to risk model in setup status.
     *
     * @param array $riskRec Risk model record
     *
     * @return array $riskRec, with cpiYear set to latest CPI year
     */
    public function forceLatestCpiYear($riskRec)
    {
        if (empty($riskRec)) {
            return;
        }
        if ($riskRec['status'] === 'setup') {
            $latest = (new CpiScore())->selectValue('MAX(cpiYear)');
            if ($latest > $riskRec['cpiYear']) {
                // update risk model
                (new RiskModels($this->tenantID))->updateByID($riskRec['id'], ['cpiYear' => $latest]);
                $riskRec['cpiYear'] = $latest;
                // delete CPI factor
                (new RiskFactor($this->tenantID))->delete(['model' => $riskRec['id'], 'component' => 'cpi']);
            }
        }
        return $riskRec;
    }


    /**
     * Get scores from DDQ Doing Business In modal
     *
     * @param int $cpiYear CPI Year
     *
     * @return array Array of scores and country ISO codes from DDQ modal
     */
    public function getScoresFromModal($cpiYear)
    {
        if (empty($cpiYear)) {
            return [];
        }
        $inFormResCnMdl = new InFormRspnsCountries($this->tenantID);
        $inFormResCnTbl = $inFormResCnMdl->getTableName();
        $sql = "SELECT distinct(iso_code) from $inFormResCnTbl";
        $rows = $this->DB->fetchObjectRows($sql);
        $newScores = [];
        $cpiScoreMdl = new CpiScore();

        if ($rows && count($rows) > 0) {
            foreach ($rows as $row) {
                $newScores[$row->iso_code] = (int)$cpiScoreMdl->selectValue(
                    'score',
                    ['cpiYear' => $cpiYear, 'isoCode' => $row->iso_code]
                );
            }
            return $newScores;
        }
        return [];
    }

    /**
     * Get scores from Third Party Profile country field
     *
     * @param int $cpiYear CPI Year
     *
     * @return array Return CPI scores and ISO codes
     */
    public function getScoresFromThirdParty($cpiYear)
    {
        if (empty($cpiYear)) {
            return [];
        }
        $tpProfileMdl = new TpProfile($this->tenantID);
        $tpProfileTbl = $tpProfileMdl->getTableName();
        $sql = "SELECT distinct(regCountry) from $tpProfileTbl";
        $rows = $this->DB->fetchObjectRows($sql);
        $newScores = [];
        $cpiScoreMdl = new CpiScore();

        if ($rows && count($rows) > 0) {
            foreach ($rows as $row) {
                $newScores[$row->regCountry] = (int)$cpiScoreMdl->selectValue(
                    'score',
                    ['cpiYear' => $cpiYear, 'isoCode' => $row->regCountry]
                );
            }
            return $newScores;
        }
        return [];
    }

    /**
     * Get scores from DDQ country field
     *
     * @param int $cpiYear CPI Year
     *
     * @return array Return CPI scores and ISO codes
     */
    public function getScoresFromDdq($cpiYear)
    {
        if (empty($cpiYear)) {
            return [];
        }
        $sql = "SELECT distinct(regCountry) from ddq";
        $rows = $this->DB->fetchObjectRows($sql);
        $newScores = [];
        $cpiScoreMdl = new CpiScore();

        if ($rows && count($rows) > 0) {
            foreach ($rows as $row) {
                $newScores[$row->regCountry] = (int)$cpiScoreMdl->selectValue(
                    'score',
                    ['cpiYear' => $cpiYear, 'isoCode' => $row->regCountry]
                );
            }
            return $newScores;
        }
        return [];
    }

    /**
     * Get scores from Third Party Profile country override field
     *
     * @param int $cpiYear CPI Year
     *
     * @return array Return CPI scores and ISO codes
     */
    public function getScoresFromCountryOverride($cpiYear)
    {
        if (empty($cpiYear)) {
            return [];
        }
        $tpProfileMdl = new TpProfile($this->tenantID);
        $tpProfileTbl = $tpProfileMdl->getTableName();
        $sql = "SELECT distinct(countryOverride) from $tpProfileTbl";
        $rows = $this->DB->fetchObjectRows($sql);
        $newScores = [];
        $cpiScoreMdl = new CpiScore();

        if ($rows && count($rows) > 0) {
            foreach ($rows as $row) {
                $newScores[$row->countryOverride] = (int)$cpiScoreMdl->selectValue(
                    'score',
                    ['cpiYear' => $cpiYear, 'isoCode' => $row->countryOverride]
                );
            }
            return $newScores;
        }
        return [];
    }

    /**
     * Get the enabled options from the risk model for the following columns:
     *     'countriesFrommodal', 'countriesFromddq', 'countriesFromcarc'
     *
     * @param int $rmodelID Risk model ID
     *
     * @return array Return array indicating the active countriesFromXXX options
     */
    public function getFlagFromOtherSources($rmodelID)
    {
        if (empty($rmodelID)) {
            return [];
        }

        $rMdl = new RiskModels($this->tenantID);
        $rows = $rMdl->selectMultiple(
            ['countriesFrommodal', 'countriesFromddq', 'countriesFromcarc'],
            ["id" => $rmodelID]
        );
        $return = [];

        foreach ($rows[0] as $k => $v) {
            if ($v) {
                $return[] = str_replace('countriesFrom', '', (string) $k);
            }
        }
        return $return;
    }

    /**
     * Get raw score column flags indicating if raw scoring is used
     *
     * @param int $rmodelID Risk model ID
     *
     * @return array Raw score flags from the Risk Model
     */
    public function getRawScoreColumns($rmodelID)
    {
        if (empty($rmodelID)) {
            return [];
        }

        $columns = ['calculateRawDdqScore', 'calculateRawCuFldScore'];
        $options = [];
        $rMdl = new RiskModels($this->tenantID);
        $cols = $rMdl->selectOne($columns, ['id' => $rmodelID]);

        if (!empty($cols)) {
            foreach ($cols as $col => $val) {
                switch ($col) {
                    case 'calculateRawDdqScore':
                        $options['ddq'] = $val;
                        break;
                    case 'calculateRawCuFldScore':
                        $options['cufld'] = $val;
                        break;
                }
            }
        }

        return $options;
    }

    /**
     * Disable all risk models
     *
     * @return string string
     */
    public function disableAllRiskModelAndRoles(): string
    {
        $message = 'error';
        try {
            // Get risk model record to be disable
            $riskMdl = new RiskModels($this->tenantID);
            $riskMdlMap = new RiskModelMap($this->tenantID);
            $riskMdlRole = new RiskModelRole($this->tenantID);

            $tpTypesCategories = $riskMdlMap->selectMultiple([
                'DISTINCT tpType',
                'tpCategory AS tpTypeCategory'
            ]);
            // Getting all primary risk models for different type and category
            $tpTypeCatPrimaryRiskModels = [];
            foreach ($tpTypesCategories as $row) {
                $primaryModel = $riskMdl->getPrimaryRiskModel($row);
                $tpTypeCat = $row['tpType'] . '-' . $row['tpTypeCategory'];
                $tpTypeCatPrimaryRiskModels[$tpTypeCat] = $primaryModel;
            }
            $primaryRiskModels = array_values(array_unique($tpTypeCatPrimaryRiskModels));
            if (count($primaryRiskModels) > 0) {
                // Disable all published risk models except primary
                $riskMdl->update(['status' => 'disabled'], [
                        'id' => ['not_in' => $primaryRiskModels],
                        'status' => 'complete'
                ]);
                foreach ($tpTypeCatPrimaryRiskModels as $tpTypeCat => $primaryModel) {
                    $tpTypeCat = explode('-', $tpTypeCat);
                    // Delete all disabled risk models mapping except primary
                    $riskMdlMap->delete(['tpType' => $tpTypeCat[0],
                                         'tpCategory' => $tpTypeCat[1],
                                         'riskModel' => ['not_in' => [$primaryModel]]]);
                }
            }
            // Get max order number to create new role
            $maxOrderRow = $riskMdlRole->selectOne(['MAX(orderNum) AS maxOrder']);
            $nextOrder = $maxOrderRow['maxOrder'] + 1;
            // Disable all active risk model roles
            $riskMdlRole->update(['active' => '0'], ['active' => '1']);
            // Creating New risk model role for all primary risk model
            $newRole = $riskMdlRole->insert([
                'name' => 'Risk Rating',
                'orderNum' => $nextOrder,
                'active' => '1'
            ]);
            if (!$newRole) {
                $message = " Can not create Risk Model Role, please contact administrator.";
            } else {
                $message = 'success';
                if (count($primaryRiskModels) > 0) {
                    // Assign new role id to primary risk models
                    if (!($riskMdl->update(['riskModelRole' => $newRole], ['id' => $primaryRiskModels]))) {
                        $message = " Can not update role in Risk Models, please contact administrator.";
                    } else {
                        // Assign new role id to primary risk models mapping
                        $setValues = ['riskModelRole' => $newRole];
                        $whereValues = ['riskModel' => $primaryRiskModels];
                        if (!($riskMdlMap->update($setValues, $whereValues))) {
                            $message = " Can not update role in Risk Models mapping, please contact administrator.";
                        }
                    }
                }
                if ($message === 'success') {
                    // Calling function to re-calculate the risk ratings for the 3P profiles affected
                    (new UpdateScoresData($this->tenantID))->update();
                }
            }
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
        return $message;
    }

    /**
     * Get all distinct type and category from risk model map
     *
     * @return array Return array of distinct type and category
     */
    public function getDifferentTypeCategories(): array
    {
        $sql = "SELECT DISTINCT tpType, tpCategory AS tpTypeCategory "
            . "FROM riskModelMap WHERE clientID = :cid ";
        return $this->DB->fetchAssocRows($sql, [':cid' => $this->tenantID]);
    }
}
