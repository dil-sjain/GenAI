<?php
/**
 * Controller for Risk Inventory (create and manage risk models)
 */

namespace Controllers\TPM\Settings\ContentControl\RiskInventory;

use Models\TPM\Settings\ContentControl\RiskInventory\RiskInventory as RiData;
use Models\TPM\RiskModel\RiskModels;       // risk models in general
use Models\TPM\RiskModel\RiskTier;         // risk tiers in general
use Models\TPM\RiskModel\RiskModelTier;    // risk tiers in defined for risk models
use Models\TPM\RiskModel\RiskFactor;       // risk factor records
use Models\TPM\RiskModel\RiskAssessment;
use Models\TPM\RiskModel\RiskDetails;
use Models\TPM\RiskModel\CpiFormat;
use Models\TPM\RiskModel\CpiScore;
use Models\TPM\CaseTypeClientBL;
use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\Globals\Geography;
use Models\LogData; // audit log
use Models\TPM\CustomField;
use Models\TPM\CustomSelectList;
use Lib\Validation\Validate;
use Lib\Validation\Validator\MinMaxInt;
use Models\TPM\IntakeForms\Legacy\OnlineQuestions;
use Models\TPM\IntakeForms\DdqName;
use Models\ThirdPartyManagement\RiskModel;
use Lib\Traits\AjaxDispatcher;
use Lib\DateTimeEx;
use Lib\Support\ForkProcess;
use Models\TPM\Api\Endpoints\ThirdPartyProfile;
use Lib\FeatureACL;
use Models\TPM\RiskModel\RiskModelRole;
use Models\TPM\RiskModel\RiskModelMap;
use Lib\Validation\ValidateFuncs;

/**
 * Controller for Risk Inventory (create and manage risk models)
 *
 * @keywords risk inventory, risk, risk model, risk model setup
 */
#[\AllowDynamicProperties]
class RiskInventory
{
    use AjaxDispatcher;

    /**
     * @var \Skinny\Skinny application instance
     */
    protected $app = null;

    /**
     * @var object instance of RiskModel
     */
    protected $riskMdl = null;

    /**
     * @var string Score regex pattern -9999 thru 9999
     */
    protected $scorePat = '/^-?\d{1,5}$/'; // {1,5} allows for out of range test

    /**
     * @var boolean On validation success and database update quit setup and return to intro page
     */
    protected $quitAfterSave = false;

    /**
     * Invoke from route file
     *
     * @return void
     */
    public static function invoke()
    {
        $app = \Xtra::app();
        // Enforce permission
        if (!$app->ftr->has(\Feature::TENANT_TPM_RISK)
            || !$app->ftr->has(\Feature::CONFIG_RISK_INVENTORY)
        ) {
            if ($app->request->isAjax()) {
                $jsObj = \Lib\Traits\JsonOutput::initJsObj();
                $jsObj->Redirect = '/accessDenied';
                echo \Lib\Traits\JsonOutput::jsonEncodeResponse($jsObj);
            } else {
                $app->redirect('/accessDenied');
            }
            return;
        }

        $tenantID = $app->ftr->tenant;
        (new self($tenantID))->ajaxHandler();
    }

    /**
     * Initialize property values for instance
     *
     * @param integer $clientID TPM tenant ID (clientProfile.id)
     *
     * @return void
     */
    public function __construct(protected $clientID)
    {
        $this->app = \Xtra::app();
    }

    /**
     * Get values needed to display Intro page. Remove unused risk tiers
     *
     * @return array
     */
    private function getIntroVars()
    {
        $acls           = $this->app->ftr->__get('tenantFeatures');
        $mdl            = new RiskModels($this->clientID);
        $ctMdl          = new CaseTypeClientBL($this->clientID);
        $trText         = $this->app->trans->groups(['riskinv_intro', 'riskinv_steps']);
        $setupList      = $mdl->getSetupList();
        $pubList        = $mdl->getPublishedList();
        $riskTiers      = $this->getRiskTiers(true);
        $tplVars        = [
            'setupList'      => $setupList,
            'pubList'        => $pubList,
            'setupCnt'       => count($setupList),
            'latestCpiYear'  => (new CpiFormat())->selectValue('MAX(cpiYear)'),
            'pubCnt'         => count($pubList),
            'pubMap'         => $mdl->publishedMap(),
            'tpTypes'        => $mdl->getTpTypes(),
            'caseTypes'      => $ctMdl->getRecords(true),
            'hasRawScoreFtr' => $this->getRawScoreFeatures(),
            'modelRoles'     => $mdl->getRiskModelRoles(),
            'MRMEnabled'     => in_array(\Feature::MULTIPLE_RISK_MODEL, $acls)
        ];
        return [$tplVars, $trText, $riskTiers, date('Y-m-d')];
    }

    /**
     * Load content <div> for Risk Inventory tab
     *
     * @return void
     */
    private function ajaxInit()
    {
        $initArgs = $this->getIntroVars();
        $filesToLoad = [
            'assets/js/TPM/Settings/Control/RiskInventory/riskinv.css',
            'assets/js/TPM/Settings/Control/RiskInventory/intro||riskinvIntro.html',
            'assets/js/TPM/Settings/Control/RiskInventory/pubmap||riskinvPubMap.html',
            'assets/js/src/TPM/Settings/Control/RiskInventory/init-full.js',
            'assets/jq/plugin/dt/js/paging/select_links.min.js'
        ];
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.rxLoader.loadFiles';
        $this->jsObj->Args = [
            $filesToLoad,
            'appNS.riskinv.init',
            null,                 // disables orderTrack arg in rxLoader.loadFiles
            $initArgs,            // args to pass to init after rxLists and rxTrack
        ];
    }

    /**
     * Reload Intro page
     *
     * @return void
     */
    private function ajaxReloadIntro()
    {
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.loadIntro';
        $this->jsObj->Args = $this->getIntroVars();
    }

    /**
     * Get a list of available risk tiers
     *
     * @param boolean $deleteUnused Rmoved risk tiers that are not used in any model for this tenant
     *
     * @return array Risk tier records
     */
    private function getRiskTiers($deleteUnused = false)
    {
        $tierMdl = new RiskTier($this->clientID);
        if ($deleteUnused) {
            $tierMdl->deleteUnused();
        }
        return $tierMdl->selectMultiple(
            ['id', "tierName AS 'name'", "tierColor AS 'bg'", "tierTextColor AS 'fg'"],
            [],
            'ORDER BY tierName',
            true
        );
    }

    /**
     * Set jsObj to refresh tiers in step 2
     *
     * @param array $tierInfo Available risk tiers and model tiers
     *
     * @return void
     */
    private function refreshTiers($tierInfo)
    {
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.refreshTierDefs';
        $this->jsObj->Args = [
            $tierInfo['tierDefs'],
            $tierInfo['riskTiers'],
        ];
    }

    /**
     * Remove a model tier
     *
     * @return void
     */
    private function ajaxRemoveTier()
    {
        $err = null;
        $rmodelID = (int)$this->getPostVar('trk.mi');
        $tierID = (int)$this->getPostVar('tierID');

        // Is request valid?
        if (!$this->validRiskModelSetup($rmodelID)) {
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (mi)';
        } elseif (empty($tierID)) {
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (ti)';
        } elseif (1 !== (new RiskModelTier($this->clientID))->delete(['tier' => $tierID, 'model' => $rmodelID])) {
            // riskmodel / model tier references did not match a tier
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (0)';
        }
        if ($err) {
            $this->handleInvalidRequest($err);
            return;
        }

        // ensure bottom model tier threshold = 0
        $riMdl = new RiData($this->clientID);
        $riMdl->forceZeroTier($rmodelID);

        // get new available tiers, omitting any that are already used in tierDefs
        $tierInfo = $riMdl->getTiers($rmodelID);
        $this->refreshTiers($tierInfo);
    }

    /**
     * Add new band to defined tiers or update an existing one
     *
     * @return void
     */
    private function ajaxAddUpdateTier()
    {
        $err = null;
        $rmodelID = (int)$this->getPostVar('trk.mi');
        $tierForm = (int)$this->getPostVar('tierForm');

        // Is request valid?
        if (!$this->validRiskModelSetup($rmodelID)) {
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (mi)';
        } elseif (empty($tierForm)) {
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (tf)';
        }
        if ($err) {
            $this->handleInvalidRequest($err);
            return;
        }

        // Validate new tier inputs and return errors and tierValues
        $rtn = $this->validateTierForm($rmodelID);
        if ($rtn['errors']) {
            $this->handleMultiError($rtn['errors']);
            return;
        }

        // add/update tier as needed
        $riMdl = new RiData($this->clientID);
        $riMdl->upsertTier($rmodelID, $rtn['tierValues']);
        // get available tiers, omitting any that are already used in tierDefs
        $tierInfo = $riMdl->getTiers($rmodelID);
        $this->refreshTiers($tierInfo);
    }

    /**
     * Get data for resuming risk model setup
     *
     * @return void
     */
    private function ajaxResume()
    {
        $rmodelID = (int)$this->getPostVar('mid');
        $rmdl = new RiskModels($this->clientID);
        $riskRec = $rmdl->selectByID($rmodelID);
        if ($rmodelID <= 0 || empty($riskRec) || !isset($riskRec['status']) || $riskRec['status'] != 'setup') {
            if ($rmodelID <= 0) {
                $code = 'z'; // invalid model ID
            } elseif (empty($riskRec)) {
                $code = 'e'; // no such risk model for this client
            } elseif ($riskRec['status'] != 'setup') {
                $code = 's'; // bad status for resume
            } else {
                $code = 'u'; // ???
            }
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (' . $code . ')');
            return;
        }

        // Ensure risk mode is using latest CPI year
        $riMdl = new RiData($this->clientID);
        $riskRec = $riMdl->forceLatestCpiYear($riskRec);

        // Get new available tiers, omitting any that are already used in tierDefs
        $tierInfo = $riMdl->getTiers($rmodelID);
        $resumeData = [
            // values to update steps data
            'modelId' => $rmodelID,
            'defaultName' => $riskRec['name'],
            'cpiYear' => $riskRec['cpiYear'],
            'tierDefs' => $tierInfo['tierDefs'],
            'riskTiers' => $tierInfo['riskTiers'],
            'realRtLen' => count($tierInfo['riskTiers']),
            'ddqDefaultValue' => $riskRec['ddqDefaultValue'],
            'activeFactors' => RiskModel::componentsToFactors($riskRec['components']),
            'factorWeights' => unserialize($riskRec['weights']),

            // other values to resume setup
            'tpType' => $riskRec['tpType'],
            'cats' => explode(',', (string) $riskRec['categories']),
        ];
        if ($this->app->ftr->tenantHas(FeatureACL::MULTIPLE_RISK_MODEL)) {
            $resumeData['modelRole'] = $riskRec['riskModelRole'];
        } else {
            $primaryRole = $rmdl->getRiskModelRoles();
            $roleID = isset($primaryRole[0]['id']) ? $primaryRole[0]['id'] : 0;
            $resumeData['modelRole'] = $roleID;
        }

        // Here's the deal, we need to pass back two bits of info, that being
        //   - is the raw score 'feature' enabled/disabled, AND
        //   - is the associated raw score 'checkbox' option checked/unchecked
        // Just because the feature is enabled doesn't mean it's being used, but we need to pass both
        // flags back for proper UI behavior and assessment calculation!!
        $resumeData['hasRawScoreFtr'] = $this->getRawScoreFeatures();
        $resumeData['hasRawScoreOpt'] = $riMdl->getRawScoreColumns($rmodelID);

        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.resumeSetup';
        $this->jsObj->Args = [$resumeData];
    }

    /**
     * Check steps data
     *
     * @return void
     */
    private function ajaxCkStep()
    {
        $validateFuncs = new ValidateFuncs();
        // check required values for all steps
        $err = null;
        $stepTracking = $this->getPostVar('trk', []);
        $stepData = $this->getPostVar('sd');
        $this->quitAfterSave = (int)$this->getPostVar('qasf', 0);
        // validate tracking has all required keys
        $reqKeys = ['am', 'cs', 'ns', 'mi'];
        if (!is_array($stepTracking)) {
            // tracking not formatted as expected
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (trk)';
        } elseif (is_null($stepData) || empty($stepData)) {
            // no step data
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (sd)';
        } elseif (!empty($stepData['n']) && !$validateFuncs->checkInputSafety($stepData['n'])) {
            $err = 'Risk Model Name contains invalid characters like html tags or javascript.';
        } else {
            foreach ($reqKeys as $tk) {
                if (!array_key_exists($tk, $stepTracking)) {
                    // unexpected tracking key
                    $err = $this->app->trans->codeKey('err_invalidRequest') . ' (trk: ' . $tk . ')';
                    break;
                } elseif ($tk !== 'am') {
                    // convert to int
                    $stepTracking[$tk] = (int)$stepTracking[$tk];
                }
            }
            if (!$err) {
                if ($stepTracking['cs'] < 1 || $stepTracking['cs'] > 9) {
                    // invalid current step
                    $err = $this->app->trans->codeKey('err_invalidRequest') . ' (cs)';
                }
            }
        }
        if ($err) {
            $this->handleInvalidRequest($err);
            return;
        }

        // Validate and process current step data
        $stepFn = 'step' . $stepTracking['cs'];
        $this->$stepFn();
    }

    /**
     * Load a DDQ for rnedering and scoring
     *
     * @return void
     */
    private function ajaxLoadDdq()
    {
        $rmodelID = (int)$this->getPostVar('mi', 0);
        $ddqRef   = trim((string) $this->getPostVar('d', ''));
        $err = false;
        // valid risk model in setup status?
        if (!$this->validRiskModelSetup($rmodelID)) {
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (mi)';
        } elseif (!($questions = (new OnlineQuestions($this->clientID))->getRiskFactorQuestions($ddqRef, $rmodelID))) {
            // valid ddqRef?
            $err = $this->app->trans->codeKey('err_invalidRequest') . ' (d)';
        }
        if ($err) {
            $this->handleInvalidRequest($err);
            return;
        }
        $ddqName = (new DdqName($this->clientID))->selectValue('name', ['legacyID' => $ddqRef]);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.openDdq';
        $this->jsObj->Args = [$ddqRef, $ddqName, $questions];
    }

    /**
     * Make response for invalid request
     *
     * @param string $err Error message
     *
     * @return void
     */
    private function handleInvalidRequest($err)
    {
        $this->jsObj->ErrTitle = $this->app->trans->codeKey('errTitle_invalidRequest');
        $this->jsObj->ErrMsg = $err;
    }

    /**
     * Make response for multiError
     *
     * @param array  $errors       multiError nested array structure
     * @param string $titleCodeKey (optional) Code key for multiError title
     *
     * @return void
     */
    private function handleMultiError($errors, $titleCodeKey = null)
    {
        if (empty($titleCodeKey)) {
            $titleCodeKey = 'invalid_InputErrors';
        }
        $this->jsObj->MultiErr = $errors;
    }
    /**
     * Process step 1 -- Model name, 3P type and categories
     *
     * @return void
     */
    private function step1()
    {
        $rmodelID  = (int)$this->getPostVar('trk.mi', 0);
        $tpType    = (int)$this->getPostVar('sd.t', 0);
        $modelName = trim((string) $this->getPostVar('sd.n', ''));
        $rawName   = trim((string) $this->getRawPostVar('sd.n', ''));
        $cats      = $this->getPostVar('sd.c', []);
        $modelRoles = (int)$this->getPostVar('sd.r', 0);

        // tpType required, must belong to tenant
        // modelRole required, must belong to tenant
        // modelName required, must not duplicate, not > 255
        // categories - at least one required, must belong to tpType
        $trans = $this->app->trans;
        $stepTracking = $this->getPostVar('trk', []);
        $cfg = [
            $trans->codeKey('lbl_ThirdPartyType') => [
                [$this->validTpType($tpType), 'Generic', 'missing_or_invalid_input'],
            ],
            $trans->codeKey('riskinv_ModelName') => [
                [$rawName, 'NoHexChar'],
                [$modelName, 'Utf8String'],
                [$modelName, 'Rules', 'required|max_len,255'],
                [$this->validModelName($modelName, $stepTracking), 'Generic', 'err_name_in_use'],
            ],
            $trans->codeKey('lbl_3P_Category') => [
                [$this->validCategories($cats, $tpType), 'Generic', 'missing_or_invalid_input'],
            ],
        ];
        if ($this->app->ftr->tenantHas(FeatureACL::MULTIPLE_RISK_MODEL)) {
            $cfg['Risk Area'][] = [$this->validModelRole($modelRoles), 'Generic','missing_or_invalid_input'];
        }
        $tests = new Validate($cfg);
        if ($tests->failed) {
            $this->handleMultiError($tests->errors);
            return;
        }

        // create a new riskmodel, as needed
        $riskMdl = new RiskModels($this->clientID);
        $now = date('Y-m-d H:i:s');
        $mustUpdate = false;
        $setValues = [
            'name' => $modelName,
            'tpType' => $tpType,
            'categories' => implode(',', $cats),
            'updated' => $now,
        ];
        if ($this->app->ftr->tenantHas(FeatureACL::MULTIPLE_RISK_MODEL)) {
            $setValues['riskModelRole'] = $modelRoles;
        } else {
            $primaryRole = $riskMdl->getRiskModelRoles();
            $roleID = isset($primaryRole[0]['id']) ? $primaryRole[0]['id'] : 0;
            $setValues['riskModelRole'] = $roleID;
        }
        if ($this->getPostVar('trk.am') === 'create' && $rmodelID === 0) {
            $setValues['cpiYear'] = (new CpiFormat())->selectValue('MAX(cpiYear)');
            $rmodelID = $riskMdl->insert($setValues);
            // Audit Log
            // 82 Create Risk Model Draft
            $msg = '(#' . $rmodelID  . ') name: `' . $modelName . '`';
            (new LogData($this->clientID, $this->app->ftr->user))->saveLogEntry(82, $msg);
            $mustUpdate = true;
        } else {
            // update name, tpType and cats
            $riskMdl->updateByID($rmodelID, $setValues);
        }

        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $next = (int)$this->getPostVar('trk.ns');
        $this->jsObj->Args = [$next];
        $this->jsObj->Result = 1;
        if ($mustUpdate) {
            $this->jsObj->FuncName = 'appNS.riskinv.assignStepData';
            $this->jsObj->Args[] = ['modelId' => $rmodelID]; // append object arg
        } else {
            $this->jsObj->FuncName = 'appNS.riskinv.gotoStep';
        }
    }

    /**
     * Process step 2 -- Risk Tiers
     *
     * @return void
     */
    private function step2()
    {
        $err = null;
        $rmodelID = (int)$this->getPostVar('trk.mi');
        $tierDefCnt = (int)$this->getPostVar('sd.tdc');
        $ckCnt = (new RiskModelTier($this->clientID))->selectValue('COUNT(*)', ['model' => $rmodelID]);
        if (empty($ckCnt) || $tierDefCnt !== $ckCnt) {
            $trans = $this->app->trans;
            if (empty($ckCnt)) {
                $err = $trans->codeKey('riskinv_requireOneTier');
            } else {
                $err = $trans->codeKey('err_invalidRequest') . ' (!=tc)';
            }
            $this->jsObj->ErrTitle = $trans->codeKey('invalid_InputErrors');
            $this->jsObj->ErrMsg = $err;
            return;
        }

        // get components

        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.gotoStep';
        $this->jsObj->Args = [(int)$this->getPostVar('trk.ns')];
    }

    /**
     * Process step 3 -- Select components
     *
     * @return void
     */
    private function step3()
    {
        $rmodelID = (int)$this->getPostVar('trk.mi');
        $factors  = $this->getPostVar('sd.f', []);
        $weights  = $this->getPostVar('sd.w', []);
        $needCpiData   = (int)$this->getPostVar('sd.ncd');
        $needDdqData   = (int)$this->getPostVar('sd.ndd');
        $needCuFldData = (int)$this->getPostVar('sd.ncf');
        $rawScoreOpt = [
            'ddq'   => (bool)$this->getPostVar('sd.hasRawScoreOpt.ddq'),
            'cufld' => (bool)$this->getPostVar('sd.hasRawScoreOpt.cufld')
        ];

        $trans = $this->app->trans;
        // element count in factors and weights must be the same
        if (count($factors) !== count($weights)) {
            $this->handleInvalidRequest($trans->codeKey('err_invalidRequest') . ' (#)');
            return;
        }

        $errors = [];
        $compKeys = [
            'tpcat' => 'lbl_ThirdPartyCategory',
            'cpi'   => 'riskinv_CPI',
            'ddq'   => 'intakeFormsLegacy_title',
            'cufld' => 'tab_3p_Custom_Fields',
        ];

        // validate inputs
        $factorWeights = [];
        if (empty($factors)) {
            // at least one component is required
            $errors[$trans->codeKey('lbl_Enable')] = [$trans->codeKey('riskinv_requireOneFactor')];
        } else {
            // each weight must be between 1 and 100
            for ($i = 0; $i < count($weights); $i++) {
                $wgt = (int)$weights[$i];
                $fact = $factors[$i];

                // Check that the weight is in range, note that if the raw score option is enabled allow a min
                // value of 0 (zero), otherwise the min value must be at least 1. The reason for allowing a min
                // value of 0 if the option is checked is when the option is checked the weighted value is not
                // included in the calculation and therefore can be zero.
                $minWgt = match ($fact) {
                    'ddq' => $rawScoreOpt['ddq'] ? 0 : 1,
                    'cufld' => $rawScoreOpt['cufld'] ? 0 : 1,
                    default => 1,
                };

                $v = new MinMaxInt($wgt, [$minWgt, 100]);
                if (!$v->isValid()) {
                    $code = $v->getErrorCodes()[0];
                    $err = $trans->codeKey($code);
                    $tokens = $v->getErrorTokens();
                    foreach ($tokens[$code] as $tok => $val) {
                        $err = str_replace($tok, $val, (string) $err);
                    }
                    $errors[$trans->codeKey($compKeys[$fact])] = [$err];
                }
                $factorWeights[$fact] = $wgt;
            }

            // weight sum must be 100
            $ttl = 0;
            foreach ($weights as $w) {
                $ttl += $w;
            }
            if ($ttl !== 100) {
                $errors[$trans->codeKey('lbl_TotalPercent')]
                    = [$trans->codeKey('riskinv_require100Percent')];
            }
        }

        if (!empty($errors)) {
            $this->handleMultiError($errors);
            return;
        }

        // save factor selection and weights
        $components = RiskModel::factorsToComponents($factors);
        $updates = $this->updateScores($factors, $components, $factorWeights, $rmodelID, $rawScoreOpt);

        // calc next step
        $next = (int)$this->getPostVar('trk.cs');
        foreach (RiskModel::$componentMap as $comp => $num) {
            $next++;
            if (in_array($comp, $factors)) {
                break;
            }
        }

        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $riData = new RiData($this->clientID);
        $updates['factorData'] = $riData->getFactorData($rmodelID);

        if ($needCpiData) {
            $updates['countries'] = $riData->getCountries();
            $updates['cpiScores'] = $riData->getCpiScores($rmodelID);
            $hasCalcFtr = $this->app->ftr->tenantHas(FeatureACL::CPI_CALC_3P_DDQ_COUNTRIES);
            $updates['cpiFeature'] = $hasCalcFtr;
            if ($hasCalcFtr) {
                $updates['hrcs'] = $riData->getFlagFromOtherSources($rmodelID);
            }
        }
        if ($needDdqData) {
            $choose = $trans->codeKey('select_default');
            $none = $trans->codeKey('select_none');
            $updates['ddqLists'] = $riData->getDdqSelects($rmodelID, $choose, $none);
        }
        if ($needCuFldData) {
            $cfData = $riData->getCuFldData();
            $updates['cuFlds'] = $cfData['fields'];    // simple array
            $updates['cfListItems'] = $cfData['listItems']; // assoc array
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.assignStepData';
        $this->jsObj->Args = [$next, $updates]; // append object arg
    }

    /**
     * Test input for integer between 0 and 100 (percent)
     * Side effect; converts $num to int if it passes preg_match
     *
     * @param mixed $num String or integer input
     *
     * @return mixed false on success or translated error
     */
    private function invalidPercent(mixed &$num)
    {
        $rtn = false;
        $trans = $this->app->trans;
        $num = trim((string) $num);
        if (!preg_match('/^\d{1,3}$/', $num)) {
            $rtn = $trans->codeKey('missing_or_invalid_input');
        } else {
            $num = (int)$num;
            $v = new MinMaxInt($num, [0, 100]);
            if (!$v->isValid()) {
                $code = $v->getErrorCodes()[0];
                $err = $trans->codeKey($code);
                $tokens = $v->getErrorTokens();
                foreach ($tokens[$code] as $tok => $val) {
                    $err = str_replace($tok, $val, (string) $err);
                }
                $rtn = $err;
            }
        }
        return $rtn;
    }

    /**
     * Test input score for integer between -9999 and 9999
     * Side effect; converts $score to int if it passes preg_match
     *
     * @param mixed $score String or integer score, as input
     *
     * @return mixed false on success or translated error
     */
    private function invalidScore(mixed &$score)
    {
        $rtn = false;
        $trans = $this->app->trans;
        $score = trim((string) $score);
        if (!preg_match($this->scorePat, $score)) {
            $rtn = $trans->codeKey('missing_or_invalid_input');
        } else {
            $score = (int)$score;
            $v = new MinMaxInt($score, [-9999, 9999]);
            if (!$v->isValid()) {
                $code = $v->getErrorCodes()[0];
                $err = $trans->codeKey($code);
                $tokens = $v->getErrorTokens();
                foreach ($tokens[$code] as $tok => $val) {
                    $err = str_replace($tok, $val, (string) $err);
                }
                $rtn = $err;
            }
        }
        return $rtn;
    }

    /**
     * Process step 4 -- 3P Category risk factor
     *
     * @return void
     */
    private function step4()
    {
        // validate tpcat scores
        $errors = [];
        $trans = $this->app->trans;
        $rmodelID = (int)$this->getPostVar('trk.mi');
        $riskRec = (new RiskModels($this->clientID))->selectByID($rmodelID, ['tpType', 'categories']);
        $cmpCats = !empty($riskRec['categories']) ? explode(',', (string) $riskRec['categories']) : [];
        $scores = $this->getPostVar('sd.s');
        // must have as many scores as selected categories
        if (count($scores) !== count($cmpCats)) {
            $this->handleInvalidRequest($trans->codeKey('err_invalidRequest') . ' (ec)');
            return;
        }
        // each score must be integer between -9999 and 9999
        $catMdl = null; // instantiate only if needed
        $factorScores = [];
        foreach ($scores as $catRef => $score) {
            if ($err = $this->invalidScore($score)) {
                if (empty($catMdl)) {
                    $catMdl = new TpTypeCategory($this->clientID);
                }
                $catName = $catMdl->selectValueByID((int)substr((string) $catRef, 6), 'name');
                $errors[$catName] = [$err];
            } else {
                $factorScores[$catRef] = $score;
            }
        }

        // abort on error
        if (!empty($errors)) {
            $this->handleMultiError($errors);
            return;
        }

        // calc max and min scores
        $testScores = array_values($factorScores);
        if (!in_array(0, $testScores)) {
            $testScores[] = 0;
        }
        $minScore = min($testScores);
        $maxScore = max($testScores);

        $rkModel = new RiData($this->clientID);
        // save factor data
        // Legacy deviation: scores are stored as integers instead of strings
        $factor = new \stdClass();
        $factor->tpType = $riskRec['tpType'];
        $factor->maxScore = $maxScore;
        $factor->minScore = $minScore;
        $factor->scores = $factorScores;
        $updates = [
            'factorData' => $rkModel->upsertFactor($rmodelID, 'tpcat', $factor),
        ];

        $hasCalcFtr = $this->app->ftr->tenantHas(FeatureACL::CPI_CALC_3P_DDQ_COUNTRIES);
        if ($hasCalcFtr) {
            $hrcs = $rkModel->getFlagFromOtherSources($rmodelID);
            $updates['$hrcs'] = $hrcs;
        }

        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.assignStepData';
        $this->jsObj->Args = [(int)$this->getPostVar('trk.ns'), $updates]; // append object arg
    }

    /**
     * Process step 5 -- CPI risk factor
     *
     * @return void
     */
    private function step5()
    {
        $errors = [];
        $trans = $this->app->trans;
        $rmodelID = (int)$this->getPostVar('trk.mi');
        $cpiYear = (new RiskModels($this->clientID))->selectValueByID($rmodelID, 'cpiYear');
        $hasCalcFtr = $this->app->ftr->tenantHas(FeatureACL::CPI_CALC_3P_DDQ_COUNTRIES);

        // Validate inputs
        $fd = $this->getPostVar('sd.fd', []);
        $unlisted   = trim((string) $this->getPostVar('sd.fd.unlisted'));

        // 3p = 3P Summary Tab
        // modal = Doing Business in Modal
        // ddq = DDQ Country Address
        // carc = Client associated Risk Country
        $hrcs = $this->getPostVar('sd.hrcs', []); // highest risk country selections, 3p is always there

        // tests
        //   $unlisted = '';    // missing/invalid
        //   $unlisted = 10000; // out of range
        $overrides  = $this->getPostVar('sd.fd.overrides', []);
        // tests
        //   $overrides = 0; // o1
        //   $overrides['cpioverride-XX'] = 0;  // o2
        //   $overrides['cpioverride-XX'] = 0;  // o3
        //   $overrides['cpioverride-CA'] = ''; // missing/invalid
        //   $overrides['cpioverride-CA'] = 10000; // out of range
        $thresholds = $this->getPostVar('sd.fd.thresholds', []);
        // tests, also trigger s1 failure
        //   $thresholds = ''; // t1
        //   $thresholds['cpithresh-XX'] = 0;  // t2
        //   $thresholds['cpithresh-100'] = 77; // t3
        //   $thresholds['cpithresh-100'] = 100; // t4
        $scores     = $this->getPostVar('sd.fd.scores', []);
        // tests
        //   $scores = ''; // s1, not an array
        //   $scores = []; // s1, count < 1
        //   unset($scores['cpirangescore-0']);  // s1, no score for 0 (bottom)
        //   $scores['cpirangescore-0'] = '';    // missing or invalid
        //   $scores['cpirangescore-0'] = 10000; // out of range
        $allScores  = ['default' => 0]; // collect all valid scores to determine max/min

        $geo         = null; // instantiate if/when needed
        $cpiScoreMdl = null; // ...
        $match       = [];   // non-essential, but it's role in preg_match makes it logical to define

        // unlisted
        //   required, integer within -9999 thru 9999 range
        if ($err = $this->invalidScore($unlisted)) {
            $errors[$trans->codeKey('riskinv_unlistedCpiScore')] = [$err];
        } else {
            $allScores['unlisted'] = $unlisted;
        }

        // overrides
        //   optional, iso must exit, score must be valid integer range
        $errKey = $trans->codeKey('riskinv_CpiOverrides');
        if (!is_array($overrides)) {
            $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (o1)'];
        } else {
            // break on any error in override scores
            foreach ($overrides as $ref => $score) {
                if (!preg_match('/^cpioverride-([A-Z2]{2})$/', $ref, $match)) {
                    // request does not have valid format
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (o2)'];
                    break;
                }
                $iso = $match[1];
                if ($geo == null) {
                    $geo = Geography::getVersionInstance();
                }
                if ($iso !== $geo->getLegacyCountryCode($iso)) {
                    // submitted range that is not in submitted thresholds
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (o3)'];
                    break;
                }
                if ($err = $this->invalidScore($score)) {
                    $errors[$errKey] = [$err];
                    break;
                }
                $allScores[$iso] = $score;
                $overrides[$ref] = $score; // save as int
            }
        }


        // thresholds
        //   optional, threshold must be a valid cpi score in cpiYear
        $errKey = $trans->codeKey('riskinv_CpiThreshRange');
        $validRanges = [];
        if (!is_array($thresholds)) {
            $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (t1)'];
        } else {
            foreach ($thresholds as $ref => $cpi) {
                if (!preg_match('/^cpithresh-(\d+)$/', $ref, $match)) {
                    // request does not have valid format
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (t2)'];
                    break;
                }
                $thresh = (int)$match[1];
                $cpi = (int)$cpi;
                if ($cpiScoreMdl == null) {
                    $cpiScoreMdl = new CpiScore();
                }
                if ($thresh !== $cpi) {
                    // invalid format
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (t3)'];
                    break;
                }
                $values = $cpiScoreMdl->selectOne(['score', 'isoCode'], ['cpiYear' => $cpiYear, 'score' => $cpi]);
                $cpiFromDB = false;
                if ($values) {
                    $cpiFromDB = $values['score'];
                    $iso = $values['isoCode'];
                }
                if ($cpiFromDB && $cpi !== (int)$cpiFromDB) {
                    // invalid cpi (score) for cpiYear
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (t4)'];
                    break;
                }
                $validRanges[$iso] = $cpi;
                $thresholds[$ref] = $cpi; // save as int
            }
        }

        if ($hasCalcFtr) {
            $riskInvModel = new RiData($this->clientID);
            $otherScores = [];
            foreach ($hrcs as $highestRiskCountry) {
                $newScores = [];
                if ($highestRiskCountry === '3p') { // third party
                    $newScores = $riskInvModel->getScoresFromThirdParty($cpiYear);
                } elseif ($highestRiskCountry === 'modal') { // doing business in modal
                    $newScores = $riskInvModel->getScoresFromModal($cpiYear);
                } elseif ($highestRiskCountry === 'ddq') { // ddq
                    $newScores = $riskInvModel->getScoresFromDdq($cpiYear);
                } elseif ($highestRiskCountry === 'carc') { // client associated risk country
                    $newScores = $riskInvModel->getScoresFromCountryOverride($cpiYear);
                }
                $otherScores = array_merge($otherScores, $newScores);
            }
        }


        // scores for cpi ranges
        //   score for 0 required, except 0 must match submitted threshold, score must be valid int in range
        $errKey = $trans->codeKey('riskinv_ScoreRanges');
        if (!is_array($scores)
            || count($scores) < 1
            || !is_array($thresholds) // already tested, but needed to prevent error in next condition
            || (1 !== (count($scores) - count($thresholds)))
            || !array_key_exists('cpirangescore-0', $scores)
        ) {
            $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (s1)'];
        } else {
            // break on any error in range scores
            foreach ($scores as $ref => $score) {
                if (!preg_match('/^cpirangescore-(\d+)$/', $ref, $match)) {
                    // request does not have valid format
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (s2)'];
                    break;
                }
                $thresh = (int)$match[1];
                $iso = array_search($thresh, $validRanges); // gets key or false otherwise
                if ($thresh > 0 && !$iso) {
                    // submitted range that is not in submitted thresholds
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (s3)'];
                    break;
                }
                if ($err = $this->invalidScore($score)) {
                    $errors[$errKey] = [$err];
                    break;
                }
                $allScores[$iso] = $score;
                $scores[$ref] = $score; // save as int
            }
        }

        // abort on error
        if (!empty($errors)) {
            $this->handleMultiError($errors);
            return;
        }
        if ($hasCalcFtr) {
            $values = $this->getMinMaxCountries($otherScores);
            $minObjOther = new \stdClass(); // minimum CPI
            $maxObjOther = new \stdClass(); // maximum CPI
            $minObjOther = $values[0];
            $maxObjOther = $values[1];

            $tempMax = -1;
            $tempMin = -1;
            foreach ($scores as $ref => $score) {
                if (!preg_match('/^cpirangescore-(\d+)$/', (string) $ref, $match)) {
                    // request does not have valid format
                    $errors[$errKey] = [$trans->codeKey('err_invalidRequest') . ' (s2)'];
                    break;
                }
                $thresh = (int)$match[1];
                if ($maxObjOther->score > $thresh) {
                    $tempMax = $score;
                }
                if ($minObjOther->score < $thresh) {
                    $tempMin = $score;
                }
            }
            $allScores[$maxObjOther->iso] = $tempMax;
            $allScores[$minObjOther->iso] = $tempMin;
        }

        $minObj = new \stdClass(); // minimum Securimate score
        $maxObj = new \stdClass(); // maximum Securimate score
        $values = $this->getMinMaxCountries($allScores);
        $minObj = $values[0];
        $maxObj = $values[1];

        $cpiRange = [];

        if (!isset($maxObj->iso) || is_null($maxObj->iso) || $maxObj->iso == 0) {
            foreach ($scores as $ref => $score) {
                preg_match('/^cpirangescore-(\d+)$/', (string) $ref, $match);
                $thresh = (int)$match[1];
                $cpiRange[] = $thresh;
                if ($maxObj->score == $score) {
                    $cpiScoreMdl = new CpiScore();
                    $totalRanges = count($cpiRange);
                    $between = '';
                    if ($totalRanges == 1) {
                        $between = "BETWEEN 0 AND " . $cpiRange[0];
                    } else {
                        $between = "BETWEEN " . $cpiRange[$totalRanges - 1] . " AND " . $cpiRange[$totalRanges - 2];
                    }

                    $values = $cpiScoreMdl->selectCountryBetweenRanges($between, $cpiYear);

                    $maxObj->iso = $values;
                }
            }
        }

        // save cpi factor data
        // Legacy deviation: scores and thresholds are stored as integers instead of strings
        $factor = new \stdClass();
        $factor->cpiYear = 'score' . $cpiYear;
        $factor->maxScore = $maxObj->score;
        $factor->minScore = $minObj->score;
        $factor->maxScoreCountry = $maxObj->iso;
        $factor->minScoreCountry = $minObj->iso;
        $factor->unlisted = $unlisted;
        $factor->overrides = $overrides;
        $factor->thresholds = $thresholds;
        $factor->scores = $scores;
        $updates = [
            'factorData' => (new RiData($this->clientID))->upsertFactor($rmodelID, 'cpi', $factor, '', $hrcs),
        ];

        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.assignStepData';
        $this->jsObj->Args = [(int)$this->getPostVar('trk.ns'), $updates]; // append object arg
    }

    /**
     * Based upon a range of CPI scores, get the minimum and maximum CPI scores
     *
     * @param array $scores Array of CPI scores
     *
     * @return array Array containing min and max CPI scores
     */
    private function getMinMaxCountries($scores)
    {
        $minObj = new \stdClass();
        $maxObj = new \stdClass();
        $minObj->score = PHP_INT_MAX;
        $minObj->iso = '';
        $maxObj->score = ~PHP_INT_MAX; // ~ getting the minimum
        $maxObj->iso = '';

        foreach ($scores as $iso => $score) {
            if ($score > $maxObj->score && $iso !== '') {
                $maxObj->score = $score;
                $maxObj->iso = $iso;
            }
            if ($score < $minObj->score && $iso !== '') {
                $minObj->score = $score;
                $minObj->iso = $iso;
            }
        }
        $return = [$minObj, $maxObj];
        return $return;
    }

    /**
     * Process step 6 -- DDQ risk factor
     *
     * @return void
     */
    private function step6()
    {
        $errors = [];
        $trans = $this->app->trans;
        $rmodelID = (int)$this->getPostVar('trk.mi');

        // Default score must be percent between 0 and 100
        $defaultValue = $this->getPostVar('sd.dv');  // DO NOT CONVERT TO INT
        if ($res = $this->invalidPercent($defaultValue)) {
            $errors[$trans->codeKey('riskinv_defaultScore')] = [$res];
        }

        // Must have at least one scored ddq
        $factorData = (new RiData($this->clientID))->getFactorData($rmodelID);
        if (empty($factorData['ddq'])) {
            $errors[$trans->codeKey('lbl_Scored')] = [$trans->codeKey('riskinv_scoreOneDdq')];
        }

        // abort on error
        if (!empty($errors)) {
            $this->handleMultiError($errors);
            return;
        }

        // Save default value to risk model. Scored DDQs have already been saved.
        (new RiskModels($this->clientID))->updateByID($rmodelID, ['ddqDefaultValue' => $defaultValue]);

        $updates = [
            'ddqDefaultValue' => $defaultValue,
        ];

        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.assignStepData';
        $this->jsObj->Args = [(int)$this->getPostVar('trk.ns'), $updates]; // append object arg
    }

    /**
     * Remove scored DDQ
     *
     * @return void
     */
    private function ajaxRemoveDdq()
    {
        $trans = $this->app->trans;
        // Valid risk model in setup status?
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        // Is this ddq scorable?
        $ddqRef = trim((string) $this->getPostVar('dr'));
        if (!$this->isScorableDdq($rmodelID, $ddqRef)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (dr)');
            return;
        }

        // Delete the ddq factor
        (new RiskFactor($this->clientID))->delete(['model' => $rmodelID, 'ddqRef' => $ddqRef, 'component' => 'ddq']);

        // Get updated factor data
        $riData = new RiData($this->clientID);
        $factorData = $riData->getFactorData($rmodelID);

        // Get DDQ lists (scored and unscored)
        $choose = $trans->codeKey('select_default');
        $none = $trans->codeKey('select_none');
        $ddqLists = $riData->getDdqSelects($rmodelID, $choose, $none);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.endDdqOperation';
        $this->jsObj->Args = [$ddqLists, $factorData];
    }

    /**
     * Save scored DDQ
     *
     * @return void
     */
    private function ajaxSaveDdq()
    {
        $trans = $this->app->trans;
        $errors = [];
        // Valid risk model in setup status?
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        // Is this ddq scorable?
        $ddqRef = trim((string) $this->getPostVar('dr'));
        if (!$this->isScorableDdq($rmodelID, $ddqRef)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (dr)');
            return;
        }

        // Validate scores
        $scores = $this->getPostVar('s', []);
        if (empty($scores)) {
            // at least one question must be selected and scored
            $errors[$trans->codeKey('riskinv_selectDDQs')] = [$trans->codeKey('riskinv_reqOneDdqQues')];
        }
        $componentMax = 0;
        $componentMin = 0;
        $factorScores = [];
        foreach ($scores as $questionID => $items) {
            $factorScores[$questionID] = [];
            $quesScores = [0];
            foreach ($items as $idStr => $score) {
                if ($err = $this->invalidScore($score)) {
                    $errors[$questionID] = [$err];
                    break;
                } else {
                    $factorScores[$questionID][$idStr] = $score;
                    $quesScores[] = $score;
                }
            }
            $componentMax += max($quesScores);
            $componentMin += min($quesScores);
        }

        // abort on error
        if (!empty($errors)) {
            $this->handleMultiError($errors);
            return;
        }

        // DDQ name is optional. Default name is legachID. Legacy performed no further validation.
        $ddqName = trim((string) $this->getPostVar('n', $ddqRef)); // optional
        $dnMdl = new DdqName($this->clientID);
        if ($dnMdl->upsertName($ddqRef, $ddqName)) {
            // get it back in case it was truncated
            $ddqName = $dnMdl->selectValue('name', ['legacyID' => $ddqRef]);
        }

        // Upsert ddq factor
        $riData = new RiData($this->clientID);
        $factor = new \stdClass();
        $factor->version = $ddqRef;
        $factor->maxScore = $componentMax;
        $factor->minScore = $componentMin;
        ;
        $factor->scores = $factorScores;
        $factorData = $riData->upsertFactor($rmodelID, 'ddq', $factor, $ddqRef);

        // Get DDQ lists (scored and unscored)
        $choose = $trans->codeKey('select_default');
        $none = $trans->codeKey('select_none');
        $ddqLists = $riData->getDdqSelects($rmodelID, $choose, $none);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.afterDdqSave';
        $this->jsObj->Args = [$ddqName, $ddqLists, $factorData];
    }

    /**
     * Process step 7 -- Custom Fields risk factor
     *
     * @see SEC-2637
     *
     * @return void
     */
    private function step7()
    {
        // ### BUG: checkbox max score error ###
        // Legacy sums check box scores conditionally - not a true sum
        // Change next line to false to use true sum only if SEC-2637 is approved
        $likeLegacy = true;

        $errors = [];
        $trans = $this->app->trans;
        $rmodelID = (int)$this->getPostVar('trk.mi');

        // Validate inputs
        $fd = $this->getPostVar('sd.fd', []);
        $unanswered = trim((string) $this->getPostVar('sd.fd.unanswered'));
        // tests
        //   $unanswered = '';  // missing/invalid
        //   $unanswered = 101; // out of range
        $fldCnt = (int)$this->getPostVar('sd.fd.fldCnt', 0);
        // test
        //   $fldCnt = 0;
        $flds = $this->getPostVar('sd.fd.fields', []);

        $fields = [];
        $sumMax = 0;   // sum of all fldmax
        $sumMin = 0;   // sum of all fldmin
        $match  = [];  // non-essential, but it's role in preg_match makes it logical to initialize

        // unanswered
        //   required, integer between 0 and 100
        if ($err = $this->invalidPercent($unanswered)) {
            $errors[$trans->codeKey('riskinv_percentUnansweredField')] = [$err];
        }
        // must select at least one field
        if ($fldCnt <= 0) {
            $errors[$trans->codeKey('riskinv_selectCustomFields')] = [$trans->codeKey('invalid_Required')];
        } else {
            $fldMdl = new CustomField($this->clientID);
            $itemMdl = new CustomSelectList($this->clientID);
            foreach ($flds as $fld => $fldSpec) {
                // test invalid field reference
                $fid = (int)substr((string) $fld, 6);
                $fields[$fld] = [];
                // $fldSpec is 'scoreCnt' integer and 'scores' assoc array
                $scoreCnt = (int)\Xtra::arrayGet($fldSpec, 'scoreCnt', 0);
                // is it an eligble field
                if (!($fldInfo = $fldMdl->riskFactorFieldByID($fid))) {
                    $errors[$trans->codeKey('errTitle_invalidRequest')]
                        = [$trans->codeKey('err_InvalidCustomFieldRef')];
                    break; // invalid field reference is a bad request. Don't keep processing
                } else {
                    $fields[$fld]['type'] = $fldInfo['type'];
                    $errKey = $trans->codeKey('lbl_Field') . ': ' . $fldInfo['name'];
                    if ('numeric' === $fldInfo['type']) {
                        // numeric has no score. scoreCnt is 0
                        if ($scoreCnt > 0) {
                            $errors[$errKey] = [$trans->codeKey('err_NoScoreNumericCuFld')];
                        }
                        $fields[$fld]['fldmax'] = $fldmax = max([0, (int)$fldInfo['maxValue']]);
                        $fields[$fld]['fldmin'] = $fldmin = min([0, (int)$fldInfo['minValue']]);
                        $sumMax += $fldmax;
                        $sumMin += $fldmin;
                    } else {
                        // score sum to used to compute a score for unanswered checkbox custom field
                        if ($likeLegacy) {
                            $tmpMaxSum = 0; // for checkbox - fldmax is sum of all positive scores
                            $tmpMinSum = 0; // for checkbox - fldmin is sum of all negative scores
                        } else {
                            $tmpSum = 0; // for checkbox - fldmax is sum of all scores
                        }
                        $tmpScores = [];
                        $fields[$fld]['fldmax'] = 0;
                        $fields[$fld]['fldmin'] = 0;
                        $fields[$fld]['scores'] = [];
                        if ($scoreCnt < 1) {
                            $errors[$errKey] = [$trans->codeKey('err_NoCuFldListItems')];
                        } else {
                            $badScore = false;
                            foreach ($fldSpec['scores'] as $itemRef => $score) {
                                // test invalid item reference
                                $iid = (int)substr((string) $itemRef, 5);
                                if (!$itemMdl->validListItemByID($iid, $fldInfo['listName'])) {
                                    $errors[$errKey] = [$trans->codeKey('err_itemNotInCuFldList')];
                                    $badScore = true;
                                    break; // stop checking item scores
                                } elseif ($err = $this->invalidScore($score)) {
                                    $errors[$errKey] = [$err];
                                    $badScore = true;
                                    break; // stop checking item scores
                                } else {
                                    $fields[$fld]['scores']['cufld-' . $fid . '-' . $iid] = $score;
                                    if ($likeLegacy) {
                                        if ($score > 0) {
                                            $tmpMaxSum += $score;
                                        } elseif ($score < 0) {
                                            $tmpMinSum += $score;
                                        }
                                    } else {
                                        $tmpSum += $score;
                                    }
                                    $tmpScores[] = $score;
                                }
                            }
                            if (!$badScore) {
                                if ('check' === $fldInfo['type']) {
                                    if ($likeLegacy) {
                                        $fields[$fld]['fldmax'] = $fldmax = $tmpMaxSum;
                                        $fields[$fld]['fldmin'] = $fldmin = $tmpMinSum;
                                    } else {
                                        $fields[$fld]['fldmax'] = $fldmax = max([0, $tmpSum]);
                                        $fields[$fld]['fldmin'] = $fldmin = min([0, $tmpSum]);
                                    }
                                } else {
                                    $fields[$fld]['fldmax'] = $fldmax = max([0, max($tmpScores)]);
                                    $fields[$fld]['fldmin'] = $fldmin = min([0, min($tmpScores)]);
                                }
                                $sumMax += $fldmax;
                                $sumMin += $fldmin;
                            }
                        }
                    }
                }
            }
        }

        // abort on error
        if (!empty($errors)) {
            $this->handleMultiError($errors);
            return;
        }

        // Save cufld factor data
        // Legacy deviation: scores and thresholds are stored as integers instead of strings
        $factor = new \stdClass();
        $factor->unanswered = $unanswered;
        $factor->maxScore = $sumMax;
        $factor->minScore = $sumMin;
        $factor->fields = $fields;

        $updates = [
            'factorData' => (new RiData($this->clientID))->upsertFactor($rmodelID, 'cufld', $factor),
        ];
        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.assignStepData';
        $this->jsObj->Args = [(int)$this->getPostVar('trk.ns'), $updates]; // append object arg
    }

    /**
     * Validate if okay to publish
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return boolean
     */
    private function okayToPublish($rmodelID)
    {
        $trans = $this->app->trans;
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($trans->codeKey('err_invalidRequest') . ' (mi)');
            return false;
        }
        // Ensure test is not still running
        $state = (new RiData($this->clientID))->getTestState($rmodelID);
        if ($state['runStatus'] == 'running') {
            $this->jsObj->ErrTitle = $trans->codeKey('title_IncompleteTest');
            $this->jsObj->ErrMsg = $trans->codeKey('msg_TestStillRunning');
            return false;
        }
        if ($state['runStatus'] === 'setup') {
            // no profiles have been assessed; test not run
            $this->jsObj->ErrTitle = $trans->codeKey('title_InsufficientTest');
            $this->jsObj->ErrMsg = $trans->codeKey('msg_InsufficientRiskModelTest');
            return false;
        }
        if ($state['elapsed'] > (12 * 3600)) {
            // test is older than 12 hours
            $this->jsObj->ErrTitle = $trans->codeKey('title_StaleTestResults');
            $this->jsObj->ErrMsg = $trans->codeKey('msg_StaleRiskModelTest');
            return false;
        }
        return true;
    }

    /**
     * Process step 8 -- Test risk model
     *
     * @return void
     */
    private function step8()
    {
        // Validate conditions to publish
        $rmodelID = (int)$this->getPostVar('trk.mi');
        if (!$this->okayToPublish($rmodelID)) {
            return;
        }
        // goto next step
        if ($this->quitAfterSave) {
            $this->ajaxReloadIntro();
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.gotoStep';
        $this->jsObj->Args = [(int)$this->getPostVar('trk.ns')];
    }

    /**
     * Process step 9 -- Publish risk model
     *
     * @return void
     */
    private function step9()
    {
        // goto next step
        $this->jsObj->Result = 1;
    }

    /**
     * Get test details
     *
     * @param integer $rmodelID risModelId
     *
     * @return mixed void or test details
     */
    private function getTestDetails($rmodelID)
    {
        if (empty($rmodelID)) {
            return;
        }
        $trans = $this->app->trans;
        $state = (new RiData($this->clientID))->getTestState($rmodelID);
        $status = '...';
        // Expand values from $state (more controlled than native extract)
        foreach (['profiles', 'assessed', 'runStatus', 'elapsed', 'summary'] as $fld) {
            ${$fld} = $state[$fld];
        }
        if ($profiles) {
            $percent = round($assessed / (float)$profiles, 2);
        } else {
            $percent = 0;
        }
        // runStatus is null, 'running' or 'complete'
        if ($runStatus !== null) {
            $progressMsg = str_replace(
                ['{#num}', '{#total}'],
                [number_format($assessed, 0), number_format($profiles, 0)],
                (string) $trans->codeKey('completionStatus')
            );
            if ($runStatus == 'complete') {
                $elapsedTxt = DateTimeEx::expressElapsedTime($elapsed);
                $trElapsed = DateTimeEx::translateTimeExpression($elapsedTxt, $trans);
                $status = str_replace(
                    ['{#event}', '{#elapsed}'],
                    [$progressMsg, $trElapsed],
                    (string) $trans->codeKey('elapsedTimeSinceEvent')
                );
            } else {
                $status = $progressMsg;
            }
        }
        return [
            $percent,
            $assessed,
            $profiles,
            $status,
            $runStatus,
            $summary,
        ];
    }

    /**
     * Determine state of testing
     *
     * @return void
     */
    private function ajaxGetTestStatus()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        [$percent, $assessed, $profiles, $status, $runStatus, $summary]
            = $this->getTestDetails($rmodelID);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.refreshTesting';
        $this->jsObj->Args = [
            $percent,
            $assessed,
            $profiles,
            $status,
            $runStatus,
            $summary,
        ];
    }

    /**
     * Determine test progress (null, running, complete) with appropriate messages and data
     *
     * @return void
     */
    private function ajaxUpdateTestProgress()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        [$percent, $assessed, $profiles, $status, $runStatus, $summary]
            = $this->getTestDetails($rmodelID);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.updateTestProgress';
        $this->jsObj->Args = [
            $percent,
            $assessed,
            $profiles,
            $status,
            ($runStatus == 'complete'),
            $summary,
        ];
    }

    /**
     * Generate new test data for risk model
     *
     * @return void
     */
    private function ajaxTestRiskModel()
    {
        $trans = $this->app->trans;
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        $riMdl = new RiData($this->clientID);
        $pidFile = $riMdl->getTestPidFile($rmodelID);
        // kill existing process
        if (is_readable($pidFile)) {
            if ($pid = (int)trim(file_get_contents($pidFile))) {
                shell_exec("kill $pid"); // SIGTERM
            }
        }
        // Remove any existing test records
        (new RiskAssessment($this->clientID))->delete(['model' => $rmodelID, 'status' => 'test']);

        // fork new process
        $this->jsObj->Result = 1;
        $cmd = "Controllers.TPM.Settings.ContentControl.RiskInventory"
            . ".TestRiskModel::run _c:{$this->clientID} $rmodelID";
        if (!($pid = (int)(new ForkProcess())->launch($cmd))) {
            $this->jsObj->FuncName = 'appNS.riskinv.startTestFailed';
            $this->jsObj->Args = [
                $trans->codeKey('title_operation_failed'),
                $trans->codeKey('status_Tryagain'),
            ];
            return;
        }
        // write the pid file and start monitoring
        file_put_contents($pidFile, $pid, LOCK_EX);
        $this->jsObj->FuncName = 'appNS.riskinv.startTestMonitor';
    }

    /**
     * Delete a risk model
     *
     * @return void
     */
    private function ajaxDeleteRiskModel()
    {
        $trans = $this->app->trans;
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        // disallow if test is running
        $riData = new RiData($this->clientID);
        $state = $riData->getTestState($rmodelID);
        if ($state['runStatus'] == 'running') {
            $this->jsObj->ErrTitle = $trans->codeKey('title_IncompleteTest');
            $this->jsObj->ErrMsg = $trans->codeKey('msg_TestStillRunning');
            return;
        }
        // Delete the risk model and all its related records
        $riData->deleteRiskModel($rmodelID, $this->app->ftr->user);
        // Reload intro to clear out browser and get updated model map
        $this->ajaxReloadIntro();
    }

    /**
     * Get sample test profiles
     *
     * @return void
     */
    private function ajaxSampleProfiles()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.appendSampleProfiles';
        $this->jsObj->Args = [(new RiData($this->clientID))->sampleProfiles($rmodelID)];
    }

    /**
     * Get data for Risk Model Detail pop-up
     *
     * @return void
     */
    private function ajaxModelDetail()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        $riskRec = (new RiskModels($this->clientID))->selectByID($rmodelID, ['status']);
        if (empty($riskRec)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        $details = (new RiskDetails($this->clientID))->modelDetail($rmodelID);
        $details['sitePath'] = $this->app->sitePath;
        $details['isPDF'] = false;
        $details['trans'] = $this->app->trans->group('risk_detail');
        $details['trans']['customItemType'] = [
            'one' => $this->app->trans->codeKey('word_one'),
            'multiple' => $this->app->trans->codeKey('word_multiple'),
        ];
        $diagContent = $this->app->view->fetch('Widgets/RiskInventory/ModelDetail.tpl', $details);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.modelDetail';
        $this->jsObj->Args = [
            $this->app->trans->codeKey('title_RiskModelConfig'),
            $diagContent,
        ];
    }

    /**
     * Get data for Risk Assessment Detail pop-up
     *
     * @return void
     */
    private function ajaxAssessmentDetail()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        $tpID = (int)$this->getPostVar('t');
        if (!$this->validRiskModelSetup($rmodelID)) {
            $this->handleInvalidRequest($this->app->trans->codeKey('err_invalidRequest') . ' (mi)');
            return;
        }
        $details = (new RiskDetails($this->clientID))->assessmentDetail($tpID, $rmodelID, 'test');
        if ($details['vars']['scopeName'] == CaseTypeClientBL::CASETYPE_INTERNAL_NAME) {
            $details['vars']['scopeName'] = $this->app->trans->codeKey($details['vars']['scopeName']);
        }
        $details['sitePath'] = $this->app->sitePath;
        $details['isPDF'] = false;
        $details['trans'] = $this->app->trans->group('risk_detail');
        $diagContent = $this->app->view->fetch('Widgets/RiskInventory/AssessmentDetail.tpl', $details);
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.profileScoreDetail';
        $this->jsObj->Args = [
            $this->app->trans->codeKey('title_RiskAssessmentDetails'),
            $diagContent,
        ];
    }

    /**
     * Clone published risk model
     *
     * @return void
     */
    private function ajaxCloneRiskModel()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        $rtn = (new RiData($this->clientID))->cloneRiskModel($rmodelID, $this->app->ftr->user);
        $trans = $this->app->trans;
        if ($rtn !== true) {
            // failure response
            $this->jsObj->ErrTitle = $trans->keyCode('title_operation_failed');
            if (is_string($rtn)) {
                $this->jsObj->ErrMsg = $trans->keyCode($rtn);
            } else {
                $this->jsObj->ErrMsg = $trans->keyCode('error_invalid_response_msg');
            }
            return;
        }
        // Reload intro to clear out browser and get updated model map
        $this->jsObj->AppNotice = [$trans->codeKey('riskinv_CloneSuccess')];
        $this->ajaxReloadIntro();
    }

    /**
     * Publish risk model
     *
     * @return void
     */
    private function ajaxPublish()
    {
        // Validate conditions to publish
        $rmodelID = (int)$this->getPostVar('mi');
        $trans = $this->app->trans;
        if (!$this->okayToPublish($rmodelID)) {
            return;
        }

        // Validate the role model
        if (!$this->validateRoleModel($rmodelID)) {
            return;
        }

        $err = (new RiData($this->clientID))->publishRiskModel($rmodelID, $this->app->ftr->user);
        if ($err && is_string($err)) {
            $transErr = $trans->codeKey($err);
            if (empty($transErr)) {
                $transErr = '(MISSING CODEKEY) ' . $err;
            }
            $this->jsObj->ErrTitle = $trans->codeKey('title_operation_failed');
            $this->jsObj->ErrMsg = $transErr;
            return;
        }
        // Reload intro to clear out browser and get updated model map
        $this->jsObj->AppNotice = [$trans->codeKey('riskinv_PublishSuccess')];
        $this->ajaxReloadIntro();
    }

    /**
     * Enable published and disabled risk model
     *
     * @return void
     */
    private function ajaxEnableRiskModel()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        $response = (new RiData($this->clientID))->enableRiskModel($rmodelID, $this->app->ftr->user);
        $trans = $this->app->trans;
        if (isset($response['status']) && $response['status']) {
            $this->jsObj->AppNotice = [$response['message']];
            $this->ajaxReloadIntro();
        } else {
            $this->jsObj->ErrTitle = $trans->codeKey('title_operation_failed');
            $this->jsObj->ErrMsg = $response['message'];
            return;
        }
    }

    /**
     * Disable published risk model
     *
     * @return void
     */
    private function ajaxDisableRiskModel()
    {
        $rmodelID = (int)$this->getPostVar('mi');
        $response = (new RiData($this->clientID))->disableRiskModel($rmodelID, $this->app->ftr->user);
        $trans = $this->app->trans;
        if (isset($response['status']) && $response['status'] == true) {
            $this->jsObj->AppNotice = [$response['message']];
            $this->ajaxReloadIntro();
        } else {
            $this->jsObj->ErrTitle = $trans->codeKey('title_operation_failed');
            $this->jsObj->ErrMsg = $response['message'];
            return;
        }
    }

    /**
     * Validate the role model
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return boolean
     */
    private function validateRoleModel($rmodelID)
    {
        $riskMdl = new RiskModels($this->clientID);
        $raMdl = new RiskAssessment($this->clientID);
        $riskMdlMap = new RiskModelMap($this->clientID);
        $modelRec = $riskMdl->selectByID($rmodelID);
        if (!$modelRec) {
            return false;
        }
        $trans = $this->app->trans;
        // Check if risk model for the same role, tpType and categories already exists
        $existingModel = $riskMdl->getExistingRoleModel(
            $modelRec['tpType'],
            $modelRec['categories'],
            $modelRec['riskModelRole'],
            'complete'
        );
        if ($existingModel) {
            // If exists, disable the risk model
            $riskMdl->disableExistingRiskModel($existingModel);
            $raMdl->updateStatus($existingModel);
            return true;
        }
        $roleCount = $riskMdlMap->getRoleCountForTypeCategory(
            $modelRec['tpType'],
            $modelRec['categories'],
            $modelRec['riskModelRole']
        );
        $roleLimit = getenv('riskModelRoleCount');
        if ($roleCount >= $roleLimit) {
            // Ask user to disable existing Risk model of another role before enabling newer one
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('title_operation_failed');
            $this->jsObj->ErrMsg = 'Risk area limit exceeded!
                                    Please disable existing Risk model of another Risk area before enabling newer one.';
            return false;
        }

        return true;
    }

    /**
     * Is model name valid?
     *
     * @param string $modelName    Name of risk model
     * @param array  $stepTracking Values to track steps progress
     *
     * @return boolean
     */
    private function validModelName($modelName, $stepTracking)
    {
        if (!empty($modelName) && !empty($stepTracking)) {
            return true;
        }
        return false;
    }

    /**
     * Validate 3P Type assigned to risk model
     *
     * @param integer $typeID tpType.id
     *
     * @return boolean
     */
    private function validTpType($typeID)
    {
        if (empty($typeID)) {
            return false;
        }
        $mdl = new TpType($this->clientID);
        return $typeID === $mdl->selectValue('id', ['id' => $typeID]);
    }

    /**
     * Validate Risk area assigned to risk model
     *
     * @param integer $roleID riskModelRole.id
     *
     * @return boolean
     */
    private function validModelRole($roleID)
    {
        if (empty($roleID)) {
            return false;
        }
        $mdl = new RiskModelRole($this->clientID);
        return $roleID === $mdl->selectValue('id', ['id' => $roleID]);
    }

    /**
     * Validate at least one category is selected and that all selected categories blong to typeID
     *
     * @param array   $cats   Category IDs
     * @param integer $typeID tpType.id
     *
     * @return boolean
     */
    private function validCategories($cats, $typeID)
    {
        if (!is_array($cats) || (0 >= count($cats)) || 0 >= $typeID) {
            return false;
        }
        $mdl = new TpTypeCategory($this->clientID);
        $typeCats = $mdl->getCleanCategoriesByType($typeID);
        $found = 0;
        foreach ($typeCats as $c) {
            if (!in_array($c['id'], $cats)) {
                continue;
            }
            $found++;
        }
        return $found === count($cats);
    }

    /**
     * Validate input on tier form
     *
     * @param integer $rmodelID risk model id
     *
     * @return array errors and tierValues for upsert
     */
    private function validateTierForm($rmodelID)
    {
        if (empty($rmodelID)) {
            return;
        }
        $mode = trim((string) $this->getPostVar('tierForm.mode'));
        if (!in_array($mode, ['add', 'update'])) {
            throw new \InvalidArgumentException('validateTierForm mode must be "add" or "update"');
        }
        $tierID = (int)$this->getPostVar('tierForm.tierID');
        $tierName = trim((string) $this->getPostVar('tierForm.tierName'));
        $rawName  = $this->getRawPostVar('tierForm.tierName', '');
        $scopeID = (int)$this->getPostVar('tierForm.scopeID');
        $startAt = $this->getPostVar('tierForm.start');
        if (is_numeric($startAt)) {
            $startAt = (int)$startAt; // allow missing to trigger required Rule
        }
        $tierFg = $this->getPostVar('tierForm.fg');
        $tierBg = $this->getPostVar('tierForm.bg');

        $trans = $this->app->trans;
        $cfg = [
            // non-zero $tierID
            //   tier exists
            //   tier not alreayd used in this model
            $trans->codeKey('lbl_Tier') => [
                [$this->validTierID($mode, $tierID), 'Generic', 'invalid_ValueNotFound'],
                [$this->uniqueModelTierID($mode, $rmodelID, $tierID), 'Generic', 'err_value_in_use'],
            ],
            // tier name
            //   no hex char codes, valid utf8
            //   not empty, not longer than 30 chars
            $trans->codeKey('riskinv_TierName') => [
                [$rawName, 'NoHexChar'],
                [$tierName, 'Utf8String'],
                [$tierName, 'Rules', 'required|max_len,30'],
                [$this->uniqueTierName($tierID, $tierName), 'Generic', 'err_name_in_use'],
            ],
            // tier threshold
            //   must be 0 if first model tier
            //   must be between 1 and 100 if not first model tier
            //   must not duplicate another model tier threshold
            $trans->codeKey('lbl_StartAt') => [
                [$startAt, 'Rules', 'required|minmax_int,0,100'],
                [
                    $this->uniqueTierThreshold($mode, $tierID, $rmodelID, $startAt),
                    'Generic',
                    'err_value_in_use',
                ],
                [
                    $this->firstTierThreshold($mode, $tierID, $rmodelID, $startAt),
                    'Generic',
                    'err_value_must_be_zero',
                ],
            ],
            // valid scopeID for this client, including gdc and internal
            $trans->codeKey('es_field_caseScopeID') => [
                [$this->validTierScopeID($scopeID), 'Generic', 'invalid_ValueNotFound'],
            ],
            // Set Tier Color fg and bg
            $trans->codeKey('riskinv_SetTierColor') => [
                [$tierFg, 'Rules', 'required|match_any_str,#ffffff #000000'],
                [$tierBg, 'Rules', 'required|regex,/^#[0-9a-f]{6}$/i'],
            ],
        ];

        $tests = new Validate($cfg);

        return [
            'tierValues' => compact('tierID', 'tierName', 'tierFg', 'tierBg', 'scopeID', 'startAt'),
            'errors' => $tests->errors, // empty array if no tests failed
        ];
    }

    /**
     * Test tier name for uniqueness within all tenant tiers
     *
     * @param integer $id   riskTier.id
     * @param string  $name riskTier.tierName
     *
     * @return boolean
     */
    private function uniqueTierName($id, $name)
    {
        $matchID = (new RiskTier($this->clientID))->selectValue('id', ['tierName' => $name]);
        return ($matchID === false || ($id && $id === $matchID));
    }

    /**
     * Model tier thresholds must not e duplicated within the same model
     *
     * @param string  $mode     'add' or 'update'
     * @param integer $tierID   riskModelTier.tier
     * @param integer $rmodelID riskModel.id
     * @param integer $startAt  riskModelTier.threshold
     *
     * @return boolean
     */
    private function uniqueTierThreshold($mode, $tierID, $rmodelID, $startAt)
    {
        if (empty($mode) || !isset($tierID) || empty($rmodelID) || !isset($startAt)) {
            return false;
        }
        if (!is_int($startAt)) {
            return true; // suppress this rule for missing value
        }
        $mtMdl = new RiskModelTier($this->clientID);
        $matchID = $mtMdl->selectValue('tier', ['model' => $rmodelID, 'threshold' => $startAt]);
        if ($mode == 'add') {
            $rtn = empty($matchID); // no model tier has this threshold
        } elseif ($mode == 'update') {
            // threshold not found or matches model tier to update
            $rtn = (empty($matchID) || $matchID === $tierID);
        } else {
            $rtn = false; // bad mode
        }
        return $rtn;
    }

    /**
     * First model tier threshold must be zero
     *
     * @param string  $mode     'add' or 'update'
     * @param integer $tierID   riskModelTier.tier
     * @param integer $rmodelID riskModel.id
     * @param integer $startAt  riskModelTier.threshold
     *
     * @return boolean
     */
    private function firstTierThreshold($mode, $tierID, $rmodelID, $startAt)
    {
        if (empty($mode) || !isset($tierID) || empty($rmodelID) || !isset($startAt)) {
            return false;
        }
        if (!is_int($startAt)) {
            return true; // suppress this rule for missing value
        }
        $mtMdl = new RiskModelTier($this->clientID);
        $records = $mtMdl->selectValue('COUNT(*)', ['model' => $rmodelID]);
        if ($mode === 'add') {
            $rtn = (($records === 0 && $startAt === 0) || $records > 0);
        } elseif ($mode === 'update') {
            if ($records === 1) {
                $rtn = ($startAt === 0);
            } else {
                // find the zero record
                $matchID = $mtMdl->selectValue(
                    'tier',
                    ['model' => $rmodelID, 'threshold' => 0],
                    'ORDER BY threshold ASC'
                );
                if ($startAt === 0) {
                    $rtn = ($tierID === $matchID); // has to be zero record if it's zero
                } else {
                    $rtn = ($tierID !== $matchID); // can't be the zero record if tierID doesn't match
                }
            }
        } else {
            $rtn = false;
        }
        return $rtn;
    }

    /**
     * Validate tier scope of investigation recommended by assessment scores in this tier
     *
     * @param integer $scopeID caseTypeClient.caseTypeID, plus internal for this client.
     *
     * @return boolean
     */
    private function validTierScopeID($scopeID)
    {
        if (empty($scopeID)) {
            return false;
        }
        $caseTypes = (new CaseTypeClientBL($this->clientID))->getRecords(true);
        $found = false;
        foreach ($caseTypes as $rec) {
            if ($rec['id'] === $scopeID) {
                $found = true;
                break;
            }
        }
        return $found;
    }

    /**
     * Tier must belong to client
     *
     * @param string  $mode   'add' or 'update'
     * @param integer $tierID riskModelTier.tier
     *
     * @return boolean
     */
    private function validTierID($mode, $tierID)
    {
        if (empty($mode)) {
            return false;
        }
        if (empty($tierID)) {
            return ($mode === 'add');
        }
        $matchID = (new RiskTier($this->clientID))->selectValueByID($tierID, 'id');
        return ($tierID === $matchID);
    }

    /**
     * Model tier cannot be repeated
     *
     * @param string  $mode     'add' or 'update'
     * @param integer $rmodelID riskModel.id
     * @param integer $tierID   riskModelTier.tier
     *
     * @return boolean
     */
    private function uniqueModelTierID($mode, $rmodelID, $tierID)
    {
        if (empty($mode) || empty($rmodelID)) {
            return false;
        }
        if ($tierID === 0) {
            return true;
        }
        $mtMdl = new RiskModelTier($this->clientID);
        $matchID = $mtMdl->selectValue('tier', ['tier' => $tierID, 'model' => $rmodelID]);
        if ($mode === 'add') {
            $rtn = empty($matchID);
        } elseif ($mode === 'update') {
            $rtn = ($tierID === $matchID);
        } else {
            $rtn = false;
        }
        return $rtn;
    }

    /**
     * Is riskModel.id a valid id for a risk model with setup status?
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return boolean
     */
    private function validRiskModelSetup($rmodelID)
    {
        $rtn = false;
        if (!empty($rmodelID)) {
            $rtn = ('setup' === (new RiskModels($this->clientID))->selectValueByID($rmodelID, 'status'));
        }
        return $rtn;
    }

    /**
     * Is ddq a scoreable intake form?
     *
     * @param integer $rmodelID riskModel.id
     * @param string  $ddqRef   Leacy intake form ID
     *
     * @return boolean
     */
    private function isScorableDdq($rmodelID, $ddqRef)
    {
        if (empty($rmodelID) || empty($ddqRef)) {
            return false;
        }
        $riData = new RiData($this->clientID);
        $lists = $riData->getDdqSelects($rmodelID, $ddqRef);
        foreach ($lists['unscored'] as $iform) {
            if ($iform['ver'] === $ddqRef) {
                return true;
            }
        }
        foreach ($lists['scored'] as $iform) {
            if ($iform['ver'] === $ddqRef) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get date for Clone/View page
     *
     * @return void
     */
    private function ajaxGetCloneView()
    {
        $rmodelID = (int)$this->getPostVar('mi', 0);
        $riskRec = (new RiskModels($this->clientID))->selectByID($rmodelID, ['id', 'name', 'status', 'updated']);
        $trans = $this->app->trans;
        if (empty($riskRec) || ('complete' !== $riskRec['status'] && 'disabled' !== $riskRec['status'])) {
            $this->jsObj->ErrTitle = $trans->codeKey('errTitle_invalidRequest');
            $this->jsObj->ErrMsg = $trans->codeKey('err_invalidRequest');
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.riskinv.loadCloneView';
        $this->jsObj->Args = [
            $trans->group('riskinv_cloneview'),
            $riskRec,
        ];
    }

    /**
     * Get the status of 'Raw Scoring' features
     *
     * @return array Indicates if any of the 'Raw Scoring' features have been enabled
     */
    private function getRawScoreFeatures()
    {
        return [
            'ddq'   => $this->app->ftr->tenantHas(FeatureACL::CALC_RAW_DDQ_SCORE),
            'cufld' => $this->app->ftr->tenantHas(FeatureACL::CALC_RAW_CUFLD_SCORE)
        ];
    }

    /**
     * Update the <clientDB>.riskModel table with the components, weights and raw scoring flags
     *
     * @param array   $factors       Contains factors that have been enabled (tpcat, cpi, ddq, ....)
     * @param integer $components    Integer value is really a bit field, see RiskModel::factorsToComponents()
     * @param array   $factorWeights Weight values entered by user for each risk factor
     * @param integer $rmodelID      riskModel.id
     * @param array   $rawScoreOpt   Flags indicating which raw score options have been set
     *
     * @return array
     */
    private function updateScores($factors, $components, $factorWeights, $rmodelID, $rawScoreOpt)
    {
        $rMdl = new RiskModels($this->clientID);
        $hasRawScoreFtr = $this->getRawScoreFeatures();

        $updates = [
            'activeFactors'  => $factors,
            'factorWeights'  => $factorWeights,
            'hasRawScoreFtr' => $hasRawScoreFtr
        ];

        $scores = [
            'components' => $components,
            'weights'    => serialize($factorWeights)
        ];

        // only update the riskModel raw scoring columns (flags) if the associated feature is enabled
        if ($hasRawScoreFtr['ddq']) {
            $scores['calculateRawDdqScore'] = $rawScoreOpt['ddq'];
        }

        if ($hasRawScoreFtr['cufld']) {
            $scores['calculateRawCuFldScore'] = $rawScoreOpt['cufld'];
        }

        $rMdl->updateByID($rmodelID, $scores);
        return $updates;
    }
}
