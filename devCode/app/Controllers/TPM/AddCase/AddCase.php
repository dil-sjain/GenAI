<?php
/**
 *  AddCase Controller
 *
 * @package Controllers\TPM\AddCase
 */

namespace Controllers\TPM\AddCase;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Lib\Traits\CheckCSRF;
use Models\TPM\AddCase\AddCase as AddCaseModel;
use Controllers\TPM\AddCase\Subs\Entry;
use Controllers\TPM\AddCase\Subs\KeyInfo;
use Controllers\TPM\AddCase\Subs\Principals;
use Controllers\TPM\AddCase\Subs\Contact;
use Controllers\TPM\AddCase\Subs\Attachments;
use Controllers\TPM\AddCase\Subs\Overview;
use Models\Globals\Billing\BillingUnit;
use Models\Globals\DocCategory;
use Models\TPM\CaseMgt\CaseFolder\SubInfoAttach;

/**
 * Class AddCase
 */
class AddCase extends Base
{
    use AjaxDispatcher {
        isCSRF as protected realIsCSRF;
    }

    public $error = [];
    public $caseID;
    public $currentStep;
    public $nextStep;
    public $tenantID;
    public $isDev;
    protected $app;
    protected $Model;
    protected $tplRoot = 'TPM/AddCase/Subs/';
    protected $tpl = 'Entry.tpl';
    protected $tppID;


    /**
     * Constructs base Add Case. Because ajaxHandler is called after construction, $this->jsObj is not available here.
     * As a result, errors from the Constructor should be stored and communicated inside of the ajax[Method] op
     * or handled in the ajaxDispatcher itself.
     *
     * @param int $tenantID associated logged in tenantID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        if ($tenantID && is_numeric($tenantID)) {
            $this->tenantID    = (int)$tenantID;
            $this->app         = \Xtra::app();
            $this->isDev       = ($this->app->mode == 'Development');
            $this->caseID      = \Xtra::arrayGet($this->app->clean_POST, 'caseID', '');
            $this->currentStep = \Xtra::arrayGet($this->app->clean_POST, 'current', '');
            $this->nextStep    = \Xtra::arrayGet($this->app->clean_POST, 'request', '');
            $this->tppID       = \Xtra::arrayGet($this->app->clean_POST, 'tpID', '');
            $this->Model       = new AddCaseModel($this->tenantID);
            $this->ajaxExceptionLogging = true;
        } else {
            throw new Exception('Unknown tenant');
        }
    }

    /**
     * Override isCSRF to allow calls from legacy for this one Ajax method
     *
     * @return bool
     */
    public function isCSRF()
    {
        if (isset($this->app->clean_POST['op']) && $this->app->clean_POST['op'] == 'checkCountry') {
            return false;
        } else {
            return $this->realIsCSRF();
        }
    }

    /**
     * Called after successful response from FileUpload,
     * moves file from /tmp and inserts record into subInfoAttach
     *
     * @return void
     * @throws \Exception
     */
    public function ajaxUploadAttachment()
    {
        $this->currentStep = 'Attachments';
        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.addCase.updateFileInfoClose';

        $this->persist();
        $this->jsObj->Args = [(new SubInfoAttach($this->tenantID))->getAttachments($this->caseID)];
    }


    /**
     * Updates a given attachment
     *
     * @return void
     */
    public function ajaxUpdateFileInfo()
    {
        $error = [];
        $categories = (new DocCategory($this->tenantID))->returnDocumentCategories();
        $category = \Xtra::arrayGet($this->app->clean_POST, 'category', '');
        $description = \Xtra::arrayGet($this->app->clean_POST, 'description', '');
        $id = \Xtra::arrayGet($this->app->clean_POST, 'id', '');

        if (!$category || !isset($categories[$category])) {
            $error[] = $this->app->trans->codeKey('missing_file_category');
        }
        if (!$description) {
            $error[] = $this->app->trans->codeKey('upload_file_description_error');
        }
        if ($error) {
            $this->jsObj->ErrMsg = (is_array($error)) ? implode('<br/>', $error) : $error;
            $this->jsObj->Result = 0;
        } else {
            $SubInfoAttach = new SubInfoAttach($this->tenantID);
            $SubInfoAttach->update($id, $description, $category, $this->caseID);

            $this->jsObj->Result   = 1;
            $this->jsObj->FuncName = 'appNS.addCase.updateFileInfoClose';
            $this->jsObj->Args     = [$SubInfoAttach->getAttachments($this->caseID)];
        }
    }


    /**
     * Deletes a given attachment
     *
     * @return void
     * @throws \Exception
     */
    public function ajaxDeleteFileInfo()
    {
        $id = \Xtra::arrayGet($this->app->clean_POST, 'id', '');

        $this->jsObj->Result    = 1;
        $this->jsObj->FuncName  = 'appNS.addCase.updateFileInfoClose';
        $this->jsObj->Args      = [(new Attachments($this->tenantID, $this->caseID))->deleteAttachment($id)];
    }


    /**
     * Responds to requests for templates.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function ajaxTemplate()
    {
        switch ($this->nextStep) {
            case 'Entry':
            case 'Principals':
            case 'Attachments':
                $this->prepareNext($this->nextStep);
                break;
            case 'Contact':
                if ($this->currentStep == 'Attachments' || empty($this->persist())) {
                    $this->prepareNext($this->nextStep);
                }
                break;
            case 'KeyInfo':
            case 'Overview':
                if (empty($this->persist())) {
                    $this->prepareNext($this->nextStep);
                }
                break;
            case 'SubmitCase':
                if (empty($this->persist())) {
                    $this->jsObj->FuncName = 'appNS.addCase.redirectUser';
                    $this->jsObj->Result   = 1;
                }
                break;
            default:
                throw new \Exception('Error: Unknown Request.');
                    break;
        }
    }


    /**
     * Called from Overview when the investigation firm select menu is changed to a new service provider.
     *
     * @return void
     * @throws \Exception
     */
    public function ajaxRecalculateCost()
    {
        $serviceProvider = \Xtra::arrayGet($this->app->clean_POST, 'serviceProvider', '');
        $this->jsObj->Args     = [
            (new Overview($this->tenantID, $this->caseID))->returnCalculationViewValues($serviceProvider)
        ];
        $this->jsObj->FuncName = 'appNS.addCase.recalculateCost';
        $this->jsObj->Result   = 1;
    }


    /**
     * AJAX method accepting a billingunit.id
     *
     * Returns billingUnitPO list for the given billingunit.id & a boolean determination on the visibility of
     * CASE_BU_REQUIRE_TEXT_PO field.
     *
     * @return void
     * @throws \Exception
     */
    public function ajaxBillingUnit()
    {
        $billingUnit     = \Xtra::arrayGet($this->app->clean_POST, 'billingUnit', '');
        $EntryController = new Entry($this->tenantID, $this->caseID);
        $units           = $EntryController->getBillingUnitPOs($billingUnit);
        $displayTextPO   = (new BillingUnit($this->tenantID))->displayPOTextField($billingUnit);

        $this->jsObj->Result = 1;
        $this->jsObj->FuncName = 'appNS.addCase.updateBillingUnitPO';
        $this->jsObj->Args = [$units, $displayTextPO];
    }


    /**
     * Returns a list of the state/provinces when the country changes. This array is prepared for a drop down
     * menu.
     *
     * @return void
     */
    public function ajaxRequestStatesProvinces()
    {
        $isoCode = \Xtra::arrayGet($this->app->clean_POST, 'isoCode', '');
        $state   = \Xtra::arrayGet($this->app->clean_POST, 'state', '');
        $states  = $this->Model->returnStateList($isoCode);
        $states  = (count($states) > 1) ? [0 => 'Choose...'] + $states : $states;

        $this->jsObj->Result    = 1;
        $this->jsObj->FuncName  = 'appNS.addCase.UpdateStates';
        $this->jsObj->Args      = [$states, $state];
    }


    /**
     *  Called from `Third Party Profile` dialog (on association of Third Party Profile)
     *
     *  Accepts a thirdPartyProfile.id
     *
     * @return void an AJAX response to update the `Add Case` dialog
     * @throws \Exception
     */
    public function ajaxRequestAssociateProfile()
    {
        $Args = (new Entry($this->tenantID, $this->caseID, $this->tppID))->AssociateProfile($this->tppID);

        $this->jsObj->Result   = 1;
        $this->jsObj->FuncName = 'appNS.addCase.handleAssociateProfile';
        $this->jsObj->Args     = [$Args];
    }

    /**
     * Check submitted country to see if it is on our Sanctioned or Prohibited lists.
     *
     * Expects 'country' to be submitted via POST and contain the countries ISO 2 code
     *
     * Return codes to expect
     *     a = Approved
     *     s = Sanctioned (Display message, force case assignment)
     *     p = Prohibited (Display message, cancel case creation)
     *
     * @return void AJAX response sent via $this->jsObj
     */
    public function ajaxCheckCountry()
    {
        $country = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'country', ''));

        $code    = 'a';
        $message = '';

        if ($this->Model->isCountryProhibited($country)) {
            $code    = 'p';
            $message = $this->app->trans->codeKey('add_case_prohibited_country_dialog');
        } elseif ($this->Model->isCountrySanctioned($country)) {
            $code = 's';
            $message = $this->app->trans->codeKey('add_case_sanctioned_country_dialog');
        }

        $this->jsObj->Result   = 1;
        $this->jsObj->FuncName = '';
        $this->jsObj->Args     = compact('code', 'message');
    }


    /**
     * Attempts to validate and store a record.
     *
     * @throws \Exception
     * @return array $error empty if validated and stored, otherwise imploded w/HTML formatting for appDialog.
     */
    private function persist()
    {
        try {
            $SubController = $this->setSubController($this->currentStep);
            $error         = $SubController->validate();
            $countryTxt    = $this->app->trans->codeKey('country');

            if (empty($error) && in_array($this->currentStep, ['Entry', 'KeyInfo'])) {
                $dialogTS = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'dialogTS', ''));
                $restDiag = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'restDiag', ''));
                if ($this->Model->isCountryProhibited($this->returnCountry())) {
                    $this->handleProhibitedCountry();
                    $error = "Prohibited $countryTxt.";
                } elseif ($this->Model->isCountrySanctioned($this->returnCountry()) &&
                    empty($dialogTS) && empty($restDiag)
                ) {
                    $this->handleSanctionedCountry();
                    $error = "Sanctioned $countryTxt.";
                }
            }
        } catch (\Exception $e) {
            // Uncaught / Runtime errors
            $error = $e->getMessage();
            $this->jsObj->ErrMsgWidth = 500;
            $this->jsObj->ErrMsg      = $error;
            $this->jsObj->Result      = 0;
        }

        if (empty($error)) {
            $SubController->store();
            $this->caseID = empty($this->caseID) ? $SubController->returnCaseID() : $this->caseID;
        } else {
            // Validation errors
            $this->jsObj->ErrMsgWidth = 500;
            $this->jsObj->ErrMsg      = (is_array($error)) ? implode('<br/>', $error) : $error;
            $this->jsObj->Result      = 0;
        }
        return $error;
    }


    /**
     * Sets the next sub factory controller.
     *
     * @param string $request Based on the given name, instances the class. Decided not to use variable variables
     *                        or magic methods to ease the integration testing.
     *
     * @return object Attachments|Contact|Entry|KeyInfo|Overview|Principals class instance.
     *
     * @throws \Exception
     */
    private function setSubController($request)
    {
        $SubController = match ($request) {
            'Entry' => new Entry($this->tenantID, $this->caseID, $this->tppID),
            'KeyInfo' => new KeyInfo($this->tenantID, $this->caseID, $this->tppID),
            'Principals' => new Principals($this->tenantID, $this->caseID),
            'Contact' => new Contact($this->tenantID, $this->caseID),
            'Attachments' => new Attachments($this->tenantID, $this->caseID),
            'Overview' => new Overview($this->tenantID, $this->caseID),
            default => throw new \Exception("Request has not been implemented! $request"),
        };

        return $SubController;
    }


    /**
     * Sets view values
     *
     * @param array $array given an associative array, sets the view values for smarty template.
     *
     * @return void
     * @throws \Exception
     */
    private function setViewValues($array)
    {
        foreach ($array as $key => $value) {
            $this->setViewValue($key, $value);
        }
    }


    /**
     * Prepares response for next template.
     *
     * @param string $step The next Sub Controller name.
     *
     * @return void
     * @throws \Exception
     */
    private function prepareNext($step)
    {
        $SubController = $this->setSubController($step);

        $this->setViewValues($SubController->getViewValues());

        $HTML = $this->app->view->fetch(
            $this->getTemplate($SubController->template),
            $this->getViewValues()
        );

        $this->jsObj->Args = [
            $HTML,
            $SubController->getTitle(),
            $SubController->returnInitialData(),
            $this->caseID
        ];
        $this->jsObj->FuncName = $SubController->callback;
        $this->jsObj->Result   = 1;
    }


    /**
     * Returns the user input country for the current template.
     *
     * @return string
     */
    private function returnCountry()
    {
        return ($this->currentStep == 'Entry')
            ?   trim((string) \Xtra::arrayGet($this->app->clean_POST, 'caseCountry', ''))
            :   trim((string) \Xtra::arrayGet($this->app->clean_POST, 'country', ''));
    }


    /**
     * SEC-3080: Pop up for sanctioned/restricted countries.
     * The user is not allowed to complete the dialog.
     */
    private function handleProhibitedCountry()
    {
        $this->jsObj->FuncName    = 'appNS.addCase.openProhibitedDialog';
        $this->jsObj->Args        = [$this->app->trans->codeKey('add_case_prohibited_country_dialog')];
        $this->jsObj->Result      = 1;
        $this->jsObj->ErrMsgWidth = 500;
    }


    /**
     * SEC-3081: Pop up for sanctioned/prohibited countries.
     * The user is allowed to complete the dialog but restrictions apply and emails are hijacked.
     */
    private function handleSanctionedCountry()
    {
        $this->jsObj->FuncName    = 'appNS.addCase.openSanctionedDialog';
        $this->jsObj->Args        = [$this->app->trans->codeKey('add_case_sanctioned_country_dialog')];
        $this->jsObj->Result      = 1;
        $this->jsObj->ErrMsgWidth = 500;
    }
}
