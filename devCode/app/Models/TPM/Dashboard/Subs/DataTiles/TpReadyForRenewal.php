<?php
/**
 * TPPs ready for renewal
 *
 * @keywords dashboard, data ribbon
 */

namespace Models\TPM\Dashboard\Subs\DataTiles;

use Lib\SettingACL;

/**
 * Class TpReadyForRenewal
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class TpReadyForRenewal extends TpTileBase
{
    public const RENEWAL_DAYS_DEFAULT = 365;

    /**
     * Initialize data
     */
    public function __construct()
    {
        parent::__construct();
        $this->title = 'Third Parties Ready for Renewal';
    }

    /**
     * Set WHERE parameters for query
     *
     * @return void
     */
    public function setWhere()
    {
        // Only look at active TPP
        $this->where[] = "AND: tPP.status = 'active'";

        // This query gets days since last Intake Form was submitted (requires that subByDate is set).
        $subQuery = '
            SELECT DATEDIFF(CURRENT_DATE, d.subByDate) AS dateDiff -- Get days since subByDate
            FROM cases AS c
            LEFT JOIN ddq AS d on d.caseID = c.id -- Join the DDQ (Intake Form)
            WHERE c.tpID = tPP.id
            AND d.id IS NOT NULL -- Make sure there is an Intake Form assigned to the case
            AND c.caseStage IN (9,11) -- Case should be in stage Accepted By Requestor OR Closed
            ORDER BY d.subByDate DESC -- Sort by date so the newest is at the top
            LIMIT 1 -- We only want the most recent subByDate
        ';
        $this->whereParams[':renewalDays'] = self::RENEWAL_DAYS_DEFAULT;
        if ($setting = (new SettingACL($this->tenantID))->get(SettingACL::INTKFRM_RENEWAL_DAYS)) {
            $this->whereParams[':renewalDays'] = $setting['value'];
        }
        $this->where[] = "AND: :renewalDays < (" . $subQuery . ")";
    }

    /**
     * Custom table JOIN. Add g_settings and g_tenantsSettings tables so we can pull out renew days
     *
     * @return void
     */
    public function setJoins()
    {
        $this->joins[] = 'LEFT JOIN clientProfile AS cP ON cP.id = tPP.clientID';
    }
}
