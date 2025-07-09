<?php
/**
 * Handles special custom fields type that references outside tables and fields
 */

namespace Models\TPM\CaseMgt;

/**
 * Class CustomRefData
 */
#[\AllowDynamicProperties]
class CustomRefData
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
     * CaseID
     *
     * @var integer
     */
    private $caseID = 0;

    /**
     * Client DB identifier
     *
     * @var string
     */
    private $clientDB = '';

    /**
     * Ref to DB tables in use
     *
     * @var object
     */
    private $tbl = null;

    /**
     * Risk Model ID
     *
     * @var int
     */
    private $riskModel = 0;

    /**
     * Country.id
     *
     * @var int
     */
    private $countryID = 0;

    /**
     * Holds riskAssessment data associated w/ $caseID
     *
     * @var object
     */
    private $riskAssessment = null;

    /**
     * Define available reference fields
     *
     * @var array
     */
    private $refOpts = [];

    /**
     * Holds risk overrides if they exist
     *
     * @var array
     */
    private $overrides = [];

    /**
     * Create class instance and set third party profile id and case id
     * Use zero value if either does not apply
     *
     * @param integer $clientID Client ID
     * @param integer $caseID   Case ID
     *
     * @return void|bool
     */
    public function __construct($clientID, $caseID = 0)
    {
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;

        $this->clientID = (int)$clientID;
        $this->caseID   = (int)$caseID;

        if ($this->clientID < 1) {
            throw new \InvalidArgumentException('Invalid clientID: '. $this->clientID .'. ClientID is required.');
        }

        $this->setDBtables();

        $this->setRiskAssessment();

        //gotta do all this just to get the serialized country override info...
        $this->setRiskModel();
        if ($this->riskModel > 0) {
            $this->setRiskOverrides();
        }

        if (strlen($this->clientDB) > 0) {
            $this->setCountry();
        }

        $this->defineRefOpts();
    }

    /**
     * Retrieve all Ref type custom fields for the current client
     *
     * @return array
     */
    public function getRefFields()
    {
        return $this->DB->fetchAssocRows(
            "SELECT * FROM {$this->tbl->cstmFld} WHERE clientID = :clientID AND type = :ref",
            [':clientID' => $this->clientID, ':ref' => 'ref']
        );
    }

    /**
     * Populate custom fields with current ref data for current case
     *
     * @return void
     * @throws \Exception
     */
    public function setCustomFields()
    {
        if (empty($this->caseID)) {
            throw new \Exception('Case ID is not set.');
        }

        $fields = $this->getRefFields();
        foreach ($fields as $field) {
            // Pass in field ref type to get value
            $val = $this->getRefData($field['refID']);

            // Pass in custom field ID to link value to field
            $this->saveRefData($field['id'], $val);
        }
    }

    /**
     * Get reference info that tells us which table entry to look for
     *
     * @param int $id refFields.id
     *
     * @return mixed
     */
    public function getRefInfo($id)
    {
        return $this->DB->fetchObjectRows(
            "SELECT * FROM {$this->tbl->refFlds} WHERE id = :id",
            [':id' => (int)$id]
        );
    }

    /**
     * Check if specified client/case have custom reference fields tied to them
     *
     * @return bool
     */
    public function hasRefFields()
    {
        return $this->DB->fetchValue(
            "SELECT COUNT(id) FROM {$this->tbl->cstmFld} WHERE clientID = :clientID AND type = :ref LIMIT 1",
            [':clientID' => $this->clientID, ':ref' => 'ref']
        );
    }

    /**
     * perform search for info being referenced
     *
     * @param int $id refOpt ID defining parameters to use in search
     *
     * @return mixed
     */
    public function getRefData($id)
    {
        $id = intval($id);

        if (array_key_exists($id, $this->refOpts) && $id > 0) {
            $refInfo = $this->refOpts[$id];
            if (array_key_exists('override', $refInfo) && !is_null($refInfo['override'])) {
                return $refInfo['override'];
            }
            $sql = trim((string) $refInfo['sql']);
            $data = $this->DB->fetchValue($sql);
            return $data;
        }
        return null;
    }

    /**
     * public accessor for refOpts
     *
     * @return array
     */
    public function getRefOpts()
    {
        return $this->refOpts;
    }

    /**
     * Validate whether passed value is a valid reference field ID
     *
     * @param integer $refID
     *
     * @return boolean Return true if valid, else false
     */
    public function validRefField($refID)
    {
        $refID = (int)$refID;
        foreach ($this->refOpts as $opt) {
            if ($opt['id'] == $refID) {
                return true;
            }
        }
        return false;
    }

    /**
     * Define all DB tables needed in the model.
     *
     * @return void
     */
    private function setDBtables()
    {
        try {
            $this->clientDB = $this->DB->getClientDB($this->clientID);
        } catch (\Exception) {
            $this->app->logger(
                'Unable to find client DB associated with client ID: '
                . $this->clientID,
                [],
                \Skinny\Log::NOTICE
            );
            throw new \InvalidArgumentException('Invalid clientID. `'. $this->clientID .'` is not a valid client.');
        }
        $this->tbl = (object)null;
        $this->tbl->cstmData  = $this->clientDB .'.customData';
        $this->tbl->cstmFld   = $this->clientDB .'.customField';
        $this->tbl->ddq       = $this->clientDB .'.ddq';
        $this->tbl->refFlds   = $this->clientDB .'.refFields';
        $this->tbl->riskAsmnt = $this->clientDB .'.riskAssessment';
        $this->tbl->riskFctr  = $this->clientDB .'.riskFactor';
        $this->tbl->gCpiScore = $this->DB->globalDB .'.g_cpiScore';
    }

    /**
     * Defines available reference fields
     * if overrides are set they will be used and sql will be ignored
     *
     * @return void
     */
    private function defineRefOpts()
    {
        $this->refOpts[] = ['id' => 0, 'name' => ''];
        $this->refOpts[] = ['id' => 1, 'name' => 'Risk Assessment', 'override' => (
        property_exists($this->riskAssessment, 'normalized')
            ? $this->riskAssessment->normalized
            : false
        )];
        $this->refOpts[] = ['id' => 2, 'name' => 'CPI', 'sql' => "
                SELECT score
                FROM {$this->tbl->gCpiScore}
                WHERE isoCode = :countryID
                ORDER BY cpiYEAR DESC
                LIMIT 1
            ", 'params' => [
            ':countryID' => $this->countryID
        ], 'override' => (
        array_key_exists($this->countryID, $this->overrides)
            ? $this->overrides[$this->countryID]
            : null
        )];
    }

    /**
     * Set or update custom field data
     *
     * @param $fieldID
     * @param $value
     *
     * @return void
     * @throws \Exception
     */
    private function saveRefData($fieldID, $value)
    {
        if (empty($this->caseID)) {
            throw new \Exception('No case ID specified.');
        }
        if (empty($this->clientID)) {
            throw new \Exception('No tenant ID specified.');
        }

        $currentVal = $this->DB->fetchAssocRow(
            "SELECT * FROM {$this->tbl->cstmData} "
                ."WHERE fieldDefID = :fldID AND clientID = :clID AND caseID = :csID LIMIT 1",
            [
                ':fieldID'  => $fieldID,
                ':clientID' => $this->clientID,
                ':caseID'   => $this->caseID
            ]
        );

        // Update value if set
        if (isset($currentVal['id'])) {
            $sql = "UPDATE {$this->tbl->cstmData} "
                . "SET value = :value "
                . "WHERE id = :id LIMIT 1";
            $params = [
                ':value' => $value,
                ':id'    => $currentVal['id']
            ];
        } else {
            $sql = "INSERT INTO {$this->tbl->cstmData} "
                . "(`clientID`, `caseID`, `fieldDefID`, `value`) VALUES(:clientID, :caseID, :fieldID, :val)";
            $params = [
                ':clientID' => $this->clientID,
                ':caseID'   => $this->caseID,
                ':fieldID'  => $fieldID,
                ':val'    => $value
            ];
        }
        $this->DB->query($sql, $params);
    }

    /**
     * Get the risk model ID
     *
     * @return void
     */
    private function setRiskModel()
    {
        if (property_exists($this->riskAssessment, 'model')) {
            $this->riskModel = $this->riskAssessment->model;
        }
    }

    /**
     * Grab country override scores for CPI
     *
     * @return null|void
     */
    private function setRiskOverrides()
    {
        if ($this->clientID > 0 && $this->riskModel > 0) {
            $sql = "
              SELECT factor FROM {$this->tbl->riskFctr}
              WHERE clientID = :clientID
              AND model = :riskModel
              AND component = 'cpi'
            ";
            $params = [
                ':clientID'  => $this->clientID,
                ':riskModel' => $this->riskModel
            ];

            $data = unserialize($this->DB->fetchValue($sql, $params));

            if (!$data) {
                return null;
            }

            if (property_exists($data, 'overrides')) {
                foreach ($data->overrides as $key => $val) {
                    if (preg_match('/^cpioverride-([A-Z]{2})$/', (string) $key, $match)) {
                        $this->overrides[$match[1]] = $val;
                    }
                }
            }
        }
    }

    /**
     * Set the countryID
     * fallback to riskAssessment to get the country if it doesn't exist in ddq tbl
     *
     * @return void
     */
    private function setCountry()
    {
        $data = $this->DB->fetchValue(
            "SELECT country FROM {$this->tbl->ddq} WHERE caseID = :caseID",
            [':caseID' => $this->caseID]
        );

        if ($data) {
            $this->countryID = $data;
        } elseif (property_exists($this->riskAssessment, 'details')
            && property_exists($this->riskAssessment->details, 'iso')
        ) {
            $this->countryID = $this->riskAssessment->details->iso;
        }
    }

    /**
     * Get the riskAssessment table associated with $caseID
     *
     * @return object
     */
    private function setRiskAssessment()
    {
        $this->riskAssessment = (object)null;
        $data = false;

        if (!$this->clientDB || !$this->clientID || !$this->caseID) {
            return $this->riskAssessment;
        }

        $sql = "SELECT tpID FROM cases where ID = :caseID";
        $tpID = $this->DB->fetchObjectRow($sql, [':caseID' => $this->caseID]);

        if ($tpID > 0) {
            $sql = "SELECT ddq.id from ddq where caseID = :caseID";
            $ddqPid = $this->DB->fetchObjectRow($sql, [':caseID' => $this->caseID]);

            if ($ddqPid > 0) {
                $sql = "SELECT * FROM {$this->tbl->riskAsmnt} "
                    . "WHERE clientID = :clientID AND status != 'test' AND tpID = :tpID "
                    . "AND details LIKE CONCAT('%ddqPID\";i:', :ddqPid, ';%')"
                    . "ORDER BY tstamp DESC "
                    . "LIMIT 1";
                $params = [
                    ':clientID' => $this->clientID,
                    ':tpID' => $tpID,
                    ':ddqPid' => $ddqPid
                ];
                $data = $this->DB->fetchObjectRow($sql, $params);
            }
        }

        if ($data) {
            $this->riskAssessment = $data;
            $this->riskAssessment->details = unserialize($this->riskAssessment->details);
        }
    }
}
