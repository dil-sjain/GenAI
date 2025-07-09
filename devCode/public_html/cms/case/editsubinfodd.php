<?php
/**
 * Edit subjectInfoDD record
 */

if (!isset($_GET['id'])) {
    exit('Access denied');
}

require_once __DIR__ . '/../includes/php/cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('updtCase')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/funcs.php';
require_once __DIR__ . '/../includes/php/class_db.php';
require_once __DIR__ . '/../includes/php/class_globalCaseIndex.php';
require_once __DIR__ . '/../includes/php/class_all_sp.php';
$e_caseID = $caseID = intval($_GET['id']);
$caseRow    = fGetCaseRow($caseID);
$subinfoRow = fGetSubInfoDDRow($caseID);
if (!$caseRow
    || !$subinfoRow
    || $caseRow['caseStage'] >= ASSIGNED
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    exit('Access denied');
}

if(isset($_SESSION['originSource'])) {
    unset($_SESSION['originSource']);
}

$e_clientID = $clientID = (int)$_SESSION['clientID'];
$caseType = $caseRow['caseType'];
$offerOsiOnPrincipals = (new ServiceProvider())->optionalScopeOnPrincipals($caseType, $clientID);
$globalIdx = new GlobalCaseIndex($clientID);
$languageCode = $_SESSION['languageCode'] ?? 'EN_US';
require_once __DIR__ . '/../includes/php/Models/Globals/Geography.php';
$geo = \Legacy\Models\Globals\Geography::getVersionInstance(null, null, $clientID);

// Define form vars
$postVarMap = ['tf_' => ['name', 'street', 'addr2', 'city', 'postCode', 'DBAname'], 'lb_' => ['subType', 'legalForm', 'state', 'country'], 'rb_' => ['subStat', 'SBIonPrincipals']];

// Define vars for principals
$prinVars = [];
$prinLimit = 10;
for ($i = 1; $i <= $prinLimit; $i++) {
    $prinVars[] = 'principal'     . $i;
    $prinVars[] = 'pRelationship' . $i;
}

require __DIR__ . '/../includes/php/'.'dbcon.php';

if (isset($_POST['Submit'])) {
    switch ($_POST['Submit']) {
        case 'Edit Principals':
            $_SESSION['subinfoHold2'] = $_POST; // save to sess vars
            session_write_close();
            header("Location: editprincipals.php?id=$caseID");
            exit;
        //break;

        case 'Do not save | Quit':
            // Redirect user to
            session_write_close();
            header("Location: casehome.sec");
            exit;
        //break;

        case 'Return to Step 1':
            $passID = $caseID;
            $nextPage = "editcase.php";
            break;

        case 'Save and Continue':
            // Initialize our next location
            $passID = $caseID;
            $nextPage = "editsubinfodd_pt2.php";
            break;

        case 'Save and Quit':
            $passID = $_SESSION['clientID'];
            $nextPage = "casehome.sec";
            break;
    }  //end switch

    $_SESSION['subinfoHold2'] = $_POST; // save to sess vars

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

    // Clear error counter
    $iError = 0;

    // If continuing to next step, validate data
    if ($_POST['Submit'] == 'Save and Continue') {
        // Check for a Complete Subject Name
        if (trim((string) $tf_name) == '') {
            $_SESSION['aszErrorList'][$iError++]
                = "You must enter a Subject Name for this investigation.";
        }
        // Check for an Address
        if (trim((string) $tf_street) == '') {
            $_SESSION['aszErrorList'][$iError++]
                = "You must enter a Street Address for this Subject.";
        }
        // Check for a city
        if (trim((string) $tf_city) == '') {
            $_SESSION['aszErrorList'][$iError++] = "You must enter a City for this Subject.";
        }
        //Check Country
        if (trim((string) $lb_country) == '') {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a " . $accCls->trans->codeKey('country') . ".";
        }
        // If EDD-based, make sure the Field investigation on Principals question is checked
        if ($offerOsiOnPrincipals && locHasPrincipals2()) {
            if ($rb_SBIonPrincipals != 'Yes' && $rb_SBIonPrincipals != 'No') {
                $_SESSION['aszErrorList'][$iError++] = "You must tell us if you want "
                    . "the Field Investigation performed on the Principals.";
            }
        }
    }

    // any errors?
    if ($iError) {
        $errorUrl = "editsubinfodd.php?id=" . $caseID;
        session_write_close();
        header("Location: $errorUrl");
        exit;
    }

    $fields = [$e_tf_name, $e_tf_street, $e_tf_addr2, $e_tf_city, $e_tf_postCode];
    $sanitizedFields = array_map(fn($field) => safeHtmlSpecialChars($field), $fields);

    // Reassign sanitized values to the original variables
    list($e_tf_name, $e_tf_street, $e_tf_addr2, $e_tf_city, $e_tf_postCode) = $sanitizedFields;

    $sql = "UPDATE subjectInfoDD SET "
        . "name      = '$e_tf_name', "
        . "street    = '$e_tf_street', "
        . "addr2     = '$e_tf_addr2', "
        . "city      = '$e_tf_city', "
        . "state     = '$e_lb_state', "
        . "subType   = '$e_lb_subType', "
        . "subStat   = '$e_rb_subStat', "
        . "DBAname   = '$e_tf_DBAname', "
        . "country   = '$e_lb_country', "
        . "postCode  = '$e_tf_postCode', "
        . "legalForm ='$e_lb_legalForm', "
        . "SBIonPrincipals = '$e_rb_SBIonPrincipals' "
        . "WHERE caseID = '$e_caseID' AND clientID = '$e_clientID' LIMIT 1";

    $result = mysqli_query(MmDb::getLink(), $sql);
    if (!$result) {
        exitApp(__FILE__, __LINE__, $sql);
    }

    // sync global index
    $globalIdx->syncByCaseData($caseID);

    unset($_SESSION['prinHold']);
    unset($_SESSION['subinfoHold']);
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
 *  3. Always read principals data from fresh copy of record
 */

$subinfoRow = fGetSubInfoDDRow($caseID);
if (isset($_SESSION['subinfoHold2'])) {
    $sih = $_SESSION['subinfoHold2'];
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
    unset($_SESSION['subinfoHold2']);
} else {
    // Create normal vars from subinfo record
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            $pVarName = $prefix . $varName;
            ${$pVarName} = $subinfoRow[$varName];
        }
    }
}

// select the radio button

$ck = ' checked="checked"';
//subStat
$PROSchecked = '';
$CURchecked  = '';
if ($rb_subStat == "Prospective") {
    $PROSchecked = $ck;
}
if ($rb_subStat == "Current") {
    $CURchecked  = $ck;
}
// SBIonPrincipals
$sbiYesChecked = '';
$sbiNoChecked  = '';
if ($rb_SBIonPrincipals == 'Yes') {
    $sbiYesChecked = $ck;
}
if ($rb_SBIonPrincipals == 'No') {
    $sbiNoChecked  = $ck;
}

$lb_country = $lb_country ?? '';
$lb_state = $lb_state ?? '';
$szCountry = $geo->getLegacyCountryCode($lb_country);
if (empty($szCountry)) {
    $szCountry = $lb_country;
}
$szState = $geo->getLegacyStateCode($lb_state, $szCountry);
if (empty($szState)) {
    $szState = $lb_state;
}
// Something is getting lost in the js, so this is a dirty workaround
$_SESSION['ecCountryCode'] = $szCountry;
$_SESSION['ecStateCode'] = $szState;
$e_szCountry = $dbCls->esc($szCountry);
$e_szState = $dbCls->esc($szState);
$szCountryText = $geo->getCountryNameTranslated($szCountry, $languageCode);
$szStateText = $geo->getStateNameTranslated($szCountry, $szState, $languageCode);

$loadYUImodules = ['cmsutil', 'linkedselects'];
$pageTitle = "Create New Case Folder";
shell_head_Popup();
?>

<!--Start Center Content-->
<script language="JavaScript" type="text/javascript">
    var $caseErrors;
    $(document).ready(function() {
        $caseErrors = $('#caseErrors');
    });

function submittheform(selectedtype)
{
    var country = $('#lb_country').val();

    $.ajax({
        type: "POST"
        ,url: '/tpm/addCase'
        ,data: {
            op: 'checkCountry'
            ,country : country
        }
    }).done(function(res) {
        var code = res.Args.code;
        var msg  = res.Args.message;

        if (selectedtype == 'Return to Step 1') {
            document.editsubinfoddform.Submit.value = selectedtype ;
            document.editsubinfoddform.submit() ;
        } else if (code === 's') {
            $caseErrors.jqxWindow({
                resizable: false,
                width: 410,
                height: 280,
                theme: 'classic',
                autoOpen: false,
                initContent: function() {
                    $('#errorPanel').jqxPanel({
                        width: 400,
                        sizeMode: 'wrap',
                        theme: 'classic'
                    });
                    $('#ok').jqxButton({
                        width: '65px'
                    });
                    $('#cancel').jqxButton({
                        width: '65px'
                    });
                    $('#ok').focus();
                }
            });
            $caseErrors.jqxWindow({ okButton: $('#ok') });
            $caseErrors.jqxWindow({ cancelButton: $('#cancel') });
            $caseErrors.jqxWindow('setTitle', 'Notice');
            $('#errorPanel').html(msg);
            $caseErrors.jqxWindow('open');

            $caseErrors.on('close', function(e) {
                if (e.args.dialogResult.OK) {
                    $caseErrors.off();
                    $(this).jqxWindow('destroy');
                    var frm = document.editsubinfoddform;
                    frm.Submit.value = selectedtype ;
                    frm.submit();
                } else {
                    $(this).jqxWindow('destroy');
                }
            });
        } else if (code === 'p') {
            $caseErrors.jqxWindow({
                resizable: false,
                width: 410,
                height: 280,
                autoOpen: false,
                initContent: function() {
                    $('#errorPanel').jqxPanel({
                        width: 400,
                        sizeMode: 'wrap',
                        theme: 'classic'
                    });
                    $('#ok').jqxButton({
                        width: '65px'
                    });
                    $('#cancel').remove();
                    $('#ok').focus();
                }
            });
            $caseErrors.jqxWindow({ okButton: $('#ok') });
            $caseErrors.jqxWindow('setTitle', 'Notice');
            $('#errorPanel').html(msg);
            $caseErrors.jqxWindow('open');

            <?php // Always close window if prohibited (no submitting) ?>
            $caseErrors.on('close', function(e) {
                $caseErrors.off();
                $(this).jqxWindow('destroy');
                top.onHide();
                return false;
            });
        } else {
            document.editsubinfoddform.Submit.value = selectedtype ;
            document.editsubinfoddform.submit() ;
        }

        return false;
    });
}
function explainText(theMessage)
{
    return;
}
</script>
<div id="caseErrors" style="display:none">
    <div>Header</div>
    <div>
        <div id="errorPanel"></div>
        <input type="button" id="ok" value="Accept" style="margin-right: 10px" />
        <input type="button" id="cancel" value="Cancel" style="margin-right: 10px" />
    </div>
</div>
<h6 class="formTitle">due diligence key info form - company </h6>

<form name="editsubinfoddform" class="cmsform"
    action="<?php echo $_SERVER['PHP_SELF']?>?id=<?php echo $caseID; ?>" method="post" >
<input type="hidden" name="Submit" />
<div class="formStepsPic2"><img src="../images/formSteps2.gif"
    width="231" height="30" border="0" /></div>
<div class="stepFormHolder">

<table width="890" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td width="406" height="141" valign="top">&nbsp;

    <table width="406" border="0" cellspacing="0" cellpadding="0" class="paddiv">
    <tr>
        <td><b><span class="style1">Type</span></b></td>
        <td>&nbsp;</td>
    </tr>
    <tr>
        <td width="190"><div align="right"><strong>Status:</strong></div></td>
        <td width="216"><input type="radio" name="rb_subStat" value="Prospective"<?php
            echo $PROSchecked; ?> /> Prospective
            <input type="radio" name="rb_subStat" value="Current"<?php
            echo $CURchecked; ?> /> Current</td>
    </tr>
    <tr>
        <td width="190"><div align="right"><strong>Relationship Type:</strong></div></td>
        <td width="216">
        <?php
        fCreateDDLfromDB("lb_subType", "relationshipType", $lb_subType, 0, 1);
        ?></td>
    </tr>

    <tr>
        <td width="190"><div align="right"><strong>Legal Form of Company: </strong></div></td>
        <td width="216">
        <?php
        fCreateDDLfromDB("lb_legalForm", "companyLegalForm", $lb_legalForm, 0, 1);
        ?></td>
    </tr>
    <tr>
        <td colspan="2"><div id="txtFieldDiv"></div></td>
    </tr>
    </table></td>

        <td width="445" valign="top">

    <table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td width="17%"><div align="right" class="style3 paddiv"><b><span
            class="style1">Principals</span></b></div></td>
        <td width="30%">&nbsp;</td>
        <td>&nbsp;</td>
        <td align="right"><a href="javascript:void(0)"
            onclick="return submittheform('Edit Principals')"><strong>Add | Edit</strong></a></td>
    </tr>
    <tr>
        <td colspan="4"><hr align="center" width="100%" size="1" noshade="noshade" /></td>
    </tr>
<?php
// display first 4 and any non-empty principals
for ($i = 1; $i <= $prinLimit; $i++) {
    $prinKey = 'principal' . $i;
    $prinVal = '';
    if (array_key_exists($prinKey, $subinfoRow)) {
        $prinVal = trim((string) $subinfoRow[$prinKey]);
    }
    if ($i > 4 && !$prinVal) {
        continue; // skip empty principals after 4
    }
    $prelKey = 'pRelationship' . $i;
    $prelVal = '';
    if (array_key_exists($prelKey, $subinfoRow)) {
        $prelVal = trim((string) $subinfoRow[$prelKey]);
    }
    echo <<<EOT
    <tr>
        <td><div align="right" class="paddiv no-wrap">Principal $i:</div></td>
        <td class="no-trsl">$prinVal &nbsp;</td>
        <td colspan="2" nowrap="nowrap"><strong>Relationship/Title: </strong><span class="no-trsl">$prelVal</span></td>
    </tr>
EOT;
}

?>
    <tr>
        <td colspan="4">&nbsp;</td>
    </tr>
    </table></td>

    </tr>
</table>

<!--Start middle section-->

<table width="890" border="0" cellspacing="0" cellpadding="0" height="233" class="paddiv">
    <tr>
        <td colspan="2"><b><span class="style1">Company Identity</span></b></td>
<?php
if ($offerOsiOnPrincipals) {
    ?>
        <td><span class="style3">Do you want to include the Principals in the
            field investigation </span></td>
    <?php
} else {
    ?>
        <td>&nbsp;</td>
    <?php
} ?>
    </tr>
    <tr>
        <td width="112"><div align="right" class="style3">Company Name:</div></td>
        <td width="293"><input name="tf_name" type="text" id="tf_name" size="40"
            maxlength="255" value="<?php echo $tf_name; ?>" tabindex="5" /></td>

<?php
if ($offerOsiOnPrincipals) {
    ?>
        <td><span class="style3">for an additional charge?</span>
            <input name="rb_SBIonPrincipals" type="radio" id="rb_SBIonPrincipalsYes"
            value="Yes"<?php echo $sbiYesChecked; ?> />
            <label for="rb_SBIonPrincipalsYes">Yes</label>
            <input name="rb_SBIonPrincipals" type="radio" id="rb_SBIonPrincipalsNo"
            value="No" <?php echo $sbiNoChecked; ?> />
            <label for="rb_SBIonPrincipalsNo">No</label></td>
    <?php
} else {
    ?>
         <td>&nbsp;</td>
    <?php
} ?>
    </tr>
    <tr>
        <td width="112"><div align="right">Alternate Trade Name(s):</div></td>
        <td width="293"><input name="tf_DBAname" type="text" id="tf_DBAname" size="40"
            maxlength="255" value="<?php echo $tf_DBAname; ?>" /></td>
        <td width="485" >&nbsp;</td>
    </tr>

    <tr>

        <td width="112"><div align="right" class="style3">Address 1:</div></td>
        <td width="293"><input name="tf_street" type="text" id="tf_street" size="40"
            maxlength="255" value="<?php echo $tf_street; ?>" tabindex="6" /></td>
        <td width="485" rowspan="6" valign="top">&nbsp;</td>
    </tr>
    <tr>

        <td width="112"><div align="right">Address 2: </div></td>
        <td><input name="tf_addr2" type="text" id="tf_addr2" size="40" maxlength="255"
            value="<?php echo $tf_addr2; ?>" tabindex="7" /></td>
    </tr>

    <tr>

        <td width="112"><div align="right" class="style3">City:</div></td>
        <td><input name="tf_city" type="text" id="tf_city" size="40" maxlength="255"
            value="<?php echo $tf_city; ?>" tabindex="8" /></td>
    </tr>
    <tr>

        <td width="112"><div align="right" class="style3"><?php echo $accCls->trans->codeKey('country') ?>:</div></td>
        <td><select id="lb_country" name="lb_country" class="cudat-medium">
            <option value="<?php echo $szCountry; ?>"
            selected="selected"><?php echo $szCountryText; ?></option>
            </select></td>
    </tr>
    <tr>

        <td width="112"><div align="right">State/Province:</div></td>
        <td><select id="lb_state" name="lb_state" class="cudat-medium">
            <option value="<?php echo $szState; ?>"
                selected="selected"><?php echo $szStateText; ?></option>
            </select></td>
    </tr>

    <tr>
        <td width="112"><div align="right">Postcode: </div></td>
        <td><input name="tf_postCode" type="text" id="tf_postCode" size="15"
            maxlength="40" value="<?php echo $tf_postCode; ?>" tabindex="11" /></td>
    </tr>
</table>

<table width="585" border="0" cellpadding="0" cellspacing="8" class="paddiv" >
    <tr>
        <td width="103" nowrap="nowrap" class="submitLinksBack"><a href="javascript:void(0)"
            onclick="return submittheform('Return to Step 1')">Go Back | Edit</a></td>
        <td width="163" nowrap="nowrap" class="submitLinks"><a href="javascript:void"
            onclick="return submittheform('Save and Continue')"
            tabindex="24" >Continue | Enter Details</a> </td>
        <td width="114" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Save and Quit')">Save and Close</a></td>
        <td width="165" nowrap="nowrap" class="submitLinksCancel paddiv"><a href="javascript:;"
            onclick="top.onHide();" >Cancel</a></td>
    </tr>
</table>
</div>
</form>

<!--   End Center Content -->

<script type="text/javascript">
YAHOO.util.Event.onDOMReady(function(){
    <?php // Country/State control ?>
    var c1 = new YAHOO.cms.LinkedSelects('cs1', {
        type: 'cs',
        primary: 'lb_country',
        secondary: 'lb_state',
        primaryPrompt: {v:'', t:"<?php echo $geo->translateCodeKey($languageCode, 'Choose...');?>"},
        secondaryPrompt: {v:'', t:"<?php echo $geo->translateCodeKey($languageCode, 'Choose...');?>"},
        secondaryEmpty: {v:'N', t:"<?php echo $geo->translateCodeKey($languageCode, 'Choose...');?>"}
    });
    c1.init("<?php echo $szCountry?>","<?php echo $szState?>");
});
</script>

<?php
noShellFoot(true);

/**
 * Case has one or more principals in subjectInfoDD
 *
 * @return bool
 */
function locHasPrincipals2()
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
