<?php
/**
 * Company Identity controller - Contains the class that Provides
 * functionality involved with the Company Identity tab under
 * the settings tab.
 *
 * @keywords company, identity, settings, logo, them, color
 */

namespace Controllers\TPM\Settings\CompanyIdentity;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\Settings\CompanyIdentity\CompanyIdentityData;
use Models\TPM\Settings\CompanyIdentity\SchemeSubmodel;
use Controllers\ThirdPartyManagement\Admin\Subscriber\SyncLogos;
use Lib\SettingACL;

/**
 * Class Provides functionality involved with the
 * Company Identity tab under the settings tab.
 */
#[\AllowDynamicProperties]
class CompanyIdentity
{
    use AjaxDispatcher;

    /**
     * Base directory for View
     *
     * @var string
     */
    protected $tplRoot = 'TPM/Settings/CompanyIdentity/';

    /**
     * Base template for View
     *
     * @var string
     */
    protected $tpl = 'CompanyIdentity.tpl';

    /**
     * Routes passed to template for ajax operations
     *
     * @var array
     */
    protected $jsRoutes = [
        'main' => '/tpm/cfg/cmpnyId',
        'sc'   => '/tpm/cfg/',
    ];

    /**
     * Application instance
     *
     * @var object
     */
    protected $app = null;

    /**
     * Base class instance
     *
     * @var object
     */
    protected $baseCtrl  = null;

    /**
     * Number of digits in prev img cachebuster
     *
     * @var integer
     */
    public const NUM_PREV_DIGITS = 6;

    /**
     * Session key / msg for client color scheme just updated
     *
     * @var string
     */
    public const SITE_MSG_KEY = 'updateClientColorScheme';

    /**
     * Session key / msg for ddq client color scheme just updated
     *
     * @var string
     */
    public const DDQ_MSG_KEY = 'updateDdqClientColorScheme';

    /**
     * Session key / current site color scheme
     *
     * @var string
     */
    public const SITE_SCHEME_KEY = 'siteColorScheme';

    /**
     * Session key / current legacy intake form (A.K.A. DDQ) color scheme
     *
     * @var string
     */
    public const DDQ_SCHEME_KEY = 'intake.legacy.colorScheme';

    /**
     * Sets up Company Identity control
     *
     * @param integer $tenantID Delta tenantID (aka: clientProfile.id)
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        $this->app  = \Xtra::app();
        $this->legacyAuth();

        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];

        $this->tenantID = $tenantID;
        $this->baseCtrl = new Base($this->tenantID, $initValues);
        $this->model    = new CompanyIdentityData(
            $this->tenantID,
            $this->app->session->get('authUserType'),
            $this->app->session->get('authUserID')
        );
        
        $this->flagSet = filter_var(getenv("AWS_ENABLED"), FILTER_VALIDATE_BOOLEAN) && filter_var(getenv("HBI_ENABLED"), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Initializes the ajax-loaded company settings tab
     *
     * @return void
     */
    private function ajaxInitialize()
    {
        foreach ($this->model->initialData() as $k => $v) {
            $this->baseCtrl->setViewValue($k, $v);
        }
        $this->baseCtrl->setViewValue('jsRoutes', $this->jsRoutes);
        $this->baseCtrl->setViewValue(
            'subDirOptions',
            json_encode(
                (object)[
                'Company Logos' => 'Company Logos'
                ]
            )
        );

        $this->baseCtrl->setViewValue('subDirDefault', 'Company Logos');

        $this->baseCtrl->setViewValue(
            'validPreviewExtensions',
            json_encode($this->model->validPreviewExtensions())
        );
        $this->baseCtrl->setViewValue('uploadFilenameEnding', "upload");
        $this->baseCtrl->setViewValue('schemeCount', SchemeSubmodel::SCHEME_COUNT);

        $this->baseCtrl->setViewValue(
            'logoVisibleClass',
            $this->baseCtrl->getViewValue('hasLogoPrev') ? true : false
        );

        $logoSrc = 'cfg/cmpnyId/lgprev/'. (random_int(10 ** (self::NUM_PREV_DIGITS-1), 10 ** 6 - 1));
        $this->baseCtrl->setViewValue(
            'logoSrcAttr',
            $this->baseCtrl->getViewValue('hasLogoPrev') ? $logoSrc : ''
        );
        $this->baseCtrl->setViewValue('hidePrevContainer', 'coident-prev-hidden');
        $this->baseCtrl->setViewValue('schemeJustChanged', $this->getSchemeJustChanged());
        $this->forgetSchemeChanged();

        $this->baseCtrl->setViewValue(
            'isVendorAdmin',
            $this->app->session->get('authUserType') == \Lib\Legacy\UserType::VENDOR_ADMIN
        );
        $setting = (new SettingACL($this->tenantID))->get(
            SettingACL::MAX_UPLOAD_FILESIZE,
            ['lookupOptions' => 'disabled']
        );
        $uplMax = ($setting) ? $setting['value'] : 10; // Default to 10MB
        $this->baseCtrl->setViewValue('uplMaxSize', $uplMax);

        $this->baseCtrl->setViewValue('flagSet', $this->flagSet);


        $html = $this->app->view->fetch($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());

        $this->jsObj->Args = ['html' => $html];
        $this->jsObj->Result = 1;
    }

    /**
     * Remove old preview and make the new one visible
     *
     * @return void
     *
     * @throws \Exception If model fails to go through the proper procedures
     */
    private function ajaxPublishLogoPreview()
    {
        if ($this->flagSet) {
            header("HTTP/1.0 404 Not Found");
            exit();
        }
        
        $uploadedName = $this->app->clean_POST['upl'];
        $ext = $this->app->clean_POST['ext'];

        $this->model->unlinkLogoPreviews();
        $published = $this->model->publishLogoPreview($uploadedName, $ext);
        if (!$published) {
            throw new \Exception("Unable to publish image preview");
        } else {
            $this->jsObj->Args = [$uploadedName];
            $this->jsObj->Result = 1;
        }
    }

    /**
     * Serve the logo preview image
     *
     * @return void
     */
    public function logoPreview()
    {
        if ($this->flagSet) {
            header("HTTP/1.0 404 Not Found");
            exit();
        }
        $previewImageName = \Xtra::head($this->model->getPreviewPaths());
        if ($previewImageName && file_exists($previewImageName)) {
            $ext = pathinfo((string) $previewImageName)['extension'];
            $fsize = filesize($previewImageName);
            $mime = ($ext == 'jpg') ? 'image/jpeg' : "image/$ext";

            $this->app->response->headers->set('Content-Type', $mime);
            $this->app->response->headers->set('Content-Length', $fsize);

            readfile($previewImageName);
        }
    }

    /**
     * Delete logo preview in the case that user has rejected it.
     *
     * @return void
     */
    private function ajaxDeleteLogoPreview()
    {
        if ($this->flagSet) {
            header("HTTP/1.0 404 Not Found");
            exit();
        }

         $deleted = $this->model->unlinkLogoPreviews();
         $this->jsObj->Args = $deleted;
         $this->jsObj->Result = 1;
    }

    /**
    * Moves the preview to the client specific locale in which it will
    * be shown as the actual logo.
    * Updates the database with this information.
    * Sets JS Args in response to indicate success or failure.
    *
    * @return null
    */
    private function ajaxApplyLogoPreview()
    {
        if ($this->flagSet) {
            header("HTTP/1.0 404 Not Found");
            exit();
        }

        $fullLogoPath = \Xtra::conf('cms.docRoot') . SyncLogos::RELATIVE_LOGO_PATH;
        $fullLogoPath = str_replace('/public_html/public_html', '/public_html', $fullLogoPath);
        $newLogoFileName = $this->model->applyPreview($fullLogoPath, SyncLogos::RELATIVE_LOGO_PATH);
        $this->app->session->set('logoFileName', $newLogoFileName);
        $this->jsObj->Args = $newLogoFileName;
        $this->jsObj->Result = $newLogoFileName ? 1 : 0;
    }

    /**
     * Switch a scheme; switch either the ddq or site color scheme
     * in db and in session.
     *
     * @return void
     */
    private function ajaxSwitchScheme()
    {
        $newScheme = $this->getValidPostedScheme();
        $newSchemeVal = $this->getValidPostedSchemeVal();

        if ($newScheme && is_numeric($newSchemeVal)) {
            $this->model->updateScheme($newScheme, $newSchemeVal);
            $this->jsObj->Args = [$newScheme, $newSchemeVal];
            $this->jsObj->Result = 1;

            // Putting this where it will transfer to the legacy session
            // just in case.

            if ($newScheme === 'site') {
                $this->app->session->set(self::SITE_SCHEME_KEY, $newSchemeVal);
                $this->app->session->set(
                    self::SITE_MSG_KEY,
                    "Successfully set and recorded new site color scheme."
                );
            } elseif ($newScheme === 'ddq') {
                $this->app->session->set(self::DDQ_SCHEME_KEY, $newSchemeVal);
                $this->app->session->set(
                    self::DDQ_MSG_KEY,
                    "Successfully set and recorded new DDQ color scheme."
                );
            }
        }
    }

    /**
     * Update company names, both the legal and abbreviated names
     * where necessary according to POST data versus current data.
     *
     * @return void
     */
    private function ajaxUpdateNames()
    {
        if (!$this->flagSet) {
            $legalName = $this->app->clean_POST['legalName'];
        }
        
        $shortName = $this->app->clean_POST['shortName'];

        $names = $this->flagSet ? compact(['shortName']) : compact(['legalName', 'shortName']);

        $validationData = $this->model->validateNames($names);

        if ($validationData[0] === true) {
            $this->model->updateNames($names, true);
            $this->jsObj->Args = $names;
            $this->jsObj->Result = 1;
        } else {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = 'Invalid Data';
            $this->jsObj->ErrMsg = implode(';', $validationData[1]);
        }
    }

    /**
     * Collect numeral POST'd as new scheme number
     *
     * @return integer Only if valid
     */
    protected function getValidPostedSchemeVal()
    {
        $validate = function ($sch) {
            if ((string)(int)$sch != $sch || ($sch > (SchemeSubmodel::SCHEME_COUNT - 1) || $sch < 0)) {
                return false;
            }
            return true;
        };

        $scheme = $this->app->clean_POST['value'];
        return $validate($scheme) ? $scheme : null;
    }

    /**
     * Collect string (probably 'site'/'ddq') POST'd as new scheme
     * to change
     *
     * @return string Only if a string and nonempty
     */
    protected function getValidPostedScheme()
    {
        $validate = function ($sch) {
            if (!is_string($sch) || empty($sch)) {
                return false;
            }
            return true;
        };
        $scheme = $this->app->clean_POST['scheme'];
        return $validate($scheme) ? $scheme : null;
    }

    /**
     * If session indicates that a col scheme (either site/ddq)
     * was changed in the previous navigation to this page obtain
     * and return which it was.
     *
     * @return boolean|string bool false if no scheme was changed
     *                        during the preceding navigation/request
     */
    protected function getSchemeJustChanged()
    {
        $siteMsg = $this->app->session->get(self::SITE_MSG_KEY);
        $ddqMsg = $this->app->session->get(self::DDQ_MSG_KEY);

        if (!empty($siteMsg)) {
            return 'site';
        } elseif (!empty($ddqMsg)) {
            return 'ddq';
        }
        return false;
    }

    /**
     * Wipe session data for either scheme just having been
     * changed
     *
     * @return void
     */
    protected function forgetSchemeChanged()
    {
        $this->app->session->set(self::SITE_MSG_KEY, '');
        $this->app->session->set(self::DDQ_MSG_KEY, '');
    }

    /**
     * Last line of defense: implement legacy style authorization.
     *
     * @return void
     */
    protected function legacyAuth()
    {
        $ftr = $this->app->ftr;
        if (!$ftr->isLegacySuperAdmin()
            && !$ftr->isLegacyClientAdmin()
            && !$ftr->isLegacySpAdmin()
        ) {
            throw new \Exception('Not authorized');
        }
    }
}
