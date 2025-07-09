<?php
/**
 * AiServices controller
 *
 * @keywords Ai Assistant, Ai Article Summarisation, Ai Services, access, user settings
 */

namespace Controllers\TPM\Settings\AiServices;

use Models\TPM\Settings\AiServices\AiServicesData;
use Controllers\ThirdPartyManagement\Base;
use Lib\FeatureACL;
use Lib\Traits\AjaxDispatcher;
use Lib\Support\Xtra;
use Skinny\Skinny;

/**
 * Class enabling certain users to view their Ai Services
 *
 */
class AiServices extends Base
{
    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/AiServices/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'AiServices.tpl';

    /**
     * @var Skinny Application instance
     */
    private Skinny $app;

    /**
     * @var AiServicesData Class instance for data access
     */
    protected AiServicesData $model;

    /**
     * Sets up AiServices Access control
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = array())
    {
        $initValues['objInit'] = true;
        $initValues['vars'] = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $this->baseCtrl = new Base($clientID, $initValues);
        $this->model = new AiServicesData($clientID);
        $this->app  =  Xtra::app();
    }

    /**
     * ajaxInitialize - ajax set initial values for the view
     *
     * @return void
     */
    private function ajaxInitialize()
    {
        $AiServices = [
            [
                'featureNumber' => FeatureACL::AI_SUMMARISATION,
                'featureTitle' => 'AI Media Monitor Article Summarization',
                'featureDescription' => 'Summarises articles related to Media Monitor hits and enables a swifter<br/> response when identifying true and false hits.',
                'helpUrl' => 'https://help.highbond.com/helpdocs/third-party-manager/Content/third_party_manager/using_media_monitoring_ai_article_summarization.htm',
                'featureStatus' => $this->app->ftr->tenantHas(FeatureACL::AI_SUMMARISATION),
                'serviceConsent' => false,
                'serviceEnable' => false,
            ],
            [
                'featureNumber' => FeatureACL::AI_ASSISTANT,
                'featureTitle' => 'AI Virtual Assistant',
                'featureDescription' => 'Helps in navigating around complex tasks and enables a quick access to<br/> summarise information.',
                'helpUrl' => 'https://help.highbond.com/helpdocs/third-party-manager/Content/third_party_manager/using_ai_virtual_assistant.htm',
                'featureStatus' => $this->app->ftr->tenantHas(FeatureACL::AI_ASSISTANT),
                'serviceConsent' => false,
                'serviceEnable' => false,
            ],
            [
                'featureNumber' => FeatureACL::AI_ONECLICK_DD_REPORT,
                'featureTitle' => 'AI Due Diligence Report',
                'featureDescription' => 'Diligent\'s AI-powered reports consolidate key risk data from global sanctions watchlists, politically exposed persons (PEPs) databases, and adverse media sources, providing a holistic view of third-party risk',
                'helpUrl' => 'https://help.highbond.com/helpdocs/third-party-manager/Content/third_party_manager/using_ai_generated_due_diligence_reports.htm',
                'featureStatus' => $this->app->ftr->tenantHas(FeatureACL::AI_ONECLICK_DD_REPORT),
                'serviceConsent' => false,
                'serviceEnable' => false,
            ]
        ];

        foreach ($AiServices as $key => $service) {
            $result = $this->model->getFeatureDetail($this->app->session->authUserID, $service['featureNumber']);
            if ($result) {
                $AiServices[$key]['serviceConsent'] = $result['actionPerformed'];
                $AiServices[$key]['serviceEnable'] = $result['featureEnabled'];
            }
        }
        $this->setViewValue('AiServices', $AiServices);
        $popupID = $this->model->getAiPopupId();
        $this->setViewValue('popupID', $popupID);
        $html = $this->app->view->fetch(
            $this->getTemplate(),
            $this->getViewValues()
        );
        $this->jsObj->Args   = ['html' => $html];
        $this->jsObj->Result = 1;
    }

    /**
     * Get HTML for the Learn more and enable consent
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxGetPopupHtml(): void
    {
        $type = $this->getPostVar('type', '');
        if ($type) {
            if ($type == 'LearnMore') {
                $featureID = $this->getPostVar('featureID', '');
                if ($featureID == FeatureACL::AI_SUMMARISATION) {
                    $html = $this->app->view->fetch($this->tplRoot . "MmLearnMore.tpl");
                    $header = $this->app->view->fetch($this->tplRoot . "LearnMoreHeader.tpl");
                } else if ($featureID == FeatureACL::AI_ASSISTANT) {
                    $html = $this->app->view->fetch($this->tplRoot . "VaLearnMore.tpl");
                    $header = $this->app->view->fetch($this->tplRoot . "LearnMoreHeader.tpl");
                } else if ($featureID == FeatureACL::AI_ONECLICK_DD_REPORT) {
                    $html = $this->app->view->fetch($this->tplRoot . "DdReportLearnMore.tpl");
                    $header = $this->app->view->fetch($this->tplRoot . "LearnMoreHeader.tpl");
                }
            } else {
                $html = $this->app->view->fetch($this->tplRoot . "$type.tpl");
                $header = '';
            }
            $this->jsObj->Args   = ['header' => $header, 'html' => $html];
            $this->jsObj->Result = 1;
        } else {
            $this->jsObj->ErrTitle = 'Invalid Type';
        }
    }
}
