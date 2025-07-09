<?php
/**
 * Update thirdPartyProfile calculated fields off-line based on queue
 */

namespace Models\TPM;

use Lib\Support\ForkProcess;
use Models\BaseLite\RequireClientID;

/**
 * Update thirdPartyProfile calculated fields off-line based on queue
 */
#[\AllowDynamicProperties]
class TpQueue extends RequireClientID
{

    /**
     * Limit the number of scripts running in the back ground,
     * we only need so many queue processes
     *
     * @var integer
     */
    public const MAX_PROCESSES = 4;

    /**
     * Queue table name
     *
     * @var string
     */
    protected $tbl = 'tpQueue';

    /**
     * @var Lib\Support\ForkProcess
     */
    protected $fork;

    /**
     * Number of rows in this instance pending processing
     *
     * @var integer
     */
    protected $rowsToProcess = 0;

    /**
     * Constructor
     *
     * @param integer $clientID   thirdPartyProfile.id
     * @param array   $connection Alternate connection values
     *
     * @return void
     */
    public function __construct($clientID, $connection = [])
    {
        $this->setClientIdField('tenantID');
        parent::__construct($clientID, $connection);
        $this->fork = new ForkProcess();
    }


    /**
     * Add the 3P id to the queue
     *
     * @param integer $tpID thirdPartyProfile.id
     *
     * @return void
     */
    public function add3P($tpID)
    {
        // make sure we don't already have the 3P Profile 'pending' in the tpQueue
        $sql = "SELECT COUNT(*) FROM {$this->clientDB}.{$this->tbl} "
            . " WHERE tenantID = :tenantID AND tpID = :tpID AND status = :status";
        $params = [':tenantID' => $this->clientID, ':tpID' => $tpID, ':status' => 'pending'];
        $pending = $this->DB->fetchValue($sql, $params);

        if (!$pending) {
            $this->insert(['tpID' => $tpID, 'tenantID' => $this->clientID]);
            $this->rowsToProcess++;
            $this->runCalc();
        }
    }


    /**
     * Run the 3P field calculations from the queue
     *
     * @param bool $final Ignore MAX_PROCESSES if called from destructor
     *
     * @return integer processID
     */
    public function runCalc($final = false)
    {
        $maxProcesses = $final ? null : self::MAX_PROCESSES;
        $target = "Controllers.Cli.Queue.ThirdPartyQueue::updateCalcFields {$this->clientID}";
        $ret = $this->fork->launch($target, '', $maxProcesses);
        $this->rowsToProcess = 0;
        return $ret;
    }

    /**
     * Assure that we don't miss any 3P calculations
     *
     * @return void
     */
    /* Jira TPM-732: This is being temporarily commented out as ignoring the MAX_PROCESS check is resulting in the
             CLI Controllers.Cli.Queue.ThirdPartyQueue::updateCalcFields to be instantiated many times (depending
             on the job size, could be hundreds of times). See the Jira issue for more details. Also I don't
             think a call in the destructor to runCalc() is needed since there's other calls to runCalc()
             already being made elsewhere.
    public function __destruct()
    {
        if ($this->rowsToProcess > 0) {
            $this->runCalc(true); // finish w/o regard for MAX_PROCESSES
        }
    }
    */
}
