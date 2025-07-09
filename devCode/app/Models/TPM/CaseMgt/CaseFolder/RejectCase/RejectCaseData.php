<?php
/**
 * Model: tpm caseMgt rejectCaseData
 */
namespace Models\TPM\CaseMgt\CaseFolder\RejectCase;

use Controllers\TPM\Email\Cases\NotifyPartnerDdqReturned;
use Lib\SettingACL;
use Models\CaseRecords;
use Models\Ddq;
use Models\LogData;
use Models\ThirdPartyManagement\Cases;
use Models\User;
use Lib\Legacy\CaseStage;
use Lib\Legacy\UserType;
use Lib\DdqSupport;
use Lib\Legacy\ClientIds;
use Lib\GlobalCaseIndex;

/**
 * Class RejectCaseData
 *
 * @keywords reject/close case, reject, close, case
 */
#[\AllowDynamicProperties]
class RejectCaseData
{
    /**
     * @var object Skinny Application instance
     */
    protected $app = null;

    /**
     * @var object FeatureACL
     */
    protected $ftr = null;

    /**
     * @var object MySqlPDO
     */
    protected $DB  = null;

    /**
     * @var integer Tenant ID (clientProfile.id or spID/vendorID)
     */
    protected $tenantID = null;

    /**
     * @var object Cases
     */
    protected $case = null;

    /**
     * @var object User
     */
    protected $user = null;

    /**
     * @var object Ddq
     */
    protected $ddq  = null;

    /**
     * @var object RejectCaseCode
     */
    protected $rejectCaseCode = null;

    /**
     * @var int cases.caseStage
     */
    protected $origCaseStage = null;

    /**
     * Constructor - initialization
     *
     * @param integer         $tenantID clientProfile.id or spID (vendorID)
     * @param integer         $caseID   cases.id
     * @param integer         $userID   users.id
     * @param \Lib\FeatureACL $ftr      FeatureACL
     *
     * @return void
     */
    public function __construct($tenantID, $caseID, $userID, $ftr = null)
    {
        \Xtra::requireInt($tenantID);
        \Xtra::requireInt($caseID);
        \Xtra::requireInt($userID);

        $this->app = \Xtra::app();
        $this->ftr = (!empty($ftr)
            ? $ftr
            : \Xtra::app()->ftr);
        $this->DB  = $this->app->DB;

        $this->tenantID = $tenantID;
        $this->case = (new Cases($this->tenantID))->findById($caseID);
        if (!empty($this->case)) {
            $this->origCaseStage = $this->case->get('caseStage');
        }
        $this->user = (new User())->findById($userID);
        $this->ddq  = (new Ddq($this->tenantID))->findByAttributes(['caseID' => $caseID]);
    }

    /**
     * Determines if the user has access to reject/close case.
     *
     * @return boolean True if user has access to reject/close case, false otherwise.
     */
    public function hasAccess()
    {
        if (!empty($this->case) && !empty($this->user)) {
            return ((($this->case->get('caseStage') < CaseStage::BUDGET_APPROVED)
                    || ($this->case->get('caseStage') == CaseStage::CLOSED_INTERNAL)
                    || ($this->case->get('caseStage') == CaseStage::CLOSED_HELD)
                    || ($this->case->get('caseStage') == CaseStage::CLOSED)
                    || ($this->case->get('caseStage') == CaseStage::CASE_CANCELED))
                && ($this->ftr->legacyUserType > UserType::VENDOR_ADMIN)
                && ($this->user->get('userSecLevel') > UserType::USER_SECLEVEL_RO)
                && $this->ftr->has(\Feature::CLOSE_INVESTIGATION) // rejectCase
            );
        }
        return false;
    }

    /**
     * Get the case record.
     *
     * @return mixed Cases record if set, otherwise null.
     */
    public function getCase()
    {
        return $this->case;
    }

    /**
     * Sets rejectCaseCode class variable to be used by other functions
     *
     * @param integer $id rejectCaseCode.id
     *
     * @todo make and use RejectCaseCode database table model
     * @throws \Exception if no rejectCaseCode record is found
     * @return bool True if record was found and set on class property, false otherwise
     */
    public function setRejectCaseCode($id)
    {
        if (!isset($this->rejectCaseCode) || $this->rejectCaseCode->id != $id) {
            $sql = "SELECT * FROM rejectCaseCode WHERE id=:id";
            $params = [':id' => $id];
            $this->rejectCaseCode = $this->DB->fetchObjectRow($sql, $params);
            if (empty($this->rejectCaseCode)) {
                throw new \Exception($this->DB->mockFinishedSql($sql, $params));
            }
        }
        return empty($this->rejectCaseCode);
    }

    /**
     * Requires rejectCaseCode to be set.
     *
     * @throws \Exception If rejectCaseCode is not set.
     * @return void Determines if rejectCaseCode is set in order to throw exception.
     */
    protected function requireRejectCaseCode()
    {
        if (is_null($this->rejectCaseCode)) {
            throw new \Exception();
        }
    }

    /**
     * Get the reject status depending on tenant preferences.
     *
     * @return string Reject status.
     */
    public function getRejectStatus()
    {
        $txtTr = $this->app->trans->codeKeys([
            'reject_close',
            'pass_fail',
            'reject_close_reopen'
        ]);
        $ddqSupport = new DdqSupport($this->app->DB, $this->app->DB->getClientDB($this->tenantID));
        if ($ddqSupport->isClientDanaher($this->tenantID) || in_array($this->tenantID, ClientIds::HP_ALL)) {
            return $txtTr['pass_fail'];
        } elseif ($this->tenantID == ClientIds::VISAQC_CLIENTID || $this->tenantID == ClientIds::VISA_CLIENTID) {
            return $txtTr['reject_close_reopen'];
        }
        return $txtTr['reject_close'];
    }

    /**
     * Get rejectCaseCode records.
     *
     * @param integer $rejectReason rejectCaseCode.id
     *
     * @todo create and use RejectCaseCode table class.
     * @return mixed
     */
    public function getRejectCaseCodes($rejectReason = null)
    {
        $sql = "SELECT id, name FROM " . $this->DB->getClientDB($this->tenantID) . ".rejectCaseCode "
            . "WHERE clientID=:tenantID AND hide = 0 AND (forCaseOrig='both' OR forCaseOrig=:forCaseOrig) "
            . "ORDER BY name";
        $params = [':tenantID' => $this->tenantID, ':forCaseOrig' => (!empty($this->ddq) ? 'ddq' : 'manual')];
        $rejectCaseCodes = $this->DB->fetchObjectRows($sql, $params);
        if (count($rejectCaseCodes) == 0) {
            $params[':tenantID'] = 0;
            $rejectCaseCodes = $this->DB->fetchObjectRows($sql, $params);
        }
        foreach ($rejectCaseCodes as $i => $rejectCaseCode) {
            if (!is_null($rejectReason) && $rejectCaseCode->id == $rejectReason) { // ?
                $rejectCaseCodes[$i]->selected = true;
            } elseif ($rejectCaseCode->id == $this->case->get('rejectReason')) {
                $rejectCaseCodes[$i]->selected = true;
            }
        }
        return $rejectCaseCodes;
    }

    /**
     * Validates all the data necessary for reject/close case form submission
     *
     * @throws \Exception If missing or incorrect data.
     * @return void Throws exception if invalid data.
     */
    public function validateData()
    {
        $this->validateReturnStatus();
    }

    /**
     * Validate the rejectCaseCode.returnStatus value to determine if it's acceptable.
     *
     * @throws \Exception If no or unknown rejectCaseCode.returnStatus
     * @return void       Throws exception if unacceptable returnStatus
     */
    protected function validateReturnStatus()
    {
        $this->requireRejectCaseCode();
        $returnStatuses = ['pending', 'passed', 'internalReview', 'held', 'closed', 'deleted', 'open', 'opensync'];
        if (empty($this->rejectCaseCode) || !in_array($this->rejectCaseCode->returnStatus, $returnStatuses)) {
            throw new \Exception(
                str_replace(
                    '{returnStatus}',
                    (!empty($this->rejectCaseCode) ? $this->rejectCaseCode->returnStatus : ''),
                    (string) $this->app->trans->codeKey('unrecognized_returnStatus')
                )
            );
        }
    }

    /**
     * Compares $returnStatus string with $this->rejectCaseCode->returnStatus (i.e.
     * rejectCaseCode.returnStatus) to determine if they are equivalent.
     *
     * @param string $returnStatus rejectCaseCode.returnStatus
     *
     * @return boolean True if rejectCaseCode.returnStatus equals $returnStatus, false otherwise.
     */
    public function hasReturnStatus($returnStatus)
    {
        $this->requireRejectCaseCode();
        return ($this->rejectCaseCode->returnStatus == $returnStatus);
    }

    /**
     * Determines if another case is linked to this case. Used to prevent breaking a
     * chain of linked cases and ddq records.
     *
     * @return boolean True if another cases record linked to this case, false otherwise.
     */
    public function isLinkedTo()
    {
        $case = (new Cases($this->tenantID))->findByAttributes(
            ['clientID' => $this->tenantID, 'linkedCaseID' => $this->case->getID()]
        );
        return !empty($case);
    }

    /**
     * Determines if the case is the top link of the chain (i.e. another case isn't linked
     * to this case and this case is linked to another). Used to confirm it is save to remove
     * the case link from the chain.
     *
     * @return boolean True if another cases record linked to this case, false otherwise.
     */
    public function isTopLink()
    {
        return !$this->isLinkedTo() && ($this->case->get('linkedCaseID') > 0);
    }

    /**
     * Remove linked case. Used in conjunction with isTopLink() to check if a case
     * is the top case of the chain that are linked
     *
     * @return void Sets cases.linkedCaseID to null
     */
    public function removeLinkedRecords()
    {
        $this->case->set('linkedCaseID', null);
        return $this->case->save();
        /*if (!$this->case->set('linkedCaseID', null)) {
            throw new \Exception();
        }
        if (!$this->case->save()) {
            throw new \Exception();
        }*/
    }

    /**
     * Updates the case record based on the rejectReason and rejectDescription given.
     *
     * @param integer $rejectReason      rejectCaseCode.id/cases.rejectReason
     * @param string  $rejectDescription cases.rejectDescription
     *
     * @note might be able to use the $this->case model to update instead of MySQL query
     *
     * @throws \Exception
     * @return void Updates case associated records
     */
    public function updateCase($rejectReason = null, $rejectDescription = null)
    {
        $this->setRejectCaseCode($rejectReason);
        $this->requireRejectCaseCode();

        $caseStage = $this->getCaseStage();
        $ddq = new Ddq($this->tenantID);
        if ($ddq->isTraining($this->case->getID())) {
            $caseStage = $ddq->mapTrainingCaseStage($caseStage);
        }

        $sql = "UPDATE cases SET requestor=:requestor,  caseStage=:caseStage, rejectReason=:rejectReason, "
            . "rejectDescription=:rejectDescription WHERE id=:id";
        $params = [':requestor'         => $this->user->get('userid'), ':caseStage'         => $caseStage, ':rejectReason'      => $rejectReason, ':rejectDescription' => $rejectDescription, ':id'                 => $this->case->getID()];
        $result = $this->DB->query($sql, $params);
        if (!$result) {
            throw new \Exception($this->DB->mockFinishedSql($sql, $params));
        }

        if ($result->rowCount()) {
            (new GlobalCaseIndex($this->tenantID))->syncByCaseData($this->case->getID());
            $rejectCaseCode = $this->DB->fetchValue(
                "SELECT name FROM rejectCaseCode WHERE id=:id LIMIT 1",
                [':id' => $rejectReason]
            );
            $logMsg = "reason: `{$rejectCaseCode}`, explanation: `{$rejectDescription}`";
            $logData = new LogData($this->tenantID, $this->user->getID());
            $logData->saveLogEntry(12, $logMsg, $this->case->getID());
        }
    }

    /**
     * Gets the Case Stage given the rejectCaseCode.returnStatus.
     *
     * @return integer case stage
     */
    protected function getCaseStage()
    {
        $this->requireRejectCaseCode();
        switch ($this->rejectCaseCode->returnStatus) {
            case 'pending':
                return CaseStage::CASE_CANCELED;
            case 'passed':
            case 'internalReview':
                return CaseStage::CLOSED_INTERNAL;
            case 'held':
                if ($this->ftr->has(\Feature::CASE_ON_HOLD_STATUS)) {
                    return CaseStage::ON_HOLD;
                } else {
                    return CaseStage::CLOSED_HELD;
                }
            case 'closed':
                return CaseStage::CLOSED;
            case 'deleted':
                return CaseStage::DELETED;
            case 'open':
                if (!empty($this->ddq)) {
                    if ($this->ftr->has(\Feature::TENANT_DDQ_INVITE)) {
                        return CaseStage::DDQ_INVITE;
                    } else {
                        return CaseStage::QUALIFICATION;
                    }
                }
                return CaseStage::REQUESTED_DRAFT;
            case 'opensync':
                if (!empty($this->ddq)) {
                    if ($this->ftr->has(\Feature::TENANT_DDQ_INVITE)) {
                        $sql = "SELECT (LENGTH(subByName) OR LENGTH(subByIP)) FROM ddq WHERE id=:id LIMIT 1";
                        if ($this->DB->fetchValue($sql, [':id' => $this->ddq->getID()])) {
                            return CaseStage::QUALIFICATION;
                        } else {
                            return CaseStage::DDQ_INVITE;
                        }
                    } else {
                        return CaseStage::QUALIFICATION;
                    }
                }
                return CaseStage::REQUESTED_DRAFT;
            default: // validation prevents this situation
                throw new \Exception("Invalid Case Stage");
        } // end switch
    }

    /**
     * Updates the ddq record based on the rejectCaseCode selected.
     *
     * @return void Updates ddq associated records
     */
    public function updateDdq()
    {
        /**
         * If there is a Ddq associated with this Case, clean any previously set
         * pending returnStatus unless they are reactivating the questionnaire,
         * in which case returnStatus must be set to 'pending'
         */
        if (!empty($this->ddq)) {
            if ($this->ddqNeedsActivation()) {
                $this->ddq->setAttributes([
                    'returnStatus' => 'pending',
                    'subByDate'    => '0000-00-00 00:00:00',
                    'status'       => 'active'
                ]);
                if ($this->ftr->has(\Feature::TENANT_APPROVE_DDQ)) {
                    // Reset ddq approval to NULL if ddq approval is enabled for tenant
                    // to allow for new approval when ddq is re-submitted
                    $this->case->setAttribute('approveDDQ', null);
                    $this->case->save();
                }
            } elseif ($this->ddqNeedsRestoration()) {
                $this->ddq->setAttributes([
                    'returnStatus' => '',
                    'status'       => 'submitted'
                ]);
            } elseif ($this->ddq->get('status') == 'active' && $this->origCaseStage == CaseStage::CASE_CANCELED) {
                /* SEC-1078 (2015-05-07)
                 * A "pending" rejection followed by a "closed" rejection will no longer be permitted
                 * to change a Case's DDQ record status to "closed" and blank out its subByDate field.
                 */
                $this->ddq->setAttributes([
                    'returnStatus' => '',
                    'status' => 'submitted',
                    'subByDate' => $this->ddq->guessSubByDate() // redundant; called by CaseRecords::fixDdqStatus()
                ]);
            } elseif ($this->ddq->get('status') != 'submitted') {
                $this->ddq->setAttributes([
                    'returnStatus' => '',
                    'status' => 'closed'
                ]);
            }
            $this->ddq->save();
            CaseRecords::fixDdqStatus($this->ddq->getID(), $this->tenantID);
        }
    }

    /**
     * Determines if $this->ddq needs to be "activated" based on the new rejectCaseCode.returnStatus and feature(s).
     *
     * @return boolean True if the ddq needs to be "activated", false otherwise.
     */
    protected function ddqNeedsActivation()
    {
        $this->requireRejectCaseCode();
        if (!empty($this->ddq)) {
            if ($this->rejectCaseCode->returnStatus == 'pending') {
                return true;
            } elseif ($this->ftr->has(\Feature::TENANT_DDQ_INVITE)) {
                if ($this->rejectCaseCode->returnStatus == 'open') {
                    return true;
                } elseif ($this->rejectCaseCode->returnStatus == 'opensync') {
                    $sql = "SELECT (LENGTH(subByName) OR LENGTH(subByIP)) FROM ddq WHERE id=:id LIMIT 1";
                    if (!$this->DB->fetchValue($sql, [':id' => $this->ddq->getID()])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Determines if $this->ddq needs to be "restored" based on the new rejectCaseCode.returnStatus and feature(s).
     *
     * @return boolean True if the ddq needs to be "restored", false otherwise.
     */
    protected function ddqNeedsRestoration()
    {
        $this->requireRejectCaseCode();
        if (!empty($this->ddq)) {
            if ($this->rejectCaseCode->returnStatus == 'open') {
                if (!$this->ftr->has(\Feature::TENANT_DDQ_INVITE)) {
                    return true;
                }
            } elseif ($this->rejectCaseCode->returnStatus == 'opensync') {
                if ($this->ftr->has(\Feature::TENANT_DDQ_INVITE)) {
                    $sql = "SELECT (LENGTH(subByName) OR LENGTH(subByIP)) FROM ddq WHERE id=:id LIMIT 1";
                    if ($this->DB->fetchValue($sql, [':id' => $this->ddq->getID()])) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Send email to the partner who submitted the DDQ notifying them that
     *
     * @return void
     */
    public function sendEmail()
    {
        $this->requireRejectCaseCode();
        if ($this->rejectCaseCode->forCaseOrig == 'ddq' && $this->rejectCaseCode->returnStatus == 'pending') {
            $email = new NotifyPartnerDdqReturned($this->tenantID, $this->case->getID(), $this->user->getID());
            $email->send();
        }
    }
}
