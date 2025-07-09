<?php
/**
 * Control for TPM Fields/Lists - 3P Compliance Factors
 */

namespace Controllers\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpCompFactorsData;

/**
 * Class handles Fields/Lists requirements for 3P Compliance Factors.
 *
 * @keywords compliance factors, tpm, fields lists, content control, settings
 */
#[\AllowDynamicProperties]
class TpCompFactors
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object TpmList model instance.
     */
    protected $data = null;

    /**
     * @var integer tenantID
     */
    protected $tenantID = 0;

    /**
     * @var object jsObj style object for return to caller.
     */
    protected $jsObj = null;

    /**
     * @var integer ctrlID of the list being worked with (hard coded in FieldsLists.php)
     */
    protected $ctrlID = 0;

    /**
     * @var array Translation text array
     */
    protected $txt = [];

    /**
     * @var string server-side path to PDF template.
     */
    protected $pdfTpl  = 'TPM/Settings/ContentControl/FieldsLists/CompFactors/varianceMapPDF.tpl';

    /**
     * Static method used by Compliance Factors to generate a printable PDF document of all Variance Mappings
     *
     * @return void
     */
    public static function pdfInvoke()
    {
        $app = \Xtra::app();
        $tenantID = $app->ftr->aclSpec['tenant'];
        $userID = $app->ftr->aclSpec['user'];
        $txt = $app->trans->group('field_list_srv', $app->trans->langCode, $tenantID);
        (new self($tenantID, 12, $userID, ['txt'=>$txt]))->varianceMappingPDF();
    }

    /**
     * class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $ctrlID     ID of current list type
     * @param integer $userID     ID of current user.
     * @param array   $initValues Any additional parameters that need to be passed in
     *
     * @throws \InvalidArgumentException Thrown if tenantID, ctrlID, or userID are <= 0;
     */
    public function __construct($tenantID, $ctrlID, $userID, $initValues = [])
    {
        $this->tenantID = (int)$tenantID;
        $this->ctrlID = (int)$ctrlID;
        $this->userID = (int)$userID;
        if ($this->tenantID <= 0) {
            throw new \InvalidArgumentException("The tenantID must be a positive integer.");
        } elseif ($this->ctrlID <= 0) {
            throw new \InvalidArgumentException("The ctrlID must be a positive integer.");
        } elseif ($this->userID <= 0) {
            throw new \InvalidArgumentException("The userID must be a positive integer.");
        }
        $this->app     = \Xtra::app();
        $this->data = new TpCompFactorsData($this->tenantID, $this->userID);
        $this->jsObj = (object)null;
        $this->txt = (!empty($initValues['txt'])) ? $initValues['txt'] : [];
    }

    /**
     * Affirm that the class has loaded and is ready to proceed.
     *
     * @return boolean True if loaded, else false.
     */
    public function isLoaded()
    {
        if ($this->data->isLoaded() && $this->tenantID > 0 && $this->ctrlID > 0
            && $this->userID > 0 && !empty($this->txt)
        ) {
            $this->data->setTrText($this->txt);
            return true;
        }
        return false;
    }

    /**
     * Control data flow into the specified sub-op and return its output.
     * Set up this way so the method can be public, while subOp is protected/private.
     *
     * @param array  $post  Sanitized post array (app()->clean_POST)
     * @param object $jsObj The ajax jsObj object at the time this method was called.
     *                      Methods add onto this and then it is returned to FlCtrl.
     *
     * @return object Returns jsObj object
     */
    public function manageData($post, $jsObj)
    {
        $this->jsObj = $jsObj;
        if (!$post['subOp'] || !method_exists($this, $post['subOp'])) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->txt['title_operation_failed'];
            $this->jsObj->ErrMsg = $this->txt['message_operation_not_recognized'];
            return $this->jsObj;
        }
        $method = $post['subOp'];
        $this->$method($post);
        if ($this->jsObj->Result == 0) {
            $this->jsObj->ErrTitle = !empty($this->jsObj->ErrTitle) ? $this->jsObj->ErrTitle : $this->txt['one_error'];
            $this->jsObj->ErrMsg = !empty($this->jsObj->ErrMsg) ? $this->jsObj->ErrMsg : $this->txt['status_Tryagain'];
        }
        return $this->jsObj;
    }

    /**
     * Setup and return data for initial display.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function initDisplay($post)
    {
        $post['phpcs'] = true;                  // hack to make phpcs happy
        $recs = $this->data->getFactors();
        $numRecs = count($recs['recs']);
        $this->jsObj->Result = 1;
        $this->jsObj->NumRecs = $numRecs;
        $this->jsObj->Recs = (($numRecs > 0) ? $recs['recs'] : []);
        $this->jsObj->DefaultWeight = (($numRecs > 0) ? $recs['weight'] : 0);
        $this->jsObj->Lists = [
            'tiers'  => $this->data->getTiers(),
            'types'  => $this->data->getTypes(),
            'groups' => $this->data->getGroups()
        ];
    }

    /**
     * Save factor
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function saveFactor($post)
    {
        if (!$this->data->saveFactor($post['vals'], $post['oldVals'])) {
            $this->jsObj->Result = 0;
            return;
        }
        $this->jsObj->Result = 1;
        $recs = $this->data->getFactors();
        $numRecs = count($recs['recs']);
        $this->jsObj->NumRecs = $numRecs;
        $this->jsObj->Recs = (($numRecs > 0) ? $recs['recs'] : []);
        $this->jsObj->DefaultWeight = (($numRecs > 0) ? $recs['weight'] : 0);
    }

    /**
     * Remove a compliance factor
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function removeFactor($post)
    {
        $id = (int)$post['fID'];
        if ($id < 1 || !$this->data->removeFactor($id)) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->txt['del_failed'];
            $this->jsObj->ErrMsg   = $this->txt['del_failed_has_data_no_perm'];
            return;
        }
        $recs = $this->data->getFactors();
        $numRecs = count($recs['recs']);
        $this->jsObj->Result = 1;
        $this->jsObj->NumRecs = $numRecs;
        $this->jsObj->Recs = (($numRecs > 0) ? $recs['recs'] : []);
        $this->jsObj->DefaultWeight = (($numRecs > 0) ? $recs['weight'] : 0);
    }

    /**
     * Get the current compliance threshold value
     *
     * @param array $post Sanitized post array (app()->clean_POST) (not used, but it's passed by controller)
     *
     * @return void
     */
    protected function getThreshold($post)
    {
        $post['phpcs'] = true;                  // hack to make phpcs happy
        $this->jsObj->Result = 1;
        $this->jsObj->ComplThresh = $this->data->getThreshold();
    }

    /**
     * Save new compliance threshold
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function updateComplThresh($post)
    {
        if (!$this->data->updateThreshold($post['val'], $post['oldVal'])) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrTitle = $this->txt['one_error'];
            $this->jsObj->ErrMsg = $this->txt['fl_error_update_compliance_threshold'];
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->ComplThresh = (int)$post['val'];
    }

    /**
     * Return data for variance mapping modal display
     *
     * @param array $post Sanitized post array (app()->clean_POST) (not used, but it's passed by controller)
     *
     * @return void
     */
    protected function varianceMapping($post)
    {
        $post['phpcs'] = true;                  // hack to make phpcs happy
        $mapping = $this->data->getVarianceMapping();
        if (!$mapping) {
            $this->jsObj->Result = 0;
            return;
        }
        $this->jsObj->Result  = 1;
        $this->jsObj->Mapping = $mapping;
    }

    /**
     * Generate a printable PDF document of all Variance Mappings
     *
     * @return void Echo out the pdf file to the browser.
     */
    public function varianceMappingPDF()
    {
        // we do this so the model has its required text. since isLoaded isn't
        // called from FlCtrl as it's bypassed for the PDF, we have to set the text manually.
        $this->data->setTrText($this->txt);
        $trTxt = $this->app->trans->group('field_list_comp_fact_pdf');
        $pdfCreated   = str_replace('{pdfDate}', date('D M d Y'), (string) $trTxt['pdf_created_datetime']);
        $pdfCreated   = str_replace('{pdfTime}', date('h:i:s'), $pdfCreated);
        $tenant       = $this->data->getTenantInfo();
        $companyName  = $tenant->clientName;
        $pdfWarn      = str_replace('{companyName}', $companyName, (string) $trTxt['pdf_warning_message']);
        unset($trTxt['pdf_created_datetime'], $trTxt['pdf_warning_message']);

        $trTxt['companyName'] = $companyName;
        $trTxt['created']     = $pdfCreated;
        $trTxt['warning']     = $pdfWarn;
        $viewVals = [
            'logoFile'   => $tenant->logoFileName,
            'rptTstamp' => date('l, F j, Y'),
            'maps'       => $this->data->getVarianceMapping(),
            'txt'        => $trTxt,
            'footerTop' => $trTxt['warning'],
            'footerMiddle' => $trTxt['created'],
        ];
        $html = $this->app->view->render($this->pdfTpl, $viewVals);
        $pdfFileName = 'ComplianceVarianceMap_' . date('Y-m-d_H-i') . '.pdf';
        (new \Lib\Services\GeneratePdf())->pdf($html, $pdfFileName);
    }


    /**
     * Get data necessary to return for display of a variance.
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function changeVariance($post)
    {
        $recs = $this->data->getVariance(
            $post['filter']['tier']['id'],
            $post['filter']['type']['id'],
            $post['filter']['cat']['id']
        );
        if (!$recs) {
            $this->jsObj->Result = 0;
            $this->jsObj->ErrMsg = "The selected variance is not valid and will not trigger.";
            return;
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Variance = $recs;
        $this->jsObj->CatList = '';
        if ($post['inclCats']) {
            $this->jsObj->CatList = $this->data->getCatsByType($post['filter']['type']['id']);
        }
    }

    /**
     * Update variance overrides
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void
     */
    protected function updateVariance($post)
    {
        foreach ($post['vals'] as $v) {
            if ($v['reset'] == 1) {
                $oID = (int)$v['oID'];
                if ($oID > 0) {
                    $this->data->removeVarianceOverride($post['filter'], $oID);
                }
            } else {
                $this->data->updateVarianceOverride($v, $post['filter']);
            }
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Variance = $this->data->getVariance(
            $post['filter']['tier']['id'],
            $post['filter']['type']['id'],
            $post['filter']['cat']['id']
        );
    }

    /**
     * Reset variance to default values
     *
     * @param array $post Sanitized post array (app()->clean_POST)
     *
     * @return void;
     */
    protected function restoreVarianceDefaults($post)
    {
        $this->data->removeVarianceOverride($post['filter']);
        $this->jsObj->Result = 1;
        $this->jsObj->Variance = $this->data->getVariance(
            $post['filter']['tier']['id'],
            $post['filter']['type']['id'],
            $post['filter']['cat']['id']
        );
    }
}
