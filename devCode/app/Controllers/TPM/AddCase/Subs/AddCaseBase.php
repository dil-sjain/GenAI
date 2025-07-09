<?php
/**
 * Add Case Dialog Base Class.
 *
 * @category AddCase_Dialog Base class.
 * @package  Controllers\TPM\AddCase\Subs;
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddCase\Subs;

use Models\TPM\AddCase\AddCase as Model;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\SubjectInfoDD;
use Lib\Legacy\CaseStage;

/**
 * Class AddCaseBase
 */
#[\AllowDynamicProperties]
abstract class AddCaseBase
{
    public $caseID;
    public $callback;
    public $title;
    public $template;
    protected $Model;
    protected $hasTPM;
    protected $tenantID;
    protected $inputs;
    protected $CasesObject;
    protected $SubInfoObject;
    protected $CasesRecord;
    protected $SubInfoRecord;
    protected $userID;


    /**
     * AddCaseBase constructor.
     *
     * @param int $tenantID tenant ID of logged in user
     * @param int $caseID   cases.id of case being added or edited.
     * @throws \Exception
     */
    public function __construct($tenantID, $caseID = null)
    {
        if ($tenantID && is_numeric($tenantID)) {
            $this->inputs = [];
            $this->tenantID = (int)$tenantID;
            $this->caseID = $caseID;
            $this->app = \Xtra::app();
            $this->userID = $this->app->ftr->user;
            $this->tenantDB = $this->app->DB->getClientDB($tenantID);
            $this->Model = new Model($tenantID);
            $this->Cases = new Cases($this->tenantID);
            $this->SubInfo = new SubjectInfoDD($this->tenantID);
            $this->CasesObject = $this->Cases->findById($this->caseID);
            $this->CasesRecord = $this->Cases->getCaseRow($this->caseID);
            $this->hasTPM = $this->app->ftr->has(\Feature::TENANT_TPM);

            if ($this->CasesRecord) {
                $this->SubInfoObject = $this->SubInfo->findByAttributes(
                    [
                        'caseID'   => $this->caseID,
                        'clientID' => $this->tenantID,
                    ]
                );
                $this->SubInfoRecord = $this->SubInfoObject->getAttributes();
            }

            $this->validateTemplateEntry();
        } else {
            throw new Exception('Unknown tenant');
        }
    }


    /**
     * Validates the appropriate input / data for the current template.
     *
     * @return void
     *
     * @throws \Exception
     */
    private function validateTemplateEntry()
    {
        $errorMessage = '';
        if (!in_array($this->template, ['Entry.tpl', 'KeyInfo.tpl'])) {
            if (empty($this->returnCaseID()) || empty($this->SubInfoRecord)) {
                $errorMessage = 'Error: Unable to retrieve record.';
            }
            if ($this->template == 'Overview.tpl') {
                if (!in_array(
                    $this->CasesRecord['caseStage'],
                    [
                        CaseStage::QUALIFICATION,
                        CaseStage::REQUESTED_DRAFT,
                        CaseStage::UNASSIGNED,
                        CaseStage::CLOSED,
                        CaseStage::CLOSED_HELD,
                        CaseStage::CLOSED_INTERNAL,
                        CaseStage::CASE_CANCELED,
                    ]
                )) {
                    $errorMessage = 'Error: invalid case stage.';
                }
            }
        }
        if (!empty($errorMessage)) {
            throw new \Exception($errorMessage);
        }
    }


    /**
     * Returns the caseID. This is primarily used to return the cases.id after its initial creation when the class
     * has been constructed with the default null parameter.
     *
     * @return int
     */
    public function returnCaseID()
    {
        return $this->caseID;
    }


    /**
     * Returns an initial array of data for the dialog. Called from AddCase->prepareNext() and passed back as the
     * second parameter in the jsObj arguments.
     *
     * @return array any type of data to be handled by a JavaScript callback.
     */
    public function returnInitialData()
    {
        return [];
    }


    /**
     * Returns an associative array of inputs matched to a user's submitted values.
     *
     * @param array $inputs user input fields.
     *
     * @return void
     */
    protected function setUserInput($inputs)
    {
        foreach ($inputs as $name) {
            $this->inputs[$name] = trim((string) \Xtra::arrayGet($this->app->clean_POST, $name, ''));
        }
    }


    /**
     * Validates a form step, returning an empty array on success.
     *
     * @return array of text translations indicating to the user which fields to fix.
     */
    public function validate()
    {
        return [];
    }
}
