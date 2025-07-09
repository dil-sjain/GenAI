<?php
/**
 * BaseLite access to client cases table
 */

namespace Models\TPM\CaseMgt;

use Lib\Legacy\CaseStage;

class CasesLT extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string Table name (required)
     */
    protected $tbl = 'cases';

    /**
     * @var bool In client DB
     */
    protected $tableInClientDB = true;

    /**
     * Get recent case data for 3P profile
     *
     * @param int   $tpID    thirdPartyProfile.id, cases.tpID
     * @param array $fields  Cases columns to return
     * @param int   $howMany Number of recent cases to return
     * @param array $where   field/value conditions AND-ed for WHERE clause
     *
     * @return array of cases records or empty array
     */
    public function getRecent3pCases($tpID, array $fields, $howMany = 1, array $where = [])
    {
        // At least one
        $howMany = (int)$howMany;
        if ($howMany < 1) {
            $howMany = 1;
        }
        if (empty($fields)) {
            $fieldList = '*';
        } else {
            $fieldList = implode(', ', $fields);
        }
        // Make certain required values are present in where clause
        $where['clientID'] = $this->clientID;
        $where['tpID'] = $tpID;
        $conditions = [];
        $params = [];
        foreach ($where as $f => $v) {
            $plainF = trim($f, '`');
            $conditions[] = "$f = :" . $plainF;
            $params[':' . $plainF] = $v;
        }
        $conditions[] = "caseStage <> " . CaseStage::DELETED;
        $whereClause = implode(' AND ', $conditions);
        $sql = "SELECT $fieldList FROM $this->tbl\n"
            . "WHERE $whereClause ORDER BY id DESC LIMIT $howMany";
        return $this->DB->fetchAssocRows($sql, $params);
    }
}
