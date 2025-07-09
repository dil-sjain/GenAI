<?php
/**
 * Find and validate eligible 3P profile internal owners.
 * Extended parent to avoid adding weight to it for this special purpose.
 *
 * @todo Decide whether it is valid to continue allowing CLIENT_USER to own a 3P outside of their region
 * @see  File: public_html/cms/thirdparty/load-tp-ws.php ($regCond)
 */

namespace Models\TPM\TpProfile;

use Models\Globals\UserRoleRegions;
use Lib\Legacy\UserType;

/**
 * Find and validate eligible 3P profile internal owners.
 *
 * @keywords owner, 3P owner, eligible owner, profile owner
 */
class EligibleOwners extends UserRoleRegions
{
    /**
     * Return attritubes of users who are eligible to be 3P internalOwner
     * Region contraint applies only to legacy userType CLIENT_MANAGER, as of 2018-03-27
     *
     * @param int    $regionID     Region ID - region.id ownned by tenant ID
     * @param array  $cols         Column => values to return for matching users
     * @param string $userOrderCol Order By column in users table
     *
     * @return array
     */
    public function getEligibleOwnersByRegion($regionID, $cols = [], $userOrderCol = 'userName')
    {
        $defaultCols = ['id', 'userName'];
        $defaultOrderCol = 'userName';
        if (empty($cols) || !is_array($cols)) {
            $cols = $defaultCols;
        }
        // Requested columns are good?
        [$db, $tbl] = explode('.', $this->tbl);
        foreach ($cols as $col) {
            if (!$this->DB->columnExists($col, $tbl, $db)) {
                $cols = $defaultCols; // perhaps, better than aborting
                break;
            }
        }
        if (empty($userOrderCol) || !$this->DB->columnExists($userOrderCol, 'users', $this->DB->authDB)) {
            $userOrderCol = $defaultOrderCol;
        }
        $safeCols = [];
        foreach ($cols as $col) {
            $safeCols[] = 'u.`' . $col . '`';
        }
        $colList = implode(', ', $safeCols);
        [$tpl, $params] = $this->sqlTemplate($regionID);
        $srch = [
            '{{colList}}',
            '{{whereUser}}',
            '{{tail}}',
        ];
        $rplc = [
            $colList,
            '',
            "ORDER BY u.`$userOrderCol`",
        ];
        $sql = str_replace($srch, $rplc, (string) $tpl);
        $owners = $this->DB->fetchAssocRows($sql, $params);
        return $owners;
    }

    /**
     * Validate user as qualified 3P profile internal owner
     *
     * @param int $userID   Unique User ID - authDB.users.id
     * @param int $regionID Region ID - region.id ownned by tenant ID
     *
     * @return bool
     */
    public function userIsEligibleOwner($userID, $regionID)
    {
        $userID = (int)$userID;
        [$tpl, $params] = $this->sqlTemplate($regionID);
        $srch = [
            '{{colList}}',
            '{{whereUser}}',
            '{{tail}}',
        ];
        $rplc = [
            'u.id',
            'u.id = :uid AND ',
            'LIMIT 1',
        ];
        $sql = str_replace($srch, $rplc, (string) $tpl);
        $params[':uid'] = $userID;
        return (!empty($userID) && $this->DB->fetchValue($sql, $params) === $userID);
    }

    /**
     * Return sql template and parameters
     *
     * @param int $regionID Region ID - region.id ownned by tenant ID
     *
     * @return array [$sql, $params]
     */
    private function sqlTemplate($regionID)
    {
        $roleTbl = $this->DB->globalDB . '.g_roles';
        $roleFeatTbl = $this->DB->globalDB . '.g_roleFeatures';
        $roleUserTbl = $this->DB->globalDB . '.g_roleUsers';
        $userTbl = $this->DB->authDB . '.users';
        $sql = <<<EOT
SELECT DISTINCT {{colList}}
FROM $userTbl AS u
INNER JOIN ($roleTbl AS ro, $roleUserTbl AS ru)
ON (ro.tenantID = :tenant AND ro.appID = :app AND ru.roleID = ro.id AND ru.userID = u.id)
LEFT JOIN $roleFeatTbl AS rf ON rf.roleID = ro.id
LEFT JOIN $this->tbl AS urr ON urr.userID = u.id AND urr.roleID = ro.id
WHERE {{whereUser}}(ro.legacyUserType <> :cliMgr
OR (rf.featureID = :hasAll OR urr.regionID = :rid OR urr.regionID = -1))
AND u.status IN('active', 'pending', 'expired')
{{tail}}
EOT;
        $params = [
            ':app' => \Feature::APP_TPM,
            ':hasAll' => \Feature::ALL_REGIONS,
            ':tenant' => $this->clientID,
            ':rid' => $regionID,
            ':cliMgr' => UserType::CLIENT_MANAGER,
        ];
        return [$sql, $params];
    }
}
