<?php
/**
 * Abstract class for basic function implementations for tiles that retrieve Third Party Profiles
 *
 * @keywords dashboard, data ribbon
 */

namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Class TpTileBase
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
abstract class TpTileBase extends DataTileBase
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
     * Regions user is allowed to access
     *
     * @var array
     */
    protected $userRegions = [];

    /**
     * Departments user is allowed to access
     *
     * @var array
     */
    protected $userDepartments = [];

    /**
     * Main table and alias for use in SQL
     *
     * @var string
     */
    protected $selectTable = 'thirdPartyProfile AS tPP';

    /**
     * Default limit to number of items that can be returned
     *
     * @var int
     */
    protected $limit = 50;

    /**
     * Initialize data
     */
    public function __construct()
    {
        parent::__construct();

        $this->loadRestrictions();
        $this->setDefaultWhere();
        $this->setWhere();
        $this->setDefaultJoins();
        $this->setJoins();
    }

    /**
     * Set default WHERE parameters for SQL.
     *
     * @return void
     */
    public function setDefaultWhere()
    {
        $where = [
            "AND: tPP.clientID = :tenantID",
            //"AND: c.caseStage <> '13'",
            //"AND: c.caseStage IN(-1,0,2,1,3,4,5,6,7,8,10)",
        ];
        $whereParams = [
            ':tenantID' => $this->tenantID
        ];

        // Duplicate
        if ($this->app->ftr->isLegacyClientManager()) {
            // View all regions if no regions are specified
            if (!empty($this->userRegions)) {
                $where[] = 'AND: tPP.region IN(' . implode(',', $this->userRegions) . ')';
            }

            // View all departments if no departments are specified
            if (!empty($this->userDepartments)) {
                // Currently department id = 0 (No Department) is always included in search results
                $where[] = 'AND: tPP.department IN(0,' . implode(',', $this->userDepartments) . ')';
            }
        } elseif ($this->app->ftr->isLegacyClientUser()) {
            // Only show cases assigned to current user
            $where[] = "tPP.ownerID = '" . $this->app->ftr->user . "'";
        }

        $this->where       = $where;
        $this->whereParams = $whereParams;
    }

    /**
     * (TP tiles currently use the Search3pData class)
     * Override to set custom JOIN tables for SQL.
     *
     * @return void
     */
    public function setDefaultJoins()
    {
        if (\Xtra::usingGeography2()) {
            $countryOn = '(ctry.legacyCountryCode = tPP.country '
                . 'OR ctry.codeVariant = tPP.country OR ctry.codeVariant2 = tPP.country) '
                . 'AND (ctry.countryCodeID > 0 OR ctry.deferCodeTo IS NULL)';
        } else {
            $countryOn = 'ctry.legacyCountryCode = tPP.country';
        }
        $joins = [
            'LEFT JOIN ' . $this->app->DB->isoDB . ".legacyCountries AS ctry ON $countryOn",
            'LEFT JOIN ' . $this->app->DB->authDB . '.users AS u ON u.id = tPP.ownerID',
            'LEFT JOIN region AS rgn ON rgn.id = tPP.region',
            'LEFT JOIN department AS dept ON dept.id = tPP.department',
        ];

        // Only JOIN risk tables if feature is enabled for tenant
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $joins[] = 'LEFT JOIN riskAssessment AS ra ON
                (ra.tpID = tPP.id AND ra.model = tPP.riskModel AND ra.status = \'current\')';
            $joins[] = 'LEFT JOIN riskTier AS rt ON rt.id = ra.tier';
            $joins[] = 'LEFT JOIN riskModelTier AS rMT ON (rMT.model = ra.model AND rMT.tier = rt.id)';
        }

        $this->joins = $joins;
    }
    /**
     * Override this to set additional WHERE clauses if needed
     *
     * @return void
     */
    #[\Override]
    public function setWhere()
    {
        return;
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
     * Pull together parts of SQL and build the query. Instead of using SQL_CALC_FOUND_ROWS we are going
     * to perform a separate SELECT COUNT to see if that is more efficient than SQL_CALC_FOUND_ROWS.
     *
     * @param string $query SQL query for getting count of 3P Profiles
     *
     * @return string
     */
    private function buildCountQuery($query)
    {
        $queryParts = explode(PHP_EOL, $query);
        $queryParts[0] = 'SELECT COUNT(DISTINCT tPP.id)';
        if ($idx = array_search('GROUP BY dbid', $queryParts)) {
            unset($queryParts[$idx]);
        }
        return implode(PHP_EOL, $queryParts);
    }

    /**
     * Pull together parts of SQL and build the query
     *
     * @return string
     */
    protected function buildQueryOld()
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
                // First WHERE statement must be AND
                $sql .= $current[1];
            } else {
                // Make sure WHERE statement conjunctions are allowed
                if (in_array(strtoupper((string) $current[0]), ['AND', 'OR'])) {
                    $sql .= strtoupper((string) $current[0]) . ' ' . $current[1];
                }
            }

            $sql .= PHP_EOL;
        }

        // If returning only 1 query field, probably not dbid
        if (count($this->getQueryFields()) > 1) {
            // Only show a TPP once
            $sql .= 'GROUP BY dbid' . PHP_EOL;
        }

        return $sql;
    }

    /**
     * Pull together parts of SQL and build the query
     *
     * @return string
     */
    protected function buildQuery()
    {
        // Select section of query with fields to return
        $sql = 'SELECT ';
        $sql .= 'DISTINCT tPP.id, ';
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
                // First WHERE statement must be AND
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
     * List of fields to be displayed from returned case data
     *
     * @return array
     */
    public function getDisplayFields()
    {
        return [
            [
                'text'      => 'TP Number',
                'dataField' => 'tpNum',
                'width'     => 100
            ],
            [
                'text'      => 'Company Name',
                'dataField' => 'companyName',
                'width'     => 300
            ],
            [
                'text'      => 'Region',
                'dataField' => 'region',
                'width'     => 100
            ],
            [
                'text'      => 'Department',
                'dataField' => 'department',
                'width'     => 100
            ],
            [
                'text'      => 'Owner',
                'dataField' => 'owner',
                'width'     => 175
            ]
        ];
    }

    /**
     * Get array of fields for use in DB query
     *
     * @return array
     */
    protected function getQueryFields()
    {
        $countryField = \Xtra::usingGeography2()
            ? 'IFNULL(ctry.dispayAs, ctry.legacyName)'
            : 'ctry.legacyName';
        $fields = [
            'tPP.id AS `dbid`',
            'tPP.userTpNum AS `tpNum`',
            'tPP.legalName AS `companyName`',
            'rgn.name AS `region`',
            'dept.name AS `department`',
            'u.userName AS `owner`',
            $countryField . ' AS `country`',
        ];

        // Only use risk tables if feature is enabled for tenant
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $fields[] = 'IF(rt.tierName IS NULL, \'&nbsp;\', rt.tierName) AS `risk`';
            $fields[] = 'IF(rt.tierName IS NULL, 101, ra.normalized) AS `riskRate`';
        }

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
                'name' => 'tpNum',
                'type' => 'string'
            ],
            [
                'name' => 'companyName',
                'type' => 'string'
            ],
            [
                'name' => 'owner',
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
        ];

        // Only use risk tables if feature is enabled for tenant
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $fieldTypes[] = [
                'name' => 'risk',
                'type' => 'string'
            ];
            $fieldTypes[] = [
                'name' => 'riskRate',
                'type' => 'number'
            ];
        }

        return $fieldTypes;
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
        $countQuery = $this->buildCountQuery($query);

        // Set limit on query for display.
        $query .= 'LIMIT ' . $this->limit . PHP_EOL;

        $items = $this->db->fetchAssocRows($query, $this->whereParams);
        $total = $this->db->fetchValue($countQuery, $this->whereParams);

        // temp save original queries so we can verify counts match
        // $queryOld = $this->buildQueryOld();
        // $queryOld .= 'LIMIT ' . $this->limit . PHP_EOL;
        // $itemsOld = $this->db->fetchAssocRows($queryOld, $this->whereParams);
        // $totalOld = $this->db->pdo->query('SELECT FOUND_ROWS();')->fetch(\PDO::FETCH_COLUMN);

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
        return '/cms/thirdparty/thirdparty_home.sec?id={{ id }}&tname=thirdPartyFolder&swtch={{ list }}';
    }

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

        // TP tiles will default to popping up a table with a list of TPs.
        $list->setClickType(DataTileBase::CLICK_TABLE);

        return $list;
    }
}
