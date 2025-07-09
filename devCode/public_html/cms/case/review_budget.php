<?php

/**
 * Accept or rejectproposed budget
 *
 * Updates caseStage based on user response
 */

if (!isset($_GET['id'])) {
    exit('Invalid access');
}

require_once __DIR__ . '/../includes/php/'."cms_defs.php";
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('approveBudget')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'."funcs.php";
require_once __DIR__ . '/../includes/php/'.'funcs_log.php'; //logUserEvent
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';

//Get  the case Record

$caseID = intval($_GET['id']);
$caseRow = fGetCaseRow($caseID);
if (!$caseRow) {
    exit;
}
$clientID = $_SESSION['clientID'];
$caseStage= $caseRow['caseStage'];
$caseType = $caseRow['caseType'];
$globalIdx = new GlobalCaseIndex($clientID);

if ($caseStage != AWAITING_BUDGET_APPROVAL
    || $_SESSION['userSecLevel'] <= READ_ONLY
    || $_SESSION['userType'] <= VENDOR_ADMIN
) {
    session_write_close();
    header("Location: /cms/case/casehome.sec");
    exit;
}

// Find the Estimated Completion Date
require __DIR__ . '/../includes/php/'.'dbcon.php';
$adjNumOfDays = fBusDays($caseRow['numOfBusDays'], $caseType, $caseRow['caseCountry']);
$szCurDate = date("Y-m-d");
$dtEstCompletionDate = date_create($szCurDate);
date_modify($dtEstCompletionDate, "+$adjNumOfDays day");
$estCompletionDate = date_format($dtEstCompletionDate, "Y-m-d");
$logMsg = "";

// Submit Processing
if (isset($_POST['Submit'])) {
    if (!PageAuth::validToken('revbudAuth', $_POST['revbudAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "review_budget.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }
    include __DIR__ . '/../includes/php/'."dbcon.php";

    $e_estCompletionDate = $estCompletionDate; //date_format
    // $e_ trusted $caseID, $clientID

    switch ($_POST['Submit']) {
    case 'Accept':
        // Update case record to BUDGET_APPREVOVED and send email to the  Vendor
        $sysEmail      = EMAIL_BUDGET_APPROVED;
        $caseStage     = BUDGET_APPROVED;
        $caseStageName = 'Budget Approved';

        // Check for Client Emails triggered by the caseStage moving in to Budget Approved
        fSendClientEmail($clientID, 'stageChangeBudgetApproved', $_SESSION['languageCode'], 0);

        $e_caseStage = intval($caseStage); //db int
        augmentLogMsg($logMsg, "caseDueDate = `$e_estCompletionDate`");

         // $e_ trusted $caseID, $clientID
        $sql = "UPDATE cases SET caseStage='$e_caseStage', "
            . "caseDueDate = '$e_estCompletionDate', "
            . "internalDueDate = '$e_estCompletionDate' "
            . "WHERE id = '$caseID' AND clientID = '$clientID' LIMIT 1";
        break;

    case 'Reject':
        // Update case record to ASSIGNED and send email to the  Vendor
        $sysEmail      = EMAIL_BUDGET_REJECTED;
        $caseStage     = ASSIGNED;
        $caseStageName = 'Budget Rejected';
        // $e_ trusted $caseID, $clientID

        $e_caseStage = intval($caseStage); //db int


        $sql = "UPDATE cases SET caseStage='$e_caseStage' "
            . "WHERE id = '$caseID' AND clientID = '$clientID' LIMIT 1";
        break;

    default:
        exit("ERROR - Submit Value NOT Recognized!");
    }

    $result = mysqli_query(MmDb::getLink(), $sql);
    if (!$result) {
        exitApp(__FILE__, __LINE__, $sql);
    }

    if (mysqli_affected_rows(MmDb::getLink())) {
        $globalIdx->syncByCaseData($caseID);
        augmentLogMsg($logMsg, "stage: `Awaiting Budget Approval` => `$caseStageName`");
        logUserEvent(26, $logMsg , $caseID);
    }

    // Generate an email to notify the requestor that the investigator has completed his work
    fSendSysEmail($sysEmail, $caseID);

    // Return to Case List
    echo "<script type=\"text/javascript\">\ntop.window.location=\"casehome.sec?tname=caselist\";\n</script>";

    exit;
}


$pageTitle = "Create New Case Folder";
shell_head_Popup();
$revbudAuth = PageAuth::genToken('revbudAuth');
?>
<!--Start Center Content-->
<script type="text/javascript">
function submittheform ( selectedtype )
{
    document.reviewbudgetform.Submit.value = selectedtype ;
    document.reviewbudgetform.submit() ;
    return false;
}
</script>

<h6 class="formTitle">&nbsp;</h6>
<h6 class="formTitle">Review/Accept Budget: <?php echo $caseRow['caseName']?></h6>

<form name="reviewbudgetform" class="cmsform"
    action="review_budget.php?id=<?php echo $caseID?>" method="post" >
<input type="hidden" name="Submit" />
  <input type="hidden" name="revbudAuth" value="<?php echo $revbudAuth; ?>" />
<div class="stepFormHolder">
<table width="503" border="0" cellspacing="0" cellpadding="0">
<tr>
    <td align="left"><span class="blackFormTitle"><b>Vendor has submitted the
        following Budget:</b></span></td>
</tr>
</table>

<table width="535" border="0" cellpadding="0" cellspacing="0" class="paddiv">
<tr>
    <td width="183"><div align="right" class="style3 paddiv">Budget Type:</div></td>
    <td width="352"><?php echo $caseRow['budgetType']?> </td>
</tr>
<tr>
    <td><div align="right" class="style3 paddiv">Budget Amount(USD):</div></td>
    <td><?php echo $caseRow['budgetAmount']?> </td>
</tr>
<tr>
    <td><div align="right" class="style3 paddiv">Budget Description:</div> </td>
    <td><?php echo $caseRow['budgetDescription']?></td>
</tr>
<tr>
    <td><div align="right" class="style3 paddiv">Est. Completion Date:</div> </td>
    <td><?php echo $estCompletionDate?></td>
</tr>
</table>
<br />

<!--  Begin BUTTONS -->
<br />
<table width="408" border="0" cellpadding="0" cellspacing="8" class="paddiv">
<tr>
    <td width="137" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
        onclick="return submittheform('Accept')" >Accept Budget</a>     </td>
    <td width="136" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
        onclick="return submittheform('Reject')" >Reject Budget</a>     </td>
    <td width="103" nowrap="nowrap" class="submitLinksCancel"> <a href="javascript:void(0)"
        onclick="top.onHide();" >Cancel</a>  </td>
</tr>
</table>

</div>
</form>

<!--   End Center Content-->
<?php noShellFoot()?>
