<?php
/**
 * Load selected case into Case Folder
 *
 * CS compliance: grh - 2012-08-23
 */
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
require_once __DIR__ . '/../includes/php/'.'ddq_funcs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();

//devDebug($_POST, '_POST');
$clientID = $_SESSION['clientID'];
$e_ownerID = intval($_SESSION['id']);
$raceOwner = 0;
if (isset($_POST['req'])) {
    // reassign case element
    $raceOwner = intval($_POST['req']);
}

require_once __DIR__ . '/../includes/php/'.'class_customdata.php';
require_once __DIR__ . '/../includes/php/'.'funcs_cases.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php'; // includes class_db

if ($_POST['op'] == 'rsf' || $_POST['op'] == 'refresh-stagelist') {
    include_once __DIR__ . '/../includes/php/'.'caselist_inc.php'; // need caseStageListByStatus()
}
if ($_POST['op'] == 'save-reassign') {
    include_once __DIR__ . '/../includes/php/'.'funcs_users.php';
    include_once __DIR__ . '/../includes/php/'.'funcs_sysmail.php';
    include_once __DIR__ . '/../includes/php/'.'class_BillingUnit.php';
}
if ($_POST['op'] == 'approve4convert') {
    include_once __DIR__ . '/../includes/php/'.'funcs_sysmail.php';
}

//$dbCls->dieOnQueryError = FALSE;

// Get environment variable value
$awsEnabled = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN);

$globaldb = GLOBAL_DB;
$realGlobalDb = REAL_GLOBAL_DB;

$jsData = '{}';
$jsObj = new stdClass();
$jsObj->DoNewCSRF = 0;

// Dispatcher
$op = $_POST['op'];

$showInvitePw = false;
if (isset($_SESSION['currentCaseID'])) {
    $caseID = intval($_SESSION['currentCaseID']);
} elseif (isset($_POST['c'])) {
    $caseID = intval($_POST['c']);
} else {
    $caseID = 0;
}

if (in_array($op, ['init-invite-pw', 'show-invite-pw', 'build-ddq-access-sessions'])) {
    $ddqSql = "SELECT status, origin FROM ddq WHERE clientID = '$clientID' "
        . "AND caseID = '$caseID' LIMIT 1";
    $showInvitePw = (($ddqRow = $dbCls->fetchArrayRow($ddqSql, MYSQLI_ASSOC))
        && $ddqRow['status'] == 'active'
        && $session->secure_value('userClass') != 'vendor'
        && ($session->secure_value('accessLevel') == SUPER_ADMIN
            || ($_SESSION['cflagShowInvitePw']
                && $ddqRow['origin'] == 'invitation'
                && ($session->secure_value('accessLevel') == CLIENT_ADMIN
                    || !$accCls->allow('noShowInvitePw')
                    )
                )
            )
        );
}

switch ($op) {
case 'crev-tgl-status':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    //set new flag either way
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if (isset($_SESSION['currentCaseID'])
            && ($caseID = $_SESSION['currentCaseID'])
        ) {
            $statusTrans = [0 => 'needsreview', 1 => 'reviewed'];
            $jsObj->qID = $qID = htmlspecialchars($dbCls->esc($_POST['qid']), ENT_QUOTES, 'UTF-8');
            $jsObj->nSect = $nSect = htmlspecialchars($dbCls->esc($_POST['nsect']), ENT_QUOTES, 'UTF-8');
            $jsObj->logID = $e_logID = htmlspecialchars($dbCls->esc($_POST['logID']), ENT_QUOTES, 'UTF-8'); // RX-XX-# through RX-XX-######
            $setStatusTo = (int)$_POST['to'];
            if ($setStatusTo !== 1) {
                $setStatusTo   = 0;
                $setStatusFrom = 1;
            } else {
                $setStatusFrom = 0;
            }
            $status = $statusTrans[$setStatusTo];
            $tbl = 'caseReviewStatus';
            $userID = $_SESSION['id'];
            $sql = "SELECT id FROM $tbl\n"
                . "WHERE caseID = '$caseID'\n"
                . "AND qID = '$qID'\n"
                . "AND nSect = '$nSect'\n"
                . "AND clientID = '$clientID'\n"
                . "ORDER BY id DESC LIMIT 1";
            if ($recID = $dbCls->fetchValue($sql)) {
                $sql = "UPDATE $tbl SET status = '$status', userID = '$userID' \n"
                    . "WHERE id = '$recID' AND clientID = '$clientID' LIMIT 1";
            } else {
                $sql = "INSERT INTO $tbl SET id = NULL,\n"
                    . "status = '$status', userID = '$userID', caseID = '$caseID',\n"
                    . "qID = '$qID', nSect = '$nSect', clientID = '$clientID'\n";
            }
            if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                $jsObj->NextStatus = (int)(!$setStatusTo);
                $jsObj->CurStatus = $status;
                // Log the status change
                $logMsg = 'review ID: `' . $e_logID . '`, status: `' . $statusTrans[$setStatusFrom]
                    . '` => `' . $statusTrans[$setStatusTo] . '`';
                logUserEvent(147, $logMsg, $caseID);


            } else {
                $jsObj->NextStatus = (int)($setStatusTo); // stays the same
                $jsObj->CurStatus = $statusTrans[!$setStatusTo];
            }
            $jsObj->Result = 1;
        }
    }
    break;

case 'about-search':
    include  __DIR__ . '/../includes/php/'.'about-search.php';
    break;
case 'sp-case-access':
case 'case-access':
case 'tpp-access':
    $recID = intval($_POST['id']);
    $icli = 0;
    $handleSp = $op == 'sp-case-access' && $session->secure_value('userClass') == 'vendor';
    if ($handleSp) {
        $icli = intval($_POST['icli']);
        $jsObj->Client = $icli;
    }
    $jsObj->RecID = $recID;
    try {
        include_once __DIR__ . '/../includes/php/'.'class_search.php';
        $gsrchSrc = 'CL';
        if (array_key_exists('caseFilterSrc', $_SESSION)) {
            $gsrchSrc = $_SESSION['caseFilterSrc'];
        }
        if ($gsrchSrc == 'AF' || $gsrchSrc == 'SB') {
            $isGlobalSrch = $_SESSION['gsrch'][$gsrchSrc]['cs']['scope'];
        } else {
            $isGlobalSrch = $_SESSION['gsrch'][$gsrchSrc]['scope'];
        }
        if ($handleSp) {
            $GS = new SpSearchCases($isGlobalSrch);
            $recAccess = $GS->checkCaseAccess($recID, $icli);
        } elseif ($op == 'tpp-access') {
            $GS = new Search3p($isGlobalSrch);
            $recAccess = $GS->checkProfileAccess($recID);
        } else {
            $GS = new SearchCases($isGlobalSrch);
            $recAccess = $GS->checkCaseAccess($recID);
        }
        $jsObj->Ok = $recAccess->access;
        $jsObj->Contact = $recAccess->formatted;
    } catch (SearchException $ex) {
        devDebug($ex->getMessage(), 'search exception');
        $jsObj->Ok = -2;
        $jsObj->Contact = 'An exception occurred';
    }
    break;

case 'vendor-incrementcase':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    $userID = intval($_POST['userID']);
    $caseID = intval($_POST['caseID']);
    $budgetAmount = intval($_POST['budgetAmount']);
    $caseTypeID = intval($_POST['caseTypeID']);
    $incrementCaseTypeID = intval($_POST['incrementCaseTypeID']);
    if (!empty($caseID) && !empty($incrementCaseTypeID)) {
        incrementCaseFolder($_SESSION['vendorID'], $_SESSION['clientID'], $userID, $caseID, $budgetAmount, $caseTypeID, $incrementCaseTypeID);
        $jsObj->newCaseTypeID = $incrementCaseTypeID;
        $jsObj->Result = 1;
    }
    break;

case 'vendor-rejectcase':
    $jsObj->Result = 0;
    //set new flag either way
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        $caseID = intval($_POST['c']);
        $note = normalizeLF(trim((string) $_POST['n']));
        $dbCls->open();
        if (!$accCls->allow('spRejectCase') || !($caseRow = fGetCaseRow($caseID))) {
            $jsObj->ErrorTitle = 'Access Denied';
            $jsObj->ErrorMsg = 'You do not have permission to access this case.';
        } elseif ($session->secure_value('userClass') != 'vendor') {
            $jsObj->ErrorTitle = 'Invalid Access';
            $jsObj->ErrorMsg = 'You do not have permission to perform this operation.';
        } elseif ($caseID != $_SESSION['currentCaseID']) {
            $jsObj->ErrorTitle = 'Invalid Access';
            $jsObj->ErrorMsg = 'Ambiguous case ID in session. Rejection aborted.';
        } elseif ($caseRow['caseStage'] != BUDGET_APPROVED
            && $caseRow['caseStage'] != ACCEPTED_BY_INVESTIGATOR
            && $caseRow['caseStage'] != ASSIGNED
        ) {
            $jsObj->ErrorTitle = 'Invalid Access';
            $jsObj->ErrorMsg = 'Case can not be rejected at its current stage.';
        } elseif ($note == '') {
            $jsObj->ErrorTitle = 'Missing Input';
            $jsObj->ErrorMsg = 'Please provide reason for rejecting case.';
        } elseif (strlen($note) > 2000) {
            $jsObj->ErrorTitle = 'Input Error';
            $jsObj->ErrorMsg = 'Note exceeds maximum length of 2,000 characters.';
        } else {
            $e_oldStage = $oldStage = $caseRow['caseStage'];
            $e_newStage = $newStage = UNASSIGNED;
            $caseSql  = "UPDATE cases SET caseStage = '$e_newStage' "
                      . "WHERE id = '$caseID' AND clientID = '$clientID' "
                      . "AND caseStage = '$e_oldStage' LIMIT 1";
            if ($dbCls->query($caseSql) && $dbCls->affected_rows()) {
                // freshen the global case index data.
                include_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
                $globalIdx = new GlobalCaseIndex($clientID);
                $globalIdx->syncByCaseData($caseID);

                $stageNames = $dbCls->fetchKeyValueRows("SELECT id, name "
                    . "FROM caseStage WHERE id IN($e_oldStage, $e_newStage)"
                );
                $logMsg = 'stage: `' . $stageNames[$oldStage]
                    . '` => `' . $stageNames[$newStage] . '`';
                logUserEvent(77, $logMsg, $caseID);
                $jsObj->Result = 1;
                // create note category if needed
                $cat = SPLITE_NOTE_CAT;
                $noteCatTbl = 'noteCategory';
                $sql = "SELECT id FROM $noteCatTbl "
                    . "WHERE id = '$cat' LIMIT 1";
                if ($dbCls->fetchValue($sql) != $cat) {
                    $dbCls->query("INSERT INTO $noteCatTbl SET id = '$cat', "
                        . "name = 'Investigator Comments'"
                    );
                }
                // save reject reason as case note
                $subj   = 'Case Rejection';
                $e_subj = $dbCls->escape_string($subj);
                $e_note = $dbCls->escape_string($note);
                if ($dbCls->query("INSERT INTO caseNote SET "
                    . "noteCatID = '$cat', "
                    . "clientID  = '$clientID', "
                    . "caseID    = '$caseID', "
                    . "subject   = '$e_subj', "
                    . "note      = '$e_note', "
                    . "ownerID   = '$e_ownerID', "
                    . "bInvestigator = '0', "
                    . "bInvestigatorCanSee = '1', "
                    . "created = NOW()"
                ) && $dbCls->affected_rows() == 1
                ) {
                    logUserEvent(29, 'Subject: ' . $subj, $caseID);
                }
                if (1) {
                    // Use the old fSendSysEmail for now since
                    // email functionality requirements have changed
                    include_once __DIR__ . '/../includes/php/'.'funcs_sysmail.php';
                    $_SESSION['szDeclineReason'] = $note; // from the generic in sendSysMail
                    fSendSysEmail(EMAIL_UNASSIGNED_NOTICE, $caseID);
                } else {
                    include_once __DIR__ . '/../includes/php/'.'funcs_sysmail2.php';
                    $args = ['caseID' => $caseID, 'rejectReason' => $note];
                    sendSysEmail(EMAIL_UNASSIGNED_NOTICE, $args);
                }
            } else {
                $jsObj->ErrorTitle = 'Unexpected Error';
                $jsObj->ErrorMsg = 'Operation FAILED. Case was NOT rejected.';
            }
        }
    } //end else csrf check
    break;

case 'show-invite-pw':
    if ($awsEnabled) {
        header("HTTP/1.0 404 Not Found");
        exit;
    }        
    $jsObj->Result = 0;
    // verify permission and user pw
    if ($showInvitePw
        && (md5((string) $_POST['upw']) == $dbCls->fetchValue("SELECT userpw FROM $globaldb.users "
            . "WHERE id='$e_ownerID' LIMIT 1"
        ))
    ) {
        // lookup ddq login credentials
        if ($ddqRec = $dbCls->fetchObjectRow("SELECT loginEmail, passWord, caseType, origin "
            . "FROM ddq WHERE caseID='$caseID' AND clientID='$clientID' "
            . "ORDER BY creationStamp DESC LIMIT 1"
        )) {
            require_once __DIR__ . '/../includes/php/ddq_funcs.php';
            $e_caseType = $dbCls->escape_string($ddqRec->caseType);
            $e_origin = $dbCls->escape_string($ddqRec->origin);
            $secCodeGlobalTbl = $dbCls->escape_string(REAL_GLOBAL_DB . ".g_intakeFormSecurityCodes");
            $secCodeRow = $dbCls->fetchObjectRow("SELECT * "
                . "FROM $secCodeGlobalTbl "
                . "WHERE clientID='$clientID' AND caseType='$e_caseType' AND origin='$e_origin' "
                . "ORDER BY id ASC LIMIT 1"
            );
            $hasOspForms = (new TenantFeatures($clientID))->tenantHasFeature(
                Feature::TENANT_DDQ_OSP_FORMS,
                Feature::APP_TPM
            );
            $jsObj->ddqLink = $secCodeRow->ddqLink;
            $jsObj->isOspForm = $hasOspForms;
            $hasAdminDDQInstantAccess = (new TenantFeatures($clientID))->tenantHasFeature(
                Feature::ADMIN_DDQ_ACCESS_LINK,
                Feature::APP_TPM
            );
            $jsObj->hasAdminDDQInstantAccess = $hasAdminDDQInstantAccess;
            $jsObj->ddqRec = $ddqRec;
            $jsObj->Result = 1;
            // don't log super admin access
            if ($session->secure_value('accessLevel') != SUPER_ADMIN) {
                logUserEvent(95, "Email: $ddqRec->loginEmail", $caseID);
            }
        }
    }
    break;

case 'init-invite-pw':
    if ($awsEnabled) {
        header("HTTP/1.0 404 Not Found");
        exit;
    }
    $jsObj->Result = 0;
    if ($showInvitePw) {
        $jsObj->Result = 1;
    }
    break;

/**
* Validates and builds out the required sessions in prep for Admin DDQ access
*/
case 'build-ddq-access-sessions':
    require_once __DIR__ . '/../includes/php/Controllers/TPM/IntakeForms/Legacy/Login/IntakeFormLogin.php';
    require_once __DIR__ . '/../includes/php/ddq_funcs.php';
    if ($ddqRec = $dbCls->fetchObjectRow("SELECT * "
        . "FROM ddq WHERE caseID='$caseID' AND clientID='$clientID' \n"
        . "ORDER BY creationStamp DESC LIMIT 1"
    )) {
        $secCodeGlobalTbl = $dbCls->escape_string(REAL_GLOBAL_DB . ".g_intakeFormSecurityCodes");
        $caseType = $dbCls->escape_string($ddqRec->caseType);
        $origin = $dbCls->escape_string($ddqRec->origin);
        $secCodeRow = $dbCls->fetchObjectRow("SELECT * FROM {$secCodeGlobalTbl} "
            . "WHERE clientID='{$clientID}' AND caseType='{$caseType}' AND origin='{$origin}' \n"
            . "LIMIT 1"
        );
        $hasOspForms = (new TenantFeatures($clientID))->tenantHasFeature(
            Feature::TENANT_DDQ_OSP_FORMS,
            Feature::APP_TPM
        );
        $hasAdminDDQInstantAccess = (new TenantFeatures($clientID))->tenantHasFeature(
            Feature::ADMIN_DDQ_ACCESS_LINK,
            Feature::APP_TPM
        );
        $jsObj->ddqLink = buildDdqLink($clientID, $caseType, $origin, $hasOspForms);
        if ($hasAdminDDQInstantAccess) {
            $jsObj->ddqAdminAccess = IntakeFormLogin::createAdminDdqAccessArray(
                $ddqRec->id,
                $clientID,
                $ddqRec->loginEmail,
                $jsObj->ddqLink,
                $hasOspForms
            );
            $ddqCode = $secCodeRow->ddqCode;
            $_SESSION['addqa'] = $jsObj->ddqAdminAccess;
            $_SESSION['clientID'] = $clientID;
            $_SESSION['ddqID'] = $ddqRec->id;
            $_SESSION['userEmail'] = $ddqRec->loginEmail;
            $_SESSION['ddqCode'] = $secCodeRow->ddqCode;
            $_SESSION['caseType'] = $secCodeRow->caseType;
            $_SESSION['secCode'] = $secCodeRow->secCode;
            $_SESSION['baseText'] = loadBaseDDQText($clientID, $secCodeRow->caseType, $_SESSION['languageCode']);
            session_write_close();
            $jsObj->Result = 1;
        } else {
            $jsObj->ddqAdminAccess = '';
            $jsObj->Result = 0;
        }
    }
    break;

case 'apf-save': // accept with pass / fail
    $jsObj->Result = 0;
    //set new flag either way
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if (($caseID = intval($_SESSION['currentCaseID']))
            && $_SESSION['cflagAcceptPassFail']
            && $accCls->allow('acceptCase')
        ) {
            // modal has dual operation mode:
            //         apf = 'pass' or 'fail'  (no passFailReason records)
            //    Or,  rid = passFailReason.id (has passFailReason records)
            $passfail = $_POST['apf'] ?? '';
            $reasonID = isset($_POST['rid']) ? intval($_POST['rid']) : 0;
            $comment = trim((string) $_POST['com']);
            $clientID = $_SESSION['clientID'];
            $e_reasonID = 'NULL'; // bare NULL in SQL
            if ($reasonID) {
                $caseType = (int)$dbCls->fetchValue("SELECT caseType FROM cases "
                    . "WHERE id='$caseID' AND clientID='$clientID' LIMIT 1"
                );
                if ($reasonRow = $dbCls->fetchObjectRow("SELECT pfType, reason "
                    . "FROM passFailReason WHERE id='$reasonID' AND clientID='$clientID' "
                    . "AND caseType='$caseType' LIMIT 1"
                )) {
                    $passfail = $reasonRow->pfType;
                    $e_reasonID = "'$reasonID'";
                } else {
                    $passfail = ''; // abort
                    $reasonID = 0;
                }
            }
            if (in_array($passfail, ['pass', 'fail', 'neither'])) {
                // Do the case stage update first to prevent double processing
                // update and log case stage change
                $prevVals = $dbCls->fetchObjectRow("SELECT c.caseStage, "
                    . "s.name AS caseStageName "
                    . "FROM cases AS c "
                    . "LEFT JOIN caseStage AS s ON s.id = c.caseStage "
                    . "WHERE c.id='$caseID' LIMIT 1"
                );

                $e_passfail = $passfail;
                $stage = ACCEPTED_BY_REQUESTOR;
                $case_stage = COMPLETED_BY_INVESTIGATOR;
                $sql = "UPDATE cases SET "
                    . "caseStage = '$stage', "
                    . "caseAcceptedByRequestor = CURDATE(), "
                    . "passORfail = '$e_passfail', "
                    . "passFailReason = $e_reasonID "
                    . "WHERE id = '$caseID' "
                    . "AND clientID = '$clientID' "
                    . "AND caseStage = '$case_stage' LIMIT 1";
                if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                    // freshen the global case index
                    include_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
                    $globalIdx = new GlobalCaseIndex($clientID);
                    $globalIdx->syncByCaseData($caseID);

                    $newStageName = $dbCls->fetchValue("SELECT name FROM caseStage "
                        . "WHERE id = $stage LIMIT 1"
                    );
                    logUserEvent(25, "stage: `$prevVals->caseStageName` => `$newStageName` ("
                        . (($reasonID == 0)
                        ? strtoupper((string) $passfail)
                        : $reasonRow->reason) . ")", $caseID
                    );

                    // create internal note category, if needed
                    $e_note_category = APF_NOTE_CAT;
                    if ($dbCls->fetchValue("SELECT id FROM noteCategory WHERE id='"
                        . $e_note_category . "' LIMIT 1"
                    ) != APF_NOTE_CAT
                    ) {
                        $catName = 'Accept Completed Investigation';
                        $e_catName = $dbCls->escape_string($catName);
                        $sql = "INSERT INTO noteCategory SET "
                            . "id = '$e_note_category', "
                            . "name = '$e_catName'";
                        $dbCls->query($sql);
                        logUserEvent(31, "name: `$catName`");
                    }
                    // save the note
                    $note_id = 0;
                    $decoded_comment = '';
                    if ($comment) {
                        if ($reasonID) {
                            $subj = 'Investigation: ' . $reasonRow->reason;
                        } else {
                            $subj = 'Investigation: ' . strtoupper((string) $passfail);
                        }
                        $e_subj = $dbCls->escape_string($subj);
                        $e_comment = $dbCls->escape_string($comment);
                        $sql = "INSERT INTO caseNote SET "
                            . "clientID = '$clientID', "
                            . "caseID = '$caseID', "
                            . "noteCatID = '$e_note_category', "
                            . "ownerID = '" . $e_ownerID . "', "
                            . "created = NOW(), "
                            . "subject = '$e_subj', "
                            . "note = '$e_comment'";
                        $dbCls->query($sql);
                        $note_id = $dbCls->insert_id();
                        $decoded_comment = html_entity_decode($comment, ENT_QUOTES, 'UTF-8');
                        logUserEvent(29, "Subject: $subj", $caseID);
                    }

                    // send email to notify requestor investigation complete
                    include_once __DIR__ . '/../includes/php/'.'funcs_sysmail.php';

                    fSendSysEmail(EMAIL_ACCEPTED_BY_REQUESTOR, $caseID);
                    fSendSysEmail(EMAIL_NOTIFY_CREATOR_PASS_FAIL, $caseID);
                }


                // on success, these 2 lines redirect to Case List
                // This is an AJAX handler so the normal header/Location thing won't work here.
                unset($_SESSION['currentCaseID']);
                $jsObj->Result = 1;  // indicates success, redirects to Case List
            } else {
                $jsObj->ErrorTitle = 'Missing Input';
                $jsObj->ErrorMsg = 'You must indicate investigation outcome (Pass or Fail)';
            }
        }
    } //end else csrf check
    break;

case 'approve4convert':
    $jsObj->Result = 0;
    if ($_SESSION['cflagApproveDDQ']
        && $accCls->allow('approveCaseConvert')
        && !$accCls->allow('convertCase')
    ) {
        $caseID = intval($_SESSION['currentCaseID']);
        $diffsql = "SELECT requestor, creatorUID FROM cases WHERE id = '$caseID' LIMIT 1";
        $oldvals = $dbCls->fetchObjectRow($diffsql);
        fSendSysEmail(EMAIL_NOTIFY_APPROVAL_NEEDED, $caseID);
        // log it
        $newvals = $dbCls->fetchObjectRow($diffsql);
        $diffs = [];
        if ($newvals->requestor != $oldvals->requestor) {
            $diffs[] = "requester: `$oldvals->requestor` => `$newvals->requestor`";
        }
        if ($newvals->creatorUID != $oldvals->creatorUID) {
            $diffs[] = "creator: `$oldvals->creatorUID` => `$newvals->creatorUID`";
        }
        $msg = 'Approve DDQ';
        if (count($diffs)) {
            $msg .= ' - ' . join(', ', $diffs);
        }
        //Reassign Case Elements
        logUserEvent(89, $msg, $caseID);
        unset($_SESSION['currentCaseID']);
        $jsObj->Result = 1;
    }
    break;

case 'race-owner-list':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
        break;
    }
    if (!$_SESSION['cflagReassignCase'] || !$accCls->allow('reassignCase')) {
        break;
    }
    $owners = [];
    $region = intval($_POST['reg']);
    $dept = intval($_POST['dpt']);
    if ($rows = prospectiveCaseOwners($region, $dept)) {
        // format for select tag
        foreach ($rows as $row) {
            $obj = new stdClass();
            $obj->v = $row->id;
            $last = html_entity_decode((string) $row->lastName, ENT_QUOTES, 'UTF-8');
            $first = html_entity_decode((string) $row->firstName, ENT_QUOTES, 'UTF-8');
            $obj->t = "$last, $first";
            $owners[] = $obj;
        }
    } else {
        $jsObj->ErrorTitle = 'Owner/Requester List';
        $jsObj->ErrorMsg = 'No qualified owners found.';
    }
    $jsObj->Owners = $owners;
    $jsObj->Result = 1;
    break;

case 'save-reassign':
    //set new flag either way
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if (!$_SESSION['cflagReassignCase'] || !$accCls->allow('reassignCase')) {
            break;
        }
        $e_region = $region = intval($_POST['reg']);
        $e_dept = $dept = intval($_POST['dpt']);
        $buIgnore = (int)$_POST['buig'];
        $poIgnore = (int)$_POST['poig'];
        $billingUnit = ($buIgnore) ? 0 : (int)$_POST['bu'];
        $billingUnitPO = 0;
        $billingUnitPOselect = ($poIgnore) ? 0 : (int)$_POST['po'];
        $billingUnitPOtext = ($poIgnore || empty($_POST['pot'])) ? '' : $_POST['pot'];
        $buCls = new BillingUnit($clientID);
        if (!empty($billingUnit) && empty($poIgnore)) {
            if ($buCls->isTextReqd($billingUnit)) {
                if (!empty($billingUnitPOtext)) {
                    if (!$buCls->getBillingUnitPOIDByName($billingUnit, $billingUnitPOtext)) {
                        $buCls->insertBillingUnitPOTF($billingUnit, $billingUnitPOtext);
                    }
                    $billingUnitPO = $buCls->getBillingUnitPOIDByName($billingUnit, $billingUnitPOtext);
                }
            } else {
                $billingUnitPO = $billingUnitPOselect;
            }
        }

        // Make sure user can own this case
        if (!bValidCaseOwner($raceOwner, $region, $dept)) {
            $jsObj->ErrorTitle = 'Input Error';
            $jsObj->ErrorMsg = 'Case can not be assigned to selected user.';
            break;
        }
        $caseID = intval($_SESSION['currentCaseID']);
        $oldCaseVals = $dbCls->fetchObjectRow("SELECT userCaseNum, caseName, region, dept, "
            . "requestor, billingUnit, billingUnitPO "
            . "FROM cases WHERE id='$caseID' LIMIT 1"
        );
        $newOwner = $dbCls->fetchObjectRow("SELECT id, userName, userEmail, userid "
            . "FROM $globaldb.users WHERE id = $raceOwner LIMIT 1"
        );
        $e_old_requestor_id = $dbCls->escape_string($oldCaseVals->requestor);
        $oldOwner = $dbCls->fetchObjectRow("SELECT id, userName, userEmail "
            . "FROM $globaldb.users "
            . "WHERE userid = '$e_old_requestor_id' LIMIT 1"
        );
        $e_new_requestor_id = $dbCls->escape_string($newOwner->userid);
        $e_buID = $billingUnit;
        $e_poID = $billingUnitPO;
        $sql = "UPDATE cases SET "
            . "requestor='$e_new_requestor_id', "
            . "region='$e_region', "
            . "billingUnit = '$e_buID', "
            . "billingUnitPO = '$e_poID', "
            . "dept='$e_dept', "
            . "reassignDate=NOW() "
            . "WHERE id = '$caseID' LIMIT 1";
        if (!$dbCls->query($sql)) {
            $jsObj->ErrorTitle = 'Database Error';
            $jsObj->ErrorMsg = 'Failed updating case.';
            break;
        }
        $jsObj->Result = 1;
        if ($dbCls->affected_rows()) {
            // freshen the global case index
            include_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
            $globalIdx = new GlobalCaseIndex($clientID);
            $globalIdx->syncByCaseData($caseID);

            $oldBuNames = $buCls->getNames($oldCaseVals->billingUnit, $oldCaseVals->billingUnitPO);
            $newBuNames = $buCls->getNames($billingUnit, $billingUnitPO);
            $changeMsg = "\n";
            if (!$oldOwner || ($oldOwner->id != $newOwner->id)) {
                if (is_object($oldOwner)) {
                    $changeMsg .= "    Owner/Requester changed "
                        . "from $oldOwner->userName to $newOwner->userName\n";
                } else {
                    $changeMsg .= "    Owner/Requester changed "
                        . "from  '$oldCaseVals->requestor' to $newOwner->userName\n";
                }
            }
            $oldRegion = $newReigion = '';
            $oldDept = $newDept = '';
            // log it
            $diffs = [];
            if ($oldCaseVals->requestor != $newOwner->userid) {
                $diffs[] = "requester: `$oldCaseVals->requestor` => `$newOwner->userid`";
            }
            $e_old_region = $dbCls->escape_string($oldCaseVals->region);
            if ($oldCaseVals->region != $region) {
                $oldName = $oldRegion = $dbCls->fetchValue("SELECT name FROM region "
                    . "WHERE id='$e_old_region' LIMIT 1"
                );
                $newName = $newRegion = $dbCls->fetchValue("SELECT name FROM region "
                    . "WHERE id='$e_region' LIMIT 1"
                );
                $changeMsg .= '    ' . $_SESSION['regionTitle']
                    . " changed from $oldRegion to $newRegion\n";
                $diffs[] = "{$_SESSION['regionTitle']}: `($oldCaseVals->region) "
                    . "$oldName` => `($region) $newName`";
            }
            $e_new_dept = $dbCls->escape_string($dept);
            if ($oldCaseVals->dept != $dept) {
                $e_old_dept = $dbCls->escape_string($oldCaseVals->dept);
                $oldName = $oldDept = $dbCls->fetchValue("SELECT name FROM department "
                    . "WHERE id='$e_old_dept' LIMIT 1"
                );
                $newName = $newDept = $dbCls->fetchValue("SELECT name FROM department "
                    . "WHERE id='$e_new_dept' LIMIT 1"
                );
                $changeMsg .= '    ' . $_SESSION['departmentTitle']
                    . " changed from $oldDept to $newDept\n";
                $diffs[] = "{$_SESSION['departmentTitle']}: `($oldCaseVals->dept) "
                    . "$oldName` => `($dept) $newName`";
            }
            // Billing Unit
            if ($oldBuNames->billingUnitName != $newBuNames->billingUnitName) {
                $buTitle = $_SESSION['customLabel-billingUnit'];
                $changeMsg .= '    ' . $buTitle
                    . " changed from $oldBuNames->billingUnitName "
                    . "to $newBuNames->billingUnitName\n";
                $diffs[] = "$buTitle: `$oldBuNames->billingUnitName` "
                    . "=> `$newBuNames->billingUnitName`";
            }
            // Purchase Order
            if ($oldBuNames->purchaseOrderName != $newBuNames->purchaseOrderName) {
                $buTitle = $_SESSION['customLabel-purchaseOrder'];
                $changeMsg .= '    ' . $buTitle
                    . " changed from $oldBuNames->purchaseOrderName "
                    . "to $newBuNames->purchaseOrderName\n";
                $diffs[] = "$buTitle: `$oldBuNames->purchaseOrderName` "
                    . "=> `$newBuNames->purchaseOrderName`";
            }

            $msg = join(', ', $diffs);
            //Reassign Case Elements
            logUserEvent(89, $msg, $_SESSION['currentCaseID']);

            // Generate an email to notify the old and new owners
            include_once __DIR__ . '/../includes/php/'.'funcs_sysmail2.php';  //Require email functions
            $bodySubs = $subjectSubs = ['<userCaseNum>' => $oldCaseVals->userCaseNum];
            $bodySubs['<caseName>'] = $oldCaseVals->caseName;
            $bodySubs['<changeMsg>'] = $changeMsg;
            $bodySubs['<senderName>'] = $_SESSION['userName'];

            // bundle all arg for function call
            $recipient = $newOwner->userEmail;
            $recipient2 = '';
            if (is_object($oldOwner)
                && ($oldOwner->userEmail != $recipient)
                && $clientID != COKE_CLIENTID
            ) {
                $recipient2 = $oldOwner->userEmail;
            } else {
                $recipient2 = '';
            }
            if ($recipient == $_SESSION['userEmail']) {
                $recipient = '';
            }
            if ($recipient2 == $_SESSION['userEmail']) {
                $recipient2 = '';
            }

            if ($recipient || $recipient2) {
                $emailArgs = ['caseID'      => $caseID, 'recipient'   => $recipient, 'recipient2'  => $recipient2, 'subjectSubs' => $subjectSubs, 'bodySubs'    => $bodySubs];
                // mm put session dependencies in fGetSysEmail()!
                if (!isset($caseRow)) {
                    $caseRow = fGetCaseRow($caseID, MYSQLI_BOTH, false); // no failure email for re-assigns
                }
                $_SESSION['caseID'] = $caseID;
                $_SESSION['tpID'] = $caseRow['tpID'];
                sendSysEmail(EMAIL_REASSIGN_CASE_OWNER, $emailArgs);
                unset($_SESSION['caseID'], $_SESSION['tpID']);
            }
        }
    } // end else csrf check
    break;

case 'casesDiv-h':
    if (isset($_POST['h']) && ($height = intval($_POST['h']))) {
        //use to preset datatable div height on initializaition
        //This helps to suppress horizontal jump from vertical srollbar oscillation
        $_SESSION[$op] = $height;
        $jsObj->Result = 1;
    }
    break;

// Save custom data
case 'cscdsave':
case 'tpcdsave':
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        if ($op == 'cscdsave') {
            $jsObj->Errors = [PageAuth::getFailMessage($_SESSION['languageCode'])];
        } elseif ($op == 'tpcdsave') {
            $jsObj->Error = 'PgAuthError';
            $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
            $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
        }
    } else {
        $_POST['loadcasePgAuth'] = PageAuth::genToken('loadcasePgAuth');
        if (isset($_SESSION['currentCaseID'])
            && ($caseID = $_SESSION['currentCaseID'])
            && $_SESSION['userType'] > VENDOR_ADMIN
        ) {
            $cdCls = new customDataClass(0, $caseID);
            //SEC-3029: Get existing custom field data for Teva to compare against below
            if ($clientID == 9165) {
                $PriorCustomData = $cdCls->getData('case');
            }

            $jsObj = $cdCls->saveData($_POST);

            //SEC-3029: For Teva ONLY
            if ($clientID == 9165 && count($jsObj->Errors) == 0) {

                //Update wf status to "Recommendation Confirmed" when "Agreement with Recommendations" is set to Yes
                if (array_key_exists('cscd-id412', $_POST) && $_POST['cscd-id412'] == "895") {
                    //if no change...
                    if ($PriorCustomData && array_key_exists('dat412', $PriorCustomData)
                        && $PriorCustomData['dat412']->listItemID == "895") {
                        //...do nothing
                    } else {
                        $jsObj = $cdCls->saveData(['op' => 'cscdsave', 'cscd-id415' => 2112]
                        );

                        $sql = "SELECT tpID, hub_lead FROM cases WHERE id = {$caseID}";
                        [$tpID, $hubLead] = $dbCls->fetchArrayRow($sql);

                        //reassign to hub lead and notify
                        if ($hubLead) {

                            $sql = "UPDATE cases SET "
                                . "requestor = hub_lead "
                                . "WHERE id = '$caseID' LIMIT 1";
                            $dbCls->query($sql);

                            $sql = "SELECT userCaseNum FROM cases WHERE id = $caseID";
                            $userCaseNum = $dbCls->fetchValue($sql);

                            include_once '../includes/php/class_AppMailer.php';
                            $subject = "A case has been reassigned to you";
                            $message = "Recommendation has been confirmed on Case #$userCaseNum.\n\r" . SITE_PATH .
                                'case/casehome.sec?id=' . $caseID . '&cid=' . $clientID . '&tname=casefolder&rd=1';

                            AppMailer::mail($clientID, $hubLead, $subject, $message);
                        }

                        //update status on 3P to Approved
                        $sql = "UPDATE thirdPartyProfile SET "
                            . "approvalStatus = 'approved' "
                            . "WHERE id = '{$tpID}' LIMIT 1";
                        $dbCls->query($sql);
                    }
                }

                //Send notification and reassign when Workflow Status is set to "Recommendation Sent"
                if (array_key_exists('cscd-id415', $_POST) && $_POST['cscd-id415'] == "2111") {
                    //if "workflow status" already equaled "recommendation sent"...
                    if ($PriorCustomData && array_key_exists('dat415', $PriorCustomData)
                        && $PriorCustomData['dat415']->listItemID == "2111") {
                        //...do nothing
                    } else {
                        //...otherwise reassign the case to LCO and send a notification (if LCO is set)
                        $currentCustomData = $cdCls->getData('case');

                        $sql = "SELECT name FROM customSelectList WHERE id = ".$currentCustomData['dat419']->listItemID;
                        $newOwnerName = $dbCls->fetchValue($sql);

                        $sql = "SELECT name FROM customSelectList WHERE id = ".$currentCustomData['dat420']->listItemID;
                        $newOwnerEmail = $dbCls->fetchValue($sql);

                        //reassignment will only be done if name & email are selected
                        if ($newOwnerEmail && $newOwnerName) {
                            $sql = "SELECT userCaseNum FROM cases WHERE id = $caseID";
                            $userCaseNum = $dbCls->fetchValue($sql);

                            //Reassign case here
                            $sql = "UPDATE cases SET "
                                . "hub_lead = requestor, "
                                . "requestor='$newOwnerEmail' "
                                . "WHERE id = '$caseID' LIMIT 1";
                            $dbCls->query($sql);
                            //Do notification here
                            include_once '../includes/php/class_AppMailer.php';
                            $subject = "A case has been reassigned to you";
                            $message = "Dear $newOwnerName,\n\r" .
                                "We have a recommendation on case #$userCaseNum.\n\r" .
                                "Please advise of your recommendation.\n\r" . SITE_PATH .
                                'case/casehome.sec?id=' . $caseID . '&cid=' . $clientID . '&tname=casefolder&rd=1';
                            AppMailer::mail($clientID, $newOwnerEmail, $subject, $message);
                        }
                    }
                }
            }
        }
    }
    break;

case 'attach-del':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if ($accCls->allow('addCaseAttach') && $accCls->allow('accCaseAttach')) {
            if (($recid = intval($_POST['recid']))
                && ($tblCode = trim((string) $_POST['tc']))
                && (in_array($tblCode, ['Investigator', 'Client']))
                && isset($_SESSION['currentCaseID'])
                && ($caseID = $_SESSION['currentCaseID'])
                && ($clientID = $_SESSION['clientID'])
                && ($caseRow = fGetCaseRow($caseID))
                && $_SESSION['userSecLevel'] > READ_ONLY
            ) {
                $caseStage = $caseRow['caseStage'];
                $candel = false;
                switch ($tblCode) {
                case 'Investigator':
                    $tbl = 'iAttachments';
                    $qualifiedPerson = intval($accCls->allow('addCaseAttach')
                        && $session->secure_value('userClass') == 'vendor'
                    );
                    $stageAllows = ($caseStage < COMPLETED_BY_INVESTIGATOR);
                    $iCompleted = ($caseStage == COMPLETED_BY_INVESTIGATOR);
                    $elapsed = $dbCls->fetchValue("SELECT "
                        . "(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(creationStamp)) AS elapsed "
                        . "FROM $tbl WHERE id='$recid' AND caseID='$caseID' LIMIT 1"
                    );
                    // Allow deletion if stage < completed by investigator
                    //   or within 24 hours of upload if stage is completed by investigator
                    $candel = ($qualifiedPerson
                        && ($stageAllows
                        || ($iCompleted
                        && ($elapsed < 86400)))
                    );
                    $sql = "DELETE FROM $tbl WHERE id='$recid' AND caseID='$caseID' LIMIT 1";
                    break;
                case 'Client':
                    $candel = ($session->secure_value('userClass') != 'vendor');
                    $tbl = 'subInfoAttach';
                    $sql = "DELETE FROM $tbl WHERE id='$recid' "
                        . "AND caseID='$caseID' AND caseStage >= '$caseStage' LIMIT 1";
                    break;
                }
                if ($candel) {
                    $row = $dbCls->fetchObjectRow("SELECT filename, description FROM $tbl "
                        . "WHERE id='$recid' AND caseID='$caseID' LIMIT 1"
                    );
                    $dbCls->query($sql);
                    if ($dbCls->affected_rows()) {
                        include_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';
                        removeClientFile($tbl, $clientID, $recid);
                        logUserEvent(28, "`$row->filename' from case folder, "
                            . "description: `$row->description`", $caseID
                        );
                        $jsObj->Result = 1;
                    }
                }
            }
        }
    }// end else csrf check
    break;

case 'note-edit':
    $jsObj->Result = 0;
    $jsObj->Note = '';
    $jsObj->Subject = '';
    $jsObj->CatID = 0;
    if (($sig = $_POST['sig'])
        && ($recid = intval($_POST['recid']))
        && $_SESSION['userSecLevel'] > READ_ONLY
        && ($clientID = $_SESSION['clientID'])
        && isset($_SESSION['currentCaseID'])
        && ($caseID = $_SESSION['currentCaseID'])
        && $accCls->allow('accCaseNotes')
        && $accCls->allow('addCaseNote')
        && $session->secure_value('allowCaseNoteEdit')->owner == $e_ownerID
        && $session->secure_value('allowCaseNoteEdit')->sig == $sig
        && $session->secure_value('allowCaseNoteEdit')->rec == $recid
        && $session->secure_value('allowCaseNoteEdit')->case == $caseID
        && ($caseRow = fGetCaseRow($caseID))
    ) {

        if ($row = $dbCls->fetchObjectRow("SELECT "
            . "note, subject, noteCatID, bInvestigatorCanSee "
            . "FROM caseNote "
            . "WHERE id='$recid' AND clientID='$clientID' "
            . "AND caseID='$caseID' LIMIT 1"
        )) {
            $jsObj->Note = quoteJSONdata($row->note);
            $jsObj->Subject = quoteJSONdata($row->subject);
            $jsObj->CatID = $row->noteCatID;
            $jsObj->iCanSee = $row->bInvestigatorCanSee;
            $jsObj->Result = 1;
        }
    }
    break;

case 'note-del':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if (($sig = $_POST['sig'])
            && ($recid = intval($_POST['recid']))
            && $accCls->allow('accCaseNotes')
            && $accCls->allow('addCaseNote')
            && $_SESSION['userSecLevel'] > READ_ONLY
            && ($clientID = $_SESSION['clientID'])
            && isset($_SESSION['currentCaseID'])
            && ($caseID = $_SESSION['currentCaseID'])
            && $session->secure_value('allowCaseNoteEdit')->owner == $e_ownerID
            && $session->secure_value('allowCaseNoteEdit')->sig == $sig
            && $session->secure_value('allowCaseNoteEdit')->rec == $recid
            && $session->secure_value('allowCaseNoteEdit')->case == $caseID
            && ($caseRow = fGetCaseRow($caseID))
        ) {
            if ($row = $dbCls->fetchObjectRow("SELECT "
                . "subject "
                . "FROM caseNote "
                . "WHERE id='$recid' AND clientID='$clientID' "
                . "AND caseID='$caseID' LIMIT 1"
            )) {
                if ($dbCls->query("DELETE FROM caseNote "
                    . "WHERE id='$recid' AND clientID='$clientID' "
                    . "AND caseID='$caseID' AND ownerID = '$e_ownerID' "
                    . "LIMIT 1"
                ) && $dbCls->affected_rows() == 1
                ) {
                    $jsObj->Result = 1;
                    if ($_SESSION['sim-userClass'] == 'vendor') {
                        logUserEvent(92, 'Subject: ' . $row->subject, $caseID);
                    } else {
                        logUserEvent(30, 'Subject: ' . $row->subject, $caseID);
                    }
                    $session->secureUnset('allowCaseNoteEdit');
                }
            }
        }
    } // end else csrf check
    break;

case 'note-show':
    $jsObj->Result = 0;
    $jsObj->FormattedNote = '';
    $jsObj->Subject = '';
    $jsObj->Category = '';
    $jsObj->Time = '';
    $jsObj->Owner = '';
    $jsObj->iCanSee = 0;
    $jsObj->RecID = 0;
    if (($recid = intval($_POST['recid']))
        && $accCls->allow('accCaseNotes')
        && ($clientID = $_SESSION['clientID'])
        && isset($_SESSION['currentCaseID'])
        && ($caseID = $_SESSION['currentCaseID'])
        && ($caseRow = fGetCaseRow($caseID))
    ) {
        $utCond = '';
        switch ($_SESSION['sim-userClass']) {
        case 'admin':
            break;
        case 'client':
            $utCond .= ' AND c.bInvestigator=0';
            break;
        case 'vendor':
            $utCond .= ' AND (c.bInvestigator=1 OR c.bInvestigatorCanSee=1)';
            break;
        default:
            $utCond .= ' AND 0';
        }

        if ($row = $dbCls->fetchObjectRow("SELECT "
            . "c.note, c.created, nc.name AS category, c.noteCatID, "
            . "c.subject, IF(c.ownerID = -1, 'n/a', u.userName) AS owner, c.ownerID, "
            . "c.bInvestigatorCanSee, "
            . "((DATE_ADD(c.created, INTERVAL 30 MINUTE) > NOW()) "
            . "AND (ownerID = '$e_ownerID') AND (c.noteCatID >= 0)) AS candel, "
            . "DATE_ADD(c.created, INTERVAL 30 MINUTE) as toolate "
            . "FROM caseNote AS c "
            . "LEFT JOIN {$globaldb}.users AS u ON u.id = c.ownerID "
            . "LEFT JOIN noteCategory AS nc ON nc.id = c.noteCatID "
            . "WHERE c.id='$recid' AND c.clientID='$clientID' "
            . "AND c.caseID='$caseID'{$utCond} LIMIT 1"
        )) {
            $jsObj->FormattedNote = quoteJSONdata(formatMultilineText($row->note), 1);
            $jsObj->Subject = $row->subject;
            $jsObj->Time = $row->created . ' ' . ((SECURIMATE_ENV != 'Development') ? 'UTC': 'CST');
            $jsObj->Owner = $row->owner;
            if ($_SESSION['sim-userClass'] != 'vendor') {
                $jsObj->Category = $row->category;
            }
            $jsObj->CanDelete = $row->candel;
            $jsObj->iCanSee = $row->bInvestigatorCanSee;
            if ($row->candel) {
                $jsObj->UntilWhen = 'before ' . $row->toolate
                    . ' ' . ((SECURIMATE_ENV != 'Development') ? 'UTC': 'CST');
                $jsObj->EditSig = Crypt64::randString(24);
                $obj = new stdClass();
                $obj->rec = $recid;
                $obj->case = $caseID;
                $obj->sig = $jsObj->EditSig;
                $obj->owner = $row->ownerID;
                $session->secure_set('allowCaseNoteEdit', $obj);
            } else {
                $jsObj->UntilWhen = '';
                $jsObj->EditSig = '';
                $session->secureUnset('allowCaseNoteEdit');
            }
            $jsObj->RecID = $recid;
            if ($jsObj->FormattedNote) {
                $jsObj->Result = 1;
            }
        }
    }
    break;

case 'save-note':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if ($accCls->allow('accCaseNotes')
            && ($clientID = $_SESSION['clientID'])
            && ($subj = $_POST['subj'])
            && ($note = $_POST['note'])
            && (($_SESSION['sim-userClass'] == 'vendor') || ($cat = intval($_POST['cat'])))
            && isset($_SESSION['currentCaseID'])
            && ($caseID = $_SESSION['currentCaseID'])
            && ($caseRow = fGetCaseRow($caseID))
            && $_SESSION['userSecLevel'] > READ_ONLY
            && $accCls->allow('addCaseNote')
        ) {
            if ($session->secure_value('userClass') != 'vendor') {
                $iCanSee = (intval($_POST['isee']) == 1) ? 1: 0;
            } else {
                $iCanSee = 0;
            }
            $note = normalizeLF($note);
            $note = mb_substr($note, 0, 2000);
            $q_note = $dbCls->escape_string($note);
            $q_note = safeHtmlSpecialChars($q_note);

            // Updating?
            $e_subj = $dbCls->escape_string($subj);
            $e_subj = safeHtmlSpecialChars($e_subj);
            $e_iCanSee = $iCanSee;
            if (!isset($cat)) {
                $cat = 0; // investigators don't use categories
            }
            if (isset($_POST['recid'])
                && ($recid = intval($_POST['recid']))
                && ($sig = $_POST['sig'])
                && $session->secure_value('allowCaseNoteEdit')->owner == $e_ownerID
                && $session->secure_value('allowCaseNoteEdit')->sig == $sig
                && $session->secure_value('allowCaseNoteEdit')->rec == $recid
                && $session->secure_value('allowCaseNoteEdit')->case == $caseID
            ) {

                if ($dbCls->query("UPDATE caseNote SET "
                    . "noteCatID='$cat', "
                    . "clientID='$clientID', "
                    . "caseID='$caseID', "
                    . "subject='$e_subj', "
                    . "note='$q_note', "
                    . "ownerID='$e_ownerID', "
                    . "bInvestigatorCanSee='$e_iCanSee', "
                    . "created = NOW() "
                    . "WHERE id='$recid' AND clientID='$clientID' "
                    . "AND caseID='$caseID' AND ownerID='$e_ownerID' LIMIT 1"
                ) && $dbCls->affected_rows() == 1
                ) {
                    $jsObj->Result = 1;
                    if ($_SESSION['sim-userClass'] == 'vendor') {
                        logUserEvent(91, 'Subject: ' . $subj, $caseID);
                    } else {
                        logUserEvent(34, 'Subject: ' . $subj, $caseID);
                    }
                    $session->secureUnset('allowCaseNoteEdit');
                }
            } else {
                $bInvestigator = ($session->secure_value('userClass') == 'vendor') ? '1': '0';
                $e_bInvestigator = $bInvestigator;
                if ($dbCls->query("INSERT INTO caseNote SET "
                    . "noteCatID='$cat', "
                    . "clientID='$clientID', "
                    . "caseID='$caseID', "
                    . "subject='$e_subj', "
                    . "note='$q_note', "
                    . "ownerID='$e_ownerID', "
                    . "bInvestigator='$e_bInvestigator', "
                    . "bInvestigatorCanSee='$e_iCanSee', "
                    . "created = NOW()"
                ) && $dbCls->affected_rows() == 1
                ) {
                    $jsObj->Result = 1;
                    if ($_SESSION['sim-userClass'] == 'vendor') {
                        logUserEvent(90, 'Subject: ' . $subj, $caseID);
                    } else {
                        logUserEvent(29, 'Subject: ' . $subj, $caseID);

                        if ($iCanSee == 1) {

                            $clientDB = $dbCls->clientDBname($clientID);

                            $sql = 'SELECT userEmail, userName '
                                 . "FROM {$globaldb}.users "
                                 . 'WHERE id = ('
                                 . 'SELECT caseInvestigatorUserID '
                                 . "FROM {$clientDB}.cases "
                                 . "WHERE clientID = {$clientID} "
                                 . "AND id = {$caseID} "
                                 . 'AND caseStage = '.ACCEPTED_BY_INVESTIGATOR.')';

                            $investigator = $dbCls->fetchObjectRow($sql);

                            if (isset($investigator->userEmail) && !empty($investigator->userEmail)) {

                                //  Send new case note email to an accepted case's investigator
                                try {

                                    $link = "{$sitepath}case/casehome.sec?id={$caseID}&tname=casefolder".
                                        "&icli={$clientID}&rd=1";

                                    $tokens = [
                                        '{toName}'      =>  $investigator->userName,
                                        '{caseNum}'     =>  $caseRow['userCaseNum'],
                                        '{company}'     =>  $_SESSION['companyShortName'],
                                        '{caseName}'    =>  $caseRow['caseName'],
                                        '{caseType}'    =>  $_SESSION['caseTypeClientList'][$caseRow['caseType']],
                                        '{link}'        =>  $link,
                                        '{noteSubject}' =>  $e_subj,
                                        '{noteMessage}' =>  $note
                                    ];

                                    $emailContent = yaml_parse_file(__DIR__ . '/spNotices.yml');

                                    $subject = str_replace(
                                        array_keys($tokens),
                                        array_values($tokens),
                                        (string) $emailContent['addNote']['EMsubject']
                                    );

                                    $message = str_replace(
                                        array_keys($tokens),
                                        array_values($tokens),
                                        (string) $emailContent['addNote']['EMbody']
                                    );

                                    include_once '../includes/php/class_AppMailer.php';

                                    AppMailer::mail($clientID, $investigator->userEmail, $subject, $message);

                                } catch (Exception $e) {

                                    error_log(__FILE__.': '.$e->getMessage());
                                }
                            }
                        }
                    }
                }
            }
        }
    } //end else csrf check

    break;

case 'crev-note-edit':
    $jsObj->Result = 0;
    $jsObj->Note = '';
    $jsObj->Subject = '';
    $jsObj->CatID = 0;
    if (($sig = $_POST['sig'])
        && ($recid = intval($_POST['recid']))
        && $_SESSION['userSecLevel'] > READ_ONLY
        && ($clientID = $_SESSION['clientID'])
        && isset($_SESSION['currentCaseID'])
        && ($caseID = $_SESSION['currentCaseID'])
        && $accCls->allow('accCaseNotes')
        && $accCls->allow('addCaseNote')
        && $session->secure_value('allowCaseNoteEdit')->owner == $e_ownerID
        && $session->secure_value('allowCaseNoteEdit')->sig == $sig
        && $session->secure_value('allowCaseNoteEdit')->rec == $recid
        && $session->secure_value('allowCaseNoteEdit')->case == $caseID
        && ($caseRow = fGetCaseRow($caseID))
    ) {

        if ($row = $dbCls->fetchObjectRow("SELECT "
            . "note, subject, noteCatID, bInvestigatorCanSee "
            . "FROM caseNote "
            . "WHERE id='$recid' AND clientID='$clientID' "
            . "AND caseID='$caseID' LIMIT 1"
        )) {
            $jsObj->Note = quoteJSONdata($row->note);
            $jsObj->Subject = quoteJSONdata($row->subject);
            $jsObj->CatID = $row->noteCatID;
            $jsObj->iCanSee = $row->bInvestigatorCanSee;
            $jsObj->Result = 1;
        }
    }
    break;

case 'crev-note-del':
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->Error = 'PgAuthError';
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
    } else {
        if (($sig = $_POST['sig'])
            && ($recid = intval($_POST['recid']))
            && $accCls->allow('accCaseNotes')
            && $accCls->allow('addCaseNote')
            && $_SESSION['userSecLevel'] > READ_ONLY
            && ($clientID = $_SESSION['clientID'])
            && isset($_SESSION['currentCaseID'])
            && ($caseID = $_SESSION['currentCaseID'])
            && $session->secure_value('allowCaseNoteEdit')->owner == $e_ownerID
            && $session->secure_value('allowCaseNoteEdit')->sig == $sig
            && $session->secure_value('allowCaseNoteEdit')->rec == $recid
            && $session->secure_value('allowCaseNoteEdit')->case == $caseID
            && ($caseRow = fGetCaseRow($caseID))
        ) {
            if ($row = $dbCls->fetchObjectRow("SELECT "
                . "qID, nSect, subject "
                . "FROM caseNote "
                . "WHERE id='$recid' AND clientID='$clientID' "
                . "AND caseID='$caseID' LIMIT 1"
            )) {
                if ($dbCls->query("DELETE FROM caseNote "
                    . "WHERE id='$recid' AND clientID='$clientID' "
                    . "AND caseID='$caseID' AND ownerID = '$e_ownerID' "
                    . "LIMIT 1"
                ) && $dbCls->affected_rows() == 1
                ) {
                    $jsObj->Result = 1;
                    if ($_SESSION['sim-userClass'] == 'vendor') {
                        logUserEvent(92, 'Subject: ' . $row->subject, $caseID);
                    } else {
                        logUserEvent(30, 'Subject: ' . $row->subject, $caseID);
                    }
                    $session->secureUnset('allowCaseNoteEdit');
                }
            }
        }
        $numNotes = $dbCls->fetchValue("SELECT count(id) FROM caseNote "
            . "WHERE caseID='$caseID' "
            . "AND nSect='$row->nSect' "
            . "AND qID='$row->qID' "
            . "AND clientID='$clientID'"
        );
        $jsObj->numNotes = $numNotes;

    } // end else csrf check
    break;

case 'crev-note-init-show':
    //devDebug('crev-note-init-show', basename(__FILE__) . ': ' . __LINE__);
    $e_qID = htmlspecialchars($dbCls->esc($_GET['qID']), ENT_QUOTES, 'UTF-8');
    $e_aID = intval($_GET['aID']);
    $e_sect = intval($_GET['nSect']);
    $defOrderBy = 'subj';
    $allowOrderBy = ['subj' => 'n.subject', 'ndate' => 'n.created', 'owner' => 'u.lastName', 'cat' => 'nc.name'];

    if (isset($_GET['ord'])) {
        $orderBy = $_GET['ord'];
    } else {
        $orderBy = '';
    }

    if (!array_key_exists($orderBy, $allowOrderBy)) {
        $orderBy = $defOrderBy;
    }

    // Get row counts
    $cntWhere = "WHERE caseID='$e_caseID' "
        . "AND nSect='$e_sect' "
        . "AND qID='$e_qID' "
        . "AND clientID='$e_clientID'";
    switch ($userClass) {
    case 'admin':
        break;
    case 'client':
        $cntWhere .= ' AND bInvestigator=0';
        break;
    case 'vendor':
        $cntWhere .= ' AND (bInvestigator = 1 OR bInvestigatorCanSee = 1)';
        break;
    default:
        $cntWhere .= ' AND 0';
    }
    $cnt = $dbCls->fetchValue("SELECT COUNT(*) AS cnt FROM caseNote $cntWhere");

    if (!$toPDF) {

        $minPP = 15;
        $maxPP = 100;
        $si = intval($_GET['si']);
        $pp = intval($_GET['pp']);
        if (! in_array($pp, [15, 20, 30, 50, 75, 100])) {
            $pp = $minPP;
        }
        $sortDir = ($_GET['dir'] == 'yui-dt-desc') ? 'yui-dt-desc' : 'yui-dt-asc';

        // Calculate page (base 1), and validate startIndex (base 0)
        if (!$cnt) {
            $pg = 1;
            $si = 0;
        } else {
            if ($si >= $cnt) {
                $si = $cnt - 1;
            }

            $t = $si + 1;
            $t -= ($si % $pp);
            $si = $t - 1;
            $pg = ($si / $pp) + 1;
        }
        if ($si < 0) {
            $si = 0;
        }

    } // !$toPDF

    $sortDir = strtoupper((($sortDir == 'yui-dt-asc') ? 'ASC': 'DESC'));

    // Construct JSON output
    $jsData = "{\"Response\":{\n"
        . " \"Records\":[";
    $notesData = '[]';

    if ($cnt) {
        if (!$toPDF) {
            $orderLimit = "ORDER BY " . $allowOrderBy[$orderBy] . " $sortDir LIMIT $si, $pp";
        } else {
            $orderLimit = 'ORDER BY n.subject ASC';
        }

        $flds = "n.id AS dbid, "
            . "nc.name AS cat, "
            . "IF(n.ownerID = -1, 'n/a', u.lastName) AS owner, "
            . "LEFT(n.created,10) AS ndate, "
            . "n.subject AS subj, "
            . "n.note";

        $from = "FROM caseNote AS n "
            . "LEFT JOIN noteCategory AS nc ON nc.id = n.noteCatID "
            . "LEFT JOIN {$globaldb}.users AS u ON u.id = n.ownerID";

        $where = "WHERE n.caseID='$e_caseID' "
            . "AND nSect='$e_sect' "
            . "AND n.qID='$e_qID' "
            . "AND n.clientID='$e_clientID'";
        switch ($userClass) {
        case 'admin':
            break;
        case 'client':
            $where .= ' AND n.bInvestigator=0';
            break;
        case 'vendor':
            $where .= ' AND (n.bInvestigator = 1 OR n.bInvestigatorCanSee = 1)';
            break;
        default:
            $where .= ' AND 0';
        }

        $rows = $dbCls->fetchObjectRows("SELECT $flds $from $where $orderLimit");
        //if ($dbCls->error())
        //    devDebug($dbCls->error(), 'db err');

        if (is_array($rows) && count($rows)) {
            $limit = count($rows);
            $fix = ['cat', 'owner', 'subj', 'note'];
            if (!$toPDF) {
                for ($i = 0; $i < $limit; $i++) {
                    foreach ($fix AS $f) {
                        $rows[$i]->$f = quoteJSONdata($rows[$i]->$f);
                    }
                    $rows[$i]->del = ' ';
                }
                //devDebug($rows, '$rows');
                $jsData .= substr(json_encodeLF($rows), 1, -1);
            } else {
                $notesData = '[';
                for ($i = 0; $i < $limit; $i++) {
                    $row = $rows[$i];
                    foreach ($fix AS $f) {
                        $row->$f = quoteJSONdata($row->$f);
                    }
                    $fmt = 'subj: "' . $row->subj . '", '
                        //. 'cat: "' . $row->cat . '", '
                        . 'note: "'  . $row->note . '", '
                        . 'ndate: "' . $row->ndate . '", '
                        . 'owner: "' . $row->owner . '"';
                    $notesData .= (($i) ? ",\n  {" : '{') . $fmt . '}';
                }
                $notesData .= ']';
                //devDebug($notesData, 'notes data');
            }
        }

    } // if $cnt

    $jsData .= "],\n";
    if (!$splPDF) {
        $dbCls->close();
    }

    if (! $cnt) {
        $cnt = '0';
    }

    if (!$toPDF) {

        $jsData .= " \"Total\":$cnt,\n"
            .  " \"RowsPerPage\":$pp,\n"
            .  " \"Page\":$pg,\n"
            .  " \"RecordOffset\":$si\n}\n}";

        // Override normal headers to values more favorable to a JSON return value
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . "GMT");
        header("Cache-Control: no-cache, must-revalidate");
        header("Pragma: no-cache");
        //header("Content-Type: text/xml; charset=utf-8"); //AJAX
        header("Content-Type: text/plain; charset=utf-8"); //JSON

        //devDebug($jsData);

        echo $jsData;

    } else {

        echo <<<EOT

<script type="text/javascript">
YAHOO.namespace('cfData');

YAHOO.cfData.notesData = $notesData;

</script>

EOT;

    }
    break;

case 'crev-note-show':
    $jsObj->Result = 1;
    $jsObj->FormattedNote = '';
    $jsObj->Subject = '';
    $jsObj->Category = '';
    $jsObj->Time = '';
    $jsObj->Owner = 'crev-note-show stuff';
    $jsObj->iCanSee = 0;
    $jsObj->RecID = 0;
    if (($recid = intval($_POST['recid']))
        && ($e_sect = $dbCls->esc($_POST['nSect']))
        && $accCls->allow('accCaseNotes')
        && ($clientID = $_SESSION['clientID'])
        && isset($_SESSION['currentCaseID'])
        && ($caseID = $_SESSION['currentCaseID'])
        && ($caseRow = fGetCaseRow($caseID))
    ) {
        $utCond = '';
        switch ($_SESSION['sim-userClass']) {
        case 'admin':
            break;
        case 'client':
            $utCond .= ' AND c.bInvestigator=0';
            break;
        default:
            $utCond .= ' AND 0';
        }

        if ($row = $dbCls->fetchObjectRow("SELECT "
            . "c.note, c.created, nc.name AS category, c.noteCatID, "
            . "c.subject, IF(c.ownerID = -1, 'n/a', u.userName) AS owner, c.ownerID, "
            . "c.bInvestigatorCanSee, "
            . "((DATE_ADD(c.created, INTERVAL 30 MINUTE) > NOW()) "
            . "AND (ownerID = '$e_ownerID') AND (c.noteCatID >= 0)) AS candel, "
            . "DATE_ADD(c.created, INTERVAL 30 MINUTE) as toolate "
            . "FROM caseNote AS c "
            . "LEFT JOIN {$globaldb}.users AS u ON u.id = c.ownerID "
            . "LEFT JOIN noteCategory AS nc ON nc.id = c.noteCatID "
            . "WHERE c.id='$recid' AND c.clientID='$clientID' "
            . "AND c.nSect='$e_sect' "
            . "AND c.caseID='$caseID'{$utCond} LIMIT 1"
        )) {
            $jsObj->FormattedNote = quoteJSONdata(formatMultilineText($row->note), 1);
            $jsObj->Subject = $row->subject;
            $jsObj->Time = $row->created . ' ' . ((SECURIMATE_ENV != 'Development') ? 'UTC': 'CST');
            $jsObj->Owner = $row->owner;
            if ($_SESSION['sim-userClass'] != 'vendor') {
                $jsObj->Category = $row->category;
            }
            $jsObj->CanDelete = $row->candel;
            if ($row->candel) {
                $jsObj->UntilWhen = 'before ' . $row->toolate
                    . ' ' . ((SECURIMATE_ENV != 'Development') ? 'UTC': 'CST');
                $jsObj->EditSig = Crypt64::randString(24);
                $obj = new stdClass();
                $obj->rec = $recid;
                $obj->case = $caseID;
                $obj->sig = $jsObj->EditSig;
                $obj->owner = $row->ownerID;
                $session->secure_set('allowCaseNoteEdit', $obj);
            } else {
                $jsObj->UntilWhen = '';
                $jsObj->EditSig = '';
                $session->secureUnset('allowCaseNoteEdit');
            }
            $jsObj->RecID = $recid;
            if ($jsObj->FormattedNote) {
                $jsObj->Result = 1;
            }
        }
    }
    break;

case 'crev-save-note':
    // save reviewer note on case folder reviewer tab
    $jsObj->Result = 0;
    $jsObj->DoNewCSRF = 1;

    // check CSRF
    if (!isset($_POST['loadcasePgAuth'])
        || !PageAuth::validToken('loadcasePgAuth', $_POST['loadcasePgAuth'])
    ) {
        $jsObj->ErrorTitle = PageAuth::getFailTitle($_SESSION['languageCode']);
        $jsObj->ErrorMsg =  PageAuth::getFailMessage($_SESSION['languageCode']);
        break;
    }

    // check permissions
    if (!$accCls->allow('accCaseNotes')
        || !($clientID = $_SESSION['clientID'])
        || !isset($_SESSION['currentCaseID'])
        || !($caseID = $_SESSION['currentCaseID'])
        || !($caseRow = fGetCaseRow($caseID))
        || !$_SESSION['userSecLevel'] == READ_ONLY
        || !$accCls->allow('addCaseNote')
    ) {
        $jsObj->ErrorTitle = 'Access Denied';
        $jsObj->ErrorMsg = 'You do not have permission to access this page.';
        break;
    }

    // Detect missing input
    if (!($subj = trim((string) $_POST['subj']))
        || !($note = trim((string) $_POST['note']))
        || !($cat = intval($_POST['cat']))
    ) {
        // report missing input
        $tmp = [];
        if (!$subj) {
            $tmp[] = 'Subject';
        }
        if (!$cat) {
            $tmp[] = 'Category';
        }
        if (!$note) {
            $tmp[] = 'Note';
        }
        $jsObj->ErrorTitle = 'Input Missing';
        $jsObj->ErrorMsg = 'The folloing input is required: ' . joint(', ', $tmp);
        break;
    }

    // Insert or update reviewer note
    $note = normalizeLF($note);
    $note = mb_substr($note, 0, 2000);
    $e_subj = $dbCls->esc($subj);
    $e_note = $dbCls->esc($note);
    $e_qid  = $dbCls->esc($_POST['qid']);
    $e_sect = $dbCls->esc($_POST['nSect']);
    $recid = 0;
    $e_ownerID = $_SESSION['id'];
    if (isset($_POST['recid'])) {
        $recid = (int)$_POST['recid'];
    }

    // Updating?
    if ($recid) {
        // Check access control and limits
        $editControl = $session->secure_value('allowCaseNoteEdit');
        $sig = $_POST['sig'];
        if (!$sig || !is_object($editControl)
            || $editControl->owner != $e_ownerID
            || $editControl->sig != $sig
            || $editControl->rec != $recid
            || $editControl->case != $caseID
        ) {
            $jsObj->ErrorTitle = 'Operation Not Permitted';
            $jsObj->ErrorMsg = 'Note can no longer be modified.';
            break;
        }

        // okay to edit
        $sql = "UPDATE caseNote SET "
            . "noteCatID='$cat', "
            . "clientID='$clientID', "
            . "caseID='$caseID', "
            . "qID='$e_qid', "
            . "subject='$e_subj', "
            . "note='$e_note', "
            . "ownerID='$e_ownerID', "
            . "created = NOW() "
            . "WHERE id='$recid' AND clientID='$clientID' "
            . "AND nSect='$e_sect' "
            . "AND caseID='$caseID' AND ownerID='$e_ownerID' LIMIT 1";
        if ($dbCls->query($sql)) {
            $jsObj->Result = 1;
            $session->secureUnset('allowCaseNoteEdit');
            if ($dbCls->affected_rows()) {
                logUserEvent(34, 'Subject: ' . $subj, $caseID);
            }
        }
    } else {
        // insert
        $sql = "INSERT INTO caseNote SET "
            . "noteCatID='$cat', "
            . "clientID='$clientID', "
            . "caseID='$caseID', "
            . "qID='$e_qid', "
            . "subject='$e_subj', "
            . "note='$e_note', "
            . "ownerID='$e_ownerID', "
            . "created = NOW(), "
            . "nSect='$e_sect'";
        if ($dbCls->query($sql)) {
            $jsObj->Result = 1;
            if ($dbCls->affected_rows()) {
                logUserEvent(29, 'Subject: ' . $subj, $caseID);
            }
        }
    }
    $numNotes = $dbCls->fetchValue("SELECT count(id) FROM caseNote "
        . "WHERE caseID='$caseID' "
        . "AND nSect='$e_sect' "
        . "AND qID='$e_qid' "
        . "AND clientID='$clientID'"
    );
    $jsObj->numNotes = $numNotes;
    break;

case 'refresh-stagelist':
case 'rsf':  // refresh Stage filter on Case List
    $status = htmlspecialchars($_POST['stat'], ENT_QUOTES, 'UTF-8');
    if ($stages = caseStageListByStatus($status)) {
        $allText = match ($status) {
            'all_cases' => 'All Stages',
            'only_closed_cases' => 'All Closed Stages',
            default => 'All Open Stages',
        };

        $jsObj->SelectValue = 'all_stages';
        $jsObj->AllText = $allText;
        $jsObj->Result = 1;
        if ($op == 'refresh-stagelist') {
            $jsObj->Stages = [];
            foreach ($stages as $v => $t) {
                $o = new stdClass();
                $o->v = $v;
                $o->t = $t;
                $jsObj->Stages[] = $o;
            }
            break;
        }
        $jsObj->Stages = $stages;
        $stg = $_POST['stg'];
        include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
        $jsObj->Regions = regionsByUserID($_SESSION['id']);
        $gsrchSrc = 'CL';
        if (array_key_exists('caseFilterSrc', $_SESSION)) {
            $gsrchSrc = $_SESSION['caseFilterSrc'];
        }
        if ($gsrchSrc == 'SB') {
            $_SESSION['gsrch']['CL']['fld'] = $_SESSION['gsrch']['SB']['cs']['fld'];
        } elseif ($gsrchSrc == 'AF') {
            $_SESSION['gsrch']['CL']['fld'] = $_SESSION['gsrch']['AF']['cs']['flds'][0];
        }
        $_SESSION['gsrch']['CL']['srch'] = '';
        $_SESSION['gsrch']['CL']['scope'] = 0; // shouldn't be needed
        $_SESSION['caseFilterSrc'] = 'CL';
    }
    break;

case 'vendor-assignCase':
    $acCaseID = (!empty($_POST['c']) && is_numeric($_POST['c'])) ? (int)$_POST['c'] : '';
    $acClientID = (!empty($_POST['cl']) && is_numeric($_POST['cl'])) ? (int)$_POST['cl'] : '';
    $acInvID = (!empty($_POST['i']) && is_numeric($_POST['i'])) ? (int)$_POST['i'] : '';
    $acInvOldID = (!empty($_POST['io']) && is_numeric($_POST['io'])) ? (int)$_POST['io'] : '';
    $acMgrID = (int)$_SESSION['id'];

    $acSpID = (int)$_SESSION['vendorID'];

    $err = 0;
    $msg = '';
    if (empty($acCaseID)) {
        $err++;
        $msg .= '- Invalid case reference.<br />';
    }

    if (empty($acClientID)) {
        $err++;
        $msg .= '- Invalid client reference.<br />';
    }

    if (empty($acInvID)) {
        $err++;
        $msg .= '- You must select an Investigator.<br />';
    } else {
        $newInv = $dbCls->fetchObjectRow("SELECT userName, userEmail, firstName, lastName "
            ."FROM ". GLOBAL_DB .".users "
            ."WHERE id='$acInvID' LIMIT 1"
        );

        if (!$newInv) {
            $err++;
            $msg .= '- Invalid investigator selected.';
        }
    }


    if ($err > 0) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Missing Input Data';
        $jsObj->ErrorMsg = $msg;
        break;
    }

    if (!empty($newInv->firstName) && !empty($newInv->lastName)) {
        $newInvName = $newInv->firstName .' '. $newInv->lastName;
    } else {
        $newInvName = $newInv->userName;
    }

    // no errors, no change.
    if ($acInvID == $acInvOldID) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Reassign Investigator Error';
        $jsObj->ErrorMsg = $newInvName .' is already the investigator for this case.';
        break;
    }

    $clDB = $dbCls->fetchValue("SELECT DBname FROM " . GLOBAL_DB . ".clientDBlist "
        ."WHERE clientID = '$acClientID' LIMIT 1"
    );

    $sql = "UPDATE {$clDB}.cases "
        ."SET "
            ."caseInvestigatorUserID = '$acInvID', "
            ."assigningProjectMgrID = '$acMgrID', "
            ."reassignDate = NOW() "
        ."WHERE id = '$acCaseID' AND clientID = '$acClientID' AND caseAssignedAgent = '$acSpID'";

    $dbCls->query($sql);
    if ($dbCls->affected_rows() == 0) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Reassign Investigator Error';
        $jsObj->ErrorMsg = 'Unable to reassign the investigator at this time.';
        break;
    }

    // freshen the global case index
    include_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
    $globalIdx = new GlobalCaseIndex($clientID);
    $globalIdx->syncByCaseData($caseID);

    $oldInv = $dbCls->fetchValue("SELECT userName FROM ". GLOBAL_DB .".users "
        ."WHERE id='$acInvOldID' LIMIT 1"
    );

    if (!$oldInv && intval($acInvOldID)) {
        $oldInv = 'id #' . $acInvOldID;
    }

    if (!$newInv && $acInvID) {
        $is = 'id #' . $acInvID;
    }

    $logMsg = "assign investigator: $oldInv => ". $newInv->userName;
    logUserEvent(78, $logMsg, $acCaseID, 0, $acClientID);

    include_once __DIR__ . '/../includes/php/'.'funcs.php';
    fSendSysEmail(EMAIL_RI_NOTIFICATION, $acCaseID);

    $jsObj->Result = 1;
    $jsObj->NewEmail = '<td>Email:</td><td>'. $newInv->userEmail .'</td>';
    $jsObj->NewName = '<td>Investigator:</td><td>'. $newInvName .'</td>';
    $jsObj->NewInv = $acInvID;
    break;

case 'vendor-assignCaseTask':
    $atCaseID = (!empty($_POST['c']) && is_numeric($_POST['c'])) ? (int)$_POST['c'] : '';
    $atClientID = (!empty($_POST['cl']) && is_numeric($_POST['cl'])) ? (int)$_POST['cl'] : '';
    $atInvID = (!empty($_POST['i']) && is_numeric($_POST['i'])) ? (int)$_POST['i'] : '';
    $atTaskID = (!empty($_POST['t']) && is_numeric($_POST['t'])) ? (int)$_POST['t'] : '';
    $e_atDetail = (!empty($_POST['d'])) ? $dbCls->escape_string(strip_tags((string) $_POST['d'])) : '';

    $err = 0;
    $msg = '';
    if (empty($atCaseID)) {
        $err++;
        $msg .= '- Invalid case reference.<br />';
    }

    if (empty($atClientID)) {
        $err++;
        $msg .= '- Invalid client reference.<br />';
    }

    if (empty($atInvID)) {
        $err++;
        $msg .= '- You must select an Investigator.<br />';
    }

    if (empty($atTaskID)) {
        $err++;
        $msg .= '- You must select a task to assign.<br />';
    }

    if ($err > 0) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Missing Input Data';
        $jsObj->ErrorMsg = $msg;
        break;
    }

    $spID = (int)$_SESSION['vendorID'];
    $sql = "INSERT INTO ". GLOBAL_SP_DB .".spCaseTasks "
        ."SET "
            ."spID = '$spID', "
            ."clientID = '$atClientID', "
            ."caseID = '$atCaseID', "
            ."userID = '$atInvID', "
            ."taskListID = '$atTaskID', "
            ."taskStatusID = '101', "
            ."taskDetails = '$e_atDetail', "
            ."created = '". date('Y-m-d H:i:s') ."'";

    if ($dbCls->query($sql)) {
        $jsObj->Result = 1;
        $jsObj->TaskID = $dbCls->insert_id();
        $trResponse = locCreateTaskRow($jsObj->TaskID, $dbCls, true);
        $jsObj->html = $trResponse->html;

        // notify user of new task assignment.
        locTaskNotification($trResponse->who, $trResponse->email, $trResponse->task);

        // log this event.
        $logMsg = 'Assign Case Task ('. $trResponse->task .') to investigator: '. $trResponse->who;
        logUserEvent(140, $logMsg, $atCaseID, 0, $atClientID);
        break;
    }

    $jsObj->Result = 0;
    $jsObj->ErrorTitle = 'Add Task Error';
    $jsObj->ErrorMsg = 'Unable to add new task at this time.';
    break;

case 'vendor-viewTask':
case 'vendor-editTask':
case 'vendor-acceptTask':
    switch($op) {
        case 'vendor-editTask':
            $showEdit = 'edit';
            $taskText = ['up' => 'Edit', 'low' => 'edit'];
            break;
        case 'vendor-acceptTask':
            $showEdit = 'accept';
            $taskText = ['up' => 'Accept', 'low' => 'accept'];
            break;
        default:
            $showEdit = 'view';
            $taskText = ['up' => 'View', 'low' => 'view'];
    }

    if (empty($_POST['t']) || !is_numeric($_POST['t'])) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = $taskText['up'] .' Task Error';
        $jsObj->ErrorMsg = 'Unable to '. $taskText['low'] .' task. Invalid/Missing task reference.';
        break;
    }

    $taskID = (int)$_POST['t'];
    $task = locSetupCtPanel($taskID, $showEdit, $dbCls, $caseID);
    if (!$task) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = $taskText['up'] .' Task Error';
        $jsObj->ErrorMsg = 'Unable to '. $taskText['low'] .' task. Task does not exist.';
        break;
    }

    $jsObj->Result = 1;
    $jsObj->ctTitle = $task->title;
    $jsObj->ctBody = $task->body;
    break;

case 'vendor-updateTask':
case 'vendor-updateOSITask':
    $err = 0;
    $msg = '';

    $etListID = (!empty($_POST['tl']) && is_numeric($_POST['tl'])) ? (int)$_POST['tl'] : '';
    $etUserID = (!empty($_POST['tu']) && is_numeric($_POST['tu'])) ? (int)$_POST['tu'] : '';

    if($op == 'vendor-updateTask') {
        $e_etDetail = (!empty($_POST['d'])) ? $dbCls->escape_string(strip_tags((string) $_POST['d'])) : '';
        if (empty($etListID)) {
            $err++;
            $msg .= '- You must select a task to assign.<br />';
        }

        if (empty($etUserID)) {
            $err++;
            $msg .= '- You must select an Investigator.<br />';
        }
    }

    $etTaskID = (!empty($_POST['t']) && is_numeric($_POST['t'])) ? (int)$_POST['t'] : '';
    if (empty($etTaskID)) {
        $err++;
        $msg .= '- Invalid task reference.<br />';
    }

    $etStatusID = (!empty($_POST['s']) && is_numeric($_POST['s'])) ? (int)$_POST['s'] : '';
    if (empty($etStatusID)) {
        $err++;
        $msg .= '- You must select a status.<br />';
    }

    if ($err > 0) {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Missing Input Data';
        $jsObj->ErrorMsg = $msg;
        break;
    }

    $spID = (int)$_SESSION['vendorID'];
    // grabbing current values to check for changes so we know what to log.
    $oldTask = $dbCls->fetchObjectRow("SELECT * FROM ". GLOBAL_SP_DB .".spCaseTasks "
        ."WHERE taskID = '$etTaskID'"
    );

    $sql = "UPDATE " . GLOBAL_SP_DB . ".spCaseTasks "
        . "SET ";

    if($op == 'vendor-updateTask') {
        $sql .= "userID = '$etUserID', "
            . "taskListID = '$etListID', "
            . "taskDetails = '$e_etDetail', ";
    }

    $sql .= "taskStatusID = '$etStatusID' WHERE taskID = '$etTaskID'";

    $steeleTaskChg               = 0;
    $skipWriterPoolManagerAccept = false;

    if ($spID == STEELE_INVESTIGATOR_PROFILE) {
        // These defines are unique to STEELE notifications, therefore being defined here
        //  from GLOBAL_SP_DB.spLists.listID
        define('OSI_RESEARCHER', 42);
        define('OSI_WRITER', 43);
        define('WRITER_POOL_MANAGER', 44);
        //  from GLOBAL_SP_DB.spCaseTasksStatus.taskStatusID
        define('ACCEPTED_TASK_STATUS', 201);
        define('DELETED_TASK_STATUS', 10);

        if ($etStatusID == ACCEPTED_TASK_STATUS || $etStatusID == DELETED_TASK_STATUS) {
            switch ($etListID) {
                case OSI_WRITER:
                    $steeleTaskChg = (int)$caseID;
                    break;
                case WRITER_POOL_MANAGER:
                    $skipWriterPoolManagerAccept = true;
                    break;
                default:
                    $steeleTaskChg = -1;
                    break;
            }
        }
    }

    if ($dbCls->query($sql)) {
        $jsObj->Result = 1;
        $jsObj->TaskID = $etTaskID;
        $trResponse = locCreateTaskRow($etTaskID, $dbCls, false, $session->secure_value('uTypeName'), $_POST['cinv']);
        $jsObj->html = $trResponse->html;

        // log this event.
        $logMsg = 'Case task ('. $trResponse->task .'): ';
        $logEventID = 141; // update case task
        $taskChange = '';
        $msgSent = false;
        if ($etStatusID == 1) {
            $logMsg .= 'Has been deleted.';
            $logEventID = 142; // delete case task;
            $taskChange = "    - The task previously assigned to you has been deleted.\n";
        } else {
            $logMsg .= 'Has been updated.';
            if ($oldTask->userID != $etUserID) {
                $logMsg .= ' Task now assigned to '. $trResponse->who .'.';
                $taskChange .= "    - The task has been reassigned to another investigator.\n";
                // notify newly assigned user of new task assignment.
                locTaskNotification($trResponse->who, $trResponse->email, $trResponse->task);

                // give the old user the boot.
                $ouSql = "SELECT u.lastName, u.firstName, u.userEmail, tn.itemName "
                    ."FROM ". GLOBAL_DB .".users AS u, ". GLOBAL_SP_DB .".spLists AS tn "
                    ."WHERE u.id = '{$oldTask->userID}' AND tn.listID = '{$oldTask->taskListID}' "
                    ."LIMIT 1";
                $oldUser = $dbCls->fetchObjectRow($ouSql);
                $ouName  = $oldUser->firstName .' '. $oldUser->lastName;
                $ouEmail = $oldUser->userEmail;
                $ouTask  = $oldUser->itemName;
                locTaskNotification($ouName, $ouEmail, $ouTask, true, $taskChange);

                // no need to send another message.
                $msgSent = true;
            }
            if ($oldTask->taskListID != $etListID) {
                $logMsg .= ' Specific task changed to '. $trResponse->task .'.';
                $taskChange .= "    - The task type has been changed to \"".
                    $trResponse->task ."\".\n";
            }
            if ($oldTask->taskStatusID != $etStatusID) {
                $logMsg .= ' Task status is now: '. $trResponse->status .'.';
                $taskChange .= "    - The task status has been changed to \"".
                    $trResponse->status ."\".\n";
            }
            if (($op == 'vendor-updateTask') && ($dbCls->esc($oldTask->taskDetails) != $e_etDetail)) {
                $logMsg .= ' Task details have been updated.';
                $taskChange .= "    - The details related to this task have been updated.\n";
            }
        }

        // send out a notification to the assignee if we aren't doing a reassign.
        if (!$msgSent && ($steeleTaskChg == 0) && !$skipWriterPoolManagerAccept) {
            locTaskNotification($trResponse->who, $trResponse->email, $trResponse->task,
                true, $taskChange
            );
        } elseif ($steeleTaskChg) {
            locTaskNotification($trResponse->who, $trResponse->email, $trResponse->task,
                true, $taskChange, $steeleTaskChg
            );
        }

        // log the event
        logUserEvent($logEventID, $logMsg, $oldTask->caseID, 0, $oldTask->clientID);

    } else {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Update Task Error';
        $jsObj->ErrorMsg = 'Unable to update the task at this time.';
    }
    break;

case 'spDueDate':
    $err = 0;
    $msg = '';
    $pdt = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['pdt'])) {
        $err++;
        $msg .= '- Invalid Date Specified;<br />';
    } else {
        $pdt = $_POST['pdt'];
    }

    if (!is_numeric($_POST['c'])) {
        $err++;
        $msg .= '- Invalid Case Reference;<br />';
    } else {
        $caseID = (int)$_POST['c'];
    }

    if (!is_numeric($_POST['cl'])) {
        $err++;
        $msg .= '- Invalid Client Reference;<br />';
    } else {
        $clientID = (int)$_POST['cl'];
    }

    if ($err > 0) {
        if ($pdt  == '') {
            $jsObj->Result = 0;
        } else {
            $jsObj->Result = 1;
        }
        $jsObj->ErrorTitle = 'Internal Due Date Error';
        $jsObj->ErrorMsg = 'Unable to update the internal due date at this time.<br />'. $msg;
        break;
    }

    $clientDB = $dbCls->clientDBname($clientID);
    $sql = "SELECT internalDueDate FROM {$clientDB}.cases "
        ."WHERE id='$caseID' AND clientID ='$clientID' LIMIT 1";
    $oldDate = $dbCls->fetchValue($sql);

    $sql = "UPDATE {$clientDB}.cases SET internalDueDate = '$pdt' "
        ."WHERE id='$caseID' AND clientID ='$clientID' LIMIT 1";
    if ($dbCls->query($sql)) {
        $jsObj->Result = 1;
        if (!$dbCls->affected_rows()) {
            break;
        }

        // freshen the global case index
        include_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';
        $globalIdx = new GlobalCaseIndex($clientID);
        $globalIdx->syncByCaseData($caseID);

        $logEventID = 144; // Change Internal Due Date;
        $logMsg = 'Changed from '. $oldDate .' to '. $pdt;
        logUserEvent($logEventID, $logMsg, $caseID, 0, $clientID);
    } else {
        $jsObj->Result = 0;
        $jsObj->ErrorTitle = 'Internal Due Date Error';
        $jsObj->ErrorMsg = 'Unable to update the internal due date at this time.';
    }
    break;

default:
    $jsObj->Result = 0;

} // dispatcher
if ($jsObj->DoNewCSRF) {
    $jsObj->loadcasePgAuth = PageAuth::genToken('loadcasePgAuth');
}
// Override normal headers to values more favorable to a JSON return value
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s")  . "GMT");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Content-Type: text/plain; charset=utf-8"); //JSON

$jsData = json_encodeLF($jsObj);
//devDebug($jsData, 'jsData');

echo $jsData;




/*************************
 *    Local Functions    *
 *************************/

/**
 * Generates html for the view/edit case task panel.
 *
 * @param integer $taskID   ID of the task being viewed/edited;
 * @param boolean $showEdit Whether this is a 'view', 'edit' or 'accept' of the task;
 * @param object  $dbCls    Instance of the dbCls.
 * @param integer $caseID   Case ID.
 *
 * @return object $rtn      Returns title and body for the panel display.
 */
function locSetupCtPanel($taskID, $showEdit, $dbCls, $caseID)
{
    $taskID = (int)$taskID;
    $spID = (int)$_SESSION['vendorID'];

    $tableTask = GLOBAL_SP_DB .'.spCaseTasks';
    $tableTaskStatus = GLOBAL_SP_DB .'.spCaseTasksStatus';
    $tableTaskNames = GLOBAL_SP_DB .'.spLists';
    $tableUsers = GLOBAL_DB .'.users';
    $sql = "SELECT t.*, ts.statusName, tn.itemName AS taskName, "
        ."CONCAT(lastName, ', ', firstName) AS displayName "
        ."FROM $tableTask AS t "
        ."LEFT JOIN $tableTaskStatus AS ts ON (t.taskStatusID = ts.taskStatusID) "
        ."LEFT JOIN $tableTaskNames AS tn ON (t.taskListID = tn.listID) "
        ."LEFT JOIN $tableUsers AS u ON (t.userID = u.id) "
        ."WHERE (t.taskID = '$taskID' AND t.spID = '$spID') "
        ."LIMIT 1";

    $task = $dbCls->fetchObjectRow($sql);
    if (!$task) {
        return false;
    }

    $task->modified = ($task->modified == '0000-00-00 00:00:00') ? $task->created : $task->modified;

    $dtCreated = date('Y-m-d', strtotime((string) $task->created));
    $dtModified = date('Y-m-d', strtotime((string) $task->modified));

    $rtn = (object)null;
    if ($showEdit == 'edit') {
        $rtn->title = '<div style="width:350px;">Editing Task: '. $task->taskName .'</div>';
        $rtn->body = '
        <table cellspacing="0" cellpadding="5" border="0" width="98%">
        <tr>
            <th class="ta-left flex-fit">Assigned Task:</th>
            <td>'.
                fTaskListEdit(['id'=>'taskListID', 'name'=>'taskListID'], $task->taskListID)
            .'</td>
        </tr><tr>
            <th class="ta-left flex-fit">Assigned Agent:</th>
            <td>'.
                fUserDDLEdit(['id'=>'taskUserID', 'name'=>'taskUserID'], $task->userID)
            .'</td>
        </tr><tr>
            <th class="ta-left flex-fit">Current Status:</th>
            <td>'. fStatusEdit($task->taskStatusID) .'</td>
        </tr><tr>
            <th class="ta-left va-top">Details:</th>
            <td><textarea id="taskDetails" cols="30" rows="5" '
                .'class="marg-rt0 cudat-large cudat-small-h">'. $task->taskDetails .'</textarea>'
                .'<br />'
            .'</td>
        </tr><tr>
            <td>&nbsp;</td>
            <td class="ta-right marg-topsm"><div class="yui-button yui-push-button">
            <span class="first-child"><button
                onclick="_opRequest(\'vendor-updateTask\'); '
                    .'spfunc.caseTaskPnl.hide();" class="btn">Update</button></span></div>
                <div class="yui-button yui-push-button"><span class="first-child"><button
                    onclick="spfunc.caseTaskPnl.hide();" class="btn">Cancel</button></span></div>
                <input type="hidden" name="ctID" id="ctID" value="'. $taskID .'" />
            </td>
        </tr>
        </table>';

        return $rtn;
    }

    if ($showEdit == 'accept') {
        $caseRow = fGetCaseRow($caseID);
        $statusHTML = fStatusEdit($task->taskStatusID, true);
        $updateBtn  = '<div class="yui-button yui-push-button"><span class="first-child">
                           <button onclick = "_opRequest(\'vendor-updateOSITask\'); spfunc.caseTaskPnl.hide();" class="btn"> Update</button>
                       </span></div>'
                       . '<input type="hidden" name="taskRef" id="taskRef" value="' . $caseRow['userCaseNum'] . '" />'
                       . '<input type="hidden" name="taskListID" id="taskListID" value="' . $task->taskListID . '" />'
                       . '<input type="hidden" name="taskUserID" id="taskUserID" value="' . $task->userID . '" />'
                       . '<input type="hidden" name="cInv" id="cInv" value="' . $caseRow['caseInvestigatorUserID'] . '" />'
                       . '<input type="hidden" name="ctID" id="ctID" value="' . $taskID . '" />';
    } else {
        $statusHTML = $task->statusName;
        $updateBtn = '';

    }

    $rtn->title = '<div style="width:350px;">Viewing Task: '. $task->taskName .'</div>';
    $rtn->body = '
        <table cellspacing="0" cellpadding="5" border="0" width="98%">
        <tr>
            <th class="ta-left flex-fit">Assigned Task:</th>
            <td>'. $task->taskName .'</td>
        </tr><tr>
            <th class="ta-left flex-fit">Assigned Agent:</th>
            <td>'. $task->displayName .'</td>
        </tr><tr>
            <th class="ta-left flex-fit">Current Status:</th>
            <td>'. $statusHTML .'</td>
        </tr><tr>
            <th class="ta-left flex-fit va-top" style="border-top:1px solid #cacaca; '
                .'border-bottom:1px solid #cacaca;">Details:</th>
            <td style="border-top:1px solid #cacaca; border-bottom:1px solid #cacaca;">
                '. str_replace(["\n", "\r\n"], '<br />', (string) $task->taskDetails) .'
            </td>
        </tr><tr>
            <th class="ta-left flex-fit">Created Date:</th>
            <td>'. $dtCreated .'</td>
        </tr><tr>
            <th class="ta-left flex-fit">Last Modified:</th>
            <td>'. $dtModified .'</td>
        </tr><tr>
            <td>&nbsp;</td>
            <td>
            <div class="ta-right">'
                . $updateBtn .
                '<div class="yui-button yui-push-button"><span class="first-child">
                    <button class="marg-rt0 btn" onclick="spfunc.caseTaskPnl.hide()">Close</button>
                </span></div>
            </div>
            </td>
        </tr>
        </table>';

    return $rtn;
} // end locSetupCtPanel();

/**
 * Create an array of users to be placed into a select list. Can return the list values,
 * or echo ready html.
 *
 * @param array   $listAtts   Any desired attributes for the select list (default: false);
 * @param integer $select     ID of the user to be initially selected. (default: empty);
 * @param boolean $nameFormat Format the name as "Last, First" (default) or "First Last".
 * @param boolean $output     True value will return object of results. False returns html
 *                            formatted select list. (default: 0/html);
 *
 * @return html/object/boolean    Returns html or object based on value of $output,
 *                                or false if there are no results.
 */
function fUserDDLEdit($listAtts = false, $select = false, $nameFormat = false, $output = false)
{
    $dbCls = dbClass::getInstance();
    // Set our Where Clause based on Client or Vendor
    $szWhereClause = "";
    $userType = VENDOR_ADMIN;

    if ($_SESSION['userType'] <= VENDOR_ADMIN) {
        $szWhereClause = "WHERE `vendorID` = ".intval($_SESSION['vendorID']);
    } else {
        $szWhereClause = "WHERE `clientID` = '".intval($_SESSION['clientID'])."' "
            ."AND `userType` > '$userType' AND `userType` <= '".intval($_SESSION['userType'])."'";
    }
    // Get User Names and IDs
    $sql = "SELECT `id`, `lastName`, `firstName` FROM ". GLOBAL_DB .".users $szWhereClause AND "
        ."(`status` = 'active' OR `status` = 'pending') ORDER BY `lastName`";

    $userList = $dbCls->fetchObjectRows($sql);

    // Check for error
    if (!$userList) {
        return false;
    }

    if (!empty($output)) {
        return $userList;
    }

    if (!is_array($listAtts)) {
        $listAtts = ['id' => 'taskList', 'name' => 'taskList'];
    }

    // Create the List w/the atrtibutes passed to the function. Use defaults if needed.
    $html = '<select';
    if (isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'];
    } elseif (!isset($listAtts['name']) && isset($listAtts['id'])) {
        $listAtts['name'] = $listAtts['id'];
    } elseif (!isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'] = 'userSeletion';
    }
    foreach ($listAtts as $att => $val) {
        // space before is important.
        $html .= ' '. $att .'="'. $val .'"';
    }
    $html .= '>'; // closes opening tag.

    if (empty($select)) {
        $html .= '<option value="">Please select a user</option>';
    }

    // Loop through inserting options into drop down
    foreach ($userList as $user) {
        if ($select == $user->id) {
            $selected = ' selected="selected"';
        } else {
            $selected = '';
        }

        if (!$nameFormat) {
            $displayName = $user->lastName .', '. $user->firstName;
        } else {
            $displayName = $user->firstName .' '. $user->lastName;
        }

        $html .= '<option value="'. $user->id .'"'. $selected .'>'. $displayName .'</option>';
    } // end foreach

    // close select list
    $html .= '</select>';

    return $html;
} // end fUserDDLEdit();

/**
 * Fetch task names and (optionally) return a select list.
 *
 * @param array   $listAtts  Any desired attributes for the select list (default: false);
 * @param integer $currentID ID of the currently assigned task name. (default: false);
 * @param boolean $output    Return the db result (true) or build a select list. (default: false);
 *
 * @return object/string $html  Returns db result, or full select list html.
 */
function fTaskListEdit($listAtts = false, $currentID = false, $output = false)
{
    $dbCls = dbClass::getInstance();

    $spID = intval($_SESSION['vendorID']);
    /*
     * listTypeID = 1 for case tasks
     * could also do a join on spListsType, looking for caseTask, and join on the id from that.
    */
    $sql = "SELECT listID, itemName FROM ". GLOBAL_SP_DB .".spLists "
        ."WHERE spID = '$spID' AND listTypeID = '1' ORDER BY sequence, itemName";
    $taskList = $dbCls->fetchObjectRows($sql);
    if (!$taskList) {
        return false;
    }

    if (!empty($output)) {
        return $taskList;
    }

    if (!is_array($listAtts)) {
        $listAtts = ['id' => 'taskList', 'name' => 'taskList'];
    }

    // Create the List w/the atrtibutes passed to the function. Use defaults if needed.
    $html = '<select';
    if (isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'];
    } elseif (!isset($listAtts['name']) && isset($listAtts['id'])) {
        $listAtts['name'] = $listAtts['id'];
    } elseif (!isset($listAtts['name']) && !isset($listAtts['id'])) {
        $listAtts['id'] = $listAtts['name'] = 'taskList';
    }
    foreach ($listAtts as $att => $val) {
        // space before is important.
        $html .= ' '. $att .'="'. $val .'"';
    }
    $html .= '>'; // closes opening tag.

    if (!$currentID) {
        $html .= '<option value="">Choose...</option>';
    }

    foreach ($taskList as $tl) {
        if ($tl->listID == $currentID) {
            $selected = ' selected="selected"';
        } else {
            $selected = '';
        }
        $html .= '<option value="'. $tl->listID .'"'. $selected .'>'. $tl->itemName .'</option>';
    }

    $html .= '</select>';

    return $html;

} // end fTaskListEdit();

/**
 * Fetch task status values and return a select list.
 *
 * @param integer $statusID  ID of the currently selected status;
 * @param boolean $isAnalyst Indicates if select list box should have limited options
 *
 * @return string $html  Returns select list html.
 */
function fStatusEdit($statusID, $isAnalyst = false)
{
    $statusForAnalyst = [10, 101, 201]; // ID's from cms_sp.spCaseTasksStatus
    $dbCls = dbClass::getInstance();

    $spID = intval($_SESSION['vendorID']);
    $html = '<select id="statusID" name="statusID">';

    // $sql = "SELECT * FROM ". GLOBAL_SP_DB .".spCaseTasksStatus WHERE spID = '$spID'";
    // this isn't going to be SP specific. commenting out above in case of change.
    $sql = "SELECT * FROM ". GLOBAL_SP_DB .".spCaseTasksStatus";

    $status = $dbCls->fetchObjectRows($sql);
    foreach ($status as $s) {
        $selected = ($statusID == $s->taskStatusID) ? ' selected="selected"':'';
        if ($isAnalyst && !in_array($s->taskStatusID, $statusForAnalyst)) {
            continue;
        }
        $html .= '<option value="'. $s->taskStatusID .'"'. $selected .'>'.
            $s->statusName .'</option>';
    }

    $html .= '</select>';

    return $html;
} // end fStatusEdit();

/**
 * Create html for a new or updated row in the task list on the company tab.
 *
 * @param integer $taskID   ID of the task added or edited;
 * @param object  $dbCls    Instance of the dbCls.
 * @param boolean $addRow   Whether to add <tr> tags around the cells. (default: false);
 * @param string  $userType User type name (such as 'Analyst'
 * @param string  $iUserID  ID of the investigator.
 *
 * @return object $rtn    Returns object with html addition/replacement, as well as the information
 *                        broken out into parts for use in log messages, emails, etc;
 */
function locCreateTaskRow($taskID, $dbCls, $addRow, $userType = '', $iUserID = '')
{
    $taskID = (int)$taskID;
    $spID = (int)$_SESSION['vendorID'];

    $tableTask = GLOBAL_SP_DB . '.spCaseTasks';
    $tableTaskStatus = GLOBAL_SP_DB . '.spCaseTasksStatus';
    $tableTaskNames = GLOBAL_SP_DB . '.spLists';
    $tableUsers = GLOBAL_DB . '.users';
    $sql = "SELECT t.*, ts.statusName, tn.itemName AS taskName, "
        . "u.lastName, u.firstName, u.userEmail "
        . "FROM $tableTask AS t "
        . "LEFT JOIN $tableTaskStatus AS ts ON (t.taskStatusID = ts.taskStatusID) "
        . "LEFT JOIN $tableTaskNames AS tn ON (t.taskListID = tn.listID) "
        . "LEFT JOIN $tableUsers AS u ON (t.userID = u.id) "
        . "WHERE (t.taskID = '$taskID' AND t.spID = '$spID') "
        . "LIMIT 1";

    $task = $dbCls->fetchObjectRow($sql);
    $html = '
    <td>' . $task->taskName . '</td>
    <td>' . $task->statusName . '</td>
    <td>' . $task->lastName . ', ' . $task->firstName . '</td>
    <td class="printBtns">
        <a href="javascript:void(0)" '
        . 'onclick="_opRequest(\'vendor-viewTask\', \'' . $task->taskID . '\');">View</a> | ';

    if (isOSITask($spID, $userType, $iUserID)) {
        $html .= '<a href="javascript:void(0)" '
                 . 'onclick="_opRequest(\'vendor-acceptTask\', \'' . $task->taskID . '\');">Edit</a>';
    } else {
        $html .= '<a href="javascript:void(0)" '
                 . 'onclick="_opRequest(\'vendor-editTask\', \'' . $task->taskID . '\');">Edit</a>';
    }
    $html .= '</td>';

    if ($addRow == true) {
        $html = '<tr id="ctRow'. $task->taskID .'">'. $html .'</tr>';
    }

    $rtn = (object)null;
    $rtn->html = $html;
    $rtn->task = $task->taskName;
    $rtn->status = $task->statusName;
    $rtn->who = $task->firstName .' '. $task->lastName;
    $rtn->email = $task->userEmail;

    return $rtn;

} // end locCreateTaskRow();

/**
 * Create an email notification when a new task is assigned, or an existing task is modified.
 *
 * @param string  $name          The name of investigator being assigned ($trResponse->who).
 * @param string  $email         The email address of the investigator being assigned ($trResponse->email).
 * @param string  $task          The task the investigator is being assigned ($trResponse->task).
 * @param boolean $isEdit        Set true/1 if this is an edit/update notification.
 * @param string  $chng          On edit, this is the taskChange message from the switch.
 * @param integer $steeleTaskChg Contains -1 to signal a change or case ID if there's been an OSI-Writer task change.
 *
 * @return void;
 */
function locTaskNotification($name, $email, $task, $isEdit = false, $chng = '', $steeleTaskChg = 0)
{
    $from = $_SESSION['userName'];
    $leadInv = null;

    // is someone other than the lead investigator messing with this?
    if (!empty($_POST['cinv']) && $_POST['cinv'] != $_SESSION['id']) {
        if ($leadInv = locGetLeadInvestigator($_POST['cinv'])) {
            $from = $leadInv->name;
        }
    }

    if ($steeleTaskChg === -1) {
        if(!$leadInv || ($name == $leadInv->name)) {
            return;
        }
        $name  = $leadInv->name;
        $email = $leadInv->email;
    } elseif ($steeleTaskChg > 0) {
        if ($writerPoolMgr = locGetOsiWriterPoolManager($steeleTaskChg)) {
            $name  = $writerPoolMgr->name;
            $email = $writerPoolMgr->email;
        } else {
            if(!$leadInv || ($name == $leadInv->name)) {
                return;
            }
            $name  = $leadInv->name;
            $email = $leadInv->email;
        }
    }

    $mesg = "To: ". $name .", \n"
        ."From: ". $_SESSION['userName'] ." \n\n";

    // new task created/assigned.
    if (!$isEdit) {
        $subj = 'New Case Task Assignment';
        $mesg .= "Your assistance has been requested with the following investigation.\n"
            ."Please log into Third Party Risk Management - Compliance to review the task, and coordinate efforts\n"
            ."with ". $from ." regarding this assignment.\n\n";
    } else {
        $subj = 'Modification To Task Assignment';
        $mesg .= "--------------------\n"
            ."$chng"
            ."--------------------\n\n"
            ."Please log into Third Party Risk Management - Compliance to review the change, and coordinate \n"
            ."with ". $from ." regarding this matter.\n\n";
    }

    if (filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN) && filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN)) {
        $loginURL = getenv("highbondUrl");
    } else {
        $loginURL = LOGIN_URL;
    }
    $mesg .= "  Requestor: " . $_SESSION['userName'] . "\n"
        ."  Case #: ". $_POST['tr'] ."\n"
        ."  Task Name: ". $task ."\n"
        ."  Link: " . $loginURL ."\n\n\n "

        ."Message automatically generated on behalf of {$_SESSION['userName']} "
        ."<{$_SESSION['userEmail']}>.";

    locSendTaskEmail($email, $subj, $mesg);

} // end locTaskNotification();


/**
 * Send an email about a task to the task assignee.
 *
 * @param string $to   Email address to send the message to.
 * @param string $subj Subject of the email being sent.
 * @param string $body Body of the email being sent.
 * @param string $cc   Email address to CC this email to. (optional)
 *
 * @return void
 */
function locSendTaskEmail($to, $subj, $body, $cc = '')
{
    global $taskMailArray;
    $taskMailArray = ['szRecipient' => $to, 'szSubject'   => $subj, 'szBody'      => $body, 'szCC'        => $cc];

    include_once __DIR__ . '/../includes/php/'.'funcs.php';
    fSendSysEmail(EMAIL_CASE_TASK, 0);

} // end locSendTaskEmail();


/**
 * Get the lead investigator profile
 *
 * @param integer $userID The userID (user.id) of the lead investigator.
 *
 * @return mixed False if !userID, else DB row (object) of the specified user.
 */
function locGetLeadInvestigator($userID)
{
    if (!$userID) {
        return false;
    }

    $dbCls = dbClass::getInstance();

    $userID = (int)$userID;
    $sql = "SELECT CONCAT(firstName, ' ', lastName) AS name, userEmail AS email "
        ."FROM ". GLOBAL_DB .".users "
        ."WHERE id = '$userID' "
        ."LIMIT 1";

    if ($leadInv = $dbCls->fetchObjectRow($sql)) {
        return $leadInv;
    }

    return false;
} // end locGetLeadInvestigator();

/**
 * Get the OSI Writer Pool Manager's task information for the case
 *
 * @param integer $caseID The case.
 *
 * @return mixed False if !task, else DB row (object) of the specified task.
 */
function locGetOsiWriterPoolManager($caseID)
{
    if (!$caseID) {
        return false;
    }

    $dbCls = dbClass::getInstance();

    // Get the Writer Pool Manager's task that's assigned to the case and has a task status
    // of either 'Assigned' or 'Accepted'
    $sql = "SELECT CONCAT(u.firstName, ' ', u.lastName) AS name, u.userEmail AS email \n"
        ."FROM ". GLOBAL_SP_DB .".spCaseTasks AS t \n"
        ."LEFT JOIN ". GLOBAL_DB .".users AS u ON (t.userID = u.id) \n"
        ."WHERE t.caseID = '$caseID' AND t.taskListID = 44 AND (t.taskStatusID = 101 OR t.taskStatusID = 201) ";

    if ($task = $dbCls->fetchObjectRow($sql)) {
        return $task;
    }

    return false;
}
