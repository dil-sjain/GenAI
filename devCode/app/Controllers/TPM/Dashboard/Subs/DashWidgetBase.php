<?php
/**
 * Base abstract controller for dashboard widgets
 *
 * @keywords dashboard, widget
 */

namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\DashboardData;
use Models\ThirdPartyManagement\Cases;

/**
 * Class DashWidgetBase
 *
 * @package Controllers\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
abstract class DashWidgetBase implements DashInterface
{
    /**
     * @var array Files to be used/included by widget, parsed based on file extension
     */
    protected $files = [];

    /**
     * @var \Slim\Slim Current application instance
     */
    protected $app = null;
    
    /**
     * @var object Instance of app logger
     */
    protected $log = null;

    /**
     * @var int Current tenant ID
     */
    protected $tenantID;

    /**
     * @var Object Data access layer for widget
     */
    protected $m;

    /**
     * @var string Current users email address
     */
    protected $authUserEmail;

    /**
     * @var int Current users ID
     */
    protected $authUserID;


    /**
     * @var sstring Widget tooltip desc
     */
    protected $desc = null;

    /**
     * @var string Widget desc codekey for tooltip
     */
    protected $ttTrans = null;

    /**
     * DashWidgetBase constructor.
     *
     * @param int $tenantID ID of the current tenant user is logged in as
     */
    public function __construct($tenantID)
    {
        $this->app      = \Xtra::app();
        $this->log      = $this->app->log;
        $this->tenantID = (int)$tenantID;
        $this->clientID = $this->tenantID;
        $this->setDescription();
    }

    /**
     * Check if there are any reasons that this widget should not be loaded.
     *
     * @return bool
     */
    public function noObstacles()
    {
        return true;
    }

    /**
     * Return array with file list required by widget
     *
     * @return array
     */
    public function getFilesList()
    {
        return $this->files;
    }

    /**
     * Get tooltip description
     *
     * @return string
     */
    public function getDescription()
    {
        if (isset($this->desc)) {
            return $this->desc;
        }
    }

    /**
     * This method sends state data passed in from the widget to be persisted in the DB. It can be overridden by the
     * widget controller if additional data needs to be stored or data needs to be modified before being stored. It will
     * take the
     *
     * @param DashboardData|null $model uses updateStateColumn() on DashboardData model.
     * @param object             $data  data to persist.
     *
     * @return bool|string True if successful, otherwise an error string.
     */
    public function persistWidgetState(DashboardData $model = null, $data = null)
    {
        if (is_object($data)) {
            $jsData = json_encode(['expanded' => $data->expanded]);
        } else {
            return 'Unable to update widget data.';
        }

        $model->updateStateColumn($data->subCtrlClassName, $jsData);

        return true;
    }

    /**
     * Get case type client list (without specifying a case id) as you have
     * to in casefolder class.
     *
     * @return array
     */
    protected function getCaseTypeClientList()
    {
        $db = $this->app->DB->getClientDB($this->tenantID);
        $sqlhead = "SELECT caseTypeID, name FROM $db.caseTypeClient WHERE";
        $sqltail = "AND investigationType = 'due_diligence' ORDER BY caseTypeID ASC";
        $params = [':clientID' => $this->tenantID];
        if (!($scopes = $this->app->DB->fetchKeyValueRows("$sqlhead clientID=:clientID $sqltail", $params))) {
            $scopes = $this->app->DB->fetchKeyValueRows("$sqlhead clientID='0' $sqltail");
        }
        return [(string)Cases::DUE_DILIGENCE_INTERNAL => "Internal Review"] + $scopes;
    }

    /**
     * set tt description
     *
     */
    private function setDescription()
    {
        if (isset($this->ttTrans) && !isset($this->desc)) {
            $this->desc = $this->app->trans->groups(['dashboard_widget_tt'])[$this->ttTrans];
        }
    }
}
