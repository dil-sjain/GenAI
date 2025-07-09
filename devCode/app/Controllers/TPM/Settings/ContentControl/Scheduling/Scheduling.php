<?php
/**
 * Site Scheduling controller
 */

namespace Controllers\TPM\Settings\ContentControl\Scheduling;

use Models\TPM\Settings\ContentControl\Scheduling\SchedulingData as SchedulingModel;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Controllers\Widgets;

/**
 * Scheduling controller
 *
 * @keywords site, Scheduling, settings
 */
#[\AllowDynamicProperties]
class Scheduling extends Base
{

    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/Scheduling/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Scheduling.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var boolean Environment variable for AWS
     */
    protected $awsEnabled = false;

    /**
     * Sets Scheduling template to view
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app  = \Xtra::app();
        $this->awsEnabled = filter_var(getenv('AWS_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
    * displaySchedulingData - display scheduling data
    *
    * @return void
    */
    private function ajaxDisplaySchedulingData()
    {
        $clientID = $this->app->session->get('clientID');

        $scheduling = new SchedulingModel();
        $SchedulingData = $scheduling->displaySchedulingData($clientID);

        $this->setViewValue('schedulingData', "$SchedulingData");

        // get the fully rendered html from the template
        $html = $this->app->view->fetch($this->getTemplate(), $this->getViewValues());

        $this->jsObj->Args   = ['html' => $html];
        $this->jsObj->SchedulingData = json_decode($SchedulingData);
        $this->jsObj->Result = 1;
    }

    /**
    * changeschedulingdata - change in status of process
    *
    * @return void
    */
    private function ajaxChangeSchedulingData()
    {
        $clientID = $this->app->session->get('clientID');

        $scheduling = new SchedulingModel();
        $SchedulingData = $scheduling->changeSchedulingData($clientID);
        $this->jsObj->SchedulingData = json_decode($SchedulingData);
        $this->jsObj->Result = 1;
    }
}
