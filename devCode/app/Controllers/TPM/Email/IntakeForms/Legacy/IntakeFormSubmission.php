<?php
/**
 * Format and send Intake Form (DDQ) Submissions.
 *
 * @keywords intake form, ddq, submission, email
 */

namespace Controllers\TPM\Email\IntakeForms\Legacy;

use Controllers\TPM\Email\Observers\EmailLoggingInterface;
use Controllers\TPM\Email\Observers\Logging;
use Controllers\TPM\Email\SystemEmail;
use Lib\Legacy\ClientIds;
use Lib\Legacy\SysEmail;
use Lib\Traits\EmailHelpers;
use Models\Globals\Geography;
use Models\Ddq;
use Models\Globals\EmailBaseModel;
use Models\Globals\Region;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ClientProfile;
use Models\ThirdPartyManagement\RelationshipType;
use Models\ThirdPartyManagement\SubjectInfoDD;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\SystemEmails;
use Models\User;

/**
 * Class IntakeFormSubmission
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
class IntakeFormSubmission extends SystemEmail implements EmailLoggingInterface
{
    use EmailHelpers;

    /**
     * Initialize class for sending an Intake Form submission notification
     *
     * @param integer $tenantID     ID client sending email
     * @param integer $intakeFormID ID of the intake form (ddq.id)
     * @param integer $userID       ID of currently logged in user. Will try to retrieve from session if not provided
     * @param boolean $isAPI        If true, this was called via the API
     *
     * @return void
     */
    public function __construct($tenantID, $intakeFormID, $userID = 0, $isAPI = false)
    {
        $tenantID = (int)$tenantID;
        $intakeFormID = (int)$intakeFormID;
        $userID = (int)$userID;
        if (empty($tenantID)) {
            throw new \Exception('Insufficient parameters: missing tenantID.');
        }
        if (empty($intakeFormID)) {
            throw new \Exception('Insufficient parameters: missing intakeFormID.');
        }
        $this->setEmType(SysEmail::EMAIL_NOTIFY_CLIENT_DDQ_SUBMIT);
        if (empty($userID)) {
            $userID = \Xtra::app()->session->get('authUserID');
        }
        $this->userID = $userID;
        $this->tenantID = $tenantID;
        $this->intakeForm = (new Ddq($tenantID, ['authUserID' => $userID, 'isAPI' => $isAPI]))->findById($intakeFormID);
        $this->intakeForm->getAttributes();
        if (!is_object($this->intakeForm)) {
            throw new \Exception('Unable to find intake form with id: ' . $intakeFormID);
        }
        $this->case = (new Cases($this->tenantID, ['authUserID' => $userID]))->findById(
            $this->intakeForm->get('caseID')
        );
        $this->case->getAttributes();
        if (!is_object($this->case)) {
            throw new \Exception('Unable to find case with id: ' . $this->intakeForm->get('caseID'));
        }
        $this->attach(new Logging());
        parent::__construct($this->tenantID, $this->case->get('id'), ['refType' => 'caseID']);
    }

    /**
     * Explicitly clean up created objects
     */
    public function __destruct()
    {
        unset($this->intakeForm, $this->user, $this->case, $this->tenantID);
        parent::__destruct();
    }

    /**
     * Initialize variables needed for sending email
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function initialize()
    {
        // Load client object
        $this->client = new ClientProfile(['clientID' => $this->tenantID]);
        $this->client = $this->client->findById($this->tenantID);
        if (!is_object($this->client)) {
            throw new \Exception('Unable to find client with id: ' . $this->tenantID);
        }
        $this->user = new User();
        $this->user = $this->user->findById($this->userID);
        if (!is_object($this->user)) {
            throw new \Exception('Unable to find user with id: ' . $this->userID);
        }

        // Set email tokens that can be used for this email type.
        $this->addEmailTokens();
    }

    /**
     * Prepare subject for sending
     *
     * @return void
     *
     * @throws \Exception
     */
    public function prepareSubject()
    {
        if (is_array($this->emails) && count($this->emails) > 1) {
            /*
             * @var EmailBaseModel $email
             */

            $cnt = 0;
            foreach ($this->emails as &$email) {
                if ($cnt > 0) {
                    // Add CC to Email subject
                    $subject = 'CC: ' . $email->getSubject();
                    $email->setSubject($subject);
                }
                $cnt++;
            }
        }
    }

    /**
     * Prepare body for sending
     *
     * @return void
     *
     * @throws \Exception
     */
    public function prepareBody()
    {
        if (is_array($this->emails) && count($this->emails) > 1) {
            /*
             * @var EmailBaseModel $email
             */

            $cnt = 0;
            foreach ($this->emails as &$email) {
                if ($cnt > 0) {
                    if ($email->isHtml()) {
                        $email->setHtmlBody($email->getHtmlBody());
                    } else {
                        $email->setBody($email->getBody());
                    }
                }
                $cnt++;
            }
        }
    }

    /**
     * Return the case ID when logging these emails instead of the ref (Intake Form) id.
     * This will not be used by this class, as it was only supplied as required by the EmailLoggingInterface.
     *
     * @return integer
     */
    public function getRefId()
    {
        return 0; // Not used
    }

    /**
     * Retrieve the multiple ID's associated with email that will be set in email log table (e.g. caseID and tpID)
     *
     * @return array
     */
    public function getRefIds()
    {
        return ['caseID' => $this->case->get('id'), 'tpID' => $this->case->get('tpID')];
    }

    /**
     * Return default email subject and body.
     *
     * @return EmailBaseModel
     */
    public function getDefaultEmail()
    {
        return $this->intakeForm->getDefaultEmail(SysEmail::EMAIL_NOTIFY_CLIENT_DDQ_SUBMIT);
    }

    /**
     * Add replaceable email tokens
     *
     * @return void
     */
    private function addEmailTokens()
    {
        $tokens = [
            'subjectInfoDD.name',
            'cases.userCaseNum',
            'cases.caseName',
            'ddq.subByName',
            'Country.name',
            'clientProfile.regionTitle',
            'region.name',
            'ddqName.name',
            'relationshipType.name',
            'LOGIN_URL'
        ];
        $caseID = $this->case->get('id');
        $subjectInfoDDname = 'Unknown';
        $subjectInfoDDType = 0;
        if ($subjectInfoDD = (new SubjectInfoDD($this->tenantID))->findByAttributes(['caseID' => $caseID])) {
            $subjectInfoDD->getAttributes();
            if (!empty($subjectInfoDD->get('name'))) {
                $subjectInfoDDname =  $subjectInfoDD->get('name');
            }
            if (!empty($subjectInfoDD->get('subType'))) {
                $subjectInfoDDType =  $subjectInfoDD->get('subType');
            }
        }
        $relationshipTypeName = '';
        if ($relationshipType = (new RelationshipType($this->tenantID))->findById($subjectInfoDDType)) {
            $relationshipType->getAttributes();
            if (!empty($relationshipType->get('name'))) {
                $relationshipTypeName = $relationshipType->get('name');
            }
        }
        $legacyID = 'L-' . $this->intakeForm->get('caseType') . $this->intakeForm->get('ddqQuestionVer');
        $ddqName = (new DdqName($this->tenantID))->selectOne('name', ['legacyID' => $legacyID]);
        $this->addTokenData('subjectInfoDD.name', $subjectInfoDDname);
        $this->addTokenData('cases.userCaseNum', $this->case->get('userCaseNum'));
        $this->addTokenData('cases.caseName', $this->case->get('caseName'));
        $this->addTokenData('ddq.subByName', $this->intakeForm->get('subByName'));
        $this->addTokenData(
            'Country.name',
            (Geography::getVersionInstance())->getCountryNameTranslated(
                $this->case->get('caseCountry'),
                $this->intakeForm->get('subInLang')
            )
        );
        $this->addTokenData('clientProfile.regionTitle', $this->client->get('regionTitle'));
        $this->addTokenData('region.name', (new Region($this->tenantID))->getRegionName($this->case->get('region')));
        $this->addTokenData('ddqName.name', $ddqName['name']);
        $this->addTokenData('relationshipType.name', $relationshipTypeName);
        $this->addTokenData('LOGIN_URL', (new SysEmail($this->tenantID))->buildLinkURL($caseID, true));
        if ($this->tenantID == ClientIds::GP_CLIENTID) {
            $this->addTokenData('ddq.genQuest133', $this->intakeForm->get('genQuest133'));
            $tokens[] = 'ddq.genQuest133';
        }
        $this->setLegacyTokens($tokens);
    }

    /**
     * Load email for sending
     *
     * @param EmailBaseModel $email  Instance of EmailBaseModel containing data for sending the current email type
     * @param Bool           $fauxCc This email requires a faux CC email be sent along with the main email.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function loadEmail(EmailBaseModel $email = null, $fauxCc = false)
    {
        if (is_null($email)) {
            $email = $this->getEmail($this->intakeForm->get('subInLang'), $this->intakeForm->get('caseType'));
        }

        $this->setEmailFrom($email);

        $this->addTokenData('users.userEmail', $email->getFrom());
        $this->addTokenData('users.userName', $email->getFromName());
        $this->setLegacyTokens([
            'users.userEmail',
            'users.userName',
        ]);

        // Set recipients
        $recipients = $this->intakeForm->getSubmissionRecipients(
            $this->case->get('caseCountry'),
            $this->intakeForm->get('caseType'),
            $this->case->get('requestor')
        );
        foreach ($recipients['to'] as $to) {
            $email->addTo($to);
        }
        foreach ($recipients['cc'] as $cc) {
            $email->addCc($cc);
        }
        parent::loadEmail($email);
    }

    /**
     * Set Email From values
     *
     * @param EmailBaseModel $email Instance of the email to update
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function setEmailFrom(&$email)
    {
        $email->setFrom($_ENV['emailNotifyAddress']);
    }

    /**
     * Attempt to retrieve language code. If not found fall back to parent method finding language code.
     *
     * @return string
     */
    protected function getLanguageCode()
    {
        $rtn = parent::getLanguageCode();
        if (($languageCode = $this->intakeForm->get('subInLang')) && !empty($languageCode)) {
            $rtn = $languageCode;
        }
        return $rtn;
    }
}
