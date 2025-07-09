<?php
/**
 * Download a case attachment
 */

@ob_end_clean();

if (isset($_GET['id'])) {
    $_GET['id'] = intval($_GET['id']);
    if (strpos((string) $_SERVER['HTTP_USER_AGENT'], 'MSIE')) {
        session_cache_limiter('none'); // For IE only
    }
    session_start(); // DO NOT REMOVE THIS LINE

    include_once __DIR__ . '/../includes/php/'.'cms_defs.php';
    $session->cms_logged_in(true, -1);
    include_once __DIR__ . '/../includes/php/'.'class_access.php';
    $accCls = UserAccess::getInstance();
    if (!$accCls->allow('accCaseAttach') || !$accCls->allow('dlCaseAttach')) {
        exit('Access denied.');
    }

    include_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';
    include  __DIR__ . '/../includes/php/'."dbcon.php";

	$rowID = (int)$_GET['id'];

	// $e_ trusted $rowID

    $query = "SELECT filename, fileType, fileSize, caseID "
        . "FROM subInfoAttach WHERE id = '$rowID'";
    if (!($result = mysqli_query(MmDb::getLink(), $query))) {
        exitApp(__FILE__, __LINE__, $query);
    }
    [$name, $type, $size, $caseID] = mysqli_fetch_array($result, MYSQLI_NUM);
    mysqli_free_result($result);

    include_once __DIR__ . '/../includes/php/'."funcs_cases.php";

    $caseRow = fGetCaseRow($caseID);
    // If we don't get a case row back, there was an Illegal Access Atempt
    if (!$caseRow) {
        header("Location: /logout");
    }

    if ($caseRow) {

        //  Notify developers if the file was not copied over
        if (SECURIMATE_ENV == 'Development') {
            $destination = '/clientfiles/'.SECURIMATE_ENV."/subInfoAttach/{$_SESSION['clientID']}/{$_GET['id']}-info";
            if (!is_file($destination)) {
                exit('The file is not available in the '.SECURIMATE_ENV.' environment.');
            }
        }

	    include_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';
        $isIE = false;
        if (strpos((string) $_SERVER['HTTP_USER_AGENT'], 'MSIE')) {
            $isIE = true;
        }
        $fname = formatContentDispositionHeader($name, $isIE);
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header("Content-Length: $size");
        header("Content-Type: $type");
        //echo $content;
        echoClientFile('subInfoAttach', $_SESSION['clientID'], $_GET['id']);
        exit;
    }
}

?>
