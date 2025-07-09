<?php
/**
 * Control for TPM Fields/Lists - Cases
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\RejectCaseData;

/**
 * Class RejectCaseForm controls the case requirements.
 * This class depends on being called via the FieldsLists controller.
 *
 * @keywords tpm, fields lists, content control, settings, case reject codes
 */
#[\AllowDynamicProperties]
class RejectCaseForm extends TpmLists
{
    /**
     * Application instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * TpmList model instance.
     *
     * @var object
     */
    protected $data = null;

    /**
     * tenantID
     *
     * @var integer
     */
    protected $tenantID = 0;

    /**
     * jsObj style object for return to caller.
     *
     * @var object
     */
    protected $jsObj = null;

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $listTypeID ID of current list type (becomes class property `ctrlID`)
     * @param integer $userID     ID of current user.
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $listTypeID, $userID, $initValues = [])
    {
        // parent handles all the "usual" var initialization.
        parent::__construct($tenantID, $listTypeID, $userID, $initValues);
        // we just need to load the right model, and kick off text translations.
        $this->data = new RejectCaseData($this->tenantID, $this->userID, $this->ctrlID, $initValues);
        $this->data->setTrText($this->txt);
    }

    /**
     * Get initial display data to pass back to the screen
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function initDisplay($post)
    {
        $this->jsObj->Result     = 1;
        $this->jsObj->Fields     = $this->data->getFields();
    }

    /**
     * Overwrite parent class add
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function add($post)
    {
        $this->save($post);
    }

    /**
     * Overwrite parent class update
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function update($post)
    {
        $this->save($post);
    }

    /**
     * Method to add/update a single record data.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    private function save($post)
    {
        $this->jsObj->Result = 0;
        $a = $this->data->save($post['vals']);
        if (!$a['Result']) {
            $this->jsObj->ErrTitle = $a['ErrTitle'];
            $this->jsObj->ErrMsg = $a['ErrMsg'];
            return;
        } elseif (isset($a['id'])) {
            $this->jsObj->ItemID = $a['id'];
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Fields     = $this->data->getFields();
    }

    /**
     * Remove the existing case reject codes from list
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function remove($post)
    {
        $this->jsObj->Result = 0;
        $rec = $this->data->remove($post['vals']);
        if (!$rec['Result']) {
            $this->jsObj->ErrTitle = $rec['ErrTitle'];
            $this->jsObj->ErrMsg = $rec['ErrMsg'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Fields = $this->data->getFields();
    }
}
