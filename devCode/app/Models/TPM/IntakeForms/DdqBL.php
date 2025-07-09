<?php
namespace Models\TPM\IntakeForms;

/**
 * Provides basic read access to ddq table
 */
#[\AllowDynamicProperties]
class DdqBL extends \Models\BaseLite\RequireClientID
{
    /**
     * @var Required by base class
     */
    protected $tbl = 'ddq';

    /**
     * @var string Name of primaryID field
     */
    protected $primaryID = 'id';

    /**
     * @var boolean table is in a client database
     */
    protected $tableInClientDB = true;
}
