<?php
/**
 * Add Case Dialog KeyInfo
 *
 * @category AddCase_Dialog
 * @package  Controllers\TPM\AddCase\Subs
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddCase\Subs;

use Models\ThirdPartyManagement\RelationshipType;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ThirdParty;
use Models\SP\ServiceProvider;
use Models\Ddq;

/**
 * Class KeyInfo
 */
#[\AllowDynamicProperties]
class KeyInfo extends AddCaseBase
{
    /**
     * KeyInfo constructor
     *
     * @param int $tenantID tenant ID
     * @param int $caseID   cases.id
     * @param int $tppID    associated thirdPartyProfile.id
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $caseID, protected $tppID = null)
    {
        $this->callback     = 'appNS.addCase.handler';
        $this->template     = 'KeyInfo.tpl';

        parent::__construct($tenantID, $caseID);
    }


    /**
     * Returns the translated dialog title
     *
     * @return string
     */
    public function getTitle()
    {
        return str_replace(
            '{caseName}',
            $this->CasesRecord['caseName'],
            (string) $this->app->trans->codeKey('due_diligence_key_info_form')
        );
    }


    /**
     * Returns view values
     *
     * @return array of view values
     */
    public function getViewValues()
    {
        return array_merge(
            $this->labelsAndListValues(),
            $this->inputValues(),
            $this->principalValues()
        );
    }


    /**
     * Returns an array of user input fields
     *
     * @return array of user input fields
     */
    public function getUserInput()
    {
        return [
            'subStat',              //  Status
            'subType',              //  Relationship Type
            'legalForm',            //  Legal form of company
            'name',                 //  Company name
            'DBAname',              //  Alternate trade name
            'street',               //  Address 1
            'addr2',                //  Address 2
            'city',                 //  City
            'state',                //  Country
            'country',              //  State/Province
            'postCode',             //  Postcodes
            'SBIonPrincipals',      //  Do you want to include...
        ];
    }


    /**
     * Validates user input
     *
     * @return array validation errors
     */
    #[\Override]
    public function validate()
    {
        $this->setUserInput($this->getUserInput());

        $error     = [];
        $trText    = $this->app->trans->group('add_case_dialog');
        $countries = $this->Model->returnCountryList();

        if (!empty($this->inputs['subStat']) && !in_array($this->inputs['subStat'], ['Prospective', 'Current'])) {
            $error[] = $trText['error_subStat'];
        }
        if (empty($this->inputs['name'])) {
            $error[] = $trText['error_subjectName'];
        }
        if (empty($this->inputs['city'])) {
            $error[] = $trText['error_city'];
        }
        if (empty($this->inputs['street'])) {
            $error[] = $trText['error_street'];
        }
        if (empty($this->inputs['country']) || !isset($countries[$this->inputs['country']])) {
            $error[] = $trText['invalid_country'];
        }
        if ($this->CasesRecord['caseType'] === Cases::DUE_DILIGENCE_SBI &&
            !in_array($this->inputs['SBIonPrincipals'], ['Yes', 'No'])) {
            $error[] = $trText['error_sbiOnPrincipals'];
        }

        if (empty($error) && !(new Ddq($this->tenantID))->findByAttributes(['caseID' => $this->caseID])) {
            // the cost time country value = subjectInfoDD country
            (new ServiceProvider())->getServiceProviderPreference(
                $this->tenantID,
                $this->CasesRecord['caseType'],
                $this->inputs['country']
            );
        }

        return $error;
    }


    /**
     * Update the existing subjectInfoDD record
     *
     * @return cases.id
     *
     * @throws \Exception
     */
    public function store()
    {
        try {
            $attributes = [
                'subType'   => $this->inputs['subType'],
                'subStat'   => $this->inputs['subStat'],
                'legalForm' => $this->inputs['legalForm'],
                'name'      => $this->inputs['name'],
                'DBAname'   => $this->inputs['DBAname'],
                'street'    => $this->inputs['street'],
                'addr2'     => $this->inputs['addr2'],
                'city'      => $this->inputs['city'],
                'country'   => $this->inputs['country'],
                'state'     => $this->inputs['state'],
                'postCode'  => $this->inputs['postCode'],
                'SBIonPrincipals' => $this->inputs['SBIonPrincipals'],
            ];
            if (!$this->SubInfoObject->setAttributes($attributes)) {
                throw new \Exception('ERROR: '.$this->SubInfoObject->getErrors());
            }
            if (!$this->SubInfoObject->save()) {
                throw new \Exception('ERROR: '.$this->SubInfoObject->getErrors());
            }
            return $this->caseID;
        } catch (\Exception $e) {
            $this->app->log->debug($e->getFile().' : '.$e->getLine().' : '.$e->getMessage());
            throw new \Exception($this->app->trans->codeKey('update_record_failed'));
        }
    }


    /**
     * Returns an array of view values including code keys & drop down menu data.
     *
     * @return array
     */
    private function labelsAndListValues()
    {
        $trText  = $this->app->trans->group('add_case_dialog');
        $default = [0 =>$trText['select_default']];

        return array_merge($trText, [
            'sitePath'               => $this->app->sitePath,
            'pageName'               => 'KeyInfo',
            'list_subType'           => $default + (new RelationshipType($this->tenantID))->getRelationshipTypes(),
            'list_legalForm'         => $default + $this->Model->getCompanyLegalForms(),
            'list_country'           => $this->Model->returnCountryList(),
            'label_isSBIOnPrincipal' =>
                ($this->CasesRecord['caseType'] === Cases::DUE_DILIGENCE_SBI),
        ]);
    }


    /**
     * Returns array of view values for input fields
     *
     * @return array
     */
    private function inputValues()
    {
        $inputs = [
            'value_subStat'         => $this->SubInfoRecord['subStat'],
            'value_subType'         => $this->SubInfoRecord['subType'],
            'value_legalForm'       => $this->SubInfoRecord['legalForm'],
            'value_name'            => $this->SubInfoRecord['name'],
            'value_DBAname'         => $this->SubInfoRecord['DBAname'],
            'value_street'          => $this->SubInfoRecord['street'],
            'value_addr2'           => $this->SubInfoRecord['addr2'],
            'value_city'            => $this->SubInfoRecord['city'],
            'value_country'         => $this->SubInfoRecord['country'],
            'value_state'           => $this->SubInfoRecord['state'],
            'value_postCode'        => $this->SubInfoRecord['postCode'],
            'value_SBIonPrincipals' => $this->SubInfoRecord['SBIonPrincipals'],
        ];

        if ($this->tppID) {
            $TPP      = (new ThirdParty($this->tenantID))->findById($this->tppID);
            $defaults = [
                'value_legalForm'   =>  $TPP->get('legalForm'),      // Legal Form of Company
                'value_name'        =>  $TPP->get('legalName'),      // Company Name
                'value_DBAname'     =>  $TPP->get('DBAname'),        // Alternate Trade Name
                'value_street'      =>  $TPP->get('addr1'),          // Address 1
                'value_addr2'       =>  $TPP->get('addr2'),          // Address 2
                'value_city'        =>  $TPP->get('city'),           // City
                'value_country'     =>  $TPP->get('country'),        // Country
                'value_state'       =>  $TPP->get('state'),          // State
                'value_postCode'    =>  $TPP->get('postcode'),       // Postcode
            ];
        } else {
            $defaults = [
                'value_country' =>  $this->CasesRecord['caseCountry'],  //  Country
                'value_state'   =>  $this->CasesRecord['caseState'],    //  State
            ];
        }

        foreach ($defaults as $input => $default) {
            $inputs[$input] = empty($inputs[$input])
                ?   $default
                :   $inputs[$input];
        }

        $stateList = $this->Model->returnStateList($inputs['value_country']);

        $inputs['stateList'] = (count($stateList) == 1 && array_column($stateList, 0) == '')
            ?   ['0' => $this->app->trans->codeKey('select_default')]
            :   $stateList;

        return $inputs;
    }


    /**
     * Returns an array of Principals information for view values
     *
     * @return array
     */
    private function principalValues()
    {
        $principalValues = [];

        for ($i = 1; $i <= 10; $i++) {
            $principalValues["valuePrincipal{$i}"]    = $this->SubInfoRecord["principal{$i}"];
            $principalValues["valueRelationship{$i}"] = $this->SubInfoRecord["pRelationship{$i}"];
        }

        return $principalValues;
    }
}
