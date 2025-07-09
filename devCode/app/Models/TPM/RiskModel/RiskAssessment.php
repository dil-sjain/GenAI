<?php
/**
 * riskAssessment records
 */

namespace Models\TPM\RiskModel;

use Lib\Validation\Validator\IsDatetime;

/**
 * riskAssessment records
 *
 * @keywords risk model, risk, assessment, risk assessment
 */
#[\AllowDynamicProperties]
class RiskAssessment extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string table name
     */
    protected $tbl = 'riskAssessment';

    /**
     * @var boolean tabled is in a client database
     */
    protected $tableInClientDB = true;

    /**
     * @var string primary id column
     */
    protected $primaryID = null;

    /**
     * @var array Enum values for status
     */
    protected $statusEnum = null; // php 5.6 can initialize this and eliminate constructor

    /**
     * Constructor - set properties
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $connection Alternate connection values
     *
     * @return void
     */
    public function __construct($clientID, $connection = [])
    {
        parent::__construct($clientID, $connection);
        $this->statusEnum = ['test', 'current', 'past'];
    }

    /**
     * Get one assessment record
     *
     * @param integer $tpID     thirdPartyProfile.id
     * @param integer $rmodelID riskModel.id
     * @param string  $status   (optional) valid status value, usually for 'current' or 'test'
     * @param string  $tstamp   (optional) 'Y-m-d H:i:s' timestamp
     *
     * @return mixed false on not match or assoc array of record
     */
    public function getRecord($tpID, $rmodelID, $status = null, $tstamp = null)
    {
        if (empty($tpID) || empty($rmodelID)) {
            return false;
        }
        $raWhere = ['tpID' => $tpID, 'model' => $rmodelID];
        if (in_array($status, $this->statusEnum)) {
            $raWhere['status'] = $status;
        }
        if ((new IsDatetime($tstamp, 'datetime'))->isValid()) {
            $raWhere['tstamp'] = $tstamp;
        }
        if ($record = $this->selectOne([], $raWhere, 'ORDER BY tstamp DESC')) {
            $record['details'] = @unserialize($record['details']);
        }
        return $record;
    }
    
    /**
     * Get assessment records by status default is current
     *
     * @param integer $tpID     thirdPartyProfile.id
     * @param string  $status   (optional) valid status value, usually for 'current' or 'test'
     *
     * @return array assoc array of record
     */
    public function getRecords(int $tpID, string $status = ''): array
    {
        $records = [];
        try {
            if ($tpID) {
                $raWhere = ['tpID' => $tpID];
                if (in_array($status, $this->statusEnum)) {
                    $raWhere['status'] = $status;
                }
                $cols = ['model', 'normalized', 'tier', 'details'];
                $records = $this->selectMultiple($cols, $raWhere, 'ORDER BY id DESC');
            }
        } catch (\Exception $e) {
            echo "Error in getRecords: ".$e->getMessage() . PHP_EOL;
        }
        return $records;
    }

    /**
     * Update unused assessment records with 'past' status for a profile or engagement.
     *
     * @param integer $tpID         thirdpartyProfile.id or engagement.id
     * @param array   $riskModelIDs (optional) riskModel.id
     *
     * @return int
     */
    public function updateUnusedRiskAssessments(int $tpID, array $riskModelIDs = []): int
    {
        $status = 0;
        try {
            if (!empty($tpID)) {
                // Set unused risk model's 'current' assessment records to 'past' for this tpID
                $status = $this->update(['status' => 'past'], ['tpID' => $tpID,
                                                                'status' => 'current',
                                                                'model' => ['not_in' => $riskModelIDs]]);
            }
        } catch (\Exception $e) {
            echo "Error in updateUnusedRiskAssessments: ".$e->getMessage() . PHP_EOL;
        }
        return $status;
    }

    /**
     * Update old assessment record with 'past' status
     *
     * @param integer $riskModelID riskModel.id
     * @param integer $tpID        thirdPartyProfile.id
     *
     * @return int
     */
    public function updateOldAssessment(int $riskModelID, int $tpID = 0): int
    {
        $status = 0;
        try {
            if (!empty($riskModelID)) {
                // Set risk model's 'current' assessment record to 'past'
                $where = ['model' => $riskModelID, 'status' => 'current'];
                if (!empty($tpID)) {
                    $where['tpID'] = $tpID;
                }
                $status = $this->update(['status' => 'past'], $where);
            }
        } catch (\Exception $e) {
            echo "Error in updateOldAssessment: ".$e->getMessage() . PHP_EOL;
        }
        return $status;
    }

    /**
     * Get tier name from profile's current risk assessment
     *
     * @param int $tpID        thirdPartyProfile.id
     * @param int $riskModelID thirdPartyProfile.riskModel
     *
     * @return string
     */
    public function getRiskRating($tpID, $riskModelID)
    {
        $name = '';
        $tpID = (int)$tpID;
        $riskModelID = (int)$riskModelID;
        if ($tpID <= 0 || $riskModelID <= 0) {
            return $name;
        }
        $tierTbl = $this->clientDB . '.riskTier';
        $sql = "SELECT t.tierName\n"
            . "FROM $this->tbl AS ra\n"
            . "INNER JOIN $tierTbl AS t ON t.id = ra.tier\n"
            . "WHERE ra.tpID = :tpID AND ra.status = 'current'\n"
            . "AND ra.model = :model AND ra.clientID = :cid\n"
            . "ORDER BY ra.id DESC LIMIT 1";
        $params = [':tpID' => $tpID, ':model' => $riskModelID, ':cid' => $this->clientID];
        if ($tier = $this->DB->fetchValue($sql, $params)) {
            $name = $tier;
        }
        return $name;
    }

    /**
     * Update status of a risk assessment record to 'past'
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return void
     */
    public function updateStatus($rmodelID)
    {
        $rmodelID = (int)$rmodelID;
        if (!$rmodelID) {
            return;
        }
        try {
            $where = ['model' => $rmodelID, 'status' => 'current'];
            $data = ['status' => 'past'];
            $this->update($data, $where);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }
}
