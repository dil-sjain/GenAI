<?php

namespace Controllers\TPM\Report\TpMonitor;

use Models\ADMIN\Config\MediaMonitor\MediaMonitorData;

/**
 * class MediaMonitorStatus
 * Return if MediaMonitor is setup by tenant
 */
#[\AllowDynamicProperties]
class MediaMonitorStatus
{
  /**
   * @param integer $tenantID - ID of the tenant to check their settings
   *
   * @return boolean true or false depending in their settings for MediaMonitor status
   */
    public static function hasMediaMonitor($tenantID)
    {
        $settings = new MediaMonitorData($tenantID);
        return filter_var(
            $settings->hasMediaMonitor($tenantID),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
