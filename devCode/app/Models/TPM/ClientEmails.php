<?php
/**
 * Model for ClientEmails
 *
 * @keywords email, client email
 */

namespace Models\TPM;

use \Lib\Legacy\SysEmail;
use \Lib\Services\AppMailer;
use \Models\BaseModel;
use \Models\ThirdPartyManagement\Cases;
use \Models\ThirdPartyManagement\ClientProfile;

/**
 * TenantIdStrict model for SystemEmails CRUD
 */
#[\AllowDynamicProperties]
class ClientEmails extends BaseModel
{
    /**
     * Name of table
     *
     * @var string
     */
    protected $table = 'clientEmails';

    /**
     * Client Emails to send
     *
     * @var array
     */
    private $clientEmailsToSend;

    /**
     * Return array gettable/settable attributes w/validation rules
     *
     * @param string $context not functional, but would allow for future conditional attributes/validation
     *
     * @return array
     */
    #[\Override]
    public static function rulesArray($context = '')
    {
        return [
            'id'               => 'db_int',
            'clientID'         => 'db_int',              // clientProfile.id
            'invokedBy'        => 'max_len,50',          // Action that triggers this email.
            'languageCode'     => 'max_len,10|required', // languages.langCode
            'EMtype'           => 'db_int|required',     // Email type (defs)
            'emailDescription' => 'max_len,65535',
            'EMrecipient'      => 'max_len,255',         // Replacement/additional Recipient
            'EMsubject'        => 'max_len,255',         // Email Subject
            'EMbody'           => 'max_len,65535',       // Email Body
            'EMfrom'           => 'max_len,255',         // Email From if different than user
            'EMcc'             => 'max_len,255',         // Email addresses to be copied on this email
            'EMbcc'            => 'max_len,255',         // email addr of bcc
            'bHTML'            => 'db_tinyint',          // does the email contain HTML
        ];
    }

    /**
     * constructor
     *
     * @param integer $tenantID unique identifier. Set to zero for only default emails.
     * @param array   $params   configuration
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct(/**
         * Tenant ID of current client.
         */
        protected $tenantID = 0,
        array $params = []
    ) {
        parent::__construct($params);
        $this->clientDB = $this->DB->getClientDB($this->tenantID);
        $this->table = "{$this->clientDB}.clientEmails";
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
            'clientID'  => '0',
            'EMsubject' => '',
            'EMbody'    => '',
            'bHTML'     => '0',
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
     * @param string $langCode Language code
     * @param int    $type     Email Type to check
     *
     * @return bool True if found
     */
    public function emailExists($langCode, $type)
    {
        $sql = 'SELECT id FROM clientEmails '
            . 'WHERE (clientID = :clientID OR clientID = 0) '
            . 'AND languageCode = :langCode '
            . 'AND EMtype = :emailType LIMIT 1';
        $params = [
            ':clientID'  => $this->tenantID,
            ':langCode'  => $langCode,
            ':emailType' => $type,
        ];
        $value = $this->DB->fetchValue($sql, $params);

        return $value > 0;
    }

    /**
     * Attempt to load clientEmail based on provided attributes
     *
     * @param string $languageCode The first language code to check for.
     * @param int    $EMtype       Type of email
     *
     * @throws \Exception
     * @return ClientEmails|null
     */
    public function findEmail($languageCode, $EMtype)
    {
        // Retrieve Client's custom email in specified language
        $email = $this->findByAttributes([
            'clientID'     => $this->tenantID,
            'languageCode' => $languageCode,
            'EMtype'       => $EMtype,
            ]);

        // If not found, look for default in specified language
        if (empty($email)) {
            $email = $this->findByAttributes([
                'clientID'     => 0,
                'languageCode' => $languageCode,
                'EMtype'       => $EMtype,
                ]);
        }

        // If still not found, fall back to Client in EN_US
        if (empty($email)) {
            $email = $this->findByAttributes([
                'clientID'     => $this->tenantID,
                'languageCode' => 'EN_US',
                'EMtype'       => $EMtype,
                ]);
        }

        // Finally, if not found, look for default in EN_US
        if (empty($email)) {
            $email = $this->findByAttributes([
                'clientID'     => 0,
                'languageCode' => 'EN_US',
                'EMtype'       => $EMtype,
                ]);
        }
        return $email;
    }

    /**
     * Sends client email(s).
     *
     * @param integer $userID         users.id
     * @param integer $userType       users.userType
     * @param string  $mgrDepartments users.mgrDepartments
     * @param string  $mgrRegions     users.mgrRegions
     * @param integer $tpID           thirdPartyProfile.id
     * @param integer $caseID         Case ID
     * @param string  $invokedBy      What action invoked this function (or something like that.)
     * @param string  $languageCode   Language code of the template to use
     * @param integer $EMtype         Type of client email to send
     * @param array   $recipientArr   Contains the recipient's name and email address (id is optional)
     * @param boolean $tpCustomFlds   Indicates if 3P Custom Field data is required for the notification
     *
     * @return integer Returns a 0
     */
    public function send(
        $userID,
        $userType,
        $mgrDepartments,
        $mgrRegions,
        $tpID,
        $caseID,
        $invokedBy,
        $languageCode,
        $EMtype,
        $recipientArr,
        $tpCustomFlds = false
    ) {
        $valid = $this->validateSendParams(
            $userID,
            $userType,
            $caseID,
            $EMtype,
            $recipientArr,
            $invokedBy,
            $languageCode
        );
        if (!$valid) {
            return (SysEmail::EM_ERR_EMAIL_NOT_FOUND);
        }
        $userID = (int)$userID;
        $userType = (int)$userType;
        $tpID = (int)$tpID;
        $caseID = (int)$caseID;
        $EMtype = (int)$EMtype;
        $systemEmails = new SysEmail($this->tenantID);
        // Get the Client Profile row now since we only need it once
        $cp = (new ClientProfile(['clientID' => $this->tenantID]))->findById($this->tenantID);
        $clientProfile = $cp->getAttributes();

        foreach ($this->clientEmailsToSend as $idx => $email) {
            $recipient = $recipientArr['toEmail'];
            $subject = $email['EMsubject'];
            $body = $email['EMbody'];
            $cc = $email['EMcc'];
            $isHTML = (array_key_exists('bHTML', $email) && (bool)$email['bHTML'] == true);
            $linkURL = $systemEmails->buildLinkURL($caseID, true, $isHTML);
            $subject = str_replace("<LOGIN_URL>", $linkURL, (string) $subject);
            $body = str_replace("<LOGIN_URL>", $linkURL, (string) $body);

            $systemEmails->replaceTableTokens('clientProfile', $clientProfile, $subject);
            $systemEmails->replaceTableTokens('clientProfile', $clientProfile, $body);

            if ($caseID) {
                $case = $systemEmails->getCaseData($caseID, $languageCode);
                if (!$case) {
                    return (SysEmail::EM_ERR_CASEID_NO_CASEREC);
                }
                $systemEmails->replaceTableTokens('cases', $case, $subject);
                $systemEmails->replaceTableTokens('cases', $case, $body);
                $ddq = $systemEmails->getDdqData($caseID);
                if ($ddq) {
                    $subject = str_replace("<ddqTitle>", $clientProfile['ddqTitle'], (string) $subject);
                    $subject = str_replace("<ddq.POCname>", $ddq->POCname, $subject);
                    $subject = str_replace("<ddq.name>", $ddq->name, $subject); // Company Name
                    $subject = str_replace("<ddq.loginEmail>", $ddq->loginEmail, $subject);
                    $body = str_replace("<ddqTitle>", $clientProfile['ddqTitle'], (string) $body);
                    $body = str_replace("<ddq.POCname>", $ddq->POCname, $body);
                    $body = str_replace("<ddq.name>", $ddq->name, $body); // Company Name
                    $body = str_replace("<ddq.loginEmail>", $ddq->loginEmail, $body);
                    $body = str_replace("<region.name>", $case->regionName, $body);
                    $body = str_replace("<Country.name>", $case->countryName, $body);
                    $subjectInfoDD = $systemEmails->getSubjectInfoDdData($caseID);
                    if (!$subjectInfoDD) {
                        return SysEmail::EM_ERR_NONEXISTANT_RECIPIENT;
                    }
                    $relationshipType = (!empty($subjectInfoDD->relationshipType))
                        ? "  Relationship Type: {$subjectInfoDD->relationshipType} \n"
                        : '';
                    $body = str_replace("<relationshipType.name>", $relationshipType, $body);
                    $systemEmails->replaceTableTokens('ddq', $ddq, $subject);
                    $systemEmails->replaceTableTokens('ddq', $ddq, $body);
                }
                $subject = str_replace("<cases.userCaseNum>", $case->userCaseNum, (string) $subject);
                $subject = str_replace("<cases.caseName>", $case->caseName, $subject);
                $subject = str_replace("<CLIENT_NAME>", $clientProfile['clientName'], $subject);
                $subject = str_replace("<clientProfile.clientName>", $clientProfile['clientName'], $subject);
                $subject = str_replace("<clientProfile.clientSt1>", $clientProfile['clientSt1'], $subject);
                $subject = str_replace("<clientProfile.clientSt2>", $clientProfile['clientSt2'], $subject);
                $subject = str_replace("<clientProfile.clientCity>", $clientProfile['clientCity'], $subject);
                $subject = str_replace("<clientProfile.clientState>", $clientProfile['clientState'], $subject);
                $subject = str_replace("<clientProfile.clientPhone>", $clientProfile['clientPhone'], $subject);
                $subject = str_replace("<clientProfile.clientCountry>", $clientProfile['clientCountry'], $subject);
                $subject = str_replace(
                    "<clientProfile.companyShortName>",
                    $clientProfile['companyShortName'],
                    $subject
                );
                $subject = str_replace("<ddqTitle>", $clientProfile['ddqTitle'], $subject);
                $body = str_replace("<cases.userCaseNum>", $case->userCaseNum, (string) $body);
                $body = str_replace("<cases.caseName>", $case->caseName, $body);
                $body = str_replace("<CLIENT_NAME>", $clientProfile['clientName'], $body);
                $body = str_replace("<clientProfile.clientName>", $clientProfile['clientName'], $body);
                $body = str_replace("<clientProfile.clientSt1>", $clientProfile['clientSt1'], $body);
                $body = str_replace("<clientProfile.clientSt2>", $clientProfile['clientSt2'], $body);
                $body = str_replace("<clientProfile.clientCity>", $clientProfile['clientCity'], $body);
                $body = str_replace("<clientProfile.clientState>", $clientProfile['clientState'], $body);
                $body = str_replace(
                    "<clientProfile.clientCountry>",
                    $clientProfile['clientCountry'],
                    $body
                );
                $body = str_replace("<clientProfile.clientPhone>", $clientProfile['clientPhone'], $body);
                $body = str_replace(
                    "<clientProfile.companyShortName>",
                    $clientProfile['companyShortName'],
                    $body
                );
                $systemEmails->replaceCustomFieldDataTags('case', $caseID, $body);
                $systemEmails->replaceCustomFieldDataTags('case', $caseID, $subject);
            }
            $currentDate = date("Y-m-d");
            $subject = str_replace("<CURRENT_DATE>", $currentDate, (string) $subject);
            $body = str_replace("<CURRENT_DATE>", $currentDate, (string) $body);
            $caseType = $systemEmails->getCaseTypeData($EMtype);
            if ($caseType) {
                $body = str_replace("<caseTypeClient.name>", $caseType->name, $body);
                $body = str_replace("<caseTypeClient.abbrev>", $caseType->abbrev, $body);
                $subject = str_replace("<caseTypeClient.name>", $caseType->name, $subject);
                $subject = str_replace("<caseTypeClient.abbrev>", $caseType->abbrev, $subject);
            }
            if (!empty($tpID) && $tpCustomFlds) { // Required for Akorn Notifications
                // Fill in 3P Email variables from profile record
                $thirdPartyProfile = $systemEmails->getThirdPartyProfileData(
                    $userID,
                    $userType,
                    $mgrDepartments,
                    $mgrRegions,
                    $tpID
                );
                if (!$thirdPartyProfile) {
                    return (SysEmail::EM_ERR_TPID_NO_TPREC);
                }
                $systemEmails->replaceTableTokens('thirdPartyProfile', $thirdPartyProfile, $subject);
                $systemEmails->replaceTableTokens('thirdPartyProfile', $thirdPartyProfile, $body);
                $systemEmails->replaceCustomFieldDataTags('thirdparty', $tpID, $body);
                $systemEmails->replaceCustomFieldDataTags('thirdparty', $tpID, $subject);
            }

            // Replace any left over token with a Not Found string
            $offset = $rc = 0;
            while (($offset < strlen((string) $body)) && strpos((string) $body, '{', $offset)) {
                $offset = strpos((string) $body, '{', $offset);
                $stTokenName = '';
                while (($offset < strlen((string) $body)) && ($body[$offset] != '}')) {
                    $stTokenName .= $body[$offset++];
                }
                $stTokenName .= '}';
                $stReplace = "Token [" . $stTokenName . "] " . SysEmail::EM_TOKEN_NOTFOUND;
                $subject = str_replace($stTokenName, $stReplace, (string) $subject);
                $body = str_replace($stTokenName, $stReplace, (string) $body);
                $rc = SysEmail::EM_WARN_TOKEN_REMAINS;
            }
            $error = $systemEmails->validateAndSetEmailList($caseID, $tpID, $recipient);
            if (!empty($error)) {
                // Since there is an error in the Recipient Address we aren't even going to try to send it
                return SysEmail::EM_ERR_BAD_RECIPIENT_ADDR;
            }
            $recipientAddresses = $recipient;
            if (strlen((string) $email['EMcc'])) {
                $error = $systemEmails->validateAndSetEmailList($caseID, $tpID, $email['EMcc']);
                $cc = (empty($error)) ? "\nCc: {$email['EMcc']}" : '';
            }
            $ccAddresses = $email['EMcc'];
            if (strlen((string) $email['EMbcc'])) {
                $error = $systemEmails->validateAndSetEmailList($caseID, $tpID, $email['EMbcc']);
                $cc .= (empty($error)) ? "\nBcc: {$email['EMbcc']}" : '';
            }
            $bccAddresses = $email['EMbcc'];
            $emailFrom = $_ENV['emailNotifyAddress'];
            $from = "From: ";
            if (strlen((string) $email['EMfrom'])) {
                $from .= $email['EMfrom'];
                if ($_ENV['env'] == 'Production') { // Gitlab #1159, temporary gate
                    $emailFrom = $email['EMfrom'];
                }
            } else {
                $from .= $_ENV['emailNotifyAddress'];
            }
            $from = $systemEmails->sanitizeEmailAddress($from . $cc);
            // Replace any HTML entities
            $subject = html_entity_decode((string) $subject, ENT_QUOTES, 'UTF-8');
            if (!$isHTML) {
                $body = html_entity_decode((string) $body, ENT_QUOTES, 'UTF-8');
            }

            try {
                $mailer = new AppMailer();
                $mailer->From = $emailFrom;
                $mailer->Subject = $subject;
                $mailer->addAddressList('to', $recipientAddresses); // add recipient(s)
                if ($ccAddresses) {
                    $mailer->addAddressList('cc', $ccAddresses);
                }
                if ($bccAddresses) {
                    $mailer->addAddressList('bcc', $bccAddresses);
                }
                if ($isHTML) {
                    $mailer->useHtmlWrapper = (strpos((string) $body, '<html>') !== true);
                    $mailer->htmlBody = $body;
                } else {
                    $mailer->Body = $body;
                }
                if (!$mailer->sendMessage($this->tenantID)) {
                    \Xtra::app()->log->info([$mailer->ErrorInfo, "Mailer Error (to $recipientAddresses)"]);
                    return 2;
                }
            } catch (\Exception $e) {
                \Xtra::app()->log->info([$e->getMessage(), "Mailer Error (to $recipientAddresses)"]);
                return 2;
            }
            $sql = "INSERT INTO {$this->clientDB}.clientEmailLog SET\n"
                . "clientID = :clientID, sender = :from, recipient = :recipient, invokedBy = :invokedBy, "
                . "languageCode = :languageCode, EMtype = :EMtype, subject = :subject, body = :body, "
                . "rc = :rc, tpID = :tpID, caseID = :caseID, cc = :ccAddresses";
            $params = [
              ':clientID'     => $this->tenantID,
              ':from'         => $from,
              ':recipient'    => $recipient,
              ':invokedBy'    => $invokedBy,
              ':languageCode' => $languageCode,
              ':EMtype'       => $EMtype,
              ':subject'      => $subject,
              ':body'         => $body,
              ':rc'           => $rc,
              ':tpID'         => $tpID,
              ':caseID'       => $caseID,
              ':ccAddresses'  => $ccAddresses
            ];
            $result = $this->DB->query($sql, $params);
            if (!$result) {
                return SysEmail::EM_ERR_CLIENTLOG_FAILURE;
            }
        }
    }

    /**
     * Validates parameters for send() method
     *
     * @param integer $userID       users.id
     * @param integer $userType     users.userType
     * @param integer $caseID       cases.id
     * @param integer $EMtype       clientEmails.EMtype
     * @param array   $recipientArr Should contain toName and toEmail
     * @param string  $invokedBy    clientEmails.invokedBy
     * @param string  $languageCode clientEmails.languageCode
     *
     * @return boolean
     */
    private function validateSendParams($userID, $userType, $caseID, $EMtype, $recipientArr, $invokedBy, $languageCode)
    {
        $userID = (int)$userID;
        $userType = (int)$userType;
        $caseID = (int)$caseID;
        $EMtype = (int)$EMtype;
        $missing = [];
        $invalid = [];
        if (empty($userID)) {
            $missing[] = 'userID';
        }
        if (empty($userType)) {
            $missing[] = 'userType';
        }
        if (empty($caseID)) {
            $missing[] = 'caseID';
        }
        if (empty($EMtype)) {
            $missing[] = 'EMtype';
        }
        if (!is_array($recipientArr) || empty($recipientArr)
            || (empty($recipientArr['toName']) && empty($recipientArr['toEmail']))
        ) {
            $missing[] = 'recipient name/email';
        } elseif (empty($recipientArr['toName']) || empty($recipientArr['toEmail'])) {
            if (empty($recipientArr['toName'])) {
                $missing[] = 'recipient name';
            } elseif (empty($recipientArr['toEmail'])) {
                $missing[] = 'recipient email';
            }
        }
        $case = (new Cases($this->tenantID))->findById($caseID);
        if (!is_object($case)) {
            $invalid[] = "Unable to find case with id: {$caseID}";
        }
        $sql = "SELECT * FROM {$this->table}\n"
            . "WHERE clientID = :tenantID AND invokedBy LIKE :invokedBy "
            . "AND languageCode LIKE :languageCode AND EMtype = :EMtype";
        $params = [
            ':tenantID'     => $this->tenantID,
            ':invokedBy'    => $invokedBy,
            ':languageCode' => $languageCode,
            ':EMtype'       => $EMtype
        ];
        if (!($clientEmails = $this->DB->fetchAssocRows($sql, $params))) {
            $invalid[] = "Unable to find any emails";
        } else {
            $this->clientEmailsToSend = $clientEmails;
        }
        return (empty($missing) && empty($invalid));
    }
}
