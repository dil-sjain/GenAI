<?php
/**
 * File containing class PasswordExpire
 *
 * @keywords password, expire, security, usertype
 *
 */
namespace Controllers\TPM\Settings\Security\PasswordExpire;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\Settings\Security\PasswordExpireData\PasswordExpireData;
use Lib\Legacy\UserType;

/**
 * Class Provides functionality involved with the
 * setting password expiration interval under the security
 * tab under the settings tab.
 *
 */
#[\AllowDynamicProperties]
class PasswordExpire extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     *
     */
    protected $tplRoot = 'TPM/Settings/Security/PasswordExpire/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'PasswordExpire.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     *
     * @var object Reference to model
     */
    private $model;

    /**
     * Set up password expiration control
     *
     * @param integer $clientID clientProfile.id
     *
     * @return void
     */
    public function __construct($clientID)
    {
        \Xtra::requireInt($clientID);
        $this->app  = \Xtra::app();


        parent::__construct($clientID);


        $this->clientID = $clientID;
        // todo: Get rid of usertype, which the Features stuff should eventually obselece
        $isSP= ($this->app->session->get('authUserType') == UserType::VENDOR_ADMIN);

        $this->model = new PasswordExpireData(
            $this->clientID,
            [
            'isSP'   => $isSP,
            'userId' => $this->app->session->get('authUserID')
            ]
        );
    }

    /**
     * obtain ajax data and create html string for presentation
     *
     * @return void
     */
    protected function ajaxInitialize()
    {
        $this->setViewValue('pwExDays', $this->model->getPwex());
        $html = $this->app->view->render(
            $this->getTemplate(),
            $this->getViewValues()
        );

        $this->jsObj->Args = (object)[
            'html' => $html
        ];
        $this->jsObj->Result = 1;
    }

    /**
     * access model to update the password expiration interval of
     * the tenant of the currently logged in user
     *
     * @return void
     */
    protected function ajaxUpdatePwex()
    {
        $this->jsObj->Result = 0;
        try {
            $newPwex = \Xtra::arrayGet($this->app->clean_POST, 'newPwex', null);
            if (!is_null($newPwex)) {
                $changedPwex = $this->model->updatePwex($newPwex);
                $this->jsObj->Args = [
                    'pwex' => $changedPwex
                ];
                $this->jsObj->Result = 1;
            }
        } catch (\Exception $ex) {
            $this->jsObj->ErrTitle = $this->app->trans->codeKey('error_invalid_input');
            $this->jsObj->ErrMsg = $ex->getMessage();
        }
    }

    /**
     * access model to retreive the password expiration interval of
     * the tenant of the currently logged in user
     *
     * @return void
     */
    protected function ajaxGetPwex()
    {
        $this->jsObj->Args = [
            'pwex' => $this->model->getPwex()
        ];
        $this->jsObj->Result = 1;
    }
}
