<?php
/**
 * Find TPPs with GDC hits that have not yet been reviewed
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Class CaseGdcFlagsToAdjudicate
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class CaseGdcFlagsToAdjudicate extends TpTileBase
{
    /**
     * CaseGdcFlagsToAdjudicate constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'GDC Hits to adjudicate';
    }

    /**
     * Override default WHERE statement in query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: tPP.status = 'active'";
        $this->where[] = "AND: (tPP.gdcReview = 1 OR tPP.gdcReviewIcij = 1)";
        $this->where[] = "AND: scrnRes.hits > 0";
    }

    /**
     * Custom table JOIN. Add clientProfile table so we can pull out renew days
     *
     * @return void
     */
    public function setJoins()
    {
        $this->joins[] = 'JOIN gdcScreening AS scrn ON tPP.gdcScreeningID = scrn.id';
        $this->joins[] = 'JOIN gdcResult AS scrnRes ON scrn.id = scrnRes.screeningID';
    }

    /**
     * Override default query fields to only return the count of found records
     *
     * @return array
     */
    protected function getQueryFields()
    {
        $fields = parent::getQueryFields();

        $fields[] = 'scrnRes.hits AS hits';

        return $fields;
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
                'width'     => 225
            ],
            [
                'text'      => 'Hits',
                'dataField' => 'hits',
                'width'     => 50
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
    /**
     * List of fields that will be returned and the type of data contained. Should maintain parity with getQueryFields
     *
     * @return array
     */
    protected function getFieldTypes()
    {
        $fieldTypes = parent::getFieldTypes();

        $fieldTypes[] = [
                'name' => 'hits',
                'type' => 'number'
            ];

        return $fieldTypes;
    }

    /**
     * Get URL for link redirects
     *
     * @return string
     */
    protected function getUrl()
    {
        return '/cms/thirdparty/thirdparty_home.sec?id={{ id }}&tname=thirdPartyFolder&pdt=dd&rvw=1&delta=2';
    }
}
