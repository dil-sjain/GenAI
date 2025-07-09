<?php
/**
 * Management Tools Widget
 *
 * @package Controllers\TPM\Dashboard\Subs
 *
 * @keywords dashboard, widget
 */

namespace Controllers\TPM\Dashboard\Subs;

use Models\TPM\Dashboard\Subs\ManagementTools as mManagementTools;
use Models\TPM\Dashboard\DashboardData;

/**
 * Class ManagementTools
 */
#[\AllowDynamicProperties]
class ManagementTools extends DashWidgetBase
{
    /**
     * The ttTrans tooltip codekey
     *
     * @var string
     */
    protected $ttTrans = 'mgmt_tools';

    /**
     * ManagementTools constructor.
     *
     * @param int $tenantID Current client ID
     *
     * @return null
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID);

        $this->app = \Xtra::app();
        $this->m     = new mManagementTools($tenantID);
        $this->files = [
            'managementTools.html',
            'ManagementTools.css',
            'ManagementTools.js',
            'AddProfile.js',
            'AddProfile.css',
            'AddCase.js',
            'AddCase.css',
            ];
    }

    /**
     * Get data for widget
     *
     * @return mixed
     */
    public function getDashboardSubCtrlData()
    {
        $ftr = $this->app->ftr;
        /**
         * View ATP icon
         *
         * @dev: legacy logic came from shell_sidebar.php & dashboard.sec & refactored from SyncLegacySession.php
         */
        $allowThirdPartySearch = (
            $ftr->has(\Feature::TENANT_TPM) &&
            $ftr->has(\Feature::THIRD_PARTIES) &&
            (
                !$ftr->appIsSP() && !$ftr->appIsSPLITE()
            )
        );
        $allowFATP = (
            $allowThirdPartySearch &&
            (
                $this->app->session->get('searchPerms.userGlobalAlways', false) ||
                $this->app->session->get('searchPerms.userRestrictedGlobal', false)
            )
        );
        $gsrchAuth = $this->app->session->getToken();

        $dat = $this->m->getData(
            $this->app->ftr->legacyUserType,
            $this->app->session->get('userSecLevel')
        );
        $dat[0]['allowFATP'] = $allowFATP;
        $dat[0]['gsrchAuth'] = $gsrchAuth;
        $dat[0]['description'] = $this->desc;

        return $dat;
    }

    /**
     * Persist widget state; updates the state column on the dashboardWidget table
     *
     * @param DashboardData/null $model uses updateStateColumn() on DashboardData model.
     * @param null               $data  data to persist.
     *
     * @return null
     */
    public function persistWidgetState(DashboardData $model = null, $data = null)
    {
        $jsData = json_encode(['expanded' => $data->expanded]);

        $model->updateStateColumn($data->subCtrlClassName, $jsData);
    }
}
