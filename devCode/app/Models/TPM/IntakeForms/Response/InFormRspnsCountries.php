<?php
/**
 * Provides basic read/write access to inFormRspnsCountries table
 */

namespace Models\TPM\IntakeForms\Response;

use Models\BaseLite\RequireClientID;

/**
 * Basic CRUD access to inFormRspnsCountries,  requiring tenantID
 *
 * @keywords tenants, settings, tenants settings
 */
#[\AllowDynamicProperties]
class InFormRspnsCountries extends RequireClientID
{
    protected $tbl = 'inFormRspnsCountries';
    protected $clientIdField = 'tenantID';
    protected $tenantID = null;
    protected $primaryID = null;

    /**
     * Determines whether or not this was instantiated via the REST API
     *
     * @var boolean
     */
    protected $isAPI = false;

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID inFormRspnsCountries.tenantID
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->tenantID = $tenantID;
        $this->primaryID = null;
        $this->logger = \Xtra::app()->log;
        parent::__construct($tenantID);
    }



    /**
     * Updates countries for a given intake form
     *
     * @param integer $inFormRspnsID Intake Form Response ID (A.K.A. ddqID)
     * @param array   $countries     Countries associated with the intake form
     *
     * @return boolean
     */
    public function updateCountries($inFormRspnsID, $countries)
    {
        \Xtra::requireInt($inFormRspnsID);

        // First, wipe the slate of any existing countries for the intake form.
        $this->delete(['tenantID' => $this->tenantID, 'inFormRspnsID' => $inFormRspnsID]);
        if ($countries === 'empty') {
            return true;
        }

        // Now, add any countries that are provided.
        foreach ($countries as $iso) {
            $this->insert(
                [
                'tenantID' => $this->tenantID,
                'inFormRspnsID' => $inFormRspnsID,
                'iso_code' => $iso
                ]
            );
        }
        return true;
    }

    /**
     * Returns available countries for questionID = 'countriesWhereBusinessPracticed'
     *
     * @param integer $inFormRspnsID Intake Form Response ID (A.K.A. ddqID)
     * @param boolean $isLegacy      ddq or instance
     *
     * @return array
     */
    public function getCountriesByDdqID($id, $isLegacy)
    {
        $query = "SELECT iso_code FROM inFormRspnsCountries "
            . "WHERE tenantID = :tID "
            . "AND inFormRspnsID = :id "
            . "AND isLegacy = :isLeg";

        $params[':tID']   = $this->tenantID;
        $params[':id']    = (int)$id;
        $params[':isLeg'] = (int)$isLegacy;
        return $this->DB->fetchIndexedRows($query, $params);
    }

    /**
     * Returns available countries for questionID = 'countriesWhereBusinessPracticed'
     *
     * @param array $question API formatted list of countries
     *
     * @return array
     */
    public function getCountriesByQ($question)
    {
        $c = $question['countries'];
        $rtn = [];
        foreach ($c as $i) {
            $rtn[] = $i['checkedValue'];
        }
        return $rtn;
    }
}
