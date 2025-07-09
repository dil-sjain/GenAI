<?php
/**
 * Cli script to generate test assessment records for new risk model before it is published
 */

namespace Controllers\TPM\Settings\ContentControl\RiskInventory;

use Models\TPM\Settings\ContentControl\RiskInventory\RiskInventory;
use Models\ThirdPartyManagement\RiskModel;
use Models\TPM\RiskModel\RiskModels;
use Models\TPM\RiskModel\RiskAssessment;
use Models\TPM\RiskModel\RiskModelMap;
use Models\TPM\TpProfile\TpProfile;
use Models\LogData;
use Lib\Database\ChunkResults;
use Lib\Validation\Validator\CsvIntList;
use Models\Globals\Features\TenantFeatures;

/**
 * Cli script to generate test assessment records for new risk model before it is published
 * and to publish it after test records have been generated.
 *
 * @keywords risk model test, test data, publish, cli script, cli, risk
 */
#[\AllowDynamicProperties]
class TestRiskModel
{
    /**
     * @var object Instance of Skinny app
     */
    protected $app = null;

    /**
     * @var object Application database connection
     */
    protected $DB = null;

    /**
     * @var integer TPM tenant identifier
     */
    protected $clientID = 0;

    /**
     * @var integer riskModel.id
     */
    protected $rmodelID = 0;

    /**
     * @var object Instance of RiskInventory model
     */
    protected $riMdl = null;

    /**
     * @var object Instance of TenantFeatures
     */
    protected $ftrs = null;

    /**
     * @var object Instance of RiskModel
     */
    protected $rMdl = null;

    /**
     * @var stdClass instance of RiskModel attributes  for riskModel.id
     */
    protected $rmRecord = false;

    /**
     * @var object Instance of RiskAssessment
     */
    protected $raMdl = null;

    /**
     * @var string CSV list of categories included in risk model
     */
    protected $categories = '0';

    /**
     * Constructor - set class properties
     *
     * @param string $clientID clientProfile.id, passed in from CLI as string, but converted to integer
     *
     * @return void
     */
    public function __construct($clientID)
    {
        $app = \Xtra::app();
        if (\Xtra::isNotCli() && $app->mode != 'Development') {
            throw new \Exception('Must be run as CLI, unless in Development mode.');
        }
        $this->clientID = (int)$clientID;
        if (empty($this->clientID) || $this->clientID < 1) {
            throw new \Exception('Invalid clientID');
        }
        $this->DB = $app->DB;
        $this->DB->setClientDB($this->clientID);
        $this->riMdl = new RiskInventory($this->clientID);
        $this->ftrs = new TenantFeatures($this->clientID);
    }

    /**
     * Perform a risk model test
     *
     * @param string $rmodelID riskModel.id passed in as string from CLI, but converted to integer
     *
     * @return void
     */
    public function run($rmodelID)
    {
        $this->rmodelID = (int)$rmodelID;
        if (!$this->isRiskModelSetup(__METHOD__)) {
            return;
        }
        $this->removeTestRecords();

        // chunk through the target profiles (ac tive only) and create test assessment records
        $sql = "SELECT id FROM thirdPartyProfile " .
                "WHERE id > :uniqueID " .
                "AND clientID = :cid "  .
                "AND status = 'active' " .
                "AND tpType = :tpType " .
                "AND tpTypeCategory IN($this->categories) " .
                "ORDER BY id ASC LIMIT 1000";

        $params = [
            ':uniqueID' => 0,
            ':cid' => $this->clientID,
            ':tpType' => $this->rmRecord->tpType,
        ];
        $chunker = new ChunkResults($this->DB, $sql, $params);
        while ($tpRec = $chunker->getRecord()) {
            $this->rMdl->insertAssessment($tpRec['id'], 'test');
        }
        $this->endTest();
    }

    /**
     * Publish a risk model
     *
     * @param string $rmodelID riskModel.id passed in as string from CLI, but converted to integer
     * @param string $userID   users.id passed in as string from CLI, but converted to integer
     *
     * @return void
     */
    public function publish($rmodelID, $userID)
    {
        $this->rmodelID = (int)$rmodelID;
        $userID = (int)$userID;
        if (!$this->isRiskModelSetup(__METHOD__)) {
            return;
        }
        $rmmMdl = new RiskModelMap($this->clientID);
        $tppMdl = new TpProfile($this->clientID);
        $rmMdlBL = new RiskModels($this->clientID); // BaseLite
        $hasMRM = $this->ftrs->tenantHasFeature(\Feature::MULTIPLE_RISK_MODEL, \Feature::APP_TPM);
        $this->getCategories();
        $cats = explode(',', $this->categories);
        $disabledModels = [];
        foreach ($cats as $cat) {
            $vars = [
                'tpType' => $this->rmRecord->tpType,
                'tpCategory' => $cat,
                'riskModelRole' => $this->rmRecord->riskModelRole
            ];
            if ($hasMRM) {
                $modelMap = $rmmMdl->selectValue('riskModel', $vars);
                if (!empty($modelMap)) {
                    $disabledModels[] = $modelMap;
                }
            }
            $rmmMdl->delete($vars);
            $vars['riskModel'] = $rmodelID;
            $rmmMdl->insert($vars);
        }
        // Update model status to 'complete'
        $rmMdlBL->updateByID($rmodelID, ['status' => 'complete']);

        // Write the pid file to signal caller okay to finish w/o waiting for the rest of the process
        $pidFile = $this->riMdl->getTestPidFile($this->rmodelID, true);
        $pid = posix_getpid();
        file_put_contents($pidFile, $pid, LOCK_EX);

        // Remove test records
        $this->removeTestRecords();

        // chunk through the target profiles (active and inactive) and create current assessment records
        $auditLog = new LogData($this->clientID, $userID);
        $sql = "SELECT id, riskModel, tpType, tpTypeCategory FROM thirdPartyProfile " .
                "WHERE id > :uniqueID " .
                "AND clientID = :cid " .
                "AND status <> 'deleted' " .
                "AND tpType = :tpType " .
                "AND tpTypeCategory IN($this->categories) " .
                "ORDER BY id ASC LIMIT 1000";

        $params = [
            ':uniqueID' => 0,
            ':cid' => $this->clientID,
            ':tpType' => $this->rmRecord->tpType,
        ];
        $chunker = new ChunkResults($this->DB, $sql, $params);
        while ($tpRec = $chunker->getRecord()) {
            if ($tpRec['riskModel'] == $rmodelID) {
                continue; // current assessment sneaked in from a near-time update?
            }
            // Get old risk model name for audit log
            $prevName = $rmMdlBL->selectValueByID($tpRec['riskModel'], 'name');
            if ($hasMRM) {
                // Mark old record with 'past' status
                if (!empty($disabledModels)) {
                    $this->raMdl->update(
                        ['status' => 'past'],
                        ['tpID' => $tpRec['id'], 'status' => 'current', 'model' => $disabledModels]
                    );
                }
                // Get primary risk model
                $primaryModel = $rmMdlBL->getPrimaryRiskModel($tpRec);
                if ($primaryModel != $tpRec['riskModel']) {
                    // Update primary risk model in profile record
                    $tppMdl->updateByID($tpRec['id'], ['riskModel' => $primaryModel]);
                }
            } else {
                // Mark current record with 'past' status
                $this->raMdl->update(['status' => 'past'], ['tpID' => $tpRec['id'], 'status' => 'current']);
                // Update model on profile record
                $tppMdl->updateByID($tpRec['id'], ['riskModel' => $rmodelID]);
            }
            // Insert new assessment
            $this->rMdl->insertAssessment($tpRec['id'], 'current');
            // Log change to profile, 52 Update Third Party Profile
            $details = "riskModel: `$prevName` => `" . $this->rmRecord->name . "`";
            $auditLog->save3pLogentry(52, $details, $tpRec['id']);
        }
        sleep(2);  // on small 3P population, don't end before caller can detect pid file
        $this->endTest(true); // alter pid file name when publishing
    }

    /**
     * Preliminary validation of risk model
     *
     * @param string $method Name of calling method
     *
     * @return boolean
     */
    private function isRiskModelSetup($method)
    {
        if (empty($method)) {
            return false;
        }
        $rtn = true;
        $rmRecord = false;
        // RisMode is neither BaseModel nor BaseLite,  RiskModels is BaseLite
        if ($rMdl = new RiskModel($this->rmodelID, $this->clientID)) {
            $rmRecord = $rMdl->getModel();
        }
        // valid risk model in setup mode for this client?
        if (empty($rMdl) || empty($rmRecord) || 'setup' !== $rmRecord->status) {
            $msg = $method . ' called with invalid riskModel.id '
                . $this->rmodelID . ' on client ' . $this->clientID;
            $this->app->log->error($msg);
            $rtn = false;
        }
        // set properties for other methods
        $this->rmRecord = $rmRecord;
        $this->rMdl = $rMdl;
        return $rtn;
    }

    /**
     * Validate and return CSV category list from risk model
     * This method provides a CSV list that can be used safely in queries w/o escaping.
     *
     * @return string CSV category list
     */
    private function getCategories()
    {
        $categories = '0';
        if ((new CsvIntList($this->rmRecord->categories))->isValid()) {
            $categories = $this->rmRecord->categories;
        }
        $this->categories = $categories;
        return $categories;
    }

    /**
     * Remove any test records already associated with this risk model
     *
     * @return void
     */
    private function removeTestRecords()
    {
        // delete any test assessments for this risk model
        $raMdl = new RiskAssessment($this->clientID);
        $raMdl->delete(['model' => $this->rmodelID, 'status' => 'test']);
        // set properties for other methods
        $this->raMdl = $raMdl;
        $this->getCategories();
    }

    /**
     * Remove pid file on completion of test
     *
     * @param boolean $publishing True if publishing, false (default) if testing
     *
     * @return void
     */
    private function endTest($publishing = false)
    {
        // delete the pidFile to signal completion
        $pidFile = $this->riMdl->getTestPidFile($this->rmodelID, $publishing);
        if (!empty($pidFile) && is_writable($pidFile)) {
            unlink($pidFile);
        }
    }
}
