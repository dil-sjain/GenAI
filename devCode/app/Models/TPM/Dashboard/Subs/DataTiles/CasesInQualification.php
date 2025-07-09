<?php
/**
 * Get cases with a status of in qualification
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;

/**
 * Class CasesInQualification
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class CasesInQualification extends CaseTileBase
{
    /**
     * CaseIntakeFormSent constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Cases in Qualification';
    }

    /**
     * Set WHERE params for query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: c.caseStage = :caseStage";
        $this->whereParams[':caseStage'] = Cases::QUALIFICATION;
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
                'text'      => 'Owner',
                'dataField' => 'requester',
                'width'     => 100
            ],
            [
                'text'      => 'Company Name',
                'dataField' => 'companyName',
                'width'     => 275
            ],
            [
                'text'      => 'Region',
                'dataField' => 'region',
                'width'     => 75
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
        ];
    }
}
