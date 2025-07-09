<?php
/**
 * Control for TPM Fields/Lists - Gift Tracking
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\GiftTrackData;

/**
 * Class GiftTracking controls the Custom Fields requirements for gift tracking.
 * This class depends on being called via the FieldsLists controller.
 *
 * @keywords tpm, fields lists, content control, settings, gift tracking, gifts
 */
class GiftTrack extends TpmLists
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
     * @var string Current scope (`case` or `thirdparty`)
     */
    protected $scope = '';

    /**
     * @var object jsObj style object for return to caller.
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
        $this->data = new GiftTrackData($this->tenantID, $this->userID, $initValues);
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
        $data = $this->data->getInitData();
        $this->jsObj->Tiers = $data['Tiers'];
        $this->jsObj->Types = $data['Types'];
        $this->jsObj->Cats  = $data['Cats'];
        $this->jsObj->Rules = $data['Rules'];
        $this->jsObj->Tpm   = $data['Tpm'];
        $this->jsObj->Risk  = $data['Risk'];
    }

    /**
     * Save custom rule (save deactivates old rule, if applicable, and adds as a new active rule.)
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function save($post)
    {
        $this->jsObj->Result = 0;
        $rec = $this->data->save($post['vals']);
        if (!$rec['Result']) {
            $this->jsObj->ErrTitle = $rec['ErrTitle'];
            $this->jsObj->ErrMsg = $rec['ErrMsg'];
            return;
        }
        $this->jsObj->Result = 1;

        // if completely new rule, then we must send back the entire rule list
        // in order for them to be ordered correctly.
        // if it's just an "update", we send back only the updated rule to replace
        // what was already there.
        if ($rec['isNew'] == 1) {
            $this->jsObj->Rules = $rec['Rules'];
        } else {
            $this->jsObj->Rule = $rec['Rule'];
        }
    }

    /**
     * Remove rule
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function remove($post)
    {
        $this->jsObj->Result = 0;
        $res = $this->data->remove($post['vals']['rid'], $post['vals']['name']);
        if (!$res['Result']) {
            $this->jsObj->ErrTitle = $res['ErrTitle'];
            $this->jsObj->ErrMsg = $res['ErrMsg'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->RuleName = $post['vals']['name'];
    }
}
