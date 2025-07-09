<?php
/**
 * Define the "Case Management" sub-tabs configuration
 */

namespace Controllers\TPM\CaseMgt;

use Lib\Navigation\Navigation;
use Models\Globals\MultiTenantAccess;

/**
 * Class CaseMgtNavBarTabs defines the configuration of the Case Management sub-tabs
 *
 * @keywords case management, case management tab, navigation, tab configuration
 */
#[\AllowDynamicProperties]
class CaseMgtNavBarTabs
{
    /**
     * Contains all the tabs configuration for the nav bar
     *
     * @var array
     */
    public static $tabs = [
        'CaseList' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'caselist',
            'feature'  => \Feature::CASE_MANAGEMENT,
            'loadType' => Navigation::LOAD_BY_PAGE,
            'url'      => 'cms/case/casehome.sec?tname=caselist',   // delta route: 'tpm/case/caseList' via LOAD_BY_GET
            'label'    => 'tab_Case_List',
            'toolTip'  => 'tab_goto_Case_List'
        ],
        'CaseFolder' => [
            'hidden'   => true,
            'index'    => 2,
            'sync'     => 'casefolder',
            'feature'  => \Feature::CASE_MANAGEMENT,
            'loadType' => Navigation::LOAD_BY_PAGE,
            'url'      => 'cms/case/casehome.sec?tname=casefolder',  // delta route: 'sp/case/caseFldr' via LOAD_BY_GET
            'label'    => 'tab_Case_Folder',
            'toolTip'  => 'tab_goto_Case_Folder'
        ],
        'UberSearchCase' => [
            'hidden'   => true,
            'index'    => 3,
            'sync'     => 'uberSearchCase',
            'feature'  => Navigation::MULTI_TENANT_ACCESS,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'ajaxOp'   => 'loadTabContent',
            'overlay'  => true,
            'url'      => 'tpm/case/uber',
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
            // only for TPM client (or Super Admin) when 3P is not enabled
            if (!$app->ftr->tenantHas(\Feature::TENANT_TPM && !$app->ftr->appIsSP())) {
                $mta = new MultiTenantAccess();
                self::$tabs['UberSearchCase']['hidden'] = !$mta->hasMultiTenantAccess($app->ftr->tenant);
            }
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
            $trText = $app->trans->group('tabs_case');

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

CaseMgtNavBarTabs::initUberSearch();
CaseMgtNavBarTabs::initTranslations();
