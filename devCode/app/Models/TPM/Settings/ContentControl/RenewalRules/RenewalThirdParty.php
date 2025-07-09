<?php
/**
 * Lightweight class speciallized for renewal query of thirdPartyProfile records
 */

namespace Models\TPM\Settings\ContentControl\RenewalRules;

#[\AllowDynamicProperties]
class RenewalThirdParty extends \Models\BaseLite\RequireClientID
{
    /**
     * Required table name
     * @var string
     */
    protected $tbl = 'thirdPartyProfile';

    /**
     * Indicate if table is in client's database
     * @var bool
     */
    protected $tableInClientDB = true;

    /**
     * Get distinct list of categories for active profile
     *
     * @return array of category integers
     */
    public function activeCategories()
    {
        $sql = <<<EOT
SELECT DISTINCT tpTypeCategory
FROM $this->tbl
WHERE clientID = :cid
  AND status = 'active'
  AND tpType > 0
  AND tpTypeCategory > 0
EOT;
        return $this->DB->fetchValueArray($sql, [':cid' => $this->clientID]);
    }

    /**
     * Provide chunker SQL to query active proviles by category
     *
     * @param int $category thirdPartyProfile.tpTypeCategory
     *
     * @return array with 'sql' and 'params' keys
     */
    public function sqlForChunkByCategory($category)
    {
        $raTbl = $this->clientDB . '.riskAssessment';
        $sql = <<<EOT
SELECT t.id, t.tpType, t.tpTypeCategory AS `tpCategory`, DATE(t.lastApprovalStatusUpdate) AS `approvalDate`,
(SELECT a.tier FROM $raTbl AS a WHERE a.tpID = t.id AND a.status = 'current' LIMIT 1) AS `risk`
FROM $this->tbl AS t
WHERE t.id > :uniqueID
  AND t.tpTypeCategory = :cat
  AND t.status = 'active'
  AND t.tpType > 0
  AND t.clientID = :cid
ORDER BY t.id ASC LIMIT 500
EOT;
        return [
            'sql' => $sql,
            'params' => [
                ':uniqueID' => 0,
                ':cat' => $category,
                ':cid' => $this->clientID,
            ],
        ];
    }
}
