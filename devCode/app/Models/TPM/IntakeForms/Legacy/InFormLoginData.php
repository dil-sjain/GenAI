<?php
/**
 * Model: Intake Form login
 *
 * @keywords intake form, login, list, ddq
 */

namespace Models\TPM\IntakeForms\Legacy;

use Lib\LoginAuth;
use Models\Globals\Features\TenantFeatures;
use Models\Globals\Geography;
use Models\Globals\Languages;
use Models\Ddq;
use Models\TPM\IntakeForms\DdqBL;
use Models\ThirdPartyManagement\Subscriber;
use Models\TPM\IntakeForms\DdqName;
use Lib\Crypt\Argon2Encrypt;

/**
 * Provides data access for Intake Form Login
 */
#[\AllowDynamicProperties]
class InFormLoginData
{
    /**
     * @var \Skinny\Skinny|null Class instance
     */
    protected $app = null;

    /**
     * @var mixed|null app->DB instance
     */
    protected $DB = null;

    /**
     * @var int 3PM tenant ID
     */
    protected $tenantID = 0;

    /**
     * @var int|mixed ddq.caseType
     */
    protected $inFormType = 0;

    /**
     * @var string 'invitation' or 'open_url'
     */
    protected $origin = '';

    /**
     * @var string Ddq intake form version
     */
    protected $inFormVersion = '';

    /**
     * @var string XX_XX language code
     */
    protected $languageCode = '';

    /**
     * @var Ddq|null Class instance
     */
    protected $ddq = null;

    /**
     * @var OnlineQuestions|null Class instance
     */
    protected $OQ = null;

    /**
     * @var mixed|null Instance of app->log
     */
    protected $logger = null;

    /**
     * @var LoginAuth|null Instance of LoginAuth
     */
    protected $loginAuth = null;

    /**
     * @var int Timeframe for validity of the DDQ OneTime Access link
     *          Note: Default time is 10 years as per ticket: TPM-840
     */
    private $ddqAccessLinkTimeLimit = 10;    // Time in years

    /**
     * @var array Map of questionIDs to extract from onlineQuestion records for a given pageTab value
     * This array will need to grow with refactoring.
     */
    protected $extractableQIDs = [
            'ddqLogin' => [
                'submitButton' => 'TEXT_SUBMIT_LOGIN',
                'newLoginCreds' => 'TEXT_CREATENEWQ_LOGIN',
                'displayLanguage' => 'TEXT_DISPLANG_LOGIN',
                'editSavedIntakeForm' => 'TEXT_EDITQ_LOGIN',
                'emailAddress' => 'TEXT_EMAILADDR_LOGIN',
                'confirmEmail' => 'TEXT_CONFIRMEMAIL_LOGIN',
                'password' => 'TEXT_PASSWORD_LOGIN',
                'confirmPassword' => 'TEXT_CONFIRMPW_LOGIN',
                'HCPselect' => [
                    'titleNonHCP' => 'TITLE_DDQ_NON_HCP',
                    'titleHCP' => 'TITLE_DDQ_HCP',
                    'descripNonHCP' => 'TEXT_NON_HCP_DESC',
                    'descripHCP' => 'TEXT_HCP_DESC',

                ],
            ],
        ];

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID clientProfile.id
     * @param array   $params   configuration
     *
     * @return void
     */
    public function __construct($tenantID, $params = [])
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        $this->tenantID = (int)$tenantID;
        $this->DB->setClientDB($this->tenantID);
        $this->loginAuth = new LoginAuth('intakeForm');
        $this->languageCode = 'EN_US';

        if (!empty($params)) {
            if (!empty($params['inFormType'])) {
                $this->inFormType = $params['inFormType'];
            }
            if (!empty($params['origin'])) {
                $this->origin = $params['origin'];
            }
            if (!empty($params['inFormVersion'])) {
                $this->inFormVersion = $params['inFormVersion'];
            }
            if (!empty($params['languageCode'])) {
                $this->languageCode = $params['languageCode'];
            }
        }
        $this->logger = $this->app->log;
        if ($this->tenantID > 0) {
            $this->ddq = new Ddq($this->tenantID);
            $this->OQ = new OnlineQuestions($this->tenantID);
        }
    }




    /**
     * New login credentials supplied. Create if none exist, else deal with them accordingly....
     *
     * @param string  $loginID       ddq.loginEmail
     * @param string  $pw            ddq.password
     * @param string  $formClass     ddq.formClass
     * @param integer $inFormType    ddq.caseType
     * @param integer $inFormVersion ddq.inFormVersion
     *
     * @return array success and data items
     *
     * @throws \Exception
     */
    public function createIntakeForm($loginID, $pw, $formClass, $inFormType, $inFormVersion)
    {
        if (empty($loginID) || empty($pw) || empty($formClass) || empty($inFormType)) {
            throw new \Exception($this->app->trans->codeKey('login_failed'));
        }
        $sql = "SELECT COUNT(*) FROM ddq\n"
            . "WHERE loginEmail = :loginEmail AND passWord <> :passWord AND clientID = :tenantID";
        $params = [':loginEmail' => $loginID, ':passWord' => $pw, ':tenantID' => $this->tenantID];
        if ($foundUserOnly = $this->DB->fetchValue($sql, $params)) {
            // treat as failed login attempt
            $previousLoginData = $this->loginAuth->isPreviousLoginLocked($loginID, md5($pw));
            $previousLoginData['tenantID'] = $this->tenantID;
            return [
                'success' => false,
                'data' => $this->loginAuth->loginFailed(
                    $loginID,
                    $previousLoginData
                )
            ];
        } else {
            // Try to log this sucker in if existing intake form creds, else create a new intake form
            return $this->login($loginID, $pw, $formClass, $inFormType, $inFormVersion, true);
        }
    }




    /**
     * Get available languages for legacy intake form login
     *
     * @return array languageCode-languageName key/value pairs
     */
    public function getAvailableLanguages()
    {
        $languages = [];
        if ($this->tenantID <= 0 || $this->inFormType <= 0) {
            return $languages;
        }

        // Get Languages for relevant intake form questions
        $sql = "SELECT DISTINCT languageCode FROM onlineQuestions\n"
            . "WHERE clientID = :tenantID AND caseType = :inFormType ORDER BY languageCode";
        $languageCodes = $this->DB->fetchValueArray(
            $sql,
            [':tenantID' => $this->tenantID, ':inFormType' => $this->inFormType]
        );
        if (empty($languageCodes)) {
            // Lets go after the default values
            $languageCodes = $this->DB->fetchValueArray(
                $sql,
                [':tenantID' => 0, ':inFormType' => $this->inFormType]
            );
        }
        if (!empty($languageCodes)) {
            $languageCodes = "'" . implode("', '", $languageCodes) . "'";
            $sql = "SELECT langCode, langNameNative AS langName, \n"
                . "CASE WHEN rtl = 0 THEN 'ltr' ELSE 'rtl' END AS dir\n"
                . "FROM " . $this->DB->globalDB . ".g_languages\n"
                . "WHERE langCode IN ($languageCodes) ORDER BY langName";
            $languages = $this->DB->fetchAssocRows($sql, []);
        }
        return $languages;
    }




    /**
     * Map text values to nav buttons, tabs, and yes/no tags
     *
     * @return array base text broken up by groups
     */
    public function getBaseText()
    {
        $baseText = [];
        if ($this->tenantID <= 0 || $this->inFormType <= 0) {
            return $baseText;
        }
        $map = [
            'CompDetail' => 'TEXT_COMPDETAIL_TAB',
            'Personnel'  => 'TEXT_PERSONNEL_TAB',
            'BusPract'   => 'TEXT_BUSPRACT_TAB',
            'Relation'   => 'TEXT_RELATION_TAB',
            'ProfInfo'   => 'TEXT_PROFINFO_TAB',
            // hcp
            'AddInfo'    => 'TEXT_ADDINFO_TAB',
            'Auth'         => 'TEXT_AUTH_TAB',
            'YesTag' => 'TEXT_YES_TAG',
            'NoTag'  => 'TEXT_NO_TAG',
            'Submit' => 'TEXT_SUBMIT_LOGIN',
            'GoBack' => 'TEXT_GOBACK_NAVBUTTON',
            'Cancel' => 'TEXT_CANCEL_NAVBUTTON',
            'Save'   => 'TEXT_SAVE_NAVBUTTON',
            'PrintQ' => 'TEXT_PRINTQ_NAVBUTTON',
            'Delete' => 'TEXT_DELETE_NAVBUTTON',
            'Continue'   => 'TEXT_CONTINUE_NAVBUTTON',
            'SaveClose'  => 'TEXT_SAVECLOSE_NAVBUTTON',
            'SaveNoSub'  => 'TEXT_SAVENOSUB_NAVBUTTON',
            'szDDQtitle' => 'TEXT_DDQ_PAGEHEAD',
            'szAllRequiredTitle' => 'TEXT_ALLREQUIRED_PAGEHEAD',
        ];
        if (empty($this->languageCode)) {
            $this->setLanguageCode();
        }
        foreach ($map as $key => $quesId) {
            $value = $grp = '';
            $row = $this->OQ->getOQrec(
                $this->languageCode,
                $this->inFormType,
                $this->inFormVersion,
                $quesId
            );
            if ($row) {
                $value = $row['labelText'];
            }
            if (preg_match('/_(TAB|NAVBUTTON|LOGIN)$/', $quesId, $match)) {
                $grp = $match[1];
            }
            switch ($grp) {
                case 'TAB':
                    $baseText['Tabs'][$key] = $value;
                    break;
                case 'LOGIN':
                case 'NAVBUTTON':
                    $baseText['NavButton'][$key] = $value;
                    break;
                default: // tag and paghead
                    $baseText[$key] = $value;
            }
        }
        return $baseText;
    }



    /**
     * Compiles intake form assets needed for legacy intake forms
     *
     * @param array  $intakeForms intake forms
     * @param string $langCode    language code
     *
     * @return array $intakeForms intake form objects with additional properties added
     */
    public function getIntakeFormAssets($intakeForms, $langCode)
    {
        $names = (new DdqName($this->tenantID))->getTenantIntakeFormNames();
        $geography = Geography::getVersionInstance(null, $this->tenantID);
        $translatedCountries = $geography->countryList('', $langCode);
        foreach ($intakeForms as $inForm) {
            // First, tack on intake form names.
            $inForm->intakeFormName = $inForm->legacyID;
            foreach ($names as $name) {
                if ($name['legacyID'] == $inForm->legacyID && $name['formClass'] == $inForm->formClass) {
                    $inForm->intakeFormName = $name['name'];
                }
            }

            // Tack on the translated state name
            $langCode = $this->app->session->languageCode ?? 'EN_US';
            $inForm->state = $geography->getStateNameTranslated($inForm->country, $inForm->state, $langCode);

            // Tack on the translated country name.
            $inForm->country = (!empty($translatedCountries[$inForm->country]))
                ? $translatedCountries[$inForm->country]
                : '';

            // Tack on the status keycode.
            $inForm->statusKeyCode = 'status_' . ((empty($inForm->status)) ? 'closed' : $inForm->status);

            // Now tack on the timezone to a few fields.
            $timezone = ($this->app->mode == 'Development') ? 'CST' : 'UTC';
            $inForm->subByDate .= " $timezone";
            $inForm->creationStamp .= " $timezone";
        }
        return $intakeForms;
    }




    /**
     * Get tenant assets needed for legacy intake forms
     *
     * @return array client assets
     */
    public function getTenantAssets()
    {
        // If you've made it here, you need to set the clientDB (because we now know the client).
        $this->DB->setClientDB($this->tenantID);

        $assets = [];
        if ($this->tenantID <= 0 || $this->inFormType <= 0) {
            return $assets;
        }
        $subscriberVals = 'logoFileName, clientName AS szClientName, ddqPrivacyLink AS szPrivacyLink, '
            . 'regionTitle, ddqQuestionVer, siteColorScheme, ddqColorScheme';
        $assets = (new Subscriber($this->tenantID))->getValues($subscriberVals);

        $ddqNameMdl = new DdqName($this->tenantID);
        $assets['ddqQuestionVer'] = $ddqNameMdl->getFormQuestVerByCaseType($this->inFormType);
        $this->inFormVersion = $assets['ddqQuestionVer'];
        $assets['formClass'] = $ddqNameMdl->getFormClassByCaseType($this->inFormType);
        $assets['invitation'] = (new TenantFeatures($this->tenantID))->tenantHasFeature(
            \Feature::TENANT_DDQ_INVITE,
            \Feature::APP_TPM
        );
        if (($assets['formClass'] != 'training') && $this->origin) {
            $assets['invitation'] = ($this->origin == 'invitation') ? 1 : 0;
        }

        // Check /clientlogos folder for unique intake form logos. Use if present
        $logoUrlBase = $_SERVER['DOCUMENT_ROOT'] . '/cms/dashboard/clientlogos/';
        $logoFileName = 'ddq' . $this->inFormType . '_logo_cid' . $this->tenantID;
        if (file_exists($logoUrlBase . $logoFileName . '.jpg')) {
            $assets['logoFileName'] = $logoFileName . '.jpg';
        } elseif (file_exists($logoUrlBase . $logoFileName . '.png')) {
            $assets['logoFileName'] = $logoFileName . '.png';
        }

        return $assets;
    }






    /**
     * Retrieve the text for legacy intake form's login page.
     *
     * @return string login page text
     */
    public function getLoginPageText()
    {
        $OQrec = $this->OQ->getOQrec(
            $this->languageCode,
            $this->inFormType,
            $this->inFormVersion,
            'TEXT_DDQ_LOGINPAGE'
        );
        return $OQrec['labelText'];
    }


    /**
     * Gets onlineQuestions rows for a given legacy intake form page.
     *
     * @param string  $pageTab   page/tab of legacy intake form
     * @param boolean $HCPselect If true, is for an HCP/non-HCP selection login screen
     *
     * @return array onlineQuestions rows
     */
    public function getOnlineQuestions($pageTab, $HCPselect = false)
    {
        $rtn = [];
        if (!empty($pageTab)) {
            $OQs = $this->OQ->getOnlineQuestions(
                $this->languageCode,
                $this->inFormType,
                $pageTab,
                0,
                $this->inFormVersion,
                true
            );

            if (empty($OQs)) {
                return $rtn;
            }

            $rtn = ['all' => $OQs];

            // If there are questionIDs to extract for the $pageTab, do so and return their text values.
            if (!empty($this->extractableQIDs[$pageTab])) {
                foreach ($this->extractableQIDs[$pageTab] as $idx => $questionID) {
                    if ($HCPselect && $idx == 'HCPselect') {
                        foreach ($this->extractableQIDs[$pageTab]['HCPselect'] as $HCPidx => $HCPquestionID) {
                            if ($extracted = $this->OQ->extractQuestion($OQs, $HCPquestionID)) {
                                $rtn['extracted'][$HCPidx] = $extracted['labelText'];
                            }
                        }
                        continue;
                    }
                    if ($extracted = $this->OQ->extractQuestion($OQs, $questionID)) {
                        $rtn['extracted'][$idx] = $extracted['labelText'];
                    }
                }
            }
        }
        return $rtn;
    }



    /**
     * Detects whether or not a language exists for a client's online questions
     *
     * @param string $languageCode language code
     *
     * @return boolean if exists true, else false
     */
    public function isOnlineQuestLang($languageCode = '')
    {
        $row = $this->OQ->getOQrec($languageCode, $this->inFormType, $this->inFormVersion, '', false);
        $isOnlineQuestLang = (!empty($row));
        return $isOnlineQuestLang;
    }




    /**
     * Login with the supplied credentials
     *
     * @param string  $loginID              ddq.loginEmail
     * @param string  $pw                   ddq.password
     * @param string  $formClass            ddq.formClass
     * @param integer $inFormType           ddq.caseType
     * @param integer $inFormVersion        ddq.ddqQuestionVer
     * @param boolean $viaNewForm           If true, called via new form creation
     * @param boolean $adminDirectDdqAccess Is this request for admin direct access
     *
     * @return array success and data items
     *
     * @throws \Exception
     */
    public function login(
        $loginID,
        $pw,
        $formClass,
        $inFormType,
        $inFormVersion,
        $viaNewForm = false,
        $adminDirectDdqAccess = false
    ) {
        if (empty($loginID) || empty($pw) || empty($formClass) || empty($inFormType)) {
            throw new \Exception($this->app->trans->codeKey('login_failed'));
        }

        if (!$viaNewForm
            && ($previousLoginData = $this->loginAuth->isPreviousLoginLocked($loginID, md5($pw)))
            && !empty($previousLoginData['locked'])
        ) {
            // Brute Force from previous attempts. Gather data, send notifications and get outa Dodge.
            $previousLoginData['tenantID'] = $this->tenantID;
            $bruteForceData = $this->loginAuth->loginFailed($loginID, $previousLoginData);
            $bruteForceData['tenantID'] = $this->tenantID;
            $bruteForceData['validLoginID'] = $loginID;
            $this->loginAuth->sendLockNotices($bruteForceData);
            return ['success' => false, 'previousLoginData' => $bruteForceData];
        }

        if ($this->validateLoginCreds($loginID, $pw) || $viaNewForm) {
            // Successsful login or eles arrived here by way of a new intake form.
            $existingIntkFrms = $this->loginPP(
                $loginID,
                $pw,
                $formClass,
                $inFormType,
                $inFormVersion,
                true,
                $viaNewForm
            );
            if ($adminDirectDdqAccess) {
                $sql = "SELECT * FROM ddq WHERE id = :ID AND status = 'active'";
                return [
                    'success' => true,
                    'existingIntkFrms' => $existingIntkFrms,
                    'matchedIntkFrm' => $this->DB->fetchIndexedRow($sql, [':ID' => $this->session->get('ddqID')])
                ];
            } else {
                $sql = "SELECT * FROM ddq\n"
                    . "WHERE loginEmail = :loginEmail AND passWord = :passWord\n"
                    . "AND clientID = :tenantID AND status = 'active'\n"
                    . "ORDER BY id DESC LIMIT 1";
                $params = [':loginEmail' => $loginID, ':passWord' => $pw, ':tenantID' => $this->tenantID];
                return [
                    'success' => true,
                    'existingIntkFrms' => $existingIntkFrms,
                    'matchedIntkFrm' => $this->DB->fetchIndexedRow($sql, $params)
                ];
            }
        } else {
            // Bad login
            $failureData = [
                'ip' => $this->app->environment['REMOTE_ADDR'],
                'pwhash' => md5($pw),
                'match_id' => $previousLoginData['match_id'],
                'match_pw' => $previousLoginData['match_pw'],
                'match_ip' => $previousLoginData['match_ip'],
                'tenantID' => $this->tenantID
            ];
            $bruteForceData = $this->loginAuth->loginFailed($loginID, $failureData);
            return ['success' => false, 'previousLoginData' => []];
        }
    }





    /**
     * Post processing following login. Returns active and inactive intake forms
     *
     * @param string  $loginID       ddq.loginEmail
     * @param string  $pw            ddq.password
     * @param string  $formClass     ddq.formClass
     * @param integer $inFormType    ddq.caseType
     * @param integer $inFormVersion ddq.ddqQuestionVer
     * @param boolean $validated     If true, the supplied credentials have been validated
     * @param boolean $viaNewForm    If true, called via new form creation
     *
     * @return array active and inactive intake forms
     *
     * @throws \Exception
     */
    private function loginPP(
        $loginID,
        $pw,
        $formClass,
        $inFormType,
        $inFormVersion,
        $validated,
        $viaNewForm = false
    ) {
        $where = "WHERE loginEmail = :loginEmail AND clientID = :tenantID";
        $params = [':loginEmail' => $loginID, ':tenantID' => $this->tenantID];

        if ($validated) {
            // Clear pwRequest (Base Model can't set a value to NULL, so going old school)
            $this->DB->query("UPDATE ddq SET pwRequest = NULL $where", $params);

            // Deactivate related brute force records
            $this->DB->query(
                "UPDATE " . $this->DB->authDB . ".ddqLoginAttempts SET active = 0\n"
                . "WHERE active = 1 AND loginid = :loginid",
                [':loginid' => $loginID]
            );
        }

        if ($viaNewForm && $formClass == 'internal') {
            $activeIntakeForms = $inactiveIntakeForms = 0;
        } else {
            // count active and inactive intake forms using these credentials
            $activeIntakeForms = $this->DB->fetchValue(
                "SELECT COUNT(*) FROM ddq $where AND status = 'active'",
                $params
            );
            $inactiveIntakeForms = $this->DB->fetchValue(
                "SELECT COUNT(*) FROM ddq $where AND status <> 'active'",
                $params
            );
        }
        if ($viaNewForm) {
            $intakeFormAttributes = [
                'clientID' => $this->tenantID,
                'loginEmail' => $loginID,
                'passWord' => $pw,
                'caseType' => $inFormType,
                'ddqQuestionVer' => $inFormVersion,
                'origin' => 'open_url',
                'formClass' => $formClass,
                'status' => 'active',
            ];

            if (!$this->ddq->setAttributes($intakeFormAttributes) || !$this->ddq->save()) {
                throw new \Exception($this->app->trans->codeKey('login_failed'));
            } else {
                $activeIntakeForms++;
            }
        }
        return ['active' => $activeIntakeForms, 'inactive' => $inactiveIntakeForms];
    }



    /**
     * Sets and returns the language intake forms will be displayed with
     *
     * @param string $languageCode language code selected by the user
     *
     * @return string Language Code
     */
    public function setLanguageCode($languageCode = '')
    {
        if (empty($languageCode) || $this->tenantID <= 0 || !$this->isOnlineQuestLang($languageCode)) {
            if ($this->tenantID <= 0 && empty($languageCode)) {
                $languageCode = 'EN_US';
            } else {
                $languageCode = (new Languages())->getDefaultBrowserLanguage();
                if ($this->tenantID > 0 && !$this->isOnlineQuestLang($languageCode)) {
                    $languageCode = 'EN_US';
                }
            }
        }
        $this->languageCode = (!empty($languageCode)) ? $languageCode : 'EN_US';
        return $this->languageCode;
    }



    /**
     * Validates loginEmail and password combo.
     *
     * @param string $loginID          ddq.loginEmail
     * @param string $providedPassword ddq.passWord
     *
     * @return boolean True if valid else false
     */
    public function validateLoginCreds($loginID, $providedPassword)
    {
        $intakeFormAttributes = ['loginEmail' => $loginID, 'clientID' => $this->tenantID];
        $recs = (new DdqBL($this->tenantID))->selectMultiple(['id', 'passWord'], $intakeFormAttributes);
        foreach ($recs as $userIntakeForm) {
            // Check all the DDQ passwords the user has access to (based on email & clientId)
            // if any 1 password validates then that should be acceptible
            if (!Argon2Encrypt::stringIsArgonPassword($userIntakeForm['passWord'])) {
                // For passwords stored in plane text
                if ($userIntakeForm['passWord'] === $providedPassword) {
                    // Convert them to Argon2
                    if (Argon2Encrypt::useArgon2Encryption($loginID)) {
                        $hashedPassword = Argon2Encrypt::argon2EncryptPassword($providedPassword, $loginID);
                        $this->ddq->changePasswordTo($loginID, $hashedPassword);
                    }
                    return true;
                }
            } else {
                return Argon2Encrypt::argonPasswordVerify($providedPassword, $userIntakeForm['passWord']);
            }
        }

        return false;
    }

    /**
     * Validate the OneTime access link complete credentials as well as valid timeframe
     *
     * @param int $Id ddq.id
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function validateOneTimeAccess($Id)
    {
        $oneTimeAccessTime = $this->ddq->findById($Id);
        return is_object($oneTimeAccessTime)
            && $this->accessLinkTimeIsValid($oneTimeAccessTime->getAttributes()['authStringCreated']);
    }

    /**
     * Check if the OneTime acces link is still in the allowded time frame
     *
     * @param $authStringCreated Timestamp from database ddq.authStringCreated
     *
     * @return bool
     *
     * @throws \Exception
     */
    private function accessLinkTimeIsValid($authStringCreated)
    {
        if (!is_int($this->ddqAccessLinkTimeLimit)) {
            throw new \Exception('ddqAccessLinkTimeLimit needs to be an Int');
        }
        $validTimeLimit = strtotime("+{$this->ddqAccessLinkTimeLimit} years", strtotime((string) $authStringCreated));
        return time() <= $validTimeLimit;
    }

    /**
     * Save DDQ password
     *
     * @param string $loginEmail ddq.loginEmail
     * @param int    $clientID   ddq.id
     * @param string $password   Password provided by user OneTime access link validation
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function setAllQuestionnairePasswords($loginEmail, $clientID, $password)
    {
        try {
            if (!(new DdqBL($clientID))
                ->update(
                    ['passWord' => $password, 'authStringCreated' => ''],
                    ['loginEmail' => $loginEmail]
                )
            ) {
                throw new \Exception('Could not save new password.');
            }
        } catch (\Exception $e) {
            $this->app->log->error($e->getMessage());
            $this->jsObj = new \stdClass();
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorMsg = $e->getMessage();
            $this->jsObj->ErrorTitle = 'Password Not Saved';
            return false;
        }
        return true;
    }

    /**
     * Get Ddq Client Url
     *
     * @param $ddqID ddq.id
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getDdqLink($ddqID)
    {
        return $this->ddq->findById($ddqID)->getLink();
    }
}
