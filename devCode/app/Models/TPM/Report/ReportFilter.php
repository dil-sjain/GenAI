<?php
/**
 * Provides basic read/write access to reportFilter table
 */

namespace Models\TPM\Report;

/**
 * Basic CRUD access to reportFilter,  requiring clientID
 *
 * @keywords report, fitler, report filter, reportFilter, config, configuration
 */
#[\AllowDynamicProperties]
class ReportFilter extends \Models\BaseLite\RequireClientID
{
    protected $tbl = 'reportFilter';
}
