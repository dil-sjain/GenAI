<?php
/**
 * Define the "Security" sub-tabs configuration
 */

namespace Controllers\TPM\Settings\Security;

use Lib\Navigation\Navigation;
use Lib\SettingACL;
use Lib\Support\Xtra;

/**
 * Class SecurityNavBarTabs defines the configuration of the Security sub-tabs
 *
 * @keywords security, security tabs, security navigation, security tab configuration
 */
#[\AllowDynamicProperties]
class SecurityNavBarTabs
{
    /**
     * @var array Contains all the tabs configuration for the nav bar
     */
    public static $tabs = [
        'CustomRoles' => [
            'index'    => 2,
            'sync'     => 'custRoles',
            'feature'  => \Feature::CUSTOM_ROLES,
            'url'      => 'cms/client/clientadmin.sec?tname=security%26ttname=custRoles', // Delta 'tpm/cfg/securty/cstmRls'
            'label'    => 'tab_Custom_Roles',
            'toolTip'  => 'tab_goto_Custom_Roles'
        ],
        'SFTPAccess' => [
            'index'    => 3,
            'sync'     => 'sftpAccess',
            'feature'  => Navigation::SFTP_ACCESS_FEATURE,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'ajaxOp'   => 'initialize',
            'url'      => 'tpm/cfg/securty/sftpAccss',
            'label'    => 'tab_SFTP_Access',
            'toolTip'  => 'tab_goto_SFTP_Access'
        ],
    ];

    /**
     * Add BYOL (Bring Your Own License) tab for full client admin only
     *
     * @return void
     */
    public static function addByolTab()
    {
        $app = Xtra::app();
        $byolValue = (new SettingACL($app->session->get('clientID')))->get(
            SettingACL::UBO_BRING_YOUR_OWN_LICENSE
        );
        $has3pUboByol = isset($byolValue['value']) && $byolValue['value'] == 1;
        if ($has3pUboByol && $app->ftr->isLegacyFullClientAdmin()) {
            self::$tabs['UBO'] = [
                'index'    => 4,
                'sync'     => 'byolAccess',
                'feature'  => Navigation::UBO_BYOL_ACCESS_FEATURE,
                'loadType' => Navigation::LOAD_BY_AJAX,
                'ajaxOp'   => 'initialize',
                'url'      => 'tpm/cfg/securty/uboByol',
                'label'    => 'tab_UBO_BYOL',
                'toolTip'  => 'tab_goto_UBO_BYOL'
            ];
        }
    }

    /**
     * Add PasswordExpiration tab to $tabs array if HBI flag is disabled
     *
     * @return void
     */
    public static function addPasswordExpirationTab()
    {
        $hbiEnabled = filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN);
        $awsEnabled = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN);

        if (!($awsEnabled && $hbiEnabled)) {
            self::$tabs['PasswordExpiration'] = [
                'active'   => true,
                'index'    => 1,
                'sync'     => 'passExpire',
                'feature'  => \Feature::CONFIG_PW_EXPIRY,
                'loadType' => Navigation::LOAD_BY_AJAX,
                'ajaxOp'   => 'initialize',
                'url'      => 'tpm/cfg/securty/pswrdExp',
                'label'    => 'tab_Password_Expiration',
                'toolTip'  => 'tab_goto_Password_Expiration'
            ];
        }
    }

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
            $app = \Xtra::app();
            $trText = $app->trans->group('tabs_security');

            foreach (self::$tabs as $key => $tab) {
                if (empty($tab['sync'])) {
                    self::$tabs[$key]['sync'] = $key;
                }
                self::$tabs[$key]['me'] = $key;
                self::$tabs[$key]['label']   = (!empty($tab['label'])) ? $trText[$tab['label']] : '';
                self::$tabs[$key]['toolTip'] = (!empty($tab['toolTip'])) ? $trText[$tab['toolTip']] : '';
            }
        }
    }
}

SecurityNavBarTabs::addPasswordExpirationTab();
SecurityNavBarTabs::addByolTab();
SecurityNavBarTabs::initTranslations();
