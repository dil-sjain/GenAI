<?php
/**
 * Controller: admin tool ManageAppFeatures
 */

namespace Controllers\TPM\Admin\Features;

use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Admin\Features\ManageAppFeaturesData;
use Lib\Support\UserLock;
use Lib\Traits\AjaxDispatcher;

/**
 * Handles requests and responses for admin tool ManageAppFeatures
 */
class ManageAppFeatures extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View (see: Base::getTemplate())
     */
    protected $tplRoot = 'TPM/Admin/Features/';

    /**
     * @var string Base template for View (Can also be an array. see: Base::getTemplate())
     */
    protected $tpl = 'ManageAppFeatures.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;

    /**
     * @var object JSON response template
     */
    private $jsObj = null;

    /**
     * @var object Model instance
     */
    private $m = null;

    /**
     * Constructor gets model instance and initializes other properties
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->app  = \Xtra::app();
        $userLock = new UserLock($this->app->session->authUserPwHash, $this->app->session->authUserID);
        $this->canAccess = $userLock->hasAccess('ManageAppFeatures');
        $this->m = new ManageAppFeaturesData();

        // Remove this upon completion of development.
        $this->showPostValsFor = ['testLink2'];
        $this->ajaxExceptionLogging = ($this->app->mode == 'Development');
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        if (!$this->canAccess) {
            UserLock::denyAccess();
        }
        $this->setViewValue('appsList', $this->m->getAppsList());
        $this->setViewValue('ftrGroupList', $this->m->getGroupList());
        $this->setViewValue('ftrList', $this->m->getFeatureList(-1, -1));
        $this->setViewValue('mngappftrCanAccess', $this->canAccess);
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * Insert or update feature from feature dialog values
     *
     * @return void
     */
    private function ajaxUpsertFeature()
    {
        $ftrID = (int)\Xtra::arrayGet($this->app->clean_POST, 'fi', 0);
        $grpID = (int)\Xtra::arrayGet($this->app->clean_POST, 'g', 0);
        $appSel = (int)\Xtra::arrayGet($this->app->clean_POST, 'asel', 0); // for feature list refresh
        $grpSel = (int)\Xtra::arrayGet($this->app->clean_POST, 'gsel', 0); // for feature list refresh
        $ftrName = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'n', ''));
        $ftrCodeKey = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'ck', ''));
        $ftrDesc = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'd', ''));
        $inApps = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'a', ''));
        [$rtnID, $err] = $this->m->upsertFeature($ftrID, $grpID, $ftrName, $ftrCodeKey, $ftrDesc, $inApps);
        if (!empty($err)) {
            if (is_array($err)) {
                $msg = $this->app->view->fetch('Widgets/UnorderedListFromIndexed.tpl', ['arrayList' => $err]);
            } else {
                $msg = $err;
            }
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $msg;
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->FuncName = 'appNS.mngappftr.afterFtrMod';
            $this->jsObj->Args = [
                $this->m->getFeatureList($appSel, $grpSel),
            ];
            $this->jsObj->AppNotice = ['Feature ' . (($grpID > 0) ? 'updated': 'added')];
        }
    }

    /**
     * Disable (deactivate) feature. Removes from all applicationFeatures
     *
     * @return void
     */
    private function ajaxDisableFeature()
    {
        $ftrID = (int)\Xtra::arrayGet($this->app->clean_POST, 'fi', 0);
        $appSel = (int)\Xtra::arrayGet($this->app->clean_POST, 'asel', 0); // for feature list refresh
        $grpSel = (int)\Xtra::arrayGet($this->app->clean_POST, 'gsel', 0); // for feature list refresh
        [$rtnID, $err] = $this->m->disableFeature($ftrID);
        if (!empty($err)) {
            if (is_array($err)) {
                $msg = $this->app->view->fetch('Widgets/UnorderedListFromIndexed.tpl', ['arrayList' => $err]);
            } else {
                $msg = $err;
            }
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $msg;
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->FuncName = 'appNS.mngappftr.afterFtrMod';
            $this->jsObj->Args = [
                $this->m->getFeatureList($appSel, $grpSel),
            ];
            $this->jsObj->AppNotice = ['Feature ' . (($grpID > 0) ? 'updated': 'added')];
        }
    }

    /**
     * Get values needed for feature dialog
     *
     * @return void
     */
    private function ajaxGetFeatureDetail()
    {
        $ftrID = (int)\Xtra::arrayGet($this->app->clean_POST, 'fi', 0);
        $detail = $this->m->getFeatureDetail($ftrID);
        if (empty($detail['rec'])) {
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = 'Invalid feature reference.';
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->FuncName = 'appNS.mngappftr.loadFtrDiag';
            $this->jsObj->Args = [$detail];
        }
    }

    /**
     * Get filtered feature list
     *
     * @return void
     */
    private function ajaxListFeatures()
    {
        $appID = (int)\Xtra::arrayGet($this->app->clean_POST, 'a', 0);
        $grpID = (int)\Xtra::arrayGet($this->app->clean_POST, 'g', 0);
        $features = $this->m->getFeatureList($appID, $grpID);
        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.mngappftr.refreshFtrList';
        $this->jsObj->AppNotice = ['Features found: ' . count($features)];
        $this->jsObj->Args = [
            $features,
        ];
    }

    /**
     * Upsert group name
     *
     * @return void Sets jsObj
     */
    private function ajaxSaveGrp()
    {
        $this->modGroup('upsert', 'Save');
    }

    /**
     * Delete group name
     *
     * @return void Sets jsObj
     */
    private function ajaxDeleteGrp()
    {
        $this->modGroup('delete', 'Delete');
    }

    /**
     * Update, Insert or Delete group name
     *
     * @param string $op        operation to perform
     * @param string $errPrefix Prefix for error title
     *
     * @return array Sets jsObj
     */
    private function modGroup($op, $errPrefix)
    {
        $grpID = (int)\Xtra::arrayGet($this->app->clean_POST, 'i', 0);
        $grpName = trim((string) \Xtra::arrayGet($this->app->clean_POST, 'n', null));
        $rtnID = 0;
        $method = $op . 'Group';
        if (method_exists($this->m, $method)) {
            [$rtnID, $err] = $this->m->$method($grpID, $grpName);
        } else {
            $errPrefix = 'Unrecogized';
            $err = 'Unknown operation.';
        }
        if (!empty($err)) {
            $this->jsObj->ErrTitle = $errPrefix . ' Operation Failed';
            $this->jsObj->ErrMsg = $err;
            return;
        }
        $this->jsObj->Result = 1; // success
        $this->jsObj->FuncName = 'appNS.mngappftr.refreshGrpList';
        $this->jsObj->Args = [
            $rtnID,
            $this->m->getGroupList(),
            (($grpID > 0) ? 1 : 0), // refresh features on all ops except insert
        ];
    }
}
