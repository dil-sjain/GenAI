<?php
/**
 * Provide acess to CustomField records
 */

namespace Models\TPM;

/**
 * Read/write access to customField
 *
 * @keywords custom field
 */
#[\AllowDynamicProperties]
class CustomField extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'customField';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;

    /**
     * Get fields eligible for risk model custom field risk factor
     *
     * @return array Set of custom field for risk factor
     */
    public function riskFactorEligibleFields()
    {
        // MAXVALUE became a reserved word in MySql 5.5
        $sql = <<<EOT
SELECT id, name, type, listName, prompt, `minValue`, `maxValue`
FROM $this->tbl
WHERE clientID = :cid AND scope = 'thirdparty' AND hide = 0
AND type IN('numeric','radio','check','select')
ORDER BY sequence ASC, name ASC
EOT;
        return $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID]);
    }

    /**
     * Get riskfactor eligile field by ID
     *
     * @param integer $fid Field id
     *
     * @return mixed false or field info as assoc array
     */
    public function riskFactorFieldByID($fid)
    {
        $rtn = false;
        $cols = ['name', 'type', 'listName', 'scope', '`minValue`', '`maxValue`'];
        $types = ['select', 'check' ,'radio', 'numeric'];
        if ($fld = $this->selectByID($fid, $cols, true)) {
            if ($fld['scope'] === 'thirdparty' && in_array($fld['type'], $types)) {
                $rtn = $fld;
            }
        }
        return $rtn;
    }

    /**
     * Get preferred language custom field ID
     *
     * @return integer
     */
    public function getPreferredLanguageID()
    {
        $sql = "SELECT id FROM customField\n"
            . "WHERE clientID = :clientID AND scope = 'thirdparty' "
            . "AND hide = 0 AND name = 'preferred_language_code' LIMIT 1";
        return (int)$this->DB->fetchValue($sql, [':clientID' => $this->clientID]);
    }

    /**
     * Get custom field language
     *
     * @param integer $tpID            thirdPartyProfile.id
     * @param integer $tpTypeCategory  tpTypeCategory.id
     * @param integer $prefLangFieldID customData.fieldDefID
     *
     * @return string Custom field language
     */
    public function getCustomFldLanguage($tpID, $tpTypeCategory, $fieldDefID)
    {
        $tpID = (int)$tpID;
        $tpTypeCategory = (int)$tpTypeCategory;
        $fieldDefID = (int)$fieldDefID;
        $sql = "SELECT value FROM customData AS d\n"
            . "LEFT JOIN customFieldExclude AS e ON "
            . "(e.tpCatID = :tpCatID AND e.cuFldID = :cuFldID AND e.clientID = :cfExClientID)\n"
            . "WHERE d.tpID = :tpID AND d.clientID = :dataClientID AND d.fieldDefID = :fieldDefID\n"
            . "AND e.cuFldID IS NULL";
        $params = [':tpCatID' => $tpTypeCategory, ':cuFldID' => $fieldDefID, ':cfExClientID' => $this->clientID, ':tpID' => $tpID, ':dataClientID' => $this->clientID, ':fieldDefID' => $fieldDefID];
        return $this->DB->fetchValue($sql, $params);
    }
}
