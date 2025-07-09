<?php
/**
 * Add Case Dialog Model
 *
 * @category AddCase_Dialog
 * @package  Models\TPM\AddCase
 * @keywords SEC-873 & SEC-2844
 */

namespace Models\TPM\AddCase;

use Models\Globals\Region;
use Models\Globals\Geography;
use Models\Globals\Department;
use Models\ThirdPartyManagement\CompanyLegalForm;
use Lib\Services\AppMailer;

/**
 * Class AddCase
 */
#[\AllowDynamicProperties]
class AddCase
{
    /**
     * @var int users.id
     */
    protected $userID;

    /**
     * @var int 3PM tenant ID
     */
    protected $tenantID;

    /**
     * @var string Client database name
     */
    protected $tenantDB;


    /**
     * AddCase constructor.
     *
     * @param int $tenantID logged in tenantID
     */
    public function __construct($tenantID)
    {
        if (is_numeric($tenantID)) {
            $this->tenantID = (int)$tenantID;
            $this->app      = \Xtra::app();
            $this->userID   = $this->app->ftr->user;
            $this->tenantDB = $this->app->DB->getClientDB($this->tenantID);
        } else {
            return false;
        }
    }


    /**
     * Returns an associative array of regions.
     *
     * @return array of regions with id keys and name values for a drop down.
     */
    public function returnRegionList()
    {
        $regions = [];

        foreach ((new Region($this->tenantID))->getUserRegions($this->userID) as $key => $object) {
            $regions[$object['id']] = $object['name'];
        }

        return (count($regions) == 1)
            ?   $regions
            :   ['0' =>  $this->app->trans->codeKey('select_default')] + $regions;
    }


    /**
     * Returns an associative array of countries.
     *
     * @return array of countries with id keys and name values for a drop down.
     */
    public function returnCountryList()
    {
        $geo = Geography::getVersionInstance(null, $this->tenantID);
        $langCode = $this->app->session->launguageCode ?? 'EN_US';
        $countries = $geo->countryList('', $langCode);

        return (count($countries))
            ?   $countries
            :   ['N' =>  $geo->translateCodeKey($langCode)];
    }


    /**
     * Given an iso code, returns an associative array.
     *
     * @param string $isoCode country to search for states.
     *
     * @return array|mixed array of matched states with id keys and name values for a drop down.
     */
    public function returnStateList($isoCode = null)
    {
        $geo = Geography::getVersionInstance(null, $this->tenantID);
        $langCode = $this->app->session->languageCode ?? 'EN_US';
        if ($country = $geo->getLegacyCountryCode($isoCode)
            && ($states = $geo->stateList($country, '', $langCode))
        ) {
            return $states;
        }
        return ['N' => $geo->translateCodeKey($langCode)];
    }


    /**
     * Returns an associative array of departments.
     *
     * @return array of departments with id keys and name values for a drop down.
     */
    public function returnDepartmentList()
    {
        foreach ((new Department($this->tenantID))->getUserDepartments($this->userID) as $department) {
            $indexed[$department['id']] = $department['name'];
        }

        asort($indexed);

        return (count($indexed) == 1)
            ?   $indexed
            :   ['0' =>  $this->app->trans->codeKey('select_default')] + $indexed;
    }


    /**
     * Returns an associative array of company legal form data.
     *
     * @return array of company legal form data with id keys and name values.
     */
    public function getCompanyLegalForms()
    {
        return (new CompanyLegalForm())->getCompanyLegalForms();
    }


    /**
     * Returns case budget & investigation information for a given caseID.
     *
     * @param int $caseID cases.id
     *
     * @refactor reviewConfirm_pt2.php
     *
     * @return mixed
     */
    public function getCaseBudgetInfo($caseID)
    {
        if (is_numeric($caseID)) {
            return $this->app->DB->fetchAssocRow(
                "SELECT c.budgetType, c.budgetAmount, "
                . "c.caseDueDate, s.name AS stageName, i.investigatorName AS spName, c.spProduct "
                . "FROM {$this->tenantDB}.cases AS c "
                . "LEFT JOIN caseStage AS s ON s.id = c.caseStage "
                . "LEFT JOIN {$this->app->DB->spGlobalDB}.investigatorProfile AS i ON i.id = c.caseAssignedAgent "
                . "WHERE c.id = :caseID",
                [
                    ':caseID' => $caseID,
                ]
            );
        }
    }


    /**
     * Returns a boolean indicating the given iso2 country code sanctioned country status.
     *
     * @param string $iso2 country code
     *
     * @return bool true if country is restricted.
     */
    public function isCountrySanctioned($iso2)
    {
        return (Geography::getVersionInstance())->isCountrySanctioned($iso2);
    }


    /**
     * Returns a boolean indicating the given iso2 country code prohibited country status.
     *
     * @param string $iso2 country code
     *
     * @return bool true if country is restricted.
     */
    public function isCountryProhibited($iso2)
    {
        return (Geography::getVersionInstance())->isCountryProhibited($iso2);
    }


    /**
     * Select Caroline Lee  as investigator for Sanctioned countries
     *
     * @return int
     */
    public function getCountrySanctionedCaseInvestigatorUserID()
    {
        $this->app->log->debug($this->app->DB);

        return $this->app->DB->fetchValue(
            "SELECT id FROM " . $this->app->DB->authDB . ".users WHERE userid = 'clee@wwsteele.com'"
        );
    }

    /**
     * Return cases country code
     * @todo: not tested
     *
     * @param int $caseID cases.id
     *
     * @return string|null
     */
    public function getCasesCaseCountry($caseID)
    {
        $CasesRecord = (new Cases($this->tenantID))->getCaseRow($caseID);

        return (is_array($CasesRecord) && isset($CasesRecord['caseCountry']))
            ?   $CasesRecord['caseCountry']
            :   null;
    }


    /**
     * Return subjectInoDD country code
     * @todo: not tested
     *
     * @param int $caseID cases.id
     *
     * @return string|null
     */
    public function getSubjectInfoDDCountry($caseID)
    {
        if (!empty($this->getCasesCaseCountry($caseID))) {
            $SubInfoObject = (new SubjectInfoDD($this->tenantID))->findByAttributes(
                [
                    'caseID'    => $caseID,
                    'clientID'  => $this->tenantID,
                ]
            );

            $SubInfoRecord = $SubInfoObject->getAttributes();

            return (is_object($SubInfoRecord) && property_exists($SubInfoRecord, 'country'))
                ?   $SubInfoRecord->country
                :   null;
        }
    }
}
