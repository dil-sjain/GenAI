<?php
/**
 * Access and manage 3P Renewal Rules data
 */

namespace Models\TPM\Settings\ContentControl\RenewalRules;

use Models\BaseLite\BasicCRUD;

#[\AllowDynamicProperties]
class RenewalDateFields extends BasicCRUD
{
    /**
     * Required table name
     * @var string
     */
    protected $tbl = 'renewalDateFields';

    /**
     * Client database name
     * @var string
     */
    protected $dbName = '';

    /**
     * Initialize instance properties
     *
     * @param array $connection Optional DB connection to override app->DB
     *
     * @throwss \Exception if $app->ftr is not set
     * @return void
     */
    public function __construct($connection = [])
    {
        $bareTbl = $this->tbl; // grab before parent alters
        parent::__construct($connection);
        $app = \Xtra::app();
        if (!is_object($app->ftr)) {
            throw new \Exception('Application FeatureACL has not been set');
        }
        $this->dbName = $app->DB->getClientDB($app->ftr->tenant);
        $this->tbl = "$this->dbName.$bareTbl";
        if (!$this->DB->tableExists($bareTbl, $this->dbName)) {
            $this->createTable();
        }
    }

    /**
     * Populate newly created table
     *
     * @return mixed result of $this->DB->query()
     */
    private function populate()
    {
        $sql = <<<EOT
INSERT INTO $this->tbl VALUES
('statChg', 'Approval Status Change Date', 10),   -- thirdPartyProfile.lastApprovalStatusUpdate
('frmSub', 'Form Submission Date', 15),           -- ddq.subByDate
('invDone', 'Investigation Completion Date', 20), -- cases.caseCompletedByInvestigator
('tpCF', '3P Custom Date Field', 25),             -- customData.value of 3P date field
('exclude', 'Exclude from Renewal', 5)            -- Do not renew
EOT;
        return $this->DB->query($sql);
    }

    /**
     * Create table if it doesn't exists
     *
     * @return bool
     */
    private function createTable()
    {
        $sql = <<<EOT
CREATE TABLE IF NOT EXISTS $this->tbl (
    abbrev varchar(10) NOT NULL DEFAULT '' COMMENT 'Date field abbreviation',
    name varchar(30) NOT NULL DEFAULT '' COMMENT 'Date field name',
    precedence tinyint NOT NULL DEFAULT '0' COMMENT 'Order in which date fields are considered',
    PRIMARY KEY (abbrev),
    UNIQUE KEY name (name),
    UNIQUE KEY precedence (precedence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
EOT;

        try {
            $this->DB->query($sql);
            $this->populate();
            $rtn = true;
        } catch (\PDOException) {
            $rtn = false;
        }
        return $rtn;
    }

    /**
     * PHPUNIT only - test error on table creation
     *
     * @return mixed null or bool
     */
    public function puCreateTable()
    {
        $app = \Xtra::app();
        if ($app->phpunit && $app->renewalMode !== 'disallow') {
            return $this->createTable();
        }
    }
}
