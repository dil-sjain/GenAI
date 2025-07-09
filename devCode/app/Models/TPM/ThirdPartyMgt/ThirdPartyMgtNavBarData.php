<?php
/**
 * Model for the "ThirdPartyMgt" sub-tabs
 */

namespace Models\TPM\ThirdPartyMgt;

use Lib\Legacy\CaseStage;
use Lib\Legacy\ClientIds;
use Models\ThirdPartyManagement\ThirdParty;
use Models\Globals\MultiTenantAccess;

/**
 * Class ThirdPartyMgtNavBarData determines if access to its sub-tabs is allowed based upon unique conditions.
 *
 * @keywords third party management tab, third party management navigation
 */
#[\AllowDynamicProperties]
class ThirdPartyMgtNavBarData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var string Client database name
     */
    private $clientDB = null;

    /**
     * @var object Database instance
     */
    protected $DB = null;

    /**
     * Init class constructor
     *
     * @param integer $clientID Client ID
     */
    public function __construct(private $clientID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
    }

    /**
     * Determine if access is allowed to the Pending Review tab
     *
     * @return boolean True if access is allowed, else false
     */
    public function allowPendingReviewAccess()
    {
        $moderateFeatures = [
            \Feature::GIFTS,
            \Feature::GIFTS_MODERATE
        ];
        $giftFeatures = [
            \Feature::TENANT_TPM_GIFTS,
            \Feature::TENANT_TPM_RELATION
        ];

        $canModerate = $this->app->ftr->hasAllOf($moderateFeatures);
        $hasGiftFtrs  = $this->app->ftr->hasAllOf($giftFeatures);

        $sql = "SELECT COUNT(*) FROM {$this->clientDB}.tpGifts "
            . "WHERE clientID = :clientID AND status = 'Pending'";
        $params = [':clientID' => $this->clientID];
        $isGiftPending = ($this->DB->fetchValue($sql, $params)) ? true : false;

        $tp = new ThirdParty($this->clientID);
        $userCondReturn = $tp->getUserConditions('tpp');
        $userCond = $userCondReturn['userCond'];
        $userParams = $userCondReturn['sqlParams'];

        $sql = "SELECT COUNT(DISTINCT tpp.id) AS cnt\n"
            . "FROM {$this->clientDB}.thirdPartyProfile AS tpp\n"
            . "LEFT JOIN cases AS c ON c.tpID=tpp.id\n"
            . "WHERE tpp.clientID = :clientID AND c.id IS NOT NULL AND c.clientID = :clientID2\n"
            . "AND " . $userCond . "\n"
            . "AND tpp.approvalStatus = 'pending' AND tpp.status <> 'deleted'\n"
            . "AND c.caseStage < " . CaseStage::TRAINING_INVITE . "\n" // Must not be training invite (below is ok)
            . "AND c.caseStage >= " . CaseStage::COMPLETED_BY_INVESTIGATOR . "\n" // completed by investigator or better
            . "AND c.caseStage <> " . CaseStage::CASE_CANCELED . "\n" // but not Case Rejected (still an open case)
            . "AND c.caseStage <> " . CaseStage::DELETED; // and not Case Deleted
        $params = [':clientID' => $this->clientID, ':clientID2' => $this->clientID];
        $params = array_merge($params, $userParams);
        $hasPending3pApproval = ($this->DB->fetchValue($sql, $params) > 0);
        return (($hasGiftFtrs && $canModerate && $isGiftPending) || $hasPending3pApproval);
    }

    /**
     * Determine if access is allowed to the Profile Details tab
     *
     * @param integer $tpID Third Party Profile ID
     *
     * @return boolean $allowAccess True if access is allowed, else false
     */
    public function allowProfileDetailAccess($tpID)
    {
        if (empty($tpID)) {
            return false;
        }
        // make sure the tpID belongs to the client and not someone tampering
        // with the ID to gain access to another 3P
        $sql = "SELECT id FROM {$this->clientDB}.thirdPartyProfile WHERE id = :tpID AND clientID = :clientID LIMIT 1";
        $params = [':tpID' => $tpID, ':clientID' => $this->clientID];
        return ($tpID == $this->DB->fetchValue($sql, $params));
    }


    /**
     * Determine if user has access to multi-tenant access
     *
     * @return boolean
     */
    public function allowUberSearch()
    {
        return (new MultiTenantAccess())->hasMultiTenantAccess($this->clientID);
    }
}
