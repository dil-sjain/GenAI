<?php
/**
 * Access and manage 3P Renewal Rules data
 */

namespace Models\TPM\Settings\ContentControl\RenewalRules;

use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\CustomField;
use Models\TPM\RiskModel\RiskTier;
use Models\TPM\CaseTypeClientBL as CaseTypeClient;

#[\AllowDynamicProperties]
class RenewalRules extends \Models\BaseLite\RequireClientID
{
    /**
     * Required table name
     *
     * @var string
     */
    protected $tbl = 'renewalRules';

    /**
     * Indicate if table is in client's database
     *
     * @var bool
     */
    protected $tableInClientDB = true;

    /**
     * dateFileds table name
     *
     * @var string
     */
    protected $dateFieldsTbl = null;

    /**
     * Records from renewalDateFields
     *
     * @array
     */
    protected $dateFields = [];

    /**
     * Application framework object
     * @var instance of Skinny
     */
    protected $app = null;

    /**
     * Initialize instance properties
     *
     * @param int   $clientID   Tanant ID
     * @param array $connection Optional DB connection values
     *
     * @return void
     */
    public function __construct($clientID, $connection = [])
    {
        $this->app = \Xtra::app();
        $bareTbl = $this->tbl; // grab before parent alters
        parent::__construct($clientID, $connection);
        if (!$this->DB->tableExists($bareTbl, $this->clientDB)) {
            $this->createTable();
        }
        // Creates and populate date fields table if it doesn't exist
        $df = new RenewalDateFields();
        $this->dateFieldsTbl = $df->getTableName();
        $this->dateFields = $df->selectMultiple([], [], 'ORDER BY precedence');
    }

    /**
     * Get risk tier name from id
     *
     * @param int $tierID riskTier.id
     *
     * @return string Tier name
     */
    public function getRiskTierName($tierID)
    {
        $tierID = (int)$tierID;
        if ($tierID === 0) {
            return '(any)';
        }
        return (new RiskTier($this->clientID))->selectValueByID($tierID, 'tierName');
    }

    /**
     * Get intake form name
     *
     * @param string $legacyID  ddqName.legacyID
     * @param string $dateField renwalRules.dateField
     *
     * @return string
     */
    public function getFormRefName($legacyID, $dateField)
    {
        if ($dateField !== 'frmSub') {
            return '';
        } elseif ($legacyID === '') {
            return '(any)';
        }
        return (new DdqName($this->clientID))->selectValue('name', ['legacyID' => $legacyID]);
    }

    /**
     * Get date fields in order of precedence
     *
     * @return array
     */
    public function getDateFields()
    {
        return $this->dateFields;
    }

    /**
     * Get date field name from reference
     *
     * @param string $ref 'statChg' or 'frmSub'
     *
     * @return string
     */
    public function getDateFieldName($ref)
    {
        $map = [
            'statChg' => 'Approval Status Change Date',
            'frmSub'  => 'Form Submission Date',
            'invDone' => 'Investigation Completion Date',
            'tpCF'    => '3P Custom Date Field',
            'exclude' => 'Exclude from Renewal',
        ];
        if (array_key_exists($ref, $map)) {
            $name = $map[$ref];
        } else {
            $name = '(unknown)';
        }
        return $name;
    }

    /**
     * Get dateModifier name from reference
     *
     * @param int $ref customField.id
     *
     * @return string
     */
    public function getModifierName($ref)
    {
        $name = '(unknown)';
        if ($ref === 0) {
            $name = '(none)';
        } elseif ($nm = (new CustomField($this->clientID))->selectValueByID($ref, 'name')) {
            $name = $nm;
        }
        return $name;
    }

    /**
     * Get caseType abbreviation from reference
     *
     * @param int $ref cases.caseType
     *
     * @return string
     */
    public function getCaseTypeAbbrev($ref)
    {
        $abbrev = '(unknown)';
        if ($ref === 0) {
            $abbrev = 'R.Scp';
        } elseif ($nm = (new caseTypeClient($this->clientID))->selectValue('abbrev', ['caseTypeID' => $ref])) {
            $abbrev = $nm;
        }
        return $abbrev;
    }

    /**
     * Construct message for audit log
     *
     * @param array $is  Current values for renewalRules record
     * @param array $was Record values before update
     *
     * @return string Log message
     */
    public function makeUpdateLogMessage(Array $is, Array $was)
    {
        $tierName = $this->getRiskTierName($was['risk']);
        $track = $this->getDateFieldName($was['dateField']);
        $rank = $was['ruleRank'];
        $msg = "Rule(#{$was['id']}) - track: `$track`; rank `$rank`; risk: `$tierName`; ";

        $changes = [];
        foreach ($is as $fld => $v) {
            if ($is[$fld] === $was[$fld]) {
                if ($fld === 'name') {
                    $changes[] = "name: `$v`";
                }
                continue;
            }
            switch ($fld) {
                case 'days':
                case 'active':
                case 'modifierIsAbsolute':
                case 'name':
                    $changes[] = "{$fld}: `{$was[$fld]}` =&gt; `{$v}`";
                    break;
                case 'formRef':
                    $cur  = $this->getFormRefName($is[$fld], $was['dateField']);
                    $prev = $this->getFormRefName($was[$fld], $was['dateField']);
                    $changes[] = "{$fld}: `$prev` =&gt; `{$cur}`";
                    break;
                case 'dateModifier':
                    $cur  = $this->getModifierName($is[$fld]);
                    $prev = $this->getModifierName($was[$fld]);
                    $changes[] = "{$fld}: `$prev` =&gt; `{$cur}`";
                    break;
                case 'renewalCaseType':
                    $cur  = $this->getCaseTypeAbbrev($is[$fld]);
                    $prev = $this->getCaseTypeAbbrev($was[$fld]);
                    $changes[] = "{$fld}: `$prev` =&gt; `{$cur}`";
                    break;
            }
        }
        return $msg . implode('; ', $changes);
    }

    /**
     * Return a list of renewal tacks with rule counts for each
     *
     * @return array with keys 'id', 'name', and 'rules'
     */
    public function countRulesPerRenewalTrack()
    {
        $sql = <<<EOT
SELECT df.abbrev AS `id`, df.name,
(SELECT COUNT(*) FROM $this->tbl WHERE clientID = :cid AND dateField = df.abbrev) AS `rules`
FROM $this->dateFieldsTbl as df WHERE 1 ORDER BY df.precedence
EOT;
        return $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID]);
    }

    /**
     * Match rules for one profile
     *
     * @param mixed $profileRef thirdPartyProfile.id|userTpNum
     *
     * @return array Rules records
     */
    public function getRulesByProfile(mixed $profileRef)
    {
        $params = [':cid' => $this->clientID];
        if (is_numeric($profileRef)) {
            $refWhere = "t.id = :ref";
            $params[':ref'] = (int)$profileRef;
        } elseif (is_string($profileRef)) {
            $refWhere = "t.userTpNum = :ref";
            $params[':ref'] = $profileRef;
        } else {
            throw new \InvalidArgumentException('Invalid Third Party reference');
        }
        $tppTbl = $this->clientDB . '.thirdPartyProfile';
        $raTbl = $this->clientDB . '.riskAssessment';
        $sql = <<<EOT
SELECT t.tpType, t.tpTypeCategory AS `tpCategory`,
(SELECT a.tier FROM $raTbl AS a WHERE a.tpID = t.id AND a.status = 'current' LIMIT 1) AS `risk`
FROM $tppTbl AS t
WHERE $refWhere AND t.clientID = :cid LIMIT 1;
EOT;
        if ($profile = $this->DB->fetchAssocRow($sql, $params)) {
            $risk = is_int($profile['risk']) ? $profile['risk'] : 0;
            return $this->getRulesByProfileGroup($risk, $profile['tpType'], $profile['tpCategory']);
        } else {
            return [];
        }
    }

    /**
     * Match rules by profile groupp in order of most specific to least specific by dateField precedence
     *
     * @param int $risk     riskTier.id
     * @param int $type     thirdPartyProfile.tpType, must be > 0
     * @param int $category thirdPartyProfile.tpTypeCategory, must be > 0
     *
     * @throws \InvalidExceptionArgument if type or category < 1
     * @return array Rules records
     */
    public function getRulesByProfileGroup($risk, $type, $category)
    {
        $category = (int)$category; // in lieu of placeholder
        $type = (int)$type;         // ...
        $risk = (int)$risk;         // ...
        // profiles require non-zero type and category - don't perform artificial tests
        if ($category <= 0) {
            throw new \InvalidArgumentException('Third Party category must be greater than 0.');
        } elseif ($type <= 0) {
            throw new \InvalidArgumentException('Third Party type must be greater than 0.');
        }
        $sql = <<<EOT
SELECT rr.id, rr.name, rr.days, rr.dateField, rr.dateModifier, rr.modifierIsAbsolute,
rr.ruleRank, rr.risk, rr.tpType, rr.tpCategory, rr.formRef, rr.ruleRank
FROM $this->tbl AS rr
LEFT JOIN $this->dateFieldsTbl AS df ON df.abbrev = rr.dateField
WHERE rr.clientID = :cid AND rr.active
  AND rr.tpCategory IN($category,0)
  AND rr.tpType IN($type,0)
  AND IF ($risk, rr.risk IN($risk,0), rr.risk = 0) -- prevent unrated profile from matching rules with excplicit risk
ORDER BY df.precedence, rr.ruleRank, rr.formRef DESC
EOT;
        $hierarchy = $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID]);

        $dateField = '';
        $matchRisk = $matchType = $matchCat = 0;
        $returnAll = ['frmSub', 'tpCF'];
        $rules = [];
        $excludeRank = 100; // artificially high level so all match, unless there's an exclude rule

        // Filter hierarchy by most specific rule(s) for each renewal track
        foreach ($hierarchy as $rule) {
            if ($rule['dateField'] !== $dateField) {
                $dateField = $rule['dateField'];
                if ($rule['ruleRank'] < $excludeRank) {
                    $rules[] = $rule;
                    // Ensure multiple renewal tracks target the same profile group
                    $matchRisk = $rule['risk'];
                    $matchType = $rule['tpType'];
                    $matchCat  = $rule['tpCategory'];
                    if ($dateField === 'exclude') {
                        $excludeRank = $rule['ruleRank'];
                    }
                }
            } elseif (in_array($dateField, $returnAll)
                && $rule['risk'] === $matchRisk
                && $rule['tpType'] === $matchType
                && $rule['tpCategory'] === $matchCat
            ) {
                $rules[] = $rule;
            }
        }
        return $rules;
    }

    /**
     * Get records for Renewal Rules list table
     *
     * @param int $track renewalRules.dateField
     *
     * @return array of records
     */
    public function getRulesForRenewalTrack($track)
    {
        $typeTbl = $this->clientDB . '.tpType';
        $catTbl = $this->clientDB . '.tpTypeCategory';
        $cfTbl = $this->clientDB . '.customField';
        $formTbl = $this->clientDB . '.ddqName';
        $tierTbl = $this->clientDB . '.riskTier';
        $sql = <<<EOT
SELECT DISTINCT rr.id, rr.name, rr.dateField, rr.renewalCaseType,
rr.dateModifier, rr.formRef, rr.modifierIsAbsolute, rr.ruleRank,
rr.risk, rr.days, rr.tpType, rr.tpCategory, rr.active,
IF(rr.tpType = 0, '', IF(t.id IS NULL, CONCAT('(unknown)', ' ', rr.tpType), t.name)) AS `typeName`,
IF(rr.tpCategory = 0, '', IF(c.id IS NULL, CONCAT('(unknown)', ' ', rr.tpCategory), c.name)) AS `catName`,
IF (rr.risk = 0, '', IF(rt.id IS NULL, CONCAT('(unknown)', ' ' , rr.risk), rt.tierName)) AS `riskName`
FROM $this->tbl AS rr
LEFT JOIN $typeTbl AS t ON t.id = rr.tpType
LEFT JOIN $catTbl AS c ON c.id = rr.tpCategory
LEFT JOIN $formTbl AS dn ON dn.legacyID = rr.formRef
LEFT JOIN $cfTbl AS cf ON cf.id = rr.dateModifier
LEFT JOIN $tierTbl AS rt ON rt.id = rr.risk
WHERE rr.clientID = :cid AND rr.dateField = :track
ORDER BY riskName DESC, typeName DESC, catName DESC, rr.ruleRank, dn.name, cf.name
EOT;
        return $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID, ':track' => $track]);
    }

    /**
     * Return related data for front-end lookups
     *
     * @return array
     */
    public function getRelatedData()
    {
        // Risk
        $riskFlds = ['id', 'tierName AS `tier`'];
        $riskOrder = 'ORDER BY tierName';
        $tiers = (new RiskTier($this->clientID))->selectMultiple($riskFlds, [], $riskOrder);
        array_unshift($tiers, ['id' => 0, 'tier' => '(unrated)']);

        // 3P Types (all)
        $typesFlds = ['id', 'name'];
        $typesOrder = 'ORDER BY name';

        // 3P Categories (all)
        $catsFlds = ['tpType', 'id', 'name'];
        $catsOrder = 'ORDER BY tpType, name';

        // Intake Forms (all)
        $formsFlds = ['legacyID', 'name', 'status'];
        $formsOrder = 'ORDER BY name';

        // 3P Custom Date Fields
        $fieldsFlds = ['id', 'name', 'hide'];
        $fieldsWhere = ['scope' => 'thirdparty', 'type' => 'date'];
        $fieldsOrder = 'ORDER BY name';

        $data = [
            'tiers'  => $tiers,
            'types'  => (new TpType($this->clientID))->selectMultiple($typesFlds, [], $typesOrder),
            'cats'   => (new TpTypeCategory($this->clientID))->selectMultiple($catsFlds, [], $catsOrder),
            'forms'  => (new DdqName($this->clientID))->selectMultiple($formsFlds, [], $formsOrder),
            'fields' => (new CustomField($this->clientID))->selectMultiple($fieldsFlds, $fieldsWhere, $fieldsOrder),
            'caseTypes' => (new CaseTypeClient($this->clientID))->getRecords(false),
        ];
        return $data;
    }

    /**
     * Create table if it doesn't exists
     *
     * @return mixed result of $this->DB->query()
     */
    private function createTable()
    {
        // phpcs:disable
        $sql = <<<EOT
CREATE TABLE $this->tbl (
    id int AUTO_INCREMENT,
    clientID int NOT NULL DEFAULT '0' COMMENT 'clientProfile.id',
    name varchar(255) NOT NULL DEFAULT '' COMMENT 'Rule name',
    dateField varchar(10) NOT NULL DEFAULT '' COMMENT 'renewalDateFields.abbrev',
    dateModifier int NOT NULL DEFAULT '0' COMMENT '3P date customField.id',
    modifierIsAbsolute bool NOT NULL DEFAULT '0' COMMENT 'If true trigger on date, not days from date',
    formRef varchar(15) NOT NULL DEFAULT '' COMMENT 'ddqName.legacyID',
    renewalCaseType int(11) NOT NULL DEFAULT '0' COMMENT 'cases.caseType expected on renewal (invDone dateField only); used by Diligent Corporation Insights for analysis',
    days int NOT NULL DEFAULT '0' COMMENT 'Time period',
    risk int NOT NULL DEFAULT '0' COMMENT 'riskTier.id',
    tpType int NOT NULL DEFAULT '0' COMMENT 'tpType.id',
    tpCategory int NOT NULL DEFAULT '0' COMMENT 'tpTypeCategory.id',
    ruleRank tinyint(4) NOT NULL DEFAULT '7' COMMENT 'hierarchy levels 1 - 6, highest to lowest rank',
    active bool NOT NULL DEFAULT '1' COMMENT 'soft delete = 0',
    ruleHash binary(20) DEFAULT NULL COMMENT 'binary sha1 of concatenated clientID, risk, tpType, tpCategory, and dateField (+ formRef for frmSub OR + dateModifier for tpCF)',
    created datetime DEFAULT NULL,
    modified timestamp DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP,
    deleted datetime DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniqRule (ruleHash),
    UNIQUE KEY uniqName (clientID, name),
    KEY risk (risk),
    KEY tpType (tpType),
    KEY tpCategory (tpCategory),
    KEY dateField (dateField),
    KEY dateModifier (dateModifier),
    KEY formRef (formRef),
    KEY active (active),
    KEY renewalCaseType (renewalCaseType),
    KEY ruleRank (ruleRank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
EOT;
        // phpcs:enable
        return $this->DB->query($sql);
    }
}
