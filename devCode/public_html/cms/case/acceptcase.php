<?php

/**
 * Accept completed investigation
 */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit('Invalid access');
}

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('acceptCase')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
require_once __DIR__ . '/../includes/php/'.'class_globalCaseIndex.php';


$e_caseID = $caseID = intval($_GET['id']);
$caseRow = fGetCaseRow($caseID);
if (!$caseRow
    || $_SESSION['userType'] <= VENDOR_ADMIN
    || $_SESSION['userSecLevel'] <= READ_ONLY
) {
    session_write_close();
    header("Location: /cms/case/casehome.sec");
    exit;
}
$e_clientID = $clientID = $_SESSION['clientID'];
$globalIdx = new GlobalCaseIndex($clientID);

$dbCls->open();
$prevVals = $dbCls->fetchObjectRow("SELECT c.caseStage, "
    . "s.name AS caseStageName "
    . "FROM cases AS c "
    . "LEFT JOIN caseStage AS s ON s.id = c.caseStage "
    . "WHERE c.id='$e_caseID' LIMIT 1"
);

$e_accepted_stage = $stage = ACCEPTED_BY_REQUESTOR;
$newStageName = $dbCls->fetchValue("SELECT name FROM caseStage WHERE id='$e_accepted_stage' LIMIT 1");

require __DIR__ . '/../includes/php/'."dbcon.php";


// Update the case record
$e_completed_stage = COMPLETED_BY_INVESTIGATOR;
$sql = "UPDATE cases SET caseStage = '$e_accepted_stage', caseAcceptedByRequestor = curdate() "
    . "WHERE id = '$e_caseID' AND clientID = '$e_clientID' "
    . "AND caseStage='$e_completed_stage' LIMIT 1";
$result = mysqli_query(MmDb::getLink(), $sql);
if (!$result) {
    exitApp(__FILE__, __LINE__, $sql);
}

$globalIdx->syncByCaseData($e_caseID);


// log the event
if (mysqli_affected_rows(MmDb::getLink())) {
    logUserEvent(25, "stage: `$prevVals->caseStageName` => `$newStageName`", $caseID);
    // Generate an email to notify the requestor that the investigator has completed his work
    fSendSysEmail(EMAIL_ACCEPTED_BY_REQUESTOR, $caseID);

    // Return to Case List
    session_write_close();
    header("Location: casehome.sec?tname=caselist");
    exit;
}
