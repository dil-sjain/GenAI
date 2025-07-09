<?php
/**
 * Provide acess to 3P Profile Risk Model Map
 */

namespace Models\TPM\RiskModel;

/**
 * Read/write access to riskModelMap
 *
 * @keywords risk, risk model
 */
#[\AllowDynamicProperties]
class RiskModelMap extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'riskModelMap';

    /**
     * @var mixed String column name or null to indicate no primary id
     */
    protected $primaryID = null;

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;

    /**
     * Get risk model role count for same tpType and category combination
     *
     * @param integer $tpType        riskModel.tpType
     * @param string  $cats          riskModel.categories
     * @param string  $riskModelRole riskModel.riskModelRole
     *
     * @return integer count of risk areas
     */
    public function getRoleCountForTypeCategory($tpType, $cats, $riskModelRole)
    {
        $cats = explode(',', $cats);
        $result = 0;
        foreach ($cats as $cat) {
            $sql = "SELECT COUNT(riskModelRole) FROM riskModelMap
                WHERE tpType = :tpType AND tpCategory = :tpCategory AND riskModelRole <> :riskModelRole";
            $params = [
                ':tpType' => $tpType,
                ':tpCategory' => $cat,
                ':riskModelRole' => $riskModelRole
            ];
            try {
                $roleCount = $this->DB->fetchValue($sql, $params);
                $result = max($result, $roleCount);
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }
        return $result;
    }
}
