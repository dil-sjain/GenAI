<?php
/**
 * Script to send out an Intake Form Invitation
 *
 * Required input (from InvitationControl) as requested action:
 *    refID (may be caseID or tpID, depending on perspective)
 *    formType (implies legacyID)
 *    caseID
 *    action
 *
 *    formatted as request variable ?idl=refID:formType:caseID:action
 */

$haltMsg = false;
$haltBadAccess = 'Invalid request for this feature. ';
$haltBadRequest = 'Requested operation unavailable: ';

$formTitle = "Due Diligence Intake Form Invitation";
$formAction = $_SERVER['PHP_SELF'];
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
if ($session->secure_value('userClass') == 'vendor'
    || !$_SESSION['allowInvite']
) {
    $haltMsg = $haltBadAccess . '(a)';
    include_once __DIR__ . '/../includes/php/'.'add_ddqinvite_form.php';
}

require_once __DIR__ . '/../includes/php/'.'funcs.php'; // is this REALLY needed?!
require_once __DIR__ . '/../includes/php/'.'ddq_funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'funcs_ddqinvite.php';
require_once __DIR__ . '/../includes/php/Lib/FeatureACL.php';
require_once __DIR__ . '/../includes/php/Models/Globals/Features/TenantFeatures.php';
require_once __DIR__ . '/../includes/php/Lib/SettingACL.php';
if ($_SESSION['b3pAccess']) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}
require_once __DIR__ . '/../includes/php/'.'Stash.php';

// clear these sess vars on init only
if (isset($_GET['xsv']) && $_GET['xsv'] == 1) {
    unset($_SESSION['profileRow3P']);
}

if (isset($_POST['rb_subStat'])) {
    $rbSubstat = $_POST['rb_subStat'];
} else {
    $rbSubstat = '';
}

require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();

// See if we have a caseID, ie. in edit mode
$tpID = $caseID = $ddqID = 0;
$clientID = (int)$_SESSION['clientID'];
$caseRow = $ddqRow = [];

$renew = $reInvite = $invite = 0;

$fromCfgFtrEnabled = (new TenantFeatures($clientID))->tenantHasFeature(
    FeatureACL::TENANT_INTAKE_INVITE_FROM,
    FeatureACL::APP_TPM
);

// get required inputs
if (isset($_REQUEST['dl'])) {
    $dialogLink = htmlspecialchars($_REQUEST['dl'], ENT_QUOTES, 'UTF-8');
    if (!preg_match('/^\d+_\d+_\d+_[-RIeitnvw]+$/', (string) $dialogLink)) {
        $haltMsg = $haltBadAccess . '(b)';
        include_once 'add_ddqinvite_form.php';
    }
    [$icRef, $icFormType, $caseID, $icAction] = explode('_', (string) $dialogLink);
} else {
    $haltMsg = $haltBadAccess . '(c)';
    include_once 'add_ddqinvite_form.php';
}

// What's the request?
require_once __DIR__ . '/../includes/php/'.'class_InvitationControl.php';
$invCtrl = new InvitationControl($clientID, ['due-diligence', 'internal'],
    $accCls->allow('sendInvite'), $accCls->allow('resendInvite')
);
$ic = $invCtrl->getInviteControl($icRef);
$icValidation = $invCtrl->explain($icFormType, $icAction);
if (!$icValidation['result']) {
    $haltMsg = $haltBadRequest . $icValidation['reason'];
    include_once 'add_ddqinvite_form.php';
}

// Get values from input control
$icForm = $ic['formList'][$icFormType];
$formClass = $icForm['formClass'];
$caseID = $icForm['caseID'];
if ($icForm['action'] == 'Renew') {
    $renewForm = $ic['formList'][$icForm['renewedBy']];
    $icLegacyID = $renewForm['legacyID'];
    $formList = [$icLegacyID => $renewForm['name']];
} else {
    $icLegacyID = $icForm['legacyID'];
    $formList = [$icLegacyID => $icForm['name']];
}


$legacyID = ''; // use to detect change from original intake form
switch ($icAction) {
case 'Invite':
    if (!$accCls->allow('sendInvite')) {
        exit('Access denied.');
        $haltMsg = $haltBadAccess . '(d)';
    }
    $invite = 1;
    $formTitle = "Send Intake Form Invitation";
    break;
case 'Re-invite':
    if (!$accCls->allow('resendInvite')) {
        exit('Access denied.');
        $haltMsg = $haltBadAccess . '(e)';
    }
    $reInvite = 1;
    $legacyID = $icLegacyID;
    $formTitle = "Resend Intake Form Invitation";
    break;
case 'Renew':
    if (!$accCls->allow('sendInvite')) {
        $haltMsg = $haltBadAccess . '(f)';
        include_once 'add_ddqinvite_form.php';
    }
    $renew = 1;
    $legacyID = $icLegacyID;
    $formTitle = "Renew Previous Intake Form Responses";
    break;
default:
    $haltMsg = $haltBadRequest . 'undefined operation.';
    include_once 'add_ddqinvite_form.php';
    break;
}


// We need to store the selected DDQ name and ID in the log file;
// Get this info here (stored later)
$log_legacy = "";
if (isset($_POST['ddqName'])) {
    $posted_legacyid = $_POST['ddqName'];
    $valid_legacy_ids = [];
    $total_legacy_id_options = count($ic['actionFormList']);
    foreach ($ic['actionFormList'] as $ft => $frm) {
        $valid_legacy_ids[] = $frm['legacyID'];
    }
    if (in_array($posted_legacyid, $valid_legacy_ids, true)) {
        $e_posted_legacyid = $dbCls->esc($posted_legacyid);
        $sql = "SELECT name FROM ddqName WHERE legacyID='$e_posted_legacyid' AND "
            . "clientID='$clientID' LIMIT 1";
        $ddqName_result = $dbCls->fetchValue($sql);

        $log_legacy = ", Intake Form: `$ddqName_result ($posted_legacyid)`";
    }
}

$tpRow = false;
$defaultLang = 'EN_US';
if ($caseID) {
    // get form info from case if available
    // We need the case row and the ddq row
    // grh: need to check if these returned rows?
    $caseRow = fGetCaseRow($caseID);
    $subjectInfoDDRow = fGetSubInfoDDRow($caseID);
    if ($ddqRow = fGetddqSubmittedRow($caseID)) {
        $ddqID = $ddqRow['id'];
        $defaultLang = $ddqRow['subInLang'];
    }
    if (!isset($_SESSION['profileRow3P'])
        && $caseRow['tpID']
        && $_SESSION['b3pAccess']
        && $accCls->allow('acc3pMng')
    ) {
        if ($tpRow = fGetThirdPartyProfileRow($caseRow['tpID'])) {
            $_SESSION['profileRow3P'] = $tpRow;
        }
    }
}

// Still need info? Try to get it from 3P profile
$infoFrom3p = false;
if (!isset($_SESSION['profileRow3P']) && $ic['perspective'] == '3p' && !empty($icRef)) {
    if ($tpRow = fGetThirdPartyProfileRow($icRef)) {
        $infoFrom3p = true;
    }
}
if (!$infoFrom3p) {
    unset($tpRow);
}

if (isset($_POST['lb_EMlang'])) {
    $defaultLang = htmlspecialchars($_POST['lb_EMlang'], ENT_QUOTES, 'UTF-8');
}

if ($infoFrom3p && is_object($tpRow)) {

    // Use only once to initiate form values
    $_SESSION['profileRow3P'] = $tpRow;
    $ddqRow['DBAname']      = $_SESSION['profileRow3P']->DBAname;
    $ddqRow['street']       = $_SESSION['profileRow3P']->addr1;
    $ddqRow['addr2']        = $_SESSION['profileRow3P']->addr2;
    $ddqRow['city']         = $_SESSION['profileRow3P']->city;
    $ddqRow['postCode']     = $_SESSION['profileRow3P']->postcode;
    $ddqRow['companyPhone'] = $_SESSION['profileRow3P']->POCphone1;
    $ddqRow['POCname']      = $_SESSION['profileRow3P']->POCname;
    $ddqRow['POCposi']      = $_SESSION['profileRow3P']->POCposi;
    $ddqRow['POCphone']     = $_SESSION['profileRow3P']->POCphone1;
    $ddqRow['POCemail']     = $_SESSION['profileRow3P']->POCemail;
    $ddqRow['stockExchange'] = $_SESSION['profileRow3P']->stockExchange;
    $ddqRow['tickerSymbol']  = $_SESSION['profileRow3P']->tickerSymbol;
    $caseRow['caseName']    = $_SESSION['profileRow3P']->legalName;
    $caseRow['region']      = $_SESSION['profileRow3P']->region;
    $caseRow['dept']        = $_SESSION['profileRow3P']->department;
    $caseRow['caseState']   = $_SESSION['profileRow3P']->state;
    $caseRow['caseCountry'] = $_SESSION['profileRow3P']->country;
}

if (!isset($_POST['Submit'])) { // Set on Form load
    $_SESSION['ddqInvite'] = new stdClass();
    if (isset($caseRow['region'])) {
        $_SESSION['ddqInvite']->region = $caseRow['region'];
    } else {
        $_SESSION['ddqInvite']->region = $_SESSION['userRegion'];
    }
    if (isset($caseRow['dept'])) {
        $_SESSION['ddqInvite']->dept = $caseRow['dept'];
    } else {
        $_SESSION['ddqInvite']->dept = $_SESSION['userDept'];
    }
    $_SESSION['ddqInvite']->caseCountry
        = $caseRow['caseCountry'] ?? '';
    $_SESSION['ddqInvite']->caseState
        = $caseRow['caseState'] ?? '';
    $_SESSION['ddqInvite']->caseName
        = $caseRow['caseName'] ?? '';
    $_SESSION['ddqInvite']->subStat
        = $subjectInfoDDRow['subStat'] ?? '';

    $_SESSION['ddqInvite']->DBAname
        = $ddqRow['DBAname'] ?? '';
    $_SESSION['ddqInvite']->street
        = $ddqRow['street'] ?? '';
    $_SESSION['ddqInvite']->addr2
        = $ddqRow['addr2'] ?? '';
    $_SESSION['ddqInvite']->city
        = $ddqRow['city'] ?? '';
    $_SESSION['ddqInvite']->postCode
        = $ddqRow['postCode'] ?? '';
    $_SESSION['ddqInvite']->companyPhone
        = $ddqRow['companyPhone'] ?? '';
    $_SESSION['ddqInvite']->stockExchange
        = $ddqRow['stockExchange'] ?? '';
    $_SESSION['ddqInvite']->tickerSymbol
        = $ddqRow['tickerSymbol'] ?? '';
}

$tpID = 0;
if (isset($_SESSION['profileRow3P'])) {
    $tpID = $_SESSION['profileRow3P']->id;
}

//----------------------------------------//
$emailAddr = $confirmEmail = '';

if (!isset($_POST['Submit'])) {
    // Just display the DDQ Invite form
    include_once 'add_ddqinvite_form.php';
} elseif (!PageAuth::validToken('addinvAuth', $_POST['addinvAuth'])) {
    // If form was submitted, but invalid authorization provided, quit here
    $_SESSION['altErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
    session_write_close();
    header("Location: $formAction");
    exit;
} else {
    // Everything moving forward in this scope can safely assume form has been submitted
    // with proper authorization token

    // Let's sanitize user input
    if (isset($_POST['tf_caseName'])) {
        $tf_caseName  = trim((string) $_POST['tf_caseName']);
    } elseif (isset($_SESSION['ddqInvite'])) {
        $tf_caseName  = $_SESSION['ddqInvite']->caseName;
    } else {
        $tf_caseName = '';
    }

    // From Email Address (tenant requires the TENANT_INTAKE_INVITE_FROM feature)
    $e_fromEmailAddr = 0;
    if ($fromCfgFtrEnabled && isset($_POST['fromEmailAddr'])
        && $_POST['fromEmailAddr'] != $_SESSION['userEmail']
    ) {
        $e_fromEmailAddr = $dbCls->esc($_POST['fromEmailAddr']);
    }

    // Invite Email Language
    if (isset($_POST['lb_EMlang'])) {
        $EMlang = $_POST['lb_EMlang'];
    } else {
        $EMlang = $_SESSION['languageCode'];
    }
    $e_EMlang = $dbCls->esc($EMlang);

    $emailAddr    = htmlspecialchars(trim((string) $_POST['tf_emailAddr']), ENT_QUOTES, 'UTF-8');
    $confirmEmail = trim((string) $_POST['tf_confirmEmail']);
    $tf_POCname   = trim((string) $_POST['tf_POCname']);
    $tf_POCposi   = trim((string) $_POST['tf_POCposi']);
    $tf_POCphone  = trim((string) $_POST['tf_POCphone']);

    $useExtraCC = false;
    if (in_array($clientID, [TEVAQC_CLIENTID, TEVA_CLIENTID])) {
        $tf_CCaddrs = trim((string) $_POST['tf_CCaddrs']);
        $useExtraCC = true;
    }

    // Escape for SQL
    $e_substat   = $dbCls->esc($rbSubstat);
    $e_caseName  = $dbCls->esc($tf_caseName);
    $e_formClass = $dbCls->esc($formClass);
    $e_emailAddr = $dbCls->esc($emailAddr);
    $e_POCname   = $dbCls->esc($tf_POCname);
    $e_POCposi   = $dbCls->esc($tf_POCposi);
    $e_POCphone  = $dbCls->esc($tf_POCphone);
    $e_userid    = $dbCls->esc($_SESSION['userid']);

    // Escape values in $_SESSION['ddqInvite']
    $regionID   = intval($_SESSION['ddqInvite']->region);
    $deptID     = intval($_SESSION['ddqInvite']->dept);
    $e_street   = $dbCls->esc($_SESSION['ddqInvite']->street);
    $e_addr2    = $dbCls->esc($_SESSION['ddqInvite']->addr2);
    $e_city     = $dbCls->esc($_SESSION['ddqInvite']->city);
    $e_postCode = $dbCls->esc($_SESSION['ddqInvite']->postCode);
    $e_DBAname  = $dbCls->esc($_SESSION['ddqInvite']->DBAname);
    $e_caseCountry   = $dbCls->esc($_SESSION['ddqInvite']->caseCountry);
    $e_caseState     = $dbCls->esc($_SESSION['ddqInvite']->caseState);
    $e_companyPhone  = $dbCls->esc($_SESSION['ddqInvite']->companyPhone);
    $e_stockExchange = $dbCls->esc($_SESSION['ddqInvite']->stockExchange);
    $e_tickerSymbol  = $dbCls->esc($_SESSION['ddqInvite']->tickerSymbol);

    $ddqName = trim((string) $_POST['ddqName']);
    $ddqNameType = 0;
    $ddqNameVersion = '';
    if ($ddqName) {
        $nameParts = splitDdqLegacyID($ddqName);
        $ddqNameType = $nameParts['caseType']; // integer
        $ddqNameVersion = $nameParts['ddqQuestionVer'];
    }

    if ($_POST['Submit'] == 'reload') {
        // Form was simply reloaded, so unset unnecessary POST vars
        clearPostVars('lb_EMlang');
    } else {
        // Form was submitted and we need to take action

        // Start by checking for submission errors
        $error_counter = 0;
        if ((!$_SESSION['b3pAccess']) && (!isset($ddqRow['name'])) && (!$tf_caseName)) {
            $_SESSION['altErrorList'][$error_counter++] = "You must enter a Company/Case Name.";
        }
        if (!$emailAddr || !bValidEmail($emailAddr) || $emailAddr != $confirmEmail) {
            $_SESSION['altErrorList'][$error_counter++] = "You must enter a valid Email Address.";
        }
        if (!$tf_POCname) {
            $_SESSION['altErrorList'][$error_counter++] = "You must enter a Point of Contact name.";
        } else if (!checkInputSafety($tf_POCname)) {
            $_SESSION['altErrorList'][$error_counter++] = "Point of Contact name contains unsafe content.";
        }
        if ($tf_POCposi && !checkInputSafety($tf_POCposi)) {
            $_SESSION['altErrorList'][$error_counter++] = "Point of Contact Position contains unsafe content.";
        }
        if ($tf_POCphone && !isValidPhone($tf_POCphone)) {
            $_SESSION['altErrorList'][$error_counter++] = "You must enter a valid Point of Contact Phone Number.";
        }

        if ($useExtraCC) {
            $tf_CCaddrs = str_replace([' ', ';'], ['', ','], trim($tf_CCaddrs, ",\t\n\r"));
            $emAddr = explode(',', $tf_CCaddrs);
            if (!$emAddr || !bValidEmail($emAddr)) {
                $_SESSION['altErrorList'][$error_counter++] = "You must enter a valid Email Address.";
            } else {
                Stash::set('CCaddrs', $emAddr);
            }
        }

        // If 3P Access isn't turned on we will have displayed fields for the Country and Region
        if (!($_SESSION['b3pAccess'])) {
            if (isset($_POST['lb_region'])) {
                if (!($_POST['lb_region'])) {
                    $_SESSION['altErrorList'][$error_counter++] = "You must enter a Region.";
                } else {
                    $_SESSION['ddqInvite']->region = $regionID = intval($_POST['lb_region']);
                }
            }

            if (isset($_POST['lb_caseCountry'])) {
                if (!($_POST['lb_caseCountry'])) {
                    $_SESSION['altErrorList'][$error_counter++] = "You must enter a "
                        . $accCls->trans->codeKey('country') . ".";
                } else {
                    $_SESSION['ddqInvite']->caseCountry = $_POST['lb_caseCountry'];
                    $e_caseCountry = $dbCls->esc($_SESSION['ddqInvite']->caseCountry);
                }
            }
        }
        // Require 3P association
        if ($_SESSION['b3pAccess']) { // If 3P is turned on, make sure there is an association
            if (!$tpID) {
                $_SESSION['altErrorList'][$error_counter++] = "You must link a Third Party Profile"
                    . "to this invitation"
                    . ((!$accCls->allow('assoc3p')) ? ',<br />but your assigned role does not'
                    . ' grant that permission.': '.');
            }
        }
        // Require at least one case type (set in fields/lists, Intake form identification)
        if (!isset($_POST['ddqName']) || !$_POST['ddqName']) {
            $_SESSION['altErrorList'][$error_counter++] = "You must have at least one Intake Form"
                . " set to active (set in Content Control, Fields/Lists, Intake Form"
                . " Identification) and you must choose Intake Form Version.";
        }

        /* 2011-11-30 grh: new rules.
        * disallow invite to existing email addr if
        *    1) 3P enabled, 3P associated, and active ddq exists for this 3P
        *    2) 3P not enabled and active ddq exists for this email
        *       Todd says not worth the brain cycles to try to match company name
        * otherwise, allow invite to same email addr
        */
        if (!($ddqTitle = $_SESSION['szDDQtitle'])) {
            $ddqTitle = 'Due Diligence Questionnaire';
        }
        if ($tpID && $_SESSION['b3pAccess']) {
            // Error if 3P already has active ddq (excluding the current ddqID)
            // emailAddr and caseType don't matter
            /*
             * Class InvitationControl checks this condition more thoroughly
             *
            $sql = "SELECT d.id "
                 . "FROM ddq AS d "
                 . "LEFT JOIN cases AS c ON c.id = d.caseID "
                 . "WHERE c.id IS NOT NULL "
                 . "AND c.tpID = '$tpID' "
                 . "AND d.status = 'active' "
                 . "AND d.formClass = '$e_formClass' "
                 . "AND d.clientID = '$clientID' "
                 . "AND d.id <> '$ddqID' LIMIT 1";
            $err = 'The associated Third Party Profile already'
                . ' has<br />an active/open ' . $ddqTitle . '.';
             *
             */

        } elseif ($ic['perspective'] != '3p') {
            // creating or updating, w/o 3P assigned
            // Error if emailAddr already has active ddq (excluding current ddqID)
            $sql = "SELECT d.id "
                 . "FROM ddq AS d "
                 . "WHERE d.status = 'active' "
                 . "AND d.formClass = '$e_formClass' "
                 . "AND d.loginEmail = '$e_emailAddr' "
                 . "AND d.clientID = '$clientID' "
                 . "AND d.id <> '$ddqID' LIMIT 1";
            $err = 'The email address you entered already has<br />'
                 . 'an active/open ' . $ddqTitle . ' associated with it.';
            if ($dbCls->fetchValue($sql)) {
                $_SESSION['altErrorList'][$error_counter++] = $err;
            }
        }

        // Proceed if no errors
        if ($error_counter == 0) {
            switch ($icAction) {
            case 'Invite':
                $caseID = ddqinvCreateDDQ('new');
                break;
            case 'Re-invite':
                $caseID = ddqinvReinviteDDQ($caseID);
                break;
            case 'Renew':
                /*
                 * 2014-11-01 grh: Todd agreed renewal should never overwrite existing data
                 * Removing ddqinvRenewLinkedDDQ() and logic to call it.
                 */
                $caseID = ddqinvCreateDDQ('renew', $caseID);
                break;
            default:
                // all possibilities have already been checked
                break;
            }

            // Clear invite request
            unset($_SESSION['ddqInvite']);

            // Send an email to the entity being invited
            $alertMailFailed = '';
            fSendSysEmail(EMAIL_SEND_DDQ_INVITATION, $caseID);
            if ($icAction == 'Invite' && $icFormType == DUE_DILIGENCE_SBI) {
                // Perform Workflow Event MANUAL_SEND_DDQ
                if ($accCls->ftr->tenantHasAllOf([\Feature::TENANT_API_INTERNAL, \Feature::TENANT_WORKFLOW])) {
                    require_once '../includes/php/Models/TPM/Workflow/TenantWorkflow.php';
                    $workflow = (new TenantWorkflow($clientID))->getTenantWorkflowClass();

                    if ($workflow->tenantHasEvent($workflow::MANUAL_SEND_DDQ)) {
                        $workflow->manualSendDDQ($tpID, $ddqID);
                    }
                }
            }
            $location = "top.window.location.replace(\"casehome.sec\")";
            // If $caseID is set redirect to the newly created Case Folder
            if (isset($caseID)) {
                $_SESSION['currentCaseID'] = intval($caseID);
                $location = "top.window.location.replace(\"casehome.sec?tname=casefolder\")";
            }
            // Redirect user
            session_write_close();
            echo "<script type=\"text/javascript\">
                  $alertMailFailed
                  $location
                  </script>";
            exit;
        }
    }

    // Now display the DDQ Invite form
    include_once 'add_ddqinvite_form.php';
}
