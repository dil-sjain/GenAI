<?php
/**
 * Controller to present clone wizard views
 *
 * @keywords clone wizard, controller, 3p, clone third party management, multi tenant, views
 */
namespace Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard;

use Controllers\ThirdPartyManagement\Base;
use Controllers\TPM\ThirdPartyMgt\UberSearch\CloneWizard\CloneWizardModel;

/**
 * Controller to present clone wizard views, used in conjunction with CloneWizard and CloneWizardModel
 *
 */
class CloneWizardView extends Base
{

    /**
     * @var $tplRoot (string) Base directory for view
     */
    protected $tplRoot = 'TPM/UberSearch/CloneWizard/';

    /**
     * @var $tpl (string / array) template(s) for view
     */
    protected $tpl = 'ProfileInputs.tpl';

    /**
     * @var array
     */
    public $app = [];

    /**
     * Acts an intermediary from the CloneWizard to the Model,
     * validating data more closely & setting all template data.
     *
     * @param int   $tenantID $this->clientID or $this->tenantID
     * @param array $input    $this->input, user inputs for cases, third party profile and dependent data
     */
    public function __construct($tenantID, public $input)
    {
        $this->app      = \Xtra::app();

        $uniqueID = ($this->input->cloneType == 'Case') ? $this->input->userCaseNum : $this->input->userTpNum;

        $this->CloneWizardModel = new CloneWizardModel(
            $tenantID,
            $this->input->tenantID,
            $this->input->cloneType,
            $uniqueID
        );
    }



    /**
     * Clones a case record using a combination of existing case data and user inputs from the wizard
     * Validation already happened in CloneWizard
     *
     * @param mixed $associateTPP clientDB.thirdPartyProfile.id to associate to case.tpID (Tpm.Case)
     *
     * @return integer case.id of new record
     */
    public function cloneCase(mixed $associateTPP = false)
    {
        $caseID   = key($this->input->sources['cases']);
        $caseData = $this->input->sources['cases'][$caseID];

        $case = ($this->input->cloneType == 'Case')
            ?   $this->CloneWizardModel->getCase()
            :   $this->getCaseFromCases();

        return $this->CloneWizardModel->cloneCase($case, $caseData, $associateTPP);
    }



    /**
     * Clones Third party profile and all dependent data returning thirdPartyProfile.id or ['error' => 'message']
     *
     * @return mixed $recordID
     */
    public function cloneTpmTpp()
    {
        return $this->CloneWizardModel->cloneTPP($this->input);
    }



    /**
     * Returns the template for use with cases review e.g. when a user has selected to clone a case or case(s)
     * This method is intended for use with the final "stage" or step within the clone wizard
     * Used with: app/Views/TPM/UberSearch/CloneWizard/ReviewSources.tpl
     *
     * @return array
     *
     * @throws \Exception
     */
    public function returnCasesReviewTemplate()
    {
        $regions     = $this->CloneWizardModel->getUserRegions();
        $departments = $this->CloneWizardModel->getUserDepartments();

        $cases = ($this->input->cloneType == 'Case')
            ?   $this->CloneWizardModel->getCase()
            :   $this->getCaseFromCases();

        $cases = $this->combineCasesWithInputs($this->input->sources['cases'], $cases);

        $viewValues = [
            'pageName'      =>  'displayCasesReview',    // This toggles the TPP view (displayReview)
            'Regions'       =>  $regions,
            'Departments'   =>  $departments,
            'cases'         => $cases,
        ] + $this->baseViewViews();

        return $returnArray = [
            'title'         => 'Review',
            'callback'      => 'displayReview',
            'template'      => $this->app->view->fetch($this->getTemplate('ReviewSources.tpl'), $viewValues),
        ];
    }



    /**
     * Returns the review template, including third party profile inputs and data for use with the clone wizard
     * This method is intended for use with the final "stage" or step within the clone wizard and is the counterpart
     * to returnCasesReviewTemplate
     * Used with: app/Views/TPM/UberSearch/CloneWizard/ReviewSources.tpl
     *
     * @return array
     */
    public function returnReviewTemplate()
    {
        $viewValues = [
            'pageName'      =>  'displayReview',
            'Regions'       =>  $this->CloneWizardModel->getUserRegions(),
            'Departments'   =>  $this->CloneWizardModel->getUserDepartments(),
            'ProfileTypes'  =>  $this->CloneWizardModel->getProfileTypesList(),
            'legalForm'     =>  $this->CloneWizardModel->getCompanyLegalFormList(),
        ];

        $profileType                     = $this->input->sources['tpprofile']['profile']['tpPT'];
        $viewValues['profileTypeLabel']  = $viewValues['ProfileTypes'][$profileType];

        $categories                      = $this->CloneWizardModel->getCategories($profileType);
        $category                        = $this->input->sources['tpprofile']['profile']['tpCat'];
        $viewValues['categoryTypeLabel'] = $categories[$category];

        $viewValues['legalFormLabel']
            = $viewValues['legalForm'][$this->input->sources['tpprofile']['profile']['tpLF']];

        $region                          = $this->input->sources['tpprofile']['profile']['tpRg'];
        $viewValues['regionLabel']       = $viewValues['Regions'][$region];

        $viewValues['sources'] = [];

        if (isset($this->input->sources['tpprofile']['trainingdocs'])) {
            $viewValues['sources']['trainingdocs'] = 'Training Documents';
        }
        if (isset($this->input->sources['tpprofile']['corporatedocs'])) {
            $viewValues['sources']['corporatedocs'] = '3P Documents';
        }
        if (isset($this->input->sources['tpprofile']['notes'])) {
            $viewValues['sources']['notes'] = 'Notes';
        }
        if (isset($this->input->sources['cases'])) {
            $viewValues['sources']['cases'] = 'Cases';
            $cases = $this->getCaseFromCases();
            $cases = $this->combineCasesWithInputs($this->input->sources['cases'], $cases);
            $viewValues['cases'] = $cases;
        }

        $viewValues = $viewValues + $this->baseViewViews();

        // Review data that can be cloned
        foreach ($this->setTPPCloneViewValues() as $key => $value) {
            $viewValues[$key] = $value;
        }

        // User data to clone
        $viewValues['internalCode'] = $this->input->sources['tpprofile']['profile']['tpIC'];
        $viewValues['POCname']      = $this->input->sources['tpprofile']['profile']['tpPcNm'];
        $viewValues['POCposi']      = $this->input->sources['tpprofile']['profile']['tpPcPos'];
        $viewValues['POCemail']     = $this->input->sources['tpprofile']['profile']['tpPcEm'];
        $viewValues['POCphone1']    = $this->input->sources['tpprofile']['profile']['tpPcPh1'];
        $viewValues['POCphone2']    = $this->input->sources['tpprofile']['profile']['tpPcPh2'];
        $viewValues['POCmobile']    = $this->input->sources['tpprofile']['profile']['tpPcMb'];
        $viewValues['POCfax']       = $this->input->sources['tpprofile']['profile']['tpPcFx'];

        return [
            'title'         => 'Review',
            'callback'      => 'displayReview',
            'template'      => $this->app->view->fetch($this->getTemplate('ReviewSources.tpl'), $viewValues),
        ];
    }



    /**
     * Returns the third party profile template and modifies html based on
     * the dependent data selected as well as the data present in the existing profile
     * Used with: app/Views/TPM/UberSearch/CloneWizard/ProfileInputs.tpl
     * 1st step if dependent data sources do not exist,
     * 2nd step if dependent data sources do exist.
     *
     * @return array
     *
     * @throws \Exception
     */
    public function returnThirdPartyProfileTemplate()
    {
        $viewValues = [
        'addProgressBar'    => !$this->hasTppData(),
        'pageName'          =>  'displayTPPInputForm',
        'Regions'           =>  $this->CloneWizardModel->getUserRegions(),
        'Departments'       =>  $this->CloneWizardModel->getUserDepartments(),
        'ProfileTypes'      =>  $this->CloneWizardModel->getProfileTypesList(),
        'legalFormTypes'    =>  $this->CloneWizardModel->getCompanyLegalFormList(),
        'hasNotes'          =>  isset($this->input->sources['tpprofile']['notes']),
        'hasCorporateDocs'  =>  isset($this->input->sources['tpprofile']['corporatedocs']),
        'hasTrainingDocs'   =>  isset($this->input->sources['tpprofile']['trainingdocs']),
        ];

        if ($viewValues['hasNotes']) {
            $viewValues['noteCategoryTypes'] = $this->CloneWizardModel->getNotesForm();
        }
        if ($viewValues['hasCorporateDocs']) {
            foreach ($this->CloneWizardModel->getCorporateDocsForm() as $key => $value) {
                $viewValues[$key] = $value;
            }
        }
        foreach ($this->setTPPCloneViewValues() as $key => $value) {
            $viewValues[$key] = $value;
        }

        return [
        'title'      =>  'Third Party Details',
        'callback'   =>  'displayTPPInputForm',
        'template'   =>  $this->app->view->fetch(
            $this->getTemplate('ProfileInputs.tpl'),
            $viewValues + $this->baseViewViews()
        ),
        ];
    }



    /**
     * Returns the cases selection template - only accessible when case-only attempting to clone a third party profile
     * Displays all the cases associated with a profile from which the user must choose (may only select one)
     * Used with:  app/Views/TPM/UberSearch/CloneWizard/CasesSelection.tpl
     *
     * @return array
     */
    public function returnCasesSelectionTemplate()
    {
        $viewValues = $this->baseViewViews() + ['cases' => $this->CloneWizardModel->getCases()];

        return [
                'title'      =>  'Cases Selection Form',
                'template'   =>  $this->app->view->fetch($this->getTemplate('CasesSelection.tpl'), $viewValues),
        ];
    }



    /**
     * Returns the case inputs template - only accessible when a case has been selected as part of the cloning process
     * Always available for case only OpCo attempting to clone a record - this is the most prevalent view in the wizard
     * Used with: app/Views/TPM/UberSearch/CloneWizard/CaseInputs.tpl
     *
     * @return array
     */
    public function returnCaseInputsTemplate()
    {
        $cases = ($this->input->cloneType == 'Case')
            ?   $this->CloneWizardModel->getCase()
            :   $this->getCaseFromCases();

        $viewValues = $this->baseViewViews() +
            [
                'cases'          => $cases,
                'addProgressBar' => (!$this->app->ftr->has(\Feature::TENANT_TPM) && $this->input->cloneType == 'Case'),
                'Regions'        => $this->CloneWizardModel->getUserRegions(),
                'Departments'    => $this->CloneWizardModel->getUserDepartments(),
            ];

        if (isset($this->input->sources['tpprofile']['profile'])) {
            $profile = $this->input->sources['tpprofile']['profile'];
            $pocName = $profile['tpPcNm'] ?? '';
            $pocPos = $profile['tpPcPos'] ?? '';
            $pocTel = $profile['tpPcPh1'] ?? '';

            $viewValues += [
                'pocname'     => $pocName,
                'pocposition'      => $pocPos,
                'poctelephone'      => $pocTel,
            ];
        }

        $title = ($this->input->cloneType == 'Case')
            ?   'Case Input Form'
            :   'Case(s) Form';

        return [
                'title'      =>  $title,
                'callback'   =>  'displayCaseInputForm',
                'template'   =>  $this->app->view->fetch($this->getTemplate('CaseInputs.tpl'), $viewValues),
        ];
    }



    /**
     * Returns the source details template, only accessible when TPM enabled cloning a third party profile
     * This template is dynamic and may contain different rows within the dependent data seleciton table dependent
     * upon those associated with the third party profile being cloned
     * Used with: app/Views/TPM/UberSearch/CloneWizard/SourceDetails.tpl
     *
     * @return array
     */
    public function returnSourceDetailsTemplate()
    {
        $tableData = [
            'cases'         => [
                'data'  => $this->CloneWizardModel->getCases(),
                'title' => 'Cases',
                'th'    => [
                    'userCaseNum' => 'Case Number',
                    'caseName'    => 'Case Name',
                    'budgetType'  => 'Budget Type',
                    'requestor'   => 'Requester',
                    'caseCreated' => 'Date',
                ],
            ],
            'trainingdocs'  => [
                'data'      => $this->CloneWizardModel->getTrainings(),
                'title'     => 'Training(s)',
                'th'        => [
                    'name'          => 'Name',
                    'provider'      => 'Provider',
                    'providerType'  => 'File Name',
                    'created'       => 'Created',
                ],
            ],
            'corporatedocs' => [
                'data'      => $this->CloneWizardModel->getTpAttach(),
                'title'     => 'Corporate Documents',
                'th'        => [
                    'description'   => 'Description',
                    'filename'      => 'File Name',
                    'fileType'      => 'File Type',
                    'creationStamp' => 'Date',
                ],
            ],
            'notes'         => [
                'data'      => $this->CloneWizardModel->getTpNote(),
                'title'     => 'Notes',
                'th'        => [
                    'subject'   => 'Subject',
                    'note'      => 'Note',
                    'created'   => 'Date',
                ],
            ],
        ];

        foreach ($tableData as $recordType => $values) {
            if (empty($values['data'])) {
                unset($tableData[$recordType]);
            }
        }

        $viewValues = $this->baseViewViews() + [
            'pageName'     => 'displaySourceDetail',
            'inputSources' => $tableData,
        ];

        return [
                'title'      =>  'Available Data Sources',
                'callback'   =>  'displaySourceDetail',
                'template'   =>  $this->app->view->fetch($this->getTemplate('SourceDetails.tpl'), $viewValues),
        ];
    }



    /**
     * Returns profile selection template, only accessible when TPM enabled attempting to clone a case
     * This view is similar to the legacy add third party profile view and requires a user to search and select
     * the appropriate third party profile with which the new case clone will be associated
     * Used with: app/Views/TPM/UberSearch/CloneWizard/ProfileSelection.tpl
     *
     * @return array
     */
    public function returnProfileSelectionTemplate()
    {
        $case                = $this->CloneWizardModel->getCase();

        $viewValues = $this->baseViewViews() + [
            'pageName'      => 'displayProfileSelection',
            'Regions'       => $this->CloneWizardModel->getUserRegions(),
            'Departments'   => $this->CloneWizardModel->getUserDepartments(),
            'ProfileTypes'  => $this->CloneWizardModel->getProfileTypesList(),
            'case'          => $case,
        ];

        return [
            'title'      =>  'Profile Selection Form',
            'callback'   =>  'displayProfileSelection',
            'template'   =>  $this->app->view->fetch($this->getTemplate('ProfileSelection.tpl'), $viewValues)
        ];
    }



    /**
     * Validates company legal form id
     *
     * @param integer $legalFormID id of the legaForm of third party profile
     *
     * @return boolean
     */
    public function validateLegalFormID($legalFormID)
    {
        $legalFormList = $this->CloneWizardModel->getCompanyLegalFormList();

        return isset($legalFormList[$legalFormID]);
    }

    /**
     * Validates profile type id
     *
     * @param integer $profileTypeID id of the profileType of third party profile (db.tpType)
     *
     * @return boolean
     */
    public function validateProfileTypeID($profileTypeID)
    {
        $profileTypeList = $this->CloneWizardModel->getProfileTypesList();

        return isset($profileTypeList[$profileTypeID]);
    }

    /**
     * Validates profile category id
     *
     * @param integer $profileTypeID     id of the profileType of third party profile (db.tpType)
     * @param integer $profileCategoryID id of tpTypeCategory
     *
     * @return boolean
     */
    public function validateProfileCategoryID($profileTypeID, $profileCategoryID)
    {
        $categoryTypeList = $this->CloneWizardModel->getCategories($profileTypeID);

        return isset($categoryTypeList[$profileCategoryID]);
    }

    /**
     * Validates region id
     *
     * @param integer $regionID id of db.region
     *
     * @return boolean
     */
    public function validateRegionID($regionID)
    {
        $regionList = $this->CloneWizardModel->getUserRegions();

        return isset($regionList[$regionID]);
    }

    /**
     * Validates department id
     *
     * @param integer $departmentID id of db.department
     *
     * @return boolean
     */
    public function validateDepartmentID($departmentID)
    {
        $departmentList = $this->CloneWizardModel->getUserDepartments();

        return isset($departmentList[$departmentID]);
    }

    /**
     * Validates the Case id, the Region, and the Department for Case.Case, Case.Tpm, Tpm.Case, but not Tpm.Tpp
     * (multiple cases)
     *
     * @return array
     */
    public function validateCase()
    {
        $errors = [];

        $caseArray = ($this->input->cloneType == 'Case')
            ?   $this->CloneWizardModel->getCase()
            :   $this->getCaseFromCases();

        $case = (array_key_exists('userCaseNum', $caseArray)) ? $caseArray : $caseArray[0];

        if (!isset($this->input->sources['cases'][$case['id']])) {
            $errors[] = 'Invalid Case. See: '.__METHOD__;
        }

        $regions = $this->CloneWizardModel->getUserRegions();
        if (!$regions[$this->input->sources['cases'][$case['id']]['csRg']]) {
            $errors[] = 'The region could not be validated. Region: '
                .$this->input->sources['cases'][$case['id']]['csRg'];
        }

        $departments  = $this->CloneWizardModel->getUserDepartments();
        if (!isset($departments[$this->input->sources['cases'][$case['id']]['csDep']])) {
            $errors[] = 'The region could not be validated. Region: '
                .$this->input->sources['cases'][$case['id']]['csRg'];
        }

        foreach (['csRg', 'csDep', 'csStat'] as $userInput) {
            if (!isset($this->input->sources['cases'][$case['id']][$userInput])
                ||  empty($this->input->sources['cases'][$case['id']][$userInput])
            ) {
                $errors[] = 'Missing or empty: '.$userInput;
            }
        }

        return $errors;
    }

    /**
     * Validates case.id
     *
     * @param integer $caseID cases.id
     *
     * @return bool
     */
    public function validateCaseID($caseID)
    {
        $caseList = [];
        foreach ($this->CloneWizardModel->getCases() as $case) {
            $caseList[] = $case['id'];
        }

        return (in_array($caseID, $caseList));
    }

    /**
     * Validates tpAttach.id (Corporate doc or 3pdoc)
     *
     * @param integer $tpAttachID tpAttach.id
     *
     * @return bool
     */
    public function validateTpAttach($tpAttachID)
    {
        $tpAttachList = [];
        foreach ($this->CloneWizardModel->getTpAttach() as $tpAttach) {
            $tpAttachList[] = $tpAttach['id'];
        }

        return (in_array($tpAttachID, $tpAttachList));
    }

    /**
     * Validates tpAttachCategory.id (Corporate doc attach type)
     *
     * @param integer $tpAttachCategoryID docCategory.id
     *
     * @return bool
     */
    public function validateTpAttachCategoryID($tpAttachCategoryID)
    {
        $tpAttachList = $this->CloneWizardModel->getCorporateDocsForm()['docCategoryID'];

        return isset($tpAttachList[$tpAttachCategoryID]);
    }

    /**
     * Validates tpNote.id (note id)
     *
     * @param integer $noteID tpNote.id
     *
     * @return bool
     */
    public function validateTpNoteID($noteID)
    {
        $tpNoteList = [];
        foreach ($this->CloneWizardModel->getTpNote() as $tpNote) {
            $tpNoteList[] = $tpNote['id'];
        }

        return in_array($noteID, $tpNoteList);
    }

    /**
     * Validates the tenantDB.thirdPartyProfile.id to associated Tpm.Case clone
     *
     * @param integer $tppID thirdPartyProfile.id
     *
     * @return bool
     */
    public function validateTppIDLink($tppID)
    {
        $isValid = $this->CloneWizardModel->validateTppIDLink($tppID);
        return !empty($isValid);
    }

    /**
     * Validates note category id
     *
     * @param integer $noteCategoryID db.noteCategory.id
     *
     * @return bool
     */
    public function validateTpNoteCategoryID($noteCategoryID)
    {
        $noteList = $this->CloneWizardModel->getNotesForm();

        return isset($noteList[$noteCategoryID]);
    }

    /**
     * Determines if a third party profile has cases associated with it
     *
     * @return bool
     */
    public function hasCases()
    {
        $hasCases = $this->CloneWizardModel->getCases();
        return !empty($hasCases);
    }

    /**
     * Returns the validated case matching user's posted data ($this->input->sources['cases']).
     *
     * @return array $cases
     * @throws \Exception
     */
    public function getCaseFromCases()
    {
        if (!isset($this->input->sources['cases'])) {
            throw new \Exception('Case ID not in $_POST');
        }
        $caseIDs = array_keys($this->input->sources['cases']);
        $cases = [];

        foreach ($this->CloneWizardModel->getCases() as $selectedCase) {
            if (in_array($selectedCase['id'], $caseIDs)) {
                $cases[] = $selectedCase;
            }
        }
        // throws exception
        if (count($cases) < 1) {
            throw new \Exception('Could not find case record.');
        }
        return $cases;
    }

    /**
     * Ajax handler for retrieving third party profile categories based on the profile type
     * CloneWizard->ajaxTppGetCategories()
     *
     * @param integer $profileType id of the profileType of third party profile (db.tpType)
     *
     * @return array
     */
    public function getCategories($profileType)
    {
        return $this->CloneWizardModel->getCategories($profileType);
    }


    /**
     * Combine $this->input->sources with $cases for the preview template
     *
     * @param array $inputs $this->input->sources (user submitted data)
     * @param array $cases  array of $cases from getCases
     *
     * @return array
     */
    public function combineCasesWithInputs($inputs, $cases)
    {
        $fmtCases = [];

        // get region and department label for the cases

        foreach ($cases as $case) {
            $combinedCase = array_merge($case, $inputs[$case['id']]);
            $case = $this->CloneWizardModel->formatCaseInputs($combinedCase);
            $fmtCases[] = $case;
        }

        return $fmtCases;
    }


    /**
     * Determine if case was generated through ddq or manual creation
     *
     * @param integer $caseID cases.id
     *
     * @return string
     */
    public function getCaseOrigin($caseID)
    {
        $source = $this->app->DB->fetchValue("SELECT caseID FROM {$this->tenantDB}.ddq WHERE caseID = :caseID", [
            ':caseID' => (int)$caseID
            ]);
        //    if a ddq exists with this caseID then it's a safe bet that the case was generated from a ddq
        return ($source == false) ? 'Manual Creation' : 'Due Diligence Questionnaire';
    }


    /**
     * Used by TPM enabled cloning third party profile for TPP Input Form and Review Page
     *
     * returnTPPInputFormTemplate(); this function creates the smarty template for rendering
     *
     * @return mixed
     */
    private function setTPPCloneViewValues()
    {
        $thirdPartyProfile = $this->CloneWizardModel->getThirdPartyProfile($this->input->userTpNum);

        $viewValues['legalName']        = $thirdPartyProfile->legalName;
        $viewValues['DBAname']          = $thirdPartyProfile->DBAname;
        $viewValues['regCountry']       = $thirdPartyProfile->regCountry;
        $viewValues['regNumber']        = $thirdPartyProfile->regNumber;
        $viewValues['website']          = $thirdPartyProfile->website;
        $viewValues['bPublicTrade']     = $thirdPartyProfile->bPublicTrade;
        $viewValues['stockExchange']    = $thirdPartyProfile->stockExchange;
        $viewValues['tickerSymbol']     = $thirdPartyProfile->tickerSymbol;
        $viewValues['addr1']            = $thirdPartyProfile->addr1;
        $viewValues['addr2']            = $thirdPartyProfile->addr2;
        $viewValues['city']             = $thirdPartyProfile->city;
        $viewValues['country']          = $thirdPartyProfile->country;
        $viewValues['state']            = $thirdPartyProfile->state;
        $viewValues['postcode']         = $thirdPartyProfile->postcode;

        return $viewValues;
    }

    /**
     * Base view value array, used in the templates associated with third party profiles
     *
     * @return array
     */
    public function baseViewViews()
    {
        $returnKeys = [
            'sitePath' => $this->app->sitePath
        ];

        $codeKeys   = $this->app->trans->codeKeys([
            'position',             'select_default',           'profDetail_official_company_name',
            'fldInfo_DBAname',      'legalForm',                'indicates_required_fields',
            'addr1',                'addr2',                    'city',
            'country',              'state',                    'fld_Internal_Owner',
            'fld_Internal_Code',    'postcode',                 'lbl_Name',
            'POCphone1',            'POCphone2',                'profile_type',
            'tpTypeCategory',       'continue_enter_details',   'cancel',
            'go_back_edit',         'continue_review',          'telephone',
            'fld_Department',       'fld_Region',               'email_addr',
            'user_mobile',          'user_fax',                 'regCountry',
            'regNumber',            'website',                  'bPublicTrade',
            'stockExchange',        'tickerSymbol',             'tab_Compnay_Details',
            'do_not_create_cancel', 'nav_next',                 'window_close',
            'main_point_of_contact','fld_Remediation'
            ]);

        foreach ($codeKeys as $key => $value) {
            $returnKeys["lbl_{$key}"] = $value;
        }

        return $returnKeys;
    }

    /**
     * Returns true if there is TPP dependent data
     * TPP dependent data consists of: cases, trainings and attachments, tpAttachs (also known as corporate / 3p docs,
     * and notes
     *
     * @return bool
     */
    public function hasTppData()
    {
        $cases      = $this->CloneWizardModel->getCases();
        $trainings  = $this->CloneWizardModel->getTrainings();
        $tpAttach   = $this->CloneWizardModel->getTpAttach();
        $notes      = $this->CloneWizardModel->getTpNote();

        return (bool)(
            !empty($cases)
        ||  !empty($trainings)
        ||  !empty($tpAttach)
        ||  !empty($notes)
        );
    }

    /**
     * Used with profile selection, searches within current tenantDB for a third party profile name provided by the user
     *
     * @param string $search thirdPartyProfile user input name
     *
     * @see    CloneWizardModel::returnMatches()   searches current tenantDB for thirdPartyProfile with matching name
     * @return object
     */
    public function returnMatches($search)
    {
        return $this->CloneWizardModel->returnMatches($search);
    }
}
