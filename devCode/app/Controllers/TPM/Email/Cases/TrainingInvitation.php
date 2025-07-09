<?php
/**
 * Format and send Intake Form (DDQ) Invitations for Training formclass.
 *
 * @keywords intake form, ddq, email
 */

namespace Controllers\TPM\Email\Cases;

use Lib\IO\CleanStr;
use Lib\Legacy\ClientIds;
use Lib\Legacy\SysEmail;
use Lib\Services\AppMailer;
use Lib\SettingACL;
use Lib\Traits\EmailHelpers;
use Models\Globals\Geography;
use Models\Ddq;
use Models\Globals\Department;
use Models\Globals\Region;
use Models\Globals\EmailBaseModel;
use Models\TPM\SystemEmails;
use Models\ThirdPartyManagement\ClientProfile;
use Models\ThirdPartyManagement\Cases;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\SystemEmailLog;
use Models\User;

/**
 * Class Training Invitation
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class TrainingInvitation
{
    use EmailHelpers;

    /**
     * @var int TPM tenant identfier
     */
    protected $clientID = 0;

    /**
     * @var int|mixed|null Users.id
     */
    protected $userID = 0;

    /**
     * @var bool|mixed Flag to include faux CC email
     */
    protected $sendFauxCC = true;

    /**
     * @var array|mixed Region lookup values
     */
    protected $regionLookup = [];

    /**
     * @var array|mixed Department lookup values
     */
    protected $deptLookup = [];

    /**
     * @var array Token values
     */
    protected $tokenData = [];

    /**
     * Value of systemEmails.EMtype
     *
     * @var int
     */
    protected $EMtype = 0;

    /**
     * Instance of Ddq model
     *
     * @var Ddq
     */
    protected $intakeForm = null;

    /**
     * Instance of Cases model
     *
     * @var Cases
     */
    protected $case = null;

    /**
     * Instance of ClientProfile  model
     *
     * @var ClientProfile
     */
    protected $client = null;

    /**
     * Instance of User model
     *
     * @var User
     */
    protected $user = null;

    /**
     * Instance of AppMailer model
     *
     * @var AppMailer
     */
    protected $mailer = null;

    /**
     * @var string Notify email address and name (From and FromName)
     */
    protected $notifyFrom = '';

    /**
     * @var string Name for From header
     */
    protected $notifyFromName = '';

    /**
     * Instance of SystemEmails model
     *
     * @var SystemEmails
     */
    protected $sysMail = null;

    /**
     * Instance of SystemEmails model
     *
     * @var SystemEmails
     */
    protected $mailTpl = null;

    /**
     * ddqName.name
     *
     * @var string
     */
    protected $trainingName = '';

    /**
     * Erros
     *
     * @var array
     */
    protected $errors = [];

    /**
     * Initialize class for sending an Training invite
     *
     * @param int        $clientID      ID of client user sending email
     * @param Ddq        $intakeForm    Instance of training intake form
     * @param Cases|null $case          Optional Cases instance
     * @param mixed      $userID        Optional ID of attribution user. Will try to provide logged-in user from
     *                                  session if not provided. May also pass instance of User model
     * @param array      $clientObjects Optional assoc can include any of these for preloading (helps bulk ops)
     *                                  'trainingName'  => (string)
     *                                  'sendFauxCC'    => (bool)
     *                                  'mailSettings'  => (array)
     *                                  'regionLookup'  => (array)
     *                                  'deptLookup'    => (array)
     *                                  'systemEmails'  => (instance)
     *                                  'clientProfile' => (instance)
     */
    public function __construct(
        $clientID,
        Ddq $intakeForm,
        ?Cases $case = null,
        mixed $userID = 0, // may also be instance of User model
        array $clientObjects = []
    ) {
        $this->clientID = (int)$clientID;
        $this->intakeForm = $intakeForm;

        // Case instance
        if ($case instanceof Cases) {
            $this->case = $case;
        } else {
            $this->case = (new Cases($this->clientID))->findById($intakeForm->get('caseID'));
        }

        $userMdl = new User();
        if ($userID instanceof User) {
            $this->user = $userID;
            $this->userID = $this->user->getId();
        } else {
            if (empty($userID)) {
                $userID = \Xtra::app()->session->get('authUserID');
            }
            $this->userID = $userID;
            $this->user = $userMdl->findById($this->userID);
        }

        // Preload client objects
        if (is_array($clientObjects) && !empty($clientObjects)) {
            if (array_key_exists('trainingName', $clientObjects)) {
                $this->trainingName = \xtra::entityEncode($clientObjects['trainingName']);
            }
            if (array_key_exists('sendFauxCC', $clientObjects)) {
                $this->sendFauxCC = $clientObjects['sendFauxCC'];
            }
            if (array_key_exists('clientProfile', $clientObjects)) {
                $this->client = $clientObjects['clientProfile'];
            }
            if (array_key_exists('systemEmails', $clientObjects)) {
                $this->sysMail = $clientObjects['systemEmails'];
            }
            if (array_key_exists('regionLookup', $clientObjects)) {
                $this->regionLookup = $clientObjects['regionLookup'];
            }
            if (array_key_exists('deptLookup', $clientObjects)) {
                $this->deptLookup = $clientObjects['deptLookup'];
            }
        }
        $this->initialize();
    }

    /**
     * Initialize variables needed for sending email
     *
     * @return void
     */
    private function initialize()
    {
        // Load client object
        if (empty($this->client)) {
            $this->client = (new ClientProfile())->findById($this->clientID);
        }

        $this->mailer = new AppMailer();

        $this->EMtype = SysEmail::EMAIL_SEND_DDQ_INVITATION;
        if (empty($this->sysMail)) {
            $this->sysMail = new SystemEmails($this->clientID);
        }
        if (empty($this->regionLookup)) {
            $this->regionLookup = (new Region($this->clientID))->getTenantRegionsLookup();
        }
        if (empty($this->deptLookup)) {
            $this->deptLookup = (new Department($this->clientID))->getTenantDepartmentsLookup();
        }
        $tpl = $this->sysMail->findEmail(
            $this->intakeForm->get('subInLang'),
            $this->EMtype,
            $this->intakeForm->get('caseType')
        );
        if (empty($tpl)) {
            $tpl = new SystemEmails($this->clientID);
            $tpl->setAttributes($this->getDefaultEmailTemplate());
        }
        $this->mailTpl = $tpl;

        // prepare tokenData
        $notifyAddress = $notifyName = '';
        $this->mailer->getNotifySource($this->clientID, $notifyAddress, $notifyName);
        $countryName = (Geography::getVersionInstance())->getCountryNameTranslated(
            $this->case->get('caseCountry'),
            $this->intakeForm->get('subInLang')
        );
        if (empty($this->trainingName)) {
            $legacyId = 'L-' . $this->intakeForm->get('caseType') . $this->intakeForm->get('ddqQuestionVer');
            $ddqName  = new DdqName($this->clientID);
            $this->trainingName
                = $ddqName->selectValue('name', ['clientID' => $this->clientID, 'legacyID' => $legacyId]);
        }
        $this->tokenData = [
            'CLIENT_NAME' => $this->client->get('clientName'),
            'ddqTitle' => $this->client->get('ddqTitle'),
            'ddq.loginEmail' => $this->intakeForm->get('loginEmail'),
            'ddq.POCname' => $this->intakeForm->get('POCname'),
            'ddq.name' => $this->intakeForm->get('name'), // company legal name
            'ddqName.name' => $this->trainingName,
            'DDQ_LINK' => $this->intakeForm->getLink(),
            'users.userEmail' => $notifyAddress,
            'users.userName' => $notifyName,
            'cases.userCaseNum' => $this->case->get('userCaseNum'),
            'cases.region' => $this->getRegionName($this->case->get('region')),
            'cases.caseCountry' => $countryName,
            'CURRENT_DATE' => date("Y-m-d"),
        ];
    }

    /**
     * Send invitation and faux CC
     *
     * @return true|error_string
     */
    public function sendInvite()
    {
        $userErr = false;
        if (!($this->user instanceof User)) {
            $userErr = 'Invalid user';
        } elseif ($this->validEmailPattern($this->user->get('userEmail'))) {
            $from = $this->user->get('userEmail');
        } elseif ($this->validEmailPattern($this->user->get('userid'))) {
            $from = $this->user->get('userid');
        } else {
            $userErr = "User #$this->userID has no valid email adddress";
        }
        if ($userErr) {
            return $userErr;
        }
        $fromName = $this->user->get('userName');

        // Notification source
        $notifyAddress = $notifyName = '';
        $this->mailer->getNotifySource($this->clientID, $notifyAddress, $notifyName);

        $cleaner = new CleanStr();
        $logger = new SystemEmailLog($this->clientID);
        $subject = $this->sysMail->tokenReplace('', $this->tokenData, $this->mailTpl->get('EMsubject'));
        $cleaner->testTxt($subject);
        $ccSubject = 'CC: ' . $subject;

        // Invitation
        $body = $this->sysMail->tokenReplace(
            '',
            array_merge($this->tokenData, ['Password' => $this->intakeForm->get('passWord')]),
            $this->mailTpl->get('EMbody')
        );
        $isHTML = ((str_contains($body, '<br/>')) || (str_contains($body, '<br />')));
        $hasHTMLtag = (bool)preg_match('#<html#i', $body);
        $this->mailer->useHtmlWrapper = $isHTML && !$hasHTMLtag;

        $this->mailer->AddAddress(
            $this->intakeForm->get('POCemail'),
            $this->intakeForm->get('POCname')
        );
        $this->mailer->From = $from;
        $this->mailer->FromName = $fromName;
        $this->mailer->Subject = $subject;
        $this->mailer->htmlBody = $body;
        $this->mailer->AltBody = $body;
        $this->mailer->Body = $body;
        //$this->mailer->SMTPDebug = 4;
        $mailResult = $this->mailer->sendMessage($this->clientID);
        if ($mailResult === true) {
            $logFrom = $fromName ? "$fromName <$from>" : $from;
            $logTo = $this->intakeForm->get('POCname')
                . ' <' . $this->intakeForm->get('POCemail') . '>';
            $okToLog = $logger->setAttributes(
                [
                    'sender' => $logFrom,
                    'recipient' => $logTo,
                    'EMtype' => $this->EMtype,
                    'funcID' => $this->case->getId(),
                    'caseID' => $this->case->getId(),
                    'tpID' => $this->case->get('tpID'),
                    'clientID' => $this->clientID,
                    'subject' => $subject,
                ]
            );
            if ($okToLog) {
                $logger->save();
            }
        }

        // ### Bulk Traiining Invites should DEFINITELY NOT send fauxCC to ANYONE ###
        if (!$this->sendFauxCC) {
            return $mailResult;
        }

        // Faux CC
        $EMbody = $this->mailTpl->get('EMbody');
        $dblSpace = ($isHTML) ? "<br />\n<br />\n" : "\n\n";
        $caseNum = $this->case->get('userCaseNum');
        $ccPrefix = "Case Number: $caseNum" . $dblSpace;
        $ccBody = $ccPrefix . $this->sysMail->tokenReplace(
            '',
            array_merge($this->tokenData, ['Password' => 'NOT DISPLAYED']),
            $EMbody
        );
        // Odd client variant from legacy
        //@see public_html/cms/includes/php/funcs_sysmail2.php:581
        if (!in_array($this->clientID, [ClientIds::SMITH_NEPHEW_CLIENTID])) {
            $notifyName = '';
        }

        $this->mailer->clearAllRecipients();
        $this->mailer->Sender = $notifyAddress;
        $this->mailer->AddAddress($from, $fromName); // back to $this->user
        $EMcc = $this->mailTpl->get('EMcc');
        if ($EMcc) {
            if (str_contains((string) $EMcc, '3POWNER:')) {
                //look up 3P owner email
                $tpOwnerEmail = $this->sysMail->lookup3POWNERemail($this->case->get('tpID'));
                if ($tpOwnerEmail) {
                    $EMcc = str_replace('3POWNER:', $tpOwnerEmail, (string) $EMcc);
                }
            }
            $this->mailer->addAddressList('cc', $EMcc);
        }
        $BNincoming = $this->user->get('BNincomingEMail');
        if ($BNincoming) {
            $this->mailer->addAddressList('cc', $BNincoming);
        }

        $this->mailer->useHtmlWrapper = $isHTML && !$hasHTMLtag;
        $this->mailer->From = $notifyAddress;
        $this->mailer->FromName = $notifyName;
        $this->mailer->Subject = $ccSubject;
        $this->mailer->htmlBody = $ccBody;
        $this->mailer->AltBody = $ccBody;
        $this->mailer->Body = $ccBody;
        $cc = $this->mailer->getCcAddresses(); // capture for log
        $ccResult = $this->mailer->sendMessage($this->clientID);
        if ($ccResult === true) {
            $ccAddresses = [];
            if ($cc) {
                foreach ($cc as $addrAry) {
                    $ccAddresses[] = $addrAry[0];
                }
            }
            $logFrom = $notifyName ? "$notifyName <$notifyAddress>" : $notifyAddress;
            $logTo = $this->user->get('userName') . ' <' . $this->user->get('userEmail') . '>';
            $okToLog = $logger->setAttributes(
                [
                    'sender' => $logFrom,
                    'recipient' => $logTo,
                    'EMtype' => $this->EMtype,
                    'funcID' => $this->case->getId(),
                    'caseID' => $this->case->getId(),
                    'tpID' => $this->case->get('tpID'),
                    'clientID' => $this->clientID,
                    'subject' => $ccSubject,
                    'cc' => ($ccAddresses ? implode(', ', $ccAddresses) : ''),
                ]
            );
            if ($okToLog) {
                $logger->save();
            }
        }

        return $mailResult;
    }

    /**
     * Lookup region name from id
     *
     * @param int $id resion.id
     *
     * @return string
     */
    private function getRegionName($id)
    {
        if (array_key_exists($id, $this->regionLookup)) {
            $name = $this->regionLookup[$id];
        } else {
            $name = "(region #$id)";
        }
        return $name;
    }

    /**
     * Lookup department name from id
     *
     * @param int $id department.id
     *
     * @return string
     */
    private function getDepartmentName($id)
    {
        if (array_key_exists($id, $this->deptLookup)) {
            $name = $this->deptLookup[$id];
        } else {
            $name = "(dept #$id)";
        }
        return $name;
    }

    /**
     * Return a default template when none can be found in systemEmails
     *
     * @return array mimic assoc row row db
     */
    private function getDefaultEmailTemplate()
    {
        $subject = 'Invitation to complete <ddqName.name> for <CLIENT_NAME>';
        $body = <<< EOT
<br/>Dear <ddq.POCname><br/>
<br/>
Before you can qualify to partner with <CLIENT_NAME> to do business, you must attend online training for ABAC.
You may do this by following the link below and logging in with the following Credentials <br/>
<br/>
User ID = <ddq.loginEmail> <br/>
Password = <Password> <br/>
<br/>
<DDQ_LINK><br/>
<br/>
<br/>
Sincerely, <br/>
<br/>
<users.userName><br/>
<CLIENT_NAME>

EOT;
        return [
            'clientID' => $this->clientID,
            'languageCode' => 'EN_US',
            'EMtype' => $this->EMtype,
            'caseType' => $this->intakeForm->get('caseType'),
            'EMsubject' => $subject,
            'EMbody' => $body,
            'EMrecipient' => null,
            'EMcc' => null,
        ];
    }

    /**
     * Return languageCode used to send invitation
     *
     * @return string
     */
    public function getMailLang()
    {
        return $this->mailTpl->get('languageCode');
    }

    /**
     * Return systemEmails.id used to send invitation
     *
     * @return integer
     */
    public function getTemplateID()
    {
        $id = $this->mailTpl->getId();
        return (empty($id) ? 0 : $id);
    }
}
