<?php
/**
 * Generate PDF for for DUE_DILIGENCE_HCPDI cases
 */

require_once '../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once '../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('accCaseMng') || !$accCls->allow('printCase')
    || !$session->value('IN_CASE_HOME') || !isset($_SESSION['currentCaseID'])
    || !($caseID = $_SESSION['currentCaseID'])
) {
    return;
}
require_once '../includes/php/'.'class_db.php';
require_once '../includes/php/'.'funcs.php';
require_once '../includes/php/'.'ddq_funcs.php';
require_once '../includes/php/'.'country_state_ddl.php';
require_once '../includes/php/'.'funcs_misc.php';
require_once '../includes/php/'.'class_db.php';
require_once '../includes/php/'.'req_validemail.php';

$e_clientID = $clientID = $_SESSION['clientID'];
if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng')) {
    include_once '../includes/php/'.'funcs_thirdparty.php';
}

$_GET['id'] = $caseID;  // set for older included scripts that depend on it
$_GET['id'] = htmlspecialchars($_GET['id'], ENT_QUOTES, 'UTF-8');
//Get  the case row
$caseRow = fGetCaseRow($caseID);
if (!$caseRow) {
    exit;
}
$e_tpID = $tpID = intval($caseRow['tpID']);
$recScopeName = '';
$caseTypeClientName = '';
if (isset($caseRow['caseType'])
    && isset($_SESSION['caseTypeClientList'][$caseRow['caseType']])
) {
    $caseTypeClientName = $_SESSION['caseTypeClientList'][$caseRow['caseType']];
}
$_SESSION['caseTypeClientName'] = $caseTypeClientName;
if ($_SESSION['b3pAccess'] && $tpID && $accCls->allow('acc3pMng')) {
    // Client has 3P on
    // is caseType a variance from what was recommended when caseType was selected for this case?

    if ($caseRow['rmID'] > 0 && $caseRow['raTstamp']) {
        $recScope = 0;
        $e_rmID = $rmID = $caseRow['rmID'];
        $e_raTstamp = $raTstamp = $caseRow['raTstamp'];
        $sql = "SELECT rmt.scope "
            . "FROM riskAssessment AS ra "
            . "LEFT JOIN riskModelTier AS rmt ON rmt.tier = ra.tier AND rmt.model = '$e_rmID' "
            . "WHERE ra.tpID = '$e_tpID' "
            . "AND ra.tstamp = '$e_raTstamp' "
            . "AND ra.model = '$e_rmID' "
            . "AND ra.clientID = '$e_clientID' "
            . "LIMIT 1";
        if (($recScope = $dbCls->fetchValue($sql)) && ($recScope != $caseRow['caseType'])) {
            $recScopeName = $_SESSION['caseTypeClientList'][$recScope];
        }
    }
}

$toPDF = true; //flag to modify behavior of some included files
$userType = $_SESSION['userType'];
$userClass = fGetUserClass($userType);

// Check for login/Security
fchkUser("casehome");

// Load up the DDQ record
if (isset($_SESSION['ddqRow'])) {
    unset($_SESSION['ddqRow']);
}
$_SESSION['ddqRow'] = $ddqRow = fGetddqSubmittedRow($caseID);

$CPid = $_SESSION['clientID'];  // We came directly in to casehome, use the id from the user rec

$showCustomData = ($accCls->allow('accCaseCustFlds') &&
    ($dbCls->fetchValue("SELECT COUNT(*) FROM customData WHERE clientID='$CPid' "
        . "AND (caseID='$caseID' OR tpID='{$caseRow['tpID']}')"
    )
    > 0)
);

// If Case Stages and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseStageList'])) {
    $_SESSION['caseStageList'] = fLoadCaseStageList();
}
// If Case Type and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseTypeList'])) {
    $_SESSION['caseTypeList'] = fLoadCaseTypeList();
}
if (!isset($_SESSION['logoFileName'])) {
    $_SESSION['logoFileName'] = $dbCls->fetchValue("SELECT logoFileName FROM clientProfile "
        . "WHERE clientID='$CPid' "
    );
}
//Get the client name
$szClientName = fGetClientName($CPid);

$_POST['caseName'] = htmlspecialchars($caseRow[1], ENT_QUOTES, 'UTF-8');
$_POST['caseType'] = $caseRow[3];
$_POST['region'] = $caseRow[4];
$_POST['regionName'] = fGetRegionName($_POST['region']);
$_POST['casePriority'] = $caseRow[6];
$_POST['caseStage'] = $caseRow[9];
$_POST['requestor'] = $caseRow[10];
$_POST['caseAssignedDate'] = $caseRow[11];
$_POST['caseAcceptedByInvestigator'] = $caseRow[13];
$_POST['caseInvestigatorUserID'] = $caseRow[14];
$_POST['caseCompletedByInvestigator'] = $caseRow[15];
$_POST['caseAcceptedByRequestor'] = $caseRow[16];
$_POST['caseDueDate'] = $caseRow[21];
$_POST['budgetAmount'] = $caseRow[19];
$_POST['billingCode'] = $caseRow[22];
$_POST['userCaseNum'] = htmlspecialchars( $caseRow[23], ENT_QUOTES, 'UTF-8');
$_POST['acceptingInvestigatorID'] = $caseRow[24];

// Get Investigator Company Information
$investigatorRow = fGetUserRow($caseRow[14]);
$_POST['investigatorName'] = $investigatorRow[25];
$_POST['investigatorEmail'] = $investigatorRow[4];
$_POST['investigatorPhone'] = $investigatorRow[20];

// Get investigator Complete Info
$iCompleteInfoRow = fGetiCompleteRow($caseID);

// Get the Subject Info Row
$subjectInfoDDRow = fGetSubInfoDDRow($caseID);
$relRow = fGetRelationshipTypeRow($subjectInfoDDRow[3]);
$_POST['subStat'] = $subjectInfoDDRow[4];
$_POST['subType'] = $relRow[2];
$_POST['legalForm'] = $subjectInfoDDRow[5];
$_POST['name'] = $subjectInfoDDRow[6];
$_POST['street'] = $subjectInfoDDRow[7];
$_POST['city'] = $subjectInfoDDRow[8];
$_POST['state'] = $subjectInfoDDRow[9];
$_POST['country'] = $subjectInfoDDRow[10];
$_POST['pointOfContact'] = $subjectInfoDDRow[11];
$_POST['phone'] = $subjectInfoDDRow[12];
$_POST['emailAddr'] = $subjectInfoDDRow[13];
$_POST['bAwareInvestigation'] = $subjectInfoDDRow[14];
$_POST['addr2'] = $subjectInfoDDRow[18];
$_POST['postCode'] = $subjectInfoDDRow[19];
$_POST['mailDDquestionnaire'] = $subjectInfoDDRow[20];
$_POST['principal1'] = $subjectInfoDDRow[21];
$_POST['principal2'] = $subjectInfoDDRow[22];
$_POST['principal3'] = $subjectInfoDDRow[23];
$_POST['principal4'] = $subjectInfoDDRow[24];
$_POST['POCposition'] = $subjectInfoDDRow[25];
$_POST['pRelationship1'] = $subjectInfoDDRow[26];
$_POST['pRelationship2'] = $subjectInfoDDRow[27];
$_POST['pRelationship3'] = $subjectInfoDDRow[28];
$_POST['pRelationship4'] = $subjectInfoDDRow[29];
$_POST['SBIonPrincipals'] = $subjectInfoDDRow[30];

// Get Investigator User Information
$invUserRow = fGetUserRow($_POST['acceptingInvestigatorID']);
$_POST['acceptInvName'] = $invUserRow[3];

// Get Requestor User Name
$reqUserRow = fGetUserRowByUserID($_POST['requestor']);
$_POST['requestorName'] = $reqUserRow[3];

$stateName = getStateName($_POST['state']);
$_POST['stateName'] = (!empty($stateName)) ? $stateName : 'None';


$countryRow = fGetCountryRow($_POST['country']);
$_POST['countryName'] = $countryRow[1];

ob_start();

$pageTitle = $caseRow['userCaseNum'];
$loadYUImodules = ['cmsutil', 'button', 'json', 'datasource', 'datatable', 'linkedselects'];

date_default_timezone_set('UTC');
$caseAssignedDate = htmlspecialchars(trim((string) $_POST['caseAssignedDate']), ENT_QUOTES, 'UTF-8');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $caseAssignedDate)) { // Validate date format (YYYY-MM-DD)
    $caseDate = substr($caseAssignedDate, 0, 10);
} else {
    $caseDate = ''; // Fallback value for invalid input
}
$tm = time();
$pdfMakeTime = date("h:i:s", $tm);
$pdfMakeDate = date("D M d Y", $tm);

$companyName = $dbCls->fetchValue("SELECT clientName FROM clientProfile "
    . "WHERE id = '{$_SESSION['clientID']}'"
);

require_once '../includes/php/'.'class_pdf.php';
$msg = "This document is confidential material of $companyName "
    . "and may not be copied or shared without permission.";
$footer = ['top' => $msg, 'left' => "Case Assign Date $caseDate", 'middle' => "PDF created on $pdfMakeDate at $pdfMakeTime"];
$pdf = new GenPDF($footer);

$insertInHead = $pdf->overrideCss();

noShellHead(true);
?>

<div style="padding-top:260px; width:100%; margin-left:40px">

<table align="center" cellpadding="0" cellspacing="5" width="100%">
    <tr>
      <td align="center" valign="top" >
      <img
      src="<?php echo $sitepath; ?>dashboard/clientlogos/<?php echo $_SESSION['logoFileName']?>"
      hspace="0" vspace="0" class="logoSize" /><br />
      <br /></td>
  </tr>
     <tr>
        <td align="center" valign="middle" nowrap="nowrap"><span class="title_text">
        <?php echo $_POST['userCaseNum'];?>:<?php echo $_POST['caseName'];?></span></td>
      </tr>
      <tr>
        <td align="center"><b>Type:</b> <?php echo $caseTypeClientName?></td>
      </tr>
<?php
if ($recScopeName) {
    ?>
    <tr>
        <td><div class="marg-rtsm">Recommended:</div></td>
        <td valign="top"><?php echo $recScopeName?></td>
    </tr>
    <?php
}
?>
      <tr>
        <td align="center"><b>Status:</b> Active
          <?php //=$_POST['caseAssignedDate']?>
        </td>
      </tr>
      <tr>
        <td align="center"><b>Stage:</b>
        <?php echo "{$_SESSION['caseStageList'][$_POST['caseStage']]}"?>
        </td>
      </tr>
</table>
</div>

<p class="pgbrk sect"></p>
<p class="sect">Professional Information</p>
<hr width="100%" size="1" noshade="noshade" />
<?php
require "caseFolderPages/company.sec";
if ($accCls->allow('accCaseAddInfo')) {
    ?>
    <!--NewPage-->
    <p class="pgbrk sect">Additional Information</p>
    <hr width="100%" size="1" noshade="noshade" />
    <?php
    include "caseFolderPages/addinfo.sec";
}
if ($accCls->allow('accCaseAttach')) {
    ?>
    <!--NewPage-->
    <p class="pgbrk sect">Attachments</p>
    <hr width="100%" size="1" noshade="noshade" />
    <?php
    include "caseFolderPages/dt-attachments-ws.sec";
    include "caseFolderPages/dt-attachments.sec";
}

if ($userClass != 'vendor') {
    if ($showCustomData) {
        if (isset($_SESSION["customLabel-customFieldsCase"])) {
            $customFieldsLabel = $_SESSION["customLabel-customFieldsCase"];
        } else {
            $customFieldsLabel = 'Custom Fields';
        }
        ?>
        <!--NewPage-->
        <p class="pgbrk sect"><?php echo $customFieldsLabel; ?></p>
        <hr width="100%" size="1" noshade="noshade" />
        <?php
        include "../thirdparty/customdata.sec";
    }
    if ($accCls->allow('accCaseNotes')) {
        ?>
        <!--NewPage-->
        <p class="pgbrk sect"><?php echo $_SESSION['customLabel-caseNotes']; ?></p>
        <hr width="100%" size="1" noshade="noshade" />
        <?php
        include "caseFolderPages/casenotes-ws.sec";
        include "caseFolderPages/casenotes.sec";
    }

    if (!$_SESSION['cflagNoReviewPanel']
        && $_SESSION['cflagReviewTab']
        && $accCls->allow('accCaseNotes')
        && (($ddqRow && $ddqRow['status'] == 'submitted')
            || in_array($caseRow['caseStage'], [COMPLETED_BY_INVESTIGATOR, ACCEPTED_BY_REQUESTOR]
            )
        )
    ) {
        ?>
        <!--NewPage-->
        <p class="pgbrk sect">Reviewer</p>
        <hr width="100%" size="1" noshade="noshade" />
        <?php
        include "caseFolderPages/reviewer.sec";
    }
} // not vendor

if ($accCls->allow('printCase')) {
    ?>
    <!--NewPage-->
    <p class="pgbrk sect">Audit Log</p>
    <hr width="100%" size="1" noshade="noshade" />
    <?php
    include "caseFolderPages/caselog-ws.sec";
    include "caseFolderPages/caselog.sec";
}
noShellFoot(true);

$pdfTitle = 'Case.Folder.' . str_replace(" ", "_", (string) $_POST['userCaseNum']).'.pdf';
if ($err = $pdf->generatePDF($pdfTitle)) {
    echo $pdf->parentErrorHandler($err);
}
