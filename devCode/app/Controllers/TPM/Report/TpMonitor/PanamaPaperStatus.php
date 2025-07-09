<?php
/**
 * PanamaPaperStatus
 */

namespace Controllers\TPM\Report\TpMonitor;

use Lib\SettingACL;

/**
 * Class PanamaPaperStatus
 * Return if ICIJ is setup by tenant
 */
#[\AllowDynamicProperties]
class PanamaPaperStatus
{
    /**
     * Does the tenant have the Panama Papers setting set?
     *
     * @param integer $tenantID - ID of the tenant to check their settings
     *
     * @return boolean true or false depending in their settings for ICIJ status
     */
    public static function hasPanamaPapers($tenantID)
    {
        $gdcOpts = (new SettingACL((int)$tenantID))->getGdcSettings();
        return (bool)$gdcOpts['search']['icij'];
    }
}
