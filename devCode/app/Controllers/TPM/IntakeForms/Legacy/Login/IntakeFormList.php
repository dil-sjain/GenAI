<?php
/**
 * Site Audit Log controller
 */

namespace Controllers\TPM\IntakeForms\Legacy\Login;

use Controllers\Login\Base;
use Lib\Crypt\Crypt64;
use Lib\Legacy\IntakeFormTypes;
use Lib\LoginAuth;
use Lib\Traits\AjaxDispatcher;
use Lib\Validation\Validate;
use Models\Ddq;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\Subscriber;
use Models\TPM\IntakeForms\IntakeFormSecurityCodes;
use Models\TPM\IntakeForms\Legacy\InFormLoginData;

/**
 * IntakeFormLogin controller
 *
 * @keywords intake form, login, ddq
 */
class IntakeFormList extends Base
{

    use AjaxDispatcher;

    public const PATH_INTAKE_FORM_UNAUTHORIZED = '/intake/legacy/';

    /**
     * template path string
     * @var string
     */
    protected $template = 'TPM/IntakeForms/Legacy/Login/List.tpl';

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var object \Xtra::app()->log instance
     */
    private $logger = null;

    /**
     * @var object App Session instance
     */
    protected $session = null;

    /**
     * @var array intake forms
     */
    protected $intakeForms = null;


    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->app = \Xtra::app();
        $this->session = $this->app->session;
        $this->logger = \Xtra::app()->log;
        $this->authorization();
    }



    /**
     * Validates authorization data passed from the login, and bounces them out if there's any funny business.
     *
     * @return void
     */
    private function authorization()
    {
        $keys = [
            'tenantID',
            'inFormLoginPath',
            'userEmail',
            'passWord',
            'languageCode',
            'inFormLoginAuthToken',
            'inFormLoginAuth'
        ];
        foreach ($keys as $key) {
            // Any required session vars missing?
            if (!$this->session->has($key)) {
                $this->unauthorized();
            }
            ${$key} = $this->session->get($key);
        }

        // Encrypted session vars match up with the token?
        if (md5($inFormLoginAuthToken . $userEmail . $passWord) != $inFormLoginAuth) {
            $this->unauthorized();
        }

        // Intake forms for the user, not including Forms 2.0?
        if (!$this->intakeForms = (new Ddq($tenantID))->getIntakeFormsByUser($userEmail, true)) {
            $this->unauthorized();
        }
    }




    /**
     * initialize the page
     *
     * @return void
     */
    public function initialize()
    {
        $this->session->set('ddqPgReferer', true);
        $this->getTranslations()->setTenantAssets()->setIntakeFormsAssets();
        $this->render($this->template);
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
        $this->viewModel->trText = $this->app->trans->groups(
            ['ddq_showrelated', 'buttons', 'common', 'ddq_login_misc']
        );
        return $this;
    }





    /**
     * AJAX method that sets session data, regenerates the session and loads the intake form
     *
     * @return void
     */
    private function ajaxLoadIntakeForm()
    {
        $inFormRspnsID = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'inFormRspnsID', ''));
        $inFormRspns = (new Ddq($this->session->get('tenantID')))->getInFormRspnsLgcy(
            $inFormRspnsID,
            $this->session->get('userEmail')
        );
        if (!$inFormRspns) {
            $redirectURL = self::PATH_INTAKE_FORM_UNAUTHORIZED;
        } else {
            /**
             * Legacy's DDQ pages (ddq2, ddq3, ddq4, etc.) requires $_SESSION['ddqRow']
             * to contain an index array of values (arriving via $inFormRspns). This is
             * why you see variables being set to some indexed array items here, as opposed
             * to associative array items. Just giving Legacy her preeeecccccioussss....
             */
            $this->session->set('inFormRspnsID', $inFormRspnsID);
            $this->session->set('inFormRspns', $inFormRspns);
            $this->session->set('inFormType', $inFormRspns['107']);
            $this->session->set('inFormVersion', $inFormRspns['126']);

            $dataParams = [];
            if ($this->session->has('inFormType')) {
                $dataParams['inFormType'] = $this->session->get('inFormType');
            }
            if ($this->session->has('inFormVersion')) {
                $dataParams['inFormVersion'] = $this->session->get('inFormVersion');
            }
            if ($this->session->has('languageCode')) {
                $dataParams['languageCode'] = $this->session->get('languageCode');
            }
            $loginDataModel = new InFormLoginData($this->session->get('tenantID'), $dataParams);

            // Put the security code into session
            $scAttributes = [
                'caseType' => $this->session->get('inFormType'),
                'clientID' => $this->session->get('tenantID')
            ];
            if ($secCodeRow = (new IntakeFormSecurityCodes())->findByAttributes($scAttributes)) {
                $this->session->set('secCode', $secCodeRow->get('ddqCode'));
                $this->session->set('inFormLoginPath', '/intake/legacy/sc/' . $this->session->get('secCode'));
            }
            // Ensure the language code is supported. If not, give them the King's English
            if (!$loginDataModel->isOnlineQuestLang($this->session->get('languageCode'))) {
                $this->session->set('languageCode', 'EN_US');
                $dataParams['languageCode'] = $this->session->get('languageCode');
                $loginDataModel = new InFormLoginData($this->session->get('tenantID'), $dataParams);
            }

            /**
             * determine correct ddq2 destination
             * @todo When ddq's are more fully refactored this logic SHOULD be deprecated
             */
            $path_dir = '/cms/ddq/';
            if ($this->session->get('inFormType') == IntakeFormTypes::SELECT_DUE_DILIGENCE_TYPE
                || $this->session->get('inFormType') == IntakeFormTypes::DUE_DILIGENCE_HCPDI
                || $this->session->get('inFormType') == IntakeFormTypes::DUE_DILIGENCE_HCPDI_RENEWAL
            ) {
                $path_dir = '/cms/ddq_hcp/';
            }
            $redirectURL = $path_dir . 'ddq2.php';

            // Store base text in session vars
            $this->session->set('baseText', $loginDataModel->getBaseText());

            // set up secured session vars
            $this->session->set('inDDQ', true);
            $this->session->secureSet('inDDQ', true);
            (new LoginAuth())->generateAdvisoryHash('thirdparty');
            $this->session->forget('inFormLoginAuthToken');
            $this->session->forget('inFormLoginAuth');

            /**
             * @todo Kill 'lgcyPgAuth' arg once intake form landing page (ddq2.php) is refactored.
             */
            $this->session->secureSet('lgcyPgAuth', $this->session->getToken());
            $this->session->regenerateSession();
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [['url' => $redirectURL]];
    }




    /**
     * Sets properties for the view pertaining to the intake form
     *
     * @return $this returns self for chaining.
     */
    protected function setIntakeFormsAssets()
    {
        $this->viewModel->intakeForms = (new InFormLoginData($this->session->get('tenantID')))->getIntakeFormAssets(
            $this->intakeForms,
            $this->session->get('languageCode')
        );
        return $this;
    }




    /**
     * Sets properties for the view pertaining to the tenant
     *
     * @return $this returns self for chaining.
     */
    protected function setTenantAssets()
    {
        $this->viewModel->loginPath = $this->session->get('inFormLoginPath');
        $this->viewModel->tenantLogo = $this->session->get('tenantLogo');
        $this->viewModel->colorScheme = $this->session->get('ddqColorScheme');
        $this->setPageTitle($this->viewModel->trText['ddq_showrel_page_title']);
        return $this;
    }



    /**
     * Somebody's messed up. Bounce!
     *
     * @return void
     */
    private function unauthorized()
    {
        \Xtra::app()->redirect(self::PATH_INTAKE_FORM_UNAUTHORIZED);
    }
}
