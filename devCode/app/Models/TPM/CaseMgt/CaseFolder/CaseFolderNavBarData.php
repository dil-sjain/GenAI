<?php
/**
 * Model for the "Case Folder" sub-tabs
 *
 * @see This model is a refactor of the getOqLabelText method from Legacy ddq_funcs.php
 */

namespace Models\TPM\CaseMgt\CaseFolder;

use Lib\Legacy\UserType;

/**
 * Class CaseFolderNavBarData gets the text for the nav bar labels that are related to DDQ's and
 * logic to determine access to the Custom Fields tab.
 *
 * @keywords case folder tab text, case folder navigation text
 */
#[\AllowDynamicProperties]
class CaseFolderNavBarData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var string Client database name
     */
    private $clientDB = null;

    /**
     * @var object Database instance
     */
    protected $DB = null;

    /**
     * Init class constructor
     *
     * @param integer $clientID Client ID
     *
     * @return void
     */
    public function __construct($clientID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($clientID);
    }

    /**
     * Return labelText value from onlineQuestions
     *
     * @param int    $clientID       tenant ID
     * @param string $questionID     onlineQuestions key
     * @param string $languageCode   Country_Language
     * @param int    $caseType       Case Type
     * @param string $ddqQuestionVer DDQ version
     *
     * return string $labelText, if none found, then blank.
     */
    public function getLabel($clientID, $questionID, $languageCode, $caseType, $ddqQuestionVer)
    {
        $sql = "SELECT labelText FROM {$this->clientDB}.onlineQuestions WHERE clientID = :clientID "
            . "AND questionID = :questionID AND languageCode = :languageCode "
            . "AND caseType = :caseType AND ddqQuestionVer = :ddqQuestionVer";

        $params = [':clientID' => $clientID, ':questionID' => $questionID, ':languageCode' => $languageCode,
            ':caseType' => $caseType, ':ddqQuestionVer' => $ddqQuestionVer ];

        $labelText = $this->DB->fetchValue($sql, $params);

        if (empty($labelText)) {
            $params[':clientID'] = 0;
            $labelText = $this->DB->fetchValue($sql, $params);
        }

        $labelText = trim(str_replace(["'", "&apos;", "&#39;", "&lsquo;", "&#8216;", "&rsquo;", "&#8217;"], "", (string) $labelText));
        return $labelText;
    }

    /**
     * Return labelText value from onlineQuestions
     *
     * @param int $clientID Client ID
     * @param int $tpID     ThirdParty ID
     *
     * @return boolean $allowAccess True/false indicator if acccess is allowed
     */
    public function allowCustomFieldAccess($clientID, $tpID)
    {
        $allowAccess = false;

        if ($this->app->session->get('authUserType') >= UserType::VENDOR_ADMIN) {
            // Determine based on scope
            $sql = "SELECT COUNT(*) AS cnt FROM {$this->clientDB}.customField "
                . "WHERE clientID = :clientID AND scope = 'case' AND hide = 0";
            $params = [':clientID' => $clientID];
            $csFldCnt = $this->DB->fetchValue($sql, $params);

            // tp only matters if there's tpData, since we can't edit here
            $tpDataCnt = 0;
            $sql = "SELECT COUNT(*) AS cnt FROM {$this->clientDB}.customField "
                . "WHERE clientID = :clientID AND scope = 'thirdparty' AND hide = 0";
            $tpFldCnt = $this->DB->fetchValue($sql, $params);

            if ($tpID && $this->app->ftr->has(\Feature::TP_CUSTOM_FLDS) && $tpFldCnt) {
                $sql = "SELECT COUNT(*) FROM {$this->clientDB}.customData "
                    . "WHERE tpID = :tpID AND clientID = :clientID AND hide = 0";
                $params = [':tpID' => $tpID, ':clientID' => $clientID];
                $tpDataCnt = $this->DB->fetchValue($sql, $params);
            }
            $allowAccess = ($csFldCnt || $tpDataCnt);
        }

        return $allowAccess;
    }
}
