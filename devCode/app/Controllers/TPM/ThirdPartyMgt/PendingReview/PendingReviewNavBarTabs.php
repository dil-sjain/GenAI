<?php
/**
 * Define the "Pending Review" sub-tabs configuration
 */

namespace Controllers\TPM\ThirdPartyMgt\PendingReview;

use Lib\Navigation\Navigation;

/**
 * Class PendingReviewNavBarTabs defines the configuration of the Profile Detail sub-tabs
 *
 * @keywords profile detail, profile detail tabs, profile detail navigation, profile detail tab configuration
 */
#[\AllowDynamicProperties]
class PendingReviewNavBarTabs
{
    /**
     * @var array Contains all the tabs configuration for the nav bar
     */
    public static $tabs = [
        'Gifts' => [
            'active'   => true,
            'index'    => 1,
            'sync'     => 'gifts',
            'feature'  => \Feature::TENANT_TPM_GIFTS,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/pndgReview/gifts',
            'label'    => 'tab_Gifts',
            'toolTip'  => 'tab_goto_Gifts'
        ],
        '3PApproval' => [
            'index'    => 2,
            'sync'     => '3pApproval',
            'feature'  => Navigation::GENERAL_ACCESS_FEATURE,
            'loadType' => Navigation::LOAD_BY_GET,
            'url'      => 'tpm/3p/pndgReview/3pApproval',
            'label'    => 'tab_3P_Approval',
            'toolTip'  => 'tab_goto_3P_Approval'
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

PendingReviewNavBarTabs::initTranslations();
