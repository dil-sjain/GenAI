<?php
/**
 * Reassign Panel controller
 */
namespace Controllers\TPM\CaseMgt\CaseFolder\ReassignPanel;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\CaseMgt\CaseFolder\ReassignPanelData;
use Models\ThirdPartyManagement\Cases;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;
use Models\User;
use Lib\GlobalCaseIndex;
use Models\Globals\Region;
use Models\Globals\Department;
use Models\LogData;
use Controllers\TPM\Email\Cases\ReassignCaseOwner;
use Lib\FeatureACL;
use Lib\Legacy\ClientIds;

/**
 * ReassignPanel controller
 *
 * @keywords reassign, reassign case, reassign case elements
 */
#[\AllowDynamicProperties]
class ReassignPanel extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/CaseMgt/CaseFolder/ReassignPanel/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'ReassignPanel.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var integer User ID
     */
    protected $userID;


    /**
     * @var int
     */
    protected $caseID = 0;

    /**
     * Constructor gets model instance to interact with JIRA API
     *
     * @param integer $tenantID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        \Xtra::requireInt($tenantID);
        parent::__construct($tenantID, $initValues);
        $this->app = \Xtra::app();
        $this->userID = $this->session->get('authUserID');
        if ($this->session->has('currentID.case')) {
            $this->caseID = $this->session->get('currentID.case');
        }
    }

    /**
     * Sets required properties to display the Reassign Panel.
     *
     * @return void Sets jsObj
     */
    private function ajaxReassignPanel()
    {
        $this->jsObj->Result = 0;
        if ($this->app->ftr->hasALLOf([\Feature::TENANT_REASIGN_CASE, \Feature::REASSIGN_CASE])) {
            $reassignPanelData = new ReassignPanelData($this->tenantID, $this->caseID);
            $this->setViewValue(
                'data',
                $reassignPanelData->getData(
                    $this->session->get('customLabels.region'),
                    $this->session->get('customLabels.department'),
                    $this->session->get('customLabels.billingUnit'),
                    $this->session->get('customLabels.billingPO')
                )
            );

            $txtTr = $this->app->trans->codeKeys(
                [
                'select_default',
                'reassign_case_elements'
                ]
            );
            $this->setViewValue('defaultSelectValue', $txtTr['select_default']);

            $html = $this->app->view->fetch(
                $this->getTemplate(),
                $this->getViewValues()
            );

            $this->jsObj->Args = [
                'title' => $txtTr['reassign_case_elements'],
                'html'  => $html
            ];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Get the list of potential case owners given a region and department.
     *
     * @return void
     */
    private function ajaxGetCaseOwnerList()
    {
        $txtTr = $this->app->trans->codeKeys(
            [
            'select_default',
            'owner_requester_list',
            'no_qual_owners_fnd'
            ]
        );
        $this->jsObj->Result = 0;
        $this->jsObj->DoNewCSRF = 1;
        if ($this->app->ftr->hasAllOf([\Feature::TENANT_REASIGN_CASE, \Feature::REASSIGN_CASE])) {
            $owners = [];
            $region = intval(\Xtra::arrayGet($this->app->clean_POST, 'reg', 0));
            $department = intval(\Xtra::arrayGet($this->app->clean_POST, 'dpt', 0));
            $rows = (new Cases($this->tenantID))->getProspectiveOwners($region, $department);
            if (!empty($rows)) {
                // Default option
                $obj = new \stdClass();
                $obj->v = 0;
                $obj->t = $txtTr['select_default'];
                $owners[] = $obj;

                // Other options
                foreach ($rows as $row) {
                    $obj = new \stdClass();
                    $obj->v = $row->id;
                    $last = html_entity_decode((string) $row->lastName, ENT_QUOTES, 'UTF-8');
                    $first = html_entity_decode((string) $row->firstName, ENT_QUOTES, 'UTF-8');
                    $obj->t = $last . ", " . $first;
                    $owners[] = $obj;
                }
            } else {
                $this->jsObj->ErrTitle = $txtTr['owner_requester_list'];
                $this->jsObj->ErrMsg = $txtTr['no_qual_owners_fnd'];
            }
            $this->jsObj->Args = [
                'Owners' => $owners
            ];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Get the purchase orders for a given billing unit.
     *
     * @return void
     */
    private function ajaxGetPurchaseOrders()
    {
        $this->jsObj->Result = 0;
        if ($this->app->ftr->hasALLOf([\Feature::TENANT_REASIGN_CASE, \Feature::REASSIGN_CASE])) {
            $txtTr = $this->app->trans->codeKeys(['select_default']);
            $purchaseOrders = [];
            $selected = null;
            $bu = intval(\Xtra::arrayGet($this->app->clean_POST, 'bu', 0));
            if ($rows = (new BillingUnitPO($this->tenantID))->getPOsByBillingUnit($bu)) {
                $obj = new \stdClass();
                $obj->v = 0;
                $obj->t = $txtTr['select_default'];
                $purchaseOrders[] = $obj;

                $case = (new Cases($this->tenantID))->findById($this->caseID);
                $selected = $case->get('billingUnitPO');
                foreach ($rows as $row) {
                    $obj = new \stdClass();
                    $obj->v = $row['id'];
                    $obj->t = $row['name'];
                    $purchaseOrders[] = $obj;
                }
            }
            $this->jsObj->Args = [
                'PurchaseOrders' => $purchaseOrders,
                'Selected' => $selected
            ];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Submit the reassign case elements popup.
     *
     * @return void
     */
    private function ajaxSubmitReassignCase()
    {
        $this->jsObj->Result = 0;
        $this->jsObj->DoNewCSRF = 1;
        if ($this->app->ftr->hasALLOf([\Feature::TENANT_REASIGN_CASE, \Feature::REASSIGN_CASE])) {
            $txtTr = $this->app->trans->codeKeys(
                [
                'bu_required_error',
                'case_owner_changed_msg',
                'case_requester_changed_msg',
                'case_element_changed_msg',
                'error_input_dialogTtl',
                'database_error',
                'fail_updating_case',
                'error_invalid_case_owner'
                ]
            );
            $owner = intval(\Xtra::arrayGet($this->app->clean_POST, 'owner', 0));
            $reg = intval(\Xtra::arrayGet($this->app->clean_POST, 'reg', 0));
            $dept = intval(\Xtra::arrayGet($this->app->clean_POST, 'dpt', 0));
            $buID = intval(\Xtra::arrayGet($this->app->clean_POST, 'bu', 0));
            $poID = intval(\Xtra::arrayGet($this->app->clean_POST, 'po', 0));
            $validCaseOwner = (new Cases($this->tenantID))->validCaseOwner($owner, $reg, $dept);
            if ($validCaseOwner) {
                $billingUnit = new BillingUnit($this->tenantID);
                $billingUnits = $billingUnit->getActiveBillingUnits();
                $buLabel = $this->session->get('customLabels.billingUnit');
                if (count($billingUnits) == 0) {
                    $buID = 0;
                    $poID = 0;
                } elseif ($buID == 0) {
                    $this->jsObj->ErrTitle = $txtTr['error_input_dialogTtl'];
                    $this->jsObj->ErrMsg = str_replace('{billing_unit}', $buLabel, (string) $txtTr['bu_required_error']);
                } else { // count($billingUnits) > 0
                    $purchaseOrders = (new BillingUnitPO($this->tenantID))->getPOsByBillingUnit($buID);
                    if (count($purchaseOrders) == 0 || $buID == 0) {
                        $poID = 0;
                    } elseif ($poID == 0) {
                        $this->jsObj->ErrTitle = $txtTr['error_input_dialogTtl'];
                        $this->jsObj->ErrMsg = str_replace('{billing_unit}', $buLabel, (string) $txtTr['bu_required_error']);
                    }
                }
                $case = (new Cases($this->tenantID))->findById($this->caseID);
                $keys = ['userCaseNum', 'caseName', 'region', 'dept', 'requestor', 'billingUnit', 'billingUnitPO'];
                $oldValues = new \stdClass();
                foreach ($keys as $key) {
                    $oldValues->$key = $case->get($key);
                }
                $newOwner = (new User())->findById($owner);
                $case->setAttributes(
                    [
                    'requestor' => $newOwner->get('userid'),
                    'region' => $reg,
                    'dept' => $dept,
                    'billingUnit' => $buID,
                    'billingUnitPO' => $poID
                    ]
                );
                if ($case->save()) {
                    $this->jsObj->Result = 1;

                    $globalInx = new GlobalCaseIndex($this->tenantID);
                    $globalInx->syncByCaseData($this->caseID);

                    $changeMsg = "\n";
                    $oldOwner = false;
                    if (isset($oldValues->requestor) && !empty($oldValues->requestor)) {
                        $oldOwner = (new User())->findByAttributes(['userid' => $oldValues->requestor]);
                    }
                    if (!$oldOwner || $oldOwner->get('id') != $newOwner->get('id')) {
                        if (is_object($oldOwner)) { // if set, always true?
                            $changeMsg .= "    "
                                . str_replace(
                                    "{new}",
                                    $newOwner->get('userName'),
                                    str_replace("{old}", $oldOwner->get('userName'), (string) $txtTr['case_owner_changed_msg'])
                                )
                                ."\n";
                        } else {
                            $changeMsg .= "    "
                                . str_replace(
                                    "{new}",
                                    $newOwner->get('userName'),
                                    str_replace(
                                        "{old}",
                                        "'".$oldValues->requestor."'",
                                        (string) $txtTr['case_owner_changed_msg']
                                    )
                                )
                                ."\n";
                        }
                    }
                    $diffs = [];
                    if ($oldValues->requestor != $newOwner->get('userid')) {
                        $diffs[] = str_replace(
                            "{new}",
                            $newOwner->get('userid'),
                            str_replace("{old}", $oldValues->requestor, (string) $txtTr['case_requester_changed_msg'])
                        );
                    }
                    if ($oldValues->region != $reg) {
                        $oldRegion = (new Region($this->tenantID))->selectByID($oldValues->region, ['name']);
                        $newRegion = (new Region($this->tenantID))->selectByID($reg, ['name']);
                        $changeMsg .= '    ' . str_replace(
                            "{element}",
                            $this->app->session->get('customLabels.region'),
                            str_replace(
                                "{old}",
                                $oldRegion['name'],
                                str_replace(
                                    "{new}",
                                    ($newRegion['name'] ?? 'unknown'),
                                    (string) $txtTr['case_element_changed_msg']
                                )
                            )
                        ) . "\n";
                        $diffs[] = "{$this->app->session->get('customLabels.region')}: `(".$oldValues->region.") "
                            . $oldRegion['name'] . "` => `($reg) " . ($newRegion['name'] ?? '(unknown)') . "`";
                    }
                    if ($oldValues->dept != $dept) {
                        $oldDept = (new Department($this->tenantID))->selectByID($oldValues->dept, ['name']);
                        $newDept = (new Department($this->tenantID))->selectByID($dept, ['name']);
                        $changeMsg .= '    ' . str_replace(
                            "{element}",
                            $this->app->session->get('customLabels.department'),
                            str_replace(
                                "{old}",
                                $oldDept['name'],
                                str_replace(
                                    "{new}",
                                    $newDept['name'],
                                    (string) $txtTr['case_element_changed_msg']
                                )
                            )
                        ) . "\n";
                        $diffs[] = $this->app->session->get('customLabels.department').": `(".$oldValues->dept.") "
                            . $oldDept['name']."` => `($dept) ".$newDept['name']."`";
                    }
                    if ($oldValues->billingUnit != $buID) {
                        $oldBU = $billingUnit->findById($oldValues->billingUnit);
                        $newBU = $billingUnit->findById($buID);
                        $buTitle = $this->app->session->get('customLabels.billingUnit');
                        $changeMsg .= '    ' . str_replace(
                            "{element}",
                            $buTitle,
                            str_replace(
                                "{old}",
                                (isset($oldBU) ? $oldBU->get('name') : ''),
                                str_replace(
                                    "{new}",
                                    (isset($newBU) ? $newBU->get('name') : ''),
                                    (string) $txtTr['case_element_changed_msg']
                                )
                            )
                        ) . "\n";
                        $diffs[] = "$buTitle: `".(isset($oldBU) ? $oldBU->get('name') : '')."` "
                            . "=> `".(isset($newBU) ? $newBU->get('name') : '')."`";
                    }
                    if ($oldValues->billingUnitPO != $poID) {
                        $purchaseOrder = new BillingUnitPO($this->tenantID);
                        $oldPO = $purchaseOrder->findById($oldValues->billingUnitPO);
                        $newPO = $purchaseOrder->findById($poID);
                        $poTitle = $this->app->session->get('customLabels.billingPO');
                        $changeMsg .= '    ' . str_replace(
                            "{element}",
                            $poTitle,
                            str_replace(
                                "{old}",
                                (isset($oldPO) ? $oldPO->get('name') : ''),
                                str_replace(
                                    "{new}",
                                    (isset($newPO) ? $newPO->get('name') : ''),
                                    (string) $txtTr['case_element_changed_msg']
                                )
                            )
                        ) . "\n";
                        $diffs[] = "$poTitle: `".(isset($oldPO) ? $oldPO->get('name') : '')."` "
                            . "=> `".(isset($newPO) ? $newPO->get('name') : '')."`";
                    }
                    $msg = join(', ', $diffs);
                    $userID = $this->app->session->get('authUserID', 0);
                    $logData = new LogData($this->tenantID, $userID);
                    $logData->saveLogEntry(89, $msg, $this->caseID);

                    $recipient = '';
                    if ($newOwner->get('id') != $this->app->session->get('authUserID')) {
                        $recipient = $newOwner->get('id');
                    }
                    /**
                     * @see SEC-2580
                     */
                    $recipient2 = '';
                    if ($newOwner->get('id') != $oldOwner->get('id')
                        && $oldOwner->get('id') != $this->app->session->get('authUserID')
                    ) {
                        if ($this->tenantID != ClientIds::COKE_CLIENTID) {
                            $recipient2 = $oldOwner->get('id');
                        }
                    }

                    if (!empty($recipient) || !empty($recipient2)) {
                        // Generate an email to notify the old and new owners
                        $email = new ReassignCaseOwner(
                            $this->tenantID,
                            $this->caseID,
                            $recipient,
                            $recipient2,
                            $changeMsg
                        );
                        $email->send();
                    }
                } else {
                    $this->jsObj->ErrTitle = $txtTr['database_error'];
                    $this->jsObj->ErrMsg = $txtTr['fail_updating_case'];
                }
            } else {
                $this->jsObj->ErrTitle = $txtTr['error_input_dialogTtl'];
                $this->jsObj->ErrMsg = $txtTr['error_invalid_case_owner'];
            }
        }
    }
}
