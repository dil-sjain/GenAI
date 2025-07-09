<?php
/**
 * Provide access to GDC source list exclusions
 *
 * @see public_html/cms/includes/php/Models/TPM/Gdc/GdcListExcl.php
 */

namespace Models\TPM\Gdc;

/**
 * Read/write access to gdcListExcl
 *
 * @keywords GDC, GDC filter, GDC suorce, GDC list, filter
 */
#[\AllowDynamicProperties]
class GdcListExcl extends GdcFilterBase
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'gdcListExcl';

    /**
     * @var string id column name (required by base class)
     */
    protected $idCol = 'listID';
}
