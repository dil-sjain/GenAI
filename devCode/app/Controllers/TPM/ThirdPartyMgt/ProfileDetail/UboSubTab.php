<?php
/**
 * Provide responses to requests from user interaction on UBO sub-tab
 */

namespace Controllers\TPM\ThirdPartyMgt\ProfileDetail;

use Models\TPM\ThirdPartyMgt\ProfileDetail\UboSubTabData;
use Models\TPM\UboDnBApi;
use Skinny\Skinny;
use Skinny\Log;
use Lib\Traits\AjaxDispatcher;
use Lib\Support\Xtra;
use Exception;

#[\AllowDynamicProperties]
class UboSubTab
{
    use AjaxDispatcher;

    /**
     * @var Skinny Instance of PHP framework
     */
    protected Skinny $app;

    /**
     * @var UboSubTabData Class instance for data access
     */
    protected UboSubTabData $data;

    /**
     * @var UboDnBApi Class instance for data access
     */
    protected UboDnBApi $uboDnBApi;

    /**
     * @var int thirdPartyProfile.id
     */
    protected int $tpID;

    /**
     * Instantiate class and set instance properties
     *
     * @param int $clientID TPM tenant ID
     */
    public function __construct(protected int $clientID)
    {
        $this->app = Xtra::app();
        $this->data = new UboSubTabData($clientID);
        $this->uboDnBApi = new UboDnBApi();
        $this->tpID = (int)$this->app->session->get('currentID.3p', 0);
    }

    /**
     * Initialize UI using Smarty template
     *
     * @return void
     *
     * @throws Exception
     */
    public function initialize(): void
    {
        // max DUNS reached?
        $maxReached = $this->data->usedMaxDuns();
        // does this profile have a DUNS number?
        $assignedDuns = $this->data->getAssignedDuns($this->tpID);
        $tpHasDuns = !empty($assignedDuns);
        $whichPage = $tpHasDuns ? 'show-ubo' : 'set-duns';
        $company = [
            'name' => '(unavailable)',
            'address' => '(unavailable)',
        ];
        if ($assignedDuns) {
            if ($entity = $this->data->getEntityByDuns($assignedDuns)) {
                $company = $entity;
            }
        }
        $uboData = [
            'company' => $company,
            'duns' => $assignedDuns,
            'updated' => false,
        ];

        $reloading = $uboNewVersion = $uboVersionButton = false;
        $viewVersion = '';
        if ($tpHasDuns) {
            //get user last viewed version
            $userID = $this->app->session->get('authUserID');
            $viewedVersion = $this->data->getUserViewedVersion($assignedDuns, $userID);
            $viewedVersion = $viewedVersion ?: 1;
            //get latest ubo version
            $uboVersion = $this->data->getLatestDunsVersion($assignedDuns);
            $viewVersion = $uboVersion['viewVersion'];
            if ($viewedVersion !== $uboVersion['version']) {
                $uboNewVersion = true;
                $this->app->session->set('previousUboViewedVersion', $viewedVersion);
            } else {
                $this->app->session->set('previousUboViewedVersion', -1);
            }
            if ($uboVersion['version'] > 1) {
                $uboVersionButton = true;
            }
        }
        $templateVars = compact(
            'whichPage',
            'maxReached',
            'tpHasDuns',
            'uboData',
            'viewVersion',
            'reloading',
            'uboNewVersion',
            'uboVersionButton'
        );
        $this->app->render('TPM/ThirdPartyMgt/ProfileDetail/UboTab.tpl', $templateVars);
    }

    /**
     * Get HTML for the UBO search dialog
     *
     * @return void
     */
    private function ajaxGetSearchHtml(): void
    {
        // Get 3P name, address, city, country
        $html = $this->data->getDialogInformation($this->tpID);
        if ($html) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [$html];
        } else {
            $this->jsObj->ErrTitle = 'Error';
            $this->jsObj->ErrMsg = 'Error in loading results. Please contact system administrator.';
        }
    }

    /**
     * Get Entity list from D&B name search API
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxGetEntityList()
    {
        // Values for API name search
        $companyName = $this->getPostVar('name', '');
        $address = $this->getPostVar('address', '');
        $city = $this->getPostVar('city', '');
        $country = $this->getPostVar('country', ''); // already filtered for true ISO to use in D&B API
        $results = $this->data->getEntityList($companyName, $address, $city, $country);
        if ($results) {
            $this->jsObj->Result = 1;
            $cardsHtml = $this->app->view->fetch(
                'TPM/ThirdPartyMgt/ProfileDetail/UboEntityList.tpl',
                ['entities' => $results['entities']]
            );
            $this->jsObj->Args = [$cardsHtml, $results['totalResult']];
        } else {
            $this->jsObj->ErrTitle = 'Nothing to Show';
            $this->jsObj->ErrMsg = 'The search returned no entities.';
        }
    }

    /**
     * Get full HTML to replace all tab content
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxReload(): void
    {
        $duns = $this->getPostVar('duns', '');
        $maxReached = $this->data->usedMaxDuns();
        $tpHasDuns = !empty($duns);
        $whichPage = $duns ? 'show-ubo' : 'set-duns';
        if (!($company = $this->data->getEntityByDuns($duns))) {
            // template requires something
            $company = [
                'name' => '(unavailable)',
                'address' => '(unavailable)',
            ];
        }

        // get ubo from api, but don't return it
        try {
            if ($duns && $this->data->saveDunsToThirdParty($duns)) {
                // Get records for UBO table?
                $this->uboDnBApi->getInitialUboData($duns, $this->clientID);
            }
        } catch (Exception $e) {
            $this->jsObj->ErrTitle = 'UBO Records Unavailable';
            $this->jsObj->ErrMsg = 'Failed loading UBO data.';
            return;
        }
        $uboData = [
            'company' => $company,
            'duns' => $duns,
            'updated' => false,
        ];
        $reloading = true;
        //get latest ubo version
        $uboVersion = $this->data->getLatestDunsVersion($duns);
        $viewVersion = $uboVersion['viewVersion'] ?? '';
        $templateVars = compact('viewVersion', 'whichPage', 'maxReached', 'tpHasDuns', 'uboData', 'reloading');
        $fullHtml = $this->app->view->fetch('TPM/ThirdPartyMgt/ProfileDetail/UboTab.tpl', $templateVars);
        $this->jsObj->Redirect = "https://" . getenv('hostName')
            . '/cms/thirdparty/thirdparty_home.sec?tname=thirdPartyFolder&pdt=ubo&id=' . $this->tpID;
    }

    /**
     * Get entity, name and address by DUNS number
     *
     * @return void
     */
    private function ajaxGetEntityByDuns(): void
    {
        $entity = '';
        $duns = $this->getPostVar('duns', '');
        try {
            if ($duns) {
                $entity = $this->data->getEntityByDuns($duns);
            }
        } catch (Exception $e) {
            Xtra::track(
                [
                    'Location' => $e->getFile() . ':' . $e->getLine(),
                    'Error' => $e->getMessage(),
                ],
                Log::ERROR
            );
        }
        if ($entity) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [$duns, $entity];
        } else {
            $this->jsObj->ErrTitle = 'Invalid D-U-N-S Number';
            $this->jsObj->ErrMsg = "No entity was found for D-U-N-S number `$duns`";
        }
    }

    /**
     * Update user's last viewed version to latest version
     *
     * @param string $duns DnB entity identifier
     *
     * @return bool Not very important if it fails
     *
     * @throws Exception
     */
    private function updateViewedVersion(string $duns): bool
    {
        $result = false;
        $dunsVersion = $this->data->getLatestDunsVersion($duns)['version'] ?? 0;
        $userID = $this->app->session->get('authUserID');
        $viewedVersion = $this->data->getUserViewedVersion($duns, $userID);
        if ($dunsVersion > 0 && $dunsVersion > $viewedVersion) {
            $result = $this->data->insertUserViewedVersion($userID, $duns, $dunsVersion);
        }
        return $result;
    }

    /**
     * Get UBO records for UBO sub-tab. Also updates user viewed version of UBO data
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxGetUboRecords(): void
    {
        $updateViewedVersion = (bool)$this->getPostVar('updateViewedVersion', 0);
        $tracking = $this->getPostVar('tracking', []);
        $uboRecords = [];
        $errTitle = $errMsg = '';
        if ($assignedDuns = $this->data->getAssignedDuns($this->tpID)) {
            // Update viewed version?
            if ($updateViewedVersion) {
                $this->updateViewedVersion($assignedDuns);
            }
            // Get records for UBO table?
            $uboRecords = $this->uboDnBApi->chunkLatestUboRecords($assignedDuns, $tracking);
            if (!empty($uboRecords['error'])) {
                $errTitle = "Unexpected Error";
                $errMsg = $uboRecords['error'];
            }
        } else {
            $errTitle = 'UBO Records Unavailable';
            $errMsg = 'No D-U-N-S number has been assigned.';
        }
        if ($errMsg) {
            $this->jsObj->ErrTitle = $errTitle;
            $this->jsObj->ErrMsg = $errMsg;
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [$uboRecords];
        }
    }

    /**
     * Get HTML for the UBO version change dialog
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxGetVersionUpdateHtml(): void
    {
        $duns = $this->data->getAssignedDuns($this->tpID);
        $defaultOlderVersion = $this->app->session->get('previousUboViewedVersion', -1);

        // Make dialog as wide as base UBO table + 100 for version
        $baseTableWidth = (int)($this->getPostVar('uboTableWidth') ?? 0) + 100;
        if ($baseTableWidth < 1200) {
            $baseTableWidth = 1200;
        }
        if ($duns) {
            //get user last viewed version
            $userID = $this->app->session->get('authUserID');
            $viewedVersion = $this->data->getUserViewedVersion($duns, $userID);
            $viewedVersion = (empty($viewedVersion)) ? 1 : $viewedVersion;
            //get latest ubo version
            $allUboVersions = $this->uboDnBApi->getFullUboVersionList($duns);
            $uboVersion = $allUboVersions[0]['version'] ?? 0;
            //history data b/w latest UBO and last viewed version
            if ($viewedVersion == $uboVersion) {
                $uboData = null;
            } else {
                $uboData = $this->uboDnBApi->compareUboVersions($duns, $viewedVersion, $uboVersion);
            }
            $templateVars = compact(
                'uboData',
                'viewedVersion',
                'allUboVersions',
                'baseTableWidth',
                'duns',
                'defaultOlderVersion'
            );
            $html = $this->app->view->fetch('TPM/ThirdPartyMgt/ProfileDetail/UboDataChangeDialog.tpl', $templateVars);
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [$html];
        } else {
            $this->jsObj->ErrTitle = 'Invalid D-U-N-S Number';
            $this->jsObj->ErrMsg = "No D-U-N-S number found for selected profile.";
        }
    }

    /**
     * Get Data for the UBO version change
     *
     * @return void
     */
    private function ajaxGetVersionUpdateData(): void
    {
        $tracking = $this->getPostVar('tracking', []);
        $duns = $this->data->getAssignedDuns($this->tpID);
        if ($duns) {
            $newVersion = (int)$this->getPostVar('currentVersion', '');
            $oldVersion = (int)$this->getPostVar('oldVersion', '');
            $uboData = $this->uboDnBApi->compareUboVersions($duns, $oldVersion, $newVersion, $tracking);
            if (!empty($uboData['error'])) {
                $this->jsObj->ErrTitle = 'Error';
                $this->jsObj->ErrMsg = $uboData['error'];
            } else {
                $this->jsObj->Result = 1;
                $this->jsObj->Args = [$uboData];
            }
        } else {
            $this->jsObj->ErrTitle = 'Invalid D-U-N-S Number';
            $this->jsObj->ErrMsg = "No D-U-N-S number found for selected profile.";
        }
    }
}
