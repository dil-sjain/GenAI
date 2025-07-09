<?php
/**
 * Define the "Case Folder" sub-tabs configuration
 */

namespace Controllers\TPM\CaseMgt\CaseFolder;

use Lib\Navigation\Navigation;

/**
 * Class CaseFolderNavBarTabs defines the configuration of the Case Folder sub-tabs
 *
 * @keywords case folder, case folder tabs, case folder navigation, case folder tab configuration
 */
#[\AllowDynamicProperties]
class CaseFolderNavBarTabs
{
    /**
     * @var array Contains all the tabs configuration for the nav bar
     */
    public static $tabs = [
         'Company' => [
             'active'   => true,
             'index'    => 1,
             'sync'     => 'company',
             'feature'  => Navigation::GENERAL_ACCESS_FEATURE,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/company',
             'label'    => 'tab_Company',
             'toolTip'  => 'tab_goto_Company'
         ],
         'Personnel' => [
             'index'    => 2,
             'sync'     => 'personnel',
             'feature'  => \Feature::CASE_PERSONNEL,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/personnel',
             'label'    => 'tab_Personnel',
             'toolTip'  => 'tab_goto_Personnel'
         ],
         'BusinessPractices' => [
             'index'    => 3,
             'sync'     => 'bizpract',
             'feature'  => \Feature::CASE_BIZ_PRACTICE,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/bizPract',
             'label'    => 'tab_Business_Practices',
             'toolTip'  => 'tab_goto_Business_Practices'
         ],
         'Relationship' => [
             'index'    => 4,
             'sync'     => 'relation',
             'feature'  => \Feature::CASE_RELATION,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/relation',
             'label'    => 'tab_Relationship',
             'toolTip'  => 'tab_goto_Relationship'
         ],
         'AdditionalInfo' => [
             'index'    => 5,
             'sync'     => 'addinfo',
             'feature'  => \Feature::CASE_ADDL_INFO,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/addlInfo',
             'label'    => 'tab_Additional_Info',
             'toolTip'  => 'tab_goto_Additional_Info'
         ],
         'Attachments' => [
             'index'    => 6,
             'sync'     => 'attachments',
             'feature'  => \Feature::CASE_DOCS,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/attachments',
             'label'    => 'tab_Attachments',
             'toolTip'  => 'tab_goto_Attachments'
         ],
         'CustomFields' => [
             'index'    => 7,
             'sync'     => 'customfields',
             'feature'  => \Feature::CASE_CUSTOM_FLDS,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/customFlds',
             'label'    => 'tab_case_Custom_Fields',
             'toolTip'  => 'tab_goto_case_Custom_Fields'
         ],
         'Notes' => [
             'index'    => 8,
             'sync'     => 'notes',
             'feature'  => \Feature::CASE_NOTES,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/notes',
             'label'    => 'tab_case_Notes',
             'toolTip'  => 'tab_goto_case_Notes'
         ],
         'Reviewer' => [
             'index'    => 9,
             'sync'     => 'reviewer',
             'feature'  => \Feature::TENANT_REVIEW_TAB,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/reviewer',
             'label'    => 'tab_Reviewer',
             'toolTip'  => 'tab_goto_Reviewer'
         ],
         'AuditLog' => [
             'index'    => 10,
             'sync'     => 'auditlog',
             'feature'  => \Feature::CASE_AUDIT_LOG,
             'loadType' => Navigation::LOAD_BY_GET,
             'url'      => 'tpm/case/caseFldr/auditLog',
             'label'    => 'tab_Audit_Log',
             'toolTip'  => 'tab_goto_Audit_Log'
         ],
         'Workflow' => [
            'index'    => 11,
            'sync'     => 'workflow',
            'feature'  => \Feature::TENANT_WORKFLOW_OSP,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/case/caseFldr/reviewer',
            'label'    => 'tab_Workflow',
            'toolTip'  => 'tab_goto_Workflow'
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
            $trText = $app->trans->group('tabs_case');

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

CaseFolderNavBarTabs::initTranslations();
