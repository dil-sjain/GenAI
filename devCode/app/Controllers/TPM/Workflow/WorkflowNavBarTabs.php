<?php
/**
 * Define the "Workflow" sub-tabs configuration
 */

namespace Controllers\TPM\Workflow;

use Lib\Navigation\Navigation;

/**
 * Class WorkflowNavBarTabs defines the configuration of the Workflow sub-tabs
 *
 * @keywords workflow, workflow tab, navigation, tab configuration
 */
#[\AllowDynamicProperties]
class WorkflowNavBarTabs
{
    /**
     * Contains all the tabs configuration for the nav bar
     *
     * @var array
     */
    public static $tabs = [
        'Tasks' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'tasks',
            'feature'  => \Feature::TENANT_WORKFLOW_OSP,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/workflow/tasks',
            'label'    => 'tab_workflow_Tasks',
            'toolTip'  => 'tab_goto_Tasks'
        ],
        'Overview' => [
            'active'   => false,
            'index'    => 2,
            'sync'     => 'overview',
            'feature'  => \Feature::TENANT_WORKFLOW_OSP,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/workflow/overview',
            'label'    => 'tab_workflow_Overview',
            'toolTip'  => 'tab_goto_Overview'
        ],
        'Preferences' => [
            'active'   => false,
            'index'    => 3,
            'sync'     => 'preferences',
            'feature'  => \Feature::TENANT_WORKFLOW_OSP,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/workflow/preferences',
            'label'    => 'tab_workflow_Preferences',
            'toolTip'  => 'tab_goto_Preferences'
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
            $app = \Xtra::app();
            $trText = $app->trans->group('tabs_workflow');
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

// This is how we've always done navigation in delta
// phpcs:disable
WorkflowNavBarTabs::initTranslations();
// phpcs:enable
