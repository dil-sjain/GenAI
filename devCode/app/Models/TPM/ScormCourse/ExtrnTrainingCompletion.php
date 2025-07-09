<?php
/**
 * Model: Access extrnTrainingCompletion records
 *
 * @see Legacy equivalent by same name.
 */

namespace Models\TPM\ScormCourse;

use Models\BaseLite\RequireClientID;

/**
 * Provides read/write access to extrnTrainingCompletion
 */
#[\AllowDynamicProperties]
class ExtrnTrainingCompletion extends RequireClientID
{
    /**
     * @var string Table name
     */
    protected $tbl = 'extrnTrainingCompletion';
    protected $tableInClientDB = true;

    /**
     * Get record from extrnTrainingCompletion
     *
     * @param integer $intakeFormID extrnTrainingCompletion.intakeFormID
     * @param integer $providerID   g_extrnTrainingProvider.id
     * @param integer $courseID     g_extrnTrainingCourse.id
     * @param boolean $create       If true, create record if missing
     *
     * @return mixed boolean or null if invalid course or invalid intakeFormID
     */
    public function isCourseCompleted($intakeFormID, $providerID, $courseID, $create = false)
    {
        $rec = $this->getCompletionRecord($intakeFormID, $providerID, $courseID, $create);
        if (!is_array($rec)) {
            return $rec; // null or false
        }
        return $rec['status'] === 'completed';
    }

    /**
     * Get completion record by clientID and uniqueLinkIdent
     *
     * @param string $smid uniqueLinkIdent
     *
     * @return mixed null, false, or info array
     */
    public function apiGetCompletionRecord($smid)
    {
        return $this->selectOne([], ['uniqueLinkIdent' => $smid]);
    }

    /**
     * Get record from extrnTrainingCompletion
     *
     * @param integer $intakeFormID extrnTrainingCompletion.intakeFormID
     * @param integer $providerID   g_extrnTrainingProvider.id
     * @param integer $courseID     g_extrnTrainingCourse.id
     * @param boolean $create       If true, create reocrd if missing
     *
     * @return mixed array if found, false if not found, null if invalid course or invalid intakeFormID
     */
    public function getCompletionRecord($intakeFormID, $providerID, $courseID, $create = false)
    {
        if (!$this->intakeFormExists($intakeFormID)
            || !$this->isValidExtrnCourse($providerID, $courseID)
        ) {
            return null;
        }
        $create = (bool)$create;
        $flds = ['id', 'status', 'score', 'uniqueLinkIdent', 'modified'];
        $where = [
            'intakeFormID' => $intakeFormID,
            'providerID' => $providerID,
            'courseID' => $courseID,
        ];
        if (!($rec = $this->selectOne($flds, $where)) && $create) {
            // Create missing record
            $sets = $where;
            $sets['created'] = date('Y-m-d H:i:s');
            if ($id = $this->insert($sets)) {
                // Add the course identifier for API
                $hash = md5('' . $id . $this->clientID . $intakeFormID . $providerID . $courseID);
                if ($this->updateById($id, ['uniqueLinkIdent' => $hash, 'modified' => null])) {
                    $rec = $this->selectOne($flds, $where);
                }
            }
        }
        return $rec;
    }

    /**
     * Check to see if providerID and courseID are configured
     *
     * @param integer $providerID g_extrnTrainingProvider.id
     * @param integer $courseID   g_extrnTrainingCourse.id
     *
     * @return boolean
     */
    public function isValidExtrnCourse($providerID, $courseID)
    {
        return is_array($this->getCourse($providerID, $courseID));
    }

    /**
     * Get provider and course information
     *
     * @param integer $providerID g_extrnTrainingProvider.id
     * @param integer $courseID   g_extrnTrainingCourse.id
     *
     * @return mixed array or false
     */
    public function getCourse($providerID, $courseID)
    {
        $dbname = $this->DB->globalDB;
        $pTbl = $dbname . '.g_extrnTrainingProvider';
        $cTbl = $dbname . '.g_extrnTrainingCourse';
        $sql =<<< EOT
SELECT c.id AS 'courseID', c.name AS 'courseName',
c.providerCourseIdent, c.courseUrl, c.courseTestUrl,
p.id AS 'providerID', p.defaultCourseUrl, p.sharedKey, p.authIP,
p.name AS 'providerName'
FROM $cTbl AS c
INNER JOIN $pTbl AS p ON p.id = c.providerID
WHERE c.id = :courseID AND c.providerID = :providerID LIMIT 1
EOT;
        $params = [':providerID' => $providerID, ':courseID' => $courseID];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Check if ddq record exists
     *
     * @param integer $intakeFormID ddq.id
     *
     * @return boolean
     */
    private function intakeFormExists($intakeFormID)
    {
        $tbl = $this->clientDB . '.ddq';
        $id = (int)$intakeFormID;
        $sql = "SELECT id FROM $tbl WHERE id = :id AND clientID = :cid LIMIT 1";
        $params = [':id' => $id, ':cid' => $this->clientID];
        return ($id && $this->DB->fetchValue($sql, $params) == $id);
    }
}
