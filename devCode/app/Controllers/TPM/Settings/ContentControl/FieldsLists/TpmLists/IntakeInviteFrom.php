<?php
/**
 * Control for TPM Fields/Lists - Intake Invite From:
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmLists;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpTypesData;
use Controllers\TPM\IntakeForms\InviteMenu\IntakeFormInvite;
use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\IntakeInviteFromData;

/**
 * Class IntakeInviteFrom controls the email address validation. This class depends on being
 * called via the FieldsLists controller.
 *
 * @keywords Intake Invite, From, tpm, fields lists, content control, settings, custom lists
 */
#[\AllowDynamicProperties]
class IntakeInviteFrom extends TpmLists
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
     * @var object IntakeFormInvite Core Feature object
     */
    protected $IntakeFormInvite = null;

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
        $this->IntakeFormInvite = new IntakeFormInvite($tenantID);
        if (!$this->IntakeFormInvite->isClientFeatureEnabled($tenantID)) {
            __destruct();
            return false; // not allowed to request this class. How did they get this far?
        }
        // we just need to load the right model, and kick off text translations.
        $this->data = new IntakeInviteFromData($this->tenantID, $this->userID, $this->listTypeID);
        $this->data->setTrText($this->txt);
    }

    /**
     * Get initial display data to pass back to the screen
     *
     * @return void
     */
    protected function initDisplay()
    {
        $this->jsObj->Result = 1;
        $this->jsObj->Items  = $this->data->getFlEmailList($this->tenantID);
    }

    /*
     * Class uses TpmList methods add/update/remove
     */
}
