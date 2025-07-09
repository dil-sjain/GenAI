<?php
/**
 * Iframe content for case upload form
 */

require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('accCaseAttach') || !$accCls->allow('addCaseAttach')) {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
require_once __DIR__ . '/../includes/php/'.'funcs_clientfiles.php';
require_once __DIR__ . '/../includes/php/'.'Lib/SettingACL.php';

$uploaded = false;
if (isset($_GET['fid'])
    && ($fid = $_GET['fid'])
    && preg_match('/^[a-f0-9]{32}$/', (string) $fid)
    && isset($_SESSION['uploads'])
    && isset($_SESSION['uploads'][$fid])
    && is_array($_SESSION['uploads'][$fid])
) {
    $caseID = $_SESSION['uploads'][$fid]['caseID'];
    $uploaded = true;
} elseif (isset($_GET['id'])) {
    $caseID = intval($_GET['id']);
} else {
    exit;
}

require_once __DIR__ . '/../includes/php/'.'funcs_cases.php';
$caseRow = fGetCaseRow($caseID, MYSQLI_ASSOC, false, 'caseStage');
if (!$caseRow || $_SESSION['userSecLevel'] <= READ_ONLY) {
    exit;
}

$clientID = $_SESSION['clientID'];
$docCategories = getClientDocumentCategories($clientID);
$docCategoryList = '<select name="attachCat" data-no-trsl-cl-labels-sel="1"><option selected="selected">---</option>';
if ($docCategories) {
    foreach ($docCategories as $docCategory) {
        foreach ($docCategory as $key => $value) {
            if ($key == 'id') {
                $catID = $value;
            } elseif ($key == 'name') {
                $catName = $value;
            }
        }
        $docCategoryList .= '<option value="'.$catID.'">'.$catName.'</option>';
    }
}
$docCategoryList .= '</select>';

$refreshDT = false;
$success = false;
$closePbar = false;
$err = [];

if ($uploaded) {
    $uploadFile = '/tmp/' . $_SESSION['uploads'][$fid]['filePrefix'] . 'upload';
    $fileSize = filesize($uploadFile);
    // validate file type here
    $filename = $_SESSION['uploads'][$fid]['filename'];
    $describe = $_SESSION['uploads'][$fid]['description'];
    $e_filename = $_SESSION['uploads'][$fid]['e_filename'];
    $e_describe = $_SESSION['uploads'][$fid]['e_description'];
    $category = $_SESSION['uploads'][$fid]['category'];
    $e_userid = $dbCls->esc($_SESSION['userid']);
    $caseStage = $caseRow['caseStage'];
    $contents = file_get_contents($uploadFile);
    $e_contents = '';//$dbCls->esc($contents);
    unset($contents);
    $validUpload = validateUpload($filename, $uploadFile, 'subinfodoc');
    if ($validUpload !== true) {
        if (file_exists($uploadFile)) {
            unlink($uploadFile);
        }
        $err[] = $validUpload;

    } else {
        $e_fileType = $dbCls->esc(
            trim(
                shell_exec(
                    "/usr/bin/mimetype -bM \"$uploadFile\""
                )
            )
        );
        // insert new document
        $sql = "INSERT INTO subInfoAttach ("
            . "caseID, description, filename, fileType, fileSize, "
            . "contents, ownerID, caseStage, catID, clientID "
            . ") VALUES ("
            . "'$caseID', '$e_describe', '$e_filename', '$e_fileType', '$fileSize', "
            . "'$e_contents', '$e_userid', '$caseStage', '$category', '$clientID' )";
        ignore_user_abort(true);
        $dbCls->query($sql);
        if ($dbCls->affected_rows()) {
            // store in filesystem, or delete db record on failure
            $recID = $dbCls->insert_id();
            if (moveUploadedClientFile($uploadFile, 'subInfoAttach', $clientID, $recID)) {
                // log the event
                logUserEvent(
                    27,
                    "`$filename' added to case folder, description: `$describe`", $caseID
                );
                $success = true;
                $refreshDT = true;
            } else {
                // delete uploaded file
                if (file_exists($uploadFile)) {
                    unlink($uploadFile);
                }
                $dbCls->query("DELETE FROM subinfoAttach WHERE id='$recID' LIMIT 1");
                $err[] = 'Failed storing upload in filesystem.';
            }
        }
    }
    // clear session var
    unset($_SESSION['uploads'][$fid]);
    $closePbar = true;

} // if upload completed

$setting = (new SettingACL($clientID))->get(
    SettingACL::MAX_UPLOAD_FILESIZE,
    ['lookupOptions' => 'disabled']
);
$uplMax = ($setting) ? $setting['value'] : 10;

$pageTitle = "Subject Information Attachments";
$reload = $sitepath . 'case/subinfoAttachForm.php?id=' . $caseID;
$insertInHead = <<<EOT

<style type="text/css">
.prmpt {
    text-align: right;
    font-weight: normal;
    padding-right: .5em;
}
</style>
<script type="text/javascript">

function doDone()
{
    parent.closeAttachModal();
}
function strtrim (str)
{
    return str.replace(/^\s*/,'').replace(/\s*$/, '');
}
function checkString(str)
{
    return str.match(/[<>]/) !== null;
}

// Prevent submission with empty inputs
function ckmissing(frm)
{
    if (strtrim(frm.upload.value).length == 0) {
        parent.subinfoUpl.showResult('Incomplete Information',
            'Please select a file to upload.'
        );
    } else if(frm.attachCat.value == 'undefined' || frm.attachCat.value == 'NULL'
        || frm.attachCat.value == '' || frm.attachCat.value == '---'
    ) { // Extensive validation to please IE7 and the rest of technology
        parent.subinfoUpl.showResult('Incomplete Information',
            'Please choose a category of the document you are uploading.'
        );
    } else if (strtrim(frm.attachDesc.value).length == 0) {
        parent.subinfoUpl.showResult('Incomplete Information',
            'Please enter a brief description of the document you are uploading.'
        );
    } else if (checkString(frm.attachDesc.value)) {
        parent.casedocUpl.showResult('Invalid Characters',
            'Description contains invalid characters: < or >.'
    );
    } else {
        var reg = YAHOO.util.Dom.getRegion('uplform-ctnr');
        parent.subinfoUpl.formWidth = reg.width;
        parent.subinfoUpl.formHeight = reg.height;
        parent.subinfoUpl.opRequest('upl-init', frm.upload.value, frm.attachDesc.value,
            frm.attachCat.value);
    }
    return false;
}

window.doSubmit = function(fid)
{
    var frm = document.uplform;
    frm.action = '/util/uploadfile?fid=' + fid + '&ms={$uplMax}';
    frm.submit();
};
window.doReload = function()
{
    window.location='$reload';
};

</script>

EOT;
$loadYUImodules = ['cmsutil', 'button'];
noShellHead(true);
$_SESSION['subinfodocPgAuth'] = PageAuth::genToken('subinfodocPgAuth');



?>
<!--Start Ifram Content-->

<div id="uplform-ctnr">

<form name="uplform" onsubmit="return ckmissing(this);" class="cmsform"
    enctype="multipart/form-data"
    action="<?php echo $_SERVER['PHP_SELF'], '?id=', $caseID; ?>" method="post">

<table cellpadding="3" cellspacing="0" width="480" border="0">
<tr>
    <td>&nbsp;</td>
    <td><p class="fw-bold">CREATE ATTACHMENT</p></td>
</tr>
<tr>
    <td><div class="prmpt">Choose File:</div></td>
    <td style="max-width:200px; overflow:hidden;"><input type="file" name="upload" /></td>
</tr>
<tr>
  <td><div class="prmpt">Choose category:</div></td>
  <td>
<?php echo $docCategoryList; ?>
  </td>
</tr>
<tr>
    <td valign="top"><div class="prmpt">Provide a description of the attachment
        you are uploading:</div></td>
    <td><textarea name="attachDesc" wrap="soft" style="width:300px;height:75px;"
        cols="40" rows="5" class="no-trsl"></textarea></td>
</tr>
<tr>
    <td><div class="no-wrap fw-normal ta-right marg-rtsm">File size limit <?php echo $uplMax; ?>MB</div></td>
    <td><input id="uplSub" class="v-hidden" type="submit" value="Upload" />
        <input id="uplDone" class= "v-hidden" type="button" value="Done" onclick="doDone();" /></td>
</tr>
</table>

</form>
</div>

<!--   End Ifram Content-->

<script type="text/javascript">

<?php
if ($refreshDT) {
    echo "parent.refreshSubinfoAttach();\n";
} elseif ($closePbar) {
    echo "parent.subinfoUpl.closePbar();\n";
}
if (count($err)) {
    $msg = (count($err) == 1) ? 'An error occurred:' : (count($err) . ' errors occurred:');
    $msg .= "\\n<ul>\\n";
    foreach ($err as $e) {
        $msg .= "<li>$e</li>\\n";
    }
    $msg .= '</ul>';
    echo "parent.subinfoUpl.showResult('Upload Failed', \"$msg\");\n";
}
?>

(function(){

    <?php // Upload button ?>
    var uplSubBtn = new YAHOO.widget.Button("uplSub", {
        id: "uplSubBtn-yui",
        label: "Upload",
        type: "submit"
    });
    <?php // Close button ?>
    var uplDoneBtn = new YAHOO.widget.Button("uplDone", {
        id: "upldoneBtn-yui",
        label: "Done",
        type: "button"
    });
    uplDoneBtn.on('click', function(){
        doDone();
    });

})();
</script>

<?php
noShellFoot(true);
?>
