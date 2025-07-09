<?php
/**
 * Update principals in subjectInfoDD record
 *
 * Data is saved only to a session variable here.
 */

if (!isset($_GET['id'])) {
    exit('Invalid access.');
}

require_once __DIR__ . '/../includes/php/cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/funcs_cases.php';
require_once __DIR__ . '/../includes/php/class_db.php';
require_once __DIR__ . '/../includes/php/class_formValidation.php';
require_once __DIR__ . '/../includes/php/class_globalCaseIndex.php';
require_once __DIR__ . '/../includes/php/class_all_sp.php';

$percentTitle = $accCls->trans->codeKey('owner_without_percent');
$formValidationCls = new formValidation('principal');

$e_clientID = $clientID = $_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);

$e_caseID = $caseID = intval($_GET['id']);
$caseRow = fGetCaseRow($caseID);

$siRow   = $dbCls->fetchArrayRow("SELECT * FROM subjectInfoDD "
    . "WHERE caseID = '$e_caseID' AND clientID = '$e_clientID' LIMIT 1", MYSQLI_ASSOC
);
if (!$caseRow
    || !$siRow
    || $session->secure_value('userClass') == 'vendor'
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    exit;
}
$caseType = $caseRow[3];
$offerOsiOnPrincipals = (new ServiceProvider())->optionalScopeOnPrincipals($caseType, $clientID);

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

// Get values for principals
// from subjectInfoDD record
// Clean it up
$spv = []; // principal values
$fieldsetCounter = 1;
$fieldCounter = 1;
$prncplsData = [];
foreach ($prinVars as $varName) {
    if ($fieldCounter == 1) {
        $prncpl = new stdClass();
    }
    $prefix = (str_starts_with($varName, 'bp')) ? 'cb_': 'tf_';
    if ($prefix == 'cb_') {
        $value = 0;
    } else {
        $value = '';
    }
    switch ($varName) {
    case 'principal' . $fieldsetCounter:
        if (isset($siRow[$varName])) {
            $value = trim((string) $siRow[$varName]);
        }
        $prncpl->pName = $value;
        break;
    case 'pRelationship' . $fieldsetCounter:
        if (isset($siRow[$varName])) {
            $value = trim((string) $siRow[$varName]);
        }
        break;
    case 'p' . $fieldsetCounter . 'OwnPercent':
        if (isset($siRow[$varName])) {
            $value = $formValidationCls->formatPercent($siRow[$varName]);
        }
        $prncpl->pOwnPercent = $value;
        break;
    case 'bp' . $fieldsetCounter . 'Owner':
        if (isset($siRow[$varName])) {
            $value = intval($siRow[$varName]);
        }
        $prncpl->bpOwner = $value;
        break;
    case 'bp' . $fieldsetCounter . 'KeyMgr':
        if (isset($siRow[$varName])) {
            $value = intval($siRow[$varName]);
        }
        $prncpl->bpKeyMgr = $value;
        break;
    case 'bp' . $fieldsetCounter . 'BoardMem':
        if (isset($siRow[$varName])) {
            $value = intval($siRow[$varName]);
        }
        $prncpl->bpBoardMem = $value;
        break;
    case 'bp' . $fieldsetCounter . 'KeyConsult':
        if (isset($siRow[$varName])) {
            $value = intval($siRow[$varName]);
        }
        $prncpl->bpKeyConsult = $value;
        break;
    case 'bp' . $fieldsetCounter . 'Unknown':
        if (isset($siRow[$varName])) {
            $value = intval($siRow[$varName]);
        }
        $prncpl->bpUnknown = $value;
        break;
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

if (isset($_POST['Submit'])) {

    if (!PageAuth::validToken('edprinAuth', $_POST['edprinAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "editprincipals.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }
    $sysEmail = 0;

    switch ($_POST['Submit']) {
    case 'Save and Continue';
        $passID = $caseID;
        $nextPage = "editsubinfodd.php";
        break;
    case 'Cancel';
        $passID = $caseID;

        if (isset($_GET['bFromKP']) && $_GET['bFromKP']) {
            echo '<script type="text/javascript">' . PHP_EOL
                . 'top.window.location.replace("casehome.sec");' . PHP_EOL
                . '</script>"';
        } else {
            $nextPage = "editsubinfodd.php";
        }

        session_write_close();
        header("Location: $nextPage?id=$passID");
        exit;
        //break;

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
        // Set all values to blank if the principal name is blank. This is effectively a 'delete'.
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

    $validationErrors = $formValidationCls->validatePrincipals($prncplsData);
    if (is_array($validationErrors) && $validationErrors['totalErrors'] == 0) {
        // Retrieve principals data from sess vars
        $prinVals = [];
        foreach ($spv as $varName => $val) {
            $e_val = $dbCls->esc($val);
            $prinVals[] = "$varName = '$e_val'";
        }
        $prinList = join(", \n", $prinVals);

        $sql = "UPDATE subjectInfoDD SET $prinList "
            . "WHERE caseID = '$e_caseID' AND clientID = '$e_clientID' LIMIT 1";

        $dbCls->query($sql);

        // sync global index
        $globalIdx->syncByCaseData($caseID);

        // Redirect user to
        if (isset($_GET['bFromKP']) && $_GET['bFromKP']) {
            echo '<script type="text/javascript">' . PHP_EOL
                . 'top.window.location.replace("casehome.sec");' . PHP_EOL
                . '</script>"';
            exit;
        } else {
            header("Location: $nextPage?id=$passID");
        }
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

// form action
$action = $_SERVER['PHP_SELF']. '?id=' . $caseID;
if (isset($_GET['bFromPK'])) {
    $_GET['bFromPK'] = htmlspecialchars($_GET['bFromPK'], ENT_QUOTES, 'UTF-8');
    $action .= '&bFromPK=' . $_GET['bFromPK'];
}

$pageTitle = "Create New Case Folder";
shell_head_Popup();
$edprinAuth = PageAuth::genToken('edprinAuth');
?>

<!--Start Center Content-->
<script type="text/javascript">
function submittheform ( selectedtype )
{
    document.editsubinfoddform.Submit.value = selectedtype ;
    document.editsubinfoddform.submit() ;
    return false;
}
<?php echo $formValidationCls->unpackInputSetJs('functions'); ?>
</script>

<form name="editsubinfoddform" class="cmsform" action="<?php echo $action; ?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="edprinAuth" value="<?php echo $edprinAuth; ?>" />
<?php
echo ((isset($validationErrors) && is_array($validationErrors))
    ?  $formValidationCls->getValidationErrors($validationErrors)
    : ''
);
?>
<div class="stepFormHolder">
<table border="0" cellspacing="0" cellpadding="0">
<tr>
    <td><h6 class="formTitle">Edit | Add Principals</h6></td>
</tr>
</table>


<table border="0" cellspacing="0" cellpadding="0">
<?php
if ($offerOsiOnPrincipals) {
    ?>
    <tr>
        <td colspan="4"><div class="errormsg paddiv"><br />
        The scope includes field investigation on the Corporate entity.<br />
        Additional costs may apply for any  principals entered below.</div></td>
    </tr>
    <?php
} elseif ($caseType == DUE_DILIGENCE_IBI) {
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
<tr id="HiddenDiv-ctrl"><td height="25" colspan="1"
    align="center"><a  href="javascript:void(0)"
    onclick="return ShowHide('HiddenDiv')" class="btn btn-light"><b>+ Add Three More Principals </b>&nbsp;</a> </td></tr>
</table>

<!--Start Princ 5 - 7 -->

<div class="fw-normal disp-none" id="HiddenDiv">
<table border="0" cellspacing="0" cellpadding="0">

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
<div class="fw-normal disp-none" id="HiddenDiv2">
<table border="0" cellspacing="0" cellpadding="0">

<?php
for ($i = 8; $i <= 10; $i++) {
    echoKP($i);
}
?>

</table>
</div>

<table border="0" cellpadding="0" cellspacing="8" class="paddiv" >
<tr><td colspan="2">&nbsp;</td></tr>
<tr>
    <td width="71" nowrap="nowrap" class="submitLinks">
        <a href="javascript:void(0)"
        onclick="return submittheform('Save and Continue')" >Update</a> </td>
    <td width="86" nowrap="nowrap" class="submitLinksCancel"><a href="javascript:void(0)"
        onclick="return submittheform('Cancel')" >Cancel</a></td>
</tr>
</table>
</div>
</form>

<?php // ----- Do Not Move! ----- // ?>
<script type="text/javascript">
function ShowHide(divId){
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
    <td><div class="pmt">Principal $idx:</div></td>
    <td><input class="pmtInp" name="tf_principal{$idx}" type="text" id="tf_principal{$idx}"
        size="40" maxlength="255" value="$prinVal" /></td>
    <td><input name="cb_bp{$idx}Owner" type="checkbox" id="cb_bp{$idx}Owner"
        value="1"$ckOwner /><label for="cb_bp{$idx}Owner">Owner</label>
        <input class="cudat-tiny " name="tf_p{$idx}OwnPercent" type="text"
        id="tf_p{$idx}OwnPercent" size="5" maxlength="$ownPercentSize"
        title="$percentTitle" alt="$percentTitle"
        value="$ownPercentVal" />%</td>
    <td><input name="cb_bp{$idx}KeyMgr" type="checkbox" id="cb_bp{$idx}KeyMgr"
        value="1"$ckKeyMgr /><label for="cb_bp{$idx}KeyMgr">Key Manager</label></td>
</tr>

<tr>
    <td><div class="pmt">Relationship/Title:</div></td>
    <td><input class="pmtInp" name="tf_pRelationship{$idx}" type="text"
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
