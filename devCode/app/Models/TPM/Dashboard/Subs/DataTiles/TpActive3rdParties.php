<?php
/**
 * Retrieve number of active Third Parties
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Class TpActive3rdParties
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class TpActive3rdParties extends TpTileBase
{
    /**
     * Initialize data
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Active 3rd Parties';
    }

    /**
     * Set WHERE parameters for query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: tPP.status = 'active'";
    }

    /**
     * Override default query fields to only return the count of found records
     *
     * @return array
     */
    protected function getQueryFields()
    {
        $fields = [
            'COUNT(tPP.id) AS `total`'
        ];

        return $fields;
    }

    /**
     * Search for and return list of TP's found
     *
     * @return DataTileList
     */
    public function getList()
    {
        $items = $this->getResults();

        $list = new DataTileList();
        $list->setCount($items['items'][0]['total']);

        return $list;
    }
}
