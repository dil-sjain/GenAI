<?php

/**
 * Delete a case attachment
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('accCaseAttach') || !$accCls->allow('addCaseAttach')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'.'funcs_cases.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';

$_GET['id'] = intval($_GET['id']);

// Connect us to the database
require __DIR__ . '/../includes/php/'.'dbcon.php';

$rowID = intval($_GET['id']);


// Lets get our caseID

// $e_ trusted $rowID
$sql = "SELECT caseID FROM subInfoAttach WHERE id = ?";

$result = executePreparedStmt($sql, 'i', $rowID);

if (!$result) {
    exitApp(__FILE__, __LINE__, $sql);
}

$row = mysqli_fetch_row($result);

$caseID = (int)$row[0];
$caseRow = fGetCaseRow($caseID);
if (!$caseRow || $_SESSION['userSecLevel'] <= READ_ONLY) {
    exit;
}


// $e_ trusted $caseID, int from db

$attachRow = $dbCls->fetchObjectRow("SELECT filename, description "
    . "FROM subInfoAttach WHERE id = '$rowID' AND caseID = '$caseID' LIMIT 1"
);
require __DIR__ . '/../includes/php/'.'dbcon.php';
// Lets delete attachment from the appropraite table
// Allow deletion only if caseStage when uploaded is >= current caseStage
ignore_user_abort(true);

$e_caseRow_caseStage = $caseRow['caseStage']; //safe, field from db pull

$sql = "DELETE FROM subInfoAttach "
    . "WHERE id = ? AND caseID = ? "
    . "AND caseStage >= ? LIMIT 1";

$result = executePreparedStmt($sql, 'iii', $rowID, $caseID, $e_caseRow_caseStage);

if (!$result) {
    exitApp(__FILE__, __LINE__, $sql);
}

if ($result !== 0) {
    include_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';
    removeClientFile('subInfoAttach', $_SESSION['clientID'], $_GET['id']);
    logUserEvent(28,
        "`$attachRow->filename' removed from case folder, description: `$attachRow->description`",
        $caseID
    );
}

// Redirect user to

header("Location: subinfoAttach.php?id=$row[0]");

?>
