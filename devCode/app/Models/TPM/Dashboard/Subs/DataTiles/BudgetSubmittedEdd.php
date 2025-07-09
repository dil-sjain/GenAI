<?php
/**
 * Get EDD cases needing budget approved
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;

/**
 * Class BudgetSubmittedEdd
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class BudgetSubmittedEdd extends CaseTileBase
{
    /**
     * CaseIntakeFormSent constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Budget Submitted for ADD';
    }

    /**
     * Set WHERE params for query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: c.caseStage = :caseStage";
        $this->where[] = "AND: c.caseType = :caseType";
        $this->whereParams[':caseStage'] = Cases::AWAITING_BUDGET_APPROVAL;
        $this->whereParams[':caseType']  = Cases::DUE_DILIGENCE_ADD;
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
