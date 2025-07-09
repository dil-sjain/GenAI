<?php
/**
 * MessageCenter controller
 *
 * @keywords MessageCenterData, message center, data, messages
 */

namespace Controllers\TPM\Settings\ContentControl\MessageCntr;

use Models\TPM\Settings\ContentControl\MessageCenter\MessageCenterData;
use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\LogData as myLD;
use Lib\IO as IO;
use Models\Globals\TxtTr;

/**
 * Class allowing certain users to update and save messages in the Message Center tab
 * under Content Control Settings that will reflect in the side bar as well.
 *
 */
#[\AllowDynamicProperties]
class MessageCenter
{

    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/MessageCenter/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'MessageCenter.tpl';

    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object Base controller instance
     */
    protected $baseCtrl = null;

    /**
     * @var object Model instance
     */
    protected $msgctr = null;

    /**
     * @var integer Delta tenantID
     */
    protected $tenantID = 0;

    /**
     * @var string The route for ajax calls in the js namespace.
     */
    protected $ajaxRoute = '/tpm/cfg/cntCtrl/msgCntr';

    /**
     * * @var array of wysiwig editor features
     */
    protected $editorFeatures = [
        'font',
        'size',
        '|',
        'bold',
        'italic',
        'underline',
        '|',
        'color',
        'background',
        '|',
        'ul',
        'ol',
    ];

    /**
     * Sets message center specific private variables
     * Note that editor features may be added or removed
     * with sections being separated by a '|' (pipe)
     *
     * @param integer $tenantID   delta tenantID
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;
        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $this->baseCtrl = new Base($this->tenantID, $initValues);
        $this->app  = \Xtra::app();
        $this->msgctr = new MessageCenterData($this->tenantID);
    }

    /**
     * Sets message center view and template values
     *
     * @return void
     */
    private function ajaxInitialize()
    {
        // Allow html editing if app is admin
        if ($this->app->ftr->appIsADMIN()) {
            $this->editorFeatures[] = '|';
            $this->editorFeatures[] = 'clean';
            $this->editorFeatures[] = 'html';
        }
        // Obtain translatable text relative to the message center
        $msgctrTrText = $this->app->trans->group('msgctr');
        $msgctrTrBtns = $this->app->trans->codeKeys(['update', 'reset']);
        // Set translatable text and editor features in the view
        $this->baseCtrl->setViewValue('msgctrTrText', $msgctrTrText);
        $this->baseCtrl->setViewValue('msgctrTrBtns', $msgctrTrBtns);
        $this->baseCtrl->setViewValue('editorFeatures', $this->printEditorFeatures());
        $this->baseCtrl->setViewValue('ajaxRoute', $this->ajaxRoute);
        $html = $this->app->view->fetch($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());
        $this->jsObj->Args   = ['html' => $html];
        $this->jsObj->Result = 1;
    }

    /**
     * get MessageCenterData - ajax get initial values for the view
     *
     * @return void
     */
    private function ajaxGetMessageCenterData()
    {
        $msgCtrData = $this->msgctr->getMessageCenterData();
        $this->jsObj->Args   = [$msgCtrData['msgCenterContent']];
        $this->jsObj->FuncName = 'appNS.msgctr.displayMessageCenterData';
        $this->jsObj->Result = 1;
    }

   /**
    * changeMessageCenterData - ajax process status of changed data
    *
    * @return void
    */
    private function ajaxChangeMessageCenterData()
    {
        $html = trim((string) $this->app->request->post('msgCtrHtml'));

        $sanitizedHtml = (($this->app->ftr->appIsADMIN()) ?
                IO::sanitizeInput($html, true, ['a']) : IO::sanitizeInput($html, true));

        $msgCtrData = $this->msgctr->changeMessageCenterData($sanitizedHtml);
        $this->logDataChange($msgCtrData['msg']);

        $this->jsObj->Result    = ($msgCtrData['Error'] == '') ? 1 : 0;
        $this->jsObj->ErrTitle  = $msgCtrData['Error'];
        $this->jsObj->ErrMsg    = $msgCtrData['ErrorMsg'];
        $this->jsObj->Args      = [$msgCtrData['html']];
        $this->jsObj->AppNotice = [$msgCtrData['msg']];
    }

    /**
     * printEditorFeatures - returns a string jqxEditor tool options
     *
     * @return string features for jqxeditor options in string format
     */
    protected function printEditorFeatures()
    {
        $string = "";
        foreach ($this->editorFeatures as $ef) {
            $string .= $ef . " ";
        }
        return trim($string);
    }

    /**
     * Setup logging and log messages as required.
     *
     * @param string $logMsg Message to be logged.
     *
     * @return void
     */
    protected function logDataChange($logMsg)
    {
        $userType = $this->app->ftr->legacyUserType;
        $userID = $this->app->ftr->user;
        $logData = new myLD($this->tenantID, $userID, $userType);
        $logData->saveLogEntry(16, $logMsg, 0, 1, $this->tenantID, $userID, $userType);
    }
}
