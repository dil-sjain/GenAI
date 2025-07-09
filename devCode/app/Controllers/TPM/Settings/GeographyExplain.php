<?php
/**
 * Provide information on Geography ISO 3166 update
 */

namespace Controllers\TPM\Settings;

use Controllers\ThirdPartyManagement\Base;
use Models\TPM\Settings\GeographyExplain as Data;
use Lib\Traits\AjaxDispatcher;
use Skinny\Skinny;
use Models\Globals\Geography;
use Exception;
use Lib\Support\Xtra;

class GeographyExplain extends Base
{
    use AjaxDispatcher;

    /**
     * @var int TPM tenant identifier
     */
    protected $clientID;

    /**
     * @var Skinny Class instance
     */
    protected Skinny $app;

    /**
     * @var Data Class instance
     */
    protected Data $data;

    /**
     * Instantiate class and initialize properties
     *
     * @param int $clientID TPM tenant identifier
     */
    public function __construct(int $clientID)
    {
        $this->app = Xtra::app();
        parent::__construct($clientID);
        $this->data = new Data();
    }

    /**
     * Initial page display and setup
     *
     * @return void
     *
     * @throws Exception
     */
    public function initialize(): void
    {
        $this->setViewValue('pgTitle', 'Countries and subdivisions');
        $this->setViewValue('canAccess', true);
        $this->setViewValue('countries', $this->data->getLegacyCountries());
        $this->app->render('TPM/Settings/Geography/GeographyExplain.tpl', $this->getViewValues());
    }

    /**
     * Get subdivision data for a country
     *
     * @return void
     */
    protected function ajaxSubdivisionsByCountry(): void
    {
        $isoCode = $this->getPostVar('iso', '');
        $geo = Geography::getVersionInstance();
        $subs = $geo->stateList($isoCode);
        $subCount = count($subs);
        $details = $this->data->getSubdivisionDetails($isoCode);
        $parentCount = 0;
        foreach ($details as $detail) {
            if ($detail['isParent']) {
                $parentCount++;
            }
        }
        $countryName = $geo->getLegacyCountryName($isoCode);
        $subHtml = $this->app->view->fetch(
            'TPM/Settings/Geography/GeographyExplainSubDetails.tpl',
            ['subs' => $subs, 'details' => $details]
        );
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [$isoCode, $countryName, $subHtml, $subCount, $parentCount];
    }
}
