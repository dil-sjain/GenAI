<?php
/**
 * Custoemr Recoud Count - orginally written for Dennis H. for insurance reporting
 * To run in CLI mode...
 * <code>
 * ./skinnycli Controllers.TPM.Admin.Subscriber.CustomerRecordCount::countCustomerRecords _c:0
 * </code>
 */

namespace Controllers\TPM\Admin\Subscriber;

use Controllers\ThirdPartyManagement\Base;
use Models\Globals\Features\TenantFeatures;
use Models\TPM\SubscriberLists;
use Models\ThirdPartyManagement\Cases;
use Models\Globals\UtilityUsage;

/**
 * Count cases of non-3P subscribers + number of 3P records for other subscribers.
 * Deleted records are ignored in counts
 */
class CustomerRecordCount extends Base
{
    /**
     * @var \Skinny\Skinny instance
     */
    protected $app = null;

    /**
     * @var object intance of MySqlPdo class
     */
    protected $DB = null;

    /**
     * @var integer Number of case management only clients
     */
    protected $caseOnlySubs = 0;

    /**
     * @var integer Number p 3P enabled clients
     */
    protected $fullSubs = 0;

    /**
     * @var integer Total number of cases
     */
    protected $ttlCases = 0;

    /**
     * @var integer Totel number of 3P profiles
     */
    protected $ttlProfiles = 0;

    /**
     * Set up instances of application and database connenct
     *
     * @param int   $clientID   Current client ID
     * @param array $initValues Any additional parameters that need to be passed in
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        if (\Xtra::isNotCli()) {
            parent::__construct($clientID, $initValues);
        }
        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;
    }

    /**
     * Iterate through all active client databases and count stored customer records
     *
     * @return void
     */
    public function countCustomerRecords()
    {
        if (\Xtra::isNotCli()) {
            $this->setViewValue('pgTitle', 'Customer Records');
            $this->setViewValue('canAccess', true);
        }

        // iterate through all active subscribers
        $subList = new SubscriberLists();
        $subs = $subList->getActiveSubscribers();
        $tenantIDs = [];
        foreach ($subs as $sub) {
            $tenantIDs[] = (int)$sub->cid;
        }
        $tenantsEnabledTP = (new TenantFeatures)->tenantsHaveFeature(
            $tenantIDs,
            \Feature::TENANT_TPM,
            \Feature::APP_TPM
        );
        foreach ($subs as $sub) {
            // is 3P enabled?
            if (!empty($tenantsEnabledTP[$sub->cid])) {
                // y
                $this->fullSubs++;
                $profiles = $this->countProfiles($sub->cid, $sub->db);
                $this->ttlProfiles += $profiles;
            } else {
                // n
                $this->caseOnlySubs++;
                $cases = $this->countCases($sub->cid, $sub->db);
                $this->ttlCases += $cases;
            }
        }
        // present summary
        if (\Xtra::isNotCli()) {
            $this->setViewValue('sums', $this->showSummary());
            echo $this->app->view->render('TPM/Admin/Subscriber/CustomerRecordCount.tpl', $this->getViewValues());
        } else {
            $this->showSummary();
        }
        if ($_SERVER['REQUEST_URI'] == '/tpm/adm/sub/rec-count') {
            (new UtilityUsage())->addUtility('Count Stored Records');
        }
    }

    /**
     * Count number of case for case management only client
     *
     * @param integer $clientID clientProfile.id
     * @param string  $dbName   Database name
     *
     * @return integer number of cases
     */
    protected function countCases($clientID, $dbName)
    {
        $sql = "SELECT COUNT(*) FROM $dbName.cases WHERE clientID = :cid AND caseStage <> :del";
        $params = [':cid' => $clientID, ':del' => Cases::DELETED];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Count number of profile for 3P enabled client
     *
     * @param integer $clientID clientProfile.id
     * @param string  $dbName   Database name
     *
     * @return integer number of profiles
     */
    protected function countProfiles($clientID, $dbName)
    {
        $sql = "SELECT COUNT(*) FROM $dbName.thirdPartyProfile\n"
            . "WHERE clientID = :cid AND status <> 'deleted'";
        $params = [':cid' => $clientID];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Display the results
     *
     * @return array formatted sums and counts
     */
    protected function showSummary()
    {
        $prettyCases = str_pad(number_format($this->ttlCases, 0, '', ','), 34, ' ', STR_PAD_LEFT);
        $prettyProfiles = str_pad(number_format($this->ttlProfiles, 0, '', ','), 34, ' ', STR_PAD_LEFT);
        $prettyTotal = str_pad(number_format($this->ttlCases + $this->ttlProfiles, 0, '', ','), 12, ' ', STR_PAD_LEFT);
        $today = date('Y-m-d');

        $vals = [
            'formattedCases' => $prettyCases,
            'formattedProfiles' => $prettyProfiles,
            'formattedTotal' => $prettyTotal,
            'caseOnlySubs' => $this->caseOnlySubs,
            'fullSubs' => $this->fullSubs,
            'today'     => $today,
        ];

        if (\Xtra::isCli()) {
            echo <<<EOT

Case Management Only - $this->caseOnlySubs
      Cases: $prettyCases

Third Party Enabled  - $this->fullSubs
   Profiles: $prettyProfiles
-----------------------------------------------
Customer Records as of $today  $prettyTotal

EOT;
        }
        return $vals;
    }
}
