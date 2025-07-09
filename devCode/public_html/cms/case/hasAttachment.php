<?php

/**
 * Lists case attachments
 *
 * Intended for use in an ifram
 */

if (!isset($_GET['id'])) {
    exit('Invalid access');
}
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
require_once __DIR__ . '/../includes/php/'.'class_db.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_cases.php';
require_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';

// Validate case_id
$caseID = intval($_GET['id']);
$case_row = fGetCaseRow($caseID);
if (!$case_row) {
    exit;
}

if(preg_match('/(?i)msie [5-8]/',(string) $_SERVER['HTTP_USER_AGENT'])) {
    $whitespace = 'normal';
    $tableWidth = '399';
} else {
    $whitespace = 'nowrap';
    $tableWidth = '410';
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<style type="text/css">
body,td {
    font-family: 'Source Sans Pro', Arial, Helvetica, sans-serif;
    font-size: 11px;
    color: #333333;
    font-weight:normal;
}
th {
font-size: 12px;
    font-weight:bold;
}
.descriptionContainer {
    max-width:245px;
    padding:0 5px;
}

.filenameContainer {
    overflow:hidden;
    padding-right:5px;
    white-space:nowrap;
    max-width:172px;
}

.filename {
    overflow:hidden;
    padding-right:5px;
    white-space:<?php echo $whitespace; ?>;
    max-width:165px;
}
.filename a {
    display:block;
    max-width:165px;
}
</style>

</head>
<body>
<?php

$bAttachments = fCheckForAttachments('subInfoAttach', $caseID);
if ($bAttachments) {
    $sql = "SELECT id, description, filename, fileSize, fileType "
        . "FROM `subInfoAttach` WHERE `caseID`='$caseID'";
    if($rows = $dbCls->fetchArrayRows($sql)) {
		if (is_array($rows) && count($rows)) {
            $limit = count($rows);
            // Build table to list Attachments
            ?>
            <table width="<?php echo $tableWidth; ?>" border="0"><tr>
                  <th width="238" align="left">Description</th>
                  <th width="172" align="left">File Name</th>
            </tr>
            <?php
            $bgColor = "#FFFFFF";      // Initialize Back Ground Color
            // Loop through inserting table rows
            for ($i = 0; $i < $limit; $i++) {
                // Grab our data
                $attachID = $rows[$i][0];
                $description = $rows[$i][1];
                $fileName = $rows[$i][2];
                $fileSize = $rows[$i][3];
                $fileType = getFileTypeDescriptionByFileName($fileName);
                $tooltip = "File: $fileName, Size: $fileSize bytes, Type: $fileType";
                $bgColor = ($bgColor=="#FFFFFF" ? "#F0F0F0" : "#FFFFFF");
                echo "<tr><td width=\"238\" bgcolor=\"$bgColor\" class=\"descriptionContainer\" data-tl-ignore='1'>$description</td>"
                    ."<td width=\"172\" bgcolor=\"$bgColor\" class=\"filenameContainer\"  data-tl-ignore='1'>"
                    ."<div class=\"filename\"><a href=\"subinfoDownload.php?id=$attachID\" "
                    ."title=\"$tooltip\" alt=\"$tooltip\"  data-tl-ignore='1'>$fileName</a></div></td></tr>";
            }
            echo "</table>";
        }
    }
}
?>

</body>
</html>
