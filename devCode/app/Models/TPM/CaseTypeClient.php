<?php
/**
 * TenantIdStrict model for CaseTypeClient
 *
 * @note code generated with \Models\Cli\ModelRules\ModelRulesGenerator.php
 */

namespace Models\TPM;

/**
 * TenantIdStrict model for CaseTypeClient CRUD
 */
#[\AllowDynamicProperties]
class CaseTypeClient extends \Models\Base\ReadWrite\TenantIdStrict
{
    /**
     * @var string name of table
     */
    protected $table = 'caseTypeClient';

    /**
     * @var string field name of the tenant ID
     */
    protected $tenantIdField = 'clientID';


    /**
     * return array gettable/settable attributes w/validation rules
     *
     * comment out any attribute you wish to hide from both read & write access
     * keeping this list as small as possible will save some memory when retrieving rows
     *
     * @param string $context not functional, but would allow for future conditional attributes/validation
     *
     * @return array
     */
    #[\Override]
    public static function rulesArray($context = '')
    {
        return [
            'id' => 'db_int',
            'caseTypeID' => 'db_int|required', // caseType.id
            'clientID' => 'db_int|required', // clientProfile.id
            'name' => 'max_len,50|required', // client name for investigation
            'abbrev' => 'max_len,10', // abbreviation for this name
            'investigationType' => 'max_len,13|contains, due_diligence', // Type of investigation
            'displayOption'
                => 'max_len,19|contains, manualOnly questionnaireOnly autoOnly manualQuestionnaire all inactive',
        ];
    }

    /**
     * configure other rules for attributes
     *
     * @param string $context allows for conditional rules (in future implementations)
     *
     * @return void
     */
    #[\Override]
    protected function loadRulesAdditional($context = '')
    {

        // set defaults, key--> column name, value--> value or functionName()
        $this->rulesDefault = [
            'name' => '',
            'abbrev' => '',
            'investigationType' => 'due_diligence',
            'displayOption' => null,
        ];
        $this->rulesNoupdate = [
            'id',
            'caseTypeID',
            'clientID',
        ];
    }


    /**
     * Get Case Types for the client.
     *
     * @return array $clientCaseTypes
     */
    public function getClientCaseTypes()
    {
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT DISTINCT(caseTypeID), name, abbrev FROM $clientDB.caseTypeClient "
            . "WHERE clientID = '0' OR clientID = :clientID";
        $caseTypesObj = $this->DB->fetchObjectRows($sql, [':clientID' => $this->clientID]);
        $clientCaseTypes = ['id'     => [], 'abbrev' => [], 'name'   => [], 'full'   => []];
        if ($caseTypesObj) {
            foreach ($caseTypesObj as $c) {
                $clientCaseTypes['id'][] = $c->caseTypeID;
                $clientCaseTypes['caseTypeID'][] = $c->caseTypeID;
                $clientCaseTypes['abbrev'][] = strtolower((string) $c->abbrev);
                $clientCaseTypes['name'][] = strtolower((string) $c->name);
                $clientCaseTypes['full'][$c->caseTypeID] = ['id'       => $c->caseTypeID, 'abbrev'   => $c->abbrev, 'lcabbrev' => strtolower((string) $c->abbrev), 'name'     => $c->name, 'lcname'   => strtolower((string) $c->name)];
            }
        }
        return $clientCaseTypes;
    }

    /**
     * Refactor of Legacy @see public_html/cms/includes/php/funcs.php fGetCaseTypeClient()
     *
     * Given a case type id, returns its name & abbreviation
     *
     * @param integer $caseTypeID Case type
     *
     * @return array
     */
    public function getCaseTypeClient($caseTypeID)
    {
        \Xtra::requireInt($caseTypeID);

        $clientDB = $this->DB->getClientDB($this->clientID);
        $row = $this->DB->fetchAssocRow(
            "SELECT name, abbrev\n"
            . "FROM $clientDB.caseTypeClient\n"
            . "WHERE caseTypeID = :caseTypeID AND clientID = :clientID",
            [':caseTypeID' => $caseTypeID, ':clientID' => $this->clientID]
        );
        if (empty($row)) {
            $row = $this->DB->fetchAssocRow(
                "SELECT name, abbrev\n"
                . "FROM $clientDB.caseType\n"
                . "WHERE id = :caseTypeID",
                [':caseTypeID' => $caseTypeID]
            );
        }

        return $row;
    }

    /**
     * Return the client case type by specified format
     *
     * @param mixed  $type   Value to be checked
     * @param string $format What format $type is (id, abbrev, name)
     *
     * @return mixed Return value if exists, else false.
     */
    public function getTypeByFormat(mixed $type, $format = 'id')
    {
        $caseTypes = $this->getClientCaseTypes();
        $type = strtolower((string) $type);
        foreach ($caseTypes['full'] as $c) {
            if ($type == $c['id'] || $type == $c['lcabbrev'] || $type == $c['lcname']) {
                if ($format == 'id' || $format == 'caseTypeID') {
                    return $c['id'];
                } elseif ($format == 'abbrev' || $format == 'abbv') {
                    return $c['abbrev'];
                } elseif ($format == 'name') {
                    return $c['name'];
                } else {
                    return $c;
                }
            }
        }
        return false;
    }



    /**
     * Retrieve case scopes for a tenant
     *
     * @see Originally public_html/cms/includes/php/funcs_sesslists.php's setCaseTypeClientList method.
     *      Record-gathering only (session-setting will happen separately on the part of the caller).
     *
     * @return array
     */
    public function getCaseScopeList()
    {
        $scopes = [];
        $clientDB = $this->DB->getClientDB($this->clientID);
        $sql = "SELECT caseTypeID, name FROM $clientDB.caseTypeClient\n"
            . "WHERE clientID = :clientID AND investigationType = 'due_diligence' ORDER BY caseTypeID ASC";
        if (!($scopes = $this->DB->fetchKeyValueRows($sql, [':clientID' => $this->clientID]))) {
            if (!($scopes = $this->DB->fetchKeyValueRows($sql, [':clientID' => 0]))) {
                return [];
            }
        }
        return $scopes;
    }
}
