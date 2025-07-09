<?php
/**
 * Display and update Case Workflow Tasks
 */

require_once __DIR__ . '/../../includes/php/'.'cms_defs.php';

$caseID = (int)$_SESSION['currentCaseID'];
$ospreyPath = $_ENV['ospreyISS'];
$ospreyTarget = $_ENV['ospreyISStarget'];
$sitepath = $_ENV['sitePath'];
if (!isset($_COOKIE['token'])) {
    (new \Models\DataIntegration\TokenData())->setUserJWT();
}
$_COOKIE['token'] = htmlspecialchars($_COOKIE['token'], ENT_QUOTES, 'UTF-8');
?>

<link rel="stylesheet" type="text/css" href="<?php echo $sitepath; ?>../assets/css/TPM/Workflow/osp-workflow.css" />

<div id="osprey-integration">
    <iframe name="osprey-integration-frame" frameborder="0" id="osprey-iframe" allowfullscreen scrolling="auto"
        src="<?php echo $ospreyPath . $ospreyTarget; ?>"></iframe>
    <form id="osprey-integration-form" method="post">
        <input type="hidden" name="applicationToken" value="<?php echo $_COOKIE['token']; ?>">
        <input type="hidden" name="requestUrl" value="/integratedlogin/integrated">
        <input type="hidden" name="isEmbedded" value="true">
        <input type="hidden" name="caseID" value="<?php echo $caseID; ?>">
        <input type="hidden" name="target" value="_self">
    </form>
</div>
<script>
    function getMessage (evt) {
        console.log("(Case WF) Message received from: " + evt.origin + ", message data: " + evt.data);
        console.log("ospreyPath: <?php echo $ospreyPath; ?>, ospreyTarget: <?php echo $ospreyTarget; ?>");
        if (evt.origin == "<?php echo $ospreyPath; ?>" && evt.data == "sendForm") {
            var formContent = $('#osprey-integration-form').serialize();
            console.log("postMessage FormContent: " + formContent);
            evt.source.postMessage(formContent, "<?php echo $ospreyPath; ?>");
        } else {
            console.log("Unauthorized message event from:" + evt.origin + ", message: " + evt.data);
        }
        window.removeEventListener("message", getMessage, false);
    }
    window.addEventListener("message", getMessage, false);
</script>
