<?php
/**
 * Provide acess to 3P Profile Type
 */

namespace Models\TPM\TpProfile;

/**
 * Read/write access to tpType
 *
 * @keywords 3p type
 */
#[\AllowDynamicProperties]
class TpType extends \Models\BaseLite\RequireClientID
{
    /**
     * Table name (required by base class)
     *
     * @var string
     */
    protected $tbl = 'tpType';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;
}
