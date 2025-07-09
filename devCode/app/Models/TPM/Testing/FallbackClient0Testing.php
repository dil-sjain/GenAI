<?php
/**
 * Test FallbackClient0 abstract class
 */

namespace Models\TPM\Testing;

use Models\BaseLite\FallbackClient0;

/**
 * Test FallbackClient0 abstract class
 * Model is in dev_cms_cid76 only and has client defined data for clientID 79, but clientID 78
 * has only default records
 */
#[\AllowDynamicProperties]
class FallbackClient0Testing extends FallbackClient0
{
    protected $tbl = 'fallbackClient0Data';
}
