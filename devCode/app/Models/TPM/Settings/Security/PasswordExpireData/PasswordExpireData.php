<?php
/**
 * File containg class to retreive and update a tenant's password
 * expiration link interval.
 *
 * @keywords password, expire, security, usertype
 */
namespace Models\TPM\Settings\Security\PasswordExpireData;

/**
 * Retrieve and update a tenant's password
 * expiration link interval.
 *
 */
#[\AllowDynamicProperties]
class PasswordExpireData
{
    /**
     * @var object Reference to global app
     */
    protected $app;

    /**
     * @var integer client id
     */
    protected $tenantId;

    /**
     * @var integer user id
     */
    protected $userId;

    /**
     * @var object MySqlPDO instance
     */
    protected $DB;

    /**
     * @var string db name for expiration interval
     */
    protected $dbName;

    /**
     * @var tbl table name containing password expiration interval
     */
    protected $tbl;

    /**
     * Constructor - initialization
     *
     * @param integer $clientId client id
     * @param array   $params   parameters in key-value pairs
     *
     * @return void
     */
    public function __construct(protected $clientId, $params = [])
    {
        if (is_bool($params['isSP']) === false) {
            throw new \InvalidArgumentException('must be of type boolean.');
        }

        \Xtra::requireInt($params['userId']);

        $this->app = \Xtra::app();
        $this->DB = $this->app->DB;

        $this->dbName
            = $params['isSP']
                ? $this->DB->spGlobalDB
                : $this->DB->getClientDB($this->clientId);

        $this->tbl
            = $params['isSP']
                ? "investigatorProfile"
                : "clientProfile";

        $this->userId = $params['userId'];

        $this->tenantId
            = $params['isSP']
                ? $this->getSpId()
                : $this->clientId;
    }

    /**
     * update db info on password reset expiration interval
     *
     * @param integer $newPwex New data
     *
     * @return integer
     *
     */
    public function updatePwex($newPwex)
    {
        $this->validate($newPwex);

        $sql
            = "UPDATE `$this->dbName`.`$this->tbl` "
            . "SET pwExpireDays=:newPwex "
            . "WHERE id=:id LIMIT 1"
        ;

        $bindings  = [
            ':id' => $this->tenantId,
            ':newPwex' => $newPwex
        ];

        $this->DB->query($sql, $bindings);

        $oldPwEx = $this->getPwex();

        return $oldPwEx;
    }

    /**
     * obtain the tenant's password expiration interval
     *
     * @return integer
     *
     */
    public function getPwex()
    {
        $sql =  "SELECT pwExpireDays "
                . "FROM $this->dbName.$this->tbl WHERE id=:id LIMIT 1";

        $bindings = [':id' => $this->tenantId];

        $oldPwEx = $this->DB->fetchValue($sql, $bindings);

        return $oldPwEx;
    }

    /**
     * obtain the db id of logged in user's tenant (service provider)
     *
     * @return void
     *
     */
    private function getSpId()
    {
        $sql = "SELECT vendorID FROM {$this->DB->authDB}.users \n" .
                "WHERE id = :userId";
        $spId = $this->DB->fetchValue($sql, [":userId" => $this->userId]);
        return $spId;
    }

    /**
     * throw exception if the argument is not a positive (nonzero) int
     *
     * @param integer $inte Value to validate
     *
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    private function validate($inte)
    {
        $valiMsg = $this->app->trans->codeKey('invalid_pw_exp_days');
        \Xtra::requireInt($inte, $valiMsg);
        if ($inte < 0) {
            throw new \InvalidArgumentException($valiMsg);
        }
    }
}
