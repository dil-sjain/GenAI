<?php
/**
 * Control for TPM Fields/Lists - Skeleton Base Class
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

/**
 * Class controls the basic Fields/Lists requirements. This class is the base from which
 * all other classes extend. If using the general use js controller/namespace, the basic
 * add/update/remove functions in this class should suffice, and the model can handle the
 * details with the extending controller setting up vars and anything in addition to the
 * basic methods.
 *
 * @keywords tpm, fields lists, content control, settings
 */
#[\AllowDynamicProperties]
class TpmLists
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
     * @var integer ctrlID of the list being worked with (hard coded in FieldsLists.php)
     */
    protected $ctrlID = 0;

    /**
     * @var array Translation text array
     */
    protected $txt = [];

    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $ctrlID     ID of current list type
     * @param integer $userID     ID of current user.
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $ctrlID, $userID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;
        $this->ctrlID = (int)$ctrlID;
        $this->userID = (int)$userID;
        $this->app     = \Xtra::app();
        $this->jsObj = (object)null;
        $this->txt = (!empty($initValues['txt'])) ? $initValues['txt'] : [];
    }

    /**
     * Affirm that the class has loaded and is ready to proceed.
     *
     * @return boolean True if loaded, else false.
     */
    public function isLoaded()
    {
        if ($this->data->isLoaded() && $this->tenantID > 0 && $this->ctrlID > 0
            && $this->userID > 0 && !empty($this->txt)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Control data flow into the specified sub-op and return its output.
     * Set up this way so the method can be public, while subOp is protected/private.
     *
     * @param array  $post  Sanitized post array (app()->clean_POST)
     * @param object $jsObj The ajax jsObj object at the time this method was called.
     *                      Methods will add onto this as it would in a normal controller.
     *
     * @return object Returns jsObj object
     */
    public function manageData($post, $jsObj)
    {
        $this->jsObj = $jsObj;
        if (!$post['subOp'] || !method_exists($this, $post['subOp'])) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->txt['title_operation_failed'];
            $this->jsObj->ErrMsg = $this->txt['message_operation_not_recognized'];
            return $this->jsObj;
        }
        $method = $post['subOp'];
        $this->$method($post);
        if ($this->jsObj->Result == 0) {
            $this->jsObj->ErrTitle = !empty($this->jsObj->ErrTitle) ? $this->jsObj->ErrTitle : $this->txt['one_error'];
            $this->jsObj->ErrMsg = !empty($this->jsObj->ErrMsg) ? $this->jsObj->ErrMsg : $this->txt['status_Tryagain'];
        }
        return $this->jsObj;
    }

    /**
     * Add a new item
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function add($post)
    {
        $this->jsObj->Result = 0;
        $a = $this->data->add($post['vals']);
        if (!$a['Result']) {
            $this->jsObj->ErrTitle = $a['ErrTitle'];
            $this->jsObj->ErrMsg = $a['ErrMsg'];
            return;
        } elseif (isset($a['id'])) {
            $this->jsObj->ItemID = $a['id'];
            if (isset($a['customMessage'])) {
                $this->jsObj->customMessage = $a['customMessage'];
            }
        }
        $this->jsObj->Result = 1;
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
        $u = $this->data->update($post['vals'], $post['oldVals']);
        if (!$u['Result']) {
            $this->jsObj->ErrTitle = $u['ErrTitle'];
            $this->jsObj->ErrMsg = $u['ErrMsg'];
            return;
        }
        if (isset($u['customMessage'])) {
            $this->jsObj->customMessage = $u['customMessage'];
        }
        $this->jsObj->Result = 1;
    }

    /**
     * Remove item
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function remove($post)
    {
        $this->jsObj->Result = 0;
        $r = $this->data->remove($post['vals']);
        if (!$r['Result']) {
            $this->jsObj->ErrTitle = $r['ErrTitle'];
            $this->jsObj->ErrMsg = $r['ErrMsg'];
            return;
        }
        if (isset($r['customMessage'])) {
            $this->jsObj->customMessage = $r['customMessage'];
        }
        $this->jsObj->Result = 1;
    }
}
