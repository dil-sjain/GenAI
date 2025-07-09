<?php
/**
 * Model: tpm casemgt CaseFolderNavBar
 */

namespace Models\TPM\CaseMgt\CaseFolder;

use Lib\InvitationControl;
use Models\ThirdPartyManagement\GdcCase;
use Lib\Legacy\UserType;
use Lib\FeatureACL;
use Lib\Legacy\CaseStage;
use Lib\Legacy\ClientIds;
use Lib\Legacy\IntakeFormTypes;
use Lib\DdqSupport;
use Lib\DuplicateCases;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\ThirdParty;
use Models\User;
use Models\Globals\Region;
use Models\Globals\Department;
use Models\TPM\CaseMgt\CaseFolder\RejectCase\RejectCaseData;

/**
 * Class CaseFolderData
 *
 * @keywords case folder, case folder tabs, case folder navigation
 */
#[\AllowDynamicProperties]
class CaseFolderData
{
    /**
     * @var \Skinny\Skinny|null Class instance
     */
    protected $app = null;

    /**
     * @var array|null Record from cases
     */
    protected $caseRow = null;

    /**
     * @var array|null Record from ddq
     */
    protected $ddqRow = null;

    /**
     * @var int|null TPM tenant ID
     */
    protected $clientID = null;

    /**
     * @var int|null cases.id
     */
    protected $caseID = null;

    /**
     * @var int|null users.id
     */
    protected $userID = null;

    /**
     * @var int|null User security level
     */
    protected $userSecLevel = null;

    /**
     * @var object|null Class instance
     */
    protected $inviteControl = null;

    /**
     * Constructor - initialization
     *
     * @param int $clientID Client ID
     * @param int $caseID   Case ID
     *
     * @return void
     */
    public function __construct($clientID, $caseID)
    {
        $this->app = \Xtra::app();

        $this->clientID = intval($clientID);
        $this->caseID = intval($caseID);

        $this->initialize();

        $this->getData();
        $this->setSessionData();
    }

    /**
     * Sets class properties needed for Case Folder functions
     *
     * @return void
     */
    protected function initialize()
    {
        $this->setCaseRow();
        $this->setDdqRow();
    }

    /**
     * Set Case row class property to be used by other functions
     *
     * @return void
     */
    public function setCaseRow()
    {
        $this->caseRow = $this->getCaseRow();
    }

    /**
     * Get the Case row
     *
     * @param int|null $caseID Case ID
     *
     * @return array an associative array row from cases
     */
    public function getCaseRow($caseID = null)
    {
        $caseID = (!empty($caseID) ? $caseID : $this->caseID);
        return (new Cases($this->clientID))->getCaseRow($caseID, \PDO::FETCH_ASSOC);
    }

    /**
     * Set Ddq row class property to be used by other functions
     *
     * @return void
     */
    public function setDdqRow()
    {
        $this->ddqRow = $this->getDdqRow();
    }

    /**
     * Get the Ddq row
     *
     * @return type
     */
    protected function getDdqRow()
    {
        $ddq     = new \Models\Ddq($this->clientID);
        $ddqRow  = $ddq->findByAttributes(['caseID' => $this->caseID]);
        return $ddqRow;
    }

    /**
     * Get session variables and set them as class properties.
     *
     * @return void
     */
    protected function getData()
    {
        $this->userID = $this->app->ftr->user;

        $user = (new User())->findByAttributes(['id' => $this->userID]);
        if (!empty($user)) {
            $this->userSecLevel = $user->get('userSecLevel');
        }
    }

    /**
     * Sets the session data
     *
     * @return void
     */
    protected function setSessionData()
    {
        $this->setRecentlyViewedList();
        $this->setCaseTypeList();
    }

    /**
     * Get the Case stage name for the current Case
     *
     * @return string caseStage.name by caseStage.id for current Case using cases.caseStage
     */
    public function getCaseStage()
    {
        $caseStageList = $this->getCaseStageList();
        $caseStage = null;
        if (isset($caseStageList[$this->caseRow['caseStage']])) {
            $caseStage = $caseStageList[$this->caseRow['caseStage']];
        }
        return $caseStage;
    }

    /**
     * Get an associative array of caseStage names by id
     *
     * @return array an associative array formatted [caseStage.id => caseStage.name]
     *
     * @throws \Exception when no caseStage records
     */
    protected function getCaseStageList()
    {
        $caseStages = $this->app->DB->fetchAssocRows("SELECT `id`, `name` FROM `caseStage` ORDER BY `id`");
        if (count($caseStages) == 0) {
            throw new \Exception("ERROR - No Data returned from the caseStage Table!!!");
        }

        $caseStageList = [];
        foreach ($caseStages as $caseStage) {
            $caseStageList[$caseStage['id']] = $caseStage['name'];
        }

        return $caseStageList;
    }

    /**
     * Set session variable array of caseType.ids and caseType.names
     *
     * @return void
     */
    public function setCaseTypeList()
    {
        $caseTypeList  = $this->app->DB->fetchObjectRows("SELECT `id`, `name` FROM `caseType` ORDER BY `id`");
        $this->app->session->set('caseTypeList', $caseTypeList);
    }

    /**
     * Returns a third party profile record
     *
     * @return array third party profile record
     */
    public function getThirdPartyProfileRow()
    {
        $thirdParty = false;
        if ($this->caseRow) {
            if ($this->app->ftr->has(FeatureACL::TENANT_TPM)
                && $this->app->ftr->has(FeatureACL::CASE_MANAGEMENT)
            ) {
                if ($this->caseRow['tpID']) {
                    $userCond = $this->mkTpUserCondition('tp.');
                    // only super admin can access deleted
                    $delCond = ($this->app->ftr->isLegacySuperAdmin() ? " AND tp.status <> 'deleted'" : '');

                    if (\Xtra::usingGeography2()) {
                        $countryField = 'IFNULL(c.displayAs, c.legacyName)';
                        $regCountryField = 'IFNULL(creg.displayAs, creg.legacyName)';
                        $stateField = 'IFNULL(s.displayAs, s.legacyName)';
                        $countryOn = '(c.legacyCountryCode = tp.country '
                            . 'OR c.codeVariant = tp.country OR c.codeVariant2 = tp.country) '
                            . 'AND (c.countryCodeID > 0 OR c.deferCodeTo IS NULL)';
                        $regCountryOn = '(creg.legacyCountryCode = tp.regCountry '
                            . 'OR creg.codeVariant = tp.regCountry OR creg.codeVariant2 = tp.regCountry) '
                            . 'AND (creg.countryCodeID > 0 OR creg.deferCodeTo IS NULL)';
                        $stateOn = 's.legacyCountryCode = c.legacyCountryCode '
                            . 'AND (s.legacyStateCode = tp.state OR s.codeVariant = tp.state)';
                    } else {
                        $countryField = 'c.legacyName';
                        $regCountryField = 'creg.legacyName';
                        $stateField = 's.legacyName';
                        $countryOn = 'c.legacyCountryCode = tp.country';
                        $regCountryOn = 'creg.legacyCountryCode = tp.regCountry';
                        $stateOn = '(s.legacyStateCode = tp.state AND s.legacyCountryCode = tp.country)';
                    }
                    $sql = "SELECT tp.*, reg.name regionName, ptype.name typeName, "
                        . "pcat.name categoryName, $countryField countryName, $regCountryField regCountryName, "
                        . "$stateField stateName, lf.name legalFormName, own.userName owner, "
                        . "orig.userName originator, DATE_FORMAT(tp.tpCreated,'%Y-%m-%d') createDate, "
                        . "dept.name deptName ";
                    if ($this->app->ftr->has(FeatureACL::TENANT_TPM_RISK)) {
                        $sql .= ", ra.normalized riskrate, "
                            . "ra.tstamp risktime, rmt.scope invScope, "
                            . "rt.tierName risk, tp.riskModel ";
                    } else {
                        // property is expected, but is missing without Risk enabled
                        $sql .= ", '0' AS invScope ";
                    }
                    $sql .= "FROM thirdPartyProfile tp "
                        . "LEFT JOIN region reg ON reg.id = tp.region "
                        . "LEFT JOIN department dept ON dept.id = tp.department "
                        . "LEFT JOIN tpType ptype ON ptype.id = tp.tpType "
                        . "LEFT JOIN tpTypeCategory pcat ON pcat.id = tp.tpTypeCategory "
                        . "LEFT JOIN companyLegalForm lf ON lf.id = tp.legalForm "
                        . "LEFT JOIN {$this->app->DB->isoDB}.legacyCountries c ON $countryOn "
                        . "LEFT JOIN {$this->app->DB->isoDB}.legacyCountries creg ON $regCountryOn "
                        . "LEFT JOIN {$this->app->DB->isoDB}.legacyStates s ON $stateOn "
                        . "LEFT JOIN {$this->app->DB->authDB}.users own ON own.id = tp.ownerID "
                        . "LEFT JOIN {$this->app->DB->authDB}.users orig ON orig.id = tp.createdBy ";
                    if ($this->app->ftr->has(FeatureACL::TENANT_TPM_RISK)) {
                        $sql .= "LEFT JOIN riskAssessment ra ON (ra.tpID = tp.id "
                            . "AND ra.model = tp.riskModel AND ra.status = 'current') "
                            . "LEFT JOIN riskTier rt ON rt.id = ra.tier "
                            . "LEFT JOIN riskModelTier rmt ON rmt.tier = ra.tier AND rmt.model = tp.riskModel ";
                    }
                    $sql .= "WHERE tp.id = :tpID AND tp.clientID = :clientID{$delCond} AND $userCond LIMIT 1";
                    $params = [':tpID' => $this->caseRow['tpID'], ':clientID' => $this->clientID];
                    $thirdParty = $this->app->DB->fetchObjectRow($sql, $params);
                }
            }
        }
        return $thirdParty; //->getAttributes();
    }

    /**
     * Get duplicate case records for client, case, and user if vendor
     *
     * @return mixed DB object result, else false
     */
    public function getDuplicateCases()
    {
        $dupCaseRecords = false;
        if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
            $dupCases = new DuplicateCases();
            $dupCaseRecords = $dupCases->getDuplicateCases($this->clientID, $this->caseID, $this->userID);
        }
        return $dupCaseRecords;
    }

    /**
     * Get caseTypeClient name by clientID
     *
     * @return string caseTypeClient name
     */
    public function getCaseTypeClientName()
    {
        $caseTypeClientList = $this->getCaseTypeClientList();

        $caseTypeClientName = '';
        if (array_key_exists($this->caseRow['caseType'], $caseTypeClientList)) {
            $caseTypeClientName = $caseTypeClientList[$this->caseRow['caseType']];
        }

        return $caseTypeClientName;
    }

    /**
     * Get array of caseTypeClient.caseTypeIDs and names
     *
     * @return array caseTypeClient.caseTypeIDs and names
     */
    public function getCaseTypeClientList()
    {
        $db = $this->app->DB->getClientDB($this->clientID);
        $sqlhead = "SELECT caseTypeID, name FROM $db.caseTypeClient WHERE";
        $sqltail = "AND investigationType = 'due_diligence' ORDER BY caseTypeID ASC";
        $params = [':clientID' => $this->clientID];
        if (!($scopes = $this->app->DB->fetchKeyValueRows("$sqlhead clientID=:clientID $sqltail", $params))) {
            $scopes = $this->app->DB->fetchKeyValueRows("$sqlhead clientID='0' $sqltail");
        }
        return [(string)Cases::DUE_DILIGENCE_INTERNAL => "Internal Review"] + $scopes;
    }

    /**
     * Sets the recentlyViewedList session variable
     *
     * @return avoid
     */
    public function setRecentlyViewedList()
    {
        $rvItems = [];
        if ($this->app->session->has('recentlyViewedList')) {
            $rvItems = explode(',', (string) $this->app->session->get('recentlyViewedList')); // convert to array
        }
        $rvToken = 'c' . $this->caseID; // build the item identifier
        if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
            $rvToken .= ':' . $this->clientID; // vendor must include clientID
        }
        if (($rvIdx = array_search($rvToken, $rvItems)) !== false) {
            unset($rvItems[$rvIdx]); // remove item if already present
        }

        // if a vendor_user is looking at this case because of forced access due to
        // access to a duplicate of this case, we don't want them to have this case in
        // their recently viewed list.
        if ($this->app->ftr->isLegacySpUser()) {
            $dupCases = new DuplicateCases();
            if ($dupCases->dupAccessType($this->clientID, $this->caseID) == 1) {
                // user has natural access to this case, add to recently viewed.
                $rvItems[] = $rvToken; // add this item to end of array
            }
        } else {
            $rvItems[] = $rvToken; // add this item to end of array
        }

        while (count($rvItems) > $this->app->session->get('recentlyViewedLimit')) {
            array_shift($rvItems); // hold to max items
        }
        $rvList = join(',', $rvItems); // convert back to list
        $this->app->session->set('recentlyViewedList', $rvList); // update sess var

        if ($this->app->ftr->legacyUserType > UserType::CLIENT_ADMIN) {
            // update admin list for this client
            $this->app->DB->query(
                "UPDATE {$this->app->DB->authDB}.adminRecentlyViewed SET recentlyViewedList=:rvList "
                . "WHERE userID=:userID AND clientID=:clientID LIMIT 1",
                [':rvList' => $rvList, ':userID' => $this->userID, ':clientID' => $this->clientID]
            );
        } else {
            // update user record
            $this->app->DB->query(
                "UPDATE {$this->app->DB->authDB}.users SET recentlyViewedList=:rvList WHERE id=:userID LIMIT 1",
                [':rvList' => $rvList, ':userID' => $this->userID]
            );
        }
    }

    /**
     * Determines if the invite password panel is displayed
     *
     * @return boolean whether or not to display the invite password panel
     */
    public function showInvitePw()
    {
        /*
         * Super Admin can see pw for all active ddq (we can see in db anyway)
         * Client class users can see if feature enabled
         *   and ddq originated by invitation
         *   and (is Client Admin or noShowInvitePw has been disabled)
         */

        if (empty($this->ddqRow)) {
            $this->setDdqRow();
        }
        $showInvitePw = ($this->ddqRow
            && $this->ddqRow->get('status') == 'active'
            && ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser())
            && ($this->app->ftr->legacyAccessLevel == UserType::SUPER_ADMIN
                || ($this->app->ftr->has(FeatureACL::TENANT_REVEAL_INVITE_PW)
                    && $this->ddqRow->get('origin') == 'invitation'
                    && ($this->app->ftr->legacyAccessLevel == UserType::CLIENT_ADMIN
                        || $this->app->ftr->has(FeatureACL::INVITE_PASS_REVEAL) //noShowInvitePw
                    )
                )
            )
        );

        return $showInvitePw;
    }

    /**
     * Determines how big the header table has to be
     *
     * @return int rowspan of table column
     */
    public function getHeaderRowspan()
    {
        $tpRow = $this->getThirdPartyProfileRow();

        // are we showing the TP row? setup rowspan for nested table depth. used in header table.
        $cfHeadRowspan = ($tpRow && $this->app->ftr->has(FeatureACL::TENANT_TPM_RISK) && $tpRow->risk ? 7 : 6);

        // are there any linked cases?
        $linkedToCaseRow = $this->getLinkedToCaseRow();
        if (!empty($linkedToCaseRow)) {
            $cfHeadRowspan++;
        }

        $linkedFromCaseRow = $this->getLinkedFromCaseRow();
        if (!empty($linkedFromCaseRow)) {
            $cfHeadRowspan++;
        }

        // is there a batch number?
        if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
            $cfHeadRowspan++;
        }

        return $cfHeadRowspan;
    }

    /**
     * Get linked to case row
     *
     * @return array associative array row of cases linkedCaseID
     */
    protected function getLinkedToCaseRow()
    {
        $linkedToCaseRow = false;
        $linkedToCaseID = intval($this->caseRow['linkedCaseID']);
        if ($linkedToCaseID > 0) {
            $linkedToCaseRow = $this->getCaseRow($linkedToCaseID);
        }
        return $linkedToCaseRow;
    }

    /**
     * Get linked form case row
     *
     * @return array associative array row of cases
     */
    protected function getLinkedFromCaseRow()
    {
        $linkedFromCaseID = $this->getLinkedFromCaseID();

        $linkedFromCaseRow = false;
        if ($linkedFromCaseID > 0) {
            $linkedFromCaseRow = $this->getCaseRow($linkedFromCaseID);
        }
        return $linkedFromCaseRow;
    }

    /**
     * Get the id of cases whose linkedCaseID is this caseID
     *
     * @return int cases.id where linkedCaseID is this caseID
     */
    protected function getLinkedFromCaseID()
    {
        $sql = "SELECT id FROM cases "
            . "WHERE linkedCaseID=:caseID AND clientID=:clientID "
            . "ORDER BY id LIMIT 1";
        $params = [':caseID' => $this->caseID, ':clientID' => $this->clientID];
        $linkedFromCaseID = intval($this->app->DB->fetchValue($sql, $params));
        return $linkedFromCaseID;
    }

    /**
     * Get the batch number of the current case row
     *
     * @return int batchID or 0
     */
    public function getBatchNumber()
    {
        $batchNumber = 0;
        if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
            $batchNumber = intval($this->caseRow['batchID']);
        }
        return $batchNumber;
    }

    /**
     * Get Linked to Case data
     *
     * @return array link data
     */
    public function getLinkedToCaseLinkData()
    {
        $link = [];

        $linkedToCaseRow = $this->getLinkedToCaseRow();
        if (!empty($linkedToCaseRow)) {
            $link['text'] = $linkedToCaseRow['userCaseNum'];
            $link['href'] = $this->app->sitePath . 'case/casehome.sec?id='
                . $this->caseRow['linkedCaseID'] . '&amp;tname=casefolder';
            if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
                $link['href'] .= '&amp;icli=' . $this->clientID;
            }
        }
        return $link;
    }

    /**
     * Get Linked from Case data
     *
     * @return array link data
     */
    public function getLinkedFromCaseLinkData()
    {
        $link = [];

        $linkedFromCaseRow = $this->getLinkedFromCaseRow();
        if (!empty($linkedFromCaseRow)) {
            $linkedFromCaseID = $this->getLinkedFromCaseID();
            $link['text'] = $linkedFromCaseRow['userCaseNum'];
            $link['href'] = $this->app->sitePath . 'case/casehome.sec?id='
                . $linkedFromCaseID . '&amp;tname=casefolder';
            if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
                $link['href'] .= '&amp;icli=' . $this->clientID;
            }
        }
        return $link;
    }

    /**
     * Static order of icons
     *
     * @return array indexed by order they should show up
     */
    public function getIconOrder()
    {
        $order = [0 => 'view_3p', 1 => 'intake_menu', 2 => 'show_invite', 3 => 'review_compliance', 4 => 'convert_investigation', 5 => 'approve_convert', 6 => 'edit_case', 7 => 'review_budget', 8 => 'accept_investigation', 9 => 'show_gdc', 10 => 'attach_report', 11 => 'assign_investigation', 12 => 'view_case', 13 => 'sp_reject_case', 14 => 'reject_case', 15 => 'reassign_case', 16 => 'print_case'];
        return $order;
    }

    /**
     * Determine what icons are visible and set their data values
     *
     * @return array associative array of icon data
     */
    public function getIconData()
    {
        if (empty($this->inviteControl)) {
            $this->setInviteControl();
        }

        $icons = [];

        $txtTr = $this->app->trans->codeKeys(
            [
            'third_party_profile',
            'view',
            'intake_form_invite_menu',
            'intake_form_invite',
            'review_compliance_issues',
            'review',
            'convert_to_investigation',
            'convert',
            'approve_ddq',
            'submit_to_legal',
            'link_edit',
            'accept_investigation',
            'accept',
            'close_case',
            'gdc',
            'attach_report',
            'reassign_investigator',
            'assign',
            'reject_case',
            'reject',
            'reject_close',
            'pass_fail',
            'reject_close_reopen',
            'icon_print',
            'print_pdf',
            ]
        );

        if ($this->app->ftr->has(FeatureACL::TENANT_TPM) && $this->caseRow['tpID']
             && (new ThirdParty($this->clientID))->canAccess3PP($this->caseRow['tpID'])
        ) {
            $icons['view_3p'] = ['href' => $this->app->sitePath . 'cms/thirdparty/thirdparty_home.sec?id='
                . $this->caseRow['tpID'] . '&tname=thirdPartyFolder', 'title' => $txtTr['third_party_profile'], 'src' => $this->app->sitePath . 'cms/images/profilecard32x32-8.png', 'alt' => $txtTr['third_party_profile'], 'text' => $txtTr['view']];
        }

        if ($this->app->ftr->has(FeatureACL::TENANT_DDQ_INVITE)
            && ($this->app->ftr->has(FeatureACL::SEND_INVITE) || $this->app->ftr->has(FeatureACL::RESEND_INVITE))
        ) {
            //$this->app->session->remove('inviteClick');
            if ($this->inviteControl['showCtrl']) {
                if ($this->inviteControl['useMenu']) {
                    $icons['intake_menu'] = ['id' => 'intakeInviteMenu', 'href' => 'javascript:void(0)', 'title' => $txtTr['intake_form_invite_menu'], 'src' => '/cms/images/invite32x32.png', 'alt' => $txtTr['intake_form_invite_menu'], 'text' => $this->inviteControl['iconLabel'], 'onclick' => 'appNS.invmnu.openMenu();'];
                } else {
                    $icons['show_invite'] = ['href' => 'javascript:void(0)', 'title' => $txtTr['intake_form_invite'], 'src' => '/cms/images/invite32x32.png', 'alt' => $txtTr['intake_form_invite'], 'text' => $this->inviteControl['iconLabel'], 'onclick' => 'appNS.caseFldrHdr.inviteDialog();'];
                }
            }
        }

        $showReview = (bool)(!($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser())
            && !$this->app->ftr->has(FeatureACL::TENANT_OMIT_REVIEW_PANEL)
            && (($this->ddqRow && $this->ddqRow->get('status') == 'submitted')
                || in_array($this->caseRow['caseStage'], [CaseStage::COMPLETED_BY_INVESTIGATOR, CaseStage::ACCEPTED_BY_REQUESTOR])
            )
        );
        //$this->app->session->set('showRedFlagReview', $showReview);

        if ($showReview === true) {
            if (!$this->app->ftr->has(FeatureACL::TENANT_REVIEW_TAB)) {
                $icons['review_compliance'] = ['href' => 'javascript:void(0)', 'title' => $txtTr['review_compliance_issues'], 'src' => $this->app->sitePath . 'cms/images/redflagreview.png', 'alt' => $txtTr['review_compliance_issues'], 'text' => $txtTr['review'], 'onclick' => "appNS.caseFldrHdr.reviewDialog();"];
            }
        }

        if ((($this->caseRow['caseStage'] == CaseStage::QUALIFICATION)
            || ($this->caseRow['caseStage'] == CaseStage::UNASSIGNED && $this->ddqRow)
            || ($this->caseRow['caseStage'] == CaseStage::CLOSED_HELD)
            || ($this->caseRow['caseStage'] == CaseStage::CLOSED_INTERNAL))
            && ($this->userSecLevel > UserType::USER_SECLEVEL_RO)
        ) {
            //Convert or Approve
            if ($this->app->ftr->has(FeatureACL::ORDER_INVESTIGATION)) {
                $icons['convert_investigation'] = ['href' => $this->app->sitePath . 'case/reviewConfirm.php?xsv=1&amp;id=' . $this->userID, 'title' => $txtTr['convert_to_investigation'], 'src' => $this->app->sitePath . 'cms/images/convert132x32.png', 'alt' => $txtTr['convert_to_investigation'], 'text' => $txtTr['convert'], 'onclick' => 'return appNS.caseFldrHdr.convertToInvestigationDialog(this.href);'];
            } elseif (!$this->caseRow['approveDDQ'] && $this->caseRow['caseStage'] == CaseStage::QUALIFICATION
                && $this->app->ftr->has(FeatureACL::TENANT_APPROVE_DDQ)
                && $this->app->ftr->has(FeatureACL::APPROVE_CASE_CONVERT)
            ) {
                $icon = ['href' => 'javascript:void(0)', 'title' => $txtTr['approve_ddq'], 'src' => $this->app->sitePath . 'cms/images/oklegal.png', 'alt' => $txtTr['approve_ddq'], 'text' => $txtTr['approve_ddq'], 'onclick' => "appNS.caseFldrHdr.approveDdqDialog();"];
                $icons['approve_convert'] = $icon;
            }
        }

        if ((($this->caseRow['caseStage'] == CaseStage::REQUESTED_DRAFT)
            || ($this->caseRow['caseStage'] == CaseStage::UNASSIGNED && !$this->ddqRow))
            && ($this->userSecLevel > UserType::USER_SECLEVEL_RO)
            && $this->app->ftr->has(FeatureACL::CASE_EDIT)
        ) {
            $icons['edit_case'] = ['href' => $this->app->sitePath . 'case/editcase.php?xsv=1&amp;id=' . $this->userID, 'src' => $this->app->sitePath . 'cms/images/edit32X32.png', 'text' => $txtTr['link_edit'], 'onclick' => 'return appNS.caseFldrHdr.editCaseDialog(this.href);'];
        }

        if (($this->caseRow['caseStage'] == CaseStage::AWAITING_BUDGET_APPROVAL)
            && ($this->app->ftr->legacyUserType > UserType::VENDOR_ADMIN)
            && ($this->userSecLevel > UserType::USER_SECLEVEL_RO)
            && $this->app->ftr->has(FeatureACL::APPROVE_CASE_BUDGET)
        ) {
            $icons['review_budget'] = ['href' => $this->app->sitePath . 'case/review_budget.php?id=' . $this->userID, 'src' => $this->app->sitePath . 'cms/images/budgetreview32x32.png', 'text' => $txtTr['review'], 'onclick' => 'return appNS.caseFldrHdr.reviewBudgetDialog(this.href);'];
        }

        if (($this->app->ftr->legacyUserType > UserType::VENDOR_ADMIN)
            && ($this->caseRow['caseStage'] == CaseStage::COMPLETED_BY_INVESTIGATOR)
            && ($this->userSecLevel > UserType::USER_SECLEVEL_RO)
            && $this->app->ftr->has(FeatureACL::ACCEPT_COMPLETED_INVESTIGATION)
        ) {
            $icon = ['id' => 'accept-investigation', 'href' => $this->app->sitePath . 'case/acceptcase.php?id=' . $this->userID, 'title' => $txtTr['accept_investigation'], 'src' => $this->app->sitePath . 'cms/images/accept32x32.png', 'alt' => $txtTr['accept_investigation'], 'text' => $txtTr['accept']];
            if ($this->clientID == ClientIds::BAXTER_CLIENTID
                || $this->clientID == ClientIds::BAXALTA_CLIENTID
                || $this->clientID == ClientIds::BAXTERQC_CLIENTID
                || $this->clientID == ClientIds::BAXALTAQC_CLIENTID
            ) {
                $icon['text'] = $txtTr['close_case'];
            }
            if ($this->app->ftr->has(FeatureACL::TENANT_ACCEPT_PASS_FAIL)) {
                $icon['href'] = 'javascript:void(0)';
            }
            $icons['accept_investigation'] = $icon;
        }

        if (($this->app->ftr->legacyUserType <= UserType::VENDOR_ADMIN)
            && ($this->userSecLevel > UserType::USER_SECLEVEL_RO)
            && $this->app->ftr->has(FeatureACL::SP_ACCESS)
        ) {
            $spID = $this->caseRow['caseAssignedAgent'];
            $sql = "SELECT id FROM {$this->app->DB->spGlobalDB}.spGdcScreening WHERE caseID = :caseID "
                . "AND spID = :spID AND clientID = :clientID ORDER BY id DESC LIMIT 1";
            $params = [
                ':caseID'   => $this->caseID,
                ':spID'     => $spID,
                ':clientID' => $this->clientID
            ];
            if (!($screeningID = $this->app->DB->fetchValue($sql, $params))
                && $this->caseRow['caseStage'] == Cases::ACCEPTED_BY_INVESTIGATOR
            ) {
                // Run a GDC now
                $gdc = new GdcCase($this->caseID, $this->clientID);
                $screeningID = $gdc->screenCase();
            }
            if ($screeningID) {
                $gdcTitle = $this->getGdcTitle($screeningID);

                $icons['show_gdc'] = ['href' => 'javascript:void(0)', 'title' => $gdcTitle, 'src' => $this->app->sitePath . 'cms/images/gdcicon32x32.png', 'alt' => $gdcTitle, 'text' => $txtTr['gdc'], 'onclick' => 'appNS.caseFldrHdr.gdcDialog(' . $screeningID . ')'];
            }

            //$this->app->session->remove('from_iAttachment');
            if (!$this->app->ftr->isLegacySpUser()
                && ($this->caseRow['caseStage'] == CaseStage::ACCEPTED_BY_INVESTIGATOR
                || $this->caseRow['caseStage'] == CaseStage::COMPLETED_BY_INVESTIGATOR)
            ) {
                /*
                 * if ($this->caseRow['caseStage'] == CaseStage::ACCEPTED_BY_INVESTIGATOR) {
                 *    $this->app->session->set('from_iAttachment', 'iQuestionDD');
                 * }
                 */

                // @todo fix new line
                $icons['attach_report'] = ['href' => $this->app->sitePath . 'icase/iAttach.php?id=' . $this->caseID, 'src' => $this->app->sitePath . 'cms/images/paperclipIcon.jpg', 'text' => str_replace(' ', '<br />', (string) $txtTr['attach_report'])];
            }

            // Set up the Reassign facility, only available to Vendor Admins
            if ($this->canAtAccessIcon()) {
                $icons['assign_investigation'] = ['href' => 'javascript:void(0)', 'src' => $this->app->sitePath . 'cms/images/reassign32x32.png', 'alt' => $txtTr['reassign_investigator'], 'text' => $txtTr['assign'], 'onclick' => 'appNS.caseFldrHdr.reassignInvestigatorDialog();'];
            }

            // why hide the view icon from case task access? form fields on view case. no touchy.
            if ($this->canAtManageCase()) {
                $icons['view_case'] = ['href' => $this->app->sitePath . 'icase/viewcase.php?id=' . $this->caseID, 'src' => $this->app->sitePath . 'cms/images/viewcase32x32.png', 'text' => $txtTr['view']];
            }

            $rejectStages = [
                CaseStage::ASSIGNED,
                // Budget Requested
                CaseStage::BUDGET_APPROVED,
                CaseStage::ACCEPTED_BY_INVESTIGATOR,
            ];
            if ($this->app->ftr->has(FeatureACL::SP_CASE_REJECT)
                && in_array($this->caseRow['caseStage'], $rejectStages)
            ) {
                // don't show reject button to vendor user when accessing case because of case task.
                if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
                    $icons['sp_reject_case'] = ['href' => 'javascript:void(0)', 'title' => $txtTr['reject_case'], 'src' => $this->app->sitePath . 'cms/images/rejectcase232x32.png', 'alt' => $txtTr['reject_case'], 'text' => $txtTr['reject'], 'onclick' => 'return appNS.caseFldrHdr.spRejectCase();'];
                }
            }
        }
        $rejectCaseData = new RejectCaseData($this->clientID, $this->caseID, $this->userID);
        if ($rejectCaseData->hasAccess()) {
            $icon = ['id' => 'reject-close-case', 'href' => 'javascript:void(0)', 'title' => $rejectCaseData->getRejectStatus(), 'src' => $this->app->sitePath . 'cms/images/rejectcase232x32.png'];
            $icon['text'] = $icon['title'];
            $icons['reject_case'] = $icon;
        }

        if ((isset($this->ddqRow)) && ($this->ddqRow->get('bDoBusWithEmbargo') == IntakeFormTypes::DUE_DILIGENCE_HCPDI
            || $this->ddqRow->get('bDoBusWithEmbargo') == IntakeFormTypes::DUE_DILIGENCE_HCPDI_RENEWAL)
        ) {
            if ($this->app->ftr->has(FeatureACL::CASE_PRINT)) {
                $icons['print_case'] = ['id' => 'print-case', 'href' => 'javascript:void(0)', 'target' => 'pdfFrame', 'title' => $txtTr['icon_print'], 'src' => $this->app->sitePath . 'cms/images/printer32x32.png', 'alt' => $txtTr['print_pdf'], 'text' => $txtTr['icon_print']];
            }
        } else { //Non-HCP
            if ($this->app->ftr->hasAllOf([FeatureACL::TENANT_REASIGN_CASE, FeatureACL::REASSIGN_CASE])) {
                $icons['reassign_case'] = ['id' => 'reassign-case', 'href' => 'javascript:void(0)', 'title' => 'Reassign Case Elements', 'src' => $this->app->sitePath . 'cms/images/reassign32x32.png', 'text' => 'Reassign'];
            }
            if ($this->app->ftr->has(FeatureACL::CASE_PRINT)) {
                $icons['print_case'] = ['id' => 'print-case', 'href' => 'javascript:void(0)', 'target' => 'pdfFrame', 'title' => $txtTr['icon_print'], 'src' => $this->app->sitePath . 'cms/images/printer32x32.png', 'alt' => $txtTr['print_pdf'], 'text' => $txtTr['icon_print']];
            }
        }

        return $icons;
    }

    /**
     * Set the inviteControl class property to be used by other functions
     *
     * @return void
     */
    public function setInviteControl()
    {
        if ($this->app->ftr->has(FeatureACL::TENANT_DDQ_INVITE)
            && ($this->app->ftr->has(FeatureACL::SEND_INVITE) || $this->app->ftr->has(FeatureACL::RESEND_INVITE))
        ) {
            //$this->app->session->remove('inviteClick');
            $invCtrl = new InvitationControl(
                $this->clientID,
                ['due-diligence', 'internal'],
                $this->app->ftr->has(FeatureACL::SEND_INVITE),
                $this->app->ftr->has(FeatureACL::RESEND_INVITE)
            );

            if ($this->app->ftr->has(FeatureACL::TENANT_TPM)) {
                /*
                 * perspective is *always* 3p with 3p is enabled
                 * A case w/o a 3p association does not support this perspective.  Allowing invitations
                 * on an unassociated case will create conflict later when it is associated with a 3p.
                 * Therefore, no invitation option is presented for unassociated cases
                 */

                $tpRow = $this->getThirdPartyProfileRow();
                if ($tpRow && $this->caseRow['tpID'] > 0) {
                    $this->inviteControl = $invCtrl->getInviteControl($this->caseRow['tpID']);
                } else {
                    // unassociated case; fake inviteControl enough to suppress any display
                    $this->inviteControl = ['useMenu' => false, 'showCtrl' => false];
                }
            } else {
                // perspective is case
                $this->inviteControl = $invCtrl->getInviteControl($this->caseID);
            }

            //devDebug($this->inviteControl, 'inviteControl from loadcase');
        }
    }

    /**
     * Get invitation forms and action maps for inviteControl
     *
     * @return array lists and maps of invitation options
     */
    public function getInviteControl()
    {
        if (empty($this->inviteControl)) {
            $this->setInviteControl();
        }
        return $this->inviteControl;
    }

    /**
     * Determines if logged-in user can access 3P Profile
     *
     * @return integer 1 = Yes, 0 = No
     * @author grh
     */
    protected function canAccessThirdPartyProfile()
    {
        $userCond = $this->mkTpUserCondition();

        // only super admin can access deleted
        $delCond = (!$this->app->ftr->isLegacySuperAdmin() ? " AND status <> 'deleted'" : '');

        $sql = "SELECT COUNT(*) FROM thirdPartyProfile "
            . "WHERE id=:id AND clientID=:clientID{$delCond} AND $userCond";
        $params = [':id' => $this->caseRow['tpID'], ':clientID' => $this->clientID];
        $cnt = intval($this->app->DB->fetchValue($sql, $params));
        return $cnt;
    }

    /**
     * Build SQL condition by userType for querying thirdPartyProfile records
     *
     * @param string $tpAlias   (optional) indicates a table alias used in the target SQL
     * @param object $forceUser (optional) override logged-in user with array of values for
     *                          another user
     *
     * @todo Centralize and rework this code
     *
     * @return string filter for use in an SQL statement
     */
    protected function mkTpUserCondition($tpAlias = '', $forceUser = null)
    {
        $uID = $this->userID;
        $userType = $this->app->ftr->legacyUserType;
        if (is_array($forceUser)) {
            $uID = $forceUser['id'];
            $userType = $forceUser['userType'];
        }
        $user = (new User())->findByAttributes(['id' => $uID]); // comment this out
        $region = new Region($this->clientID);
        $dept = new Department($this->clientID);

        $condition = '';
        switch ($userType) {
            case UserType::CLIENT_ADMIN:
                // if included in restrictUsers
                // otherwise, all are accessible
                $condition .= "IF({$tpAlias}restricted, FIND_IN_SET('$uID', "
                    . "'{$tpAlias}restrictUsers'), 1))=]";
                break;

            case UserType::CLIENT_MANAGER:
                /*
                 * if ($region->hasAllRegions($uID)) {
                 *
                 * } else {
                 *   $regions = $region->getUserRegions($uID); // $roleID?
                 * }
                 * if ($dept->hasAllDepartments($uID)) {
                 *
                 * } else {
                 *    $depts = $dept->getUserDepartments($uID); // $roleID?
                 * }
                 */

                $mgrRegions        = $user->get('mgrRegions');
                $mgrRegionsALL     = $this->app->session->get('mgrRegions-ALL'); //?
                $mgrDepartments    = $user->get('mgrDepartments');
                $mgrDepartmentsALL = $this->app->session->get('mgrDepartments-ALL'); //?
                if (is_array($forceUser)) {
                    //devDebug($forceUser, "ForceUser");
                    $mgrRegions        = $forceUser['mgrRegions'];
                    $mgrRegionsALL     = $forceUser['mgrRegions-ALL']; //?
                    $mgrDepartments    = $forceUser['mgrDepartments'];
                    $mgrDepartmentsALL = $forceUser['mgrDepartments-ALL']; //?
                } else {
                    //devDebug($mgrDepartments, "mgrDepartments");
                    //devDebug($mgrDepartmentsALL, "mgrDepartmentsALL");
                }
                // if included in restrictUsers
                // otherwise, match regions in assigned mgrRegions or all if none assigned
                $regCond   = ($mgrRegionsALL)
                           ? '1'
                           : "{$tpAlias}region IN($mgrRegions)";
                $deptCond  = ($mgrDepartmentsALL)
                           ? ''
                           : " AND {$tpAlias}department IN($mgrDepartments)";
                $condition .= "(IF({$tpAlias}restricted, FIND_IN_SET('$uID', "
                           . "'{$tpAlias}restrictUsers'), $regCond{$deptCond}))";
                //devDebug($condition, "Condition");
                break;

            case UserType::CLIENT_USER:
                $uRegion = $user->get('userRegion');
                if (is_array($forceUser)) {
                    $uRegion = $forceUser['userRegion'];
                }
                // if included in restrictUsers
                // otherwise, ownerID and region must match users.id and userRegion
                // 2012-01-19 take out region
                //$condition = "(IF({$tpAlias}restricted, "
                //    . "FIND_IN_SET('$uID', '{$tpAlias}restrictUsers'), "
                //    . "({$tpAlias}ownerID='$uID' AND {$tpAlias}region='$uRegion')))";
                $condition .= "(IF({$tpAlias}restricted, "
                    . "FIND_IN_SET('$uID', '{$tpAlias}restrictUsers'), ({$tpAlias}ownerID='$uID')))";
                break;

            default:
                // all are accessible to system admin types
                $condition = '1';
        }
        return $condition;
    }

    /**
     * Get the GDC title
     *
     * @param int $screeningID Screening record ID
     *
     * @return string GDC title
     */
    protected function getGdcTitle($screeningID)
    {
        $txtTr = $this->app->trans->codeKeys(
            [
            'gdc_name_error_icon',
            'gdc_hits'
            ]
        );

        $sums = $this->app->DB->fetchObjectRow(
            "SELECT SUM(hits) AS hitSum, SUM(nameError) AS errSum FROM {$this->app->DB->spGlobalDB}.spGdcResult "
            . "WHERE screeningID = :screeningID GROUP BY screeningID",
            [':screeningID' => $screeningID]
        );
        $hits = $sums->hitSum;
        $nameErr = ($sums->errSum ? ', ' . $txtTr['gdc_name_error_icon'] . '!' : '');
        //$gdcTitle = "matches: $matches, hits: $hits";
        $gdcTitle = $txtTr['gdc_hits'] . ": $hits{$nameErr}";
        return $gdcTitle;
    }

    /**
     * Get the region name
     *
     * @return string region.name
     *
     * @throws \Exception when nothing returned
     */
    public function getRegionName()
    {
        $name = (new Region($this->clientID))->getRegionName($this->caseRow['region']);
        if (!empty($name)) {
            return $name;
        }
        return $this->app->DB->fetchValue(
            "SELECT name FROM region WHERE id=:id",
            [':id' => $this->caseRow['region']]
        );
    }

    /**
     * Get the department name
     *
     * @note Have to run query because Models require Client ID to be greater than 0.
     *
     * @return string department.name
     */
    public function getDepartmentName()
    {
        $mame = (new Department($this->clientID))->getDepartmentName($this->caseRow['dept']);
        if (!empty($mame)) {
            return $mame;
        }
        return $this->app->DB->fetchValue(
            "SELECT name FROM department WHERE id=:id",
            [':id' => $this->caseRow['dept']]
        );
    }

    /**
     * Get Status based on case's caseStage
     *
     * @return string status
     */
    public function getDynStatus()
    {
        if ($this->app->ftr->isLegacySpAdmin() || $this->app->ftr->isLegacySpUser()) {
            $dynStatus = ($this->caseRow['caseStage'] >= CaseStage::COMPLETED_BY_INVESTIGATOR ? 'Closed' : 'Active');
        } else {
            $dynStatus = ($this->caseRow['caseStage'] >= CaseStage::ACCEPTED_BY_REQUESTOR
                    && $this->caseRow['caseStage'] != CaseStage::CASE_CANCELED ? 'Closed' : 'Active');
        }
        return $dynStatus;
    }

    /**
     * Determine access to the reassign icon
     *
     * @return boolean
     */
    public function canAtAccessIcon()
    {
        return ($this->app->ftr->isLegacySpAdmin() || $this->caseRow['caseInvestigatorUserID'] == $this->userID);
    }

    /**
     * Determine access to the case assignment
     *
     * @return boolean
     */
    public function canAtAssign()
    {
        return ($this->app->ftr->isLegacySpAdmin() && $this->caseRow['caseInvestigatorUserID'] == $this->userID);
    }

    /**
     * 4/9/14 - Luke - SEC-379
     * Add to handle vendor users who "need" case access
     * because of their case task assignments. I would expect this to
     * change during refactoring, and once the vendor/client code is
     * separated, and vendor interface takes over.
     *
     * For now, this returns false for users who access the case by having a task.
     *
     * @return boolean
     */
    public function canAtManageCase()
    {
        return ($this->app->ftr->isLegacySpAdmin() || $this->caseRow['caseInvestigatorUserID'] == $this->userID);
    }
}
