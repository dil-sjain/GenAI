<?php
/**
 * Prepare to display case in Case Folder
 *
 * CS compliance (partial, lines 1 - 186) and cleanup: 2012-07-06 grh
 */

// Prevent direct access
if (realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__) {
    return;
}
$_GET['id'] = htmlspecialchars($_GET['id'], ENT_QUOTES, 'UTF-8');
if (!is_int($_GET['id'])) {
    $_GET['id'] = intval($_GET['id']);
}

require_once __DIR__ . '/../includes/php/ddq_funcs.php';
require_once __DIR__ . '/../includes/php/class_db.php';
if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng')) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}
require_once __DIR__ . '/../includes/php/Lib/FeatureACL.php';
$engagementEnabled = ($accCls->ftr->tenantHas(Feature::TENANT_TPM_ENGAGEMENTS) || hasEngagementRecord());

// initialize duplicate case stuff (if SP); var false until set otherwise.
$dupCaseRecords = false;
if ($userClass == 'vendor') {
    include_once __DIR__ . '/../includes/php/class_duplicateCases.php';
}

require_once __DIR__ . '/../includes/php/funcs_cases.php';
require_once __DIR__ . '/../includes/php/Models/Globals/Features/TenantFeatures.php';

// Get environment variable value
$awsEnabled = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN);

$clientID = $_SESSION['clientID'];
$tf = (new TenantFeatures($clientID))->tenantHasFeatures([Feature::AI_SUMMARISATION], FEATURE::APP_TPM);
// aiSummary feature flag is enabled and tenant has AI_SUMMARISATION feature
$aiSummaryEnabled = (filter_var(getenv("AI_SUMMARY_ENABLED"), FILTER_VALIDATE_BOOLEAN) && !empty($tf[Feature::AI_SUMMARISATION]));

// Clear any DDQ related Session data still set from a previous case
unset(
    $_SESSION['ddqRow'],
    $_SESSION['ddqID'],
    $_SESSION['aOnlineQuestions']
);

$globalsp = GLOBAL_SP_DB;
$e_clientID = $clientID = $_SESSION['clientID'];
$e_userID = $userID = (int)$_SESSION['id'];

// Make sure there is no 3rd Party Prefill data hanging around
unset(
    $_SESSION['profileRow3P'],
    $_SESSION['tpID'],
    $_SESSION['bError']
);

// Load up the DDQ record
if ($ddqRow = fGetddqSubmittedRow($_SESSION['currentCaseID'])) {
    $_SESSION['ddqRow'] = $ddqRow;
    $_SESSION['ddqID']  = $ddqRow['id'];
}

// If Case Stages and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseStageList'])) {
    $_SESSION['caseStageList'] = fLoadCaseStageList();
}

// If Case Type and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseTypeList'])) {
    $_SESSION['caseTypeList'] = fLoadCaseTypeList();
}

$showCustomData = $caseID = $tpID = 0;
$screeningID = 0;
$triggerGdc = false;
if (isset($_SESSION['currentCaseID'])) {
    $e_caseID = $caseID = $_SESSION['currentCaseID'];

    // set up duplicate case stuff
    if ($userClass == 'vendor') {
        $dupCaseCls = new DuplicateCases();
        $dupCaseRecords = $dupCaseCls->getDuplicateCases($clientID, $caseID, $userID);
    }

    // Get the case row
    $caseRow = fGetCaseRow($caseID, MYSQLI_BOTH, false);
    if (!$caseRow) {
        //  SEC-2385
        if (SECURIMATE_ENV == 'Development') {
            $path = realpath(__DIR__ . '/../includes/php/Lib/Database');
            require $path . '/MySqlPdo.php';

            $DB = new MySqlPdo(['clientID' => $e_clientID]);

            $sql   = [];
            $sql[] = 'SELECT ';
            $sql[] = 'IF (region.id IS NULL, "invalid", "valid") as validRegion,';
            $sql[] = 'IF (department.id IS NULL, "invalid", "valid") as validDepartment,';
            $sql[] = 'cases.region as region,';
            $sql[] = 'cases.dept as department';
            $sql[] = 'FROM cases';
            $sql[] = 'LEFT JOIN region ON cases.region = region.id';
            $sql[] = 'LEFT JOIN department ON cases.dept = department.id';
            $sql[] = 'WHERE cases.id = :caseID';
            $sql[] = 'AND cases.clientID = :clientID';

            $bind = [
                ':caseID' => (int)$caseID,
                ':clientID' => (int)$e_clientID,
            ];

            $result = $DB->fetchAssocRow(implode(PHP_EOL, $sql), $bind);

            if (!empty($result)) {
                $errorMessage = [];
                $prependError = 'The case is set to reference ';
                $appendError  = 'which does not exist - check case for data integrity.';
                if ($result['validRegion'] == 'invalid') {
                    $errorMessage[] = "{$prependError} region {$result['region']} {$appendError}";
                }
                if ($result['validDepartment'] == 'invalid') {
                    $errorMessage[] = "{$prependError} department {$result['department']} {$appendError}";
                }
                if (!empty($errorMessage)) {
                    echo implode('<br />', array_merge(['Development environment error:'], $errorMessage));
                }
            }
        }
        return;
    }
    $tpRow = false;
    if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng')) {
        if ($caseRow['tpID']) {
            $tpRow = fGetThirdPartyProfileRow($caseRow['tpID']);
        }
    }
    // Make GDC available to investigator and refresh caseTypeClientList
    if ($userClass == 'vendor') {
        // Refresh caseTypeClientList
        include_once __DIR__ . '/../includes/php/'.'funcs_sesslists.php';
        setCaseTypeClientList($clientID);

        // Screening triggered?
        if (isset($_GET['rvw']) && $_GET['rvw'] == 1) {
            $triggerGdc = true;
        }
        $gdcTitle = '';
        // is there already a GDC?
        $e_spID = $spID = $caseRow['caseAssignedAgent'];
        $sql = "SELECT id FROM $globalsp.spGdcScreening WHERE caseID = '$e_caseID' "
            . "AND spID = '$e_spID' AND clientID = '$e_clientID' ORDER BY id DESC LIMIT 1";
        if (!($screeningID = $dbCls->fetchValue($sql))
            && $caseRow['caseStage'] == ACCEPTED_BY_INVESTIGATOR
        ) {
            // Run a GDC now
            include_once __DIR__ . '/../includes/php/'.'class_gdccase.php';
            $gdc = new GdcCase($caseID, $clientID);
            $screeningID = $gdc->screenCase();
        }
        $e_screeningID = $screeningID;
        if ($screeningID) {
            /*
            $matches = $dbCls->fetchValue("SELECT SUM(matches) FROM $globalsp.spGdcResult "
                . "WHERE screeningID = '$screeningID'"
            );
             */
            $sums = $dbCls->fetchObjectRow("SELECT SUM(hits) AS hitSum, "
                . "SUM(nameError) AS errSum FROM $globalsp.spGdcResult "
                . "WHERE screeningID = '$e_screeningID' GROUP BY screeningID");
            $hits = (!empty($sums->hitSum)) ? $sums->hitSum : 0;
            $nameErr = (!empty($sums->errSum)) ? ', Name Error!': '';
            //$gdcTitle = "matches: $matches, hits: $hits";
            $gdcTitle = "Hits: $hits{$nameErr}";
        }
    }
    $caseTypeClientName = '';
    if (array_key_exists($caseRow['caseType'], $_SESSION['caseTypeClientList'])) {
        $caseTypeClientName = $_SESSION['caseTypeClientList'][$caseRow['caseType']];
    }
    $_SESSION['caseTypeClientName'] = $caseTypeClientName;

    // Update recently veiwed list
    $rvItems = [];
    if ($_SESSION['recentlyViewedList']) {
        $rvItems = explode(',', (string) $_SESSION['recentlyViewedList']); // convert to array
    }
    $rvToken = 'c' . $caseID; // build the item identifier
    if ($userClass == 'vendor') {
        $rvToken .= ':' . $clientID; // vendor must include clientID
    }
    if (($rvIdx = array_search($rvToken, $rvItems)) !== false) {
        unset($rvItems[$rvIdx]); // remove item if already present
    }

    // if a vendor_user is looking at this case because of forced access due to
    // access to a duplicate of this case, we don't want them to have this case in
    // their recently viewed list.
    if ($_SESSION['userType'] == VENDOR_USER) {
        if ($dupCaseCls->dupAccessType($clientID, $caseID) == 1) {
            // user has natural access to this case, add to recently viewed.
            $rvItems[] = $rvToken; // add this item to end of array
        }
    } else {
        $rvItems[] = $rvToken; // add this item to end of array
    }
    while (count($rvItems) > $_SESSION['recentlyViewedLimit']) {
        array_shift($rvItems); // hold to max items
    }
    $rvList = join(',', $rvItems); // convert back to list
    $_SESSION['recentlyViewedList'] = $rvList; // update sess var

    if ($_SESSION['userType'] > CLIENT_ADMIN) {
        // update admin list for this client
        $dbCls->query("UPDATE " . GLOBAL_DB . ".adminRecentlyViewed "
            . "SET recentlyViewedList='$rvList' "
            . "WHERE userID='$e_userID' "
            . "AND clientID='$e_clientID' LIMIT 1");
    } else {
        // update user record
        $dbCls->query("UPDATE " . GLOBAL_DB . ".users SET recentlyViewedList='$rvList' "
            . "WHERE id='$e_userID' LIMIT 1");
    }
    unset($rvList, $rvIdx, $rvToken); // clean up tmp vars

    // Show custom data if available and if user is not a service provider
    // Also determine if there is Third Party profile
    if ($_SESSION['userType'] >= VENDOR_ADMIN) {
        $e_tpID = $tpID = $caseRow['tpID'];

        // Determine based on scope
        $tpFldCnt = 0;
        $csFldCnt = $dbCls->fetchValue("SELECT COUNT(*) AS cnt FROM customField "
            . "WHERE clientID = '$e_clientID' AND scope='case' AND hide=0");

        // tp only matters if there's tpData, since we can't edit here
        $tpDataCnt = 0;
        $sql = "SELECT COUNT(*) AS cnt FROM customField "
            . "WHERE clientID = '$e_clientID' AND scope='thirdparty' AND hide=0";
        if ($tpID
            && $accCls->allow('acc3pCustFlds')
            && ($tpFldCnt = $dbCls->fetchValue($sql))
        ) {
            $tpDataCnt = $dbCls->fetchValue("SELECT COUNT(*) FROM customData "
                . "WHERE tpID='$e_tpID' AND clientID='$e_clientID' AND hide=0");
        }
        $showCustomData = ($accCls->allow('accCaseCustFlds')
            && ($csFldCnt || $tpDataCnt)
        );
    }

    /*
     * Super Admin can see pw for all active ddq (we can see in db anyway)
     * Client class users can see if feature enabled
     *   and ddq originated by invitation
     *   and (is Client Admin or noShowInvitePw has been disabled)
     */
    $showInvitePw = ($ddqRow
        && $ddqRow['status'] == 'active'
        && $session->secure_value('userClass') != 'vendor'
        && ($session->secure_value('accessLevel') == SUPER_ADMIN
            || ($_SESSION['cflagShowInvitePw']
                && $ddqRow['origin'] == 'invitation'
                && ($session->secure_value('accessLevel') == CLIENT_ADMIN
                    || !$accCls->allow('noShowInvitePw')
                )
            )
        )
    );

    $showReviewTab = $showReviewPanel = false;
    $showReview = (bool)($session->secure_value('userClass') != 'vendor'
        && !$_SESSION['cflagNoReviewPanel']
        && (($ddqRow && $ddqRow['status'] == 'submitted')
            || in_array($caseRow['caseStage'], [COMPLETED_BY_INVESTIGATOR, ACCEPTED_BY_REQUESTOR])
        )
    );
    $_SESSION['showRedFlagReview'] = $showReview;
    if ($showReview === true) {
        if ($_SESSION['cflagReviewTab']) {
            $showReviewTab = true; // use reviewer tab
        } else {
            $showReviewPanel = true; // use review panel
        }
    }

    $stageLimit = ($userClass == 'vendor') ? COMPLETED_BY_INVESTIGATOR : ACCEPTED_BY_REQUESTOR;
    if ($userClass == 'vendor') {
        $dynStatus = ($caseRow['caseStage'] >= $stageLimit) ? 'Closed' : 'Active';
    } elseif ($caseRow['caseStage'] == ON_HOLD) {
        $dynStatus = 'On Hold';
    } else {
        $dynStatus = ($caseRow['caseStage'] >= $stageLimit && ($caseRow['caseStage'] != CASE_CANCELED && $caseRow['caseStage'] != AI_REPORT_GENERATED)) ? 'Closed' : 'Active';
    }
    $regionName = fGetRegionName($caseRow['region']);
    $deptName = fGetDepartmentName($caseRow['dept']);

    // Set SESSION variables for onlineQuestions to display correctly
    $_SESSION['bDDQcreated'] = fNumOfDDQRows($caseID);

    // Keep languageCode set as English for now
    $_SESSION['languageCode'] = 'EN_US';
} else {
    // no valid caseID
    return;
}

// billling unit and p.o.
require_once __DIR__ . '/../includes/php/class_BillingUnit.php';
$buCls = new BillingUnit($clientID);
$billingUnit = $caseRow['billingUnit'];
$billingUnitPO = $caseRow['billingUnitPO'];

// determine access to the assign/task icon/button and funcs
/*
    4/9/14 - Luke - SEC-379
    Add atManageCase to handle vendor users who "need" case access
    because of their case task assignments. I would expect this to
    change during refactoring, and once the vendor/client code is
    separated, and vendor interface takes over.

    For now, this var is false for users who access the case by having a task.
*/
$atIconAccess = false;
$atCanAssign = false;
$atManageCase = false;
if ($_SESSION['userType'] == VENDOR_ADMIN
    || $caseRow['caseInvestigatorUserID'] == $_SESSION['id']
) {
    $atIconAccess = true;
    $atCanAssign = true;
    $atManageCase = true; // true because of vendor_admin, or being lead investigator.
    if ($_SESSION['userType'] != VENDOR_ADMIN) {
        $atCanAssign = false;
    }
}


if ($engagementEnabled && $tpRow) {
    $_SESSION['currentThirdPartyIsEngagement'] = $tpRow->isEngagement ?? 0;
}

//
// generate icons. do this here to make it cleaner in the markup below.
//
$cfIcons = '<ul>';
$liAlign = "\n\t\t\t\t"; // keeps source cleaner.
if ($_SESSION['b3pAccess'] && $tpID && bCanAccessThirdPartyProfile($tpID)) {
    $cfIcons .= $liAlign;
    $icon = 'fa-id-card';
    $toolTip = 'Third Party Profile';

    if ($_SESSION['currentThirdPartyIsEngagement']) {
        $icon = 'fa-building';
        $toolTip = 'Engagement Profile';
    }

    $cfIcons .= '<li><a href="'. $sitepath .'thirdparty/thirdparty_home.sec?id='
        . $tpID .'&tname=thirdPartyFolder" '
        . 'title="' . $toolTip . '"><span class="fas ' . $icon . '"></span>'
        . '<br />View</a></li>';
}

if ($_SESSION['allowInvite']) {
    unset($_SESSION['inviteClick']);
    include_once __DIR__ . '/../includes/php/'.'class_InvitationControl.php';
    $invCtrl = new InvitationControl(
        $clientID,
        ['due-diligence', 'internal'],
        $accCls->allow('sendInvite'),
        $accCls->allow('resendInvite')
    );

    if ($_SESSION['b3pAccess']) {
        /*
         * perspective is *always* 3p with 3p is enabled
         * A case w/o a 3p association does not support this perspective.  Allowing invitations
         * on an unassociated case will create conflict later when it is associated with a 3p.
         * Therefore, no invitation option is presented for unassociated cases
         */
        if ($tpRow && $tpID > 0) {
            $inviteControl = $invCtrl->getInviteControl($tpID);
        } else {
            // unassociated case; fake inviteControl enough to suppress any display
            $inviteControl = ['useMenu' => false, 'showCtrl' => false];
        }
    } else {
        // perspective is case
        $inviteControl = $invCtrl->getInviteControl($caseID);
    }
    //devDebug($inviteControl, 'inviteControl from loadcase');

    if ($inviteControl['showCtrl']) {
        if ($inviteControl['useMenu']) {
            $inviteClick = "YAHOO.invmnu.showMenu()";
            $inviteTitle = "Intake Form Invitation Menu";
            $inviteHref  = 'javascript:void(0)';
            $inviteOpRequest = 'opRequest';
        } else {
            $inviteTitle = "Intake Form Invitation";
            $inviteClick = "return GB_showPageInvite(this.href)";
            $icFormType = key($inviteControl['actionFormList']);
            $icForm = current($inviteControl['actionFormList']);
            // above replaces the deprecated each from below. code left for brevity/context.
            // the reset shouldn't be needed as key/current do not advance the pointer.
            // however, also leaving that just in case as it won't hurt anything.
            //list($icFormType, $icForm) = each($inviteControl['actionFormList']);
            reset($inviteControl['actionFormList']);
            $inviteHref  = $sitepath .'case/add_ddqinvite.php?xsv=1&amp;dl=' . $icForm['dialogLink'];
        }
        $cfIcons .= $liAlign .'<li><a href="'. $inviteHref .'" title="' . $inviteTitle . '" '
            . 'onclick="'.  $inviteClick .'">'
                .'<span class="fas fa-share-square"></span>'
                .'<br />'
                . $inviteControl['iconLabel'] .'</a></li>';
    }
}

if ($showReviewPanel) {
    $cfIcons .= $liAlign .'<li><a href="javascript:void(0)" '
        .'onclick="return _opRequest(\'show-review-panel\')" '
        .'title="Review Potential Compliance Issues">'
            .'<span class="fas fa-flag"></span>'
            .'<br />Review</a></li>';
}

if ((($caseRow['caseStage'] == QUALIFICATION)
    || ($caseRow['caseStage'] == UNASSIGNED && $ddqRow)
    || ($caseRow['caseStage'] == CLOSED_HELD)
    || ($caseRow['caseStage'] == CLOSED_INTERNAL)
    || ($caseRow['caseSource'] == DUE_DILIGENCE_CS_AI_DD && $caseRow['caseStage'] == AI_REPORT_GENERATED) )
    && ($_SESSION['userSecLevel'] > READ_ONLY)
) {
    //Convert or Approve
    if ($accCls->allow('convertCase')) {
        $btnName = ($caseRow['caseSource'] == DUE_DILIGENCE_CS_AI_DD) ? 'Order Due<br>Diligence' : 'Convert';
        $cfIcons .= $liAlign .'<li><a href="'. $sitepath .'case/reviewConfirm.php?xsv=1&amp;id='
            .$_GET['id'] .'" id="el2" onclick="return GB_showPage(this.href)" '
            .'title="Convert to Investigation">'
            .'<span class="fas fa-sync"></span>'
            .'<br />'
            . $btnName . '</a></li>';
    } elseif (!$caseRow['approveDDQ'] && $caseRow['caseStage'] == QUALIFICATION
        && $_SESSION['cflagApproveDDQ'] && $accCls->allow('approveCaseConvert')
    ) {
        $apprTitle =  'Approve DDQ';
        $apprIconLabel = $apprTitle;
        $cfIcons .= $liAlign;
        $cfIcons .= '<li><a href="javascript:void(0)" onclick="opRequest(\'approve4convert\')" '
            .'title="'. $apprTitle .'"><span class="fas fa-gavel"></span><br />'
            .$apprIconLabel .'</a></li>';
    }
}

if ((($caseRow['caseStage'] == REQUESTED_DRAFT)
    || ($caseRow['caseStage'] == UNASSIGNED && !$ddqRow))
    && ($_SESSION['userSecLevel'] > READ_ONLY)
    && $accCls->allow('updtCase')
) {
    $cfIcons .= $liAlign .'<li><a href="'. $sitepath .'case/editcase.php?xsv=1&amp;id='
        .$_GET['id'] .'" id="el3" onclick="return GB_showPage(this.href)"><span class="fas fa-edit"></span>'
        .'<br />Edit</a></li>';
}
if (($caseRow['caseStage'] == AWAITING_BUDGET_APPROVAL)
    && ($_SESSION['userType'] > VENDOR_ADMIN)
    && ($_SESSION['userSecLevel'] > READ_ONLY)
    && $accCls->allow('approveBudget')
) {
    $cfIcons .= $liAlign;
    $cfIcons .= '<li><a href="'. $sitepath .'case/review_budget.php?id='. $_GET['id'] .'" '
        .'id="el4" onclick="return GB_showPage(this.href)"><span class="fas fa-file-invoice-dollar"></span>'
        .'<br />Review</a></li>';
}

if (($_SESSION['userType'] > VENDOR_ADMIN)
    && ($caseRow['caseStage'] == COMPLETED_BY_INVESTIGATOR)
    && ($_SESSION['userSecLevel'] > READ_ONLY)
    && $accCls->allow('acceptCase')
) {
    $acptTag = "Accept";
    if ($_SESSION['clientID'] == BAXTER_CLIENTID
        || $_SESSION['clientID'] == BAXALTA_CLIENTID
        || $_SESSION['clientID'] == BAXTERQC_CLIENTID
        || $_SESSION['clientID'] == BAXALTAQC_CLIENTID) {
        $acptTag = "Close Case";
    }

    if ($_SESSION['cflagAcceptPassFail']) {
        $cfIcons .= $liAlign .'<li><a href="javascript:void(0)" onclick="initPassFail()" '
            .'title="Accept Investigation"><span class="fas fa-check-square"></span>'
            .'<br />Accept</a></li>';
    } else { // work on these also
        $cfIcons .= $liAlign;
        $cfIcons .= '<li><a href="'. $sitepath .'case/acceptcase.php?id='. $_GET['id'] .'" '
            .'title="Accept Investigation">'
            .'<span class="fas fa-check-square"></span>'
            ."<br />$acptTag</a></li>";
    }
}

$tf = (new TenantFeatures($clientID))->tenantHasFeatures(
    [Feature::INCREMENT_CASE],
    FEATURE::APP_SP
);
$incrementedCaseTypeID = 0;
$canIncrement = (
    $_SESSION['userType'] >= VENDOR_ADMIN
    && !empty($tf[Feature::INCREMENT_CASE])
    && ($incrementedCaseTypeID = getIncrementedCaseTypeID($_SESSION['clientID'], $caseRow['caseType']))
);

if (($_SESSION['userType'] <= VENDOR_ADMIN)
    && ($_SESSION['userSecLevel'] > READ_ONLY)
    && $accCls->allow('spAccess')
) {
    if ($screeningID) {
        $cfIcons .= $liAlign .'<li><a id="showGDCButton" href="javascript:void(0)" '
            .'onclick="YAHOO.gdcifr.showGDC('. $screeningID .')">'
            .'<span class="fas fa-check-square"></span>'
            .'<br />GDC</a></li>';
    }

    if ($canIncrement) {
        $cfIcons .= $liAlign .'<li><a href="javascript:void(0)" '
        .'onclick="return spfunc.showSpCaseIncrement()">'
        .'<span class="fas fa-plus"></span>'
        .'<br />Convert to<br />Incremental</a></li>';
    }

    unset($_SESSION['from_iAttachment']);
    if ($_SESSION['userType'] != VENDOR_USER
        && ($caseRow['caseStage'] == ACCEPTED_BY_INVESTIGATOR
        || $caseRow['caseStage'] == COMPLETED_BY_INVESTIGATOR)
    ) {
        if ($caseRow['caseStage'] == ACCEPTED_BY_INVESTIGATOR) {
            $_SESSION['from_iAttachment'] = 'iQuestionDD';
        }
        $cfIcons .= $liAlign .'<li><a href="'. $sitepath .'icase/iAttachment.php?id='.$caseID .'">'
            .'<span class="fas fa-paperclip"></span>'
            .'<br />Attach<br />Report</a></li>';
    }

    // Set up the Reassign facility, only available to Vendor Admins
    if ($atIconAccess) {
        $cfIcons .= $liAlign .'<li><a href="javascript:void(0)" '
            .'onclick="spfunc.showSpAssignCase()"><span class="fas fa-user-circle"></span>'
            .'<br />Assign</a></li>';
    }
    // why hide the view icon from case task access? form fields on view case. no touchy.
    if (true) {
        $cfIcons .= $liAlign .'<li><a href="'. $sitepath .'icase/viewcase.php?id='. $caseID .'">'
            .'<span class="fas fa-folder-open"></span>'
            .'<br />View</a></li>';
    }

    $rejectStages = [
        ASSIGNED,
        // Budget Requested
        BUDGET_APPROVED,
        ACCEPTED_BY_INVESTIGATOR,
    ];
    if ($accCls->allow('spRejectCase') && in_array($caseRow['caseStage'], $rejectStages)) {
        // don't show reject button to vendor user when accessing case because of case task.
        if ($userClass == 'vendor' && $atManageCase === true) {
            $cfIcons .= $liAlign .'<li><a href="javascript:void(0)" '
                .'onclick="return spfunc.showSpCaseReject()">'
                .'<span class="fas fa-folder-minus"></span>'
                .'<br />Reject</a></li>';
        }
    }
}

if ((($caseRow['caseStage'] < BUDGET_APPROVED)
    || ($caseRow['caseStage'] == CLOSED_INTERNAL)
    || ($caseRow['caseStage'] == CLOSED_HELD)
    || ($caseRow['caseStage'] == CLOSED)
    || ($caseRow['caseStage'] == CASE_CANCELED)
    || ($caseRow['caseStage'] == AI_REPORT_GENERATED))
    && ($_SESSION['userType'] > VENDOR_ADMIN)
    && ($_SESSION['userSecLevel'] > READ_ONLY)
    && $accCls->allow('rejectCase')
) {
    if (fbClientDanaher($_SESSION['clientID'])
        || in_array($_SESSION['clientID'], HP_ALL)
    ) {
        $_SESSION['szRejectIcon'] = "Pass/Fail";
    } elseif ($_SESSION['clientID'] == VISAQC_CLIENTID || $_SESSION['clientID'] == VISA_CLIENTID) {
        $_SESSION['szRejectIcon'] = "Reject/Close/Reopen";
    } else {
        $_SESSION['szRejectIcon'] = "Reject/Close";
    }

    $cfIcons .= $liAlign .'<li><a href="'. $sitepath .'case/rejectcase.php?id='. $_GET['id'] .'" '
        .'title="'. $_SESSION['szRejectIcon'] .'" id="el5" '
        .'onclick="return GB_showPage(this.href)">'
        .'<span class="fas fa-folder-minus"></span>'
        .'<br />'. $_SESSION['szRejectIcon'] .'</a></li>';
}

if ((isset($_SESSION['ddqRow'])) && ($_SESSION['ddqRow'][107] == DUE_DILIGENCE_HCPDI
    || $_SESSION['ddqRow'][107] == DUE_DILIGENCE_HCPDI_RENEWAL)
) {
    if ($accCls->allow('printCase')) {
        $cfIcons .= $liAlign;
        $cfIcons .= '<li><a href="'. $sitepath .'case/printHCP-PDF.php" target="pdfFrame" '
            .'onclick="return printCaseFolderPDF(\'pdfFrame\', this.href)">'
            .'<span class="fas fa-print"></span>'
            .'<br />Print</a></li>';
    }
} else { //Non-HCP
    if ($_SESSION['cflagReassignCase'] && $accCls->allow('reassignCase')) {
        $cfIcons .= $liAlign .'<li><a href="javascript:void(0)" title="Reassign Case Elements" '
            .'id="el5" onclick="return initReassignPanel()">'
            .'<span class="fas fa-user-circle"></span>'
            .'<br />Reassign</a></li>';
    }
    if ($accCls->allow('printCase')) {
        $cfIcons .= $liAlign .'<li><a href="'. $sitepath .'case/printPDF.php" target="pdfFrame" '
            .'onclick="return printCaseFolderPDF(\'pdfFrame\', this.href)">'
            .'<span class="fas fa-print"></span>'
            .'<br />Print</a></li>';
    }
}
$cfIcons .= "\n\t\t\t</ul>\n";

//
// end of icon generation.
//

// are we showing the TP row? setup rowspan for nested table depth. used in header table.
$cfHeadRowspan = ($tpRow && $_SESSION['b3pRisk'] && $tpRow->risk) ? 7:6;

// are there any linked cases?
$show_linked_to = false;
$linkedToCaseID = (int)$caseRow['linkedCaseID'];
if ($linkedToCaseID > 0) {
    $linkedToCaseRow = fGetCaseRow($linkedToCaseID, MYSQLI_ASSOC, false, 'userCaseNum');
    if (isset($linkedToCaseRow['userCaseNum'])) {
        $show_linked_to = true;
        $cfHeadRowspan++;
        $linked_to_casenum = $linkedToCaseRow['userCaseNum'];
        $casenum_link_to = $sitepath . 'case/casehome.sec?id='
            . $linkedToCaseID . '&amp;tname=casefolder';
        if ($userClass == 'vendor') {
            $casenum_link_to .= '&amp;icli=' . $clientID;
        }
    }
}
$show_linked_from = false;
$sql = "SELECT id FROM cases "
    . "WHERE linkedCaseID='$e_caseID' AND clientID='$e_clientID' "
    . "ORDER BY id LIMIT 1";
$linkedFromCaseID = (int)$dbCls->fetchValue($sql);
if ($linkedFromCaseID > 0) {
    $linkedFromCaseRow = fGetCaseRow($linkedFromCaseID, MYSQLI_ASSOC, false, 'userCaseNum');
    if (isset($linkedFromCaseRow['userCaseNum'])) {
        $show_linked_from = true;
        $cfHeadRowspan++;
        $linked_from_casenum = $linkedFromCaseRow['userCaseNum'];
        $casenum_link_from = $sitepath . 'case/casehome.sec?id='
            . $linkedFromCaseID . '&amp;tname=casefolder';
        if ($userClass == 'vendor') {
            $casenum_link_from .= '&amp;icli=' . $clientID;
        }
    }
}
unset($linkedToCaseRow, $linkedFromCaseRow);

// is there a batch number?
$batchNumber = 0;
if ($userClass == 'vendor') {
    $cfHeadRowspan++;
    $batchNumber = (int)$caseRow['batchID'];
}
?>

<script type="text/javascript">
    var loadcasePgAuth = false;
<?php echo $buCls->jsHandler($billingUnit, $billingUnitPO); ?>
</script>

<!-- Begin Case Folder heading -->
<div class="fix-ie6-hdr">
<table width="100%" border="0" cellspacing="10" cellpadding="0" >
    <tr>
        <td width="76" valign="top" rowspan="<?php echo $cfHeadRowspan; ?>">
            <span class="fas fa-folder" style="font-size: 50px !important;"></span>
        </td>
        <td class="style11 case-folder-name-heading no-trsl"><?php echo $caseRow['userCaseNum'];?>:</td>
        <td class="style11 fw-normal case-folder-name-heading no-trsl"><?php echo $caseRow['caseName'];?></td>
        <td valign="top" class="iconMenu no-wrap" width="56%" rowspan="<?php
            echo $cfHeadRowspan; ?>">
            <?php echo $cfIcons; ?>
        </td>
    </tr>
<?php
if ($batchNumber) {
    echo <<< EOT
    <tr>
        <td><div class="no-wrap marg-rtsm">Batch Number:</div></td>
        <td class="no-trsl">$batchNumber</td>
    </tr>
EOT;
}
if ($show_linked_to) {
    echo <<< EOT
    <tr>
        <td><div class="no-wrap marg-rtsm">Linked case:</div></td>
        <td class="no-trsl"><a href="$casenum_link_to">$linked_to_casenum</a></td>
    </tr>
EOT;
}
if ($show_linked_from) {
    echo <<< EOT
    <tr>
        <td><div class="no-wrap marg-rtsm">Linked case:</div></td>
        <td class="no-trsl"><a href="$casenum_link_from">$linked_from_casenum</a></td>
    </tr>
EOT;
}
?>
    <tr>
        <td>
            <div class="no-wrap marg-rtsm"><?php echo $_SESSION['regionTitle']?>:</div>
        </td>
        <td><?php echo $regionName; ?></td>
    </tr>
    <tr>
        <td>
            <div class="no-wrap marg-rtsm"><?php echo $_SESSION['departmentTitle']?>:</div>
        </td>
        <td><?php echo $deptName; ?></td>
    </tr>
    <tr>
        <td><div class="marg-rtsm">Scope:</div></td>
        <td><?php echo $caseTypeClientName?></td>
    </tr>
    <tr>
        <td><div class="marg-rtsm">Status:</div></td>
        <td><?php echo $dynStatus?></td>
    </tr>
    <tr>
        <td><div class="marg-rtsm">Stage:</div></td>
        <td class="no-wrap">
<?php

if (isset($_SESSION['caseStageList'][$caseRow['caseStage']])) {
    echo $_SESSION['caseStageList'][$caseRow['caseStage']];
}
if ($showInvitePw && !$awsEnabled) {
    // Admin DDQ Instant Access Link
    $hasAdminDDQInstantAccess = (new TenantFeatures($clientID))->tenantHasFeature(
        Feature::ADMIN_DDQ_ACCESS_LINK,
        Feature::APP_TPM
    );
    $spLink = '<span style="display:inline-block;padding-left:1.5em">'
        .'<a href="javascript:void(0)" onclick="opRequest(\'init-invite-pw\')" title="';
    $spLink .= (getIntakeFormType() !== 'osprey' && $hasAdminDDQInstantAccess) ?
        'Access Questionnaire Form">(Access Form' : 'Show DDQ Invitation login credentials">(show password';
    $spLink .= ')</a></span>';

    echo $spLink;
}
?>
        </td>
    </tr>
<?php

if ($tpRow && $_SESSION['b3pRisk'] && $tpRow->risk) {
    ?>
    <tr>
        <td><div class="no-wrap marg-rtsm">Risk rating:</div></td>
        <td><?php echo $tpRow->risk; ?></td>
    </tr>
    <?php
}

// dupCaseRecord variable is set based off permissions above, so go ahead and use it show/not show
// it to the SP.
if ($dupCaseRecords) {
    $numDups = count($dupCaseRecords);
    echo '
    <tr>
        <td><div class="no-wrap marg-rtsm">Possible Duplicates:</div></td>
        <td><a href="javascript:void(0)" onclick="spfunc.showSpDupCases()" '
        .'title="View possible case duplicates">View List ('. $numDups .')</a></td>
    </tr>';
}
?>
    <tr><td colspan="4">&nbsp;</td></tr>
<?php // major edit; old nested table stuff removed; ?>
</table>

</div>

<div id="iHelpPanel" class="disp-none">
  <div class="hd"></div>
  <div class="bd"></div>
  <div class="ft"></div>
</div>

<?php
 if ($aiSummaryEnabled){
?>
    <div id="caseMonitorPanelDivWrapper"></div>
<?php
}
?>
<!-- End Case Folder heading -->

<div class="tabOverflowContainer">
    <div id="subdemo" class="yui-navset">
        <div id="scrollControlsContainer" class="noOverflow">
            <a id="arrowBack"></a>
            <a id="arrowFwd"></a>
        </div>
    </div>
</div>
<div id="text-cleaner" class="disp-none"></div>

<?php
if ($_SESSION['cflagReassignCase'] && $accCls->allow('reassignCase')) {
    $userRow = fGetUserRowByUserID($caseRow['requestor']);
    $userPID = $userRow[0] ?? 0;
    ?>

    <div id="reassignCtrl" class="disp-none">
    <div id="reassignDiv">
    <div class="hd"><div class="panelFormTitle">Reassign Case Elements</div></div>
    <div class="bd">
        <div style="padding:2px 2px 7px 2px; font-weight:bold;">
    <?php
    echo $caseRow['userCaseNum'] .': &nbsp; '. $caseRow['caseName'];
    ?>
        </div>
        <table align="center" cellpadding="2" cellspacing="0">
        <tr>
            <td>Owner/Requester:</td>
            <td>
            <select id="race-owner" class="marg-rt0 min-width no-trsl" data-tl-ignore="1">
    <?php
    $e_requestor = $dbCls->escape_string($caseRow['requestor']);
    $sql = 'SELECT id, firstName, lastName FROM ' . GLOBAL_DB . ".users "
        ."WHERE userid = '" . $e_requestor . "' LIMIT 1";
    if ($oRow = $dbCls->fetchObjectRow($sql)) {
        echo '<option value="' . $oRow->id . '" selected="selected" class="no-trsl">'
            .$oRow->lastName . ', ' . $oRow->firstName . '</option>';
    } else {
        echo '<option value="0">Choose...</option>';
    }
    ?>
                </select>
            </td>
        </tr>
        <tr>
            <td><?php echo $_SESSION['regionTitle']; ?>:</td>
            <td>
                <select id="race-region" class="marg-rt0 min-width" onchange="_opRequest('race-owner-list')" data-tl-ignore="1">
    <?php
    fCreateDDLfromDB(null, "region", $caseRow['region'], 0, 1);
    ?>
                </select>
            </td>
        </tr>
        <tr>
            <td><?php echo $_SESSION['departmentTitle']; ?>:</td>
            <td>
                <select id="race-dept" class="marg-rt0 min-width" onchange="_opRequest('race-owner-list')" data-tl-ignore="1">
    <?php
    fCreateDDLfromDB(null, "department", $caseRow['dept'], 0, 1);
    ?>
                </select>
            </td>
        </tr>
    <?php
    echo $buCls->selectTagsTR(false, true);
    ?>

        <tr>
            <td colspan="2">
                <div class="ta-right" style="padding-top:7px;">
                    <button id="race-submit" class="btn">Reassign</button> &nbsp;
                    <button id="race-cancel" class="btn">Cancel</button>
                </div>
            </td>
        </tr>
        </table>
        <input type="hidden" name="buIgnore" id="buIgnore" value="1" />
        <input type="hidden" name="poIgnore" id="poIgnore" value="1" />
    </div>
    <div class="ft"></div>
    </div>
    </div>
    <?php
} // end if cflagReassignCase;

    ?>

    <?php

    if ($atIconAccess) {
        $curInvstProfile = fGetUserRow($caseRow['caseInvestigatorUserID']);
        $curInvstName = $curInvstProfile['firstName'] . ' ' . $curInvstProfile['lastName'];

        // just get the list, so we can reuse it for add task.
        $ddlUsers = fCreateUserDDL(false, false, false, true);
        ?>
        <div id="spAssignCaseCtrl" class="disp-none">
        <div id="spAssignCase">
        <?php
        if ($atCanAssign) {
            ?>
            <div class="hd"><div class="panelFormTitle">Primary Case Assignment</div></div>
            <div class="bd">
            <div class="cudat-large marg-bot1e">
            This will assign/reassign the primary case investigator.
            </div>
            <div class="marg-botsm fw-bold">
            <table>
                <tr>
                    <td>Current Investigator:</td>
                    <td class="no-trsl"><?php echo $curInvstName; ?></td>
                </tr><tr>
                    <td colspan="2">&nbsp;</td>
                </tr><tr>
                    <td>Assign to:</td>
                    <td>
        <?php
        if ($ddlUsers) {
            echo '
                        <select id="primaryInvestigator" name="primaryInvestigator" '
                            .'class="marg-rt0">';
            if (empty($caseRow['caseInvestigatorUserID'])) {
                echo '<option value="">Choose...</option>';
            }

            foreach ($ddlUsers as $u) {
                $selected = ($u->id == $caseRow['caseInvestigatorUserID']) ?
                    ' selected="selected"' : '';
                echo '
                            <option value="'. $u->id .'"'. $selected .' class="no-trsl">'
                                .$u->lastName .', '. $u->firstName .'</option>';
            }
            echo '
                        </select>
            <input type="hidden" name="hf_caseInvestigatorUserID" id="hf_caseInvestigatorUserID"
            value="'. $caseRow['caseInvestigatorUserID'] .'" />';
        } ?>
                    </td>
                </tr>
            </table>
            </div>
            <div class="marg-topsm ta-right">
            <input
                class="marg-rt0" type="button" value="Assign Case"
                id="spac-assign" />
            </div>
            </div>
            <div class="ft"></div>
            <?php
        } // end if canAssign;
        ?>
        <div class="hd"><div class="panelFormTitle">Add Case Task</div></div>
        <div class="bd">
        <div class="cudat-large marg-bot1e">
            This allows for assigning individual tasks within the case to an alternate investigator.
        </div>
        <div class="marg-botsm fw-bold">
            <table cellpadding="4" cellspacing="0" border="0">
                <tr>
                    <td>Assign Task To:</td>
                    <td>
        <?php
        if ($ddlUsers) {
            echo '
                        <select id="taskInvestigator" name="taskInvestigator" class="marg-rt0">
                            <option value="">Choose...</option>';
            foreach ($ddlUsers as $u) {
                echo '
                            <option value="'. $u->id .'" class="no-trsl">'
                                .$u->lastName .', '. $u->firstName .'</option>';
            }

            echo '
                        </select>';
        }
        ?>
                    </td>
                </tr><tr>
                    <td>Select a Task:</td>
                    <td><?php echo fCreateTaskList(); ?></td>
                </tr><tr>
                    <td colspan="2">Task Details:</td>
                </tr><tr>
                    <td colspan="2"><textarea id="spac-details" cols="30" rows="5"
                            class="marg-rt0 cudat-large cudat-small-h"></textarea><br />
                        <br /><br />
                    </td>
                </tr>
            </table>
        </div>
        <div class="marg-topsm ta-right">
            <input type="hidden" name="cInv" id="cInv" value="<?php
                echo $caseRow['caseInvestigatorUserID']; ?>" />
                <input type="hidden" name="taskRef" id="taskRef" value="<?php
                echo $caseRow['userCaseNum']; ?>" />
                <input class="ta-cent marg-rt0" type="button" value="Assign Case Task"
                 id="spac-assignTask" />
            </div>
        </div>
        <div class="ft"></div>
        </div>
        </div>

<?php
    } // end if atIconAccess;

// duplicate case list panel
    if ($dupCaseRecords) {
        $thStyle = 'text-align:left; padding: 5px; background:#d4d5d6; border-right:1px solid #555;';
        $thStyleEnd = 'text-align:left; padding: 5px; background:#d4d5d6;';

        $tdStyle = 'text-align:left; padding:5px; background:{bgColor}; border-right:1px solid #555;';
        $tdBg1 = '#fefefe';
        $tdBg2 = '#cddcf3';

        echo '
    <div id="spDupCasesCtrl" class="disp-none">
    <div id="spDupCases">
    <div class="hd"><div class="panelFormTitle">Potential Duplicates of this Case</div></div>
    <div class="bd">
        <div class="cudat-large marg-bot1e">
            The following is a list of potential duplications for this case which you may wish to review.
        </div>
        <table align="center" class="gray-sp" cellpadding="5" cellspacing="2">
            <tr>
                <th>Case Name</th>
                <th>Company Name</th>
                <th>Company DBA</th>
                <th>Country</th>
                <th>View Case Folder</th>
            </tr>';

        $trClass = '';
        foreach ($dupCaseRecords as $c) {
            $trClass = ($trClass == 'odd') ? 'even' : 'odd';
            $dupCaseLink = '<a href="/cms/case/casehome.sec?id='. $c->caseID .'&tname=casefolder&icli='
            . $c->clientID.'">View: '. $c->caseNum .'</a>';
            echo '
            <tr class="'. $trClass .'">
                <td>'. $c->caseName .'</td>
                <td>'. $c->companyName .'</td>
                <td>'. $c->companyDBA .'</td>
                <td>'. $c->country .'</td>
                <td>'. $dupCaseLink .'</td>
            </tr>';
        }
    ?>

        </table>
    </div>
    </div>
    </div>

    <?php
    }

    if ($userClass == 'vendor') {
        if ($canIncrement) {
            ?>
            <div id="spIncrementCaseCtrl" class="disp-none">
                <div id="spIncrementCase">
                    <div class="hd">
                        <div class="panelFormTitle"><strong>Increment Case</strong></div>
                    </div>
                    <div class="bd">
                        <div class="cudat-large marg-bot1e">
                        Convert an existing case folder to an incremental report.
                        Incremental reports are reports which include fewer details
                        (as they are supplemental in nature), and likewise the pricing
                        for these reports is reduced by 25%.
                        </div>
                        <div class="marg-topsm ta-right">
                            <input id="increment-caseType" type="hidden" value="<?php echo $incrementedCaseTypeID; ?>" />
                            <input class="ta-cent" type="button" value="Increment Case" id="increment-submit" />
                            <input class="ta-cent marg-rt0" type="button" value="Cancel" id="increment-cancel" />
                        </div>
                    </div>
                    <div class="ft"></div>
                </div>
            </div>
            <?php
        }
        ?>

        <div id="spRejectCaseCtrl" class="disp-none">
        <div id="spRejectCase">
        <div class="hd"><div class="panelFormTitle">Reject Case</div></div>
        <div class="bd">
        <div class="cudat-large marg-bot1e">
            The comment entered here will be added to Case Folder Notes and will be
            viewable by the subscriber. It will also be included in a notice to the
            requester when the case is rejected.
        </div>
        <div class="marg-botsm fw-bold">
            Reason for rejecting this case:
            <span class="fw-normal">(required)</span>
        </div>
        <textarea id="sprc-comment" cols="30" rows="5" class="marg-rt0 cudat-large cudat-small-h">
        </textarea><br />
        (maximum 2,000 characters)
        <div class="marg-topsm ta-right">
            <input class="ta-cent" type="button" value="Reject Case" id="sprc-reject" />
            <input class="ta-cent marg-rt0" type="button" value="Cancel" id="sprc-cancel" />
        </div>
        </div>
        <div class="ft"></div>
        </div>
        </div>

        <?php
    } // end if userclass vendor;

    if ($accCls->allow('acceptCase') && $_SESSION['cflagAcceptPassFail']) {
        $useApfDD = 'false'; // for JS
        $e_caseType = intval($caseRow['caseType']);
        $apfRsql = "SELECT id, reason, (pfType + 0) AS enumIdx FROM passFailReason "
        ."WHERE clientID='$e_clientID' AND caseType='$e_caseType' ORDER BY enumIdx ASC";
        if ($apfReasons = $dbCls->fetchObjectRows($apfRsql)) {
            $useApfDD = 'true';
        }
        ?>

        <div id="showInvitePwCtrl" class="disp-none">
        <div id="showInvitePw">
        <div class="hd"><div class="panelFormTitle cudat-medium">DDQ Invitation Access</div></div>
        <div class="bd"></div>
        <div class="ft"></div>
        </div>
        </div>

        <div id="apfCtrl" class="disp-none">
        <div id="acceptPassFailDiv">
        <div class="hd"><div class="panelFormTitle">Accept Investigation</div></div>
        <div class="bd">
        <div style="padding:2px 2px 7px 2px; font-weight: bold;">
            <?php echo $caseRow['userCaseNum']?>: &nbsp; <?php echo $caseRow['caseName']?>
        </div>
        <table cellpadding="2" cellspacing="0">
        <tr>
            <td colspan="2">
                <div style="margin-bottom:7px">Outcome of this Investigation?</div>
            </td>
        </tr>
    <?php
    if ($apfReasons) {
        ?>
        <tr>
            <td colspan="2">
                <div class="indent">
                    <select id="apf-reason">
                        <option value="0" selected="selected">Choose...</option>
        <?php
        foreach ($apfReasons as $r) {
            echo '<option value="' . $r->id . '">' . $r->reason . '</option>' . "\n";
        }
        ?>
                    </select>
                </div>
            </td>
        </tr>
        <?php
    } else {
        ?>
        <tr>
        <td width="25" align="right">
            <input type="radio" name="rb-apf" id="rb-apf-pass" value="pass" class="marg-rt0" />
        </td>
        <td>
            <label class="fw-normal" for="rb-apf-pass">Pass</label>
        </td>
        </tr>
        <tr>
        <td align="right">
            <input type="radio" name="rb-apf" id="rb-apf-fail" value="fail" class="marg-rt0" />
        </td>
        <td>
            <label class="fw-normal" for="rb-apf-fail">Fail</label>
        </td>
        </tr>
        <?php
    } // end if/else apfReasons;
        ?>
        <tr>
            <td colspan="2">
                <div class="marg-topsm">Comment (optional)</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="indent">
                    <textarea id="apf-comment" class="marg-rt0 cudat-large cudat-small-h"
                        rows="5" cols="40" wrap="virtual"></textarea>
                </div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="ta-right" style="padding-top:7px;">
                    <button id="apf-submit" class="btn">Submit</button> &nbsp;
                    <button id="apf-cancel" class="btn">Cancel</button>
                </div>
            </td>
        </tr>
        </table>
        </div>
        <div class="ft"></div>
        </div>
        </div>

<?php
    } // end if cflagAcceptPassFail;

    if ($showReviewPanel) {
        ?>
        <div id="creview-view" class="disp-none">
        <div class="hd"><div class="panelFormTitle">Potential Compliance Issues</div></div>
        <div class="bd">
        <div id="creview-items">
        <?php
        $creviewDdqTopMarg = 'marg-top1e ';
        if ($_SESSION['b3pRisk']) {
            $creviewDdqTopMarg = 'marg-top2e ';
            ?>
            <div class="marg-top1e fw-bold">Deviated from Recommended Scope of Due Diligence:</div>
            <div id="creview-scope"></div>
            <?php
        }
        ?>
            <div class="<?php echo $creviewDdqTopMarg; ?>fw-bold">
                Unexpected Response Provided by Third Party on Intake Form:
            </div>
            <div id="creview-ddq"></div>
            <div class="marg-top2e fw-bold">
                Potential Red Flag(s) Identified During Due Diligence:
            </div>
            <div id="creview-redflags"></div>
        </div>
        </div>
        <div class="ft"></div>
        </div>
        <?php
    } // end if showReviewPanel;
?>

<script type="text/javascript">

<?php // disable links pending load of dependent components ?>
opActive = false;

YAHOO.namespace("ihelp");
iHelp = YAHOO.ihelp;
iHelp.data = [];
YAHOO.namespace("spfunc");
spfunc = YAHOO.spfunc;
YAHOO.namespace("creview");
creview = YAHOO.creview;
creview.ready = false;
creview.ScopeDeviation = '';
creview.DdqAlerts = '';
creview.RedFlags  = '';



YAHOO.namespace("gdcifr");

function rtrim (str) {
    return str.replace(/^\s*/,'').replace(/\s*$/, '');
}

function confirmAction(action, recid, tbl)
{
    var btns;
    if (action == 'attach-del' || action == 'note-del' || action == 'crev-note-del')
    {
        if (!confirmDiag.rendered) {
            confirmDiag.rendered = true;
            confirmDiag.render(document.body);
        }
        var pmt = "Are you <i><u>certain</u></i> you want to delete this ";
        confirmDiag.setHeader("Please Confirm Deletion");
        if (action == 'attach-del') {
            pmt += "attachment?";
        } else if (action == 'note-del' || action == 'crev-note-del') {
            pmt += "note?";
        } else {
            pmt += 'item?';
        }
        confirmDiag.setBody(pmt);

        btns = [
            { text: "Yes", handler: function(){
                if (action == 'attach-del') {
                    opRequest(action, recid, tbl);
                } else {
                    opRequest(action, recid);
                }
                confirmDiag.hide();
            }},
            { text:"No", handler: function(){ confirmDiag.hide(); }, isDefault:true}
        ];
        confirmDiag.cfg.setProperty("buttons", btns);
        confirmDiag.show();
    }
}


function opRequest(op)
{
    if (opActive)
    {
        switch (op)
        {
        case 'note-show':
        case 'crev-note-show':
            _opRequest(op, arguments[1]);
            break;
        case 'attach-del':
        case 'crev-note-init-show':
            _opRequest(op, arguments[1], arguments[2]);
            break;
        case 'crev-tgl-status':
            _opRequest(op, arguments[1], arguments[2], arguments[3], arguments[4]);
            break;
        default:
            _opRequest(op);
        }
    }
    return false;
}

function initPassFail()
{
    if (!apfPanel.rendered)
    {
        apfPanel.rendered = true;
        apfPanel.render(document.body);
    }
    apfPanel.show();
    YAHOO.util.Dom.replaceClass('apfCtrl', 'disp-none', 'disp-block')
    Dom.get('apf-comment').focus();
    return false;
}

function initReassignPanel()
{
    if (!reassignPanel.rendered)
    {
        reassignPanel.rendered = true;
        reassignPanel.render(document.body);
        <?php // set selects to same width ?>
        var i, reg, maxw = 0;
        var ids = ['race-region', 'race-dept', 'race-owner',
            'lb_billingUnit', 'lb_billingUnitPO'
        ];
        for (i = 0; i < ids.length; i++) {
            reg = YAHOO.util.Dom.getRegion(ids[i]);
            if (reg.width > maxw) maxw = reg.width;
        }
        for (i = 0; i < ids.length; i++) {
            YAHOO.util.Dom.setStyle(ids[i], 'width', maxw + 'px');
        }
        _opRequest('race-owner-list');
<?php
echo $buCls->initLinkedSelects($billingUnit, $billingUnitPO);
?>
    }
    reassignPanel.show();
    YAHOO.util.Dom.replaceClass('reassignCtrl', 'disp-none', 'disp-block');
    return false;
}

function initStatusPanel()
{
    if (!statusPanel.rendered)
    {
        statusPanel.rendered = true;
        statusPanel.render(document.body);
    }
    statusPanel.show();
    YAHOO.util.Dom.replaceClass('statusCtrl', 'disp-none', 'disp-block');
    return false;
}

(function(){
    var Dom = YAHOO.util.Dom;
    var Event = YAHOO.util.Event;
    var Util = YAHOO.cms.Util;
    var Lang = YAHOO.lang;

<?php
if ($screeningID) {
    echo '    YAHOO.gdcifr.screeningID = ', $screeningID, ';';
    ?>

    YAHOO.gdcifr.showPanelClose = function(show) {
        var el = Dom.getElementBy(function(e){return true;}, 'a', 'gdcpnl');
        if (el) {
            if (show) {
                Dom.removeClass(el, 'v-hidden');
            } else {
                Dom.addClass(el, 'v-hidden');
            }
        }
    };

    YAHOO.gdcifr.closePanel = function() {
        var pnl = YAHOO.cms.IframePanel.getPanel('gdcpnl');
        pnl.panel.hide();
    };

    YAHOO.gdcifr.showGDC = function()
    {
    <?php
        if($aiSummaryEnabled){
            // new ai summary code
    ?>
        var alreadyLoading = $("#showGDCButton").hasClass("disabled");
        if (alreadyLoading === false) {
            $('#showGDCButton').addClass('disabled');
            if(document.getElementById('caseMonitorPanelDiv') == null) {
                var scrID = '';
                if (YAHOO.gdcifr.screeningID != 0) {
                    scrID = '?ls=' + YAHOO.gdcifr.screeningID;
                    YAHOO.gdcifr.screeningID = 0;
                }
                $("#cmTabs #cmTabs_tab2 #caseMonitorPanelDivWrapper").load(
                        '<?php echo $sitepath; ?>case/gdc-mm-ai.sec' + scrID,
                        function(responseTxt, statusTxt, xhr) {
                            $('#showGDCButton').removeClass('disabled');
                        });
            } else {
                casePanel.render(document.body);
                casePanel.center();
                casePanel.show();
                $('#showGDCButton').removeClass('disabled');
            }
        }

    <?php
        }

        else {
            // old code
    ?>
        var scrID = '';
        if (YAHOO.gdcifr.screeningID != 0) {
            scrID = '?ls=' + YAHOO.gdcifr.screeningID;
            YAHOO.gdcifr.screeningID = 0;
        }
        var config = {
            width: 1020,
            maxHeight: 2000,
            percentViewPort: 95,
            url: '<?php echo $sitepath; ?>case/gdc.sec' + scrID
        };
        var gdcpanel = new YAHOO.cms.IframePanel('gdcpnl', config, 'Global Database Check');
        gdcpanel.show.call(gdcpanel);

    <?php

    }
    ?>

    <?php
    if ($triggerGdc) {
        ?>
        var pnl = YAHOO.cms.IframePanel.getPanel('gdcpnl');
        pnl.panel.hideEvent.subscribe(function(o){
            window.location = '<?php echo $sitepath, 'metrics/gdc/icaseGdcReport.sec'; ?>';
        });
        <?php
    }
    ?>
        return false;
    };

    <?php
}

if ($showReviewPanel) {
    ?>

    var showReviewPanel = function(){
        if (!cReviewPanel.rendered) {
            cReviewPanel.render(document.body);
            cReviewPanel.rendered = true;
        }
        var rfid, cond, txt, qty, cked;
    <?php
    if ($_SESSION['b3pRisk']) {
        ?>
        Dom.get('creview-scope').innerHTML = creview.ScopeDeviation;
        <?php
    } ?>
        Dom.get('creview-ddq').innerHTML = creview.DdqAlerts;
        Dom.get('creview-redflags').innerHTML = creview.RedFlags;
        <?php // Fit report to content or max height at 85% of viewport ?>
        var rptEl = Dom.get('creview-items'); <?php // formatted detail container ?>
        var pnlEl = Dom.get('creview-view'); <?php // panel ?>
        Dom.getElementsByClassName('bd', 'div', pnlEl, function(el){
            Dom.setStyle(el,'background-color','#ffffff');
        });
        var region = Dom.getClientRegion();
        Dom.setStyle(rptEl, 'overflow', 'hidden');
        Dom.setStyle(rptEl, 'height', '0pt');
        cReviewPanel.center();
        cReviewPanel.show();
        var maxPanelHeight = 0.85 * (region.bottom - region.top);
        var curPanelHeight = parseInt(Dom.getStyle(pnlEl, 'height'));
        if (isNaN(curPanelHeight)) {
            curPanelHeight = parseInt(pnlEl.offsetHeight);
            if (isNaN(curPanelHeight)) {
                curPanelHeight = 197; <?php // I give up! How 'bout a guess? ?>
            }
        }
        var pixToAdd = rptEl.scrollHeight + 5;
        if ((curPanelHeight + pixToAdd) > maxPanelHeight) {
            pixToAdd = maxPanelHeight - curPanelHeight;
        }
        if (pixToAdd < 30) {
            pixToAdd = 30;
        }
        var topToMove = pixToAdd / 2;
        var curPanelTop = cReviewPanel.cfg.getProperty('y');
        cReviewPanel.cfg.setProperty('y', curPanelTop - topToMove);
        Dom.setStyle(rptEl, 'overflow', 'auto');
        Dom.setStyle(rptEl, 'height', pixToAdd + 'px');
    };

<?php
} ?>

    var loadCaseFolder = function() {
        var delegate = YAHOO.plugin.Dispatcher.delegate;

<?php
/**
 * @author: Harris Bierhoff
 * @date: 2014-12-04
 * @todo: consolidate subscriber settings and centralize Case tabs
 *        The conditions for these Case tabs need to be consistent with those in
 *        includes/php/shell_tabs.php as used in DDQ tabs. Currently, they are out of step with each
 *        other. The subscriber settings should be consolidated and ultimately centralized for
 *        access by both areas.
 */
$caseType = 0;
$ddqQuesVer = '';
if (!empty($ddqRow)) {
    $caseType = $ddqRow[107];
    $ddqQuesVer = $ddqRow[126];
}
$caseTabLbls = ['personnel' => getOqLabelText($clientID, 'TEXT_PERSONNEL_TAB', 'EN_US', $caseType, $ddqQuesVer), 'buspract' => getOqLabelText($clientID, 'TEXT_BUSPRACT_TAB', 'EN_US', $caseType, $ddqQuesVer), 'relation' => getOqLabelText($clientID, 'TEXT_RELATION_TAB', 'EN_US', $caseType, $ddqQuesVer), 'profinfo' => getOqLabelText($clientID, 'TEXT_PROFINFO_TAB', 'EN_US', $caseType, $ddqQuesVer), 'addinfo' => getOqLabelText($clientID, 'TEXT_ADDINFO_TAB', 'EN_US', $caseType, $ddqQuesVer), 'auth' => getOqLabelText($clientID, 'TEXT_AUTH_TAB', 'EN_US', $caseType, $ddqQuesVer)];

/*
 * For the Osprey Forms we need to check and see if any onlineQuestions are assigned to a tab that is not going
 * to be rendered. This is to resolve an issue where the Osprey Forms may assign question(s) to
 * onlineQuestions.pageTab that is not included in the form/caseType. Per GitLab #2635 it was decided to go
 * ahead and display the tab even if it's not one that should be shown instead of modifying the Osprey Forms
 * code.
 */

 $pageTabs = getAssignedPageTabs($_SESSION['bDDQcreated'], $clientID, $caseType, $ddqQuesVer, 'EN_US');

// Lets See if this is a Health Care Professional Questionnaire
if ((isset($_SESSION['ddqRow'])) && ($caseType == DUE_DILIGENCE_HCPDI
    || $caseType == DUE_DILIGENCE_HCPDI_RENEWAL)
) {
    $lbl = (!empty($caseTabLbls['profinfo']) ? $caseTabLbls['profinfo'] : $trText['tab_Professional_Information']);
    ?>
          var tabViewS =  new YAHOO.widget.TabView('subdemo',{'orientation':'top'});

          delegate(new YAHOO.widget.Tab({
              label: '<?php echo $lbl; ?>',
              dataSrc: '/cms/case/caseFolderPages/company.sec?id=<?php echo $caseID?>',
              cacheData: true,
              active: true
          }), tabViewS);

    <?php
    if ($accCls->allow('accCaseAddInfo') && $caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD) {
        $lbl = (!empty($caseTabLbls['addinfo']) ? $caseTabLbls['addinfo'] : $trText['tab_Additional_Info']);
        ?>
          tabViewS.addTab( new YAHOO.widget.Tab({
              label: '<?php echo $lbl; ?>',
              dataSrc: '/cms/case/caseFolderPages/addinfo.sec?id=<?php echo $caseID?>',
              cacheData: true,
              active: false
          }));
        <?php
    }
    if ($accCls->allow('accCaseAttach')) {
        ?>
          delegate(new YAHOO.widget.Tab({
            label: '<?php echo $trText['tab_Attachments'];?>',
              dataSrc: '/cms/case/caseFolderPages/dt-attachments.sec',
              cacheData: true,
              active: false
          }), tabViewS);
        <?php
    }
} else { // end if $_SESSION['ddqRow'][107] == DUE_DILIGENCE_HCPDI
    $lbl = $trText['tab_Company'];
    ?>
    <?php //Start Set 2
    // Company tab
    ?>
    var tabViewS =  new YAHOO.widget.TabView('subdemo',{'orientation':'top'});

    delegate(new YAHOO.widget.Tab({
        label: '<?php echo $lbl; ?>',
        dataSrc: '/cms/case/caseFolderPages/company.sec?id=<?php echo $caseID?>',
        cacheData: true,
        active: true
    }), tabViewS);
    <?php
    if ($accCls->allow('accCasePersonnel') && $caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD) {
        if (!(isset($_SESSION['ddqRow']))
            || (isset($_SESSION['ddqRow'])
              && !($_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM2
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM2_COPY85
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM3
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM4
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA_FORM2
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM2_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM3_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM4_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA_FORM2_RENEWAL
                || $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SHORTFORM
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM2
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM3
                || $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SHORTFORM_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM2_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM3_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_4PAGE
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_4PAGE_RENEWAL
                || ($_SESSION['ddqRow'][107] >= DDQ_SHORTFORM_2PAGE_1601
                    && $_SESSION['ddqRow'][107] <= DDQ_SHORTFORM_2PAGE_1620
                )
                || ($_SESSION['ddqRow'][107] >= DDQ_SHORTFORM_2PAGE_1701
                    && $_SESSION['ddqRow'][107] <= DDQ_SHORTFORM_2PAGE_1740
                )
              )
              || isset($pageTabs['personnel'])
            )
        ) {
            $lbl = (!empty($caseTabLbls['personnel']) ? $caseTabLbls['personnel'] : $trText['tab_Personnel']);
            // Personnel tab
            ?>

            tabViewS.addTab( new YAHOO.widget.Tab({
                label: '<?php echo $lbl; ?>',
                dataSrc: '/cms/case/caseFolderPages/personnel.sec?id=<?php echo $caseID?>',
                cacheData: true,
                active: false
            }));
<?php
        }
    }
    if ($_SESSION['bDDQcreated'] && $caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD) { // next 2 tabs only apply if case originated from DDQ
        if ($accCls->allow('accCaseBizPrac')
            && ($_SESSION['ddqRow'][107] != DDQ_SHORTFORM_5PAGENOBP)
            && ($_SESSION['ddqRow'][107] != DDQ_5PAGENOBP_FORM2)
            && ($_SESSION['ddqRow'][107] != DDQ_5PAGENOBP_FORM3)
            && ($_SESSION['ddqRow'][107] != DDQ_SHORTFORM_3PAGECDKPACL1)
            && ($_SESSION['ddqRow'][107] != DDQ_SHORTFORM_3PAGECDKPACL2)
            && !($_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM2
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM2_COPY85
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA_FORM2
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM2_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGEA_FORM2_RENEWAL
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM4
                || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_2PAGE_FORM3
                || ($_SESSION['ddqRow'][107] >= DDQ_SHORTFORM_2PAGE_1601
                    && $_SESSION['ddqRow'][107] <= DDQ_SHORTFORM_2PAGE_1620
                )
                || ($_SESSION['ddqRow'][107] >= DDQ_SHORTFORM_2PAGE_1701
                    && $_SESSION['ddqRow'][107] <= DDQ_SHORTFORM_2PAGE_1740
                )
            )
            || isset($pageTabs['buspract'])
        ) {
            if (!empty($caseTabLbls['buspract'])) {
                $lbl = $caseTabLbls['buspract'];
            } else {
                $lbl = $trText['tab_Business_Practices'];
                if (($_SESSION['ddqRow'][107] == DUE_DILIGENCE_SHORTFORM
                    || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM2
                    || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM3
                    || $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SHORTFORM_RENEWAL
                    || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM2_RENEWAL
                    || $_SESSION['ddqRow'][107] == DDQ_SHORTFORM_FORM3_RENEWAL)
                    || (in_array($_SESSION['clientID'], HP_ALL))
                ) {
                    $lbl = $trText['tab_Questionnaire'];
                }
            }
            // Business Practices tab
            ?>
            tabViewS.addTab( new YAHOO.widget.Tab({
                label: '<?php echo $lbl; ?>',
                dataSrc: '/cms/case/caseFolderPages/bizprac.sec?id=<?php echo $caseID?>',
                cacheData: true,
                active: false
            }));
<?php
        }
        if ($accCls->allow('accCaseRelation')
            && $_SESSION['ddqRow'][107] != DUE_DILIGENCE_SHORTFORM
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_3PAGECDKPACL1
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_3PAGECDKPACL2
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_FORM2
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_FORM3
            && $_SESSION['ddqRow'][107] != DUE_DILIGENCE_SHORTFORM_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_FORM2_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_FORM3_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_4PAGE
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_4PAGE_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE_FORM4
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE_FORM3
            && $_SESSION['ddqRow'][107] != DDQ_4PAGE_FORM1
            && $_SESSION['ddqRow'][107] != DDQ_4PAGE_FORM2
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGEA
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGEA_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE_FORM2
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE_FORM2_COPY85
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGEA_FORM2
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGE_FORM2_RENEWAL
            && $_SESSION['ddqRow'][107] != DDQ_SHORTFORM_2PAGEA_FORM2_RENEWAL
            && !($_SESSION['ddqRow'][107] >= DDQ_SHORTFORM_2PAGE_1601
              && $_SESSION['ddqRow'][107] <= DDQ_SHORTFORM_2PAGE_1620
            )
            && !($_SESSION['ddqRow'][107] >= DDQ_SHORTFORM_2PAGE_1701
              && $_SESSION['ddqRow'][107] <= DDQ_SHORTFORM_2PAGE_1740
            )
            && !(in_array($_SESSION['clientID'], HP_ALL)
                && $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SBI)
            && !(in_array($_SESSION['clientID'], HP_ALL)
                && $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SBI_RENEWAL)
            && !(in_array($_SESSION['clientID'], HP_ALL)
                && $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SBI_RENEWAL_2)
            && !(in_array($_SESSION['clientID'], HP_ALL)
                && $_SESSION['ddqRow'][107] == DDQ_SBI_FORM2)
            && !(in_array($_SESSION['clientID'], HP_ALL)
                && $_SESSION['ddqRow'][107] == DDQ_SBI_FORM4)
            && !(in_array($_SESSION['clientID'], HP_ALL)
                && in_array($_SESSION['ddqRow'][107], [DDQ_SBI_FORM6, DDQ_SBI_FORM6_RENEWAL, DDQ_SBI_FORM5, DDQ_SBI_FORM5_RENEWAL]))
            || isset($pageTabs['relation'])
        ) {
            $lbl = (!empty($caseTabLbls['relation']) ? $caseTabLbls['relation'] : $trText['tab_Relationship']);
            // Relationship tab
            ?>
            delegate( new YAHOO.widget.Tab({
                label: '<?php echo $lbl; ?>',
                dataSrc: '/cms/case/caseFolderPages/relationships.sec?id=<?php echo $caseID?>',
                cacheData: true,
                active: false
              }), tabViewS);
<?php
        } // end $_SESSION['ddqRow'][107] != DUE_DILIGENCE_SHORTFORM
    } // end if $_SESSION['bDDQcreated']

    if ($accCls->allow('accCaseAddInfo') && $caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD) {
        if ((isset($_SESSION['ddqRow']) && $_SESSION['ddqRow'][107] == DUE_DILIGENCE_SHORTFORM)
            || in_array($_SESSION['clientID'], HP_ALL)
        ) {
            $lbl = (!empty($caseTabLbls['auth']) ? $caseTabLbls['auth'] : $trText['submit']);
        } else {
            $lbl = (!empty($caseTabLbls['addinfo']) ? $caseTabLbls['addinfo'] : $trText['tab_Additional_Info']);
        }
        // Additional Information tab
        ?>
        tabViewS.addTab( new YAHOO.widget.Tab({
            label: '<?php echo $lbl; ?>',
            dataSrc: '/cms/case/caseFolderPages/addinfo.sec?id=<?php echo $caseID?>',
            cacheData: true,
            active: false
        }));
<?php
    }
    // Osprey Workflow tab
    if ($accCls->ftr->tenantHas(\Feature::TENANT_WORKFLOW_OSP_CASE_TAB) && $caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD) {
        ?>
        delegate( new YAHOO.widget.Tab({
            label: 'Workflow',
            dataSrc: '/cms/case/caseFolderPages/case-workflow.php',
            cacheData: true,
            active: false
        }), tabViewS);
        <?php
    }
    // Attachments tab
    if ($accCls->allow('accCaseAttach')) {
        ?>
          delegate( new YAHOO.widget.Tab({
              label: '<?php echo $trText['tab_Attachments'];?>',
              dataSrc: '/cms/case/caseFolderPages/dt-attachments.sec',
              cacheData: true,
              active: false
          }), tabViewS);
        <?php
    }
} // end else

// Check for custom data
if ($showCustomData) {
    // Custom fields tab
    ?>

           delegate (new YAHOO.widget.Tab({
            label: '<?php echo $_SESSION['customLabel-customFieldsCase'] ?>',
            dataSrc: '/cms/thirdparty/customdata.sec',
            cacheData: true,
            active: false
           }), tabViewS);

<?php
}

if ($accCls->allow('accCaseNotes')) {
    // Notes tab
        ?>

        var caseNotesTab = new YAHOO.widget.Tab({
         label: '<?php echo $_SESSION['customLabel-caseNotes']; ?>',
         dataSrc: '/cms/case/caseFolderPages/casenotes.sec',
            <?php
         // TODO: Switch to this once Delta Case Folder - Notes section is live
         //dataSrc: '/tpm/case/caseFldr/notes',
            ?>
         cacheData: true,
         active: false
        });
        delegate(caseNotesTab, tabViewS);

<?php
}

// Can we show the reviewer tab?
if ($userClass != 'vendor' && $showReviewTab && $caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD) {
    // Reviewer tab
    ?>
        var caseReviewerTab = new YAHOO.widget.Tab({
            label: '<?php echo $trText['tab_Reviewer'];?>',
            dataSrc: '/cms/case/caseFolderPages/reviewer.sec',
            cacheData: false,
            active: false
           });
           delegate(caseReviewerTab, tabViewS);
<?php
}


if ($accCls->allow('accCaseLog')) {
    // hide audit log from user accessing case because of case task assignment.
    if ($userClass != 'vendor' || ($userClass == 'vendor' && $atManageCase === true)) {
        // Audit log tab
        ?>

        var auditLogTab = new YAHOO.widget.Tab({
         label: '<?php echo $trText['tab_Audit_Log'];?>',
         dataSrc: '/cms/case/caseFolderPages/caselog.sec',
         cacheData: true,
         active: false
        });
        delegate(auditLogTab, tabViewS);

        function caseSubTabChange(e)
        {
            var tab = tabViewS.getTab(e.newValue);
            switch (tab.get('label'))
            {
            case '<?php echo $trText['tab_Audit_Log'];?>':
                caseLogRefresh();
                YAHOO.util.Dom.setStyle('casedocUpl-pbar', 'visibility', 'hidden');
                break;
            case '<?php echo $trText['tab_Attachments'];?>':
                if (YAHOO.util.Dom.get('casedocUpl-pbar') && !YAHOO.util.Dom.hasClass('casedocUpl-pbar', 'yui-overlay-hidden')) {
                    YAHOO.util.Dom.setStyle('casedocUpl-pbar', 'visibility', 'visible');
                } else {
                    YAHOO.util.Dom.setStyle('casedocUpl-pbar', 'visibility', 'hidden');
                }
                break;
            default:
                YAHOO.util.Dom.setStyle('casedocUpl-pbar', 'visibility', 'hidden');
                break;
            }
        }
        tabViewS.subscribe('activeIndexChange', caseSubTabChange);

<?php
    } // $atManageCase = true;
} ?>

        confirmDiag = new YAHOO.widget.SimpleDialog("confirm-dlg", {
            width: "300px",
            fixedcenter: "contained",
            visible: false,
            modal: true,
            draggable: true,
            close: false,
            icon: YAHOO.widget.SimpleDialog.ICON_HELP,
            constraintoviewport: false
        });
        confirmDiag.rendered = false;

<?php

if ($showReviewPanel) {
    ?>
    cReviewPanel = new YAHOO.widget.Panel('creview-view', {
        width: '600px',
        close: true,
        modal: true,
        draggable: true,
        visible: false
    });
    cReviewPanel.rendered = false;
    Dom.replaceClass('creview-view', 'disp-none', 'disp-block');
    <?php
}

if ($showInvitePw) {
    ?>
        sipwPanel = new YAHOO.widget.Panel('showInvitePw', {
            modal: true,
            close: true,
            draggable: true,
            fixedcenter: 'contained',
            visible: false
        });
        sipwPanel.rendered = false;
        sipwPanel.hideEvent.subscribe(function(o){sipwPanel.setBody('(content redacted)');});
    <?php
}

if ($_SESSION['cflagReassignCase'] && $accCls->allow('reassignCase')) {
    ?>

        reassignPanel = new YAHOO.widget.Panel('reassignDiv', {
            modal: true,
            close: false,
            draggable: true,
            fixedcenter: 'contained',
            visible: false
        });
        reassignPanel.rendered = false;

        var raceSubmit = new YAHOO.widget.Button('race-submit', {
            id: 'race-sub-btn',
            label: 'Reassign',
            type: 'button'
        });
        var raceCancel = new YAHOO.widget.Button('race-cancel', {
            id: 'race-can-btn',
            label: 'Cancel',
            type: 'button'
        });
        raceSubmit.on('click', function(o){
            _opRequest('save-reassign');
        });
        raceCancel.on('click', function(o){
            reassignPanel.hide();
        });
    <?php
}
    ?>

    <?php
    if ($accCls->allow('acceptCase') && $_SESSION['cflagAcceptPassFail']) {
        ?>

        apfPanel = new YAHOO.widget.Panel('acceptPassFailDiv', {
            modal: true,
            close: false,
            draggable: true,
            fixedcenter: 'contained',
            visible: false
        });
        apfPanel.rendered = false;
        apfPanel.useDD = <?php echo $useApfDD?>;

        var apfSubmit = new YAHOO.widget.Button('apf-submit', {
            id: 'apf-sub-btn',
            label: 'Submit',
            type: 'button'
        });
        var apfCancel = new YAHOO.widget.Button('apf-cancel', {
            id: 'apf-can-btn',
            label: 'Cancel',
            type: 'button'
        });
        apfSubmit.on('click', function(o){
            var ok = false;
            if (apfPanel.useDD) {
                var rid = YAHOO.cms.Util.getSelectValue('apf-reason');
                if (rid == 0)
                    showResultDiag('Missing Input',
                        'Please select outcome of investigation from drop-down list.');
                else
                    ok = true;
            } else {
                var apf = YAHOO.cms.Util.getRadioValue('acceptPassFailDiv', 'rb-apf');
                if (apf != 'pass' && apf != 'fail')
                    showResultDiag('Missing Input',
                        'Please indicate outcome of investigation (pass/fail).');
                else
                    ok = true;
            }
            if (ok)
                opRequest('apf-save');;
        });
        apfCancel.on('click', function(o){
            apfPanel.hide();
        });
    <?php
    }

    if ($atIconAccess) {
        ?>
        spfunc.loadCompanyPage = function()
        {
            tabViewS.selectTab(0);
        };
        <?php
    } ?>

    }; <?php // load case folder ?>

    Event.onDOMReady(function(o) {
<?php

if ($atIconAccess) {
    ?>
    spfunc.showSpAssignCase = function()
    {
        if (!spfunc.spAssignCasePanel.rendered) {
            spfunc.spAssignCasePanel.rendered = true;
            spfunc.spAssignCasePanel.render(document.body);
        }
        spfunc.loadCompanyPage();
        spfunc.spAssignCasePanel.show();
        Dom.replaceClass('spAssignCaseCtrl', 'disp-none', 'disp-block');
        return false;
    };

    spfunc.spAssignCasePanel = new YAHOO.widget.Panel('spAssignCase', {
        modal: true,
        close: true,
        draggable: true,
        fixedcenter: 'contained',
        visible: false
    });
    spfunc.spAssignCasePanel.rendered = false;
    <?php
    if ($atCanAssign) {
        ?>
        var spacSubmit = new YAHOO.widget.Button('spac-assign', {
            id: 'spac-sub-btn',
            label: 'Assign Case',
            type: 'button'
        });

        spacSubmit.on('click', function(o){
            _opRequest('vendor-assignCase');
        });
        <?php
    } ?>
    var spacSubmitTask = new YAHOO.widget.Button('spac-assignTask', {
        id: 'spac-subtsk-btn',
        label: 'Assign Case Task',
        type: 'button'
    });

    spacSubmitTask.on('click', function(o){
        _opRequest('vendor-assignCaseTask');
    });

    <?php
} // end if atIconAccess;

if ($userClass == 'vendor') {
    if ($canIncrement) {
        ?>
        spfunc.showSpCaseIncrement = function()
        {
            if (!spfunc.spIncrementPanel.rendered) {
                spfunc.spIncrementPanel.rendered = true;
                spfunc.spIncrementPanel.render(document.body);
            }
            spfunc.spIncrementPanel.show();
            Dom.replaceClass('spIncrementCaseCtrl', 'disp-none', 'disp-block');
            return false;
        };
        spfunc.spIncrementPanel = new YAHOO.widget.Panel('spIncrementCase', {
            modal: true,
            close: false,
            draggable: true,
            fixedcenter: 'contained',
            visible: false
        });
        spfunc.spIncrementPanel.rendered = false;

        var incrementSubmit = new YAHOO.widget.Button('increment-submit', {
            id: 'increment-submit-button',
            label: 'Increment Case',
            type: 'button'
        });
        var incrementCancel = new YAHOO.widget.Button('increment-cancel', {
            id: 'increment-cancel-button',
            label: 'Cancel',
            type: 'button'
        });
        incrementSubmit.on('click', function(o){
            _opRequest('vendor-incrementcase');
        });
        incrementCancel.on('click', function(o){
            spfunc.spIncrementPanel.hide();
        });
        <?php
    }
    ?>

        spfunc.showSpCaseReject = function()
        {
            if (!spfunc.spRejectPanel.rendered) {
                spfunc.spRejectPanel.rendered = true;
                spfunc.spRejectPanel.render(document.body);
            }
            spfunc.spRejectPanel.show();
            Dom.replaceClass('spRejectCaseCtrl', 'disp-none', 'disp-block');
            Dom.get('sprc-comment').focus();
            return false;
        };

        spfunc.spRejectPanel = new YAHOO.widget.Panel('spRejectCase', {
            modal: true,
            close: false,
            draggable: true,
            fixedcenter: 'contained',
            visible: false
        });
        spfunc.spRejectPanel.rendered = false;

        var sprcSubmit = new YAHOO.widget.Button('sprc-reject', {
            id: 'sprc-sub-btn',
            label: 'Reject Case',
            type: 'button'
        });
        var sprcCancel = new YAHOO.widget.Button('sprc-cancel', {
            id: 'sprc-can-btn',
            label: 'Cancel',
            type: 'button'
        });
        sprcSubmit.on('click', function(o){
            _opRequest('vendor-rejectcase');
        });
        sprcCancel.on('click', function(o){
            spfunc.spRejectPanel.hide();
        });

    spfunc.caseTaskPnl = null;
    spfunc.caseTask = function(titleTxt, bodyTxt) {
        var Dom   = YAHOO.util.Dom;
        var Util  = YAHOO.cms.Util;

        if (spfunc.caseTaskPnl == null) {
            spfunc.caseTaskPnl = new YAHOO.widget.Panel('caseTask-panel',
                {close: false, modal: true, visible: false}
            );
            spfunc.caseTaskPnl.render(document.body);
        }
        spfunc.caseTaskPnl.setHeader(titleTxt);
        spfunc.caseTaskPnl.setBody(bodyTxt);
        var pnl = spfunc.caseTaskPnl;
        pnl.center();
        pnl.show();
    };

    <?php
}

if ($dupCaseRecords) {
    ?>
        spfunc.showSpDupCases = function()
        {
            if (!spfunc.spDupCasesPanel.rendered) {
                spfunc.spDupCasesPanel.rendered = true;
                spfunc.spDupCasesPanel.render(document.body);
            }
            spfunc.spDupCasesPanel.show();
            Dom.replaceClass('spDupCasesCtrl', 'disp-none', 'disp-block');
            return false;
        };

        spfunc.spDupCasesPanel = new YAHOO.widget.Panel('spDupCases', {
            modal: true,
            close: true,
            draggable: true,
            fixedcenter: 'contained',
            visible: false
        });
        spfunc.spDupCasesPanel.rendered = false;
    <?php
}

?>
        creview.ready = true;
        loadCaseFolder();

<?php

if ($userClass == 'vendor' && $triggerGdc && $screeningID) {
    ?>
        YAHOO.gdcifr.showGDC(<?php echo $screeningID; ?>);
    <?php
} ?>
    });

_opRequest = function(op) {
    var handleOperation = function(o) {
        res = false;
        try {
            res = YAHOO.lang.JSON.parse(o.responseText);
        }
        catch (ex) {
<?php

if ($_SESSION['userType'] == SUPER_ADMIN || SECURIMATE_ENV == 'Development') {
    ?>
            alert(o.responseText);
    <?php
} else {
    ?>
            alert('An error occurred while communicating with the server');
    <?php
} ?>
            return;
        }
        if (res.DoNewCSRF == 1) {
            window.loadcasePgAuth = res.loadcasePgAuth;
        }

        var txt = '';
        var dr = Dom.get('divResults');
        switch (o.argument[0]) {
        case 'crev-tgl-status':
            if (res.Result == 1) {
                var key = res.nSect + res.qID + 'stat';
                var imgEl = Dom.get(key);
                var src, tip, ocl, tf;
                var ocl = "opRequest('crev-tgl-status', '" + res.nSect
                    + "', '" + res.qID + "', " + res.NextStatus + ", '" + res.logID + "')";
                if (res.CurStatus == 'needsreview') {
                    src = '/assets/image/needsreview_icon.png';
                    tip = 'Click to mark as Reviewed';
                } else {
                    src = '/assets/image/reviewed_icon.png';
                    tip = 'Click to mark as Needs Review';
                }
                Dom.setAttribute(imgEl, 'src', src);
                Dom.setAttribute(imgEl, 'onclick', ocl);
                Dom.setAttribute(imgEl, 'alt', tip);
                Dom.setAttribute(imgEl, 'title', tip);
            } else if (res.ErrorTitle && res.ErrorMsg) {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            }
            break;
        case 'show-review-panel':
<?php

if ($showReview) {
    ?>
            if (creview.ready) {
                showReviewPanel();
                return false;
            } else {
                alert('Not ready');
            }
            break;
    <?php
}

if ($userClass == 'vendor') {
    if ($atIconAccess) {
        if ($atCanAssign) {
            ?>
            case 'vendor-assignCase':
                if (res.Result == 1) {
                    var li = Dom.get('leadInvestigator');
                    var lm = Dom.get('leadInvestigatorEmail');

                    li.style = 'background-color: #e3f7d0';
                    lm.style = 'background-color: #e3f7d0';

                    li.innerHTML = res.NewName;
                    lm.innerHTML = res.NewEmail;
                    Dom.get('hf_caseInvestigatorUserID').value = res.NewInv;
                    Dom.get('cInv').value = res.NewInv;

                    setTimeout(
                        function() {
                            var li = Dom.get('leadInvestigator');
                            var lm = Dom.get('leadInvestigatorEmail');
                            li.style = '';
                            lm.style = '';
                        },
                        3000
                    );
                } else if (res.ErrorTitle && res.ErrorMsg) {
                    showResultDiag(res.ErrorTitle, res.ErrorMsg);
                } else {
                    showResultDiag('An Error Occurred',
                        'An unknown error occurred while processing your request.');
                }
                break;
                        <?php
        } // end if atCanAssign;
        ?>

        case 'vendor-assignCaseTask':
            if (res.Result == 1) {
                <?php // reset add task inputs back to defaults. ?>
                Dom.get('taskInvestigator').value = '';
                Dom.get('taskList').value = '';
                Dom.get('spac-details').value = '';

                <?php // make sure table is visible ?>
                Dom.get('ctTbl').style.display = 'block';
                var ct = Dom.get('ctTblBody');
                ct.innerHTML += res.html;

                var cr = Dom.get('ctRow'+ res.TaskID);
                cr.style = 'background-color: #e3f7d0';
                cr.innerHTML = res.html;
                setTimeout(
                    function() {
                        Dom.get('ctRow'+ res.TaskID).style = '';
                    },
                    3000
                );
            } else if (res.ErrorTitle && res.ErrorMsg) {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            } else {
                showResultDiag('An Error Occurred',
                    'An unknown error occurred while processing your request.');
            }
            break;
        case 'vendor-updateTask':
            if (res.Result == 1) {
                var cr = Dom.get('ctRow'+ res.TaskID);
                cr.style = 'background-color: #e3f7d0';
                cr.innerHTML = res.html;
                setTimeout(
                    function() {
                        Dom.get('ctRow'+ res.TaskID).style = '';
                    },
                    3000
                );
            } else if (res.ErrorTitle && res.ErrorMsg) {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            } else {
                showResultDiag('An Error Occurred',
                    'An unknown error occurred while processing your request.'
                );
            }
            break;
        case 'vendor-editTask':
<?php
    } // end if atIconAccess;
    ?>
        case 'vendor-acceptTask':
        case 'vendor-viewTask':
            if (res.Result == 1) {
                spfunc.caseTask(res.ctTitle, res.ctBody);
            } else if (res.ErrorTitle && res.ErrorMsg) {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            } else {
                showResultDiag('An Error Occurred',
                    'An unknown error occurred while processing your request.');
            }
            break;
        case 'vendor-updateOSITask':
            if (res.Result == 1) {
                var cr = Dom.get('ctRow'+ res.TaskID);
                if (cr) {
                    cr.style = 'background-color: #e3f7d0';
                    cr.innerHTML = res.html;
                    setTimeout(
                        function() {
                            Dom.get('ctRow'+ res.TaskID).style = '';
                        },
                        3000
                    );
                } else {
                    showResultDiag('An Error Occurred',
                        'Error getting the Case Task Row from the web page.'
                    );
                }
            } else if (res.ErrorTitle && res.ErrorMsg) {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            } else {
                showResultDiag('An Error Occurred',
                    'An unknown error occurred while processing your request.'
                );
            }
            break;
    <?php if ($canIncrement) {
        ?>
        case 'vendor-incrementcase':
            if (res.Result == 1) {
                spfunc.spIncrementPanel.hide();
                window.location = '/cms/case/casehome.sec?'
                    + 'id=' + <?php echo $caseID; ?>
                    + '&tname=casefolder'
                    + '&icli=' + <?php echo $clientID; ?>;
            }
            break;
        <?php
    }
    if ($atManageCase) {
        ?>
        case 'vendor-rejectcase':
            if (res.Result == 1) {
                spfunc.spRejectPanel.hide();
                window.location = 'casehome.sec?tname=caselist';
            } else if (res.ErrorTitle && res.ErrorMsg) {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            }
            break;

        case 'spDueDate':
            var orgSpDate = Dom.get('org_spdate');
            if (res.Result == 1) {
                orgSpDate.value = Dom.get('spdate').innerHTML;
                Dom.get('spDueDateTr').style = 'background-color: #e3f7d0';
                setTimeout(
                    function() {
                        Dom.get('spDueDateTr').style = '';
                    },
                    4000
                );
            } else {
                Dom.get('spdate').innerHTML = orgSpDate.value;
                if (res.ErrorTitle && res.ErrorMsg) {
                    showResultDiag(res.ErrorTitle, res.ErrorMsg);
                } else {
                    showResultDiag('An Error Occurred',
                        'Unable to change the internal due date at this time.'
                    );
                }
            }
            break;
        <?php
    } // end if atManageCase;
} // end if (userClass == vendor);

if ($showInvitePw) {
    ?>
        case 'init-invite-pw':
            if (res.Result == 1) {
                if (!sipwPanel.rendered) {
                    sipwPanel.render(document.body);
                    sipwPanel.rendered = true;
                }
                txt = '<table cellpadding="0" cellspacing="3">'
                    +'<tr><td>For security, enter your password.<td></td></tr>'
                    +'<tr><td><input id="sipw-upw" type="password" autocomplete="off" '
                        +'class="cudat-medium marg-rt0" /></td></tr>'
                    +'<tr><td align="right"><div class="marg-top1e"><button id="sipwbtn" '
                    +'onclick="opRequest(\'show-invite-pw\')" class="btn">Show Credentials</button>'
                    +'</div></td></tr></table>';
                sipwPanel.setBody(txt);
                var sipwSubmit = new YAHOO.widget.Button('sipwbtn', {
                    label: 'Show Credentials',
                    type: 'button'
                });
                sipwSubmit.on('click', function(o){
                    _opRequest('show-invite-pw');
                });
                sipwPanel.show();
                var el = Dom.get("sipw-upw");
                if (el) el.focus();
                YAHOO.util.Dom.replaceClass('showInvitePwCtrl', 'disp-none', 'disp-block')
            }
            break;

        case 'show-invite-pw':
            if (res.Result == 1 && res.hasAdminDDQInstantAccess === true && res.isOspForm === false) {
                sipwPanel.setHeader('VIEW DDQ');
                txt = '<div class="fw-bold no-wrap" style="white-space:pre">Access:  '
                  + '<span class="fw-normal">'
                  + '<a href="javascript:void(0)" onclick="opRequest(\'build-ddq-access-sessions\')">OPEN DDQ</a>'
                  + '</span></div>'
                  +'<div class="no-wrap marg-top1e fw-normal dim">Upon clicking the link, you will be logged out and taken to the DDQ.</div>';
                sipwPanel.setBody(txt);
            } else {
                txt = '<div class="fw-bold no-wrap" style="white-space:pre">URL:  '
                    +'<span class="fw-normal">' + res.ddqLink + '</span></div>'
                    +'<div class="fw-bold no-wrap marg-topsm" style="white-space:pre">Login Email:  '
                    +'<span class="fw-normal">' + res.ddqRec.loginEmail + '</span></div>'
                    +'<div class="fw-bold no-wrap marg-topsm" style="white-space:pre">Password:  '
                    +'<span class="fw-normal">' + res.ddqRec.passWord + '</span></div>';
                sipwPanel.setBody(txt);
            }
            if (typeof caseLogRefresh != 'undefined') {
                caseLogRefresh();
            }
            break;

        /**
         *
         * Sessions are built in loadcase-ws.php
         * @see public_html/cms/case/loadcase-ws.php -> build-ddq-access-sessions
         */
        case 'build-ddq-access-sessions':
            if (res.Result) {
                window.location = '/cms/ddq/ddq2.php';
            } else {
              showResultDiag('An Error Occurred', 'Failed loading DDQ.');
            }
            break;
    <?php
} ?>
        case 'apf-save':
        case 'approve4convert':
            if (res.Result == 1) {
                window.location = '<?php echo $sitepath; ?>case/casehome.sec';
            } else {
                if (res.ErrorTitle && res.ErrorMsg) {
                    showResultDiag(res.ErrorTitle, res.ErrorMsg);
                }
            }
            break;

        case 'race-owner-list':
            if (res.Result == 1) {
                var curowner = YAHOO.cms.Util.getSelectValue('race-owner');
                YAHOO.cms.Util.populateSelect('race-owner',
                    res.Owners, curowner, {v:0, t:'Choose...'});
            } else {
                multiError(res.ErrorTitle, res.ErrorMsg);
            }
            break;

        case 'save-reassign':
            if (res.Result == 1) {
                reassignPanel.hide();
                window.location = '/cms/case/casehome.sec?tname=caselist';
            } else {
                multiError(res.ErrorTitle, res.ErrorMsg);
            }
            break;

        case 'tpcdsave':
        case 'cscdsave':

            cdShowDiv(res.WhichDiv, res.Prefix);
            <?php // What about dislaying errors? ?>
            if (res.Success == 1) {
                var el;
                for (var divID in res.Display) {
                    if (el = Dom.get(divID)) {
                        el.innerHTML = res.Display[divID];
                    }
                }
                adjustForm('d-' + res.Prefix);
                Dom.replaceClass(res.Prefix + 'CtrlCancel', 'v-hidden', 'v-visible');
            } else {
                var errList = 'Error(s):<ul>\n';
                var e;
                for (i in res.Errors) {
                    errList += '<li>' + res.Errors[i] + '</li>\n';
                }
                errList += '</ul>';
                showResultDiag('One or more Errors Occurred', errList);
            }
            break;

        case 'attach-del':
            if (res.Result == 1) {
                dtDocsRefresh();
            } else {
                if(res.Error == 'PgAuthError') {
                    showResultDiag(res.ErrorTitle, res.ErrorMsg);
                } else {
                    showResultDiag('An Error Occurred', 'The document deletion failed.');
                }
            }
            break;

        case 'note-show':
            if (res.Result == 1) {
                Dom.get('note-show').innerHTML = res.FormattedNote;
                Dom.get('note-prev-subj').innerHTML = res.Subject;
                Dom.get('note-prev-time').innerHTML = res.Time;
<?php
if (isset($_SESSION['sim-userClass']) && $_SESSION['sim-userClass'] != 'vendor') {
    ?>
                Dom.get('note-prev-cat').innerHTML = res.Category;
    <?php
} ?>
                Dom.get('note-prev-owner').innerHTML = res.Owner;
                if (canAddNote)
                {
                    Dom.get('note-until-when').innerHTML = res.UntilWhen;
                    var visDelEl = Dom.get('vis-delredo-note-btn');
                    if (res.CanDelete == 1)
                    {
                        Dom.replaceClass(visDelEl, 'v-hidden', 'v-visible');
                        noteRedoID = res.RecID;
                        noteEditSig = res.EditSig;
                    }
                    else
                    {
                        Dom.replaceClass(visDelEl, 'v-visible', 'v-hidden');
                        noteRedoID = 0;
                        noteEditSig = '';
                    }
                }
                var stitle = (res.iCanSee == 1) ? 'Case Note (shared) ' : 'Case Note';
                setNoteView('show', stitle);
            }
            else
                showResultDiag('An Error Occurred', 'The note can not be shown.');
            break;

        case 'note-del':
            <?php //res.Result  1 for success, 0 for failure ?>
            if (res.Result == 1)
            {
                setNoteView('hide');
            }
            else
                showResultDiag('Delete Failed', 'The note can not be deleted.');
            break;

        case 'note-edit':
            <?php
            //res.Result  1 for success, 0 for failure
            //res.Note
            //res.Subject
            //res.CatID
            ?>
            if (res.Result == 1) {
                <?php
                // Use new text-cleaner surrogate to suppress literal character
                // entity display when using data from AJAX request as unser input
                ?>
                var tc = Dom.get('text-cleaner'); <?php // hidden div on parent page ?>
                var tcclose = '</textarea>';
                var tcopen = '<textarea id="clean-text" wrap="soft" cols="20" rows="6">';
                tc.innerHTML = tcopen + res.Subject + tcclose;
                Dom.get('tf_note_subject').value = Dom.get('clean-text').value;
                tc.innerHTML = tcopen + res.Note + tcclose;
                Dom.get('ta_note').value = Dom.get('clean-text').value;
<?php

if ($session->secure_value('userClass') != 'vendor') {
    ?>

                var sel = Dom.get('dd_note_category');
                var i;
                for (i=0; i < sel.length; i++) {
                    if (sel[i].value == res.CatID) {
                        sel.selectedIndex = i;
                        break;
                    }
                }
                <?php // investigator can see ?>
                Dom.get('iseenote').checked = (res.iCanSee == 1);
<?php
} ?>
                setNoteView('hide');
                setNotesForm('show');
            }
            else
                showResultDiag('Setup for Edit Failed', 'The note can not be edited.');
            break;

        case 'save-note':
            <?php //res.Result  1 for success, 0 for failure ?>
            if (res.Result == 1)
            {
                noteRedoID = 0;
                noteEditSig = '';
                dtNotesRefresh();
                clearNotesForm();
                setNotesForm('hide');
            }
            else
                showResultDiag('An Error Occurred', 'Your note was NOT saved.');
            break;

        case 'crev-note-init-show':
            if (res.Result == 1)
            {
                Dom.get('crev-note-show').innerHTML = res.FormattedNote;
                Dom.get('crev-note-prev-subj').innerHTML = res.Subject;
                Dom.get('crev-note-prev-time').innerHTML = res.Time;
<?php

if (isset($_SESSION['sim-userClass']) && $_SESSION['sim-userClass'] != 'vendor') {
    ?>
                Dom.get('crev-note-prev-cat').innerHTML = res.Category;
    <?php
} ?>
                Dom.get('crev-note-prev-owner').innerHTML = res.Owner;
                if (YAHOO.crevtab.canAddNote)
                {
                    Dom.get('crev-note-until-when').innerHTML = res.UntilWhen;
                    var visDelEl = Dom.get('crev-vis-delredo-note-btn');
                    if (res.CanDelete == 1)
                    {
                        Dom.replaceClass(visDelEl, 'v-hidden', 'v-visible');
                        YAHOO.crevtab.noteRedoID = res.RecID;
                        YAHOO.crevtab.noteEditSig = res.EditSig;
                    }
                    else
                    {
                        Dom.replaceClass(visDelEl, 'v-visible', 'v-hidden');
                        YAHOO.crevtab.noteRedoID = 0;
                        YAHOO.crevtab.noteEditSig = '';
                    }
                }
                var stitle = (res.riCanSee == 1) ? 'Case Note (shared) ' : 'Case Note';
                YAHOO.crevtab.setNoteView('show', stitle);
            }
            else
                showResultDiag('An Error Occurred', 'The reviewer note can not be shown.');
            break;

        case 'crev-note-show':
            if (res.Result == 1) {
                Dom.get('crev-note-show').innerHTML = res.FormattedNote;
                Dom.get('crev-note-prev-subj').innerHTML = res.Subject;
                Dom.get('crev-note-prev-time').innerHTML = res.Time;
<?php

if (isset($_SESSION['sim-userClass']) && $_SESSION['sim-userClass'] != 'vendor') {
    ?>
                Dom.get('crev-note-prev-cat').innerHTML = res.Category;
    <?php
} ?>
                Dom.get('crev-note-prev-owner').innerHTML = res.Owner;
                if (YAHOO.crevtab.canAddNote) {
                    Dom.get('crev-note-until-when').innerHTML = res.UntilWhen;
                    var visDelEl = Dom.get('crev-vis-delredo-note-btn');
                    if (res.CanDelete == 1) {
                        Dom.replaceClass(visDelEl, 'v-hidden', 'v-visible');
                        YAHOO.crevtab.noteRedoID = res.RecID;
                        YAHOO.crevtab.noteEditSig = res.EditSig;
                    } else {
                        Dom.replaceClass(visDelEl, 'v-visible', 'v-hidden');
                        YAHOO.crevtab.noteRedoID = 0;
                        YAHOO.crevtab.noteEditSig = '';
                    }
                }
                var stitle = (res.riCanSee == 1) ? 'Case Note (shared) ' : 'Case Note';
                YAHOO.crevtab.setNoteView('show', stitle);
            } else {
                showResultDiag('An Error Occurred', 'The reviewer note can not be shown.');
            }
            break;

        case 'crev-note-del':
            if (res.Result == 1) {
                Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                    + 'a').title = 'Notes (' + res.numNotes + ')';
                Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                    + 'img').alt   = 'Notes (' + res.numNotes + ')';
                if (res.numNotes > 0) {
                    Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                        + 'img').src = '/cms/images/note16x16.png';
                } else {
                    Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                        + 'img').src = '/assets/image/noteBW16x16.png';
                }
                YAHOO.crevtab.setNoteView('hide');
            } else {
                showResultDiag('Delete Failed', 'The reviewer note can not be deleted.');
            }
            break;

        case 'crev-note-edit':
            if (res.Result == 1)
            {
                <?php
                // Use new text-cleaner surrogate to suppress literal character
                // entity display when using data from AJAX request as unser input
                ?>
                var tc = Dom.get('text-cleaner'); <?php // hidden div on parent page ?>
                var tcclose = '</textarea>';
                var tcopen = '<textarea id="clean-text" wrap="soft" cols="20" rows="6">';
                tc.innerHTML = tcopen + res.Subject + tcclose;
                Dom.get('crev_note_subject').value = Dom.get('clean-text').value;
                tc.innerHTML = tcopen + res.Note + tcclose;
                Dom.get('crev_note').value = Dom.get('clean-text').value;
<?php

if ($session->secure_value('userClass') != 'vendor') {
    ?>

                var sel = Dom.get('crev_note_category');
                var i;
                for (i=0; i < sel.length; i++)
                {
                    if (sel[i].value == res.CatID)
                    {
                        sel.selectedIndex = i;
                        break;
                    }
                }
    <?php
} ?>

                YAHOO.crevtab.setNoteForm('show');
            }
            else
                showResultDiag('Setup for Edit Failed', 'The reviewer note can not be edited.');
            break;

        case 'crev-save-note':
            if (res.Result == 1) {
                Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                    + 'a').title = 'Notes (' + res.numNotes + ')';
                Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                    + 'img').alt   = 'Notes (' + res.numNotes + ')';
                if (res.numNotes > 0) {
                    Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                        + 'img').src = '/cms/images/note16x16.png';
                } else {
                    Dom.get(YAHOO.crevtab.section + YAHOO.crevtab.qID
                        + 'img').src = '/assets/image/noteBW16x16.png';
                }
                YAHOO.crevtab.setNoteForm('hide');
            } else {
                showResultDiag(res.ErrorTitle, res.ErrorMsg);
            }
            break;
        }
    }
    if (!window.loadcasePgAuth) {
        window.loadcasePgAuth = '<?php echo PageAuth::genToken('loadcasePgAuth'); ?>';
    }
    var postData = "op=" + op;
    switch (op) {

    case 'crev-tgl-status':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth
            + '&nsect=' + encodeURIComponent(arguments[1])
            + '&qid=' + encodeURIComponent(arguments[2])
            + '&to=' + arguments[3]
            + '&logID=' + arguments[4];
        break;

<?php
if ($userClass == 'vendor') {
    if ($atIconAccess) {
        if ($atCanAssign) {
            ?>
            case 'vendor-assignCase':
                postData += '&loadcasePgAuth=' + window.loadcasePgAuth + '&c='
                    + <?php echo $caseID; ?>
                    + '&cl=' + <?php echo $caseRow['clientID']; ?>
                    + '&i=' + Dom.get('primaryInvestigator').value
                    + '&io=' + Dom.get('hf_caseInvestigatorUserID').value;
                break;
            <?php
        } // end if atCanAssign;
        ?>
        case 'vendor-assignCaseTask':
            postData += '&loadcasePgAuth=' + window.loadcasePgAuth + '&c='
                + <?php echo $caseID; ?>
                + '&cl=' + <?php echo $caseRow['clientID']; ?>
                + '&i=' + Dom.get('taskInvestigator').value + '&t=' + Dom.get('taskList').value
                + '&d=' + encodeURIComponent(Dom.get('spac-details').value)
                + '&tr=' + Dom.get('taskRef').value + '&cinv=' + Dom.get('cInv').value;
            break;
        case 'vendor-updateTask':
            postData += '&loadcasePgAuth=' + window.loadcasePgAuth + '&t=' + Dom.get('ctID').value
                + '&tl=' + Dom.get('taskListID').value + '&tu=' + Dom.get('taskUserID').value
                + '&s=' + Dom.get('statusID').value
                + '&d=' + encodeURIComponent(Dom.get('taskDetails').value)
                + '&tr=' + Dom.get('taskRef').value + '&cinv=' + Dom.get('cInv').value;
            break;
        case 'vendor-editTask':
<?php
    } // end if atIconAccess;
    ?>
    case 'vendor-acceptTask':
    case 'vendor-viewTask':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth
            + '&t=' + arguments[1];
        break;
    case 'vendor-updateOSITask':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth + '&t=' + Dom.get('ctID').value
            + '&s=' + Dom.get('statusID').value + '&tl=' + Dom.get('taskListID').value
            + '&tr=' + Dom.get('taskRef').value + '&tu=' + Dom.get('taskUserID').value
            + '&cinv=' + Dom.get('cInv').value;
        break;
    <?php
    if ($canIncrement) {
        ?>
        case 'vendor-incrementcase':
            postData += '&caseID=' + <?php echo $e_caseID; ?>
                + '&userID=' + <?php echo $e_userID; ?>
                + '&budgetAmount=' + <?php echo $caseRow['budgetAmount']; ?>
                + '&caseTypeID=' + <?php echo $caseRow['caseType']; ?>
                + '&incrementCaseTypeID=' + Dom.get('increment-caseType').value;
            break;
        <?php
    }

    if ($atManageCase) {
        ?>
        case 'vendor-rejectcase':
            postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
            postData += '&c=' + <?php echo $caseID; ?>
                + '&n=' + encodeURIComponent(Dom.get('sprc-comment').value);
            break;

        case 'spDueDate':
            postData +='&pdt='+ Dom.get('pdt_spdate').value
                + '&c='+ <?php echo $caseID; ?>
                + '&cl=' + <?php echo $caseRow['clientID']; ?>;
            break;
        <?php
    } // end if atManageCase;
} // end if userClass == 'vendor';

if ($showInvitePw) {
    ?>
    case 'init-invite-pw':
        break;
    case 'show-invite-pw':
        postData += '&upw=' + encodeURIComponent(Dom.get('sipw-upw').value);
        break;
    <?php
} ?>
    <?php // add extra postData ?>
    case 'apf-save':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        if (apfPanel.useDD)
            postData += '&rid=' + YAHOO.cms.Util.getSelectValue('apf-reason');
        else
            postData += '&apf=' + YAHOO.cms.Util.getRadioValue('acceptPassFailDiv', 'rb-apf');
        postData += '&com=' + encodeURIComponent(Dom.get('apf-comment').value);
        break;
<?php

if ($_SESSION['cflagReassignCase'] && $accCls->allow('reassignCase')) {
    ?>
    case 'save-reassign':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth
            + '&req=' + YAHOO.cms.Util.getSelectValue('race-owner')
            + '&buig=' + Dom.get('buIgnore').value
            + '&poig=' + Dom.get('poIgnore').value
            + '&bu=' + YAHOO.cms.Util.getSelectValue('lb_billingUnit')
            + '&po=' + YAHOO.cms.Util.getSelectValue('lb_billingUnitPO')
            + '&pot=' + Dom.get('tf_billingUnitPO').value;
    <?php // fall thru ?>
    case 'race-owner-list':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        postData += '&reg=' + YAHOO.cms.Util.getSelectValue('race-region');
        postData += '&dpt=' + YAHOO.cms.Util.getSelectValue('race-dept');
        break;
<?php
} ?>
    case 'tpcdsave':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        postData += mkcdPostData(tpcd_id_list);
        break;
    case 'cscdsave':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        postData += mkcdPostData(cscd_id_list);
        break;
    case 'attach-del':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        postData += '&recid=' + arguments[1] + '&tc=' + arguments[2];
        break;
<?php
if ($accCls->allow('accCaseNotes')) {
    ?>
    case 'note-show':
        postData += '&recid=' + arguments[1];
        break;
    <?php
    if ($accCls->allow('addCaseNote')) {
        ?>
        case 'save-note':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        var expd = mkNotePostData();
        if (expd == false) {
            return;
        }
        postData += expd;
        if (noteRedoID) {
            postData += '&recid=' + noteRedoID + '&sig=' + noteEditSig;
        }
        break;
        case 'note-edit':
        if (noteRedoID == 0) {
            return;
        }
        postData += '&recid=' + noteRedoID + '&sig=' + noteEditSig;
        break;
        case 'note-del':
        if (noteRedoID == 0)
            return;
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        postData += '&recid=' + noteRedoID + '&sig=' + noteEditSig;
        break;
        <?php
    }
}

if ($accCls->allow('accCaseNotes')) {
    ?>
    case 'crev-note-show':
        postData += '&recid=' + arguments[1]
            + '&nSect=' + YAHOO.crevtab.section
            + '&qid=' + arguments[2];
        break;
    case 'crev-note-init-show':
        postData += '&recid=' + arguments[1]
            + '&qid=' + arguments[2];
        break;
    <?php
    if ($accCls->allow('addCaseNote')) {
        ?>
        case 'crev-save-note':
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        var expd = YAHOO.crevtab.mkNotePostData();
        if (expd == false)
            return;
        postData += expd;
        if (YAHOO.crevtab.noteRedoID)
            postData += '&recid=' + YAHOO.crevtab.noteRedoID + '&sig='
            + YAHOO.crevtab.noteEditSig;
        break;
        case 'crev-note-edit':
        if (YAHOO.crevtab.noteRedoID == 0)
            return;
        postData += '&recid=' + YAHOO.crevtab.noteRedoID + '&sig=' + YAHOO.crevtab.noteEditSig;
        break;
        case 'crev-note-del':
        if (YAHOO.crevtab.noteRedoID == 0)
            return;
        postData += '&loadcasePgAuth=' + window.loadcasePgAuth;
        postData += '&recid=' + YAHOO.crevtab.noteRedoID + '&sig=' + YAHOO.crevtab.noteEditSig;
        break;
        <?php
    }
} ?>

}

    var sUrl = '<?php echo $sitepath; ?>case/loadcase-ws.php';
    var callback = {
        success: handleOperation,
        failure: function(o){
            <?php
if ($_SESSION['userType'] > CLIENT_ADMIN) {
    ?>
            alert("Failed connecting to " + sUrl);
    <?php
} else {
    ?>
            var a = 1; <?php // do nothing ?>
<?php
} ?>
    
        },
        argument: arguments,
        cache: false
    };
    <?php
    if (!($awsEnabled && $showInvitePw)) {
        ?>
        var request = YAHOO.util.Connect.asyncRequest('POST', sUrl, callback, postData);
    <?php
    }
    ?>
};

    opActive = true; <?php //enable links ?>

})();

YAHOO.util.Event.onDOMReady(resizeTabContainer);

</script>

<?php

if (isset($inviteControl) && $inviteControl['useMenu']) {
    include_once __DIR__ . '/../includes/php/'.'inviteMenu.php';
}

// ############### Local Functions ###############

/**
 * Create an array of users to be placed into a select list. Can return the list values,
 * or echo ready html.
 *
 * @param array   $listAtts   Any desired attributes for the select list (default: false);
 * @param integer $select     ID of the user to be initially selected. (default: empty);
 * @param boolean $nameFormat Format the name as "Last, First" (default) or "First Last".
 * @param boolean $output     True value will return object of results. False returns html
 *                            formatted select list. (default: 0/html);
 *
 * @return html/object/boolean    Returns html or object based on value of $output,
 *                                or false if there are no results.
 */
function fCreateUserDDL($listAtts = false, $select = false, $nameFormat = false, $output = false)
{
    $dbCls = dbClass::getInstance();
    // Set our Where Clause based on Client or Vendor
    $szWhereClause = "";
    $userType = VENDOR_ADMIN;

    if ($_SESSION['userType'] <= VENDOR_ADMIN) {
        $szWhereClause = "WHERE vendorID = ".intval($_SESSION['vendorID']);
    } else {
        $szWhereClause = "WHERE clientID = '".intval($_SESSION['clientID'])."' "
            ."AND userType > '$userType' AND userType <= '".intval($_SESSION['userType'])."'";
    }
    // Get User Names and IDs
    $sql = "SELECT id, lastName, firstName FROM ". GLOBAL_DB .".users $szWhereClause AND "
        ."(status = 'active' OR status = 'pending') ORDER BY lastName ASC, firstName ASC";

    $userList = $dbCls->fetchObjectRows($sql);

    // Check for error
    if (!$userList) {
        return false;
    }

    if (!empty($output)) {
        return $userList;
    }

    if (!is_array($listAtts)) {
        $listAtts = ['id' => 'taskList', 'name' => 'taskList'];
    }

    $html = '';

    // Create the List w/the attrtibutes passed to the function. Use defaults if needed.
    $html .= '<select';
    if (isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'];
    } elseif (!isset($listAtts['name']) && isset($listAtts['id'])) {
        $listAtts['name'] = $listAtts['id'];
    } elseif (!isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'] = 'userSeletion';
    }
    foreach ($listAtts as $att => $val) {
        // space before is important.
        $html .= ' '. $att .'="'. $val .'"';
    }
    $html .= '>'; // closes opening tag.

    if (empty($select)) {
        $html .= '<option value="">Please select a user</option>';
    }

    // Loop through inserting options into drop down
    foreach ($userList as $user) {
        if ($select == $user->id) {
            $selected = ' selected="selected"';
        } else {
            $selected = '';
        }

        if (!$nameFormat) {
            $displayName = $user->lastName .', '. $user->firstName;
        } else {
            $displayName = $user->firstName .' '. $user->lastName;
        }

        $html .= '<option value="'. $user->id .'"'. $selected .'>'. $displayName .'</option>';
    } // end foreach

    // close select list
    $html .= '</select>';

    return $html;
} // end function fCreateUserDDL

/**
 * Fetch task names and (optionally) return a select list.
 *
 * @param array   $listAtts  Any desired attributes for the select list (default: false);
 * @param integer $currentID ID of the currently assigned task name. (default: false);
 * @param boolean $output    Return the db result (true) or build a select list. (default: false);
 *
 * @return object/string $html  Returns db result, or full select list html.
 */
function fCreateTaskList($listAtts = false, $currentID = false, $output = false)
{
    $dbCls = dbClass::getInstance();

    $spID = intval($_SESSION['vendorID']);
    /*
     * listTypeID = 1 for case tasks
     * could also do a join on spListsType, looking for caseTask, and join on the id from that.
    */
    $sql = "SELECT listID, itemName FROM ". GLOBAL_SP_DB .".spLists "
        ."WHERE spID = '$spID' AND listTypeID = '1' AND active = '1' ORDER BY sequence, itemName";
    $taskList = $dbCls->fetchObjectRows($sql);
    if (!$taskList) {
        return false;
    }

    if (!empty($output)) {
        return $taskList;
    }

    if (!is_array($listAtts)) {
        $listAtts = ['id' => 'taskList', 'name' => 'taskList'];
    }

    $html = '';

    // Create the List w/the attrtibutes passed to the function. Use defaults if needed.
    $html .= '<select';
    if (isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'];
    } elseif (!isset($listAtts['name']) && isset($listAtts['id'])) {
        $listAtts['name'] = $listAtts['id'];
    } elseif (!isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'] = 'taskList';
    }
    foreach ($listAtts as $att => $val) {
        // space before is important.
        $html .= ' '. $att .'="'. $val .'"';
    }
    $html .= '>'; // closes opening tag.

    if (!$currentID) {
        $html .= '<option value="">Choose...</option>';
    }

    foreach ($taskList as $tl) {
        if ($tl->listID == $currentID) {
            $selected = ' selected="selected"';
        } else {
            $selected = '';
        }
        $html .= '<option value="'. $tl->listID .'">'. $tl->itemName .'</option>';
    }

    $html .= '</select>';

    return $html;
}
