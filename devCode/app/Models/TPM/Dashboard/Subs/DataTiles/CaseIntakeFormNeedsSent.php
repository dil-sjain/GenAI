<?php
/**
 * Get list of TPPs that need to send an Intake Form
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;

/**
 * Class CaseIntakeFormNeedsSent
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class CaseIntakeFormNeedsSent extends TpTileBase
{
    /**
     * CaseIntakeFormNeedsSent constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Intake Form Needs to be Sent';
    }

    /**
     * Set WHERE parameters for query
     *
     * @return void
     */
    public function setWhere()
    {
        // These find ALL TPP that have Cases that do not have an assigned DDQ
        $this->where[] = "AND: ddq.id IS NULL";
        $this->where[] = "AND: tPP.status = 'active'";
        $this->where[] = "AND: tPP.approvalStatus = 'pending'";
        $this->where[] = "AND: tPP.id NOT IN (" . $this->getTpIDs() . ")";
    }

    /**
     * Join additional tables for query
     *
     * @return void
     */
    public function setJoins()
    {
        $this->joins[] = 'LEFT JOIN cases AS c ON c.tpID = tPP.id';
        $this->joins[] = 'LEFT JOIN ddq ON ddq.caseID = c.id';
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
            [
                'text'      => 'Owner',
                'dataField' => 'owner',
                'width'     => 175
            ]
        ];
    }

    /**
     * This finds all profiles with Cases that are not deleted and have an assigned DDQ.
     * Cases do not know if a DDQ is attached without joining the table, so we need to
     * fully join all 3 tables again to determine this.
     * Then, the top level query will return FALSE if the current profile is found
     * in this list (which excludes profiles that might have multiple Cases,
     * some with assigned DDQs and some without).
     *
     * @return string
     */
    private function getTpIDs()
    {
        $rtn = '';
        $sql = "SELECT tp.id FROM thirdPartyProfile AS tp\n"
            . "LEFT JOIN cases on cases.tpID = tp.id\n"
            . "LEFT JOIN ddq on ddq.caseID = cases.id\n"
            . "WHERE ddq.id IS NOT NULL\n"
            . "AND tp.status = 'active'\n"
            . "AND tp.approvalStatus = 'pending'\n"
            . "AND cases.caseStage != :caseStage";
        $params = [':caseStage' => Cases::DELETED];
        $tpIDs = \Xtra::app()->DB->fetchValueArray($sql, $params);
        if (count($tpIDs) > 0) {
            return implode(', ', $tpIDs);
        }
        return 0;
    }
}
