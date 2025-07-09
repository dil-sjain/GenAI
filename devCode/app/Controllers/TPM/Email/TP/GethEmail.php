<?php
/**
 * Base class for handling GETH emails
 *
 * @keywords email, 3p, gifts, geth
 */

namespace Controllers\TPM\Email\TP;

use Controllers\TPM\Email\SystemEmail;
use Models\TPM\TpGifts;

/**
 * Class GethEmail
 *
 * @package Controllers\TPM\Email\TP
 */
#[\AllowDynamicProperties]
abstract class GethEmail extends SystemEmail
{
    /**
     * @var TpGifts
     */
    protected $gift   = null;

    /**
     * Initialize values needed to build emails
     *
     * @throws \Exception
     * @return void
     */
    public function initialize()
    {
        $this->gift = (new TpGifts($this->tenantID))->findById($this->refID);
        if (!is_object($this->gift)) {
            throw new \Exception('Unable to find gift associated with the ID: ' . $this->refID);
        }
    }

    /**
     * Prepare email body.
     *
     * @return void
     */
    public function prepareBody()
    {
        return;
    }

    /**
     * Prepare the email subject.
     *
     * @return void
     */
    public function prepareSubject()
    {
        return;
    }
}
