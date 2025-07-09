<?php
/**
 * List of companies in a risk tier that defaults to an EDD
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Class TpHighRiskCompanies
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class TpHighRiskCompanies extends TpTileBase
{
    /**
     * Initialize data
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'High Risk Companies';
    }

    /**
     * Set WHERE parameters for query
     *
     * @return void
     */
    public function setWhere()
    {

        // We can only check this if the tenant is can use the risk model
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $this->where[] = "AND: rMT.scope = 12";
        }
        $this->where[] = "AND: tPP.status = 'active'";
    }

    /**
     * Search for and return list of TP's found
     *
     * @return DataTileList
     */
    public function getList()
    {
        $items      = $this->getResults();

        $list = new DataTileList();
        $list->setCount($items['total']);

        return $list;
    }
}
