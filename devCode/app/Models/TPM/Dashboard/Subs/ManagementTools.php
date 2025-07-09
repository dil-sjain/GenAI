<?php
/**
 * Management Tools Widget
 *
 * @keywords dashboard, widget
 */

namespace Models\TPM\Dashboard\Subs;

use Lib\Legacy\UserType;

/**
 * Class ManagementTools
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ManagementTools
{
    /**
     *
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    private $DB = null;

    /**
     * @var int tenantID
     */
    private $tenantID = null;

    /**
     * Init class constructor
     *
     * @param int $tenantID Current tenant ID
     *
     * @return none
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;
        $this->tenantID = $tenantID;
        $this->session  = $this->app->session;
    }

    /**
     * Get widget specific data
     *
     * @param integer $authUserType User type
     * @param integer $userSecLevel User sec lev
     *
     * @return mixed
     */
    public function getData($authUserType, $userSecLevel)
    {
        \Xtra::requireInt($authUserType);
        \Xtra::requireInt($userSecLevel);

        $ftr = \Xtra::app()->ftr;

        $date2 = date('Y-m-d');
        $date1 = date('Y-m-d', mktime(0, 0, 0, date("m")-1, date("d"), date("Y")));

        /**
         * Add Profile & Case icons
         */
        $addProfile = false;
        $addCase    = false;

        if ($authUserType > UserType::VENDOR_ADMIN
            && $userSecLevel > UserType::USER_SECLEVEL_RO
        ) {
            $addProfile = (
                $ftr->has(\Feature::TENANT_TPM) &&
                $ftr->has(\Feature::THIRD_PARTIES) &&
                $ftr->has(\Feature::TP_PROFILE_ADD)
            );

            $addCase = (
                $ftr->has(\Feature::CASE_MANAGEMENT) &&
                $ftr->has(\Feature::CASE_ADD)
            );
        }

        $jsData[0] = [
            'sitePath'   => \Xtra::conf('cms.sitePath'),
            'allowFATP'  => null, //session-related; added in controller
            'accMetrics' => $ftr->has(\Feature::ANALYTICS),
            'addProfile' => $addProfile,
            'addCase'    => $addCase,
            'gsrchAuth'  => null,
            'date1'      => $date1,
            'date2'      => $date2,
            'trText'     => $this->app->trans->group('add_profile_dialog'),
            'description' => null,
            'multiTenant' => $this->isMultiTenant()
        ];

        return $jsData;
    }

    /**
     * @return boolean true if the subscriber has multi-tenant access enabled
     */
    public function isMultiTenant()
    {
        $result = $this->DB->fetchValue(
            "SELECT tenantID FROM {$this->DB->globalDB}.g_multiTenantAccess WHERE tenantID = :tenantID LIMIT 1",
            [
                ':tenantID' => $this->tenantID
            ]
        );

        return ($result == $this->tenantID);
    }
}
