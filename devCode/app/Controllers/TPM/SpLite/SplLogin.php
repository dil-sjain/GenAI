<?php
/**
 * SpLite Login controller
 *
 * @keywords splite, splite login, passcode, validate passcode
 */

namespace Controllers\TPM\SpLite;

use Controllers\ThirdPartyManagement\Base;
use Lib\ResourceManager\Manager;
use Lib\Traits\AjaxDispatcher;
use Models\ThirdPartyManagement\ClientProfile;
use Models\TPM\SpLite\SpLite;

/**
 * SplLogin controller
 */
class SplLogin extends Base
{
    use AjaxDispatcher;

    public const CLIENTID_PRESCO = 79;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/SpLite/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'SplLogin.tpl';

    /**
     * @var object Application instance
     */
    private $app = null;


    /**
     * Constructor for Login (passcode) page for SpLite application.
     *
     * Note: At the time of instantiation of this class, SplLogin does not
     * have a Client ID, so for the purposes of the constructor, the Client ID
     * value is not used.
     *
     * @param integer $clientID   Client ID
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, ['isSpLite' => true]);
        $this->app = \Xtra::app();
        $this->resources = new Manager();
        $this->session = $this->app->session;
        $this->setCommonViewValues();

        $sitePath = $this->app->sitePath;
        $this->removeFileDependency($sitePath . 'assets/js/ThirdPartyManagement/skel/skel_subscriberSwitch.js');
        $this->removeFileDependency($sitePath . 'assets/js/ThirdPartyManagement/skel/skel_userSwitch.js');
    }

    /**
     * Set vars on page load
     *
     * @return void
     */
    public function initialize()
    {
        $this->app->view->display($this->getTemplate(), $this->getViewValues());
    }

    /**
     * Set common view values for display the full Main layout.
     * Values are provided for app header, sidebar and footer.
     * These override what is initially set by Base, thus the force option is applied.
     *
     * @return void
     */
    private function setCommonViewValues()
    {
        $this->setViewValue('isSP', null, 1);
        $this->setViewValue('recentlyViewed', [], 1);
        $this->setViewValue('tabData', [], 1);
        $this->setViewValue('colorScheme', 0, 1);
        $this->setViewValue('logo', $this->getLogo(self::CLIENTID_PRESCO));
    }


    /**
     * Send the test string containing tags off for processing
     *
     * @return void sets jsObj
     */
    private function ajaxSplAuthenticate()
    {
        $key = \Xtra::arrayGet($this->app->clean_POST, 'ky', 0);
        $passCode = \Xtra::arrayGet($this->app->clean_POST, 'passCode', 0);

        $spl = new SpLite();
        $result = $spl->authByUrl($key);
        // In dev, always allow 'splite' for company passcode
        if ($_ENV['env'] === 'Development' && strtolower((string) $passCode) === 'splite') {
            $spID = $spl->authRow->spID;
            $this->session->set('spLiteCoPw', $spID);
        } else {
            $spID = $spl->validatePasscode($passCode, $spl->authRow);
        }
        $caseStatus = $spl->getCaseStatus($spl);
        $caseStatus = $spl->prepareCase($spl, $caseStatus);

        if ($result && $spID && $caseStatus && empty($caseStatus['invalidAccess'])) {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = ['authorized' => $result, 'params' => $this->getRedirectParams()];
        } else {
            $this->jsObj->ErrTitle = 'Operation Failed';
            $this->jsObj->ErrMsg = $caseStatus['badAccessMsg'];
        }
    }

    /**
     * Get the client logo to render, however, for SpLite it's always going to be the PrresCo logo
     *
     * @param integer $clienID Client ID
     *
     * @return mixed|Client logo file name or null
     */
    private function getLogo($clienID)
    {
        $cpMdl = new ClientProfile(['clientID' => $clienID]);
        $client = $cpMdl->findById($clienID);

        return $client->get('logoFileName');
    }

    /**
     * Set common view values for display the full Main layout.
     * Values are provided for app header, sidebar and footer
     *
     * @return string $params Rebuilt URL params as a string to use for redirection
     */
    private function getRedirectParams()
    {
        $params      = null;
        $urlParams   = $this->app->clean_POST;
        $validParams = ['ky', 'a', 'r'];

        foreach ($urlParams as $key => $value) {
            if (in_array($key, $validParams)) {
                $params .= $params ? "&$key=$value" : "?$key=$value";
            }
        }

        return $params;
    }
}
