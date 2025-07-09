<?php
/**
 * Control for TPM Fields/Lists - Custom Labels
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\CustLabelsData;

/**
 * Class CustLabels controls the Custom Label requirements. This class depends on being
 * called via the FieldsLists controller.
 *
 * @keywords custom labels, tpm, fields lists, content control, settings, custom lists
 */
class CustLabels extends TpmLists
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object TpmList model instance.
     */
    protected $data = null;

    /**
     * @var integer tenantID
     */
    protected $tenantID = 0;

    /**
     * @var object jsObj style object for return to caller.
     */
    protected $jsObj = null;

    /**
     * @var integer ListTypeID of the list being worked with (hard coded in FieldsLists.php)
     */
    protected $listTypeID = 0;

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $listTypeID ID of current list type
     * @param integer $userID     ID of current user.
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $listTypeID, $userID, $initValues = [])
    {
        // parent handles all the "usual" var initialization.
        parent::__construct($tenantID, $listTypeID, $userID, $initValues);
        // we just need to load the right model, and kick off text translations.
        $this->data = new CustLabelsData($this->tenantID, $this->userID);
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
        $this->jsObj->Result = 1;
        $this->jsObj->Recs  = $this->data->getAll();
    }

    /**
     * Overwrite parent method as there is no add functionality.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function add($post)
    {
        $this->jsObj->Result = 0;
    }

    /**
     * Update item
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function update($post)
    {
        $this->jsObj->Result = 0;
        $u = $this->data->update($post['vals']);
        $this->jsObj->Recs = $u['Recs'];
        if (!$u['Result']) {
            $this->jsObj->ErrTitle = $u['ErrTitle'];
            $this->jsObj->ErrMsg = $u['ErrMsg'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->OpType = 'save';
    }

    /**
     * Remove all overrides and reset to default values
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function remove($post)
    {
        $this->jsObj->Result = 0;
        $r = $this->data->resetAllToDefault();
        $this->jsObj->Recs = $r['Recs'];
        if (!$r['Result']) {
            $this->jsObj->ErrTitle = $r['ErrTitle'];
            $this->jsObj->ErrMsg = $r['ErrMsg'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->OpType = 'restore';
    }
}
