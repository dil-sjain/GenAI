<?php
// Make sure this form isn't directly accessible
if (!defined('SECURIMATE_ENV')) {
    exit('Access denied.');
}

// cms_defs is already loaded
$clientID = (int)$_SESSION['clientID'];
if (!$dbCls) {
    $dbCls = dbClass::getInstance();
}
require_once __DIR__ . '/../includes/php/'.'req_validemail.php';

$loadYUImodules = ['cmsutil', 'linkedselects'];

$pageTitle = "Intake Form Invitation";
noShellHead();

$addinvAuth = PageAuth::genToken('addinvAuth');
?>
<h6 class="formTitle"><?php echo $formTitle; ?></h6>
<form name="add_ddqinviteform" id="add_ddqinviteform"
    class="cmsform" action="<?php echo $formAction?>" method="post">

<?php
if (!$haltMsg) {
    $dialogLink = "{$icRef}_{$icFormType}_{$caseID}_{$icAction}";
?>

<!--Start Center Content-->

<script language="JavaScript" type="text/javascript">
function submittheform (selectedtype)
{
  var container = document.getElementById('sendInvContainer');
  container.innerHTML = '<span style="color:gray;">Send Invitation</span>';
  document.add_ddqinviteform.Submit.value = selectedtype ;
  document.add_ddqinviteform.submit() ;
  return false;
}
function explainText(id)
{
    return;
}
function relay3pAssoc(caseID)
{
    if (!parent.YAHOO.add3p.panelReady) return false;
    var frm = document.add_ddqinviteform;
    parent.YAHOO.add3p.callerFormVals = {
        'fields': ['POCname', 'POCemail', 'phone'],
        'POCname': frm.tf_POCname.value,
        'POCemail': frm.tf_emailAddr.value,
        'phone': frm.tf_POCphone.value
    };
    return parent.assoc3pProfile('di', caseID);
}
</script>

<input type="hidden" name="Submit" value="Submit"/>
<input type="hidden" name="addinvAuth" value="<?php echo $addinvAuth; ?>" />
<input type="hidden" name="formClass" value="<?php echo $formClass; ?>" />

<?php // track key values from InvitationControl ?>
<input type="hidden" name="dl" value="<?php echo $dialogLink; ?>" />

<div id="invform" class="stepFormHolder v-hidden">

<?php
if ($_SESSION['b3pAccess'] && $accCls->allow('acc3pMng') && !isset($_SESSION['profileRow3P'])) {
    $img = '<div';
    if ($accCls->allow('assoc3p')) {
        $onclk = "onclick=\"return relay3pAssoc(" . intval($caseID) . ")\"";
        $img .= $onclk . ' style="cursor:pointer"';
        $lbl = '<a href="javascript:void(0)" ' . $onclk . ' title="Associate 3P Profile">'
             . 'CLICK HERE</a> <span class="fw-normal">to associate a third party profile'
             . ' with this questionnaire.</span>';
    } else {
        $lbl = 'A third party profile must be associated with this questionnaire.<br />'
             . 'Your assigned role does not have that permission.';
    }
    $img .= '><span class="fas fa-plus" style="font-size: 20px;"></span><span class="fas fa-id-card"></span></div>';
    ?>
    <table cellpadding="0" cellspacing="0">
    <tr>
        <td><div class="ta-right marg-rtsm"><?php echo $img?></div></td>
        <td><?php echo $lbl?></td>
    </tr>
    </table>
    <?php
} ?>

<table border="0" cellpadding="3" cellspacing="0" style="margin-left:auto; margin-right:auto;">
    <?php if (isset($_SESSION['profileRow3P'])) { ?>
    <tr>
    <td height="29" width="183" class="fw-normal">Associated 3P Profile:</td>
    <td class="fw-normal"><?php echo $_SESSION['profileRow3P']->legalName?></td>
    </tr>
    <?php
}
// If no 3p and no pre-existing ddq, we need a name for it.
if (!$_SESSION['b3pAccess']) { // && !isset($ddqRow['name']) //Disabled for Company Name to display on re-invite. [DG]

    //Check for here and use if set. [DG]
    if(isset($ddqRow['name'])){
        $cmpCaseName=$ddqRow['name'];
    }else{
        $cmpCaseName="";
    }

    ?>
    <tr>
        <td height="32" class="redbold">Name of Company/Case:</td>
        <td><input name="tf_caseName" type="text" size="40" maxlength="255" value="<?php echo $cmpCaseName; ?>" /></td>
    </tr>
    <?php
}


// Initialize Point of Contact based on available data
$POCname = $ddqRow['POCname'] ?? '';
$POCposi = $ddqRow['POCposi'] ?? '';
$POCphone = $ddqRow['POCphone'] ?? '';
$POCemail = $ddqRow['POCemail'] ?? '';

$tpCountry = '';


if (!($_SESSION['b3pAccess'])) { ?>
    <tr>
      <td><div class="paddiv"><?php echo $accCls->trans->codeKey('country') ?>:</div></td>
      <td>
    <?php
    if (isset($_POST['lb_caseCountry'])) {
        fCreateCountryList("lb_caseCountry", $_POST['lb_caseCountry'], 0);
    } else {
        $defCountry = ($caseRow) ? $caseRow[8]: '';
        fCreateCountryList("lb_caseCountry", $defCountry, 0);
    }
    ?>
      </td>
    </tr>
    <tr>
    <td width="183"><div class="paddiv" data-no-trsl-cl-labels-sel="1"><?php echo $_SESSION['regionTitle']; ?>:</div></td>
    <td width="358" data-no-trsl-cl-labels-sel="1">
    <?php
    switch ($_SESSION['userType']) {
    case SUPER_ADMIN:
    case CLIENT_ADMIN:
        if (isset($_POST['lb_region'])) {
            fCreateDDLfromDB("lb_region", "region", $_POST['lb_region'], 0, 1);
        } elseif (isset($caseRow[4])) {
            fCreateDDLfromDB("lb_region", "region", $caseRow[4], 0, 1);
        } else {
            fCreateDDLfromDB("lb_region", "region", $_SESSION['userRegion'], 0, 1);
        }
        break;
    case CLIENT_MANAGER:
        echo "<select id=\"lb_region\" name=\"lb_region\" data-no-trsl-cl-labels-sel='1'>";
        if (isset($_POST['lb_region'])) {
            fFillUserLimitedRegion($_SESSION['mgrRegions'], $_POST['lb_region']);
        } elseif (isset($caseRow[4])) {
            fFillUserLimitedRegion($_SESSION['mgrRegions'], $caseRow[4]);
        } else {
            fFillUserLimitedRegion($_SESSION['mgrRegions'], $_SESSION['userRegion']);
        }
        echo "</select>";
        break;
    case CLIENT_USER:
        echo "<select id=\"lb_region\" name=\"lb_region\" data-no-trsl-cl-labels-sel='1'>";

        if (isset($_POST['lb_region'])) {
            fFillUserLimitedRegion($_SESSION['userRegion'], $_POST['lb_region']);
        } else {
            fFillUserLimitedRegion($_SESSION['userRegion'], $_SESSION['userRegion']);
        }
        echo "</select>";
        break;

    default:
        exit("<br/>ERROR - userType NOT Recognized<br/>");
    } // end switch

      ?>
    </td>
    </tr>
<?php
} elseif (isset($_SESSION['profileRow3P'])) {
    $POCname = $_SESSION['profileRow3P']->POCname;
    $POCposi = $_SESSION['profileRow3P']->POCposi;
    $POCphone = $_SESSION['profileRow3P']->POCphone1;
    $POCemail = $_SESSION['profileRow3P']->POCemail;
    $tpCountry = $_SESSION['profileRow3P']->country;
}
?>
  <tr>
    <td colspan="2"><div class="marg-topsm fw-normal"><i>Point of Contact</i></div></td>
  </tr>
  <tr>
    <td><div class="indent redbold">Name:</div></td>
    <td><input name="tf_POCname" type="text" size="40"  maxlength="255"
        value="<?php echo $POCname; ?>" class="no-trsl" /></td>
  </tr>
  <tr>
    <td><div class="fw-normal indent">Position:</div></td>
    <td><input name="tf_POCposi" type="text" size="40"  maxlength="255"
        value="<?php echo $POCposi; ?>" class="no-trsl"/></td>
  </tr>
  <tr>
    <td><div class="fw-normal indent">Phone Number:</div></td>
    <td><input name="tf_POCphone" type="text" size="40"  maxlength="255"
        value="<?php echo $POCphone; ?>" class="no-trsl"/>
    </td>
  </tr>
  <tr>
    <td><div class="redbold indent paddiv">Email Address: </div></td>
    <td><input name="tf_emailAddr" type="text" size="40"  maxlength="255"
        value="<?php echo $POCemail; ?>" class="no-trsl"/>
    </td>
  </tr>
  <tr>
    <td><div class="indent redbold">Confirm Email: </div></td>
    <td><input name="tf_confirmEmail" type="text" size="40"  maxlength="255"
        value="<?php echo $POCemail; ?>" class="no-trsl"/></td>
  </tr>
<?php
$ilSQL = "SELECT id, name FROM intakeFormInviteList WHERE clientID = $clientID";
if ($fromCfgFtrEnabled && ($inviteList = $dbCls->fetchKeyValueRows($ilSQL))) {
    ?>
    <td class="fw-normal">From:</td>
    <td>
        <select id="fromEmailAddr" name="fromEmailAddr" class="cudat-medium" data-tl-ignore="1">
        <option value="0" selected><?php echo $_SESSION['userEmail']; ?></option>
    <?php
    foreach ($inviteList as $id => $name) {
        if ($name != $_SESSION['userid']) {
            echo "<option value=\"{$id}\">{$name}</option>";
        }
    }
    ?>
    </select>
    </td>
    <?php
}

    if (in_array($clientID, [TEVAQC_CLIENTID, TEVA_CLIENTID])) {
        $CCaddrs = $caseRow['requestor'] ?? $_SESSION['userid'];
        // try to get the user's email address
        $sql = "SELECT userEmail FROM " . GLOBAL_DB . ".users\n"
            . "WHERE userid = '" . $dbCls->esc($CCaddrs) . "' LIMIT 1";
        if (($em = $dbCls->fetchValue($sql)) && bValidEmail($em)) {
            $CCaddrs = $em;
        }
?>
  <tr>
    <td><div class="redbold indent paddiv">CC addresses, separate with a comma: </div></td>
    <td><input name="tf_CCaddrs" type="text" size="40"  maxlength="255"
        value="<?php echo fixEmailAddr($CCaddrs); ?>"/>
    </td>
  </tr>

<?php } ?>

  <tr>
    <td><div class="redbold">Intake Form Version:</div></td>
    <td><select name="ddqName" id="ddqName" class="cudat-medium"
        onchange="return checkLang.opRequest('checkLangs');" data-no-trsl-cl-labels-sel="1">
<?php
$sel = ' selected="selected"';
if (count($formList) != 1) {
    echo '  <option value="">Choose...</option>', "\n";
}
foreach ($formList as $lid => $name) {
    $s = ($lid == $icLegacyID) ? $sel : '';
    echo '  <option value="', $lid, '"', $s, '>', $name, '</option>', "\n";
}
?>
    </select><input type="hidden" name="origDDQ" value="<?php echo $legacyID; ?>" />
    </td>
  </tr>
  <tr>
    <td class="fw-normal">Language:</td>
    <td><select id="lb_EMlang" name="lb_EMlang" class="cudat-medium" data-tl-ignore="1">
        <option value="EN_US">English</option>
        </select>
    </td>
  </tr>
  <tr>
<?php
$ck = ' checked="checked"';
if (isset($_SESSION['ddqInvite']->subStat)) {
    $subStat = $_SESSION['ddqInvite']->subStat;
} else {
    $subStat = '';
}
$pCk = ($rbSubstat == 'Prospective' || $subStat == 'Prospective') ? $ck : '';
$cCk = ($rbSubstat == 'Current' || $subStat == 'Current') ? $ck : '';
    ?>
    <td height="29" class="fw-normal">Partner Status?</td>
    <td><input name="rb_subStat" type="radio"
        id="rb_subStat1" class="rad" value="Prospective"<?php echo $pCk?> />
        <label class="rad fw-normal" for="rb_subStat1">Prospective</label>
        <input name="rb_subStat" type="radio"
        id="rb_subStat2" class="rad" value="Current"<?php echo $cCk?> />
        <label class="rad fw-normal" for="rb_subStat2">Current </label></td>
  </tr>

</table>

<br/>

<?php
} else {
    echo '<p style="margin-left: 2em;">', $haltMsg, '</p>', PHP_EOL;
} ?>

<table border="0" width="325" cellpadding="0" cellspacing="8"
    class="paddiv" style="margin-left:auto; margin-right:auto;">
  <tr>
<?php
if (!$haltMsg) {
    ?>
     <td id="sendInvContainer" nowrap="nowrap" width="175" class="submitLinks paddiv">
        <a href="javascript:void(0)"
        onclick="return submittheform('Send Invitation')" >Send Invitation</a>
     </td>
    <?php
} ?>
     <td nowrap="nowrap" class="submitLinksCancel paddiv">
        <a href="javascript:void(0)" onclick="top.onHide();" >Cancel</a>  </td>
  </tr>
</table>
</div>
</form>

<?php
if ($haltMsg) {
    noShellFootLogin();
    exit;
}
?>

<script type="text/javascript">
YAHOO.util.Event.onDOMReady(function(o){
    var Dom = YAHOO.util.Dom;
    var reg, el, i, mw = 0;
    var ids = ['region', 'dept', 'EMlang'];
    var els = [];
    for (i in ids)
    {
        el = Dom.get('lb_' + ids[i]);
        els[els.length] = el;
        reg = Dom.getRegion(el);
        if (reg.width > mw) mw = reg.width;
    }
    if (mw)
    {
        for (i in els)
            Dom.setStyle(els[i], 'width', mw + 'px');
    }
    Dom.replaceClass('invform', 'v-hidden', 'v-visible');
});

<?php // choose languages based on ddq type ?>
YAHOO.namespace('checkLang');
checkLang = YAHOO.checkLang; <?php // external reference ?>
checkLang.opActive = false;
(function()
{
    var Dom   = YAHOO.util.Dom;
    var Event = YAHOO.util.Event;
    var Lang  = YAHOO.lang;
    var Util  = YAHOO.cms.Util;

    var defaultLang = '<?php echo $defaultLang; ?>';
    var tpCountry = '<?php echo $tpCountry; ?>';
    var legacyID = '<?php echo $legacyID; ?>';
    checkLang.opRequest = function(op, arg1)
    {
        if (!checkLang.opActive) {
            return false;
        }
        var postData = "op=" + op;
        switch (op) {
        case 'checkLangs':
            if (arg1 != -1) {
                defaultLang = Util.getSelectValue('lb_EMlang');
            }
            var ddqName = YAHOO.util.Dom.get('ddqName').value;
            var reInvite = <?php echo ($reInvite) ? 1 : 0; ?>;
            postData += "&ddqName=" + ddqName
                + '&defLang=' + encodeURIComponent(defaultLang)
                + '&tpCountry=' + encodeURIComponent(tpCountry);
            if (reInvite == 1 && ddqName != legacyID) {
                var title = "Warning";
                var msg = "By changing the Intake form type on a re-invite any existing "
                    + "answers on the current form will be erased when you click "
                    + "Send Invitation.";
                parent.showResultDiag(title, msg);
            }
            break;
        default:
            alert('operation not configured');
            return false;
        }

        var handleOperation = function(o) {
            var res = false;
            var op = o.argument[0];
            try {
                res = Lang.JSON.parse(o.responseText);
            } catch (ex) {
    <?php
if ($session->secure_value('accessLevel') == SUPER_ADMIN) {
    ?>
                alert(o.responseText);
    <?php
} else {
    ?>
                alert('An error occurred while communicating with the server');
    <?php
}
    ?>
                return;
            }
            switch (op) {
            case 'checkLangs':
                if (res.Result ==1) {
                    defaultLang = res.defaultLang;
                    if (res.countryLang) {
                        defaultLang = res.countryLang
                    }
                    Util.populateSelect('lb_EMlang', res.Langs, defaultLang);
                } else {
                    parent.showResultDiag(res.ErrTitle, res.ErrMsg);
                }
                break;
            }
        };

        var sUrl = '<?php echo $sitepath; ?>case/add_ddqinvite-ws.php';
        var callback = {
            success: handleOperation,
            failure: function(o){
    <?php
if ($_SESSION['userType'] > CLIENT_ADMIN) {
    ?>
                alert("Failed connecting to " + sUrl);
    <?php
} else {
    ?>
                var a = 1; <?php // do nothing ?>
<?php
}
    ?>
            },
            argument: [op],
            cache: false
        };
        var request = YAHOO.util.Connect.asyncRequest('POST', sUrl, callback, postData);

        return false; <?php // cancel href action ?>
    };

    checkLang.opActive = true; <?php //enable links ?>
    checkLang.opRequest('checkLangs', -1); <?php //Fire initially to get original list ?>
})();
</script>

<!--   End Center Content-->
<?php
noShellFootLogin(true);
