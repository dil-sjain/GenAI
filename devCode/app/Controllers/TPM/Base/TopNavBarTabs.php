<?php
/**
 * Define the "Top" tabs configuration
 */

namespace Controllers\TPM\Base;

use Lib\Legacy\UserType;

/**
 * Class TopNavBarTabs defines the configuration of the Top tabs
 *
 * @keywords top tabs, top tabs navigation, top tabs configuration
 */
#[\AllowDynamicProperties]
class TopNavBarTabs
{
    public const TOP = 'Top';

    /**
     * @var array Contains all the tabs configuration for the nav bar
     */
    public static $tabs = [
        'Dashboard' => [
            'active'   => true,
            'index'    => 1,
            'feature'  => \Feature::DASHBOARD,
            'url'      => 'tpm/dsh',
            'label'    => 'tab_Dashboard',
            'toolTip'  => 'tab_goto_Dashboard'
        ],
        'AiVirtualAssistant' => [
            'index'    => 2,
            'feature'  => \Feature::AI_ASSISTANT,
            'url'      => 'aiAssistant',
            'label'    => 'tab_Virtual_Assistant',
            'toolTip'  => 'tab_goto_Virtual_Assistant'
        ],
        'ThirdPartyMgt' => [
            'index'    => 3,
            'feature'  => \Feature::THIRD_PARTIES,
            'url'      => 'cms/thirdparty/thirdparty_home.sec',     // delta route: 'tpm/3p'
            'label'    => 'tab_Third_Party_Management',
            'toolTip'  => 'tab_goto_Third_Party_Management'
        ],
        'CaseMgt' => [
            'index'    => 4,
            'feature'  => \Feature::CASE_MANAGEMENT,
            'url'      => 'cms/case/casehome.sec',                  // delta route: 'tpm/case'
            'label'    => 'tab_Case_Management',
            'toolTip'  => 'tab_goto_Case_Management'
        ],
        'Analytics' => [
            'index'    => 5,
            'feature'  => \Feature::ANALYTICS,
            'url'      => 'tpReport',
            'label'    => 'tab_Analytics',
            'toolTip'  => 'tab_goto_Analytics'
        ],
        'Settings' => [
            'index'    => 6,
            'feature'  => \Feature::SETTINGS,
            'url'      => 'tpm/cfg',
            'label'    => 'tab_Settings',
            'toolTip'  => 'tab_goto_Settings'
        ],
        'Workflow' => [
            'index'    => 7,
            'feature'  => \Feature::TENANT_WORKFLOW_OSP,
            'url'      => 'tpm/workflow',
            'label'    => 'tab_Workflow',
            'toolTip'  => 'tab_goto_Workflow'
        ],
        'Support' => [
            'index'    => 8,
            'feature'  => \Feature::SUPPORT,
            'url'      => 'tpm/adm',
            'label'    => 'tab_Support',
            'toolTip'  => 'tab_goto_Support'
        ]
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
            $app = \Xtra::app();
            $trText = $app->trans->group('tabs_top_level');

            foreach (self::$tabs as $key => $tab) {
                if (empty($tab['sync'])) {
                    self::$tabs[$key]['sync'] = $key;
                }
                self::$tabs[$key]['me'] = $key;
                if ($key == 'AiVirtualAssistant' && !filter_var(getenv("AI_VIRTUAL_ASSISTANT_FEATURE"), FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                self::$tabs[$key]['label']   = (!empty($tab['label'])) ? $trText[$tab['label']] : '';
                self::$tabs[$key]['toolTip'] = (!empty($tab['toolTip'])) ? $trText[$tab['toolTip']] : '';
            }
        }
    }
}

TopNavBarTabs::initTranslations();
