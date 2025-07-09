<?php
/**
 * Controller: PresCo Tenant Custom Workflow
 */

namespace Models\TPM\Workflow;

/**
 * Delta copy of public_html/cms/includes/php/Models/TPM/Workflow/PresCo.php provides Workflow functionality for
 * PresCo (TenantID = 79)
 */
#[\AllowDynamicProperties]
class PresCo extends TenantWorkflow
{
    /**
     * PresCo constructor.
     *
     * @param int $tenantID Tenant ID
     *
     * @throws \Exception
     */
    public function __construct($tenantID)
    {
        parent::__construct($tenantID);
        if (!$this->tenantHasWorkflow()
            || (!in_array($this->tenantID, [79]))
        ) {
            throw new \Exception("Not PresCo or PresCo doesn't have Workflow Feature/Setting enabled.");
        }
    }
}
