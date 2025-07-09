<?php
/**
 * Model for the main Fields/Lists data operations for Custom Labels.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;

/**
 * Class CustLabelsData handles basic data modeling for the TPM application fields/lists.
 * As requirements dictate, this class should not be extended by other data classes in order to
 * fulfill their own requirements.
 *
 * BE ADVISED: This class (and the entire Custom Labels section for that matter) are to be
 * superseded by the work of text translations. As that is not yet client facing, this section
 * is being updated for now so it may still be used as is. There will be no improvements as
 * requested within SEC-546, and this ONLY provides an update interface in the interim,
 * and ONLY in English. (It should be noted that as refactoring is dead in favor of the rewrite to node,
 * that the transition to translations will happen there, if applicable.)
 *
 * @keywords custom labels, tpm, fields lists, model, settings
 */
#[\AllowDynamicProperties]
class CustLabelsData extends TpmListsData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    protected $DB = null;

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
     * @var array General translation text array (set through parent method)
     */
    protected $txt = [];

    /**
     * @var object Current values
     */
    protected $values = null;

    /**
     * @var object Private instance of the TranslateTextData class. (Only called as needed, not set in USE)
     */
    private $trData = null;


    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $userID     Current userID
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $userID, $initValues = [])
    {
        parent::__construct($tenantID, $userID, $initValues);
        $this->app->trans->langCode = 'EN_US';
        $this->app->trans->tenantID = $this->tenantID;
        $this->setupTableNames(); // remove when moving off appLabel to full translation support
        $this->getValues();
    }

    /**
     * Setup required table names in the tbl object. This overwrites parent stub method.
     *
     * @return void
     */
    protected function setupTableNames()
    {
        $clientDB  = $this->DB->getClientDB($this->tenantID);
        $this->tbl = (object)null;
        $this->tbl->appLabel  = $clientDB . '.applicationLabel';
        $this->tbl->clProfile = $clientDB . '.clientProfile';
    }

    /**
     * Public wrapper to grab all records (resets main array keys to 0-based count.)
     *
     * @return object DB result object
     */
    public function getAll()
    {
        return array_values($this->values);
    }

    /**
     * Update a record
     *
     * @param array $vals Array of all allowed labels. (See: this->getValues() for array keys passed back.)
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function update($vals)
    {
        $this->trData = new \Models\ADMIN\Translation\TranslateTextData();
        $currentVals = $this->values;
        $err = [];
        foreach ($vals as $val) {
            $trID = (int)$val['trID'];
            if (!array_key_exists($trID, $currentVals)) {
                continue;
            }
            $current = $currentVals[$trID];
            $ovrRec = $this->trData->getOvrRecord($trID, $this->tenantID);
            $recID = 0;
            if ($ovrRec) {
                if ($val['labelText'] == $val['labelDefault']) {
                    $val['labelText'] = '';
                } elseif ($val['labelText'] == $val['oldLabelText'] || $val['labelText'] == $ovrRec['trans']) {
                    continue;
                }
                $recID = $ovrRec['id'];
            }
            if (!empty($val['labelText']) && !$this->validateLabel($trID, $val['labelName'], $val['labelText'])) {
                // for this class we'll still validate by legacy rules first.
                // the TranslateTextData model also validates.
                $err[] = '`' . $val['labelText'] . '` is not a valid label.';
                continue;
            }
            $args = [
                'tenantID' => $this->tenantID,
                'recID' => $recID,
                'trID' => $trID,
                'langCode' => 'EN_US',
                'translation' => $val['labelText'],
                'translator' => $this->app->session->get('user.userName', 'User (' . $this->userID . ')'),
            ];
            $this->trData->upsertOvrTranslation($args);
            if (empty($val['labelText'])) {
                $val['labelText'] = $val['labelDefault'];
            }
            $sessKey = $this->getLegacySessionKeyByLabel($current['labelName']);
            if (!empty($sessKey)) {
                $this->app->session->set($sessKey, $val['labelText']);
            }
            $this->updateLegacyCustomLabel($current, $val['labelText']);
        }
        $recs = array_values($this->getValues(true));
        if ($err) {
            return [
                'Result' => 0,
                'ErrTitle' => $this->txt['one_error'],
                'ErrMsg' => implode('<br />', $err),
                'Recs' => $recs,
            ];
        }
        return ['Result' => 1, 'Recs' => $recs];
    }

    /**
     * Restore all values to default. Nothing is actually removed/deleted.
     *
     * @return array Return array with result status, and error info if applicable.
     */
    public function resetAllToDefault()
    {
        $this->trData = new \Models\ADMIN\Translation\TranslateTextData();
        $values = $this->getValues();
        foreach ($values as $v) {
            $ovrRec = $this->trData->getOvrRecord($v['trID'], $this->tenantID);
            if (!$ovrRec) {
                // already default if no record.
                continue;
            }
            $args = [
                'tenantID' => $this->tenantID,
                'recID' => $ovrRec['id'],
                'trID' => $v['trID'],
                'langCode' => 'EN_US',
                'translation' => '',
                'translator' => $this->app->session->get('user.userName', 'User (' . $this->userID . ')'),
            ];
            $this->trData->upsertOvrTranslation($args);
        }
        $this->resetSessionValues();
        $this->resetAllLegacyValues();
        $updatedValues = $this->getValues(true);
        return ['Result' => 1, 'Recs' => array_values($updatedValues)];
    }

    /**
     * Reset session values to default.
     *
     * @return void
     */
    private function resetSessionValues()
    {
        foreach ($this->values as $val) {
            $sessKey = $this->getLegacySessionKeyByLabel($val['labelName']);
            if (!empty($sessKey)) {
                $this->app->session->set($sessKey, $val['labelText']);
            }
        }
    }

    /**
     * Validate label value
     *
     * @param integer $trID       g_txtTr.id
     * @param string  $name       desired text value
     * @param string  $labelValue this->getValues[][labelName]
     *
     * @return boolean True if valid, else false
     */
    private function validateLabel($trID, $name, $labelValue)
    {
        $vals = $this->getValues();
        if (empty($vals[$trID])) {
            return false;
        }
        $vals = $vals[$trID];
        if ($name == 'department' || $name == 'region') {
            $maxLength = 15;
        } else {
            $maxLength = $vals['maxLength'];
        }
        if ($labelValue == $vals['labelText'] || $labelValue == $vals['labelDefault']) {
            // default values always validate true.
            return true;
        }
        if (preg_match('/^[-a-z0-9_ ]{0,' . $maxLength . '}$/i', $labelValue)) {
            return true;
        }
        return false;
    }

    /**
     * Hard coded defaults, just like legacy. Adds in current translation values
     *
     * @param boolean $force [optional] Force a reload of this->values (update/restore)
     *
     * @return array
     */
    private function getValues($force = false)
    {
        if ((!is_null($this->values) || !empty($this->values)) && $force == false) {
            $this->values;
        }
        $clTxt = $this->app->trans->group('legacy_custom_labels');
        $values = [10110 => ['trID'         => 10110, 'labelName'    => 'customFieldsCase', 'trCodeKey'    => 'tab_case_Custom_Fields', 'labelText'    => $clTxt['tab_case_Custom_Fields'], 'labelDefault' => 'Custom Fields', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Case Custom Fields', 'labelDesc'    => 'Displayed on the Custom Fields Tab in Case Folder.'], 10765 => ['trID'         => 10765, 'labelName'    => 'legalName', 'trCodeKey'    => 'profDetail_official_company_name', 'labelText'    => $clTxt['profDetail_official_company_name'], 'labelDefault' => 'Official Company Name', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => '3P Official Company Name', 'labelDesc'    => 'Displayed on the Summary Tab in Profile Detail.'], 10764 => ['trID'         => 10764, 'labelName'    => 'DBAname', 'trCodeKey'    => 'profDetail_alt_trade_name_s', 'labelText'    => $clTxt['profDetail_alt_trade_name_s'], 'labelDefault' => 'Alternate Trade Name(s)', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => '3P Alternate Trade Name(s)', 'labelDesc'    => 'Displayed on the Summary Tab in Profile Detail.'], 10761 => ['trID'         => 10761, 'labelName'    => 'billingUnit', 'trCodeKey'    => 'case_billing_unit', 'labelText'    => $clTxt['case_billing_unit'], 'labelDefault' => 'Billing Unit', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Billing Unit', 'labelDesc'    => 'Displayed in Case Conversion Review/Confirm.'], 10762 => ['trID'         => 10762, 'labelName'    => 'purchaseOrder', 'trCodeKey'    => 'case_purchase_order', 'labelText'    => $clTxt['case_purchase_order'], 'labelDefault' => 'Purchase Order', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Purchase Order', 'labelDesc'    => 'Displayed in Case Conversion Review/Confirm.'], 10112 =>  ['trID'         => 10112, 'labelName'    => 'caseNotes', 'trCodeKey'    => 'tab_case_Notes', 'labelText'    => $clTxt['tab_case_Notes'], 'labelDefault' => 'Notes', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Case Notes', 'labelDesc'    => 'Displayed on the Notes Tab in Case Folder.'], 99999 =>  ['trID'         => 99999, 'labelName'    => 'Remediation', 'trCodeKey'    => 'fld_Remediation', 'labelText'    => $clTxt['fld_Remediation'], 'labelDefault' => 'Remediation', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Remediation', 'labelDesc'    => 'Displayed on the 3P Monitor Detail.']];

        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM)) {
            // Only if Thirdparty is enabled
            $values[10092] = ['trID'         => 10092, 'labelName'    => 'customFields', 'trCodeKey'    => 'tab_3p_Custom_Fields', 'labelText'    => $clTxt['tab_3p_Custom_Fields'], 'labelDefault' => 'Custom Fields', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => '3P Custom Fields', 'labelDesc'    => 'Displayed on the Custom Fields Tab in Profile Detail.'];

            // Only if Compliance is enabled
            if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_COMPLIANCE)) {
                $values[10088] = ['trID'         => 10088, 'labelName'    => 'compliance', 'trCodeKey'    => 'tab_Compliance', 'labelText'    => $clTxt['tab_Compliance'], 'labelDefault' => 'Compliance', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Compliance', 'labelDesc'    => 'Displayed on the Compliance Tab in Profile Detail.'];
            }
            $values[10763] = ['trID'         => 10763, 'labelName'    => 'approvalStatus', 'trCodeKey'    => 'approval_status', 'labelText'    => $clTxt['approval_status'], 'labelDefault' => 'Approval Status', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Approval Status', 'labelDesc'    => 'Displayed at the top of Profile Detail and when selecting Status.'];
            $values[10722] = ['trID'         => 10722, 'labelName'    => 'status', 'trCodeKey'    => 'case_status', 'labelText'    => $clTxt['case_status'], 'labelDefault' => 'Status', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Status', 'labelDesc'    => 'Displayed under the approval icon for Approval Status.'];
            $values[10039] = ['trID'         => 10039, 'labelName'    => 'internalCode', 'trCodeKey'    => 'fld_Internal_Code', 'labelText'    => $clTxt['fld_Internal_Code'], 'labelDefault' => 'Internal Code', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => 'Internal Code', 'labelDesc'    => 'Displayed on the Third Party Record.'];

            $values[10094] = ['trID'         => 10094, 'labelName'    => 'tpNotes', 'aliasName'    => '3pNotes', 'trCodeKey'    => 'tab_3p_Notes', 'labelText'    => $clTxt['tab_3p_Notes'], 'labelDefault' => 'Notes', 'maxLength'    => 25, 'langCode'     => 'EN_US', 'friendlyName' => '3P Notes', 'labelDesc'    => 'Displayed on the Notes Tab in 3P Profile Detail.'];
        }
        if ($this->app->ftr->has(\Feature::SETTINGS_ARCHITECTURE)) {
            $values[10044] = ['trID'         => 10044, 'labelName'    => 'region', 'trCodeKey'    => 'fld_Region', 'labelText'    => $clTxt['fld_Region'], 'labelDefault' => 'Region', 'maxLength'    => 15, 'langCode'     => 'EN_US', 'friendlyName' => 'Region Title', 'labelDesc'    => 'Displayed in Third Party List, Profile Details, and Case Folder.'];
            $values[10040] = ['trID'         => 10040, 'labelName'    => 'department', 'trCodeKey'    => 'fld_Department', 'labelText'    => $clTxt['fld_Department'], 'labelDefault' => 'Department', 'maxLength'    => 15, 'langCode'     => 'EN_US', 'friendlyName' => 'Department Title', 'labelDesc'    => 'Displayed in Third Party List, Profile Details, and Case Folder.'];
        }
        $this->values = [];
        $values = $this->mergeLegacyValues($values);
        $this->values = $values;
        return $values;
    }

    /**
     * Merge existing legacy values from appLabel (and clientProfile, if client has architecture access)
     *
     * @param array $values array of custom labels
     *
     * @return array Updated array to account for legacy custom labels
     */
    private function mergeLegacyValues($values)
    {
        if (!$this->trData) {
            $this->trData = new \Models\ADMIN\Translation\TranslateTextData();
        }
        // get legacy values
        $legacy = $this->getLegacyLabels($values); // accounts for reg/dept from clientProfile
        foreach ($values as $trID => $val) {
            $trCodeKey = $val['trCodeKey'];
            if (isset($legacy[$trCodeKey])) {
                $legacyVal = $legacy[$trCodeKey]['value'];
                $legacyID  = $legacy[$trCodeKey]['legacyID'];
                if ($legacy[$trCodeKey]['value'] !== $val['labelText']) {
                    // translation does not match legacy. we need to make sure it does.
                    // first see if an override already exists
                    $ovrRec = $this->trData->getOvrRecord($trID, $this->tenantID);
                    $recID  =  (($ovrRec) ? $ovrRec['id'] : 0);
                    $args = [
                        'tenantID' => $this->tenantID,
                        'recID' => $recID,
                        'trID' => $trID,
                        'langCode' => 'EN_US',
                        'translation' => $legacy[$trCodeKey]['value'],
                        'translator' => $this->app->session->get('user.userName', 'User (' . $this->userID . ')'),
                    ];
                    $this->trData->upsertOvrTranslation($args);
                }
                // now update our value array with the correct text, and add in the legacy ID.
                $values[$trID]['labelText'] = $legacyVal;
                $values[$trID]['legacyID']  = $legacyID;
            }
        }
        return $values;
    }

    /**
     * Get all legacy custom label values (both applicationLabel table, and architecture values)
     *
     * @param array $values Array from this->getValues BEFORE merging legacy values
     *
     * @return array Return array of legacy values to be merged (does not return $values)
     */
    private function getLegacyLabels($values)
    {
        $legacy = $this->DB->fetchAssocRows(
            "SELECT id, labelName, labelText FROM {$this->tbl->appLabel} WHERE clientID = :cID",
            [':cID' => $this->tenantID]
        );
        $arch = [];
        if ($this->app->ftr->has(\Feature::SETTINGS_ARCHITECTURE)) {
            $arch = $this->DB->fetchAssocRow(
                "SELECT regionTitle AS reg, departmentTitle AS dept FROM {$this->tbl->clProfile} WHERE id = :cID",
                [':cID' => $this->tenantID]
            );
            // remember that the $legacy array is not associative, so we just tack on to follow the same format.
            if (!empty($arch['reg'])) {
                $legacy[] = [
                    'id' => 0,
                    'labelName'    => 'region',
                    'labelText'    => $arch['reg'],
                ];
            }
            if (!empty($arch['dept'])) {
                $legacy[] = [
                    'id' => 0,
                    'labelName'    => 'department',
                    'labelText'    => $arch['dept'],
                ];
            }
        }
        if (empty($legacy)) {
            return [];
        }
        return $this->mapLegacyKeysToTranslations($legacy);
    }

    /**
     * Update legacy applicationLabels table to keep it in sync with text translations.
     *
     * @param array  $current Currently stored values
     * @param string $newText New label text
     *
     * @return void
     */
    private function updateLegacyCustomLabel($current, $newText)
    {
        if (in_array($current['labelName'], ['region','department'])) {
            $field = $current['labelName'] . 'Title';
            $this->DB->query(
                "UPDATE {$this->tbl->clProfile} SET {$field} = :val WHERE id = :cID LIMIT 1",
                [':val' => $newText, ':cID' => $this->tenantID]
            );
        } elseif (!empty($current['legacyID'])) {
            $this->DB->query(
                "UPDATE {$this->tbl->appLabel} SET labelText = :newText WHERE id = :id AND clientID = :clientID",
                [':newText' => $newText, ':id' => (int)$current['legacyID'], ':clientID' => $this->tenantID]
            );
        } else {
            $this->DB->query(
                "INSERT INTO {$this->tbl->appLabel} SET "
                    . "labelName = :labelName, "
                    . "labelText = :labelText, "
                    . "clientID  = :clientID, "
                    . "maxLength = :maxLength, "
                    . "langCode  = :langCode ",
                [
                    ':labelName' => $current['labelName'],
                    ':labelText' => $newText,
                    ':clientID'  => $this->tenantID,
                    ':maxLength' => $current['maxLength'],
                    ':langCode'  => 'EN_US',
                ]
            );
        }
        $sessKey = $this->getLegacySessionKeyByLabel($current['labelName']);
        if (!empty($sessKey)) {
            // working directly with _SESSION is not allowed. However, we have to update the
            // legacy session (which was directly manipulated) so we must do this for now.
            if (isset($_SESSION[$sessKey])) {
                unset($_SESSION[$sessKey]);
            }
            $_SESSION[$sessKey] = $newText;
        }
    }

    /**
     * Reset all legacy values to the default
     *
     * @return void
     */
    private function resetAllLegacyValues()
    {
        $this->DB->query(
            "DELETE FROM {$this->tbl->appLabel} WHERE clientID = :clientID",
            [':clientID' => $this->tenantID]
        );
        foreach ($this->values as $vals) {
            if (in_array($vals['labelName'], ['region', 'department'])) {
                $field = $vals['labelName'] . 'Title';
                $this->DB->query(
                    "UPDATE {$this->tbl->clProfile} SET {$field} = :val WHERE id = :cID",
                    [':val' => $vals['labelDefault'], ':cID' => $this->tenantID]
                );
            } else {
                $this->DB->query(
                    "INSERT INTO {$this->tbl->appLabel} SET labelName = :lbl, labelText = :txt, clientID = :cID",
                    [':lbl' => $vals['labelName'], ':txt' => $vals['labelDefault'], ':cID' => $this->tenantID]
                );
            }
            $sessKey = $this->getLegacySessionKeyByLabel($vals['labelName']);
            if (!empty($sessKey)) {
                // working directly with _SESSION is not allowed. However, we have to update the
                // legacy session (which was directly manipulated) so we must do this for now.
                unset($_SESSION[$sessKey]);
                $_SESSION[$sessKey] = $vals['labelDefault'];
            }
        }
    }

    /**
     * Map legacy result into array with keys matching deltal translation result
     *
     * @param array $legacy Legacy values from the applicationLabel table
     *
     * @return array Array of established values
     */
    private function mapLegacyKeysToTranslations($legacy)
    {
        $mapKeys = $this->generateKeyMap();
        $map     = [];
        foreach ($legacy as $l) {
            $trCodeKey = $mapKeys[$l['labelName']];
            $map[$trCodeKey] = [
                'legacyID' => $l['id'],
                'label'    => $l['labelName'],
                'value'    => $l['labelText'],
            ];
        }
        return $map;
    }

    /**
     * Return a key/value array of a legacy labelName to translation codeKey map, or the reverse.
     *
     * @param boolean $fromLegacy Set true to use the legacy labelName as the array key, else false to use trCodeKey
     *
     * @return array
     */
    private function generateKeyMap($fromLegacy = true)
    {
        $labelMap = [
            ['legacyLabelName' => 'customFieldsCase', 'trCodeKey' => 'tab_case_Custom_Fields'],
            ['legacyLabelName' => 'legalName', 'trCodeKey' => 'profDetail_official_company_name'],
            ['legacyLabelName' => 'DBAname', 'trCodeKey' => 'profDetail_alt_trade_name_s'],
            ['legacyLabelName' => 'billingUnit', 'trCodeKey' => 'case_billing_unit'],
            ['legacyLabelName' => 'purchaseOrder', 'trCodeKey' => 'case_purchase_order'],
            ['legacyLabelName' => 'caseNotes', 'trCodeKey' => 'tab_case_Notes'],
            ['legacyLabelName' => 'customFields', 'trCodeKey' => 'tab_3p_Custom_Fields'],
            ['legacyLabelName' => 'compliance', 'trCodeKey' => 'tab_Compliance'],
            ['legacyLabelName' => 'approvalStatus', 'trCodeKey' => 'approval_status'],
            ['legacyLabelName' => 'status', 'trCodeKey' => 'case_status'],
            ['legacyLabelName' => 'internalCode', 'trCodeKey' => 'fld_Internal_Code'],
            ['legacyLabelName' => 'tpNotes', 'trCodeKey' => 'tab_3p_Notes'],
            ['legacyLabelName' => 'region', 'trCodeKey' => 'fld_Region'],
            ['legacyLabelName' => 'department', 'trCodeKey' => 'fld_Department'],
            ['legacyLabelName' => 'Remediation', 'trCodeKey' => 'fld_Remediation'],
        ];
        $from = (($fromLegacy == true) ? 'legacyLabelName' : 'trCodeKey');
        $to   = (($from == 'legacyLabelName') ? 'trCodeKey' : 'legacyLabelName');
        $map = [];
        foreach ($labelMap as $m) {
            $map[$m[$from]] = $m[$to];
        }
        return $map;
    }

    /**
     * Map legacy labelName to session key
     *
     * @param string $labelName The legacy label name
     *
     * @return string
     */
    private function getLegacySessionKeyByLabel($labelName)
    {
        $map = [
            'department'       => 'departmentTitle',
            'region'           => 'regionTitle',
            'billingUnit'      => 'customLabel-billingUnit',
            'purchaseOrder'    => 'customLabel-purchaseOrder',
            'customFieldsCase' => 'customLabel-customFieldsCase',
            'caseNotes'        => 'customLabel-caseNotes',
            'internalCode'     => 'customLabel-internalCode',
            'customFields'     => 'customLabel-customFields',
            'compliance'       => 'customLabel-compliance',
            'approvalStatus'   => 'customLabel-approvalStatus',
            'status'           => 'customLabel-status',
            'legalName'        => 'customLabel-legalName',
            'DBAname'          => 'customLabel-DBAname',
            'tpNotes'          => 'customLabel-tpNotes',
            'Remediation'      => 'customLabel-Remediation',
        ];
        return ((!empty($map[$labelName])) ? $map[$labelName] : '');
    }
}
