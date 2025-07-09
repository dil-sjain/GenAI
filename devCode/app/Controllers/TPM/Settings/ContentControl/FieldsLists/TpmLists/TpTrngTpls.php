<?php
/**
 * Control for TPM Fields/Lists - 3P Training Templates
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpTrngTplsData;

/**
 * Class TpTrngTpls controls the Training Template requirements in Fields/Lists.
 * This class depends on being called via the FieldsLists controller.
 *
 * @keywords third party training, training templates, tpm, fields lists, content control, settings
 */
#[\AllowDynamicProperties]
class TpTrngTpls extends TpmLists
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object model instance.
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
        $this->data = new TpTrngTplsData($this->tenantID, $this->userID, $this->listTypeID);
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
        $this->jsObj->Items  = $this->data->getTypes();
    }

    /**
     * Get training by type as dictated by the select list
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function changeList($post)
    {
        $this->jsObj->Result  = 1;
        $this->jsObj->Records = $this->data->getTrainingByType($post['trnType']);
    }

    /**
     * Check for valid User Training Attachment number
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function checkValidTA($post)
    {
        $this->jsObj->Result = 1;
        $this->jsObj->ValidTA = $this->data->checkUserTaNum($post['taNum']);
    }

    /**
     * Public wrapper to add new item
     * See storeData method for jsObj values set
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function add($post)
    {
        $this->storeData($post['vals']);
    }

    /**
     * Public wrapper to update an item
     * See storeData method for jsObj values set
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function update($post)
    {
        $this->storeData($post['vals']);
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
        $this->jsObj->Result = 1;
        $this->jsObj->Records = $r['Records'];
    }

    /**
     * Call add or update data method and setup jsObj vals
     * Adds SubResult for actual success/fail as it uses text already on front end if error.
     * If error, it sets TrnErr which is an array of [ErrTitleKey, ErrMsgKey] pairs, which will
     * be used to pull text already present in js for display of single or multiple errors.
     *
     * @param array $data Input values from vals key of app()->clean_POST
     *
     * @return void
     */
    private function storeData($data)
    {
        $this->jsObj->Result = 1; // always 1 since we're going to handle our own errors for this.
        $this->jsObj->SubResult = 1;
        $a = $this->data->saveData($data);
        if (!$a['subResult']) {
            $this->jsObj->SubResult = 0;
            $this->jsObj->TrnErr = $a['errors'];
            return;
        }
        $this->jsObj->CurRecID = (int)$a['curRecID'];
        $this->jsObj->Records = $this->data->getTrainingByType($data['trainingType']);
    }
}
