<?php
/**
 * Model for SystemEmails
 *
 * @keywords email, system email
 */

namespace Models\TPM;

use Lib\Legacy\SysEmail;
use \Models\BaseModel;

/**
 * TenantIdStrict model for SystemEmails CRUD
 */
#[\AllowDynamicProperties]
class SystemEmails extends BaseModel
{
    /**
     * @var string name of table
     */
    protected $clientDB = null;

    /**
     * @var string name of table
     */
    protected $table = 'systemEmails';

    /**
     * @var int Client ID of current client.
     */
    protected $clientID;


    /**
     * Return array gettable/settable attributes w/validation rules
     *
     * @param string $context not functional, but would allow for future conditional attributes/validation
     *
     * @return array
     */
    public static function rulesArray($context = '')
    {
        return [
            'id'           => 'db_int',
            'clientID'     => 'db_int', // clientProfile.id
            'languageCode' => 'max_len,10|required', // languages.langCode
            'EMtype'       => 'db_int|required', // Email type (defs)
            'caseType'     => 'db_smallint', // caseType.id
            'EMsubject'    => 'max_len,255', // Email Subject
            'EMbody'       => 'max_len,65535', // Email Body
            'EMrecipient'  => 'max_len,65535', // Replacement/additional Recipient
            'EMcc'         => 'max_len,65535', // Replacement/additional CC
        ];
    }

    /**
     * Constructor
     *
     * @param integer $tenantID Unique identifier. Set to zero for only default emails.
     * @param array   $params   Configuration
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($tenantID = 0, array $params = [])
    {
        parent::__construct($params);
        $this->clientID = (int)$tenantID;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
        $this->table = "{$this->clientDB}.systemEmails";
    }

    /**
     * Configure other rules for attributes
     *
     * @param string $context Allows for conditional rules (in future implementations)
     *
     * @return void
     */
    protected function loadRulesAdditional($context = '')
    {
        // set defaults, key--> column name, value--> value or functionName()
        $this->rulesDefault = [
            'clientID'  => '0',
            'caseType'  => '12',
            'EMsubject' => '',
            'EMbody'    => '',
        ];

        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }

    /**
     * Does system email exist for language, ddq.caseType and optional email type.
     * Checks for current clientID and falls back to clientID 0 for non-client specific emails.
     *
     * @param string $langCode       Language code
     * @param int    $intakeFormType ddq.caseType
     * @param int    $type           Email Type to check
     *
     * @return bool True if found
     */
    public function emailExists($langCode, $intakeFormType, $type = SysEmail::EMAIL_SEND_DDQ_INVITATION)
    {
        $sql = "SELECT id FROM {$this->table}\n"
            . "WHERE (clientID = :clientID OR clientID = 0) "
            . "AND languageCode = :langCode "
            . "AND EMtype = :emailType "
            . "AND caseType = :caseType LIMIT 1";
        $params = [
            ':clientID'  => $this->clientID,
            ':langCode'  => $langCode,
            ':emailType' => $type,
            ':caseType'  => $intakeFormType
        ];
        $value = $this->DB->fetchValue($sql, $params);
        return $value > 0;
    }

    /**
     * Replace tokens in strings from an array or object inheriting from BaseModel. This maintains compatibility with
     * token replacement from Legacy.
     * See: funcs_sysmail_common.php Line: 449 - fRplcTableTokens
     *
     * @param string          $prefix    In Legacy this was the name of the table to pull replacement values from.
     * @param array|BaseModel $fields    An array or child of BaseModel. Method extracts fields from Object.
     * @param string          $toReplace String to search for replacement tokens.
     *
     * @throws \Exception
     * @return string
     */
    public function tokenReplace($prefix, $fields, $toReplace)
    {
        $data = [];
        $tags = [];

        // If not providing an array of replacement values, check if valid DB model and pull columns from that
        if (!is_array($fields)) {
            $models = ['BaseModel', \Models\BaseModel::class];
            if (is_object($fields) && !empty(array_intersect($models, class_parents($fields)))) {
                $fields = $fields->getAttributes();
            } else {
                throw new \Exception('Unable parse text replacement fields.');
            }
        }

        // If prefix is defined, set as table prefix
        if (!empty($prefix)) {
            $prefix = $prefix . '.';
        }

        // Loop through replacement tags
        foreach ($fields as $column => $columnData) {
            $tags[] = '{' . $prefix . $column . '}';
            $data[] = $columnData;
            // cover legacy token format (less than ideal for html email)
            $tags[] = '<' . $prefix . $column . '>';
            $data[] = $columnData;
        }

        return str_replace($tags, $data, $toReplace);
    }

    /**
     * Attempt to load systemEmail based on provided attributes
     *
     * @param string $languageCode The first language code to check for.
     * @param int    $EMtype       Type of email
     * @param int    $caseType     Type of case associated with email
     *
     * @throws \Exception
     * @return SystemEmails|null
     */
    public function findEmail($languageCode, $EMtype, $caseType)
    {
        // Retrieve Client's custom email in specified language
        $email = $this->findByAttributes([
            'clientID'     => $this->clientID,
            'languageCode' => $languageCode,
            'EMtype'       => $EMtype,
            'caseType'     => $caseType
        ]);

        // If not found, look for default in specified language
        if (empty($email)) {
            $email = $this->findByAttributes([
                'clientID'     => 0,
                'languageCode' => $languageCode,
                'EMtype'       => $EMtype,
                'caseType'     => $caseType
            ]);
        }

        // If still not found, fall back to Client in EN_US
        if (empty($email)) {
            $email = $this->findByAttributes([
                'clientID'     => $this->clientID,
                'languageCode' => 'EN_US',
                'EMtype'       => $EMtype,
                'caseType'     => $caseType
            ]);
        }

        // Finally, if not found, look for default in EN_US
        if (empty($email)) {
            $email = $this->findByAttributes([
                'clientID'     => 0,
                'languageCode' => 'EN_US',
                'EMtype'       => $EMtype,
                'caseType'     => $caseType
            ]);
        }
        return $email;
    }

    /**
     * Lookup the email address of the user who owns a given tpID
     *
     * @param int $tpID thirdPartyProfile.id
     *
     * @return string tpOwnerEmailAddress
     */
    public function lookup3POWNERemail($tpID)
    {
        $tpID = intval($tpID);
        $sql = "SELECT u.userEmail FROM `" . $this->DB->authDB . "`.`users` AS u "
            . "JOIN `" . $this->clientDB . "`.`thirdPartyProfile` AS tpp ON tpp.ownerID = u.id "
            . "WHERE tpp.id = :tpID "
            . "LIMIT 1";
        $params = [':tpID' => $tpID];
        $res = $this->DB->fetchValue($sql, $params);
        $res = $res ?: false;
        return $res;
    }

    /**
     * Check custom field existence
     * @param integer $clientID
     * @param string $scope scope  case/thirdparty
     * @param string $name field name
     *
     * @return boolean
     */
    public function checkCustomFieldExistsForMailType($clientID, $scope, $name)
    {
        $sql = "SELECT count(*) as cnt FROM {$this->clientDB}.`customField` "
            . "WHERE `clientID` = :clientID AND `scope` = :scope AND `name` = :name LIMIT 1";
        $params = [
            ':clientID' => $clientID,
            ':scope' => $scope,
            ':name' => $name
        ];
        return  $this->DB->fetchValue($sql, $params);
    }

     /**
     * Check DDQ Question Id existence
     * @param integer $clientID
     * @param string $ddqID id
     *
     * @return boolean
     */
    public function checkDDQQuestionIdExistsForMailType($clientID, $ddqID)
    {
        $sql = "SELECT count(*) as cnt FROM {$this->clientDB}.`onlineQuestions` WHERE `clientID` = :clientID "
            . "AND `questionID`= :questionID and qStatus = 1 order by ID LIMIT 1";
        $params = [
            ':clientID' => $clientID,
            ':questionID' => $ddqID
        ];
        return  $this->DB->fetchValue($sql, $params);
    }
}
