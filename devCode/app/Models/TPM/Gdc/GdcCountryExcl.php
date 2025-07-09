<?php
/**
 * Provide acess to GDC country exclusions
 *
 * @see public_html/cms/includes/php/Models/TPM/Gdc/GdcCountryExcl.php
 */

namespace Models\TPM\Gdc;

/**
 * Read/write access to gdcCountryExcl
 *
 * @keywords GDC, GDC filter, GDC country, filter
 */
#[\AllowDynamicProperties]
class GdcCountryExcl extends GdcFilterBase
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'gdcCountryExcl';

    /**
     * @var string id column name (required by base class)
     */
    protected $idCol = 'countryID';
}
