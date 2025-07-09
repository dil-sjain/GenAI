<?php
/**
 * Add new subjectInfoDD record from manual input
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
require_once __DIR__ . '/../includes/php/Models/Globals/Geography.php';
require_once __DIR__ . '/../includes/php/class_all_sp.php';
$e_caseID = $caseID = intval($_GET['id']);
$caseRow = fGetCaseRow($caseID);
if (!$caseRow
    || $caseRow['caseStage'] >= ASSIGNED
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    exit('Access denied');
}

if(isset($_SESSION['addPrincipalsSource'])) {
    unset($_SESSION['addPrincipalsSource']);
}

// Go to edit if subjectInfoDD record exists
if (fGetSubInfoDDRow($caseID)) {
    session_write_close();
    header("Location: editsubinfodd.php?id=$caseID");
    exit;
}
require_once __DIR__ . '/../includes/php/Models/Globals/Geography.php';

$e_clientID = $clientID = (int)$_SESSION['clientID'];
$geo = \Legacy\Models\Globals\Geography::getVersionInstance(null, null, $clientID);
$caseType = $caseRow['caseType'];
$offerOsiOnPrincipals = (new ServiceProvider())->optionalScopeOnPrincipals($caseType, $clientID);
$globalIdx = new GlobalCaseIndex($clientID);
$langCode = $_SESSION['languageCode'] ?? 'EN_US';
$geography = \Legacy\Models\Globals\Geography::getVersionInstance(null, null, $clientID);

// Define form vars
$postVarMap = ['tf_' => ['name', 'street', 'addr2', 'city', 'postCode', 'DBAname'], 'lb_' => ['subType', 'legalForm', 'state', 'country'], 'rb_' => ['subStat', 'SBIonPrincipals']];

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
if (isset($_POST['Submit']) && $_POST['Submit']) {
    if (!PageAuth::validToken('addsiAuth', $_POST['addsiAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "addsubinfodd.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }
    include __DIR__ .'/../includes/php/'.'dbcon.php';

    switch ($_POST['Submit']) {
        case 'Add Principals':
            $_SESSION['subinfoHold'] = $_POST; // save to sess vars
            session_write_close();
            header("Location: addprincipals.php?id=" . $caseID);
            exit;
        // break;

        case 'Return to Step 1':
            // Get rid of 3P data
            unset($_SESSION['profileRow3P']);
            $nextPage = "editcase.php";
            break;

        case 'Save and Continue':
            $nextPage = "editsubinfodd_pt2.php";
            break;

        case 'Save and Quit':
            if (isset($_SESSION['profileRow3P'])) {
                unset($_SESSION['profileRow3P']);
                $nextPage = "thirdparty_home.sec";
            } else {
                $nextPage = "casehome.sec";
            }
            break;
    }

    $_SESSION['subinfoHold'] = $_POST; // save to sess vars

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

    // If continueing to next step, validate data
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
        if ($offerOsiOnPrincipals && locHasPrincipals()) {
            if ($rb_SBIonPrincipals != 'Yes' && $rb_SBIonPrincipals != 'No') {
                $_SESSION['aszErrorList'][$iError++] = "You must tell us if you want "
                    . "the Field Investigation performed on the Principals.";
            }
        }
    }


    // any errors?
    if ($iError) {
        session_write_close();
        $errorUrl = "addsubinfodd.php?id=" . $caseID;
        header("Location: $errorUrl");
        exit;
    }

    // Retrieve principals data from sess vars set in addprincipals
    $prinVals = [];
    $sessPrinVars = [];
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
                $value = trim((string) $sessPrinVars[$varName]);
            }
        }
        $prinVals[] = $value;
    }
    $prinFldList = join(', ', $prinVars);
    $prinValList = "'" . join("', '", $prinVals) . "'";

    $fields = [$e_tf_name, $e_tf_street, $e_tf_city, $e_tf_addr2, $e_tf_postCode];
    $sanitizedFields = array_map(fn($field) => safeHtmlSpecialChars($field), $fields);

    // Reassign sanitized values back to the original variables
    list($e_tf_name, $e_tf_street, $e_tf_city, $e_tf_addr2, $e_tf_postCode) = $sanitizedFields;

    try {
        $sql = 'INSERT INTO subjectInfoDD ('
            . 'caseID, clientID, subType, subStat, legalForm, '
            . 'name, street, city, state, country, '
            . 'SBIonPrincipals, addr2, postCode, DBAname, '
            . $prinFldList
            . ') VALUES ('
            . "'$e_caseID', '$e_clientID', '$e_lb_subType', '$e_rb_subStat', '$e_lb_legalForm', "
            . "'$e_tf_name', '$e_tf_street', '$e_tf_city', '$e_lb_state', '$e_lb_country', "
            . "'$e_rb_SBIonPrincipals', '$e_tf_addr2', '$e_tf_postCode', '$e_tf_DBAname', "
            . $prinValList
            . ')';
        $result = mysqli_query(MmDb::getLink(), $sql);
    } catch (Exception $e) {
        debugTrack([
          'Insert SQL' => $sql,
          'Failed to insert subject info DD' => $ex->getMessage(),
        ]);
    }
    if (!$result) {
        exitApp(__FILE__, __LINE__, $sql);
    }

    $globalIdx->syncByCaseData($e_caseID);

    // Cleear sess vars
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            unset($_SESSION[$varName]);
        }
    }
    unset($_SESSION['prinHold']);
    unset($_SESSION['subinfoHold']);
    unset($_SESSION['subinfoHold2']);
    unset($_SESSION['subinfo_pt2Hold']);

    // Redirect
    if ($_POST['Submit'] == 'Save and Quit') {
        if (isset($_SESSION['profileRow3P'])) {
            $dest = $sitepath . 'thirdparty/thirdparty_home.sec';
            unset($_SESSION['profileRow3P']);
        } else {
            $dest = $sitepath . 'case/casehome.sec';
        }
        echo '<script type="text/javascript">' . PHP_EOL
            . 'top.window.location.replace("' . $dest . '");' . PHP_EOL
            . '</script>' . PHP_EOL;
    } else {
        $dest = "$nextPage?id=$caseID";
        session_write_close();
        header("Location: $dest");
    }
    exit;
}

// -------- Show Form

/*
 * Data source precedence:
 *  1. use sess vars if persent
 *  2. use sess profileRow3P row if present
 *  3. use default values
 */

if (isset($_SESSION['subinfoHold'])) {
    $sih = $_SESSION['subinfoHold'];
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
} else {
    if (isset($_SESSION['profileRow3P'])) {
        // from 3P association
        $pro = $_SESSION['profileRow3P'];
        $lb_legalForm = $pro->legalForm;
        $tf_name      = $pro->legalName;
        $tf_street    = $pro->addr1;
        $tf_city      = $pro->city;
        if (isset($_GET['country']) && isset($_GET['state'])) {
            $lb_state   = htmlspecialchars($_GET['state'], ENT_QUOTES, 'UTF-8');
            $lb_country = htmlspecialchars($_GET['country'], ENT_QUOTES, 'UTF-8');
        } else {
            $lb_state   = $pro->state;
            $lb_country = $pro->country;
        }
        $tf_addr2     = $pro->addr2;
        $tf_postCode  = $pro->postcode;
        $tf_DBAname   = $pro->DBAname;
        $rb_subStat   = '';
        $lb_subType   = '';
        $rb_SBIonPrincipals = '';
        unset($pro);
    } else {
        // Create normal vars with default values
        foreach ($postVarMap as $prefix => $vars) {
            foreach ($vars as $varName) {
                $pVarName = $prefix . $varName;
                ${$pVarName} = '';
            }
        }
        if (isset($_GET['country']) && isset($_GET['state'])) {
            $lb_state   = htmlspecialchars($_GET['state'], ENT_QUOTES, 'UTF-8');
            $lb_country = htmlspecialchars($_GET['country'], ENT_QUOTES, 'UTF-8');
        }
    }
}

// Make normal vars for principals and pRelationships
$sessPrinVars = [];
if (isset($_SESSION['prinHold'])) {
    $sessPrinVars = $_SESSION['prinHold'];
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
$e_lb_country = $dbCls->esc($lb_country);
$e_lb_state = $dbCls->esc($lb_state);

$szCountryText = $geography->getCountryNameTranslated($lb_country, $langCode);
$szStateText = $geography->getStateNameTranslated($e_lb_state, $langCode);

$loadYUImodules = ['cmsutil', 'linkedselects'];
$pageTitle = "Create New Case Folder";
shell_head_Popup();
$addsiAuth = PageAuth::genToken('addsiAuth');

//Sets to true if environment is aws
$awsEnabled = filter_var(getenv('AWS_ENABLED'), FILTER_VALIDATE_BOOLEAN);
?>

<!--Start Center Content-->
<script type="text/javascript">
    var $caseErrors;
    var origCountry;

    $(document).ready(function() {
        $caseErrors = $('#caseErrors');
        origCountry = $('#lb_country').val();
    });

function submittheform(selectedtype)
{
    var country = $('#lb_country').val();
    var countryChanged = false;
    if (country != origCountry) {
        countryChanged = true;
    }

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

        if (code === 's') {
            $caseErrors.jqxWindow({
                resizable: false,
                width: 410,
                height: 350,
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

            <?php // Only display notice if country changed ?>
            if (countryChanged) {
                $caseErrors.jqxWindow('open');

                $caseErrors.on('close', function(e) {
                    if (e.args.dialogResult.OK) {
                        $caseErrors.off();
                        $(this).jqxWindow('destroy');
                        document.addsubinfoddform.Submit.value = selectedtype ;
                        document.addsubinfoddform.submit() ;
                    } else {
                        $(this).jqxWindow('destroy');
                    }
                });

            } else {
                document.addsubinfoddform.Submit.value = selectedtype ;
                document.addsubinfoddform.submit() ;

            }

        } else if (code === 'p') {
            $caseErrors.jqxWindow({
                resizable: false,
                width: 410,
                height: 350,
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
            document.addsubinfoddform.Submit.value = selectedtype ;
            document.addsubinfoddform.submit() ;
        }

        return false;
    });
}

function explainText(theValue)
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

<form name="addsubinfoddform" class="cmsform"
    action="<?php echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="addsiAuth" value="<?php echo $addsiAuth; ?>" />
<div class="formStepsPic2"><img src="../images/formSteps2.gif"
    width="231" height="30" border="0" id="step1" /></div>
<div class="stepFormHolder">

<table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td width="384" valign="top">&nbsp;

    <table width="381" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td nowrap="nowrap" class="paddiv"><b><span class="style1">Type</span></b></td>
        <td nowrap="nowrap">&nbsp;</td>
    </tr>
    <tr>
        <td width="165" nowrap="nowrap" class="paddiv"><strong>Status:</strong></td>
        <td width="216"><input type="radio" name="rb_subStat" value="Prospective"<?php
            echo $PROSchecked; ?> /> Prospective
            <input type="radio" name="rb_subStat" value="Current"<?php
            echo $CURchecked; ?> /> Current</td>
    </tr>
    <tr>
        <td width="165" nowrap="nowrap" class="paddiv"><strong>Relationship Type:</strong></td>
        <td width="216" nowrap="nowrap">
        <?php
        fCreateDDLfromDB("lb_subType", "relationshipType", $lb_subType, 0, 1);
        ?></td>
    </tr>

    <tr>
        <td width="165" nowrap="nowrap" class="paddiv"><strong>Legal Form of Company</strong></td>
        <td width="216">
        <?php
        fCreateDDLfromDB("lb_legalForm", "companyLegalForm", $lb_legalForm, 0, 1);
        ?></td>
    </tr>
    <tr>
        <td colspan="2" nowrap="nowrap" class="paddiv"><div id="txtFieldDiv"></div></td>
    </tr>
    </table></td>

        <td width="445" valign="top">

    <table width="100%" border="0" cellspacing="0" cellpadding="0">
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
        $prinVal = trim((string) $sessPrinVars[$prinKey]);
    }
    if ($i > 4 && !$prinVal) {
        continue; // skip empty principals after 4
    }
    $prelKey = 'pRelationship' . $i;
    $prelVal = '';
    if (array_key_exists($prelKey, $sessPrinVars)) {
        $prelVal = trim((string) $sessPrinVars[$prelKey]);
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
    </table></td>

    </tr>
</table>

<!--Start middle section-->

<table width="890" border="0" cellspacing="0" cellpadding="0" class="paddiv">
    <tr>
        <td colspan="3"><b><span class="style1">Company Identity</span></b></td>
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
        <td width="1"></td>
        <td width="161"><div align="right" class="style3 paddiv">Company Name:</div></td>
        <td width="283"><input name="tf_name" type="text" id="tf_name" size="40"
            maxlength="255" value="<?php echo $tf_name; ?>" class="no-trsl" /></td>

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
        <td width="1"></td>
        <td width="161"><div align="right" class="paddiv">Alternate Trade Name(s):</div></td>
        <td width="283"><input name="tf_DBAname" type="text" id="tf_DBAname"
            size="40" maxlength="255" value="<?php echo $tf_DBAname; ?>" class="no-trsl" /></td>
        <td>&nbsp;</td>
    </tr>

    <tr>
        <td width="1"></td>
        <td width="161"><div align="right" class="style3 paddiv">Address 1:</div></td>
        <td><input name="tf_street" type="text" id="tf_street" size="40"
            maxlength="255" value="<?php echo $tf_street; ?>" class="no-trsl" /></td>
        <td rowspan="6" align="left" valign="top">&nbsp;</td>
    </tr>
    <tr>
        <td width="1"></td>
        <td width="161"><div align="right" class="paddiv">Address 2: </div></td>
        <td><input name="tf_addr2" type="text" id="tf_addr2" size="40"
            maxlength="255" value="<?php echo $tf_addr2; ?>" class="no-trsl" /></td>
    </tr>
    <tr>
        <td width="1"></td>
        <td width="161"><div align="right" class="style3 paddiv">City:</div></td>
        <td><input name="tf_city" type="text" id="tf_city" size="40" maxlength="255"
            value="<?php echo $tf_city; ?>" class="no-trsl" /></td>
    </tr>
    <tr>
        <td width="1"></td>
        <td width="161"><div align="right" class="style3 paddiv"><?php echo $accCls->trans->codeKey('country') ?>:</div></td>
        <td><select id="lb_country" name="lb_country" class="cudat-medium">
            <option value="<?php echo $szCountry; ?>"
                selected="selected"><?php echo $szCountryText; ?></option>
            </select></td>
    </tr>
    <tr>
        <td width="1"></td>
        <td width="161"><div align="right" class="paddiv">State/Province:</div></td>
        <td><select id="lb_state" name="lb_state" class="cudat-medium">
            <option value="<?php echo $szState; ?>"
                selected="selected"><?php echo $szStateText; ?></option>
            </select></td>
    </tr>

    <tr>
        <td width="1"></td>
        <td width="161"><div align="right" class="paddiv">Postcode: </div></td>
        <td><input name="tf_postCode" type="text" id="tf_postCode" size="15" maxlength="40"
            value="<?php echo $tf_postCode; ?>" class="no-trsl" /></td>
    </tr>
</table>

<br />
<br />
<table width="569" height="44" border="0" cellpadding="0" cellspacing="8">
    <tr>
        <td width="103" nowrap="nowrap" class="submitLinksBack"><a href="javascript:void(0)"
            onclick="return submittheform('Return to Step 1')" >Go Back | Edit</a></td>
        <td width="70" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Save and Continue')">Continue | Enter Details</a></td>
        <td width="114" nowrap="nowrap" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Save and Quit')" >Save and Close</a></td>
        <td width="242" class="submitLinksCancel paddiv"> <a href="javascript:void(0)"
            onclick="top.onHide();" >Cancel</a></td>
    </tr>
</table>
</div>
</form>

<script type="text/javascript">
YAHOO.util.Event.onDOMReady(function()
{
    <?php // Country/State control ?>
    var c1 = new YAHOO.cms.LinkedSelects('cs1', {
        type: 'cs',
        primary: 'lb_country',
        secondary: 'lb_state',
        primaryPrompt: {v:'', t:"<?php echo $geography->translateCodeKey($langCode, 'Choose...');?>"},
        secondaryPrompt: {v:'', t:"<?php echo $geography->translateCodeKey($langCode, 'Choose...');?>"},
        secondaryEmpty: {v:'N', t:"<?php echo $geography->translateCodeKey($langCode, 'None');?>"}
    });
    c1.init("<?php echo $szCountry; ?>","<?php echo $szState; ?>");
});
</script>

<!-- End Center Content-->

<?php
noShellFoot(true);

/**
 * Case has one or more principals in subjectInfoDD
 *
 * @return bool
 */
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
