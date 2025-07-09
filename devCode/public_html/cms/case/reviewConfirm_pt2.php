<?php

/**
 * Convert case to investigation
 */

require_once __DIR__ . '/../includes/php/cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/class_access.php';
$accCls = UserAccess::getInstance();
require_once __DIR__ . '/../includes/php/funcs.php';
require_once __DIR__ . '/../includes/php/funcs_log.php';
require_once __DIR__ . '/../includes/php/funcs_misc.php';
require_once __DIR__ . '/../includes/php/ddq_funcs.php';
require_once __DIR__ . '/../includes/php/class_globalCaseIndex.php';
require_once __DIR__ . '/../includes/php/class_caseCostTimeCalc.php';
require_once __DIR__ . '/sanctionedProhibitedCountries.php';

$clientID = (int)$_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);
$caseID   = 0;
if (!isset($_GET['id']) && isset($_SESSION['currentCaseID'])) {
    $caseID = $_SESSION['currentCaseID'];
} elseif (isset($_GET['id'])) {
    $caseID = intval($_GET['id']);
}
$dbCls->open();


// case stage must be one of these in order to convert to investigation
$cvtStages = array(
    QUALIFICATION,
    REQUESTED_DRAFT,
    UNASSIGNED,
    CLOSED,
    CLOSED_HELD,
    CLOSED_INTERNAL,
    CASE_CANCELED,
    AI_REPORT_GENERATED
);
$caseRow = fGetCaseRow($caseID);
if (!$caseRow || !in_array($caseRow['caseStage'], $cvtStages)) {
    exit;
}
$globaldb = GLOBAL_DB;
$isodb = ISO_DB;
$real_globaldb = REAL_GLOBAL_DB;
$sp_globaldb = GLOBAL_SP_DB;

$languageCode = $_SESSION['languageCode'] ?? 'EN_US';
require_once __DIR__ . '/../includes/php/Models/Globals/Geography.php';
$geo = \Legacy\Models\Globals\Geography::getVersionInstance(null, null, $clientID);


$resultVars = ['configError', 'calcError', 'budgetType', 'budgetAmount', 'extraSubCharge', 'caseDueDate', 'busDayTAT', 'showCalDays', 'showDueDate', 'showExtraSubCost', 'showBaseCost', 'showTotalCost', 'spProductID', 'budgetNegotiation', 'deliveryCost', 'bilingualRptCost'];

$caseType = $scope = (int)$caseRow['caseType'];
if ($_SESSION['userSecLevel'] <= READ_ONLY || $_SESSION['userType'] <= VENDOR_ADMIN) {
    session_write_close();
    header("Location: /cms/case/casehome.sec");
    exit;
}

// Get the Subject Info Row
$subjectInfoDDRow = fGetSubInfoDDRow($caseID);
$SBIonPrincipals  = $subjectInfoDDRow['SBIonPrincipals'];

// case origin, manual or ddq?

// $e_ trusted $caseID,$clientID
$sql = "SELECT caseID FROM ddq WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1";
$hasDDQ = ($dbCls->fetchValue($sql) == $caseID);

$costTimeCountry = $subjectInfoDDRow['country'];

// Get non-blank principal info
$prinInfo  = [];
$principalNames = [];
for ($i = 1; $i <= SUBINFO_PRINCIPAL_LIMIT; $i++) {
    if ($info = trim((string) $subjectInfoDDRow['principal' . $i])) {
        $principalNames[] = $info;
        if ($rel = trim((string) $subjectInfoDDRow['pRelationship' . $i])) {
            $info .= ', ' . $rel;
        }
        $prinInfo[] = $info;
    }
}

// How many subjects are being investigated
$numOfExtraSubs = count($prinInfo);

// Active SPs available to this subscriber
require_once __DIR__ . '/../includes/php/'.'class_all_sp.php';
$SP = new ServiceProvider();
try {
    $allowSPs = $SP->availableServiceProviderIDs($clientID);
} catch (SpException $spEx) {
    // fall back to Steele if no other
    debugTrack(['SP Available Service' => $spEx->getMessage()]);
    $allowSPs = [STEELE_INVESTIGATOR_PROFILE];
}

// Get service provider preference and delivery option
$configError  = false;
$spID = 0;
do {
    // get sp from service provider select tag
    if (isset($_POST['productSpId'])) {
        $tmp = intval($_POST['productSpId']);
        if ($tmp && in_array($tmp, $allowSPs)) {
            $spID = $tmp;
            break;
        }
    }
    // set sp from user input
    if (isset($_POST['setSpId'])) {
        if (!PageAuth::validToken('revconf2Auth', $_POST['revconf2Auth'])) {
            $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
            session_write_close();
            $errUrl = "reviewConfirm_pt2.php?id=$caseID";
            header("Location: $errUrl");
            exit;
        }
        $tmp = intval($_POST['setSpId']);
        if ($tmp && in_array($tmp, $allowSPs)) {
            $spID = $tmp;
            break;
        }
    }
    // use caseAssignedAgent, if present and allowed
    $tmp = intval($caseRow['caseAssignedAgent']);
    if ($tmp && in_array($tmp, $allowSPs)) {
        $spID = $tmp;
        break;
    }
    // use subscriber's preferred SP for this scope in cost/time country
    try {
        $tmp = $SP->preferredSpForScope($allowSPs, $scope, $clientID, $costTimeCountry);
        $spID = $tmp;
    } catch (SpException $spEx) {
        debugTrack(['SP preference not configured' => $spEx->getMessage()]);
        $configError = 'Service Provider preference not configured for scope.';
    }
    break;
} while (1);

// Get all service providers offering product for this scope
if (!$configError) {
    try {
        $spChoices = $SP->allSpForScope($allowSPs, $scope, $clientID, $costTimeCountry);
        $firstSp = 0;
        $spHasProduct = false;
        foreach ($spChoices as $s_id => $s_name) {
            if ($s_id == $spID) {
                $spHasProduct = true;
                break;
            }
            if (!$firstSp) {
                $firstSp = $s_id;
            }
        }
        if (!$spHasProduct) {
            // select first spChoice with product for this scope
            $spID = $firstSp;
        }
    } catch (SpException $spEx) {
        if ($spID && $spID != STEELE_INVESTIGATOR_PROFILE) {
            debugTrack(['SP choices for scope' => $spEx->getMessage()]);
            $configError = 'No service provider products configured for scope.';
        } else {
            $spID = STEELE_INVESTIGATOR_PROFILE;
            $spChoices = [STEELE_INVESTIGATOR_PROFILE => $SP->spName($spID)];
        }
    }
}

$e_spID = $spID; //safe integer

if ($caseType === DUE_DILIGENCE_INTERNAL) {
    $spID = 0;
    $isSpLite = false;
    $spRow = null;
} else {
    // get Service Provider information
    // - set $isSpLite
    // - set $spRow
    // Note: if the call to $SP->isSpLite() fails, $isSpLite is set to true
    //       and $spRow will be empty or null.  This is a bug from the prior code,
    //       and will need to be fixed when refactored.
    try {
        $isSpLite = $SP->isSpLite($spID);
        $spRow = $SP->getSpRow();
    } catch (SpException $spEx) {
        debugTrack(['SP Lite:' => $spEx->getMessage()]);
        $isSpLite = true;
    }
}

// Get delivery option details if enabled for client. Does not apply to SP Lite.
$dlvryData = null;
$deliveryOptionList = [];
$deliveryName = '';
$deliveryOptionID = null;
$deliveryID = null;
if ($spID && !$isSpLite && $allowDeliveryOption = $accCls->ftr->tenantHas(\FEATURE::CASE_DELIVERY_OPTION)) {
    include_once __DIR__ . '/../includes/php/Models/TPM/CaseMgt/DeliveryOption.php';
    $dlvryData = new DeliveryOption();
    if (array_key_exists('setDeliveryOption', $_POST)) {
        // on recalc
        $tmp = trim((string) $_POST['setDeliveryOption']);
        if ($tmp === '') {
            $deliveryOptionID = null;
        } else {
            $deliveryOptionID = (int)$tmp;
        }
    } elseif (array_key_exists('dop-rb-grp', $_POST)) {
        // on Submit
        $deliveryOptionID = (int)$_POST['dop-rb-grp'];
    } else {
        $deliveryOptionID = $dlvryData->optionByDeliveryID($deliveryID);
    }
    $spProduct = 0;
    try {
       if ($productObject = $SP->productForScope($spID, $clientID, $scope, $costTimeCountry)) {
           $spProduct = (int)$productObject->id;
       }
    } catch (SpException $spEx) {
        if ($spEx->getCode() === ServiceProvider::NO_ACTIVE_PRODUCT) {
            debugTrack(['SP product for scope' => $spEx->getMessage()]);
        }
    }
    if ($spProduct) {
        if ($dopData = $dlvryData->deliveryData($spProduct, $deliveryOptionID, $clientID, $deliveryID)) {
            $deliveryID = $dopData['id'];
        } else {
            $deliveryID = null;
        }
        // add options to select
        if ($deliveryOptionList = $dlvryData->optionListByProductID($spProduct, $clientID, true)) {
            if ($deliveryOptionID === null) {
                $deliveryOptionID = 0;
            }
            foreach ($deliveryOptionList AS $opt) {
                if ($opt['id'] === $deliveryOptionID) {
                    $deliveryName = ucfirst((string) $opt['name']);
                }
            }
        } else {
            // No delivery options for this spID/productID
            $dlvryData = null;
            $deliveryOptionList = [];
            $deliveryName = '';
            $deliveryOptionID = null;
            $deliveryID = null;
        }
    }
}

// calculate cost/time
$productRow = null;
if (!$configError) {
    $calc = new CaseCostTimeCalc(
        $spID,
        $clientID,
        $caseType,
        $costTimeCountry,
        $numOfExtraSubs,
        $SBIonPrincipals,
        ['deliveryID' => $deliveryID, 'bilingualRptID' => $caseRow['bilingualRpt']]
    );
    $calcResult = $calc->getCostTime();
    foreach ($resultVars as $k) {
        if (array_key_exists($k, $calcResult)) {
            ${$k} = $calcResult[$k];
        }
    }
    // stop on errors
    if (!$configError) {
        $productRow = $calc->getInternals('productRow');
        $configError = $calcError;
    }
} else {
    $deliveryCost = 0;
    $bilingualRptCost = 0;
}

if ($configError) {
    $pageTitle = "Create New Case Folder";
    shell_head_Popup();
    echo '<h3>CONFIGURATION ERROR</h3>', PHP_EOL;
    echo '<p>', $configError, '</p>', PHP_EOL;
    ?>
    <div class="marg-top1e">
    <table width="491" cellpadding="0" cellspacing="8" class="paddiv">
    <tr>
        <td width="201" nowrap="nowrap" class="submitLinksCancel">
            <a href="javascript:void(0)"
            onclick="return top.onHide()" >Close</a></td>
    </tr>
    </table>
    </div>
    <?php
    noShellFoot();
    exit;
}

$caseAssignedAgent = $spID;
if ($spID == STEELE_INVESTIGATOR_PROFILE) {
    $spc = new SanctionedProhibitedCountries($dbCls);
    if ($spc->isCountrySanctioned($caseRow['caseCountry'])) {
        // Set Caroline Lee as investigator for Sanctioned countries
        $caseInvestigatorUserID
            = $dbCls->fetchValue("SELECT id FROM " . GLOBAL_DB . ".users WHERE userid = 'clee@wwsteele.com'");
    } else {
        $caseInvestigatorUserID = STEELE_VENDOR_ADMIN;
    }
} else {
    $caseInvestigatorUserID = '';
}

// Redirect user when:
// - ($_POST['Submit'] == 'Submit to Investigator')
function submitToInvestigatorAndExit(): never
{
    echo '<script type="text/javascript">' . PHP_EOL
        . 'top.window.location="casehome.sec?tname=caselist";' . PHP_EOL
        . '</script>' . PHP_EOL;
    exit;
}

if (isset($_POST['Submit'])) {
    if (!PageAuth::validToken('revconf2Auth', $_POST['revconf2Auth'])) {
        $_SESSION['aszErrorList'][0] = PageAuth::getFailMessage($_SESSION['languageCode']);
        session_write_close();
        $errUrl = "reviewConfirm_pt2.php?id=$caseID";
        header("Location: $errUrl");
        exit;
    }

    $logConvertCase = false;

    $prevVals = $dbCls->fetchArrayRow("SELECT c.budgetType, c.budgetAmount, "
        . "c.caseDueDate, s.name AS stageName, i.investigatorName AS spName, c.spProduct "
        . "FROM cases AS c "
        . "LEFT JOIN caseStage AS s ON s.id = c.caseStage "
        . "LEFT JOIN $sp_globaldb.investigatorProfile AS i ON i.id = c.caseAssignedAgent "
        . "WHERE c.id='$caseID' LIMIT 1", MYSQLI_ASSOC
    );

    switch ($_POST['Submit']) {
    case 'Close':
        // Redirect user to
        echo '<script type="text/javascript">' . PHP_EOL
            . 'top.window.location="casehome.sec?tname=casefolder";' . PHP_EOL
            . '</script>' . PHP_EOL;
        exit;
        //break;

    case 'Go back and edit information':
        if ($accCls->allow('updtCase')) {
            session_write_close();
            if ($hasDDQ) {
                header("Location: reviewConfirm.php?id=$caseID");
            } else {
                header("Location: editsubinfodd_pt2.php?id=$caseID");
            }
            exit;
        } else {
            session_write_close();
            header("Location: casehome.sec?tname=caselist");
            exit;
        }
        break; // never reached

    case 'Submit to Investigator';
        if ($accCls->allow('convertCase')) {
            $logConvertCase = true;
            $passID = $caseID;
            $nextPage = "casehome.sec";
        }
        break;
    }

    // Clear error flag
    $iError = 0;

    // Check accept terms
    if (!array_key_exists('cb_acceptTerms', $_POST) || !$_POST['cb_acceptTerms']) {
        $_SESSION['aszErrorList'][$iError++] = "You must Accept the terms under "
            . "section AUTHORIZATION.";
    }
    if ($_SESSION['b3pAccess'] && $caseRow['tpID'] == 0) {
        $_SESSION['aszErrorList'][$iError++] = "Case has not been associated "
            . "with a thrid party profile.";
    }

    // any errors?
    if ($iError) {
        $errorUrl = "reviewConfirm_pt2.php?id=$caseID";
        session_write_close();
        header("Location: $errorUrl");
        exit;
    }


    // If a budget Amount has already been determined, we can skip to the budget approved stage based on
    // the budgetNegotiation flag from getCostTime being false.
    if (empty($budgetNegotiation)) {
        // Set Stage
        $caseStage = BUDGET_APPROVED;
        $sysEmail =  EMAIL_BUDGET_APPROVED;

        // Check for Client Emails triggered by the caseStage moving in to Budget Approved
        fSendClientEmail($clientID, 'stageChangeBudgetApproved', $_SESSION['languageCode'], 0);
    } else {
        // We have to start the budget process
        // Set Stage
        $caseStage = ASSIGNED;
        $sysEmail =  EMAIL_ASSIGNED_NOTICE;
    }

    $e_caseStage = $caseStage; //app assigned above

    $newStageName = $dbCls->fetchValue("SELECT name FROM caseStage "
        . "WHERE id = $e_caseStage LIMIT 1"
    );

    // only applies to full SP.
    if (!$isSpLite) {
        // check for auto-assignment of an investigator.
        $autoAssignment = false;
        if (!empty($spProductID) && !empty($costTimeCountry)) {
            $aaID = $SP->caseAutoAssign($caseInvestigatorUserID, $spID, $spProductID, $costTimeCountry);
            if ($aaID != $caseInvestigatorUserID) {
                // 6/26/14: with moving to database templates for SP emails, bringing
                // back the auto_assign notice capability. Currently it is the same as
                // the assigned notice, but since it has its own template we'll add it in.
                // we had an auto-assignment. update the sysEmail type.
                if ($sysEmail == EMAIL_ASSIGNED_NOTICE) {
                    $sysEmail =  EMAIL_AUTO_ASSIGNED_NOTICE;
                }
            }
            $caseInvestigatorUserID = $aaID;
        }

        // case investigator user id does not exist in the Authoritative Users table.
        $caseInvestigatorUserIDFound = true;

        // verify that $caseInvestigatorUserID exist in the Authoritative(GLOBAL_DB) Users table.
        if (fGetUserRow($caseInvestigatorUserID) === false) {

            // As of 2016-03-28, the list of folks to be notified if the $caseInvestigatorUserID does NOT exists in the
            // Authoritative(GLOBAL_DB) Users table.
            $alertTo = $_ENV['devopsEmailAddress'];

            $alertEnv = SECURIMATE_ENV;
            $userID = $_SESSION['id'];
            $clientID = $_SESSION['clientID'];
            $errorLineNumber = __LINE__;
            $errorFileName = __FILE__;

            $subj = "FAILURE:  Attempted to send notification when adding a new case from the dashboard.";
            $msg =  "Environment: $alertEnv\n"
                . "userID: $userID\n"
                . "clientID: $clientID\n\n"
                . "The case investigator user ID, {$caseInvestigatorUserID}, does not exist in the Authoritative Users table.\n\n"
                . "This occurred at or near line {$errorLineNumber} in file {$errorFileName}.\n";
            AppMailer::mail(
                0,
                $alertTo,
                $subj,
                $msg,
                ['addHistory' => false, 'forceSystemEmail' => true]
            );

            $caseInvestigatorUserIDFound = false;
        }
    }

    // $_  trusted $clientID, $caseID
    $e_budgetType = $dbCls->esc($budgetType); //db varchar
    $e_budgetAmount = $dbCls->esc($budgetAmount); //db varchar
    $e_spProductID = intval($spProductID); //db int
    $e_caseInvestigatorUserID = intval($caseInvestigatorUserID); //db int
    $e_caseDueDate = $dbCls->esc($caseDueDate);
    $e_busDayTAT = $dbCls->esc($busDayTAT);
    $e_delivery = is_numeric($deliveryOptionID) ? (int)$deliveryID : 'NULL';

    // Update the case record
    try {
        $sql = "UPDATE cases SET "
            . "budgetType = '$e_budgetType', "
            . "budgetAmount = '$e_budgetAmount', "
            . "caseDueDate = '$e_caseDueDate', "
            . "numOfBusDays = '$e_busDayTAT', "
            . "caseStage = '$e_caseStage', "
            . "spProduct = '$e_spProductID', "
            . "caseInvestigatorUserID = '$e_caseInvestigatorUserID', "
            . "caseAssignedDate = NOW(), "
            . "caseAssignedAgent = '$e_spID', "
            . "internalDueDate = '$e_caseDueDate', "
            . "delivery = $e_delivery " // no quotes!
            . "WHERE id = '$caseID' AND clientID = '$clientID' LIMIT 1";

        // log the event
        if ($dbCls->query($sql) && $dbCls->affected_rows()) {
            $globalIdx->syncByCaseData($caseID);

            $newVals = $dbCls->fetchArrayRow("SELECT c.budgetType, c.budgetAmount, "
                . "c.caseDueDate, s.name AS stageName, i.investigatorName AS spName, c.spProduct "
                . "FROM cases AS c "
                . "LEFT JOIN caseStage AS s ON s.id = c.caseStage "
                . "LEFT JOIN $sp_globaldb.investigatorProfile AS i ON i.id = c.caseAssignedAgent "
                . "WHERE c.id='$caseID' LIMIT 1", MYSQLI_ASSOC
            );
            $chg = [];
            foreach ($prevVals as $k => $v) {
                if ($v != $newVals[$k]) {
                    $chg[] = "$k: `$v` => `" . $newVals[$k] . '`';
                }
            }
            $logMsg = join(', ', $chg);
            logUserEvent(24, $logMsg, $caseID);
            if ($ddqID = $dbCls->fetchValue("SELECT id FROM ddq "
                . "WHERE caseID = '$caseID' AND clientID = '$clientID' LIMIT 1"
            )) {
                fixDdqStatus($ddqID, $clientID);
            }

            //SEC-3029: Update Workflow Status of DDQ to "Report Requested" for Teva ONLY
            if ($clientID == 9165 && $prevVals['stageName'] == 'Qualification') {
                require_once __DIR__ . '/../includes/php/'.'class_customdata.php';
                $cdCls = new customDataClass(0, $caseID);
                $jsObj = $cdCls->saveData(['op' => 'cscdsave', 'cscd-id415' => 2106]
                );
            }

            if ($caseStage == BUDGET_APPROVED && $_SESSION['b3pAccess'] && $caseRow['tpID'] != 0) {
                //now the case has been assigned to an investigator, we can pull 3ppersons
                $ddqKeyCount = 0;
                $subjectInfoDDKeyCount = 0;
                $tp_persons_inserted = false;

                //is there a ddq?
                if (!$ddqID) {
                    $siID = $subjectInfoDDRow['id'];
                    $prinFields = 'principal<num> AS principal, '
                    . 'pRelationship<num> AS relationship, '
                    . 'p<num>email AS email, '
                    . 'p<num>phone AS phone, '
                    . 'p<num>OwnPercent AS ownerpercent, '
                    . 'bp<num>Owner AS owner, '
                    . 'bp<num>KeyMgr AS keymgr, '
                    . 'bp<num>BoardMem AS boardmem, '
                    . 'bp<num>KeyConsult AS keyconsult, '
                    . "(LENGTH(principal<num>) != CHAR_LENGTH(principal<num>)) AS altScript";
                    // not needed now
                    // . "((LENGTH(principal<num>) - LENGTH(REPLACE(principal<num>, ' ', ''))) = 1)
                    // AS canSplit";
                    //go through the subjectInfoDD row
                    if ($siID) {
                        for ($i = 1; $i <= SUBINFO_PRINCIPAL_LIMIT; $i++) {
                            $flds = str_replace('<num>', $i, $prinFields);
                            $e_flds = $flds; //string defined above
                            $e_siID = $siID; //id defined above from array
                            $sql = "SELECT $e_flds FROM subjectInfoDD WHERE id = '$e_siID' LIMIT 1";
                            $e_q_name = $q_name = '';
                            $e_q_email = $q_email = $e_q_phone = $q_phone = '';
                            $e_q_pos = $q_pos = '';
                            $e_percent = $percent = 0;

                            $prin = $dbCls->fetchObjectRow($sql);
                            if ($prin->principal) {
                                $e_q_name = $q_name = $dbCls->escape_string($prin->principal);
                            }
                            if ($prin->email) {
                                $e_q_email = $q_email = $dbCls->escape_string($prin->email);
                            }
                            if ($prin->phone) {
                                $e_q_phone = $q_phone = $dbCls->escape_string($prin->phone);
                            }
                            if ($prin->owner) {
                                $e_percent = $percent = intval($prin->ownerpercent);
                            }

                            if ($prin->principal) {
                                // process principal

                                $fld = ($prin->altScript) ? 'altScript': 'fullName';

                                $sql = "SELECT id FROM tpPerson WHERE $fld = '$e_q_name' "
                                    . "AND clientID = '$clientID' LIMIT 1";
                                if (!($personID = $dbCls->fetchValue($sql))) {
                                    // insert new record
                                    $tmp = [];
                                    $tmp[] = "fullName = '$e_q_name'";
                                    if ($prin->altScript) {
                                        $tmp[] = "altScript = '$e_q_name'";
                                    }
                                    $full = $first = $last = '';
                                    $isCompany = false;
                                    $parsedName = guessNameParts(
                                        $prin->principal, $first, $last, $full, $isCompany
                                    );
                                    // HB on 2014-04-22:
                                    // Trigger has served its purpose, disabled until further notice.
                                    /*if (!($parsedName = guessNameParts(
                                        $prin->principal, $first, $last, $full, $isCompany))
                                        && (trim($full) != "")
                                    ) {
                                        nameParseException($clientID,__FILE__,__LINE__,$prin->principal);
                                    }*/
                                    // The following are derived from the unescaped value and are assigned
                                    // by reference inside guessNameParts.
                                    $e_first = $dbCls->esc($first);
                                    $e_last = $dbCls->esc($last);
                                    $e_full = $dbCls->esc($full);

                                    if ($first && $last) {
                                        $tmp[] = "firstName = '$e_first'";
                                        $tmp[] = "lastName = '$e_last'";
                                    } elseif ($full) {
                                        $tmp[] = "firstName = NULL";
                                        $tmp[] = "lastName = NULL";
                                        if ($isCompany) {
                                            $tmp[] = "rectype = 'Entity'";
                                        }
                                    }
                                    if ($prin->email) {
                                        $tmp[] = "email = '$e_q_email'";
                                    }
                                    if ($prin->phone) {
                                        $tmp[] = "phone = '$e_q_phone'";
                                    }
                                    $sql = "INSERT INTO tpPerson SET "
                                        . "clientID = '$clientID', "
                                        . "status = 'active', "
                                        . join(', ', $tmp);
                                    if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                                        $personID = $dbCls->insert_id();
                                        $tp_persons_inserted = true;
                                    }
                                }
                                // Associate with 3P
                                if ($personID) {
                                    // $e_ safe $personID, query id OR insert id
                                    //safe, but converted to local var, db int
                                    $e_tpID = $caseRow['tpID'];
                                    $sql = "SELECT id FROM tpPersonMap WHERE personID = '$personID' "
                                        . "AND clientID = '$clientID' AND tpID = '$e_tpID' LIMIT 1";
                                    if (!$dbCls->fetchValue($sql)) {
                                        // make the association
                                        $tmp = [];
                                        if ($prin->relationship) {
                                            $e_q_pos = $q_pos = $dbCls->esc($prin->relationship);
                                            $tmp[] = "position = '$e_q_pos'";
                                        }
                                        if ($prin->owner) {
                                            $tmp[] = "bOwner = 1";
                                            $tmp[] = "ownerPercent = '$e_percent'";
                                        }
                                        if ($q_email) {
                                            $tmp[] = "companyEmail = '$e_q_email'";
                                        }
                                        if ($q_phone) {
                                            $tmp[] = "companyPhone = '$e_q_phone'";
                                        }

                                        // $e_ safe $personID, query id OR insert id
                                        // $e_tpID safe, but converted to local var, db int
                                        $sql = "INSERT INTO tpPersonMap SET "
                                            . "tpID = '$e_tpID', "
                                            . "clientID= '$clientID', "
                                            . "personID = '$personID', "
                                            . "bIncludeInGDC = 1, "
                                            . "bKeyMgr = '$prin->keymgr', "
                                            . "bBoardMem = '$prin->boardmem', "
                                            . "bKeyConsult = '$prin->keyconsult'";
                                        if ($tmp) {
                                            $sql .= ', ' . join(', ', $tmp);
                                        }
                                        if ($dbCls->query($sql) && $dbCls->affected_rows()) {
                                            $tp_persons_inserted = true;
                                        }
                                    }

                                }
                            }
                        }
                    }

                    // If client has 3P Monitor or 3P Monitor + 1 Free enabled, auto-run a GDC
                    if ($tp_persons_inserted
                        && ($_SESSION['cflagPaid3pMonitor'] || $_SESSION['cflagBase3pMonitor'])
                    ) {
                        include_once __DIR__ . '/../includes/php/'.'class_3pmonitor.php';
                        $gdc_monitor = new GdcMonitor($clientID, $_SESSION['id']);
                        try {
                            $gdc_monitor->run3pGdc($e_tpID);
                        } catch (GdcException) {
                            // There was an error here ... ex->get_message to find out what
                        }
                    }
                }
            } //end extract 3ppersons

        }
        unset($_SESSION['prinHold']);
    }catch (Exception $ex) {
        debugTrack(['Failed to update case' => $ex->getMessage()]);
    }

    // Destination after review and confirm
    if (!$isSpLite) {
        if ($caseInvestigatorUserIDFound) {
            fSendSysEmail($sysEmail, $caseID);
        }
    } else {
        include_once __DIR__ . '/../includes/php/'.'class_lite_sp.php';
        $token   = Crypt64::randString(8);
        $spLite  = new LiteSp();
        $country = $costTimeCountry;
        $sentTo  = $spRow->investigatorEmail;
        $subjectName = $subjectInfoDDRow['name'];

        // $e_ $globaldb trusted
        $e_country = $dbCls->esc($country); //app defined above

        $countryName = $geo->getCountryNameTranslated($country, $languageCode);
        if (!$countryName) {
            $countryName = '(' . $accCls->trans->codeKey('country') . ' not available)';
        }
        $countryName = html_entity_decode($countryName);
        $cost = number_format(floatval($budgetAmount), 2, '.', '');
        $productName = $productRow->name;
        if ($productRow->abbrev) {
            $productName .= " ($productRow->abbrev)";
        }
        $q_subjectName  = $dbCls->escape_string($subjectName);
        $q_sentTo       = $dbCls->escape_string($sentTo);
        $region         = $caseRow['region'];
        $addlCompanies  = 0;
        $addlPrincipals = count($principalNames);

        //$e_ trusted $real_globaldb, $caseID, $clientID
        $e_q_subjectName = $q_subjectName;   //escaped above
        $e_addlCompanies = $addlCompanies;   //app defined above
        $e_addlPrincipals = $addlPrincipals; //count used
        $e_token = $token;                   //app defined above
        $e_spID = $spID;                     //app defined above
        $e_scope = $scope;                   //app defined above
        $e_productRow = $productRow->id;     //safe, from $SP->productForScope
        $e_q_sentTo = $q_sentTo;             //escaped above
        $e_region = $region;                 //safe, from fGetCaseRow()
        $e_cost = $cost;                     //number_format above

        $sql = "INSERT INTO $real_globaldb.g_spLiteAuth SET "
             . "subjectName    = '$e_q_subjectName', "
             . "addlCompanies  = '$e_addlCompanies', "
             . "addlPrincipals = '$e_addlPrincipals', "
             . "token    = '$e_token', "
             . "spID     = '$e_spID', "
             . "caseID   = '$caseID', "
             . "clientID = '$clientID', "
             . "scope    = '$e_scope', "
             . "spProduct= '$e_productRow', "
             . "sentTo   = '$e_q_sentTo', "
             . "country  = '$e_country', "
             . "region   = '$e_region', "
             . "cost     = '$e_cost', "
             . "caseKey  = SHA1(CONCAT(token, spID, caseID, scope, addlCompanies, "
             . "addlPrincipals, country, region, cost, clientID, CURRENT_TIMESTAMP()))";
        if (!$dbCls->query($sql) || !$dbCls->affected_rows()) {
            debugTrack(['Failed to insert g_spLiteAuth record' => $sql]);
        }
        $recID = $dbCls->insert_id(); //safe
        $caseKey = $dbCls->fetchValue("SELECT caseKey FROM $real_globaldb.g_spLiteAuth "
            . "WHERE id = '$recID' LIMIT 1"
        );

        // send email
        //$budget = '$' . number_format(floatval($cost), 0);
        $basePath = substr((string) $sitepath, 0, -4);
        $acceptLink    = $basePath . 'spl/case?ky=' . $caseKey . '&a=1';
        $rejectLink    = $basePath . 'spl/case?ky=' . $caseKey . '&r=1';
        $h_acceptLink  = $basePath . 'spl/case?ky=' . $caseKey . '&amp;a=1';
        $h_rejectLink  = $basePath . 'spl/case?ky=' . $caseKey . '&amp;r=1';
        $accessLink    = $basePath . 'spl/case?ky=' . $caseKey;
        $requester     = $caseRow['requestor'];
        $userRow = $dbCls->fetchObjectRow("SELECT userName, userEmail, userPhone "
            . "FROM $globaldb.users WHERE userid = '"
            . $dbCls->escape_string($requester) . "' LIMIT 1"
        );
        if ($userRow->userEmail || $userRow->userPhone) {
            $requesterName = $userRow->userName;
            $txt_requesterName = "\n    $userRow->userName";
            if ($userRow->userEmail) {
                $requesterName .= "<br>\n$userRow->userEmail";
                $txt_requesterName .= "\n    $userRow->userEmail";
            }
            if ($userRow->userPhone) {
                $requesterName .= "<br>\n$userRow->userPhone";
                $txt_requesterName .= "\n    $userRow->userPhone";
            }
        } elseif ($userRow->userName) {
            $requesterName = $userRow->userName;
            $txt_requesterName = $userRow->userName;
        } else {
            $requesterName = $requester;
            $txt_requesterName = $requester;
        }
        $clientName  = html_entity_decode((string) $_SESSION['clientName'], ENT_QUOTES, 'UTF-8');
        $stateName   = '';
        if (trim((string) $caseRow['caseState'])) {
            $stateName = trim(html_entity_decode((string) $caseRow['caseState'], ENT_QUOTES, 'UTF-8'))
                . ', ';
        }
        $location    = $stateName . $countryName;
        $principals  = '(none)';
        if ($principalNames) {
            $principals = html_entity_decode(join(', ', $principalNames), ENT_QUOTES, 'UTF-8');
        }
        if ($budgetAmount) {
            $amt = $cost;
        } else {
            if ($productRow->costMethod == 'Unpublished') {
                $amt = 'Unpublished';
            } else {
                $amt = $cost;
            }
        }

        $subject = 'New Case Request: ' . html_entity_decode((string) $subjectName, ENT_QUOTES, 'UTF-8');
        $textMsg = <<<EOT
$clientName is requesting you to complete a new investigation.

Quick Links

Accept Case: $acceptLink
Reject: $rejectLink

Requester: $txt_requesterName

Company: $subjectName
Principal(s): $principals
Location: $location
Budget (USD): $cost
Due Date: $showDueDate
Scope: $productName

Save this email. If you Accept this case, you will be able to access
full details about the subject(s) of this investigation at the
following URL:

$accessLink

EOT;

        include_once __DIR__ . '/../includes/php/'.'class_AppMailer.php';
        try {
            $mailer = new AppMailer($clientID);
            $mailer->Subject = $subject;
            $mailer->AltBody = $textMsg;

            $spacer = $mailer->spacer;
            $ff = 'font-family: Source Sans Pro,Arial,san-serif';
            $simLogo = '/assets/image/Diligent_Logo_2021_RGB.svg';
            $ml3  = 'margin-left:3em';
            $mt07 = 'margin-top:0.7em';
            $mb07 = 'margin-bottom:0.7em';

            $htmlMsg = <<<EOT
<table cellpadding="0" cellspacing="0" border="0"><tr><td>
<h3 style="$ff">$clientName is requesting you to complete a new investigation.</h3>

<p style="$ff;$mb07"><b>Quick Links</b></p>

<p style="$ff;$mb07"><a href="$h_acceptLink"><b>Accept Case</b></a></p>

<p style="$ff;$mb07"><a href="$h_rejectLink"><b>Reject</b></a></p>

<table cellpadding="1" cellspacing="0" border="0">

<tr><td colspan="2"><img src="$spacer" width="30" height="20" alt="" title=""></td></tr>
<tr>
    <td valign="top" style="$ff;width:130px;font-weight:bold">Requester</td>
    <td style="$ff" valign="top">$requesterName</td>
</tr>
<tr><td colspan="2"><img src="$spacer" width="30" height="20" alt="" title=""></td></tr>

<tr>
    <td valign="top" style="$ff;font-weight:bold" colspan="2">Details:</td>
</tr>
<tr>
    <td style="$ff;width:130px;font-weight:bold">Company</td><td style="$ff">$subjectName</td>
</tr>
<tr>
    <td valign="top" style="$ff;width:130px;font-weight:bold">Principal(s)</td>
    <td style="$ff" align="top">$principals</td>
</tr>
<tr>
    <td style="$ff;width:130px;font-weight:bold">Location</td><td style="$ff">$location</td>
</tr>
<tr>
    <td style="$ff;width:130px;font-weight:bold">Budget (USD)</td><td style="$ff">$amt</td>
</tr>
<tr>
    <td style="$ff;width:130px;font-weight:bold">Due Date</td><td style="$ff">$caseDueDate</td>
</tr>
<tr>
    <td style="$ff;width:130px;font-weight:bold">Scope</td><td style="$ff">$productName</td>
</tr>
</table>

<p style="$ff;$mt07;$mb07">Save this email. If you <i>Accept</i> this case, you will be able
to access full details about the subject(s) of this investigation
<a href="$accessLink">here</a>.</p>

<p><img src="$simLogo" alt="Powered by Third Party Risk Management - Compliance" title="Powered by Third Party Risk Management - Compliance" border="0"></p>
</td></tr></table>

EOT;

            $mailer->htmlBody = $htmlMsg;
            $mailer->addAddress($sentTo);
            if (!$mailer->sendMessage($clientID)) {
                debugTrack(['Mailer error (reviewConfirm_pt2)' => $mailer->ErrorInfo]);
            }
        } catch (Exception $e) {
            debugTrack(['Mailer Error (reviewConfirm_pt2)' => $e->getMessage()]);
        }
    }

    // deduct budget amount from the Purchase Order
    if ($caseRow['budgetAmount']) {
        $e_cost = number_format(floatval($caseRow['budgetAmount']), 2, '.', '');
        $dbCls->query("UPDATE purchaseOrder SET currentAmt=(currentAmt - '$e_cost') "
            . "WHERE clientID='$clientID' LIMIT 1"
        );
    }

    // Redirect user to
    if ($_POST['Submit'] == 'Submit to Investigator') {
        require_once 'sanctionedProhibitedCountries.php';
        $sanc = new SanctionedProhibitedCountries($dbCls);
        if ($sanc->isCountrySanctioned($caseRow['caseCountry'])) {
            $sanc->sendSanctionedCaseNotification($caseID);
        }
        submitToInvestigatorAndExit();
    } else {
        session_write_close();
        header("Location: $nextPage?id=$passID");
    }
    exit;

}

// -------- Show Form

// If Case Type and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseTypeList'])) {
    $_SESSION['caseTypeList'] = fLoadCaseTypeList();
}

// make normal vars
//$tmp = array('caseName', 'caseType', 'region', 'caseAssignedAgent', 'userCaseNum', 'tpID');
$tmp = ['caseName', 'region', 'userCaseNum', 'tpID', 'billingUnit', 'billingUnitPO'];
foreach ($tmp as $key) {
    ${$key} = $caseRow[$key];
}
$regionName = fGetRegionName($caseRow['region']);

// Get Investigation firm data
if ($caseType === DUE_DILIGENCE_INTERNAL) {
    $investigatorName = '(internal)';
} else {
    $investigatorName = $spRow->investigatorName;
}

$subType = '';
if (isset($subjectInfoDDRow['subType'])) {
    If ($relRow = fGetRelationshipTypeRow($subjectInfoDDRow['subType'])) {
      $subType = $relRow['name'];
    }
}

$tmp = ['subStat', 'name', 'street', 'city', 'country', 'state', 'postCode', 'pointOfContact', 'phone', 'emailAddr', 'POCposition', 'bAwareInvestigation'];
foreach ($tmp as $key) {
    ${$key} = $subjectInfoDDRow[$key];
}

$stateName = getStateName($state);
$stateName = (!empty($stateName)) ? $stateName : 'None';

$countryRow = fGetCountryRow($country);
$countryName = $countryRow['name'];

$insertInHead =<<< EOT
<style type="text/css">
    div.dop-bracket {
        border-left: 1px solid #aaaaaa;
        padding-left: 10px;
    }
</style>

EOT;

$pageTitle = "Create New Case Folder";
shell_head_Popup();
$revconf2Auth = PageAuth::genToken('revconf2Auth');
?>

<form name="recalcCostForm" id="recalcCostForm" style="margin:0;padding:0" method="post"
    action="<?php echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>">
<input type="hidden" name="setSpId" id="setSpId" value="" />
<input type="hidden" name="setDeliveryOption" id="setDeliveryOption" value="" />
<input type="hidden" name="revconf2Auth" value="<?php echo $revconf2Auth; ?>" />
</form>

<!--Start Center Content-->
<script type="text/javascript">
function recalcCaseConversion(rb) {
    var spSel = document.querySelector('#productSpId'),
        defaultSp = <?php echo $spID; ?>,
        spVal = (spSel ? spSel[spSel.selectedIndex].value : defaultSp),
        optVal = rb.value,
        frm = document.recalcCostForm;
    frm.setSpId.value = spVal;
    frm.setDeliveryOption.value = optVal;
    frm.submit();
    return false;
}
function submittheform (selectedtype)
{
    document.vc2form.Submit.value = selectedtype;
    document.vc2form.submit() ;
    return false;
}
</script>


<h6 class="formTitleBlueHeader">&nbsp;&nbsp;INVESTIGATION ORDER </h6>

<form action="<?php echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>"
    method="post" name="vc2form" class="cmsform" id="vc2form" >
<input type="hidden" name="Submit" />
<input type="hidden" name="revconf2Auth" value="<?php echo $revconf2Auth; ?>" />
<div class="stepFormHolder">
<table width="876" border="0" cellpadding="0" cellspacing="0" class="paddiv">
<tr>
    <td width="155">Scope of Due Diligence:</td>
    <td width="286"><?php echo $_SESSION['caseTypeClientList'][$caseType]; ?></td>
    <td width="435"  rowspan="9" valign="top">
        <div style="margin-left:2em"><table width="100%" border="0"
        cellspacing="0" cellpadding="0">
        <tr>
            <td width="31%">Point of Contact: </td>
            <td width="69%" class="no-trsl"><?php echo $pointOfContact; ?></td>
        </tr>
        <tr>
            <td>Position: </td>
            <td class="no-trsl"><?php echo $POCposition; ?></td>
        </tr>
        <tr>
            <td>Telephone: </td>
            <td class="no-trsl"><?php echo $phone; ?></td>
        </tr>
        <tr>
            <td valign="top">&nbsp;</td>
            <td>&nbsp; <br /></td>
        </tr>
        </table>
<?php
if (count($prinInfo)) {
    ?>
    <table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td class="no-wrap"><span class="blackFormTitle">Principals you
            want to investigate:</span></td>
    </tr>
    <tr>
        <td><select size="4" style="height:auto;" data-tl-ignore="1">
    <?php
    foreach ($prinInfo as $info) {
        echo '<option>', $info, '</option>', PHP_EOL;
    }
    ?>
        </select></td>
    </tr>
    <tr>
        <td>&nbsp;</td>
    </tr>
    </table>
    <?php
} ?>

    <table width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr>
        <td width="43%">Investigation Firm:</td>
        <td width="1%"></td>
        <td width="56%">
<?php
if (count($spChoices) == 1) {
    echo $spID && $spRow ? $spRow->investigatorName : $investigatorName;
} else {
    echo '<select name="productSpId" id="productSpId" onchange="recalcCaseConversion(this)" data-no-trsl-cl-labels-sel="1">',
        PHP_EOL;
    foreach ($spChoices as $s_id => $s_name) {
        $s = ($s_id == $spID) ? ' selected="selected"': '';
        echo '<option value="', $s_id, '"', $s, '>', $s_name, '</option>', PHP_EOL;
    }
    echo '</select>', PHP_EOL;
} ?>
        </td>
    </tr>
    <tr id="rc-tat">
        <td>Est. Completion Date (<span class="days"><?php echo $showCalDays; ?></span> days):</td>
        <td></td>
        <td class="date"><?php echo $showDueDate; ?></td>
    </tr>
    <tr>
        <td>Standard Cost:</td>
        <td></td>
        <td ><?php echo $showBaseCost; ?></td>
    </tr>
<?php
if ($extraSubCharge) {
    ?>
    <tr>
        <td nowrap="nowrap">Additional Subjects
              <?php echo $numOfExtraSubs; ?>: </td>
        <td></td>
        <td><?php echo $showExtraSubCost; ?></td>
    </tr>
    <?php
}
if ($deliveryOptionID > 0) {
    ?>
    <tr id="rc-dlvry">
        <td><?php echo $deliveryName; ?> Delivery:</td>
        <td></td>
        <td>$ <?php echo (int)$deliveryCost; ?></td>
    </tr>
    <?php
} elseif ($bilingualRptCost) { ?>
    <tr id="rc-dlvry">
        <td>Bilingual Report:</td>
        <td></td>
        <td>$ <?php echo (int)$bilingualRptCost; ?></td>
    </tr>
<?php } ?>
    <tr>
        <td>Total Cost:</td>
        <td></td>
        <td><span class="style10" id="rc-ttl-cost">
    <?php echo $showTotalCost; ?></span></td>
    </tr>
<?php if ($deliveryOptionList) {
    ?>
    <tr>
        <td>Delivery Option:</td>
        <td></td>
        <td><div class="dop-bracket">
            <?php
            foreach ($deliveryOptionList as $opt) {
                $ck = $opt['id'] === $deliveryOptionID ? ' checked="checked"' : '';
                $dopId = 'dop-ck-' . $opt['id'];
                echo "<div><input type=\"radio\" id=\"{$dopId}\" name=\"dop-rb-grp\" "
                    . "value=\"{$opt['id']}\"{$ck} onclick=\"return recalcCaseConversion(this)\" /> "
                    . "<label for=\"{$dopId}\">{$opt['name']}</label></div>\n";
            }
            ?>
        </div></td>
    </tr>
    <?php
} ?>
    </table></div>

    </td>
</tr>
 <tr>
    <td colspan="2">
    <table cellpadding="0" cellspacing="0">
    <tr>
        <td width="155">Case #:</td>
        <td class="no-trsl"><?php echo $userCaseNum; ?></td>
    </tr>
    <tr>
        <td>Case Name:</td>
        <td class="no-trsl"><?php echo $caseName; ?></td>
    </tr>
    <tr>
        <td>Relationship Status:</td>
        <td class="no-trsl"><?php echo $subStat; ?></td>
    </tr>
    <tr>
        <td>Relationship Type:</td>
        <td class="no-trsl"><?php echo $subType; ?></td>
    </tr>
    <tr>
        <td class="no-trsl-cl-labels"><?php echo $_SESSION['regionTitle']; ?>:</td>
        <td class="no-trsl"><?php echo $regionName; ?></td>
    </tr>

<?php
require_once __DIR__ . '/../includes/php/'.'class_BillingUnit.php';
$buCls = new BillingUnit($clientID);
echo $buCls->namesInTR($buCls->getNames($billingUnit, $billingUnitPO));
?>
    </table>
    </td>
</tr>
<tr>
    <td colspan="2"><div class="marg-top1e">SUBJECT OF INVESTIGATION</div></td>
</tr>
<tr>
    <td>Company:</td>
    <td class="no-trsl"><?php echo $name; ?></td>
</tr>
<tr>
    <td>Street:</td>
    <td class="no-trsl"><?php echo $street; ?></td>
</tr>
<tr>
    <td>City:</td>
    <td class="no-trsl"><?php echo $city; ?></td>
</tr>
<tr>
    <td>State/Province:</td>
    <td><?php echo $stateName; ?></td>
</tr>
<tr>
    <td><?php echo $accCls->trans->codeKey('country'); ?>:</td>
    <td><?php echo $countryName; ?></td>
</tr>
<tr>
    <td>Postcode: </td>
    <td class="no-trsl"><?php echo $postCode; ?></td>
</tr>
</table>
</div>

<div class="marg-top2e">
<table width="831" border="0" cellspacing="0" cellpadding="0" class="paddiv">
<tr>
    <td>AUTHORIZATION</td>
</tr>
<tr>
    <td>
        <ul style="margin:0">
        <li>I am authorized to request this investigation.</li>
        <li>I give permission to the investigation provider to conduct
            the type of investigation indicated above.</li>
        <li>I understand that in the course of investigation, third party
            researchers may be required to participate in the investigation.</li>
        <li>I hereby authorize the investigation provider to utilize such
            third parties so long as all parties have executed a written agreement
            to comply with our Policy regarding Conduct of Investigations.</li>
        </ul>
    </td>
</tr>
<tr>
    <td align="left" valign="top" bgcolor="#FFFF66">
        <input type="checkbox" name="cb_acceptTerms" value="1" class="ckbx"
         id="cb_acceptTerms" /> I have reviewed the information entered above and
        agree with the authorization to continue.
        <span class="style9">&nbsp;(Please check the box to
        continue.)</span></td>
</tr>
</table>
</div>

<?php
// Adjust Close button label and omit Submit to Investigator for Internal Review
if ($caseType === DUE_DILIGENCE_INTERNAL) {
    $showSubmitToInvestigator = false;
    $closeLabel = 'Save and Close';
} else {
    $showSubmitToInvestigator = true;
    $closeLabel = 'Do not Submit | Close';
}
?>

<table width="491" cellpadding="0" cellspacing="8" class="paddiv">
<tr>
    <td width="108" height="19" nowrap="nowrap" class="submitLinksBack">
        <a href="javascript:void(0)"
        onclick="return submittheform('Go back and edit information')">Go
        Back | Edit</a></td>
    <?php if ($showSubmitToInvestigator) { ?>
    <td width="166" nowrap="nowrap" class="submitLinks">
        <a href="javascript:void(0)"
        onclick="return submittheform('Submit to Investigator')">Submit to
        Investigator</a></td>
    <?php } ?>
    <td width="200" nowrap="nowrap" class="submitLinksCancel"><a href="javascript:void(0)"
        onclick="return submittheform('Close');" ><?php echo $closeLabel; ?></a></td>
</tr>
</table>
</form>

<!--   End Center Content-->
<?php
noShellFoot(true);
?>