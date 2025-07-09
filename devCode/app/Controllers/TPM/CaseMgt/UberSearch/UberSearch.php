<?php
/**
 * Multi-tenant name search on elastic index from Case Management (3P not enabled)
 */

namespace Controllers\TPM\CaseMgt\UberSearch;

use Controllers\TPM\CaseMgt\CaseMgtNavBar;
use Lib\Traits\UberSearch\UberSearchController;

/**
 * Multi-tenant name search on elastic index
 *
 * @keywords uber search, search, name search, elastic, elastic search, multi-tenant access
 */
#[\AllowDynamicProperties]
class UberSearch extends CaseMgtNavBar
{
    use UberSearchController; // Common behavior on Third Party Management or Case Management

    /**
     * @const string Minimum Y-m-d date
     */
    public const MINIMUM_DATE = '2000-01-01'; // well before any records in Securimate

    /**
     * Set class properties
     *
     * @param integer $clientID   clientProfile.id
     * @param array   $initValues Flexible construct to pass in values
     *
     * @return void
     */
    public function __construct($clientID, $initValues = [])
    {
        parent::__construct($clientID, $initValues);
        $this->initClassProperties($clientID);
    }
}
