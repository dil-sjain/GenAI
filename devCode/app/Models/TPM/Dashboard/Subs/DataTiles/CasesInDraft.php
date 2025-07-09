<?php
/**
 * Display cases current in Draft status
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;

/**
 * Class CasesInDraft
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class CasesInDraft extends CaseTileBase
{
    /**
     * Initialize data
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Cases in Draft (DDQ)';
    }

    /**
     * Set WHERE params for query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: c.caseStage = :caseStage";
        $this->whereParams[':caseStage'] = Cases::REQUESTED_DRAFT;
    }

    /**
     * List of fields to be displayed from returned case data
     *
     * @return array
     */
    public function getDisplayFields()
    {
        return [
            [
                'text'      => 'Case Number',
                'dataField' => 'caseNum',
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
            [
                'text'      => 'Owner',
                'dataField' => 'requester',
                'width'     => 175
            ]
        ];
    }
}
