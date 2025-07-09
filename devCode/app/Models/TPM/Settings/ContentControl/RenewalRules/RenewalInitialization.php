<?php
/**
 * Track triggered renewals as unique comparisons in renewalInitialization
 */

namespace Models\TPM\Settings\ContentControl\RenewalRules;

#[\AllowDynamicProperties]
class RenewalInitialization extends \Models\BaseLite\RequireClientID
{
    /**
     * Required table name
     * @var string
     */
    protected $tbl = 'renewalInitialization';

    /**
     * Indicate if table is in client's database
     * @var bool
     */
    protected $tableInClientDB = true;

    /**
     * Initialize instance properties
     *
     * @param int   $clientID   clientProfile.id
     * @param array $connection Optional DB connection to override app->DB
     */
    public function __construct($clientID, $connection = [])
    {
        $bareTbl = $this->tbl; // grab before parent alters
        parent::__construct($clientID, $connection);
        if (!$this->DB->tableExists($bareTbl, $this->clientDB)) {
            $this->createTable();
        }
    }

    /**
     * Determine if this comparison has already triggered a renewal
     *
     * @param int    $tpID          thirdPartyProfil.id
     * @param int    $cmpRecordID   Comparison record id
     * @param string $cmpRecordDate Compairson date
     * @param string $renewalTrack  renewalDateFields.abbrev
     *
     * @return bool
     */
    public function alreadyTriggered($tpID, $cmpRecordID, $cmpRecordDate, $renewalTrack = 'tpCF')
    {
        $cmpHash = $this->mkCmpHash($tpID, $cmpRecordID, $cmpRecordDate, $renewalTrack);
        return (bool)$this->selectValue('id', ['cmpHash' => $cmpHash]);
    }

    /**
     * Insert unique comparison properties that triggered a renewal
     *
     * @param int    $tpID          thirdPartyProfile.id
     * @param int    $cmpRecordID   Comparison record id
     * @param string $cmpRecordDate Compairson date
     * @param string $renewalTrack  renewalDateFields.abbrev
     *
     * @return mixed 1 on success, true if already exists, false on failure
     */
    public function markInitialization($tpID, $cmpRecordID, $cmpRecordDate, $renewalTrack = 'tpCF')
    {
        if (!$this->alreadyTriggered($tpID, $cmpRecordID, $cmpRecordDate, $renewalTrack)) {
            $cmpHash = $this->mkCmpHash($tpID, $cmpRecordID, $cmpRecordDate, $renewalTrack);
            return $this->insert(compact('tpID', 'cmpRecordID', 'cmpRecordDate', 'renewalTrack', 'cmpHash'));
        } else {
            return true;
        }
    }

    /**
     * Generate binary SHA1 for unique comparison identifier
     *
     * @param int    $tpID          thirdPartyProfil.id
     * @param int    $cmpRecordID   Comparison record id
     * @param string $cmpRecordDate Compairson date
     * @param string $renewalTrack  renewalDateFields.abbrev
     *
     * @return string binary cmpHash
     */
    private function mkCmpHash($tpID, $cmpRecordID, $cmpRecordDate, $renewalTrack)
    {
        return sha1('' . $this->clientID . $tpID . $cmpRecordID . $cmpRecordDate . $renewalTrack, true);
    }

    /**
     * Create table - called in contructor if table doesn't exist
     *
     * @return mixed result of $this->DB->query()
     */
    private function createTable()
    {
        // phpcs:disable
        $sql = <<<EOT
CREATE TABLE $this->tbl (
    id int AUTO_INCREMENT,
    clientID int NOT NULL DEFAULT '0' COMMENT 'clientProfile.id',
    tpID int NOT NULL DEFAULT '0' COMMENT 'thirdPartyProfile.id',
    cmpRecordID int DEFAULT NULL COMMENT 'Comparison record id',
    cmpRecordDate date DEFAULT NULL COMMENT 'Comparison date',
    renewalTrack varchar(10) DEFAULT NULL COMMENT 'renewalDateFields.abbrev',
    cmpHash binary(20) DEFAULT NULL COMMENT 'binary sha1 of concatenated clientID, tpID, cmpRecordID, cmpRecordDate, renewalTrack)',
    created timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniqueCmp (cmpHash),
    KEY clientID (clientID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
EOT;
        // phpcs:enable
        return $this->DB->query($sql);
    }
}
