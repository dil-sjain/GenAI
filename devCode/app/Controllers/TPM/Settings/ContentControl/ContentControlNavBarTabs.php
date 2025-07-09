<?php
/**
 * Define the "Content Control" sub-tabs configuration
 */

namespace Controllers\TPM\Settings\ContentControl;

use Lib\Navigation\Navigation;
use Lib\Support\Xtra;

/**
 * Class ContentControlNavBarTabs defines the configuration of the Content Control sub-tabs
 *
 * @keywords content control, content control tabs, content control navigation, content control tab configuration
 */
#[\AllowDynamicProperties]
class ContentControlNavBarTabs
{
    /**
     * Contains all the tabs configuration for the nav bar
     *
     * @var array
     */
    public static $tabs = [
        // comment OUT this key/array to turn legacy back on.
        'FieldsLists' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'customFields',
            'feature'  => \Feature::FIELDS_LISTS,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'ajaxOp'   => 'init',
            'url'      => 'tpm/cfg/cntCtrl/fldsLsts',
            'overlay'  => true,
            'label'    => 'tab_Fields_Lists',
            'toolTip'  => 'tab_goto_Fields_Lists',
        ],
        'RiskMgt' => [
            'index'    => 2,
            'sync'     => 'riskInv',
            'feature'  => \Feature::CONFIG_RISK_INVENTORY,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'url'      => 'tpm/cfg/cntCtrl/riskInvntry',
            'ajaxOp'   => 'init',
            'overlay'  => true,
            'label'    => 'tab_Risk_Inventory',
            'toolTip'  => 'tab_goto_Risk_Inventory'
        ],
        'MessageCenter' => [
            'index'    => 3,
            'sync'     => 'msgCent',
            'feature'  => \Feature::MSG_CENTER,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'url'      => 'tpm/cfg/cntCtrl/msgCntr',
            'ajaxOp'   => 'initialize',
            'overlay'  => true,
            'label'    => 'tab_Message_Center',
            'toolTip'  => 'tab_goto_Message_Center'
        ],
        'CfgServiceProvider' => [
            'index'    => 4,
            'sync'     => 'configSP',
            'feature'  => \Feature::TENANT_TPM_SP_CFG,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/cntCtrl/svcPrvdr',
            'label'    => 'tab_Service_Provider',
            'toolTip'  => 'tab_goto_Service_Provider'
        ],
        'Scheduling' => [
            'index'    => 5,
            'sync'     => 'schedule',
            'feature'  => \Feature::SCHEDULING,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'url'      => 'tpm/cfg/cntCtrl/sched',
            'ajaxOp'   => 'displaySchedulingData',
            'overlay'  => true,
            'label'    => 'tab_Scheduling',
            'toolTip'  => 'tab_goto_Scheduling'
        ],
        'GdcFilter' => [
            'index'    => 6,
            'sync'     => 'gdcfilter',
            'feature'  => \Feature::GDC_FILTER,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/cntCtrl/gdcfilt',
            'label'    => 'tab_GDC_Filters',
            'toolTip'  => 'tab_goto_GDC_Filters'
        ],
        'MediaMonitor' => [
            'index'    => 7,
            'sync'     => 'mediamonitor',
            'feature'  => \Feature::TENANT_MEDIA_MONITOR,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/cntCtrl/mm',
            'label'    => 'tab_Media_Monitor',
            'toolTip'  => 'tab_goto_Media_Monitor'
        ],
        'FormBuilder' => [
            'index'    => 8,
            'sync'     => 'formbuilder',
            'feature'  => \Feature::TENANT_DDQ_OSP_FORMS,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/cfg/cntCtrl/frmBldr',
            'label'    => 'tab_Form_Builder',
            'toolTip'  => 'tab_goto_Form_Builder'
        ],
        'WorkflowBuilder' => [
            'index'     => 9,
            'sync'      => 'workflowbuilder',
            'feature'   => \Feature::TENANT_WORKFLOW_OSP,
            'loadType'  => Navigation::LOAD_BY_GET,
            'url'       => 'tpm/cfg/cntCtrl/wrkflwBldr',
            'label'     => 'tab_Workflow_Builder',
            'toolTip'   => 'tab_goto_Workflow_Builder',
            'forceLoad' => true
        ],
        'RenewalRules' => [
            'index'    => 10,
            'sync'     => 'renewalrules',
            'feature'  => \Feature::TENANT_3P_RENEWAL_RULES,
            'loadType' => Navigation::LOAD_BY_AJAX,
            'url'      => 'tpm/cfg/cntCtrl/renewalRules',
            'ajaxOp'   => 'initRenewalRules',
            'overlay'  => true,
            'label'    => 'tab_Renewal_Rules',
            'toolTip'  => 'tab_goto_Renewal_Rules'
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
     * Translate tab label and tooltip text
     *
     * @return void
     */
    public static function initTranslations()
    {
        if (!self::$initialized) {
            self::$initialized = true;
            $app = Xtra::app();
            $trText = $app->trans->group('tabs_content_control');
            foreach (self::$tabs as $key => $tab) {
                if (empty($tab['sync'])) {
                    self::$tabs[$key]['sync'] = $key;
                }
                self::$tabs[$key]['me']        = $key;
                self::$tabs[$key]['forceLoad'] = (!empty($tab['forceLoad'])) ? $tab['forceLoad'] : false;
                // label
                if (!empty($tab['label'])) {
                    if (substr($tab['label'], 0, 8) === 'literal:') {
                        self::$tabs[$key]['label'] = substr($tab['label'], 8);
                    } else {
                        self::$tabs[$key]['label'] = $trText[$tab['label']];
                    }
                } else {
                    self::$tabs[$key]['label'] = '';
                }
                // tooTip
                if (!empty($tab['toolTip'])) {
                    if (substr($tab['toolTip'], 0, 8) === 'literal:') {
                        self::$tabs[$key]['toolTip'] = substr($tab['toolTip'], 8);
                    } else {
                        self::$tabs[$key]['toolTip'] = $trText[$tab['toolTip']];
                    }
                } else {
                    self::$tabs[$key]['toolTip'] = '';
                }
                self::$tabs[$key]['forceLoad'] = (!empty($tab['forceLoad'])) ? $tab['forceLoad'] : false;
            }
        }
    }

    /**
     * Conditionally add GeographyExplain to Settings tab
     *
     * @return void
     */
    public static function addGeographyTab()
    {
        if (Xtra::usingGeography2()) {
            self::$tabs['Geography'] = ['index' => 11,
                'sync'     => 'geography',
                'feature'  => \Feature::SETTINGS,
                'loadType' => Navigation::LOAD_BY_GET,
                'url'      => 'tpm/cfg/geography',
                'label'    => 'literal:Countries',
                'toolTip'  => 'literal:Go to Countries Tab',
            ];
        }
    }
}

ContentControlNavBarTabs::addGeographyTab();
ContentControlNavBarTabs::initTranslations();
