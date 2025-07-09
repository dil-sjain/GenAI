<?php
/**
 * Provides basic read/write access to ddqName table
 */

namespace Models\TPM\IntakeForms;

use Lib\Legacy\LegacyDdqVersion;
use Lib\Traits\SplitDdqLegacyID;
use Models\Ddq;

/**
 * Basic CRUD access to ddqName,  requiring clientID
 *
 * @keywords ddqName, ddq name, intake form, intake form name, ddq
 */
#[\AllowDynamicProperties]
class DdqName extends \Models\BaseLite\RequireClientID
{
    use splitDdqLegacyID;

    /**
     * @var Required by base class
     */
    protected $tbl = 'ddqName';

    /**
     * @var boolean tabled is in a client database
     */
    protected $tableInClientDB = true;

    /**
     * @var string Name of primaryID field
     */
    protected $primaryID = 'id';

    /**
     * Authorized userID
     *
     * @var integer
     */
    protected $authUserID = null;

    /**
     * DDQ invitations are enabled for tenant
     * @var bool
     */
    protected $hasInviteFeature = false;

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
        $clientID = (int)$clientID;
        parent::__construct($clientID, $params);
        $this->clientID = $clientID;
        $this->isAPI = (!empty($params['isAPI']));
        $this->authUserID = (!empty($params['authUserID']))
            ? $params['authUserID']
            : \Xtra::app()->ftr->user;
        $this->hasInviteFeature = $this->tenantHasInviteFeature();
    }

    /**
     * Get formClass value given clientID and caseType
     *
     * @param integer $caseType ddqName.caseType
     *
     * @return string formClass
     */
    public function getFormClassByCaseType($caseType)
    {
        $caseType = (int)$caseType;
        $sql = "SELECT formClass FROM {$this->clientDB}.ddqName\n"
            . "WHERE clientID = :clientID AND legacyID RLIKE '^L-".$caseType."[^0-9]*$'\n"
            . "ORDER BY legacyID DESC LIMIT 1";
        return $this->DB->fetchValue($sql, [':clientID' => $this->clientID]);
    }

    /**
     * Get current DDQ version for a given clientID and caseType
     *
     * @param integer $caseType ddqName.caseType
     *
     * @return string DDQ version
     */
    public function getFormQuestVerByCaseType($caseType)
    {
        $caseType = (int)$caseType;
        $sql = "SELECT legacyID FROM {$this->clientDB}.ddqName\n"
            . "WHERE clientID = :clientID \n"
            . "AND legacyID RLIKE '^L-".$caseType."[^0-9]*$'\n"
            . "AND `status` = :status \n"
            . "ORDER BY legacyID";
        $legacyIDs = $this->DB->fetchValueArray($sql, [':clientID' => $this->clientID, ':status' => 1]);
        if (!is_array($legacyIDs) || count($legacyIDs) < 1) {
            $sql2 = "SELECT legacyID FROM {$this->clientDB}.ddqName\n"
                . "WHERE clientID = :clientID \n"
                . "AND legacyID RLIKE '^L-".$caseType."[^0-9]*$'\n"
                . "ORDER BY legacyID";
            $legacyIDs = $this->DB->fetchValueArray($sql2, [':clientID' => $this->clientID]);
        }

        $ver = new LegacyDdqVersion();
        return $ver->getHighestDdqVer($legacyIDs);
    }

    /**
     * Get all ddqName records for a tenant
     *
     * @return array ddqName records
     */
    public function getTenantIntakeFormNames()
    {
        return $this->DB->fetchAssocRows(
            "SELECT name, legacyID, formClass FROM {$this->clientDB}.ddqName WHERE clientID = :tenantID",
            [':tenantID' => $this->clientID]
        );
    }

    /**
     * Upsert ddq name. Legacy performs no validation on submitted name
     *
     * @param string $ddqRef LegacyID
     * @param string $name   Name assigned to this questionnaire
     *
     * @return boolean
     */
    public function upsertName($ddqRef, $name)
    {
        if (!preg_match('/^L-\d+[a-z]{0,2}$/', $name)) {
            return false;
        }
        if (trim($name) == '') {
            $name = $ddqRef;
        }
        $curName = $this->selectOne('name', ['legacyID' => $ddqRef]);
        if ($curName !== false) {
            $rtn = $this->update(['name' => $name], ['legacyID' => $ddqRef]);
        } else {
            $rtn = $this->insert(['name' => $name, 'legacyID' => $ddqRef]);
        }
        return $rtn !== false;
    }

    /**
     * Returns an array of active intakeFormTypeIDs
     *
     * @param array
     */
    public function getActiveDdqIntakeFormTypeIDs()
    {
        $rtn = [];
        $invitationTypes = $this->getActiveDdqIntakeFormInvitationTypes();
        foreach ($invitationTypes as $type) {
            $rtn[] = $type['id'];
        }
        $openUrlTypes = $this->getActiveDdqIntakeFormOpenUrlTypes();
        foreach ($openUrlTypes as $type) {
            $rtn[] = $type['id'];
        }
        return $rtn;
    }

    /**
     * Get list of active invitation intake form types for a tenant
     *
     * @return array
     */
    public function getActiveDdqIntakeFormInvitationTypes()
    {
        return $this->formatCfgRows($this->getConfigs());
    }

    /**
     * Get list of active Open URL intake form types for a tenant
     *
     * @return array
     */
    private function getActiveDdqIntakeFormOpenUrlTypes()
    {
        $rtn = [];
        $sql = "SELECT DISTINCT sc.caseType AS typeID FROM {$this->clientDB}.ddqName AS dn\n"
            . "LEFT JOIN {$this->DB->globalDB}.g_intakeFormSecurityCodes AS sc "
            . "ON (sc.clientID = dn.clientID "
            . "AND sc.caseType = (SUBSTRING(dn.legacyID, 3) + 0) AND sc.origin = 'open_url')\n"
            . "WHERE dn.clientID = :clientID AND dn.status = 1 AND sc.id IS NOT NULL\n"
            . "ORDER BY sc.caseType ASC";
        $params = [':clientID' => $this->clientID];
        if ($rows = $this->DB->fetchAssocRows($sql, $params)) {
            foreach ($rows as $row) {
                $rtn[] = ['id' => $row['typeID'], 'version' => $this->getFormQuestVerByCaseType($row['typeID'])];
            }
        }
        return $rtn;
    }

    /**
     * Returns invitation ddqName recs for client
     *
     * @return array
     */
    private function getConfigs()
    {
        $rtn = [];
        if ($this->hasInviteFeature) {
            $sql = "SELECT DISTINCT sc.caseType AS formType, dn.id AS cfgID, dn.formClass, dn.renewalOf\n"
                . "FROM {$this->clientDB}.ddqName AS dn\n"
                . "INNER JOIN {$this->DB->globalDB}.g_intakeFormSecurityCodes AS sc ON (\n"
                . "  sc.clientID = dn.clientID\n"
                . "  AND sc.caseType = (SUBSTRING(dn.legacyID, 3) + 0)\n"
                . "  AND sc.origin = 'invitation'\n"
                . ")\n"
                . "WHERE dn.clientID = :clientID\n"
                . "ORDER BY dn.formClass ASC";
            if ($rows = $this->DB->fetchAssocRows($sql, [':clientID' => $this->clientID])) {
                $rtn = $rows;
            }
        }
        return $rtn;
    }

    /**
     * Collect all languages for the intake form configuration
     *
     * @param integer $intakeFormCfgID ddqName.id
     *
     * @return array
     */
    public function getLanguagesByID($intakeFormCfgID)
    {
        $rtn = [];
        $intakeFormCfgID = (int)$intakeFormCfgID;
        if (!empty($intakeFormCfgID) && ($intakeFormTypes = $this->getActiveDdqIntakeFormInvitationTypes())) {
            foreach ($intakeFormTypes as $intakeFormType) {
                if ($intakeFormType['intakeFormCfgID'] == $intakeFormCfgID) {
                    if (!empty($intakeFormType['languages'])) {
                        foreach ($intakeFormType['languages'] as $language) {
                            $rtn[] = $language['langCode'];
                        }
                    }
                }
            }
        }
        return $rtn;
    }

    /**
     * Format ddqName rows for payload
     *
     * @param array   $rows     ddqName rows
     * @param boolean $isLegacy If true, format payload for Legacy else don't.
     *
     * @return array
     */
    private function formatCfgRows($rows, $isLegacy = true)
    {
        $ddq = new Ddq($this->clientID, ['authUserID' => $this->authUserID, 'isAPI' => $this->isAPI]);
        $formTypeToCfgIDMap = [];
        foreach ($rows as $row) {
            if (!in_array($row['formClass'], ['due-diligence', 'internal'])) {
                continue;
            }

            // Build renewal mapping for progressions identification
            $cfgID = (int)$row['cfgID'];
            $renewalOf = (int)$row['renewalOf'];
            $formType = (int)$row['formType'];
            $version = $this->getFormQuestVerByCaseType($formType);
            $formTypeToCfgIDMap[$formType] = $cfgID;

            if ($formType > 0) {
                // renewal form => the form renewed by it
                $allRenewalOf[$formType] = $renewalOf;

                // record only the current ddqQuestionVer of the forms
                $legacyID = 'L-' . $row['formType'] . $version;
                $sql = "SELECT id, name, status FROM {$this->clientDB}.ddqName\n"
                    . "WHERE clientID = :clientID AND legacyID = :legacyID "
                    . "AND renewalOf = :renewalOf AND status = 1\n"
                    . "LIMIT 1";
                $params = [
                    ':clientID' => $this->clientID,
                    ':legacyID' => $legacyID,
                    ':renewalOf' => $row['renewalOf']
                ];
                if ($dnRow = $this->DB->fetchAssocRow($sql, $params)) {
                    $name = $dnRow['name'];
                    $formExists = true;
                    $formUsable = ($dnRow['status'] == 1);
                } else {
                    $name = '';
                    $formExists = false;
                    $formUsable = false;
                }
                if (empty($name)) {
                    $name = $legacyID;
                }
                if ($formExists && $formUsable) {
                    if ($isLegacy) {
                        $rtn[$formType] = [
                            'id' => $formType,
                            'intakeFormCfgID' => $cfgID,
                            'version' => $version,
                            'name' => $name,
                            'formClass' => $row['formClass'],
                            'languages' => $ddq->getIntakeFormLanguages($formType, $version),
                            'renewalOf' => $renewalOf,
                            'renewedBy' => $formType, // default, renews itself
                            'legacyID' => $legacyID
                        ];
                    } else {
                        $rtn[$formType] = [
                            'id' => $cfgID,
                            'name' => $name,
                            'type' => $row['formClass'],
                            'languages' => $ddq->getIntakeFormLanguages($formType, $version)
                        ];
                        if (!empty($version)) {
                            $rtn[$formType]['version'] = $version;
                        }
                        if (isset($formTypeToCfgIDMap[$renewalOf])) {
                            $rtn[$formType]['renewalOf'] = $formTypeToCfgIDMap[$renewalOf];
                        }
                    }
                }
            }
        }
        $progressions = [];
        // Define start of all progressions
        foreach (array_keys($rtn) as $formType) {
            if ($allRenewalOf[$formType] == 0) {
                $progressions[] = [$formType];
            }
        }

        // Finish construction of renewal progressions
        for ($i = 0; $i < count($progressions); $i++) {
            $renewalOf = $progressions[$i][0]; // get end
            $failsafe = 50;
            while (($formType = array_search($renewalOf, $allRenewalOf)) && $failsafe-- > 0) {
                $progressions[$i][] = $formType;
                $renewalOf = $formType;
            }
        }

        // Provide references to renewal forms
        foreach ($allRenewalOf as $renewedBy => $formType) {
            if ($formType > 0 && array_key_exists($formType, $rtn)) {
                if ($isLegacy) {
                    $rtn[$formType]['renewedBy'] = $renewedBy;
                } elseif (isset($formTypeToCfgIDMap[$renewedBy])) {
                    $rtn[$formType]['renewedBy'] = $formTypeToCfgIDMap[$renewedBy];
                }
            }
        }
        return \Xtra::sortArrayByColumn($rtn, 'id');
    }

    /**
     * Returns intake form configurations
     *
     * @return array
     */
    public function getIntakeFormConfigurations()
    {
        return $this->formatCfgRows($this->getConfigs(), false);
    }

    /**
     * Check if tenant is enabled for invitations
     * @return bool
     */
    private function tenantHasInviteFeature()
    {
        $tbl = $this->DB->globalDB . '.g_tenantFeatures';
        $sql = "SELECT featureID FROM $tbl WHERE tenantID = :tenant AND appID = :app AND featureID = :feature LIMIT 1";
        $params = [
            ':tenant' => $this->clientID,
            ':app' => \Feature::APP_TPM,
            ':feature' => \Feature::TENANT_DDQ_INVITE,
        ];
        return $this->DB->fetchValue($sql, $params) === \Feature::TENANT_DDQ_INVITE;
    }

    /**
     * Property getter for hasInviteFeature
     * @return bool
     */
    public function getInviteFeature()
    {
        return $this->hasInviteFeature;
    }
}
