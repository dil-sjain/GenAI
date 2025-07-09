<?php
/**
 * Define the "Profile Detail" sub-tabs configuration
 */

namespace Controllers\TPM\ThirdPartyMgt\ProfileDetail;

use Lib\Navigation\Navigation;

/**
 * Class ProfileDetailNavBarTabs defines the configuration of the Profile Detail sub-tabs
 *
 * @keywords profile detail, profile detail tabs, profile detail navigation, profile detail tab configuration
 */
#[\AllowDynamicProperties]
class ProfileDetailNavBarTabs
{
    /**
     * @var array Contains all the tabs configuration for the nav bar
     */
    public static $tabs = [
        'Summary' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'summary',
            'feature'  => Navigation::GENERAL_ACCESS_FEATURE,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/summary',
            'label'    => 'tab_Summary',
            'toolTip'  => 'tab_goto_Summary'
        ],
        'Training' => [
             'index'    => 2,
             'sync'     => 'training',
             'feature'  => \Feature::TP_TRAINING,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/3p/prflDetail/training',
             'label'    => 'tab_Training',
             'toolTip'  => 'tab_goto_Training'
        ],
        'DueDiligence' => [
            'index'    => 3,
            'sync'     => 'dueDiligence',
            'feature'  => \Feature::CASE_MANAGEMENT,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/dueDiligence',
            'label'    => 'tab_Due_Diligence',
            'toolTip'  => 'tab_goto_Due_Diligence'
        ],
        'UBO' => [
            'index'    => 4,
            'sync'     => 'ubo',
            'feature'  => \Feature::TENANT_3P_UBO_DATA,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/ubo',
            'label'    => 'tab_UBO',
            'toolTip'  => 'tab_goto_UBO'
        ],
        'Documents' => [
            'index'    => 5,
            'sync'     => 'documents',
            'feature'  => \Feature::TP_DOCS,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/documents',
            'label'    => 'tab_Documents',
            'toolTip'  => 'tab_goto_Documents'
        ],
        'Compliance' => [
            'index'    => 6,
            'sync'     => 'compliance',
            'feature'  => \Feature::TP_COMPLIANCE,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/compliance',
            'label'    => 'tab_Compliance',
            'toolTip'  => 'tab_goto_Compliance'
        ],
        'Gifts' => [
             'index'    => 7,
             'sync'     => 'gifts',
             'feature'  => \Feature::GIFTS,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/3p/prflDetail/gifts',
             'label'    => 'tab_Gifts',
             'toolTip'  => 'tab_goto_Gifts'
         ],
        'Connections' => [
             'index'    => 8,
             'sync'     => 'connections',
             'feature'  => \Feature::TP_RELATE,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/3p/prflDetail/connections',
             'label'    => 'tab_Connections',
             'toolTip'  => 'tab_goto_Connections'
        ],
         'CustomFields' => [
             'index'    => 9,
             'sync'     => 'customFLds',
             'feature'  => \Feature::TP_CUSTOM_FLDS,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/3p/prflDetail/customFlds',
             'label'    => 'tab_3p_Custom_Fields',
             'toolTip'  => 'tab_goto_3p_Custom_Fields'
         ],
        'Notes' => [
            'index'    => 10,
            'sync'     => 'notes',
            'feature'  => \Feature::TP_NOTES,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/notes',
            'label'    => 'tab_3p_Notes',
            'toolTip'  => 'tab_goto_3p_Notes'
        ],
        'AuditLog' => [
            'index'    => 11,
            'sync'     => 'auditLog',
            'feature'  => \Feature::TP_AUDIT_LOG,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/prflDetail/auditLog',
            'label'    => 'tab_Audit_Log',
            'toolTip'  => 'tab_goto_Audit_Log'
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
            $trText = $app->trans->group('tab_3p');

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

ProfileDetailNavBarTabs::initTranslations();
