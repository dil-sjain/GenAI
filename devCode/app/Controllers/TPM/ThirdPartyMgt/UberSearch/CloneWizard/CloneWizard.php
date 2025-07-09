<?php
/**
 * CloneWizard Allows for the cloning of Third Party Profiles and Case Folders as well as their dependent data
 * Acts as the controller for the Clone Wizard
 *
 * @keywords clone wizard, controller, 3p, clone third party management, multi tenant
 */

namespace Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard;

use Lib\Traits\AjaxDispatcher;
use Models\Globals\MultiTenantAccess;

/**
 * Controller to copy 3P parties, case folders and related information
 *
 */
#[\AllowDynamicProperties]
class CloneWizard
{
    use AjaxDispatcher;

    /**
     * @var object Instance of app
     */
    private $app;

    /**
     * @var $input
     */
    private $input;

    /**
     * @var $sequence
     * $this->initInput();
     */
    private $sequence;

    /**
     * @var $CloneWizardView
     */
    private $CloneWizardView;

    /**
     * @var $requestType
     * $this->initRequestType();
     */
    private $requestType;

    /**
     * @var $tenantID
     */
    protected $tenantID;



    /**
     * CloneWizard constructor.
     *
     * @param object  $app application instance
     * @param integer $cid clientID / tenantID
     *
     * @throws \Exception
     */
    public function __construct($app, $cid)
    {
        try {
            $this->app      = $app;
            $this->tenantID = $cid;

            $this->canAccessWizard();
            $this->initInput();
            $this->initMultiTenantAccess();
            $this->app->log->error($this->input->sources);
            $this->initRequestType();
            $this->app->log->error($this->requestType);
            $this->initSequence();
            $this->app->log->error($this->sequence);
            $this->hasPermission();
        } catch (\Exception $e) {
            /**
             * All thrown Exceptions caught here in __construct().
             */
            $this->app->log->error($e->getMessage());

            $this->jsObj->ErrTitle  = 'Unrecoverable Clone Wizard Error';
            $this->jsObj->ErrMsg    = $e->getMessage();
            $this->jsObj->Result    = 0;
            return;
        }
    }



    /**
     * Multi-tenant access validation
     *
     * @return void
     * @throws \Exception
     */
    private function initMultiTenantAccess()
    {
        $MultiTenantAccess = new MultiTenantAccess();

        if (!$MultiTenantAccess->hasMultiTenantAccess($this->tenantID)) {
            $this->handleError('No Multi-tenant access.', 'Invalid request:');
            throw new \Exception('No Multi-tenant access.');
        }
        if (!in_array($this->input->tenantID, $MultiTenantAccess->getAccessibleTenants($this->tenantID))) {
            $this->handleError('Multi-tenant access is not enabled.', 'Invalid request:');
            throw new \Exception('Multi-tenant access is not enabled.');
        }

        $this->CloneWizardView = new CloneWizardView($this->tenantID, $this->input);
    }



    /**
     * Minimum request data validation for clientID, userProfileNumber, userCaseNumber,
     * the record type e.g. the cloneType and the source details (dSrc) used to determine
     * dependent data and user inputs
     *
     * @return void
     * @throws \Exception
     */
    private function initInput()
    {
        $this->input = (object) [
            'tenantID'    => \Xtra::arrayGet($this->app->clean_POST, 'cID', ''),
            'userTpNum'   => \Xtra::arrayGet($this->app->clean_POST, 'pNum', ''),
            'userCaseNum' => \Xtra::arrayGet($this->app->clean_POST, 'cNum', ''),
            'cloneType'   => \Xtra::arrayGet($this->app->clean_POST, 'typ', ''),
            'sources'     => \Xtra::arrayGet($this->app->clean_POST, 'dSrc', ''),
        ];

        $invalidTenantID = (empty($this->input->tenantID));
        $invalidUniqueID = (empty($this->input->userTpNum) && empty($this->input->userCaseNum));
        $invalidType     = (!in_array($this->input->cloneType, ['Case', 'TpProfile']));

        if ($invalidTenantID || $invalidUniqueID || $invalidType) {
            $this->handleError('Insufficient Clone Wizard data.', 'Invalid request:');
            throw new \Exception('Invalid request: insufficient Clone Wizard data.');
        }
    }



    /**
     * Sets request type information, this helps to determine the sequence as well as the dependent data available.
     *
     * @return void
     */
    private function initRequestType()
    {
        $this->requestType = [
            'isCaseCase'    =>  (!$this->app->ftr->has(\Feature::TENANT_TPM) && $this->input->cloneType == 'Case'),
            'isCaseTpp'     =>  (!$this->app->ftr->has(\Feature::TENANT_TPM) && $this->input->cloneType == 'TpProfile'),
            'isTpmTpp'      =>  ( $this->app->ftr->has(\Feature::TENANT_TPM) && $this->input->cloneType == 'TpProfile'),
            'isTpmCase'     =>  ( $this->app->ftr->has(\Feature::TENANT_TPM) && $this->input->cloneType == 'Case'),
            'incCase'       =>  (
                                    $this->input->cloneType == 'Case'
                                    ||
                                    isset($this->input->sources['cases'])
                                ),
            'incNotes'      =>  (
                                    $this->input->cloneType == 'TpProfile'
                                    &&
                                    isset($this->input->sources['tpprofile']['notes'])
                                ),
            'incDocs'       =>  (
                                    $this->input->cloneType == 'TpProfile'
                                    &&
                                    isset($this->input->sources['tpprofile']['corporatedocs'])
                                ),
            'incTrainings'  =>  (
                                    $this->input->cloneType == 'TpProfile'
                                    &&
                                    isset($this->input->sources['tpprofile']['trainingdocs'])
                                ),
        ];
    }



    /**
     * Sets the sequence for progression through the clone wizard, determines the views required and their order
     * Sequence is determined by three factors: features e.g. TPM enabled or case only and dependent data
     *
     * @return void
     */
    private function initSequence()
    {
        if ($this->requestType['isTpmTpp']) {
            if ($this->CloneWizardView->hasTppData()) {
                $this->sequence = $this->requestType['incCase']
                    ?               ['displaySourceDetail', 'displayTPPInputForm', 'displayCaseInputForm',
                                    'displayReview']
                    :               ['displaySourceDetail', 'displayTPPInputForm', 'displayReview'];
            } else {
                $this->sequence = $this->requestType['incCase']
                    ?               ['displayTPPInputForm', 'displayCasesSelection', 'displayReview']
                    :               ['displayTPPInputForm', 'displayReview'];
            }
        } elseif ($this->requestType['isTpmCase']) {
                $this->sequence =   ['displayProfileSelection', 'displayCaseInputForm', 'displayReview'];
        } elseif ($this->requestType['isCaseTpp']) {
                $this->sequence =   ['displayCasesSelection', 'displayCaseInputForm', 'displayReview'];
        } elseif ($this->requestType['isCaseCase']) {
                $this->sequence =   ['displayCaseInputForm', 'displayReview'];
        }
    }


    /**
     *  Ajax call to Open the Clone Wizard
     *  Request to open the Clone Wizard
     *
     *  @return void AjaxDispatcher (object) jsObj
     */
    public function ajaxOpenWizard()
    {
        try {
            if ($this->requestType['isTpmTpp']) {
                $data = $this->CloneWizardView->hasTppData()
                    ?   $this->CloneWizardView->returnSourceDetailsTemplate()
                    :   $this->CloneWizardView->returnThirdPartyProfileTemplate();
            } elseif ($this->requestType['isTpmCase']) {
                $data = $this->CloneWizardView->returnProfileSelectionTemplate();
            } elseif ($this->requestType['isCaseTpp']) {
                $hasCases = $this->CloneWizardView->hasCases();
                if (empty($hasCases)) {
                    $this->handleError('The Third Party Profile contains no cases.', 'No cases to clone:');
                    return;
                }
                $data = $this->CloneWizardView->returnCasesSelectionTemplate();
            } elseif ($this->requestType['isCaseCase']) {
                $data = $this->CloneWizardView->returnCaseInputsTemplate();
            }

            $this->jsObj->FuncName = "appNS.cloneWizard.{$this->sequence[0]}";
            $this->jsObj->Args     = [$data['template'], $data['title'], json_encode($this->sequence)];
            $this->jsObj->Result   = 1;
        } catch (\Exception $e) {
            $this->handleError('Unable to open clone wizard.');
            $this->app->log->error('Caught exception: '.$e->getMessage());
        }
    }



    /**
     * Ajax handler for submitting case input form
     * Generates inputs for app/Views/TPM/UberSearch/CloneWizard/CaseInputs.tpl
     *
     * @return void AjaxDispatcher (object) jsObj
     *
     * @throws \Exception
     */
    public function ajaxSubmitCaseInputForm()
    {
        try {
            $error = [];

            if ($this->requestType['isCaseCase'] || $this->requestType['isCaseTpp']) {
                $valid = $this->CloneWizardView->validateCase();
                if (count($valid)) {
                    $error[] = implode('<br>', $valid);
                    $error   = implode('<br>', $error);
                    $this->handleError($error, 'Validation Error(s):');
                    return;
                }
                $data = $this->CloneWizardView->returnCasesReviewTemplate();
            } elseif ($this->requestType['isTpmCase']) {
                if (!isset($this->input->sources['tpprofile']['id'])
                    ||  !$this->CloneWizardView->validateTppIDLink($this->input->sources['tpprofile']['id'])
                ) {
                    $this->handleError('Error, unable to access profile.', 'Validation Error(s):');
                    return;
                }
                $valid = $this->CloneWizardView->validateCase();
                if (count($valid)) {
                    $error[] = implode('<br>', $valid);
                    $error   = implode('<br>', $error);
                    $this->handleError($error, 'Validation Error(s):');
                    return;
                }
                $data = $this->CloneWizardView->returnCasesReviewTemplate();
            } elseif ($this->requestType['isTpmTpp']) {
                if (!isset($this->input->sources['cases'])) {
                    $this->handleError('Invalid Access');
                    return;
                }
                $error = array_merge(
                    $this->validateTPPInputForm(),
                    $this->validateTPPDependentData()
                );
                if (count($error)) {
                    $this->jsObj->ErrTitle  = 'Validation Error(s):';
                    $this->handleError(implode('<br>', $error));
                    return;
                }
                $data = $this->CloneWizardView->returnReviewTemplate();
            }

            $this->jsObj->FuncName = "appNS.cloneWizard.{$data['callback']}";
            $this->jsObj->Args     = [$data['template'], $data['title'], json_encode($this->sequence)];
            $this->jsObj->Result   = 1;
        } catch (\Exception $e) {
            $this->handleError('Unable to process case input form');
            $this->app->log->error('Caught exception: '.$e->getMessage());
        }
    }



    /**
     *  Main function for cloning third party profile, cases and other dependent data
     *  Validation performed in CloneWizardView, actual cloning and record insertion performed in CloneWizardModel
     *
     * @see    CloneWizardView::cloneTpmTpp()        call to clone the third party profile
     * @see    CloneWizardModel::cloneTPP()  function which handles profile cloning for the model
     * @see    TrainingTrait::getTrainings() get the trainings associated with the tpprofile
     * @return void AjaxDispatcher (object) jsObj
     *
     * @throws \Exception
     */
    public function ajaxCloneRecord()
    {
        try {
            if ($this->requestType['isTpmCase']
                ||  $this->requestType['isCaseCase']
                ||  $this->requestType['isCaseTpp']
            ) {
                $error = $this->CloneWizardView->validateCase();
            }

            if ($this->requestType['isTpmTpp']) {
                $error = array_merge(
                    $this->validateTPPInputForm(),
                    $this->validateTPPDependentData()
                );

                if ($this->requestType['incCase']) {
                    foreach ($this->input->sources['cases'] as $insertCase) {
                        if (!$this->CloneWizardView->validateCaseID($insertCase['id'])) {
                            $error[] = 'Could not validate case: '.$insertCase['id'];
                        }
                    }
                }

                if (empty($error)) {
                    $recordID   = $this->CloneWizardView->CloneTpmTpp($this->input->sources['tpprofile']['id']);
                    $url        = $this->app->sitePath.'cms/thirdparty/thirdparty_home.sec?id='.
                        $recordID.'&tname=thirdPartyFolder';
                }
            } elseif ($this->requestType['isTpmCase']) {
                if (empty($error)) {
                    if (!isset($this->input->sources['tpprofile']['id'])
                        || !$this->CloneWizardView->validateTppIDLink($this->input->sources['tpprofile']['id'])
                    ) {
                        $error[] = 'Could not validate the association of Third Party Profile ID';
                    }
                    if (empty($error)) {
                        $recordID   = $this->CloneWizardView->cloneCase($this->input->sources['tpprofile']['id']);
                        $url        = $this->app->sitePath.'cms/case/casehome.sec?id='.$recordID.'&tname=casefolder';
                    }
                }
            } elseif ($this->requestType['isCaseCase'] || $this->requestType['isCaseTpp']) {
                if (empty($error)) {
                    $recordID   = $this->CloneWizardView->cloneCase();
                    $url        = $this->app->sitePath.'cms/case/casehome.sec?id='.$recordID.'&tname=casefolder';
                }
            }
            if (!empty($error)) {
                $error = implode('<br>', $error);
                $this->handleError($error, 'Validation Error(s):');
                return;
            }
            if (is_array($recordID) && isset($recordID['error'])) {
                $error = implode('<br>', $recordID);
                $this->handleError($error, 'Database error');
                return;
            }

            $this->jsObj->FuncName = 'appNS.cloneWizard.goToRecord';
            $this->jsObj->Args     = [$url];
            $this->jsObj->Result   = 1;
        } catch (Exception $e) {
            $this->jsObj->ErrMsg = $e->getMessage();
            $this->jsObj->Result = 0;
        }
    }


    /**
     * Ajax handler for entry points into Clone Wizard including source details, cases selection form,
     * profile selection form
     * Used by the following templates:
     * app/Views/TPM/UberSearch/CloneWizard/SourceDetails.tpl
     * app/Views/TPM/UberSearch/CloneWizard/CloneWizard.js.tpl
     * app/Views/TPM/UberSearch/CloneWizard/ProfileSelection.tpl
     * app/Views/TPM/UberSearch/CloneWizard/CasesSelection.tpl
     *
     * @return void AjaxDispatcher (object) jsObj
     *
     * @throws \Exception
     */
    public function ajaxSubmitDataSources()
    {
        try {
            if ($this->requestType['isTpmTpp']) {
                //   validate `SourceDetailsForm`
                $error = $this->validateTPPDependentData();
                if (count($error)) {
                    $this->handleError(implode('<br>', $error));
                    return;
                }

                $data = $this->CloneWizardView->returnThirdPartyProfileTemplate();
            } elseif ($this->requestType['isTpmCase']) {
                //   validate `ProfileSelectionForm`
                if (!isset($this->input->sources['tpprofile']['id'])
                    ||  !$this->CloneWizardView->validateTppIDLink($this->input->sources['tpprofile']['id'])
                ) {
                    $this->handleError('Error, unable to access profile.');
                    return;
                }
                $data = $this->CloneWizardView->returnCaseInputsTemplate();
            } elseif ($this->requestType['isCaseTpp']) {
                $case = $this->CloneWizardView->getCaseFromCases();         // Validates
                $data = $this->CloneWizardView->returnCaseInputsTemplate();
            }

            $this->jsObj->FuncName = "appNS.cloneWizard.{$data['callback']}";
            $this->jsObj->Args     = [$data['template'], $data['title'], json_encode($this->sequence)];
            $this->jsObj->Result   = 1;
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: '.$e->getMessage());
            $this->jsObj->ErrMsg = $e->getMessage();
        }
    }


    /**
     * Ajax handler for submitting third party profile data
     * Used with: app/Views/TPM/UberSearch/CloneWizard/ProfileInputs.tpl
     *
     * @return void AjaxDispatcher (object) jsObj
     *
     * @throws \Exception
     */
    public function ajaxSubmitTPPInputForm()
    {
        try {
            $error = array_merge(
                $this->validateTPPInputForm(),
                $this->validateTPPDependentData()
            );

            if (count($error)) {
                $this->jsObj->ErrTitle  = 'Validation Error(s):';
                $this->handleError(implode('<br>', $error));
                return;
            }

            $data = ($this->requestType['incCase'])
                ?   $this->CloneWizardView->returnCaseInputsTemplate()          //   Tpm.Tpp w.case
                :   $this->CloneWizardView->returnReviewTemplate();             //   Tpm.Tpp

            $this->jsObj->FuncName = "appNS.cloneWizard.{$data['callback']}";
            $this->jsObj->Args     = [$data['template'], $data['title'], json_encode($this->sequence)];
            $this->jsObj->Result   = 1;
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: '.$e->getMessage());
            $this->handleError('Unable to submit Third Party Input Form');
        }
    }


    /**
     * Ajax handler for getting third party profile categories
     *
     * @return void AjaxDispatcher (object) jsObj
     *
     * @throws \Exception
     */
    public function ajaxTppGetCategories()
    {
        try {
            $profileType = \Xtra::arrayGet($this->app->clean_POST, 'prtyp', '');
            $categories  = $this->CloneWizardView->getCategories($profileType);

            if (empty($categories)) {
                $this->jsObj->ErrMsg = 'Unable to retrieve categories. Please try again later.';
                $this->jsObj->Result   = 0;
            } else {
                $this->jsObj->FuncName = 'appNS.cloneWizard.updateCategories';
                $this->jsObj->Args     = [$categories];
                $this->jsObj->Result   = 1;
            }
        } catch (Exception $e) {
            $this->handleError('Unable to categories. Please try again later.');
            $this->app->log->error('Caught exception: '.$e->getMessage());
        }
    }


    /**
     * Searches for a thirdPartyProfile name match based on searchText
     *
     * @return void AjaxDispatcher (object) jsObj
     *
     * @throws \Exception
     * @refactor:    duplicate code used in `Add Profile Dialog`,
     */
    public function ajaxRequestMatches()
    {
        try {
            $search  = \Xtra::arrayGet($this->app->clean_POST, 'searchText', '');
            $matches = $this->CloneWizardView->returnMatches($search);
            $trText  = $this->app->trans->group('add_profile_dialog');
            $trText  = [
                'no_records'                => $trText['no_records'],
                'found_matching_records'    => $trText['found_matching_records'],
                'too_many_matching_records' => $trText['too_many_matching_records'],
            ];

            if ($matches->Count == 0) {
                $trString = $trText['no_records'];
            } else {
                $translatedText = (!$matches->TooMany)
                    ?   $trText['found_matching_records']
                    :   $trText['too_many_matching_records'];

                $trString  = str_replace('{totalRecords}', $matches->Count, (string) $translatedText);
            }

            $args = [
                'Matches' => $matches->Matches,
                'Count'   => $matches->Count,
                'TooMany' => (int)$matches->TooMany,
                'TrText'  => $trString,
            ];

            $this->jsObj->FuncName = 'appNS.cloneWizard.npMatchPopulate';
            $this->jsObj->Args     = [$args];
            $this->jsObj->Result = 1;
        } catch (\Exception $e) {
            $this->app->log->error('Caught exception: ' . $e->getFile() . ' on line ' . $e->getLine() . ': ' . $e->getMessage());
            $this->handleError('Unable to locate matches. Please try again later.');
        }
    }


    /**
     * Validates data specific to Tpm.Tpp dependent data sources
     *
     * @return array
     */
    private function validateTPPDependentData()
    {
        $error = [];

        //   Validate dependent data
        if ($this->requestType['incDocs']) {
            foreach ($this->input->sources['tpprofile']['corporatedocs'] as $doc) {
                if (!$this->CloneWizardView->validateTpAttach($doc['id'])) {
                    $error[] = 'Could not validate corporate document: '.$doc['id'];
                }
            }
        }
        if ($this->requestType['incNotes']) {
            foreach ($this->input->sources['tpprofile']['notes'] as $note) {
                if (!$this->CloneWizardView->validateTpNoteID($note['id'])) {
                    $error[] = 'Could not validate note: '.$note['id'];
                }
            }
        }
        if ($this->requestType['incCase']) {
            foreach ($this->input->sources['cases'] as $case) {
                if (!$this->CloneWizardView->validateCaseID($case['id'])) {
                    $error[] = 'Could not validate case: '.$case['id'];
                };
            }
        }

        return $error;
    }



    /**
     * Validates the TPP Input Form including dependent data.
     * Specifically validates user input e.g. $this->input->sources['profile']
     *
     * @return array
     */
    private function validateTPPInputForm()
    {
        $error = [];
        if (!$this->requestType['isTpmTpp']) {
            $error[] = 'Only Tpm.Tpp access';
        }

        $legalFormID        = $this->input->sources['tpprofile']['profile']['tpLF'];
        $profileTypeID      = $this->input->sources['tpprofile']['profile']['tpPT'];
        $profileCategoryID  = $this->input->sources['tpprofile']['profile']['tpCat'];
        $regionID           = $this->input->sources['tpprofile']['profile']['tpRg'];
        $departmentID       = $this->input->sources['tpprofile']['profile']['tpDp'];

        if (empty($legalFormID) || !$this->CloneWizardView->validateLegalFormID($legalFormID)) {
            $error[] = 'Could not validate legal form id';
        }
        if (empty($profileTypeID) || !$this->CloneWizardView->validateProfileTypeID($profileTypeID)) {
            $error[] = 'Could not validate profile type id';
        }
        if (empty($profileCategoryID)
            || !$this->CloneWizardView->validateProfileCategoryID($profileTypeID, $profileCategoryID)
        ) {
            $error[] = 'Could not validate profile category id';
        }
        if (empty($regionID) || !$this->CloneWizardView->validateRegionID($regionID)) {
            $error[] = 'Could not validate region id';
        }
        if (empty($departmentID) || !$this->CloneWizardView->validateDepartmentID($departmentID)) {
            $error[] = 'Could not validate department id';
        }

        //   Validate dependent data
        if ($this->requestType['incDocs']) {
            $tpAttachCategoryID = $this->input->sources['tpprofile']['profile']['docCat'];
            if (!$this->CloneWizardView->validateTpAttachCategoryID($tpAttachCategoryID)) {
                $error[] = 'Could not validate corporate document category ID';
            }
        }
        if ($this->requestType['incNotes']) {
            $noteCategoryID = $this->input->sources['tpprofile']['profile']['noteCat'];
            if (!$this->CloneWizardView->validateTpNoteCategoryID($noteCategoryID)) {
                $error[] = 'Could not validate notes category ID';
            }
        }

        return $error;
    }


    /**
     * Slightly more convenient than writing it out each time
     *
     * @param string $message error message to be returned
     * @param string $title   error title to be returned
     *
     * @return void AjaxDispatcher (object) jsObj
     */
    private function handleError($message, $title = null)
    {
        if (!is_null($title)) {
            $this->jsObj->ErrTitle = $title;
        }
        $this->jsObj->ErrMsg = $message;
        $this->jsObj->Result     = 0;
    }


    /**
     * Determine if a user should have clone wizard access
     *
     * @return boolean
     *
     * @throws \Exception
     */
    private function canAccessWizard()
    {
        if ($this->app->ftr->isLegacyClientAdmin()
            || $this->app->ftr->isLegacyFullClientAdmin()
            || $this->app->ftr->isSuperAdmin()
        ) {
            return true;
        }

        throw new \Exception('Insufficient user permissions.');
    }


    /**
     * Determine if the user has the appropriate features to interact with clone wizard
     *
     * @return boolean
     *
     * @throws \Exception
     */
    private function hasPermission()
    {
        if (!isset($this->sequence)) {
            throw new \Exception('Unable to verify clone wizard sequence.');
        }
                 // sequence is an array
        if (array_key_exists('displayProfileSelection', $this->sequence)
            || array_key_exists('displaySourceDetail', $this->sequence)
        ) {
            if (!$this->app->ftr->hasAll(
                \Feature::TENANT_TPM,
                \Feature::TP_ASSOC_CASE,
                \Feature::TP_DOCS,
                \Feature::TP_DOCS_ADD,
                \Feature::TP_DOCS_DL,
                \Feature::TP_NOTES,
                \Feature::TP_NOTES_ADD,
                \Feature::TP_PROFILE_ADD,
                \Feature::TP_PROFILE_EDIT
            )
            ) {
                throw new \Exception('Insufficient Permissions');
            }
        }

        if (array_key_exists('displayCaseInputForm', $this->sequence)
            ||array_key_exists('displayCasesSelection', $this->sequence)
        ) {
            if (!$this->app->ftr->hasAll(
                \Feature::CASE_DOCS,
                \Feature::CASE_DOCS_ADD,
                \Feature::CASE_NOTES,
                \Feature::CASE_NOTES_ADD,
                \Feature::CASE_ADD,
                \Feature::CASE_ADDL_INFO,
                \Feature::CASE_MANAGEMENT,
                \Feature::CASE_PERSONNEL,
                \Feature::CASE_PRINT
            )
            ) {
                throw new \Exception('Insufficient Permissions');
            }
        }

        return true;
    }
}
