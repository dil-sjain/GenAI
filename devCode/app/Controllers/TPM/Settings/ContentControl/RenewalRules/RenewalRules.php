<?php
/**
 * Endpoint for Settings/ContentControl/RenewalRules
 */

namespace Controllers\TPM\Settings\ContentControl\RenewalRules;

use Models\TPM\Settings\ContentControl\RenewalRules\RenewalRules as RulesData;
use Models\TPM\IntakeForms\DdqName;
use Models\API\Endpoints\CustomField;
use Models\TPM\RiskModel\RiskTier;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\TPM\TpProfile\TpType;
use Models\LogData;
use Lib\Traits\AjaxDispatcher;
use Lib\Validation\ValidateFuncs;

#[\AllowDynamicProperties]
class RenewalRules
{
    use AjaxDispatcher;

    /**
     * Time period range 0 - 2557 (7 years)
     */
    public const MIN_DAYS = 0;
    public const MAX_DAYS = 2557;

    /**
     * @var object Application instance
     */
    private $app = null;

    private $clientID = null;

    /**
     * Data accessor
     *
     * @var RulesData instance
     */
    private $accessor = null;

    /**
     * Template base dir
     *
     * @var string From app/Views
     */
    private $templateBase = 'TPM/Settings/ContentControl/RenewalRules/';


    /**
     * Initialize instance
     *
     * @param array $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($initValues = [])
    {
        $this->app  = \Xtra::app();
        if ($this->clientID = $this->app->ftr->tenant) {
            $this->accessor = new RulesData($this->clientID);
        } else {
            throw new \Exception('Invalid client identifier');
        }
    }

    /**
     * Validate and upsert renewal rule rule
     *
     * @return void
     */
    private function ajaxSaveRule()
    {
        // Get user input
        $ruleID       = (int)$this->getPostVar('rid', 0);  // renewalRule.id
        $name         = trim((string) $this->getPostVar('nm', '')); // rule name
        $dateModifier = (int)$this->getPostVar('dm', 0);   // customField.id
        $formRef      = $this->getPostVar('frm', '');      // Intake form (ddq.legacyID)
        $days         = (int)$this->getPostVar('dys', 0);  // time period (days)
        $active       = (int)$this->getPostVar('act', 0) ? 1 : 0; // db bool
        $modifierIsAbsolute = (int)$this->getPostVar('dmt', 0) ? 1 : 0; // db bool
        $renewalCaseType    = (int)$this->getPostVar('ct', 0); // cases.caseType (for analysis only)

        $errors  = [];
        $origRec = null;
        if ($ruleID === 0) {
            $risk         = (int)$this->getPostVar('rr', 0);   // Risk Rating (riskTier.id)
            $tpType       = (int)$this->getPostVar('typ', 0);  // 3P type
            $tpCategory   = (int)$this->getPostVar('cat', 0);  // 3P category
            $dateField    = $this->getPostVar('df', '');       // statChg = 3P appr. change date
            $this->validateRuleName($errors, $name, 0);
        } else {
            // Validate Rule Name
            if ($origRec = $this->validateRuleName($errors, $name, $ruleID)) {
                $risk = $origRec['risk'];
                $tpType = $origRec['tpType'];
                $tpCategory = $origRec['tpCategory'];
                $dateField = $origRec['dateField'];
            }
        }
        $hashBasis = '' . $this->clientID . $risk . $tpType . $tpCategory . $dateField;
        if ($dateField === 'exclude') {
            // These values don't apply to an 'exclude' record
            $days = 0;
            $dateModifier = 0;
            $modifierIsAbsolute = 0;
            $renewalCaseType = 0;
        } elseif ($dateField !== 'invDone') {
            $renewalCaseType = 0; // applies only to Investigation Complletion Date track
        }
        // Set rule rank
        if ($risk && $tpType && $tpCategory) {
            $ruleRank = 1;
        } elseif ($risk && $tpType) {
            $ruleRank = 2;
        } elseif ($risk) {
            $ruleRank = 3;
        } elseif ($tpCategory) {
            $ruleRank = 4;
        } elseif ($tpType) {
            $ruleRank = 5;
        } else {
            $ruleRank = 6; // risk, type, and cat all = 0
        }
        $origFormRef  = $origRec ? $origRec['formRef'] : false;
        $origModifier = $origRec ? $origRec['dateModifier'] : false;

        // Generate binary SHA1 rule hash for duplicate prevention in lieu of 7-field composite index
        $dupMsg = 'A rule already exists for this combination of '
            . 'Risk Rating, 3P Type, 3P Category, and Renewal Track.';
        if ($dateField === 'frmSub') {
            $dupMsg = 'A rule already exists for this combination of '
                . 'Risk Rating, 3P Type, 3P Category, Renewal Track, and Form Name.';
            $hashBasis .= $formRef;
        } elseif ($dateField === 'tpCF') {
            $dupMsg = 'A rule already exists for this combination of '
                . 'Risk Rating, 3P Type, 3P Category, Renewal Track, and Date Field.';
            $hashBasis .= $dateModifier;
        }
        $ruleHash = sha1($hashBasis, true);

        // Validate Other Inputs
        $this->validateDateModifier($errors, $dateModifier, $tpCategory, $origModifier, $dateField, $active);
        $this->validatePeriod($errors, $days);

        if ($ruleID === 0) {
            $this->validateRiskTier($errors, $risk);
            $this->validateCategory($errors, $tpCategory, $tpType); // also validates 3P Type
        }
        // Date field also validates form reference, if applicable
        $this->validateDateField($errors, $dateField, $formRef, $origFormRef, $active);

        // Stop if validation fails
        if ($errors) {
            $this->jsObj->ErrTitle = 'Input Error(s)';
            $this->jsObj->MultiErr = $errors;
            return;
        }

        try {
            if ($ruleID === 0) {
                // Insert new rule record
                $created = date('Y-m-d H:i:s');
                $setValues = compact(
                    'name',
                    'dateField',
                    'dateModifier',
                    'modifierIsAbsolute',
                    'formRef',
                    'renewalCaseType',
                    'days',
                    'risk',
                    'tpType',
                    'tpCategory',
                    'ruleRank',
                    'active',
                    'ruleHash',
                    'created'
                );
                if (!($newID = $this->accessor->insert($setValues))) {
                    $this->jsObj->ErrTitle = 'Operation Failed';
                    $this->jsObj->ErrMsg = 'A database error occured';
                    return;
                }

                // Audit log
                $tierName = $this->accessor->getRiskTierName($risk);
                $track = $this->accessor->getDateFieldName($dateField);
                $logMsg = "Rule (#$newID) - track: `$track`; rank `$ruleRank`; risk: `$tierName`; name: `$name`";
                $logEvent = 183; // New Renewal Rule
                $appNotice = 'New rule added';
            } else {
                $setValues = compact(
                    'name',
                    'dateModifier',
                    'modifierIsAbsolute',
                    'renewalCaseType',
                    'formRef',
                    'days',
                    'active',
                    'ruleHash'
                );
                if ($origRec['active'] !== $active) {
                    $setValues['deleted'] = $active ? null : date('Y-m-d H:i:s');
                }
                $updated = $this->accessor->updateByID($ruleID, $setValues);
                if ($updated === false) {
                    $this->jsObj->ErrTitle = 'Operation Failed';
                    $this->jsObj->ErrMsg = 'A database error occured';
                    return;
                }
                if ($updated === 0) {
                    $this->jsObj->ErrTitle = 'Nothing to Do';
                    $this->jsObj->ErrMsg = 'No rule properties were changed';
                    return;
                }

                // Audit log details, compare with origRec
                $logMsg = $this->accessor->makeUpdateLogMessage($setValues, $origRec);
                $logEvent = 184; // Update Renewal Rule
                $appNotice = 'Rule updated';
            }

            // Add Audit Log record and notify user
            $auditLog = new LogData($this->clientID, $this->app->ftr->user);
            $auditLog->saveLogEntry($logEvent, $logMsg);
            $this->jsObj->AppNotice = [$appNotice];

            // Signal success
            $this->jsObj->Result = 1;
        } catch (\PDOException $pex) {
            // PDOException::getCode() returns a string, not an int
            if ($pex->getCode() === '23000' && str_contains($pex->getMessage(), 'uniqRule')) {
                $this->jsObj->ErrTitle = 'Duplicate Rule';
                $where = ['ruleHash' => $ruleHash];
                if ($dupRule   = $this->accessor->selectOne(['name', 'active'], $where)) {
                    $dupMsg .= '<div class="marg-top1e">See ' . ($dupRule['active'] ? 'active' : 'inactive')
                        . " rule named <strong>{$dupRule['name']}</strong>.</p>";
                }
                $this->jsObj->ErrMsg = $dupMsg;
            } else {
                $this->jsObj->ErrTitle = 'Operation Failed';
                $this->jsObj->ErrMsg = 'A database error occurred';
            }
            return; // not necessary, but keep it in case other code is added beyone this point
        }
    }

    /**
     * Return dialog html for adding a new rule
     *
     * @param string $track Renewal track (dataField)
     *
     * @return void
     */
    private function addRuleDialog($track)
    {
        $html = $this->app->view->fetch($this->templateBase . 'AddDialog.tpl', ['track' => $track]);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [$html];
    }

    /**
     * Return dialog html and data for editing an existing rule
     *
     * @param int    $ruleID renewalRules.id
     * @param string $track  Renewal track (dateField)
     *
     * @return void
     */
    private function editRuleDialog($ruleID, $track)
    {
        // does the rule exist?
        if (!($ruleRec = $this->accessor->selectByID($ruleID))) {
            $this->jsObj->ErrMsg = 'Requested Renewal Rule not found.';
            $this->jsObj->ErrTitle = 'Operation Failed';
            return;
        }

        // get html from template
        $html = $this->app->view->fetch($this->templateBase . 'EditDialog.tpl', ['track' => $track]);

        unset($ruleRec['ruleHash']); // binary string breaks ajax response
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [$html, $ruleRec];
    }

    /**
     * Return dialog html and data
     *
     * @return void
     */
    private function ajaxDialog()
    {
        $track = $this->getPostVar('df', 'statChg'); // renewal track (dateField)
        if ($ruleID = (int)$this->getPostVar('r', 0)) {
            $this->editRuleDialog($ruleID, $track);
        } else {
            $this->addRuleDialog($track);
        }
    }

    /**
     * Return rules matcihng targeted profile group
     *
     * @return void
     */
    private function ajaxMatchingRules()
    {
        $risk = (int)$this->getPostVar('r', 0);
        $tpType = (int)$this->getPostVar('t', 0);
        $tpCategory = (int)$this->getPostVar('c', 0);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [
            $this->accessor->getRulesByProfileGroup($risk, $tpType, $tpCategory),
        ];
    }

    /**
     * Return dialog html and matching rules for match dialog
     *
     * @return void
     */
    private function ajaxMatchDialog()
    {
        $risk = (int)$this->getPostVar('r', 0);
        $tpType = (int)$this->getPostVar('t', 0);
        $tpCategory = (int)$this->getPostVar('c', 0);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [
            $this->app->view->fetch($this->templateBase . 'ProfileGroupRules.tpl', []),
            $this->accessor->getRulesByProfileGroup($risk, $tpType, $tpCategory),
        ];
    }

    /**
     * Fetch and return required data
     *
     * @return void
     */
    private function ajaxRefresh()
    {
        $dateField = $this->getPostVar('df', 'statChg');
        $init = (int)$this->getPostVar('ini', 0);
        $initTable = (int)$this->getPostVar('initbl', 0);
        $initData = 0;
        if ($init) {
            $initData = $this->accessor->getRelatedData();
        }
        $tblCols = 0;
        if ($initTable) {
            $tblCols = [
                'Risk',
                '3P Type',
                '3P Category',
                'Rank',
                'Name',
            ];
            if ($dateField !== 'exclude') {
                $tblCols[] = 'Days';
                if ($dateField !== 'tpCF') {
                    $tblCols[] = 'Date Modifier';
                }
            }
            if ($dateField === 'frmSub') {
                $tblCols[] = 'Form Name';
            } elseif ($dateField === 'invDone') {
                $tblCols[] = 'Case Type';
            } elseif ($dateField === 'tpCF') {
                $tblCols[] = 'Date Field';
            }
        }
        $this->jsObj->Result= 1;
        $this->jsObj->Args = [
            $this->accessor->countRulesPerRenewalTrack(),  // track counts
            $this->accessor->getRulesForRenewalTrack($dateField), // rules
            $initData, // 0 or assoc array of tiers, types, categories, custom fields, intake forms, case types
            $tblCols,  // 0 or array of table column headings
        ];
    }

    /**
     * Set layout framework and load .js and .css files
     *
     * @return void
     */
    private function ajaxInitRenewalRules()
    {
        // On ajax response this html is inserted into tab content and script is executed.
        $html = $this->app->view->fetch($this->templateBase . 'TabContent.tpl', []);
        $this->jsObj->Args   = ['html' => $html];
        $this->jsObj->Result = 1;
    }

    /**
     * Test input for unique rule name
     *
     * @param array  &$errors Accumulative field/error messages
     * @param string $name    renewalRule.name
     * @param int    $ruleID  renewalRule.id
     *
     * @return mixed array rule record or null
     */
    private function validateRuleName(&$errors, $name, $ruleID)
    {
        $validateFuncs = new ValidateFuncs();
        // Rule exists
        $ruleRec = false;
        if ($ruleID) {
            if (!($ruleRec = $this->accessor->selectByID($ruleID))) {
                $this->addMultiError($errors, 'Rule Identifier', 'Invalid rule reference');
            }
        }

        // Unique name?
        $nameLength = mb_strlen($name);
        if ($nameLength < 1 || $nameLength > 255) {
            $this->addMultiError($errors, 'Name', 'Rule name must be between 1 and 255 characters');
        } else if (!$validateFuncs->checkInputSafety($name)) {
            $this->addMultiError($errors, 'Name', 'Rule name contains invalid characters like html tags or javascript');
        } else {
            $row = $this->accessor->selectOne(['id'], ['name' => $name]);
            $dup = false;
            if ($ruleID) {
                // can only match itself
                if ($row && ($row['id'] !== $ruleID)) {
                    $dup = true;
                }
            } elseif ($row) {
                // name should not already exist for new rule
                $dup = true;
            }
            if ($dup) {
                $dupErr = 'Name is already in use by another rule.';
                if ($dupRec =$this->accessor->selectOne(['name', 'dateField', 'active'], ['name' => $name])) {
                    $track = $this->accessor->getDateFieldName($dupRec['dateField']);
                    $dupErr .= "  See " . ($dupRec['active'] ? 'active': 'inactive')
                        . " rule named <strong>{$dupRec['name']}</strong> "
                        . "under <strong>$track</strong> Renewal Track.";
                }
                $this->addMultiError($errors, 'Name', $dupErr);
            }
        }

        return $ruleRec;
    }

    /**
     * Test input for risk rating (tier)
     *
     * @param array &$errors Accumulative field/error messages
     * @param int   $tier    riskTier.id
     *
     * @return void
     */
    private function validateRiskTier(&$errors, $tier)
    {
        if ($tier) {
            $mdl = new RiskTier($this->clientID);
            if ($mdl->selectValueByID($tier, 'id') !== $tier) {
                $this->addMultiError($errors, 'Risk Rating', 'Risk tier not found');
            }
        }
    }

    /**
     * Test input for number of days in time period
     *
     * @param array &$errors Accumulative field/error messages
     * @param int   $days    Number of days in time period for date comparison
     *
     * @return void
     */
    private function validatePeriod(&$errors, $days)
    {
        $validateFuncs = new ValidateFuncs();
        if (!$validateFuncs->isNumeric($days)) {
            $this->addMultiError($errors, 'Time Period', 'Time Period must be a number');
        }
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            $msg = 'Must be between ' . self::MIN_DAYS . ' and ' . self::MAX_DAYS;
            $this->addMultiError($errors, 'Time Period', $msg);
        }
    }

    /**
     * Test input for 3P Type and Caegory
     *
     * @param array &$errors    Accumulative field/error messages
     * @param int   $tpCategory tpTypCategory.id
     * @param int   $tpType     tpType.id
     *
     * @return void
     */
    private function validateCategory(&$errors, $tpCategory, $tpType)
    {
        if ($tpCategory) {
            $mdl = new TpTypeCategory($this->clientID);
            if ($mdl->selectValueByID($tpCategory, 'tpType') !== $tpType) {
                $this->addMultiError($errors, '3P Category', 'Category not found');
            }
        } elseif ($tpType) {
            $mdl = new TpType($this->clientID);
            if ($mdl->selectValueByID($tpType, 'id') !== $tpType) {
                $this->addMultiError($errors, '3P Type', 'Type not found');
            }
        }
    }

    /**
     * Test input for date field and intake form referennce or custom field, as needed
     *
     * @param array  &$errors     Accumulative field/error messages
     * @param string $dateField   'statChg', 'frmSub', or 'cf[id#]'
     * @param string $formRef     ddqName.legacyID
     * @param mixed  $origFormRef What the record holds now, or false on new rule
     * @param bool   $active      Rule is being set to active (1) or inactive (0)
     *
     * @return void
     */
    private function validateDateField(&$errors, $dateField, $formRef, mixed $origFormRef, $active)
    {
        // Valid date field?
        $fld = 'Start Date Field';
        $allow = [];
        $dateFields = $this->accessor->getDateFields();
        foreach ($dateFields as $rec) {
            $allow[] = $rec['abbrev'];
        }
        if (!in_array($dateField, $allow)) {
            $this->addMultiError($errors, $fld, 'Unrecognized field');
            return;
        } elseif (empty($formRef)) {
            return;
        }

        if ($dateField === 'frmSub') {
            // Intake form must exist and be active
            $fld2 = 'Form Name';
            $where = ['legacyID' => $formRef];
            if ($formRef !== '') {
                $formStatus = (new DdqName($this->clientID))->selectValue('status', $where);
                if ($formStatus === 0) {
                    // allow inactive form only if it's already in the current record and rule is not active
                    if (($formRef !== $origFormRef) || $active) {
                        $this->addMultiError($errors, $fld2, 'Intake form is not active');
                    }
                } elseif ($formStatus !== 1) {
                    $this->addMultiError($errors, $fld2, 'Intake form not found');
                }
            }
        }
    }

    /**
     * Test input for date modifier (custom date field)
     *
     * @param array  &$errors      Accumulative field/error messages
     * @param string $dateModifier ddqName.legacyID
     * @param int    $tpCategory   tpTypeCategory.id
     * @param mixed  $origFormRef   What the record holds now, or false on new rule
     * @param string $dateField    If 'tpCF' dateModifier cannot be 0
     * @param bool   $active       Rule is being set to active (1) or inactive (0)
     *
     * @return void
     */
    private function validateDateModifier(&$errors, $dateModifier, $tpCategory, $origModifier, $dateField, $active)
    {
        $fld = 'Date Modifier'; // Label for validation error

        // On update, allow keeping previouly validated modifier; needed if only sometihng like name is changing
        if ($dateField === 'tpCF') {
            if (!empty($dateModifier) && ($dateModifier === $origModifier) && !$active) {
                // Bypass validation only if modifier is unchanged and rule is inactive
                return;
            } elseif (empty($dateModifier)) {
                $this->addMultiError(
                    $errors,
                    $fld,
                    'Cannot be <strong>(none)</strong> when Start Date Field is 3P Custom Date Field'
                );
                return;
            }
        } elseif (empty($dateModifier) || (($dateModifier === $origModifier) && !$active)) {
            // Bypass validation for empty modifier or if modifier is unchanged and rule is inactive
            return;
        }

        // 3P cutomer field must exist and be visible for this 3P category
        $cfMdl = new CustomField($this->clientID);
        if ($rows = $cfMdl->get3pCustomFieldDefs(false, 0, $dateModifier)) {
            // Must be a date field and not excluded in 3P Category
            if ($rows[0]['type'] !== 'date') {
                $this->addMultiError($errors, $fld, 'Custom field must be date type');
            } elseif ($tpCategory) {
                $rows = $cfMdl->get3pCustomFieldDefs(false, $tpCategory, $dateModifier);
                if (empty($rows)) {
                    $this->addMultiError($errors, $fld, 'Custom field is excluded in 3P Category');
                }
            }
        } else {
            $this->addMultiError($errors, $fld, 'Custom field not found');
        }
    }
}
