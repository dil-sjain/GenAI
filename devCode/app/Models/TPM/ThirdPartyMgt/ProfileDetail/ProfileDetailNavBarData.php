<?php
/**
 * Model for the "Profile Detail" sub-tabs
 *
 * @see This model is a refactor of the getOqLabelText method from Legacy ddq_funcs.php
 */

namespace Models\TPM\ThirdPartyMgt\ProfileDetail;

use Models\TPM\Compliance;

/**
 * Class ProfileDetailNavBarData gets the text for the nav bar labels that are related to DDQ's and
 * logic to determine access to its sub-tabs.
 *
 * @keywords profile detail tab text, profile detail navigation text
 */
#[\AllowDynamicProperties]
class ProfileDetailNavBarData
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
     * @param int $clientID Client ID
     */
    public function __construct(private $clientID)
    {
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
    }

    /**
     * Determine if access is allowed to the 3P Custom Fields tab
     *
     * @return boolean True if access is allowed, else false
     */
    public function allowCustomFieldsAccess()
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->clientDB}.customField "
            . "WHERE clientID = :clientID AND scope = 'thirdparty'";
        $params = [':clientID' => $this->clientID];
        return ($this->app->ftr->has(\Feature::TP_CUSTOM_FLDS) && $this->DB->fetchValue($sql, $params));
    }
    
    /**
     * Determine if access is allowed to the Training tab
     *
     * @return boolean True if access is allowed, else false
     */
    public function allowTrainingAccess()
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->clientDB}.trainingType "
            . "WHERE clientID = :clientID AND active = :active ";
        $params = [':clientID' => $this->clientID, ':active' => 1];
        return (bool) $this->DB->fetchValue($sql, $params);
    }


    /**
     * Determine if access is allowed to the Compliance tab by checking if there are any active compliance factors
     *
     * @param int $tpID Third Party Profile ID
     *
     * @return integer $allowAccess Access is allowed if > 0
     */
    public function allowComplianceAccess($tpID)
    {
        $allowAccess = false;
        if ($this->app->ftr->has(\Feature::TENANT_TPM_COMPLIANCE)) {
            $compliance = new Compliance($this->clientID);
            $tierWithComply = $compliance->useTier;
            $allowAccess = ($compliance->hasFactors);

            if ($allowAccess) {
                $sql = "SELECT tpType, tpTypeCategory, riskModel FROM {$this->clientDB}.thirdPartyProfile "
                    . "WHERE id = :tpID AND clientID = :clientID LIMIT 1";
                $params = [':tpID' => $tpID, ':clientID' => $this->clientID];
                [$tpType, $tpTypeCat, $riskModel] = $this->DB->fetchIndexedRow($sql, $params);

                if ($tierWithComply && $riskModel > 0) {
                    $sql = "SELECT tier FROM {$this->clientDB}.riskAssessment WHERE tpID = :tpID "
                        . "AND status = 'current' AND model = :riskModel AND clientID = :clientID LIMIT 1";
                    $params[':riskModel'] = $riskModel;

                    if ($tpTier = $this->DB->fetchValue($sql, $params)) {
                        $variDef = $compliance->matchVarianceDef($tpTier, $tpType, $tpTypeCat);
                    } else {
                        $variDef = $compliance->matchVarianceDef(0, $tpType, $tpTypeCat);
                    }
                } else {
                    // no tier
                    $variDef = $compliance->matchVarianceDef(0, $tpType, $tpTypeCat);
                }

                $sql = "SELECT factorID, compliance, LEFT(tstamp,10) AS tstamp "
                    . "FROM {$this->clientDB}.tpComply WHERE tpID = :tpID AND "
                    . "FIND_IN_SET(factorID, :idList)";
                $params = [':tpID' => $tpID, ':idList' => $variDef->idList];
                $compRows = $this->DB->fetchObjectRows($sql, $params);

                $compScore = $compliance->calcScore($variDef->variance, $compRows, true);
                $allowAccess = (is_array($compScore->info) && count($compScore->info));
            }
        }
        return $allowAccess;
    }
}
