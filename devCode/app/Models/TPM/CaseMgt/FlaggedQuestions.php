<?php
/**
 * Handles special case custom fields for flagged questions in a DDQ.
 */

namespace Models\TPM\CaseMgt;

use Lib\SettingACL;

/**
 * Class FlaggedQuestions
 */
#[\AllowDynamicProperties]
class FlaggedQuestions
{
    /**
     * App instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * Database instance
     *
     * @var object
     */
    private $DB = null;

    /**
     * ClientID
     *
     * @var integer
     */
    private $clientID = 0;

    /**
     * Database tables with identifier
     *
     * @var object
     */
    private $tbl = null;

    /**
     *

    /**
     * Class constructor
     *
     * @param integer $clientID Client ID
         * @param integer $caseID   Case ID
     *
     * @return void
     */
    public function __construct($clientID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientID = (int)$clientID;
        if ($this->clientID > 0) {
            try {
                $clientDB = $this->DB->getClientDB($this->clientID);
            } catch (\Exception) {
                $this->app->logger(
                    'Unable to find client DB associated with client ID: '
                    . $this->clientID,
                    [],
                    \Skinny\Log::NOTICE
                );
                throw new \InvalidArgumentException('Invalid clientID: '. $this->clientID);
            }
        } else {
            throw new \InvalidArgumentException('Invalid clientID: '. $this->clientID);
        }
        // if ENABLE_REFERENCE_FIELDS is changed to separate flagged questions from ref fields,
        // you must also update the custom field model under fields/lists.
        if (!($this->app->ftr->has(SettingACL::ENABLE_REFERENCE_FIELDS))) {
            throw new \RuntimeException('You do not have permission to access flagged questions.');
        }
        $this->tbl = (object)null;
        $this->tbl->cstmSelList = $clientDB .'.customSelectList';
        $this->tbl->ddqName     = $clientDB .'.ddqName';
        $this->tbl->flagged     = $clientDB .'.customFieldFlagged';
        $this->tbl->qstns       = $clientDB .'.onlineQuestions';
    }

    /**
     * Public method to get All DDQs, each with questions, and also the currently flagged questions.
     *
     * @return array see: setupFlaggedLists()
     */
    public function getFlaggedQuestionLists()
    {
        return $this->buildFlaggedLists();
    }

    /**
     * Coordinate and setup ddq and flagged lists
     *
     * @return array ['ddqs' => (see: fetchDDQwithQuestions), 'flagged' => (see: fetchFlaggedQuestions)
     */
    private function buildFlaggedLists()
    {
        $rtn = ['ddqs' => [], 'flagged' => [], 'numDDQs' => 0];
        // get the current ddq's
        $ddqs = $this->fetchActiveDDQs();
        $ddqX = 1;
        if (!is_array($ddqs) || empty($ddqs)) {
            return $rtn;
        }
        foreach ($ddqs as $ddq) {
            if (!$ddq['id']) {
                continue;
            }
            $rtn['ddqs'][$ddq['id']] = [
                'id'        => $ddq['id'],
                'name'      => $ddq['name'],
                'caseType'  => $ddq['caseType'],
                'ddqVer'    => $ddq['ddqQuestionVer'],
                'sequence'  => $ddqX,
                'questions' => $this->fetchQuestions($ddq['caseType'], $ddq['ddqQuestionVer']),
            ];
            $ddqX++;
            $rtn['flagged'] = $this->fetchCurrentFlagged($ddq['caseType'], $ddq['ddqQuestionVer'], $rtn['flagged']);
        }
        $rtn['numDDQs'] = count($rtn['ddqs']);
        return $rtn;
    }

    /**
     * Get current DDQs
     *
     * @return array
     */
    private function fetchActiveDDQs()
    {
        // This makes the assumption that a tenant will always have an english version of their DDQ
        $sql = "SELECT DISTINCT "
            ."CONCAT(o.caseType, TRIM(o.ddqQuestionVer)) AS `id`, n.name, n.status, o.caseType, o.ddqQuestionVer \n"
            ."FROM {$this->tbl->qstns} AS o \n"
            ."LEFT JOIN {$this->tbl->ddqName} AS n ON "
                ."(n.legacyID=CONCAT('L-',o.caseType,TRIM(o.ddqQuestionVer)) AND n.clientID = :clID1) \n"
            ."WHERE o.clientID = :clID2 AND o.languageCode = :langCode AND o.dataIndex > :dataIdx "
                ."AND o.qStatus <> :qStatus AND n.status = :status \n"
            ."ORDER BY o.caseType ASC, o.ddqQuestionVer ASC";
        $params = [
            ':clID1' => $this->clientID, ':clID2' => $this->clientID, ':langCode' => 'EN_US',
            ':dataIdx' => 0, ':qStatus' => 2, ':status' => 1
        ];

        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Get questions for specified DDQ
     *
     * @param integer $caseType   caseType.id (value from the onlineQuestions row)
     * @param string  $ddqVersion onlineQuestions.ddqQuestionVer
     *
     * @return array
     */
    private function fetchQuestions($caseType, $ddqVersion)
    {
        $sql = "SELECT questionID AS `id`, labelText AS `name`, controlType, generalInfo "
            ."FROM {$this->tbl->qstns} "
            . "WHERE languageCode = :lang AND caseType = :case AND ddqQuestionVer = :ver "
            . "AND clientID = :cID AND (controlType = :ctrl1 OR controlType = :ctrl2) AND qStatus = :qSts";
        $params = [
            ':lang' => 'EN_US', ':case' => $caseType, ':ver' => $ddqVersion, ':cID' => $this->clientID,
            ':ctrl1' => 'radioYesNo', ':ctrl2' => 'DDLfromDB', ':qSts' => 1,
        ];

        $allQs = $this->DB->fetchAssocRows($sql, $params);
        $rtnQs = [];
        foreach ($allQs as $k => $v) {
            if (empty($v['id']) || empty($v['name'])) {
                continue;
            }
            $tmpQ = [
                'id'      => $v['id'],
                'name'    => substr(trim(strip_tags(html_entity_decode((string) $v['name']))), 0, 89),
                'options' => [],
            ];
            $tmpQ['name'] = mb_convert_encoding($tmpQ['name'], 'UTF-8', 'auto');
            if ($v['controlType'] == 'DDLfromDB') {
                $listName = explode(',', (string) $v['generalInfo']);
                if (isset($listName[2])) {
                    $listName = $listName[2];
                    $sql = "SELECT `id`, `name` FROM {$this->tbl->cstmSelList} "
                        . "WHERE clientID = :clientID "
                        . "AND listName = :listName";
                    $listParams = [':clientID' => $this->clientID, ':listName' => $listName];
                    $listOptions = $this->DB->fetchAssocRows($sql, $listParams);
                    $tmpQ['options'] = $listOptions;
                } else {
                    continue;
                }
            } elseif ($v['controlType'] == 'radioYesNo') {
                $tmpQ['options'] = [
                    ['id' => 'Yes', 'name' => 'Yes'],
                    ['id'  =>'No', 'name' => 'No']
                ];
            } else {
                continue;
            }
            $rtnQs[] = $tmpQ;
        }
        return $rtnQs;
    }

    /**
     * Get currently flagged questions
     *
     * @param integer $caseType   caseType.id (value from the onlineQuestions row)
     * @param string  $ddqVersion onlineQuestions.ddqQuestionVer
     * @param array   $flagged    array of currently flagged questions (previous outputs from this method)
     *
     * @return array
     */
    private function fetchCurrentFlagged($caseType, $ddqVersion, $flagged)
    {
        $sql = "SELECT fieldID, caseType, ddqQuestionVer, qID, displayName, flaggedAnswer "
            ."FROM {$this->tbl->flagged} "
            ."WHERE clientID = :cID AND  caseType = :case AND ddqQuestionVer = :ver";
        $params = [':cID' => $this->clientID, ':case' => $caseType, ':ver' => $ddqVersion];
        $flagQs = $this->DB->fetchAssocRows($sql, $params);
        if (!is_array($flagQs) || empty($flagQs)) {
            return $flagged;
        }
        foreach ($flagQs as $fq) {
            $ddqID = $fq['caseType'] . $fq['ddqQuestionVer'];
            if (!isset($flagged[$fq['fieldID']][$ddqID]['numQs'])) {
                $flagged[$fq['fieldID']][$ddqID]['numQs'] = 0;
            }
            $flagged[$fq['fieldID']][$ddqID]['numQs'] = ($flagged[$fq['fieldID']][$ddqID]['numQs'] + 1);
            $flagged[$fq['fieldID']][$ddqID]['flagged'][] = [
                'id'    => $fq['qID'],
                'name'  => $fq['displayName'],
                'val'   => json_decode((string) $fq['flaggedAnswer']),
            ];
        }
        return $flagged;
    }
}
