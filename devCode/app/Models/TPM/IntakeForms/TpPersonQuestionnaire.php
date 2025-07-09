<?php
/**
 * Provides read write access to tpPersonQuestionniare table in client databases
 */

namespace Models\TPM\IntakeForms;

/**
 * Basic CRUD access to tpPersonQuestionnaire, requiring clientID
 *
 * @keywords tpPersonQuestionnaire, questionnaire, intake form, training
 */
#[\AllowDynamicProperties]
class TpPersonQuestionnaire extends \Models\BaseLite\RequireClientID
{
    /**
     * Required by base class
     s
     * @var string
     */
    protected $tbl = 'tpPersonQuestionnaire';

    /**
     * Name of primaryID field
     *
     * @var string
     */
    protected $primaryID = 'rowID';

    /**
     * Get a training invitee name for a given hosted training.
     * Modified 2019-01-08 to also use firstName/lastName if fullName is empty
     *
     * @param int $ddqID ddq.id with which the invitee is associated.
     *
     * @return string $invitee The invitee's name.
     */
    public function getTrainingInvitee($ddqID)
    {
        $ddqID = (int)$ddqID;
        $invitee = '';
        if ($ddqID > 0) {
            $sql = "SELECT TRIM(p.fullName) AS `full_name`,\n"
                . "TRIM(p.firstName) AS `first_name`,\n"
                . "TRIM(p.lastName) AS `last_name`\n"
                . "FROM tpPerson AS p\n"
                . "LEFT JOIN $this->tbl AS q ON q.tpPersonID = p.id\n"
                . "WHERE q.ddqID = :ddqID AND q.clientID = :cid AND q.formClass = 'training'\n"
                . "LIMIT 1";
            $row = $this->DB->fetchAssocRow($sql, [':ddqID' => $ddqID, ':cid' => $this->clientID]);
            if ($row) {
                if (!empty($row['full_name'])) {
                    $invitee = $row['full_name'];
                } elseif (!empty($row['first_name']) && !empty($row['last_name'])) {
                    $invitee = $row['first_name'] . ' ' . $row['last_name'];
                }
            }
        }
        return $invitee;
    }

    /**
     * Find active training intake form
     * Differs from legacy by limiting to specific profile #
     *
     * @param int    $personID   tpPerson.id
     * @param int    $tpID       thirdPartyProfile.id
     * @param int    $ddqType    ddq.caseType
     * @param string $ddqVersion ddq.ddqQuestionVer
     *
     * @return false|array
     */
    public function findActiveTraining($personID, $tpID, $ddqType, $ddqVersion)
    {
        // Differs from legacy by limiting to specific 3P profile
        $sql = <<< EOT
SELECT q.ddqID, q.casesID, c.caseStage,
d.POCname, d.POCemail, d.POCposi, d.POCphone, d.passWord, d.subInLang
FROM $this->tbl AS q
INNER JOIN (ddq AS d, cases AS c) ON (d.id = q.ddqID AND c.id = q.casesID)
WHERE q.tpPersonID = :personID
  AND q.formClass = 'training'
  AND q.caseType = :ddqType
  AND q.ddqQuestionVer = :ddqVer
  AND d.status = 'active'
  AND c.tpID = :tpID
  AND c.caseStage NOT IN(13,104)
ORDER BY q.rowID DESC LIMIT 1
EOT;
        $params = [
            ':personID' => $personID,
            ':ddqType' => $ddqType,
            ':ddqVer' => $ddqVersion,
            ':tpID' => $tpID,
        ];
        if ($rec = $this->DB->fetchAssocRow($sql, $params)) {
            return $rec;
        }
        return false;
    }
}
