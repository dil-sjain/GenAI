<?php
/**
 * Control for TPM Fields/Lists - Custom Fields
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\CustFieldsData;

/**
 * Class CustFields controls the Custom Fields requirements for BOTH case and 3P.
 * This class depends on being called via the FieldsLists controller.
 *
 * @keywords tpm, fields lists, content control, settings, custom fields
 */
class CustFields extends TpmLists
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
     * Current scope (`case` or `thirdparty`)
     *
     * @var string
     */
    protected $scope = '';

    /**
     * Enable reference fields?
     *
     * @var boolean
     */
    protected $hasRef = false;

    /**
     * Enable flagged questions?
     *
     * @var boolean
     */
    protected $hasFlagged = false;

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
        $this->data = new CustFieldsData($this->tenantID, $this->userID, $this->ctrlID, $initValues);
        $this->data->setTrText($this->txt);
        $this->scope = $this->data->getScope();
        if ($this->scope == 'case') {
            $this->hasRef     = $this->data->hasRefFields();
            $this->hasFlagged = $this->data->hasFlaggedQuestions();
        }
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
        $this->jsObj->TypeCats   = ($this->scope == 'thirdparty') ? $this->data->getTypeCats() : '';
        $this->jsObj->Lists      = $this->data->getLists();
        $this->jsObj->Chains     = $this->data->makeFieldChains($this->jsObj->Fields);
        $this->jsObj->HasRef     = $this->hasRef;
        $this->jsObj->HasFlagged = $this->hasFlagged;
        $this->jsObj->RefList    = [];
        $this->jsObj->FlagData   = [];
        if ($this->hasRef) {
            $this->jsObj->RefList = $this->data->getRefListValues();
        }
        if ($this->hasFlagged) {
            $this->jsObj->FlagData = $this->data->getFlaggedData();
        }
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
     * Combined add/update method to save a single field definition.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    private function save($post)
    {
        $this->jsObj->Result = 0;
        if ($this->hasFlagged) {
            if (!empty(intval($post['saveField']))) {
                $rec = $this->data->save($post['vals']);
                if (!$rec['Result']) {
                    $this->jsObj->ErrTitle = $rec['ErrTitle'];
                    $this->jsObj->ErrMsg = $rec['ErrMsg'];
                    return;
                }
                $fieldID = $rec['FieldID']; // covers add or update when "saveField" specified.
            } else {
                $fieldID = $post['vals']['id']; // covers when no field data is to be saved. (updates only.)
            }
            if (!empty($post['flaggedVals']) && is_array($post['flaggedVals'])) {
                $flg = $this->data->saveFlagged($post['flaggedVals'], $fieldID);
                if (!$flg['Result']) {
                    $this->jsObj->ErrTitle = $flg['ErrTitle'];
                    $this->jsObj->ErrMsg = $flg['ErrMsg'];
                    $this->jsObj->FlagData = $this->data->getFlaggedData();
                    return;
                }
            }
        } else {
            $rec = $this->data->save($post['vals']);
            if (!$rec['Result']) {
                $this->jsObj->ErrTitle = $rec['ErrTitle'];
                $this->jsObj->ErrMsg = $rec['ErrMsg'];
                return;
            }
            $fieldID = $rec['FieldID'];
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Fields = $this->data->getFields();
        $this->jsObj->FieldID = $fieldID;
        $this->jsObj->Chains = $this->data->makeFieldChains($this->jsObj->Fields);
        if ($this->hasFlagged) {
            $this->jsObj->FlagData = $this->data->getFlaggedData();
        }
    }

    /**
     * Remove custom field
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
        $this->jsObj->Chains = $this->data->makeFieldChains($this->jsObj->Fields);
        if ($this->hasFlagged) {
            $this->data->removeAllFlaggedByField($post['vals']['id']);
            $this->jsObj->FlagData = $this->data->getFlaggedData();
        }
    }

    /**
     * Update exclusion(s)
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function updateExcl($post)
    {
        $this->jsObj->Result = 0;
        if ($this->scope != 'thirdparty') {
            return;
        }
        $rec = $this->data->updateExcl($post['vals']);
        if (!$rec['Result']) {
            $this->jsObj->ErrTitle = $rec['ErrTitle'];
            $this->jsObj->ErrMsg = $rec['ErrMsg'];
            $this->jsObj->Errors = $rec['Errors'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Fields = $this->data->getFields();
        $this->jsObj->TypeCats = $this->data->getTypeCats();
        $this->jsObj->Chains = $this->data->makeFieldChains($this->jsObj->Fields);
    }
}
