<?php
/**
 * Provides basic read/write access to ddqName table
 */

namespace Models\TPM\IntakeForms;

/**
 * Basic CRUD access to inFormSubmissionAttempts, requiring tenantID
 *
 * @keywords inFormSubmissionAttempts, intake form, intake form submission, ddq
 */
#[\AllowDynamicProperties]
class InFormSubmissionAttempts extends \Models\BaseLite\RequireClientID
{
    /**
     * @var Required by base class
     */
    protected $tbl = 'inFormSubmissionAttempts';

    /**
     * @var boolean tabled is in a client database
     */
    protected $tableInClientDB = true;

    /**
     * @var string Name of primaryID field
     */
    protected $primaryID = null;

    /**
     * TPM clientID field name
     *
     * @var string
     */
    protected $clientIdField = 'tenantID';

    /**
     * Debug if true
     *
     * @var boolean
     */
    public $debug = true;
}
