<?php
/**
 * Abstract class for basic function implementations for tiles that retrieve Cases
 *
 * @keywords dashboard, data ribbon
 */

namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ThirdPartyManagement\Cases;
use Models\TPM\CaseTypeClient;

/**
 * Class CaseTileBase
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
abstract class CaseTileBase extends DataTileBase
{
    /**
     * Array of where conditions to use in SQL
     *
     * @var array
     */
    protected $where = [];

    /**
     * Array of parameters to pass to PDO for prepared SQL
     *
     * @var array
     */
    protected $whereParams = [];

    /**
     * Array of table joins for use in SQL
     *
     * @var array
     */
    protected $joins = [];

    /**
     * Case types available for current Tenant
     *
     * @var array
     */
    protected $caseTypes = [];

    /**
     * Main table and alias for use in SQL
     *
     * @var string
     */
    protected $selectTable = 'cases AS c';

    /**
     * Default limit to number of items that can be returned
     *
     * @var int
     */
    protected $limit = 50;

    /**
     * Initialize base class
     */
    public function __construct()
    {
        parent::__construct();

        $clientCaseTypes = new CaseTypeClient($this->tenantID);
        $this->caseTypes = $clientCaseTypes->getClientCaseTypes();

        $this->loadRestrictions();
        $this->setDefaultWhere();
        $this->setWhere();
        $this->setDefaultJoins();
        $this->setJoins();
    }

    /**
     * Set default JOIN statements for Case queries
     *
     * @return void
     */
    protected function setDefaultJoins()
    {
        if (\Xtra::usingGeography2()) {
            $countryOn = '(ctry.legacyCountryCode = c.caseCountry '
                . 'OR ctry.codeVariant = c.caseCountry OR ctry.codeVariant2 = c.caseCountry) '
                . 'AND (ctry.countryCodeID > 0 OR ctry.deferCodeTo IS NULL)';
        } else {
            $countryOn = 'ctry.legacyCountryCode = c.caseCountry';
        }
        $joins = [
            'LEFT JOIN thirdPartyProfile AS tPP ON tPP.id = c.tpID',
            'LEFT JOIN caseStage AS stg ON stg.id = c.caseStage',
            'LEFT JOIN ' . $this->app->DB->authDB . '.users AS u ON u.userid = c.requestor',
            'LEFT JOIN ' . $this->app->DB->isoDB . ".legacyCountries AS ctry ON $countryOn",
            'LEFT JOIN region AS rgn ON rgn.id = c.region',
            'LEFT JOIN department AS dept ON dept.id = c.dept',
            'LEFT JOIN ddq AS ddq ON ddq.caseID = c.id'
        ];
        $this->joins = $joins;
    }


    /**
     * Override this to JOIN additional tables if needed
     *
     * @return void
     */
    #[\Override]
    public function setJoins()
    {
        return;
    }

    /**
     * Set default WHERE statements for use in Case queries
     *
     * @return void
     */
    protected function setDefaultWhere()
    {
        $where = [
            "AND: c.clientID = :tenantID",
        ];
        $whereParams = [
            ':tenantID' => $this->tenantID
        ];

        if ($this->app->ftr->isLegacyClientManager()) {
            // View all regions if no regions are specified
            if (!empty($this->userRegions)) {
                $where[] = 'AND: c.region IN(' . implode(',', $this->userRegions) . ')';
            }

            // View all departments if no departments are specified
            if (!empty($this->userDepartments)) {
                // Currently department id = 0 (No Department) is always included in search results
                $where[] = 'AND: c.dept IN(0,' . implode(',', $this->userDepartments) . ')';
            }
        } elseif ($this->app->ftr->isLegacyClientUser()) {
            // Only show cases assigned to current user
            $where[] = "u.id = {$this->app->ftr->user}";

            // Users should have a region defined before adding this
            if (!empty($this->userRegions)) {
                $where[] = "OR: c.region IN (" . implode(',', $this->userRegions) . ") "
                    . "AND c.caseStage = :qualificationStage AND c.requestor = ''";
                $whereParams[':qualificationStage'] = Cases::QUALIFICATION;
            }
        }

        $this->where       = $where;
        $this->whereParams = $whereParams;
    }

    /**
     * Get array of fields for use in DB query
     *
     * @return array
     */
    protected function getQueryFields()
    {
        if (\Xtra::usingGeography2()) {
            $countryNameField = 'IFNULL(ctry.displayAs, ctry.legacyName)';
        } else {
            $countryNameField = 'ctry.legacyName';
        }
        $fields = [
            'c.id AS `dbid`',
            'c.userCaseNum AS `caseNum`',
            'c.caseName AS `caseName`',
            'IFNULL(tPP.legalName, CONCAT("* ", c.caseName)) AS `companyName`',
            'c.caseCountry AS `iso2`',
            'c.tpID',
            'c.caseType',
            'stg.name AS `stage`',
            'u.userName AS `requester`',
            $countryNameField . ' AS `country`',
            'rgn.name AS `region`',
            'dept.name AS `department`',
            'ddq.origin AS `source`',
        ];

        return $fields;
    }

    /**
     * List of fields that will be returned and the type of data contained. Should maintain parity with getQueryFields
     *
     * @return array
     */
    protected function getFieldTypes()
    {
        $fieldTypes = [
            [
                'name' => 'dbid',
                'type' => 'number'
            ],
            [
                'name' => 'caseNum',
                'type' => 'string'
            ],
            [
                'name' => 'caseName',
                'type' => 'string'
            ],
            [
                'name' => 'companyName',
                'type' => 'string'
            ],
            [
                'name' => 'iso2',
                'type' => 'string'
            ],
            [
                'name' => 'tpID',
                'type' => 'number'
            ],
            [
                'name' => 'caseType',
                'type' => 'string'
            ],
            [
                'name' => 'stage',
                'type' => 'string'
            ],
            [
                'name' => 'requester',
                'type' => 'string'
            ],
            [
                'name' => 'country',
                'type' => 'string'
            ],
            [
                'name' => 'region',
                'type' => 'string'
            ],
            [
                'name' => 'department',
                'type' => 'string'
            ],
            [
                'name' => 'source',
                'type' => 'string'
            ],
        ];

        return $fieldTypes;
    }

    /**
     * Pull together parts of SQL and build the query
     *
     * @return string
     */
    protected function buildQuery()
    {
        // Select section of query with fields to return
        $sql = 'SELECT SQL_CALC_FOUND_ROWS ';
        $sql .= implode(', ', $this->getQueryFields());
        $sql .= PHP_EOL;

        // Table to use for SELECT and any JOINs
        $sql .= 'FROM ' . $this->selectTable . PHP_EOL;
        foreach ($this->joins as $join) {
            $sql .= $join;
            $sql .= PHP_EOL;
        }

        // Query parameters and WHERE clauses
        $sql .= 'WHERE ';
        foreach ($this->where as $cnt => $w) {
            $current = explode(': ', (string) $w);

            // If delimiter was not found, then we default to AND and use the whole string
            if (count($current) != 2) {
                $current[0] = 'AND';
                $current[1] = $w;
            }

            if ($cnt == 0) {
                // First WERE statement must be AND
                $sql .= $current[1];
            } else {
                // Make sure WHERE statement conjunctions are allowed
                if (in_array(strtoupper((string) $current[0]), ['AND', 'OR'])) {
                    $sql .= strtoupper((string) $current[0]) . ' ' . $current[1];
                }
            }

            $sql .= PHP_EOL;
        }

        return $sql;
    }


    /**
     * Retrieve SQL query results
     *
     * @return array
     */
    protected function getResults()
    {
        $timer = false;
        if ($timer) {
            $mtime = microtime();
            $mtime = explode(' ', $mtime);
            $mtime = $mtime[1] + $mtime[0];
            $tstart = $mtime;
        }

        $query = $this->buildQuery();

        // Set limit on query
        $query .= 'LIMIT ' . $this->limit . PHP_EOL;

        $found = $this->db->fetchAssocRows($query, $this->whereParams);
        $total = $this->db->pdo->query('SELECT FOUND_ROWS();')->fetch(\PDO::FETCH_COLUMN);

        $items = [];
        if (isset($found) && is_array($found)) {
            foreach ($found as $k => $case) {
                if ($case['caseType'] == 0) {
                    // Case type 0 will be allowed but blank
                    $current = array_merge($case, ['caseType' => '']);

                    // Assign case data to new list of cases to be returned
                    $items[] = $current;
                } else {
                    // Only show if case type exists for tenant
                    if (isset($this->caseTypes['full'][$case['caseType']])) {
                        // Merge current case with Case Type common name
                        $current
                            = array_merge($case, ['caseType' => $this->caseTypes['full'][$case['caseType']]['name']]);

                        // Assign case data to new list of cases to be returned
                        $items[] = $current;
                    }
                }
            }
        }

        if ($timer) {
            $mtime = microtime();
            $mtime = explode(' ', $mtime);
            $mtime = $mtime[1] + $mtime[0];
            $tend = $mtime;
            $totalTime = ($tend - $tstart);
            $totalTime = sprintf("%2.4f s", $totalTime);
        }

        return compact('items', 'total');
    }

    /**
     * Get URL for link redirects
     *
     * @return string
     */
    #[\Override]
    protected function getUrl()
    {
        return '/cms/case/casehome.sec?id={{ id }}&tname=casefolder&swtch={{ list }}';
    }

    /**
     * List of fields to be displayed from returned case data
     *
     * @return array
     */
    abstract public function getDisplayFields();

    /**
     * Return results from query with additional data needed for Data Tile display
     *
     * @return DataTileList
     */
    #[\Override]
    public function getList()
    {
        $list = new DataTileList();

        $items = $this->getResults();
        $list->setItems($items['items']);
        $list->setCount($items['total']);

        $display = $this->getDisplayFields();
        $list->setDisplayFields($display);

        $fieldTypes = $this->getFieldTypes();
        $list->setFieldTypes($fieldTypes);

        $list->setUrl($this->getUrl());

        // Case tiles will default to popping up a table with a list of cases.
        $list->setClickType(DataTileBase::CLICK_TABLE);

        return $list;
    }
}
