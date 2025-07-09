<?php
/**
 * Add principals to subjectInfoDD record
 *
 * Data is saved only to a session variable here.
 */

if (!isset($_GET['id'])) {
    exit('Invalid access.');
}

require_once __DIR__ . '/../includes/php/cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/funcs_cases.php';
require_once __DIR__ . '/../includes/php/class_formValidation.php';
require_once __DIR__ . '/../includes/php/class_all_sp.php';
// grh: can't confirm this is invoked
$percentTitle = $accCls->trans->codeKey('owner_without_percent');
$formValidationCls = new formValidation('principal');

$caseID = intval($_GET['id']);
$caseRow = fGetCaseRow($caseID);
if (!$caseRow
    || $session->secure_value('userClass') == 'vendor'
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    exit;
}
$caseType = $caseRow[3];
$offerOsiOnPrincipals = (new ServiceProvider())->optionalScopeOnPrincipals($caseType, (int)$_SESSION['clientID']);

// Define vars for principals
$prinVars = [];
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
    //$prinVars[] = 'p'  . $i . 'phone';
    //$prinVars[] = 'p'  . $i . 'email';
}

if (isset($_POST['Submit'])) {
    if (!PageAuth::validToken('addprinAuth', $_POST['addprinAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errorUrl = "addprincipals.php?id=" . $caseID;
        header("Location: $errorUrl");
        exit;
    }

    switch ($_POST['Submit']) {
    case 'Update':
        $nextPage = (isset($_SESSION['addPrincipalsSource']) && $_SESSION['addPrincipalsSource'] == 'reviewConfirm') ? "reviewConfirm.php" :"addsubinfodd.php";
        debugTrack('addprincipals, On update it works');
        $passID = $caseID;
        break;

    case 'Cancel':
        session_write_close();
        debugTrack('addprincipals, On cancel it redirects');
        $nextPage = (isset($_SESSION['addPrincipalsSource']) && $_SESSION['addPrincipalsSource'] == 'reviewConfirm') ? "reviewConfirm.php" :"addsubinfodd.php";
        header("Location: $nextPage?id=" . $caseID);
    exit;

    }

    // determine which fieldsetCounter fields should be blank because principal name is blank
    $clearPrincipals = []; // collect which principals should be 'deleted'
    foreach ($prinVars as $varName) {
        $matches = [];
        if (preg_match('#^principal(\d+)$#', $varName, $matches)) {
            $tmpPostKey = 'tf_principal'.$matches[1];
            if (isset($_POST[$tmpPostKey]) && strlen(trim((string) $_POST[$tmpPostKey])) == 0) {
                $clearPrincipals[] = $matches[1]; // mark for delete
            }
        }
    }

    // save _POST data to sess var to make form sticky
    $spv = []; // session principal values
    $fieldsetCounter = 1;
    $fieldCounter = 1;
    $prncplsData = [];
    foreach ($prinVars as $varName) {
        if ($fieldCounter == 1) {
            $prncpl = new stdClass();
        }
        $prefix = (str_starts_with($varName, 'bp')) ? 'cb_': 'tf_';
        $pVarName = $prefix . $varName;
        if ($prefix == 'cb_') {
            $value = 0;
        } else {
            $value = '';
        }
        switch ($varName) {
        case 'principal' . $fieldsetCounter:
            if (isset($_POST[$pVarName])) {
                $value = trim((string) $_POST[$pVarName]);
            }
            $prncpl->pName = $value;
            break;
        case 'pRelationship' . $fieldsetCounter:
            if (isset($_POST[$pVarName])) {
                $value = trim((string) $_POST[$pVarName]);
            }
            break;
        case 'p' . $fieldsetCounter . 'OwnPercent':
            if (isset($_POST[$pVarName])) {
                $value = $_POST[$pVarName];
            }
            $prncpl->pOwnPercent = $value;
            break;
        case 'bp' . $fieldsetCounter . 'Owner':
            if (isset($_POST[$pVarName])) {
                $value = intval($_POST[$pVarName]);
            }
            $prncpl->bpOwner = $value;
            break;
        case 'bp' . $fieldsetCounter . 'KeyMgr':
            if (isset($_POST[$pVarName])) {
                $value = intval($_POST[$pVarName]);
            }
            $prncpl->bpKeyMgr = $value;
            break;
        case 'bp' . $fieldsetCounter . 'BoardMem':
            if (isset($_POST[$pVarName])) {
                $value = intval($_POST[$pVarName]);
            }
            $prncpl->bpBoardMem = $value;
            break;
        case 'bp' . $fieldsetCounter . 'KeyConsult':
            if (isset($_POST[$pVarName])) {
                $value = intval($_POST[$pVarName]);
            }
            $prncpl->bpKeyConsult = $value;
            break;
        case 'bp' . $fieldsetCounter . 'Unknown':
            if (isset($_POST[$pVarName])) {
                $value = intval($_POST[$pVarName]);
            }
            $prncpl->bpUnknown = $value;
            break;
        }
        // Set all values to blank if the principal name is blank.
        if (in_array($fieldsetCounter, $clearPrincipals)) {
            $value = ($prefix == 'cb_') ? 0 : '';
        }
        $spv[$varName] = $value;
        if ($fieldCounter > 7) {
            $fieldCounter = 1;
            $prncplsData[$fieldsetCounter] = $prncpl;
            $fieldsetCounter++;
        } else {
            $fieldCounter++;
        }
    }
    $_SESSION['prinHold'] = $spv;

    $validationErrors = $formValidationCls->validatePrincipals($prncplsData);
    if (is_array($validationErrors) && $validationErrors['totalErrors'] == 0) {
        // Redirect
        session_write_close();
        header("Location: $nextPage?id=$passID");
        exit;
    }

}

$insertInHead =<<<EOT

<style type="text/css">
.formValidationErrors {
    color: #FF0000;
    font-weight: bold;
}
.lmarg15 {
    margin-left: 15px;
}
.tr-spacer {
    line-height:1em;
}
input[type=checkbox] {
    vertical-align: middle;
    margin: -2px 5px 0 15px;
}
.pmt {
    white-space: nowrap;
    text-align: right;
    margin-right: 5px;
}
.pmtInp {
    width:250px;
}

</style>

EOT;
$pageTitle = "Add Principals";
shell_head_Popup();
$addprinAuth = PageAuth::genToken('addprinAuth');

$spv = [];
if (isset($_SESSION['prinHold'])) {
    $spv = $_SESSION['prinHold'];
}
// make sure all session principal values are set
foreach ($prinVars as $varName) {
    if (!isset($spv[$varName])) {
        $spv[$varName] = (str_starts_with($varName, 'bp')) ? 0: '';
    }
}
$_SESSION['prinHold'] = $spv;

?>

<!--Start Center Content-->
<script type="text/javascript">
function submittheform ( selectedtype )
{
    document.addsubinfoddform.Submit.value = selectedtype ;
    document.addsubinfoddform.submit() ;
    return false;
}
</script>

<form name="addsubinfoddform" class="cmsform"
    action="<?php echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="addprinAuth" value="<?php echo $addprinAuth; ?>" />
<?php
echo ((isset($validationErrors) && is_array($validationErrors))
    ?  $formValidationCls->getValidationErrors($validationErrors)
    : ''
);
?>
<div class="stepFormHolder">
<table border="0" cellspacing="0" cellpadding="0">
<tr>
    <td><h6 class="formTitle">Add Principals</h6></td>
</tr>
</table>

<table border="0" cellspacing="0" cellpadding="0" style="width: 100%;">

<?php
if ($offerOsiOnPrincipals) {
    ?>
    <tr>
        <td colspan="4"><div class="errormsg paddiv"><br />
        The scope includes field investigation on the Corporate entity.<br />
        Additional costs may apply for any  principals entered below.</div></td>
    </tr>
    <?php
} elseif ($caseType == DUE_DILIGENCE_IBI || $caseType == DUE_DILIGENCE_OSIPDF) {
    ?>
    <tr>
        <td colspan="4"><div class="errormsg paddiv" ><br />
        The scope includes online research of select public<br />
        information only. No field investigation is conducted.<br />
        Additional cost may apply per Principal added below.</div></td>
    </tr>
    <?php
} ?>

<tr>
    <td colspan="2"></td>
    <td height="35"><div class="lmarg15">Role:</div></td>
    <td height="35"><div class="no-wrap lmarg15">Check all that apply</div></td>
</tr>

<?php

for ($i = 1; $i <= 4; $i++) {
    if ($i == 1) {
        echoKP($i, 0);
    } else {
        echoKP($i);
    }
}
?>

<tr id="HiddenDiv-spc"><td colspan="4" class="tr-spacer">&nbsp;</td></tr>
<tr id="HiddenDiv-ctrl"> <td height="25" colspan="1"
    align="center"><a  href="javascript:void(0)"
    onclick="return ShowHide('HiddenDiv')" class="btn btn-light"><b>+ Add Three More Principals </b>&nbsp;</a> </td></tr>
</table>

<!--Start Princ 5 - 7 -->

<div class="disp-none" id="HiddenDiv" style="width: 100%;">
<table border="0" cellspacing="0" cellpadding="0" style="width: 100%;">

<?php
for ($i = 5; $i <= 7; $i++) {
    echoKP($i);
}
?>

<tr id="HiddenDiv2-spc"><td colspan="4" class="tr-spacer">&nbsp;</td></tr>
<tr id="HiddenDiv2-ctrl" bgcolor="#DADADA"> <td height="25" colspan="4"
    align="center"><a href="javascript:void(0)"
    onclick="return ShowHide('HiddenDiv2')"><b>+ Add Three More Principals </b>&nbsp;</a> </td></tr>
</table>
</div>

<!-- Start Princ 8 - 10-->
<div class="disp-none" id="HiddenDiv2" style="width: 100%;">
<table border="0" cellspacing="0" cellpadding="0" style="width: 100%;">

<?php
for ($i = 8; $i <= 10; $i++) {
    echoKP($i);
}
?>

</table>
</div>

<table width="181" border="0" cellpadding="0" cellspacing="8" class="paddiv">
<tr><td colspan="2">&nbsp;</td></tr>
<tr>
    <td width="68" nowrap="nowrap" class="submitLinks paddiv">
        <a href="javascript:void(0)" onclick="return submittheform('Update')" >Update</a></td>
    <td width="408" nowrap="nowrap" class="submitLinksCancel paddiv"><a href="javascript:void(0)"
        onclick="return submittheform('Cancel')" >Cancel</a></td>
</tr>

</table>
</div>
</form>

<?php // ----- Do Not Move! ----- // ?>
<script type="text/javascript">
function ShowHide(divId)
{
    Dom.replaceClass(divId, 'disp-none', 'disp-block');
    Dom.replaceClass(divId + '-ctrl', 'disp-block', 'disp-none');
    Dom.replaceClass(divId + '-spc', 'disp-block', 'disp-none');
    return false;
}

<?php
// show extra principal inputs if any of the remaining values are not empty
for ($i = 5; $i <= 10; $i++) {
    $id = 'principal' . $i;
    if ($spv[$id] != '') {
        ?>
        ShowHide('HiddenDiv');
        <?php
        break;
    }
}
for ($i = 8; $i <= 10; $i++) {
    $id = 'principal' . $i;
    if ($spv[$id] != '') {
        ?>
        ShowHide('HiddenDiv2');
        <?php
        break;
    }
}
?>

</script>

<!-- End Center Content -->

<?php
noShellFoot(true);

/**
 * Display form fields for one principal
 *
 * @param integer $idx    Index number of principal to display
 * @param boolean $spacer (optional) Add a spacer above
 *
 * @global array $spv Holds valeus for all principals
 * @return void
 */
function echoKP($idx, $spacer=1)
{
    global $spv, $formValidationCls, $percentTitle; // session principal values, percent title
    $prinVal = $spv['principal' . $idx];
    // Load up handlers for Owner Percent textbox.
    $decPlc = $formValidationCls->getPercentDecPlc();
    $percentDefault = bcadd('0', '0', $decPlc);
    $ownPercentSize = (($decPlc > 0) ? 4 + $decPlc : 3);
    $ownPercentKey = 'tf_p' . $idx . 'OwnPercent';
    $ownPercentRec = $spv['p' . $idx . 'OwnPercent'];
    $ownPercentVal = $percentDefault;
    if (isset($_POST[$ownPercentKey]) && !empty($_POST[$ownPercentKey])) {
        $ownPercentVal = htmlspecialchars($_POST[$ownPercentKey], ENT_QUOTES, 'UTF-8');
    } elseif ($ownPercentRec) {
        $ownPercentVal = $formValidationCls->formatPercent($ownPercentRec);
    }
    $pRelVal = $spv['pRelationship' . $idx];

    $ck = ' checked="checked"';

    $ckOwner      = ($spv['bp' . $idx . 'Owner']) ? $ck : '';
    $ckKeyMgr     = ($spv['bp' . $idx . 'KeyMgr']) ? $ck : '';
    $ckBoardMem   = ($spv['bp' . $idx . 'BoardMem']) ? $ck : '';
    $ckKeyConsult = ($spv['bp' . $idx . 'KeyConsult']) ? $ck : '';
    $ckUnknown    = ($spv['bp' . $idx . 'Unknown']) ? $ck : '';

    if ($spacer) {
        echo '    <tr><td colspan="4" class="tr-spacer">&nbsp;</td></tr>' . "\n";
    }

    echo <<<EOT

<tr>
    <td style="width: 175px;"><div class="pmt">Principal $idx:</div></td>
    <td><input class="pmtInp no-trsl" name="tf_principal{$idx}" type="text" id="tf_principal{$idx}"
        size="40" maxlength="255" value="$prinVal" /></td>
    <td><input name="cb_bp{$idx}Owner" type="checkbox" id="cb_bp{$idx}Owner"
        value="1"$ckOwner /><label for="cb_bp{$idx}Owner">Owner</label>
        <input class="cudat-tiny input-sm" name="tf_p{$idx}OwnPercent" type="text"
        id="tf_p{$idx}OwnPercent" size="5" maxlength="$ownPercentSize"
        title="$percentTitle" alt="$percentTitle"
        value="$ownPercentVal" />%</td>
    <td><input name="cb_bp{$idx}KeyMgr" type="checkbox" id="cb_bp{$idx}KeyMgr"
        value="1"$ckKeyMgr /><label for="cb_bp{$idx}KeyMgr">Key Manager</label></td>
</tr>

<tr>
    <td><div class="pmt">Relationship/Title:</div></td>
    <td><input class="pmtInp no-trsl" name="tf_pRelationship{$idx}" type="text"
        id="tf_pRelationship{$idx}" size="40" maxlength="255" value="$pRelVal" /></td>
    <td><input name="cb_bp{$idx}BoardMem" type="checkbox" id="cb_bp{$idx}BoardMem"
        value="1"$ckBoardMem /><label for="cb_bp{$idx}BoardMem">Board Member</label></td>
    <td><input name="cb_bp{$idx}KeyConsult" type="checkbox" id="cb_bp{$idx}KeyConsult"
        value="1"$ckKeyConsult /><label for="cb_bp{$idx}KeyConsult">Key Consultant</label>
        <input name="cb_bp{$idx}Unknown" type="checkbox" id="cb_bp{$idx}Unknown"
        value="1"$ckUnknown /><label for="cb_bp{$idx}Unknown">Unknown</label></td>
</tr>

EOT;

}

?>
