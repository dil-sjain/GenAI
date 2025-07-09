<?php

/**
 * Add/Edit ddq_keayperson records from case fold personnel tab
 *
 * HB on 08/05/14:
 * This form accessed via Case's Personnel tab corresponds to the form accessed via
 * ddq3.php in add_key_person.php. Significant structural changes should be consistent
 * in both places.
 *
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';

if (!isset($_SESSION['currentCaseID'])) {
    exit('Invalid access');
}
$e_caseID = $caseID   = $_SESSION['currentCaseID'];
$e_clientID = $clientID = $_SESSION['clientID'];

$session->ddq_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'."funcs.php";
require_once __DIR__ . '/../includes/php/'."ddq_funcs.php";
require_once __DIR__ . '/../includes/php/'."funcs_misc.php";
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
require_once __DIR__ . '/../includes/php/'.'class_access.php';
require_once __DIR__ . '/../includes/php/'.'class_formValidation.php';
$formValidationCls = new formValidation('keyPerson');
$accCls = UserAccess::getInstance();
if (!$accCls->allow('accCasePersonnel')
    || !isset($_SESSION['ddqID'])
    || !($caseRow = fGetCaseRow($caseID))
    || $accCls->allow('noAddPrincipal')
) {
    exit('Access denied');
}
$e_ddqID = $ddqID = $_SESSION['ddqID'];

// Lets set the Add/Edit Mode
$bEditMode = 0;
$kpID      = 0;
$task      = '';
if (isset($_GET['id'])) {
    $_GET['id'] = intval($_GET['id']);
    if ($_GET['id']) {
        $kpID = intval($_GET['id']);
        $bEditMode = 1;
    }
}

// define Post vars used by this form
$postVarMap = ['tf_' => ['kp%dName', 'kp%dNationality', 'kp%dOwnPercent', 'kp%dPosition', 'kp%dIDnum', 'proposedRole%d', 'email%d'], 'cb_' => ['bkp%dOwner', 'bkp%dKeyMgr', 'bkp%dBoardMem', 'bkp%dKeyConsult'], 'rb_' => ['bResOfEmbargo%d'], 'ta_' => ['kp%dAddr']];
$reps = 4;

$kpData = [];

$e_kpID = $kpID;

if (isset($_POST['Submit'])) {
    if (!PageAuth::validToken('edkpAuth', $_POST['edkpAuth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "editddq_keyperson.php?id=$kpID";
        header("Location: $errUrl");
        exit;
    }

    // Get posted values into an array of objects
    if ($_POST['Submit'] != 'Delete') {
        for ($i = 1; $i <= $reps; $i++) {
            $kp = new stdClass();
            $kp->ddqID = $ddqID;
            $kp->e_ddqID = $ddqID;
            $kp->recID = $kpID;
            $kp->e_recID = $kpID;
            foreach ($postVarMap as $prefix => $vars) {
                foreach ($vars as $varName) {
                    $pKey = $prefix . str_replace('%d', $i, $varName);
                    $prop = str_replace('%d', '', $varName);
                    $eValue = $value = '';
                    if ($prefix == 'cb_') {
                        $value = 0;
                        if (isset($_POST[$pKey])) {
                            $eValue = $value = intval($_POST[$pKey]);
                        }
                    } else {
                        $value = '';
                        if ($prefix == 'ta_') {
                            $eValue = '';
                            if (isset($_POST[$pKey])) {
                                $value = htmlspecialchars(normalizeLF(trim((string) $_POST[$pKey])), ENT_QUOTES, 'UTF-8');
                                $eValue = $dbCls->escape_string($value);
                            }
                        } else {
                            if (isset($_POST[$pKey])) {
                                $value = htmlspecialchars(trim((string) $_POST[$pKey]), ENT_QUOTES, 'UTF-8');
                                $eValue = $dbCls->escape_string($value);
                            }
                        }
                    }
                    $eProp = 'e_' . $prop;
                    $kp->{$prop} = $value;
                    $kp->{$eProp} = $eValue;
                }
            }
            $kpData[$i] = $kp;

            if ($bEditMode) {
                break;  // only need first one for update
            }
        }
    }

    switch ($_POST['Submit']) {
    case 'Save':
        $nextPage = "casehome.sec";
        break;

    case 'Delete':
        if ($kpName = $dbCls->fetchValue("SELECT kpName FROM ddqKeyPerson "
            . "WHERE id = '$e_kpID' AND ddqID='$e_ddqID' AND clientID = '$e_clientID' LIMIT 1"
        )) {
            $sql = "DELETE FROM ddqKeyPerson WHERE id = '$e_kpID' "
                . "AND ddqID='$e_ddqID' AND clientID = '$e_clientID' LIMIT 1";
            if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                logUserEvent(100, $kpName, $caseID);
            }
        }
        locBackToCasehome();
        //break;

    case 'Update':
        $kp = $kpData[1];
        $validationErrors = $formValidationCls->validateKeyPerson($kp, false, "", true); // incomplete validation?
        if (is_array($validationErrors) && $validationErrors['totalErrors'] == 0) {
            $oldData = $dbCls->fetchObjectRow("SELECT * FROM ddqKeyPerson "
                . "WHERE id = '$e_kpID' AND ddqID = '$e_ddqID' AND clientID = '$e_clientID' LIMIT 1"
            );
            $kpName = $kp->e_kpName;
            if (!str_ends_with((string) $kpName, ' *')) {
                $kpName .= ' *';
            }
            $e_kpName = $dbCls->esc($kpName);
            $e_kpOwnPercent = $formValidationCls->formatPercent($kp->e_kpOwnPercent);
            $sql = "UPDATE ddqKeyPerson SET "
                . "kpName        = '$kpName', "
                . "kpAddr        = '$kp->e_kpAddr', "
                . "kpNationality = '$kp->e_kpNationality', "
                . "bkpOwner      = '$kp->e_bkpOwner', "
                . "kpOwnPercent  = '$e_kpOwnPercent', "
                . "bkpKeyMgr     = '$kp->e_bkpKeyMgr', "
                . "kpPosition    = '$kp->e_kpPosition', "
                . "kpIDnum       = '$kp->e_kpIDnum', "
                . "bkpBoardMem   = '$kp->e_bkpBoardMem', "
                . "bkpKeyConsult = '$kp->e_bkpKeyConsult', "
                . "bResOfEmbargo = '$kp->e_bResOfEmbargo', "
                . "proposedRole  = '$kp->e_proposedRole', "
                . "email         = '$kp->e_email' "
                . "WHERE id = '$e_kpID' AND ddqID = '$e_ddqID' "
                . "AND clientID = '$e_clientID' LIMIT 1";
            if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                $logMsg = "$kp->kpName -- ";
                $chg = [];
                foreach ($postVarMap as $prefix => $vars) {
                    foreach ($vars as $varName) {
                        $prop = str_replace('%d', '', $varName);
                        if (str_starts_with($prop, 'kp')) {
                            $k = substr($prop, 2);
                        } elseif (str_starts_with($prop, 'bkp')) {
                            $k = substr($prop, 3);
                        } elseif (str_starts_with($prop, 'b')) {
                            $k = substr($prop, 1);
                        } else {
                            $k = $prop;
                        }
                        $oldVal = $oldData->{$prop};
                        $newVal = $kp->{$prop};
                        if ($oldVal != $newVal) {
                            $chg[] = "$k `$oldVal` => `$newVal`";
                        }
                    }
                }
                $logMsg .= join('; ', $chg);
                logUserEvent(99, $logMsg, $caseID);
            }
            locBackToCasehome();
        } // not error
        break;

    default:
        exit('ERROR: Unknown Submit Value');
    }

    // Save new key persons if no error
    if (!isset($validationErrors) && !$bEditMode) {
        $validationErrors = $formValidationCls->validateKeyPeople($kpData, $reps, "", true);
        if (is_array($validationErrors) && $validationErrors['totalErrors'] == 0) {
            for ($i = 1; $i <= $reps; $i++) {
                if (!in_array($i, $validationErrors['blankRecords'])) {
                    $kp = $kpData[$i];
                    if ($kp->kpName) {
                        $kpName = $kp->e_kpName;
                        if (!str_ends_with((string) $kpName, ' *')) {
                            $kpName .= ' *';
                        }
                        $e_kpOwnPercent = $formValidationCls->formatPercent($kp->e_kpOwnPercent);
                        $sql = "INSERT INTO ddqKeyPerson SET "
                            . "id            = NULL, "
                            . "ddqID         = '$e_ddqID', "
                            . "clientID      = '$e_clientID', "
                            . "kpName        = '$kpName', "
                            . "kpAddr        = '$kp->e_kpAddr', "
                            . "kpNationality = '$kp->e_kpNationality', "
                            . "bkpOwner      = '$kp->e_bkpOwner', "
                            . "kpOwnPercent  = '$e_kpOwnPercent', "
                            . "bkpKeyMgr     = '$kp->e_bkpKeyMgr', "
                            . "kpPosition    = '$kp->e_kpPosition', "
                            . "kpIDnum       = '$kp->e_kpIDnum', "
                            . "bkpBoardMem   = '$kp->e_bkpBoardMem', "
                            . "bkpKeyConsult = '$kp->e_bkpKeyConsult', "
                            . "bResOfEmbargo = '$kp->e_bResOfEmbargo', "
                            . "proposedRole  = '$kp->e_proposedRole', "
                            . "email         = '$kp->e_email'";
                        if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                            $logMsg = "$kp->kpName manually entered *";
                            logUserEvent(93, $logMsg, $caseID);
                        }
                    }
                }
            }
            locBackToCasehome();
        }
    }
    // end Submit Processing

} elseif ($bEditMode) {
    // get kp data from db
    $kpData[1] = $dbCls->fetchObjectRow("SELECT * FROM ddqKeyPerson "
        . "WHERE id = '$e_kpID' AND clientID = '$e_clientID' LIMIT 1"
    );
} else {
    // initialize empty kp data array
    for ($i = 1; $i <= $reps; $i++) {
        $kp = new stdClass();
        foreach ($postVarMap as $prefix => $vars) {
            foreach ($vars as $varName) {
                $fld  = $prefix . str_replace('%d', $i, $varName);
                $prop = str_replace('%d', '', $varName);
                if ($prefix == 'cb_') {
                    $value = 0;
                } else {
                    $value = '';
                }
                $kp->fld = $fld;
                $kp->{$prop} = $value;
            }
        }
        $kpData[$i] = $kp;

        if ($bEditMode) {
            break;  // only need first one for update
        }
    }
}

?>

<?php noShellHead();
$edkpAuth = PageAuth::genToken('edkpAuth');

$action = "editddq_keyperson.php";
if ($bEditMode) {
    $action .= '?id=' . $kpID;
}

?>
<!--Start Center Content-->
<script type="text/javascript">
function submittheform ( selectedtype )
{
    document.add_key_person.Submit.value = selectedtype ;
    document.add_key_person.submit() ;
    return false;
}
</script>
<style type="text/css">
.style1 {
    font-size: 24px
}
.formValidationErrors {
    color: #FF0000;
    font-weight: bold;
}
input[type=checkbox] {
    vertical-align: middle;
    margin: -2px 5px 0 15px;
}
</style>

<h6 class="formTitle">Add/Edit Key Person</h6>

<form name="add_key_person" class="cmsform" action="<?php echo $action; ?>" method="post">
<input type="hidden" name="Submit" />
<input type="hidden" name="edkpAuth" value="<?php echo $edkpAuth; ?>" />
<?php
echo (isset($validationErrors) && is_array($validationErrors))
    ?  $formValidationCls->getValidationErrors($validationErrors)
    : '';
?>
<table width="895" border="0" class="paddiv">

<?php
$ck = ' checked="checked"';
$percentTitle = $accCls->trans->codeKey('owner_without_percent');
$decPlc = $formValidationCls->getPercentDecPlc();
$percentDefault = bcadd('0', '0', $decPlc);
$ownPercentSize = (($decPlc > 0) ? 4 + $decPlc : 3);
$caseType = 0;
// Get headers
$fieldLabels = ['lblName' => 'TEXT_NAME_COL', 'lblAddress' => 'TEXT_ADDRESS_COL', 'lblNationality' => 'TEXT_NATION_COL', 'lblRole' => 'TEXT_ROLE_MODAL', 'lblCheckall' => 'TEXT_CHECKALL_MODAL', 'lblOwner' => 'TEXT_OWNER_MODAL', 'lblKeyManager' => 'TEXT_KEYMGR_MODAL', 'lblIdNum' => 'TEXT_ID_COL', 'lblPosition' => 'TEXT_POSITION_COL', 'lblBoardMem' => 'TEXT_BRDMEM_MODAL', 'lblKeyConsult' => 'TEXT_KEYCONSULT_MODAL', 'lblEmbargoed' => 'TEXT_EMBARGOQUEST_MODAL', 'lblProposedRole' => 'TEXT_ROLEQUEST_MODAL'];
foreach ($fieldLabels as $label=>$questionID) {
    $aQuestionRow = foqGetQRowFromPage($_SESSION['aOnlineQuestions'], $questionID);
    if ($caseType == 0) {
        $caseType = $aQuestionRow[4];
    }
    ${$label} = $aQuestionRow[5];
}
$lblEmail = $accCls->trans->codeKey('kp_lbl_email');

for ($i = 1; $i <= $reps; $i++) {
    $kp = $kpData[$i];
    $lblCheckAll = (showKeyPersonField('showCheckAllCol', $clientID, $caseType)) ? $lblCheckall : '';

    $nameKey = 'tf_kp' . $i . 'Name';
    $nameVal = $kp->kpName;
    $name = '<input name="' . $nameKey . '" type="text" id="' . $nameKey . '"
        size="25" maxlength="255" value="' . $nameVal . '" />';

    $emailKey = 'tf_email' . $i;
    $emailVal = $kp->email;
    $email = '<input name="' . $emailKey . '" type="text" id="' . $emailKey . '" size="25" maxlength="255" '
        . 'value="' . $emailVal . '" />';

    $positionKey = 'tf_kp' . $i . 'Position';
    $positionVal = $kp->kpPosition;
    $position = '<input name="' . $positionKey . '" type="text" '
        . 'id="' . $positionKey . '" size="25" maxlength="255" value="' . $positionVal . '" />';

    $nationality = '';
    if (showKeyPersonField('showNationality', $clientID, $caseType)) {
        $natKey = 'tf_kp' . $i . 'Nationality';
        $natVal = $kp->kpNationality;
        $nationality = '<input name="' . $natKey . '" type="text" id="' . $natKey . '" size="25" maxlength="255" '
            . 'value="' . $natVal . '" />';
    } else {
        $lblNationality = '';
    }

    $address = '';
    if (showKeyPersonField('showAddress', $clientID, $caseType)) {
        $addrKey = 'ta_kp' . $i . 'Addr';
        $addrVal = $kp->kpAddr;
        $address = '<textarea name="' . $addrKey . '" id="'.  $addrKey . '" cols="20" rows="2">' . $addrVal
            . '</textarea>';
    } else {
        $lblAddress = '';
    }

    $lblIdNum = (showKeyPersonField('showIdCol', $clientID, $caseType) ? $lblIdNum : '');

    $idNum = '';
    if (showKeyPersonField('showIdNum', $clientID, $caseType)) {
        $idNumKey = 'tf_kp' . $i . 'IDnum';
        $idNumVal = $kp->kpIDnum;
        $idNum = '<input name="' . $idNumKey . '" type="text" id="' . $idNumKey . '" size="25" maxlength="255" '
            . 'value="' . $idNumVal . '" />';
    }

    $ownerRow = '';
    if (!$_SESSION['CNC']['baRemoveOwnershipList'] && showKeyPersonField('showOwner', $clientID, $caseType)) {
        $ownerKey = 'cb_bkp' . $i . 'Owner';
        $ownerCk  = ($kp->bkpOwner) ? $ck: '';
        $ownPercentKey = 'tf_kp' . $i . 'OwnPercent';
        $ownPercentVal = $percentDefault;
        if (isset($_POST[$ownPercentKey]) && !empty($_POST[$ownPercentKey])) {
            $ownPercentVal = htmlspecialchars($_POST[$ownPercentKey], ENT_QUOTES, 'UTF-8');
        } elseif ($kp->kpOwnPercent) {
            $ownPercentVal = $formValidationCls->formatPercent($kp->kpOwnPercent);
        }
        $ownerRow = '<input name="' . $ownerKey . '" type="checkbox" id="' . $ownerKey . '" class="marg-rt0" value="1"'
            .$ownerCk.' />'
            . '<label for="' . $ownerKey . '">'.$lblOwner.'</label>'
            . '<input name="' . $ownPercentKey . '" type="text" id="' . $ownPercentKey . '" size="5" '
            . 'maxlength="' .$ownPercentSize .'" value="' . $ownPercentVal . '" title="' . $percentTitle . '" '
            . 'alt="' . $percentTitle . '" class="marg-rt0" /> %';
    }

    $keyMgr = '';
    if (!$_SESSION['CNC']['baRemoveKeyManagementList'] && showKeyPersonField('showKeyManager', $clientID, $caseType)) {
        $keyMgrKey = '' . $i . '';
        $keyMgrKey = 'cb_bkp' . $i . 'KeyMgr';
        $keyMgrCk     = ($kp->bkpKeyMgr) ? $ck: '';
        $keyMgr = '<input name="' . $keyMgrKey . '" type="checkbox" class="ckbx marg-rt0" id="' . $keyMgrKey. '" '
            . 'value="1"' . $keyMgrCk . ' />'
            . ' <label for="' . $keyMgrKey . '">'.$lblKeyManager.'</label>';
    }

    $boardMem = '';
    if (!$_SESSION['CNC']['baRemoveBoardOfDirList'] && showKeyPersonField('showBoardMem', $clientID, $caseType)) {
        $boardMemKey = '' . $i . '';
        $boardMemKey = 'cb_bkp' . $i . 'BoardMem';
        $boardMemCk  = ($kp->bkpBoardMem) ? $ck: '';
        $boardMem = '<input name="' . $boardMemKey . '" type="checkbox" class="ckbx marg-rt0" '
            . 'id="' . $boardMemKey . '" value="1"' . $boardMemCk . ' />'
            . ' <label for="' . $boardMemKey . '">'.$lblBoardMem.'</label>';
    }

    $keyConsult = '';
    if (!$_SESSION['CNC']['baRemoveKeyConsultantList'] && showKeyPersonField('showKeyConsult', $clientID, $caseType)
    ) {
        $keyConsultKey = '' . $i . '';
        $keyConsultKey = 'cb_bkp' . $i . 'KeyConsult';
        $keyConsultCk  = ($kp->bkpKeyConsult) ? $ck: '';
        $keyConsult = '<input name="' . $keyConsultKey . '" type="checkbox" class="ckbx marg-rt0" '
            . 'id="' . $keyConsultKey . '" value="1"' . $keyConsultCk . ' />'
            . ' <label for="' . $keyConsultKey . '">'.$lblKeyConsult.'</label>';
    }

    // lay out table rows
    echo <<<EOT
    <tr>
        <td width="22"><strong>$i</strong></td>
        <td width="160">$lblName</td>
        <td width="163">$lblEmail</td>
        <td width="157">$lblNationality</td>
        <td width="167">$lblRole</td>
        <td width="200">$lblCheckAll</td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td valign="top">$name</td>
        <td valign="top">$email</td>
        <td valign="top">$nationality</td>
        <td valign="top">$ownerRow</td>
        <td valign="top">$keyMgr</td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td valign="top">$lblPosition</td>
        <td width="163">$lblAddress</td>
        <td valign="top">$lblIdNum</td>
        <td valign="top">&nbsp;</td>
        <td valign="top">&nbsp;</td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td valign="top">$position</td>
        <td valign="top">$address</td>
        <td valign="top">$idNum</td>
        <td valign="top">$boardMem</td>
        <td valign="top">$keyConsult</td>
    </tr>
EOT;

    if (showKeyPersonField('showLastRow', $clientID, $caseType)) {
        echo <<<EOT
    <tr>
        <td>&nbsp;</td>
        <td valign="top" colspan="3">
EOT;
        if (showKeyPersonField('showEmbargoQuestion', $clientID, $caseType)) {
            $embargoKey = 'rb_bResOfEmbargo' . $i;
            $embargoYes   = ($kp->bResOfEmbargo == 'Yes') ? $ck: '';
            $embargoNo    = ($kp->bResOfEmbargo == 'No') ? $ck: '';
            echo <<<EOT
        {$lblEmbargoed}<br />
        <input type="radio" class="ckbx marg-rt0" name="$embargoKey" id="{$embargoKey}Yes" value="Yes"{$embargoYes} />
        <label for="{$embargoKey}Yes">Yes</label> &nbsp;
        <input class="ckbx marg-rt0" type="radio" name="$embargoKey" id="{$embargoKey}No" value="No"{$embargoNo} />
        <label for="{$embargoKey}No">No</label>
EOT;
        }
        echo <<<EOT
        </td>
        <td valign="top" colspan="2">
EOT;
        if (showKeyPersonField('showProposedRole', $clientID, $caseType)) {
            $roleKey = 'tf_proposedRole' . $i;
            $roleVal = $kp->kpPosition;
            echo <<<EOT
        {$lblProposedRole}<br />
        <input name="{$roleKey}" type="text" id="{$roleKey}" size="25" maxlength="50" value="{$roleVal}" />
EOT;
        }
        echo <<<EOT
        </td>
    </tr>
EOT;
    }
    // horizontal rule
    ?>
    <tr>
        <td colspan="6"><hr width="100%" size="1" noshade="noshade" /></td>
    </tr>
    <?php

    if ($bEditMode) {
        break;  // show only one for edit mode
    }
}
?>

</table>
<br />

<table width="204" border="0" align="left" cellpadding="0" cellspacing="0" class="paddiv">
<?php
if (!$bEditMode) {
    ?>
    <tr>
        <td align="left" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Save')" >Save</a></td>
    </tr>
    <?php
} else {
    ?>
    <tr>
        <td align="left" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Update')" >Save</a></td>
    </tr>
    <tr>
        <td align="left" class="submitLinks"><a href="javascript:void(0)"
            onclick="return submittheform('Delete')" >Delete</a></td>
    </tr>
    <?php
} ?>
    <tr>
        <td align="left" class="submitLinks"><a href="javascript:;"
            onclick="top.onHide();" >Cancel</a>    </td>
    </tr>
</table>
</form>

<?php noShellFootLogin();

/**
 * Return to casehome from modal
 *
 * @return void
 */
function locBackToCasehome(): never
{
    echo '<script type="text/javascript">', PHP_EOL,
        'parent.window.location.replace("casehome.sec");', PHP_EOL,
        '</script>', PHP_EOL;
    exit;
}

?>
