<?php
/**
 * ForgotUsername.php
 * Controller for the forgot username page
 */

namespace Controllers\TPM\IntakeForms\Legacy\Login;

use Controllers\Login\Base;
use Models\Ddq;
use Lib\Services\AppMailer;
use Lib\Validation\Validator\MultiRules;

/**
 * Controller class for the forgot username page
 */
class IntakeFormForgotPassword extends Base
{
    /**
     * template path string
     * @var string
     */
    protected $template = "TPM/IntakeForms/Legacy/Login/ForgotPassword.tpl";

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
     * Index method for this controller. This is the corresponding index route for "/intake/legacy/forgot/password"
     *
     * @return string rendered template
     */
    public function index()
    {
        $this->setPageTitle($this->trText['ddq_forgotpass_title']);
        $this->viewModel->email = $this->app->session->get('intake.legacy.forgotPass.emailResetAttempt');
        $this->app->session->set('intake.legacy.forgotPass.emailResetAttempt', "");
        return $this->render($this->template);
    }

    /**
     * post method for this controller. This is the corresponding post route for "/intake/legacy/forgot/password"
     *
     * @return string rendered template
     */
    public function post()
    {
        $this->app->session->set('intake.legacy.forgotPass.emailResetAttempt', $this->request('email'));
        $attempt = (new Ddq($this->app->session->get('tenantID')))->forgotPassword(
            $this->request('email'),
            $this->request('captcha')
        );
        if (!$attempt['succeeded'] && !empty($attempt['errors'])) {
            $this->app->session->flash('error', $attempt['errors']);
        } else {
            $this->app->session->flash('success', $this->trText['after_submit']);
        }
        return $this->redirectToReferrer();
    }

    /**
     * Sets various tenant asset properties for the view
     *
     * @return self returns itself for chaining.
     */
    public function setTenantAssets()
    {
        $this->viewModel->isDdq = true;
        $this->viewModel->afterSubmit = $this->trText['after_submit'];
        $this->viewModel->tenantID = $this->app->session->get('tenantID');
        $this->viewModel->tenantLogo = $this->app->session->get('tenantLogo');
        $this->viewModel->colorScheme = $this->app->session->get('siteColorScheme');
        $this->viewModel->loginPath = $this->app->session->get('inFormLoginPath');
        $this->viewModel->isRTL = \Xtra::isRTL($this->app->session->get('languageCode'));
        return $this;
    }

    /**
     * Populate $this->trText translation text array
     *
     * @return $this
     */
    protected function getTranslations()
    {
        $app = \Xtra::app();
        $this->trText = $app->trans->groups(['ddq_login_misc', 'buttons', 'ddq_forgotpass']);
        $this->viewModel->trText = $this->trText;
        return $this;
    }
}
