<?php
/**
 * Controller: admin tool create cases/invitations from a list of 3P Profile numbers
 *
 * @link     http://jira.securimate.com:8090/x/eQBQ
 * @keywords admin, bulk upload, 3p, cases, invitations
 */

namespace Controllers\TPM\Admin\BulkUpload;

use Controllers\ThirdPartyManagement\Base;
use Lib\Csv;
use Lib\CsvIO;
use Lib\FeatureACL as Feature;
use Lib\SequenceParse; // Dependency from original refactor
use Lib\Services\AppMailer;
use Lib\Support\UserLock;
use Lib\Support\Xtra;
use Lib\Traits\AjaxDispatcher;
use Lib\Traits\SplitDdqLegacyID;
use Models\Ddq;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;
use Models\Globals\Geography;
use Models\Globals\Languages;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\SubjectInfoDD;
use Models\TPM\Admin\BulkUpload\MassCaseInvitOrderData;
use Models\TPM\SystemEmails;
use Models\Globals\UtilityUsage;
use Skinny\Skinny;

/**
 * Handles requests and responses for admin tool Mass Case Invitations Order from 3P List
 */
class MassCaseInvitOrder extends Base
{
    use AjaxDispatcher;
    use SplitDdqLegacyID;

    public const PROFILE_LIST_CHAR_MAX = 10000;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Admin/BulkUpload/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'MassCaseInvitOrder.tpl';

    /**
     * @var int Holds clientProfile.id
     */
    protected $clientID;

    /**
     * @var bool Control admin tool access
     */
    protected $canAccess = true;

    /**
     * Application instance
     *
     * @var Skinny Application instance
     */
    protected $app;

    /**
     * @var int Authenticated User ID
     */
    protected $userID;

    /**
     * @var \Lib\Support\UserLock Class instance
     */
    protected $userLock = null;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var string Title for current page
     */
    protected $pageTitle = 'Order Cases/Invite from 3P Profiles';

    /**
     * @var MassCaseInvitOrderData Class instance
     */
    private $data;

    /**
     * @var AppMailer Class instance
     */
    protected $appMailer = null;

    /**
     * @var  \LegacyUserAccessAlias Class instance
     */
    private $legacyUserAccess;

    /**
     * @var array Service Providers enabled for this clientID
     */
    private $serviceProviders = [];

    /**
     * @var array Case types
     */
    private $scopes = [];

     /**
      * Constructor
      *
      * @param integer $clientID   clientProfile.id
      * @param array   $initValues Flexible construct to pass in values
      */
    public function __construct($clientID, $initValues = array())
    {
        // allow PHPUNIT to mock LegacyUserAccess
        if (!class_exists('\LegacyUserAccessAlias')) {
            class_alias(\Models\ThirdPartyManagement\LegacyUserAccess::class, 'LegacyUserAccessAlias');
        }
        Xtra::requireInt($clientID);
        parent::__construct($clientID, $initValues);
        $this->legacyUserAccess = new \LegacyUserAccessAlias($this->clientID);
        $this->app = Xtra::app();
        $this->clientID = $clientID;
        $this->userID = $this->app->session->authUserID;
        $this->userLock = new UserLock($this->app->session->get('authUserPwHash'), $this->userID);
        $this->canAccess = (($this->app->ftr->appIsAdmin() || $this->userLock->hasAccess('accSysAdmin'))
            && $this->app->ftr->tenantHas(Feature::TENANT_TPM)
        );
        $this->data = new MassCaseInvitOrderData($clientID);
        $this->scopes = $this->data->getScopes();
        $this->serviceProviders = (new ServiceProvider(0))->getServiceProviders($clientID);
        $this->appMailer = new AppMailer();
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        if (!$this->canAccess) {
            throw new \Exception('Unauthorized access');
        }

        // Defaults
        $phase = 'init';
        $intakeFormDefault = 0;
        $languageDefault = $this->data->getDefault('language');
        $overrideRequestor = $profileRefList = $SBIonPrincipals = '';
        $spID = $this->serviceProviders[0]['id'];
        $investigatorID = $this->data->getDefault('investigator');
        $scopeID = $this->data->getDefault('scope');
        $estimatedDueDate = (new ServiceProvider($spID))->getEstimatedDueDate();
        $billingUnits = (new BillingUnit($this->clientID))->getActiveBillingUnits();
        $billingUnit = ($billingUnits) ? $billingUnits[0]['id'] : 0;
        $billingUnitPOs = ($billingUnit > 0)
            ? (new BillingUnitPO($this->clientID))->getBillingUnitPOs($billingUnit)
            : [];
        $billingUnitPO = ($billingUnitPOs) ? $billingUnitPOs[0]['id'] : 0;

        // "Sticky" overrides: only used if we haven't switched subscribers.
        if (($config =  $this->app->session->get('massCaseInvitOrderConfig'))
            && $config['clientID'] == $this->clientID
        ) {
            // @TODO: Temporarily adding logging here to track an issue possibly caused by an empty config. TPM-2135
            if (empty($config)) {
                error_log(print_r([
                    'Error' => 'array_key_exists() expects parameter 2 to be array, null given',
                    'Description' => 'array_key_exists Error -> Cause by empty $config',
                    'File' => __FILE__,
                    'Line' => __LINE__
                ], true), 0);
            } else {
                // Invitation-specific
                if (array_key_exists('intakeForm', $config)) {
                    $intakeFormDefault = $config['intakeForm'];
                }
                if (array_key_exists('language', $config)) {
                    $languageDefault = $config['language'];
                }

                // Non-Invitation-specific
                if (array_key_exists('scopeID', $config)) {
                    $scopeID = $config['scopeID'];
                }
                if (array_key_exists('SBIonPrincipals', $config)) {
                    $SBIonPrincipals = $config['SBIonPrincipals'];
                }
                if (array_key_exists('spID', $config)) {
                    $spID = $config['spID'];
                }
                if (array_key_exists('investigatorID', $config)) {
                    $investigatorID = $config['investigatorID'];
                }
                if (array_key_exists('estimatedDueDate', $config)) {
                    $estimatedDueDate = $config['estimatedDueDate'];
                }
                if (array_key_exists('billingUnit', $config)) {
                    $billingUnit = $config['billingUnit'];
                }
                if (array_key_exists('billingUnitPO', $config)) {
                    $billingUnitPO = $config['billingUnitPO'];
                }

                // General
                if (array_key_exists('overrideRequestor', $config)) {
                    $overrideRequestor = $config['overrideRequestor'];
                }
                if (array_key_exists('profileRefList', $config)) {
                    $profileRefList = $config['profileRefList'];
                }
                if (array_key_exists('phase', $config)) {
                    $phase = $config['phase'];
                }
            }
        } else {
            // Must have switched clients. Kill the overrides.
            $this->app->session->forget('massCaseInvitOrderConfig');
        }

        $this->setViewValue('pgTitle', $this->pageTitle);
        $this->setViewValue('SBI', Cases::DUE_DILIGENCE_SBI);
        $this->setViewValue('billingUnits', $billingUnits);
        $this->setViewValue('billingUnitPOs', $billingUnitPOs);
        $this->setViewValue('intakeForms', $this->data->getIntakeFormsOptions());
        $this->setViewValue('serviceProviders', $this->serviceProviders);
        $this->setViewValue('scopes', $this->scopes);
        $this->setViewValue('IBItbl', true);
        $this->setViewValue('samples', $this->data->getSampleTpProfileRefs());
        $this->setViewValue('phase', $phase);
        $this->setViewValue('intakeFormDefault', $intakeFormDefault);
        $this->setViewValue('languageDefault', $languageDefault);
        $this->setViewValue('overrideRequestor', $overrideRequestor);
        $this->setViewValue('profileRefList', $profileRefList);
        $this->setViewValue('investigators', $this->data->getInvestigators($spID));
        $this->setViewValue('spID', $spID);
        $this->setViewValue('scopeID', $scopeID);
        $this->setViewValue('SBIonPrincipals', $SBIonPrincipals);
        $this->setViewValue('investigatorID', $investigatorID);
        $this->setViewValue('estimatedDueDate', $estimatedDueDate);
        $this->setViewValue('billingUnit', $billingUnit);
        $this->setViewValue('billingUnitPO', $billingUnitPO);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
        if ($_SERVER['REQUEST_URI'] == '/tpm/adm/bulkUpload/massCaseInvitOrder') {
            (new UtilityUsage())->addUtility('Order Cases/Invite from 3P Profiles');
        }
    }



    /**
     * Get new purchase orders for a given billing unit
     *
     * @return void
     */
    private function ajaxGetBillingUnitPOs()
    {
        $billingUnit = (int)$this->getPostVar('billingUnit', 0);
        $POs = (new BillingUnitPO($this->clientID))->getBillingUnitPOs($billingUnit);
        $this->jsObj->Result = 1;
        $this->jsObj->Args[0]['billingUnitPOs'] = (!empty($POs)) ? $POs : [];
    }



    /**
     * Get new Investigators for a given SP
     *
     * @return void
     */
    private function ajaxGetInvestigators()
    {
        $spID = (int)$this->getPostVar('spID', 0);
        if ($investigators = $this->data->getInvestigators($spID)) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [[
                'investigators' => $investigators
            ]];
        }
    }



    /**
     * Get languages for a given intake form
     *
     * @return void
     */
    private function ajaxGetLanguages()
    {
        $intakeForm = $this->getPostVar('intakeForm', '');
        if ($languages = $this->data->getLanguages($intakeForm)) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [[
                'preferredLangDefined' => $this->data->getPreferredLanguageDefined(),
                'languages' => $languages
            ]];
        }
    }


    /**
     * Reset the form values if they live in the session.
     *
     * @return void
     */
    private function ajaxResetForm()
    {
        if ($this->app->session->get('massCaseInvitOrderConfig')) {
            $this->app->session->forget('massCaseInvitOrderConfig');
        }
        $this->jsObj->Result = 1;
    }



    /**
     * Reset the phase to init if it lives in the session.
     *
     * @return void
     */
    private function ajaxResetPhase()
    {
        if ($config = $this->app->session->get('massCaseInvitOrderConfig')) {
            $config['phase'] = 'init';
            $this->app->session->set('massCaseInvitOrderConfig', $config);
        }
        $this->jsObj->Result = 1;
    }


    /**
     * Validate data
     *
     * @return void
     */
    private function ajaxValidateData()
    {
        $config = $this->app->session->get('massCaseInvitOrderConfig');
        if (array_key_exists('intakeForm', $config) && !empty($config['intakeForm'])) {
            // Invitation Validation
            $this->initNewInvitations();
        } else {
            // Cases Validation
            $this->initNewCases();
        }
    }


    /**
     * Validate form
     *
     * @return void
     */
    private function ajaxValidateForm()
    {
        $intakeForm = $this->getPostVar('intakeForm', '');
        $overrideRequestor = trim((string) $this->getPostVar('overrideRequestor', ''));
        $profileRefList = Xtra::normalizeLF($this->getPostVar('profileRefList', ''));
        $phase = $this->getPostVar('phase', '');

        if ($phase != 'validation' && $phase != 'processing') {
            throw new \Exception('Invalid phase supplied.');
        }

        if (!empty($intakeForm)) {
            $language = $this->getPostVar('emailLang', 'EN_US');
            $this->validateInvitations(
                $intakeForm,
                $language,
                $overrideRequestor,
                $profileRefList,
                $phase
            );
        } else {
            $scopeID = (int)$this->getPostVar('scopeID', 0);
            $billingUnit = (int)$this->getPostVar('billingUnit', 0);
            $billingUnitPO = (int)$this->getPostVar('billingUnitPO', 0);
            $SBIonPrincipals = $this->getPostVar('SBIonPrincipals', '');
            $spID = (int)$this->getPostVar('spID', 0);
            $investigatorID = (int)$this->getPostVar('investigatorID', 0);
            $estimatedDueDate = trim((string) $this->getPostVar('estimatedDueDate', ''));
            $this->validateCases(
                $scopeID,
                $billingUnit,
                $billingUnitPO,
                $SBIonPrincipals,
                $spID,
                $investigatorID,
                $estimatedDueDate,
                $overrideRequestor,
                $profileRefList,
                $phase
            );
        }
    }



    /**
     * Write CSV record
     *
     * @param resource $fp     Open file pointer
     * @param array    $data   Associative array of data to write
     * @param string   $action Invite, Renew, Create, Skip or Error
     * @param string   $reason Reason for Skip, Error or ''
     *
     * @return integer bytes written
     */
    private function csvCreateRow($fp, $data, $action, $reason)
    {
        if (!is_resource($fp) || !is_array($data)) {
            return 0;
        }
        $data['Action'] = $action;
        $data['Reason'] = $reason;
        return fwrite($fp, Csv::make(array_values($data), 'std', true));
    }



    /**
     * Check that expected CSV file exists and begin download.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function csvDownload()
    {
        // Check that CSV directory and file exist
        $outDir = '/var/local/adminData/' . $this->app->mode . '/massOrderInvite';
        if (!is_dir($outDir)) {
            throw new \Exception('CSV directory not found.');
        }
        $outFile = $outDir . '/uid' . $this->userID . '_massOrderInvite.csv';
        if (!is_file($outFile)) {
            throw new \Exception('CSV not found.');
        }

        // Quick check if browser string looks like IE
        $isIE = (str_contains(strtoupper((string) $this->app->environment['HTTP_USER_AGENT']), 'MSIE'));
        $csv = new CsvIO($isIE);

        // Define output file name and start output
        $fileName = 'Mass_Order_Invite_' . date('Y-m-d_H-i') . '.csv';
        $csv->streamCsv($fileName, $outFile);
    }



    /**
     * Format CSV data from 3P row object
     *
     * @param array  $row      3P row of values
     * @param string $language Email language
     *
     * @return array associative array to be used later for writing csv record
     */
    private function csvFormatRow($row, $language = '')
    {
        return ['Client ID' => $this->clientID, '3P #' => $row['userTpNum'], '3P Name' => trim((string) $row['legalName']), '3P DBA' => trim((string) $row['DBAname']), 'POC Name' => trim((string) $row['POCname']), 'POC Email' => trim((string) $row['POCemail']), 'Language' => $language, 'Country' => $row['country'], 'Case #' => '', 'Action' => '', 'Reason' => ''];
    }



    /**
     * Creates CSV file, and its header row.
     *
     * @return string CSV file path
     */
    private function csvHeader()
    {
        // Prepare for csv output
        $outDir = '/var/local/adminData/' . $this->app->mode . '/massOrderInvite';
        if (!is_dir($outDir) && !mkdir($outDir)) {
            throw new \Exception("Unable to create `$outDir`");
        }
        $outFile = $outDir . '/uid' . $this->userID . '_massOrderInvite.csv';
        $outFp = fopen($outFile, 'w');
        if (!$outFp) {
            throw new \Exception("Unable to open `$outFile` for writing.");
        }
        $dataFlds = ['Client ID', '3P #', '3P Name', '3P DBA', 'POC Name', 'POC Email', 'Language', 'Country', 'Case #', 'Action', 'Reason'];
        fwrite($outFp, Csv::make($dataFlds, 'std', true));
        return $outFp;
    }





    /**
     * Kicks off the creation of the CSV file, and the bgProcess if in 'processing' phase.
     *
     * @param string  $phase   Either validation or processing
     * @param integer $numRecs Number of records to process
     *
     * @return array 'outFp' and 'batchID' items
     */
    private function csvStart($phase = 'validation', $numRecs = 0)
    {
        $rtn['outFp'] = $this->csvHeader();
        $rtn['batchID'] = ($phase == 'processing') ? $this->data->bgProcessCreate($numRecs) : 0;
        return $rtn;
    }




    /**
     * Closes out the CSV file, and toggles the bgProcess to stop.
     *
     * @param resource $outFp   File handle for CSV output
     * @param string   $phase   Either validation or processing
     * @param integer  $batchID g_bgProcess.id
     * @param integer  $checked Number of records left to process
     *
     * @return void
     */
    private function csvStop($outFp, $phase, $batchID, $checked)
    {
        // close output file
        if ($outFp) {
            fclose($outFp);
            $outFp = null;
        }

        // update bgProcess record
        if ($phase == 'processing' && $batchID > 0) {
            $this->data->bgProcessUpdate($batchID, $checked);
        }
    }





    /**
     * Validates or processes new cases
     *
     * @return void
     */
    private function initNewCases()
    {
        // Unpackage the config var, which contains the following:
        // scopeID, billingUnit, billingUnitPO, SBIonPrincipals, spID, isFullSP, investigatorID,
        // estimatedDueDate, overrideRequestor, overrideRequestorID, profileRefList, tpIDs, phase,
        // clientID
        $config = $this->app->session->get('massCaseInvitOrderConfig');
        foreach ($config as $key => $value) {
            ${$key} = $value;
        }

        if (empty($tpIDs)) {
            return; // That's all folks!
        }

        $csvStart = $this->csvStart($phase, count($tpIDs));
        $outFp = $csvStart['outFp'];
        $batchID = $csvStart['batchID'];

        $checked = $skipped = $canCreate = $badProduct = $badCountry = $badEmail = 0;
        $badCasesVals = $badSubjInfDdVals = 0;
        $geography = Geography::getVersionInstance();

        flush();

        $markTime = time();
        foreach ($tpIDs as $tpID) {
            $tpID = (int)$tpID;
            $checked++;

            if ((time() - $markTime) >= 14) {
                set_time_limit(15);
                $markTime = time();
            }
            if ($checked & 1) {
                flush();
            }
            $tpRow = $this->data->getThirdPartyRow($tpID);

            if ($overrideRequestorID) {
                // apply override requestor
                $tpRow['ownerID'] = $overrideRequestorID;
            }

            $recData = $this->csvFormatRow($tpRow);

            if ($this->data->hasOpenCase($tpID)) {
                $this->csvCreateRow($outFp, $recData, 'Skip', 'Has open case');
                $skipped++;
                continue;
            }

            // Get override data from bulkIBIupload table
            $override = $this->initOverride($tpRow, $recData, $phase);
            $tpRow = $override['tpRow'];
            $recData = $override['recData'];
            $principal1 = (!empty($override['principalNames'][0])) ? $override['principalNames'][0] : '';
            $principal2 = (!empty($override['principalNames'][1])) ? $override['principalNames'][1] : '';
            $pRelationship1 = (!empty($override['pRelationship1'])) ? $override['pRelationship1'] : '';
            $pRelationship2 = (!empty($override['pRelationship2'])) ? $override['pRelationship2'] : '';

            // valid country?
            if ($tpRow['country'] !== $geography->getLegacyCountryCode($tpRow['country'])) {
                $this->csvCreateRow($outFp, $recData, 'Error', 'Invalid country');
                $badCountry++;
                continue;
            }

            $investigationInfo = (new ServiceProvider($spID))->getCostAndDate(
                $this->clientID,
                $scopeID,
                $estimatedDueDate,
                $tpRow['country'],
                count($override['principalNames'])
            );

            if (array_key_exists('error', $investigationInfo) && !empty($investigationInfo['error'])) {
                $this->csvCreateRow($outFp, $recData, 'Error', $investigationInfo['error']);
                $badProduct++;
                continue;
            }

            // Load up the fields, and give them some sanity tests.
            $cases = new Cases($this->clientID);
            $vals = [
                'cases' => [
                    'tpID' => (int)$tpRow['id'],
                    'caseName' => $tpRow['legalName'],
                    'region' => (int)$tpRow['region'],
                    'dept' => (int)$tpRow['department'],
                    'caseCountry' => $tpRow['country'],
                    'caseState' => $tpRow['state'],
                    'caseStage' => $investigationInfo['stage'],
                    'requestor' => $cases->getRequestor($tpRow['ownerID']),
                    'creatorUID' => $this->userID,
                    'budgetAmount' => $investigationInfo['cost'],
                    'budgetType' => $investigationInfo['budgetType'],
                    'caseDueDate' => $investigationInfo['caseDueDate'],
                    'internalDueDate' => $investigationInfo['caseDueDate'],
                    'caseAssignedAgent' => $spID,
                    'caseInvestigatorUserID' => $investigatorID,
                    'caseType' => $scopeID,
                    'numOfBusDays' => $investigationInfo['busDays'],
                    'spProduct' => $investigationInfo['spProductID'],
                    'billingUnit' => $billingUnit,
                    'billingUnitPO' => $billingUnitPO,
                ],
                'subjectInfoDD' => [
                    'name' => $tpRow['legalName'],
                    'country' => $tpRow['country'],
                    'pointOfContact' => $tpRow['POCname'],
                    'POCposition' => $tpRow['POCposi'],
                    'phone' => $tpRow['POCphone1'],
                    'emailAddr' => $tpRow['POCemail'],
                    'street' => $tpRow['addr1'],
                    'addr2' => $tpRow['addr2'],
                    'city' => $tpRow['city'],
                    'postCode' => $tpRow['postcode'],
                    'DBAname' => $tpRow['DBAname'],
                    'principal1' => $principal1,
                    'pRelationship1' => $pRelationship1,
                    'principal2' => $principal2,
                    'pRelationship2' => $pRelationship2,
                ]
            ];

            // Vet Cases fields
            $tpAccess = $this->app->ftr->tenantHas(Feature::TENANT_TPM);
            $engagementAccess = false; // Context is ordering a case from a 3PP, not an engagement
            $spConfigAccess = $this->app->ftr->tenantHas(Feature::TENANT_TPM_SP_CFG);
            $exceptionMsg = null;
            try {
                $fldsVetted = $cases->vetNewCaseFlds(
                    $vals['cases'],
                    $tpAccess,
                    $engagementAccess,
                    $spConfigAccess,
                    false,
                    true
                );
            } catch (\Exception $e) {
                $exceptionMsg = $e->getMessage();
            }
            if (!empty($fldsVetted['badFlds']) || $exceptionMsg !== null) {
                $badCasesVals++;
                if (!empty($fldsVetted['badFlds'])) {
                    $error = 'These cases fields are missing values: ' . join(", ", $fldsVetted['badFlds']);
                } elseif ($exceptionMsg !== null) {
                    $error = $exceptionMsg;
                }
                $this->csvCreateRow($outFp, $recData, 'Error', $error);
                continue;
            }

            $caseStage = (int)$vals['cases']['caseStage'];
            $isInvestigation = ($caseStage > $cases::UNASSIGNED && $caseStage < $cases::ACCEPTED_BY_REQUESTER);
            $hasPrincipals = (!empty($vals['subjectInfoDD']['principal1'])
                || !empty($vals['subjectInfoDD']['principal2'])
            );
            $isSBI = (($scopeID == $cases::DUE_DILIGENCE_SBI) && $hasPrincipals);

            if ($isInvestigation) {
                $vals['subjectInfoDD']['bAwareInvestigation'] = 1;
                if ($isSBI) {
                    $vals['subjectInfoDD']['SBIonPrincipals'] = $SBIonPrincipals;
                }
            }

            // Vet SubjectInfoDD fields
            $exceptionMsg = null;
            try {
                $fldsVetted = $cases->vetNewCaseSbjInfDDFlds(
                    $vals['subjectInfoDD'],
                    $isInvestigation,
                    $isSBI,
                    true
                );
            } catch (\Exception $e) {
                $exceptionMsg = $e->getMessage();
            }
            if (!empty($fldsVetted['badFlds']) || $exceptionMsg !== null) {
                $badSubjInfDdVals++;
                if (!empty($fldsVetted['badFlds'])) {
                    $error = 'These subjectInfoDD fields are missing values: '
                        . join(", ", $fldsVetted['badFlds']);
                } elseif ($exceptionMsg !== null) {
                    $error = $exceptionMsg;
                }
                $this->csvCreateRow($outFp, $recData, 'Error', $error);
                continue;
            }

            // Past all of the roadblocks. If you've made it this far, you can create!
            $canCreate++;

            if ($phase == 'validation') {
                // End of the road for validation. Write the row, and move on to the next record.
                $this->csvCreateRow($outFp, $recData, 'Create', '');
                continue;
            }
            $caseID = 0;
            $exceptionMsg = null;
            try {
                $caseID = $cases->createNewCase($vals);
            } catch (\Exception $e) {
                $exceptionMsg = $e->getMessage();
            }
            // Add batchID and prevent re-use of override record
            $this->data->updateBatchCase($caseID, $batchID, $tpRow['userTpNum'], $recData);

            if ($caseID > 0) {
                /*
                 * @todo
                 * If this tool is used to bulk create cases for spLite an
                 * accept/reject notice for each case needs to be sent.
                 * See cms/case/reviewConfirm_pt2.php, around line 586 for example code.
                 */

                // record csv
                $this->csvCreateRow($outFp, $recData, 'Create', '');
            } else {
                // adjust count and record error in csv
                $canCreate--;
                $skipped++;
                $error = 'Failed creating case record';
                if ($exceptionMsg !== null) {
                    $error .= ': ' . $exceptionMsg;
                }
                $this->csvCreateRow($outFp, $recData, 'Error', $error);
            }
        }

        $this->csvStop($outFp, $phase, $batchID, $checked);

        $summary = [
            'Records' => count($tpIDs),
            'Create' => $canCreate,
        ];
        $recsWithIssues = [
            'Has Open Case' => $skipped,
            'Bad Country' => $badCountry,
            'Bad Product' => $badProduct,
            'Bad cases Values' => $badCasesVals,
            'Bad subjectInfoDD Values' => $badSubjInfDdVals,
        ];

        if ($phase == 'processing') {
            // Done with this session variable. Kill it.
            $this->app->session->forget('massCaseInvitOrderConfig');
        }

        $this->jsObj->Result = 1;
        $this->jsObj->Args = [[
            'type' => 'cases',
            'summaryTitle' => strtoupper((string) $phase),
            'summary' => $summary,
            'recsWithIssues' => $recsWithIssues,
            'phase' => $phase
        ]];
        if ($phase == 'validation') {
            if ($isFullSP) {
                $investigators = $this->data->getInvestigators($spID);
                $this->jsObj->Args[0]['investigatorName']
                    = $this->data->getDataNameById($investigatorID, $investigators);
            } else {
                $this->jsObj->Args[0]['investigatorName'] = '';
            }
            $billingUnits = (new BillingUnit($this->clientID))->getActiveBillingUnits();
            $billingUnitPOs = ($billingUnit > 0)
                ? (new BillingUnitPO($this->clientID))->getBillingUnitPOs($billingUnit)
                : [];

            $this->jsObj->Args[0]['SBIonPrincipals'] = $SBIonPrincipals;
            $this->jsObj->Args[0]['billingUnit'] = $this->data->getDataNameById($billingUnit, $billingUnits);
            $this->jsObj->Args[0]['billingUnitPO'] = $this->data->getDataNameById($billingUnitPO, $billingUnitPOs);
            $this->jsObj->Args[0]['estimatedDueDate'] = $estimatedDueDate;
            $this->jsObj->Args[0]['spName'] = $this->data->getDataNameById($spID, $this->serviceProviders);
            $this->jsObj->Args[0]['scopeName'] = $this->data->getDataNameById($scopeID, $this->scopes);
            $this->jsObj->Args[0]['overrideRequestor'] = $overrideRequestor;
            $this->jsObj->Args[0]['profileRefList'] = $profileRefList;
        }
    }





    /**
     * Validates or processes new DDQ Invitations
     *
     * @return void
     */
    private function initNewInvitations()
    {
        // Unpackage the config var, which contains the following:
        // intakeForm, intakeFormVersion, intakeFormType, language, overrideRequestor,
        // overrideRequestorID, profileRefList, action, tpIDs, phase and clientID

        $config = $this->app->session->get('massCaseInvitOrderConfig');
        foreach ($config as $key => $value) {
            ${$key} = $value;
        }

        if (empty($tpIDs)) {
            return; // That's all folks!
        }

        $csvStart = $this->csvStart($phase, count($tpIDs));
        $outFp = $csvStart['outFp'];
        $batchID = $csvStart['batchID'];

        $checked = $skipped = $canInvite = $canRenew = 0;
        $badCountry = $badEmail = $badRenewal = $badInvite = 0;
        $badDdqVals = $badCasesVals = $badSubjInfDdVals = 0;
        $intakeForms = $this->data->getIntakeFormsConfig();
        $ddq = new Ddq($this->clientID);
        $cases = new Cases($this->clientID);
        $subjectInfoDD = new SubjectInfoDD($this->clientID);
        $geography = Geography::getVersionInstance();

        flush();

        $markTime = time();
        foreach ($tpIDs as $tpID) {
            $tpID = (int)$tpID;
            $invExplanation = $this->data->getInvitationExplanation($tpID, $intakeFormType, $action);
            $checked++;
            if ((time() - $markTime) >= 14) {
                set_time_limit(15);
                $markTime = time();
            }
            if ($checked & 1) {
                flush();
            }
            $tpRow = $this->data->getThirdPartyRow($tpID);

            if ($overrideRequestorID) {
                // apply override requestor
                $tpRow['ownerID'] = $overrideRequestorID;
            }

            $recData = $this->csvFormatRow($tpRow, $language);

            if (is_array($invExplanation) && $invExplanation['result'] == false) {
                if ($invExplanation['caseInProgress']) {
                    $this->csvCreateRow($outFp, $recData, 'Skip', 'Has open case');
                    $skipped++;
                } else {
                    if ($action == 'Renew') {
                        $badRenewal++;
                    } elseif ($action == 'Invite') {
                        $badInvite++;
                    }
                    $this->csvCreateRow($outFp, $recData, 'Error', $invExplanation['reason']);
                }
                continue;
            }

            $override = $this->initOverride($tpRow, $recData, $phase, $intakeFormType);
            $tpRow = $override['tpRow'];
            $recData = $override['recData'];
            $updateProfile = $override['updateProfile'];
            $updatePOCname = $override['updatePOCname'];
            $updatePOCemail = $override['updatePOCemail'];

            // valid country?
            if ($tpRow['country'] !== $geography->getLegacyCountryCode($tpRow['country'])) {
                $this->csvCreateRow($outFp, $recData, 'Error', 'Invalid country');
                $badCountry++;
                continue;
            }

            // init vars
            $invite = $reInvite = $renew = $renewalCaseID = $ddqID = 0;
            $caseRow = $subjectInfoDDRow = $ddqRow = [];

            // Look for language override in custom field
            if ($this->data->getPrefLangFieldID() > 0) {
                $cuLang = $this->data->getCustomFldLanguage($tpRow['id'], $tpRow['tpTypeCategory']);
                $recData['Language'] = $cuLang;
                if ($langError = $this->validateLanguage($recData['Language'], $intakeFormType)) {
                    if ($action == 'Renew') {
                        $badRenewal++;
                    } elseif ($action == 'Invite') {
                        $badInvite++;
                    }
                    $errorPrefLang = 'Invalid Preferred Language Custom Field setting or no system email exists '
                        . 'for Preferred Language.';
                    $this->csvCreateRow($outFp, $recData, 'Error', $errorPrefLang);
                    continue;
                }
            }

            // Get relevant data
            if ($action == 'Renew') {
                $renewalCaseID = $invExplanation['caseID'];
                $legacyID = $invExplanation['legacyID'];
                if ($caseRow = $cases->findById($renewalCaseID)) {
                    $subjectInfoDDRow = $subjectInfoDD->findByAttributes(
                        ['caseID' => $renewalCaseID]
                    );
                    // We need the case row and the ddq row
                    if ($ddqRow = $ddq->findByAttributes(['caseID' => $renewalCaseID])) {
                        $ddqID = $ddqRow->getId();
                        $subInLang = $ddqRow->get('subInLang');
                        if (!$langError = $this->validateLanguage($subInLang, $intakeFormType)) {
                            $recData['Language'] = $ovr_lang = $subInLang;
                        }
                    }
                }
                if (!$caseRow || !$subjectInfoDDRow || !$ddqRow) {
                    $error = 'Missing case, subjectInfoDD, and/or ddq record';
                    $this->csvCreateRow($outFp, $recData, 'Error', $error);
                    $badRenewal++;
                    continue;
                } else {
                    $canRenew++;
                    $renew = 1;
                }
            } elseif ($action == 'Invite') {
                $canInvite++;
                $invite = 1;
            }
            // Load up the fields, and give them some sanity tests.
            $ddqName = $intakeForms[$intakeForm]['name'];
            $userID = $cases->getRequestor($tpRow['ownerID']);

            $ddqInviteVals = [
                'ddq' => [
                    'loginEmail' => is_null($tpRow['POCemail']) ? '' : $tpRow['POCemail'],
                    'name' => is_null($tpRow['legalName']) ? '' : $tpRow['legalName'],
                    'country' => is_null($tpRow['country']) ? '' : $tpRow['country'],
                    'state' => is_null($tpRow['state']) ? '' : $tpRow['state'],
                    'POCname' => is_null($tpRow['POCname']) ? '' : $tpRow['POCname'],
                    'POCposi' => is_null($tpRow['POCposi']) ? '' : $tpRow['POCposi'],
                    'POCphone' => is_null($tpRow['POCphone1']) ? '' : $tpRow['POCphone1'],
                    'POCemail' => is_null($tpRow['POCemail']) ? '' : $tpRow['POCemail'],
                    'caseType' => $intakeFormType,
                    'street' => is_null($tpRow['addr1']) ? '' : $tpRow['addr1'],
                    'addr2' => is_null($tpRow['addr2']) ? '' : $tpRow['addr2'],
                    'city' => is_null($tpRow['city']) ? '' : $tpRow['city'],
                    'postCode' => is_null($tpRow['postcode']) ? '' : $tpRow['postcode'],
                    'DBAname' => is_null($tpRow['DBAname']) ? '' : $tpRow['DBAname'],
                    'companyPhone' => is_null($tpRow['POCphone1']) ? '' : substr((string) $tpRow['POCphone1'], 0, 40),
                    'stockExchange' => is_null($tpRow['stockExchange']) ? '' : $tpRow['stockExchange'],
                    'tickerSymbol' => is_null($tpRow['tickerSymbol']) ? '' : $tpRow['tickerSymbol'],
                    'ddqQuestionVer' => $intakeFormVersion,
                    'subInLang' => $recData['Language'],
                    'formClass' => $intakeForms[$intakeForm]['formClass'],
                    'id' => $ddqID,
                    'logLegacy' => ", Intake Form: `$ddqName ($intakeForm)`",
                ],
                'cases' => [
                    'caseName' => is_null($tpRow['legalName']) ? '' : $tpRow['legalName'],
                    'region' => is_null($tpRow['region']) ? '' : (int)$tpRow['region'],
                    'dept' => is_null($tpRow['department']) ? '' : (int)$tpRow['department'],
                    'caseCountry' => is_null($tpRow['country']) ? '' : $tpRow['country'],
                    'caseState' => is_null($tpRow['state']) ? '' : $tpRow['state'],
                    'requestor' => $userID,
                    'creatorUID' => $userID,
                    'tpID' => is_null($tpRow['id']) ? '' : (int)$tpRow['id'],
                ],
                'subjectInfoDD' => [
                    'name' => is_null($tpRow['legalName']) ? '' : $tpRow['legalName'],
                    'country' => is_null($tpRow['country']) ? '' : $tpRow['country'],
                    'subStat' => '',
                    'pointOfContact' => is_null($tpRow['POCname']) ? '' : $tpRow['POCname'],
                    'POCposition' => is_null($tpRow['POCposi']) ? '' : $tpRow['POCposi'],
                    'phone' => is_null($tpRow['POCphone1']) ? '' : $tpRow['POCphone1'],
                    'emailAddr' => is_null($tpRow['POCemail']) ? '' : $tpRow['POCemail'],
                    'street' => is_null($tpRow['addr1']) ? '' : $tpRow['addr1'],
                    'addr2' => is_null($tpRow['addr2']) ? '' : $tpRow['addr2'],
                    'city' => is_null($tpRow['city']) ? '' : $tpRow['city'],
                    'postCode' => is_null($tpRow['postcode']) ? '' : $tpRow['postcode'],
                    'DBAname' => is_null($tpRow['DBAname']) ? '' : $tpRow['DBAname'],
                ]
            ];

            // bail on empty fields required for a new ddq record
            $required = [
                'loginEmail', 'name', 'POCemail', 'formClass', 'country', 'subInLang'
            ];
            if ($action == 'Renew') {
                $required[] = 'id';
            }
            if ($error = $this->validateRequiredFlds($required, $ddqInviteVals['ddq'], 'ddq')) {
                if ($invite) {
                    $canInvite--;
                } elseif ($renew) {
                    $canRenew--;
                }
                $badDdqVals++;
                $this->csvCreateRow($outFp, $recData, 'Error', $error);
                continue;
            }

            // bail on empty fields required for a new cases record
            $required = [
                'caseName', 'region', 'caseCountry', 'creatorUID', 'requestor', 'tpID'
            ];
            if ($error = $this->validateRequiredFlds($required, $ddqInviteVals['cases'], 'cases')) {
                if ($invite) {
                    $canInvite--;
                } elseif ($renew) {
                    $canRenew--;
                }
                $badCasesVals++;
                $this->csvCreateRow($outFp, $recData, 'Error', $error);
                continue;
            }

            // bail on empty fields required for a new subjectInfoDD record
            $required = [
                'name', 'country'
            ];
            if ($error = $this->validateRequiredFlds($required, $ddqInviteVals['subjectInfoDD'], 'subjectInfoDD')) {
                if ($invite) {
                    $canInvite--;
                } elseif ($renew) {
                    $canRenew--;
                }
                $badSubjInfDdVals++;
                $this->csvCreateRow($outFp, $recData, 'Error', $error);
                continue;
            }

            // bail on bad email
            if (!$this->appMailer->isValidAddress($tpRow['POCemail'])) {
                if ($invite) {
                    $canInvite--;
                } elseif ($renew) {
                    $canRenew--;
                }
                $badEmail++;
                $this->csvCreateRow($outFp, $recData, 'Error', 'Invalid POCemail');
                continue;
            }


            if ($phase == 'validation') {
                // End of the line for validation. Move on to the next record.
                $this->csvCreateRow($outFp, $recData, 'Invite', '');
                continue;
            }

            // Do the renew or invite

            $caseID = 0;
            $exceptionMsg = null;
            try {
                if ($invite) {
                    $caseID = $ddq->createInvitation($ddqInviteVals, false, 0, $batchID);
                } elseif ($renew) {
                    // 2014-11-01 grh: Todd agreed renewal should never overwrite existing data
                    $caseID = $ddq->createInvitation($ddqInviteVals, true, $renewalCaseID, $batchID);
                }
            } catch (\Exception $e) {
                $exceptionMsg = $e->getMessage();
            }

            if ($caseID <= 0) {
                // shouldn't happen
                if ($invite) {
                    $canInvite--;
                    $badInvite++;
                } elseif ($renew) {
                    $canRenew--;
                    $badRenewal++;
                }
                $error = 'Failed creating case record';
                if ($exceptionMsg !== null) {
                    $error .= ': ' . $exceptionMsg;
                }
                $this->csvCreateRow($outFp, $recData, $action . 'Error', $error);
                continue;
            }

            // Add batch ID to case, update $recData with new userCaseNum, mark override data as 'used'
            $this->data->updateBatchCase($caseID, $batchID, $tpRow['userTpNum'], $recData);

            $ddqAttribs = ['caseID' => $caseID, 'clientID' => $this->clientID];

            // Send invitation
            $mailStatus = $ddq->findByAttributes($ddqAttribs)->sendInvite();

            // Capture in csv
            if ($mailStatus !== true) {
                $error = "$action created, but failed on sending invitation. $error";
                $this->csvCreateRow($outFp, $recData, $action . ' (Mail Error)', $error);
            } else {
                $this->csvCreateRow($outFp, $recData, $action, '');
                // Update 3P Profile from overrides
                if ($phase == 'processing' && $updateProfile) {
                    $updateProfile = false;
                    $this->data->updateTpProfile($tpRow['id'], $updatePOCname, $updatePOCemail);
                }
            }
        }

        $this->csvStop($outFp, $phase, $batchID, $checked);

        $summary = ['Records' => count($tpIDs)];
        $recsWithIssues = [
            'Has Open Case' => $skipped,
            'Bad Country' => $badCountry,
            'Bad ddq Values' => $badDdqVals,
            'Bad cases Values' => $badCasesVals,
            'Bad subjectInfoDD Values' => $badSubjInfDdVals,
            'Bad Email' => $badEmail,
        ];

        if ($action == 'Renew') {
            $summary['Renew'] = $canRenew;
            $recsWithIssues['Ineligible Renew'] = $badRenewal;
        } else {
            $summary['Invite'] = $canInvite;
            $recsWithIssues['Ineligible Invite'] = $badInvite;
        }
        if ($phase == 'processing') {
            // Done with this session variable. Kill it.
            $this->app->session->forget('massCaseInvitOrderConfig');
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [[
            'type' => 'invitations',
            'summaryTitle' => strtoupper((string) $phase),
            'summary' => $summary,
            'recsWithIssues' => $recsWithIssues,
            'phase' => $phase
        ]];
        if ($phase == 'validation') {
            $this->jsObj->Args[0]['intakeForm'] = $intakeForms[$intakeForm]['name'];
            $this->jsObj->Args[0]['language'] = $language;
            $this->jsObj->Args[0]['overrideRequestor'] = $overrideRequestor;
            $this->jsObj->Args[0]['profileRefList'] = $profileRefList;
        }
    }



    /**
     * Compile override data via bulkIBIupload table
     *
     * @param array  $tpRow          Row of 3P Profile data
     * @param array  $recData        CSV Row
     * @param string $phase          Either validation or processing
     * @param string $intakeFormType ddq.caseType
     *
     * @return array Override data values
     */
    private function initOverride($tpRow, $recData, $phase, $intakeFormType = 0)
    {
        $rtn['updatePOCname'] = $rtn['updatePOCemail'] = $rtn['updateProfile'] = false;
        $rtn['pRelationship1'] = $rtn['pRelationship2'] = $rtn['updatePOCname'] = $rtn['updatePOCemail'] = '';
        $rtn['principalNames'] = []; // needed for cost/time calc
        if ($overrideRow = $this->data->getOverrideData($tpRow['userTpNum'])) {
            // update recData and objects
            $principal1 = (isset($overrideRow['principal1'])) ? trim((string) $overrideRow['principal1']) : '';
            if (!empty($principal1)) {
                $rtn['principalNames'][] = $principal1;
            }
            $principal2 = (isset($overrideRow['principal2'])) ? trim((string) $overrideRow['principal2']) : '';
            if (!empty($principal2)) {
                $rtn['principalNames'][] = $principal2;
            }
            $rtn['pRelationship1'] = (isset($overrideRow['pRelationship1']))
                ? trim((string) $overrideRow['pRelationship1'])
                : '';
            $rtn['pRelationship2'] = (isset($overrideRow['pRelationship2']))
                ? trim((string) $overrideRow['pRelationship2'])
                : '';
            $ovr_POCname = (isset($overrideRow['p3phone'])) ? trim((string) $overrideRow['p3phone']) : '';
            $ovr_POCemail = (isset($overrideRow['p3email'])) ? trim((string) $overrideRow['p3email']) : '';
            $ovr_lang = (isset($overrideRow['p2phone'])) ? trim((string) $overrideRow['p2phone']) : '';
            $ovr_requestor = (isset($overrideRow['gempoc_email'])) ? trim((string) $overrideRow['gempoc_email']) : '';
            if ($ovr_POCname) {
                $mustUpdate = true;
                $tpRow['POCname'] = $ovr_POCname;
                if ($phase == 'processing') {
                    $rtn['updatePOCname'] = $ovr_POCname;
                    $rtn['updateProfile'] = true;
                }
            }
            // only override email language if there is a system email for it
            if ($ovr_POCemail && $this->appMailer->isValidAddress($ovr_POCemail)) {
                $mustUpdate = true;
                $tpRow['POCemail'] = $ovr_POCemail;
                if ($phase == 'processing') {
                    $rtn['updatePOCemail'] = $ovr_POCemail;
                    $rtn['updateProfile'] = true;
                }
            }
            if ($ovr_requestor && ($ovr_requestor_id = $this->data->getOverrideRequestorID($ovr_requestor))) {
                $mustUpdate = true;
                $tpRow['ownerID'] = $ovr_requestor_id;
            }
            if ($mustUpdate) {
                $recData = $this->csvFormatRow($tpRow, $recData['Language']);
            }
            if ($intakeFormType && $ovr_lang
                && (!$langError = $this->validateLanguage($ovr_lang, $intakeFormType))
            ) {
                $recData['Language'] = $ovr_lang;
            }
        }
        $rtn['tpRow'] = $tpRow;
        $rtn['recData'] = $recData;
        return $rtn;
    }


    /**
     * Validate non-invitation cases
     *
     * @param integer $scopeID           cases.caseType
     * @param integer $billingUnit       cases.billingUnit
     * @param integer $billingUnitPO     cases.billingUnitPO
     * @param string  $SBIonPrincipals   subjectInfoDD.SBIonPrincipals
     * @param integer $spID              cases.caseAssignedAgent
     * @param integer $investigatorID    cases.caseInvestigatorUserID
     * @param string  $estimatedDueDate  cases.caseDueDate
     * @param string  $overrideRequestor Email address override for cases.requestor
     * @param string  $profileRefList    Third party profile references
     * @param string  $phase             Either 'validation' or 'processing'
     *
     * @return bool
     */
    private function validateCases(
        $scopeID,
        $billingUnit,
        $billingUnitPO,
        $SBIonPrincipals,
        $spID,
        $investigatorID,
        $estimatedDueDate,
        $overrideRequestor,
        $profileRefList,
        $phase
    ) {
        $validInvestigator = true;
        $validSP = $validScope = $validSBI = false;
        $isFullSP = 0;
        $investigators = $this->data->getInvestigators($spID);
        foreach ($this->serviceProviders as $idx => $sp) {
            if ($sp['id'] == $spID) {
                $validSP = true;
                $isFullSP = $sp['fullSP'];
                if ($isFullSP) {
                    $validInvestigator = false;
                    foreach ($investigators as $investigator) {
                        if ($investigator['id'] == $investigatorID) {
                            $validInvestigator = true;
                            break;
                        }
                    }
                }
                break;
            }
        }
        foreach ($this->scopes as $idx => $scope) {
            if ($scope['id'] == $scopeID) {
                $validSBI = true;
                if ($scope['id'] == Cases::DUE_DILIGENCE_SBI) {
                    $validSBI = ($SBIonPrincipals == 'Yes' || $SBIonPrincipals == 'No');
                }
                $validScope = true;
                break;
            }
        }
        $validBU = false;
        $exceptionMsg = null;
        try {
            $validBU = (new Cases($this->clientID))->vetNewCaseBillingUnits(
                ['billingUnit' => $billingUnit, 'billingUnitPO' => $billingUnitPO],
                (new BillingUnit($this->clientID))->getActiveBillingUnits(),
                (new BillingUnitPO($this->clientID))->getBillingUnitPOs($billingUnit)
            );
        } catch (\Exception $e) {
            $exceptionMsg = $e->getMessage();
        }
        $profileListValidation = $this->validateProfileList($profileRefList);
        if (!empty($profileListValidation['error'])) {
            $this->jsObj->ErrMsg = $profileListValidation['error'];
        } elseif (!$validScope) {
            $this->jsObj->ErrMsg = 'Invalid Scope of Investigation.';
        } elseif (!$validSBI) {
            $this->jsObj->ErrMsg = 'Invalid entry for Including Principals in field investigation.';
        } elseif (!$validSP) {
            $this->jsObj->ErrMsg = 'Invalid Service Provider.';
        } elseif (!$validInvestigator) {
            $this->jsObj->ErrMsg = 'Invalid Investigator.';
        } elseif ($dueDateError = $this->validateDueDate($estimatedDueDate)) {
            $this->jsObj->ErrMsg = $dueDateError;
        } elseif (!empty($overrideRequestor)
            && (!$overrideRequestorID = $this->data->getOverrideRequestorID($overrideRequestor))
        ) {
            $this->jsObj->ErrMsg = 'Invalid Requestor Override.';
        } elseif (!$tpIDs = $this->data->getThirdPartyIDs($profileListValidation['profiles'])) {
            $this->jsObj->ErrMsg = 'No 3P records found.';
        } elseif (!$validBU) {
            $this->jsObj->ErrMsg = 'Invalid Billing Unit and/or Billing Unit Purchase Order.';
        }
        if (!empty($this->jsObj->ErrMsg)) {
            // Kill the overrides to start from scratch.
            $this->app->session->forget('massCaseInvitOrderConfig');

            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = 'Invitation validation error.';
        } else {
            $this->jsObj->Result = 1;
            $action = ($phase == 'processing') ? 'Processing' : 'Validating';
            $this->jsObj->Args[0]['msg'] = $action . ' ' . count($tpIDs) . ' records. Please wait...';
            $config = [
                'scopeID' => $scopeID,
                'billingUnit' => $billingUnit,
                'billingUnitPO' => $billingUnitPO,
                'SBIonPrincipals' => $SBIonPrincipals,
                'spID' => $spID,
                'isFullSP' => $isFullSP,
                'investigatorID' => (($isFullSP) ? $investigatorID : 0),
                'estimatedDueDate' => $estimatedDueDate,
                'overrideRequestor' => $overrideRequestor,
                'overrideRequestorID' => ((!empty($overrideRequestor)) ? $overrideRequestorID : 0),
                'profileRefList' => $profileRefList,
                'tpIDs' => $tpIDs,
                'phase' => $phase,
                'clientID' => $this->clientID,
            ];
            $this->app->session->set('massCaseInvitOrderConfig', $config);
        }
    }


    /**
     * Validate estimated due date for non-invitation cases
     *
     * @param string $dueDate Case completion date
     *
     * @return mixed false if no error, otherwise string containing the error
     */
    private function validateDueDate($dueDate)
    {
        $error = false;
        $now = date('Y-m-d');
        if (!preg_match('/^20\d{2}(-\d{2}){2}$/', $dueDate)) {
            $error = "Invalid Completion Date.";
        } elseif ($dueDate <= $now) {
            $error = "Completion Date must be future.";
        }
        return $error;
    }

    /**
     * Validate invitations for cases
     *
     * @param string $intakeForm        DDQ legacyID
     * @param string $language          Language
     * @param string $overrideRequestor Email address override for cases.requestor
     * @param string $profileRefList    Third party profile references
     * @param string $phase             Either 'validation' or 'processing'
     *
     * @return bool
     */
    private function validateInvitations($intakeForm, $language, $overrideRequestor, $profileRefList, $phase)
    {
        $ddq = new Ddq($this->clientID);
        $intakeFormID = $ddq->splitDdqLegacyID($intakeForm);
        $intakeFormVersion = $intakeFormID['ddqQuestionVer'];
        $intakeFormType = $intakeFormID['caseType'];
        $profileListValidation = $this->validateProfileList($profileRefList);
        $intakeForms = $this->data->getIntakeForms();

        if (!empty($profileListValidation['error'])) {
            $this->jsObj->ErrMsg = $profileListValidation['error'];
        } elseif (!array_key_exists($intakeForm, $intakeForms)) {
            $this->jsObj->ErrMsg = 'Unknown Intake Form.';
        } elseif (($this->data->getPreferredLanguageDefined() == 0)
            && ($langError = $this->validateLanguage($language, $intakeFormType))
        ) {
            $this->jsObj->ErrMsg = $langError;
        } elseif (!empty($overrideRequestor)
            && (!$overrideRequestorID = $this->data->getOverrideRequestorID($overrideRequestor))
        ) {
            $this->jsObj->ErrMsg = 'Invalid Requestor Override.';
        } elseif (!$tpIDs = $this->data->getThirdPartyIDs($profileListValidation['profiles'])) {
            $this->jsObj->ErrMsg = 'No 3P records found.';
        }
        if (!empty($this->jsObj->ErrMsg)) {
            // Kill the overrides to start from scratch.
            $this->app->session->forget('massCaseInvitOrderConfig');

            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = 'Invitation validation error.';
        } else {
            $this->jsObj->Result = 1;
            $action = ($phase == 'processing') ? 'Processing' : 'Validating';
            $this->jsObj->Args[0]['msg'] = $action . ' ' . count($tpIDs) . ' records. Please wait...';
            $config = [
                'intakeForm' => $intakeForm,
                'intakeFormVersion' => $intakeFormVersion,
                'intakeFormType' => $intakeFormType,
                'language' => $language,
                'overrideRequestor' => $overrideRequestor,
                'overrideRequestorID' => ((!empty($overrideRequestor)) ? $overrideRequestorID : 0),
                'profileRefList' => $profileRefList,
                'action' => ($ddq->isRenewal($intakeForm)) ? 'Renew' : 'Invite',
                'tpIDs' => $tpIDs,
                'phase' => $phase,
                'clientID' => $this->clientID,
            ];
            $this->app->session->set('massCaseInvitOrderConfig', $config);
        }
    }



    /**
     * Validate language
     *
     * @param string  $language       ISO code
     * @param integer $intakeFormType ddq.caseType
     *
     * @return mixed false if no error, otherwise string containing the error
     */
    private function validateLanguage($language, $intakeFormType)
    {
        $error = false;
        if (!$valid = (new Languages())->validLang($language)) {
            // Invalid language
            $error = "Invalid Invitation Email Language";
        } elseif (!$exists = (new SystemEmails($this->clientID))->emailExists($language, $intakeFormType)) {
            // Invite email does not exist for the language.
            $error = "No system email exists for Invitation Email Language";
        }
        return $error;
    }




    /**
     * Validate 3P profile reference list
     *
     * @param string $profileList third party profile references
     *
     * @return array Items include error string and profiles array
     */
    private function validateProfileList($profileList)
    {
        $rtn = ['error' => '', 'profiles' => []];

        if (empty($profileList)) {
            $rtn['error'] = 'No profile references entered.';
        } elseif (strlen($profileList) > self::PROFILE_LIST_CHAR_MAX) {
            $rtn['error'] = 'Profile references must be no longer than ' . self::PROFILE_LIST_CHAR_MAX . ' characters.';
        } elseif (!$profiles = (new SequenceParse())->parseIdentifierSequences($profileList)) {
            $rtn['error'] = 'One or more invalid profile references.';
        } elseif (!$profiles['singles'] && !$profiles['ranges']) {
            $rtn['error'] = 'No valid profile references.';
        } else {
            $rtn['profiles'] = $profiles;
        }
        return $rtn;
    }



    /**
     * Validate that required fields are not empty.
     *
     * @param array  $requiredFlds array of fields that are required
     * @param array  $vals         array of field values
     * @param string $type         either 'ddq', 'cases' or 'subjectInfoDD'
     *
     * @return mixed false if no error, otherwise string containing the error
     */
    private function validateRequiredFlds($requiredFlds, $vals, $type)
    {
        $error = false;
        $emptyFlds = [];
        foreach ($requiredFlds as $fld) {
            if (empty($vals[$fld])) {
                $emptyFlds[] = $fld;
            }
        }
        if (!empty($emptyFlds)) {
            $error = 'These ' . $type . ' fields are missing values: ' . join(", ", $emptyFlds);
        }
        return $error;
    }
}
