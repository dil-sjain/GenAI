<?php
/**
 * Provide acess to Subscriber related lists, like database names and client names//ids
 */

namespace Models\TPM;

use Lib\Support\Format;

/**
 * Provide acess to Subscriber related lists, like database names and client names//ids
 *
 * @keywords subscriber list, database list, client list
 */
#[\AllowDynamicProperties]
class SubscriberLists
{
    /**
     * @const integer ClientID of template database
     */

    // phpcs:disable
    // @todo consts should be ALLCAPS
    public const NewTemplateClientID = 2000000000;
    public const NEW_TEMPLATE_DB = 'cms_cid000'; // better than using clientID lookup
    // phpcs:enable

    /**
     * @var string primary table for queries
     */
    protected $tbl = '';

    /**
     * @var object Instance of MySqlPdo
     */
    protected $DB = null;

    /**
     * Contructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->DB = \Xtra::app()->DB;
        $this->tbl = $this->DB->authDB . '.clientDBlist';
    }

    /**
     * Read protected property
     *
     * @param string $prop Property name
     *
     * @return mixed value of property or null
     */
    public function __get($prop)
    {
        $rtn = null;
        if (is_string($prop) && property_exists($this, $prop)) {
            // restrict what can be read
            switch ($prop) {
                case 'tbl':
                    $rtn = $this->$prop;
                    break;
            }
        }
        return $rtn;
    }

    /**
     * Compile list of existing subscriber database names
     *
     * CAN NOT DEPEND ON clientDBlist to be reliable
     * excludes [prefix_]cms_global and [prefix_]cms_sp_global
     *
     * @param boolean $excludeTemplate If true, don't enclude
     *
     * @return array String array client db names
     */
    public function getSubscriberDatabases($excludeTemplate = false)
    {
        $srch = str_replace('_', '\_', (string) $this->DB->authDB).'%';
        $sql = "SHOW DATABASES LIKE '$srch'";
        $tmp = $this->DB->fetchValueArray($sql);
        $dbs = [$this->DB->authDB];
        $templateDB = '';
        if ((bool)$excludeTemplate) {
            $templateDB = $this->DB->dbPrefix . self::NEW_TEMPLATE_DB;
        }
        foreach ($tmp as $db) {
            // exclude true _global and _sp
            if (preg_match('/(_global|_sp)$/', (string) $db)) {
                continue;
            }
            if (preg_match('/cms_cid(\d+)$/', (string) $db, $match) && $db != $templateDB) {
                $dbs[] = $db;
            }
        }
        sort($dbs, SORT_NATURAL);
        return $dbs;
    }

    /**
     * Get subscribers with existing database in current environment
     *
     * @param string $whereCond      WHERE clause for query of clientDBlist
     * @param array  $whereParams    Assoc array of placeholder value pairs for $whereCond
     * @param array  $exclClients    Array of clientIDs to exlclude from result
     * @param array  $exclDatabases  Array of database names to exclude from result
     * @param bool   $inclCasePrefix Include client's case prefix if true
     *
     * @note it is possible for subscribers to exist in cms.clientDBList but not have clientProfile
     *
     * @return array of subscriber objects ::cid, ::name and ::db
     */
    public function getSubscribers(
        $whereCond = '1',
        $whereParams = [],
        $exclClients = [],
        $exclDatabases = [],
        $inclCasePrefix = true
    ) {
        // negate invalid arguments
        if (!is_string($whereCond)) {
            $whereCond = '1';
        }
        if (!is_array($whereParams)) {
            $whereParams = [];
        }
        if (!is_array($exclClients)) {
            $exclClients = [];
        }
        if (!is_array($exclDatabases)) {
            $exclDatabases = [];
        }

        $subscribers = [];
        $sql = "SELECT clientID AS cid, clientName AS name, DBname AS db\n"
            . "FROM $this->tbl\n"
            . "WHERE $whereCond ORDER BY clientName ASC";
        if ($rows = $this->DB->fetchObjectRows($sql, $whereParams)) {
            $dbs = $this->getSubscriberDatabases();
            foreach ($rows as $row) {
                // validate subscriber db exists in this environment
                // and is not an excluded clientID nor an excluded db
                // and clientProfile exists
                $e_db = $this->DB->esc($row->db);
                $sql = "SELECT id, caseUserNumPrefix FROM $e_db.clientProfile WHERE id = :cid";
                if (in_array($row->db, $dbs)
                    && !in_array($row->db, $exclDatabases)
                    && !in_array($row->cid, $exclClients)
                    && ($cp = $this->DB->fetchAssocRow($sql, [':cid' => $row->cid]))
                ) {
                    if ($inclCasePrefix) {
                        $row->caseUserNumPrefix = $cp['caseUserNumPrefix'];
                    }
                    $subscribers[] = $row;
                }
            }
        }
        return $subscribers;
    }

    /**
     * Get active subscribers. Excludes template client.
     *
     * @return array of subscriber objects ::cid, ::name and ::db
     */
    public function getActiveSubscribers()
    {
        return $this->getSubscribers("status = 'active'", [], [self::NewTemplateClientID]);
    }

    /**
     * Get key value list of active clientID keys and client names and db names. Excludes template client.
     *
     * @return array of subscriber objects ::cid, ::name and ::db
     */
    public function getActiveClientIDkeyAndClientDataVals()
    {
        $tenants = $this->getSubscribers("status = 'active'", [], [self::NewTemplateClientID]);
        $rtn = [];
        if ($tenants = json_decode(json_encode($tenants), true)) {
            foreach ($tenants as $tenant) {
                $rtn[$tenant['cid']] = [
                    'db' => $tenant['db'],
                    'name' => $tenant['name'],
                    'caseUserNumPrefix' => $tenant['caseUserNumPrefix']
                ];
            }
        }
        return $rtn;
    }

    /**
     * Get key value list of active clientIDs and db names. Excludes template client.
     *
     * @return array of subscriber objects ::cid, ::name and ::db
     */
    public function getActiveClientIDandDbKeyVals()
    {
        $tenants = $this->getSubscribers("status = 'active'", [], [self::NewTemplateClientID]);
        $rtn = [];
        if ($tenants = json_decode(json_encode($tenants), true)) {
            foreach ($tenants as $tenant) {
                $rtn[$tenant['cid']] = $tenant['db'];
            }
        }
        return $rtn;
    }

    /**
     * Get subscriber list of cids and names using search on clientName col
     * verified against existing databases.
     *
     * @param string  $search          A string to search the clientName col.
     * @param integer $currentClientID Current clientID is excluded from search results
     *
     * @return assoc array with 'cid' and 'name' keys
     */
    public function getSwitchSubscriberList($search, $currentClientID)
    {
        $subscribers = [];
        if (strlen($search) >= 2) {
            $cond = "status = 'active' AND clientName LIKE :search";
            $params = [':search' => '%' . $this->DB->escapeWildcards($search) . '%'];
            $exclClients = [$currentClientID, self::NewTemplateClientID];
            $rows = $this->getSubscribers($cond, $params, $exclClients);
            foreach ($rows as $row) {
                $subscribers[] = [
                    "cid" => $row->cid,
                    "name" => $row->name,
                ];
            }
        }
        return $subscribers;
    }

    /**
     * Get list of clientID and client Names for drop-down options
     *
     * @param boolean $activeOnly   exclude inactive clients
     * @param boolean $showClientID prefix name with clientID
     * @param boolean $vtOptions    output as vtOptions for select tab
     *
     * @return array assoc array elements or v/t value/text for options
    */
    public function clientList($activeOnly = true, $showClientID = true, $vtOptions = true)
    {
        $method =  ($activeOnly) ? 'getActiveSubscribers' : 'getSubscribers';
        $records = $this->$method();
        $items = [];
        foreach ($records as $rec) {
            // drop 'db' property
            $name = ($showClientID) ? "($rec->cid) $rec->name" : $rec->name;
            $items[] = [$rec->cid => $name];
        }
        if ($vtOptions) {
            return Format::prepSelectTagVtOptions($items);
        } else {
            return $items;
        }
    }

    /**
     * Get list of clientID and client Names for drop-down options
     * Formatted as v/t pairs for select tag.
     *
     * @param boolean $showClientID prefix name with clientID
     *
     * @return array elements are v/t value/text for options
    */
    public function activeClientListVtOptions($showClientID = false)
    {
        return $this->clientList(true, $showClientID, true);
    }

    /**
     * Get the list of active Tenants filtering by ignoreQC
     *
     * @param bool $ignoreQC do not include QC tenants in the count
     *
     * @return array
     */
    public function getActiveTenantList($ignoreQC = true)
    {
        $where = $ignoreQC ? "status = 'active' AND tenantTypeID = 1 " : "status = 'active' ";
        return $this->getSubscribers($where, [], [], [], false);
    }
}
