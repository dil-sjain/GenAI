<?php
/**
 * Nav class for application tab state management
 */

namespace Controllers\TPM\Base;

use Lib\Legacy;
use Lib\Navigation\Navigation;
use Lib\Traits\AjaxDispatcher;

/**
 * Class Nav
 *
 * @keywords navigation state, navigation state management,
 */
#[\AllowDynamicProperties]
class NavState
{
    use AjaxDispatcher;

    /**
     * @var object Session variable
     */
    protected $session = null;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $app       = \Xtra::app();
        $this->app = $app;
    }

    /**
     * Ajax method to update the navigation state based upon the tab id passed in the POST
     *
     * @return void
     */
    private function ajaxUpdateNavState()
    {
        $id       = $this->app->clean_POST['id'];
        $redirect = $this->app->clean_POST['redir'];

        $nav = new Navigation();
        $result = $nav->updateStateByNodeId($id);

        if ($result) {
            $this->jsObj->Result = 1;
            if (!empty($redirect)) {
                $this->jsObj->Redirect = $redirect;
            }
        } else {
            $this->jsObj->ErrTitle = 'Operation Terminated';
            $this->jsObj->ErrMsg = 'Unable to update Navigation state';
        }
    }
}
