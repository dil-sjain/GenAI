<?php
/**
 * Control for TPM Fields/Lists - 3P Approval Reasons
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpApprvRsnsData;

/**
 * Class TpApprvRsns controls the 3P Approval Reasons requirements. This class depends on being
 * called via the FieldsLists controller.
 *
 * @keywords tpm, fields lists, content control, settings, approval reasons
 */
#[\AllowDynamicProperties]
class TpApprvRsns extends TpmLists
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
        $this->data = new TpApprvRsnsData($this->tenantID, $this->userID, $this->listTypeID);
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
        $this->jsObj->Lists = [
            ['value' => 'approved', 'name' => $this->txt['status_Approved']],
            ['value' => 'pending',  'name' => $this->txt['status_Pending']],
            ['value' => 'denied',   'name' => $this->txt['status_Denied']],
        ];
    }

    /**
     * Fetch and setup specific list items for return to view
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function changeList($post)
    {
        $this->jsObj->Result = 1;
        $recs = $this->data->getReasons($post['type']);
        if ($recs['Result'] != 1) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $recs['ErrTitle'];
            $this->jsObj->ErrMsg = $recs['ErrMsg'];
        } else {
            $this->jsObj->Records = $this->mapRecordsForUI($recs['Records']);
        }
    }

    /**
     * General Use passes the op as add, so we'll pass along to save.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    #[\Override]
    protected function add($post)
    {
        $this->saveRecord($post);
    }

    /**
     * General Use passes the op as update, so we'll pass along to save.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    #[\Override]
    protected function update($post)
    {
        $this->saveRecord($post);
    }

    /**
     * Save a record
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    private function saveRecord($post)
    {
        $this->jsObj->Result = 1;
        $recs = $this->data->saveReason($this->mapPostValsToFields($post));
        if ($recs['Result'] != 1) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $recs['ErrTitle'];
            $this->jsObj->ErrMsg = $recs['ErrMsg'];
        } else {
            if (empty($post['vals']['id'])) {
                $this->jsObj->ItemID = $recs['ItemID'];
            }
            $this->jsObj->Records = $this->mapRecordsForUI($recs['Records']);
        }
    }

    /**
     * Takes posted "general" values from UI and maps them to the correct/expected keys
     * Model will validate, we only want to map here.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return array Array of properly mapped values, ready for save.
     */
    private function mapPostValsToFields($post)
    {
        return [
            'id' => (!empty($post['vals']['id']) ? intval($post['vals']['id']) : 0),
            'appType' => (!empty($post['vals']['subList']['val']) ? $post['vals']['subList']['val'] : ''),
            'reason' => (!empty($post['vals']['name']) ? $post['vals']['name'] : ''),
            'active' => (!empty($post['vals']['ckBox']) ? 1 : 0),
        ];
    }

    /**
     * General Use js UI expects certain values. We need to map our values to those values
     * before sending data to the display.
     * Specifically we map name -> reason, ckBox -> active
     *
     * @param mixed $data DB result data (could be single object record, or array of object records)
     *
     * @return mixed Return values as they were passed (single object record, or array of object records)
     */
    private function mapRecordsForUI(mixed $data)
    {
        if (is_array($data)) {
            $rtn = [];
            foreach ($data as $k => $v) {
                $rtn[$k] = (object)null;
                $rtn[$k]->id     = $v->id;
                $rtn[$k]->name   = $v->reason;
                $rtn[$k]->ckBox  = $v->active;
                $rtn[$k]->canDel = $v->canDel;
                $rtn[$k]->numFld = ($v->active == 1 ? $this->txt['yes']:$this->txt['no']);
            }
            return $rtn;
        }
        $rtn = (object)null;
        $rtn->id     = $data->id;
        $rtn->name   = $data->reason;
        $rtn->ckBox  = $data->active;
        $rtn->canDel = $data->canDel;
        $rtn->numFld = ($data->active == 1 ? $this->txt['yes']:$this->txt['no']);
        return $rtn;
    }

    /**
     * The below method is added to prevent issues if called blindly,
     * as they overwrite the parent methods to prevent failures. These
     * methods should never be called
     */
    /**
     * Neuter parent method
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    #[\Override]
    protected function remove($post)
    {
        $this->jsObj->Result   = 0;
        $this->jsObj->ErrTitle = $this->txt['one_error'];
        $this->jsObj->ErrMsg   = $this->txt['del_failed_has_data_no_perm'];
    }
}
