<?php
/**
 * Read/write access to applicationLabels
 */

namespace Models\TPM;

use Models\ThirdPartyManagement\Subscriber;

/**
 * Read/write access to applicationLabels
 *
 * @keywords custom label, applicationLables, label
 */
#[\AllowDynamicProperties]
class SubscriberCustomLabels extends \Models\BaseLite\RequireClientID
{
    
    public const DEFAULT_OPEN_DELIMITER = '{';
    public const DEFAULT_CLOSE_DELIMITER = '}';
    public const DEFAULT_LANG = 'EN_US';

    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'applicationLabel';

    /**
     * @var array Default Delimiters
     */
    protected $defaultDelimiters = [
        self::DEFAULT_OPEN_DELIMITER,
        self::DEFAULT_CLOSE_DELIMITER
    ];

    protected $defaults = [
        self::DEFAULT_LANG => [
            // General
            'departmentTitle'  => 'Department',
            'regionTitle'      => 'Region',
            // Case
            'billingUnit'      => 'Billing Unit',
            'purchaseOrder'    => 'Purchase Order', // billingPO
            'customFieldsCase' => 'Custom Fields',  // Case Custom Fields
            'caseNotes'        => 'Notes',
            
        ]
    ];

    /**
     * @var array Loaded label tokens (with delimiters)
     */
    protected $tokens  = null;

    /**
     * @var array Loaded label values
     */
    protected $values  = null;

    /**
     * @var array Active delimiters
     */
    protected $delim  = null;

    /**
     * @var string Currently loaded language
     */
    protected $lang  = null;

    /**
     * @var integer Number of times labels were loaded from db
     */
    protected $timesLoaded = 0;

    /**
     * Read-only access to protected vars
     *
     * @param string $prop Property name
     *
     * @return null or value of property
     */
    public function __get($prop)
    {
        $rtn = match ($prop) {
            'tokens', 'values', 'delim', 'lang', 'defaultDelimiters', 'timesLoaded' => $this->$prop,
            default => null,
        };
        return $rtn;
    }



    /**
     * Get assoc array of all custom label values
     *
     * @see Refactor of getLabels method in public/cms/includes/php/class_customLabels.php
     *
     * @param string  $langCode Language code
     * @param boolean $all      if true, will get all default labels without feature check
     *
     * @return array Custom labels with overrides
     */
    public function getLabels($langCode = self::DEFAULT_LANG, $all = true)
    {
        // get overrides
        $tbl = $this->DB->getClientDB($this->clientID) . "." . $this->tbl;
        $appLabels = $this->DB->fetchAssocRows(
            "SELECT labelName, labelText FROM $tbl WHERE clientID = :clientID AND langCode = :langCode",
            [':clientID' => $this->clientID, ':langCode' => $langCode]
        );
        $m_sub = new Subscriber($this->clientID);
        $cpRec = $m_sub->getValues('regionTitle, departmentTitle');


        // replace defaults with overrides
        $labels = $this->getDefaults($langCode, $all);
        foreach ($appLabels as $lbl) {
            if (!empty($lbl['labelName']) && !empty($lbl['labelText'])) {
                $labels[$lbl['labelName']] = $lbl['labelText'];
            }
        }
        $labels['regionTitle'] = $cpRec['regionTitle'];
        $labels['departmentTitle'] = $cpRec['departmentTitle'];
        return $labels;
    }


    /**
     * Get assoc array of custom label values using keys corresponding with session vars
     *
     * @param string $langCode Language code
     *
     * @return array Custom labels
     */
    public function getLabelsWithSessKeys($langCode = self::DEFAULT_LANG)
    {
        $labels = [];
        $origLabels = $this->getLabels($langCode, false);
        $origLblKeysToSessKeys = [
            'departmentTitle'  => 'department',
            'regionTitle'      => 'region',
            'billingUnit'      => 'billingUnit',
            'purchaseOrder'    => 'billingPO',
            'customFieldsCase' => 'caseCustomFields',
            'caseNotes'        => 'caseNotes',
        ];
        if (\Xtra::app()->ftr->has(\Feature::TENANT_TPM)) {
            $origLblKeysToSessKeys['internalCode'] = 'tpInternalCode';
            $origLblKeysToSessKeys['customFields'] = 'tpCustomFields';
            $origLblKeysToSessKeys['approvalStatus'] = 'tpApprovalStatus';
            $origLblKeysToSessKeys['status'] = 'tpApprovalStatusIcon';
            $origLblKeysToSessKeys['legalName'] = 'tpCompanyName';
            $origLblKeysToSessKeys['DBAname'] = 'tpAltCompanyName';
            $origLblKeysToSessKeys['tpNotes'] = 'tpNotes';
            if (\Xtra::app()->ftr->has(\Feature::TENANT_TPM_COMPLIANCE)) {
                $origLblKeysToSessKeys['compliance'] = 'tpCompliance';
            }
        }

        foreach ($origLabels as $key => $value) {
            if (!empty($origLblKeysToSessKeys[$key])) {
                $labels[$origLblKeysToSessKeys[$key]] = $value;
            }
        }
        return $labels;
    }



    /**
     * Get default custom label values
     *
     * @param string  $langCode Language code
     * @param boolean $all      if true, will get all labels without feature check
     *
     * @todo   Needs translation
     * @return array custom label defaults
     */
    public function getDefaults($langCode = self::DEFAULT_LANG, $all = true)
    {
        // get English defaults if $langCode not defined
        $defaults = \Xtra::arrayGet($this->defaults, $langCode, $this->defaults[self::DEFAULT_LANG]);
        if ($all || \Xtra::app()->ftr->has(\Feature::TENANT_TPM)) {
            $defaults['internalCode'] = 'Internal Code';
            $defaults['customFields'] = 'Custom Fields';
            $defaults['approvalStatus'] = 'Approval Status';
            $defaults['status'] = 'Status';
            $defaults['legalName'] = 'Official Company Name';
            $defaults['DBAname'] = 'Alternate Trade Name(s)';
            $defaults['tpNotes'] = 'Notes';
            if ($all || \Xtra::app()->ftr->has(\Feature::TENANT_TPM_COMPLIANCE)) {
                $defaults['compliance'] = 'Compliance';
            }
        }
        return $defaults;
    }

    /**
     * Replace tokens in provide original
     *
     * @param mixed  $original   String or array of strings to have token replacement
     * @param string $langCode   Langugage code
     * @param array  $delimiters opening and closing delimiters
     *
     * @return mixed strings with tokens replaced by labels
     */
    public function replaceTokens(mixed $original, $langCode = self::DEFAULT_LANG, $delimiters = null)
    {
        if (is_null($delimiters)) {
            $delimiters = $this->defaultDelimiters;
        }
        if ($langCode != $this->lang
            || is_null($this->tokens)
            || is_null($this->values)
            || $delimiters !== $this->delim
        ) {
            // reload labels
            $this->timesLoaded++;
            $this->lang = $langCode;
            $labels = $this->getLabels($langCode);
            $this->values = array_values($labels);
            if (!is_array($delimiters) || count($delimiters) != 2) {
                $delimiters = $this->defaultDelimiters;
            }
            $this->delim = $delimiters;
            $openDelim = $delimiters[0];
            $closeDelim = $delimiters[1];
            $this->tokens = array_map(
                fn($tok) => $openDelim . $tok . $closeDelim,
                array_keys($labels)
            );
        }
        if (is_string($original) || is_array($original)) {
            $rtn = str_replace($this->tokens, $this->values, $original);
        } else {
            $rtn = $original;
        }
        return $rtn;
    }
}
