<?php
/**
 * Base controller for emails that are based on a Case or Intake Form
 *
 * @keywords email, case, intake form
 */

namespace Controllers\TPM\Email\Cases;

use Controllers\TPM\Email\Observers\EmailLoggingInterface;
use Controllers\TPM\Email\Observers\Logging;
use Controllers\TPM\Email\SystemEmail;
use Models\Ddq;
use Models\Globals\EmailBaseModel;
use Models\SP\InvestigatorProfile;
use Models\SP\ServiceProvider;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ClientProfile;
use Models\ThirdPartyManagement\ThirdParty;
use Models\User;

/**
 * Class CaseEmail
 *
 * @package Controllers\TPM\Email\Cases
 */
#[\AllowDynamicProperties]
abstract class CaseEmail extends SystemEmail implements EmailLoggingInterface
{
    /**
     * @var \Models\Ddq Holds an instance of the IntakeForm we are working with
     */
    protected $intakeForm = null;

    /**
     * @var int ID of the current case
     */
    protected $caseID;

    /**
     * @var \Models\ThirdPartyManagement\Cases An instance of case associated with the IntakeForm or null
     */
    protected $case = null;

    /**
     * @var InvestigatorProfile Holds an instance of the case investigator profile
     */
    protected $investigator = null;

    /**
     * These 3 values are here for matching up data that was handled in legacy. Should be able to refine this when
     * case management is refactored.
     *
     * @var User|array Investigator info array
     */
    protected $caseInvestigator = [
        'firstName' => '',
        'lastName'  => '',
        'userEmail' => ''
    ];

    /**
     * The assigning PM if applicable
     *
     * @var User
     */
    protected $caseAssigningPM = null;
    protected $vendorRecipient = '';
    protected $displayName     = '';

    /**
     * Initialize class for sending an Intake Form invite
     *
     * @param int $clientID ID client sending email
     * @param int $caseID   ID of main object needed to send email
     */
    public function __construct($clientID, $caseID)
    {
        $this->attach(new Logging());
        parent::__construct($clientID, $caseID, ['refType' => 'caseID']);
    }

    /**
     * Explicitly clean up created objects
     */
    public function __destruct()
    {
        unset($this->intakeForm, $this->user, $this->case, $this->investigator);
        parent::__destruct();
    }

    /**
     * Initialize variables needed for sending email
     *
     * @throws \Exception
     * @return void
     */
    protected function initialize()
    {
        // If case ID has not been set then use refID
        if (empty($this->caseID)) {
            $this->caseID = $this->refID;
        }
        $this->case = new Cases($this->tenantID);
        $this->case = $this->case->findById($this->caseID);
        if (!is_object($this->case)) {
            throw new \Exception('Unable to find case with id: ' . $this->caseID);
        }

        // Try to load an intake form associated with the Case.
        $this->intakeForm = (new Ddq($this->tenantID))->findByAttributes(['caseID' => $this->caseID]);

        $this->thirdPartyProfile = new ThirdParty($this->tenantID);
        $this->thirdPartyProfile = $this->thirdPartyProfile->findById($this->case->get('tpID'));
        if (!is_object($this->thirdPartyProfile)) {
            throw new \Exception('No Third Party associated with Case.');
        }

        if (!is_object($this->client)) {
            $this->client = new ClientProfile();
            $this->client = $this->client->findById($this->tenantID);
            if (!is_object($this->client)) {
                throw new \Exception('Unable to find client with id: ' . $this->tenantID);
            }
        }

        // If user is not already set we use the case requester
        if (!is_object($this->user)) {
            $this->user = new User();
            $this->user = $this->user->findByAttributes(['userid' => $this->case->get('requestor')]);
            if (!is_object($this->user)) {
                throw new \Exception('Unable to find user with userid: ' . $this->case->get('requestor'));
            }
        }

        // Get assigned investigator profile. This can be unassigned.
        $investigator   = $this->case->get('caseAssignedAgent');
        $investigatorID = $this->case->get('caseInvestigatorUserID');
        $this->investigator = (new InvestigatorProfile())->findById($investigator);

        // Load the assigning PM if possible
        $pmID = $this->case->get('assigningProjectMgrID');
        if (!empty($pmID) && is_int($pmID)) {
            $this->caseAssigningPM = (new User())->findById($pmID);
        }
        
        if (is_object($this->investigator) || !empty($investigatorID)) {
            if (!empty($investigatorID) && $investigatorID != ServiceProvider::STEELE_VENDOR_ADMIN) {
                $this->caseInvestigator = (new User())->findById($investigatorID);
                $this->vendorRecipient  = $this->caseInvestigator->get('userEmail');
                $this->displayName      = $this->caseInvestigator->get('firstName') . ' '
                    . $this->caseInvestigator->get('lastName');
            } elseif (is_object($this->investigator)) {
                $this->caseInvestigator = [
                    'firstName' => $this->investigator->get('investigatorName'),
                    'lastName'  => '',
                    'userEmail' => $this->investigator->get('investigatorEmail')
                ];
                $this->vendorRecipient = $this->caseInvestigator['userEmail'];
                $this->displayName     = $this->caseInvestigator['firstName'];
            }
        }
    }

    /**
     * Prepare subject for sending
     *
     * @throws \Exception
     * @return void
     */
    public function prepareSubject()
    {
        if (is_array($this->emails) && count($this->emails) > 1) {
            /**
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
     * @throws \Exception
     * @return void
     */
    public function prepareBody()
    {
        if (is_array($this->emails) && count($this->emails) > 1) {
            /**
             * @var EmailBaseModel $email
             */
            $cnt = 0;
            foreach ($this->emails as &$email) {
                if ($cnt > 0) {
                    // Add case number to top of CC body
                    $body = 'Case Number: ' . $this->case->get('userCaseNum');
                    if ($email->isHtml()) {
                        $htmlBody = $body;
                        $htmlBody .= "<br />\n<br />\n";
                        $htmlBody .= $email->getHtmlBody();

                        // Ensure removal of password replacement token for CC emails
                        $htmlBody = str_replace('<Password>', 'NOT DISPLAYED', $htmlBody);
                        $htmlBody = str_replace('{Password}', 'NOT DISPLAYED', $htmlBody);
                    } else {
                        $body .= "\n\n";
                        $body .= $email->getBody();
                    }

                    // Ensure removal of password replacement token for CC emails
                    $body = str_replace('<Password>', 'NOT DISPLAYED', $body);
                    $body = str_replace('{Password}', 'NOT DISPLAYED', $body);

                    $email->setBody($body);
                    if (isset($htmlBody)) {
                        $email->setHtmlBody($htmlBody);
                    }

                    $this->fauxCC = true;
                }

                $cnt++;
            }
        }
    }

    /**
     * Return the case ID when logging these emails instead of the ref (Intake Form) id
     *
     * @return int
     */
    public function getRefId()
    {
        return $this->caseID;
    }

    /**
     * Retrieve the multiple ID's associated with email that will be set in email log table (e.g. caseID and tpID)
     * This will not be used by this class, as it was only supplied as required by the EmailLoggingInterface.
     *
     * @return array
     */
    public function getRefIds()
    {
        return []; // Not used
    }
}
