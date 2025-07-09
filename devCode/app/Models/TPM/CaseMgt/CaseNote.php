<?php
/**
 * Work with Case Notes table
 *
 * @keywords case, case notes, model
 */

namespace Models\TPM\CaseMgt;

use Models\Base\ReadWrite\TenantIdStrict;

/**
 * TenantIdStrict model for CaseNote CRUD
 */
class CaseNote extends TenantIdStrict
{
    /**
     * @var int The current client ID
     */
    protected $clientID = 0;

    /**
     * @var string Table containing Cases
     */
    protected $table = 'caseNote';

    /**
     * @var \Skinny\Skinny App instance
     */
    protected $app = null;

    /**
     * @var \FeatureACL FeatureACL instance
     */
    protected $ftr = null;

    /**
     * @var \Lib\Database\MySqlPdo $DB
     */
    protected $DB = null;

    /**
     * @var Text Translation instance
     */
    protected $tr = null;
    /**
     * @var string field name of the tenant ID
     */
    protected $tenantIdField = 'clientID';

    /**
     * CaseNotes constructor.
     *
     * @param integer $tenantID Current client id
     * @param array   $params   Configuration
     *
     * @return void
     */
    public function __construct($tenantID, array $params = [])
    {
        $this->app = \Xtra::app();
        $this->tr = $this->app->trans;
        $this->ftr = $this->app->ftr;
        parent::__construct($tenantID, $params);
    }

    /**
     * Return array gettable/settable attributes w/validation rules
     *
     * @param string $context allows for conditional rules (in future implementations)
     *
     * @return array
     */
    public static function rulesArray($context = '')
    {
        $rules = [
            'id'                  => 'db_int',
            'clientID'            => 'db_int|required',
            'caseID'              => 'db_int',
            'noteCatID'           => 'db_int',
            'ownerID'             => 'db_int',
            'created'             => 'db_datetime',
            'subject'             => 'max_len,255',
            'note'                => 'max_len,65535',
            'bInvestigator'       => 'db_tinyint',  // flag investigator note
            'bInvestigatorCanSee' => 'db_tinyint',  // Investigator can see client note
            'qID'                 => 'max_len,50',  // ddq: onlineQuestions.questionID
            'nSect'               => 'contains,,rScope,rQuestion,rRedFlag,rReview'
                                                        // Specialized Note Sections Identifier
        ];

        return $rules;
    }

    /**
     * Get array with list of case notes
     *
     * @param int    $caseID  ID of case notes should be associated with
     * @param int    $limit   Limit number of notes returned
     * @param int    $offset  Notes list offset if limiting return
     * @param string $sortBy  Column used to order notes
     * @param string $sortDir Direction to sort by (ASC|DESC)
     *
     * @return array
     */
    public function getList($caseID, $limit = 0, $offset = 0, $sortBy = 'subject', $sortDir = 'ASC')
    {
        // Make sure sort direction is valid
        $allowedSortDir = ['ASC', 'DESC'];
        if (!in_array($sortDir, $allowedSortDir)) {
            $sortDir = 'ASC';
        }

        // Make sure sort column is valid
        $allowSortBy = [
            'subject'  => 'subject',
            'date'     => 'n.created',
            'owner'    => 'u.lastName',
            'cat'      => 'nc.name'
        ];
        if (!array_key_exists($sortBy, $allowSortBy)) {
            $sortBy = 'subject';
        }

        $fields = "n.id AS dbid, "
            . "nc.name AS category, "
            . "IF(n.ownerID = -1, 'n/a', u.lastName) AS owner, "
            . "LEFT(n.created,10) AS date, "
            . "n.subject AS subject, "
            . "n.note AS note";

        $clientDB = $this->DB->getClientDB($this->tenantIdValue);
        $sql = 'SELECT ' . $fields . ' '
            . 'FROM ' . $this->DB->prependDbName($clientDB, $this->table) . ' AS n '
            . 'LEFT JOIN ' . $this->DB->prependDbName($clientDB, 'noteCategory') . ' AS nc ON nc.id = n.noteCatID '
            . 'LEFT JOIN ' . $this->DB->prependDbName($this->DB->authDB, 'users') . ' AS u ON u.id = n.ownerID '
            . 'WHERE n.caseID=:caseID AND n.clientID=:clientID';

        // TODO: Remove ftr->isLegacy** checks when these FeatureACL calls are fully replaced.
        if ($this->ftr->application !== \Feature::APP_ADMIN || !$this->ftr->isLegacyFullSuperAdmin()) {
            if ($this->ftr->application == \Feature::APP_TPM
                || $this->ftr->isLegacyClientAdmin()
                || $this->ftr->isLegacyClientManager()
                || $this->ftr->isLegacyClientUser()
            ) {
                $sql .= ' AND n.bInvestigator=0';
            } elseif (($this->ftr->application == \Feature::APP_SP || $this->ftr->application == \Feature::APP_SP_LITE)
                || $this->ftr->isLegacySpAdmin() || $this->ftr->isLegacySpUser()
            ) {
                $sql .= ' AND (n.bInvestigator = 1 OR n.bInvestigatorCanSee = 1)';
            } else {
                $sql .= ' AND 0';
            }
        }

        // Set column to use for sorting
        $sql .= ' ORDER BY `' . $allowSortBy[$sortBy] . '` ' . $sortDir;

        // Set query limits if requested
        if ((is_int($limit) && $limit > 0) && is_int($offset)) {
            $sql .= ' LIMIT ' . $limit . ' ' . $offset;
        }

        $params = [
            ':caseID'   => $caseID,
            ':clientID' => $this->tenantIdValue
        ];

        $result = $this->DB->fetchAssocRows($sql, $params);

        return $result;
    }

    /**
     * Get count of all accessible case notes
     *
     * @param integer $caseID ID of case notes should be associated with
     * @param integer $limit  Limit number of notes returned
     * @param integer $offset Notes list offset if limiting return
     *
     * @return array
     */
    public function getListCount($caseID, $limit = 0, $offset = 0)
    {
        $fields = "COUNT(n.id)";

        $clientDB = $this->DB->getClientDB($this->tenantIdValue);
        $sql = 'SELECT ' . $fields . ' '
            . 'FROM ' . $this->DB->prependDbName($clientDB, $this->table) . ' AS n '
            . 'LEFT JOIN ' . $this->DB->prependDbName($clientDB, 'noteCategory') . ' AS nc ON nc.id = n.noteCatID '
            . 'LEFT JOIN ' . $this->DB->prependDbName($this->DB->authDB, 'users') . ' AS u ON u.id = n.ownerID '
            . 'WHERE n.caseID=:caseID AND n.clientID=:clientID';

        // TODO: Remove ftr->isLegacy** checks when these FeatureACL calls are fully replaced.
        if ($this->ftr->application !== \Feature::APP_ADMIN || !$this->ftr->isLegacyFullSuperAdmin()) {
            if ($this->ftr->application == \Feature::APP_TPM
                || $this->ftr->isLegacyClientAdmin()
                || $this->ftr->isLegacyClientManager()
                || $this->ftr->isLegacyClientUser()
            ) {
                $sql .= ' AND n.bInvestigator=0';
            } elseif (($this->ftr->application == \Feature::APP_SP || $this->ftr->application == \Feature::APP_SP_LITE)
                || $this->ftr->isLegacySpAdmin() || $this->ftr->isLegacySpUser()
            ) {
                $sql .= ' AND (n.bInvestigator = 1 OR n.bInvestigatorCanSee = 1)';
            } else {
                $sql .= ' AND 0';
            }
        }

        $params = [
            ':caseID'   => $caseID,
            ':clientID' => $this->tenantIdValue
        ];

        $result = $this->DB->fetchValue($sql, $params);

        return $result;
    }
}
