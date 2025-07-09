<?php
/**
 * DdqChangePassword.php
 * Controller for the DDQ Change Password page
 */

namespace Controllers\TPM\IntakeForms\Legacy\Login;

use Controllers\Login\Base;
use Models\Ddq;
use Models\ThirdPartyManagement\DdqLoginAttempts;
use Models\ThirdPartyManagement\Subscriber;
use Lib\Validation\Validator\MultiRules;

/**
 * Controller class for the DDQ Change Password page
 */
class IntakeFormChangePassword extends Base
{
    /**
     * template path string
     * @var string
     */
    protected $template = "TPM/IntakeForms/Legacy/Login/ChangePassword.tpl";

    protected $remember = [
        'user_id',
        'cur_pw',
        'new_pw',
        'new_pw2'
    ];

    /**
     * this is a construct override so that we can check the cid set by legacy
     *
     * @return void nuthin'
     */
    public function __construct()
    {
        parent::__construct();
        $this->app->session->set('passwordPgReferer', true);
        $validTenantID = ($this->app->session->has('tenantID')
            && ($this->app->session->get('tenantID') > 0));
        if (!$validTenantID) {
            $loginPath = ($this->app->session->has('inFormLoginPath'))
                ? $this->app->session->get('inFormLoginPath')
                : '/intake/legacy/';
            $this->redirectTo($loginPath);
        }
        $this->getTranslations()->setTenantAssets();
    }

    /**
     * Populate $this->trText translation text array
     *
     * @return $this
     */
    protected function getTranslations()
    {
        $app = \Xtra::app();
        $this->trText = $app->trans->groups(
            [
            'ddq_login_err', 'ddq_login_misc', 'ddq_changepass', 'ddq_changepass_err', 'buttons'
            ]
        );
        $this->viewModel->trText = $this->trText;
        return $this;
    }

    /**
     * Index method for this controller. This is the corresponding index route for "/intake/legacy/user/password"
     *
     * @return string rendered template
     */
    public function index()
    {
        $this->setPageTitle($this->trText['ddq_chgpass_title']);

        // sets all the remembered items to be used in view then clear them
        $this->getRetainedValues();

        return $this->render($this->template);
    }

    /**
     * Post method for this controller. This is the corresponding post route for "/intake/legacy/user/password"
     *
     * @return string rendered template
     */
    public function post()
    {
        $this->setRetainedValues($this->request()); // Sets all remembered values
        $attempt = (new Ddq($this->app->session->get('tenantID')))->changePassword(
            $this->request('user_id'),
            $this->request('cur_pw'),
            $this->request('new_pw'),
            $this->request('new_pw2'),
            $this->request('code')
        );
        if (!$attempt['succeeded'] && !empty($attempt['errors'])) {
            $this->app->session->flash('error', $attempt['errors']);
        }
        return $this->redirectToReferrer();
    }

    /**
     * gets the preset values for inputs and puts them into the view values
     *
     * @return self returns itself for chaining
     */
    public function getRetainedValues()
    {
        foreach ($this->remember as $item) {
            $this->viewModel->{$item} = $this->app->session->get("intake.legacy.changePass.remember.{$item}");
            $this->app->session->set("intake.legacy.changePass.remember.{$item}", "");
        }
        return $this;
    }

    /**
     * sets the preset values to be remembered and used by the view later
     *
     * @param array $request [description]
     *
     * @return self returns itself for chaining
     */
    public function setRetainedValues($request = [])
    {
        foreach ($this->remember as $item) {
            $this->app->session->set("intake.legacy.changePass.remember.{$item}", $request[$item]);
        }
        return $this;
    }

    /**
     * Sets various tenant asset properties for the view
     *
     * @return self returns itself for chaining.
     */
    public function setTenantAssets()
    {
        $this->viewModel->tenantID = $this->app->session->get('tenantID');
        $this->viewModel->tenantLogo = $this->app->session->get('tenantLogo');
        $this->viewModel->colorScheme = $this->app->session->get('siteColorScheme');
        $this->viewModel->loginPath = $this->app->session->get('inFormLoginPath');
        $this->viewModel->isRTL = \Xtra::isRTL($this->app->session->get('languageCode'));
        return $this;
    }
}
