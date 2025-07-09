<?php
/**
 * Provides read-only access to g_cpiFormat
 */

namespace Models\TPM\RiskModel;

/**
 * Read g_cpiFormat records
 *
 * @keywords cpi, cpi format
 */
#[\AllowDynamicProperties]
class CpiFormat extends \Models\BaseLite\ReadData
{
    /**
     * @var string Table name
     */
    protected $tbl    = 'g_cpiFormat';

    /**
     * @var string Database name
     */
    protected $dbName = 'cms_global';
}
