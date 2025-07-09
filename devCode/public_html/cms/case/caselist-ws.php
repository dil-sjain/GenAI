<?php
/**
 * Process request for case list
 *
 * CS compliance: grh - 08/20/2012
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
if (! $session->cms_logged_in()) {
    exit;
}
$caseListAuthKey = 'caseListPgAuth';
$pgAuthErr = (!isset($_GET['pgauth'])
    || !PageAuth::validToken($caseListAuthKey, $_GET['pgauth']));

if (isset($_SESSION['b3pAccess']) && $_SESSION['b3pAccess']) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}

if (! isset($_SESSION['id']) || intval($_SESSION['id']) == 0) {
    return;
}

require_once __DIR__ . '/../includes/php/'.'funcs_users.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'caselist_inc.php';
require_once __DIR__ . '/../includes/php/'.'class_search.php';

$userType = $_SESSION['userType'];
$userClass = $session->secure_value('userClass');
$cnt = 0;

// max num results to use multi-assign cases. (*** also set in caselist-json.php ***)
$maCaseMax = 400;

$fld  = $_GET['fld'];
$srch = $_GET['srch'];
$stg  = $_GET['stg'];
$stat = $_GET['stat'];
$dts  = 'all_dates';
$useSpCases = false;
if ($userClass != 'vendor') {
    $rgn  = $_GET['reg'];
} else {
    $rgn = '';
    $cname = trim((string) $_GET['cname']);
    $iname = trim((string) $_GET['iname']);
    $useSpCases = empty($_ENV['holdSpCaseList']);
}

$gsrchSrc = 'CL';
if (array_key_exists('caseFilterSrc', $_SESSION)) {
    $gsrchSrc = $_SESSION['caseFilterSrc'];
}
if ($gsrchSrc == 'AF' || $gsrchSrc == 'SB') {
    if ($gsrchSrc == 'AF') {
        $_SESSION['gsrch'][$gsrchSrc]['cs']['flds'][0] = $fld;
        $_SESSION['gsrch'][$gsrchSrc]['cs']['srch'][0] = $srch;
    } else {
        $_SESSION['gsrch'][$gsrchSrc]['cs']['fld'] = $fld;
        $_SESSION['gsrch'][$gsrchSrc]['cs']['srch'] = $srch;
    }
    $gsrch = $_SESSION['gsrch'][$gsrchSrc]['cs'];
    $gsrch['mode'] = 'cs';
} else {
    $_SESSION['gsrch'][$gsrchSrc]['fld'] = $fld;
    $_SESSION['gsrch'][$gsrchSrc]['srch'] = $srch;
    $gsrch = $_SESSION['gsrch'][$gsrchSrc];
}

$defOrderBy = 'casenum';
if ($userClass == 'vendor') {
    $allowOrderBy = ['casenum', 'clientname', 'casename', 'casetype', 'stage', 'investiagent', 'assigned', 'duedate', 'iso2', 'dlvry'];
} else {
    $allowOrderBy = ['casenum', 'dbaname', 'casename', 'casetype', 'stage', 'requester', 'region', 'iso2'];
}
$orderBy = $_GET['ord'];
if (!in_array($orderBy, $allowOrderBy)) {
    $orderBy = $defOrderBy;
}

$minPP = 15;
$si = intval($_GET['si']);
$rpp = intval($_GET['pp']);
if (!in_array($rpp, [15, 20, 30, 50, 75, 100])) {
    $rpp = $minPP;
}
$yuiSortDir = ($_GET['dir'] == 'yui-dt-desc') ? 'yui-dt-desc' : 'yui-dt-asc';
$sortDir = ($yuiSortDir == 'yui-dt-asc') ? 'ASC': 'DESC';

// gather input values for search
$inValues = ['src'   => $gsrchSrc, 'fld'   => $fld, 'srch'  => $srch, 'stat'  => $stat, 'stg'   => $stg, 'si'    => $si, 'sort'  => $orderBy, 'dir'   => $sortDir, 'rpp'   => $rpp, 'spc'   => $useSpCases];
if ($userClass == 'vendor') {
    $inValues['iname'] = $iname;
    $inValues['cli'] = $cname;
} else {
    $inValues['rgn'] = $rgn;
}
if ($gsrchSrc == 'AF') {
    $showing = $gsrch['showing'];
    if ($showing == 1) {
        $inValues['srch'] = $gsrch['srch'][0];
        $inValues['fld'] = $gsrch['flds'][0];
    } else {
        $inValues['srch'] = [];
        $inValues['fld'] = [];
        for ($i = 0; $i < $showing; $i++) {
            $inValues['srch'][] = $gsrch['srch'][$i];
            $inValues['fld'][] = $gsrch['flds'][$i];
        }
    }
    $inValues['matchCase'] = $gsrch['matchIncl'];
    if ($gsrch['dateIncl']) {
        if ($gsrch['date1'] != '0000-00-00') {
            $inValues['date1'] = $gsrch['date1'];
        }
        if ($gsrch['date2'] != '0000-00-00') {
            $inValues['date2'] = $gsrch['date2'];
        }
    }
    if ($gsrch['meIncl']) {
        switch ($gsrch['me']) {
        case 'requestor':
            $inValues['me'] = $_SESSION['userid'];
            $inValues['meCol'] = $gsrch['me'];
            break;
        case 'caseInvestigatorUserID':
        case 'acceptingInvestigatorID':
        case 'assigningProjectMgrID':
            $inValues['me'] = $_SESSION['id'];
            $inValues['meCol'] = $gsrch['me'];
            break;
        }
    }
}
try {
    if ($pgAuthErr) {
        $records = 0;
        $startOffset = 0;
        $startPage = 1;
    } else {
        // Scope not always set. Adding isset check,
        // which fixes "Warning: Illegal string offset" error.
        $gsrchScope = $gsrch['scope'] ?? '';
        if ($userClass == 'vendor') {
            $GS = new SpSearchCases($gsrchScope);
        } else {
            $GS = new SearchCases($gsrchScope);
        }
        $GS->parseInput($inValues);
        $records = $GS->countRows();
        $pages = $GS->pages;
        $rpp = $GS->recordsPerPage;
        $startPage = $GS->startPage;
        $startOffset = $GS->startOffset;
    }
    if ($records) {
        $rows = $GS->getRecords();
    }
} catch (SearchException $ex) {
    debugTrack(['SearchException message' => $ex->getMessage()]);
    $records = 0;
    $startOffset = 0;
    $startPage = 1;
}

// setup of the multi-case assignment vars.
// only holds values when number of records the search will return is within $maCaseMax setting.
if ($_SESSION['userType'] == VENDOR_ADMIN) {
    if (!empty($records) && $records <= $maCaseMax) {
        $_SESSION['multiAssignCase'] = [
            //            'count' => $GS->countSql,
            //            'search' => $GS->searchSql,
            'records' => $records,
        ];
    } else {
        unset($_SESSION['multiAssignCase']);
    }
}

$sessKey = 'stickyCL';
$_SESSION[$sessKey]['si'] = $startOffset;
$_SESSION[$sessKey]['pg'] = $startPage;
$_SESSION[$sessKey]['pp'] = $rpp;
$_SESSION[$sessKey]['tr'] = $records;
$_SESSION[$sessKey]['dir'] = $yuiSortDir;
$_SESSION[$sessKey]['ord'] = $orderBy;
$_SESSION[$sessKey]['lb_status'] = $stat;
$_SESSION[$sessKey]['lb_stage'] = $stg;
if ($userClass != 'vendor') {
    $_SESSION[$sessKey]['lb_region'] = $rgn;
} else {
    $_SESSION[$sessKey]['cname'] = $cname;
    $_SESSION[$sessKey]['iname'] = $iname;
}

// Construct JSON output
$jsData = "{\"Response\":{\n"
        . " \"Records\":[";

if ($records && $rows) {
    $limit = count($rows);
    if ($userClass == 'vendor') {
        for ($i = 0; $i < $limit; $i++) {
            $rows[$i]->casename = quoteJSONdata($rows[$i]->casename);
            $rows[$i]->clientname = quoteJSONdata($rows[$i]->clientname);
            $rows[$i]->investiagent = quoteJSONdata($rows[$i]->investiagent);
        }
    } else {
        for ($i = 0; $i < $limit; $i++) {
            $rows[$i]->casename = quoteJSONdata($rows[$i]->casename);
            $rows[$i]->requester = quoteJSONdata($rows[$i]->requester);
            if ($_SESSION['b3pAccess'] && $rows[$i]->tpID) {
                if ($userType > CLIENT_ADMIN) {
                    $rows[$i]->tpOk = 1;
                } else {
                    $rows[$i]->tpOk = bCanAccessThirdPartyProfile($rows[$i]->tpID);
                }
            } else {
                $rows[$i]->tpOk = 0;
            }
        }
    }

    $dbCls = dbClass::getInstance();
    for ($i = 0; $i < $limit; $i++) {
        $ddqExists = $dbCls->fetchObjectRow("SELECT caseID, clientID, formClass, "
            . "status, subByDate, CONCAT('L-', caseType, ddqQuestionVer) "
            . "AS legacyID FROM ddq where caseID = '" . $rows[$i]->dbid . "'"
        );
        if (!$ddqExists || $ddqExists->caseID != $rows[$i]->dbid) {
            $rows[$i]->infoSource = quoteJSONdata("Manual Creation");
        } else {
            $ddqName = $dbCls->fetchObjectRow("SELECT name FROM ddqName where "
                . "legacyID = '{$ddqExists->legacyID}' "
                . "AND clientID = '{$ddqExists->clientID}' "
                . "AND formClass = '{$ddqExists->formClass}'"
            );
            if ($ddqName && strlen((string) $ddqName->name) > 0) {
                $intakeName = $ddqName->name;
            } else {
                $intakeName = $ddqExists->legacyID;
            }
            switch ($ddqExists->status) {
            case 'submitted':
                $trimDate = substr((string) $ddqExists->subByDate, 0, 10);
                $rows[$i]->infoSource = quoteJSONdata("{$intakeName} ({$trimDate})");
                break;
            default:
                $rows[$i]->infoSource = quoteJSONdata("{$intakeName} "
                    . "({$ddqExists->status})"
                );
                break;
            }
        }
    }

    // Convert data to JSON format
    $jsData .= substr(json_encodeLF($rows), 1, -1);
}


$jsData .= "],\n";
$pgAuth = PageAuth::genToken($caseListAuthKey);
$maShow = 0;
if (!empty($_SESSION['multiAssignCase']) && $records == $_SESSION['multiAssignCase']['records']) {
    $maShow = 1;
}
$jsData .= " \"Total\":$records,\n"
        .  " \"RowsPerPage\":$rpp,\n"
        .  " \"Page\":$startPage,\n"
        .  " \"PgAuth\":\"$pgAuth\",\n"
        .  " \"PgAuthErr\":\"$pgAuthErr\",\n"
        .  " \"RecordOffset\":$startOffset,\n"
        .  " \"MultiAssign\":$maShow\n }\n}\n";

// Override normal headers to values more favorable to a JSON return value
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . "GMT");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Content-Type: text/plain; charset=utf-8"); //JSON

echo $jsData;

// end php tab not required
