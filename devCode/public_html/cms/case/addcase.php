<?php
// phpcs:ignoreFile -- Legacy file that does not follow coding standard
/**
 * Add a new case record to cases table from manual input
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
require_once __DIR__ . '/../includes/php/Lib/FeatureACL.php';
$accCls = UserAccess::getInstance();

$longDisplayTxt = "Third Party";
if($_SESSION['currentThirdPartyIsEngagement']) {
    $longDisplayTxt = "Engagement";
}
if (!$accCls->allow('addCase') || $session->secure_value('userClass') == 'vendor') {
    exit('Invalid Access!');
}
require_once __DIR__ . '/../includes/php/funcs.php';
require_once __DIR__ . '/../includes/php/funcs_misc.php';
require_once __DIR__ . '/../includes/php/funcs_log.php';
require_once __DIR__ . '/../includes/php/LoadClass.php';
$addCaseLoader = LoadClass::getInstance();
$addCaseGeo = $addCaseLoader->geography();
if ($_SESSION['b3pAccess']) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}
require __DIR__ . '/../includes/php/'.'dbcon.php';
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
require_once __DIR__ . '/sanctionedProhibitedCountries.php';

// Check for login/Security
fchkUser("addcase");

// requestor is not user input
$e_clientID = $clientID  = $_SESSION['clientID'];
$varsInitialized = false;
$languageCode = $_SESSION['languageCode'] ?? 'EN_US';
require_once __DIR__ . '/../includes/php/'.'class_cases.php';
$casesCls = new Cases($clientID, $languageCode);
require_once __DIR__ . '/../includes/php/'.'class_BillingUnit.php';
$buCls = new BillingUnit($clientID);
$billingUnit = 0;
$billingUnitPO = 0;
$globalIdx = new GlobalCaseIndex($clientID);

// Sticky bilingual report request
$bilingualRptRequested = !empty($_SESSION['aeBilingualRpt']);
unset($_SESSION['aeBilingualRpt']);

// define Post vars used by this form
$postVarMap = ['tf_' => ['caseName', 'billingUnitPO'], 'lb_' => ['caseType', 'region', 'dept', 'caseState', 'caseCountry', 'billingUnit', 'billingUnitPO'], 'ta_' => ['caseDescription']];
// which ones are integer values?
$intVals = ['caseType', 'region', 'dept', 'billingUnit', 'billingUnitPO'];

// clear sess vars on init
$init = (isset($_GET['xsv']) && $_GET['xsv'] == 1);
if ($init || isset($_SESSION['linked3pProfileRow'])) {
    // clear sticky Session variables and initialize normal variables
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            unset($_SESSION[$varName]);
            $pVarName = $prefix . $varName;
            ${$pVarName} = (in_array($varName, $intVals)) ? 0: '';
        }
    }
    $varsInitialized = true;

    unset($_SESSION['profileRow3P']);
    if (isset($_SESSION['linked3pProfileRow']) && $accCls->allow('assoc3p')) {
        $pro = $_SESSION['profileRow3P'] = $_SESSION['linked3pProfileRow'];
        $tf_caseName    = safeHtmlSpecialChars(trim($pro->legalName));
        $lb_region      = $pro->region;
        $lb_dept        = $pro->department;
        $lb_caseState   = $pro->state;
        $lb_caseCountry = $pro->country;
        $lb_caseType    = $pro->invScope;
    }
    // It's a one-shot; kill it now
    unset($_SESSION['linked3pProfileRow'], $pro);
}

// Get risk assessment recommended scope
$recScope = 0;
$recScopeName = '';
$tpID = 0;

if ($_SESSION['b3pAccess']) {
    // 3P enabled
    if (isset($_SESSION['profileRow3P'])) {
        $tpID = $_SESSION['profileRow3P']->id;
        if ($_SESSION['b3pRisk'] && $_SESSION['profileRow3P']->invScope) {
            if ($recScope = $_SESSION['profileRow3P']->invScope) {
                $recScopeName = $_SESSION['caseTypeClientList'][$recScope];
                if (!$accCls->allow('caseTypeSelection')) {
                    $_SESSION['caseType'] = $recScope;
                    $_POST['lb_caseType'] = $recScope;
                }
            }
        }
    }
}

if (isset($_POST['Submit'])) {
    $nextPage = 'addcase.php';
    if (!PageAuth::validToken('addcaseAuth', $_POST['addcaseAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "addcase.php";
        header("Location: $errUrl");
        exit;
    }
    $validate = false;
    switch ($_POST['Submit']) {
        case 'Continue':
            $validate = true;
            $e_ct = $dbCls->esc($_POST['lb_caseType']);
            if ((int)$_POST['lb_caseType'] === DUE_DILIGENCE_INTERNAL) {
                $result = 1;
            } else {
                $sql = "SELECT subInfo FROM " . REAL_GLOBAL_DB . ".g_aclScopes WHERE "
                    . "value = '" . $e_ct . "' LIMIT 1";
                $result = (int)$dbCls->fetchValue($sql);
            }
            // select target the subject info based on caseType
            if ($result === 1) {
                $nextPage = "addsubinfodd.php";
            }
            break;

        case 'reload':
            // pass thru
            break;

    }  //end switch


    if ($validate) {
        // Use session data to make form "sticky" assign to $_SESSION variables and create ordinary variables
        foreach ($postVarMap as $prefix => $vars) {
            foreach ($vars as $varName) {
                $pVarName = $prefix . $varName;
                $isInt    = in_array($varName, $intVals);
                if (isset($_POST[$pVarName])) {
                    if ($isInt) {
                        $value = intval($_POST[$pVarName]);
                    } else {
                        $value = htmlspecialchars($_POST[$pVarName], ENT_QUOTES, 'UTF-8');
                    }
                    $_SESSION[$varName] = $value;
                } else {
                    $value = ($isInt) ? 0: '';
                }
                ${$pVarName} = $value; // create normal var
            }
        }
        $varsInitialized = true;

        // Set error counter
        $iError = 0;

        // Check for a Type
        if (!$lb_caseType) {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a Case Type.";
        } elseif ($_SESSION['b3pAccess']
            && $recScope > 0
            && $recScope != $lb_caseType
            && trim((string) $ta_caseDescription) == ''
        ) {
            $_SESSION['aszErrorList'][$iError++] = "Missing explanation for why Type of Case "
                . "differs from recommendation.";
        }
        // Check for a Case Name
        if (trim((string) $tf_caseName) == '') {
            $_SESSION['aszErrorList'][$iError++] = "You must enter a Case Name.";
        }
        //Check GEM/Region
        if (!$lb_region) {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a "
                . "{$_SESSION['regionTitle']}.";
        }
        //Check Country
        if (trim((string) $lb_caseCountry) == '') {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a " . $accCls->trans->codeKey('country') . ".";
        }
        // Require 3P association if 3P enabled
        if ($_SESSION['b3pAccess']) {
            if (!$tpID) {
                $allowedAssoc = (!$accCls->allow('assoc3p')) ?
                    ',<br />but your assigned role does not grant that permission.' : '.';
                $_SESSION['aszErrorList'][$iError++] = "You must link a Third Party Profile "
                    . "to this case" . $allowedAssoc;
            }
        }

        // Set Billing Unit and Purchase Order.
        $billingUnit = (!empty($_POST['lb_billingUnit'])) ? (int)$_POST['lb_billingUnit'] : 0;
        if (!empty($billingUnit)) {
            $_POST['tf_billingUnitPO'] = htmlspecialchars($_POST['tf_billingUnitPO'], ENT_QUOTES, 'UTF-8');
            $billingUnitPO = ($buCls->isTextReqd($billingUnit))
                ? $_POST['tf_billingUnitPO']
                : (int)$_POST['lb_billingUnitPO'];
        } else {
            $billingUnitPO = 0;
        }
        $buCls->validatePostedValues($billingUnit, $billingUnitPO, $iError);

        // any errors?
        if ($iError) {
            if ($_SESSION['b3pAccess']) {
                $_SESSION['bError'] = 1;
            } else {
                unset($_SESSION['bError']);
            }
            $errorUrl = "addcase.php";
            if ($_POST['bilingualRpt'] === '1') {
                $_SESSION['aeBilingualRpt'] = '1';
            }
            session_write_close();
            header("Location: $errorUrl");
            exit();
        } else {
            // Check if sanctioned or prohibited
            $iso2 = $lb_caseCountry;
            $SPC  = new SanctionedProhibitedCountries($dbCls);


            if ($SPC->isCountryProhibited($iso2)) {
                // If we get to this point they are trying to circumvent the JS check blocking creation of cases tied to
                // prohibited countries.
                debugLog('Tried to create case based on a prohibited country');
                exitApp(__FILE__, __LINE__, 'Unable create case based on prohibited '
                    . $accCls->trans->codeKey('country'));

            } elseif ($SPC->isCountrySanctioned($iso2)) {
                debugLog('Creating case based on sanctioned country');
                $_SESSION['addSanctionedCountry'] = true;
            }
        }

        // Insert new case record - values get escaped by PDO
        $clientID = (int)$clientID;
        $tf_caseName = safeHtmlSpecialChars($tf_caseName);
        $ta_caseDescription = safeHtmlSpecialChars($ta_caseDescription);
        $caseAttributes = [
            'clientID' => $clientID,
            'caseName' => $tf_caseName,
            'caseDescription'  => normalizeLF($ta_caseDescription),
            'caseType' => (int)$lb_caseType,
            'region' => (int)$lb_region,
            'dept' => (int)$lb_dept,
            'caseState' => $lb_caseState,
            'caseCountry' => $lb_caseCountry,
            'caseStage' => REQUESTED_DRAFT,
            'requestor' => $_SESSION['userid'],
            'caseCreated' => date('Y-m-d H:i:s'),
            'creatorUID' => $_SESSION['userid'],
            'billingUnit' => $billingUnit,
            'billingUnitPO' => $billingUnitPO,
        ];
        $newCaseResult = insertNewCase($clientID, $caseAttributes);

        $iid = 0;
        if ($newCaseResult['caseID']) {
            $iid = $newCaseResult['caseID'];
            $szUserCaseNum = $newCaseResult['userCaseNum'];
            $logMsg = "caseName: `$tf_caseName`";
            logUserEvent(11, $logMsg, $iid);

            // Bilingual report requested?
            if (((int)$lb_caseType === DUE_DILIGENCE_OSRC) && ($_POST['bilingualRpt'] === '1')) {
                $sql = "INSERT INTO " . GLOBAL_SP_DB . ".spAdditionalReportLanguage SET\n"
                    . "id = NULL, clientID = '$e_clientID', caseID = '$iid', langCode = 'ZH_CN'";
                $dbCls->query($sql);
            }
        }

        // If needed, let's insert a thirdPartyElelment
        if ($iid && isset($_SESSION['profileRow3P'])) {
            bLink3pToCase($tpID, $iid);
            // record riskAssessment from which recommended scope comes
            if ($recScope > 0) {
                $rmID = $_SESSION['profileRow3P']->riskModel;
                $raTstamp = "'" . $_SESSION['profileRow3P']->risktime . "'";
            } else {
                $rmID = '0';
                $raTstamp = 'NULL';
            }
            $e_rmID = intval($rmID);
            $e_raTstamp = $raTstamp;
            $e_iid = intval($iid);
            $sql = "UPDATE cases SET rmID='$e_rmID', raTstamp = $e_raTstamp WHERE id='$e_iid' LIMIT 1";
            $dbCls->query($sql);
        }

        // if saving and continuing to subject information pass along new caseID
        if ($_POST['Submit'] == 'Continue') {
            if ($iid) {
                $passID = $iid;
            }

            // Initial values for State and Country
            $passID .= "&country={$lb_caseCountry}&state={$lb_caseState}";
        }

        if ((int)$lb_caseType !== DUE_DILIGENCE_INTERNAL) {
            $globalIdx->syncByCaseData($iid);
        }

        // Clean up
        foreach ($postVarMap as $prefix => $vars) {
            foreach ($vars as $varName) {
                unset($_SESSION[$varName]);
            }
        }
        // Clear vars for step 2 (subject Info)
        unset($_SESSION['subinfoHold']);
        unset($_SESSION['prinHold']);

        // Make sure ddq Record is unset
        unset($_SESSION['ddqRow']);

        // Redirect user to
        session_write_close();
        header("Location: $nextPage?id=$passID");
        exit();
    } // validate and process

} // Submitted

// Ensure vars are initialized
if (!$varsInitialized) {
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            $pVarName = $prefix . $varName;
            $isInt    = in_array($varName, $intVals);
            if (isset($_SESSION[$varName])) {
                $value = $_SESSION[$varName];
            } else {
                $value = (in_array($varName, $intVals)) ? 0: '';
            }
            ${$pVarName} = $value; // create normal var
        }
    }
    $varsInitialized = true;
}

// Set up for Country/State dynamic drop downs
$lb_caseCountry ??= '';
$lb_caseState ??= '';
$szCountry = $addCaseGeo->getLegacyCountryCode($lb_caseCountry);
if (empty($szCountry)) {
    $szCountry = $lb_caseCountry;
}
$szState = $addCaseGeo->getLegacyStateCode($lb_caseState, $szCountry);
if (empty($szState)) {
    $szState = $lb_caseState;
}
// Something is getting lost in the js, so this is a dirty workaround
$_SESSION['ecCountryCode'] = $szCountry;
$_SESSION['ecStateCode'] = $szState;
$szCountryText = $addCaseGeo->getCountryNameTranslated($szCountry, $languageCode);
$szStateText = $addCaseGeo->getStateNameTranslated($szCountry, $szState, $languageCode);

$insertInHead =<<<EOT
<style type="text/css">
.risktier {
    font-weight: normal;
    width: 110px;
    text-align: center;
    white-space: nowrap;
    padding: 2px;
    *padding-top: 1px;
}
#caseErrors span {
    font-weight: normal;
    font-size: 11px;
}
div.ae-columns > div.lt {
    float: left;
}
div.ae-columns > div.bilingual {
    margin-left: 20px;
    padding-top: 5em;
    width: 340px;
}
div.ae-columns > div.bilingual div {
    font-weight: normal;
    margin: 0.7em 0 0 1em;
}
div.clr {
    clear: both;
}
</style>

EOT;


$skipBubbling = true;
$loadYUImodules = ['cmsutil', 'linkedselects'];
$pageTitle = "Create New Case Folder";
noShellHead();
$addcaseAuth = PageAuth::genToken('addcaseAuth');

//Sets to trure if environment is AWS
$awsEnabled = $awsEnabled = filter_var(getenv('AWS_ENABLED'), FILTER_VALIDATE_BOOLEAN);
?>

<!--Start Center Content-->
<script type="text/javascript">
    var $caseErrors;
    $(document).ready(function() {
        $caseErrors = $('#caseErrors');
    });

<?php
echo $buCls->jsHandler($billingUnit, $billingUnitPO);
?>

function submittheform ( selectedtype )
{
    var country = $('#lb_caseCountry').val();
    $.ajax({
        type: "POST"
        ,url: '/tpm/addCase'
        ,data: {
            op: 'checkCountry',
            country: country
        }
    }).done(function(res) {
        var code = res.Args.code;
        var msg  = res.Args.message;

        if (code === 's') {
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
                    var frm = document.addsubinfoddform;
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
            var frm = document.addsubinfoddform;
            frm.Submit.value = selectedtype ;
            frm.submit();
        }

        return false;
    });
}

function explainText(theMessage)
{
    return;
}

function explainCaseType(theMessage)
{
    var msg = '',
        jqBilingual = 'div.ae-columns > div.bilingual',
        closeBilingual = true;

    theMessage = '' + theMessage;
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
    case '<?php echo DUE_DILIGENCE_OSRC; ?>':
        msg = "The scope includes online research of select public information only.  "
            + "No field investigation is conducted.";
        if (theMessage == '<?php echo DUE_DILIGENCE_OSRC; ?>') {
            $(jqBilingual).removeClass('disp-none');
            closeBilingual = false;
        }
        break;
    case '<?php echo DUE_DILIGENCE_HCPEI; ?>':
    case '<?php echo DUE_DILIGENCE_SBI; ?>':
    case '<?php echo DUE_DILIGENCE_ESDD; ?>':
    case '<?php echo DUE_DILIGENCE_ESDD_CA; ?>':
    case '<?php echo DUE_DILIGENCE_EDDPDF; ?>':
        msg = "The scope includes field investigation and online research of "
            + "select public information.";
        break;
    case '<?php echo DUE_DILIGENCE_HCPAI; ?>':
    case '<?php echo DUE_DILIGENCE_ABI; ?>':
    case '<?php echo SPECIAL_PROJECT; ?>':
        msg = "Define your requested scope in the <b>Brief Note</b> below. "
            + "A budget and time line will be provided for your approval.";
        break;
    <?php
    if ($clientID == PEPSICO_CLIENTID) {
    ?>
    case '<?php echo DUE_DILIGENCE_SCD; ?>':
        msg = "Supply Chain Diligence";
        break;

    <?php
    } elseif ($clientID == 306) {
        // Collins Aerospace
    ?>
    case '<?php echo DUE_DILIGENCE_UTC5; ?>':
        msg = "UTC Aerospace";
        break;

<?php
    } elseif ($clientID == BERGER_CLIENTID) {
    ?>
    case '<?php echo DUE_DILIGENCE_OSI_CSRI; ?>':
        msg = "OSI + CSRI";
        break;
    <?php
    }
    ?>
    } // end switch
    if (closeBilingual) {
        $(jqBilingual).addClass('disp-none');
    }
    YAHOO.util.Dom.get('messageDiv').innerHTML = msg;
}

function relay3pAssoc()
{
    if (!parent.YAHOO.add3p.panelReady) {
        return false;
    }
    parent.YAHOO.add3p.addEngagement = false;
    // var check = parseInt("<?php echo $_SESSION['currentThirdPartyIsEngagement'];?>");
    // if (check) {
    //     parent.YAHOO.add3p.addEngagement = true;
    // }
    var frm = document.addsubinfoddform;
    parent.YAHOO.add3p.callerFormVals = {
        'fields': ['company', 'region', 'dept', 'country', 'state'],
        'company': frm.tf_caseName.value,
        'region': frm.lb_region[frm.lb_region.selectedIndex].value,
        'dept': frm.lb_dept[frm.lb_dept.selectedIndex].value,
        'country': frm.lb_caseCountry[frm.lb_caseCountry.selectedIndex].value,
        'state': frm.lb_caseState[frm.lb_caseState.selectedIndex].value
    };
    return parent.assoc3pProfile('ac', 0);
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

<h6 class="formTitle">Create New Case Folder</h6>

<form name="addsubinfoddform" class="cmsform"
action="<?php echo $_SERVER['PHP_SELF']?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="addcaseAuth" value="<?php echo $addcaseAuth; ?>" />
<input type="hidden" name="caseType" />
<input type="hidden" name="bilingualRpt" id="aeBilingualRpt"
    value="<?php echo ($bilingualRptRequested ? '1' : '0'); ?>" />

<div class="formStepsPic1" id="columns"><img src="/cms/images/formSteps1.gif"
width="231" height="30" /></div>
<div id="stpfh" class="stepFormHolder v-hidden">

<div style="line-height:1em">&nbsp;</div>

<?php
if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng')) {

    $img = '<div';
    if ($accCls->allow('assoc3p')) {
        $onclk = " onclick=\"return relay3pAssoc()\"";
        $img .= $onclk . ' style="cursor:pointer"';
        $lbl = '<a href="javascript:void(0)" ' . $onclk
            . ' title="Associate Third Party">CLICK HERE</a> <span '
            . 'class="fw-normal">to associate a third Party with '
            . 'this investigation.</span>';
    } else {
        $lbl = 'A third party must be associated with this investigation.<br />'
            . 'Your assigned role does not have that permission.';
    }
    $img .= '><span class="fas fa-plus" style="font-size: 20px;"></span><span class="fas fa-id-card"></span></div>';?>
    <div style="margin-bottom:5px"><table cellpadding="0" cellspacing="0">
    <tr>
        <td><div class="ta-right marg-rtsm"><?php echo $img?></div></td>
        <td><?php echo $lbl?></td>
    </tr>
    </table></div>
    <?php
} ?>

<div class="ae-columns">
    <div class="lt" style="float:left">

<table border="0" cellpadding="0" cellspacing="0">

<?php
if (isset($_SESSION['profileRow3P'])) {
    ?>
    <tr>
        <td height="29"><div class="no-wrap marg-rtsm"><u>Associated <?php echo $longDisplayTxt;?></u>:</div></td>
        <td class="no-trsl"><?php echo $_SESSION['profileRow3P']->legalName?></td>
    </tr>
    <?php
} ?>
    <tr>
        <td colspan="2"><span class="style11">Case Folder Identification</span></td>
    </tr>
    <tr>
        <td><div class="indent sytle3">Name this Case:</div></td>
        <td><input name="tf_caseName" type="text" class="cudat-large no-trsl" size="40"
            maxlength="40" value="<?php echo $tf_caseName; ?>" /></td>
    </tr>
    <tr>
        <td><div class="indent style3">Type of Case:</div></td>
        <td class="no-trsl">
<?php
if ($recScope && !$accCls->allow('caseTypeSelection')) {
    echo $recScopeName;
    echo '<input type="hidden" id="lb_caseType" name="lb_caseType" value="' . $recScope  . '" />';
} else {
    $selectedValue = $lb_caseType;
    if (($scopeSelectList = $casesCls->buildScopeSelectList("lb_caseType", $selectedValue, 0))
        && ($scopeSelectList != "")
    ) {
        echo $scopeSelectList;
    }
} ?></td>
    </tr>
    <tr>
        <td></td>
        <td><div id="messageDiv" class="fw-normal indent" style="width:300px"></div></td>
    </tr>
<?php
if ($recScopeName) {
    ?>
    <tr>
        <td valign="top"><div class="indent"><u>Recommendation</u>:<div
            class="no-wrap marg-topsm marg-rtsm">Risk: <span
            class="fw-normal"><?php echo $_SESSION['profileRow3P']->risk?></span></div>
            </div></td>
        <td valign="top"><?php echo $recScopeName?><div class="indent fw-normal"
            style="width:300px">Please explain below if selected Type of Case differs from
            risk assessment recommendation.</div></td>
    </tr>
    <tr>
        <td colspan="2" style="line-height:5px">&nbsp;</td>
    </tr>
    <?php
} ?>
    <tr>
        <td valign="top"><div class="indent no-wrap marg-rtsm"
            style="margin-top:3px">Brief Note/<br />Billing Information:</div></td>
        <td><textarea name="ta_caseDescription" class="cudat-large no-trsl"
            cols="35" rows="4"><?php echo $ta_caseDescription; ?></textarea></td>
    </tr>
    <tr>
        <td colspan="2"><div class="style11 marg-top1e">Case Demographics</div></td>
    </tr>
    <tr>
        <td><div class="indent style3 no-wrap marg-rtsm no-trsl"><?php
            echo $_SESSION['regionTitle']; ?>:</div></td>
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
        <td><div class="indent style3"><?php echo $accCls->trans->codeKey('country') ?>:</div></td>
        <td><select id="lb_caseCountry" name="lb_caseCountry" class="cudat-medium">
            <option value="<?php echo $szCountry?>" selected="selected"><?php echo $szCountryText?></option>
            </select></td>
    </tr>
    <tr>
        <td><div class="indent no-wrap marg-rtsm">State/Province:</div></td>
        <td><select id="lb_caseState" name="lb_caseState" class="cudat-medium">
            <option value="<?php echo $szState?>" selected="selected"><?php echo $szStateText?></option>
            </select></td>
    </tr>
    <tr>
        <td><div class="indent no-wrap marg-rtsm no-trsl"><?php
            echo $_SESSION['departmentTitle']; ?>:</div></td>
        <td>
<?php
switch ($_SESSION['userType']) {
    case SUPER_ADMIN:
    case CLIENT_ADMIN:
        fCreateDDLfromDB("lb_dept", "department", $lb_dept, 0, 1);
        break;

    case CLIENT_MANAGER:
        echo '<select id="lb_dept" name="lb_dept" data-no-trsl-cl-labels-sel="1">';
            fFillUserLimitedDepartment($_SESSION['mgrDepartments'], $lb_dept);
        echo '</select>';
        break;

    case CLIENT_USER:
        echo '<select id="lb_dept" name="lb_dept" data-no-trsl-cl-labels-sel="1">';
        fFillUserLimitedDepartment($_SESSION['userDept'], $lb_dept);
        echo '</select>';
        break;

    default:
        exit('<br/>ERROR - userType NOT Recognized<br/>');
}
?>
        </td>
    </tr>

<?php
echo $buCls->selectTagsTR();
?>

</table>
    </div>

    <div class="lt bilingual disp-none">
        <input id="ae-bilingual-rpt" type="checkbox"
            value="1"<?php echo ($bilingualRptRequested ? ' checked="checked"' : ''); ?> />
            <label for="ae-bilingual-rpt">Order bilingual report in Chinese</label>
        <div>An additional report will be provided by the investigator
            in Chinese for an additional cost
        </div>
    </div>

    <div class="clr" style="clear:both"></div>
</div>

<div style="margin-top:1.5em">
<table width="325" border="0" cellpadding="0" cellspacing="8" class="paddiv">
    <tr>
        <td width="231" nowrap="nowrap" class="submitLinks paddiv">
            <a href="javascript:void(0)"
            onclick="return submittheform('Continue')">Create Case | Enter Company Details</a></td>
        <td width="245" nowrap="nowrap" class="submitLinksCancel paddiv"> <a
            href="javascript:;" onclick="top.onHide();" >Cancel</a>  </td>
    </tr>

</table>
</div>

</div>
<input type="hidden" name="buIgnore" id="buIgnore" value="1" />
<input type="hidden" name="poIgnore" id="poIgnore" value="1" />
</form>

<script type="text/javascript">
YAHOO.util.Event.onDOMReady(function(){
    var Dom = YAHOO.util.Dom;
    var el, i, reg, w, mw = 0;
    var ids = ['region', 'caseCountry', 'caseState', 'dept'];
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
    el = Dom.get('lb_caseType');
    reg = Dom.getRegion(el);
    if (((mw * .8) <= reg.width)  && reg.width <= mw) {
        Dom.setStyle(el, 'width', w);
        explainCaseType(YAHOO.cms.Util.getSelectValue(el));
    }
    Dom.replaceClass('stpfh', 'v-hidden', 'v-visible');

    <?php // Country/State control ?>
    var c1 = new YAHOO.cms.LinkedSelects('cs1', {
        type: 'cs',
        primary: 'lb_caseCountry',
        secondary: 'lb_caseState',
        primaryPrompt: {v:'', t:"<?php echo $addCaseGeo->translateCodeKey($languageCode, 'Choose...');?>"},
        secondaryPrompt: {v:'', t:"<?php echo $addCaseGeo->translateCodeKey($languageCode, 'Choose...');?>"},
        secondaryEmpty: {v:'N', t:"<?php echo $addCaseGeo->translateCodeKey($languageCode, 'None');?>"}
    });
    c1.init("<?php echo $szCountry?>","<?php echo $szState?>");

    <?php // Billing Unit / Purchase Order ?>
<?php
echo $buCls->initLinkedSelects($billingUnit, $billingUnitPO);
?>
    $('#ae-bilingual-rpt').on('click', function(e) {
        $('#aeBilingualRpt').val($(e.currentTarget).prop('checked') ? '1' : '0');
    });

});
</script>

<!--   End Center Content-->

<?php
noShellFoot(true);
?>
