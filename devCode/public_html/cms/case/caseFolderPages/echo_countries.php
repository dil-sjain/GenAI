<?php
require_once __DIR__ . '/../../includes/php/cms_defs.php';
$session->cms_logged_in(TRUE, -1);

$isoCodeArray = $_SESSION['inFormRspnsCountries'] ?? [];
if (!empty($isoCodeArray) && is_array($isoCodeArray)) {
    if (isset($geography)) {
        $geo = $geography;
    }
    if (!isset($geo)) {
        include_once __DIR__ . '/../../includes/php/Models/Globals/Geography.php';
        $geo = \Legacy\Models\Globals\Geography::getVersionInstance(null, null, (int)($_SESSION['clientID'] ?? 1));
    }
    $countryNames = $geo->getCountryNames($isoCodeArray); // also translates the names
    echo implode(', ', $countryNames);
}
