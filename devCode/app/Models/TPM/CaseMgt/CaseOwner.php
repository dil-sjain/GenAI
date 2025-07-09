<?php
/**
 * Lookup and validate cases.requestor (case owner)
 * These methods were moved here from Models\ThirdPartyManagement\Cases so that they could be accessed independently.
 */

namespace Models\TPM\CaseMgt;

use Lib\Legacy\ClientIds;
use Lib\Legacy\UserType;
use Lib\Database\MySqlPdo;
use Lib\Support\Xtra;

/**
 * Method relating to cases.requestor
 */
#[\AllowDynamicProperties]
class CaseOwner
{
    /**
     * @var int $clientID clientProfile.id is TPM tenant identifier
     */
    protected $clientID = 0;

    /**
     * @var null|MySqlPdo Class instance
     */
    protected $DB;

    /**
     * Class constructor
     *
     * @param int $clientID TPM identifier
     */
    public function __construct($clientID)
    {
        $this->clientID = (int)$clientID;
        $this->DB = Xtra::app()->DB;
    }

    /**
     * Test if userID can own a case with given region, dept
     *
     * @param int $userID users.id
     * @param int $region region.id
     * @param int $dept   department.id
     *
     * @return bool True if user can own case, otherwise false
     */
    public function validCaseOwner($userID, $region, $dept)
    {
        $userID = (int)$userID;
        $region = (int)$region;
        $dept   = (int)$dept;
        $authDB = $this->DB->authDB;
        $rtn = false; // assume user can't access case
        if ($userID) {
            $sql = "SELECT userid, userType, userRegion, userDept, mgrRegions, mgrDepartments\n"
                . "FROM $authDB.users\n"
                . "WHERE id = :id AND status <> 'deleted'\n"
                . "AND (clientID = :clientID OR userType > :userType) LIMIT 1";
            $params = [':id' => $userID, ':clientID' => $this->clientID, ':userType' => UserType::CLIENT_ADMIN];
            if ($userRow = $this->DB->fetchObjectRow($sql, $params)) {
                // TODO grh - these tests should use the newer userRoleRegions and userRoleDepartments
                switch ($userRow->userType) {
                    case UserType::SUPER_ADMIN:
                    case UserType::CLIENT_ADMIN:
                        $rtn = true;
                        break;
                    case UserType::CLIENT_MANAGER:
                        // confirm logic
                        $mgrRegions = trim((string) $userRow->mgrRegions);
                        $mgrDepartments = trim((string) $userRow->mgrDepartments);
                        if (($mgrRegions === '' || in_array($region, explode(',', $mgrRegions . ',0')))
                            && ($mgrDepartments === ''
                                || in_array($dept, explode(',', $mgrDepartments . ',0'))
                            )
                        ) {
                            $rtn = true;
                        }
                        break;

                    case UserType::CLIENT_USER:
                        /*
                         * In the application, region is used in only one narrow set of circumstances
                         * to determine Client User access to a case.  Here are the rules:
                         *
                         *   1. Access *any* case for which Client User is the requester, regardless of
                         *      the region assignment of the case.
                         *   2. Access cases in qualification stage (only) for which there is no requester
                         *      assigned, if and only if the case region is the same as the Client User
                         *      region.
                         *
                         * Since this function determines potential case ownership/requester, region is not
                         * relevant, strictly speaking, since region does not deny Client User access to any
                         * case for which he is the requester.
                         *
                         * We allow clients the option to filter Client User ownership by region if the
                         * subscriber's preference (not an application requirement) is to keep case
                         * assignments within Client User assigned region.  Other subscribers may not care
                         * about it, and want to be able to assign cases to Client User without regard for
                         * Client User's assigned region.
                         *
                         * We also allow clients the option to filter Client User by dept. or by both
                         * region and dept.  Currently (2012-06-30), this filtering applies only to the
                         * Reassign Case Elemements dialog user list  Add clientID to the appropriate
                         * array below to match subscriber preference.
                         *
                         * if the region argument is 0, then it indicates region is not important and will
                         * match any Client User region assignment. The same goes for dept.  Also if Client
                         * User dept assignment is 0 any dept argument will be deemed to match Client User
                         * dept.
                         */

                        /*
                         * Add clientID to these arrays to match client preference.  Client ID should
                         * be added to only one of these array.
                         */

                        $restrictToRegion = [];
                        $restrictToDept = [];
                        $restrictToRegionAndDept = [ClientIds::BAXTER_CLIENTID, ClientIds::BAXALTA_CLIENTID];

                        $regionOk = ($region == $userRow->userRegion || $region == 0 || $userRow->userRegion == 0);
                        $deptOk   = ($dept == $userRow->userDept || $dept == 0 || $userRow->userDept == 0);

                        if (in_array($this->clientID, $restrictToRegion)) {
                            $rtn = $regionOk;
                        } elseif (in_array($this->clientID, $restrictToDept)) {
                            $rtn = $deptOk;
                        } elseif (in_array($this->clientID, $restrictToRegionAndDept)) {
                            $rtn = ($regionOk && $deptOk);
                        } else {
                            /*
                             * 2012-01-23 Todd asked us to remove restriction by region.
                             * Default behavior: Client User can be an owner of a case without regard
                             *                   for Client User's assigned region or department.
                             *
                             * COKE_CLIENTID explicitly asked for this behavior
                             */

                            $rtn = true;
                        }
                        break;
                }
            }
        }
        return $rtn;
    }

    /**
     * Provides users who could own case in given region and dept.
     *
     * @param int $region Region id
     * @param int $dept   Department id
     *
     * @return array Array of user row objects or an empty array
     */
    public function getProspectiveOwners($region, $dept)
    {
        $region = (int)$region;
        $dept = (int)$dept;
        $authDB = $this->DB->authDB;
        $sql = "SELECT id, lastName, firstName FROM $authDB.users\n"
            . "WHERE userType > :venderAdmin AND userType <= :clientAdmin AND clientID = :clientID\n"
            . "AND status = 'active' AND ((userRegion = :region OR userRegion = '0') OR (userType >= :clientManager))\n"
            . "ORDER BY lastName, firstName";
        $params = [
            ':venderAdmin' => UserType::VENDOR_ADMIN,
            ':clientAdmin' => UserType::CLIENT_ADMIN,
            ':clientID' => $this->clientID,
            ':region' => $region,
            ':clientManager' => UserType::CLIENT_MANAGER
        ];
        $users = [];
        if ($rows = $this->DB->fetchObjectRows($sql, $params)) {
            foreach ($rows as $row) {
                if ($this->validCaseOwner($row->id, $region, $dept)) {
                    $users[] = $row;
                }
            }
        }
        return $users;
    }

    /**
     * Returns an array of prospective case owners for a given region and department
     *
     * @param integer $regionID     Region ID
     * @param integer $departmentID Department ID
     * @param integer $userTypeID   Filter on users.userType
     *
     * @return array
     */
    public function getProspectiveOwnerDetails($regionID, $departmentID, $userTypeID = 0)
    {
        $rtn = [];
        $regionID = (int)$regionID;
        $departmentID = (int)$departmentID;
        $userTypeID = (int)$userTypeID;
        $validUserTypes = [
            UserType::SUPER_ADMIN => 'Super Admin',
            UserType::CLIENT_ADMIN => 'Client Admin',
            UserType::CLIENT_MANAGER => 'Client Manager',
            UserType::CLIENT_USER => 'Client User'
        ];
        if (!empty($regionID) && !empty($departmentID)) {
            $params = [
                ':clientID' => $this->clientID,
                ':regionID' => $regionID,
                ':clientManager' => UserType::CLIENT_MANAGER
            ];
            if (!empty($userTypeID) && array_key_exists($userTypeID, $validUserTypes)) {
                $userTypeClause = "userType = :userTypeID";
                $params[':userTypeID'] = $userTypeID;
            } else {
                $userTypeClause = 'userType > :spAdmin AND userType <= :clientAdmin';
                $params = array_merge(
                    $params,
                    [':spAdmin' => UserType::VENDOR_ADMIN, ':clientAdmin' => UserType::CLIENT_ADMIN]
                );
            }
            $cols = "id, concat(lastName, ', ', firstName) as name, userType, mgrRegions, mgrDepartments";
            $sql = "SELECT {$cols} FROM {$this->DB->authDB}.users\n"
                . "WHERE clientID = :clientID AND status = 'active' AND {$userTypeClause} "
                . "AND ((userRegion = :regionID OR userRegion = 0) OR (userType >= :clientManager))\n"
                . "ORDER BY lastName ASC, firstName ASC";
            if ($prospectiveOwners = $this->DB->fetchAssocRows($sql, $params)) {
                foreach ($prospectiveOwners as $prospectiveOwner) {
                    $userType = (int)$prospectiveOwner['userType'];
                    $managerRegions = trim((string) $prospectiveOwner['mgrRegions']);
                    $managerDepartments = trim((string) $prospectiveOwner['mgrDepartments']);
                    $regions = $departments = [];
                    $valid = false;
                    if (array_key_exists($userType, $validUserTypes)) {
                        if ($userType !== UserType::CLIENT_MANAGER
                            || (empty($managerRegions) && empty($managerDepartments))
                        ) {
                            $valid = true;
                        } else {
                            $inRegions = true;
                            $inDepartments = true;
                            if (!empty($managerRegions)) {
                                $regions = array_map('intval', explode(',', $managerRegions));
                                $inRegions  = in_array($regionID, array_merge($regions, [0]));
                            }
                            if (!empty($managerDepartments)) {
                                $departments = array_map('intval', explode(',', $managerDepartments));
                                $inDepartments  = in_array($departmentID, array_merge($departments, [0]));
                            }
                            $valid = ($inRegions && $inDepartments);
                        }
                    }
                    if ($valid) {
                        $owner = [
                            'id' => $prospectiveOwner['id'],
                            'name' => $prospectiveOwner['name'],
                            'userTypeID' => $prospectiveOwner['userType'],
                            'userType' => $validUserTypes[$prospectiveOwner['userType']]
                        ];
                        if ($userType === UserType::CLIENT_MANAGER) {
                            $owner['departments'] = $departments;
                            $owner['regions'] = $regions;
                        }
                        $rtn[] = $owner;
                    }
                }
            }
        }
        return $rtn;
    }
}
