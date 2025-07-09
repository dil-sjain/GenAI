<?php
/**
 * Site Notices controller
 */

namespace Controllers\TPM\Settings\Notices;

use Models\TPM\Settings\Notices\NoticesData;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;

/**
 * SiteNotices controller
 *
 * @keywords site, notices, settings
 */
#[\AllowDynamicProperties]
class Notices
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/Notices/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'Notices.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object Notices data model instance
     */
    protected $data = null;

    /**
     * Sets SiteNotice template to view
     *
     * @param integer $tenantID   delta tenantID
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $this->baseCtrl = new Base($tenantID, $initValues);
        $this->data = new NoticesData($tenantID);
        $this->app  = \Xtra::app();
    }

    /**
    * importTableData - pull records to manage import fields
    *
    * @return void
    */
    private function ajaxDisplayNotices()
    {
        $notices  = $this->data->displayNotices();
        $noActive = (!$notices['activeNotices']) ? $notices['content'] : '';
        unset($notices['activeNotices']);
        $this->baseCtrl->setViewValue('notices', $notices);
        $this->baseCtrl->setViewValue('noActiveNotices', $noActive);

        // get the fully rendered html from the template
        $html = $this->app->view->fetch($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());
        $this->jsObj->Args   = ['html' => $html];
        $this->jsObj->Result = 1;
    }
}
