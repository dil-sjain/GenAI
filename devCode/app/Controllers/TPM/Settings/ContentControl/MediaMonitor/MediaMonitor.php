<?php
/**
 * Media Monitor config controller
 *
 * @keywords media monitor
 */

namespace Controllers\TPM\Settings\ContentControl\MediaMonitor;

use Controllers\ThirdPartyManagement\Base;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\MediaMonitor\MediaMonitorFilterData;

/**
 * Class allowing users to manage Media Monitor searches for client
 *
 */
#[\AllowDynamicProperties]
class MediaMonitor
{

    use AjaxDispatcher;

    /**
     * @var string Base directory for View
     */
    protected $tplRoot = 'TPM/Settings/ContentControl/MediaMonitor/';

    /**
     * @var string Base template for View
     */
    protected $tpl = 'MediaMonitor.tpl';

    /**
     * @var \Skinny\Skinny Application instance
     */
    protected $app = null;

    /**
     * @var Base Base controller instance
     */
    protected $baseCtrl = null;

    /**
     * @var int Delta tenantID
     */
    protected $tenantID = 0;

    /**
     * @var string The route for ajax calls in the js namespace.
     */
    protected $ajaxRoute = '/tpm/cfg/cntCtrl/mm';

    /**
     * @var MediaMonitorFilterData access for media monitor control panel
     */
    protected $data;

    /**
     * Initialize MediaMonitor settings controller
     *
     * @param integer $tenantID   Delta tenantID
     * @param array   $initValues Flexible construct to pass in values
     */
    public function __construct($tenantID, $initValues = [])
    {
        $this->tenantID        = (int)$tenantID;
        $initValues['objInit'] = true;
        $initValues['vars']    = [
            'tpl'     => $this->tpl,
            'tplRoot' => $this->tplRoot,
        ];
        $this->baseCtrl  = new Base($this->tenantID, $initValues);
        $this->data      = new MediaMonitorFilterData($this->tenantID);
        $this->app       = \Xtra::app();
        $this->fullPerms = ($this->app->ftr->isLegacyClientAdmin() || $this->app->ftr->isSuperAdmin());
    }

    /**
     * Sets message center view and template values
     *
     * @return void
     */
    public function initialize()
    {
        $this->baseCtrl->setViewValue('fullPerms', $this->fullPerms);
        $this->baseCtrl->setViewValue('canAccess', true);
        $this->app->view->display($this->baseCtrl->getTemplate(), $this->baseCtrl->getViewValues());
    }

    /**
     * Get filters and thresholds - ajax get initial values for the view
     *
     * @param mixed $count Either array including filterID and count of filtered searches
     *                     else a false boolean
     *
     * @return void
     */
    private function ajaxGetFilters(mixed $count = false)
    {
        $filters    = $this->data->getFilters();
        $thresholds = $this->data->getThresholds();

        if ($this->fullPerms && $count && is_array($count) && !empty($count['filterID'])
            && isset($count['count'])
        ) {
            foreach ($filters as $idx => $filter) {
                if ((int)$filter['id'] == (int)$count['filterID']) {
                    $filters[$idx]['count'] = (int)$count['count'];
                    break;
                }
            }
        }
        \Xtra::app()->log->debug($count);
        \Xtra::app()->log->debug($filters);
        $this->jsObj->Args = ['filters' => $filters, 'thresholds' => $thresholds];
        $this->jsObj->Result = 1;
    }

    /**
     * Get filter options for the view
     *
     * @return void
     */
    private function ajaxGetFilterOptions()
    {

        $this->jsObj->Args   = [
            'filterOptions' => [
                'riskLevels' => $this->formatList('riskLevels'),
                'types' => $this->data->typeCats,
                'includeEntities' => ['value' => 1, 'name' => 'Include Entities'],
                'entities' => $this->formatList('entities'),
                'associates' => $this->formatList('associates'),
                'frequency' => $this->formatList('frequencies')
            ]
        ];
        $this->jsObj->Result = 1;
    }

    /**
     * Formats list of a specified type
     *
     * @param string $type Either riskLevels, associates, frequencies or entities
     *
     * @return array
     */
    private function formatList($type)
    {
        $rtn = [];
        if ($type == 'riskLevels') {
            $rtn = $this->data->riskLevels;
            unset($rtn[0]);
            $rtn = array_merge($rtn);
        } else {
            foreach ($this->data->$type as $value => $name) {
                $rtn[] = ["value" => "$value", "name" => "$name"];
            }
        }
        return $rtn;
    }

    private function ajaxUpdateStatus()
    {
        $post = $this->app->clean_POST;
        \Xtra::requireInt($post['id']);
        $status = ($post['status'] === 'paused' ? 'active' : 'paused');

        $this->data->updateStatus($post, $status);
        $count = ($status == 'active')
            ? ['filterID' => $post['id'], 'count' => $this->data->getFilterCount($post['id'])]
            : false;
        $this->ajaxGetFilters($count);
    }

    /**
     * Get MessageCenterData - ajax get initial values for the view
     *
     * @return void
     */
    private function ajaxSaveFilter()
    {
        $post = $this->app->clean_POST;
        unset($post['pgAuth'], $post['op']);
        $filterID = $this->data->saveFilter($post);
        $count = ['filterID' => $filterID, 'count' => $this->data->getFilterCount($filterID)];
        $this->ajaxGetFilters($count);
    }

    /**
     * Save the threshold settings for the tenant with new data
     *
     * @return void
     */
    private function ajaxSaveThreshold()
    {
        $thresholds = $this->app->clean_POST;
        unset($thresholds['pgAuth'], $thresholds['op']);
        $rowsChanged = $this->data->updateThresholds($thresholds);

        $this->jsObj->Args = [
            'msg' => $rowsChanged ? 'Update successful' : 'Nothing changed'
        ];
        $this->jsObj->Result = 1;
    }
}
