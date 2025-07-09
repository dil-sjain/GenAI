<?php
/**
 * Interface required for working with the email logging observer
 *
 * @keywords email, logging, interface
 */

namespace Controllers\TPM\Email\Observers;

/**
 * Interface EmailLoggingInterface
 *
 * @package Controllers\TPM\Email\Observers
 */
interface EmailLoggingInterface
{
    /**
     * Retrieve the main ID associated with email that will be set in email log table
     *
     * @return int
     */
    public function getRefId();

    /**
     * Retrieve the multiple ID's associated with email that will be set in email log table (e.g. caseID and tpID)
     *
     * @return array
     */
    public function getRefIds();

    /**
     * Get set sender of current email.
     *
     * @return string
     */
    public function getSender();

    /**
     * Get an array containing all normal message recipients
     *
     * @return array
     */
    public function getRecipients();

    /**
     * Int representing the current type of email being sent
     *
     * @return int
     */
    public function getEmailType();

    /**
     * Get the ID of the current tenant or client being used to send the email
     *
     * @return int
     */
    public function getTenantId();
}
