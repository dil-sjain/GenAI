<?php
/**
 * Intake Form (A.K.A. DDQ) Login controller
 */

namespace Controllers\TPM\IntakeForms\Legacy\Login;

use Controllers\Login\Base;
use Lib\Crypt\Crypt64;
//use Lib\Legacy\UserType;
use Lib\LoginAuth;
use Lib\SettingACL;
use Lib\Traits\AjaxDispatcher;
use Lib\Validation\Validate;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\Subscriber;
use Models\TPM\IntakeForms\IntakeFormSecurityCodes;
use Models\TPM\IntakeForms\Legacy\InFormLoginData;
use Models\User;
use Models\Globals\Features\TenantFeatures;
use Firebase\JWT\JWT;
use Models\Ddq;
use Models\TPM\IntakeForms\DdqBL;
use Lib\FeatureACL;

/**
 * IntakeFormLogin controller
 *
 * @keywords intake form, login, ddq
 */
class IntakeFormLogin extends Base
{
    use AjaxDispatcher;

    public const ENABLE_ONETIME_ACCESS_LINK = 0;


    public const PATH_INTAKE_FORM = '/cms/ddq/ddq2.php';
    public const PATH_INTAKE_FORM_HCP = '/cms/ddq_hcp/ddq2.php';
    public const PATH_INTAKE_FORM_LIST = '/intake/legacy/formslist';

    /**
     * template path string
     *
     * @var string
     */
    protected $template = 'TPM/IntakeForms/Legacy/Login/Login.tpl';

    /**
     * @var string Template name
     */
    protected $HCPselectTemplate = 'TPM/IntakeForms/Legacy/Login/HCPselectLogin.tpl';

    /**
     * @var string Template name
     */
    protected $HCPrequestFormsTemplate = 'TPM/IntakeForms/Legacy/Login/HCPrequestForms.tpl';

    /**
     * @var string Template name
     */
    protected $noAccessTemplate = 'TPM/IntakeForms/Legacy/Login/NoAccess.tpl';

    /**
     * JSON response template
     *
     * @var object
     */
    private $jsObj = null;

    /**
     * \Xtra::app()->log instance
     *
     * @var object
     */
    private $logger = null;

    /**
     * InFormLoginData instance
     *
     * @var object
     */
    private $data = null;

    /**
     * App Session instance
     *
     * @var object
     */
    protected $session = null;

    /**
     * KeyCode for title translation
     *
     * @var string
     */
    private $titleKeyCode = null;

    /**
     * current template name
     *
     * @var string
     */
    private $currentTemplate = null;

    /**
     * onlineQuestions records
     *
     * @var array
     */
    private $OQs = [];

    /**
     * determines if noAccess template is used or not
     *
     * @var boolean
     */
    private $noAccess = null;

    /**
     * Current translation language
     *
     * @var string
     */
    private $langCode = '';

    /**
     * Url Parameter key used for the One-time access link
     * @var string
     */
    protected static $accessLinkParamKey = 'ddqal';

    /**
     * @var string Key used for used One-Time access link [en/de]cryption
     *             If you change this value, all existing OneTime access links will be invalid.
     *             Be sure to change its legacy equivalent as well:
     *             public_html/cms/includes/php/Controllers/TPM/IntakeForms/Legacy/Login/IntakeFormLogin.php
     */
    private static $accessLinkEncryptionKey = "xomQ)|A'tFVYk2iV~";

    /**
     * Sets IntakeFormLogin template to view
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->app = \Xtra::app();
        $this->session = $this->app->session;
        $this->logger = \Xtra::app()->log;

        // Default to NoAccess (assume the worst)
        $this->noAccess = true;
        $this->currentTemplate = $this->noAccessTemplate;
        $this->titleKeyCode = 'title_tag';
    }




    /**
     * initialize the page based upon the validity of the securityCode in the URL
     *
     * @param string  $accessCode   security code for accessing intake form
     * @param boolean $requestForms If true, will set template to HCP Request Forms
     *
     * @return void
     */
    public function initialize($accessCode = '', $requestForms = false)
    {
        // Default values
        $HCPselect = false;
        $loginPath = '/intake/legacy/';
        $tenantID = $inFormType = 0;
        $secCode = (!empty($accessCode))
            ? (new IntakeFormSecurityCodes())->findByAttributes(['ddqCode' => $accessCode])
            : [];

        if (empty($accessCode)) {
            $this->resetReferers(['ddqPgReferer']);
            return $this->initializeError('no_access_code');
        }

        if (!$this->keepSessionIntact()) {
            $this->sanitize();
        }

        if (!empty($secCode)) {
            $this->noAccess = false;
            $tenantID = $secCode->get('clientID');
            $inFormType = $secCode->get('caseType');
            $origin = $secCode->get('origin');
            $this->session->set('clientID', $tenantID);
            $this->session->set('tenantID', $tenantID);
            $this->session->set('inFormType', $inFormType);
            $this->session->set('origin', $origin);
            $HCPselect = ($tenantID == Subscriber::BIOMET_CLIENTID);
        }

        // Redirect if Tenant has Osprey Forms (Forms 2.0) feature and the lgcRdr query param is not present.
        if (!isset($_GET['lgcRdr'])
            && !empty($tenantID)
            && (new TenantFeatures($tenantID))->tenantHasFeature(
                \Feature::TENANT_DDQ_OSP_FORMS_REDIRECT,
                \Feature::APP_TPM
            )
        ) {
            header("Location: {$_ENV['ospreyISS']}/Login/forms/{$accessCode}");
            exit();
        }

        $this->session->set('secCode', $accessCode);

        $this->setDataModel()
            ->setTenantAssets($tenantID)
            ->setLanguage($tenantID)
            ->getOQs($HCPselect)
            ->getTranslations()
            ->getDdqPrivacySettings($tenantID);

        if ($this->noAccess) {
            // It's definitely a no-access situation. Off to render the No Access template...
            if (empty($secCode)) {
                $errorCode = 'bad_access_code';
            } elseif (!$this->OQs) {
                $errorCode = 'no_questions_found';
            }
            return $this->initializeError($errorCode, $accessCode);
        }

        $loginPath .= "sc/$accessCode";

        $this->viewModel->secCode = $this->session->get('secCode');
        $this->viewModel->inFormType = $this->session->get('inFormType');
        $this->viewModel->inFormVersion = ($this->session->has('inFormVersion'))
            ? $this->session->get('inFormVersion') : '';
        $this->viewModel->formClass = $this->session->get('formClass');
        $this->viewModel->availableLanguages = $this->data->getAvailableLanguages();
        $this->viewModel->tenantID = $tenantID;

        /*
         * DDQ One-Time Link Access
         * @Note  If the url param is found the script halts here
         */

        if (isset($this->app->clean_GET[self::$accessLinkParamKey])) {
            $accessHash = $this->app->clean_GET[self::$accessLinkParamKey];
            if ([$Id, $clientID, $loginID] = $this->decryptOneTimeAuthHash($accessHash)) {
                if ($this->data->validateOneTimeAccess($Id)) {
                    $this->viewModel->invitationLoginID = $loginID;
                    $this->viewModel->invitationID = $Id;
                    $this->titleKeyCode = 'intakeFormsLegacy_title';
                    $this->currentTemplate = 'TPM/IntakeForms/Legacy/Login/OneTimeLoginPasswordCreate.tpl';
                    $this->renderTemplate();
                    return;
                }
            } else {
                $this->viewModel->accessLinkError = 'Invalid Access Link';
            }
        }

        if ($HCPselect) {
            if ($inFormType == Cases::SELECT_DUE_DILIGENCE_TYPE) {
                if ($requestForms) {
                    $this->titleKeyCode = "intakeFormsLegacy_title_hcp_request_forms";
                    $this->currentTemplate = $this->HCPrequestFormsTemplate;
                } else {
                    $this->titleKeyCode = "intakeFormsLegacy_title_choose";
                    $this->currentTemplate = $this->HCPselectTemplate;
                    $this->viewModel->biometTeamMembersURL = "/intake/legacy/hcp/requestforms/$accessCode";
                }
            } elseif ($inFormType == Cases::DUE_DILIGENCE_HCPDI
                || $inFormType == Cases::DUE_DILIGENCE_HCPDI_RENEWAL
            ) {
                $this->titleKeyCode = "intakeFormsLegacy_title_hcp";
                $this->currentTemplate = $this->template;
            } else {
                $this->titleKeyCode = 'intakeFormsLegacy_title';
                $this->currentTemplate = $this->template;
            }
        } else {
            $this->titleKeyCode = 'intakeFormsLegacy_title';
            $this->currentTemplate = $this->template;
        }
        if (!isset($this->viewModel->accessLinkError)) {
            $this->viewModel->accessLinkError = '';
        }
        $this->viewModel->invitationID = '';
        $this->viewModel->invitationLoginID = '';
        $this->session->set('inFormLoginPath', $loginPath);
        $this->viewModel->loginPath = $loginPath;
        $this->renderTemplate();
    }



    /**
     * Initialize the No Access template displaying a specific error message.
     *
     * @param string $errorCode  code for displaying specific kind of error
     * @param string $accessCode security code for accessing intake form
     *
     * @return void
     */
    public function initializeError($errorCode = '', $accessCode = '')
    {
        $loginPath = '/intake/legacy/';
        if (empty($accessCode)) {
            $errorCode = 'no_access_code';
        }

        $tenantID = 0;

        if (!empty($accessCode)) {
            $secCode = (new IntakeFormSecurityCodes())->findByAttributes(['ddqCode' => $accessCode]);
            if (!empty($secCode)) {
                $tenantID = $secCode->get('clientID');
                $loginPath .= "sc/$accessCode";
                $this->session->set('inFormLoginPath', $loginPath);
            } else {
                $errorCode = 'bad_access_code';
            }
        }

        $this->setDataModel()
            ->setTenantAssets($tenantID)
            ->setLanguage($tenantID)
            ->getTranslations();

        // If a bad error code, redirect to the login path.
        if (empty($errorCode) || empty($this->viewModel->trText[$errorCode])) {
            $this->app->response->redirect($loginPath);
        }

        $this->viewModel->tenantID = $tenantID;
        $this->viewModel->errorKeyCode = $errorCode;
        $this->renderTemplate();
    }



    /**
     * Detect if the session data should be spared due to specific referering pages having session keys.
     * If at least one does, wipe out any refering page keys from the session.
     *
     * @return boolean
     */
    private function keepSessionIntact()
    {
        $keepIntact = false;
        $intactSessionReferers = ['passwordPgReferer', 'languageChanged'];
        foreach ($intactSessionReferers as $referer) {
            if ($this->session->has($referer)) {
                $keepIntact = true;
                $this->resetReferers($intactSessionReferers);
                break;
            }
        }
        return $keepIntact;
    }



    /**
     * AJAX method that changes the language as set by the user
     *
     * @return void
     */
    private function ajaxChangeLanguage()
    {
        $languageCode = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'langCode', ''));
        if (!$this->isSessionDataSecure()) {
            $this->sessionDataFailure();
        } else {
            $tenantID = $this->session->get('tenantID');
            $this->setDataModel()->setLanguage($tenantID, $languageCode);
            $this->session->set('languageChanged', true);
            $this->jsObj->Result = 1;
        }
    }




    /**
     * AJAX method that creates new login credentials.
     *
     * @return void
     */
    private function ajaxCreateIntakeForm()
    {
        $loginID = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'loginID', ''));
        $pw = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'pw', ''));
        $this->sharedLogin($loginID, $pw, true);
    }



    /**
     * AJAX method that submits login credentials.
     *
     * @return void
     */
    private function ajaxLogin()
    {
        $loginID = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'loginID', ''));
        $pw = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'pw', ''));
        $this->sharedLogin($loginID, $pw);
    }

    /**
     * Login to a DDQ from either user login submission or Forms 2.0 JWT
     *
     * @param string  $loginID              ddq.loginEmail
     * @param string  $pw                   ddq.passWord
     * @param boolean $newForm              indicates that a new intake form with new credentials is being created
     * @param boolean $bypassList           If true, bypass intake form list and force login to matched form
     * @param boolean $adminDirectDdqAccess True if this request comes from Admin DDQ access link
     *
     * @return void
     */
    private function sharedLogin($loginID, $pw, $newForm = false, $bypassList = false, $adminDirectDdqAccess = false)
    {
        if (!$this->isSessionDataSecure()) {
            $this->sessionDataFailure();
        } else {
            // Logic only pertains to creating new intake form credentials.
            if ($newForm && $errors = $this->validateCredentials($loginID, $pw)) {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrTitle = $this->app->trans->codeKey('error_please_note');
                $this->jsObj->ErrMsg = $errors;
            }

            $formClass = $this->session->get('formClass');
            $inFormType = $this->session->get('inFormType');
            $inFormVersion = $this->session->get('inFormVersion');
            $this->setDataModel();
            $login = [];
            $exceptionMsg = null;
            try {
                // If this is a new form, try creating the form otherwise, login.
                $login = ($newForm)
                    ? $this->data->createIntakeForm($loginID, $pw, $formClass, $inFormType, $inFormVersion)
                    : $this->data->login($loginID, $pw, $formClass, $inFormType, $inFormVersion, $adminDirectDdqAccess);
            } catch (\Exception $e) {
                $exceptionMsg = $e->getMessage();
            }
            $this->loginPP($loginID, $pw, $login, ($newForm || $bypassList), $exceptionMsg);
        }
    }

    /**
     * AJAX method that submits password credentials.
     *
     * @return void
     */
    private function ajaxSetPassword()
    {
        $loginID = $this->getPostVar('loginID', '');
        $password = $this->getPostVar('pw', '');
        $ddqID = $this->getPostVar('invitationID', '');
        $clientID = $this->getPostVar('tenantID', '');
        $this->data = new InFormLoginData($clientID, []);
        if ($this->data->setAllQuestionnairePasswords($loginID, $clientID, $password)) {
            $this->jsObj->FuncName = 'appNS.legacyIntakeLogin.createPasswordHndl';
            $this->jsObj->Args = [$this->data->getDdqLink($ddqID)];
            $this->jsObj->Result = 1;
        } else {
            // Errors Setting the Password
            $this->jsObj->ErrTitle = 'Password Error';
            $this->jsObj->ErrMsg = 'Error setting DDQ password';
        }
    }

    /**
     * Gets onlineQuestions content, and loads into session variables.
     *
     * @param boolean $HCPselect If true, is for an HCP/non-HCP selection login screen
     *
     * @return $this returns self for chaining.
     */
    protected function getOQs($HCPselect)
    {
        if ($this->noAccess) {
            return $this;
        }
        $this->OQs = false;
        if ($OQs = $this->data->getOnlineQuestions('ddqLogin', $HCPselect)) {
            $this->OQs = $OQs['all'];
            $this->session->set("aOnlineQuestions", $OQs['all']);

            // Get Login page text
            $this->viewModel->loginPageText = $this->data->getLoginPageText();

            // Load up all other view variables for text extracted from onlineQuestions
            if (!empty($OQs['extracted'])) {
                foreach ($OQs['extracted'] as $idx => $text) {
                    $this->viewModel->$idx = $text;
                }
            }
        } else {
            $this->noAccess = true;
        }
        return $this;
    }




    /**
     * Populate $this->trText translation text array
     *
     * @return $this returns self for chaining.
     */
    #[\Override]
    protected function getTranslations()
    {
        $this->viewModel->isRTL = \Xtra::isRTL($this->session->get('languageCode'));
        $this->viewModel->trText = ($this->noAccess)
            ? $this->app->trans->group('ddq_noaccess')
            : $this->app->trans->groups(['ddq_login', 'ddq_login_err', 'ddq_login_misc', 'ddq_privacy', 'login']);
        return $this;
    }





    /**
     * Compares session vars passed down to view and retrieved via AJAX with current session variables.
     * Returns false for any variation.
     *
     * @return boolean
     */
    private function isSessionDataSecure()
    {
        $tenantID = intval(\Xtra::arrayGet($this->app->clean_POST, 'tenantID', 0));
        $secCode = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'secCode', ''));
        $inFormType = intval(\Xtra::arrayGet($this->app->clean_POST, 'inFormType', 0));
        $formClass = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'formClass', ''));
        $inFormVersion = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'inFormVersion', ''));
        $tenantIDSession = ($this->session->has('tenantID')) ? $this->session->get('tenantID') : 0;
        $secCodeSession = ($this->session->has('secCode')) ? $this->session->get('secCode') : '';
        $inFormTypeSession = ($this->session->has('inFormType')) ? $this->session->get('inFormType') : 0;
        $formClassSession = ($this->session->has('formClass')) ? $this->session->get('formClass') : 0;
        $inFormVersionSession = ($this->session->has('inFormVersion')) ? $this->session->get('inFormVersion') : 0;
        return (($tenantIDSession == $tenantID) && ($secCodeSession == $secCode)
            && ($inFormTypeSession == $inFormType) && ($formClassSession == $formClass)
            && ($inFormVersionSession == $inFormVersion));
    }




    /**
     * Load up the intake form matched to the logged in user.
     *
     * @param array $matchedIntkFrm intake form info
     * @param bool  $isAjax         request type
     *
     * @return mixed Depending on $isAjax request type
     *               If isAjax then jsObj is built
     *               If not Ajax then array [Result, Args => [url]]
     */
    protected function loadIntakeForm($matchedIntkFrm, $isAjax = true)
    {
        $tenantID = $this->session->get('tenantID');
        $languageCode = $this->session->get('languageCode');

        /*
         * Legacy's DDQ pages (ddq2, ddq3, ddq4, etc.) requires $_SESSION['ddqRow']
         * to contain an index array of values (arriving via $matchedIntkFrm). This is
         * why you see variables being set to some indexed array items here, as opposed
         * to associative array items. Just giving Legacy her preeeecccccioussss....
         */

        $inFormRspnsID = $matchedIntkFrm['0']; // A.K.A. ddq.id or ddqID
        $inFormType = $matchedIntkFrm['107'];
        $inFormVersion = $matchedIntkFrm['126'];
        $loginEmail = $matchedIntkFrm['3'];
        $passWord = $matchedIntkFrm['4'];
        $origin = $matchedIntkFrm['144'];

        $this->session->set('userEmail', $loginEmail);
        $this->session->set('passWord', $passWord);
        $this->session->set('inFormRspnsID', $inFormRspnsID);
        $this->session->set('inFormRspns', $matchedIntkFrm);
        $this->session->set('inFormType', $inFormType);
        $this->session->set('inFormVersion', $inFormVersion);

        $this->setDataModel()->setLanguage($tenantID, $languageCode);

        // verify active questionnaire's ddqCode
        if ($secCodeRow =
            (new IntakeFormSecurityCodes())->findByAttributes(
                ['caseType' => $inFormType, 'clientID' => $tenantID, 'origin' => $origin]
            )
        ) {
            $ddqCode = $secCodeRow->get('ddqCode');
            $loginPath = '/intake/legacy/sc/' . $ddqCode;
            $this->session->set('secCode', $ddqCode);
            $this->session->set('inFormLoginPath', $loginPath);
        }

        // Store base text in session vars
        $this->session->set('baseText', $this->data->getBaseText());

        // set up secured session vars
        $this->session->set('inDDQ', true);
        $this->session->secureSet('inDDQ', true);
        (new LoginAuth())->generateAdvisoryHash('thirdparty');

        /*
         * @todo Kill 'lgcyPgAuth' arg once intake form landing page (ddq2.php or showrelated.sec) is refactored.
         */

        $this->session->secureSet('lgcyPgAuth', $this->session->getToken());
        $this->session->regenerateSession();

        $redirectURL = ($tenantID == Subscriber::BIOMET_CLIENTID)
            ? self::PATH_INTAKE_FORM_HCP
            : self::PATH_INTAKE_FORM;

        if ($isAjax) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [['url' => $redirectURL]];
        } else {
            return [
                'Result' => 1,
                'Args' => [
                    'url' => $redirectURL
                ]
            ];
        }
    }



    /**
     * Load up a list of intake forms matched to the logged in user.
     *
     * @param string $loginID ddq.loginEmail
     * @param string $pw      ddq.password
     *
     * @return void
     */
    protected function loadIntakeFormList($loginID, $pw)
    {
        $this->session->set('userEmail', $loginID);
        $this->session->set('passWord', $pw);
        $this->session->set("allowDDQlist", true);
        $token = Crypt64::randString(16);
        $this->session->set("inFormLoginAuthToken", $token);
        $this->session->set("inFormLoginAuth", md5($token . $loginID . $pw));
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [['url' => self::PATH_INTAKE_FORM_LIST]];
    }



    /**
     * Login failure
     *
     * @param array  $previousLoginData Contains locked, ip, pwhash, match_id, match_pw, match_ip, tenantID
     * @param string $exceptionMsg      Any exception caught
     *
     * @return void
     */
    protected function loginFailure($previousLoginData = [], $exceptionMsg = '')
    {
        $this->jsObj->Result = 0;
        if (!empty($exceptionMsg)) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('error_legacyIntakeForm_dialogTtl');
            $this->jsObj->ErrMsg = $exceptionMsg;
        } elseif (!empty($previousLoginData)) {
            $errs = $this->app->trans->codeKeys(['error_legacyIntakeForm_dialogTtl', 'brute_force_lock']);
            $this->jsObj->ErrTitle = $errs['error_legacyIntakeForm_dialogTtl'];
            $this->jsObj->ErrMsg = $errs['brute_force_lock'];
        } else {
            $errs = $this->app->trans->codeKeys(['error_legacyIntakeForm_dialogTtl', 'login_failed']);
            $this->jsObj->ErrTitle = $errs['error_legacyIntakeForm_dialogTtl'];
            $this->jsObj->ErrMsg = $errs['login_failed'];
        }
    }



    /**
     * Login post-processing: the tail to either ajaxLogin or ajaxCreateIntakeForm
     *
     * @param string  $loginID      ddq.loginEmail
     * @param string  $pw           ddq.passWord
     * @param array   $loginData    either brute force data if failed or intake form data if succeeded
     * @param boolean $bypassList   If true, called via new form creation or called from Forms 2.0 JWT to
     *                              bypass intake form list and force login to matched form
     * @param string  $exceptionMsg Any exception caught from either caller
     *
     * @return void
     */
    protected function loginPP($loginID, $pw, $loginData, $bypassList, $exceptionMsg)
    {
        if ($exceptionMsg !== null || !$loginData['success']) {
            // Login failure
            $previousLoginData = (!empty($loginData['previousLoginData']))
                ? $loginData['previousLoginData']
                : [];
            $this->loginFailure($previousLoginData, $exceptionMsg);
        } else {
            // Login succeeded
            $activeIntkFrms = $loginData['existingIntkFrms']['active'];
            $inactiveIntkFrms = $loginData['existingIntkFrms']['inactive'];
            $matchedIntkFrm = $loginData['matchedIntkFrm'];
            // Forms 2.0 forces redirect to a single DDQ after login, bypassing legacy questionnaire list.
            if (($activeIntkFrms == 1 && $inactiveIntkFrms == 0 && !empty($matchedIntkFrm)) || $bypassList) {
                // Single intake form matched up to login. Get right into it.
                $this->loadIntakeForm($matchedIntkFrm);
            } elseif ($activeIntkFrms + $inactiveIntkFrms) {
                // show questionnaire list, login after selection even if none are still active!
                $this->loadIntakeFormList($loginID, $pw);
            }
        }
    }

    /**
     * Validate the Admin access link as well as valid timeframe
     *
     * @param array $authHash indexed array [ddqID, clientID, loginEmail, validationTimeStamp]
     *
     * @return bool
     */
    public function validateAdminAccessHash($authHash)
    {
        if (empty($authHash)) {
            return false;
        }
        [$ddqID, $clientID, $loginID, $validationTime] = $authHash;
        $hasDDQ = (new Ddq())->findById($ddqID);                // Check for a valid/active ddq
        $linkTimeIsValid = strtotime((string) $validationTime) > time(); // Make sure timestamp is within range

        return !empty($hasDDQ) && $linkTimeIsValid;
    }

    /**
     * Sets page title and renders the template
     *
     * @return void
     */
    protected function renderTemplate()
    {
        $this->setPageTitle($this->viewModel->trText[$this->titleKeyCode]);
        $this->render($this->currentTemplate);
    }





    /**
     * Loops through referers, and removes them from app->session and $_SESSION.
     *
     * @param array $referers keys in app->session and $_SESSION identifying referring pages
     *
     * @return void
     */
    private function resetReferers($referers = [])
    {
        foreach ($referers as $referer) {
            if ($this->session->has($referer)) {
                $this->session->remove($referer);
            }
            if (!empty($_SESSION[$referer])) {
                unset($_SESSION[$referer]);
            }
        }
    }



    /**
     * If the intake form language is not being changed, this will sanitize all intake form and DDQ
     * related session vars in both Delta's app->session object and the $_SESSION superglobal.
     * This will kill any other intake forms in progress in other tabs.
     *
     * @return void
     */
    private function sanitize()
    {
        $lgcyDeltaSessKeyMap = ['clientID'                => 'tenantID', 'clientID'                => 'clientID', 'tenantLogo'              => 'tenantLogo', 'siteColorScheme'         => 'siteColorScheme', 'userEmail'               => 'userEmail', 'passWord'                => 'passWord', 'preferredLanguage'       => 'preferredLanguage', 'logoFileName'            => 'logoFileName', 'szClientName'            => 'szClientName', 'szPrivacyLink'           => 'szPrivacyLink', 'regionTitle'             => 'regionTitle', 'caseType'                => 'inFormType', 'origin'                  => 'origin', 'secCode'                 => 'secCode', 'formClass'               => 'formClass', 'aOnlineQuestions'        => 'aOnlineQuestions', 'ddqID'                   => 'inFormRspnsID', 'ddqRow'                  => 'inFormRspns', 'ddqQuestionVer'          => 'inFormVersion', 'ddqLoginPath'            => 'inFormLoginPath', 'ddqColorScheme'          => 'siteColorScheme', 'allowDDQlist'            => 'allowDDQlist', 'ddqLoginAuthToken'       => 'ddqLoginAuthToken', 'ddqLoginAuth'            => 'ddqLoginAuth', 'sim-userClass'           => 'authUserClass', 'sim-secure_vars'         => 'secure_vars', 'sim-inDDQ'               => 'inDDQ', 'inDDQ'                   => 'inDDQ', 'sim-secure_cookie_set'   => 'secure_cookie_set', 'sim-advisory_login_hash' => 'advisory_login_hash', 'lgcyPgAuth'              => 'pgAuth'];

        if ($this->session->has('ddqPgReferer')) {
            // Referred back to this page from ddqsaveclose.php page.
            // Reset the flag
            $this->session->remove('ddqPgReferer');
            unset($_SESSION['ddqPgReferer']);
        } else {
            // Not referred back to this page from ddqsaveclose.php page, so include languageCode to wipe out.
            $lgcyDeltaSessKeyMap['languageCode'] = 'languageCode';
        }

        foreach ($lgcyDeltaSessKeyMap as $lgcy => $delta) {
            if (!empty($_SESSION[$lgcy])) {
                unset($_SESSION[$lgcy]);
            }
            if ($this->session->has($delta)) {
                $this->session->remove($delta);
            }
        }
        if (!empty($_SESSION['pageGUID'])) {
            unset($_SESSION['pageGUID']);
        }
        if (!empty($_SESSION['ddqThankYou'])) {
            unset($_SESSION['ddqThankYou']);
        }
    }





    /**
     * Session data was lost for intake form login prior to AJAX request. Throw error and redirect.
     *
     * @return void
     */
    private function sessionDataFailure()
    {
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [['expired' => true]];
    }




    /**
     * Sets various session variables as well as properties for the view via tenant data.
     *
     * @param integer $tenantID cms_global.g_intakeFormSecurityCode.clientID
     *
     * @return $this returns self for chaining.
     */
    protected function setTenantAssets($tenantID)
    {
        $tenantID = (int)$tenantID;

        if ($tenantID > 0) {
            // Login View
            $assets = $this->data->getTenantAssets();
            $this->session->set('szClientName', $assets['szClientName']);
            $this->session->set('szPrivacyLink', $assets['szPrivacyLink']);
            $this->session->set('regionTitle', $assets['regionTitle']);
            $this->session->set('inFormVersion', $assets['ddqQuestionVer']);
            $this->session->set('ddqColorScheme', $assets['ddqColorScheme']);
            $this->session->set('formClass', $assets['formClass']);
            $this->viewModel->colorScheme = $assets['ddqColorScheme'];
            $this->viewModel->invitation = $assets['invitation'];
            $caseType = $this->session->get('inFormType'); //Sett in $this->initialize() from $caseType

            $intakeLogo = "/cms/dashboard/clientlogos/ddq" . $caseType . "_logo_cid" . $tenantID;
            if (file_exists($intakeLogo . ".jpg")) {
                $this->session->set('logoFileName', $intakeLogo . ".jpg");
                $this->viewModel->tenantLogo = $intakeLogo . ".jpg";
            } elseif (file_exists($intakeLogo . ".png")) {
                $this->session->set('logoFileName', $intakeLogo . ".png");
                $this->viewModel->tenantLogo = $intakeLogo . ".png";
            } else {
                $this->session->set('logoFileName', $assets['logoFileName']);
                $this->viewModel->tenantLogo = "/cms/dashboard/clientlogos/" . $assets['logoFileName'];
            }
        } else {
            // No Access View
            $this->viewModel->tenantLogo = '/cms/dashboard/clientlogos/logo_cid1.png';
            $this->viewModel->colorScheme = 0;
        }
        $this->session->set('tenantLogo', $this->viewModel->tenantLogo);
        $this->session->set('siteColorScheme', $this->viewModel->colorScheme);
        return $this;
    }




    /**
     * Sets the InFormLoginData model using session variables.
     *
     * @return mixed returns self for chaining
     */
    protected function setDataModel()
    {
        $tenantID = 0;
        $dataParams = [];
        if ($this->session->has('tenantID')) {
            $tenantID = $this->session->get('tenantID');
            if ($this->session->has('inFormType')) {
                $dataParams['inFormType'] = $this->session->get('inFormType');
            }
            if ($this->session->has('origin')) {
                $dataParams['origin'] = $this->session->get('origin');
            }
            if ($this->session->has('inFormVersion')) {
                $dataParams['inFormVersion'] = $this->session->get('inFormVersion');
            }
            if ($this->session->has('languageCode')) {
                $dataParams['languageCode'] = $this->session->get('languageCode');
            }
        }
        $this->data = new InFormLoginData($tenantID, $dataParams);
        return $this;
    }



    /**
     * Sets view and various session languageCode-related variables
     *
     * @param integer $tenantID     Client ID
     * @param string  $languageCode language code selected by the user
     *
     * @return mixed returns self for chaining
     */
    protected function setLanguage($tenantID, $languageCode = '')
    {
        if ($tenantID <= 0) {
            $languageCode = ($this->session->has('languageCode')) ? $this->session->get('languageCode') : '';
            $langCode = $this->data->setLanguageCode();
        } elseif (!empty($languageCode) || !$this->session->has('languageCode')) {
            $langCode = $this->data->setLanguageCode(
                $languageCode
            );
        } else {
            // Vet the language in the session to ensure it's configured for the tenant.
            $langCode = $this->data->setLanguageCode(
                $this->session->get('languageCode')
            );
        }
        $this->langCode = $langCode;
        $this->session->set('languageCode', $langCode);
        $this->session->set('preferredLanguage', $langCode);
        $this->viewModel->languageCode = $this->session->get('languageCode');
        return $this;
    }



    /**
     * Checks to make sure supplied credentials for intake form creation satisfies requirements.
     *
     * @param string $loginID ddq.loginEmail
     * @param string $pw      ddq.password
     *
     * @return string unordered list of error message if any issues, else blank
     */
    protected function validateCredentials($loginID, $pw)
    {
        $tests = new Validate(
            [
            'email' => [[$loginID, 'Rules', 'required|valid_email']],
            'password' => [[$pw, 'Rules', 'required|min_len|password']]
            ]
        );
        $errors = '';
        if ($tests->failed) {
            $emailErrors = (!empty($tests->errors['email'])) ? $tests->errors['email'] : [];
            $pwErrors = (!empty($tests->errors['password'])) ? $tests->errors['password'] : [];
            if (!empty($emailErrors) || !empty($pwErrors)) {
                $errors = '<ul>';
                foreach ($emailErrors as $key => $val) {
                    $errors .= "<li>$val</li>";
                }
                foreach ($pwErrors as $key => $val) {
                    $errors .= "<li>$val</li>";
                }
                $errors .= '</ul>';
            }
        }
        return $errors;
    }

    /**
     * Sets up the needed array and session vals for DDQ Privacy settings
     *
     * @param integer $tenantID Current tenantID
     *
     * @return void
     */
    private function getDdqPrivacySettings($tenantID)
    {
        // SETUP CONSTANTS IN ACL FOR THE GROUP (or USE individual setting id's, whatever)
        // 20 below is for temporary use until actual id's/group id is established.
        $setting = $this->mapDdqPrivacySettings($tenantID);
        // establish the defaults. (if nothing is turned on, no need for text or anything.
        $privacy = [
            'cookieWarn'        => 0,  // cookie warning enabled?
            'cookieTxt'         => '', // full text block for cookie warning
            'acceptBtn'         => '', // accept button text for cookies
            'rejectBtn'         => '', // reject button text for cookies
            'rejectUrl'         => '', // url to redirect to if reject cookies clicked
            'noSteeleBrand'     => 0,  // remove steele branding enabled?
            'noSteeleLogo'      => 0,  // turn off steele logo in DDQ header?
            'noSteeleCopyright' => 0,  // turn off steele logo in DDQ header?
            'footerReplace'     => '', // replace steele copyright in footer with link (and text if applicable)
        ];
        // GDPR-style cookie warning
        if ($setting['enableCookieWarn'] == 1) {
            $privacy['cookieWarn'] = ($setting['enableCookieWarn'] == 1 ? 1 : 0);
            $cookieTxt = $this->viewModel->trText['ddq_privacy_cookie_warning'];
            $cookieVisitTxt = $this->viewModel->trText['ddq_privacy_cookie_policy_visit_text'];
            $fullLinkTxt = '';
            if (filter_var($setting['cookiePolicyLink'], FILTER_VALIDATE_URL)) {
                $urlText = $setting['cookiePolicyLink'];
                if ($setting['cookieLinkCstmTxt'] == 1) {
                    $urlText = $this->viewModel->trText['ddq_privacy_cookie_warning_link'];
                }
                $url = '<a href="' . $setting['cookiePolicyLink'] . '" target="_blank" title="' . $urlText . '">'
                    . $urlText . '</a>';
                if ($setting['cookieLinkCstmTxt'] == 1) {
                    $fullLinkTxt = str_replace('{privacy_policy_link}', $url, (string) $cookieVisitTxt);
                } else {
                    $fullLinkTxt = $url;
                }
            }
            $privacy['cookieTxt'] = str_replace('{ddq_privacy_cookie_policy_visit_text}', $fullLinkTxt, (string) $cookieTxt);
            $privacy['acceptBtn'] = $this->viewModel->trText['ddq_privacy_button_accept'];
            $privacy['rejectBtn'] = $this->viewModel->trText['ddq_privacy_button_reject'];
            $privacy['rejectUrl'] = 'https://google.com';
            if (filter_var($setting['rejectLink'], FILTER_VALIDATE_URL)) {
                $privacy['rejectUrl'] = $setting['rejectLink'];
            }
        }
        // Remove Steele branding(s)?
        if ($setting['noBranding'] == 1) {
            $privacy['noSteeleBrand'] = 1;
            $privacy['noSteeleLogo'] = ($setting['noSteeleLogo'] == 1 ? 1 : 0);
            if ($setting['noSteeleCopyright'] == 1) {
                $privacy['noSteeleCopyright'] = 1;
                $footReplace = '';
                // if a client wanted just a text string, they could have no url, but enable the link text.
                if (filter_var($setting['footerLink'], FILTER_VALIDATE_URL)) {
                    $footReplace = '<a href="' . $setting['footerLink'] . '" target="_blank">';
                    if ($setting['footerText'] == 1) {
                        $footReplace .= $this->viewModel->trText['ddq_privacy_footer_link'];
                    } else {
                        $footReplace .= $setting['footerLink'];
                    }
                    $footReplace .= '</a>';
                } elseif ($setting['footerText'] == 1) {
                    $footReplace = $this->viewModel->trText['ddq_privacy_footer_link'];
                }
                $privacy['footerReplace'] = $footReplace;
            }
        }
        // push settings to the session for use throughout the DDQ.
        $this->session->set('ddqPrivacy', $privacy);
        $this->viewModel->privacyConfig = $privacy;
    }

    /**
     * Get tenant settings by group (more efficient) and turn into smaller, more usable array (key -> value)
     *
     * @param integer $tenantID tenantID
     *
     * @return array
     */
    private function mapDdqPrivacySettings($tenantID)
    {
        $settingIDmap = [
            SettingACL::DDQ_PRIVACY_ENABLE_COOKIE_WARNING   => 'enableCookieWarn', // 1/0
            SettingACL::DDQ_PRIVACY_POLICY_LINK             => 'cookiePolicyLink', // url
            SettingACL::DDQ_PRIVACY_POLICY_LINK_CUSTOM_TEXT => 'cookieLinkCstmTxt', // 1/0
            SettingACL::DDQ_PRIVACY_REJECT_REDIRECT_LINK    => 'rejectLink', // url
            SettingACL::DDQ_PRIVACY_ENABLE_DEBRANDING       => 'noBranding', // 1/0
            SettingACL::DDQ_PRIVACY_NO_STEELE_LOGO          => 'noSteeleLogo', // 1/0
            SettingACL::DDQ_PRIVACY_NO_STEELE_COPYRIGHT     => 'noSteeleCopyright', // 1/0
            SettingACL::DDQ_PRIVACY_FOOTER_LINK             => 'footerLink', // url (replaces copyright)
            SettingACL::DDQ_PRIVACY_FOOTER_LINK_CUSTOM_TEXT => 'footerText', // text (text if link or just plain text)
        ];
        $settings = (new SettingACL($tenantID))->getGroupSettings(SettingACL::DDQ_PRIVACY_SETTINGS_GROUP);
        $rtn = [];
        foreach ($settings as $s) {
            if (!array_key_exists($s->id, $settingIDmap)) {
                continue;
            }
            $rtn[$settingIDmap[$s->id]] = $s->config->value;
        }
        return $rtn;
    }

    /**
     * Log into a DDQ via JWT for Forms 2.0 integration
     *
     * @return void
     */
    private function ajaxLoginByJWT()
    {
        $token = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'token', ''));
        $decoded = null;
        // No token to decode.
        if (empty($token)) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorMsg = 'No token provided';
            $this->jsObj->ErrorTitle = 'No integration token was provided';
            return;
        }

        try {
            // Decode the JWT to authenticate user session for Forms
            $decoded = JWT::decode($token, $_ENV['nodeJWTSecret'], ['HS256']);
        } catch (\Exception $e) {
            $this->app->log->error($e->getMessage());
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorMsg = 'Invalid token';
            $this->jsObj->ErrorTitle = 'Unable to decode supplied token';
        }
        // If JWT was decoded attempt to log into the form or forms list.
        if ($decoded && is_object($decoded)) {
            $this->validateFormJWTContents($decoded);
            $dbName = $this->app->DB->getClientDB($decoded->client);
            $sql = "SELECT passWord FROM {$dbName}.ddq "
                . "WHERE id = :id AND clientID = :clientID AND loginEmail = :loginEmail";
            $pw = $this->app->DB->fetchValue(
                $sql,
                [
                    ':id'         => $decoded->ddq,
                    ':clientID'   => $decoded->client,
                    ':loginEmail' => $decoded->loginEmail
                ]
            );
            // If we have a password, attempt the login.
            if ($pw) {
                $this->sharedLogin($decoded->loginEmail, $pw, false, true);
            } else {
                $this->jsObj->Result = 0;
                $this->jsObj->ErrorMsg = 'Invalid login';
                $this->jsObj->ErrorTitle = 'No intake form found';
            }
        } else {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorMsg = 'Invalid token';
            $this->jsObj->ErrorTitle = 'Unable to decode supplied token';
        }
    }

    /**
     * Validates the JWT contents for Forms 2.0 automatic login
     *
     * @param object $decoded decrypted JWT contents as an object
     *
     * @return void
     */
    private function validateFormJWTContents($decoded)
    {
        if (!isset($decoded->ddq) || !isset($decoded->client) || !isset($decoded->loginEmail)) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrorMsg = 'Invalid token';
            $this->jsObj->ErrorTitle = 'Missing parameters in supplied token';
        }
    }

    /**
     * Check to determine if tenant has OneTime Access link feature enabled
     *
     * @param int $tenantID tenantID
     *
     * @return bool
     *
     * @throws \Exception
     */
    public static function hasOneTimeAccessLinkFeature($tenantID)
    {
        return (new TenantFeatures($tenantID))->tenantHasFeature(
            FeatureACL::DDQ_ONETIME_ACCESS_LINK,
            FeatureACL::APP_TPM
        );
    }

    /**
     * Create One-Time assess link for use in email
     *
     * @note  This particular method is NOT used. See its legacy equivalent.
     * @see   Legacy: cms/includes/php/Controllers/TPM/IntakeForms/Legacy/Login/IntakeFormLogin.php
     *
     * @param int    $Id       ddq.id
     * @param int    $clientID ddq.clientID
     * @param string $loginID  ddq.loginEmail
     * @param string $ddqLink  ddq url
     *
     * @return string
     */
    public static function createOneTimeDdqAccessLink($Id, $clientID, $loginID, $ddqLink)
    {
        return sprintf(
            '%s?%s=%s',
            $ddqLink,
            self::$accessLinkParamKey,
            IntakeFormLogin::encryptOneTimeAuthHash($Id, $clientID, $loginID)
        );
    }

    /**
     * Create One-Time assess link for Admins - for use in DDQ Admin access
     *
     * @note  This particular method is NOT used. See its legacy equivalent.
     * @see   Legacy: cms/includes/php/Controllers/TPM/IntakeForms/Legacy/Login/IntakeFormLogin.php
     *
     * @param int    $id          ddq.id
     * @param int    $clientID    ddq.clientID
     * @param string $loginID     ddq.loginEmail
     * @param string $ddqLink     ddq access url
     * @param bool   $hasOspForms Does client have Osprey forms tenant features
     *
     * @return array
     */
    public static function createAdminDdqAccessArray($id, $clientID, $loginID, $ddqLink, $hasOspForms)
    {
        return [
            'url' => sprintf(
                '%s',
                $ddqLink
            ),
            'hash' => self::encryptOneTimeAuthHash($id, $clientID, $loginID, true)
        ];
    }

    /**
     * Compile One-Time access hash
     *
     * @note  This particular method is NOT used. See its legacy equivalent.
     * @see   Legacy: cms/includes/php/Controllers/TPM/IntakeForms/Legacy/Login/IntakeFormLogin.php
     *
     * @param int    $id          ddq.id
     * @param int    $clientID    ddq.clientID
     * @param string $loginID     ddq.loginEmail
     * @param bool   $adminAccess Encrypt for admins with timestamp
     *
     * @return string
     */
    public static function encryptOneTimeAuthHash($id, $clientID, $loginID, $adminAccess = false)
    {
        $ddqCaseParams = [
            $id,
            $clientID,
            $loginID
        ];
        if ($adminAccess) {
            // For admins we add time validation
            array_push($ddqCaseParams, date("Y-m-d H:i:s", strtotime("+" . self::$adminAccessValidTime . " hours")));
        }
        return urlencode(Crypt64::encrypt(implode('|', $ddqCaseParams), self::$accessLinkEncryptionKey));
    }

    /**
     * DeCrypt the One-Time access link
     * - link contains caseID|clientID|loginID
     *
     * @param string $encryption Encrypted content
     *
     * @return array|false (caseID, clientID, loginID || timestamp)
     */
    private function decryptOneTimeAuthHash($encryption)
    {
        $decryptionParams = explode(
            '|',
            Crypt64::decrypt($encryption, self::$accessLinkEncryptionKey)
        );
        // If we do not have the proper amount of params return from the link
        if (count($decryptionParams) !== 3 && count($decryptionParams) !== 4) {
            // Invalid Access Link
            return false;
        }

        // Admin links contain a time param
        if (count($decryptionParams) === 4) {
            $this->hasAdminAccessLink = true;
        }
        return $decryptionParams;
    }
}
