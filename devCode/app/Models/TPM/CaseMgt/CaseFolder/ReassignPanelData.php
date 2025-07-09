<?php
/**
 * Model: Case Folder - Reassign Panel
 *
 * @keywords reassign case, reassign panel
 */
namespace Models\TPM\CaseMgt\CaseFolder;

use Models\User;
use Models\ThirdPartyManagement\Cases;
use Models\Globals\Region;
use Models\Globals\Department;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\Billing\BillingUnitPO;

/**
 * Class ReassignPanelData
 *
 * Configure reassign panel
 */
#[\AllowDynamicProperties]
class ReassignPanelData
{
    /**
     * the app instance loaded in via Xtra::app
     * @var object Skinny instance
     */
    protected $app = null;

    /**
     * Construct and manage reassign panel
     *
     * @param integer $tenantID clientProfile.id
     * @param integer $caseID   cases.id
     */
    public function __construct(protected $tenantID, protected $caseID)
    {
        $this->app = \Xtra::app();
    }

    /**
     * Get the data to display the reassign case elements popup
     *
     * @param string $regionLabel        Region custom label
     * @param string $deptLabel          Department custom label
     * @param string $billingUnitLabel   Billing unit custom label
     * @param string $purchaseOrderLabel Purchase order custom label
     *
     * @return array an associative array of data
     */
    public function getData($regionLabel, $deptLabel, $billingUnitLabel, $purchaseOrderLabel)
    {
        $txtTr = $this->app->trans->codeKeys(
            [
            'reassign_case_elements',
            'lbl_owner_requester'
            ]
        );
        $data = ['title' => $txtTr['reassign_case_elements']];
        $data['ownerTitle'] = $txtTr['lbl_owner_requester'];

        $caseRow = (new Cases($this->tenantID))->getCaseRow($this->caseID, \PDO::FETCH_ASSOC);
        if (isset($caseRow) && !empty($caseRow)) {
            $data['userCaseNum'] = $caseRow['userCaseNum'];
            $data['caseName'] = $caseRow['caseName'];
            $owner = $this->getSelectedOwner($caseRow['requestor']);
            if (!empty($owner)) {
                $data['owner'] = $owner;
            }

            $data['poLbl'] = (!empty($purchaseOrderLabel) ? $purchaseOrderLabel : '');
            $data['purchaseOrders'] = $this->getPurchaseOrders($caseRow['billingUnit'], $caseRow['billingUnitPO']);
        }

        $data['regionTitle'] = (!empty($regionLabel) ? $regionLabel : '');
        $region = (isset($caseRow) && !empty($caseRow)
            ? $caseRow['region']
            : null);
        $data['regions'] = $this->getRegions($region);

        $data['departmentTitle'] = (!empty($deptLabel) ? $deptLabel : '');
        $dept = (isset($caseRow) && !empty($caseRow)
            ? $caseRow['dept']
            : null);
        $data['departments'] = $this->getDepartments($dept);

        $data['buLbl'] = (!empty($billingUnitLabel) ? $billingUnitLabel : '');
        $billingUnit = (isset($caseRow) && !empty($caseRow)
            ? $caseRow['billingUnit']
            : null);
        $data['billingUnits'] = $this->getBillingUnits($billingUnit);

        return $data;
    }

    /**
     * Get the owner of the case
     *
     * @param string $userid users.userid
     *
     * @return array associative array for a selected dropdown option
     */
    protected function getSelectedOwner($userid)
    {
        $user = (new User())->findByAttributes(['userid' => $userid]);
        if (!empty($user)) {
            return ['value' => $user->get('id'), 'text' => $user->get('lastName') . ", " . $user->get('firstName')];
        }
        return null;
    }

    /**
     * Get tenant regions
     *
     * @param integer $caseRegion cases.region
     *
     * @return array of regions
     */
    protected function getRegions($caseRegion = null)
    {
        $regions = (new Region($this->tenantID))->getTenantRegions();
        if (!empty($caseRegion)) {
            foreach ($regions as $i => $region) {
                if ($region['id'] == $caseRegion) {
                    $regions[$i]['selected'] = true;
                }
            }
        }
        return $regions;
    }

    /**
     * Get tenant departments
     *
     * @param integer $caseDept cases.dept
     *
     * @return array of departments
     */
    protected function getDepartments($caseDept = null)
    {
        $departments = (new Department($this->tenantID))->getTenantDepartments();
        if (!empty($caseDept)) {
            foreach ($departments as $i => $department) {
                if ($department['id'] == $caseDept) {
                    $departments[$i]['selected'] = true;
                }
            }
        }
        return $departments;
    }

    /**
     * Get active billing units for tenant
     *
     * @param integer $caseBillingUnit billingUnit.id
     *
     * @return array of billing unit associative arrays
     */
    protected function getBillingUnits($caseBillingUnit = null)
    {
        $billingUnits = (new BillingUnit($this->tenantID))->getActiveBillingUnits();
        if (!empty($caseBillingUnit)) {
            foreach ($billingUnits as $i => $billingUnit) {
                if ($billingUnit['id'] == $caseBillingUnit) {
                    $billingUnits[$i]['selected'] = true;
                }
            }
        }
        return $billingUnits;
    }

    /**
     * Get purchase orders depending on billing unit
     *
     * @param integer $caseBillingUnit   billingUnit.id
     * @param integer $casePurchaseOrder billingUnitPO.id
     *
     * @return array of purchase order associative arrays
     */
    protected function getPurchaseOrders($caseBillingUnit, $casePurchaseOrder = null)
    {
        $purchaseOrders = (new BillingUnitPO($this->tenantID))->getPOsByBillingUnit($caseBillingUnit);
        if (!empty($casePurchaseOrder)) {
            foreach ($purchaseOrders as $i => $purchaseOrder) {
                if ($purchaseOrder['id'] == $casePurchaseOrder) {
                    $purchaseOrders[$i]['selected'] = true;
                }
            }
        }
        return $purchaseOrders;
    }
}
