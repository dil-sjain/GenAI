<?php

/**
 * Process a submitted DDQ (Qualification stage)
 * phpcs checked (passed) 12/11/14 - Luke
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if ($_SESSION['userSecLevel'] <= READ_ONLY
    || !$accCls->allow('convertCase')
    || $_SESSION['userType'] <= VENDOR_ADMIN
) {
    exit('Access denied');
}

require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'class_db.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';

if ($_SESSION['b3pAccess']) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}

$preselectAllPrin = false;
$clientID = $_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);
$languageCode = (isset($_SESSION['languageCode']) ? $_SESSION['languageCode'] : 'EN_US');
require_once __DIR__ . '/../includes/php/'.'class_cases.php';
$casesCls = new Cases($clientID, $languageCode);
unset($_SESSION['addPrincipalsSource']);

// clear these on init only
if (isset($_GET['xsv']) && $_GET['xsv'] == 1) {
    unset($_SESSION['linked3pProfileRow']);
    unset($_SESSION['rcPOST']);
    unset($_SESSION['rcMustReload']);
    unset($_SESSION['prinHold']);
    // peselect all principals for these subscribers
    $preselectAllPrin = in_array($clientID, [
        MEDTRONIC_CLIENTID,
        MEDTRONICQC_CLIENTID,
        157,
        // NGC
        138,
    ]
    );
}

if (isset($_GET['id'])) {
    $_GET['id']  = htmlspecialchars($_GET['id'], ENT_QUOTES, 'UTF-8');
    $_GET['id'] = intval($_GET['id']);
    $caseID = (int)$_GET['id'];
} elseif (isset($_SESSION['currentCaseID'])) {
    $_GET['id'] = $_SESSION['currentCaseID'];
    $caseID = intval($_SESSION['currentCaseID']);
} else {
    exit('Invalid access');
}
$ddqRow = false;

// Sticky bilingual report checlbpx
$bilingualRptChecked = false;
$bilingualRptID = false;
// Is there an existing bilingual report request?
$sql = "SELECT id FROM " . GLOBAL_SP_DB . ".spAdditionalReportLanguage\n"
    . "WHERE clientID = '$clientID' AND caseID = '$caseID'";
$bilingualRptID = $dbCls->fetchValue($sql);
if ((!empty($_SESSION['rvcBilingualRpt']) && $_SESSION['rvcBilingualRpt'] === $caseID)
    || !empty($billingualRptID)
) {
    $bilingualRptChecked = true;
} else {
    unset($_SESSION['rvcBilingualRpt']);
}
if (isset($_SESSION['ddqRow'])) {
    $ddqRow = $_SESSION['ddqRow'];
}
$caseRow = fGetCaseRow($caseID);
$subInfoRow   = fGetSubInfoDDRow($caseID);
if ($caseRow['caseStage'] != AI_REPORT_GENERATED && (!$caseRow || !$subInfoRow || !$ddqRow
    || $_SESSION['userSecLevel'] <= READ_ONLY
    || $_SESSION['userType'] <= VENDOR_ADMIN )
) {
    if (0 || SECURIMATE_ENV == 'Development') {
        debugTrack([
            'caseRow' => $caseRow,
            'subInfoRow' => $subInfoRow,
            'ddqRow' => $ddqRow,
            'userSecLevel' => $_SESSION['userSecLevel'],
            'userType' => $_SESSION['userType'],
        ]);
        exit('Access denied');
    } else {
        exit;
    }
}

// init more vars
$ddqID    = $ddqRow['id'];
$tpID     = intval($caseRow['tpID']);
$tpRow    = null;
$recScope = 0;
$recScopeName = '';
require_once __DIR__ . '/../includes/php/'.'class_BillingUnit.php';
$buCls = new BillingUnit($clientID);
$billingUnit = $caseRow['billingUnit'];
$billingUnitPO = $caseRow['billingUnitPO'];
$aiDDBillingUnit   = $buCls->getBillingUnitByName('Investigation scope undefined', 1);
$aiBillingUnit    = (empty($aiDDBillingUnit)) ? 0 : $aiDDBillingUnit[0]['id'];
if ($_SESSION['b3pAccess']) {
    if (isset($_SESSION['linked3pProfileRow']) && $accCls->allow('assoc3p')) {
        $tpRow = $_SESSION['linked3pProfileRow'];
        $tpID = $tpRow->id;
        unset($_SESSION['linkded3pProfileRow']);
        $_SESSION['rcMustReload'] = true;
    } elseif ($tpID) {
        $tpRow = fGetThirdPartyProfileRow($tpID);
    }
    if ($tpRow && $_SESSION['b3pRisk']) {
        if ($recScope = $tpRow->invScope) {
            $recScopeName = $_SESSION['caseTypeClientList'][$recScope];
            if (!$accCls->allow('caseTypeSelection')) {
                $_POST['lb_caseType'] = $recScope;
                $caseRow[3] = $recScope;
                $caseRow['caseType'] = $recScope;
            }
        }
    }
}
// Define vars for principals
$prinVars = array();
$prinLimit = 10;
for ($i = 1; $i <= $prinLimit; $i++) {
    $prinVars[] = 'principal'     . $i;
    $prinVars[] = 'pRelationship' . $i;
    $prinVars[] = 'bp' . $i . 'Owner';
    $prinVars[] = 'p'  . $i . 'OwnPercent';
    $prinVars[] = 'bp' . $i . 'KeyMgr';
    $prinVars[] = 'bp' . $i . 'BoardMem';
    $prinVars[] = 'bp' . $i . 'KeyConsult';
    $prinVars[] = 'bp' . $i . 'Unknown';
}

if (isset($_POST['Submit']) && $_POST['Submit'] != 'reload') {

    if (!PageAuth::validToken('revconfAuth', $_POST['revconfAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "reviewConfirm.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }

    include __DIR__ . '/../includes/php/'.'dbcon.php';

    switch ($_POST['Submit']) {
        case 'Add Principals':
            $_SESSION['rcHOLD'] = $_POST; // save to sess vars
            $_SESSION['addPrincipalsSource'] = 'reviewConfirm';
            session_write_close();
            header("Location: addprincipals.php?id=" . $caseID);
            exit;
    case 'Submit to Investigator':
        $passID = $caseID;
        $_SESSION['originSource'] = 'reviewConfirm';
        $nextPage = isset($caseRow['caseSource']) && $caseRow['caseSource'] == DUE_DILIGENCE_CS_AI_DD ? "editsubinfodd_pt2.php" : "reviewConfirm_pt2.php";
        break;
    }  //end switch
    // create normal vars from POST
    $lb_region           = intval($_POST['lb_region']);
    $lb_dept             = intval($_POST['lb_dept']);
    $lb_caseType         = intval($_POST['lb_caseType']);
    $billingUnit         = (int)$_POST['lb_billingUnit'];
    $billingUnitPOselect = (int)$_POST['lb_billingUnitPO'];
    $billingUnitPOtext   = htmlspecialchars($_POST['tf_billingUnitPO'], ENT_QUOTES, 'UTF-8');
    $billingUnitPOtext   = (int)$_POST['tf_billingUnitPO'];
    $billingUnitPO       = 0;

    // Bilingual report requested?
    if (!empty($_POST['bilingualRpt'])) {
      $bilingualRptChecked = true;
      $_SESSION['rvcBilingualRpt'] = $caseID;
    } else {
      $bilingualRptChecked = false;
      unset($_SESSION['rvcBilingualRpt']);
    }

    if (!empty($billingUnit)) {
        $billingUnitPO = ($buCls->isTextReqd($billingUnit))
            ? $billingUnitPOtext
            : $billingUnitPOselect;
    }
    $_POST['ta_caseDescription'] = htmlspecialchars($_POST['ta_caseDescription'], ENT_QUOTES, 'UTF-8');
    $ta_caseDescription = normalizeLF(trim((string) $_POST['ta_caseDescription']));
    $rb_SBIonPrincipals = '';
    if (isset($_POST['rb_SBIonPrincipals'])) {
        $rb_SBIonPrincipals = $_POST['rb_SBIonPrincipals'];
    }
    $selectedPrincipalIDs = [];
    if (isset($_POST['lbm_principals'])) {
        $selectedPrincipalIDs = $_POST['lbm_principals'];
        if (!is_array($selectedPrincipalIDs)) {
            $selectedPrincipalIDs = [];
        }
    }
    if ($hideSBIchoice = intval($_POST['hideSBIchoice'])) {
        $rb_SBIonPrincipals = ''; // clear it - may have been set previously
    }

    // Error Check
    $iError = 0;

    // Check for a Type
    if ($lb_caseType == 0) {
        $_SESSION['aszErrorList'][$iError++] = "You must Choose a Case Type.";
    } elseif ($_SESSION['b3pAccess']
        && $recScope > 0
        && $recScope != $lb_caseType
        && trim($ta_caseDescription) == ''
    ) {
        $_SESSION['aszErrorList'][$iError++] = "Missing explanation for why type of "
            . "investigation differs from recommendation.";
    }

    // If 3P is turned on, make sure there is an association
    if ($_SESSION['b3pAccess'] && !$tpID) {
        $_SESSION['aszErrorList'][$iError++] = "You must link a Third Party Profile "
            . "to this case"
            . ((!$accCls->allow('assoc3p'))
                ? ',<br />but your assigned role does not grant that permission.'
                : '.');
    }
    // If SBI (EDD-based), make sure we have the SBI (EDD) on Principals question checked
    if (count($selectedPrincipalIDs) && $hideSBIchoice == 0) {
        if ($rb_SBIonPrincipals != 'Yes' && $rb_SBIonPrincipals != 'No') {
            $_SESSION['aszErrorList'][$iError++] = "You must tell us if you want the Field "
                . "Investigation performed on the Principals.";
        }
    }
    // Limit principal selection to available fields
    if (count($selectedPrincipalIDs) > SUBINFO_PRINCIPAL_LIMIT) {
        $_SESSION['aszErrorList'][$iError++] = 'You can select no more than '
            . SUBINFO_PRINCIPAL_LIMIT . ' Principals to investigate.';
    }

    // Billing Unit / PO validation
    $buCls->validatePostedValues($billingUnit, $billingUnitPO, $iError);

    // See if we had any errors
    if ($iError) {
        // save inputs
        $_SESSION['rcHOLD'] = ['lb_caseType'          => $lb_caseType, 'lb_region'            => $lb_region, 'lb_dept'              => $lb_dept, 'billingUnit'          => $billingUnit, 'billingUnitPO'        => $billingUnitPO, 'ta_caseDescription'   => $ta_caseDescription, 'rb_SBIonPrincipals'   => $rb_SBIonPrincipals, 'selectedPrincipalIDs' => $selectedPrincipalIDs];
        session_write_close();
        $errorUrl = "reviewConfirm.php?id=$caseID";
        header("Location: $errorUrl");
        exit;
    }

    // Insert or remove bilingual report record, as needed
    if ($bilingualRptChecked) {
        if (empty($bilingualRptID)) {
            $rptSql = "INSERT IGNORE INTO " . GLOBAL_SP_DB . ".spAdditionalReportLanguage SET\n"
                . "id = NULL, clientID = '$clientID', caseID = '$caseID', langCode = 'ZH_CN'";
            $dbCls->query($rptSql);
        }
    } else {
        if (!empty($bilingualRptID)) {
            $rptSql = "DELETE FROM " . GLOBAL_SP_DB . ".spAdditionalReportLanguage\n"
                . "WHERE id = '$bilingualRptID' AND clientID = '$clientID'\n"
                . "AND caseID = '$caseID' AND langCode = 'ZH_CN'";
            $dbCls->query($rptSql);
        }
    }

    //$e_ trusted $clientID, $caseID
    $e_ta_caseDescription = mysqli_real_escape_string(MmDb::getLink(), $ta_caseDescription); //text from post
    $e_rb_SBIonPrincipals = $rb_SBIonPrincipals; //text from post, db varchar

    // If this is a QUALIFICATION stage record, lets fill in the missing data
    // and do the cost calculations that were done previously
    // for caases that were added by the Create Case process
    $caseType  = $lb_caseType;
    $caseSource = $caseRow['caseSource'];
    if ($caseStage == QUALIFICATION || $caseStage == UNASSIGNED || $caseSource == DUE_DILIGENCE_CS_AI_DD) {
        $ddqCaseType = 0;
        $ddqID       = 0;
        if ($ddqRow) {
            $ddqCaseType = $ddqRow['caseType'];
            $ddqID = $ddqRow['id'];
        }

        if($caseSource != DUE_DILIGENCE_CS_AI_DD){
        // copy selected key persons to subinfo principals
        $idx = 1;
        if (count($selectedPrincipalIDs)) {
            foreach ($selectedPrincipalIDs as $ddqKeyPersonID) {

                $e_ddqKeyPersonID = intval($ddqKeyPersonID); //db int
                // $e_ trusted $ddqID, db int

                $sql = "SELECT kpName, kpPosition, bkpOwner, kpOwnPercent, bkpKeyMgr, "
                    . "bkpBoardMem, bkpKeyConsult "
                    . "FROM ddqKeyPerson WHERE id='$e_ddqKeyPersonID' "
                    . "AND TRIM(kpName) <> '' "
                    . "AND ddqID = '$ddqID' AND clientID = '$clientID' LIMIT 1";
                if ($kp = $dbCls->fetchObjectRow($sql)) {
                    // Save principal to subjectInfoDD
                    $sql = "UPDATE subjectInfoDD SET "
                        . "principal{$idx}     = '$kp->kpName', "
                        . "pRelationship{$idx} = '$kp->kpPosition', "
                        . "p{$idx}OwnPercent   = '$kp->kpOwnPercent', "
                        . "bp{$idx}Owner       = '$kp->bkpOwner', "
                        . "bp{$idx}KeyMgr      = '$kp->bkpKeyMgr', "
                        . "bp{$idx}BoardMem    = '$kp->bkpBoardMem', "
                        . "bp{$idx}KeyConsult  = '$kp->bkpKeyConsult', "
                        . "bp{$idx}Unknown     = 0 "
                        . "WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1";
                    $dbCls->query($sql);
                    $idx++;
                    // no more prin fields in subjectInfoDD?
                    if ($idx > SUBINFO_PRINCIPAL_LIMIT) {
                        break;
                    }
                }
            }
        }
        /*
         * the $idx being carried over from the previous loop is intentional,
         * as an initialization for this next loop in blanking out any
         * unfilled principal fields
         */
        for ($i = $idx; $i <= SUBINFO_PRINCIPAL_LIMIT; $i++) {
            $sql = "UPDATE subjectInfoDD SET "
                . "principal{$i}     = '', "
                . "pRelationship{$i} = '', "
                . "p{$i}OwnPercent   = 0, "
                . "bp{$i}Owner       = 0, "
                . "bp{$i}KeyMgr      = 0, "
                . "bp{$i}BoardMem    = 0, "
                . "bp{$i}KeyConsult  = 0, "
                . "bp{$i}Unknown     = 0 "
                . "WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1";
            $dbCls->query($sql);
        }
        $principals = locGetPrincipals($caseID);

        // Update SBIonPrincipals
        $dbCls->query("UPDATE subjectInfoDD SET SBIonPrincipals = '$e_rb_SBIonPrincipals' "
            . "WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1"
        );
    }
    if($caseSource == DUE_DILIGENCE_CS_AI_DD){
        // Retrieve principals data from sess vars set in addprincipals
    $prinVals = array();
    $sessPrinVars = array();
    if (isset($_SESSION['prinHold'])) {
        $sessPrinVars = $_SESSION['prinHold'];
    }
    foreach ($prinVars as $varName) {
        $isInt = preg_match('/^bp\d+/', $varName);
        $value = ($isInt) ? 0: '';
        if (isset($sessPrinVars[$varName])) {
            if ($isInt) {
                $value = intval($sessPrinVars[$varName]);
            } else {
                $value = trim($sessPrinVars[$varName]);
            }
        }
        $prinVals[] = $value;
    }
    $prinFldList = join(', ', $prinVars);
    $prinValList = "'" . join("', '", $prinVals) . "'";
    // Construct the SET part of the UPDATE statement
    $updateFields = [];
    foreach ($prinVars as $index => $field) {
        $updateFields[] = "$field = '{$prinVals[$index]}'";
    }
    $updateFieldsList = join(', ', $updateFields);

        try {
            $sql = 'UPDATE subjectInfoDD SET '
                . $updateFieldsList
                . ' WHERE caseID = ?'; // Assuming caseID is the unique identifier
        
            $stmt = mysqli_prepare(MmDb::getLink(), $sql);
            mysqli_stmt_bind_param($stmt, 's', $caseID); // Bind the caseID parameter
            $result = mysqli_stmt_execute($stmt);
        } catch (Exception $e) {
            debugTrack([
                'Update SQL' => $sql,
                'Failed to update subject info DD' => $e->getMessage(),
            ]);
        }
    }

        if (trim($caseRow['creatorUID'])) {
            $szCreatorUID = $caseRow['creatorUID'];
        } else {
            $szCreatorUID = $_SESSION['userid'];
        }

        $e_sess_userid = mysqli_real_escape_string(MmDb::getLink(), (string) $_SESSION['userid']);
        $e_lb_caseType = intval($lb_caseType); //db int
        $e_lb_dept = intval($lb_dept); //db int
        $e_lb_region = intval($lb_region); //db int
        // billingUnit vars are already integers
        

        // Update the Case record w/the investigation type and other info
        $sql = "UPDATE cases SET "
            . "requestor = '$e_sess_userid', "
            . "caseDescription = '$e_ta_caseDescription', "
            . "creatorUID = '$e_sess_userid', "
            . "caseType = '$e_lb_caseType', "
            . "dept     = '$e_lb_dept', "
            . "billingUnit   = '$billingUnit', "
            . "billingUnitPO = '$billingUnitPO', "
            . "region   = '$e_lb_region' "
            . "WHERE `id`='$caseID' AND clientID = '$clientID' LIMIT 1";
        $result = mysqli_query(MmDb::getLink(), $sql);
        if (!$result) {
            exitApp(__FILE__, __LINE__, $sql);
        }

        $globalIdx->syncByCaseData($caseID);

        // record riskAssessment from which recommended scope comes
        if ($tpRow) {
            if ($recScope > 0 && $tpRow->riskModel && $tpRow->risktime) {
                $rmID = $tpRow->riskModel;
                $raTstamp = "'" . $tpRow->risktime . "'";
            } else {
                $rmID = '0';
                $raTstamp = 'NULL';
            }

            $e_rmID = intval($rmID); //int
            $e_raTstamp = $raTstamp; //date from db

            $sql = "UPDATE cases SET rmID = $e_rmID, raTstamp = $e_raTstamp "
                . "WHERE id='$caseID' LIMIT 1";
            $dbCls->query($sql);
        }

    } // end QUALIFICATION stage processing

    unset($_SESSION['rcHOLD']); // no longerneeded

    // Redirect user to
    header("Location: $nextPage?id=$passID");
    exit;

}


//---- Show Form -----//

// Restore previous input values
$preSelectPrincipals = [];
$principals = locGetPrincipals($caseID);
$keyPersons = $dbCls->fetchKeyValueRows("SELECT id, kpName FROM ddqKeyPerson "
    . "WHERE clientID = '$clientID' AND ddqID = '$ddqID' "
    . "AND TRIM(kpName) <> ''"
);

if (isset($_SESSION['rcHOLD'])) {
    // refresh data from sess vars
    foreach ($_SESSION['rcHOLD'] AS $k => $v) {
        ${$k} = $v;
    }
    if($caseRow['caseSource'] == DUE_DILIGENCE_CS_AI_DD){
        if (!empty($lb_billingUnit) && $lb_billingUnit != '0') {
            $billingUnit = $lb_billingUnit;
            $billingUnitPO = ($buCls->isTextReqd($lb_billingUnit))
                ? $tf_billingUnitPO
                : $lb_billingUnitPO;
        }
    }
    $preSelectPrincipals = array();
    foreach ($selectedPrincipalIDs AS $kpid) {
        $e_kpid = $kpid;
        $preSelectPrincipals[] = $dbCls->fetchValue("SELECT kpName "
            . "FROM ddqKeyPerson WHERE id = '$e_kpid' AND ddqID = '$ddqID' "
            . "AND clientID = '$clientID' LIMIT 1"
        );
    }
    unset($_SESSION['rcHOLD']);
} else {
    // get data from database
    $lb_caseType         = $caseRow['caseType'];
    $lb_region           = $caseRow['region'];
    $lb_dept             = $caseRow['dept'];
    $billingUnit         = $caseRow['billingUnit'];
    $billingUnitPO       = $caseRow['billingUnitPO'];
    $ta_caseDescription  = $caseRow['caseDescription'];
    $rb_SBIonPrincipals  = $subInfoRow['SBIonPrincipals'];
    $preSelectPrincipals = $principals;
}
$prinLimit = 10;
$caseStage = $caseRow['caseStage'];
// Check if Converting a Case that has been previously Rejected is in CASE_CANCELED stage
if ($caseStage == CASE_CANCELED || $caseStage == CLOSED_HELD || $caseStage == CLOSED_INTERNAL) {
    // We need to update the Case Stage to Qualification and take the
    // pending status off of the DDQ record
    include __DIR__ . '/../includes/php/'."dbcon.php";

    $caseStageQualification = QUALIFICATION;

    $e_caseStageQualification = $caseStageQualification;

    $sql = "UPDATE cases SET caseStage='$e_caseStageQualification' "
        . "WHERE id = '$caseID' AND clientID = '$clientID' LIMIT 1";
    $result = mysqli_query(MmDb::getLink(), $sql);
    if (!$result) {
        exitApp(__FILE__, __LINE__, $sql);
    }

    $globalIdx->syncByCaseData($caseID);

    $caseStage = $caseStageQualification;

    $sql = "UPDATE ddq SET returnStatus='' "
        . "WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1";
    $result = mysqli_query(MmDb::getLink(), $sql);
    if (!$result) {
        exitApp(__FILE__, __LINE__, $sql);
    }
}

// If Case Type and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseTypeList'])) {
    $_SESSION['caseTypeList'] = fLoadCaseTypeList();
}

$regionName   = fGetRegionName($lb_region);
$caseDueDate  = $caseRow['caseDueDate'];
$budgetAmount = $caseRow['budgetAmount'];

// Get Investigation firm data
$investigatorName = '';
if ($investigatorRow = fGetInvestigatorProfileRow($caseRow['caseAssignedAgent'])) {
    $investigatorName = $investigatorRow[0];
}

// Get the Subject Info Row
$subType = '';
if ($relRow = fGetRelationshipTypeRow($subInfoRow['subType'])) {
  $subType = $relRow[2];
}
$flds = ['subStat', 'name', 'street', 'city', 'state', 'country', 'postCode',
    'pointOfContact', 'phone', 'emailAddr'];
foreach ($flds as $fld) {
    ${$fld} = $subInfoRow[$fld];
}

$stateName = getStateName($state);
$stateName = (!empty($stateName)) ? $stateName : 'None';

$countryRow = fGetCountryRow($country);
$countryName = $countryRow[1];
// Make normal vars for principals and pRelationships
$sessPrinVars = array();
if (isset($_SESSION['prinHold'])) {
    $sessPrinVars = $_SESSION['prinHold'];
}
$insertInHead =<<<EOT
<style type="text/css">
.risktier {
    font-weight: normal;
    width: 110px;
    text-align:center;
    white-space: nowrap;
    padding:2px;
    *padding-top:1px;
}
</style>

EOT;

$loadYUImodules = ['cmsutil', 'linkedselects'];
$pageTitle = "Create New Case Folder";
shell_head_Popup();
$revconfAuth = PageAuth::genToken('revconfAuth');
?>

<div>
<!--Start Center Content-->
<script type="text/javascript">

<?php
if ($caseStage == AI_REPORT_GENERATED && ($billingUnit == '0' || $billingUnit == $aiBillingUnit)) {
    $billingUnit = 0;
}
echo $buCls->jsHandler($billingUnit, $billingUnitPO);
?>

function submittheform ( selectedtype )
{
    document.addsubinfoddform.Submit.value = selectedtype ;
    document.addsubinfoddform.submit() ;
    return false;
}

function explainText(theMessage)
{
    return;
}

function explainCaseType(theMessage)
{
    var eddBasedProducts = [<?php locCaseTypesForOsiOnPrincipals($clientID); ?>];
    var offerOsiOnPrincipals = eddBasedProducts.includes(theMessage);
    var msg = '';
    theMessage = '' + theMessage;
    var showBilingual = theMessage == '<?php echo DUE_DILIGENCE_OSRC; ?>';
    if (offerOsiOnPrincipals) {
      msg = "The scope includes field investigation and online research "
        + "of select public information.";
    } else {
      switch (theMessage) {
        case '<?php echo DUE_DILIGENCE_HCPDI; ?>':
        case '<?php echo DUE_DILIGENCE_IBI; ?>':
        case '<?php echo DUE_DILIGENCE_OSISIP; ?>':
        case '<?php echo DUE_DILIGENCE_OSIAIC; ?>':
        case '<?php echo DUE_DILIGENCE_OSISV; ?>':
        case '<?php echo DUE_DILIGENCE_OSIRC; ?>':
        case '<?php echo DUE_DILIGENCE_OSIPDF; ?>':
        case '<?php echo DUE_DILIGENCE_HCPVR; ?>':
        case '<?php echo DUE_DILIGENCE_SDD; ?>':
        case '<?php echo DUE_DILIGENCE_SDD_CA; ?>':
          msg = "The scope includes online research of select public information only.  "
            + "No field investigation is conducted.";
          break;
        case '<?php echo DUE_DILIGENCE_HCPEI; ?>':
        case '<?php echo DUE_DILIGENCE_SBI; ?>':
        case '<?php echo DUE_DILIGENCE_EDDPDF; ?>':
        case '<?php echo DUE_DILIGENCE_ESDD; ?>':
        case '<?php echo DUE_DILIGENCE_ESDD_CA; ?>':
          msg = "The scope includes field investigation and online research "
            + "of select public information.";
          break;
        case '<?php echo DUE_DILIGENCE_HCPAI; ?>':
        case '<?php echo DUE_DILIGENCE_ABI; ?>':
        case '<?php echo SPECIAL_PROJECT; ?>':
          msg = "Define your requested scope in the <b>Brief Note</b> below. "
            + "A budget and time line will be provided for your approval.";
          break;
        default:
          msg = "Special scope of case.";
          break;
      }
    }
    YAHOO.util.Dom.get('messageDiv').innerHTML = msg;
    if (showBilingual) {
      YAHOO.util.Dom.removeClass('bilingual-rpt-row', 'disp-none');
    } else {
      YAHOO.util.Dom.addClass('bilingual-rpt-row', 'disp-none');
    }

    if (offerOsiOnPrincipals) {
        YAHOO.util.Dom.removeClass('prin-ask', 'disp-none');
    } else {
        YAHOO.util.Dom.addClass('prin-ask', 'disp-none');
    }
    YAHOO.util.Dom.get('hideSBIchoice').value = offerOsiOnPrincipals ? "0" : "1";
}

function relay3pAssoc(caseID)
{
    if (!parent.YAHOO.add3p.panelReady) return false;
    var frm = document.addsubinfoddform;
    parent.YAHOO.add3p.callerFormVals = {
        'fields': ['region', 'dept'],
        'region': frm.lb_region[frm.lb_region.selectedIndex].value,
        'dept': frm.lb_dept[frm.lb_dept.selectedIndex].value
    };
    return parent.assoc3pProfile('rc', caseID);
}
</script>

<h6 class="formTitle">Review | Confirm</h6>
<form name="addsubinfoddform" class="cmsform" action="<?php
    echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="revconfAuth" value="<?php echo $revconfAuth; ?>" />

<div class="formStepsPic3"><img src="/cms/images/formSteps2.gif" width="231" height="30" /></div>
<div class="stepFormHolder">
<p class="style11">Review the information below for accuracy before proceeding.</p>

<table border="0" cellpadding="0" cellspacing="0">
<tr>
    <td valign="top">
<?php // left column ?>

<table border="0" cellpadding="0" cellspacing="0">
<tr>
    <td><div class="no-wrap marg-rtsm">Relationship Status:</div></td>
    <td class="no-wrap"><?php echo $subStat; ?></td>
</tr>
<tr>
    <td>Relationship Type: </td>
    <td class="no-wrap"><?php echo $subType; ?></td>
</tr>
<tr>
    <td><div class="no-wrap marg-rtsm"><?php echo $_SESSION['regionTitle']; ?>:</div></td>
    <td>
<?php
switch ($_SESSION['userType']) {
case SUPER_ADMIN:
case CLIENT_ADMIN:
    fCreateDDLfromDB("lb_region", "region", $lb_region, 0, 1);
    break;

case CLIENT_MANAGER:
    echo '<select id="lb_region" name="lb_region" data-no-trsl-cl-labels-sel="1">' ;
    fFillUserLimitedRegion($_SESSION['mgrRegions'], $lb_region);
    echo '</select>';
    break;

case CLIENT_USER:
    echo '<select id="lb_region" name="lb_region" data-no-trsl-cl-labels-sel="1">';
    fFillUserLimitedRegion($_SESSION['userRegion'], $lb_region);
    echo '</select>';
    break;

default:
    exit('<br/>ERROR - userType NOT Recognized<br/>');
}
?>
    </td>
</tr>
<tr>
    <td><div class="no-wrap marg-rtsm"><?php echo $_SESSION['departmentTitle']?>:</div></td>
    <td>
<?php
switch ($_SESSION['userType']) {
case SUPER_ADMIN:
case CLIENT_ADMIN:
    fCreateDDLfromDB("lb_dept", "department", $lb_dept, 0, 1, 'class="cudat-medium"');
    break;

case CLIENT_MANAGER:
    echo '<select class="cudat-medium" id="lb_dept" name="lb_dept">';
        fFillUserLimitedDepartment($_SESSION['mgrDepartments'], $lb_dept);
    echo '</select>';
    break;

case CLIENT_USER:
    echo '<select class="cudat-medium" id="lb_dept" name="lb_dept">';
    fFillUserLimitedDepartment($_SESSION['userDept'], $lb_dept);
    echo '</select>';
    break;

default:
    exit('<br/>ERROR - userType NOT Recognized<br/>');
} ?>
    </td>
</tr>

<?php
echo $buCls->selectTagsTR(true);
?>

<tr>
    <td colspan="2"><div class="marg-topsm"><strong>SUBJECT DETAIL</strong></div></td>
</tr>
<tr>
    <td><div class="indent"><?php echo ($ddqRow['caseType'] == DUE_DILIGENCE_HCPDI
                                        || $_SESSION['ddqRow'][107] == DUE_DILIGENCE_HCPDI_RENEWAL)
        ? "Full Name"
        : "Company"; ?>:</div></td>
    <td><?php echo $name; ?></td>
</tr>
<tr>
    <td><div class="indent">Street:</div></td>
    <td><?php echo $street; ?></td>
</tr>
<tr>
    <td><div class="indent">City:</div></td>
    <td><?php echo $city; ?></td>
</tr>
<tr>
    <td><div class="indent no-wrap marg-rtsm">State/Province:</div></td>
    <td><?php echo $stateName; ?></td>
</tr>
<tr>
    <td><div class="indent">Country:</div></td>
    <td><?php echo $countryName; ?></td>
</tr>
<tr>
    <td><div class="indent">Postcode:</div></td>
    <td><?php echo $postCode; ?></td>
</tr>
</table>

<?php // end left column ?>
    </td>
    <td valign="top">
<?php // right column ?>

<div style="margin-left:30px;">
<?php if($caseRow['caseSource'] == DUE_DILIGENCE_CS_AI_DD){ ?>
    <table width="439" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td colspan="2"><div align="right" class="style3 paddiv"><b><span
            class="style1">Principals</span></b></div></td>
        <td width="55">&nbsp;</td>
        <td width="79">&nbsp;</td>
        <td width="82"><a href="javascript:void(0)"
            onclick="return submittheform('Add Principals')" class="btn btn-light">
            <strong>Add | Edit</strong></a></td>
    </tr>
    <tr>
        <td colspan="5"><hr width="100%" size="1" noshade="noshade" /></td>
    </tr>
<?php
// display first 4 and any non-empty principals
for ($i = 1; $i <= $prinLimit; $i++) {
    $prinKey = 'principal' . $i;
    $prinVal = '';
    if (array_key_exists($prinKey, $sessPrinVars)) {
        $prinVal = trim($sessPrinVars[$prinKey]);
    }
    if ($i > 4 && !$prinVal) {
        continue; // skip empty principals after 4
    }
    $prelKey = 'pRelationship' . $i;
    $prelVal = '';
    if (array_key_exists($prelKey, $sessPrinVars)) {
        $prelVal = trim($sessPrinVars[$prelKey]);
    }
    echo <<<EOT
    <tr>
        <td width="71" nowrap="nowrap"><div align="right" class="paddiv">Principal $i: </div></td>
        <td width="158">$prinVal &nbsp;</td>
        <td colspan="3" nowrap="nowrap"><strong>Relationship/Title: </strong>$prelVal</td>
    </tr>
EOT;
}

?>
    <tr>
        <td colspan="5">&nbsp;</td>
    </tr>
    </table>
    <?php } ?>
<table width="439" cellspacing="0" cellpadding="0">
<?php
if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng')) {
    if ($accCls->allow('assoc3p')) {
        $img = '<div';
        if ($accCls->allow('assoc3p')) {
            $onclk = "onclick=\"return relay3pAssoc('{$_SESSION['currentCaseID']}')\"";
            $img .= $onclk . ' style="cursor:pointer"';
            $lbl = '<a href="javascript:void(0)" ' . $onclk
                . ' title="Associate 3P Profile">CLICK HERE</a> '
                . '<span class="fw-normal">to associate a third party profile with this '
                . 'investigation.</span>';
        } else {
            $lbl = 'A third party profile must be associated with this investigation.<br />'
                . 'Your assigned role does not have that permission.';
        }
        $img .= '><span class="fas fa-plus" style="font-size: 20px;"></span><span class="fas fa-id-card"></span></div>';
        ?>
        <tr>
            <td colspan="2"><table cellpadding="0" cellspacing="0">
            <tr>
                <td><div class="ta-right marg-rtsm"><?php echo $img?></div></td>
                <td><?php echo $lbl?></td>
            </tr>
            </table>
            </td>
        </tr>
        <?php
    }
    if ($tpID) {
        $img = '<img src="/cms/images/profilecard32x32-8.png" width="32" height="32" '
            . 'title="Associated 3P Profile" alt="Associated 3P Profile" />';
        $lbl = $tpRow->legalName;
        ?>
        <tr>
            <td><div class="marg-rtsm no-wrap"><u>Associated 3P Profile</u>:</div></td>
            <td><?php echo $lbl?></td>
        </tr>
        <?php
    }
} ?>

<tr>
    <td>Point of Contact: </td>
    <td><?php echo $pointOfContact; ?></td>
</tr>
<tr>
    <td>Telephone:</td>
    <td><?php echo $phone; ?></td>
</tr>
<tr>
    <td>Email:</td>
    <td><?php echo $emailAddr; ?></td>
</tr>
<tr>
    <td colspan="2"><div style="line-height:5px">&nbsp;</div></td>
</tr>
<tr>
    <td><div class="no-wrap marg-rtsm">Type of investigation<br />you want to conduct?</div></td>
    <td>
<?php
if ($recScope && !$accCls->allow('caseTypeSelection')) {
    echo $recScopeName;
    echo '<input type="hidden" id="lb_caseType" name="lb_caseType" value="', $recScope, '" />';
} else {
    $selectedValue = ($recScope && $lb_caseType == 0 ? $recScope : $lb_caseType);
    $selectedValue = $selectedValue == DUE_DILIGENCE_AI_GENERATED ? 0 : $selectedValue;
    if (($scopeSelectList = $casesCls->buildScopeSelectList("lb_caseType", $selectedValue, 0))
        && ($scopeSelectList != "")
    ) {
        echo $scopeSelectList;
    }
}
?></td>
</tr>
<tr>
  <td></td>
  <td><div id="messageDiv" class="fw-normal indent"></div></td>
</tr>
<tr id="bilingual-rpt-row" class="disp-none">
  <td><label for="bilingual-rpt">Order bilingual report in Chinese</label></td>
  <td class="fw-normal"><input id="bilingual-rpt" name="bilingualRpt" type="checkbox"
    <?php echo ($bilingualRptChecked ? 'checked="checked" ' : ''); ?>value="1" />
    An additional report will be provided by the investigator in Chinese for an additional cost
  </td>
</tr>

<?php
if ($recScopeName) {
    ?>
    <tr>
        <td valign="top"><u>Recommendation</u>:<div
            class="no-wrap marg-topsm marg-rtsm">Risk: <span class="fw-normal"><?php
            echo $tpRow->risk?></span></div></td>
        <td valign="top"><?php echo $recScopeName?><div class="indent fw-normal">Please
            explain below if selected type of investigation<br />differs from risk
            assessment recommendation.</div></td>
    </tr>
    <?php
} ?>

<tr>
    <td colspan="2"><div style="line-height:5px">&nbsp;</div></td>
</tr>
<tr>
    <td valign="top"><div class="no-wrap marg-rtsm" style="margin-top:3px">Brief Note/<br/>
        Billing Information:</div></td>
    <td><div align="left"><textarea name="ta_caseDescription" cols="40" rows="4"><?php
        echo $ta_caseDescription; ?></textarea></div></td>
</tr>

</table></div>

<?php // end right column ?>
    </td>
</tr>
</table>


<?php
if($caseRow['caseSource'] != DUE_DILIGENCE_CS_AI_DD){
// Decide how (and if) to show key persons / principals to investigate
if (count($keyPersons)) {
    // multi-select list
    echo '<div style="margin: .5em 0"><b>Select Principals to include:</b> &nbsp; ',
        '    <span class="fw-normal">(hold the <CTRL> key to select multiple ',
        '    principals)</span></div>', PHP_EOL,
        '<select name="lbm_principals[]" id="rvwConfirmPri" size="4" style="min-width:350px" ',
        '    multiple="multiple">', PHP_EOL;
    // output option tabs
    foreach ($keyPersons as $id => $name) {
        // select name?
        if ($preselectAllPrin) {
            $sel = ' selected="selected"';
        } else {
            $sel = (in_array($name, $preSelectPrincipals)) ? ' selected="selected"': '';
        }
        echo '<option value="', $id, '"', $sel, '>', $name, '</option>', PHP_EOL;
    }
    echo '</select>', PHP_EOL;
} elseif (count($principals)) {
    // show scrollable list in div (no selection)
    echo '<div style="margin: .5em 0"><b>Principals included in Investigation:', PHP_EOL,
        '<div class="marg-topsm fw-normal" ',
        'style="border:1px solid #ccc; width:350px; height:51px; ',
        'overflow: auto; padding-left:.5em">', PHP_EOL;
    echo join("<br />\n", $principals);
    echo '</div>', PHP_EOL;
    echo '<input type="hidden" name="lbm_principals" />', PHP_EOL;
} else {
    // no need to show SBIonPrincipals option
    echo '<input type="hidden" name="lbm_principals" />', PHP_EOL;
    echo '<input type="hidden" name="rb_SBIonPrincipals" value="" />', PHP_EOL;
}
}
$ck = ' checked="checked"';
$sbiYes = ($rb_SBIonPrincipals == 'Yes') ? $ck: '';
$sbiNo  = ($rb_SBIonPrincipals == 'No') ? $ck: '';
?>
<div id="prin-ask" class="errormsg disp-none"
    style="background-color:#FFFF00;"><div
    style="margin: 0, .5em; line-height: normal">If you would like investigators to
    include the Principals in the field investigation, this will be completed for
    an additional cost per  Principal.</div>
    <div style="margin:0 1.5em"><span class="style10">Do you want to also include the
    Principals in the field investigation?</span> &nbsp;
    <input name="rb_SBIonPrincipals" type="radio" value="Yes" id="sbiYes"<?php
        echo $sbiYes; ?> class="ckbx marg-rt0" /> <label for="sbiYes">Yes</label> &nbsp;
    <input name="rb_SBIonPrincipals" type="radio" value="No" id="sbiNo"<?php
        echo $sbiNo; ?> class="ckbx marg-rt0" /> <label for="sbiNo">No</label></div>
</div>
<input type="hidden" id="hideSBIchoice" name="hideSBIchoice" value="" />

<div class="marg-top1e">
<table width="418" cellpadding="0" cellspacing="8" class="paddiv">
<tr>
    <td width="165" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
        onclick="return submittheform('Submit to Investigator')" >Convert to
        Investigation</a></td>
<?php
if (isset($_SESSION['rcMustReload'])) {
    ?>
    <td width="200" nowrap="nowrap" class="submitLinksCancel"><a href="javascript:void(0)"
        onclick="window.parent.location='/cms/case/casehome.sec'">Cancel</a></td>
    <?php
} else {
    ?>
    <td width="200" nowrap="nowrap" class="submitLinksCancel"><a href="javascript:void(0)"
        onclick="top.onHide();" >Cancel</a></td>
    <?php
} ?>
</tr>
</table>
</div>

</div>
<input type="hidden" name="buIgnore" id="buIgnore" value="1" />
<input type="hidden" name="poIgnore" id="poIgnore" value="1" />
</form>

<script type="text/javascript">
(function()
{
    var Dom = YAHOO.util.Dom;
    var el, i, reg, w, mw = 0;
    var ids = ['region', 'dept'];
    var els = [];
    for (i in ids) {
        el = Dom.get('lb_' + ids[i]);
        reg = Dom.getRegion(el);
        if (reg.width > mw) {
            mw = reg.width;
        }
        els[els.length] = el;
    }
    if (mw > 0) {
        w = mw + 'px';
        for (i in els) {
            Dom.setStyle(els[i], 'width', w);
        }
    }
})();

YAHOO.util.Event.onDOMReady(function(o){
    var Dom = YAHOO.util.Dom;

<?php
if ($recScope && !$accCls->allow('caseTypeSelection')) {
    echo <<<EOT
    explainCaseType($recScope);
EOT;
} else {
    echo <<<EOT
    explainCaseType(YAHOO.cms.Util.getSelectValue('lb_caseType'));
EOT;
}
// Billing Unit / Purchase Order
echo $buCls->initLinkedSelects($billingUnit, $billingUnitPO);
?>

});

</script>

<!--   End Center Content -->
<?php noShellFoot();

/**
 * Get array of principal names
 *
 * Extracts non-empty principal names from subjectInfoDD
 *
 * @param integer $caseID cases ID number
 *
 * @return array Principal naems or empty array
 */
function locGetPrincipals($caseID)
{
    $prins = [];
    $siRow = fGetSubInfoDDRow($caseID);
    for ($i = 1; $i <= SUBINFO_PRINCIPAL_LIMIT; $i++) {
        $pKey = 'principal' . $i;
        if ($name = trim((string) $siRow[$pKey])) {
            $prins[] = $name;
        }
    }
    return $prins;
}

/**
 * Write the JS array string values to test if selected caseType offers OSI on principals
 *
 * @param int $clentID TPM tenant ID
 *
 * @return void
 */
function locCaseTypesForOsiOnPrincipals($clientID)
{
    $clientID = (int)$clientID;
    $DB = dbClass::getInstance();
    $clientDB = $DB->clientDBname($clientID, false);
    $mapTable = $clientDB . '.clientSpProductMap';
    $globalSpDB = GLOBAL_SP_DB;
    $sql = "SELECT DISTINCT m.scope\n"
        . "FROM $mapTable m\n"
        . "INNER JOIN $globalSpDB.investigatorProfile i ON i.id = m.spID\n"
        . "INNER JOIN $globalSpDB.spProduct p ON p.id = m.product\n"
        . "WHERE m.clientID = $clientID\n"
        . "AND p.costMethod = 'Fixed' AND i.bFullSp AND i.status = 'active'\n"
        . "AND p.offerOsiOnPrincipals";
    if ($caseTypeList = $DB->fetchValueArray($sql)) {
        echo "'", implode("', '", $caseTypeList), "'";
    }
}

function locHasPrincipals()
{
    // $caseID and $clientID are already cast to int - safe to use directly in query
    global $dbCls, $prinLimit, $caseID, $clientID;
    $result = false;
    for ($index = 1; $index <= $prinLimit; $index++) {
        $sql = "SELECT LENGTH(principal{$index}) FROM subjectInfoDD\n"
            . "WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1";
        if ((int)$dbCls->fetchValue($sql)) {
            $result = true;
            break;
        }
    }
    return $result;
}
