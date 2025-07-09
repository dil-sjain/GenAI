<?php
/**
 * Get all active OSI cases
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;

/**
 * Class ActiveOSI
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class ActiveOSI extends CaseTileBase
{
    /**
     * CaseIntakeFormSent constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Active OSI';
    }

    /**
     * Set WHERE params for query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: (c.caseStage = :caseStage OR c.caseStage = :caseStage2)";
        $this->where[] = "AND: c.caseType = :caseType";
        $this->whereParams[':caseStage']  = Cases::ACCEPTED_BY_INVESTIGATOR;
        $this->whereParams[':caseStage2'] = Cases::BUDGET_APPROVED;
        $this->whereParams[':caseType']   = Cases::DUE_DILIGENCE_OSI;
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
                'width'     => 250
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
                'text'      => 'Intake Form Source',
                'dataField' => 'source',
                'width'     => 125
            ],
            [
                'text'      => 'Owner',
                'dataField' => 'requester',
                'width'     => 100
            ]
        ];
    }
}
