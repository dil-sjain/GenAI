<?php
/**
 * Define the "Settings" sub-tabs configuration
 */

namespace Controllers\TPM\Settings;

use Lib\Navigation\Navigation;
use Lib\Support\Xtra;

/**
 * Class SettingsNavBarTabs defines the configuration of the Settings sub-tabs
 *
 * @keywords settings, settings tab, settings navigation, settings tab configuration
 */
#[\AllowDynamicProperties]
class SettingsNavBarTabs
{
    /**
     * @var array Contains all the tabs configuration for the nav bar
     */
    public static $tabs = [
        'UserProfile' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'edituser',
            'feature'  => \Feature::SETTINGS_USER_PROFILE,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/usrPrfl',
            'label'    => 'tab_User_Profile',
            'toolTip'  => 'tab_goto_User_Profile'
        ],
        'CompanyId' => [
            'index'    => 2,
            'sync'     => 'coIdent',
            'feature'  => \Feature::SETTINGS_COMPANY_ID,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'url'      => 'tpm/cfg/cmpnyId',
            'ajaxOp'   => 'initialize',
            'label'    => 'tab_Company_Identity',
            'toolTip'  => 'tab_goto_Company_Identity'
        ],
        'Architecture' => [
            'index'    => 3,
            'sync'     => 'arch',
            'feature'  => \Feature::SETTINGS_ARCHITECTURE,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/arch',
            'label'    => 'tab_Architecture',
            'toolTip'  => 'tab_goto_Architecture'
        ],
        'UserMgt' => [
            'index'    => 4,
            'sync'     => 'userMng',
            'feature'  => \Feature::SETTINGS_USER_MGMT,
            'url'      => 'cms/client/clientadmin.sec?tname=userMng', // Delta route: 'tpm/cfg/usrMgt'
            'label'    => 'tab_User_Management',
            'toolTip'  => 'tab_goto_User_Management'
        ],
        'ContentCtrl' => [
            'index'    => 6,
            'sync'     => 'contentctrl',
            'feature'  => \Feature::SETTINGS_CONTENT_CTRL,
            'url'      => 'tpm/cfg/cntCtrl',
            'label'    => 'tab_Content_Control',
            'toolTip'  => 'tab_goto_Content_Control'
        ],
        'Security' => [
            'index'    => 7,
            'sync'     => 'security',
            'feature'  => \Feature::SETTINGS_SECURITY,
            'url'      => 'tpm/cfg/securty',
            'label'    => 'tab_Security',
            'toolTip'  => 'tab_goto_Security'
        ],
        'AuditLog' => [
            'index'    => 8,
            'sync'     => 'userLog',
            'feature'  => \Feature::SETTINGS_AUDIT_LOG,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/audtLg',
            'label'    => 'tab_Audit_Log',
            'toolTip'  => 'tab_goto_Audit_Log'
        ],
        'EmailLog' => [
            'index'    => 9,
            'sync'     => 'emailLog',
            'feature'  => \Feature::SETTINGS_EMAIL_LOG, // Email Audit Log setting
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/emailLg',
            'label'    => 'tab_Email_Log',
            'toolTip'  => 'tab_goto_Email_Log'
        ],
        'SponsorEmail' => [
            'index'    => 10,
            'sync'     => 'sponsoremail',
            'feature'  => \Feature::TENANT_DDQ_SPONSOR_EMAIL,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/sponsoremail',
            'label'    => 'tab_SponsorEmail',
            'toolTip'  => 'tab_goto_SponsorEmail',
            'forceLoad' => true,
        ],
        'Notices' => [
            'index'    => 11,
            'sync'     => 'sitemsg',
            'feature'  => \Feature::SETTINGS_NOTICES,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'url'      => 'tpm/cfg/notices',
            'ajaxOp'   => 'displayNotices',
            'label'    => 'tab_Notices',
            'toolTip'  => 'tab_goto_Notices'
        ],
    ];

    /**
     * @var boolean Flag to indicate if the class has already been instantiated to prevent
     *              unnecessary translations if already instantiated.
     */
    private static $initialized = false;

    /**
     * Translate tab label and tooltip text
     *
     * @return void
     */
    public static function initTranslations()
    {
        if (!self::$initialized) {
            self::$initialized = true;
            $app = Xtra::app();
            $trText = $app->trans->group('tabs_settings');

            foreach (self::$tabs as $key => $tab) {
                if (empty($tab['sync'])) {
                    self::$tabs[$key]['sync'] = $key;
                }
                self::$tabs[$key]['me'] = $key;
                self::$tabs[$key]['forceLoad'] = (!empty($tab['forceLoad'])) ? $tab['forceLoad'] : false;
                self::$tabs[$key]['label'] = (!empty($tab['label'])) ? $trText[$tab['label']] : '';
                self::$tabs[$key]['toolTip'] = (!empty($tab['toolTip'])) ? $trText[$tab['toolTip']] : '';
            }
        }
    }

    /**
     * Add AI Services tab to $tabs array if AI Services feature is enabled
     *
     * @return void
     */
    public static function addaiServicesTab()
    {
        if (filter_var(getenv('AI_ONBOARDING'), FILTER_VALIDATE_BOOLEAN)) {
            self::$tabs['aiServices'] = [
                'index'    => 5,
                'sync'     => 'aiservices',
                'feature'  => Navigation::AI_SERVICES_ACCESS_FEATURE,
                'loadType' => Navigation::LOAD_BY_AJAX,
                'url'      => 'tpm/cfg/aiservices',
                'ajaxOp'   => 'initialize',
                'label'    => 'tab_aiServices',
                'toolTip'  => 'tab_goto_aiServices'
            ];
        }
    }
}

// This is how we've always done navigation in delta
// phpcs:disable
SettingsNavBarTabs::addaiServicesTab();
SettingsNavBarTabs::initTranslations();
// phpcs:enable
