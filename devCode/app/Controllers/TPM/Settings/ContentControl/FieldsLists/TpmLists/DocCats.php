<?php
/**
 * Control for TPM Fields/Lists - Document Categories
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\DocCatsData;

/**
 * Class DocCats controls the Document Category requirements. This class depends on being
 * called via the FieldsLists controller.
 *
 * @keywords note categories, tpm, fields lists, content control, settings, custom lists
 */
class DocCats extends TpmLists
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
        $this->data = new DocCatsData($this->tenantID, $this->userID, $this->listTypeID);
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
        $this->jsObj->Items  = $this->data->getCategories();
    }

    /*
     * Class uses TpmList methods add/update/remove
     */
}
