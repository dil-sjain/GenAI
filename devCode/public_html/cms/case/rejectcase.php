<?php

/**
 * Reject/Close modal iframe src
 * Presents user interaface in YUI iframe based panel for rejection or closing a Case Folder
 *
 * @author mwm
 * phpcs checked (passed) 12/11/14 - Luke
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('rejectCase')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
require_once __DIR__ . '/../includes/php/'.'ddq_funcs.php';
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';


// Check for login/Security
fchkUser("rejectcase");

//Get the case Record
$clientID = $_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);
$caseID = 0;
$caseRow = false;
if (isset($_GET['id']) && ($caseID = intval($_GET['id']))) {
    $caseRow = fGetCaseRow($caseID);
}
if (!$caseRow) {
    exit;
}

// use this to map dd vs training form class for case stage, if applicable.
require_once __DIR__ . '/../includes/php/'.'class_cases.php';
$caseCls = new Cases($clientID);

require_once __DIR__ . '/../includes/php/Lib/SettingACL.php';

// Check for DDQ

// $e_ trusted $caseID
$ddqID = 0;
if ($ddqRow = $dbCls->fetchAssocRow("SELECT id, status FROM ddq WHERE caseID = '$caseID' LIMIT 1")) {
    $ddqID = $ddqRow['id'];
    $ddqStatus = $ddqRow['status'];
}
$origCaseStage = $caseRow['caseStage'];

/*
if ($caseRow[9] >= BUDGET_APPROVED
    || $_SESSION['userSecLevel'] <= READ_ONLY
    || $_SESSION['userType'] <= VENDOR_ADMIN
) {
    session_write_close();
    header("Location: /cms/case/casehome.sec");
    exit;
}
 */

$_POST['caseName']  = htmlspecialchars($caseRow['caseName'], ENT_QUOTES, 'UTF-8');
$_POST['caseType']  = $caseRow['caseType'];
$_POST['region']    = $caseRow['region'];
$_POST['caseStage'] = htmlspecialchars($caseRow['caseStage'], ENT_QUOTES, 'UTF-8');
$_POST['requestor'] = $caseRow['requestor'];
$_POST['rejectReason'] = $caseRow['rejectReason'];
$_POST['rejectDescription'] = $caseRow['rejectDescription'];

// Submit Processing
if (isset($_POST['Submit'])) {
    if (!PageAuth::validToken('rejectCaseAuth', $_POST['rejectCaseAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl="rejectcase.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }
    if ($_POST['Submit'] == 'Attach Document') {
        $rtnPath = $sitepath . 'case/rejectcase.php';
        if ($_SERVER['QUERY_STRING']) {
            $rtnPath .= '?' . $_SERVER['QUERY_STRING'];
        }
        $_SESSION['modalReturnPath'] = $rtnPath;
        $_SESSION['save_rejectReason'] = htmlspecialchars($_POST['lb_rejectReason'], ENT_QUOTES, 'UTF-8');
        $_SESSION['save_rejectDescription'] = htmlspecialchars($_POST['ta_rejectDescription'], ENT_QUOTES, 'UTF-8');
        session_write_close();
        header("Location: /cms/case/subinfoAttach.php?id=$caseID");
        exit();

    } else {
        $iError = 0;
        // $_POST['Submit'] == 'Update | Close'

        // Lets make sure the user selected a reason to reject the case before we let them
        if ($_POST['lb_rejectReason']) {
            // Get the reject reason row from the DB
            $rejectCaseCodeRow = fGetRejectCaseCodeRow($_POST['lb_rejectReason']);
            if($caseRow['caseSource'] == DUE_DILIGENCE_CS_AI_DD && $rejectCaseCodeRow['returnStatus'] == 'deleted'){
                $rejectCaseCodeRow['returnStatus'] = 'closed';
            }
            $activateDdq = false; // status = active
            $restoreDdq  = false; // status = submitted
            switch ($rejectCaseCodeRow['returnStatus']) {

            case 'pending':
                $caseCanceled = CASE_CANCELED; //10
                if ($ddqID) {
                    $activateDdq = true;
                }
                break;

            case 'passed':
            case 'internalReview':
                $caseCanceled = CLOSED_INTERNAL; //14
                break;

            case 'held':
                if ($accCls->ftr->tenantHas(\Feature::CASE_ON_HOLD_STATUS)) {
                    $caseCanceled = ON_HOLD; //-2
                    $dbCls->query("UPDATE cases SET prevCaseStage = {$origCaseStage} WHERE id = {$caseID}");
                } else {
                    $caseCanceled = CLOSED_HELD; //15
                }

                break;

            case 'closed':
                $caseCanceled = CLOSED; //11
                break;

            case 'deleted':

                // Must check if case is linked. If at top of chain, remove linkage from
                // case and ddq. If something has linked to it, deletion is not allowed.

                $sql = "SELECT id FROM cases WHERE clientID = '$clientID' AND linkedCaseID = '$caseID'";
                if ($dbCls->fetchValue($sql) > 0) {
                    // Something after this case has linked to it. Deletion would break the chain.
                    $_SESSION['aszErrorList'][$iError++] = "This case is a linked commponent in a renewal "
                        . "sequence. It must not be deleted.";
                    goto displayRejectError; // 2014-11-03 grh: yes, I did it!
                } elseif ($caseRow['linkedCaseID'] > 0) {
                    // It's at the top of the chain. Linkage can be safely removed from case and ddq.
                    $sql = "UPDATE cases SET linkedCaseID = NULL\n"
                        . "WHERE id = '$caseID' AND clientID = '$clientID' LIMIT 1";
                    $dbCls->query($sql);
                }

                $caseCanceled = DELETED; //13
                break;

            case 'open':
                // We are actually using the Reject Case code to Reopen
                // a previously Closed Case.  I know,
                // do you believe that shit...that is why I am commenting it here now.
                // If the Case originated as a DDQ, we need to decide if we need
                // to set the caseStage back to
                // DDQ_INVITE or QUALIFICATION
                if ($ddqID) {
                    if ($_SESSION['bDDQinviteProc']) {
                        // use 'opensync' to preserve/restore submitted ddq
                        $caseCanceled = DDQ_INVITE; //-1
                        $activateDdq = true;
                    } else {
                        $caseCanceled = QUALIFICATION; //0
                        $restoreDdq = true;
                    }
                } else {
                    $caseCanceled = REQUESTED_DRAFT; //1
                }
                break;

            case 'opensync':
                // Differs from 'open' by preserving or restoring submitted ddq to
                // submitted status, rather than arbitrarily re-opening questionnaire.
                if ($ddqID) {
                    if ($_SESSION['bDDQinviteProc']) {
                        // activate or restore ddq
                        $dbCls->open();

                        // $e_ trusted $ddqID
                        $sql = "SELECT (LENGTH(subByName) OR LENGTH(subByIP)) AS `test` "
                            . "FROM ddq WHERE id = '$ddqID' LIMIT 1";
                        if ($dbCls->fetchValue($sql)) {
                            $caseCanceled = QUALIFICATION; //0
                            $restoreDdq = true;
                        } else {
                            $caseCanceled = DDQ_INVITE; //-1
                            $activateDdq = true;
                        }
                    } else {
                        $caseCanceled = QUALIFICATION; //0
                        $restoreDdq = true;
                    }
                } else {
                    $caseCanceled = REQUESTED_DRAFT; //1
                }
                break;

            default:
                // uh-oh
                exitApp(__FILE__, __LINE__, "Unrecognized returnStatus "
                    . "`". $rejectCaseCodeRow['returnStatus'] ."` in rejectCaseCode"
                );

            } //end switch

            $userid = $dbCls->esc($_SESSION['userid']); //string
            $e_lb_rejectReason = intval($_POST['lb_rejectReason']); //int db field
            $e_ta_rejectDescription = $dbCls->esc($_POST['ta_rejectDescription']); //text db field
            // $e_ trusted $caseID

            // make the case stage form-class aware. If form class is training, map the caseStage.
            if ($caseCls->isTraining($caseID)) {
                $caseCanceled = $caseCls->mapTrainingCaseStage($caseCanceled);
            }

            $sql = "UPDATE `cases` SET `requestor`='$userid', "
                 . "`caseStage`='$caseCanceled', `rejectReason`='$e_lb_rejectReason', "
                 . "`rejectDescription`='$e_ta_rejectDescription' "
                 . "WHERE `id`='$caseID'";
            $result = $dbCls->query($sql);
            if (!$result) {
                exitApp(__FILE__, __LINE__, $sql);
            }

            if ($dbCls->affected_rows()) {
                $globalIdx->syncByCaseData($caseID);

                $dbCls->open();
                $rr = $dbCls->fetchValue("SELECT name FROM rejectCaseCode "
                    . "WHERE id = '$e_lb_rejectReason' LIMIT 1"
                );
                $logMsg = "reason: `$rr`, explanation: `{$_POST['ta_rejectDescription']}`";
                logUserEvent(12, $logMsg , $caseID);
            }

            // If there is a ddq associated w/this Case, clear any previously set
            // pending returnStatus unless they are reactivating the questionnaire,
            // in which case returnStatus must be set to 'pending'
            if ($ddqID) {

                if ($activateDdq) {
                    $sql = "UPDATE ddq SET returnStatus = 'pending', "
                        . "subByDate = '0000-00-00 00:00:00', status = 'active' "
                        . "WHERE id = '$ddqID' LIMIT 1";
                } elseif ($restoreDdq) {
                    $sql = "UPDATE ddq SET returnStatus ='', status = 'submitted' "
                        . "WHERE id='$ddqID' LIMIT 1";
                } elseif ($ddqStatus == 'active' && $origCaseStage == CASE_CANCELED) {
                    /*
                     * SEC-1078 (2015-05-07)
                     * A "pending" rejection followed by a "closed" rejection will no longer be permitted
                     * to change a Case's DDQ record status to "closed" and blank out its subByDate field.
                     */

                    // Get new subByDate value (which was blanked out when the ddq status was set to 'active').
                    $ddqDatesSql = "SELECT d.caseID, d.status, d.origin, d.returnStatus, c.caseCreated, "
                        . "d.subByDate, d.subByIP, d.subByName, d.lastAccess, c.caseStage\n"
                        . "FROM ddq AS d\n"
                        . "LEFT JOIN cases AS c ON c.id = d.caseID\n"
                        . "WHERE d.id = '$ddqID' AND d.clientID = '$clientID'\n"
                        . "AND c.id IS NOT NULL LIMIT 1";
                    $ddqDatesRow = $dbCls->fetchObjectRow($ddqDatesSql);
                    $subByDate = guessSubByDate($clientID, $ddqDatesRow);

                    $sql = "UPDATE ddq SET\n"
                        . "returnStatus = '', status = 'submitted', subByDate = '$subByDate'\n"
                        . "WHERE id='$ddqID' LIMIT 1";
                } else {
                    // Conditionally close ddq
                    $sql = "UPDATE ddq SET returnStatus = '', status='closed' "
                        . "WHERE id = '$ddqID' AND `status` <> 'submitted' LIMIT 1";
                }
                $dbCls->query($sql);
                if ($activateDdq && $_SESSION['cflagApproveDDQ']) {
                    // reset ddq approval to NULL if ddq approval is enabled for client
                    // to allow for new approval when ddq is re-submitted
                    $sql = "UPDATE cases SET approveDDQ = NULL WHERE id = '$caseID' "
                        . "AND clientID = '$clientID' LIMIT 1";
                    $dbCls->query($sql);
                }
                fixDdqStatus($ddqID, $clientID); // sanity check and finish restoreDdq
            }

            $alertMailFailed = '';
            if ($rejectCaseCodeRow[4] == 'ddq') {
                if ($rejectCaseCodeRow[3] == 'pending') {
                    // Send email to the partner who submitted the DDQ
                    fSendSysEmail(EMAIL_NOTIFY_PARTNER_DDQ_RETURNED, $caseID);
                } // end $rejectCaseCodeRow[3] == 'pending'

            } // end $rejectCaseCodeRow[4] == 'ddq'

            if (isset($_SESSION['modalReturnPath'])) {
                unset($_SESSION['modalReturnPath']);
            }
            // Return to Case List
            echo <<<EOT

$alertMailFailed
<script type="text/javascript">
top.window.location = "casehome.sec?tname=caselist";
</script>

EOT;

            session_write_close();
            exit;
            // end if( $_POST['lb_rejectReason'] )
        } else {
            $_SESSION['aszErrorList'][$iError++] = "You MUST choose a reason to reject this "
                . "Partner before proceeding.";
        }
    }
} // end if (isset($_POST['Submit']))

// target for shortcut to display an error
displayRejectError:

$pageTitle = "Create New Case Folder";
shell_head_Popup();
$rejectCaseAuth = PageAuth::genToken('rejectCaseAuth');

foreach (['rejectReason', 'rejectDescription'] as $k) {
    $sessKey = 'save_' . $k;
    $postKey = 'lb_' . $k;
    if (isset($_SESSION[$sessKey])) {
        $_POST[$postKey] = $_SESSION[$sessKey];
        unset($_SESSION[$sessKey]);
    } else {
        $_POST[$postKey] = '';
    }
}

?>

<!--Start Center Content-->
<script type="text/javascript">

var submitted = false;
function submittheform (selectedtype)
{
    if (!submitted) {
        submitted = true;
        document.rejectcaseform.Submit.value = selectedtype;
        document.rejectcaseform.submit() ;
    }
    return false;
}

</script>

<h6 class="formTitle">&nbsp;</h6>
<h6 class="formTitle"><?php echo $_SESSION['szRejectIcon']; ?>:
    <?php echo $_POST['caseName']; ?></h6>

<form name="rejectcaseform" class="cmsform"
    action="rejectcase.php?id=<?php echo $caseID; ?>" method="post" >
  <input type="hidden" name="hf_caseStage" value="<?php echo $_POST['caseStage']; ?>" />
  <input type="hidden" name="Submit" />
  <input type="hidden" name="rejectCaseAuth" value="<?php echo $rejectCaseAuth; ?>" />

  <div class="stepFormHolder">
<table width="503" border="0" cellspacing="0" cellpadding="0">
  <tr>
    <td align="left"><span class="blackFormTitle"><b>This partner's status of
        <?php echo $_SESSION['szRejectIcon']; ?> is based on the following reason:</b></span></td>
  </tr>
</table>

<table width="535" border="0" cellpadding="0" cellspacing="0" class="paddiv">
  <tr>
    <td colspan="2"><?php fCreateRejectReasonDDL("lb_rejectReason"); ?></td>
  </tr>

<?php
if (isset($_POST['ta_rejectDescription'])) {
    $resetMsg = htmlspecialchars($_POST['ta_rejectDescription'], ENT_QUOTES, 'UTF-8');
} else {
    $resetMsg = htmlspecialchars($_POST['rejectDescription'], ENT_QUOTES, 'UTF-8');
}
?>

  <tr>
     <td width="102">&nbsp;</td>
     <td>&nbsp;</td>
  </tr>

  <tr>
    <td colspan="2"><div id="attachDiv" style="font-weight:normal;height:3em;"><a
        href="javascript:void(0)" onclick="return submittheform('Attach Document')">
        <span class="fas fa-paperclip" style="font-size: 16px;"></span><span
        style="font-size:115%;">Attach investigation report or
        other documents</span></a>.</div>
        List of documents attached to this case:
        <iframe src="hasAttachment.php?id=<?php echo $caseID; ?>" name="hasAttach" width="430"
        marginwidth="0" height="90" marginheight="0" align="left" scrolling="Auto" frameborder="1"
        id="hasAttach" style="border:1px dotted grey"></iframe>
        </div></td>
  </tr>

  <tr>
    <td colspan="2"><div id="descDiv" style="height:12em;"><div class="blackFormTitle"
        style="margin-bottom:.25em;margin-top:.25em">Explanation: </div>
      <textarea id="ta_rejectDescription" name="ta_rejectDescription" cols="35" rows="4"
        style="height:8em;width:30em;" class="no-trsl"><?php echo $resetMsg; ?></textarea></div></td>
  </tr>
</table>
<br />
<!--  Begin BUTTONS -->
<br />

<table width="206" border="0" cellpadding="0" cellspacing="8" class="paddiv">
  <tr>
    <td width="112" nowrap="nowrap" class="submitLinks">
    <a href="javascript:void(0)" id="btnUpdateClose"
        onclick="return submittheform('Update | Close')" >Update | Close</a></td>
    <td width="367" nowrap="nowrap" class="submitLinksCancel"> <a href="javascript:;"
        onclick="top.onHide();" >Cancel</a></td>
  </tr>
</table>

</div>
</form>

<!--   End Center Content-->
<?php

noShellFoot(true);


/**
 * Outputs <select> tag and options for reject codes
 *
 * @param string $id_list Value for name attribute on select tag
 *
 * @global integer $ddqID DDQ record identifier (ddq.id)
 * @global integer $clientID clientProfile record identifier (clientProfile.id)
 * @author mwm
 * @return void
 */
function fCreateRejectReasonDDL($id_list)
{
    global $ddqID, $clientID;

    $dbCls = dbClass::getInstance();

    $clientDB = $dbCls->clientDBname($clientID);

    $orig = ($ddqID) ? 'ddq' : 'manual';

    // clientID and orig are trusted. no e_ stuff.

    $sql = "SELECT id, name FROM {$clientDB}.rejectCaseCode "
         . "WHERE clientID = '$clientID' AND hide = 0 "
         . "AND (forCaseOrig = 'both' OR forCaseOrig = '$orig') ORDER BY name";
    $result = $dbCls->fetchObjectRows($sql);

    // Make sure we got data back
    if (count($result) == 0) {
        // Lets go after the default values
        $sql = "SELECT id, name FROM {$clientDB}.rejectCaseCode "
             . "WHERE clientID = '0' AND hide = 0 "
             . "AND (forCaseOrig = 'both' OR forCaseOrig = '$orig') "
             . "ORDER BY name";
        $result = $dbCls->fetchObjectRows($sql);

        // Check for error
        if (!$result) {
            exitApp(__FILE__, __LINE__, $sql);
        }
    }


    echo '<select id="mySelect6" name="'. $id_list .'" data-tl-ignore="1">'
        .'<option value="" >Choose...</option>';

    foreach ($result as $r) {
        $selected = '';
        if (isset($_POST['lb_rejectReason']) && $_POST['lb_rejectReason'] == $r->id) {
            $selected = ' selected="selected"';
        } elseif ($_POST['rejectReason'] == $r->id) {
            $selected = ' selected="selected"';
        }
        echo '<option value="'. $r->id .'"'. $selected .'>'. $r->name .'</option>';
    }

    // end of list
    echo '</select>';

} // end fCreateRejectReasonDDL
