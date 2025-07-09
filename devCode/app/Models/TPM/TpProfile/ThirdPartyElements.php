<?php
/**
 * Provide access to thirdPartyElements
 */

namespace Models\TPM\TpProfile;

/**
 * Read/write access to thirdPartyElementsd
 */
#[\AllowDynamicProperties]
class ThirdPartyElements extends \Models\BaseLite\RequireClientID
{
    protected $tbl = 'thirdPartyElements';
}
