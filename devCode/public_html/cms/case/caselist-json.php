<?php
/**
 * Display Case List
 *
 * CS compliance: grh - 08/19/2012
 */

// Prevent direct access
if (realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__) {
    exit;
}
if (!isset($_SESSION['gsrch'])) {
    return;
}

require_once __DIR__ . '/../includes/php/'.'caselist_inc.php';
require_once __DIR__ . '/../includes/php/Models/TPM/CustomFieldConflict.php';
$caseListPgAuth = PageAuth::genToken('caseListPgAuth');
$caseWsAuth = PageAuth::genToken('caseWsAuth');

$gsrchSrc = 'CL';
if (array_key_exists('caseFilterSrc', $_SESSION)) {
    $gsrchSrc = $_SESSION['caseFilterSrc'];
}
if ($initSearch
    && ($gsrchSrc == 'SB' || $gsrchSrc == 'AF')
) {
    // Remove all filter values
    $sessKey = 'stickyCL';
    $_SESSION[$sessKey]['lb_status'] = 'all_cases';
    $_SESSION[$sessKey]['lb_stage'] = 'all_stages';
    $_SESSION[$sessKey]['si'] = 0;
    $_SESSION[$sessKey]['tr'] = 0;
    $_SESSION[$sessKey]['lb_region'] = '';
    if ($userClass == 'vendor') {
        $_SESSION[$sessKey]['cname'] = '';
        $_SESSION[$sessKey]['iname'] = '';
    }
}

if ($gsrchSrc == 'AF' || $gsrchSrc == 'SB') {
    $gsrch = $_SESSION['gsrch'][$gsrchSrc]['cs'];
    $gsrch['mode'] = 'cs';
} else {
    $gsrch = $_SESSION['gsrch'][$gsrchSrc];
}
$csf_srch = $gsrch['srch'];
$csSrchFields = srchFields('cs', $gsrch['scope']);
if ($gsrchSrc == 'AF') {
    $csf_srch = $gsrch['srch'][0];
    $csSrchFld = $gsrch['flds'][0];
} else {
    $csf_srch = $gsrch['srch'];
    $csSrchFld = $gsrch['fld'];
}
$csSrchLbl = $csSrchFields[$csSrchFld];

$defOrderBy = 'casenum';
if ($userClass == 'vendor') {
    $hasCaseCustomFieldConflicts = false;
    $iFilters = 1;
    $showIcons = 0;

    // max num results to use multi-assign cases. (*** also set in caselist-ws.php ***)
    $maCaseMax = 400;
    $allowOrderBy = ['casenum', 'clientname', 'casename', 'casetype', 'stage', 'investiagent', 'assigned', 'duedate', 'iso2', 'dlvry'];
} else {
    $hasCaseCustomFieldConflicts = (new CustomFieldConflict((int)$_SESSION['clientID']))->hasCaseConflict();
    $iFilters = 0;
    $showIcons = ($_SESSION['userSecLevel'] > READ_ONLY
        && (($accCls->allow('addCase'))
        || ($accCls->allow('sendInvite')
        && $_SESSION['bDDQinviteProc']))
    );
    $allowOrderBy = ['casenum', 'casename', 'casetype', 'stage', 'requester', 'region', 'iso2'];
}

$minPP = 15;
$maxPP = 100;

$startIdx = 0;
$recsPerPage = $minPP;
$ttlRecs = 0;
$orderBy = $defOrderBy;
$sortDir = 'yui-dt-asc';
$cname = $iname = $lb_region = "";

// Sticky filter values overrid defaults
$sessKey = 'stickyCL';
if (isset($_SESSION[$sessKey]['ord'])) {
    if (isset($_SESSION[$sessKey]['tr'])) {
        $ttlRecs = intval($_SESSION[$sessKey]['tr']);
    }
    if (isset($_SESSION[$sessKey]['si'])) {
        $startIdx = intval($_SESSION[$sessKey]['si']);
    }
    $recsPerPage = intval($_SESSION[$sessKey]['pp']);
    if ($recsPerPage < $minPP) {
        $recsPerPage = $minPP;
    } elseif ($recsPerPage > $maxPP) {
        $recsPerPage = $maxPP;
    }
    if ($startIdx < 0) {
        $startIdx = 0;
    }
    $sortDir = ($_SESSION[$sessKey]['dir'] == 'yui-dt-desc') ? 'yui-dt-desc' : 'yui-dt-asc';
    if (in_array($_SESSION[$sessKey]['ord'], $allowOrderBy)) {
        $orderBy = $_SESSION[$sessKey]['ord'];
    }
    // Filter values
    //$filterKeys = array('status','dates','stage','region');
    $filterKeys = ['status', 'stage', 'region'];
    foreach ($filterKeys as $k) {
        $k2 = 'lb_' . $k;
        if (isset($_SESSION[$sessKey][$k2])) {
            ${$k2} = $_SESSION[$sessKey][$k2];
        }
    }

    if ($iFilters) {
        $cname = '';
        $iname = '';
        if (isset($_SESSION[$sessKey]['cname'])) {
            $cname = $_SESSION[$sessKey]['cname'];
        }
        if (isset($_SESSION[$sessKey]['iname'])) {
            $iname = $_SESSION[$sessKey]['iname'];
        }
    }
}
// @boolean, sets to true if AWS_ENABLED is set to true in the environment
$flagSet = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN);

/*
 * 2012-11-09: grh
 *
 * I took out the floating elements above the Case List table.  They were behaving very
 * badly, and it needed to be fixed quickly, before Kenn and Todd's Pfizer presentation.
 *
 * Whet's here now is an old-school table -- nothing fancy, but it doesn't fall apart when
 * Adv. Find hint text gets busy like the previous version with floats did.
 *
 * I'm about to go make the same transitioni for the heading above 3P List for the same
 * reason.
 */

?>

<div class="fix-ie6-hdr marg-botsm" style="margin-left:0px;">
<table summary="layout" style="margin: auto; width: 100%" cellspacing="0" cellpadding="0"><tr>

<td width="75%" valign="top"><div style="margin-left: 2px">

    <?php if ($hasCaseCustomFieldConflicts) { ?>
      <div class="cfConflictAlert" title="Click to list records with conflicts">
        Case Custom Field Conflicts
        <span class="warn">WARNING</span>
      </div>
    <?php } ?>

    <div style="margin-top: .7em"><table cellpadding="0" cellspacing="0"><tr>
        <td><div class="style11 marg-rtsm">Case List Filters</div></td>

        <td width="30">
            <div><img class="ihelp" hspace="4"
            src="<?php echo $sitepath; ?>images/inlinehelp.png"
            title="About search" alt="About search" onclick="clOpRequest('about-search')" /></div>
        </td>

        <td><span class="gap-left-lg"><a href="javascript:void(0)"
            onclick="caseListFilterReset();return false;"
            title="Restore default filter values">Reset View</a></span><?php
if ($gsrchSrc == 'AF') {
    ?>
          <span id="advfind-flag" class="style11 gap-left-lg">Advanced Find Results:</span>
    <?php
} elseif ($gsrchSrc == 'SB') {
    $SBscopeHint = ($gsrch['scope']) ? 'All records': 'My records';
    ?>
        <span id="advfind-flag" class="gap-left-lg">(Quick Search &ndash; <?php
    echo $SBscopeHint; ?>)</span>
    <?php
} ?>
</td>

</tr></table></div>

<?php
if ($gsrchSrc == 'AF') {
    ?>
       <div id="advfind-hint" class="marg-top1e marg-bot1e"><?php
          echo $_SESSION['caseAdvFindHint']; ?></div>
    <?php
} ?>

</div></td>


<?php // This cell holds the action icons ?>

<td valign="top" align="right">

<?php
if ($showIcons) {
    ?>

    <div class="iconMenu">
    <ul style="height:50px;">

    <?php
    unset($_SESSION['inviteClick']);
    if ($_SESSION['allowInvite'] && $accCls->allow('sendInvite') && !$_SESSION['b3pAccess']) {
        /*
            This Invite icon should be visible only in the absence of 3P being enabled if invitations
            are enabled.  It should never show anything other than an Invite option, because it
            can not be related to any specific case in this context. If clicked, it should present
            a list of base due-diligence intake forms, excluding any configured as renewal of other
            forms.
         */
        $e_clientID = $clientID = (int)$_SESSION['clientID'];
        include_once __DIR__ . '/../includes/php/'.'class_InvitationControl.php';
        $invCtrl = new InvitationControl($clientID, ['due-diligence'],
            $accCls->allow('sendInvite'), $accCls->allow('resendInvite')
        );
        $inviteControl = $invCtrl->getInviteControl(0); // invoke anonymous perspective
        if ($inviteControl['showCtrl']) {
            if ($inviteControl['useMenu']) {
                $inviteClick = "YAHOO.invmnu.showMenu()";
                $inviteTitle = "Intake Form Invitation Menu";
                $inviteHref  = 'javascript:void(0)';
                $inviteOpRequest = 'opRequest';
            } else {
                $inviteTitle = "Intake Form Invitation";
                $icFormType = key($inviteControl['actionFormList']);
                $icForm = current($inviteControl['actionFormList']);
                // above replaces the deprecated each from below. code left for brevity/context.
                // the reset shouldn't be needed as key/current do not advance the pointer.
                // however, also leaving that just in case as it won't hurt anything.
                //list($icFormType, $icForm) = each($inviteControl['actionFormList']);
                reset($inviteControl['actionFormList']);
                $inviteClick = "return GB_showPageInvite("
                    . "'/cms/case/add_ddqinvite.php?xsv=1&dl=" . $icForm['dialogLink'] . "')";
            }
            echo '<li><a href="javascript:void(0)" title="',
                $inviteTitle, '" onclick="', $inviteClick,'">',
                '<span class="fas fa-share-square"></span><br />',
                $inviteControl['iconLabel'], '</a></li>';
        }
    }
    if ($accCls->allow('addCase') && !$_SESSION['b3pAccess']) {
        // SEC-2395 has multi-tenant access?
        $clientID = (int)$_SESSION['clientID'];
        $sql = "SELECT tenantID FROM " . REAL_GLOBAL_DB . ".g_multiTenantAccess\n"
            . "WHERE tenantID = '$clientID' LIMIT 1";
        $hasUberSearch = ($dbCls->fetchValue($sql) == $clientID);
        $funcStr = ($hasUberSearch) ?
            "window.location = '/tpm/case/uber';" : "GB_showPage('/cms/case/addcase.php?xsv=1');return false;";
        // improve this after demo
        $fromUber = isset($_GET['uberReq']);

        if ($fromUber) { $funcStr = "GB_showPage('/cms/case/addcase.php?xsv=1');return false;"; }
        ?>
        <li>
            <a href="javascript:void(0)" title="New Case Folder" onclick="<?php echo $funcStr ?>">
                <span class="fas fa-folder-plus"></span><br />
                Add
            </a>
        </li>
        <?php
    } ?>

    </ul></div>

    <?php
    // end showIcons
} else {
    if ($_SESSION['userType'] == VENDOR_ADMIN) {
        $maShowMe = (!empty($ttlRecs) && $ttlRecs <= $maCaseMax) ? '' : ' style="display:none;"';
        echo '
    <div class="iconMenu">
        <ul style="height:50px;">
            <li id="maLink"'. $maShowMe .'>

                <a href="multi-assign.sec" title="Multi-Assign Cases">
                   <span class="fas fa-user-friends"></span>
                   <br />Multi-Assign
               </a>
            </li>
        </ul>
    </div>';
    } else {
        // placeholder
        echo $flagSet ? '<img src="/cms/images/spacer.gif" width="80" height="50" alt="">' : '<img src="/cms/images/spacer.gif" width="80" height="50" alt="">' . "\n";
    }
} ?>
</td>

</tr></table>
</div>

<?php // End area above list filters ?>



<form id="form1000" name="form1000" method="POST" style="margin:0 0 1em 0;"
    action="<?php echo $_SERVER['PHP_SELF']?>">
<table cellspacing="0" cellpadding="0" style="margin: auto;">
  <tr>
    <td class="tdbot0"><div class="filter-lbl" style="width:125px;"><a
        class="vizlink <?php echo $flagSet ? 'url-aws' : ''; ?>" href="javascript:void(0)" onclick="return showCsSrchPnl(this);"
        title="Click to change search field"><span
        id="cs-srch-lbl"><?php echo $csSrchLbl?></span></a></div></td>
    <td class="tdbot0"><div class="filter-lbl">Status</div></td>
    <td class="tdbot0"><div class="filter-lbl">Stage</div></td>
<?php
if ($_SESSION['userType'] > VENDOR_ADMIN) {
    ?>
    <td class="tdbot0"><div class="filter-lbl"><?php echo $_SESSION['regionTitle']?></div></td>
    <?php
} else { ?>
    <td class="tdbot0"><div class="filter-lbl">Client</div></td>
    <td class="tdbot0"><div class="filter-lbl">Investigator
    <?php
} ?>
  </tr>
  <tr>
    <td><div class="tpl-liner"><input type="text" id="csf_srch"
        value="<?php echo $csf_srch; ?>" onkeyup="findClTyping('stop')"
        onkeydown="findClTyping('start')" style="width:120px"/></div></td>
    <td><div class="tpl-liner"><select name="lb_status" id="lb_status"
        onchange="caseListStatusUpdate()">
      <?php fLoadStatusDDL();?>
      </select></div></td>
    <td><div class="tpl-liner"><select name="lb_stage" id="lb_stage"
        onchange="refreshDT()" style="width:155px;">
      <?php fLoadStageDDL(); ?>
      </select></div></td>

<?php
/*
    <td width="120"><strong>Date Created</strong><br />
      <select name="lb_dates" id="lb_dates" onchange="refreshDT()">
      <  ?php fLoadDatesDDL();?  >
      </select>
    </td>
 */

$hasRegionFilter = 0;
if ($userClass != 'vendor') {
    $hasRegionFilter = 1;
    echo '<td><div class="tpl-liner">';
    switch ($_SESSION['userType']) {
    case SUPER_ADMIN:
    case CLIENT_ADMIN:
        if (isset($_SESSION[$sessKey]['lb_region'])) {
            fCreateDDLfromDB("lb_region", "region", $_SESSION[$sessKey]['lb_region'], 0, 1);
        } else {
            fCreateDDLfromDB("lb_region", "region", 1, 0, 1);
        }
        break;
    case CLIENT_MANAGER:
        echo "<select id=\"lb_region\" name=\"lb_region\" data-no-trsl-cl-labels-sel='1'>\n";
        echo "<option value=\"\" >Any</option>\n";
        $regions = ($gsrch['scope'] == 1) ? '' : $_SESSION['mgrRegions'];
        if (isset($_SESSION[$sessKey]['lb_region'])) {
            fFillUserLimitedRegion($regions, $_SESSION[$sessKey]['lb_region']);
        } else {
            fFillUserLimitedRegion($regions, 1);
        }
        echo "</select>";
        break;
    case CLIENT_USER:
        echo "<select id=\"lb_region\" name=\"lb_region\" data-no-trsl-cl-labels-sel='1'>\n";
        echo "<option value=\"\" >Choose...</option>\n";
        if (isset($_SESSION[$sessKey]['lb_region'])) {
            fFillUserLimitedRegion($_SESSION['userRegion'], $_SESSION[$sessKey]['lb_region']);
        } else {
            fFillUserLimitedRegion($_SESSION['userRegion'], '');
        }
        echo "</select>";
        break;

    default:
        exit("<br/>ERROR - userType NOT Recognized<br/>");
    }
    echo "</div></td>\n";
    //. "<td align=\"right\" width=\"120\"> </td>\n";
    // end non-vendor user

} else {
    // Provider filters:  client and investigator
    $hasRegionFitler = 0;
    echo <<<EOT

    <td><div class="tpl-liner"><input id="tf_cname" type="text" value="$cname" class="cudat-small"
        onkeyup="findClTyping('stop')" onkeydown="findClTyping('start')" /></div></td>
    <td><div class="tpl-liner"><input id="tf_iname" type="text" value="$iname" class="cudat-small"
        onkeyup="findClTyping('stop')" onkeydown="findClTyping('start')" /></div></td>

EOT;

} ?>

  </tr>
</table>
</form>

<?php

if ($hasRegionFilter) {
    echo <<<EOT

<script type="text/javascript">
    YAHOO.cms.Util.selectOption("lb_region", "$lb_region");
    YAHOO.util.Event.addListener("lb_region", "change", function(o){refreshDT();});
</script>

EOT;

}

if ($userClass == 'vendor') {
    $csSrchFldSize = 7; // no requester or department
} else {
    if ($_SESSION['userType'] == CLIENT_USER) {
        $csSrchFldSize = 7; // no requester
    } else {
        $csSrchFldSize = 8;
    }
}

if ($enableWaitSpinner) {
    echo '<div id="waitspinDiv"  style="margin-left: 5px;"></div>', PHP_EOL;
}

?>

<div id="casesDiv"<?php
if (isset($_SESSION['casesDiv-h'])) {
    echo ' style="min-height:' . $_SESSION['casesDiv-h'] . 'px;"';
} ?>>&nbsp;</div>

<div id="cs-srch-pnl" class="disp-none">
  <div class="hd">Set Search Field</div>
  <div class="bd"><select class="marg-rt0" size="<?php echo $csSrchFldSize?>"
    id="cs-srch-fld" onclick="setCsSrchFld()">
<?php
foreach ($csSrchFields as $f => $l) {
    $s = ($f == $csSrchFld) ? ' selected="selected"' : '';
    echo '<option value="' . $f . '"' . $s . '>' . $l . '</option>' . "\n";
} ?>
</select></div>
</div>

<script type="text/javascript">

<?php  if ($hasCaseCustomFieldConflicts) {  ?>
$('div.cfConflictAlert').on('click', function() {
  document.location = '/tpm/rpt/cfConflict/case';
});
<?php  }  ?>

var clUserClass = '<?php echo $userClass?>';
var myCaseList = null;
var csSrchFld = '<?php echo $csSrchFld?>';
var csSrchPanel = null;
<?php
// this will set a variable to check for
if ($_SESSION['userType'] == VENDOR_ADMIN) {
    echo 'var maIcon = true;';
} else {
    echo 'var maIcon = false';
}
?>

function setCsSrchFld()
{
    var sel = Dom.get('cs-srch-fld');
    var fld = sel[sel.selectedIndex].value;
    if (fld != csSrchFld)     {
        var el = Dom.get('csf_srch');
        el.value = '';
        Dom.get('cs-srch-lbl').innerHTML = sel[sel.selectedIndex].text;
        csSrchFld = fld;
        csSrchPanel.hide();
        el.focus();
        refreshDT();
    } else {
        csSrchPanel.hide();
    }
}

function showCsSrchPnl(sel)
{
    var pnl = csSrchPanel;
    if (pnl == null) return false;
    if (!pnl.rendered) {
        pnl.render(document.body);
        Dom.replaceClass('cs-srch-pnl', 'disp-none', 'disp-block');
        pnl.rendered = true;
    }
    var xy = Dom.getXY(sel);
    pnl.cfg.setProperty('xy', [xy[0]-5, xy[1]-50]);
    pnl.show();
    return false;
}
var caseWsAuth = '<?php echo $caseWsAuth; ?>';
(function(){
    var Dom = YAHOO.util.Dom;
    var Event = YAHOO.util.Event;
    var Util = YAHOO.cms.Util;

    var caseListPgAuth = '<?php echo $caseListPgAuth; ?>';


    var findClTimer = 0;

    triggerFindCl = function()
    {
        findClTyping('start');
        refreshDT();
    };

    findClTyping = function(action){
        var tid;
        <?php // clear timer if active ?>
        if (findClTimer != 0) {
            tid = findClTimer;
            if (!YAHOO.lang.isNull(tid)) {
                clearTimeout(tid);
            }
            if (findClTimer == tid) {
                findClTimer = 0;
            }
        }
        if (action == 'stop') {
            findClTimer = setTimeout("triggerFindCl()", 1250);
        }
    };

    var mkCaseList = function(o)
    {
        var showTimer, hidTimer;
        var countryTip = function(elCell, oRecord, oColumn, oData)
        {
            var c = oRecord.getData('country');
            var elTd = Dom.getAncestorByTagName(elCell, 'td');
            if (elTd) {
                Dom.setAttribute(elTd, 'title', c);
            } else {
                Dom.setAttribute(elCell, 'title', c);
            }
            elCell.classList.add("no-trsl");
            elCell.setAttribute("data-tl-ignore", "1");
            elCell.innerHTML = oData;
        };

        var batchTip = function(elCell, oRecord, oColumn, oData)
        {
            var b = oRecord.getData('batchid');
            var elTd = Dom.getAncestorByTagName(elCell, 'td');
            var sym = '';
            if (b > 0) {
                var t = 'Batch #' + b;

                sym = ' &laquo;';
                if (elTd) {
                    Dom.setAttribute(elTd, 'title', t);
                } else {
                    Dom.setAttribute(elCell, 'title', t);
                }
            }
            elCell.innerHTML = oData + sym;
        };

        var fmt3pLink = function(elCell, oRecord, oColumn, oData)
        {
            var cursor, img;
            var tip = '';
            var flagSet = '<?php echo $flagSet; ?>';
            if (oData > 0) {
                if (oRecord.getData('tpOk') == 1) {
                    cursor = 'pointer';
                    tip = 'Go to 3P Profile';
                } else {
                    cursor = 'default';
                }
                img = flagSet ? 'url(/cms/images/3plinked-8.png)' : 'url(/cms/images/3plinked-8.png)';
            } else {
                cursor = 'pointer';
                tip = 'Create link to 3P Profile';
                img = flagSet ? 'url(/cms/images/3pnotlinked-8.png)' : 'url(/cms/images/3pnotlinked-8.png)';
            }
            var elTd = Dom.getAncestorByClassName(elCell, 'yui-dt-col-tpID');
            Dom.setStyle(elTd, 'cursor', cursor);
            Dom.setStyle(elTd, 'background-image', img);
            Dom.setAttribute(elTd, 'title', tip);
            elCell.innerHTML = ' ';
        };

        myCaseList = function() {
            YAHOO.widget.DataTable.preventTranslations = function(elLiner, oRecord, oColumn, oData) {
                elLiner.classList.add("no-trsl");
                elLiner.innerHTML = oData;
                elLiner.setAttribute("data-tl-ignore", "1");
            };

            var myColumnDefs = [];
            var DSflds = [];
            var infoSourceTip = function(elCell, oRecord, oColumn, oData) {

                var infoSource = "Information Source: " + (oRecord.getData('infoSource') ? oRecord.getData('infoSource') : "N/A");

                var elTd = Dom.getAncestorByTagName(elCell, 'tr');
                if (elTd) {
                    Dom.setAttribute(elTd, 'title', infoSource);
                } else {
                    Dom.setAttribute(elCell, 'title', infoSource);
                }
                elCell.classList.add("no-trsl");
                elCell.innerHTML = oData;
                elCell.setAttribute("data-tl-ignore", "1");
            };

            if (clUserClass == 'vendor') {
                DSflds = [
                    "dbid",
                    "dbid2",
                    "casenum",
                    "clientname",
                    "casename",
                    "casetype",
                    "stage",
                    "investiagent",
                    "assigned",
                    "duedate",
                    "iso2",
                    "country",
                    "batchid",
                    "infoSource",
                    "dlvry"
                ];
                myColumnDefs = [
                    {key:"casenum", label:"Case #", sortable:true, formatter: batchTip, width: 65},
                    {key:"clientname", label:"Client", sortable:true, formatter: infoSourceTip, width: 48},
                    {key:"casename", label:"Case Name", sortable:true, width: 134, formatter: YAHOO.widget.DataTable.preventTranslations},
                    {key:"casetype", label:"Type", sortable:true, width: 38},
                    {key:"stage", label:"Stage", sortable:true, width: 87},
                    {key:"investiagent", label:"Investigator", sortable:true, width: 58},
                    {key:"assigned", label:"Assigned Date", sortable:true, width: 77},
                    {key:"duedate", label:"Due Date", sortable:true, width: 66},
                    {key:"iso2", label:"Country", sortable:true, formatter: countryTip, width: 10},
                    {key:"dlvry", label:"Delivery", sortable:true, width: 10}
                ];
            } else {
                <?php // non-vendor type ?>
                DSflds = [
                    "dbid",
                    "tpID",
                    "tpOk",
                    "casenum",
                    "casename",
                    "casetype",
                    "stage",
                    "requester",
                    "region",
                    "iso2",
                    "country",
                    "infoSource"
                ];
<?php
if ($b3pAccess) {
    ?>
                myColumnDefs = [
                    {key:"tpID",label:"3P", sortable:false, formatter: fmt3pLink, width: 10},
                    {key:"casenum",label:"Case #", sortable:true, formatter: infoSourceTip, width: 65},
                    {key:"casename",label:"Case Name", sortable:true, width: 127, formatter: YAHOO.widget.DataTable.preventTranslations},
                    {key:"casetype",label:"Case Type", sortable:true, width: 127},
                    {key:"stage",label:"Stage", sortable:true, width: 85},
                    {key:"requester",label:"Requester", sortable:true, width: 109},
                    {key:"region",label:"<?php echo $_SESSION['regionTitle'];?>",
                        sortable:true, width: 72},
                    {key:"iso2",label:"&nbsp;", sortable:true, formatter: countryTip, width: 20}
                ];
    <?php
} else {
    ?>
                myColumnDefs = [
                    {key:"casenum",label:"Case #", sortable:true, width: 65},
                    {key:"casename",label:"Case Name", sortable:true, formatter: infoSourceTip, width: 127},
                    {key:"casetype",label:"Case Type", sortable:true, width: 150 },
                    {key:"stage",label:"Stage", sortable:true, width: 85},
                    {key:"requester",label:"Requester", sortable:true, width: 119},
                    {key:"region",label:"<?php echo $_SESSION['regionTitle'];?>",
                        sortable:true, width: 72},
                    {key:"iso2",label:"&nbsp;", sortable:true, formatter: countryTip, width: 20}
                ];
    <?php
} ?>
            } <?php // non-vendor type ?>

            var myDataSource = new YAHOO.util.XHRDataSource("<?php
                echo $sitepath; ?>case/caselist-ws.php");
            myDataSource.responseType = YAHOO.util.XHRDataSource.TYPE_JSON;
            myDataSource.responseSchema = {
                resultsList: "Response.Records",
                fields: DSflds,
                metaFields: {
                    totalRecords: "Response.Total",
                    rowsPerPage: "Response.RowsPerPage",
                    recordOffset: "Response.RecordOffset",
                    page: "Response.Page",
                    PgAuth: "Response.PgAuth",
                    PgAuthErr: "Response.PgAuthErr",
                    multiAssign: "Response.MultiAssign"
                }
            };
<?php
if (SECURIMATE_ENV == 'Development' || $session->secure_value('accessLevel') == SUPER_ADMIN) {
    ?>
            myDataSource.subscribe('dataErrorEvent', function(o){
                alert('626: ' + YAHOO.lang.dump(o.response.responseText, 1));
            });
    <?php
} ?>
            var caseListFilterQuery = function()
            {
                <?php // Get filter values ?>
                var reg = "", dts = "";
                var ret = "";
                var stg  = Dom.get("lb_stage").value;
                var stat = encodeURIComponent(Dom.get("lb_status").value);

                if (clUserClass != 'vendor') {
                    reg  = encodeURIComponent(Dom.get("lb_region").value);
                }
                var fld = Util.getSelectValue('cs-srch-fld');
                var raw_srch = Dom.get('csf_srch').value;
                var p = globalSearch.p;
                if (myCaseList != null && myCaseList.oDT.gsrchRefresh) {
                    p.AF.cs.srch[0] = raw_srch;
                    p.AF.cs.flds[0] = fld;
                    if (globalSearch.Panel != null && p.AF.mode == 'cs') {
                        Dom.get('gsrchAF-srch1').value = raw_srch;
                        Util.selectOption('gsrchAF-fld1', fld);
                    }
                }
                var srch = encodeURIComponent(raw_srch);

                ret = "&stg=" + stg + "&stat=" + stat + "&reg=" + reg + '&srch='
                    + srch + "&fld=" + fld;
                if (clUserClass == 'vendor') {
                    ret += '&cname=' + encodeURIComponent(Dom.get('tf_cname').value);
                    ret += '&iname=' + encodeURIComponent(Dom.get('tf_iname').value);
                }
                return ret;
            };

            var myRequestBuilder = function(oState, oSelf)
            {
<?php
if ($enableWaitSpinner) {
    echo '                YAHOO.waitspin.start("waitspinDiv");', PHP_EOL;
} ?>
                <?php // Get states or use defaults ?>
                oState = oState || {pagination:null, sortedBy:null};
                var sort = (oState.sortedBy) ? oState.sortedBy.key : "casenum";
                var dir = (oState.sortedBy
                    && oState.sortedBy.dir == YAHOO.widget.DataTable.CLASS_DESC)
                    ? "yui-dt-desc"
                    : "yui-dt-asc";
                var startIndex = (oState.pagination) ? oState.pagination.recordOffset : 0;
                var results = (oState.pagination)
                    ? oState.pagination.rowsPerPage
                    : <?php echo $recsPerPage?>;
                <?php // Build custom request ?>
                return  ("?si=" + startIndex + "&pp=" + results + "&ord=" + sort
                    + "&dir=" + dir + caseListFilterQuery() + "&pgauth=" + caseListPgAuth);
            };

            var myConfigs = {
                draggableColumns:true,
                sortedBy:{key:"<?php echo $orderBy?>", dir:"<?php echo $sortDir?>" },
                paginator: new YAHOO.widget.Paginator({
                    alwaysVisible: true,
                    totalRecords: <?php echo $ttlRecs?>,
                    recordOffset: <?php echo $startIdx?>,
                    rowsPerPage: <?php echo $recsPerPage?>,
                    template: '{TotalRows} <span class="yui-pg-records">Records</span> '
                        + '<span class="yui-pg-extra">Page</span> {CurrentPageReport} '
                        + '{FirstPageLink} {PreviousPageLink} '
                        + '<span class="yui-pg-extra2">Jump to</span> '
                        + '{JumpToPageDropdown} {NextPageLink} {LastPageLink} '
                        + '{RowsPerPageDropdown} <span '
                        + 'class="yui-pg-extra">Records per Page</span>',
                    rowsPerPageOptions: [15,20,30,50,75,100]
                }),
                initialRequest: "<?php echo "?si=$startIdx&pp=$recsPerPage&ord=$orderBy"
                    . "&pgauth=$caseListPgAuth"
                    . "&dir=$sortDir"; ?>" + caseListFilterQuery(),
                generateRequest: myRequestBuilder,
                dynamicData: true
            };

            var myDataTable = new YAHOO.widget.DataTable("casesDiv", myColumnDefs,
                myDataSource, myConfigs
            );


            <?php // Turn off obnoxious "Loading..." message ?>
            var catchLoading = function(oArg) {
                if (oArg.className == "yui-dt-loading") {
                    this.hideTableMessage();
                }
            };
            myDataTable.on("tableMsgShowEvent", catchLoading);

            myDataTable.handleDataReturnPayload = function(oRequest, oResponse, oPayload)
            {
                var ttl, pp, si, li, mamx;
                si = oResponse.meta.recordOffset;
                pp = oResponse.meta.rowsPerPage;
                ttl = oResponse.meta.totalRecords;
                if (ttl == 0) {
                    li = 0;
                } else if (si + pp <= ttl) {
                    li = (si + pp) - 1;
                } else {
                    li = ttl - 1;
                }

<?php
if ($enableWaitSpinner) {
    echo '               YAHOO.waitspin.stop("waitspinDiv");', PHP_EOL;
}

if ($_SESSION['userType'] == VENDOR_ADMIN) {
    echo "
                if (maIcon == true && oResponse.meta.multiAssign == 1) {
                    Dom.get('maLink').style.display = 'block';
                } else {
                    Dom.get('maLink').style.display = 'none';
                }", PHP_EOL;
}
?>
                oPayload.totalRecords = ttl;
                oPayload.pagination.totalRecords = ttl;
                oPayload.pagination.rowsPerPage = pp;
                oPayload.pagination.recordOffset = si;
                oPayload.pagination.records = [si,li];
                oPayload.pagination.page = oResponse.meta.page;
                caseListPgAuth = oResponse.meta.PgAuth;
                if (oResponse.meta.PgAuthErr == 1) {
                    alert('<?php echo PageAuth::getFailMessage($_SESSION['languageCode']); ?>');
                }
                return oPayload;
            };

            var pickCase = function(oArg) {
                var col = myDataTable.getColumn(oArg.target);
                var rec = myDataTable.getRecord(oArg.target);
                if (col.key == 'tpID') {
                    var tpID = rec.getData('tpID');
                    if (tpID > 0) {
                        if (rec.getData('tpOk') == 1) {
                            clOpRequest('tpp-access', tpID);
                            return;
                        }
                    } else {
<?php
if ($accCls->allow('acc3pMng') && $accCls->allow('add3pProfile') && $accCls->allow('assoc3p')) {
    ?>
                        assoc3pProfile('cl', rec.getData('dbid'));
    <?php
} ?>
                    }
                } else {
                    if (clUserClass == 'vendor') {
                        clOpRequest('sp-case-access', rec.getData('dbid'), rec.getData('dbid2'));
                    } else {
                        clOpRequest('case-access', rec.getData('dbid'));
                    }
                    return;
                }
            };

            <?php // Enable row highlighting ?>
            myDataTable.subscribe("rowMouseoverEvent", myDataTable.onEventHighlightRow);
            myDataTable.subscribe("rowMouseoutEvent", myDataTable.onEventUnhighlightRow);
            myDataTable.subscribe("cellClickEvent", pickCase);
            myDataTable.subscribe("renderEvent", function(o){
                var reg = Dom.getRegion('casesDiv');
                clOpRequest('casesDiv-h', reg.height);
            });
            myDataTable.gsrchRefresh = <?php echo ($gsrchSrc == 'AF')
                ? 'true': 'false'; ?>;
            return {
                oDS: myDataSource,
                oDT: myDataTable
            };
        }();
    };
    mkCaseList();

    csSrchPanel = new YAHOO.widget.Panel('cs-srch-pnl', {
        visible: false,
        draggable: false,
        close: false,
        modal: false
    });
    csSrchPanel.rendered = false;
    YAHOO.util.Event.addListener('cs-srch-pnl', 'mouseout', function(e){
        var r = Dom.getRegion('cs-srch-pnl');
        var mXY = YAHOO.util.Event.getXY(e);
        var mX = mXY[0];
        var mY = mXY[1];
        if ((mX < r.x) || (mX >= r.right) || (mY < r.y) || (mY >= r.bottom)) {
            csSrchPanel.hide();
        }
    });
    Util.selectOption('cs-srch-fld', csSrchFld);

})();

<?php // null function to eat call from lb_region onchange event ?>
function explainText() { return; }

function caseListFilterReset()
{
    var Util = YAHOO.cms.Util;
    var Dom = YAHOO.util.Dom;
    Util.selectOption("lb_stage", "all_stages");
    Util.selectOption("lb_status", "only_open_cases");

<?php
if ($userClass != 'vendor') {
    ?>
    Util.selectOption("lb_region", "");
    <?php
} else {
    ?>
    Dom.get('tf_cname').value = '';
    Dom.get('tf_iname').value = '';
    <?php
} ?>
    Dom.get('csf_srch').value = '';
    if (myCaseList != null) {
        myCaseList.oDT.gsrchRefresh = false;
        Dom.replaceClass('advfind-flag', 'gap-left-lg', 'disp-none');
        Dom.addClass('advfind-hint', 'disp-none');
    }
    clOpRequest('rsf');
}

function caseListStatusUpdate()
{
    var Util = YAHOO.cms.Util;
    var Dom = YAHOO.util.Dom;
    var stat = Util.getSelectValue("lb_status");
    var opts, def = 'all_stages';
    if (stat == 'only_open_cases') {
        opts = [{v: 'all_stages', t: 'All Open Stages'}];
    } else if (stat == 'only_closed_cases') {
        opts = [{v: 'all_stages', t: 'All Closed Stages'}];
    } else {

        opts = [{v: 'all_stages', t: 'All Stages'}];
    }
    Util.populateSelect('lb_stage', opts, def);
    refreshDT();
    clOpRequest('refresh-stagelist');
}

function refreshDT()
{
    if (typeof myCaseList == 'undefined' || myCaseList == null) {
        return;
    }
    if (csSrchPanel != null) {
        csSrchPanel.hide();
    }
    var DT = myCaseList.oDT;
    var oState = DT.getState();
    var request = DT.get("generateRequest")(oState, DT);
    var oCallback = {
        success: function(oRequest, oResponse, oPayload)
        {
            var newPayload = DT.handleDataReturnPayload(oRequest, oResponse, oPayload);
            DT.onDataReturnSetRows(oRequest, oResponse, newPayload);

        },
        failure: function()
        {
<?php
if ($_SESSION['userType'] == SUPER_ADMIN) {
    ?>
            alert("Failed loading data.");
    <?php
} else {
    ?>
            var a = 1; <?php // do nothing ?>
<?php
} ?>
        },
        argument: oState,
        scope: DT
    };
    myCaseList.oDS.sendRequest(request, oCallback);
}

function clOpRequest(op, arg1, arg2)
{
    var Dom = YAHOO.util.Dom;
    var Lang = YAHOO.lang;
    var Util = YAHOO.cms.Util;

    var addOption = function(sel, val, txt, selectIt)
    {
        var opt = document.createElement('option');
        opt.text = txt;
        opt.value = val;
        try {
            sel.add(opt, null); <?php // compliant ?>
        } catch(ex) {
            sel.add(opt); <?php // IE ?>
        }
        if (selectIt) {
            sel.selectedIndex = sel.length - 1;
        }
    };

    var handleOperation = function(o) {
        var res, ex;
        try {
            res = Lang.JSON.parse(o.responseText);
            caseWsAuth = res.caseWsAuth;
            if (res.pgAuthErr) {
                showResultDiag(
                    '<?php echo PageAuth::getFailTitle($_SESSION['languageCode']); ?>',
                    '<?php echo PageAuth::getFailMessage($_SESSION['languageCode']); ?>'
                );
            }
        } catch (ex) {
<?php
if (SECURIMATE_ENV == 'Development' || $_SESSION['userType'] == SUPER_ADMIN) {
    ?>
            alert('929: JSON Error: ' + o.responseText);
    <?php
} ?>
            return;
        }
        switch (o.argument[0]) {
        case 'about-search':
            globalSearch.f.showMsgPanel(res.Help);
            break;
        case 'case-access':
        case 'sp-case-access':
        case 'tpp-access':
            if (res.Ok !== undefined) {
                switch (res.Ok) {
                case 1:
                    var dest = "/cms/case/casehome.sec?id=" + res.RecID + '&tname=casefolder' ;
                    var recType = 'Case';
                    switch (o.argument[0]) {
                    case 'sp-case-access':
                        dest += "&icli=" + res.Client;
                        break;
                    case 'tpp-access':
                        recType = 'Profile';
                        dest = "/cms/thirdparty/thirdparty_home.sec?id=" + res.RecID
                            + '&tname=thirdPartyFolder';
                        break;
                    }
                    window.location = dest;
                    break;
                case 2:
                    globalSearch.f.showGsRestrictedAccess(null, res.Contact);
                    break;
                case 0:
                    globalSearch.f.showGsRestrictedAccess('Access denied', res.Contact);
                    break;
                case -1: <?php // invalid profile ?>
                    globalSearch.f.showGsRestrictedAccess(recType + ' not accessible', res.Contact);
                    break;
                default: <?php // -2 gs exception ?>
                    globalSearch.f.showGsRestrictedAccess(recType + ' not accessible', res.Contact);
                }
            }
            break;

        case 'casesDiv-h':
            if (res.Result == 1) {
                Dom.setStyle('casesDiv', 'min-height', '0px');
            }
            break;

        case 'refresh-stagelist':
            if (res.Result == 1) {
                Util.populateSelect('lb_stage', res.Stages, res.SelectValue,
                    {v: res.SelectValue, t: res.AllText}
                );
            }
            break;

        case 'rsf': <?php // refresh stage filter on case list ?>
            if (res.Result == 1) {
                var sel = Dom.get('lb_stage');
                var startlen = sel.length;
                var opt, t, i;
                res.SelectValue = res.SelectValue;
                addOption(sel, 'all_stages', res.AllText, res.SelectValue == 'all_stages');
                for (t in res.Stages) {
                    addOption(sel, t, res.Stages[t], t == res.SelectValue);
                }
                <?php // remove previous options; avoids removing all if done first ?>
                for (i=0; i < startlen; i++) {
                    sel.remove(0); <?php // delete from top of list ?>
                }
                if (Dom.get('lb_region') != null) {
                    YAHOO.cms.Util.populateSelect('lb_region', res.Regions, '', {v: '', t: 'Any'});
                }
            }
            refreshDT();
            break;
        }
    };

    var postData = 'op=' + op + '&caseWsAuth=' + caseWsAuth;
    var sel1, sel2;
    switch (op) {
    case 'about-search':
        break;
    case 'sp-case-access':
        postData += '&icli=' + arg2;
        <?php // fall thru ?>
    case 'tpp-access':
    case 'case-access':
        postData += '&id=' + arg1;
        break;
    case 'casesDiv-h':
        postData += '&h=' + arg1;
        break;

    case 'refresh-stagelist':
        postData += '&stat=' + Util.getSelectValue('lb_status');
        break;

    case 'rsf':
        sel1 = Dom.get('lb_status');
        sel2 = Dom.get('lb_stage');
        postData += '&stat=' + sel1[sel1.selectedIndex].value
            + '&stg=' + sel2[sel2.selectedIndex].value;
        break;
    }

    var sUrl = '<?php echo $sitepath; ?>case/loadcase-ws.php';
    var callback = {
        success: handleOperation,
        failure: function(o){
<?php
if ($_SESSION['userType'] == SUPER_ADMIN) {
    ?>
            alert("Failed connecting to " + sUrl);
    <?php
} else {
    ?>
            var a = 1; <?php // do nothing ?>
<?php
} ?>
        },
        argument: arguments,
        cache: false
    };
    var request = YAHOO.util.Connect.asyncRequest('POST', sUrl, callback, postData);
};

</script>

<?php
if (isset($_GET['openAddCase'])) { ?>
<script>
    YAHOO.util.Event.onDOMReady(function(e) {
        GB_showPage('/cms/case/addcase.php?xsv=1');
    });
</script>
<?php } ?>

<?php

if (isset($inviteControl) && $inviteControl['useMenu']) {
    include_once __DIR__ . '/../includes/php/'.'inviteMenu.php';
}

// ############### Local Functions ###############

/**
 * Build Stage drop-down filter
 *
 * @return void
 */
function fLoadStageDDL()
{
    global $sessKey;

    // Set our Pre-Select Value
    if (!isset($_SESSION[$sessKey]['lb_stage'])) {
        $_SESSION[$sessKey]['lb_stage'] = "all_stages";
    }
    $szPreSelect = $_SESSION[$sessKey]['lb_stage'];
    $stages = caseStageListByStatus($_SESSION[$sessKey]['lb_status']);
    $allText = match ($_SESSION[$sessKey]['lb_status']) {
        'all_cases' => 'All Stages',
        'only_closed_cases' => 'All Closed Stages',
        default => 'All Open Stages',
    };
    $selected = false;
    $token = '{tmp_select}';
    $firstopt = "<option value=\"all_stages\"{$token}>$allText</option>\n";
    $opts = '';
    foreach ($stages as $val => $stage) {
        $sel = ($szPreSelect == $val) ? ' selected="selected"' : '';
        if ($sel) {
            $selected = true;
            $_SESSION[$sessKey]['lb_stage'] = $val;
        }
        $opts .= "<option value=\"$val\"{$sel}>$stage</option>\n";
    }
    if ($selected) {
        $rplc = '';
    } else {
        $rplc = ' selected="selected"';
        $_SESSION[$sessKey]['lb_stage'] = 'all_stages';
    }
    $firstopt = str_replace($token, $rplc, $firstopt);
    echo $firstopt . $opts;

} // fLoadStageDDL

/**
 * Build Status drop-down filter
 *
 * @return void
 */
function fLoadStatusDDL()
{
    global $sessKey;
    // Build a stage aarray
    $aszStatus[0][0] = "all_cases";                $aszStatus[0][1] = "All Cases";
    $aszStatus[1][0] = "only_open_cases";          $aszStatus[1][1] = "Only Open Cases";
    $aszStatus[2][0] = "only_closed_cases";        $aszStatus[2][1] = "Only Closed Cases";

    // Set our Pre-Select Value
    if (!isset($_SESSION[$sessKey]['lb_status'])) {
        $_SESSION[$sessKey]['lb_status'] = "only_open_cases";
    }
    $szPreSelect = $_SESSION[$sessKey]['lb_status'];

    for ($i = 0; $i < 3; $i++) {
        if ($aszStatus[$i][0] == $szPreSelect) {
            echo "<option value=\"{$aszStatus[$i][0]}\" selected=\"selected\">"
                . "{$aszStatus[$i][1]}</option>\n";
        } else {
            echo "<option value=\"{$aszStatus[$i][0]}\">{$aszStatus[$i][1]}</option>\n";
        }
    }

} // fLoadStatusDDL

/**
 * Build Date drop-down filter
 *
 * @return void
 */
function fLoadDatesDDL()
{
    global $sessKey;
    // Build a stage array
    $aszDates[0][0] = "all_dates";              $aszDates[0][1] = "All Dates";
    $aszDates[1][0] = "past_week";              $aszDates[1][1] = "Past Week";
    $aszDates[2][0] = "past_month";             $aszDates[2][1] = "Past Month";
    $aszDates[3][0] = "within_the_past_year";   $aszDates[3][1] = "Past Year";

    // Set our Pre-Select Value
    if (!isset($_SESSION[$sessKey]['lb_dates'])) {
        $_SESSION[$sessKey]['lb_dates'] = "all_dates";
    }
    $szPreSelect = $_SESSION[$sessKey]['lb_dates'];

    for ($i = 0; $i < 4; $i++) {
        if ($aszDates[$i][0] == $szPreSelect) {
            echo "<option value=\"{$aszDates[$i][0]}\" selected=\"selected\">"
                . "{$aszDates[$i][1]}</option>\n";
        } else {
            echo "<option value=\"{$aszDates[$i][0]}\">{$aszDates[$i][1]}</option>\n";
        }
    }

} // fLoadDatesDDL

// php end tag not required
