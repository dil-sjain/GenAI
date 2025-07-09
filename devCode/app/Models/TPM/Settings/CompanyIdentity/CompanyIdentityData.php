<?php
/**
 * File containg class that provides Access data needed to manipulate and read the company's
 * chosen look and feel within securimate.
 *
 * @keywords company, identity, settings, logo, theme, color
 */
namespace Models\TPM\Settings\CompanyIdentity;

use Models\TPM\Settings\CompanyIdentity\LogoSubmodel;
use Models\TPM\Settings\CompanyIdentity\SchemeSubmodel;
use Models\TPM\Settings\CompanyIdentity\NamesSubmodel;

/**
 * Provides Access data needed to manipulate and read the company's
 * chosen look and feel within securimate.
 *
 */
#[\AllowDynamicProperties]
class CompanyIdentityData
{
    /**
     * @var object Reference to global app
     */
    protected $app;

    /**
     * @var object Submodel for logos - instance
     */
    protected $logoSubmodel;

    /**
     * @var object Submodel for schemes - instance
     */
    protected $schemeSubmodel;

    /**
     * @var object Submodel for names - instance
     */
    protected $namesSubmodel;

    /**
     * @var string db name
     */
    protected $namesDB;

    /**
     * @var tableName
     */
    protected $tableName = 'clientProfile';

    /**
     * @var array Array of profile fields used in the submodels.
     */
    protected $profileFields = [
        'scheme' => 'ddqColorScheme, siteColorScheme',
        'name'   => 'clientName', // investigatorName
    ];

    /**
     * Constructor - initialization
     *
     * @param type $tenantID tenant id
     * @param type $userType type of current logged in user
     * @param type $userID   id of current logged in user
     *
     * @return void
     */
    public function __construct(protected $tenantID, $userType, protected $userID)
    {
        $this->app = \Xtra::app();
        $this->DB  = $this->app->DB;
        $this->userType = $userType;
        $this->namesDB  = $this->DB->getClientDB($this->tenantID);

        $subModelParams = [
            'table'     => $this->namesDB .'.'. $this->tableName,
            'userID'    => $this->userID,
            'loggingID' => $this->tenantID,
            'fileTag'   => 'cid'. $this->tenantID,
            'fields'    => $this->profileFields,
        ];

        $this->namesSubmodel = new NamesSubmodel($this->tenantID, $subModelParams);
        $this->logoSubmodel = new LogoSubmodel($this->tenantID, $subModelParams);
        $this->schemeSubmodel = new SchemeSubmodel($this->tenantID, $subModelParams); // profileFields
    }

    /**
     * Return initial data probably to be used in view
     *
     * @return void
     */
    public function initialData()
    {
        $nameData = $this->namesSubmodel->getNames();
        $schemeData = $this->schemeSubmodel->getSchemes();
        $initLogo = $this->logoSubmodel->initHasLogo();

        return array_merge(
            $nameData,
            $schemeData,
            $initLogo
        );
    }

    /**
     * Rename the uploaded file with something predictable
     * with respect to this specified company.
     *
     * This file overwrites the previously uploaded logo
     * preview if it exists.
     *
     * @param string $uploadedFilename  Name of uploaded file
     * @param string $uploadedExtension Extension of uploaded file
     *
     * @return boolean True if no show-stopping problems are encountered
     *
     * @throws \Exception If invalid file extension is passed
     */
    public function publishLogoPreview($uploadedFilename, $uploadedExtension)
    {
        return $this->logoSubmodel->publishLogoPreview($uploadedFilename, strtolower($uploadedExtension));
    }

    /**
     * delete any logo previews for this client
     *
     * @return string deleted file names
     */
    public function unlinkLogoPreviews()
    {
        return $this->logoSubmodel->unlinkLogoPreviews();
    }

    /**
     * return assoc. array (ultimately for consumption by the view)
     * indicating whether current client / vendor has a logo preview
     *
     * @return array
     */
    public function hasLogoPreview()
    {
        return $this->logoSubmodel->initHasLogo();
    }

    /**
     * get list of preview files w. paths
     *
     * @return array Strings; file paths
     */
    public function getPreviewPaths()
    {
        return $this->logoSubmodel->getPreviewPaths();
    }

    /**
    * apply logo preview
     *
    * @param type $fullLogoPath     path of logo prev
    * @param type $relativeLogoPath rel path of logo prev
    *
    * @return mixed $moved new filename
    */
    public function applyPreview($fullLogoPath, $relativeLogoPath)
    {
        return $this->logoSubmodel->applyPreview($fullLogoPath, $relativeLogoPath);
    }

    /**
     * return a list of valid preview extensions
     *
     * @return array
     */
    public function validPreviewExtensions()
    {
        return $this->logoSubmodel->validPreviewExtensions;
    }

    /**
     * update the specified scheme ('site'/'ddq') to the specified
     * value.
     *
     * @param string  $schemeType  'site' or 'ddq'
     * @param integer $schemeValue integer
     *
     * @return void
     */
    public function updateScheme($schemeType, $schemeValue)
    {
        $this->schemeSubmodel->updateScheme($schemeType, $schemeValue);
    }

    /**
     * get site color scheme
     *
     * @return integer
     */
    public function getSiteSchemeVal()
    {
        return $this->schemeSubmodel->getSchemeVal('site');
    }

    /**
     * validate company shortname and legal name
     *
     * @param array $names associative array of data to validate
     *
     * @return boolean
     */
    public function validateNames($names)
    {
        return $this->namesSubmodel->validateInput($names);
    }

    /**
     * update company shortname and legalname
     *
     * @param array   $names          assoc. array containing new names
     * @param boolean $skipValidation indicate whether to skip validation
     *                                (already done in controller, eg)
     *
     * @return void
     */
    public function updateNames($names, $skipValidation = false)
    {
        $this->namesSubmodel->updateNames($names, $skipValidation);
    }

    /**
     * set preview prefix
     *
     * @param string $prefix prefix to use
     *
     * @return void
     */
    public function setPreviewPrefix($prefix)
    {
        $this->logoSubmodel->setFilePrefix($prefix);
    }

    /**
     * set preview regex
     *
     * @param string $regex regular expression
     *
     * @return void
     */
    public function setPreviewRegex($regex)
    {
        $this->logoSubmodel->setFileRegex($regex);
    }
}
