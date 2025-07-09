<?php
/**
 * Invite Menu controller
 */

namespace Controllers\TPM\IntakeForms\InviteMenu;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\IntakeForms\InviteMenuData;
use Models\TPM\CaseMgt\CaseFolder\CaseFolderData;

/**
 * InviteMenu controller
 *
 * @keywords invite menu, intake invitation, intake invite form
 */
#[\AllowDynamicProperties]
class InviteMenu extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = null;

    /**
     * @var string Base template for View
     */
    protected $tpl = null;

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var int
     */
    protected $clientID;

    /**
     * @var int
     */
    protected $userID;

    /**
     * Constructor gets model instance to interact with JIRA API
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app = \Xtra::app();
        //$this->DB = $this->app->DB;
        $this->clientID = $clientID;
        $this->userID = $this->session->get('authUserID');
    }

    /**
     * Sets required properties to display the Intake Invitation Menu.
     *
     * TO-DO: This ajax request was built for the Case Folder header
     *  but can be used for other Intake Invitation Menu popups.
     *  All that is needed is to modify the code to be able to use
     *  different $inviteControl variables.
     *
     * @return void Sets jsObj
     */
    private function ajaxInviteMenu()
    {
        $this->tplRoot = 'Resources/IntakeInvitationMenu/';
        $this->tpl = 'IntakeInvitationMenu.tpl';

        $caseID = 0;
        if ($this->app->session->has('currentID.case')) {
            $caseID = $this->app->session->get('currentID.case');
        }
        $caseFolderData = new CaseFolderData($this->clientID, $caseID);
        $inviteControl = $caseFolderData->getInviteControl();

        $intakeMenu = new InviteMenuData($this->clientID);
        $this->setViewValue('inviteMenuRows', $intakeMenu->getRows($inviteControl));


        // logic to change title
        $this->jsObj->Args = [
            'title' => 'Intake Form Invitation Menu',
            'html'  => $this->app->view->fetch($this->getTemplate(), $this->getViewValues())
        ];
        $this->jsObj->Result = 1;
    }
}
