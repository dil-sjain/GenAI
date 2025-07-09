<?php
/**
 * ddq invite processor
 *
 * This is the working script for ddq invite and re-invite.
 *
 * CS compliance and cleanup: 2012-07-23 lrb
 */

if (!isset($_POST['op'])) {
    return;
}
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();
if (!$accCls->allow('sendInvite') && !$accCls->allow('resendInvite')) {
        exit('Access denied.');
}

require_once __DIR__ . '/../includes/php/'.'ddq_funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_misc.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
require_once __DIR__ . '/../includes/php/'.'class_db.php';

$clientID = $_SESSION['clientID'];
$globaldb = GLOBAL_DB;
$real_global_db = REAL_GLOBAL_DB;

$jsData = '{}';
$jsObj = new stdClass();
$jsObj->Result = 0;

// Dispatcher
switch ($_POST['op']) {
    case 'checkLangs':
        $caseType = 12;
        $version = '';
        if (!empty($_POST['ddqName'])) {
            $_POST['ddqName'] = htmlspecialchars($_POST['ddqName'], ENT_QUOTES, 'UTF-8');
            $parts = splitDdqLegacyID($_POST['ddqName']);
            $caseType = $parts['caseType'];
            $version = $parts['ddqQuestionVer'];
        }
        $ddqLanguages = getDdqLanguages($clientID, $caseType, $version, EMAIL_SEND_DDQ_INVITATION, true);
        $_POST['defLang'] = htmlspecialchars($_POST['defLang'], ENT_QUOTES, 'UTF-8');
        $defaultLanguage = (isset($_POST['defLang'])) ? $_POST['defLang'] : 'EN_US';
        $defaultVersion  = '';
        if (empty($ddqLanguages)) {
            // Uh-oh.  No invitation email defined, so go create default email templates in the client DB
            setDdqDefaultEmail($clientID, $caseType, EMAIL_SEND_DDQ_INVITATION, $defaultLanguage, $defaultVersion);
            setDdqDefaultEmail(
                $clientID,
                $caseType,
                EMAIL_NOTIFY_CLIENT_DDQ_SUBMIT,
                $defaultLanguage,
                $defaultVersion,
                true
            );
            $ddqLanguages = getDdqLanguages($clientID, $caseType, $version, EMAIL_SEND_DDQ_INVITATION, true);
        }
        // Organize in key/value pairs
        $languages = [];
        foreach ($ddqLanguages as $language) {
            $languages[$language['langCode']] = $language['langNameEng'];
        }
        if (!array_key_exists($defaultLanguage, $languages)) {
            $defaultLanguage = 'EN_US';
            $languages['EN_US'] = 'English';
        }
        $jsObj->defaultLang = $defaultLanguage;
        $jsObj->countryLang = '';
        $_POST['tpCountry'] = htmlspecialchars($_POST['tpCountry'], ENT_QUOTES, 'UTF-8');
        if ($accCls->ftr->tenantHas(\Feature::TENANT_USE_COUNTRY_LANG) && !empty($_POST['tpCountry'])) {
            $jsObj->countryLang = getCountryLanguage($clientID, $_POST['tpCountry'], array_keys($languages));
        }
        $jsObj->Langs = [];
        foreach ($languages as $code => $name) {
            $jsObj->Langs[] = ['v' => $code, 't' => $name];
        }
        $jsObj->Result = 1;
        break;
    default:
        // do nothing
} // dispatcher


// Override normal headers to values more favorable to a JSON return value
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . "GMT");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Content-Type: text/plain; charset=utf-8"); //JSON

$jsData = json_encodeLF($jsObj);
echo $jsData;
