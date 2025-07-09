<?php
/**
 * Edit User Profile
 *
 * @keywords user, profile, edit, password, details, modify, update
 */

namespace Controllers\TPM\Settings\UserProfile;

use Controllers\ThirdPartyManagement\Base;
use Lib\Database\MySqlPdo;
use Models\Globals\Geography;
use Models\TPM\Settings\UserProfile\UserProfileData;
use Models\User;
use Lib\Legacy\UserType;
use Models\ThirdPartyManagement\LegacyUserAccess;
use Models\Globals\Features;
use Models\LogData;
use Lib\Traits\AjaxDispatcher;
use Lib\Crypt\Crypt64;
use Controllers\Listeners\UserChangeLogging;
use Lib\Support\UserLock;
use Controllers\Listeners\UserNotificationPreferencesChangeLogging;
use Models\MasterLoginData;
use Controllers\Login\MasterLogin;
use Lib\Crypt\Argon2Encrypt;
use Lib\Validation\ValidateFuncs;

// allow PHPUNIT to mock LegacyUserAccess, bypasses legacy dependencies on $_SESSION
if (!class_exists('\LegacyUserAccessAlias')) {
    class_alias(\Models\ThirdPartyManagement\LegacyUserAccess::class, 'LegacyUserAccessAlias');
}

/**
 * Class UserProfile modifies User record
 */
#[\AllowDynamicProperties]
class UserProfile
{
    use AjaxDispatcher;

    public const MIN_PASS_LEN = 8;  // Minimum allowable character count for new passwords
    public const MAX_PASS_LEN = 50; // Maximum allowable character count for new passwords
    public const COMPANY_TERMS_PATH = "/cms/misc/company_terms.pdf";

    /**
     * @var \Skinny\Skinny Class instance
     */
    protected $app;

    /**
     * @var MySqlPdo Class instance
     */
    protected $DB;

    /**
     * @var Base Class instance
     */
    protected $baseCtrl;

    /**
     * @var object App session singleton
     */
    protected $sess = null;

    /**
     * @var UserProfileData Class instance
     */
    protected $m;

    /**
     * @var \LegacyUserAccessAlias Class instance
     */
    protected $accCls;

    /**
     * @var User Class instance
     */
    protected $User;

    /**
     * @var LogData Class instance
     */
    protected $LogData;

    /**
     * @var array|null Prompts/labels for user input
     */
    protected $prompts = null;

    /**
     * @var array|null Column names
     */
    protected $optField = null;

    /**
     * @var array|null Column names
     */
    protected $optFieldNoCol = null;

    /**
     * @var int TPM tenant identifier
     */
    protected $tenantID = 0;

    /**
     * @var int users.id
     */
    protected $userID = 0;

    /**
     * @var string Smarty template name
     */
    protected $tpl = 'UserProfile.tpl';

    /**
     * @var string Smarty template root
     */
    protected $tplRoot = 'TPM/Settings/UserProfile/';

    /**
     * @var string Delta route for AAX calls
     */
    protected $jsRoute = '/tpm/cfg/usrPrfl';

    /**
     * @var array Valid langding pages for current user
     */
    protected $landPgMap = [];

    /**
     * @var bool Flag to display languagee selector
     */
    protected $langSel = false;

    /**
     * @var array User Profile text translations
     */
    protected $upTrans = [];

    /**
     * @var bool Feature flag
     */
    protected $userNotificationOptIn = false;

    /**
     * @var bool If 2FA (Optional) is enabled by the tenant
     */
    protected $tfaOptional = false;

    /**
     * @var If 2FA (Enforced) is enabled by the tenant
     */
    protected $tfaEnforced = false;

    /**
     * @var bool Sets to true if AWS_ENABLED flag is set to true
     */
    protected $awsEnabled = false;

    /**
     * @var bool Sets to true if HBI_ENABLED flag is set to true
     */
    protected $hbiEnabled = false;


    /**
     * Constructor
     *
     * @param integer $tenantID   Delta tenantID (aka: clientProfile.id)
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;
        // allow PHPUNIT to mock LegacyUserAccess, bypasses legacy dependencies on $_SESSION
        if (!class_exists('\LegacyUserAccessAlias')) {
            class_alias('Models\ThirdPartyManagement\LegacyUserAccess', 'LegacyUserAccessAlias');
        }

        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];

        $this->baseCtrl       = new Base($tenantID, $initValues);
        $this->app            = \Xtra::app();
        $this->DB             = $this->app->DB;
        $this->sess           = $this->app->session;
        $this->userID         = $this->sess->get('authUserID');
        $this->m              = new UserProfileData($tenantID);
        $this->accCls         = new \LegacyUserAccessAlias($tenantID);
        $this->User           = new User();
        $this->LogData        = new LogData($this->tenantID, $this->userID);
        $this->langSel        = $this->app->ftr->tenantHas(\Feature::TENANT_MULTI_LANG_UI);
        $this->app->trans->tenantID = $this->tenantID;
        $this->upTrans  = $this->app->trans->group('user_profile');
        $this->userNotificationOptIn = (
            $this->app->session->get('authUserType') != UserType::CLIENT_USER &&
            $this->app->ftr->tenantHas(\Feature::TENANT_USER_NOTIFICATION_OPT_IN)
        );

        $this->tfaOptional    = $this->app->ftr->tenantHas(\Feature::TENANT_2FA_OPTIONAL);
        $this->tfaEnforced    = $this->app->ftr->tenantHas(\Feature::TENANT_2FA_ENFORCED);

        $trText = $this->app->trans->group('tabs_top_level');

        // list of all main tabs and its route.
        $this->landPgMap = [
            \Feature::DASHBOARD => ['name' => $trText['tab_Dashboard'], 'path' => '/tpm/dsh'],
            \Feature::THIRD_PARTIES => ['name' => $trText['tab_Third_Party_Management'],
                'path' => 'thirdparty/thirdparty_home.sec'],
            \Feature::CASE_MANAGEMENT => ['name' => $trText['tab_Case_Management'], 'path' => 'case/casehome.sec'],
            \Feature::ANALYTICS => ['name' => $trText['tab_Analytics'], 'path' => '/tpReport'],
            \Feature::SETTINGS => ['name' => $trText['tab_Settings'], 'path' => '/tpm/cfg'],
            \Feature::SUPPORT => ['name' => $trText['tab_Support'], 'path' => '/tpm/adm'],
            \Feature::TENANT_AI_INVESTIGATOR => ['name' => 'AI Investigator', 'path' => '/search'],
            \Feature::TENANT_WORKFLOW_OSP => ['name' => $trText['tab_Workflow'], 'path' => '/tpm/workflow']
        ];

        $this->hbiEnabled = filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN);
        $this->awsEnabled = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN);
    }


    /**
     * Initialize the page
     *
     * @return void
     */
    public function initialize()
    {
        $this->baseCtrl->setViewValue('userProfileCanAccess', false);
        if ($this->baseCtrl->readProp('canAccess') == true) {
            $this->baseCtrl->setViewValue('userProfileCanAccess', true);
        }

        $ulock = new UserLock($this->sess->get('authUserPwHash'), $this->sess->get('authUserID'));
        $this->baseCtrl->setViewValue('isDev', $ulock->hasAccess('IsDeveloper'));

        $getEdUsFo  = $this->getEditUserForm();
        $this->getPromptField($getEdUsFo);
        $landPgPref = $this->landPgPref($getEdUsFo);
        $eufrm_lock = Crypt64::randString(8);
        $langSelPref = ($this->langSel) ? $this->langSelPref() : 'false';

        $this->baseCtrl->setViewValue('pgTitle', 'User Profile');
        $this->baseCtrl->setViewValue('tenantID', $this->tenantID);
        $this->baseCtrl->setViewValue('jsRoute', $this->jsRoute);
        $this->baseCtrl->setViewValue('landPgOptions', $landPgPref);
        $this->baseCtrl->setViewValue('upTrans', $this->upTrans);
        $this->baseCtrl->setViewValue('userNotificationOptIn', $this->userNotificationOptIn);
        $this->baseCtrl->setViewValue('langSelOptions', $langSelPref);
        $this->baseCtrl->setViewValue('twoFactorAuth', ($this->tfaOptional || $this->tfaEnforced));
        $this->baseCtrl->setViewValue('eufrm_lock', $eufrm_lock);
        $this->sess->set('eufrm_lock', $eufrm_lock);

        $loginIDPend  = $this->User->getValuesById('userid, userName, accessAdvisory', $this->userID);
        $accessLevel = $this->User->getAccessLevel();
        if (empty($accessLevel)) {
            // integration tests don't populate secure session
            $accessLevel = $loginIDPend['accessAdvisory'];
        }
        $userTypePend = $this->accCls->userTypeName($accessLevel);

        if ($this->app->ftr->appIsTPM()) {
            // clientProfile.CPstatus - pending if they have not accepted the usage aggreement
            $this->baseCtrl->setViewValue('clientProfileStatus', $this->m->getClientProfileStatus());
            $anchor = '<a target="_blank" href="' . self::COMPANY_TERMS_PATH . '"><u>'
                . $this->upTrans['pmt_tenant_terms_of_usage'] . '</u></a>';
            $this->baseCtrl->setViewValue(
                'companyTermsLink',
                str_replace('{doc_link}', $anchor, (string) $this->upTrans['link_tenant_terms_of_usage'])
            );
        } else {
            $this->baseCtrl->setViewValue('clientProfileStatus', '');
            $this->baseCtrl->setViewValue('companyTermsLink', '');
        }

        $this->baseCtrl->setViewValue('loginIDPend', $loginIDPend);
        $this->baseCtrl->setViewValue('userTypePend', $userTypePend);

        $pendStatus = false;
        if ($this->sess->get('authUserStatus') == 'pending') {
            $pendStatus = true;
            $this->baseCtrl->setViewValue('passPmt', $this->prompts['pass']);
            $this->baseCtrl->setViewValue('passPmt2', $this->prompts['pass2']);
        } else {
            $this->baseCtrl->setViewValue('loginIDTxt', $loginIDPend['userid']);
            $this->baseCtrl->setViewValue('userTypeTxt', $userTypePend);
        }
        $this->baseCtrl->setViewValue('pendStatus', $pendStatus);
        $this->baseCtrl->setViewValue('prompts', $this->prompts);
        $this->baseCtrl->setViewValue('preform', $getEdUsFo);

        $this->baseCtrl->setViewValue('hbiEnabled', $this->hbiEnabled, true);
        $this->baseCtrl->setViewValue('awsEnabled', $this->awsEnabled, true);

        $this->baseCtrl->addFileDependency('/assets/jq/jqx/jqwidgets/jqxwindow.js');
        $this->app->view->display($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());
    }


    /**
     * Checks EditUserForm_Values vars
     *
     * @return array of values
     */
    protected function getEditUserForm()
    {
        $ret = [];
        $eufVals = $this->sess->get('edituserform_values');
        if (isset($eufVals)) {
            $keys = ['firstName', 'lastName', 'userEmail', 'userAddr1', 'userAddr2', 'userCity', 'userPC', 'userCountry', 'userState', 'userPhone', 'userMobile', 'userFax', 'landPgPref', 'userNote', 'userEmailConfirm', 'userPhoneCountry', 'userMobileCountry', 'twoFactorAuth', 'tfaDevice'];
            foreach ($eufVals as $k => $v) {
                if (!in_array($k, $keys)) {
                    continue;
                }
                $ret[$k . 'A'] = $v;
            }
            $this->sess->forget('edituserform_values');
        }

        $RetRow = $this->m->getUserData($this->userID);

        foreach ($RetRow as $k => $v) {
            $ret[$k . 'A'] = $v;
            $eufVals[$k] = $v;
        }

        $ret['userEmailConfirmA'] = $RetRow['userEmail'];
        $this->sess->set('edituserform_values', $eufVals);

        // Get User Notification Preferences
        if ($this->userNotificationOptIn) {
            $userNP = $this->User->getNotificationPreferences($this->userID);
            $vals = $userNP->getAttributes();
            unset($vals['id']);
            unset($vals['userID']);
            $ret = array_merge($ret, $vals);
        }

        return $ret;
    }


    /**
     * Get configuration for fields to be edited
     *
     * @param array $vals user data array
     *
     * @return void
     */
    protected function getPromptField($vals)
    {
        $this->prompts = [
            'pass'           => $this->upTrans['password'],
            'pass2'          => $this->upTrans['pmt_password_confirm'],
            'email'          => $this->upTrans['kp_lbl_email'],
            'email2'         => $this->upTrans['pmt_email_confirm'],
            'first'          => $this->upTrans['pmt_first_name'],
            'last'           => $this->upTrans['pmt_last_name'],
            'addr1'          => $this->upTrans['user_address1'],
            'addr2'          => $this->upTrans['user_address2'],
            'city'           => $this->upTrans['col_city'],
            'country'        => $this->upTrans['col_country'],
            'state'          => $this->upTrans['user_state'],
            'postCode'       => $this->upTrans['user_postal_code'],
            'phone'          => $this->upTrans['user_phone'],
            'twoFactorAuth'  => 'Two Factor Authentication',
            'tfaDevice'      => '2FA Device',
            'mobile'         => $this->upTrans['user_mobile'],
            'fax'            => $this->upTrans['user_fax'],
            'landingPage'    => $this->upTrans['user_landing_page_pref'],
            'note'           => $this->upTrans['user_note'],
            'langSelect'     => $this->upTrans['pmt_language'],
        ];
        $this->optField = [
            'state',
            'addr2',
            'fax',
            'mobile',
            'note',
            'landingPage',
            'langSelect',
            'userNotificationOptIn'
        ];

        if ($this->tfaOptional) {
            array_push($this->optField, 'twoFactorAuth', 'tfaDevice');
        }

        $this->optFieldNoCol = [
            'acceptedByInvestigator',
            'caseReassigned',
            'casePassedCaseFailed',
            'completedByInvestigator',
            'ddqSubmitted',
            'documentUploadedByInvestigator',
        ];
        if ($this->userNotificationOptIn) {
            // @TODO: add code keys
            $this->prompts['userNotificationOptIn'] = 'Notification Opt In';
            $this->prompts['acceptedByInvestigator'] = 'Accepted by investigator';
            $this->prompts['caseReassigned'] = 'Case Reassigned';
            $this->prompts['casePassedCaseFailed'] = 'Case Passed/Case Failed';
            $this->prompts['completedByInvestigator'] = 'Completed by Investigator';
            $this->prompts['ddqSubmitted'] = 'DDQ Submitted';
            $this->prompts['documentUploadedByInvestigator'] = 'Document Uploaded by Investigator';
        }
        if ($this->sess->get('authUserStatus') != 'pending') {
            $opt2 = [
                'addr1',
                'city',
                'country',
                'postCode',
                'phone',
            ];
            $this->optField = array_merge($this->optField, $opt2);
        }
        foreach ($this->prompts as $k => $v) {
            $this->prompts[$k] = $this->showPrompt($k);
        }
        $geography = Geography::getVersionInstance();
        $pCountry = $vals['userCountry'] ?? $vals['userCountryA'];
        $pPhoneCountry = (isset($vals['userPhoneCountry'])) ? $vals['userPhoneCountry'] : $vals['userPhoneCountryA'];
        $pMobileCountry = (isset($vals['userMobileCountry'])) ? $vals['userPhoneCountry'] : $vals['userMobileCountryA'];
        $pCountryText = $geography->getLegacyCountryName($pCountry);
        $pState = $vals['lb_userState'] ?? $vals['userStateA'];
        $pStateText = $geography->getLegacyStateName($pState, $pCountry);
        $this->baseCtrl->setViewValue(
            'defCountryOption',
            "<option value=\"$pCountry\" selected=\"selected\">$pCountryText</option>"
        );
        $this->baseCtrl->setViewValue(
            'defStateOption',
            "<option value=\"$pState\" selected=\"selected\">$pStateText</option>"
        );
        $this->baseCtrl->setViewValue('pState', $pState);
        $this->baseCtrl->setViewValue('pCountry', $pCountry);

        $this->baseCtrl->setViewValue(
            'CountryDD',
            $this->m->getCountriesList($this->sess->get('languageCode'), $pCountry)
        );
        $this->baseCtrl->setViewValue(
            'StateDD',
            $this->m->getStatesList($pCountry, $this->sess->get('languageCode'), $pState)
        );

        $this->baseCtrl->setViewValue('showCallingCode', false);
        $this->baseCtrl->setViewValue(
            'phoneCountryDD',
            $this->m->getCallingCodeCountriesList($pPhoneCountry)
        );

        $this->baseCtrl->setViewValue(
            'mobileCountryDD',
            $this->m->getCallingCodeCountriesList($pMobileCountry)
        );
    }


    /**
     * Build landing page preference dropdown
     *
     * @param array $vals array of user data
     *
     * @return string HTML options for use between select tags
     */
    protected function landPgPref($vals)
    {
        /*
         * Build Landing Page Preference dropdown list options.
         * Landing Page Preference ID Index (lives in code for now):
         *
         * #0: None
         * #3: Dashboard
         * #6: Third Party Management
         * #2: Case Management
         * #1: Analytics
         * #4: Settings
         * #5: Support
         * #7: AI Investigator
         */

        $landPgPref = (int)(isset($vals['landPgPref'])) ? $vals['landPgPref'] : $vals['landPgPrefA'];

        // Determine which landing pages the user will have access to
        $userLandPgs = [];
        foreach ($this->landPgMap as $featureID => $value) {
            if ($this->app->ftr->has($featureID)) {
                $userLandPgs[] = $featureID;
            }
        }

        $landPgOptions = '<option value="0">' . $this->app->trans->codeKey('app_default') . '</option>';
        foreach ($this->landPgMap as $featureID => $value) {
            if (in_array($featureID, $userLandPgs)) {
                $landPgOptions .= "<option value=\"$featureID\"";
                if ($landPgPref == $featureID) {
                    $landPgOptions .= ' selected="selected"';
                }
                $landPgOptions .= '>' . $this->landPgMap[$featureID]['name'] . '</option>';
            }
        }
        return $landPgOptions;
    }

    /**
     * Build language preference dropdown
     *
     * @return string HTML options for use between select tags
     */
    protected function langSelPref()
    {
        /*
         * Build Language Preference dropdown list options.
         */

        $langSelPref = $this->m->getlangOptions();

        $langSelOptions = '';
        foreach ($langSelPref as $item) {
            $langSelOptions .= "<option value=\"$item->v\"";
            if ($item->v == $this->sess->get('languageCode')) {
                $langSelOptions .= ' selected="selected"';
            }
            $langSelOptions .= ' dir="' . ((empty($item->d)) ? 'ltr' : 'rtl') . '">'
                . $item->t . '</option>';
        }
        return $langSelOptions;
    }


    /**
     * Given a landing page key, determine if the user/subscriber settings permit the use of a landing page.
     * If the landing page is valid and has the necessary permissions, then pass back any array of info.
     *
     * @param int $featureID Landing Page Key - associated with g_features.id
     *
     * @return array Contains name and path items if validated, otherwise empty array.
     */
    public function validateLandPg($featureID)
    {
        if (!$this->app->ftr->has($featureID)) {
            return [];
        }
        if (!array_key_exists($featureID, $this->landPgMap)) {
            return []; // Bad key, exit early.
        }
        return $this->landPgMap[$featureID];
    }

    /**
     * Update the password in the DB and log the change
     *
     * @param string $newHash New password hash
     * @param string $oldHash Old password hash
     *
     * @return array Contains error messages
     */
    private function updatePassword($newHash, $oldHash)
    {
        // update database historical password records for this user with 'old password'
        $this->m->moveCurrentPwToHistorical($this->userID, $oldHash);

        // update database user record with new data
        $this->m->updatePw($this->userID, $newHash);

        // update session var with new pass hash
        $this->sess->set('authUserPwHash', $newHash);
        $this->sess->set('authUserStatus', 'active');
        $this->sess->set('status', 'active');

        // Make a log entry
        $this->LogData->saveLogEntry(19, '');
    }

    /**
     * Return the formatted prompt based on field
     *
     * @param string $pmtKey Prompt Key
     *
     * @return string HTML formatted prompt value
     */
    protected function showPrompt($pmtKey)
    {
        if (array_key_exists($pmtKey, $this->prompts)) {
            $pmt = $this->prompts[$pmtKey];
            if (in_array($pmtKey, $this->optField)) {
                $rtn = $pmt . ':';
            } elseif (in_array($pmtKey, $this->optFieldNoCol)) {
                $rtn = $pmt;
            } else {
                $rtn = '<span class="style3">* ' . $pmt . ':</span>';
            }
        } else {
            $rtn = '(undefined prompt)';
        }
        return $rtn;
    }


    /**
     * Ajax handler for generating a new password string
     *
     * @return void
     */
    private function ajaxGenPass()
    {
        if (filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN)
            && filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN)
        ) {
            header("HTTP/1.0 404 Not Found");
            exit;
        }
        $tmpPass['tmpPass'] = $this->User->tempPassword();
        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.userprofile.handleGenPass';
        $this->jsObj->Args = [
            $tmpPass['tmpPass'],
            $this->upTrans['new_pw_title'],
            $this->upTrans['new_pw_before'],
            $this->upTrans['new_pw_after'],
            $this->jsObj->ErrBtnLabel,
        ];
    }


    /**
     * Ajax handler for changing passwords
     *
     * @return void
     */
    private function ajaxPassChange()
    {
        if (filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN)
            && filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN)
        ) {
            header("HTTP/1.0 404 Not Found");
            exit();
        }
        $old  = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'oldPw', ''));
        $new  = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'newPw', ''));
        $new2 = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'new2Pw', ''));
        $loginID = $this->sess->get('authUserLoginID');

        // retrieve md5 hash of the passwords
        // new, old (form), existing (db)
        $newHash = MasterLogin::hashEncrypt($new, $loginID);
        $oldHash = MasterLogin::hashEncrypt($old, $loginID);
        $authHash = $this->sess->get('authUserPwHash');
        $oldPassIsValid = MasterLogin::validateUserPassword($authHash, $oldHash, $old);
        $newPassDiffFromOld = !MasterLogin::validateUserPassword($authHash, $newHash, $new);
        $valCfg = [
            $this->upTrans['user_change_pw_current'] => [
                [$old, 'Rules', 'required', 'oldPw'],
                [$oldPassIsValid, 'Generic', 'error_incorrect_pw_status'],
            ],
            $this->upTrans['new_pw'] => [
                [$new, 'Rules', 'required|password', 'newPw'],
                [$new, 'NoDupPassword', $this->userID],
                [$newPassDiffFromOld, 'Generic', 'invalid_disallowCurPassword'],
            ],
            $this->upTrans['new_pw2'] => [
                [
                    $new2, 'ConfirmSame',
                    ['firstField' => $this->upTrans['new_pw'], 'firstValue' => $new]
                ],
            ],
        ];

        // Validate inputs
        $tests = new \Lib\Validation\Validate($valCfg);
        if ($tests->failed) {
            $this->jsObj->ErrTitle = $tests->getErrTitle();
            $this->jsObj->ErrMsg = $tests->formatErrMsg();
            return;
        }
        $this->updatePassword($newHash, $oldHash);
        $msg = $this->upTrans['user_update_success_msg'];
        $msgTitle = $this->upTrans['user_update_success_title'];
        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.userprofile.handlePassChange';
        $this->jsObj->Args = [$msgTitle, $msg, $this->jsObj->ErrBtnLabel];
    }


    /**
     * Ajax handler for updating the user profile
     *
     * @return void
     */
    private function ajaxUpdateUserProfile()
    {
        $validateFuncs = new ValidateFuncs();
        $user = (new User())->findById($this->userID);
        $eufVals = [];
        $valCfg = []; // validation config
        $userCurrentEmail = $user->get('userEmail');

        $flagSet = $this->awsEnabled && $this->hbiEnabled;
        $twoFactorAuth = 0;

        // These values must NOT come from front-end!
        $isPending = ($user->get('status') == 'pending');
        $clientProfilePending = ($this->m->getClientProfileStatus() == 'pending');

        $hbiEnabled = filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN);
        $awsEnabled = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN);

        if ($isPending) {
            // Password
            $userpw = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userpw', ''));
            $userpwConfirm = trim(\Xtra::arrayGet($this->app->clean_POST, 'tf_userpwConfirm', ''));
            $userEmail = trim(\Xtra::arrayGet($this->app->clean_POST, 'tf_userEmail', ''));
            $newPassHash = MasterLogin::hashEncrypt($userpw, $userEmail);
            $oldPassHash = $this->sess->get('authUserPwHash');

            $passwordsNotMatch = MasterLogin::passwordEncryptionType(
                Argon2Encrypt::CURRENT_ENCRYPTION_ID
            ) === 'none' ? $newPassHash !== $oldPassHash : !password_verify($userpw, (string) $oldPassHash);

            if (!($hbiEnabled && $awsEnabled)) {
                $valCfg[$this->upTrans['password']] = [
                    [$userpw, 'Rules', 'required|password', 'tf_userpw'],
                    [$userpw, 'NoDupPassword', $this->userID],
                    [$passwordsNotMatch, 'Generic', 'invalid_disallowCurPassword'],
                ];
            } else {
                $valCfg[$this->upTrans['password']] = [
                    [$userpw, 'Rules', 'password', 'tf_userpw'],
                    [$userpw, 'NoDupPassword', $this->userID],
                    [$passwordsNotMatch, 'Generic', 'invalid_disallowCurPassword'],
                ];
            }
            $valCfg[$this->upTrans['pmt_password_confirm']] = [
                [
                    $userpwConfirm, 'ConfirmSame',
                    ['firstField' => $this->upTrans['password'], 'firstValue' => $userpw]
                ],
            ];
        } else {
            // Email
            $userEmail = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userEmail', ''));
            if (!$flagSet) {
                $userEmailConfirm = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userEmailConfirm', ''));
                $valCfg[$this->upTrans['kp_lbl_email']] = [
                    [$userEmail, 'Rules', 'required|valid_email|max_len,50', 'tf_userEmail']
                ];
                $valCfg[$this->upTrans['pmt_email_confirm']] = [
                    [$userEmailConfirm, 'ConfirmSame',
                        ['firstField' => $this->upTrans['kp_lbl_email'], 'firstValue' => $userEmail]
                    ],
                ];
            }
        }


        // First Name
        $firstName = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_firstName', ''));
        $rules = ((!$flagSet) ? 'required|' : '') . 'max_len,125';
        $valCfg[$this->upTrans['pmt_first_name']] = [[$firstName, 'Rules', $rules, 'tf_firstName']];
        if (!empty($firstName) && !$validateFuncs->checkInputSafety($firstName)) {
            $this->jsObj->ErrTitle = 'Invalid First Name';
            $this->jsObj->ErrMsg = 'First Name contains invalid characters like html tags, script tags, etc.';
            return;
        }

        // Last Name
        $lastName = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_lastName', ''));
        $valCfg[$this->upTrans['pmt_last_name']] = [[$lastName, 'Rules', $rules, 'tf_lastName']];
        if (!empty($lastName) && !$validateFuncs->checkInputSafety($lastName)) {
            $this->jsObj->ErrTitle = 'Invalid First Name';
            $this->jsObj->ErrMsg = 'Last Name contains invalid characters like html tags, script tags, etc.';
            return;
        }
        
        if (!$flagSet) {
            // Address 1
            $userAddr1 = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userAddr1', ''));
            $rules = (($isPending) ? 'required|' : '') . 'max_len,255';
            $valCfg[$this->upTrans['user_address1']] = [[$userAddr1, 'Rules', $rules, 'tf_userAddr1']];
            if (!empty($userAddr1) && !$validateFuncs->checkInputSafety($userAddr1)) {
                $this->jsObj->ErrTitle = 'Invalid Address';
                $this->jsObj->ErrMsg = 'Address1 contains invalid characters like html tags, script tags, etc.';
                return;
            }

            // Address 2
            $userAddr2 = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userAddr2', ''));
            $valCfg[$this->upTrans['user_address2']] = [[$userAddr2, 'Rules', 'max_len,255', 'tf_userAddr2']];
            if (!empty($userAddr2) && !$validateFuncs->checkInputSafety($userAddr2)) {
                $this->jsObj->ErrTitle = 'Invalid Address';
                $this->jsObj->ErrMsg = 'Address2 contains invalid characters like html tags, script tags, etc.';
                return;
            }

            // City
            $userCity = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userCity', ''));
            $rules = (($isPending) ? 'required|' : '') . 'max_len,255';
            $valCfg[$this->upTrans['col_city']] = [[$userCity, 'Rules', $rules, 'tf_userCity']];
            if (!empty($userCity) && !$validateFuncs->checkInputSafety($userCity)) {
                $this->jsObj->ErrTitle = 'Invalid City';
                $this->jsObj->ErrMsg = 'City contains invalid characters like html tags, script tags, etc.';
                return;
            }

            // Country
            $userCountry = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'userCountry', ''));
            $rules = (($isPending) ? 'required|' : '') . 'max_len,2';
            $valCfg[$this->upTrans['col_country']] = [[$userCountry, 'Rules', $rules, 'userCountry']];

            // State
            $userState = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'lb_userState', ''));
            $valCfg[$this->upTrans['user_state']] = [[$userState, 'Rules', 'max_len,50', 'lb_userState']];

            // Postal Code
            $userPC = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userPC', ''));
            $rules = (($isPending) ? 'required|' : '') . 'max_len,50';
            $valCfg[$this->upTrans['user_postal_code']] = [[$userPC, 'Rules', $rules, 'tf_userPC']];
            if (!empty($userPC) && !$validateFuncs->checkInputSafety($userPC)) {
                $this->jsObj->ErrTitle = 'Invalid Postal Code';
                $this->jsObj->ErrMsg = 'Postal Code contains invalid characters like html tags, script tags, etc.';
                return;
            }

            // Two Factor Authentication
            $twoFactorAuth = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'twoFactorAuth', ''));
            $rules = (($this->tfaEnforced) ? "required|" : '') . 'max_len,1';
            $valCfg['Two Factor Authentication'] = [[$twoFactorAuth, 'Rules', $rules, 'twoFactorAuth']];

            // 2FA Device
            $tfaDevice = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tfaDevice', ''));
            $rules = (($twoFactorAuth) ? 'required|' : '') . 'max_len,255';
            $valCfg['2FA Device'] = [[$tfaDevice, 'Rules', $rules, 'tfaDevice']];

            // Phone Country
            $userPhoneCountry = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'userPhoneCountry', ''));
            $valCfg['Phone Country'] = [[$userPhoneCountry, 'Rules', 'max_len,2', 'userPhoneCountry']];

            // Phone
            $userPhone = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userPhone', ''));
            $rules = (($isPending || ($twoFactorAuth && $tfaDevice == 'userPhone')) ? 'required|' : '') .
                (($twoFactorAuth && $tfaDevice == 'userPhone') ? "valid_phone,{$userPhoneCountry}|" : '') .
                'max_len,50';
            $valCfg[$this->upTrans['user_phone']] = [[$userPhone, 'Rules', $rules, 'tf_userPhone']];
            if (!empty($userPhone) && !$validateFuncs->isValidPhone($userPhone)) {
                $this->jsObj->ErrTitle = 'Invalid Phone';
                $this->jsObj->ErrMsg = 'Invalid characters in Phone. It should be numeric, spaces, dashes, underscores,+,(,).';
                return;
            }

            // Mobile Phone Country
            $userMobileCountry = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'userMobileCountry', ''));
            $valCfg['Cellular/Mobile Country'] = [[$userMobileCountry, 'Rules', 'max_len,2', 'userMobileCountry']];

            // Mobile Phone
            $userMobile = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userMobile', ''));
            $rules = (($twoFactorAuth && $tfaDevice == 'userMobile') ?
                "required|valid_phone,{$userMobileCountry}|" : '') .
                "max_len,50";
            $valCfg[$this->upTrans['user_mobile']] = [[$userMobile, 'Rules', $rules, 'tf_userMobile']];
            if (!empty($userMobile) && !$validateFuncs->isValidPhone($userMobile)) {
                $this->jsObj->ErrTitle = 'Invalid Mobile Phone';
                $this->jsObj->ErrMsg = 'Invalid characters in Mobile Phone. It should be numeric, spaces, dashes, underscores,+,(,).';
                return;
            }

            // Fax
            $userFax = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'tf_userFax', ''));
            $valCfg[$this->upTrans['user_fax']] = [[$userFax, 'Rules', 'max_len,50', 'tf_userFax']];
            if (!empty($userFax) && !$validateFuncs->isValidFax($userFax)) {
                $this->jsObj->ErrTitle = 'Invalid Fax';
                $this->jsObj->ErrMsg = 'Invalid characters in Fax. It should be numeric, spaces, dashes, underscores,+,(,).';
                return;
            }

            // Language Pref
            $langPref = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'langPref', 'EN_US'));
            $valCfg['pmt_language'] = [[$langPref, 'Rules', 'max_len,5', 'langPref']];
        }
        // Landing Page
        $landPgPref = (int)\Xtra::arrayGet($this->app->clean_POST, 'landPgPref', '');
        $valCfg[$this->upTrans['user_landing_page_pref']] = [[$landPgPref, 'Rules', 'max_len,4']];

        // Notes
        $userNote = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'userNote', ''));
        $valCfg[$this->upTrans['user_note']] = [[$userNote, 'Rules', 'max_len,4000', 'userNote']];
        if (!empty($userNote) && !$validateFuncs->checkInputSafety($userNote)) {
            $this->jsObj->ErrTitle = 'Invalid Note';
            $this->jsObj->ErrMsg = 'Note contains invalid characters like html tags, script tags, etc.';
            return;
        }

        // User Notification Opt In
        if ($this->userNotificationOptIn) {
            $userNotificationPreferences = $user->getNotificationPreferences();
            $userNotificationPreferences->set('userID', $this->userID);

            foreach ($userNotificationPreferences->optNotifications as $option) {
                ${$option} = trim((string) \Xtra::arrayGet($this->app->clean_POST, $option, 0));

                $valCfg[$this->camelCaseToSnakeCase($option)] = [
                    [${$option}, 'Rules', 'db_boolean', $option]
                ];
            }

            foreach ($userNotificationPreferences->optNotifications as $option) {
                $userNotificationPreferences->set($option, ${$option});
            }

            $uNPCLog = new UserNotificationPreferencesChangeLogging(
                $this->app->session->get('authUserID'),
                $this->app->session->get('authUserType')
            );
            $userNotificationPreferences->attach($uNPCLog);
            $userNotificationPreferences->save();
        }

        // Usage Terms (clientProfile)
        if ($clientProfilePending) {
            $coAgree  = \Xtra::arrayGet($this->app->clean_POST, 'cb_companyAgreement', '');
            $valCfg[$this->upTrans['pmt_tenant_terms_of_usage']] = [[$coAgree, 'Rules', 'required']];
        }

        // Validate inputs
        $tests = new \Lib\Validation\Validate($valCfg);
        if ($tests->failed) {
            $this->jsObj->ErrTitle = $tests->getErrTitle();
            $this->jsObj->ErrMsg = $tests->formatErrMsg();
            return;
        }

        // Validation passed. Update records
        if ($clientProfilePending) {
            if (!$this->m->activateClientProfile()) {
                $this->jsObj->ErrTitle = $this->app->trans->codeKey('title_operation_failed');
                $this->jsObj->ErrMsg = $this->app->trans->codeKey('upd_failed');
                return;
            }
        }

        if ($twoFactorAuth) {
            $tfaPhone = ($tfaDevice == 'userPhone') ? $userPhone : $userMobile;
            $tfaCountryISO = ($tfaDevice == 'userPhone') ? $userPhoneCountry : $userMobileCountry;
            $authyID = $this->m->createOrRestoreAuthyUser($user, $tfaPhone, $tfaCountryISO);
            $user->set('authyID', $authyID);
        }

        $user->set('userNote', $userNote);
        $eufVals['userNote'] = $userNote;

        $user->set('landPgPref', $landPgPref);
        $eufVals['landPgPref'] = $landPgPref;
        if (!$flagSet) {
            $user->set('firstName', $firstName);
            $eufVals['firstName'] = $firstName;
            $this->sess->set('user.firstName', $firstName);

            $user->set('lastName', $lastName);
            $eufVals['lastName'] = $lastName;
            $this->sess->set('user.lastName', $lastName);

            $user->set('userName', $firstName . ' ' . $lastName);
            $this->sess->set('user.userName', $firstName . ' ' . $lastName);

            $user->set('userAddr1', $userAddr1);
            $eufVals['userAddr1'] = $userAddr1;

            $user->set('userAddr2', $userAddr2);
            $eufVals['userAddr2'] = $userAddr2;

            $user->set('userCity', $userCity);
            $eufVals['userCity'] = $userCity;

            $user->set('userCountry', $userCountry);
            $eufVals['userCountry'] = $userCountry;

            $user->set('userState', $userState);
            $eufVals['userState'] = $userState;

            $user->set('userPC', $userPC);
            $eufVals['userPC'] = $userPC;

            $user->set('userPhoneCountry', $userPhoneCountry);
            $eufVals['userPhoneCountry'] = $userPhoneCountry;

            $user->set('userPhone', $userPhone);
            $eufVals['userPhone'] = $userPhone;

            $user->set('userMobileCountry', $userMobileCountry);
            $eufVals['userMobileCountry'] = $userMobileCountry;

            $user->set('userMobile', $userMobile);
            $eufVals['userMobile'] = $userMobile;

            $user->set('userFax', $userFax);
            $eufVals['userFax'] = $userFax;

            $user->set('langPref', $langPref);
            $eufVals['langPref'] = $langPref;
            $this->sess->set('languageCode', $langPref);

            $user->set('twoFactorAuth', $twoFactorAuth);
            $eufVals['twoFactorAuth'] = $twoFactorAuth;

            $user->set('tfaDevice', $tfaDevice);
            $eufVals['tfaDevice'] = $tfaDevice;
        }

        if ($isPending) {
            if (!($hbiEnabled && $awsEnabled)) {
                $this->updatePassword($newPassHash, $oldPassHash);
                $user->set('userpw', $newPassHash);
            }
            $user->set('status', 'active');
            $user->set('aclReset', '1');
            $user->set('pwSetDate', date('Y-m-d'));
        } else {
            if (!$flagSet) {
                $eufVals['userEmail'] = $userEmail;
                $user->set('userEmail', $userEmail);
            }
        }

        $this->sess->set('edituserform_values', $eufVals);
        $ucLog = new UserChangeLogging(
            $this->app->session->get('authUserID'),
            $this->app->session->get('authUserType')
        );
        $user->attach($ucLog); // attach right before saving, might be issues if done before

        if ($user->save()) {
            if ($isPending) {
                $this->jsObj->Redirect = '/logout/' . $this->app->session->get('authUserID') . '/true';
                return;
            }
            if ($userCurrentEmail !== $userEmail) {
                $this->jsObj->Result = 1;
                $msg = $this->upTrans['user_update_success_msg'];
                $msgTitle = $this->upTrans['user_update_success_title'];
                $this->jsObj->FuncName = 'appNS.userprofile.handleUpdateUserProfile';
                $this->jsObj->Args = [$msgTitle, $msg, $this->jsObj->ErrBtnLabel];
                $this->jsObj->Redirect = '/logout/' . $this->app->session->get('authUserID') . '/true';
                return;
            }
            $this->jsObj->Result = 1;
            $msg = $this->upTrans['user_update_success_msg'];
            $msgTitle = $this->upTrans['user_update_success_title'];
            $this->jsObj->FuncName = 'appNS.userprofile.handleUpdateUserProfile';
            $this->jsObj->Args = [$msgTitle, $msg, $this->jsObj->ErrBtnLabel];
        } else {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('title_operation_failed');
            $this->jsObj->ErrMsg = $this->app->trans->codeKey('upd_failed');
        }
    }

    /**
     * Ajax handler for updating the region dropdown when new Country is selected
     *
     * @return void
     */
    private function ajaxUpdateRegionDD()
    {
        $countryISO  = \Xtra::arrayGet($this->app->clean_POST, 'countryISO', '');
        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.userprofile.handleUpdateRegionDD';
        $this->jsObj->Args = [$this->m->getStatesList($countryISO, $this->sess->get('languageCode'))];
    }

    /**
     * Toggle trans->eveal
     *
     * @return void
     */
    private function ajaxToggleTransReveal()
    {
        $ulock = new UserLock($this->sess->get('authUserPwHash'), $this->sess->get('authUserID'));
        if ($ulock->hasAccess('IsDeveloper')) {
            if (!$this->sess->get('transReveal')) {
                $v = '⚑';
                $notice = 'Translation reveal is ON';
                $tpl = 'success';
            } else {
                $v = '';
                $notice = 'Translation reveal is OFF';
                $tpl = 'info';
            }
            $this->sess->set('transReveal', $v);
            $this->jsObj->AppNotice = [$notice, ['template' => $tpl]];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Convert a string from camel case to snake case
     *
     * @param string $str camel case string to be converted to snake case
     *
     * @return string
     */
    private function camelCaseToSnakeCase($str)
    {
        $str = str_split($str);
        foreach ($str as &$char) {
            if (ctype_upper($char)) {
                $char = "_" . strtolower($char);
            }
        }
        return implode('', $str);
    }

    /**
     * Handles "Send Test SMS" Button for 2FA
     *
     * @return void
     */
    private function ajaxHandleTest2FAButtonClick()
    {
        if (!($this->tfaOptional || $this->tfaEnforced)) {
            return;
        }

        $user = (new User())->findById($this->userID);

        // Phone Country
        $countryISO = \Xtra::arrayGet($this->app->clean_POST, 'countryISO', '');
        $valCfg['Phone Country'] = [[$countryISO, 'Rules', 'max_len,2']];

        // Phone
        $phoneNumber = \Xtra::arrayGet($this->app->clean_POST, 'phoneNumber', '');
        $rules = "required|valid_phone,{$countryISO}|max_len,50";
        $valCfg['Phone Number'] = [[$phoneNumber, 'Rules', $rules]];

        // Validate inputs
        $tests = new \Lib\Validation\Validate($valCfg);
        if ($tests->failed) {
            $this->jsObj->ErrTitle = $tests->getErrTitle();
            $this->jsObj->ErrMsg = $tests->formatErrMsg();
            return;
        }
        $authyID = $this->m->createOrRestoreAuthyUser($user, $phoneNumber, $countryISO);

        if ($this->m->authenticator->sendSMSCode($authyID)) {
            $this->jsObj->Result = 1; // success
            $this->jsObj->FuncName = 'appNS.userprofile.test2FAButtonClickSuccess';
        } else {
            $this->jsObj->ErrTitle = "Two Factor Authentication Error";
            $this->jsObj->ErrMsg = "There was an error with two factor authentication.";
        }
    }

    /**
     * Confirm the user's password
     *
     * @return void
     */
    public function confirmUserPassword(): void
    {
        try {
            $user = (new User())->findById($this->userID);
            if (!$user) {
                throw new \Exception('User not found');
            }

            $currentEmail = $user->get('userEmail');
            $password = \Xtra::arrayGet($this->app->clean_POST, 'userPassword', '');
            $passwordHash = MasterLogin::hashEncrypt($password, $currentEmail);
            $userPasswordHash = $user->get('userpw');

            $isValid = ($passwordHash === $userPasswordHash);

            echo json_encode(['isValid' => $isValid]);
        } catch (\Exception $e) {
            echo json_encode(['isValid' => false, 'error' => $e->getMessage()]);
        }
    }
}
