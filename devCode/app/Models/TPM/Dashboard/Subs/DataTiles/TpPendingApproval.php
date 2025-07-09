<?php
/**
 * Third parties with an approval status of pending
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Class TpPendingApproval
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class TpPendingApproval extends TpTileBase
{
    /**
     * Initialize data
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = '3P\'s Pending Approval';
    }

    /**
     * Set WHERE parameters for query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: tPP.status = 'active'";
        $this->where[] = "AND: tPP.approvalStatus = 'pending'";
    }

    /**
     * List of fields to be displayed from returned case data
     *
     * @return array
     */
    public function getDisplayFields()
    {
        $display =  [
            [
                'text'      => 'TP Number',
                'dataField' => 'tpNum',
                'width'     => 100
            ],
            [
                'text'      => 'Company Name',
                'dataField' => 'companyName',
                'width'     => 300
            ],
            [
                'text'      => 'Region',
                'dataField' => 'region',
                'width'     => 100
            ],
            [
                'text'      => 'Department',
                'dataField' => 'department',
                'width'     => 100
            ],
        ];

        // Only display risk if feature is enabled for tenant
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $display[] = [
                'text'      => 'Risk Rating',
                'dataField' => 'risk',
                'width'     => 150
            ];
        }

        return $display;
    }
}
