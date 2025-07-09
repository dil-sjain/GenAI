<?php
/**
 *  IntakeFormInstances
 *
 * @keywords intake, form, instances
 *
 */

namespace Models\TPM\IntakeForms;

use Lib\DdqAcl;
use Models\ThirdPartyManagement\ClientProfile;

/**
 * Instances of an intake form (previously the ddq table)
 */
#[\AllowDynamicProperties]
class IntakeFormInstances
{
    /**
     * Authorized userID
     *
     * @var integer
     */
    protected $authUserID = null;

    /**
     * Determines whether or not this was instantiated via the REST API
     *
     * @var boolean
     */
    public $isAPI = false;

    /**
     * Case constructor requires clientID
     *
     * @param integer $clientID clientProfile.id
     * @param array   $params   configuration
     */
    public function __construct($clientID, $params = [])
    {
        $this->clientID = $clientID;
        $this->DB = \Xtra::app()->DB;
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : \Xtra::app()->ftr->user;
    }

    /**
     * Get intakeFormInstances row by either id or clientID/ddqID combo
     *
     * @param integer $reference Either intakeFormInstances.id or intakeFormInstances.ddqID
     * @param boolean $isDdqID   If true, then $reference will contain intakeFormInstances.ddqID
     *
     * @return array
     */
    public function getInstance($reference, $isDdqID = false)
    {
        $reference = (int)$reference;
        $clientDB = $this->DB->getClientDB($this->clientID);
        $fields = "i.id, i.ddqID, i.intakeFormCfgID, i.languageCode, i.caseID, i.loginEmail, i.origin, i.status, "
            . "i.aclID, i.aclVersion, i.returnStatus";
        $whereClause = "WHERE "
            . (($isDdqID) ? "d.status = 1 AND d.clientID = :clientID AND i.ddqID = :ddqID" : "i.id = :id")
            . "\n";
        $sql = "SELECT {$fields} FROM {$clientDB}.intakeFormInstances AS i\n"
            . "LEFT JOIN {$clientDB}.ddqName AS d ON d.id = i.intakeFormCfgID\n"
            . $whereClause
            . "LIMIT 1";
        $params = ($isDdqID) ? [':clientID' => $this->clientID, ':ddqID' => $reference] : [':id' => $reference];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Get intakeFormInstances row by either id or clientID/ddqID combo
     *
     * @param string $loginEmail intakeFormInstances.loginEmail
     *
     * @return array
     */
    public function getInstancesByLoginEmail($loginEmail)
    {
        $rtn = [];
        if (!empty($loginEmail)) {
            $clientDB = $this->DB->getClientDB($this->clientID);
            $fields = "i.id, i.ddqID, i.intakeFormCfgID, i.languageCode, i.caseID, i.origin, i.status, "
                . "i.aclID, i.aclVersion, i.returnStatus";
            $sql = "SELECT {$fields} FROM {$clientDB}.intakeFormInstances AS i\n"
                . "LEFT JOIN {$clientDB}.ddqName AS d ON d.id = i.intakeFormCfgID\n"
                . "WHERE d.clientID = :clientID AND i.loginEmail = :loginEmail";
            $params = [':clientID' => $this->clientID, ':loginEmail' => $loginEmail];
            $rtn = $this->DB->fetchAssocRow($sql, $params);
        }
        return $rtn;
    }

    /**
     * Get intakeFormInstances rows for a client and constrain by ddqID if param is supplied
     *
     * @param integer $ddqID DDQ ID (optional)
     *
     * @return array
     */
    public function getInstances($ddqID)
    {
        $ddqID = (int)$ddqID;
        $clientDB = $this->DB->getClientDB($this->clientID);
        $params = [':clientID' => $this->clientID];
        $whereClause = "WHERE d.status = 1 AND d.clientID = :clientID";
        if (!empty($ddqID)) {
            $whereClause .= " AND i.ddqID = :ddqID";
            $params[':ddqID'] = $ddqID;
        }
        $sql = "SELECT i.id, i.intakeFormCfgID, i.languageCode, i.caseID, i.origin, i.status, "
            . "i.aclID, i.aclVersion, i.returnStatus\n"
            . "FROM {$clientDB}.intakeFormInstances AS i\n"
            . "LEFT JOIN {$clientDB}.ddqName AS d ON d.id = i.intakeFormCfgID\n"
            . "{$whereClause}\n"
            . "ORDER BY i.id ASC";
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Validate that intake form is submittable
     * This pertains to either a ddq with extended questions/asnwers in the intakeForm* schema
     * or else an intake form configured entirely in the intakeForm* schema.
     *
     * @param integer $id intakeFormInstances.id
     *
     * @return mixed String if an error message, else true boolean
     */
    public function isSubmittable($id)
    {
        $id = (int)$id;
        if (empty($id) || !($instance = $this->getInstance($id)) || empty($instance)) {
            return 'Intake Form Instance does not exist';
        }
        $rtn = [];
        $params = ['authUserID' => $this->authUserID, 'isAPI' => $this->isAPI];
        $questions = (new IntakeFormQuestions($this->clientID, $params))->getExtensions(
            $instance['intakeFormCfgID'],
            $instance['languageCode'],
            $id
        );
        return ($questions)
            ? (new IntakeFormResponses($this->clientID, $params))->validateSubmittable($id, $questions, false)
            : ['submittable' => false, 'submissionBlockingErrors' => []];
    }

    /**
     * Return ACL content
     *
     * @param string $companyName           ddq.name
     * @param string $pointOfContactName    ddq.POCname
     * @param string $languageCode          ddq.languageCode
     * @param int $intakeFormTypeID          ddq.caseType
     *
     * @return string
     */
    private function getACL($companyName, $pointOfContactName, $languageCode, $intakeFormTypeID)
    {
        $client = (new ClientProfile(['clientID' => $this->clientID]))->findById($this->clientID);
        $params = ['companyName' => $companyName,
        'pointOfContactName' => $pointOfContactName];
        $acl = (new DdqAcl($params))->loadAcl(
            $this->clientID,
            DdqAcl::getAclScopeID($intakeFormTypeID),
            $languageCode,
            $client->get('ddqACLversion')
        );
        $aclContent = null;
        if ($acl['content'] != '') {
            $aclContent =preg_replace(
                "/&#?[a-z0-9]+;/i",
                '',
                filter_var($acl['content'], FILTER_SANITIZE_STRING)
            );
            $aclContent = preg_replace('/\s+/', ' ', (string) $aclContent);
        }
        return $aclContent;
    }

    /**
     * Returns submit section details of a caseID
     *
     * @param integer $caseID Case ID
     *
     * @return submitData object key-values in array format
     */
    public function getSubmitDataByCaseID($caseID)
    {
        $rtn = [];
        if (!empty($caseID)) {
            $clientDB = $this->DB->getClientDB($this->clientID);
            $sql = "SELECT subByName AS Name, subByTitle AS Title, subByPhone AS Telephone, "
                . "subByEmail AS Email, subByIP AS IP, subByDate AS Date, name AS companyName, "
                . "POCname AS pointOfContactName, subInLang AS languageCode, caseType AS intakeFormTypeID\n"
                . "FROM {$clientDB}.ddq\n"
                . "WHERE caseID = :caseID AND clientID = :clientID LIMIT 1";
            $ddqRes = $this->DB->fetchAssocRow($sql, [':caseID' => $caseID, ':clientID' => $this->clientID]);
            $rtn = $ddqRes;
        }
        if ($rtn) {
            $rtn['acl'] = $this->getACL(
                $rtn['companyName'],
                $rtn['pointOfContactName'],
                $rtn['languageCode'],
                $rtn['intakeFormTypeID']
            );
        }
        return $rtn;
    }
}
