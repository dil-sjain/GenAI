<?php
/**
 * @see: SEC-3080 & 3081
 *
 * @dev: See Delta class functionality in app/Models/Globals/Geography.php.
 */

require_once __DIR__ . '/../includes/php/'.'class_db.php';

#[\AllowDynamicProperties]
class SanctionedProhibitedCountries
{
    /**
     * @var dbClass|null Legacy database object
     */
    private $db = null;

    /**
     * Investigator email address to contact for case on sanctioned country
     * Must be sanctionedCountryContact in .env
     *
     * @var string Investigator contact for case on sanctioned country
     */
    private $notifyEmail = '';

    /**
     * @var \Legacy\Models\Globals\Geography|null instance of
     */
    private $geo = null;

    /**
     * Get class instance and set properties
     *
     * @param dbClass $dbObject Database class instance
     */
    public function __construct($dbObject)
    {
        if (!is_a($dbObject, 'dbClass')) {
            throw new \InvalidArgumentException("Constructor requires a valid database object.");
        }
        $this->notifyEmail = $_ENV['sanctionedCountryContact'] ?? '';
        $this->db  = $dbObject;
        include_once __DIR__ . '/../includes/php/LoadClass.php';
        $loader = LoadClass::getInstance();
        $this->geo = $loader->geography();
    }

    /**
     * Returns a boolean indicating the given country has sanctioned country status.
     *
     * @param string $countryValue ISO code, number or name of country to find
     *
     * @return bool true if country is sanctioned.
     */
    public function isCountrySanctioned($countryValue)
    {
        return $this->geo->isCountrySanctioned($countryValue);
    }

    /**
     * Returns a boolean indicating the given country has prohibited country status.
     *
     * @param string $countryValue ISO code, number or name of country to find
     *
     * @return  bool    true if country is prohibited.
     */
    public function isCountryProhibited($countryValue)
    {
        return $this->geo->isCountryProhibited($countryValue);
    }

    /**
     * Send a notification when a case is created on a sanctioned country
     *
     * @param int $caseID cases.id
     *
     * @return void
     *
     * @throws Exception
     */
    public function sendSanctionedCaseNotification($caseID = 0)
    {
        if (empty($this->notifyEmail)) {
            return;
        }
        $sql = "SELECT userCaseNum, clientID FROM cases WHERE id = " . intval($caseID);
        if ($case = $this->db->fetchAssocRow($sql)) {
            $caseNum = $case['userCaseNum'];
            $tenantID = $case['clientID'];
            $sbj = "New Case Assignment";
            $msg = "The following case has been assigned to you and is waiting to be processed: $caseNum\n"

                . "Link: " . fBuildLinkUrl($caseID, $tenantID, true);

            AppMailer::mail(
                $tenantID,
                $this->notifyEmail,
                $sbj,
                $msg,
                ['forceSystemEmail' => true]
            );
        }
    }
}
