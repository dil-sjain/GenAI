<?php
// phpcs:ignoreFile -- Legacy file that does not follow coding standard
/**
 * Edit a case record before it's converted to an investigation
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('updtCase') && !$access->allow('convertCase')) {
    exit('Access denied');
}
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'class_db.php';
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
require_once 'sanctionedProhibitedCountries.php';

if ($_SESSION['b3pAccess']) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}

// Check for login/Security
fchkUser("editcase");

// requestor is not user input
$requestor = $dbCls->escape_string($_SESSION['userid']);
$e_clientID = $clientID  = (int)$_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);

// Sticky bilingual report request
$bilingualRptValue = false;
if (array_key_exists('aeBilingualRpt', $_SESSION)) {
    // coming back from an error
    $bilingualRptValue = $_SESSION['aeBilingualRpt'];
}
unset($_SESSION['aeBilingualRpt']);

$languageCode = $_SESSION['languageCode'] ?? 'EN_US';
require_once __DIR__ . '/../includes/php/'.'class_cases.php';
$casesCls = new Cases($clientID, $languageCode);
require_once __DIR__ . '/../includes/php/Models/Globals/Geography.php';
$geo = \Legacy\Models\Globals\Geography::getVersionInstance(null, null, $clientID);

$caseID    = 0;
if (isset($_GET['id'])) {
    // Support old method of passing caseID on URL
    $caseID = intval($_GET['id']);
} elseif (isset($_SESSION['currentCaseID'])) {
    $caseID = $_SESSION['currentCaseID'];
}

$e_caseID = intval($caseID);

// Get the case Record
$caseRow = fGetCaseRow($caseID);
if (!$caseRow
    || $caseRow['caseStage'] >= ASSIGNED
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    exit('Access denied');
}

// Does this caseType use subInfo?
$safeType = (int)$caseRow['caseType'];
$aclSubInfo = $casesCls->caseTypeHasSubInfo($safeType);

// Is there an existing bilingual report request?
$sql = "SELECT id FROM " . GLOBAL_SP_DB . ".spAdditionalReportLanguage\n"
    . "WHERE clientID = '$e_clientID' AND caseID = '$e_caseID'";
if (($bilingualRptID = $dbCls->fetchValue($sql)) && ($bilingualRptValue === false)) {
    $bilingualRptValue = '1';
}

require_once __DIR__ . '/../includes/php/'.'class_BillingUnit.php';
$buCls = new BillingUnit($clientID);
$billingUnit = $caseRow['billingUnit'];
$billingUnitPO = $caseRow['billingUnitPO'];

$varsInitialized = false;

// define Post vars used by this form
$postVarMap = [
    'tf_' => [
        'caseName', 'billingUnitPO' //  billingUnitPO.name
    ],
    'lb_' => [
        'caseType', 'region', 'dept', 'caseState', 'caseCountry', 'billingUnit', 'billingUnitPO' //  billingUnitPO.id
    ],
    'ta_' => [
        'caseDescription'
    ],
];

// which ones are integer values?
// labeled as a 'int'... It eventually becomes int but may be string in $_POST... if tf_billingUnitPO.
$intVals = ['caseType', 'region', 'dept', 'billingUnit', 'billingUnitPO'];

// clear sess vars on init
if (isset($_GET['xsv']) && $_GET['xsv'] == 1) {
    // clear sticky sess vars for this form
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            unset($_SESSION[$varName]);
        }
    }

    // Unset "sticky" sess vars for 3P association and step 2 of edit case
    $sessVars = [
        'subinfoHold',
        'prinHold',
        // Make sure ddq Record is unset
        'ddqRow',
        // Clear 3P association setter
        'linked3pProfileRow',
        'ecPOST',
        'ecMustReload',
    ];
    foreach ($sessVars as $varName) {
        unset($_SESSION[$varName]);
    }
    unset($sessVars);
}

// Get 3P values
$tpID = intval($caseRow['tpID']);
$tpRow = null;
$recScopeName = '';
$recScope = 0;
if ($_SESSION['b3pAccess']) {
    if (isset($_SESSION['linked3pProfileRow']) && $accCls->allow('assoc3p')) {
        $tpRow = $_SESSION['linked3pProfileRow'];
        $tpID = $tpRow->id;
        unset($_SESSION['linkded3pProfileRow']);
        $_SESSION['ecMustReload'] = true;
    } elseif ($tpID) {
        $tpRow = fGetThirdPartyProfileRow($tpID);
    }
    if ($tpRow && $_SESSION['b3pRisk']) {
        if ($recScope = $tpRow->invScope) {
            $recScopeName = $_SESSION['caseTypeClientList'][$recScope];
            if (!$accCls->allow('caseTypeSelection')) {
                $_POST['lb_caseType'] = $recScope;
                $_SESSION['caseType'] = $recScope;
                $caseRow['caseType']  = $recScope;
                $caseRow[3]           = $recScope;
            }
        }
    }
}

if (isset($_POST['Submit'])) {
    if (!PageAuth::validToken('edcaseAuth', $_POST['edcaseAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "editcase.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }
    // Save POSTed vars
    $_SESSION['ecPOST'] = $_POST;

    include __DIR__ . '/../includes/php/'.'dbcon.php';
    // Initialize an Email variable
    $sysEmail = 0;

    $validate = true;
    switch ($_POST['Submit']) {
    case 'reload':
        $validate = false;
        break;

    case 'Save and Continue':
        $nextPage = '';
        // select target the subject info based on caseType
        if ($aclSubInfo) {
            $sql = "SELECT caseID FROM subjectInfoDD "
                . "WHERE caseID = '$e_caseID' AND clientID = '$e_clientID' LIMIT 1";
            if ($dbCls->fetchValue($sql) == $caseID) {
                $nextPage = 'editsubinfodd.php';
            } else {
                $nextPage = 'addsubinfodd.php';
                debugTrack(['editcase it returns the nextpage' => $nextPage]);
            }
            break;
        }
        break;
    }

    if ($validate) {
        // Use session data to make form "sticky" assign to session vars and create ordinary vars
        foreach ($postVarMap as $prefix => $vars) {
            foreach ($vars as $varName) {
                $pVarName = $prefix . $varName;
                $isInt = in_array($varName, $intVals);
                if (isset($_POST[$pVarName])) {
                    if ($varName == 'billingUnitPO') {
                        $bu             = $_POST['lb_billingUnit'];
                        $POisTextField  = $buCls->isTextReqd($bu);
                        $value = $POisTextField ? $_POST['tf_billingUnitPO'] : (int)$_POST['lb_billingUnitPO'];
                    } else {
                        $_POST[$pVarName] = htmlspecialchars($_POST[$pVarName], ENT_QUOTES, 'UTF-8');
                        $value = ($isInt) ? intval($_POST[$pVarName]) : $_POST[$pVarName];
                    }
                    //  This is the merge of $_POST into $_SESSION.
                    $_SESSION[$varName] = $value;
                } else {
                    $value = ($isInt) ? 0 : '';
                }
                ${$pVarName} = $value; // create normal var
            }
        }
        $varsInitialized = true;

        // Set error flag to false
        $iError = 0;

        // Check for a Type
        if (intval($lb_caseType) == 0) {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a Case Type.";
        } elseif ($_SESSION['b3pAccess']
            && $recScope > 0
            && $recScope != intval($lb_caseType)
            && trim((string) $ta_caseDescription) == ''
        ) {
            $_SESSION['aszErrorList'][$iError++]
                = "Missing explanation for why Type of Case differs from recommendation.";
        }

        // Check for a Case Name
        if (trim((string) $tf_caseName) == '') {
            $_SESSION['aszErrorList'][$iError++] = "You must enter a Case Name.";
        }
        //Check GEM/Region
        if (intval($lb_region) == 0) {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a {$_SESSION['regionTitle']}.";
        }
        //Check Country
        if (trim((string) $lb_caseCountry) == '') {
            $_SESSION['aszErrorList'][$iError++] = "You must Choose a " . $accCls->trans->codeKey('country') . ".";
        }

        // @dev: SEC-3080 & 3081
        $iso2 = $lb_caseCountry;
        $SPC  = new SanctionedProhibitedCountries($dbCls);

        if ($SPC->isCountryProhibited($iso2)) {
            debugLog('Prohibited country.');
        } else if ($SPC->isCountrySanctioned($iso2)) {
            debugLog('Sanctioned country.');
        }

        // If 3P is turned on, make sure there is an association
        if ($_SESSION['b3pAccess'] && !$tpID) {
            $_SESSION['aszErrorList'][$iError++]
                = "You must link a Third Party Profile to this case"
                . (!$accCls->allow('assoc3p'))
                    ? ',<br />but your assigned role does not grant that permission.'
                    : '.';
        }
        // Set Billing Unit and Purchase Order.
        $billingUnit = (!empty($_POST['lb_billingUnit'])) ? (int)$_POST['lb_billingUnit'] : 0;
        if (!empty($billingUnit)) {
            $_POST['tf_billingUnitPO'] = htmlspecialchars($_POST['tf_billingUnitPO'], ENT_QUOTES, 'UTF-8');
            $billingUnitPO = ($buCls->isTextReqd($billingUnit))
                ? (int)$_POST['tf_billingUnitPO']
                : (int)$_POST['lb_billingUnitPO'];
        } else {
            $billingUnitPO = 0;
        }
        $buCls->validatePostedValues($billingUnit, $billingUnitPO, $iError);
        // any errors?
        if ($iError) {
            $errorUrl = "editcase.php?id=$caseID";
            // in edit mode this setting needs to be retained unconditionally
            $_SESSION['aeBilingualRpt'] = $_POST['bilingualRpt'];
            session_write_close();
            header("Location: $errorUrl");
            exit;
        }

        // clean up description
        $e_ta_caseDescription = mysqli_real_escape_string(MmDb::getLink(), normalizeLF($ta_caseDescription));
        $e_tf_caseName = mysqli_real_escape_string(MmDb::getLink(), (string) $tf_caseName);
        $e_lb_caseType = intval($lb_caseType);
        $e_lb_region = intval($lb_region);
        $e_lb_dept = intval($lb_dept);
        $e_lb_caseState = mysqli_real_escape_string(MmDb::getLink(), (string) $lb_caseState);
        $e_lb_caseCountry = mysqli_real_escape_string(MmDb::getLink(), (string) $lb_caseCountry);
        $e_bu = $billingUnit;
        $e_po = (int)$billingUnitPO;

        // update case record
        $sql = "UPDATE cases SET "
            . "caseName        = '$e_tf_caseName', "
            . "caseDescription = '$e_ta_caseDescription', "
            . "caseType        = '$e_lb_caseType', "
            . "region          = '$e_lb_region', "
            . "dept            = '$e_lb_dept', "
            . "caseState       = '$e_lb_caseState', "
            . "caseCountry     = '$e_lb_caseCountry', "
            . "requestor       = '$requestor', "
            . "billingUnit     = '$e_bu', "
            . "billingUnitPO   = '$e_po' "
            . "WHERE id = '$e_caseID' AND clientID = '$e_clientID' LIMIT 1";

        $result = mysqli_query(MmDb::getLink(), $sql);
        if (!$result) {
            exitApp(__FILE__, __LINE__, $sql);
        }

        // Update bilingual report request, if needed
        if (($e_lb_caseType === DUE_DILIGENCE_OSRC) && ($_POST['bilingualRpt'] === '1')) {
            // insert request if there isn't one already
            if (empty($bilingualRptID)) {
                $sql = "INSERT INTO " . GLOBAL_SP_DB . ".spAdditionalReportLanguage SET\n"
                    . "id = NULL, clientID = '$e_clientID', caseID = '$e_caseID', langCode = 'ZH_CN'";
                $dbCls->query($sql);
            }
        } elseif (!empty($bilingualRptID)) {
            // remove existing request
            $sql = "DELETE FROM " . GLOBAL_SP_DB . ".spAdditionalReportLanguage\n"
                . "WHERE id = '$bilingualRptID' AND clientID = '$e_clientID'\n"
                . "AND caseID = '$e_caseID' AND langCode = 'ZH_CN' LIMIT 1";
            $dbCls->query($sql);
        }

        // record riskAssessment from which recommended scope comes
        if ($tpRow) {
            if ($recScope > 0 && $tpRow->riskModel && $tpRow->risktime) {
                $rmID = $tpRow->riskModel;
                $raTstamp = "'" . $tpRow->risktime . "'";
            } else {
                $rmID = '0';
                $raTstamp = 'NULL';
            }
            $e_rmID = $rmID;
            $e_raTstamp = $raTstamp;
            $sql = "UPDATE cases SET rmID = '$e_rmID', raTstamp = $e_raTstamp "
                . "WHERE id='$e_caseID' LIMIT 1";
            $dbCls->query($sql);
        }

        // sync global case index.
        $globalIdx->syncByCaseData($e_caseID);

        // If this Submit requires a system email, send it
        if ($sysEmail) {
            fSendSysEmail($sysEmail, $caseID);
        }

        unset($_SESSION['ecPOST']); // don't need POST var anymore
        // Redirect user to
        session_write_close();
        if (empty($nextPage)) {
            // refresh case folder (catches any changes so they can be displayed.)
            echo '<script type="text/javascript">window.parent.location="/cms/case/casehome.sec";</script>';
        } else {
            header("Location: $nextPage?id=$caseID");
        }
        exit;
    }
}

// Ensure vars are initialized
// Restore previous input values
if (isset($_SESSION['ecPOST'])) {
    $held = ['tf_caseName', 'lb_caseType', 'lb_caseCountry', 'lb_caseState', 'ta_caseDescription', 'lb_region', 'lb_dept', 'lb_billingUnit', 'lb_billingUnitPO'];
    foreach ($held AS $k) {
        ${$k} = $_SESSION['ecPOST'][$k];
    }
    unset($_SESSION['ecPOST']);
    $varsInitialized = true;
}

if (!$varsInitialized) {
    foreach ($postVarMap as $prefix => $vars) {
        foreach ($vars as $varName) {
            $pVarName = $prefix . $varName;
            $isInt    = in_array($varName, $intVals);
            if (isset($_SESSION[$varName])) {
                $value = $_SESSION[$varName];
            } else {
                $value = $caseRow[$varName];
            }
            ${$pVarName} = $value; // create normal var
        }

    }
    $varsInitialized = true;
}
$ta_caseDescription = normalizeLF($ta_caseDescription);

$lb_caseCountry = $lb_caseCountry ?? '';
$lb_caseState = $lb_caseState ?? '';
$szCountry = $geo->getLegacyCountryCode($lb_caseCountry);
if (empty($szCountry)) {
    $szCountry = $lb_caseCountry;
}
$szState = $geo->getLegacyStateCode($lb_caseState, $szCountry);
if (empty($szState)) {
  $szState = $lb_caseState;
}
// Something is getting lost in the js, so this is a dirty workaround
$_SESSION['ecCountryCode'] = $szCountry;
$_SESSION['ecStateCode'] = $szState;
$e_lb_caseCountry = $dbCls->esc($lb_caseCountry);
$e_lb_caseState = $dbCls->esc($lb_caseState);
$szCountryText = $geo->getCountryNameTranslated($szCountry, $languageCode);
$szStateText = $geo->getStateNameTranslated($szCountry, $szState, $languageCode);

//----- Show the Form -----//

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

$loadYUImodules = ['cmsutil', 'linkedselects'];
$pageTitle = "Create New Case Folder";
noShellHead();
$edcaseAuth = PageAuth::genToken('edcaseAuth');

//Sets to true if environment is aws
$awsEnabled = filter_var(getenv('AWS_ENABLED'), FILTER_VALIDATE_BOOLEAN);
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
                    var frm = document.editcaseform;
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
            document.editcaseform.Submit.value = selectedtype;
            document.editcaseform.submit();
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
        msg = "The scope includes field investigation and online research "
            + "of select public information.";
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

<?php //create onDomReady Event ?>
window.onDomReady = DomReady;

<?php //Setup the event ?>
function DomReady(fn)
{
    <?php //W3C ?>
    if(document.addEventListener) {
        document.addEventListener("DOMContentLoaded", fn, false);
    } else {
        <?php //IE ?>
        document.onreadystatechange = function(){readyState(fn)};
    }
}

<?php //IE execute function ?>
function readyState(fn)
{
    <?php //dom is ready for interaction ?>
    if(document.readyState == "interactive") {
        fn();
    }
}

window.onDomReady(onReady);


function onReady()
{
    var msg = "<?php echo $caseRow['caseType'] ?>";

}

function relay3pAssoc(caseID)
{
    if (!parent.YAHOO.add3p.panelReady) return false;
    var frm = document.editcaseform;
    parent.YAHOO.add3p.callerFormVals = {
        'fields': ['company', 'region', 'dept', 'country', 'state'],
        'company': frm.tf_caseName.value,
        'region': frm.lb_region[frm.lb_region.selectedIndex].value,
        'dept': frm.lb_dept[frm.lb_dept.selectedIndex].value,
        'country': frm.lb_caseCountry[frm.lb_caseCountry.selectedIndex].value,
        'state': frm.lb_caseState[frm.lb_caseState.selectedIndex].value
    };
    return parent.assoc3pProfile('ec', caseID);
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
<h6 class="formTitle">Update Case Folder</h6>

<form name="editcaseform" class="cmsform"
action="<?php echo $_SERVER['PHP_SELF'] .'?id=' . $caseID; ?>" method="post" >
<input type="hidden" name="Submit" />
<input type="hidden" name="edcaseAuth" value="<?php echo $edcaseAuth; ?>" />
<input type="hidden" name="bilingualRpt" id="aeBilingualRpt"
    value="<?php echo ($bilingualRptValue === '1' ? '1' : '0'); ?>" />
<div class="formStepsPic1"><img src="/cms/images/formSteps1.gif" width="231" height="30" /></div>
<div id="stpfh" class="stepFormHolder v-hidden">

<div style="line-height:1em">&nbsp;</div>

<?php
if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng')) {
    $img = '<div';
    if ($accCls->allow('assoc3p')) {
        $onclk = " onclick=\"return relay3pAssoc(" . intval($caseID) . ")\"";
        $img .= $onclk . ' style="cursor:pointer"';
        $lbl = '<a href="javascript:void(0)" ' . $onclk
            . ' title="Associate 3P Profile">CLICK HERE</a> '
            . '<span class="fw-normal">to associate a third party profile '
            . 'with this investigation.</span>';
    } else {
        $lbl = 'A third party profile must be associated with this investigation.<br />'
            . 'Your assigned role does not have that permission.';
    }
    $img .= '><span class="fas fa-plus" style="font-size: 20px;"></span><span class="fas fa-id-card"></span></div>';
    ?>
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
if ($tpRow) {
    ?>
    <tr>
        <td height="29"><div class="no-wrap marg-rtsm"><u>Associated 3P Profile</u>:</div></td>
        <td><?php echo $tpRow->legalName?></td>
    </tr>
    <?php
} ?>
    <tr>
        <td colspan="2"><div class="style11">Case Folder Identification</div></td>
    </tr>
    <tr>
        <td><div class="indent style3 no-wrap">Name this Case:</div></td>
        <td><input name="tf_caseName" type="text" class="cudat-large" size="40"
            value="<?php echo $tf_caseName; ?>"/></td>
    </tr>
    <tr>
        <td><div class="indent style3 no-wrap">Type of Case:</div></td>
        <td>
<?php
if ($recScope && !$accCls->allow('caseTypeSelection')) {
    echo $recScopeName;
    echo '<input type="hidden" id="lb_caseType" name="lb_caseType" value="' . $recScope  . '" />';
} else {
    $selectedValue = ($recScope && $lb_caseType == 0 ? $recScope : $lb_caseType);
    if (($scopeSelectList = $casesCls->buildScopeSelectList("lb_caseType", $selectedValue, 0))
        && ($scopeSelectList != "")
    ) {
        echo $scopeSelectList;
    }
}  ?></td>
    </tr>
    <tr>
        <td></td>
        <td><div id="messageDiv" class="fw-normal indent" style="width:300px"></div></td>
    </tr>
<?php
if ($recScopeName) {
    ?>
    <tr>
        <td valign="top"><div class="indent marg-rtsm"><u>Recommendation</u>:<div
            class="no-wrap marg-topsm marg-rtsm">Risk: <span
            class="fw-normal"><?php echo $tpRow->risk?></span></div></div></td>
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
        <td><textarea name="ta_caseDescription" class="cudat-large" cols="35"
            rows="4"><?php
            echo $ta_caseDescription; ?></textarea></td>
    </tr>
    <tr>
        <td colspan="2"><div class="style11 marg-top1e">Case Demographics</div></td>
    </tr>
    <tr>
        <td><div class="indent style3 no-wrap marg-rtsm"><?php
            echo $_SESSION['regionTitle']?>:</div></td>
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
            <option value="<?php echo $szCountry?>"
                selected="selected"><?php echo $szCountryText?></option>
            </select></td>
    </tr>
    <tr>
        <td><div class="indent no-wrap marg-rtsm">State/Province:</div></td>
        <td><select id="lb_caseState" name="lb_caseState" class="cudat-medium">
            <option value="<?php echo $szState?>"
                selected="selected"><?php echo $szStateText?></option>
            </select></td>
    </tr>
    <tr>
        <td><div class="indent no-wrap marg-rtsm"><?php
            echo $_SESSION['departmentTitle']?>:</div></td>
        <td>
<?php
switch ($_SESSION['userType']) {
case SUPER_ADMIN:
case CLIENT_ADMIN:
    fCreateDDLfromDB("lb_dept", "department", $lb_dept, 0, 1);
    break;

case CLIENT_MANAGER:
    echo '<select id="lb_dept" name="lb_dept">';
        fFillUserLimitedDepartment($_SESSION['mgrDepartments'], $lb_dept);
    echo '</select>';
    break;

case CLIENT_USER:
    echo '<select id="lb_dept" name="lb_dept">';
    fFillUserLimitedDepartment($_SESSION['userDept'], $lb_dept);
    echo '</select>';
    break;

default:
    exit('<br/>ERROR - userType NOT Recognized<br/>');
} ?></td>
    </tr>

<?php
echo $buCls->selectTagsTR();
?>

</table>
<br />
</div>

<div class="lt bilingual disp-none">
    <input id="ae-bilingual-rpt" type="checkbox"
        value="1"<?php echo ($bilingualRptValue === '1' ? ' checked="checked"' : ''); ?> />
        <label for="ae-bilingual-rpt">Order bilingual report in Chinese</label>
    <div>An additional report will be provided by the investigator
        in Chinese for an additional cost
    </div>
</div>

<div class="clr" style="clear:both"></div>
</div>

<table width="279" border="0" cellpadding="0" cellspacing="8" class="paddiv">
    <tr>
        <td width="55" nowrap="nowrap" class="submitLinks">
            <a href="javascript:void(0)"
            onclick="return submittheform('Save and Continue')" ><?php
                if (!$aclSubInfo) {
                    echo "Save and Close";
                } else {
                    echo "Continue | Enter Details";
                }?></a></td>
<?php
if (isset($_SESSION['ecMustReload'])) {
    ?>
        <td width="200" nowrap="nowrap" class="submitLinksCancel"> <a href="javascript:void(0)"
            onclick="window.parent.location='/cms/case/casehome.sec'" >Cancel</a></td>
    <?php
} else {
    ?>
        <td width="200" nowrap="nowrap" class="submitLinksCancel"> <a href="javascript:void(0)"
            onclick="top.onHide();" >Cancel</a></td>
    <?php
} ?>
    </tr>
</table>

</div>
<input type="hidden" name="buIgnore" id="buIgnore" value="1" />
<input type="hidden" name="poIgnore" id="poIgnore" value="1" />
</form>

<script type="text/javascript">
YAHOO.util.Event.onDOMReady(function(o){

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
    }
    explainCaseType(YAHOO.cms.Util.getSelectValue(el));
    Dom.replaceClass('stpfh', 'v-hidden', 'v-visible');

    <?php // Country/State control ?>
    var c1 = new YAHOO.cms.LinkedSelects('cs1', {
        type: 'cs',
        primary: 'lb_caseCountry',
        secondary: 'lb_caseState',
        primaryPrompt: {v:'', t:"<?php echo $geo->translateCodeKey($languageCode, 'Choose...');?>"},
        secondaryPrompt: {v:'', t:"<?php echo $geo->translateCodeKey($languageCode, 'Choose...');?>"},
        secondaryEmpty: {v:'N', t:"<?php echo $geo->translateCodeKey($languageCode, 'None');?>"}
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

<!-- End Center Content-->

<?php
noShellFoot(true);
?>
