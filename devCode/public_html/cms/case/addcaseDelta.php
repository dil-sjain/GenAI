<?php
/**
 *  @sec: SEC-873: RF: Add Case Refactor
 *
 *  @dev: This PHP file acts like Legacy's public_html/cms/dashboard/dashboard.sec
 *
 */


/**
 *  @dev: Taken directly from public_html/cms/dashboard/dashboard.sec
 */
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(TRUE, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('accDashboard')) {
    exit('Access denied');
}
require_once __DIR__ . '/../includes/php/'.'class_db.php';
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/'.'dashboard_funcs.php';

fchkUser("dashboard");
$b3pAccess = (isset($_SESSION['b3pAccess']) && $_SESSION['b3pAccess'] == 1);
$regionTitle = $_SESSION['regionTitle'];

// If Case Stages and Description haven't already been loaded, lets do it
if (!isset($_SESSION['caseStageList'])) {
    $_SESSION['caseStageList'] = fLoadCaseStageList();
}

// If Case Type and Description haven't already been loaded, You can do it!!
if (!isset($_SESSION['caseTypeList'])) {
    $_SESSION['caseTypeList'] = fLoadCaseTypeList();
}

require_once __DIR__ . '/../includes/php/'.'caselist_inc.php';
require_once __DIR__ . '/../includes/php/'.'chartInc.php';

$loadYUImodules = ['cmsutil', 'button', 'json'];
$pageTitle = "Dashboard Snapshot";
noShellHead();
?>


<script type="text/javascript">

    <?php
    /**
     *  @dev: Taken directly from public_html/cms/dashboard/dashboard.sec
     */
    ?>
    YAHOO.namespace('add3p');
    YAHOO.add3p.panelReady = false;

    assoc3pProfile = function (caller, caseID) {
        return (YAHOO.add3p.panelReady) ? YAHOO.add3p.assocProfile(caller, caseID) : false;
    };
    add3pProfile = function () {
        return (YAHOO.add3p.panelReady) ? YAHOO.add3p.addProfile() : false;
    };


    <?php
    /**
     *  @dev: public_html/cms/includes/php/shell_header.php:697-702 - function onHide() { ... };
     *
     *  @dev: Closes the `Add Case` form by destroying the appDialog
     */
    ?>
    top.onHide = function () {
        top.appNS.addCase.p.eventDialog.destroy();
    };


    <?php
    /**
     *  @dev: public_html/cms/includes/php/shell_header.php:339-363
     *
     *  @dev: Legacy progress bar rendering for Upload Attachments
     *
     *  @see: subInfoAttach.php & class_uploadmonitor.php
     */
    ?>
    top.panelNow = new YAHOO.widget.Panel('resizablepanel2', {
        visible: false,
        draggable: false,
        close: false,
        width: '970px',
        modal: true,
        fixedcenter: false,
        y: 0,
        zIndex: 1500
    });
    top.panelNow.renderEvent.subscribe(function () {
            YAHOO.util.Dom.setStyle(Dom.get('mainIframe'),
                'overflow', 'auto'
            );
            ifrm = Dom.get('mainIframe')[0];
        },
        top.panelNow,
        true
    );
    top.panelNow.render();
</script>


<?php
/**
 *  @dev: Taken directly from public_html/cms/dashboard/dashboard.sec
 */
if ($b3pAccess) {
    include_once __DIR__ . '/../includes/php/'.'add3p.php';
}
?>


<style type="text/css">
    #new-profile_mask.mask, .yui-skin-sam .mask {
        opacity: 0 !important;
    }
    #mainIframe .jqx-window-content {
        width: 970px !important;
        max-width: 970px !important;
        height: 700px !important;
        max-height: 700px !important;
    }
    #mainIframe .appdiag-content {
        width: 950px !important;
        max-width: 950px !important;
        height: 700px !important;
        max-height: 700px !important;
    }
    #mainIframe .jqx-window-header {
        height: 11px !important;
        padding-top:4px !important;
        padding-bottom:4px !important;
        margin-bottom:1px !important;
    }
    #mainIframe {
        width: 950px !important;
        max-width: 950px !important;
        height: 600px !important;
        max-height: 600px !important;
        border: none;
    }
</style>


<?php
/**
 *  @dev: iframe limits the scope of the requests from addcase.php where a require/include would have changed it
 */
$url = str_replace('addcaseDelta', 'addcase', (string) $_SERVER['SCRIPT_URI']);
?>
<iframe id="mainIframe" src="<?=$url; ?>?xsv=1"></iframe>
