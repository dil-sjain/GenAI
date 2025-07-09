<?php
/**
 * Calculate average days to complete a DDQ.
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;

/**
 * Class AvgDaysToCompleteDdq
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class AvgDaysToCompleteDdq extends CaseTileBase
{
    /**
     * AvgDaysToCompleteDdq constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Avg days to complete DDQ';
        $this->type  = 'Generic';
    }

    /**
     * Set fields to return for query
     *
     * @return array
     */
    public function getQueryFields()
    {
        $fields = [
            'AVG(TIME_TO_SEC(TIMEDIFF(c.modified,c.caseCreated))) AS timediff'
        ];

        return $fields;
    }

    /**
     * Specify WHERE parameters for SQL query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: c.caseStage IN (:caseStage1, :caseStage2)";
        $this->whereParams[':caseStage1'] = Cases::ACCEPTED_BY_REQUESTER;
        $this->whereParams[':caseStage2'] = Cases::CLOSED;
    }

    /**
     * Get AVG number of days to complete an investigation in seconds
     *
     * @return float
     */
    protected function getResults()
    {
        $query = $this->buildQuery();

        $found = $this->db->fetchValue($query, $this->whereParams);

        return $found;
    }

    /**
     * Get AVG in seconds, round to whole number of days and return
     *
     * @return DataTileList
     */
    public function getList()
    {
        $avgTimeInSeconds = $this->getResults();
        $avgDays = round((($avgTimeInSeconds / 60) / 60) / 24);

        $list = new DataTileList();
        $list->setCount($avgDays);

        return $list;
    }

    /**
     * Required method implementation from CassTileBase
     *
     * @return array
     */
    public function getDisplayFields()
    {
        return [];
    }
}
