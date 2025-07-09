<?php
/**
 * Define the "Third Party Management" sub-tabs configuration
 */

namespace Controllers\TPM\ThirdPartyMgt;

use Lib\Navigation\Navigation;
use Models\Globals\MultiTenantAccess;
use Models\ThirdPartyManagement\ThirdParty;

/**
 * Class ThirdPartyMgtNavBarTabs defines the configuration of the Third Party Management sub-tabs
 *
 * @keywords third party management, third party management tab, navigation, tab configuration
 */
#[\AllowDynamicProperties]
class ThirdPartyMgtNavBarTabs
{

    /**
     * Contains all the tabs configuration for the nav bar
     *
     * @var array
     */
    public static $tabs = [
        'ThirdPartyList' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'thirdPartylist',
            'feature'  => \Feature::TENANT_TPM,
            //'loadType' => Navigation::LOAD_BY_GET,
            //'url'      => 'tpm/3p/3pLst',
            'loadType' => Navigation::LOAD_BY_PAGE,
            'url'      => 'cms/thirdparty/thirdparty_home.sec?tname=thirdPartylist',
            'label'    => 'tab_Third_Party_List',
            'toolTip'  => 'tab_goto_Third_Party_List'
        ],
        'ProfileDetail' => [
            'hidden'   => true,
            'index'    => 2,
            'sync'     => 'thirdPartyFolder',
            //'feature'  => Navigation::GENERAL_ACCESS_FEATURE,
            //'url'      => 'tpm/3p/prflDetail',
            'loadType' => Navigation::LOAD_BY_PAGE,
            'url'      => 'cms/thirdparty/thirdparty_home.sec?tname=thirdPartyFolder',
            'label'    => \Feature::TENANT_TPM_ENGAGEMENTS ? 'tab_Record_Detail' : 'tab_Profile_Detail',
            'toolTip'  => 'tab_goto_Profile_Detail'
        ],
        'PendingReview' => [
            'index'    => 3,
            'hidden'   => false,
            'sync'     => 'pendingReview',
            //'feature'  => Navigation::GENERAL_ACCESS_FEATURE,
            //'url'      => 'tpm/3p/pndgReview',
            'loadType' => Navigation::LOAD_BY_PAGE,
            'url'      => 'cms/thirdparty/thirdparty_home.sec?tname=pendingReview',
            'label'    => 'tab_Pending_Review',
            'toolTip'  => 'tab_goto_Pending_Review'
        ],
        'UberSearch' => [
            'hidden'   => true,
            'index'    => 4,
            'sync'     => 'uberSearch',
            'feature'  => Navigation::MULTI_TENANT_ACCESS,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'ajaxOp'   => 'loadTabContent',
            'overlay'  => true,
            'url'      => 'tpm/3p/uber',
            'label'    => 'tab_Uber_Search',
            'toolTip'  => 'tab_goto_Uber_Search',
        ],
    ];

    /**
     * Flag to indicate if the class has already been instantiated to prevent
     * unnecessary translations if already instantiated.
     *
     * @var boolean
     */
    private static $initialized = false;

    /**
     * Check if TPM tenantID has multi-tenant access
     *
     * @return void Sets hidden to true/false
     */
    public static function initUberSearch()
    {
        $app = \Xtra::app();
        if (is_object($app->ftr)) {
            $mta = new MultiTenantAccess();
            self::$tabs['UberSearch']['hidden'] = !$mta->hasMultiTenantAccess($app->ftr->tenant);
        }
        if ((new ThirdParty($app->ftr->tenant))->hasEngagementRecord()) {
            self::$tabs['ProfileDetail']['label'] = 'tab_Record_Detail';
        }
    }

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
            $trText = $app->trans->group('tab_3p');

            foreach (self::$tabs as $key => $tab) {
                if (empty($tab['sync'])) {
                    self::$tabs[$key]['sync'] = $key;
                }
                self::$tabs[$key]['me']      = $key;
                self::$tabs[$key]['label']   = (!empty($tab['label'])) ? $trText[$tab['label']] : '';
                self::$tabs[$key]['toolTip'] = (!empty($tab['toolTip'])) ? $trText[$tab['toolTip']] : '';
            }
        }
    }
}

ThirdPartyMgtNavBarTabs::initUberSearch();
ThirdPartyMgtNavBarTabs::initTranslations();
