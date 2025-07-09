<?php
/**
 * Upload case file attachment
 */

if (!isset($_GET['id'])) {
    exit('Invalid access');
}
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('accCaseAttach') || !$accCls->allow('addCaseAttach')) {
    exit('Access denied.');
}

$caseID = intval($_GET['id']);

require_once __DIR__ . '/../includes/php/'.'funcs_cases.php';
$caseRow = fGetCaseRow($caseID, MYSQLI_ASSOC, false, 'caseName, caseStage');
if (!$caseRow || $_SESSION['userSecLevel'] <= READ_ONLY) {
    exit;
}

if (isset($_SESSION['modalReturnPath'])) {
    $modalReturnPath = $_SESSION['modalReturnPath'];
} else {
    $modalReturnPath = $sitepath . 'case/editsubinfodd_pt2.php?id=' . $caseID;
}

require_once __DIR__ . '/../includes/php/'.'class_uploadmonitor.php';
require_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';

$jsVar = 'subinfoUpl';
$subinfoUplMon = new UploadParent($jsVar, 'case/subinfoAttachForm.php', 'subinfodoc', $caseID);

if(preg_match('/(?i)msie [5-7]/',(string) $_SERVER['HTTP_USER_AGENT'])) {
    $whitespace = 'normal';
    $tableCapWidth = '99';
} else {
    $whitespace = 'nowrap';
    $tableCapWidth = '100';
}
$insertInHead = <<<EOT

<style type="text/css">
/* Overide global float setting for mainContent */
#mainContent {
    float:left !important;
}

.filenameContainer {
    overflow:hidden;
    padding-right:5px;
    white-space:nowrap;
    max-width:287px;
}

.filename {
    overflow:hidden;
    padding-right:5px;
    white-space:{$whitespace};
    max-width:280px;
}
.filename a {
    display:block;
    max-width:280px;
}
#tableCap {
    width:{$tableCapWidth}%;
}
</style>

<script type="text/javascript">
function refreshSubinfoAttach()
{
    window.location = "{$sitepath}case/subinfoAttach.php?id={$caseID}";
}
function closeAttachModal()
{
    window.location = "$modalReturnPath";
}
</script>

EOT;

$loadYUImodules = ['cmsutil', 'button', 'json'];
$pageTitle = "Subject Information Attachments";
noShellHead(true);

require_once __DIR__ . '/../includes/php/'.'class_editAttach.php';
$editAttach = new EditAttach('subinfodoc', $_SESSION['clientID']);
echo $editAttach->panelDiv();
echo $editAttach->panelJs();

$upError = safeGetSessVar('uperror');

?>

<!--Start Center Content-->

<div id="mainContent" style="margin:1.5em;">

<h1 style="padding-left:5px">Additional Detail Attachments:
    <?php echo $caseRow['caseName']; ?></h1>

<div id="tableHoldDivBlue">

<table id="tableCap2" cellspacing="0" cellpadding="0">
<caption class="no-trsl">Attachments <?php echo $upError; ?></caption>  <tr><td></td></tr>
</table>

<table id="myTable" cellspacing="0" class="sortable">
<tr>
    <th width="370">Description/Type of Attachment</th>
    <th width="287">File Name</th>
    <th width="307">Category</th>
<?php if (!isset($_GET['viewOnly'])) {
    ?>
     <th  width="160" style="padding-left:20px;">Edit Attachment</th>
    <th class="sorttable_nosortDelete ta-cent" style="color:#990000;" scope="col" width="160">Delete
        Attachment</th>
    <?php
} ?>
</tr>

<?php
require __DIR__ . '/../includes/php/'.'dbcon.php';
/*
*  Check for data
*  Get Attachments
*/
$sql = "SELECT subinfo.id, subinfo.description, subinfo.filename, subinfo.fileType, "
    . "subinfo.fileSize, subinfo.caseStage, category.name, subinfo.undeletable FROM subInfoAttach "
    . "AS subinfo LEFT JOIN docCategory AS category on "
    . "subinfo.catID = category.id WHERE subinfo.caseID = '$caseID' ORDER BY `description`";

$result = mysqli_query(MmDb::getLink(), $sql);

/* Check for error */
if (!$result) {
    exitApp(__FILE__, __LINE__, $sql);
}

$NumOfRows = mysqli_num_rows($result);

/* Loop through inserting table rows */
for ($i = 0; $i < $NumOfRows; $i++) {

    $row = mysqli_fetch_array($result, MYSQLI_BOTH);
    $fileType = getFileTypeDescriptionByFileName($row[2]);
    $tooltip = "File: $row[2], Size: $row[4] bytes, Type: $fileType";
    echo "<tr>\n";
    /* Description */
    echo "  <td width=\"370\" data-tl-ignore='1'>" . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8')
    . "<input type='hidden' value='" . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8') . "' id='editAttachDesc-" . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . "'>"
    . "</td>\n";
    /* Filename */
    echo "<td width=\"287\" class=\"filenameContainer\" data-tl-ignore='1'><div class=\"filename\">"
    . "<a href=\"" . htmlspecialchars("subinfoDownload.php?id=" . $row[0], ENT_QUOTES, 'UTF-8') . "\" "
    . "title=\"" . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . "\" alt=\"" . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . "\">"
    . htmlspecialchars($row[2], ENT_QUOTES, 'UTF-8') . "</a></div></td>";
    /* Category */
    echo "<td width=\"307\" data-tl-ignore='1'>" . htmlspecialchars($row[6], ENT_QUOTES, 'UTF-8') . "</td>";

    if (!isset($_GET['viewOnly'])) {
        /* Edit Attachment */
        $doEdit = 'YAHOO.' . $editAttach->jsPrefix . '.fetchData(' . $row[0] . ')';
        echo "<td width=\"160\" class=\"ta-cent\"><a href='javascript:void(0);' "
            . "onclick='" . htmlspecialchars($doEdit, ENT_QUOTES, 'UTF-8') . "'>Edit</a></td>";
        if ($row[5] >= $caseRow['caseStage'] && $row[7] == 0) {
            /* Delete Attachment link */
            $delLink = "<a href=\"subinfoDelAttach.php?id=" . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . "\">Delete</a>" ;
        } else {
            /* Delete not allowed */
            $delLink = '---';
        }
        echo "  <td width=\"160\" class=\"ta-cent\">" . $delLink . "</td>\n";

    }
    echo "</tr>\n";
} /* End for loop */

mysqli_free_result($result);

 ?>

</table>
</div>
<?php

$subinfoUplMon->outputFormDiv();
$subinfoUplMon->outputOverlay();

?>

<script type="text/javascript">

<?php
$subinfoUplMon->initJsVar();
$subinfoUplMon->outputMonitor();
?>

YAHOO.util.Event.onDOMReady(function(o){
    subinfoUpl.setUploadForm('show');
});

</script>
</div>
<!--   End Center Content-->
<?php
noShellFoot(true);
?>
