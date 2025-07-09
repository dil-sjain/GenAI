<?php
/**
 * Control for TPM Fields/Lists - Risk Area
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\RiskModelRolesData;

/**
 * Class RiskModelRoles controls the risk-model area requirements. This class depends on being
 * called via the FieldsLists controller.
 *
 * @keywords Risk Area, tpm, fields lists, content control, settings, custom lists
 */
#[\AllowDynamicProperties]
class RiskModelRoles extends TpmLists
{
    /**
     * @var integer ListTypeID of the list being worked with (hard coded in FieldsLists.php)
     */
    protected int $listTypeID = 0;

    /**
     * @var integer The next order difference number
     */
    protected int $orderGap = 10;

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $listTypeID ID of current list type
     * @param integer $userID     ID of current user.
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $listTypeID, $userID, $initValues = array())
    {
        // parent handles all the "usual" var initialization.
        parent::__construct($tenantID, $listTypeID, $userID, $initValues);
        // we just need to load the right model, and kick off text translations.
        $this->data = new RiskModelRolesData($this->tenantID, $this->userID, $this->listTypeID);
        $this->data->setTrText($this->txt);
    }

    /**
     * Get initial display data to pass back to the screen
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function initDisplay($post): void
    {
        try {
            $this->jsObj->Result = 1;
            $rolesList = $this->data->getRiskModelRoles();
            if (count($rolesList) === 0) {
                $result = $this->data->add(['name' => 'Risk Rating',
                                            'numFld' => $this->orderGap,
                                            'ckBox' => 1]);
                if ($result['Result'] == 1) {
                    $rolesList = $this->data->getRiskModelRoles();
                }
            }
            $this->jsObj->Items  = $rolesList;
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

    /**
     * Get next order's value to auto-populate in role's order field
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    public function getNextOrder($post): void
    {
        try {
            $this->jsObj->Result = 1;
            // Get next order to populate suggestion on view
            $nextOrder = $this->data->getMaxUsedOrderNumber();
            $autoFillFieldsOnAdd = (isset($post['autoFillFieldsOnAdd']) && !empty($post['autoFillFieldsOnAdd']))
                                    ? explode(',', $post['autoFillFieldsOnAdd']) : ['Result'];
            foreach ($autoFillFieldsOnAdd as $fields) {
                $this->jsObj->$fields = intval($nextOrder);
            }
        } catch (\Exception $e) {
            $this->jsObj->Result = 0;
            echo "Error: " . $e->getMessage();
        }
    }
}
