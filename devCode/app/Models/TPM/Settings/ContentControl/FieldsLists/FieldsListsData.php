<?php
/**
 * Model for the main Fields/Lists data operations.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists;

/**
 * Class FieldsListsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements. Sub-classes could/should be extended as needed.
 *
 * DO NOT UNDER ANY CIRCUMSTANCES ALTER THE EXISTING ID's IN THE $list ARRAY!!!
 *
 * @keywords tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class FieldsListsData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    private $DB = null;

    /**
     * @var integer The current tenantID
     */
    protected $tenantID = 0;

    /**
     * @var integer The current userID
     */
    protected $userID = 0;

    /**
     * @var object Tables used by the model.
     */
    protected $tbl = null;

    /**
     * @var string Base path to js files
     */
    private $jsPath = '/assets/js/TPM/Settings/Control/FieldsLists/Subs/';

    /**
     * @var string Base path to js widget files (not jqWidgets)
     */
    private $jsWidgetPath = '/assets/js/widgets/';

    /**
     * @var string Base path to js template files
     */
    private $jsTplPath = '/assets/js/views/TPM/settings/control/lists/';

    /**
     * @var string Base path to jq widget files
     */
    private $jqxPath = '/assets/jq/jqx/jqwidgets/';

    /**
     * @var array of translated text items
     */
    private $txt = [];

    /**
     * @var boolean Useful in dev/troubleshooting to use the source js/css vice minified. Set true to use source.
     */
    private $useSrc = false;

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param array   $initValues Any additional parameters that need to be passed in
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;

        $this->tenantID   = (int)$tenantID;

        if ($this->tenantID <= 0) {
            throw new \InvalidArgumentException("The tenantID must be a positive integer.");
        }

        $this->tbl = (object)null;
        // add tables to object if necessary for future expansion.
        if ($this->useSrc) {
            $this->jsPath = str_replace('/assets/js/TPM/', '/assets/js/src/TPM/', $this->jsPath);
        }
    }

    /**
     * Method to get active list types.
     *
     * @return array List types array sorted by sequence.
     */
    public function getListTypes()
    {
        return $this->listTypesData();
    }

    /**
     * Method to get all list types, regardless of active setting.
     *
     * @return array List types array sorted by sequence.
     */
    public function getAllListTypes()
    {
        return $this->listTypesData(false);
    }

    /**
     * Method to get single list types by its ID.
     *
     * @param integer $id Valid list type id.
     *
     * @return array Single list type array sorted by sequence.
     */
    public function getListTypeByID($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return [];
        }
        $list = $this->listTypesData(false);
        foreach ($list as $l) {
            if ($l['id'] == $id) {
                return $l;
            }
        }
        return [];
    }

    /**
     * Method to set translation text values needed for this class.
     * Allows controller/caller to do a single call for translations
     * vice each file on its own.
     *
     * @param array $txt Array of translated text items.
     *
     * @return void
     */
    public function setTrText($txt)
    {
        if (is_array($txt) && !empty($txt)) {
            $this->txt = $txt;
        }
    }

    /**
     * This method mimics a db query result array.
     * At some point this data may be db based, but for now it is not.
     *
     * WATCH OUT if you add something to the array below. It's not written in order of sequence,
     * due to permission checks, so be careful to know what key you need to follow, then adjust the rest.
     * The sequence key MUST be unique.
     *
     * DO NOT UNDER ANY CIRCUMSTANCES ALTER THE EXISTING ID's IN THE $list ARRAY!!!
     *
     * @param boolean $activeOnly Set false to get ALL F/L components, INCLUDING inactive ones.
     *
     * @return array Array of listTypes SORTED by `sequence`
     */
    private function listTypesData($activeOnly = true)
    {
        /*
            Data format:
            id       => fake primary id. unique
            key      => code key for js object
            ctrl     => js namespace to use
            files    => array of files to be loaded for area
            default  => default name to be used if name is empty.
            name     => name from text translation
            active   => 1 for active, 0 to remove from select list. (even if active, perms still have final call)
            sequence => order to show in the dropdown list.
        */

        $acls = $this->app->ftr->__get('tenantFeatures');
        $list = [
            [
                'id' => 10,
                'key' => 'custflds',
                'ctrl' => 'CustFields',
                'files' => [
                    $this->jsPath . 'CustFields.js',
                    $this->jsWidgetPath . 'iHelp.js',
                    $this->jsTplPath . 'custFields/customFields||cfHome.html',
                    $this->jsTplPath . 'custFields/customFieldsTable||cfData.html',
                    $this->jsTplPath . 'custFields/customFieldsTableRow||cfDataRow.html',
                    $this->jsTplPath . 'custFields/customFieldsForm||cfForm.html',
                    $this->jsTplPath . 'custFields/flaggedQuestionsForm||fqForm.html',
                    $this->jsTplPath . 'custFields/flaggedQuestionsListRow||fqListRow.html',
                    $this->jsTplPath . 'custFields/flaggedQuestionsAnswerCheckbox||fqAnsCkBx.html',
                    $this->jsTplPath . 'custFields/flaggedQuestionsSelected||fqSelected.html',
                    $this->jsTplPath . 'custFields/customFieldsPreview||cfPreview.html',
                ],
                'default' => 'Case Folder Custom Fields',
                'name' => ($this->txt['flTitle_CaseCustFlds'] ?? ''),
                'active' => 1,
                'sequence' => 10,
            ],
            [
                'id' => 20,
                'key' => 'custlst',
                'ctrl' => 'CustLists',
                'files' => [
                    $this->jsPath . 'CustLists.js',
                    $this->jsPath . 'GeneralUse.js',
                ],
                'default' => 'Custom Lists',
                'name' => ($this->txt['flTitle_CustLists'] ?? ''),
                'active' => 1,
                'sequence' => 20,
            ],
            [
                'id' => 30,
                'key' => 'custlstitm',
                'ctrl' => 'CustListItems',
                'files' => [
                    $this->jsPath . 'CustListItems.js',
                    $this->jsPath . 'GeneralUse.js',
                    $this->jsTplPath . 'subSelect||subHome.html',
                ],
                'default' => 'Custom List Items',
                'name' => ($this->txt['flTitle_CustListItems'] ?? ''),
                'active' => 1,
                'sequence' => 30,
            ],
            [
                'id' => 40,
                'key' => 'custlbl',
                'ctrl' => 'CustLabels',
                'files' => [
                    $this->jsPath . 'CustLabels.js',
                    $this->jsTplPath . 'customLabels||clblHome.html',
                    $this->jsTplPath . 'customLabelsTable||clblData.html',
                ],
                'default' => 'Customizable Labels',
                'name' => ($this->txt['flTitle_CustLabels'] ?? ''),
                'active' => 1,
                'sequence' => 40,
            ],
            [
                'id' => 48,
                'key' => 'RejectCaseForm',
                'ctrl' => 'RejectCaseForm',
                'files' => [
                    $this->jsPath . 'RejectCaseForm.js',
                    $this->jsWidgetPath . 'iHelp.js',
                    $this->jsTplPath . 'RejectCase/customFields||cfHome.html',
                    $this->jsTplPath . 'RejectCase/customFieldsTable||cfData.html',
                    $this->jsTplPath . 'RejectCase/customFieldsTableRow||cfDataRow.html',
                    $this->jsTplPath . 'RejectCase/customFieldsForm||cfForm.html',
                ],
                'default' => 'Case Reject/Close Codes',
                'name' => ($this->txt['flTitle_RejectCaseFormName'] ?? ''),
                'active' => 1,
                'sequence' => 48,
            ],

            [
                'id' => 50,
                'key' => 'intkfmnm',
                'ctrl' => 'IntakeFormNames',
                'files' => [
                    $this->jsPath . 'IntakeFormNames.js',
                    $this->jsTplPath . 'intakeFormNames||ifnHome.html',
                    $this->jsTplPath . 'intakeFormNamesTable||ifnData.html',
                ],
                'default' => 'Intake Form Identification',
                'name' => ($this->txt['flTitle_IntakeFormIdent'] ?? ''),
                'active' => 1,
                'sequence' => 50,
            ],
            [
                'id' => 60,
                'key' => 'notecat',
                'ctrl' => 'NoteCats',
                'files' => [
                    $this->jsPath . 'NoteCats.js',
                    $this->jsPath . 'GeneralUse.js',
                ],
                'default' => 'Note Categories',
                'name' => ($this->txt['flTitle_NoteCats'] ?? ''),
                'active' => 1,
                'sequence' => 60,
            ],
            [
                'id' => 70,
                'key' => 'doccat',
                'ctrl' => 'DocCats',
                'files' => [
                    $this->jsPath . 'DocCats.js',
                    $this->jsPath . 'GeneralUse.js',
                ],
                'default' => 'Document Categories',
                'name' => ($this->txt['flTitle_DocCats'] ?? ''),
                'active' => 1,
                'sequence' => 70,
            ],
            [
                'id' => 80,
                'key' => 'billing',
                'ctrl' => 'BillingUnit',
                'files' => [
                    $this->jsPath . 'BillingUnit.js',
                    $this->jsPath . 'GeneralUse.js',
                ],
                'default' => 'Billing Unit',
                'name' => ($this->txt['flTitle_BillingUnit'] ?? ''),
                'active' => 1,
                'sequence' => 80,
            ],
            [
                'id' => 90,
                'key' => 'purchase',
                'ctrl' => 'PurchaseOrder',
                'files' => [
                    $this->jsPath . 'PurchaseOrder.js',
                    $this->jsPath . 'GeneralUse.js',
                    $this->jsTplPath . 'subSelect||subHome.html',
                ],
                'default' => 'Purchase Order',
                'name' => ($this->txt['flTitle_PurchaseOrder'] ?? ''),
                'active' => 1,
                'sequence' => 90,
            ],
        ];
        if (in_array(\Feature::TENANT_TPM, $acls)) {
            // This applies only to 3P for now, but may apply to Case later.
            // If it also applies to case move it out of TENANT_TPM
            if (in_array(\Feature::TENANT_3P_MULTIPLE_ADDRESS, $acls)) {
                $list[] = [
                    'id' => 200,
                    'key' => 'addrcat',
                    'ctrl' => 'AddressCategory',
                    'files' => [
                        $this->jsPath . 'AddressCategory.js',
                        $this->jsPath . 'GeneralUse.js',
                    ],
                    'default' => 'Address Categories',
                    'name' => ($this->txt['flTitle_AddrCats'] ?? ''),
                    'active' => 1,
                    'sequence' => 95,
                ];
            }
            // Checking multiple risk model toggle enabled in teanant feature settings.
            if (in_array(\Feature::MULTIPLE_RISK_MODEL, $acls)) {
                $list[] = [
                    'id' => 245,
                    'key' => 'RiskModelRoles',
                    'ctrl' => 'RiskModelRoles',
                    'files' => [
                        $this->jsPath . 'RiskModelRoles.js',
                        $this->jsPath . 'GeneralUse.js',
                    ],
                    'default' => 'Risk Areas',
                    'name' => 'Risk Areas',
                    'active' => 1,
                    'sequence' => 191,
                ];
            }
            $list[] = [
                'id' => 100,
                'key' => 'tpaprvrsn',
                'ctrl' => 'TpApprvRsns',
                'files' => [
                    $this->jsPath . 'TpApprvRsns.js',
                    $this->jsPath . 'GeneralUse.js',
                    $this->jsTplPath . 'subSelect||subHome.html',
                ],
                'default' => '3P Approval Reasons',
                'name' => ($this->txt['flTitle_3PApprvReasons'] ?? ''),
                'active' => 1,
                'sequence' => 100,
            ];
            $list[] = [
                'id' => 130,
                'key' => 'tpcustflds',
                'ctrl' => 'CustFields',
                'files' => [
                    $this->jsPath . 'CustFields.js',
                    $this->jsWidgetPath . 'iHelp.js',
                    $this->jsTplPath . 'custFields/customFields||cfHome.html',
                    $this->jsTplPath . 'custFields/customFieldsTable||cfData.html',
                    $this->jsTplPath . 'custFields/customFieldsTableRow||cfDataRow.html',
                    $this->jsTplPath . 'custFields/customFieldsForm||cfForm.html',
                    $this->jsTplPath . 'custFields/customFieldsTypeForm||cfTypeForm.html',
                    $this->jsTplPath . 'custFields/customFieldsPreview||cfPreview.html',
                ],
                'default' => '3P Custom Fields',
                'name' => ($this->txt['flTitle_3PCustFlds'] ?? ''),
                'active' => 1,
                'sequence' => 130,
            ];
            $list[] = [
                'id' => 140,
                'key' => 'tptype',
                'ctrl' => 'TpTypes',
                'files' => [
                    $this->jsPath . 'TpTypes.js',
                    $this->jsPath . 'GeneralUse.js',
                ],
                'default' => '3P Types',
                'name' => ($this->txt['flTitle_3PTypes'] ?? ''),
                'active' => 1,
                'sequence' => 140,
            ];
            $list[] = [
                'id' => 150,
                'key' => 'tptypecat',
                'ctrl' => 'TpTypeCats',
                'files' => [
                    $this->jsPath . 'TpTypeCats.js',
                    $this->jsPath . 'GeneralUse.js',
                    $this->jsTplPath . 'subSelect||subHome.html',
                ],
                'default' => '3P Type Categories',
                'name' => ($this->txt['flTitle_3PTypeCats'] ?? ''),
                'active' => 1,
                'sequence' => 150,
            ];

            if (in_array(\Feature::TENANT_TPM_COMPLIANCE, $acls)) {
                $list[] = [
                    'id' => 110,
                    'key' => 'tpcompgrp',
                    'ctrl' => 'TpCompGroups',
                    'files' => [
                        $this->jsPath . 'TpCompGroups.js',
                        $this->jsPath . 'GeneralUse.js',
                    ],
                    'default' => '3P Compliance Groups',
                    'name' => ($this->txt['flTitle_3PCompGroups'] ?? ''),
                    'active' => 1,
                    'sequence' => 110,
                ];
                $list[] = [
                    'id' => 120,
                    'key' => 'tpcompfact',
                    'ctrl' => 'TpCompFactors',
                    'files' => [
                        $this->jsPath . 'TpCompFactors.js',
                        $this->jsTplPath . 'tpCompFactors/tpcfHome||tpcfHome.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfMainTable||tpcfMainTbl.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfMainTableRow||tpcfMainTblRow.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfCompareTable||tpcfCompTbl.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfMainForm||tpcfMainForm.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfThresholdForm||tpcfThreshForm.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfVarianceForm||tpcfVarForm.html',
                        $this->jsTplPath . 'tpCompFactors/tpcfVariance||tpcfVariance.html',
                    ],
                    'default' => '3P Compliance Factors',
                    'name' => ($this->txt['flTitle_3PCompFactors'] ?? ''),
                    'active' => 1,
                    'sequence' => 120,
                ];
            }
            if (in_array(\Feature::TENANT_TPM_TRAINING, $acls)) {
                $list[] = [
                    'id' => 160,
                    'key' => 'tptrngtpls',
                    'ctrl' => 'TpTrngTpls',
                    'files' => [
                        $this->jsPath . 'TpTrngTpls.js',
                        $this->jsTplPath . 'subSelect||subHome.html',
                        $this->jsTplPath . 'tpTrngTpls/tplHome||tplHome.html',
                        $this->jsTplPath . 'tpTrngTpls/tplTbl||tplTbl.html',
                        $this->jsTplPath . 'tpTrngTpls/tplTblRow||tplTblRow.html',
                        $this->jsTplPath . 'tpTrngTpls/tplForm||tplForm.html',
                    ],
                    'default' => '3P Training Templates',
                    'name' => ($this->txt['flTitle_3PTrngTpls'] ?? ''),
                    'active' => 1,
                    'sequence' => 160,
                ];
                $list[] = [
                    'id' => 170,
                    'key' => 'tptrngtypes',
                    'ctrl' => 'TpTrngTypes',
                    'files' => [
                        $this->jsPath . 'TpTrngTypes.js',
                        $this->jsPath . 'GeneralUse.js',
                    ],
                    'default' => '3P Training Types',
                    'name' => ($this->txt['flTitle_3PTrngTypes'] ?? ''),
                    'active' => 1,
                    'sequence' => 170,
                ];
                $list[] = [
                    'id' => 180,
                    'key' => 'tptrngattchtypes',
                    'ctrl' => 'TpTrngAttchTypes',
                    'files' => [
                        $this->jsPath . 'TpTrngAttchTypes.js',
                        $this->jsPath . 'GeneralUse.js',
                    ],
                    'default' => '3P Training Attachment Types',
                    'name' => ($this->txt['flTitle_3PTrngAttchTypes'] ?? ''),
                    'active' => 1,
                    'sequence' => 180,
                ];
            }
        }
        if (in_array(\Feature::TENANT_TPM_GIFTS, $acls) && in_array(\Feature::TENANT_TPM_RELATION, $acls)) {
            $list[] = [
                'id' => 190,
                'key' => 'gfttrk',
                'ctrl' => 'GiftTrack',
                'files' => [
                    $this->jsPath . 'GiftTrack.js',
                    $this->jsTplPath . 'giftTrack/giftTrack||gtHome.html',
                    $this->jsTplPath . 'giftTrack/giftTrackForm||gtForm.html',
                    $this->jsTplPath . 'giftTrack/giftTrackRules||gtRules.html',
                ],
                'default' => 'Gift Tracking',
                'name' => ($this->txt['flTitle_GiftTrack'] ?? ''),
                'active' => 1,
                'sequence' => 190,
            ];
        }
        if (in_array(\Feature::TENANT_INTAKE_INVITE_FROM, $acls)) {
            $list[] = [
                'id' => 200,
                'key' => 'intakefrom',
                'ctrl' => 'IntakeInviteFrom',
                'files' => [
                    $this->jsPath . 'IntakeInviteFrom.js',
                    $this->jsPath . 'GeneralUse.js',
                ],
                'default' => 'Intake FROM',
                'name' => ($this->txt['flTitle_IntakeInviteFrom'] ?? ''),
                'active' => 1,
                'sequence' => 55,
            ];
        }
        $seqList = [];
        foreach ($list as $l) {
            if ($activeOnly && !$l['active']) {
                continue;
            }
            if ($this->useSrc) {
                foreach ($l['files'] as $k => $v) {
                    if (stristr((string) $v, '/assets/js/src/TPM/')) {
                        $l['files'][$k] = str_replace('.js', '.src.js', (string) $v);
                    }
                }
            }
            $seqList[$l['sequence']] = $l;
        }
        ksort($seqList);
        return $seqList;
    }
}
