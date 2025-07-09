<?php

/**
 * Additional edit of subjectInfoDD record
 *
 * Point of Contact, are they aware of investigation, and attach questionnaire
 */

if (!isset($_GET['id'])) {
    exit('Access denied');
}

require_once __DIR__ . '/../includes/php/'.'cms_defs.php' ;
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('addCase')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';

$e_caseID = $caseID = intval($_GET['id']);
$caseRow    = fGetCaseRow($caseID);
$originSource = isset($_SESSION['originSource']) ? $_SESSION['originSource'] : '';
$subinfoRow = fGetSubInfoDDRow($caseID);
if (!$caseRow
    || !$subinfoRow
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    exit;
}

$e_clientID = $clientID = $_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);


// Define form vars
$postVarMap = [
    'tf_' => [
        'pointOfContact', 'POCposition', 'phone',
    ],
    'ta_' => [
        'addInfo',
    ],
    'rb_' => [
        'bAwareInvestigation', 'bInfoQuestnrAttach',
    ],
];
if (isset($_POST['Submit'])) {

    if (!PageAuth::validToken('edsi2Auth', $_POST['edsi2Auth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "editsubinfodd_pt2.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }
    switch ($_POST['Submit']) {

    case 'Attach Questionnaire':
        if ($accCls->allow('addCaseAttach')) {
            $_SESSION['subinfo_pt2Hold'] = $_POST;
            session_write_close();
            header("Location: subinfoAttach.php?id=$caseID");
            exit;
        }
        break;

    case 'Return to Step 1':
        // Redirect previous step
        $passID = $caseID;
        $nextPage = $originSource === 'reviewConfirm' ?
            "reviewConfirm.php" : "editsubinfodd.php";
        break;

    case 'Save and Continue':
        $passID = $caseID;
        $nextPage = "reviewConfirm_pt2.php";
        break;

    case 'Save and Quit';
        $passID = $caseID;
        $nextPage = "casehome.sec";
        break;

    }  //end switch

    include __DIR__ . '/../includes/php/'.'dbcon.php';

    // Create normal vars from POST
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            $pVarName = $prefix . $varName;
            $e_pVarName = 'e_' . $pVarName;
            $value = '';
            if (isset($_POST[$pVarName])) {
                $value = $_POST[$pVarName];
            }
            ${$pVarName} = $value;
            ${$e_pVarName} = mysqli_real_escape_string(MmDb::getLink(), (string) $value);
        }
    }
    if ($rb_bAwareInvestigation === '') {
        $rb_bAwareInvestigation = null;
    }

    // Clear error counter
    $iError = 0;
    if ($_POST['Submit'] == 'Save and Continue') {
        // Check for all Point of Contact information
        if (!(isset($_POST['rb_bAwareInvestigation']))) {
            $_SESSION['aszErrorList'][$iError++] = "You must mark Yes/No if the Subject is "
                . "aware they are being investigated.";
        }
    }
    // any errors?
    if ($iError) {
        debugTrack(['POST DATA' => $_POST]);
        $_SESSION['subinfo_pt2Hold'] = $_POST;
        $errorUrl = "editsubinfodd_pt2.php?id=" . $caseID;
        debugTrack(['Error URL' => $errorUrl]);
        session_write_close();
        header("Location: $errorUrl");
        exit;
    }

    // Cleana up addInfo
    $ta_addInfo = mysqli_real_escape_string(MmDb::getLink(), normalizeLF($ta_addInfo));
    if ($rb_bAwareInvestigation === null) {
        $bAwareInvestigation = 'NULL';
    } else {
        $bAwareInvestigation = "'$rb_bAwareInvestigation'";
    }

    $fields = [$e_tf_phone, $e_ta_addInfo, $e_tf_POCposition, $e_tf_pointOfContact];
    $sanitizedFields = array_map(fn($field) => safeHtmlSpecialChars($field), $fields);

    // Reassign sanitized values to the original variables
    list($e_tf_phone, $e_ta_addInfo, $e_tf_POCposition, $e_tf_pointOfContact) = $sanitizedFields;

    $sql = "UPDATE subjectInfoDD SET "
        . "phone       = '$e_tf_phone', "
        . "addInfo     = '$e_ta_addInfo', "
        . "POCposition = '$e_tf_POCposition', "
        . "pointOfContact      = '$e_tf_pointOfContact', "
        . "bAwareInvestigation = $bAwareInvestigation, " // NULL, '0', or '1'
        . "bInfoQuestnrAttach  = '$e_rb_bInfoQuestnrAttach' "
        . "WHERE caseID = '$e_caseID' AND clientID = '$e_clientID' LIMIT 1";
    $result = mysqli_query(MmDb::getLink(), $sql);
    if (!$result) {
        debugTrack(['Result Failed']);
        exitApp(__FILE__, __LINE__, $sql);
    }

    // sync global index
    $globalIdx->syncByCaseData($caseID);

    unset($_SESSION['subinfoHold2']);
    unset($_SESSION['subinfo_pt2Hold']);

    // Redirect
    if ($_POST['Submit'] == 'Save and Quit') {
        $dest = $sitepath . 'case/casehome.sec';
        echo '<script type="text/javascript">' . PHP_EOL
            . 'top.window.location.replace("' . $dest . '");' . PHP_EOL
            . '</script>' . PHP_EOL;
    } else {
        session_write_close();
        header("Location: $nextPage?id=$passID");
    }
    exit;
}

// -------- Show Form

/*
 * Data source precedence:
 *  1. use sess vars if persent
 *  2. use values from record
 */

if (isset($_SESSION['subinfo_pt2Hold'])) {
    $sih = $_SESSION['subinfo_pt2Hold'];
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            $pVarName = $prefix . $varName;
            $value = '';
            if (isset($sih[$pVarName])) {
                $value = $sih[$pVarName];
            }
            ${$pVarName} = $value;
        }
    }
    if ($rb_bAwareInvestigation === '') {
        $rb_bAwareInvestigation = null;
    }
    unset($_SESSION['subinfo_pt2Hold']);
} else {
    // Create normal vars from subinfo record
    $subinfoRow = fGetSubInfoDDRow($caseID);
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            $pVarName = $prefix . $varName;
            ${$pVarName} = $subinfoRow[$varName];
        }
    }
}

$ck = ' checked="checked"';
if ($rb_bAwareInvestigation === null) {
    $awareYes = '';
    $awareNo  = '';
} else {
    $awareYes = ($rb_bAwareInvestigation == 1) ? $ck: '';
    $awareNo  = ($rb_bAwareInvestigation == 0) ? $ck: '';
}
$qAttachYes = ($rb_bInfoQuestnrAttach == 'Yes') ? $ck: '';
$qAttachNo  = ($rb_bInfoQuestnrAttach == 'No') ? $ck: '';

$pageTitle = "Create New Case Folder";
shell_head_Popup();
$edsi2Auth = PageAuth::genToken('edsi2Auth');

if(preg_match('/(?i)msie [5-8]/',(string) $_SERVER['HTTP_USER_AGENT'])) {
    $hasAttachmentsWidth = '420';
} else {
    $hasAttachmentsWidth = '430';
}

?>

<!--Start Center Content-->
<script type="text/javascript">
function submittheform ( selectedtype )
{
    document.editsubinfoddform.Submit.value = selectedtype ;
    document.editsubinfoddform.submit() ;
    return false;
}
function explainText(theMessage)
{
    return;
}
</script>

<h6 class="formTitle">due diligence key info form - company </h6>

<form name="editsubinfoddform" class="cmsform"
    action="<?php echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="edsi2Auth" value="<?php echo $edsi2Auth; ?>" />
<div class="formStepsPic2"><img src="/assets/image/formSteps3.gif" name="step1"
    width="223" height="30" border="0" id="step1" /></div>
<div class="stepFormHolder">

<table width="881" height="233" border="0" cellpadding="0" cellspacing="0" class="paddiv">
    <tr>
        <td colspan="3" align="left"><b><span
            class="style1">Information Questionnaire</span></b></td>
    </tr>

    <tr>
        <td colspan="3"><strong>POINT OF CONTACT</strong></td>
    </tr>

    <tr>
        <td height="10" valign="top"><div align="right" class="style3">Are they aware
            that they will <br />be investigated?:</div></td>
        <td valign="top">
            <input type="radio" name="rb_bAwareInvestigation" class="ckbx"
                value="1"<?php echo $awareYes; ?> />Yes &nbsp;
            <input type="radio" name="rb_bAwareInvestigation" class="ckbx"
                value="0"<?php echo $awareNo; ?> />No</td>
        <td width="550" rowspan="6" valign="top"><table width="422" border="0"
            cellspacing="0" cellpadding="0" class="paddiv">
            <tr>
                <td><a href="javascript:void(0)"
                    onclick="return submittheform('Attach Questionnaire')">Click here to
                    Attach Questionnaire or other documents.</a><br/>
                    List of documents attached to this case:</td>
            </tr>
            <tr>
                <td><iframe src="hasAttachment.php?id=<?php echo $caseID; ?>"
                    name="hasAttach" width="<?php echo $hasAttachmentsWidth; ?>"
                    marginwidth="0" height="150" marginheight="0" align="left"
                    scrolling="Auto" frameborder="1" id="hasAttach"
                    style="border:1px dotted grey"></iframe></td>
            </tr>
            </table>
            <table width="430" border="0" cellpadding="0" cellspacing="0" class="paddiv">
            <tr>
                <td><br /><input type="radio" name="rb_bInfoQuestnrAttach" value="Yes"
                    class="ckbx" tabindex="5"<?php echo $qAttachYes; ?> />I have reviewed and
                    attached the Information Questionnaire</td>
            </tr>
            <tr>
                <td><input type="radio" name="rb_bInfoQuestnrAttach" value="No" class="ckbx"
                    tabindex="6"<?php echo $qAttachNo; ?> />No Questionnaire Attached
                    (Explanation provided below)
            </tr>
            <tr>
                <td width="599"><textarea name="ta_addInfo" cols="40" rows="5" wrap="soft"
                    tabindex="7" class="no-trsl"><?php echo $ta_addInfo; ?></textarea></td>
            </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td valign="top" colspan="2"><table width="351" border="0" cellpadding="0"
            cellspacing="0">
            <tr>
                <td width="101" height="10"><div align="right">Name:</div></td>
                <td width="284" height="10"><input name="tf_pointOfContact" type="text"
                    id="tf_pointOfContact" size="25" maxlength="255"
                    value="<?php echo $tf_pointOfContact; ?>" tabindex="2" class="no-trsl" /></td>
            </tr>

            <tr>
                <td width="101" height="10"><div align="right">Position:</div></td>
                <td width="284" height="10"><input name="tf_POCposition" type="text"
                    id="tf_POCposition" size="25" maxlength="255"
                    value="<?php echo $tf_POCposition; ?>" tabindex="3" class="no-trsl" /></td>
            </tr>
            <tr>
                <td width="101" height="10"><div align="right">Telephone:</div></td>
                <td width="284" height="10"><input name="tf_phone" type="text" id="tf_phone"
                    size="25" maxlength="255"
                    value="<?php echo $tf_phone; ?>" tabindex="4" class="no-trsl" /></td>
            </tr>
            </table>
        </td>
    </tr>
</table>
<br />

<table width="618" border="0" cellpadding="0" cellspacing="8" class="paddiv" >
    <tr>
        <td width="103" nowrap="nowrap" class="submitLinksBack"><a href="javascript:void(0)"
            onclick="return submittheform('Return to Step 1')" >Go Back | Edit</a></td>
        <td width="161" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Save and Continue')"
            tabindex="24">Continue | Enter Details</a> </td>
        <td width="114" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Save and Quit')" >Save and Close</a></td>
        <td width="200" nowrap="nowrap" class="submitLinksCancel paddiv">
            <a href="javascript:void(0)" onclick="top.onHide();" >Cancel</a>  </td>
    </tr>
</table>
</div>
</form>

<!--   End Center Content-->

<?php
noShellFoot(true);
?>
