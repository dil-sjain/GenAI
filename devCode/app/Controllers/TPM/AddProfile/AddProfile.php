<?php
/**
 * Add Profile Dialog
 *
 * @category AddProfile_Dialog
 * @package  Controllers\TPM\AddProfile
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddProfile;

use Controllers\Cli\MediaMonitor\MediaMonitorQueue;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\ThirdPartyManagement\GdcMonitor;
use Models\ThirdPartyManagement\ThirdParty;
use Models\TPM\AddProfile\AddProfile as mAddProfile;
use Models\TPM\Workflow\TenantWorkflow;
use Models\User;
use Models\TPM\SubscriberCustomLabels;
use Lib\Legacy\Security;
use Models\Globals\Geography;
use Models\LogData;

/**
 * Class AddProfile
 */
class AddProfile extends Base
{
    use AjaxDispatcher;

    /**
     * @var object Instance of model
     */
    protected $model = null;

    /**
     * @var object Instance of app
     */
    protected $app = null;

    /**
     * @var $tplRoot string Base directory for View
     */
    protected $tplRoot = 'TPM/AddProfile/';

    /**
     * @var $tpl string Base template for View
     */
    protected $tpl = 'AddProfile.tpl';

    /**
     * @var integer Client ID
     */
    public $clientID = null;

    /**
     * @var integer $_SESSION authUserType
     */
    public $userType = null;

    /**
     * @var integer $_SESSION authUserID
     */
    public $userID = null;

    /**
     * @var integer|null $_SESSION userSecLevel
     */
    public $userSecLevel = null;

    /**
     * @var array thirdPartyProfile record
     */
    private $record = [];

    /**
     * @var array user input
     */
    private $inputs = [];

    /**
     * AddProfile constructor.
     *
     * Add Profile consists of 3 main AJAX requests: Start page, Detail page, Review page
     *
     * @param int $clientID logged in tenant
     *
     * @throws \Exception
     */
    public function __construct($clientID)
    {
        $this->app          = \Xtra::app();
        $this->clientID     = (int)$clientID;
        $this->userID       = $this->app->ftr->user;
        $this->userType     = $this->app->ftr->legacyUserType;
        $this->model        = new mAddProfile($this->clientID, $this->userID);
        $this->userSecLevel = (new User())->findByAttributes(['id' => $this->userID])->get('userSecLevel');
        $this->record       = $this->setRecord();
        $this->inputs       = $this->setInputs();
        $this->trText       = $this->app->trans->group('add_profile_dialog');
        $this->ajaxExceptionLogging = true;
    }


    /**
     * AJAX request for Start Page
     *
     * @return HTML template string for Add Profile dialog Start Page
     */
    public function ajaxStartPage()
    {
        $profileTypes = $this->addSelectDefault($this->model->getProfileTypesList());
        $profileType  = (count($profileTypes) == 1) ? key($profileTypes) : '';
        $categories   = (!empty($profileType)) ? $this->model->npCategories($profileType) : [];
        $caseName     = \Xtra::arrayGet($this->app->clean_POST, 'cname', ''); // appNS.addCase.addProfileClick
        $companyName  = $this->record['legalName'] ?: $caseName;

        $this->setBaseViewValues();
        $this->setViewValue('pageName', 'StartPage');
        $this->setViewValue('ProfileTypes', $profileTypes);
        $this->setViewValue('npType', $profileType);
        $this->setViewValue('tpTypeCategories', $this->addSelectDefault($categories));
        $this->setViewValue('companyName', $companyName);
        $this->setViewValue('tpTypeCategory', $this->record['tpTypeCategory']);

        $matches = ($companyName) ? $this->model->returnMatches($companyName) : null;

        $this->jsObj->Args = [$this->app->view->fetch($this->getTemplate(), $this->getViewValues()), $matches];
        $this->jsObj->FuncName = 'appNS.addProfile.open';
        $this->jsObj->Result = 1;
    }


    /**
     * AJAX request for Detail Page
     *
     * Validates `Start Page`
     *
     * @return HTML template string for Add Profile dialog Detail Page
     * on success or an error message on failure.
     */
    public function ajaxDetailPage()
    {
        try {
            $error = $this->validateStartPage();
            if (empty($error)) {
                $this->setDetailPageView();
                $this->jsObj->Args = [$this->app->view->fetch($this->getTemplate(), $this->getViewValues())];
                $this->jsObj->FuncName = 'appNS.addProfile.template';
                $this->jsObj->Result = 1;
            } else {
                $this->setPageError($error);
            }
        } catch (\Exception $e) {
            $this->jsObj->ErrMsg = $e->getMessage();
            $this->jsObj->Result = 0;
        }
    }


    /**
     * AJAX request for Review Page
     *
     * Validates `Detail Page`
     *
     * @return HTML template string for Add Profile dialog Review Page
     * on success or an error message on failure.
     */
    public function ajaxReviewPage()
    {
        try {
            $errors = $this->validateDetailPage();
            if (empty($errors)) {
                $this->setReviewPageView();
                $this->jsObj->Args = [$this->app->view->fetch($this->getTemplate(), $this->getViewValues())];
                $this->jsObj->FuncName = 'appNS.addProfile.template';
                $this->jsObj->Result = 1;
            } else {
                $this->setPageError($errors);
            }
        } catch (\Exception $e) {
            $this->jsObj->ErrMsg = $e->getMessage();
            $this->jsObj->Result = 0;
        }
    }


    /**
     * AJAX request for Submitting Third Party Profile
     *
     * Validates all user input.
     * Warns user of an exact match if necessary.
     * Creates a new Third Party Profile.
     * Logs the event.
     * Runs a GDC on the record if necessary.
     *
     * @return void Prepares an ajax response
     *
     * @throws \Exception
     */
    public function ajaxSubmitPage()
    {
        $errors = $this->validateStartPage() + $this->validateDetailPage();
        if (!$errors) {
            // Exact match warning
            if ($this->model->isExactMatch($this->inputs['npCompany'])) {
                $saveAnyway = \Xtra::arrayGet($this->app->clean_POST, 'saveAnyway', null);
                if ($saveAnyway != 'saveAnyway') {
                    $this->jsObj->Args = [];
                    $this->jsObj->Result = 1;
                    $this->jsObj->FuncName = 'appNS.addProfile.showWarning';
                    return;
                }
            }
            //  Save the new profile, returning the profileID or a failure message
            $newProfile = $this->addNewProfile();
            // Insert failure
            if (!is_numeric($newProfile)) {
                $this->setPageError($newProfile);
                return;
            }
            //  Log event
            $this->logAddProfile("company: `{$this->inputs['npCompany']}`", $newProfile);

            //  Run GDC
            $this->runGDC($newProfile);

            // Run risk assessment
            $tp = new ThirdParty($this->clientID);
            $tp->updateCurrentRiskAssessment($newProfile);

            // Run Media Monitor
            $this->runMediaMonitor($newProfile);

            // Run Workflow event PROFILE_CREATED
            if ($this->app->ftr->tenantHasAllOf([\Feature::TENANT_API_INTERNAL, \Feature::TENANT_WORKFLOW])) {
                $workflow = (new TenantWorkflow($this->clientID))->getTenantWorkflowClass();

                if ($workflow->tenantHasEvent($workflow::PROFILE_CREATED)) {
                    $workflow->startProfileWorkflow($newProfile);
                }
            }

            $this->jsObj->Args = [$newProfile];
            $this->jsObj->Result = 1;
            $this->jsObj->FuncName = 'appNS.addProfile.handleSubmit';
        } else {
            $this->setPageError($errors);
        }
    }


    /**
     * AJAX Request event for profile selection
     *
     * @return object list of categories for the given profile type
     */
    public function ajaxRequestCategories()
    {
        $categories  = [];
        $profileType = (int)\Xtra::arrayGet($this->app->clean_POST, 'profileType', '');

        foreach ($this->addSelectDefault($this->model->npCategories($profileType)) as $key => $value) {
            $categories[] = ['id' => $key, 'name' => $value];
        }

        $this->jsObj->Args     = [$categories];
        $this->jsObj->Result   = 1;
        $this->jsObj->FuncName = 'appNS.addProfile.npCategoryReload';
    }


    /**
     * AJAX Request event for possible existing matches/official company name text entering
     *
     * @return object List of possible existing thirdPartyProfile matches
     */
    public function ajaxRequestMatches()
    {
        $search  = \Xtra::arrayGet($this->app->clean_POST, 'searchText', '');
        $matches = $this->model->returnMatches($search);

        $this->jsObj->Result    = 1;
        $this->jsObj->FuncName  = 'appNS.addProfile.npMatchPopulate';
        $this->jsObj->Args      = [
            $matches->Matches,
            $matches->Count,
            $matches->TooMany,
        ];
    }


    /**
     * AJAX Request event for Country selection
     *
     * @return object list of matching State/Provinces for the iso code
     */
    public function ajaxRequestStatesProvinces()
    {
        $isoCode = \Xtra::arrayGet($this->app->clean_POST, 'isoCode', '');

        $this->jsObj->Args      = [$this->addSelectDefault($this->states($isoCode))];
        $this->jsObj->FuncName  = 'appNS.addProfile.npState';
        $this->jsObj->Result    = 1;
    }


    /**
     * AJAX request for an existing thirdPartyProfile view
     *
     * @return access information on a third party profile
     * including un/restricted access level and record information
     */
    public function ajaxRecordAccessDialog()
    {
        try {
            $recordID   = \Xtra::arrayGet($this->app->clean_POST, 'tpID', '');
            $recordInfo = $this->model->restrictedProfileAccess($recordID);
            $this->jsObj->Args     = [ $recordInfo->access, $recordInfo->formatted, $recordID ];
            $this->jsObj->FuncName = 'appNS.addProfile.restrictedAccessDialog';
            $this->jsObj->Result   = 1;
        } catch (\Exception $e) {
            $this->jsObj->ErrMsg = $e->getMessage();
            $this->jsObj->Result = 0;
        }
    }


    /**
     * Validates the Start Page
     *
     * @return array error message, empty on successful validation
     */
    private function validateStartPage()
    {
        $errors         = [];
        $profileTypes   = $this->model->getProfileTypesList();
        $categoryTypes  =  $this->model->npCategories($this->inputs['npType']);

        if (!isset($profileTypes[$this->inputs['npType']])) {
            $errors[] = $this->trText['invalid_profile_type'];
        }
        if (empty($this->inputs['npCompany'])) {
            $errors[] = $this->trText['company_name_not_provided'];
        }
        if (!isset($categoryTypes[$this->inputs['npCategory']])) {
            $errors[] = $this->trText['invalid_category_type'];
        }

        return $errors;
    }


    /**
     * Validates the Detail Page
     *
     * @return string error message, empty on successful validation
     */
    private function validateDetailPage()
    {
        $errors   = [];
        $validate = [
            'npCountry'    => false,
            'npState'      => false,
            'npRegion'     => false,
            'npDepartment' => false,
        ];

        $this->inputs['npRecordType'] = (in_array($this->inputs['npRecordType'], ['Entity', 'Person']))
            ?   $this->inputs['npRecordType']
            :   'Entity';

        if (!empty($this->inputs['npCountry'])) {
            $langCode = $this->app->session->languageCode ?? 'EN_US';
            $countries = (Geography::getVersionInstance(null, $this->clientID))->countryList('', $langCode);
            $validate['npCountry']  = isset($countries[$this->inputs['npCountry']]);
        }
        if (!empty($this->inputs['npState']) && $validate['npCountry']) {
            $states                 = $this->states($this->inputs['npCountry']);
            $validate['npState']    = isset($states[$this->inputs['npState']]);
        }
        if (!empty($this->inputs['npRegion'])) {
            $regions                = $this->model->getUserRegions();
            $validate['npRegion']   = isset($regions[$this->inputs['npRegion']]);
        }
        if (!empty($this->inputs['npDepartment'])) {
            $departments                = $this->model->getUserDepartments();
            $validate['npDepartment']   = isset($departments[$this->inputs['npDepartment']]);
        }
        if (!$validate['npCountry']) {
            $errors[] = $this->trText['choose_profile_country'];
        }
        if (!$validate['npRegion']) {
            $errors[] = $this->trText['choose_profile_region'];
        }
        if (!empty($this->inputs['npEmail'])
            && !filter_var(html_entity_decode((string) $this->inputs['npEmail'], ENT_QUOTES), FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] = $this->trText['error_invalid_email'];
        }

        return $errors;
    }


    /**
     * Sets Detail Page view values
     *
     * @return void
     */
    private function setDetailPageView()
    {
        $this->setBaseViewValues();

        $departments  = $this->model->getUserDepartments();
        $department   = (count($departments) == 1) ? key($departments) : 0;
        $regions      = $this->model->getUserRegions();
        $region       = (count($regions) == 1) ? key($regions) : 0;
        $highlightLbl = ($this->app->ftr->tenantHas(\Feature::TENANT_3P_HIGHLIGHT_INTERNAL_CODE)) ? 1 : 0;
        $langCode     = $this->app->session->languageCode ?? 'EN_US';

        $this->setViewValue('highlightLbl', $highlightLbl);
        $this->setViewValue('pageName', 'DetailPage');
        $this->setViewValue('Departments', $departments);
        $this->setViewValue('npDepartment', $department);
        $this->setViewValue('Regions', $regions);
        $this->setViewValue('npRegion', $region);
        $this->setViewValue(
            'Countries',
            $this->addSelectDefault((Geography::getVersionInstance())->countryList('', $langCode))
        );
        $this->setViewValue('States', $this->states($this->inputs['npCountry']));
    }


    /**
     * Sets Review Page view values
     *
     * @return void
     */
    private function setReviewPageView()
    {
        $this->setBaseViewValues();
        $this->setViewValue('pageName', 'ReviewPage');
        $langCode = $this->app->session->languageCode ?? 'EN_US';

        $profileTypes   = $this->model->getProfileTypesList();
        $categories     = $this->model->npCategories($this->inputs['npType']);
        $countries      = (Geography::getVersionInstance(null, $this->tenantID))->countryList('', $langCode);
        $states         = $this->states($this->inputs['npCountry']);
        $regions        = $this->model->getUserRegions();
        $departments    = $this->model->getUserDepartments();
        $recordTypeName = ($this->inputs['npRecordType'] !== 'Person')
                            ?   $this->trText['tp_profile_record_entity']
                            :   $this->trText['tp_profile_record_person'];
        $labels         = [
            'recordTypeName'    =>  $recordTypeName,
            'npTypeName'        =>  $profileTypes[$this->inputs['npType']],
            'npCategoryName'    =>  $categories[$this->inputs['npCategory']],
            'npCountryName'     =>  $countries[$this->inputs['npCountry']],
            'npStateName'       =>  $states[$this->inputs['npState']] ?? '',
            'npRegionName'      =>  $regions[$this->inputs['npRegion']],
            'npDepartmentName'  =>  $departments[$this->inputs['npDepartment']] ?? '',
        ];
        foreach ($labels as $key => $value) {
            $this->setViewValue($key, $value);
        }
    }


    /**
     * Sets base view values, including sitePath & custom labels
     *
     * @return void
     */
    private function setBaseViewValues()
    {
        $this->setViewValue('sitePath', $this->app->sitePath);
        $this->setViewValue('inDevelopment', ($this->app->mode == 'Development'));

        foreach ((new SubscriberCustomLabels($this->clientID))->getLabels() as $key => $customLabel) {
            $this->setViewValue($key, $customLabel);
        }
        foreach ($this->trText as $key => $label) {
            $this->setViewValue($key, $label);
        }
        foreach ($this->inputs as $key => $value) {
            $this->setViewValue($key, $value);
        }
    }


    /**
     * Logs a successful thirdPartyProfile record creation event
     *
     * @param string  $message new profile log message
     * @param integer $tpID    Third Party record ID
     *
     * @return void
     */
    private function logAddProfile($message, $tpID)
    {
        $logData = new LogData($this->clientID, $this->userID, $this->userType);
        $logData->save3pLogEntry(51, $message, $tpID, 0, $this->clientID);
    }


    /**
     * Performs GDC on new thirdPartyProfile
     *
     * @param int $thirdPartyProfileID thirdPartyProfile.id
     *
     * @return void
     */
    private function runGDC($thirdPartyProfileID)
    {
        $thirdPartyProfileID = (int)$thirdPartyProfileID;
        if (!empty($thirdPartyProfileID) && $this->app->ftr->tenantHas(\Feature::TENANT_GDC_BASIC)) {
            $GdcMonitor = new GdcMonitor($this->clientID, $this->userID);
            $GdcMonitor->run3pGdc($thirdPartyProfileID);
        }
    }

    /**
     * Performs Media Monitor on new thirdPartyProfile
     *
     * @param int $thirdPartyProfileID thirdPartyProfile.id
     *
     * @return void
     */
    private function runMediaMonitor($thirdPartyProfileID)
    {
        $thirdPartyProfileID = (int)$thirdPartyProfileID;
        if (!empty($thirdPartyProfileID) && $this->app->ftr->tenantHas(\Feature::TENANT_MEDIA_MONITOR)) {
            (new MediaMonitorQueue())->queueProfile($this->userID, $this->clientID, $thirdPartyProfileID);
        }
    }

    /**
     * Perform a "one off" media monitor background search based on the current profile and those subjects
     *
     * @param integer $clientID  g_tenants.id
     * @param integer $profileID thirdPartyProfile.id
     *
     * @return void
     */
    private function performMMOneOffSearch($clientID, $profileID)
    {
        if (!$this->app->ftr->tenantHas(\Feature::TENANT_MEDIA_MONITOR)) {
            return;
        }
        $clientID  = (int)$clientID;
        $profileID = (int)$profileID;

        $target  = escapeshellcmd("Controllers.Cli.MediaMonitorQueue::queueProfile $clientID $profileID" . ' ' . true);
        $fork    = new \Lib\Support\ForkProcess();
        $fork->launch($target);
    }


    /**
     * AJAX Response generic page error
     *
     * @param array $errors error array added into AJAX response on failure
     *
     * @return object response for handling unsuccessful AJAX requests
     */
    private function setPageError($errors)
    {
        $errorMessage = '';
        foreach ($errors as $error) {
            $errorMessage .= '<li>' . $error . '</li>';
        }

        $this->jsObj->ErrTitle = 'Invalid or Missing Input';
        $this->jsObj->ErrMsg   = $errorMessage;
        $this->jsObj->errors   = null;
        $this->jsObj->Result   = 0;
    }


    /**
     * Adds the new thirdPartyProfile record
     *
     * READ_ONLY generally not used in DELTA anymore, however, keeping it in for Legacy/Delta consistency
     *
     * @return mixed thirdPartyProfile.id on success, error string/void on failure
     */
    private function addNewProfile()
    {
        if ($this->userSecLevel > Security::READ_ONLY) {
            return $this->model->addNewThirdParty($this->inputs);
        }
    }


    /**
     * Queries for an indexed list of states
     *
     * @param string $isoCode sanitized and validated user input country iso code
     *
     * @return array indexed list of states for the given country iso code
     */
    private function states($isoCode)
    {
        if ($isoCode) {
            $geo = Geography::getVersionInstance(null, $this->tenantID);
            $country = $geo->getLegacyCountryCode($isoCode);
            $langCode = $this->app->session->languageCode ?? 'EN_US';
            if ($states = $geo->stateList($country, '', $langCode)) {
                return $states;
            } else {
                return ['N' => $geo->translateCodeKey($langCode)];
            }
        }
    }


    /**
     * An associative array of sanitized user input
     *
     * @return array
     */
    private function setInputs()
    {
        $sanitized  = [];
        foreach ([
                'npType',
                'npCompany',
                'npCategory',
                'npRecordType',
                'npDBAname',
                'npInternalCode',
                'npAddr1',
                'npAddr2',
                'npCity',
                'npCountry',
                'npState',
                'npPostCode',
                'npRegion',
                'npDepartment',
                'npPoc',
                'npEmail',
                'npPhone1',
                'npPhone2',
            ] as $input
        ) {
            $sanitized[$input] = trim((string) \Xtra::arrayGet($this->app->clean_POST, $input, null));
        }

        return $sanitized;
    }


    /**
     *  Attempts to find a thirdPartyProfile given an optional thirdPartyProfile.id,
     *  setting the complete record or default keys to class property.
     *
     * @return array thirdPartyProfile record or defaults for form inputs
     */
    private function setRecord()
    {
        $tppID  = \Xtra::arrayGet($this->app->clean_POST, 'tppID', '');
        $record = ($tppID && $this->app->ftr->has(\Feature::TENANT_TPM))
            ?   $this->model->getTPPEntryData($tppID)
            :   [];

        return $record ?: [
            'npType'         =>  0,
            'legalName'      =>  '',
            'tpTypeCategory' =>  0,
        ];
    }


    /**
     * Prepends the default option to an array if needed.
     *
     * @param array $selectArray associative array shown as a select menu that may require a default option.
     *
     * @return array the same array given with the default option prepended if needed.
     */
    private function addSelectDefault($selectArray)
    {
        return (count($selectArray) > 1)
            ?   [0 => $this->app->trans->codeKey('select_default')] + $selectArray
            :   $selectArray;
    }
}
