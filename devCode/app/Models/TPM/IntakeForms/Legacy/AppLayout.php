<?php
/**
 * Provide access to the data required by Main app layout
 */

namespace Models\TPM\IntakeForms\Legacy;

use Models\SP\ServiceProvider;
use Models\User;
use Lib\Legacy\Misc;
use Lib\SearchFormsSetup;

/**
 * Retrieve data use on Main app layout
 */
#[\AllowDynamicProperties]
class AppLayout
{
    protected $tenantID = null;
    protected $tenantDB = null;
    protected $authUserID = null;
    protected $DB = null;
    protected $app = null;

    /**
     * Capture tenantID for other methods
     *
     * @param integer $tenantID active tenant
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
        \Xtra::requireInt($tenantID, 'tenantID must be an integer value');
        $this->tenantID = $tenantID;
        $this->tenantDB = $this->DB->getClientDB($this->tenantID);
    }


    /**
     * Fetch subscriber info for the view
     *
     * @todo This will gradually fill out throughout the refactor of DDQ
     *
     * @return array $info
     */
    public function getSubscriberSkelInfo()
    {
        return [];
    }
}
