<?php
/**
 * Model for the "CaseMgt" sub-tabs
 */

namespace Models\TPM\CaseMgt;

use Models\Globals\MultiTenantAccess;

/**
 * Class CaseMgtNavBarData determines if access to its sub-tabs is allowed based upon unique conditions.
 *
 * @keywords case management tab, case management navigation
 */
#[\AllowDynamicProperties]
class CaseMgtNavBarData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var string Client database name
     */
    protected $clientDB = null;

    /**
     * @var object Database instance
     */
    protected $DB = null;

    /**
     * Init class constructor
     *
     * @param integer $clientID Client ID
     *
     * @return void
     */
    public function __construct(protected $clientID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
    }

    /**
     * Determine if access is allowed to the Case Folder tab
     *
     * @param integer $caseID Case ID
     *
     * @return boolean $allowAccess True if access is allowed, else false
     */
    public function allowCaseFolderAccess($caseID)
    {
        if (empty($caseID)) {
            return false;
        }
        // make sure the caseID belongs to the client and not someone tampering
        // with the ID to gain access to another case
        $sql = "SELECT id FROM {$this->clientDB}.cases WHERE id = :caseID AND clientID = :clientID LIMIT 1";
        $params = [':caseID' => $caseID, ':clientID' => $this->clientID];
        return ($caseID == $this->DB->fetchValue($sql, $params));
    }

    /**
     * Determine if user has access to multi-tenant access
     *
     * @return boolean
     */
    public function allowUberSearch()
    {
        // Must not have 3P enabled, must be configured for multi-tenant access
        // and app must be either ADMIN or TPM
        return (!$this->app->ftr->tenantHas(\FEATURE::TENANT_TPM)
            && ($this->app->ftr->appIsTPM() || $this->app->ftr->appIsADMIN())
            && (new MultiTenantAccess())->hasMultiTenantAccess($this->clientID)
        );
    }
}
