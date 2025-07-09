<?php
/**
 * Provides read-only access to g_cpiScore
 */

namespace Models\TPM\RiskModel;

/**
 * Read g_cpiScore records
 *
 * @keywords cpi, cpi score
 */
#[\AllowDynamicProperties]
class CpiScore extends \Models\BaseLite\ReadData
{
    /**
     * @var string Table name
     */
    protected $tbl = 'g_cpiScore';

    /**
     * @var string Database name
     */
    protected $dbName = 'cms_global';

    public function selectCountryBetweenRanges($between, $param)
    {
        if (empty($between) || empty($param)) {
            return;
        }
        $sql = "SELECT isoCode from " . $this->getTableName() . " where cpiYear = $param and score ";
        $sql .= $between;
        $sql .= " order by rank desc limit 1";
        return $this->DB->fetchValue($sql);
    }
}
